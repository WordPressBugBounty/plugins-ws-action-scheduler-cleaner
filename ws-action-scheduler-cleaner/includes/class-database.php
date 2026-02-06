<?php
/**
 * Database utilities for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WSACSC_Database
 */
class WSACSC_Database {

    /**
     * Cache group for plugin cache operations
     */
    const CACHE_GROUP = 'wsacsc';

    /**
     * Check whether the Action Scheduler tables actually exist
     *
     * @return bool
     */
    public static function check_tables_exist(): bool {
        global $wpdb;
        $cache_key = 'wsacsc_tables_exist';
        $cached_result = wp_cache_get($cache_key, self::CACHE_GROUP);
        
        if (false !== $cached_result) {
            return (bool) $cached_result;
        }
        
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';
        
        $actions_exist_query = $wpdb->prepare("SHOW TABLES LIKE %s", $actions_table);
        $actions_exist = $wpdb->get_var($actions_exist_query) === $actions_table;
        
        $logs_exist_query = $wpdb->prepare("SHOW TABLES LIKE %s", $logs_table);
        $logs_exist = $wpdb->get_var($logs_exist_query) === $logs_table;
        
        $result = $actions_exist && $logs_exist;
        wp_cache_set($cache_key, (int) $result, self::CACHE_GROUP, 3600);
        
        return $result;
    }

    /**
     * Get table size data
     *
     * @return array
     */
    public static function get_table_size_data(): array {
        global $wpdb;
        $cache_key = 'wsacsc_table_sizes';
        $cached_data = wp_cache_get($cache_key, self::CACHE_GROUP);
        
        if (false !== $cached_data && is_array($cached_data)) {
            return $cached_data;
        }
        
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';
        
        $actions_count_query = $wpdb->prepare("SELECT COUNT(*) FROM %i", $actions_table);
        $actions_count = $wpdb->get_var($actions_count_query);

        $logs_count_query = $wpdb->prepare("SELECT COUNT(*) FROM %i", $logs_table);
        $logs_count = $wpdb->get_var($logs_count_query);
        
        $table_sizes_query = $wpdb->prepare(
            "SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
            FROM information_schema.TABLES 
            WHERE table_schema = %s AND table_name IN (%s, %s)",
            DB_NAME, $actions_table, $logs_table
        );
        $table_sizes_result = $wpdb->get_results($table_sizes_query, ARRAY_A);
        
        $sizes = [];
        if (is_array($table_sizes_result)) {
            $sizes = array_column($table_sizes_result, 'size_mb', 'table_name');
        }
        
        $data = [
            'actions_count' => number_format((int)($actions_count ?? 0)),
            'logs_count' => number_format((int)($logs_count ?? 0)),
            'actions_mb' => number_format((float)($sizes[$actions_table] ?? 0), 2),
            'logs_mb' => number_format((float)($sizes[$logs_table] ?? 0), 2)
        ];
        
        wp_cache_set($cache_key, $data, self::CACHE_GROUP, 300);
        
        return $data;
    }
}
