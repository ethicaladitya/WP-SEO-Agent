<?php
/**
 * Database manager — creates and upgrades all custom tables beyond the
 * original activity-log table (which SEO_Agent_AI_Activity_Log still owns).
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_DB_Manager {

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'seo_agent_ai_db_manager_v';

	// Table suffixes (no prefix).
	const TABLE_KEYWORD_HISTORY = 'seo_agent_keyword_history';
	const TABLE_PAGE_INSIGHTS   = 'seo_agent_page_insights';
	const TABLE_AI_DECISIONS    = 'seo_agent_ai_decisions';
	const TABLE_DAILY_REPORTS   = 'seo_agent_daily_reports';
	const TABLE_INTERNAL_LINKS  = 'seo_agent_internal_links';

	// AI decision statuses.
	const STATUS_PENDING   = 'pending';
	const STATUS_APPROVED  = 'approved';
	const STATUS_REJECTED  = 'rejected';
	const STATUS_APPLIED   = 'applied';
	const STATUS_DISCARDED = 'discarded';

	// -------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------

	/**
	 * Create or upgrade all custom tables.
	 * Safe to call repeatedly — dbDelta handles idempotency.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$cc = $wpdb->get_charset_collate();

		// 1. Keyword ranking history — one row per (post, keyword, date).
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_KEYWORD_HISTORY . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			keyword varchar(500) NOT NULL DEFAULT '',
			position decimal(8,2) DEFAULT NULL,
			impressions int(10) unsigned NOT NULL DEFAULT 0,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			ctr decimal(6,4) NOT NULL DEFAULT '0.0000',
			recorded_at date NOT NULL,
			PRIMARY KEY  (id),
			KEY post_recorded (post_id, recorded_at),
			KEY kw_recorded (keyword(100), recorded_at)
		) $cc;" );

		// 2. Per-page SEO score snapshots (7 dimensions + overall).
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_PAGE_INSIGHTS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			score_overall tinyint(3) unsigned NOT NULL DEFAULT 0,
			score_metadata tinyint(3) unsigned NOT NULL DEFAULT 0,
			score_content tinyint(3) unsigned NOT NULL DEFAULT 0,
			score_internal_links tinyint(3) unsigned NOT NULL DEFAULT 0,
			score_schema tinyint(3) unsigned NOT NULL DEFAULT 0,
			score_engagement tinyint(3) unsigned NOT NULL DEFAULT 0,
			score_freshness tinyint(3) unsigned NOT NULL DEFAULT 0,
			signal_data longtext DEFAULT NULL,
			recorded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY recorded_at (recorded_at)
		) $cc;" );

		// 3. AI decision queue — pending approvals + historical decisions.
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_AI_DECISIONS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			decision_type varchar(60) NOT NULL DEFAULT '',
			field varchar(100) DEFAULT NULL,
			proposed_value longtext DEFAULT NULL,
			current_value longtext DEFAULT NULL,
			confidence decimal(4,3) NOT NULL DEFAULT '0.000',
			reasoning text DEFAULT NULL,
			expected_impact varchar(200) DEFAULT NULL,
			risk_level varchar(20) NOT NULL DEFAULT 'safe',
			status varchar(20) NOT NULL DEFAULT 'pending',
			approved_by bigint(20) unsigned DEFAULT NULL,
			reviewed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status),
			KEY created_at (created_at)
		) $cc;" );

		// 4. Daily generated reports — one row per calendar day.
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_DAILY_REPORTS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			report_date date NOT NULL,
			report_data longtext NOT NULL,
			pages_analyzed int(10) unsigned NOT NULL DEFAULT 0,
			pages_optimized int(10) unsigned NOT NULL DEFAULT 0,
			opportunities_detected int(10) unsigned NOT NULL DEFAULT 0,
			problems_detected int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY report_date (report_date)
		) $cc;" );

		// 5. Internal link tracking — links added by the plugin.
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_INTERNAL_LINKS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			anchor_text varchar(500) NOT NULL DEFAULT '',
			context_snippet text DEFAULT NULL,
			added_by varchar(20) NOT NULL DEFAULT 'plugin',
			added_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			KEY source_post_id (source_post_id),
			KEY target_post_id (target_post_id),
			KEY status (status)
		) $cc;" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Run upgrade only when the stored version is behind current.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Drop all managed tables. Called from uninstall.php.
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			self::TABLE_KEYWORD_HISTORY,
			self::TABLE_PAGE_INSIGHTS,
			self::TABLE_AI_DECISIONS,
			self::TABLE_DAILY_REPORTS,
			self::TABLE_INTERNAL_LINKS,
		);

		foreach ( $tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$t}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		delete_option( self::DB_VERSION_OPTION );
	}

	// -------------------------------------------------------------------
	// Table-name helpers
	// -------------------------------------------------------------------

	public static function keyword_history_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_KEYWORD_HISTORY;
	}

	public static function page_insights_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_PAGE_INSIGHTS;
	}

	public static function ai_decisions_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_AI_DECISIONS;
	}

	public static function daily_reports_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_DAILY_REPORTS;
	}

	public static function internal_links_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_INTERNAL_LINKS;
	}

	// -------------------------------------------------------------------
	// AI Decisions CRUD
	// -------------------------------------------------------------------

	/**
	 * Insert a new decision record.
	 *
	 * @param array $data Keys: post_id, decision_type, field, proposed_value,
	 *                    current_value, confidence, reasoning, expected_impact,
	 *                    risk_level. Status defaults to 'pending'.
	 * @return int|false Inserted row ID or false.
	 */
	public static function insert_decision( array $data ) {
		global $wpdb;

		$post_id = (int) ( $data['post_id'] ?? 0 );
		$type    = sanitize_text_field( $data['decision_type'] ?? '' );
		$field   = sanitize_text_field( $data['field'] ?? '' );
		$table   = self::ai_decisions_table();

		// Deduplicate: if a pending decision for this post+type+field already exists, return that ID.
		$existing_id = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT id FROM {$table} WHERE post_id = %d AND decision_type = %s AND field = %s AND status = %s LIMIT 1",
			$post_id,
			$type,
			$field,
			self::STATUS_PENDING
		) );

		if ( $existing_id ) {
			// Update confidence and proposed value in case it improved.
			$wpdb->update( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'confidence'      => round( (float) ( $data['confidence'] ?? 0 ), 3 ),
				'proposed_value'  => $data['proposed_value'] ?? '',
				'reasoning'       => sanitize_textarea_field( $data['reasoning'] ?? '' ),
			), array( 'id' => (int) $existing_id ) );
			return (int) $existing_id;
		}

		$row = array(
			'post_id'         => $post_id,
			'decision_type'   => $type,
			'field'           => $field,
			'proposed_value'  => $data['proposed_value'] ?? '',
			'current_value'   => $data['current_value'] ?? '',
			'confidence'      => round( (float) ( $data['confidence'] ?? 0 ), 3 ),
			'reasoning'       => sanitize_textarea_field( $data['reasoning'] ?? '' ),
			'expected_impact' => sanitize_text_field( $data['expected_impact'] ?? '' ),
			'risk_level'      => sanitize_text_field( $data['risk_level'] ?? 'safe' ),
			'status'          => self::STATUS_PENDING,
			'created_at'      => current_time( 'mysql', true ),
		);

		$result = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a decision's status (approve/reject/apply/discard).
	 *
	 * @param int    $id     Decision row ID.
	 * @param string $status New status constant.
	 * @param int    $user_id WP user performing the action (0 for system).
	 */
	public static function update_decision_status( $id, $status, $user_id = 0 ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::ai_decisions_table(),
			array(
				'status'      => sanitize_text_field( $status ),
				'approved_by' => $user_id ? (int) $user_id : null,
				'reviewed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Fetch decisions, newest first.
	 *
	 * @param array $args Optional: post_id, risk_level, date_from, date_to, limit, offset.
	 * @return array
	 */
	public static function get_decisions( array $args = array() ) {
		global $wpdb;

		$status  = $args['status'] ?? self::STATUS_PENDING;
		$limit   = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$offset  = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where   = $wpdb->prepare( 'status = %s', $status );

		if ( ! empty( $args['post_id'] ) ) {
			$where .= $wpdb->prepare( ' AND post_id = %d', $args['post_id'] );
		}
		if ( ! empty( $args['risk_level'] ) ) {
			$where .= $wpdb->prepare( ' AND risk_level = %s', $args['risk_level'] );
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where .= $wpdb->prepare( ' AND created_at >= %s', $args['date_from'] );
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where .= $wpdb->prepare( ' AND created_at <= %s', $args['date_to'] );
		}

		$table = self::ai_decisions_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT {$offset}, {$limit}",
			ARRAY_A
		) ?: array();
	}

	/**
	 * Count decisions by status.
	 */
	public static function count_decisions( $status = '' ) {
		global $wpdb;
		$table = self::ai_decisions_table();
		if ( $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	// -------------------------------------------------------------------
	// Keyword history CRUD
	// -------------------------------------------------------------------

	/**
	 * Upsert a keyword history row for today.
	 *
	 * @param int    $post_id
	 * @param string $keyword
	 * @param array  $data    Keys: position, impressions, clicks, ctr.
	 */
	public static function upsert_keyword_history( $post_id, $keyword, array $data ) {
		global $wpdb;

		$today = gmdate( 'Y-m-d' );
		$table = self::keyword_history_table();

		$existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT id FROM {$table} WHERE post_id = %d AND keyword = %s AND recorded_at = %s LIMIT 1",
			$post_id,
			$keyword,
			$today
		) );

		$row = array(
			'position'    => isset( $data['position'] ) ? round( (float) $data['position'], 2 ) : null,
			'impressions' => (int) ( $data['impressions'] ?? 0 ),
			'clicks'      => (int) ( $data['clicks'] ?? 0 ),
			'ctr'         => round( (float) ( $data['ctr'] ?? 0 ), 4 ),
		);

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$row['post_id']     = (int) $post_id;
			$row['keyword']     = $keyword;
			$row['recorded_at'] = $today;
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Get keyword position trend for a post (last N days).
	 *
	 * @param int $post_id
	 * @param int $days
	 * @return array Array of rows with keyword, position, recorded_at.
	 */
	public static function get_keyword_trend( $post_id, $days = 30 ) {
		global $wpdb;
		$table = self::keyword_history_table();
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT keyword, position, impressions, clicks, ctr, recorded_at
			 FROM {$table}
			 WHERE post_id = %d AND recorded_at >= %s
			 ORDER BY recorded_at ASC, impressions DESC",
			$post_id,
			$since
		), ARRAY_A ) ?: array();
	}

	// -------------------------------------------------------------------
	// Page insights CRUD
	// -------------------------------------------------------------------

	/**
	 * Insert a page insight snapshot.
	 *
	 * @param int   $post_id
	 * @param array $scores  Keys: overall, metadata, content, internal_links, schema, engagement, freshness.
	 * @param array $signals Raw signal data array (will be JSON-encoded).
	 */
	public static function insert_page_insight( $post_id, array $scores, array $signals = array() ) {
		global $wpdb;

		$clamp = function( $v ) { return max( 0, min( 100, (int) $v ) ); };

		$wpdb->insert( self::page_insights_table(), array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'post_id'             => (int) $post_id,
			'score_overall'       => $clamp( $scores['overall'] ?? 0 ),
			'score_metadata'      => $clamp( $scores['metadata'] ?? 0 ),
			'score_content'       => $clamp( $scores['content'] ?? 0 ),
			'score_internal_links'=> $clamp( $scores['internal_links'] ?? 0 ),
			'score_schema'        => $clamp( $scores['schema'] ?? 0 ),
			'score_engagement'    => $clamp( $scores['engagement'] ?? 0 ),
			'score_freshness'     => $clamp( $scores['freshness'] ?? 0 ),
			'signal_data'         => wp_json_encode( $signals ),
			'recorded_at'         => current_time( 'mysql', true ),
		) );
	}

	/**
	 * Upsert a page insight from ScoringEngine::score() output.
	 * One row per post per calendar day — updates if today's row already exists.
	 *
	 * @param int   $post_id
	 * @param array $score_data  Keys: overall (int), dimensions (array), signals (array), improvements (array).
	 */
	public static function upsert_page_insight( $post_id, array $score_data ) {
		global $wpdb;

		$clamp = function( $v ) { return max( 0, min( 100, (int) round( (float) $v ) ) ); };
		$dims  = isset( $score_data['dimensions'] ) && is_array( $score_data['dimensions'] ) ? $score_data['dimensions'] : array();
		$today = gmdate( 'Y-m-d' );
		$table = self::page_insights_table();

		$existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT id FROM {$table} WHERE post_id = %d AND DATE(recorded_at) = %s LIMIT 1",
			(int) $post_id,
			$today
		) );

		$row = array(
			'score_overall'        => $clamp( $score_data['overall'] ?? 0 ),
			'score_metadata'       => $clamp( $dims['metadata'] ?? 0 ),
			'score_content'        => $clamp( $dims['content'] ?? 0 ),
			'score_internal_links' => $clamp( $dims['internal_links'] ?? 0 ),
			'score_schema'         => $clamp( $dims['schema'] ?? 0 ),
			'score_engagement'     => $clamp( $dims['engagement'] ?? 0 ),
			'score_freshness'      => $clamp( $dims['freshness'] ?? 0 ),
			'signal_data'          => wp_json_encode( array(
				'signals'      => $score_data['signals'] ?? array(),
				'improvements' => $score_data['improvements'] ?? array(),
			) ),
			'recorded_at'          => current_time( 'mysql', true ),
		);

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$row['post_id'] = (int) $post_id;
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Update the engagement score on the most recent insight row from GA4 landing page data.
	 *
	 * @param int   $post_id
	 * @param array $item  Keys: engagement_rate (0-1 float), bounce_rate (0-1 float), avg_time_sec (int), sessions (int).
	 */
	public static function upsert_page_insight_engagement( $post_id, array $item ) {
		global $wpdb;
		$table = self::page_insights_table();

		$existing_id = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT id FROM {$table} WHERE post_id = %d ORDER BY recorded_at DESC LIMIT 1",
			(int) $post_id
		) );

		$engagement_score = self::calc_engagement_score( $item );

		if ( ! $existing_id ) {
			$wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'post_id'              => (int) $post_id,
				'score_overall'        => $engagement_score,
				'score_metadata'       => 0,
				'score_content'        => 0,
				'score_internal_links' => 0,
				'score_schema'         => 0,
				'score_engagement'     => $engagement_score,
				'score_freshness'      => 0,
				'signal_data'          => wp_json_encode( array( 'ga4' => $item ) ),
				'recorded_at'          => current_time( 'mysql', true ),
			) );
			return;
		}

		$wpdb->update( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'score_engagement' => $engagement_score,
		), array( 'id' => (int) $existing_id ) );
	}

	/**
	 * Purge records older than the retention window across all tables.
	 * Keeps pending decisions regardless of age.
	 *
	 * @param int $retention_days  Minimum 7 days.
	 */
	public static function purge_old_data( $retention_days = 90 ) {
		global $wpdb;

		$days        = max( 7, (int) $retention_days );
		$cutoff_dt   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$kh = self::keyword_history_table();
		$pi = self::page_insights_table();
		$ad = self::ai_decisions_table();
		$dr = self::daily_reports_table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$kh} WHERE recorded_at < %s", $cutoff_date ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$pi} WHERE recorded_at < %s", $cutoff_dt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$ad} WHERE status IN ('applied','rejected','discarded') AND created_at < %s", $cutoff_dt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$dr} WHERE report_date < %s", $cutoff_date ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Get the latest insight snapshot for a post.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function get_latest_insight( $post_id ) {
		global $wpdb;
		$table = self::page_insights_table();
		$row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT * FROM {$table} WHERE post_id = %d ORDER BY recorded_at DESC LIMIT 1",
			$post_id
		), ARRAY_A );

		if ( $row && ! empty( $row['signal_data'] ) ) {
			$row['signal_data'] = json_decode( $row['signal_data'], true );
		}
		return $row ?: null;
	}

	/**
	 * Get insight history for a post (last N snapshots).
	 *
	 * @param int $post_id
	 * @param int $limit
	 * @return array
	 */
	public static function get_insight_history( $post_id, $limit = 30 ) {
		global $wpdb;
		$table = self::page_insights_table();
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT * FROM {$table} WHERE post_id = %d ORDER BY recorded_at DESC LIMIT %d",
			$post_id,
			$limit
		), ARRAY_A ) ?: array();
	}

	// -------------------------------------------------------------------
	// Daily reports CRUD
	// -------------------------------------------------------------------

	/**
	 * Upsert a daily report row.
	 *
	 * @param string $date   YYYY-MM-DD.
	 * @param array  $report Report data array (will be JSON-encoded).
	 * @param array  $counts Keys: pages_analyzed, pages_optimized, opportunities_detected, problems_detected.
	 */
	public static function upsert_daily_report( $date, array $report, array $counts = array() ) {
		global $wpdb;
		$table = self::daily_reports_table();

		$row = array(
			'report_date'            => sanitize_text_field( $date ),
			'report_data'            => wp_json_encode( $report ),
			'pages_analyzed'         => (int) ( $counts['pages_analyzed'] ?? 0 ),
			'pages_optimized'        => (int) ( $counts['pages_optimized'] ?? 0 ),
			'opportunities_detected' => (int) ( $counts['opportunities_detected'] ?? 0 ),
			'problems_detected'      => (int) ( $counts['problems_detected'] ?? 0 ),
			'created_at'             => current_time( 'mysql', true ),
		);

		$existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT id FROM {$table} WHERE report_date = %s LIMIT 1",
			$date
		) );

		if ( $existing ) {
			unset( $row['created_at'] ); // Don't overwrite creation timestamp.
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Get a daily report by date.
	 *
	 * @param string $date YYYY-MM-DD. Defaults to today.
	 * @return array|null
	 */
	public static function get_daily_report( $date = '' ) {
		global $wpdb;
		$date  = $date ?: gmdate( 'Y-m-d' );
		$table = self::daily_reports_table();
		$row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT * FROM {$table} WHERE report_date = %s LIMIT 1",
			$date
		), ARRAY_A );

		if ( $row && ! empty( $row['report_data'] ) ) {
			$row['report_data'] = json_decode( $row['report_data'], true );
		}
		return $row ?: null;
	}

	/**
	 * Get a list of available report dates.
	 *
	 * @param int $limit
	 * @return array Array of date strings.
	 */
	public static function get_report_dates( $limit = 30 ) {
		global $wpdb;
		$table = self::daily_reports_table();
		return $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT report_date FROM {$table} ORDER BY report_date DESC LIMIT %d",
			$limit
		) ) ?: array();
	}

	// -------------------------------------------------------------------
	// Internal links CRUD
	// -------------------------------------------------------------------

	/**
	 * Log a plugin-inserted internal link.
	 *
	 * @param int    $source_post_id
	 * @param int    $target_post_id
	 * @param string $anchor_text
	 * @param string $context_snippet Short excerpt around the link.
	 * @param string $added_by        'plugin'|'manual'.
	 * @return int|false
	 */
	public static function insert_internal_link( $source_post_id, $target_post_id, $anchor_text, $context_snippet = '', $added_by = 'plugin' ) {
		global $wpdb;

		$result = $wpdb->insert( self::internal_links_table(), array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'source_post_id' => (int) $source_post_id,
			'target_post_id' => (int) $target_post_id,
			'anchor_text'    => sanitize_text_field( $anchor_text ),
			'context_snippet'=> sanitize_textarea_field( $context_snippet ),
			'added_by'       => sanitize_text_field( $added_by ),
			'added_at'       => current_time( 'mysql', true ),
			'status'         => 'active',
		) );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get links added for a post (as source or target).
	 *
	 * @param int    $post_id
	 * @param string $direction 'source'|'target'|'both'.
	 * @return array
	 */
	public static function get_post_links( $post_id, $direction = 'source' ) {
		global $wpdb;
		$table = self::internal_links_table();

		if ( $direction === 'target' ) {
			$where = $wpdb->prepare( 'target_post_id = %d', $post_id );
		} elseif ( $direction === 'both' ) {
			$where = $wpdb->prepare( 'source_post_id = %d OR target_post_id = %d', $post_id, $post_id );
		} else {
			$where = $wpdb->prepare( 'source_post_id = %d', $post_id );
		}

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT * FROM {$table} WHERE {$where} AND status = 'active' ORDER BY added_at DESC",
			ARRAY_A
		) ?: array();
	}

	/**
	 * Mark a plugin-inserted link as removed.
	 *
	 * @param int $link_id
	 */
	public static function deactivate_link( $link_id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::internal_links_table(),
			array( 'status' => 'removed' ),
			array( 'id' => (int) $link_id )
		);
	}

	/**
	 * Purge old keyword history beyond retention window.
	 *
	 * @param int $days
	 */
	public static function purge_keyword_history( $days = 365 ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$table  = self::keyword_history_table();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"DELETE FROM {$table} WHERE recorded_at < %s",
			$cutoff
		) );
	}

	/**
	 * Get the latest page insight snapshot for every post.
	 *
	 * @param int $limit Max rows to return.
	 * @return array[]
	 */
	public static function get_all_latest_insights( $limit = 200 ) {
		global $wpdb;
		$table = self::page_insights_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT pi.* FROM {$table} pi
			 INNER JOIN (
			     SELECT post_id, MAX(recorded_at) AS max_at
			     FROM {$table}
			     GROUP BY post_id
			 ) latest ON pi.post_id = latest.post_id AND pi.recorded_at = latest.max_at
			 ORDER BY pi.post_id
			 LIMIT %d",
			(int) $limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get activity log entries created within a datetime range.
	 *
	 * @param string $date_from 'Y-m-d H:i:s'
	 * @param string $date_to   'Y-m-d H:i:s'
	 * @return array[]
	 */
	public static function get_activity_for_range( $date_from, $date_to ) {
		global $wpdb;
		$table = SEO_Agent_AI_Activity_Log::get_table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY created_at DESC LIMIT 500",
			$date_from,
			$date_to
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Alias kept for callers that used the old name.
	 *
	 * @param array $data Flat array with report_date, report_data, and count keys.
	 */
	public static function upsert_report( array $data ) {
		$date   = $data['report_date'] ?? gmdate( 'Y-m-d' );
		$report = isset( $data['report_data'] ) ? json_decode( $data['report_data'], true ) : array();
		$counts = array(
			'pages_analyzed'         => $data['pages_analyzed'] ?? 0,
			'pages_optimized'        => $data['pages_optimized'] ?? 0,
			'opportunities_detected' => $data['opportunities_detected'] ?? 0,
			'problems_detected'      => $data['problems_detected'] ?? 0,
		);
		self::upsert_daily_report( $date, $report, $counts );
	}

	// -------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------

	/**
	 * Derive a 0-100 engagement score from GA4 landing page quality metrics.
	 *
	 * @param array $item  Keys: engagement_rate (0-1), avg_time_sec, bounce_rate (0-1).
	 * @return int
	 */
	private static function calc_engagement_score( array $item ) {
		$engagement_rate = (float) ( $item['engagement_rate'] ?? 0.0 );
		$avg_time        = (int)   ( $item['avg_time_sec']    ?? 0 );
		$bounce_rate     = (float) ( $item['bounce_rate']     ?? 1.0 );

		// engagement_rate 0-1 → 0-50 pts.
		$score = $engagement_rate * 50.0;

		// avg_time: 8 sec per point, max 30 pts (≈240 s).
		$score += min( 30.0, $avg_time / 8.0 );

		// High bounce penalises up to 20 pts.
		$score -= $bounce_rate * 20.0;

		return max( 0, min( 100, (int) round( $score ) ) );
	}
}
