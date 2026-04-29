<?php
/**
 * SEO Agent AI — uninstall handler.
 *
 * Triggered by WordPress when the user deletes the plugin from the admin UI.
 * Removes every artifact this plugin ever wrote: custom DB table, options,
 * transients, post meta keys, scheduled cron events.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Drop the custom activity-log table.
$table = $wpdb->prefix . 'seo_agent_ai_activity';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// 2. Delete every option this plugin ever wrote.
$options = array(
	'seo_agent_ai_google_client_id',
	'seo_agent_ai_google_client_secret',
	'seo_agent_ai_google_refresh_token',
	'seo_agent_ai_google_access_token',
	'seo_agent_ai_google_access_token_expires_at',
	'seo_agent_ai_google_connected_email',
	'seo_agent_ai_gsc_site_url',
	'seo_agent_ai_ga4_property_id',
	'seo_agent_ai_gemini_api_key',
	'seo_agent_ai_autopilot_enabled',
	'seo_agent_ai_autopilot_max_daily',
	'seo_agent_ai_autopilot_min_confidence',
	'seo_agent_ai_log_retention_days',
	'seo_agent_ai_last_run',
	'seo_agent_ai_activity_db_v',
	'seo_agent_ai_consecutive_api_failures',
	'seo_agent_ai_last_api_error',
);

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

// 3. Delete transients (both the well-known keys and any prefix-keyed daily counters).
$transients = array(
	'seo_agent_ai_oauth_state',
	'seo_agent_ai_analysis_lock',
	'seo_agent_ai_connection_test_result',
	'seo_agent_ai_batch_state',
);
foreach ( $transients as $t ) {
	delete_transient( $t );
	delete_site_transient( $t );
}

// 3b. Daily autopilot counters use a prefix and a date suffix; sweep them.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_seo_agent_ai_ap_count_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_seo_agent_ai_ap_count_' ) . '%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 4. Delete every post-meta key this plugin ever wrote.
//    (We deliberately do NOT touch Yoast / RankMath / SmartCrawl / SEO Framework
//     keys — those are owned by other plugins and the user may keep them.)
$meta_keys = array(
	'_seo_agent_ai_metrics',
	'_seo_agent_ai_recommendations',
	'_seo_agent_ai_backups',
	'_seo_agent_ai_last_applied_at',
	'_seo_agent_ai_meta_title',
	'_seo_agent_ai_meta_description',
);

foreach ( $meta_keys as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

// 5. Unschedule cron events.
$timestamp = wp_next_scheduled( 'seo_agent_ai_daily_analysis' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'seo_agent_ai_daily_analysis' );
}
wp_clear_scheduled_hook( 'seo_agent_ai_daily_analysis' );
wp_clear_scheduled_hook( 'seo_agent_ai_run_manual_analysis' );
