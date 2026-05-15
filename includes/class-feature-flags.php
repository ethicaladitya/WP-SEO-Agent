<?php
/**
 * Feature flags — runtime on/off toggles stored in wp_options.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Feature_Flags {

	const OPTION_PREFIX = 'seo_agent_ai_flag_';

	// Default enabled/disabled state for every flag.
	private static $defaults = array(
		'autopilot'             => false,
		'internal_link_engine'  => true,
		'schema_injection'      => true,
		'decay_detection'       => true,
		'cannibalization_check' => true,
		'scoring_engine'        => true,
		'gsc_analysis'          => true,
		'ga4_analysis'          => true,
		'opportunity_analyzer'  => true,
	);

	/**
	 * Check whether a feature flag is enabled.
	 *
	 * @param string $flag  Flag name (must be a key in $defaults).
	 * @return bool
	 */
	public static function is_enabled( $flag ) {
		$flag    = sanitize_key( $flag );
		$default = isset( self::$defaults[ $flag ] ) ? self::$defaults[ $flag ] : false;
		$stored  = get_option( self::OPTION_PREFIX . $flag, null );
		return null === $stored ? $default : (bool) $stored;
	}

	/**
	 * Enable or disable a feature flag.
	 *
	 * @param string $flag
	 * @param bool   $value
	 */
	public static function set( $flag, $value ) {
		update_option( self::OPTION_PREFIX . sanitize_key( $flag ), (bool) $value, false );
	}

	/**
	 * Return the current state of every known flag.
	 *
	 * @return array  Flag name => bool.
	 */
	public static function get_all() {
		$result = array();
		foreach ( array_keys( self::$defaults ) as $flag ) {
			$result[ $flag ] = self::is_enabled( $flag );
		}
		return $result;
	}

	/**
	 * Delete all stored flag options, reverting to defaults.
	 */
	public static function reset_all() {
		foreach ( array_keys( self::$defaults ) as $flag ) {
			delete_option( self::OPTION_PREFIX . sanitize_key( $flag ) );
		}
	}
}
