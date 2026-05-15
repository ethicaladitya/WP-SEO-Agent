<?php
/**
 * Redirect Manager — 301/302 redirects and 404 logging.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Redirect_Manager {

	const TABLE_REDIRECTS = 'seo_agent_redirects';
	const TABLE_404_LOG   = 'seo_agent_404_log';

	const REDIRECT_CACHE_KEY = 'seo_agent_ai_redirect_list';
	const REDIRECT_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	// -------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------

	public function init_hooks() {
		add_action( 'template_redirect', array( $this, 'process_redirects' ), 1 );
		add_action( 'wp',                array( $this, 'init_404_logging' ) );
	}

	// -------------------------------------------------------------------
	// Table creation
	// -------------------------------------------------------------------

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$cc = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_REDIRECTS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_url varchar(500) NOT NULL DEFAULT '',
			target_url varchar(500) NOT NULL DEFAULT '',
			redirect_type smallint(5) unsigned NOT NULL DEFAULT 301,
			hit_count int(10) unsigned NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			notes varchar(500) DEFAULT '',
			PRIMARY KEY  (id),
			KEY source_url (source_url(191))
		) $cc;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_404_LOG . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(500) NOT NULL DEFAULT '',
			referrer varchar(500) DEFAULT NULL,
			hit_count int(10) unsigned NOT NULL DEFAULT 1,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			redirect_created tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url (url(191))
		) $cc;" );
	}

	// -------------------------------------------------------------------
	// 404 logging
	// -------------------------------------------------------------------

	/**
	 * Log a 404 URL.
	 *
	 * @param string $url      The requested URL.
	 * @param string $referrer HTTP referrer.
	 */
	public function log_404( $url, $referrer = '' ) {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE_404_LOG;
		$url     = substr( sanitize_text_field( $url ), 0, 500 );
		$ref     = $referrer ? substr( sanitize_text_field( $referrer ), 0, 500 ) : null;
		$now     = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, hit_count FROM `{$table}` WHERE url = %s LIMIT 1",
			$url
		) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'hit_count' => $existing->hit_count + 1,
					'last_seen' => $now,
					'referrer'  => $ref,
				),
				array( 'id' => $existing->id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'url'        => $url,
					'referrer'   => $ref,
					'hit_count'  => 1,
					'first_seen' => $now,
					'last_seen'  => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Get 404 log entries, ordered by hit count.
	 *
	 * @param int $limit  Number of rows.
	 * @param int $offset Offset.
	 * @return array[]
	 */
	public function get_404_log( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_404_LOG;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$table}` ORDER BY hit_count DESC LIMIT %d OFFSET %d",
			(int) $limit,
			(int) $offset
		), ARRAY_A );
	}

	// -------------------------------------------------------------------
	// Redirects CRUD
	// -------------------------------------------------------------------

	/**
	 * Add a redirect rule.
	 *
	 * @param string $source Source URL path or full URL.
	 * @param string $target Target URL.
	 * @param int    $type   HTTP status code (301 or 302).
	 * @param string $notes  Optional notes.
	 * @return int|false Inserted row ID or false on error.
	 */
	public function add_redirect( $source, $target, $type = 301, $notes = '' ) {
		global $wpdb;

		$source = esc_url_raw( $source );
		$target = esc_url_raw( $target );
		$type   = in_array( (int) $type, array( 301, 302 ), true ) ? (int) $type : 301;
		$notes  = substr( sanitize_text_field( $notes ), 0, 500 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE_REDIRECTS,
			array(
				'source_url'    => substr( $source, 0, 500 ),
				'target_url'    => substr( $target, 0, 500 ),
				'redirect_type' => $type,
				'hit_count'     => 0,
				'created_at'    => current_time( 'mysql' ),
				'notes'         => $notes,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( $result ) {
			delete_transient( self::REDIRECT_CACHE_KEY );
			return (int) $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get all redirect rules.
	 *
	 * @param int $limit  Number of rows.
	 * @param int $offset Offset.
	 * @return array[]
	 */
	public function get_redirects( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_REDIRECTS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
			(int) $limit,
			(int) $offset
		), ARRAY_A );
	}

	/**
	 * Delete a redirect rule by ID.
	 *
	 * @param int $id Row ID.
	 * @return bool True on success.
	 */
	public function delete_redirect( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete(
			$wpdb->prefix . self::TABLE_REDIRECTS,
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		if ( $result ) {
			delete_transient( self::REDIRECT_CACHE_KEY );
		}

		return (bool) $result;
	}

	// -------------------------------------------------------------------
	// Runtime redirect processing
	// -------------------------------------------------------------------

	/**
	 * Process redirects on every request. Hooked on template_redirect, priority 1.
	 */
	public function process_redirects() {
		global $wpdb;

		// Load redirect list from cache or DB.
		$redirects = get_transient( self::REDIRECT_CACHE_KEY );

		if ( false === $redirects ) {
			$table = $wpdb->prefix . self::TABLE_REDIRECTS;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$redirects = $wpdb->get_results(
				"SELECT id, source_url, target_url, redirect_type FROM `{$table}` ORDER BY id ASC",
				ARRAY_A
			);
			set_transient( self::REDIRECT_CACHE_KEY, $redirects, self::REDIRECT_CACHE_TTL );
		}

		if ( empty( $redirects ) ) {
			return;
		}

		$current_url = home_url( add_query_arg( null, null ) );
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		foreach ( $redirects as $redirect ) {
			$source = $redirect['source_url'];

			// Match against full URL or just the path.
			if ( $current_url === $source || $request_uri === $source || untrailingslashit( $current_url ) === untrailingslashit( $source ) ) {
				// Update hit count asynchronously (best-effort).
				$table = $wpdb->prefix . self::TABLE_REDIRECTS;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare(
					"UPDATE `{$table}` SET hit_count = hit_count + 1, last_hit = %s WHERE id = %d",
					current_time( 'mysql' ),
					(int) $redirect['id']
				) );

				wp_redirect( esc_url_raw( $redirect['target_url'] ), (int) $redirect['redirect_type'] );
				exit;
			}
		}
	}

	// -------------------------------------------------------------------
	// 404 logging hook
	// -------------------------------------------------------------------

	/**
	 * Log 404 requests. Hooked on `wp` action.
	 */
	public function init_404_logging() {
		if ( ! is_404() ) {
			return;
		}

		$url      = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		$this->log_404( $url, $referrer );
	}

	// -------------------------------------------------------------------
	// Stats
	// -------------------------------------------------------------------

	/**
	 * Get overall stats.
	 *
	 * @return array{total_redirects: int, total_404s: int, unresolved_404s: int}
	 */
	public function get_stats() {
		global $wpdb;

		$r_table = $wpdb->prefix . self::TABLE_REDIRECTS;
		$l_table = $wpdb->prefix . self::TABLE_404_LOG;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_redirects  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$r_table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_404s       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$l_table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$unresolved_404s  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$l_table}` WHERE redirect_created = 0" );

		return compact( 'total_redirects', 'total_404s', 'unresolved_404s' );
	}
}
