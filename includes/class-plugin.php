<?php
/**
 * Plugin orchestrator.
 *
 * Wires all modules together, registers WordPress hooks, and coordinates
 * analysis, autopilot, rollbacks, and OAuth.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Plugin {

	const ANALYSIS_LOCK_KEY         = 'seo_agent_ai_analysis_lock';
	const ANALYSIS_LOCK_TTL         = 15 * MINUTE_IN_SECONDS;
	const CONNECTION_TEST_TRANSIENT = 'seo_agent_ai_connection_test_result';
	const DAILY_AP_TRANSIENT_PREFIX = 'seo_agent_ai_ap_count_';
	const BATCH_ANALYSIS_KEY        = 'seo_agent_ai_batch_state';
	const OPTION_API_FAILURES       = 'seo_agent_ai_consecutive_api_failures';
	const OPTION_LAST_API_ERROR     = 'seo_agent_ai_last_api_error';
	const API_FAILURE_NOTICE_AFTER  = 2;
	const CRON_HOOK_DAILY           = 'seo_agent_ai_daily_analysis';
	const CRON_HOOK_MANUAL          = 'seo_agent_ai_run_manual_analysis';
	const CRON_HOOK_GSC             = 'seo_agent_fetch_gsc_data';
	const CRON_HOOK_GA4             = 'seo_agent_fetch_ga4_data';
	const CRON_HOOK_REPORT          = 'seo_agent_generate_report';
	const CRON_HOOK_SCORE           = 'seo_agent_score_pages';
	const CRON_HOOK_DECAY           = 'seo_agent_detect_decay';
	const CRON_HOOK_LINKS           = 'seo_agent_run_internal_links';
	const CRON_HOOK_PURGE           = 'seo_agent_purge_old_data';
	const CRON_HOOK_CANNIBAL        = 'seo_agent_detect_cannibalization';
	const CRON_HOOK_IMPROVE         = 'seo_agent_score_and_improve';
	const CRON_HOOK_ORPHAN          = 'seo_agent_detect_orphans';

	private static $instance = null;

	/** @var SEO_Agent_AI_Data_Store */
	private $data_store;

	/** @var SEO_Agent_AI_Google_OAuth */
	private $oauth;

	/** @var SEO_Agent_AI_GSC_Client */
	private $gsc_client;

	/** @var SEO_Agent_AI_GA4_Client */
	private $ga4_client;

	/** @var SEO_Agent_AI_SEO_Analyzer */
	private $analyzer;

	/** @var SEO_Agent_AI_Recommendation_Engine */
	private $recommendation_engine;

	/** @var SEO_Agent_AI_Fix_Executor */
	private $fix_executor;

	/** @var SEO_Agent_AI_Activity_Log */
	private $activity_log;

	/** @var SEO_Agent_AI_SEO_Plugin_Bridge */
	private $bridge;

	/** @var SEO_Agent_AI_Gemini_Client */
	private $gemini;

	/** @var SEO_Agent_AI_OpenAI_Client */
	private $openai;

	/** @var SEO_Agent_AI_Logger */
	private $logger;

	/** @var SEO_Agent_AI_Content_Analyzer */
	private $content_analyzer;

	/** @var SEO_Agent_AI_Keyword_Cluster */
	private $keyword_cluster;

	/** @var SEO_Agent_AI_SEO_Scoring_Engine */
	private $scoring_engine;

	/** @var SEO_Agent_AI_Decision_Engine */
	private $decision_engine;

	/** @var SEO_Agent_AI_Schema_Engine */
	private $schema_engine;

	/** @var SEO_Agent_AI_Internal_Link_Engine */
	private $internal_link_engine;

	/** @var SEO_Agent_AI_Report_Engine */
	private $report_engine;

	/** @var SEO_Agent_AI_Queue_Manager */
	private $queue_manager;

	/** @var SEO_Agent_AI_Admin_Page */
	private $admin_page;

	/** @var SEO_Agent_AI_Image_SEO */
	private $image_seo;

	/** @var SEO_Agent_AI_Social_Meta */
	private $social_meta;

	/** @var SEO_Agent_AI_Meta_Box */
	private $meta_box;

	/** @var SEO_Agent_AI_Taxonomy_SEO */
	private $taxonomy_seo;

	/** @var SEO_Agent_AI_Redirect_Manager */
	private $redirect_manager;

	// -------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Core infrastructure.
		$this->logger       = new SEO_Agent_AI_Logger();
		$this->data_store   = new SEO_Agent_AI_Data_Store();
		$this->activity_log = new SEO_Agent_AI_Activity_Log();
		$this->oauth        = new SEO_Agent_AI_Google_OAuth();
		$this->bridge       = new SEO_Agent_AI_SEO_Plugin_Bridge();

		// API clients.
		$this->gemini     = new SEO_Agent_AI_Gemini_Client();
		$this->openai     = new SEO_Agent_AI_OpenAI_Client();
		$this->gsc_client = new SEO_Agent_AI_GSC_Client( $this->oauth );
		$this->ga4_client = new SEO_Agent_AI_GA4_Client( $this->oauth );

		// Analysis engines.
		$this->content_analyzer = new SEO_Agent_AI_Content_Analyzer();
		$this->keyword_cluster  = new SEO_Agent_AI_Keyword_Cluster();
		$this->scoring_engine   = new SEO_Agent_AI_SEO_Scoring_Engine( $this->content_analyzer );
		$this->decision_engine  = new SEO_Agent_AI_Decision_Engine();

		// Schema injection (wp_head, priority 5).
		$this->schema_engine = new SEO_Agent_AI_Schema_Engine( $this->content_analyzer, $this->logger );

		// Analyzer + recommendation engine (depend on above).
		$this->analyzer              = new SEO_Agent_AI_SEO_Analyzer( $this->content_analyzer, $this->keyword_cluster );
		$this->recommendation_engine = new SEO_Agent_AI_Recommendation_Engine( $this->gemini, $this->openai, $this->decision_engine );
		$this->fix_executor          = new SEO_Agent_AI_Fix_Executor( $this->activity_log, $this->bridge );

		// Autonomous systems.
		$this->internal_link_engine = new SEO_Agent_AI_Internal_Link_Engine( $this->logger );
		$this->report_engine        = new SEO_Agent_AI_Report_Engine( $this->logger );
		$this->queue_manager        = new SEO_Agent_AI_Queue_Manager( $this->logger );

		// Feature modules.
		$this->image_seo        = new SEO_Agent_AI_Image_SEO( $this->gemini, $this->openai, $this->logger );
		$this->social_meta      = new SEO_Agent_AI_Social_Meta();
		$this->meta_box         = new SEO_Agent_AI_Meta_Box();
		$this->taxonomy_seo     = new SEO_Agent_AI_Taxonomy_SEO();
		$this->redirect_manager = new SEO_Agent_AI_Redirect_Manager();

		// Admin page sub-page instances.
		$connect_page           = new SEO_Agent_AI_Connect_Page( $this->oauth );
		$report_page            = new SEO_Agent_AI_Report_Page( $this->activity_log, $this->data_store );
		$dashboard_page         = new SEO_Agent_AI_Dashboard_Page( $this->decision_engine, $this->report_engine );
		$opportunities_page     = new SEO_Agent_AI_Opportunities_Page( $this->decision_engine );
		$rankings_page          = new SEO_Agent_AI_Rankings_Page();
		$pending_approvals_page = new SEO_Agent_AI_Pending_Approvals_Page( $this->decision_engine, $this->fix_executor, $this->internal_link_engine );
		$rollback_center_page   = new SEO_Agent_AI_Rollback_Center_Page( $this->fix_executor, $this->activity_log );
		$cron_status_page       = new SEO_Agent_AI_Cron_Status_Page();
		$image_seo_page         = new SEO_Agent_AI_Image_SEO_Page( $this->image_seo );
		$redirects_page         = new SEO_Agent_AI_Redirects_Page( $this->redirect_manager );

		$this->admin_page = new SEO_Agent_AI_Admin_Page(
			$this->data_store,
			$connect_page,
			$report_page,
			$this->oauth,
			$this->bridge,
			$dashboard_page,
			$opportunities_page,
			$rankings_page,
			$pending_approvals_page,
			$rollback_center_page,
			$cron_status_page,
			$image_seo_page,
			$redirects_page
		);

		// Admin hooks.
		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_action( 'admin_post_seo_agent_ai_apply_fix', array( $this, 'handle_apply_fix' ) );
		add_action( 'admin_post_seo_agent_ai_run_analysis', array( $this, 'handle_manual_analysis' ) );
		add_action( 'admin_post_seo_agent_ai_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_seo_agent_ai_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_seo_agent_ai_google_disconnect', array( $this, 'handle_google_disconnect' ) );
		add_action( 'admin_post_seo_agent_ai_rollback_backup', array( $this, 'handle_rollback_backup' ) );
		add_action( 'admin_post_seo_agent_ai_rollback', array( $this, 'handle_activity_rollback' ) );

		// New v3.0 admin-post handlers.
		add_action( 'admin_post_seo_agent_ai_decision', array( $pending_approvals_page, 'handle_action' ) );
		add_action( 'admin_post_seo_agent_ai_rollback_new', array( $rollback_center_page, 'handle_rollback' ) );
		add_action( 'admin_post_seo_agent_ai_trigger_cron', array( $cron_status_page, 'handle_trigger' ) );
		add_action( 'admin_post_seo_agent_ai_manage_redirect', array( $redirects_page, 'handle_action' ) );

		// OAuth callback — must run before page output.
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_callback' ) );

		// AJAX: property listing for Settings page.
		add_action( 'wp_ajax_seo_agent_ai_list_gsc_sites', array( $this, 'ajax_list_gsc_sites' ) );
		add_action( 'wp_ajax_seo_agent_ai_list_ga4_properties', array( $this, 'ajax_list_ga4_properties' ) );

		// AJAX: interactive batch analysis.
		add_action( 'wp_ajax_seo_agent_ai_analyze_batch', array( $this, 'ajax_analyze_batch' ) );

		// Cron hooks — daily.
		add_action( self::CRON_HOOK_DAILY, array( $this, 'run_daily_analysis' ) );
		add_action( self::CRON_HOOK_MANUAL, array( $this, 'run_daily_analysis' ) );
		add_action( self::CRON_HOOK_GSC, array( $this, 'run_fetch_gsc' ) );
		add_action( self::CRON_HOOK_GA4, array( $this, 'run_fetch_ga4' ) );
		add_action( self::CRON_HOOK_REPORT, array( $this, 'run_generate_report' ) );

		// Cron hooks — weekly.
		add_action( self::CRON_HOOK_SCORE, array( $this, 'run_score_pages' ) );
		add_action( self::CRON_HOOK_DECAY, array( $this, 'run_detect_decay' ) );
		add_action( self::CRON_HOOK_LINKS, array( $this, 'run_internal_links' ) );
		add_action( self::CRON_HOOK_PURGE, array( $this, 'run_purge_old_data' ) );
		add_action( self::CRON_HOOK_CANNIBAL, array( $this, 'run_detect_cannibalization' ) );
		add_action( self::CRON_HOOK_IMPROVE, array( $this, 'run_improve_low_scoring_posts' ) );

		// Cron hook — orphan detection.
		add_action( self::CRON_HOOK_ORPHAN, array( $this, 'run_detect_orphans' ) );

		$this->image_seo->init_hooks();
		$this->social_meta->init_hooks();
		$this->meta_box->init_hooks();
		$this->taxonomy_seo->init_hooks();
		$this->redirect_manager->init_hooks();

		// Defensive: re-add cron schedules on every load (guarded by transient).
		add_action( 'init', array( $this, 'ensure_cron_schedules' ) );

		// Persistent admin notice when GSC/GA4 fails repeatedly.
		add_action( 'admin_notices', array( $this, 'maybe_render_api_failure_notice' ) );
	}

	// -------------------------------------------------------------------
	// Activation / deactivation
	// -------------------------------------------------------------------

	public static function activate() {
		SEO_Agent_AI_Activity_Log::create_table();
		SEO_Agent_AI_DB_Manager::create_tables();

		$daily_hooks = array(
			self::CRON_HOOK_DAILY,
			self::CRON_HOOK_GSC,
			self::CRON_HOOK_GA4,
			self::CRON_HOOK_REPORT,
		);
		$offset      = 0;
		foreach ( $daily_hooks as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS + $offset * 5 * MINUTE_IN_SECONDS, 'daily', $hook );
			}
			++$offset;
		}

		$weekly_hooks = array(
			self::CRON_HOOK_SCORE,
			self::CRON_HOOK_DECAY,
			self::CRON_HOOK_LINKS,
			self::CRON_HOOK_PURGE,
			self::CRON_HOOK_CANNIBAL,
			self::CRON_HOOK_IMPROVE,
			self::CRON_HOOK_ORPHAN,
		);
		foreach ( $weekly_hooks as $hook ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', $hook );
			}
		}

		add_option( SEO_Agent_AI_Data_Store::OPTION_LAST_RUN, array(), '', false );
	}

	public static function deactivate() {
		$all_hooks = array(
			self::CRON_HOOK_DAILY,
			self::CRON_HOOK_MANUAL,
			self::CRON_HOOK_GSC,
			self::CRON_HOOK_GA4,
			self::CRON_HOOK_REPORT,
			self::CRON_HOOK_SCORE,
			self::CRON_HOOK_DECAY,
			self::CRON_HOOK_LINKS,
			self::CRON_HOOK_PURGE,
			self::CRON_HOOK_CANNIBAL,
			self::CRON_HOOK_IMPROVE,
			self::CRON_HOOK_ORPHAN,
		);
		foreach ( $all_hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public static function maybe_upgrade() {
		$installed = (int) get_option( SEO_Agent_AI_Activity_Log::DB_VERSION_OPTION, 0 );
		if ( $installed < SEO_Agent_AI_Activity_Log::DB_VERSION ) {
			SEO_Agent_AI_Activity_Log::create_table();
		}
		SEO_Agent_AI_DB_Manager::maybe_upgrade();
	}

	/**
	 * Ensure all cron events are scheduled.
	 * Guarded by a 1-hour transient to avoid 9 DB queries on every page load.
	 */
	public function ensure_cron_schedules() {
		if ( get_transient( 'seo_agent_ai_cron_checked' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_DAILY );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_GSC ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK_GSC );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_GA4 ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS + 10 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK_GA4 );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_REPORT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS + 15 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK_REPORT );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_SCORE ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK_SCORE );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_DECAY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK_DECAY );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_LINKS ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK_LINKS );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_PURGE ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK_PURGE );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_CANNIBAL ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK_CANNIBAL );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_IMPROVE ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK_IMPROVE );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK_ORPHAN ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK_ORPHAN );
		}

		set_transient( 'seo_agent_ai_cron_checked', 1, HOUR_IN_SECONDS );
	}

	// -------------------------------------------------------------------
	// Cron callbacks — specialized jobs
	// -------------------------------------------------------------------

	public function run_fetch_gsc() {
		$this->logger->info( 'Starting dedicated GSC keyword history fetch.' );
		$post_types = (array) get_option( 'seo_agent_ai_post_types', array( 'post' ) );
		$posts      = get_posts(
			array(
				'post_type'   => $post_types ?: array( 'post' ),
				'post_status' => 'publish',
				'numberposts' => 100,
			)
		);

		foreach ( $posts as $post ) {
			$url = get_permalink( $post );
			if ( ! $url ) {
				continue;
			}
			$history = $this->gsc_client->get_keyword_history( $url, 90 );
			if ( is_array( $history ) ) {
				foreach ( $history as $kw => $data ) {
					if ( is_string( $kw ) && is_array( $data ) ) {
						SEO_Agent_AI_DB_Manager::upsert_keyword_history( (int) $post->ID, $kw, $data );
					}
				}
			}
			update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_GSC, current_time( 'mysql' ), false );
		}

		$this->logger->info( 'GSC keyword history fetch complete. Posts: ' . count( $posts ) );
	}

	public function run_fetch_ga4() {
		$this->logger->info( 'Starting dedicated GA4 engagement metrics fetch.' );
		$quality = $this->ga4_client->get_landing_page_quality( 28, 100 );
		if ( is_array( $quality ) ) {
			foreach ( $quality as $item ) {
				$post_id = url_to_postid( $item['page'] ?? '' );
				if ( $post_id ) {
					SEO_Agent_AI_DB_Manager::upsert_page_insight_engagement( $post_id, $item );
				}
			}
		}
		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_GA4, current_time( 'mysql' ), false );
		$this->logger->info( 'GA4 engagement fetch complete.' );
	}

	public function run_generate_report() {
		$this->logger->info( 'Generating daily SEO report.' );
		$result = $this->report_engine->generate( '', false );
		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_REPORT, current_time( 'mysql' ), false );
		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Report generation failed: ' . $result->get_error_message() );
		} else {
			$this->logger->info( 'Daily report generated.' );
		}
	}

	public function run_score_pages() {
		$this->logger->info( 'Starting weekly SEO scoring pass.' );
		$post_types = (array) get_option( 'seo_agent_ai_post_types', array( 'post' ) );
		$posts      = get_posts(
			array(
				'post_type'   => $post_types ?: array( 'post' ),
				'post_status' => 'publish',
				'numberposts' => 200,
			)
		);

		foreach ( $posts as $post ) {
			$score_data = $this->scoring_engine->score( $post );
			if ( is_array( $score_data ) ) {
				SEO_Agent_AI_DB_Manager::upsert_page_insight( (int) $post->ID, $score_data );
				update_post_meta( (int) $post->ID, '_seo_agent_ai_score', $score_data['overall'] ?? 0 );
			}
		}
		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_SCORE, current_time( 'mysql' ), false );
		$this->logger->info( 'Scoring pass complete. Posts: ' . count( $posts ) );
	}

	public function run_detect_decay() {
		$this->logger->info( 'Running content decay detection.' );
		$post_types = (array) get_option( 'seo_agent_ai_post_types', array( 'post' ) );
		$posts      = get_posts(
			array(
				'post_type'   => $post_types ?: array( 'post' ),
				'post_status' => 'publish',
				'numberposts' => 100,
			)
		);

		$flagged = 0;
		foreach ( $posts as $post ) {
			$content_data = $this->content_analyzer->analyze( $post );
			if ( empty( $content_data['content_decay_risk'] ) ) {
				continue;
			}

			$freshness  = isset( $content_data['freshness_score'] ) ? (float) $content_data['freshness_score'] : 0.5;
			$confidence = round( max( 0.50, min( 0.80, 1.0 - $freshness ) ), 3 );

			$rec = array(
				'type'            => 'content_refresh',
				'field'           => 'content',
				'proposed_value'  => '',
				'current_value'   => '',
				'confidence'      => $confidence,
				'reasoning'       => 'Content may be outdated based on publish date and stale year references.',
				'expected_impact' => 'Moderate traffic recovery possible after a content refresh.',
				'risk_level'      => 'safe',
			);
			$this->decision_engine->process( (int) $post->ID, $rec, 0.70, false );
			++$flagged;
		}

		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_DECAY, current_time( 'mysql' ), false );
		$this->logger->info( "Decay detection complete. Flagged: {$flagged}" );
	}

	/**
	 * Weekly cannibalization check.
	 * Reads keyword_history DB table (populated by run_fetch_gsc) — no live API calls.
	 * Pages sharing a high-impression keyword are flagged via the decision engine.
	 */
	public function run_detect_cannibalization() {
		global $wpdb;

		$this->logger->info( 'Running cannibalization detection from keyword history.' );

		$table = SEO_Agent_AI_DB_Manager::keyword_history_table();
		$since = gmdate( 'Y-m-d', strtotime( '-28 days' ) );

		// Aggregate impressions per (post_id, keyword) over the last 28 days.
		$sql = 'SELECT post_id, keyword,
		        SUM(impressions) AS total_impressions,
		        AVG(position) AS avg_position
		 FROM ' . $table . '
		 WHERE recorded_at >= %s AND impressions > 0
		 GROUP BY post_id, keyword
		 HAVING total_impressions >= 20
		 ORDER BY keyword, total_impressions DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $rows ) ) {
			$this->logger->info( 'No keyword history data for cannibalization check.' );
			update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_CANNIBAL, current_time( 'mysql' ), false );
			return;
		}

		// Build keyword → [ { post_id, impressions, position } ] map.
		$kw_map = array();
		foreach ( $rows as $row ) {
			$kw = strtolower( trim( (string) $row['keyword'] ) );
			if ( ! isset( $kw_map[ $kw ] ) ) {
				$kw_map[ $kw ] = array();
			}
			$kw_map[ $kw ][] = array(
				'post_id'     => (int) $row['post_id'],
				'impressions' => (int) $row['total_impressions'],
				'position'    => (float) $row['avg_position'],
			);
		}

		$flagged = 0;
		foreach ( $kw_map as $keyword => $pages ) {
			if ( count( $pages ) < 2 ) {
				continue;
			}

			// Sort: best-ranking page first (lowest position number = better).
			usort(
				$pages,
				function ( $a, $b ) {
					return $a['position'] <=> $b['position'];
				}
			);

			$primary           = $pages[0];
			$total_impressions = array_sum( array_column( $pages, 'impressions' ) );

			// Flag each weaker competing page.
			foreach ( array_slice( $pages, 1 ) as $competing ) {
				$confidence = round( min( 0.85, 0.50 + ( $total_impressions / 5000 ) ), 3 );

				$rec = array(
					'type'            => 'cannibalization',
					'field'           => 'content',
					'proposed_value'  => '',
					'current_value'   => '',
					'confidence'      => $confidence,
					'reasoning'       => sprintf(
						/* translators: 1: keyword, 2: primary position, 3: competing position. */
						__( 'Keyword “%1$s” ranks for multiple pages (positions %2$.1f vs %3$.1f). Consider differentiating or consolidating content.', 'seo-agent-ai' ),
						esc_html( $keyword ),
						$primary['position'],
						$competing['position']
					),
					'expected_impact' => 'Reducing keyword cannibalization can improve ranking clarity and overall CTR.',
					'risk_level'      => 'safe',
				);

				$this->decision_engine->process( (int) $competing['post_id'], $rec, 0.70, false );
				++$flagged;
			}
		}

		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_CANNIBAL, current_time( 'mysql' ), false );
		$this->logger->info( "Cannibalization detection complete. Flagged: {$flagged}" );
	}

	public function run_internal_links() {
		$this->logger->info( 'Running internal link opportunity pass.' );
		$dry_run = (bool) get_option( 'seo_agent_ai_debug_mode', false );
		$result  = $this->internal_link_engine->run_pass( 5, $dry_run );
		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_LINKS, current_time( 'mysql' ), false );
		$this->logger->info( sprintf( 'Internal links pass complete. Inserted: %d, Skipped: %d', (int) ( $result['inserted'] ?? 0 ), (int) ( $result['skipped'] ?? 0 ) ) );
	}

	public function run_purge_old_data() {
		$this->logger->info( 'Running data purge.' );
		$retention = (int) get_option( 'seo_agent_ai_log_retention_days', 90 );
		SEO_Agent_AI_DB_Manager::purge_old_data( $retention );
		$this->activity_log->purge_old_entries( $retention );
		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_PURGE, current_time( 'mysql' ), false );
		$this->logger->info( 'Data purge complete.' );
	}

	/**
	 * Weekly cron: find posts scoring below the target threshold and generate
	 * targeted improvements for the weakest SEO dimensions.
	 *
	 * The target threshold is configurable via the `seo_agent_ai_score_target`
	 * option (default 70). Up to 30 lowest-scoring posts are processed per run.
	 * Safe fixes are auto-applied when autopilot is on; everything else is
	 * routed to the pending-approval queue.
	 */
	public function run_improve_low_scoring_posts() {
		$this->logger->info( 'Starting score-targeted improvement pass.' );

		$target    = max( 1, min( 100, (int) get_option( 'seo_agent_ai_score_target', 70 ) ) );
		$autopilot = (bool) get_option( 'seo_agent_ai_autopilot_enabled', false );
		$post_ids  = SEO_Agent_AI_DB_Manager::get_posts_below_score_threshold( $target, 30 );

		if ( empty( $post_ids ) ) {
			$this->logger->info( 'Score improvement pass: no posts below threshold ' . $target . '.' );
			update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_IMPROVE, current_time( 'mysql' ), false );
			return;
		}

		$queued = 0;
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
				continue;
			}

			// Re-score to get fresh dimension data.
			$score_data = $this->scoring_engine->score( $post );
			if ( ! is_array( $score_data ) ) {
				continue;
			}

			SEO_Agent_AI_DB_Manager::upsert_page_insight( $post_id, $score_data );
			update_post_meta( $post_id, '_seo_agent_ai_score', $score_data['overall'] ?? 0 );

			// Skip if the freshly-computed score already meets the target.
			if ( ( $score_data['overall'] ?? 0 ) >= $target ) {
				continue;
			}

			$this->generate_score_improvements( $post, $score_data, $autopilot );
			++$queued;
		}

		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_IMPROVE, current_time( 'mysql' ), false );
		$this->logger->info(
			sprintf(
				'Score improvement pass complete. Checked: %d, queued improvements for: %d (target ≥ %d).',
				count( $post_ids ),
				$queued,
				$target
			)
		);
	}

	/**
	 * Generate, persist, and route improvement recommendations for a single
	 * low-scoring post. Prioritises the weakest scoring dimensions.
	 *
	 * @param WP_Post $post
	 * @param array   $score_data  ScoringEngine output (overall, dimensions, signals, improvements).
	 * @param bool    $autopilot   Whether autopilot mode is active.
	 */
	private function generate_score_improvements( WP_Post $post, array $score_data, $autopilot ) {
		$overall    = (int) ( $score_data['overall'] ?? 0 );
		$dimensions = is_array( $score_data['dimensions'] ?? null ) ? $score_data['dimensions'] : array();
		$target     = max( 1, min( 100, (int) get_option( 'seo_agent_ai_score_target', 70 ) ) );
		$url        = get_permalink( $post );

		// Reuse already-cached API data (populated by daily GSC/GA4 cron jobs).
		$gsc_metrics = $url ? $this->gsc_client->get_page_metrics( $url ) : array();
		$ga4_metrics = $url ? $this->ga4_client->get_page_metrics( $url ) : array();
		$gsc_safe    = is_wp_error( $gsc_metrics ) ? array() : (array) $gsc_metrics;
		$ga4_safe    = is_wp_error( $ga4_metrics ) ? array() : (array) $ga4_metrics;

		$seo_audit = $this->bridge->audit_post( (int) $post->ID, $post );
		$analysis  = $this->analyzer->analyze( $post, $gsc_safe, $ga4_safe, $seo_audit );
		$min_conf  = (float) get_option( 'seo_agent_ai_autopilot_min_confidence', 0.7 );

		$recommendations = $this->recommendation_engine->generate(
			$post,
			$analysis,
			$gsc_safe,
			$ga4_safe,
			$seo_audit,
			$min_conf,
			false
		);

		if ( empty( $recommendations ) ) {
			return;
		}

		// Sort recommendations: weakest dimensions first so the most impactful
		// fixes are processed first within the daily autopilot budget.
		$dim_scores = array();
		foreach ( $dimensions as $dim => $dim_score ) {
			$dim_scores[ $dim ] = (int) $dim_score;
		}
		asort( $dim_scores );
		$weakest_dims = array_keys( $dim_scores );

		usort(
			$recommendations,
			function ( $a, $b ) use ( $weakest_dims ) {
				$a_idx = array_search( $a['field'] ?? '', $weakest_dims, true );
				$b_idx = array_search( $b['field'] ?? '', $weakest_dims, true );
				$a_idx = $a_idx === false ? PHP_INT_MAX : $a_idx;
				$b_idx = $b_idx === false ? PHP_INT_MAX : $b_idx;
				return $a_idx <=> $b_idx;
			}
		);

		$this->data_store->save_recommendations( (int) $post->ID, $recommendations );

		$signal_data = array(
			'signals'      => $analysis['signals'] ?? array(),
			'evidence'     => $analysis['evidence'] ?? array(),
			'score_before' => $overall,
			'score_target' => $target,
		);

		foreach ( $recommendations as $rec ) {
			$risk       = $rec['risk'] ?? 'risky';
			$confidence = (float) ( $rec['confidence'] ?? 0.0 );
			$reasoning  = $rec['reasoning'] ?? sprintf(
				/* translators: 1: current score, 2: target score, 3: dimension name. */
				__( 'Score %1$d → target %2$d: improve %3$s dimension.', 'seo-agent-ai' ),
				$overall,
				$target,
				$rec['field'] ?? 'unknown'
			);

			$decision_rec = array(
				'type'            => $rec['type'] ?? 'score_improvement',
				'field'           => $rec['field'] ?? '',
				'proposed_value'  => $rec['proposed_value'] ?? '',
				'current_value'   => $rec['current_value'] ?? '',
				'confidence'      => $confidence,
				'reasoning'       => $reasoning,
				'expected_impact' => $rec['expected_impact'] ?? __( 'Score improvement.', 'seo-agent-ai' ),
				'risk_level'      => $risk,
			);

			if ( $autopilot && $risk === 'safe' && $confidence >= $min_conf ) {
				$result = $this->fix_executor->apply(
					(int) $post->ID,
					$rec,
					SEO_Agent_AI_Activity_Log::TRIGGER_AUTOPILOT,
					$signal_data
				);
				if ( ! is_wp_error( $result ) && ! empty( $rec['decision_id'] ) ) {
					$this->decision_engine->mark_applied( (int) $rec['decision_id'] );
				}
			} else {
				// Route to pending-approval queue (risky, low-confidence, or autopilot off).
				$this->decision_engine->process( (int) $post->ID, $decision_rec, $min_conf, false );
			}
		}
	}

	// -------------------------------------------------------------------
	// Manual analysis trigger
	// -------------------------------------------------------------------

	public function handle_manual_analysis() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'seo-agent-ai' ) );
		}
		check_admin_referer( 'seo_agent_ai_run_analysis' );

		if ( ! wp_next_scheduled( self::CRON_HOOK_MANUAL ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK_MANUAL );
		}

		wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'analysis_scheduled', admin_url( 'admin.php?page=seo-agent-ai' ) ) );
		exit;
	}

	// -------------------------------------------------------------------
	// Core analysis pipeline (cron + manual)
	// -------------------------------------------------------------------

	public function run_daily_analysis() {
		if ( ! $this->acquire_lock() ) {
			return;
		}

		$started_at    = current_time( 'mysql' );
		$processed     = 0;
		$with_recs     = 0;
		$failed        = 0;
		$autopilot     = (bool) get_option( 'seo_agent_ai_autopilot_enabled', false );
		$log_retention = (int) get_option( 'seo_agent_ai_log_retention_days', 90 );

		try {
			$posts = $this->get_posts_for_analysis( 50 );

			foreach ( $posts as $post ) {
				++$processed;
				$result = $this->analyze_single_post( $post, $autopilot );
				if ( $result['had_api_failure'] ) {
					++$failed;
				}
				if ( $result['had_recommendations'] ) {
					++$with_recs;
				}
			}

			$this->update_api_failure_tracker( $processed, $failed );

			$this->data_store->set_last_run(
				array(
					'started_at'                 => $started_at,
					'finished_at'                => current_time( 'mysql' ),
					'processed_posts'            => $processed,
					'posts_with_recommendations' => $with_recs,
					'failed_posts'               => $failed,
					'mode'                       => wp_doing_cron() ? 'cron' : 'manual',
				)
			);

			if ( $log_retention > 0 ) {
				$this->activity_log->purge_old_entries( $log_retention );
			}

			update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_DAILY, current_time( 'mysql' ), false );

		} finally {
			$this->release_lock();
		}
	}

	// -------------------------------------------------------------------
	// Per-post analysis (shared by cron, manual, and batch AJAX)
	// -------------------------------------------------------------------

	private function analyze_single_post( WP_Post $post, $autopilot = false ) {
		$url = get_permalink( $post );
		if ( ! $url ) {
			return array(
				'had_recommendations' => false,
				'had_api_failure'     => false,
			);
		}

		$gsc_metrics = $this->gsc_client->get_page_metrics( $url );
		$ga4_metrics = $this->ga4_client->get_page_metrics( $url );
		$seo_audit   = $this->bridge->audit_post( (int) $post->ID, $post );

		$had_api_failure = is_wp_error( $gsc_metrics ) || is_wp_error( $ga4_metrics );
		$gsc_safe        = is_wp_error( $gsc_metrics ) ? array() : $gsc_metrics;
		$ga4_safe        = is_wp_error( $ga4_metrics ) ? array() : $ga4_metrics;

		if ( $had_api_failure ) {
			$msg = is_wp_error( $gsc_metrics ) ? $gsc_metrics->get_error_message() : '';
			if ( $msg === '' && is_wp_error( $ga4_metrics ) ) {
				$msg = $ga4_metrics->get_error_message();
			}
			update_option( self::OPTION_LAST_API_ERROR, $msg, false );
		}

		$analysis        = $this->analyzer->analyze( $post, $gsc_safe, $ga4_safe, $seo_audit );
		$autopilot_conf  = (float) get_option( 'seo_agent_ai_autopilot_min_confidence', 0.7 );
		$recommendations = $this->recommendation_engine->generate(
			$post,
			$analysis,
			$gsc_safe,
			$ga4_safe,
			$seo_audit,
			$autopilot_conf,
			false
		);

		$metrics = array(
			'gsc'        => $gsc_safe,
			'ga4'        => $ga4_safe,
			'analysis'   => $analysis,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( is_wp_error( $gsc_metrics ) ) {
			$metrics['gsc_error'] = $gsc_metrics->get_error_message();
		}
		if ( is_wp_error( $ga4_metrics ) ) {
			$metrics['ga4_error'] = $ga4_metrics->get_error_message();
		}

		$this->data_store->save_post_metrics( (int) $post->ID, $metrics );
		$this->data_store->save_recommendations( (int) $post->ID, $recommendations );

		update_post_meta( (int) $post->ID, '_seo_agent_ai_last_analyzed', current_time( 'mysql' ) );

		$had_recs = ! empty( $recommendations );
		if ( $autopilot && $had_recs ) {
			$this->maybe_autopilot_apply( (int) $post->ID, $recommendations, $analysis );
		}

		return array(
			'had_recommendations' => $had_recs,
			'had_api_failure'     => $had_api_failure,
			'title'               => $post->post_title,
		);
	}

	// -------------------------------------------------------------------
	// Post selection: round-robin across configured post types
	// -------------------------------------------------------------------

	/**
	 * Return $count posts using a persistent offset so every run covers
	 * a different slice. Wraps around when the end of the list is reached.
	 *
	 * @param int $count
	 * @return WP_Post[]
	 */
	private function get_posts_for_analysis( $count = 50 ) {
		$post_types = (array) get_option( 'seo_agent_ai_post_types', array( 'post' ) );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
		}

		// Count total published posts across all configured post types.
		$total = 0;
		foreach ( $post_types as $pt ) {
			$counts = wp_count_posts( $pt );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		if ( $total === 0 ) {
			return array();
		}

		$offset = (int) get_option( 'seo_agent_ai_analysis_offset', 0 );
		if ( $offset >= $total ) {
			$offset = 0;
		}

		$posts = get_posts(
			array(
				'post_type'     => $post_types,
				'post_status'   => 'publish',
				'numberposts'   => (int) $count,
				'offset'        => $offset,
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'no_found_rows' => true,
			)
		);

		// Advance offset; wrap to zero when we pass the end of the list.
		$new_offset = $offset + count( $posts );
		update_option( 'seo_agent_ai_analysis_offset', $new_offset >= $total ? 0 : $new_offset, false );

		return $posts;
	}

	// -------------------------------------------------------------------
	// AJAX: interactive batch analysis
	// -------------------------------------------------------------------

	public function ajax_analyze_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
			return;
		}
		check_ajax_referer( 'seo_agent_ai_analyze_batch' );

		if ( get_transient( self::ANALYSIS_LOCK_KEY ) ) {
			wp_send_json_error( __( 'Analysis already in progress (scheduled task). Please wait.', 'seo-agent-ai' ) );
			return;
		}

		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch_size = 5;
		$autopilot  = (bool) get_option( 'seo_agent_ai_autopilot_enabled', false );

		// For AJAX batch we get a consistent snapshot of posts for the session.
		$post_types = (array) get_option( 'seo_agent_ai_post_types', array( 'post' ) );
		$posts      = get_posts(
			array(
				'post_type'   => $post_types ?: array( 'post' ),
				'post_status' => 'publish',
				'numberposts' => 200,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			)
		);

		$total = count( $posts );

		if ( $offset === 0 ) {
			$state = array(
				'with_recs'  => 0,
				'failed'     => 0,
				'started_at' => current_time( 'mysql' ),
			);
			set_transient( self::BATCH_ANALYSIS_KEY, $state, 30 * MINUTE_IN_SECONDS );
		} else {
			$state = get_transient( self::BATCH_ANALYSIS_KEY );
			if ( ! is_array( $state ) ) {
				$state = array(
					'with_recs'  => 0,
					'failed'     => 0,
					'started_at' => current_time( 'mysql' ),
				);
			}
		}

		$batch         = array_slice( $posts, $offset, $batch_size );
		$current_title = '';

		foreach ( $batch as $post ) {
			$current_title = $post->post_title;
			$result        = $this->analyze_single_post( $post, $autopilot );
			if ( $result['had_api_failure'] ) {
				++$state['failed'];
			}
			if ( $result['had_recommendations'] ) {
				++$state['with_recs'];
			}
		}

		$processed = min( $offset + count( $batch ), $total );
		$done      = ( $processed >= $total );

		set_transient( self::BATCH_ANALYSIS_KEY, $state, 30 * MINUTE_IN_SECONDS );

		if ( $done ) {
			$this->data_store->set_last_run(
				array(
					'started_at'                 => $state['started_at'],
					'finished_at'                => current_time( 'mysql' ),
					'processed_posts'            => $total,
					'posts_with_recommendations' => (int) $state['with_recs'],
					'failed_posts'               => (int) $state['failed'],
					'mode'                       => 'manual',
				)
			);
			$log_retention = (int) get_option( 'seo_agent_ai_log_retention_days', 90 );
			if ( $log_retention > 0 ) {
				$this->activity_log->purge_old_entries( $log_retention );
			}
			$this->update_api_failure_tracker( $total, (int) $state['failed'] );
			delete_transient( self::BATCH_ANALYSIS_KEY );
		}

		wp_send_json_success(
			array(
				'processed'     => $processed,
				'total'         => $total,
				'percent'       => $total > 0 ? (int) round( ( $processed / $total ) * 100 ) : 100,
				'done'          => $done,
				'current_title' => $current_title,
				'with_recs'     => (int) $state['with_recs'],
				'failed'        => (int) $state['failed'],
			)
		);
	}

	// -------------------------------------------------------------------
	// Autopilot: auto-apply safe recommendations
	// -------------------------------------------------------------------

	private function maybe_autopilot_apply( $post_id, array $recommendations, array $analysis ) {
		$max_daily      = (int) get_option( 'seo_agent_ai_autopilot_max_daily', 5 );
		$min_confidence = (float) get_option( 'seo_agent_ai_autopilot_min_confidence', 0.7 );
		$date_key       = self::DAILY_AP_TRANSIENT_PREFIX . gmdate( 'Y-m-d' );
		$applied_today  = (int) get_transient( $date_key );

		$signal_data = array(
			'signals'  => isset( $analysis['signals'] ) ? $analysis['signals'] : array(),
			'evidence' => isset( $analysis['evidence'] ) ? $analysis['evidence'] : array(),
		);

		foreach ( $recommendations as $rec ) {
			if ( $applied_today >= $max_daily ) {
				break;
			}

			$risk       = isset( $rec['risk'] ) ? $rec['risk'] : 'risky';
			$confidence = isset( $rec['confidence'] ) ? (float) $rec['confidence'] : 0.0;

			if ( $risk !== 'safe' ) {
				continue;
			}

			if ( $confidence < $min_confidence ) {
				continue;
			}

			// Skip if this rec was already routed to decision engine as pending.
			if ( isset( $rec['decision_tier'] ) && $rec['decision_tier'] === 'pending_approval' ) {
				continue;
			}

			$result = $this->fix_executor->apply(
				$post_id,
				$rec,
				SEO_Agent_AI_Activity_Log::TRIGGER_AUTOPILOT,
				$signal_data
			);

			if ( ! is_wp_error( $result ) ) {
				++$applied_today;
				set_transient( $date_key, $applied_today, DAY_IN_SECONDS );
				// Mark the queued decision as applied so it doesn't re-appear as pending.
				if ( ! empty( $rec['decision_id'] ) ) {
					$this->decision_engine->mark_applied( (int) $rec['decision_id'] );
				}
			}
		}
	}

	// -------------------------------------------------------------------
	// Manual "Approve & Apply" handler
	// -------------------------------------------------------------------

	public function handle_apply_fix() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'seo-agent-ai' ) );
		}
		check_admin_referer( 'seo_agent_ai_apply_fix' );

		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$rec_index = isset( $_POST['rec_index'] ) ? absint( $_POST['rec_index'] ) : -1;

		if ( ! $post_id || $rec_index < 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'invalid_input', admin_url( 'admin.php?page=seo-agent-ai' ) ) );
			exit;
		}

		$recommendations = $this->data_store->get_recommendations( $post_id );
		if ( ! isset( $recommendations[ $rec_index ] ) ) {
			wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'recommendation_not_found', admin_url( 'admin.php?page=seo-agent-ai' ) ) );
			exit;
		}

		$metrics     = $this->data_store->get_post_metrics( $post_id );
		$analysis    = isset( $metrics['analysis'] ) ? $metrics['analysis'] : array();
		$signal_data = array(
			'signals'  => isset( $analysis['signals'] ) ? $analysis['signals'] : array(),
			'evidence' => isset( $analysis['evidence'] ) ? $analysis['evidence'] : array(),
		);

		$result = $this->fix_executor->apply(
			$post_id,
			$recommendations[ $rec_index ],
			SEO_Agent_AI_Activity_Log::TRIGGER_MANUAL,
			$signal_data
		);

		$notice = is_wp_error( $result ) ? 'apply_failed' : 'fix_applied';
		wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', $notice, admin_url( 'admin.php?page=seo-agent-ai' ) ) );
		exit;
	}

	// -------------------------------------------------------------------
	// Rollback: post-meta backup (from Overview page)
	// -------------------------------------------------------------------

	public function handle_rollback_backup() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'seo-agent-ai' ) );
		}
		check_admin_referer( 'seo_agent_ai_rollback_backup' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'invalid_input', admin_url( 'admin.php?page=seo-agent-ai' ) ) );
			exit;
		}

		$result = $this->fix_executor->rollback( $post_id );
		$notice = is_wp_error( $result ) ? 'rollback_failed' : 'rollback_done';
		wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', $notice, admin_url( 'admin.php?page=seo-agent-ai' ) ) );
		exit;
	}

	// -------------------------------------------------------------------
	// Rollback: activity log entry (from Report page)
	// -------------------------------------------------------------------

	public function handle_activity_rollback() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'seo-agent-ai' ) );
		}
		check_admin_referer( 'seo_agent_ai_rollback' );

		$log_id  = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $log_id || ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'invalid_input', admin_url( 'admin.php?page=seo-agent-ai-report' ) ) );
			exit;
		}

		$entry = $this->activity_log->get_entry( $log_id );
		if ( ! $entry || (int) $entry['post_id'] !== $post_id ) {
			wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'rollback_failed', admin_url( 'admin.php?page=seo-agent-ai-report' ) ) );
			exit;
		}

		$field  = (string) $entry['field_changed'];
		$before = (string) $entry['value_before'];

		$bridge_field_map = array(
			'meta_title'       => 'title',
			'meta_description' => 'description',
		);

		if ( isset( $bridge_field_map[ $field ] ) ) {
			$keys = $this->bridge->get_all_backup_keys( $bridge_field_map[ $field ] );
			foreach ( $keys as $meta_key ) {
				update_post_meta( $post_id, $meta_key, $before );
			}

			$this->activity_log->log(
				$post_id,
				SEO_Agent_AI_Activity_Log::TRIGGER_ROLLBACK,
				$field,
				(string) $entry['value_after'],
				$before,
				/* translators: %d: activity log entry id. */
				sprintf( __( 'Rolled back log entry #%d.', 'seo-agent-ai' ), $log_id ),
				array(),
				1.0,
				SEO_Agent_AI_Activity_Log::TRIGGER_ROLLBACK
			);

			$this->activity_log->update_status( $log_id, SEO_Agent_AI_Activity_Log::STATUS_ROLLED_BACK );
		}

		wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'rollback_done', admin_url( 'admin.php?page=seo-agent-ai-report' ) ) );
		exit;
	}

	// -------------------------------------------------------------------
	// Settings save
	// -------------------------------------------------------------------

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'seo-agent-ai' ) );
		}
		check_admin_referer( 'seo_agent_ai_save_settings' );

		$client_id     = isset( $_POST['google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_id'] ) ) : '';
		$client_secret = isset( $_POST['google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_secret'] ) ) : '';
		$gsc_site_url  = isset( $_POST['gsc_site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['gsc_site_url'] ) ) : '';
		$ga4_property  = isset( $_POST['ga4_property_id'] ) ? preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['ga4_property_id'] ) ) ) : '';
		$gemini_key    = isset( $_POST['gemini_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gemini_api_key'] ) ) : '';
		$autopilot     = ! empty( $_POST['autopilot_enabled'] );
		$max_daily     = isset( $_POST['autopilot_max_daily'] ) ? max( 1, min( 50, absint( $_POST['autopilot_max_daily'] ) ) ) : 5;
		$min_conf      = isset( $_POST['autopilot_min_confidence'] ) ? round( min( 1.0, max( 0.1, (float) sanitize_text_field( wp_unslash( $_POST['autopilot_min_confidence'] ) ) ) ), 2 ) : 0.7;
		$log_retention = isset( $_POST['log_retention_days'] ) ? max( 7, min( 730, absint( $_POST['log_retention_days'] ) ) ) : 90;

		// OpenAI / AI provider settings.
		$ai_provider   = isset( $_POST['ai_provider'] ) ? sanitize_key( $_POST['ai_provider'] ) : 'gemini';
		$openai_key    = isset( $_POST['openai_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ) ) : '';
		$openai_url    = isset( $_POST['openai_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['openai_base_url'] ) ) : '';
		$openai_model  = isset( $_POST['openai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_model'] ) ) : '';
		$email_reports = ! empty( $_POST['email_reports'] );

		if ( ! in_array( $ai_provider, array( 'gemini', 'openai', 'auto' ), true ) ) {
			$ai_provider = 'gemini';
		}

		if ( $client_id !== '' ) {
			update_option( SEO_Agent_AI_Google_OAuth::OPTION_CLIENT_ID, $client_id, false );
		}
		if ( $client_secret !== '' ) {
			update_option( SEO_Agent_AI_Google_OAuth::OPTION_CLIENT_SECRET, SEO_Agent_AI_Crypto::encrypt( $client_secret ), false );
		}
		if ( $gemini_key !== '' ) {
			update_option( SEO_Agent_AI_Gemini_Client::OPTION_API_KEY, SEO_Agent_AI_Crypto::encrypt( $gemini_key ), false );
		}
		if ( $openai_key !== '' ) {
			update_option( SEO_Agent_AI_OpenAI_Client::OPTION_API_KEY, SEO_Agent_AI_Crypto::encrypt( $openai_key ), false );
		}

		update_option( SEO_Agent_AI_GSC_Client::OPTION_GSC_SITE_URL, $gsc_site_url, false );
		update_option( SEO_Agent_AI_GA4_Client::OPTION_GA4_PROPERTY_ID, $ga4_property, false );
		update_option( 'seo_agent_ai_autopilot_enabled', $autopilot, false );
		update_option( 'seo_agent_ai_autopilot_max_daily', $max_daily, false );
		update_option( 'seo_agent_ai_autopilot_min_confidence', $min_conf, false );
		update_option( 'seo_agent_ai_log_retention_days', $log_retention, false );
		update_option( 'seo_agent_ai_ai_provider', $ai_provider, false );
		update_option( SEO_Agent_AI_OpenAI_Client::OPTION_BASE_URL, $openai_url, false );
		update_option( SEO_Agent_AI_OpenAI_Client::OPTION_MODEL, $openai_model, false );
		update_option( 'seo_agent_ai_email_reports', $email_reports, false );

		wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'settings_saved', admin_url( 'admin.php?page=seo-agent-ai-settings' ) ) );
		exit;
	}

	// -------------------------------------------------------------------
	// Connection test
	// -------------------------------------------------------------------

	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'seo-agent-ai' ) );
		}
		check_admin_referer( 'seo_agent_ai_test_connection' );

		$gsc_result       = $this->gsc_client->test_connection();
		$analytics_result = $this->ga4_client->test_connection();

		if ( ! is_wp_error( $gsc_result ) && ! is_wp_error( $analytics_result ) ) {
			delete_option( self::OPTION_API_FAILURES );
			delete_option( self::OPTION_LAST_API_ERROR );
			delete_transient( 'seo_agent_ai_auth_health' );
		}

		set_transient(
			self::CONNECTION_TEST_TRANSIENT,
			array(
				'gsc'       => is_wp_error( $gsc_result )
					? array(
						'success' => false,
						'message' => $gsc_result->get_error_message(),
					)
					: array(
						'success' => true,
						'message' => isset( $gsc_result['message'] ) ? (string) $gsc_result['message'] : __( 'Search Console connected.', 'seo-agent-ai' ),
					),
				'analytics' => is_wp_error( $analytics_result )
					? array(
						'success' => false,
						'message' => $analytics_result->get_error_message(),
					)
					: array(
						'success' => true,
						'message' => isset( $analytics_result['message'] ) ? (string) $analytics_result['message'] : __( 'Analytics connected.', 'seo-agent-ai' ),
					),
			),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'connection_tested', admin_url( 'admin.php?page=seo-agent-ai-settings' ) ) );
		exit;
	}

	// -------------------------------------------------------------------
	// Google disconnect
	// -------------------------------------------------------------------

	public function handle_google_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'seo-agent-ai' ) );
		}
		check_admin_referer( 'seo_agent_ai_google_disconnect' );
		$this->oauth->disconnect();
		wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'google_disconnected', admin_url( 'admin.php?page=seo-agent-ai-connect' ) ) );
		exit;
	}

	// -------------------------------------------------------------------
	// OAuth callback (admin_init — before any page HTML is output)
	// -------------------------------------------------------------------

	public function maybe_handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( 'seo-agent-ai-connect' !== $page ) {
			return;
		}

		$code  = filter_input( INPUT_GET, 'code', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$state = filter_input( INPUT_GET, 'state', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$error = filter_input( INPUT_GET, 'error', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( ! $code && ! $error ) {
			return;
		}

		if ( $error ) {
			$msg = sanitize_text_field( wp_unslash( (string) $error ) );
			wp_safe_redirect(
				add_query_arg(
					'seo_agent_ai_oauth_error',
					rawurlencode( $msg ),
					admin_url( 'admin.php?page=seo-agent-ai-connect' )
				)
			);
			exit;
		}

		$result = $this->oauth->handle_callback(
			sanitize_text_field( wp_unslash( (string) $code ) ),
			sanitize_text_field( wp_unslash( (string) $state ) )
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					'seo_agent_ai_oauth_error',
					rawurlencode( $result->get_error_message() ),
					admin_url( 'admin.php?page=seo-agent-ai-connect' )
				)
			);
		} else {
			wp_safe_redirect(
				add_query_arg(
					'seo_agent_ai_notice',
					'google_connected',
					admin_url( 'admin.php?page=seo-agent-ai-connect' )
				)
			);
		}
		exit;
	}

	// -------------------------------------------------------------------
	// AJAX: list Google Search Console properties
	// -------------------------------------------------------------------

	public function ajax_list_gsc_sites() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
			return;
		}
		check_ajax_referer( 'seo_agent_ai_property_list' );

		$sites = $this->gsc_client->list_sites();
		if ( is_wp_error( $sites ) ) {
			wp_send_json_error( $sites->get_error_message() );
			return;
		}
		wp_send_json_success( $sites );
	}

	// -------------------------------------------------------------------
	// AJAX: list Google Analytics 4 properties
	// -------------------------------------------------------------------

	public function ajax_list_ga4_properties() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
			return;
		}
		check_ajax_referer( 'seo_agent_ai_property_list' );

		$properties = $this->ga4_client->list_properties();
		if ( is_wp_error( $properties ) ) {
			wp_send_json_error( $properties->get_error_message() );
			return;
		}
		wp_send_json_success( $properties );
	}

	// -------------------------------------------------------------------
	// Lock helpers
	// -------------------------------------------------------------------

	private function acquire_lock() {
		if ( get_transient( self::ANALYSIS_LOCK_KEY ) ) {
			return false;
		}
		set_transient( self::ANALYSIS_LOCK_KEY, 1, self::ANALYSIS_LOCK_TTL );
		return true;
	}

	private function release_lock() {
		delete_transient( self::ANALYSIS_LOCK_KEY );
	}

	// -------------------------------------------------------------------
	// API failure tracking & persistent admin notice
	// -------------------------------------------------------------------

	private function update_api_failure_tracker( $processed, $failed ) {
		$all_failed = $processed > 0 && $failed === $processed;
		$current    = (int) get_option( self::OPTION_API_FAILURES, 0 );

		if ( $all_failed ) {
			update_option( self::OPTION_API_FAILURES, $current + 1, false );
		} else {
			if ( $current > 0 ) {
				update_option( self::OPTION_API_FAILURES, 0, false );
			}
			delete_option( self::OPTION_LAST_API_ERROR );
		}
	}

	public function maybe_render_api_failure_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$count = (int) get_option( self::OPTION_API_FAILURES, 0 );
		if ( $count < self::API_FAILURE_NOTICE_AFTER ) {
			return;
		}

		$msg = (string) get_option( self::OPTION_LAST_API_ERROR, '' );

		echo '<div class="notice notice-error"><p>';
		echo '<strong>' . esc_html__( 'SEO Agent AI:', 'seo-agent-ai' ) . '</strong> ';
		printf(
			/* translators: %d: number of consecutive failed analysis runs. */
			esc_html__( 'Search Console / Analytics calls failed on the last %d analysis runs.', 'seo-agent-ai' ),
			(int) $count
		);
		echo ' ';
		printf(
			/* translators: 1: opening anchor for Connect page, 2: closing anchor. */
			esc_html__( 'Reconnect your Google account on the %1$sConnect page%2$s, or check Settings for property selection.', 'seo-agent-ai' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-connect' ) ) . '">',
			'</a>'
		);
		if ( $msg !== '' ) {
			echo '<br><em>' . esc_html__( 'Last error:', 'seo-agent-ai' ) . '</em> ' . esc_html( $msg );
		}
		echo '</p></div>';
	}

	// -------------------------------------------------------------------
	// Public analysis entry point for WP-CLI
	// -------------------------------------------------------------------

	public function analyze_post_for_cli( WP_Post $post, $autopilot = false, $dry_run = false ) {
		$url = get_permalink( $post );
		if ( ! $url ) {
			return array(
				'had_recommendations' => false,
				'had_api_failure'     => false,
				'signals'             => array(),
			);
		}

		$gsc_metrics = $this->gsc_client->get_page_metrics( $url );
		$ga4_metrics = $this->ga4_client->get_page_metrics( $url );
		$seo_audit   = $this->bridge->audit_post( (int) $post->ID, $post );

		$had_api_failure = is_wp_error( $gsc_metrics ) || is_wp_error( $ga4_metrics );
		$gsc_safe        = is_wp_error( $gsc_metrics ) ? array() : $gsc_metrics;
		$ga4_safe        = is_wp_error( $ga4_metrics ) ? array() : $ga4_metrics;

		$analysis        = $this->analyzer->analyze( $post, $gsc_safe, $ga4_safe, $seo_audit );
		$autopilot_conf  = (float) get_option( 'seo_agent_ai_autopilot_min_confidence', 0.7 );
		$recommendations = $this->recommendation_engine->generate(
			$post,
			$analysis,
			$gsc_safe,
			$ga4_safe,
			$seo_audit,
			$autopilot_conf,
			$dry_run
		);

		if ( ! $dry_run ) {
			$this->data_store->save_post_metrics(
				(int) $post->ID,
				array(
					'gsc'        => $gsc_safe,
					'ga4'        => $ga4_safe,
					'analysis'   => $analysis,
					'updated_at' => current_time( 'mysql' ),
				)
			);
			$this->data_store->save_recommendations( (int) $post->ID, $recommendations );
			update_post_meta( (int) $post->ID, '_seo_agent_ai_last_analyzed', current_time( 'mysql' ) );

			if ( $autopilot && ! empty( $recommendations ) ) {
				$this->maybe_autopilot_apply( (int) $post->ID, $recommendations, $analysis );
			}
		}

		return array(
			'had_recommendations' => ! empty( $recommendations ),
			'had_api_failure'     => $had_api_failure,
			'signals'             => isset( $analysis['signals'] ) ? $analysis['signals'] : array(),
			'recommendations'     => $recommendations,
			'title'               => $post->post_title,
		);
	}

	// -------------------------------------------------------------------
	// Cron: orphan detection
	// -------------------------------------------------------------------

	/**
	 * Detect orphan pages — published posts with no inbound internal links.
	 * Processes up to 100 posts per run to avoid slow-query timeouts.
	 */
	public function run_detect_orphans() {
		global $wpdb;

		$this->logger->info( 'Starting orphan page detection.' );

		$posts   = $this->get_posts_for_analysis( 100 );
		$orphans = 0;
		$checked = 0;

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			if ( ! $permalink ) {
				continue;
			}
			$permalink_path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
			if ( ! $permalink_path ) {
				continue;
			}

			// Count other published posts whose content contains a link to this post.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$link_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND ID != %d
				   AND post_content LIKE %s",
					$post->ID,
					'%' . $wpdb->esc_like( $permalink_path ) . '%'
				)
			);

			++$checked;

			if ( $link_count === 0 ) {
				++$orphans;
				$rec = array(
					'type'       => 'internal_linking',
					'field'      => 'content',
					'confidence' => 0.65,
					'reasoning'  => __( 'This post has no inbound internal links (orphan page). Adding internal links from related posts will help search engines discover and rank it.', 'seo-agent-ai' ),
					'risk_level' => 'safe',
				);

				$this->decision_engine->process( (int) $post->ID, $rec, 0.65, false );
			}
		}

		$this->logger->info( sprintf( 'Orphan detection complete. Checked: %d, Orphans found: %d', $checked, $orphans ) );
		update_option( 'seo_agent_ai_last_run_' . self::CRON_HOOK_ORPHAN, current_time( 'mysql' ), false );
	}

	// -------------------------------------------------------------------
	// Accessor methods for WP-CLI and admin pages
	// -------------------------------------------------------------------

	public function get_gsc_client() {
		return $this->gsc_client; }
	public function get_ga4_client() {
		return $this->ga4_client; }
	public function get_analyzer() {
		return $this->analyzer; }
	public function get_recommendation_engine() {
		return $this->recommendation_engine; }
	public function get_fix_executor() {
		return $this->fix_executor; }
	public function get_scoring_engine() {
		return $this->scoring_engine; }
	public function get_decision_engine() {
		return $this->decision_engine; }
	public function get_report_engine() {
		return $this->report_engine; }
	public function get_queue_manager() {
		return $this->queue_manager; }
	public function get_logger() {
		return $this->logger; }
	public function get_oauth() {
		return $this->oauth; }
	public function get_gemini() {
		return $this->gemini; }
	public function get_openai() {
		return $this->openai; }
	public function get_data_store() {
		return $this->data_store; }
	public function get_activity_log() {
		return $this->activity_log; }
	public function get_bridge() {
		return $this->bridge; }
}
