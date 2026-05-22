<?php
/**
 * Uninstall script for WS Action Scheduler Cleaner
 *
 * This file is called by WordPress when the plugin is uninstalled.
 * It performs comprehensive cleanup including cron hook deletion.
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$plugin_file = __FILE__;
$plugin_dir  = plugin_dir_path( $plugin_file );

if ( file_exists( $plugin_dir . 'includes/helpers.php' ) ) {
	require_once $plugin_dir . 'includes/helpers.php';
}
if ( file_exists( $plugin_dir . 'includes/class-database.php' ) ) {
	require_once $plugin_dir . 'includes/class-database.php';
}
if ( file_exists( $plugin_dir . 'includes/class-scheduler.php' ) ) {
	require_once $plugin_dir . 'includes/class-scheduler.php';
}

global $wpdb;

$actions_table = $wpdb->prefix . 'actionscheduler_actions';
if ( class_exists( 'WSACSC_Database' ) && WSACSC_Database::check_tables_exist() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP INDEX IF EXISTS as_status_scheduled ON `{$actions_table}`" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP INDEX IF EXISTS as_status_completed ON `{$actions_table}`" );
}

$hook_names = array( 'wsacsc_cleanup_logs', 'wsacsc_cleanup_actions' );

if ( class_exists( 'WSACSC_Scheduler' ) ) {
	WSACSC_Scheduler::winningsolutions_force_clear_cron_hooks( $hook_names );
} else {
	foreach ( $hook_names as $hook_name ) {
		wp_clear_scheduled_hook( $hook_name );
	}
}

foreach ( $hook_names as $hook_name ) {
	$still_exists = wp_next_scheduled( $hook_name );
	if ( false !== $still_exists && function_exists( 'wsacsc_should_log' ) && wsacsc_should_log() ) {
		error_log( sprintf( 'WS Action Scheduler Cleaner: Warning - Hook %s still exists after cleanup attempt', $hook_name ) );
	}
}

$plugin_options = array(
	'wsacsc_logs_retention',
	'wsacsc_actions_retention',
	'wsacsc_selected_statuses',
	'wsacsc_actions_schedule_interval',
	'wsacsc_logs_schedule_interval',
	'wsacsc_actions_schedule_time',
	'wsacsc_logs_schedule_time',
	'wsacsc_migration_v2_done',
);

foreach ( $plugin_options as $option ) {
	delete_option( $option );
	wp_cache_delete( $option, 'options' );
}

wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'cron', 'options' );
wp_cache_delete( 'wsacsc_tables_exist', 'wsacsc' );
wp_cache_delete( 'wsacsc_table_sizes', 'wsacsc' );

wp_cache_flush_group( 'options' );

wp_cache_flush();
