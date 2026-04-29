<?php
/**
 * Plugin Name:       SEO Agent AI
 * Plugin URI:        https://adityashah.blog/seo-agent-ai/
 * Description:       Autonomous SEO agent — continuously analyzes Search Console and GA4 signals, then proposes prioritized SEO recommendations with full audit trail and optional autopilot.
 * Version:           2.1.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            SEO Agent AI
 * Author URI:        https://adityashah.blog/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-agent-ai
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEO_AGENT_AI_VERSION',     '2.1.1' );
define( 'SEO_AGENT_AI_PLUGIN_FILE', __FILE__ );
define( 'SEO_AGENT_AI_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SEO_AGENT_AI_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Shared helpers.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-crypto.php';

// Core data layer.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-data-store.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-activity-log.php';

// Google API clients.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/clients/class-google-oauth.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/clients/class-gsc-client.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/clients/class-ga4-client.php';

// SEO plugin integration bridge.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-seo-plugin-bridge.php';

// SEO intelligence.
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/class-seo-analyzer.php';
require_once SEO_AGENT_AI_PLUGIN_DIR . 'includes/clients/class-gemini-client.php';
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

add_action( 'plugins_loaded', array( 'SEO_Agent_AI_Plugin', 'maybe_upgrade' ) );

SEO_Agent_AI_Plugin::instance();
