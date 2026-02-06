<?php
/**
 * Uninstall script for WS Action Scheduler Cleaner
 *
 * This file is called by WordPress when the plugin is uninstalled.
 * It performs comprehensive cleanup including cron hook deletion.
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$plugin_file = __FILE__;
$plugin_dir = plugin_dir_path($plugin_file);

if (file_exists($plugin_dir . 'includes/class-database.php')) {
    require_once $plugin_dir . 'includes/class-database.php';
}
if (file_exists($plugin_dir . 'includes/class-scheduler.php')) {
    require_once $plugin_dir . 'includes/class-scheduler.php';
}

global $wpdb;

$actions_table = $wpdb->prefix . 'actionscheduler_actions';
if (class_exists('WSACSC_Database') && WSACSC_Database::check_tables_exist()) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query("DROP INDEX IF EXISTS as_status_scheduled ON `{$actions_table}`");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query("DROP INDEX IF EXISTS as_status_completed ON `{$actions_table}`");
}

$hook_names = array('wsacsc_cleanup_logs', 'wsacsc_cleanup_actions');

if (class_exists('WSACSC_Scheduler')) {
    WSACSC_Scheduler::winningsolutions_force_clear_cron_hooks($hook_names);
} else {
    wp_clear_scheduled_hook('wsacsc_cleanup_logs');
    wp_clear_scheduled_hook('wsacsc_cleanup_actions');
    
    wp_cache_delete('cron', 'options');
    wp_cache_delete('alloptions', 'options');
    
    $cron_option = get_option('cron');
    if (is_array($cron_option)) {
        foreach ($cron_option as $ts => $events) {
            if (isset($events['wsacsc_cleanup_logs'])) {
                unset($cron_option[$ts]['wsacsc_cleanup_logs']);
            }
            if (isset($events['wsacsc_cleanup_actions'])) {
                unset($cron_option[$ts]['wsacsc_cleanup_actions']);
            }
            if (empty($cron_option[$ts])) {
                unset($cron_option[$ts]);
            }
        }
        update_option('cron', $cron_option);
    }
}

wp_cache_delete('cron', 'options');
wp_cache_delete('alloptions', 'options');

foreach ($hook_names as $hook_name) {
    $cron_option = get_option('cron');
    if (is_array($cron_option)) {
        $modified = false;
        foreach ($cron_option as $ts => $events) {
            if (isset($events[$hook_name])) {
                unset($cron_option[$ts][$hook_name]);
                $modified = true;
                if (empty($cron_option[$ts])) {
                    unset($cron_option[$ts]);
                }
            }
        }
        if ($modified) {
            update_option('cron', $cron_option);
        }
    }
    
    $still_exists = wp_next_scheduled($hook_name);
    if (false !== $still_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('WS Action Scheduler Cleaner: Warning - Hook %s still exists after cleanup attempt', $hook_name));
        }
    }
}

$plugin_options = [
    'wsacsc_logs_retention',
    'wsacsc_actions_retention',
    'wsacsc_selected_statuses',
    'wsacsc_actions_schedule_interval',
    'wsacsc_logs_schedule_interval',
    'wsacsc_actions_schedule_time',
    'wsacsc_logs_schedule_time',
    'wsacsc_migration_v2_done',
];

foreach ($plugin_options as $option) {
    delete_option($option);
    wp_cache_delete($option, 'options');
}

wp_cache_delete('alloptions', 'options');
wp_cache_delete('cron', 'options');
wp_cache_delete('wsacsc_tables_exist', 'wsacsc');
wp_cache_delete('wsacsc_table_sizes', 'wsacsc');

if (function_exists('wp_cache_flush_group')) {
    wp_cache_flush_group('options');
}

wp_cache_flush();
