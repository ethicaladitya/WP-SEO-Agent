<?php
/**
 * Google Site Kit integration bridge.
 *
 * Transparently reads Google OAuth tokens and module settings from the
 * Site Kit plugin (if installed and connected) so users don't need to
 * configure Search Console or GA4 credentials separately. Works exactly
 * like the SEO-plugin bridge — auto-detected, zero user configuration.
 *
 * Detection: `defined('GOOGLESITEKIT_VERSION') && owner_id > 0 && GSC property set`
 *
 * Token storage: Site Kit stores tokens per-user in WP user meta using
 * AES-256-CTR encryption keyed from LOGGED_IN_KEY / LOGGED_IN_SALT
 * (same wp-config constants). We replicate the same decryption so we
 * never need to depend on Site Kit's internal classes.
 *
 * Token refresh: if the stored access token has expired we call Site Kit's
 * OAuth proxy (`https://sitekit.withgoogle.com/o/oauth2/token/`) directly,
 * using the site-specific credentials stored in `googlesitekit_credentials`.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_SiteKit_Bridge {

	// -----------------------------------------------------------------------
	// Site Kit option / user-meta keys (stable since Site Kit 1.x)
	// -----------------------------------------------------------------------

	/** wp_options: the WP user ID that owns the Site Kit connection. */
	const OPT_OWNER_ID = 'googlesitekit_owner_id';

	/** wp_options: serialised Search Console module settings. */
	const OPT_SC_SETTINGS = 'googlesitekit_search-console_settings';

	/** wp_options: serialised Analytics 4 module settings. */
	const OPT_GA4_SETTINGS = 'googlesitekit_analytics-4_settings';

	/**
	 * wp_options: encrypted proxy credentials.
	 * Decrypts to JSON with oauth2_client_id / oauth2_client_secret.
	 */
	const OPT_CREDENTIALS = 'googlesitekit_credentials';

	/** User-meta suffix — WP stores user meta with 'wp_' prefix on single sites. */
	const META_ACCESS_TOKEN    = 'wp_googlesitekit_access_token';
	const META_TOKEN_EXPIRES   = 'wp_googlesitekit_access_token_expires_in';
	const META_TOKEN_CREATED   = 'wp_googlesitekit_access_token_created_at';
	const META_REFRESH_TOKEN   = 'wp_googlesitekit_refresh_token';

	/** Token refresh endpoint on Site Kit's OAuth proxy. */
	const PROXY_TOKEN_URL = 'https://sitekit.withgoogle.com/o/oauth2/token/';

	/** Number of seconds before expiry to consider the token "stale". */
	const EXPIRY_BUFFER_SECS = 120;

	// -----------------------------------------------------------------------
	// Detection / status
	// -----------------------------------------------------------------------

	/**
	 * Whether Site Kit is active, connected, and has Search Console configured.
	 *
	 * @return bool
	 */
	public static function is_active() {
		// Plugin must be present and activated.
		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
			return false;
		}

		// A connected owner user must exist.
		if ( self::get_owner_id() <= 0 ) {
			return false;
		}

		// Search Console property must be set.
		return self::get_gsc_site_url() !== '';
	}

	/**
	 * Whether GA4 is also connected inside Site Kit.
	 *
	 * @return bool
	 */
	public static function is_ga4_active() {
		return self::is_active() && self::get_ga4_property_id() !== '';
	}

	// -----------------------------------------------------------------------
	// Module settings readers
	// -----------------------------------------------------------------------

	/**
	 * GSC property ID — typically the full site URL, e.g. "https://example.com/".
	 *
	 * @return string  Empty string when not available.
	 */
	public static function get_gsc_site_url() {
		$settings = get_option( self::OPT_SC_SETTINGS, array() );
		if ( is_array( $settings ) && ! empty( $settings['propertyID'] ) ) {
			return (string) $settings['propertyID'];
		}
		return '';
	}

	/**
	 * GA4 numeric property ID (e.g. "533051680").
	 *
	 * @return string  Empty string when not available.
	 */
	public static function get_ga4_property_id() {
		$settings = get_option( self::OPT_GA4_SETTINGS, array() );
		if ( is_array( $settings ) && ! empty( $settings['propertyID'] ) ) {
			return (string) $settings['propertyID'];
		}
		return '';
	}

	// -----------------------------------------------------------------------
	// Access token
	// -----------------------------------------------------------------------

	/**
	 * Return a valid Google access token, refreshing it if necessary.
	 *
	 * On failure returns a WP_Error (compatible with what the GSC/GA4 clients
	 * already handle from `$this->google_auth->get_access_token()`).
	 *
	 * @return string|WP_Error  Access token string, or WP_Error on failure.
	 */
	public static function get_access_token() {
		$owner_id = self::get_owner_id();
		if ( $owner_id <= 0 ) {
			return new WP_Error(
				'seo_agent_ai_sitekit_no_owner',
				__( 'Site Kit owner user not found.', 'seo-agent-ai' )
			);
		}

		// Determine whether the stored token is still usable.
		$created    = (int) get_user_meta( $owner_id, self::META_TOKEN_CREATED, true );
		$expires_in = (int) get_user_meta( $owner_id, self::META_TOKEN_EXPIRES, true );
		$is_expired = ( $expires_in > 0 ) && ( ( $created + $expires_in - self::EXPIRY_BUFFER_SECS ) < time() );

		if ( $is_expired ) {
			$refreshed = self::refresh_token( $owner_id );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}

		$encrypted = get_user_meta( $owner_id, self::META_ACCESS_TOKEN, true );
		if ( empty( $encrypted ) ) {
			return new WP_Error(
				'seo_agent_ai_sitekit_no_token',
				__( 'No Site Kit access token found. Please re-connect Site Kit.', 'seo-agent-ai' )
			);
		}

		$token = self::sk_decrypt( (string) $encrypted );
		if ( $token === '' ) {
			return new WP_Error(
				'seo_agent_ai_sitekit_decrypt_fail',
				__( 'Could not decrypt Site Kit access token.', 'seo-agent-ai' )
			);
		}

		return $token;
	}

	// -----------------------------------------------------------------------
	// Token refresh
	// -----------------------------------------------------------------------

	/**
	 * Refresh the access token for a given Site Kit owner user.
	 *
	 * Delegates entirely to Site Kit's own OAuth client so the proxy
	 * authentication, headers, and token storage are all handled correctly
	 * by Site Kit's own code. After a successful refresh, the new token is
	 * stored in user meta by Site Kit itself and can be read back normally.
	 *
	 * @param int $owner_id  WP user ID.
	 * @return true|WP_Error  True on success.
	 */
	private static function refresh_token( $owner_id ) {
		// Site Kit's classes must be available (they will be if the plugin is active).
		if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' )
			|| ! class_exists( 'Google\Site_Kit\Context' ) ) {
			return new WP_Error(
				'seo_agent_ai_sitekit_no_classes',
				__( 'Site Kit classes not available for token refresh.', 'seo-agent-ai' )
			);
		}

		// Build Site Kit's dependency graph for the OAuth client.
		$context      = new \Google\Site_Kit\Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE );
		$options      = new \Google\Site_Kit\Core\Storage\Options( $context );
		$user_options = new \Google\Site_Kit\Core\Storage\User_Options( $context, $owner_id );
		$enc_options  = new \Google\Site_Kit\Core\Storage\Encrypted_Options( $options );
		$credentials  = new \Google\Site_Kit\Core\Authentication\Credentials( $enc_options );
		$google_proxy = new \Google\Site_Kit\Core\Authentication\Google_Proxy( $context );

		$oauth_client = new \Google\Site_Kit\Core\Authentication\Clients\OAuth_Client(
			$context,
			$options,
			$user_options,
			$credentials,
			$google_proxy
		);

		// Trigger the refresh — Site Kit stores the new token in user meta itself.
		$oauth_client->refresh_token();

		// Verify the refresh produced a usable access token.
		$new_token = $oauth_client->get_access_token();
		if ( empty( $new_token ) ) {
			return new WP_Error(
				'seo_agent_ai_sitekit_refresh_empty',
				__( 'Site Kit token refresh completed but no access token was returned. Please re-connect Site Kit.', 'seo-agent-ai' )
			);
		}

		return true;
	}

	// -----------------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------------

	/**
	 * WP user ID of the Site Kit owner (the admin who connected the plugin).
	 *
	 * @return int  0 if not set.
	 */
	public static function get_owner_id() {
		return (int) get_option( self::OPT_OWNER_ID, 0 );
	}

	/**
	 * Decrypt a value using Site Kit's encryption scheme.
	 *
	 * Site Kit uses AES-256-CTR (not CBC) with LOGGED_IN_KEY and appends
	 * LOGGED_IN_SALT as a plaintext suffix to validate integrity.
	 * See: google-site-kit/includes/Core/Storage/Data_Encryption.php
	 *
	 * @param string $raw_value Base64-encoded ciphertext as Site Kit stores it.
	 * @return string  Plaintext on success, empty string on failure.
	 */
	private static function sk_decrypt( $raw_value ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			// OpenSSL absent — Site Kit falls back to storing plaintext.
			return (string) $raw_value;
		}

		$decoded = base64_decode( $raw_value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( $decoded === false ) {
			return '';
		}

		$key    = defined( 'LOGGED_IN_KEY' )  ? LOGGED_IN_KEY  : 'das-ist-kein-geheimer-schluessel';
		$salt   = defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : 'das-ist-kein-geheimes-salz';
		$method = 'aes-256-ctr';
		$ivlen  = openssl_cipher_iv_length( $method );

		$iv  = substr( $decoded, 0, $ivlen );
		$enc = substr( $decoded, $ivlen );

		$plain = openssl_decrypt( $enc, $method, $key, 0, $iv );
		if ( $plain === false ) {
			return '';
		}

		// Integrity check: Site Kit appends the salt to the plaintext.
		if ( substr( $plain, - strlen( $salt ) ) !== $salt ) {
			return '';
		}

		return substr( $plain, 0, - strlen( $salt ) );
	}

	/**
	 * Encrypt a value using Site Kit's encryption scheme so we can write
	 * back refreshed tokens in Site Kit's own format.
	 *
	 * @param string $value Plaintext to encrypt.
	 * @return string  Base64-encoded ciphertext, or raw base64 if OpenSSL absent.
	 */
	private static function sk_encrypt( $value ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$key    = defined( 'LOGGED_IN_KEY' )  ? LOGGED_IN_KEY  : 'das-ist-kein-geheimer-schluessel';
		$salt   = defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : 'das-ist-kein-geheimes-salz';
		$method = 'aes-256-ctr';
		$ivlen  = openssl_cipher_iv_length( $method );
		$iv     = openssl_random_pseudo_bytes( $ivlen );

		$raw = openssl_encrypt( $value . $salt, $method, $key, 0, $iv );
		if ( $raw === false ) {
			return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return base64_encode( $iv . $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
