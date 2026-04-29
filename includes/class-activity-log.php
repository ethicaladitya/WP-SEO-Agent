<?php
/**
 * Activity log — custom DB table for SEO change history.
 *
 * Records every change made by the agent (manual or autopilot), the data
 * signals that led to it, and whether the change was later rolled back.
 * Provides a filterable, paginated read API for the Report admin page.
 *
 * Table: {prefix}seo_agent_ai_activity
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Activity_Log {

	const DB_VERSION_OPTION = 'seo_agent_ai_activity_db_v';
	const DB_VERSION        = 2;

	// Status values.
	const STATUS_APPLIED     = 'applied';
	const STATUS_ROLLED_BACK = 'rolled_back';
	const STATUS_SKIPPED     = 'skipped';

	// Trigger sources.
	const TRIGGER_MANUAL    = 'manual';
	const TRIGGER_AUTOPILOT = 'autopilot';
	const TRIGGER_ROLLBACK  = 'rollback';

	// -----------------------------------------------------------------------
	// Schema management
	// -----------------------------------------------------------------------

	/**
	 * Create or upgrade the activity log table.
	 * Safe to call on every activation; dbDelta handles idempotency.
	 */
	public static function create_table() {
		global $wpdb;

		$table         = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id      bigint(20) unsigned NOT NULL DEFAULT 0,
			change_type  varchar(60)  NOT NULL DEFAULT '',
			field_changed varchar(100) NOT NULL DEFAULT '',
			value_before longtext    NOT NULL,
			value_after  longtext    NOT NULL,
			reason       text        NOT NULL,
			signal_data  longtext    NOT NULL,
			confidence   decimal(4,3) NOT NULL DEFAULT '0.000',
			triggered_by varchar(20) NOT NULL DEFAULT 'manual',
			status       varchar(20) NOT NULL DEFAULT 'applied',
			created_at   datetime    NOT NULL,
			PRIMARY KEY (id),
			KEY idx_post_id    (post_id),
			KEY idx_created_at (created_at),
			KEY idx_triggered  (triggered_by),
			KEY idx_status     (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	// -----------------------------------------------------------------------
	// Write
	// -----------------------------------------------------------------------

	/**
	 * Record a single field change.
	 *
	 * @param int    $post_id       Target post ID (0 for global/system events).
	 * @param string $change_type   e.g. 'meta_update', 'monitor_decline', 'rollback'.
	 * @param string $field_changed e.g. 'meta_title', 'meta_description'.
	 * @param string $value_before  Previous value.
	 * @param string $value_after   New value.
	 * @param string $reason        Human-readable explanation (from recommendation).
	 * @param array  $signal_data   Signals/evidence array from analyzer.
	 * @param float  $confidence    0.0–1.0 confidence score.
	 * @param string $triggered_by  'manual' | 'autopilot' | 'rollback'.
	 */
	public function log(
		$post_id,
		$change_type,
		$field_changed,
		$value_before,
		$value_after,
		$reason,
		array $signal_data,
		$confidence,
		$triggered_by
	) {
		global $wpdb;

		$table = self::get_table_name();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'post_id'       => (int) $post_id,
				'change_type'   => sanitize_text_field( (string) $change_type ),
				'field_changed' => sanitize_text_field( (string) $field_changed ),
				'value_before'  => (string) $value_before,
				'value_after'   => (string) $value_after,
				'reason'        => sanitize_textarea_field( (string) $reason ),
				'signal_data'   => wp_json_encode( $signal_data ),
				'confidence'    => round( min( 1.0, max( 0.0, (float) $confidence ) ), 3 ),
				'triggered_by'  => sanitize_key( (string) $triggered_by ),
				'status'        => self::STATUS_APPLIED,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);
	}

	/**
	 * Update the status of a log entry (e.g. to 'rolled_back').
	 *
	 * @param int    $id     Log entry primary key.
	 * @param string $status New status string.
	 */
	public function update_status( $id, $status ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::get_table_name(),
			array( 'status' => sanitize_key( (string) $status ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	// -----------------------------------------------------------------------
	// Read
	// -----------------------------------------------------------------------

	/**
	 * Fetch a page of log entries, newest first.
	 *
	 * @param array $filters {
	 *   Optional. Associative filters:
	 *   @type int    post_id      Filter by post.
	 *   @type string change_type  Filter by type key.
	 *   @type string triggered_by Filter by trigger.
	 *   @type string date_from    MySQL date string (inclusive lower bound).
	 *   @type string date_to      MySQL date string (inclusive upper bound).
	 * }
	 * @param int $page     1-based page number.
	 * @param int $per_page Rows per page.
	 * @return array
	 */
	public function get_entries( array $filters = array(), $page = 1, $per_page = 20 ) {
		global $wpdb;

		$table  = self::get_table_name();
		$where  = $this->build_where( $filters );
		$offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;

		$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			(int) $per_page,
			$offset
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['signal_data'] = json_decode( (string) $row['signal_data'], true );
			if ( ! is_array( $row['signal_data'] ) ) {
				$row['signal_data'] = array();
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Count log entries matching optional filters.
	 *
	 * @param array $filters Same structure as get_entries().
	 * @return int
	 */
	public function get_count( array $filters = array() ) {
		global $wpdb;

		$table = self::get_table_name();
		$where = $this->build_where( $filters );

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} {$where}"
		);

		return (int) $count;
	}

	/**
	 * Get all log entries for a specific post, newest first.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public function get_post_history( $post_id ) {
		return $this->get_entries( array( 'post_id' => (int) $post_id ), 1, 50 );
	}

	/**
	 * Get a single log entry by ID.
	 *
	 * @param int $id
	 * @return array|null
	 */
	public function get_entry( $id ) {
		global $wpdb;

		$table = self::get_table_name();
		$sql   = $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['signal_data'] = json_decode( (string) $row['signal_data'], true );
		if ( ! is_array( $row['signal_data'] ) ) {
			$row['signal_data'] = array();
		}

		return $row;
	}

	/**
	 * Delete entries older than $days days.
	 *
	 * @param int $days
	 */
	public function purge_old_entries( $days ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
		$table  = self::get_table_name();
		$sql    = $wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < %s", $cutoff ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * @return string Fully-qualified table name with wpdb prefix.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'seo_agent_ai_activity';
	}

	/**
	 * Build a safe WHERE clause from filters.
	 */
	private function build_where( array $filters ) {
		global $wpdb;

		$clauses = array();

		if ( ! empty( $filters['post_id'] ) ) {
			$clauses[] = $wpdb->prepare( 'post_id = %d', (int) $filters['post_id'] );
		}

		if ( ! empty( $filters['change_type'] ) ) {
			$clauses[] = $wpdb->prepare( 'change_type = %s', sanitize_key( (string) $filters['change_type'] ) );
		}

		if ( ! empty( $filters['triggered_by'] ) ) {
			$clauses[] = $wpdb->prepare( 'triggered_by = %s', sanitize_key( (string) $filters['triggered_by'] ) );
		}

		if ( ! empty( $filters['status'] ) ) {
			$clauses[] = $wpdb->prepare( 'status = %s', sanitize_key( (string) $filters['status'] ) );
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$clauses[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( (string) $filters['date_from'] ) );
		}

		if ( ! empty( $filters['date_to'] ) ) {
			// Include the entire target day.
			$to_day    = sanitize_text_field( (string) $filters['date_to'] );
			$clauses[] = $wpdb->prepare( 'created_at < %s', gmdate( 'Y-m-d', strtotime( $to_day ) + DAY_IN_SECONDS ) );
		}

		if ( empty( $clauses ) ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $clauses );
	}
}
