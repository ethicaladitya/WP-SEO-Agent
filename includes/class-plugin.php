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

	const ANALYSIS_LOCK_KEY          = 'seo_agent_ai_analysis_lock';
	const ANALYSIS_LOCK_TTL          = 15 * MINUTE_IN_SECONDS;
	const CONNECTION_TEST_TRANSIENT  = 'seo_agent_ai_connection_test_result';
	const DAILY_AP_TRANSIENT_PREFIX  = 'seo_agent_ai_ap_count_';

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

	/** @var SEO_Agent_AI_Admin_Page */
	private $admin_page;

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
		$this->data_store            = new SEO_Agent_AI_Data_Store();
		$this->oauth                 = new SEO_Agent_AI_Google_OAuth();
		$this->gsc_client            = new SEO_Agent_AI_GSC_Client( $this->oauth );
		$this->ga4_client            = new SEO_Agent_AI_GA4_Client( $this->oauth );
		$this->analyzer              = new SEO_Agent_AI_SEO_Analyzer();
		$this->recommendation_engine = new SEO_Agent_AI_Recommendation_Engine();
		$this->activity_log          = new SEO_Agent_AI_Activity_Log();
		$this->fix_executor          = new SEO_Agent_AI_Fix_Executor( $this->activity_log );

		$connect_page      = new SEO_Agent_AI_Connect_Page( $this->oauth );
		$report_page       = new SEO_Agent_AI_Report_Page( $this->activity_log, $this->data_store );
		$this->admin_page  = new SEO_Agent_AI_Admin_Page(
			$this->data_store,
			$connect_page,
			$report_page,
			$this->oauth
		);

		// Admin hooks.
		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_action( 'admin_post_seo_agent_ai_apply_fix',        array( $this, 'handle_apply_fix' ) );
		add_action( 'admin_post_seo_agent_ai_run_analysis',     array( $this, 'handle_manual_analysis' ) );
		add_action( 'admin_post_seo_agent_ai_save_settings',    array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_seo_agent_ai_test_connection',  array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_seo_agent_ai_google_disconnect',array( $this, 'handle_google_disconnect' ) );
		add_action( 'admin_post_seo_agent_ai_rollback_backup',  array( $this, 'handle_rollback_backup' ) );
		add_action( 'admin_post_seo_agent_ai_rollback',         array( $this, 'handle_activity_rollback' ) );

		// OAuth callback — must run before page output.
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_callback' ) );

		// AJAX: property listing for Settings page.
		add_action( 'wp_ajax_seo_agent_ai_list_gsc_sites',      array( $this, 'ajax_list_gsc_sites' ) );
		add_action( 'wp_ajax_seo_agent_ai_list_ga4_properties', array( $this, 'ajax_list_ga4_properties' ) );

		// Cron hook.
		add_action( 'seo_agent_ai_daily_analysis', array( $this, 'run_daily_analysis' ) );
	}

	// -------------------------------------------------------------------
	// Activation / deactivation
	// -------------------------------------------------------------------

	public static function activate() {
		SEO_Agent_AI_Activity_Log::create_table();

		if ( ! wp_next_scheduled( 'seo_agent_ai_daily_analysis' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'seo_agent_ai_daily_analysis' );
		}

		add_option( SEO_Agent_AI_Data_Store::OPTION_LAST_RUN, array(), '', false );
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'seo_agent_ai_daily_analysis' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'seo_agent_ai_daily_analysis' );
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
		$this->run_daily_analysis();
		wp_safe_redirect( add_query_arg( 'seo_agent_ai_notice', 'analysis_complete', admin_url( 'admin.php?page=seo-agent-ai' ) ) );
		exit;
	}

	// -------------------------------------------------------------------
	// Core analysis pipeline (cron + manual)
	// -------------------------------------------------------------------

	public function run_daily_analysis() {
		if ( ! $this->acquire_lock() ) {
			return;
		}

		$started_at       = current_time( 'mysql' );
		$processed        = 0;
		$with_recs        = 0;
		$failed           = 0;
		$autopilot        = (bool) get_option( 'seo_agent_ai_autopilot_enabled', false );
		$log_retention    = (int) get_option( 'seo_agent_ai_log_retention_days', 90 );

		try {
			$posts = get_posts( array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'numberposts' => 50,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			) );

			foreach ( $posts as $post ) {
				$processed++;
				$url = get_permalink( $post );
				if ( ! $url ) {
					continue;
				}

				$gsc_metrics = $this->gsc_client->get_page_metrics( $url );
				$ga4_metrics = $this->ga4_client->get_page_metrics( $url );

				if ( is_wp_error( $gsc_metrics ) || is_wp_error( $ga4_metrics ) ) {
					$failed++;
					$this->data_store->save_post_metrics( (int) $post->ID, array(
						'gsc_error'  => is_wp_error( $gsc_metrics ) ? $gsc_metrics->get_error_message() : '',
						'ga4_error'  => is_wp_error( $ga4_metrics ) ? $ga4_metrics->get_error_message() : '',
						'analysis'   => array(
							'signals'    => array(
								'content_refresh_needed'  => false,
								'title_meta_optimization' => false,
								'intent_mismatch'         => false,
								'declining_performance'   => false,
							),
							'severity'   => 'none',
							'confidence' => 0.0,
							'evidence'   => array(),
						),
						'updated_at' => current_time( 'mysql' ),
					) );
					$this->data_store->save_recommendations( (int) $post->ID, array() );
					continue;
				}

				$analysis        = $this->analyzer->analyze( $post, $gsc_metrics, $ga4_metrics );
				$recommendations = $this->recommendation_engine->generate( $post, $analysis, $gsc_metrics, $ga4_metrics );

				if ( ! empty( $recommendations ) ) {
					$with_recs++;
				}

				$this->data_store->save_post_metrics( (int) $post->ID, array(
					'gsc'        => $gsc_metrics,
					'ga4'        => $ga4_metrics,
					'analysis'   => $analysis,
					'updated_at' => current_time( 'mysql' ),
				) );
				$this->data_store->save_recommendations( (int) $post->ID, $recommendations );

				// Autopilot: auto-apply safe recommendations above confidence threshold.
				if ( $autopilot && ! empty( $recommendations ) ) {
					$this->maybe_autopilot_apply( (int) $post->ID, $recommendations, $analysis );
				}
			}

			$this->data_store->set_last_run( array(
				'started_at'                  => $started_at,
				'finished_at'                 => current_time( 'mysql' ),
				'processed_posts'             => $processed,
				'posts_with_recommendations'  => $with_recs,
				'failed_posts'                => $failed,
				'mode'                        => wp_doing_cron() ? 'cron' : 'manual',
			) );

			// Purge old activity log entries.
			if ( $log_retention > 0 ) {
				$this->activity_log->purge_old_entries( $log_retention );
			}

		} finally {
			$this->release_lock();
		}
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

			$result = $this->fix_executor->apply(
				$post_id,
				$rec,
				SEO_Agent_AI_Activity_Log::TRIGGER_AUTOPILOT,
				$signal_data
			);

			if ( ! is_wp_error( $result ) ) {
				$applied_today++;
				set_transient( $date_key, $applied_today, DAY_IN_SECONDS );
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

		// Restore the specific field that was changed.
		$field_map = array(
			'meta_title'       => array( '_seo_agent_ai_meta_title', '_yoast_wpseo_title', 'rank_math_title' ),
			'meta_description' => array( '_seo_agent_ai_meta_description', '_yoast_wpseo_metadesc', 'rank_math_description' ),
		);

		if ( isset( $field_map[ $field ] ) ) {
			foreach ( $field_map[ $field ] as $meta_key ) {
				update_post_meta( $post_id, $meta_key, $before );
			}

			// Log the rollback action.
			$this->activity_log->log(
				$post_id,
				SEO_Agent_AI_Activity_Log::TRIGGER_ROLLBACK,
				$field,
				(string) $entry['value_after'],
				$before,
				sprintf( 'Rolled back log entry #%d.', $log_id ),
				array(),
				1.0,
				SEO_Agent_AI_Activity_Log::TRIGGER_ROLLBACK
			);

			// Mark the original entry as rolled back.
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

		$client_id      = isset( $_POST['google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_id'] ) ) : '';
		$client_secret  = isset( $_POST['google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_secret'] ) ) : '';
		$gsc_site_url   = isset( $_POST['gsc_site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['gsc_site_url'] ) ) : '';
		$ga4_property   = isset( $_POST['ga4_property_id'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['ga4_property_id'] ) ) : '';
		$autopilot      = ! empty( $_POST['autopilot_enabled'] );
		$max_daily      = isset( $_POST['autopilot_max_daily'] ) ? max( 1, min( 50, absint( $_POST['autopilot_max_daily'] ) ) ) : 5;
		$min_conf       = isset( $_POST['autopilot_min_confidence'] ) ? round( min( 1.0, max( 0.1, (float) wp_unslash( $_POST['autopilot_min_confidence'] ) ) ), 2 ) : 0.7;
		$log_retention  = isset( $_POST['log_retention_days'] ) ? max( 7, min( 730, absint( $_POST['log_retention_days'] ) ) ) : 90;

		// Only update client credentials if the user typed something (avoid wiping with empty strings).
		if ( $client_id !== '' ) {
			update_option( SEO_Agent_AI_Google_OAuth::OPTION_CLIENT_ID, $client_id, false );
		}
		if ( $client_secret !== '' ) {
			update_option( SEO_Agent_AI_Google_OAuth::OPTION_CLIENT_SECRET, $client_secret, false );
		}

		update_option( SEO_Agent_AI_GSC_Client::OPTION_GSC_SITE_URL, $gsc_site_url, false );
		update_option( SEO_Agent_AI_GA4_Client::OPTION_GA4_PROPERTY_ID, $ga4_property, false );
		update_option( 'seo_agent_ai_autopilot_enabled', $autopilot, false );
		update_option( 'seo_agent_ai_autopilot_max_daily', $max_daily, false );
		update_option( 'seo_agent_ai_autopilot_min_confidence', $min_conf, false );
		update_option( 'seo_agent_ai_log_retention_days', $log_retention, false );

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

		set_transient(
			self::CONNECTION_TEST_TRANSIENT,
			array(
				'gsc'       => is_wp_error( $gsc_result )
					? array( 'success' => false, 'message' => $gsc_result->get_error_message() )
					: array( 'success' => true,  'message' => isset( $gsc_result['message'] ) ? (string) $gsc_result['message'] : __( 'Search Console connected.', 'seo-agent-ai' ) ),
				'analytics' => is_wp_error( $analytics_result )
					? array( 'success' => false, 'message' => $analytics_result->get_error_message() )
					: array( 'success' => true,  'message' => isset( $analytics_result['message'] ) ? (string) $analytics_result['message'] : __( 'Analytics connected.', 'seo-agent-ai' ) ),
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

		$code  = filter_input( INPUT_GET, 'code',  FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$state = filter_input( INPUT_GET, 'state', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$error = filter_input( INPUT_GET, 'error', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( ! $code && ! $error ) {
			return; // Normal connect-page load — nothing to handle.
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
}
