<?php
/**
 * WP-CLI command suite for SEO Agent AI.
 *
 * Register with: WP_CLI::add_command( 'seo-agent', 'SEO_Agent_AI_CLI' )
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Autonomous SEO agent commands.
 */
class SEO_Agent_AI_CLI {

	// -------------------------------------------------------------------
	// analyze
	// -------------------------------------------------------------------

	/**
	 * Run full SEO analysis on all posts or a single post.
	 *
	 * ## OPTIONS
	 *
	 * [--post-id=<id>]
	 * : Analyze a specific post ID only.
	 *
	 * [--dry-run]
	 * : Validate but do not write decisions or apply changes.
	 *
	 * [--verbose]
	 * : Show detailed signal output per post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seo-agent analyze
	 *     wp seo-agent analyze --post-id=42 --dry-run --verbose
	 *
	 * @subcommand analyze
	 */
	public function analyze( $args, $assoc_args ) {
		$post_id  = isset( $assoc_args['post-id'] ) ? (int) $assoc_args['post-id'] : 0;
		$dry_run  = isset( $assoc_args['dry-run'] );
		$verbose  = isset( $assoc_args['verbose'] );

		$plugin   = SEO_Agent_AI_Plugin::instance();
		$logger   = $plugin->get_logger();

		if ( $dry_run ) {
			WP_CLI::log( WP_CLI::colorize( '%Y[DRY-RUN]%n Analysis will run but no changes will be written.' ) );
		}

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
				WP_CLI::error( "Post #{$post_id} not found or not published." );
				return;
			}
			$posts = array( $post );
		} else {
			$posts = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			) );
		}

		$processed = 0;
		$with_recs = 0;
		$failed    = 0;

		$progress = WP_CLI\Utils\make_progress_bar( 'Analyzing posts', count( $posts ) );

		foreach ( $posts as $post ) {
			$result = $plugin->analyze_post_for_cli( $post, false, $dry_run );
			$processed++;

			if ( $result['had_api_failure'] ) {
				$failed++;
			}
			if ( $result['had_recommendations'] ) {
				$with_recs++;
			}

			if ( $verbose ) {
				WP_CLI::log( sprintf(
					'  Post #%d "%s" — signals: %s',
					$post->ID,
					$post->post_title,
					implode( ', ', array_keys( array_filter( $result['signals'] ?? array() ) ) ) ?: 'none'
				) );
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success( sprintf(
			'Analyzed %d posts. %d with recommendations, %d API failures.',
			$processed,
			$with_recs,
			$failed
		) );
	}

	// -------------------------------------------------------------------
	// optimize
	// -------------------------------------------------------------------

	/**
	 * Apply AI recommendations to all eligible posts or a single post.
	 *
	 * ## OPTIONS
	 *
	 * [--post-id=<id>]
	 * : Apply to a specific post ID.
	 *
	 * [--dry-run]
	 * : Show what would be applied without writing.
	 *
	 * [--mode=<mode>]
	 * : safe or aggressive (default: safe).
	 *
	 * @subcommand optimize
	 */
	public function optimize( $args, $assoc_args ) {
		$post_id  = isset( $assoc_args['post-id'] ) ? (int) $assoc_args['post-id'] : 0;
		$dry_run  = isset( $assoc_args['dry-run'] );
		$mode     = isset( $assoc_args['mode'] ) && $assoc_args['mode'] === 'aggressive' ? 'aggressive' : 'safe';

		$plugin = SEO_Agent_AI_Plugin::instance();

		if ( $dry_run ) {
			WP_CLI::log( WP_CLI::colorize( '%Y[DRY-RUN]%n No changes will be written.' ) );
		}

		$post_ids = $post_id > 0 ? array( $post_id ) : $plugin->get_data_store()->get_posts_with_recommendations( 200 );
		$applied  = 0;
		$skipped  = 0;

		foreach ( $post_ids as $pid ) {
			$recs    = $plugin->get_data_store()->get_recommendations( $pid );
			$metrics = $plugin->get_data_store()->get_post_metrics( $pid );
			$analysis = $metrics['analysis'] ?? array();

			foreach ( $recs as $rec ) {
				if ( $mode === 'safe' && ( $rec['risk'] ?? 'risky' ) !== 'safe' ) {
					$skipped++;
					continue;
				}

				$result = $plugin->get_fix_executor()->apply(
					$pid,
					$rec,
					'manual',
					array(
						'signals'  => $analysis['signals'] ?? array(),
						'evidence' => $analysis['evidence'] ?? array(),
					),
					$dry_run
				);

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( "Post #{$pid}: " . $result->get_error_message() );
					$skipped++;
				} else {
					$applied++;
					if ( $dry_run ) {
						WP_CLI::log( "  Would apply {$rec['type']} to post #{$pid}" );
					}
				}
			}
		}

		WP_CLI::success( "Applied: {$applied}, Skipped: {$skipped}." );
	}

	// -------------------------------------------------------------------
	// report
	// -------------------------------------------------------------------

	/**
	 * Display or generate the daily SEO report.
	 *
	 * ## OPTIONS
	 *
	 * [--date=<date>]
	 * : Report date in Y-m-d format (default: today).
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv (default: table).
	 *
	 * @subcommand report
	 */
	public function report( $args, $assoc_args ) {
		$date   = isset( $assoc_args['date'] ) ? sanitize_text_field( $assoc_args['date'] ) : gmdate( 'Y-m-d' );
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$plugin  = SEO_Agent_AI_Plugin::instance();
		$engine  = $plugin->get_report_engine();
		$report  = $engine->get( $date );

		if ( $report === null ) {
			WP_CLI::log( "No report for {$date}. Generating now..." );
			$report = $engine->generate( $date );
		}

		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
			return;
		}

		$summary = $report['summary'] ?? array();
		$rows    = array();
		foreach ( $summary as $key => $val ) {
			$rows[] = array( 'Metric' => $key, 'Value' => $val );
		}

		if ( $format === 'csv' ) {
			WP_CLI\Utils\format_items( 'csv', $rows, array( 'Metric', 'Value' ) );
		} else {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'Metric', 'Value' ) );
		}
	}

	// -------------------------------------------------------------------
	// rollback
	// -------------------------------------------------------------------

	/**
	 * Rollback the most recent meta backup for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : ID of the post to rollback.
	 *
	 * [--dry-run]
	 * : Show what would be restored without writing.
	 *
	 * @subcommand rollback
	 */
	public function rollback( $args, $assoc_args ) {
		$post_id = (int) ( $args[0] ?? 0 );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( ! $post_id ) {
			WP_CLI::error( 'Please provide a post ID.' );
			return;
		}

		$plugin = SEO_Agent_AI_Plugin::instance();
		$result = $plugin->get_fix_executor()->rollback( $post_id, $dry_run );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( $dry_run && is_array( $result ) ) {
			WP_CLI::log( 'Would restore:' );
			WP_CLI\Utils\format_items( 'table', array( $result['would_restore'] ), array_keys( $result['would_restore'] ) );
		} else {
			WP_CLI::success( "Post #{$post_id} rolled back successfully." );
		}
	}

	// -------------------------------------------------------------------
	// fetch-gsc
	// -------------------------------------------------------------------

	/**
	 * Fetch and store GSC keyword history data.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Lookback window (default: 28).
	 *
	 * [--verbose]
	 * : Show per-page output.
	 *
	 * @subcommand fetch-gsc
	 */
	public function fetch_gsc( $args, $assoc_args ) {
		$days    = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 28;
		$verbose = isset( $assoc_args['verbose'] );

		$plugin     = SEO_Agent_AI_Plugin::instance();
		$gsc_client = $plugin->get_gsc_client();

		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
		) );

		$stored  = 0;
		$errors  = 0;
		$progress = WP_CLI\Utils\make_progress_bar( 'Fetching GSC data', count( $posts ) );

		foreach ( $posts as $post ) {
			$url     = get_permalink( $post );
			$history = $gsc_client->get_keyword_history( $url, $days );

			if ( ! empty( $history ) ) {
				foreach ( $history as $row ) {
					SEO_Agent_AI_DB_Manager::insert_keyword_history( array(
						'post_id'     => $post->ID,
						'keyword'     => $row['keyword'],
						'position'    => $row['position'],
						'impressions' => $row['impressions'],
						'clicks'      => $row['clicks'],
						'ctr'         => $row['ctr'],
						'recorded_at' => $row['date'] . ' 00:00:00',
					) );
				}
				$stored += count( $history );
				if ( $verbose ) {
					WP_CLI::log( "  Post #{$post->ID}: " . count( $history ) . " keyword rows stored." );
				}
			} else {
				$errors++;
			}

			$progress->tick();
			usleep( 1000000 ); // 1s rate limit.
		}

		$progress->finish();
		WP_CLI::success( "GSC fetch complete. {$stored} rows stored, {$errors} posts with no data." );
	}

	// -------------------------------------------------------------------
	// fetch-ga4
	// -------------------------------------------------------------------

	/**
	 * Fetch and store GA4 engagement metrics.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Lookback window (default: 28).
	 *
	 * [--verbose]
	 * : Show per-page output.
	 *
	 * @subcommand fetch-ga4
	 */
	public function fetch_ga4( $args, $assoc_args ) {
		$days    = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 28;
		$verbose = isset( $assoc_args['verbose'] );

		$plugin     = SEO_Agent_AI_Plugin::instance();
		$ga4_client = $plugin->get_ga4_client();

		$posts    = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
		) );

		$fetched  = 0;
		$progress = WP_CLI\Utils\make_progress_bar( 'Fetching GA4 data', count( $posts ) );

		foreach ( $posts as $post ) {
			$url     = get_permalink( $post );
			$metrics = $ga4_client->get_page_metrics( $url );

			if ( ! is_wp_error( $metrics ) ) {
				$plugin->get_data_store()->save_post_metrics( $post->ID, array(
					'ga4'        => $metrics,
					'updated_at' => current_time( 'mysql' ),
				) );
				$fetched++;
				if ( $verbose ) {
					WP_CLI::log( "  Post #{$post->ID}: engagement_rate=" . $metrics['engagement_rate'] );
				}
			}

			$progress->tick();
			usleep( 500000 ); // 0.5s rate limit.
		}

		$progress->finish();
		WP_CLI::success( "GA4 fetch complete. {$fetched} posts updated." );
	}

	// -------------------------------------------------------------------
	// score
	// -------------------------------------------------------------------

	/**
	 * Run the SEO scoring engine and output scores.
	 *
	 * ## OPTIONS
	 *
	 * [--post-id=<id>]
	 * : Score a specific post only.
	 *
	 * [--format=<format>]
	 * : table or json (default: table).
	 *
	 * @subcommand score
	 */
	public function score( $args, $assoc_args ) {
		$post_id = isset( $assoc_args['post-id'] ) ? (int) $assoc_args['post-id'] : 0;
		$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$plugin = SEO_Agent_AI_Plugin::instance();
		$engine = $plugin->get_scoring_engine();

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				WP_CLI::error( "Post #{$post_id} not found." );
				return;
			}
			$posts = array( $post );
		} else {
			$posts = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			) );
		}

		$rows     = array();
		$progress = WP_CLI\Utils\make_progress_bar( 'Scoring posts', count( $posts ) );

		foreach ( $posts as $post ) {
			$seo_audit = $plugin->get_bridge()->audit_post( $post->ID, $post );
			$metrics   = $plugin->get_data_store()->get_post_metrics( $post->ID );
			$gsc       = $metrics['gsc'] ?? array();
			$ga4       = $metrics['ga4'] ?? array();

			$result = $engine->score( $post, $gsc, $ga4, $seo_audit );

			$rows[] = array(
				'ID'       => $post->ID,
				'Title'    => substr( $post->post_title, 0, 40 ),
				'Overall'  => $result['overall'],
				'Metadata' => $result['dimensions']['metadata'] ?? 0,
				'Content'  => $result['dimensions']['content'] ?? 0,
				'Schema'   => $result['dimensions']['schema'] ?? 0,
				'CTR'      => $result['dimensions']['ctr'] ?? 0,
			);

			$progress->tick();
		}

		$progress->finish();

		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
		} else {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Title', 'Overall', 'Metadata', 'Content', 'Schema', 'CTR' ) );
		}
	}

	// -------------------------------------------------------------------
	// opportunities
	// -------------------------------------------------------------------

	/**
	 * Show ranked SEO opportunities from the decision queue.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Max opportunities to show (default: 20).
	 *
	 * [--format=<format>]
	 * : table or json (default: table).
	 *
	 * @subcommand opportunities
	 */
	public function opportunities( $args, $assoc_args ) {
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$decisions = SEO_Agent_AI_DB_Manager::get_decisions( array(
			'status' => SEO_Agent_AI_DB_Manager::STATUS_PENDING,
			'limit'  => $limit,
		) );

		if ( empty( $decisions ) ) {
			WP_CLI::log( 'No pending opportunities. Run wp seo-agent analyze first.' );
			return;
		}

		$rows = array();
		foreach ( $decisions as $dec ) {
			$post  = get_post( (int) $dec['post_id'] );
			$rows[] = array(
				'ID'         => $dec['id'],
				'Post'       => $post instanceof WP_Post ? substr( $post->post_title, 0, 35 ) : "(#{$dec['post_id']})",
				'Type'       => $dec['decision_type'],
				'Confidence' => round( (float) $dec['confidence'] * 100 ) . '%',
				'Risk'       => $dec['risk_level'],
				'Impact'     => substr( $dec['expected_impact'] ?? '', 0, 40 ),
			);
		}

		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
		} else {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Post', 'Type', 'Confidence', 'Risk', 'Impact' ) );
		}
	}

	// -------------------------------------------------------------------
	// status
	// -------------------------------------------------------------------

	/**
	 * Show plugin health: API connections, cron status, counts.
	 *
	 * @subcommand status
	 */
	public function status( $args, $assoc_args ) {
		$plugin    = SEO_Agent_AI_Plugin::instance();
		$oauth     = $plugin->get_oauth();
		$logger    = $plugin->get_logger();

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== SEO Agent AI v' . SEO_AGENT_AI_VERSION . ' ===%n' ) );
		WP_CLI::log( '' );

		// Google auth.
		$connected = $oauth->is_connected();
		WP_CLI::log( 'Google Auth:   ' . ( $connected ? WP_CLI::colorize( '%Gconnected%n' ) : WP_CLI::colorize( '%Rnot connected%n' ) ) );

		// AI provider.
		$provider = (string) get_option( 'seo_agent_ai_ai_provider', 'gemini' );
		$gemini_ok = class_exists( 'SEO_Agent_AI_Gemini_Client' ) && ( new SEO_Agent_AI_Gemini_Client() )->is_configured();
		$openai_ok = class_exists( 'SEO_Agent_AI_OpenAI_Client' ) && ( new SEO_Agent_AI_OpenAI_Client() )->is_configured();
		WP_CLI::log( 'AI Provider:   ' . $provider . ' | Gemini: ' . ( $gemini_ok ? 'configured' : 'not set' ) . ' | OpenAI: ' . ( $openai_ok ? 'configured' : 'not set' ) );

		// DB tables.
		global $wpdb;
		$tables   = array( 'seo_agent_keyword_history', 'seo_agent_page_insights', 'seo_agent_ai_decisions', 'seo_agent_daily_reports', 'seo_agent_internal_links', 'seo_agent_ai_activity' );
		$all_ok   = true;
		foreach ( $tables as $tbl ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $tbl ) );
			if ( ! $exists ) {
				$all_ok = false;
			}
		}
		WP_CLI::log( 'DB Tables:     ' . ( $all_ok ? WP_CLI::colorize( '%G6/6 OK%n' ) : WP_CLI::colorize( '%Rmissing tables — run: wp plugin deactivate seo-agent-ai && wp plugin activate seo-agent-ai%n' ) ) );

		// Cron hooks.
		$cron_hooks = array( 'seo_agent_ai_daily_analysis', 'seo_agent_fetch_gsc_data', 'seo_agent_fetch_ga4_data', 'seo_agent_generate_report', 'seo_agent_score_pages', 'seo_agent_detect_decay', 'seo_agent_run_internal_links', 'seo_agent_purge_old_data' );
		$cron_ok    = 0;
		foreach ( $cron_hooks as $hook ) {
			if ( wp_next_scheduled( $hook ) ) {
				$cron_ok++;
			}
		}
		WP_CLI::log( "Cron Hooks:    {$cron_ok}/" . count( $cron_hooks ) . " scheduled" );

		// Pending approvals.
		$pending = SEO_Agent_AI_DB_Manager::count_decisions( SEO_Agent_AI_DB_Manager::STATUS_PENDING );
		WP_CLI::log( "Pending:       {$pending} decisions awaiting approval" );

		// Queue status.
		$raw   = get_option( SEO_Agent_AI_Queue_Manager::OPTION_KEY, '' );
		$queue = $raw !== '' ? json_decode( $raw, true ) : array();
		$q_pending = count( $queue['items'] ?? array() );
		WP_CLI::log( "Queue:         {$q_pending} posts pending processing" );

		WP_CLI::log( '' );
	}

	// -------------------------------------------------------------------
	// logs
	// -------------------------------------------------------------------

	/**
	 * Tail the plugin debug log.
	 *
	 * ## OPTIONS
	 *
	 * [--level=<level>]
	 * : Filter level: debug, info, warning, error (default: all).
	 *
	 * [--lines=<lines>]
	 * : Number of lines to show (default: 50).
	 *
	 * @subcommand logs
	 */
	public function logs( $args, $assoc_args ) {
		$level = isset( $assoc_args['level'] ) ? strtoupper( $assoc_args['level'] ) : '';
		$lines = isset( $assoc_args['lines'] ) ? (int) $assoc_args['lines'] : 50;

		$plugin = SEO_Agent_AI_Plugin::instance();
		$logger = $plugin->get_logger();

		$log_lines = $logger->tail( $lines, $level ?: null );

		if ( empty( $log_lines ) ) {
			WP_CLI::log( 'No log entries found.' );
			return;
		}

		foreach ( $log_lines as $line ) {
			WP_CLI::log( $line );
		}
	}
}
