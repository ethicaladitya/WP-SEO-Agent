<?php
// Simple check via web request - needs to be in wp-content
define('SHORTINIT', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

$v = get_option('seo_agent_ai_db_manager_v', 'NOT_SET');
$act = get_option('seo_agent_ai_activity_log_v', 'NOT_SET');
$tables = $GLOBALS['wpdb']->get_col("SHOW TABLES LIKE '{$GLOBALS['wpdb']->prefix}seo_agent%'");

header('Content-Type: text/plain');
echo "DB Manager version: $v\n";
echo "Activity Log version: $act\n";  
echo "SEO tables: " . (empty($tables) ? 'NONE' : implode(', ', $tables)) . "\n";
echo "WP Prefix: " . $GLOBALS['wpdb']->prefix . "\n";
