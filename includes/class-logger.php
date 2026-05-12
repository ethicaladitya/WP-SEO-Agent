<?php
/**
 * Debug / verbose logger.
 *
 * Writes to a dedicated log file in wp-content/ and optionally mirrors to
 * the native WordPress debug log. Rotation kicks in when the file exceeds 5 MB.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Logger {

	const OPTION_DEBUG_MODE   = 'seo_agent_ai_debug_mode';
	const OPTION_VERBOSE_MODE = 'seo_agent_ai_verbose_mode';

	const LEVEL_DEBUG   = 'DEBUG';
	const LEVEL_INFO    = 'INFO';
	const LEVEL_WARNING = 'WARNING';
	const LEVEL_ERROR   = 'ERROR';

	const LOG_BASENAME = 'seo-agent-ai-debug.log';
	const MAX_BYTES    = 5242880; // 5 MB

	/** @var bool */
	private $debug;
	/** @var bool */
	private $verbose;
	/** @var string */
	private $log_path;

	/**
	 * @param bool|null $debug   Override option (useful for WP-CLI --verbose flag).
	 * @param bool|null $verbose Override option.
	 */
	public function __construct( $debug = null, $verbose = null ) {
		$this->debug    = $debug   !== null ? (bool) $debug   : (bool) get_option( self::OPTION_DEBUG_MODE, false );
		$this->verbose  = $verbose !== null ? (bool) $verbose : (bool) get_option( self::OPTION_VERBOSE_MODE, false );
		$this->log_path = WP_CONTENT_DIR . '/' . self::LOG_BASENAME;
	}

	// -------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------

	public function info( $message, array $context = array() ) {
		$this->write( self::LEVEL_INFO, $message, $context );
	}

	public function debug( $message, array $context = array() ) {
		if ( $this->debug ) {
			$this->write( self::LEVEL_DEBUG, $message, $context );
		}
	}

	public function verbose( $message, array $context = array() ) {
		if ( $this->verbose || $this->debug ) {
			$this->write( self::LEVEL_DEBUG, '[verbose] ' . $message, $context );
		}
	}

	public function warning( $message, array $context = array() ) {
		$this->write( self::LEVEL_WARNING, $message, $context );
	}

	public function error( $message, array $context = array() ) {
		$this->write( self::LEVEL_ERROR, $message, $context );
	}

	// -------------------------------------------------------------------
	// Log reading (for WP-CLI + admin Cron Status page)
	// -------------------------------------------------------------------

	/**
	 * Return the last N lines from the log file, optionally filtered by level.
	 *
	 * @param int    $lines
	 * @param string $level_filter  One of DEBUG, INFO, WARNING, ERROR, or '' for all.
	 * @return string[]
	 */
	public function tail( $lines = 50, $level_filter = '' ) {
		if ( ! file_exists( $this->log_path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = @file_get_contents( $this->log_path );
		if ( ! $raw ) {
			return array();
		}

		$all = array_filter( explode( "\n", $raw ) );

		if ( $level_filter ) {
			$tag = '[' . strtoupper( $level_filter ) . ']';
			$all = array_filter( $all, function( $l ) use ( $tag ) {
				return strpos( $l, $tag ) !== false;
			} );
		}

		return array_values( array_slice( array_values( $all ), -$lines ) );
	}

	/**
	 * Return full path to the current log file.
	 */
	public function get_log_path() {
		return $this->log_path;
	}

	/**
	 * Enable debug mode at runtime (e.g. from WP-CLI --verbose).
	 */
	public function enable_debug() {
		$this->debug   = true;
		$this->verbose = true;
	}

	// -------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------

	private function write( $level, $message, array $context = array() ) {
		$this->maybe_rotate();

		$ts   = gmdate( 'Y-m-d H:i:s' );
		$line = "[{$ts}] [SEO-AGENT] [{$level}] {$message}";

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $this->log_path, $line . "\n", FILE_APPEND | LOCK_EX );

		// Mirror to native WP debug log.
		if (
			$this->debug &&
			defined( 'WP_DEBUG' ) && WP_DEBUG &&
			defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG
		) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $line );
		}
	}

	private function maybe_rotate() {
		if ( ! file_exists( $this->log_path ) ) {
			return;
		}
		if ( @filesize( $this->log_path ) > self::MAX_BYTES ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			@rename( $this->log_path, $this->log_path . '.1' );
		}
	}
}
