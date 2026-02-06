<?php
/**
 * Cleanup functions for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WSACSC_Cleanup
 */
class WSACSC_Cleanup {

    /**
     * Cache group for plugin cache operations
     */
    const CACHE_GROUP = 'wsacsc';

    /**
     * Track if hooks have been registered
     */
    private static $hooks_registered = false;

    /**
     * Temporarily increase PHP execution time and memory limits
     * 
     * @return array Original settings to restore later
     */
    private static function increase_php_limits(): array {
        $original_settings = array(
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit')
        );
        
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);
        
        return $original_settings;
    }

    /**
     * Restore original PHP execution time and memory limits
     * 
     * @param array $original_settings Original settings to restore
     */
    private static function restore_php_limits(array $original_settings): void {
        if (isset($original_settings['max_execution_time'])) {
            $max_exec_time = $original_settings['max_execution_time'];
            if ($max_exec_time === false || $max_exec_time === '') {
                @ini_set('max_execution_time', '30');
            } else {
                @ini_set('max_execution_time', (string) $max_exec_time);
                @set_time_limit((int) $max_exec_time);
            }
        }
        
        if (isset($original_settings['memory_limit'])) {
            $memory_limit = $original_settings['memory_limit'];
            if ($memory_limit === false || $memory_limit === '') {
                @ini_set('memory_limit', '128M');
            } else {
                @ini_set('memory_limit', $memory_limit);
            }
        }
    }

    /**
     * Initialize cleanup functionality
     */
    public static function init(): void {
        if (self::$hooks_registered) {
            return;
        }
        
        if (!has_action('wsacsc_cleanup_logs', array(__CLASS__, 'cleanup_logs'))) {
            add_action('wsacsc_cleanup_logs', array(__CLASS__, 'cleanup_logs'));
        }
        
        if (!has_action('wsacsc_cleanup_actions', array(__CLASS__, 'cleanup_actions'))) {
            add_action('wsacsc_cleanup_actions', array(__CLASS__, 'cleanup_actions'));
        }
        
        self::$hooks_registered = true;
    }

    /**
     * Optimize logs table
     *
     * @return bool
     */
    public static function optimize_logs_table(): bool {
        global $wpdb;
        
        $original_settings = self::increase_php_limits();
        
        try {
            $logs_table = $wpdb->prefix . 'actionscheduler_logs';
            
            $table_exists_query = $wpdb->prepare("SHOW TABLES LIKE %s", $logs_table);
            $table_exists = $wpdb->get_var($table_exists_query) === $logs_table;
            
            if (!$table_exists) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WS Action Scheduler Cleaner: Logs table does not exist for optimization.');
                }
                return false;
            }
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query("OPTIMIZE TABLE `{$logs_table}`");
            
            if ($result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WS Action Scheduler Cleaner: Failed to optimize logs table. Error: ' . $wpdb->last_error);
                }
                return false;
            }
            
            return true;
        } finally {
            self::restore_php_limits($original_settings);
        }
    }

    /**
     * Optimize actions table
     *
     * @return bool
     */
    public static function optimize_actions_table(): bool {
        global $wpdb;
        
        $original_settings = self::increase_php_limits();
        
        try {
            $actions_table = $wpdb->prefix . 'actionscheduler_actions';
            
            $table_exists_query = $wpdb->prepare("SHOW TABLES LIKE %s", $actions_table);
            $table_exists = $wpdb->get_var($table_exists_query) === $actions_table;
            
            if (!$table_exists) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WS Action Scheduler Cleaner: Actions table does not exist for optimization.');
                }
                return false;
            }
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query("OPTIMIZE TABLE `{$actions_table}`");
            
            if ($result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WS Action Scheduler Cleaner: Failed to optimize actions table. Error: ' . $wpdb->last_error);
                }
                return false;
            }
            
            return true;
        } finally {
            self::restore_php_limits($original_settings);
        }
    }

    /**
     * Batch delete until complete
     *
     * @param string $table Table name
     * @param string $where_clause WHERE clause
     * @param array $where_params WHERE parameters
     * @param int $batch_size Batch size
     * @param int $max_iterations Max iterations
     * @return int Total deleted
     */
    public static function batch_delete_until_complete($table, $where_clause, $where_params = array(), $batch_size = 1000, $max_iterations = 1000): int {
        global $wpdb;
        
        $total_deleted = 0;
        $iterations = 0;
        
        while ($iterations < $max_iterations) {
            $query = "DELETE FROM `{$table}` WHERE {$where_clause} LIMIT {$batch_size}";
            
            if (!empty($where_params)) {
                $query = $wpdb->prepare($query, $where_params);
            }
            
            $deleted = $wpdb->query($query);
            
            if ($deleted === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WS Action Scheduler Cleaner: Batch deletion failed. Error: ' . $wpdb->last_error);
                }
                break;
            }
            
            $total_deleted += (int) $deleted;
            
            if ($deleted === 0) {
                break;
            }
            
            $iterations++;
            
            usleep(10000);
        }
        
        return $total_deleted;
    }

    /**
     * Process a single batch of deletions for AJAX cleanup
     *
     * @param string $cleanup_id Unique cleanup identifier
     * @param string $table Table name
     * @param string $where_clause WHERE clause
     * @param array $where_params WHERE parameters
     * @param int $batch_size Batch size
     * @return array Status array with 'completed', 'total_deleted', 'in_progress' keys
     */
    public static function process_ajax_batch($cleanup_id, $table, $where_clause, $where_params = array(), $batch_size = 5000): array {
        global $wpdb;
        
        $original_settings = self::increase_php_limits();
        
        try {
            $transient_key = 'wsacsc_cleanup_' . $cleanup_id;
            $progress = get_transient($transient_key);
            
            if ($progress === false) {
                $progress = array(
                    'total_deleted' => 0,
                    'iterations' => 0,
                    'table' => $table,
                    'where_clause' => $where_clause,
                    'where_params' => $where_params,
                    'batch_size' => $batch_size,
                    'start_time' => time()
                );
            }
            
            $deleted_in_batch = 0;
            $iterations_in_batch = 0;
            $max_iterations_per_request = 100;
            
            while ($iterations_in_batch < $max_iterations_per_request) {
                $query = "DELETE FROM `{$table}` WHERE {$where_clause} LIMIT {$batch_size}";
                
                if (!empty($where_params)) {
                    $query = $wpdb->prepare($query, $where_params);
                }
                
                $deleted = $wpdb->query($query);
                
                if ($deleted === false) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('WS Action Scheduler Cleaner: AJAX batch deletion failed. Error: ' . $wpdb->last_error);
                    }
                    delete_transient($transient_key);
                    return array(
                        'completed' => true,
                        'total_deleted' => $progress['total_deleted'],
                        'in_progress' => false,
                        'error' => true
                    );
                }
                
                $deleted_count = (int) $deleted;
                $deleted_in_batch += $deleted_count;
                $progress['total_deleted'] += $deleted_count;
                $progress['iterations']++;
                
                if ($deleted_count === 0) {
                    delete_transient($transient_key);
                    wp_cache_delete('wsacsc_table_sizes', self::CACHE_GROUP);
                    return array(
                        'completed' => true,
                        'total_deleted' => $progress['total_deleted'],
                        'in_progress' => false
                    );
                }
                
                $iterations_in_batch++;
            }
            
            set_transient($transient_key, $progress, 300);
            
            return array(
                'completed' => false,
                'total_deleted' => $progress['total_deleted'],
                'in_progress' => true
            );
        } finally {
            self::restore_php_limits($original_settings);
        }
    }

    /**
     * Cleanup logs
     */
    public static function cleanup_logs(): void {
        global $wpdb;
        
        $original_settings = self::increase_php_limits();
        
        try {
            wp_cache_delete('wsacsc_logs_retention', 'options');
            $retention_days_option = get_option('wsacsc_logs_retention', '30');
            if ($retention_days_option === '' || !ctype_digit((string) $retention_days_option)) {
                return;
            }
            $retention_days = (int) $retention_days_option;
            $logs_table = $wpdb->prefix . 'actionscheduler_logs';
            
            if (!WSACSC_Database::check_tables_exist()) {
                return;
            }
            
            if ($retention_days === 0) {
                $where_clause = '1=1';
                $where_params = array();
            } else {
                $where_clause = '`log_date_gmt` < DATE_SUB(NOW(), INTERVAL %d DAY)';
                $where_params = array($retention_days);
            }
            
            $total_deleted = self::batch_delete_until_complete($logs_table, $where_clause, $where_params);
            
            if ($total_deleted > 0) {
                wp_cache_delete('wsacsc_table_sizes', self::CACHE_GROUP);
                self::optimize_logs_table();
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG && $total_deleted > 0) {
                error_log('WS Action Scheduler Cleaner: Cleaned up ' . $total_deleted . ' log entries.');
            }
        } finally {
            self::restore_php_limits($original_settings);
        }
    }

    /**
     * Cleanup actions
     */
    public static function cleanup_actions(): void {
        global $wpdb;
        
        $original_settings = self::increase_php_limits();
        
        try {
            wp_cache_delete('wsacsc_actions_retention', 'options');
            $retention_days_option = get_option('wsacsc_actions_retention', '30');
            if ($retention_days_option === '' || !ctype_digit((string) $retention_days_option)) {
                return;
            }
            $retention_days = absint($retention_days_option);

            wp_cache_delete('wsacsc_selected_statuses', 'options');
            $selected_statuses_option = get_option('wsacsc_selected_statuses', ['complete', 'failed', 'canceled']);
            if (!is_array($selected_statuses_option)) {
                 $selected_statuses_option = ['complete', 'failed', 'canceled'];
            }
            $selected_statuses = array_map('sanitize_text_field', $selected_statuses_option);

            $allowed_statuses = ['complete', 'failed', 'canceled'];
            $selected_statuses = array_intersect($selected_statuses, $allowed_statuses);

            if (!empty($selected_statuses)) {
                if (!WSACSC_Database::check_tables_exist()) {
                    return;
                }
                
                $actions_table = $wpdb->prefix . 'actionscheduler_actions';
                $placeholders = implode(',', array_fill(0, count($selected_statuses), '%s'));
                
                if ($retention_days === 0) {
                    $where_clause = "status IN (" . $placeholders . ")";
                    $where_params = $selected_statuses;
                } else {
                    $where_clause = "status IN (" . $placeholders . ") AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL %d DAY)";
                    $where_params = array_merge($selected_statuses, array($retention_days));
                }
                
                $total_deleted = self::batch_delete_until_complete($actions_table, $where_clause, $where_params);

                if ($total_deleted > 0) {
                    wp_cache_delete('wsacsc_table_sizes', self::CACHE_GROUP);
                    self::optimize_actions_table();
                }
                
                if (defined('WP_DEBUG') && WP_DEBUG && $total_deleted > 0) {
                    error_log('WS Action Scheduler Cleaner: Cleaned up ' . $total_deleted . ' action entries.');
                }
            }
        } finally {
            self::restore_php_limits($original_settings);
        }
    }
}
