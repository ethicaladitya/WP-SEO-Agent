<?php
/**
 * Plugin Name: SEO Agent AI
 * Description: Autonomous SEO agent — continuously analyzes Search Console and GA4 signals, then applies smart metadata optimizations with full audit trail and autopilot mode.
 * Version: 2.0.0
 * Author: SEO Agent AI
 * License: GPL-2.0-or-later
 * Text Domain: seo-agent-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEO_AGENT_AI_VERSION',    '2.0.0' );
define( 'SEO_AGENT_AI_PLUGIN_FILE', __FILE__ );
define( 'SEO_AGENT_AI_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SEO_AGENT_AI_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Core data layer.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-data-store.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-activity-log.php';

// Google API clients.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/clients/class-google-oauth.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/clients/class-gsc-client.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/clients/class-ga4-client.php';

// SEO intelligence.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-seo-analyzer.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-recommendation-engine.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-fix-executor.php';

// Admin pages.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/admin/class-connect-page.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/admin/class-report-page.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/admin/class-admin-page.php';

// Plugin orchestrator.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'SEO_Agent_AI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SEO_Agent_AI_Plugin', 'deactivate' ) );

SEO_Agent_AI_Plugin::instance();
