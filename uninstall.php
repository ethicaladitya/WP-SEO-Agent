<?php
/**
 * SEO Agent AI — uninstall handler.
 *
 * Triggered by WordPress when the user deletes the plugin from the admin UI.
 * Removes every artifact this plugin ever wrote: custom DB tables, options,
 * transients, post meta keys, scheduled cron events.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Wrap the uninstall logic in an anonymous function so file-scope variables
 * stay out of the global namespace (Plugin Check flags them otherwise).
 */
( function () {
	global $wpdb;

	// 1. Drop all custom tables (original + 5 new v3.0 tables).
	$seo_agent_ai_tables = array(
		$wpdb->prefix . 'seo_agent_ai_activity',
		$wpdb->prefix . 'seo_agent_keyword_history',
		$wpdb->prefix . 'seo_agent_page_insights',
		$wpdb->prefix . 'seo_agent_ai_decisions',
		$wpdb->prefix . 'seo_agent_daily_reports',
		$wpdb->prefix . 'seo_agent_internal_links',
	);
	foreach ( $seo_agent_ai_tables as $seo_agent_ai_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$seo_agent_ai_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	// 2. Delete every option this plugin ever wrote.
	$seo_agent_ai_options = array(
		// Google OAuth.
		'seo_agent_ai_google_client_id',
		'seo_agent_ai_google_client_secret',
		'seo_agent_ai_google_refresh_token',
		'seo_agent_ai_google_access_token',
		'seo_agent_ai_google_access_token_expires_at',
		'seo_agent_ai_google_connected_email',
		'seo_agent_ai_gsc_site_url',
		'seo_agent_ai_ga4_property_id',
		// AI providers.
		'seo_agent_ai_gemini_api_key',
		'seo_agent_ai_openai_api_key',
		'seo_agent_ai_openai_base_url',
		'seo_agent_ai_openai_model',
		'seo_agent_ai_ai_provider',
		// Autopilot / settings.
		'seo_agent_ai_autopilot_enabled',
		'seo_agent_ai_autopilot_max_daily',
		'seo_agent_ai_autopilot_min_confidence',
		'seo_agent_ai_log_retention_days',
		'seo_agent_ai_email_reports',
		// Debug / mode.
		'seo_agent_ai_debug_mode',
		'seo_agent_ai_verbose_mode',
		// Runtime state.
		'seo_agent_ai_last_run',
		'seo_agent_ai_activity_db_v',
		'seo_agent_ai_db_version',
		'seo_agent_ai_consecutive_api_failures',
		'seo_agent_ai_last_api_error',
		'seo_agent_ai_auth_health',
		'seo_agent_ai_queue',
	);

	foreach ( $seo_agent_ai_options as $seo_agent_ai_option ) {
		delete_option( $seo_agent_ai_option );
		delete_site_option( $seo_agent_ai_option );
	}

	// 3. Delete transients (well-known keys + prefix-keyed daily counters).
	$seo_agent_ai_transients = array(
		'seo_agent_ai_oauth_state',
		'seo_agent_ai_analysis_lock',
		'seo_agent_ai_connection_test_result',
		'seo_agent_ai_batch_state',
	);
	foreach ( $seo_agent_ai_transients as $seo_agent_ai_transient ) {
		delete_transient( $seo_agent_ai_transient );
		delete_site_transient( $seo_agent_ai_transient );
	}

	// 3b. Daily autopilot counters + cron last-run options use date suffixes; sweep them.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_seo_agent_ai_ap_count_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_seo_agent_ai_ap_count_' ) . '%',
			$wpdb->esc_like( 'seo_agent_ai_last_run_' ) . '%',
			$wpdb->esc_like( '_transient_seo_agent_schema_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// 4. Delete every post-meta key this plugin ever wrote.
	$seo_agent_ai_meta_keys = array(
		'_seo_agent_ai_metrics',
		'_seo_agent_ai_recommendations',
		'_seo_agent_ai_backups',
		'_seo_agent_ai_last_applied_at',
		'_seo_agent_ai_meta_title',
		'_seo_agent_ai_meta_description',
		'_seo_agent_ai_last_analyzed',
		'_seo_agent_ai_score',
	);

	foreach ( $seo_agent_ai_meta_keys as $seo_agent_ai_meta_key ) {
		delete_post_meta_by_key( $seo_agent_ai_meta_key );
	}

	// 5. Unschedule all cron events.
	$seo_agent_ai_cron_hooks = array(
		'seo_agent_ai_daily_analysis',
		'seo_agent_ai_run_manual_analysis',
		'seo_agent_fetch_gsc_data',
		'seo_agent_fetch_ga4_data',
		'seo_agent_generate_report',
		'seo_agent_score_pages',
		'seo_agent_detect_decay',
		'seo_agent_run_internal_links',
		'seo_agent_purge_old_data',
	);
	foreach ( $seo_agent_ai_cron_hooks as $seo_agent_ai_hook ) {
		wp_clear_scheduled_hook( $seo_agent_ai_hook );
	}
} )();
