<?php
/**
 * Shared symmetric-encryption helper.
 *
 * Used to protect at-rest secrets in wp_options (OAuth client_secret, API
 * keys, refresh tokens). Falls back to base64 obfuscation when OpenSSL is
 * unavailable so the plugin keeps working but the value is no longer
 * meaningfully protected — that fallback is documented in the UI.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Crypto {

	/**
	 * Encrypt a value for storage. Returns '' for empty input.
	 *
	 * @param string $value Plaintext.
	 * @return string Ciphertext (base64) or base64 of plaintext if OpenSSL is missing.
	 */
	public static function encrypt( $value ) {
		$value = (string) $value;
		if ( $value === '' ) {
			return '';
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$key = self::derive_key();
			$iv  = openssl_random_pseudo_bytes( 16 );
			$enc = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( $enc !== false ) {
				return base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a previously-stored value. Tolerates legacy base64-only and
	 * legacy plaintext rows so existing installs keep working.
	 *
	 * @param string $value Ciphertext as produced by ::encrypt().
	 * @return string Plaintext.
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( $value === '' ) {
			return '';
		}

		$raw = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( $raw === false ) {
			return $value; // Plaintext legacy row.
		}

		if ( function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 16 ) {
			$key = self::derive_key();
			$iv  = substr( $raw, 0, 16 );
			$enc = substr( $raw, 16 );
			$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( $dec !== false ) {
				return $dec;
			}
		}

		return $raw;
	}

	/**
	 * Derive a 32-byte key from wp_salt(secure_auth).
	 */
	private static function derive_key() {
		return substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 32 );
	}

	/**
	 * Best-effort masking for display in admin UIs.
	 *
	 * @param string $value Stored value.
	 * @return string  e.g. "AIza••••••••••YwQk"
	 */
	public static function mask( $value ) {
		$value = (string) $value;
		$len   = strlen( $value );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $value, 0, 4 ) . str_repeat( '•', max( 4, $len - 8 ) ) . substr( $value, -4 );
	}
}
