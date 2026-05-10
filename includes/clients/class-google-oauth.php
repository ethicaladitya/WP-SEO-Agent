<?php
/**
 * Google OAuth 2.0 client.
 *
 * Manages the full OAuth lifecycle: authorization URL generation, callback
 * handling, token storage (with optional AES-256 encryption), automatic
 * access-token refresh, and disconnection.
 *
 * Required Google Cloud Console setup:
 *   - Scopes: webmasters.readonly, analytics.readonly, userinfo.email
 *   - Redirect URI: value returned by ::get_redirect_uri()
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Google_OAuth {

	// -----------------------------------------------------------------------
	// Option / transient keys
	// -----------------------------------------------------------------------

	const OPTION_CLIENT_ID        = 'seo_agent_ai_google_client_id';
	const OPTION_CLIENT_SECRET    = 'seo_agent_ai_google_client_secret';
	const OPTION_REFRESH_TOKEN    = 'seo_agent_ai_google_refresh_token';
	const OPTION_ACCESS_TOKEN     = 'seo_agent_ai_google_access_token';
	const OPTION_TOKEN_EXPIRES_AT = 'seo_agent_ai_google_access_token_expires_at';
	const OPTION_CONNECTED_EMAIL  = 'seo_agent_ai_google_connected_email';
	const TRANSIENT_OAUTH_STATE   = 'seo_agent_ai_oauth_state';

	// -----------------------------------------------------------------------
	// OAuth parameters
	// -----------------------------------------------------------------------

	const OAUTH_AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT       = 'https://oauth2.googleapis.com/token';
	const USERINFO_ENDPOINT    = 'https://www.googleapis.com/oauth2/v3/userinfo';
	const STATE_TTL_SECONDS    = 600; // 10 minutes

	/**
	 * OAuth scopes required by this plugin.
	 */
	private static $scopes = array(
		'https://www.googleapis.com/auth/webmasters.readonly',
		'https://www.googleapis.com/auth/analytics.readonly',
		'https://www.googleapis.com/auth/userinfo.email',
	);

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Check whether OAuth client credentials have been saved.
	 */
	public function is_configured() {
		return $this->get_client_id() !== '' && $this->get_client_secret() !== '';
	}

	/**
	 * Check whether a valid refresh token is stored, meaning the account is connected.
	 */
	public function is_connected() {
		return $this->get_stored_refresh_token() !== '';
	}

	/**
	 * Return the redirect URI that must be registered in Google Cloud Console.
	 */
	public function get_redirect_uri() {
		return admin_url( 'admin.php?page=seo-agent-ai-connect' );
	}

	/**
	 * Build the Google authorization URL with a CSRF state token.
	 *
	 * @return string|WP_Error
	 */
	public function get_authorize_url() {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'seo_agent_ai_oauth_not_configured',
				__( 'Enter your OAuth Client ID and Client Secret before connecting.', 'seo-agent-ai' )
			);
		}

		$state = wp_generate_password( 32, false );
		set_transient( self::TRANSIENT_OAUTH_STATE, $state, self::STATE_TTL_SECONDS );

		return add_query_arg(
			array(
				'client_id'     => rawurlencode( $this->get_client_id() ),
				'redirect_uri'  => rawurlencode( $this->get_redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( implode( ' ', self::$scopes ) ),
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => rawurlencode( $state ),
			),
			self::OAUTH_AUTH_ENDPOINT
		);
	}

	/**
	 * Exchange an authorization code received in the OAuth callback for tokens.
	 *
	 * @param string $code  Code from ?code= query param.
	 * @param string $state State token from ?state= query param.
	 * @return true|WP_Error
	 */
	public function handle_callback( $code, $state ) {
		$saved_state = get_transient( self::TRANSIENT_OAUTH_STATE );
		delete_transient( self::TRANSIENT_OAUTH_STATE );

		if ( $saved_state === false || ! hash_equals( (string) $saved_state, (string) $state ) ) {
			return new WP_Error(
				'seo_agent_ai_oauth_state_mismatch',
				__( 'OAuth state mismatch. Possible CSRF attempt. Please try connecting again.', 'seo-agent-ai' )
			);
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => sanitize_text_field( wp_unslash( (string) $code ) ),
					'client_id'     => $this->get_client_id(),
					'client_secret' => $this->get_client_secret(),
					'redirect_uri'  => $this->get_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seo_agent_ai_oauth_request_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || empty( $data['access_token'] ) ) {
			$msg = isset( $data['error_description'] ) ? (string) $data['error_description']
				: __( 'Token exchange failed.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_oauth_token_error', $msg );
		}

		$this->store_tokens( $data );
		$this->fetch_and_store_user_email( $data['access_token'] );
		$this->reset_auth_health_signals();

		return true;
	}

	/**
	 * Invalidate stale "auth failing" UI signals after a successful flow so the
	 * banner and the API-failure notice flip back to green immediately.
	 */
	private function reset_auth_health_signals() {
		delete_transient( 'seo_agent_ai_auth_health' );
		delete_option( 'seo_agent_ai_consecutive_api_failures' );
		delete_option( 'seo_agent_ai_last_api_error' );
	}

	/**
	 * Get a valid access token, refreshing automatically if expired.
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		$access_token = $this->decrypt( (string) get_option( self::OPTION_ACCESS_TOKEN, '' ) );

		if ( $access_token !== '' && ! $this->is_expired() ) {
			return $access_token;
		}

		$refresh_token = $this->get_stored_refresh_token();
		if ( $refresh_token === '' ) {
			return new WP_Error(
				'seo_agent_ai_not_connected',
				__( 'Google account is not connected. Go to SEO Agent AI → Connect Google to authenticate.', 'seo-agent-ai' )
			);
		}

		return $this->refresh_access_token( $refresh_token );
	}

	/**
	 * Revoke stored tokens and clear all auth options.
	 */
	public function disconnect() {
		delete_option( self::OPTION_ACCESS_TOKEN );
		delete_option( self::OPTION_REFRESH_TOKEN );
		delete_option( self::OPTION_TOKEN_EXPIRES_AT );
		delete_option( self::OPTION_CONNECTED_EMAIL );
		delete_transient( self::TRANSIENT_OAUTH_STATE );
	}

	/**
	 * Return the email address of the connected Google account.
	 */
	public function get_connected_email() {
		return (string) get_option( self::OPTION_CONNECTED_EMAIL, '' );
	}

	// -----------------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------------

	/**
	 * Exchange a refresh token for a new access token.
	 */
	private function refresh_access_token( $refresh_token ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $this->get_client_id(),
					'client_secret' => $this->get_client_secret(),
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seo_agent_ai_oauth_refresh_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || empty( $data['access_token'] ) ) {
			$msg = isset( $data['error_description'] ) ? (string) $data['error_description']
				: __( 'Could not refresh access token.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_oauth_refresh_error', $msg );
		}

		$access_token = sanitize_text_field( (string) $data['access_token'] );
		$expires_in   = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

		update_option( self::OPTION_ACCESS_TOKEN, $this->encrypt( $access_token ), false );
		update_option( self::OPTION_TOKEN_EXPIRES_AT, time() + max( 60, $expires_in - 60 ), false );

		return $access_token;
	}

	/**
	 * Persist access + refresh tokens from a token response payload.
	 */
	private function store_tokens( array $data ) {
		$access_token  = sanitize_text_field( (string) $data['access_token'] );
		$expires_in    = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

		update_option( self::OPTION_ACCESS_TOKEN, $this->encrypt( $access_token ), false );
		update_option( self::OPTION_TOKEN_EXPIRES_AT, time() + max( 60, $expires_in - 60 ), false );

		if ( ! empty( $data['refresh_token'] ) ) {
			$refresh_token = sanitize_text_field( (string) $data['refresh_token'] );
			update_option( self::OPTION_REFRESH_TOKEN, $this->encrypt( $refresh_token ), false );
		}
	}

	/**
	 * Fetch the authenticated user's email from Google and store it.
	 */
	private function fetch_and_store_user_email( $access_token ) {
		$response = wp_remote_get(
			self::USERINFO_ENDPOINT,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['email'] ) ) {
			update_option( self::OPTION_CONNECTED_EMAIL, sanitize_email( (string) $data['email'] ), false );
		}
	}

	/**
	 * Whether the stored access token has passed its expiry time.
	 */
	private function is_expired() {
		$expires_at = (int) get_option( self::OPTION_TOKEN_EXPIRES_AT, 0 );
		if ( $expires_at <= 0 ) {
			return false;
		}
		return time() >= $expires_at;
	}

	/**
	 * Decrypt the stored refresh token.
	 */
	private function get_stored_refresh_token() {
		$raw = (string) get_option( self::OPTION_REFRESH_TOKEN, '' );
		return $raw !== '' ? $this->decrypt( $raw ) : '';
	}

	private function encrypt( $value ) {
		return SEO_Agent_AI_Crypto::encrypt( $value );
	}

	private function decrypt( $value ) {
		return SEO_Agent_AI_Crypto::decrypt( $value );
	}

	private function get_client_id() {
		if ( defined( 'SEO_AGENT_AI_GOOGLE_CLIENT_ID' ) ) {
			$v = constant( 'SEO_AGENT_AI_GOOGLE_CLIENT_ID' );
			if ( is_string( $v ) && trim( $v ) !== '' ) {
				return trim( $v );
			}
		}
		return (string) get_option( self::OPTION_CLIENT_ID, '' );
	}

	private function get_client_secret() {
		if ( defined( 'SEO_AGENT_AI_GOOGLE_CLIENT_SECRET' ) ) {
			$v = constant( 'SEO_AGENT_AI_GOOGLE_CLIENT_SECRET' );
			if ( is_string( $v ) && trim( $v ) !== '' ) {
				return trim( $v );
			}
		}
		$stored = (string) get_option( self::OPTION_CLIENT_SECRET, '' );
		return $stored !== '' ? SEO_Agent_AI_Crypto::decrypt( $stored ) : '';
	}
}
