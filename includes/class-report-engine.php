<?php
/**
 * Report Engine.
 *
 * Generates structured daily SEO reports and stores them in the
 * seo_agent_daily_reports table. Optionally emails the admin.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Report_Engine {

	/** @var SEO_Agent_AI_Logger */
	private $logger;

	public function __construct( SEO_Agent_AI_Logger $logger ) {
		$this->logger = $logger;
	}

	// -------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------

	/**
	 * Generate a daily report for a given date.
	 *
	 * @param string $date  'Y-m-d' format (defaults to today).
	 * @param bool   $force Overwrite if a report already exists for this date.
	 * @return array  The report data array.
	 */
	public function generate( $date = '', $force = false ) {
		$date = $date !== '' ? $date : gmdate( 'Y-m-d' );

		// Skip if report already exists for this date and not forced.
		if ( ! $force ) {
			$existing = SEO_Agent_AI_DB_Manager::get_daily_report( $date );
			if ( $existing !== null ) {
				$this->logger->debug( "Daily report for {$date} already exists — skipping." );
				return is_array( $existing['report_data'] ) ? $existing['report_data'] : json_decode( $existing['report_data'] ?? '{}', true );
			}
		}

		$report = $this->build_report( $date );

		$pages_analyzed    = $report['summary']['pages_analyzed'] ?? 0;
		$pages_optimized   = $report['summary']['pages_optimized'] ?? 0;
		$opportunities     = $report['summary']['opportunities_detected'] ?? 0;
		$problems          = $report['summary']['problems_detected'] ?? 0;

		SEO_Agent_AI_DB_Manager::upsert_report( array(
			'report_date'          => $date,
			'report_data'          => wp_json_encode( $report ),
			'pages_analyzed'       => $pages_analyzed,
			'pages_optimized'      => $pages_optimized,
			'opportunities_detected' => $opportunities,
			'problems_detected'    => $problems,
		) );

		$this->logger->info( "Daily report for {$date} generated. {$pages_analyzed} pages, {$opportunities} opportunities." );

		// Optionally email admin.
		if ( (bool) get_option( 'seo_agent_ai_email_reports', false ) ) {
			$this->email_report( $report, $date );
		}

		return $report;
	}

	/**
	 * Retrieve a stored report by date.
	 *
	 * @param string $date  'Y-m-d' (defaults to today).
	 * @return array|null
	 */
	public function get( $date = '' ) {
		$date = $date !== '' ? $date : gmdate( 'Y-m-d' );
		$row = SEO_Agent_AI_DB_Manager::get_daily_report( $date );
		if ( $row === null ) {
			return null;
		}
		return is_array( $row['report_data'] ) ? $row['report_data'] : json_decode( $row['report_data'], true );
	}

	/**
	 * List available report dates (most recent first).
	 *
	 * @param int $limit
	 * @return string[]  Array of 'Y-m-d' date strings.
	 */
	public function list_dates( $limit = 30 ) {
		return SEO_Agent_AI_DB_Manager::get_report_dates( $limit );
	}

	// -------------------------------------------------------------------
	// Report builder
	// -------------------------------------------------------------------

	private function build_report( $date ) {
		// Pull data from DB tables populated by the analysis cron.
		$activity_since    = $date . ' 00:00:00';
		$activity_until    = $date . ' 23:59:59';

		// Activity log entries for this date.
		$activity_rows     = SEO_Agent_AI_DB_Manager::get_activity_for_range( $activity_since, $activity_until );
		$pages_optimized   = count( array_unique( array_column( $activity_rows, 'post_id' ) ) );

		// AI decisions created today.
		$decisions_today   = SEO_Agent_AI_DB_Manager::get_decisions( array(
			'date_from' => $activity_since,
			'date_to'   => $activity_until,
		) );

		// Pending approvals total.
		$pending_count     = SEO_Agent_AI_DB_Manager::count_decisions( SEO_Agent_AI_DB_Manager::STATUS_PENDING );

		// Latest page insights (all posts with a snapshot).
		$all_insights      = SEO_Agent_AI_DB_Manager::get_all_latest_insights( 200 );
		$pages_analyzed    = count( $all_insights );

		// Score distribution.
		$score_dist        = $this->score_distribution( $all_insights );

		// Top opportunities from ai_decisions (pending, ordered by confidence).
		$top_opportunities = SEO_Agent_AI_DB_Manager::get_decisions( array(
			'status' => SEO_Agent_AI_DB_Manager::STATUS_PENDING,
			'limit'  => 10,
		) );

		// Rising vs declining pages (from keyword_history trends).
		$trends            = $this->compute_trends();

		// Problems detected: pages with overall score < 40.
		$low_score_pages   = array_filter( $all_insights, fn( $r ) => (int) ( $r['score_overall'] ?? 100 ) < 40 );
		$problems_detected = count( $low_score_pages );

		// Opportunities: ai_decisions pending.
		$opps_count        = count( $top_opportunities );

		$report = array(
			'generated_at' => gmdate( 'Y-m-d H:i:s' ),
			'report_date'  => $date,
			'summary'      => array(
				'pages_analyzed'        => $pages_analyzed,
				'pages_optimized'       => $pages_optimized,
				'opportunities_detected' => $opps_count,
				'problems_detected'     => $problems_detected,
				'pending_approvals'     => $pending_count,
				'changes_made'          => count( $activity_rows ),
			),
			'score_distribution' => $score_dist,
			'top_opportunities'  => $this->format_decisions( $top_opportunities ),
			'recent_changes'     => $this->format_activity( $activity_rows ),
			'trends'             => $trends,
			'low_score_pages'    => array_slice( $this->format_insights( array_values( $low_score_pages ) ), 0, 10 ),
		);

		return $report;
	}

	// -------------------------------------------------------------------
	// Formatting helpers
	// -------------------------------------------------------------------

	private function score_distribution( array $insights ) {
		$buckets = array(
			'excellent' => 0, // 80-100
			'good'      => 0, // 60-79
			'average'   => 0, // 40-59
			'poor'      => 0, // 20-39
			'critical'  => 0, // 0-19
		);

		foreach ( $insights as $row ) {
			$score = (int) ( $row['score_overall'] ?? 0 );
			if ( $score >= 80 ) {
				$buckets['excellent']++;
			} elseif ( $score >= 60 ) {
				$buckets['good']++;
			} elseif ( $score >= 40 ) {
				$buckets['average']++;
			} elseif ( $score >= 20 ) {
				$buckets['poor']++;
			} else {
				$buckets['critical']++;
			}
		}

		return $buckets;
	}

	private function compute_trends() {
		// Get top 10 rising and declining pages based on keyword_history position changes.
		global $wpdb;
		$table = $wpdb->prefix . 'seo_agent_keyword_history';

		// Rising: pages whose avg position improved by ≥ 2 positions in last 7 days.
		$rising = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM (
			     SELECT post_id, keyword,
			         AVG(CASE WHEN recorded_at >= %s THEN position END) AS pos_recent,
			         AVG(CASE WHEN recorded_at < %s AND recorded_at >= %s THEN position END) AS pos_prior
			     FROM {$table}
			     GROUP BY post_id, keyword
			 ) AS agg
			 WHERE pos_recent IS NOT NULL AND pos_prior IS NOT NULL
			   AND (pos_prior - pos_recent) >= 2
			 ORDER BY (pos_prior - pos_recent) DESC
			 LIMIT 10",
			gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			gmdate( 'Y-m-d', strtotime( '-14 days' ) )
		), ARRAY_A );

		// Declining: pages whose avg position got worse by ≥ 2 positions.
		$declining = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM (
			     SELECT post_id, keyword,
			         AVG(CASE WHEN recorded_at >= %s THEN position END) AS pos_recent,
			         AVG(CASE WHEN recorded_at < %s AND recorded_at >= %s THEN position END) AS pos_prior
			     FROM {$table}
			     GROUP BY post_id, keyword
			 ) AS agg
			 WHERE pos_recent IS NOT NULL AND pos_prior IS NOT NULL
			   AND (pos_recent - pos_prior) >= 2
			 ORDER BY (pos_recent - pos_prior) DESC
			 LIMIT 10",
			gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			gmdate( 'Y-m-d', strtotime( '-14 days' ) )
		), ARRAY_A );

		$format_trend = function( $rows ) {
			$out = array();
			foreach ( $rows as $row ) {
				$post_id = (int) $row['post_id'];
				$post    = get_post( $post_id );
				$out[]   = array(
					'post_id'    => $post_id,
					'post_title' => $post instanceof WP_Post ? $post->post_title : "(#{$post_id})",
					'keyword'    => $row['keyword'],
					'pos_prior'  => round( (float) $row['pos_prior'], 1 ),
					'pos_recent' => round( (float) $row['pos_recent'], 1 ),
					'change'     => round( (float) $row['pos_prior'] - (float) $row['pos_recent'], 1 ),
				);
			}
			return $out;
		};

		return array(
			'rising'    => $format_trend( is_array( $rising ) ? $rising : array() ),
			'declining' => $format_trend( is_array( $declining ) ? $declining : array() ),
		);
	}

	private function format_decisions( array $decisions ) {
		$out = array();
		foreach ( $decisions as $dec ) {
			$post_id = (int) ( $dec['post_id'] ?? 0 );
			$post    = get_post( $post_id );
			$out[]   = array(
				'decision_id'    => (int) $dec['id'],
				'post_id'        => $post_id,
				'post_title'     => $post instanceof WP_Post ? $post->post_title : "(#{$post_id})",
				'post_url'       => $post instanceof WP_Post ? get_permalink( $post ) : '',
				'decision_type'  => $dec['decision_type'] ?? '',
				'field'          => $dec['field'] ?? '',
				'proposed_value' => $dec['proposed_value'] ?? '',
				'confidence'     => round( (float) ( $dec['confidence'] ?? 0.0 ), 2 ),
				'risk_level'     => $dec['risk_level'] ?? '',
				'reasoning'      => $dec['reasoning'] ?? '',
			);
		}
		return $out;
	}

	private function format_activity( array $rows ) {
		$out = array();
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			$post_id = (int) ( $row['post_id'] ?? 0 );
			$post    = get_post( $post_id );
			$out[]   = array(
				'post_id'    => $post_id,
				'post_title' => $post instanceof WP_Post ? $post->post_title : "(#{$post_id})",
				'type'       => $row['change_type'] ?? $row['type'] ?? '',
				'field'      => $row['field_changed'] ?? $row['field'] ?? '',
				'created_at' => $row['created_at'] ?? '',
			);
		}
		return $out;
	}

	private function format_insights( array $insights ) {
		$out = array();
		foreach ( $insights as $row ) {
			$post_id = (int) ( $row['post_id'] ?? 0 );
			$post    = get_post( $post_id );
			$out[]   = array(
				'post_id'       => $post_id,
				'post_title'    => $post instanceof WP_Post ? $post->post_title : "(#{$post_id})",
				'post_url'      => $post instanceof WP_Post ? get_permalink( $post ) : '',
				'score_overall' => (int) ( $row['score_overall'] ?? 0 ),
				'recorded_at'   => $row['recorded_at'] ?? '',
			);
		}
		return $out;
	}

	// -------------------------------------------------------------------
	// Email
	// -------------------------------------------------------------------

	private function email_report( array $report, $date ) {
		$admin_email = get_option( 'admin_email', '' );
		if ( $admin_email === '' ) {
			return;
		}

		$blog_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[%s] SEO Agent Daily Report — %s', $blog_name, $date );

		$summary = $report['summary'] ?? array();
		$body    = sprintf(
			"SEO Agent AI — Daily Report for %s\n\n"
			. "Pages analyzed:        %d\n"
			. "Pages optimized:       %d\n"
			. "Opportunities found:   %d\n"
			. "Problems detected:     %d\n"
			. "Pending approvals:     %d\n\n"
			. "Log in to your WordPress admin to review opportunities and approve pending changes:\n"
			. "%s",
			$date,
			$summary['pages_analyzed'] ?? 0,
			$summary['pages_optimized'] ?? 0,
			$summary['opportunities_detected'] ?? 0,
			$summary['problems_detected'] ?? 0,
			$summary['pending_approvals'] ?? 0,
			admin_url( 'admin.php?page=seo-agent-approvals' )
		);

		wp_mail( $admin_email, $subject, $body );
	}
}
