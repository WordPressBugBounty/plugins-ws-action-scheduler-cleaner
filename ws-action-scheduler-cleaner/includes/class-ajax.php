<?php
/**
 * AJAX handlers for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WSACSC_Ajax
 */
class WSACSC_Ajax {

    /**
     * Initialize AJAX handlers
     */
    public static function init(): void {
        add_action('wp_ajax_wsacsc_get_table_sizes', array(__CLASS__, 'get_table_sizes'));
        add_action('wp_ajax_wsacsc_clear_actions', array(__CLASS__, 'clear_actions'));
        add_action('wp_ajax_wsacsc_clear_logs', array(__CLASS__, 'clear_logs'));
        add_action('wp_ajax_wsacsc_save_schedule', array(__CLASS__, 'save_schedule'));
        add_action('wp_ajax_wsacsc_save_selected_statuses', array(__CLASS__, 'save_selected_statuses'));
        add_action('wp_ajax_wsacsc_get_single_table_size', array(__CLASS__, 'get_single_table_size'));
        add_action('wp_ajax_wsacsc_optimize_table', array(__CLASS__, 'optimize_table'));
        add_action('wp_ajax_wsacsc_check_cleanup_progress', array(__CLASS__, 'check_cleanup_progress'));
    }

    /**
     * Get table sizes
     */
    public static function get_table_sizes(): void {
        nocache_headers();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        check_ajax_referer('wsacsc_nonce', 'nonce');
        
        wp_send_json_success(WSACSC_Database::get_table_size_data());
    }

    /**
     * Clear actions
     */
    public static function clear_actions(): void {
        nocache_headers();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        check_ajax_referer('wsacsc_nonce', 'nonce');
        
        global $wpdb;
        $statuses_input = isset($_POST['statuses']) && is_array($_POST['statuses']) ? $_POST['statuses'] : array();
        $statuses = array_map('sanitize_text_field', wp_unslash($statuses_input));
        
        $allowed_statuses = ['complete', 'failed', 'canceled'];
        $statuses = array_intersect($statuses, $allowed_statuses);
        
        if (empty($statuses)) {
            wp_send_json_error(['message' => __('Please select at least one status to clear.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        if (!WSACSC_Database::check_tables_exist()) {
            wp_send_json_error(['message' => __('Action Scheduler tables do not exist.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $where_clause = "status IN (" . $placeholders . ")";
        $where_params = $statuses;
        
        $cleanup_id = 'actions_' . wp_generate_password(12, false);
        $batch_size = 50000;
        
        $result = WSACSC_Cleanup::process_ajax_batch($cleanup_id, $actions_table, $where_clause, $where_params, $batch_size);
        
        if ($result['completed']) {
            wp_cache_delete('wsacsc_table_sizes', WSACSC_Database::CACHE_GROUP);
            wp_send_json_success(array_merge(
                WSACSC_Database::get_table_size_data(),
                array(
                    'message' => __('Selected actions cleared successfully!', 'ws-action-scheduler-cleaner'),
                    'cleanup_id' => $cleanup_id,
                    'completed' => true
                )
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('Clearing in progress...', 'ws-action-scheduler-cleaner'),
                'cleanup_id' => $cleanup_id,
                'completed' => false
            ));
        }
    }

    /**
     * Clear logs
     */
    public static function clear_logs(): void {
        nocache_headers();
        
        check_ajax_referer('wsacsc_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        if (!WSACSC_Database::check_tables_exist()) {
            wp_send_json_error(['message' => __('Action Scheduler tables do not exist.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        global $wpdb;
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';
        $where_clause = '1=1';
        $where_params = array();
        
        $cleanup_id = 'logs_' . wp_generate_password(12, false);
        $batch_size = 50000;
        
        $result = WSACSC_Cleanup::process_ajax_batch($cleanup_id, $logs_table, $where_clause, $where_params, $batch_size);
        
        if ($result['completed']) {
            wp_cache_delete('wsacsc_table_sizes', WSACSC_Database::CACHE_GROUP);
            wp_send_json_success(array_merge(
                WSACSC_Database::get_table_size_data(),
                array(
                    'message' => __('Logs cleared successfully!', 'ws-action-scheduler-cleaner'),
                    'cleanup_id' => $cleanup_id,
                    'completed' => true
                )
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('Clearing in progress...', 'ws-action-scheduler-cleaner'),
                'cleanup_id' => $cleanup_id,
                'completed' => false
            ));
        }
    }

    /**
     * Save schedule
     */
    public static function save_schedule(): void {
        nocache_headers();
        
        check_ajax_referer('wsacsc_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        $actions_schedule_interval = isset($_POST['actions_schedule_interval']) ? sanitize_text_field(wp_unslash($_POST['actions_schedule_interval'])) : '';
        $actions_schedule_time = isset($_POST['actions_schedule_time']) ? sanitize_text_field(wp_unslash($_POST['actions_schedule_time'])) : '';
        $actions_retention = isset($_POST['actions_retention']) ? sanitize_text_field(wp_unslash($_POST['actions_retention'])) : '';
        
        $logs_schedule_interval = isset($_POST['logs_schedule_interval']) ? sanitize_text_field(wp_unslash($_POST['logs_schedule_interval'])) : '';
        $logs_schedule_time = isset($_POST['logs_schedule_time']) ? sanitize_text_field(wp_unslash($_POST['logs_schedule_time'])) : '';
        $logs_retention = isset($_POST['logs_retention']) ? sanitize_text_field(wp_unslash($_POST['logs_retention'])) : '';
        
        if ($actions_schedule_interval === '0') {
            $actions_schedule_interval = '';
        }
        if ($logs_schedule_interval === '0') {
            $logs_schedule_interval = '';
        }
        
        if ($actions_schedule_interval !== '' && (!ctype_digit((string) $actions_schedule_interval) || (int) $actions_schedule_interval < 1 || (int) $actions_schedule_interval > 365)) {
            wp_send_json_error(['message' => __('Actions schedule interval must be empty or between 1 and 365 days.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        if ($logs_schedule_interval !== '' && (!ctype_digit((string) $logs_schedule_interval) || (int) $logs_schedule_interval < 1 || (int) $logs_schedule_interval > 365)) {
            wp_send_json_error(['message' => __('Logs schedule interval must be empty or between 1 and 365 days.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        if ($actions_schedule_time !== '' && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $actions_schedule_time)) {
            wp_send_json_error(['message' => __('Actions schedule time must be in HH:MM format.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        if ($logs_schedule_time !== '' && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $logs_schedule_time)) {
            wp_send_json_error(['message' => __('Logs schedule time must be in HH:MM format.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        if ($actions_retention === '' || !ctype_digit((string) $actions_retention) || (int) $actions_retention < 0 || (int) $actions_retention > 365) {
            wp_send_json_error(['message' => __('Actions retention period is required and must be between 0 and 365 days.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        if ($logs_retention === '' || !ctype_digit((string) $logs_retention) || (int) $logs_retention < 0 || (int) $logs_retention > 365) {
            wp_send_json_error(['message' => __('Logs retention period is required and must be between 0 and 365 days.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        $options_to_save = array(
            'wsacsc_actions_schedule_interval' => $actions_schedule_interval,
            'wsacsc_actions_schedule_time' => $actions_schedule_time,
            'wsacsc_actions_retention' => $actions_retention,
            'wsacsc_logs_schedule_interval' => $logs_schedule_interval,
            'wsacsc_logs_schedule_time' => $logs_schedule_time,
            'wsacsc_logs_retention' => $logs_retention,
        );

        $backup = array();
        foreach ($options_to_save as $option_name => $value) {
            $backup[$option_name] = get_option($option_name, '');
        }
        set_transient('wsacsc_save_backup', $backup, HOUR_IN_SECONDS);

        $save_success = false;
        $max_attempts = 2;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            update_option('wsacsc_actions_schedule_interval', $actions_schedule_interval, 'no');
            update_option('wsacsc_actions_schedule_time', $actions_schedule_time, 'no');
            update_option('wsacsc_actions_retention', $actions_retention);
            update_option('wsacsc_logs_schedule_interval', $logs_schedule_interval, 'no');
            update_option('wsacsc_logs_schedule_time', $logs_schedule_time, 'no');
            update_option('wsacsc_logs_retention', $logs_retention);
            
            wp_cache_delete('wsacsc_actions_schedule_interval', 'options');
            wp_cache_delete('wsacsc_actions_schedule_time', 'options');
            wp_cache_delete('wsacsc_actions_retention', 'options');
            wp_cache_delete('wsacsc_logs_schedule_interval', 'options');
            wp_cache_delete('wsacsc_logs_schedule_time', 'options');
            wp_cache_delete('wsacsc_logs_retention', 'options');
            wp_cache_delete('alloptions', 'options');

            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group('options');
            }

            usleep(50000);

            $validation_passed = true;
            $actions_interval_check = get_option('wsacsc_actions_schedule_interval', '');
            $actions_time_check = get_option('wsacsc_actions_schedule_time', '');
            $logs_interval_check = get_option('wsacsc_logs_schedule_interval', '');
            $logs_time_check = get_option('wsacsc_logs_schedule_time', '');

            if ($actions_interval_check !== $actions_schedule_interval) {
                $validation_passed = false;
            }
            if ($actions_time_check !== $actions_schedule_time) {
                $validation_passed = false;
            }
            if ($logs_interval_check !== $logs_schedule_interval) {
                $validation_passed = false;
            }
            if ($logs_time_check !== $logs_schedule_time) {
                $validation_passed = false;
            }

            if ($validation_passed) {
                $save_success = true;
                break;
            }

            if ($attempt < $max_attempts) {
                wp_cache_delete('wsacsc_actions_schedule_interval', 'options');
                wp_cache_delete('wsacsc_actions_schedule_time', 'options');
                wp_cache_delete('wsacsc_logs_schedule_interval', 'options');
                wp_cache_delete('wsacsc_logs_schedule_time', 'options');
                wp_cache_delete('alloptions', 'options');
            }
        }

        if (!$save_success) {
            $backup_restore = get_transient('wsacsc_save_backup');
            if (false !== $backup_restore && is_array($backup_restore)) {
                foreach ($backup_restore as $option_name => $value) {
                    update_option($option_name, $value, 'no');
                }
                wp_cache_delete('alloptions', 'options');
            }
            delete_transient('wsacsc_save_backup');
            wp_send_json_error(array('message' => __('Failed to save schedule. Please try again.', 'ws-action-scheduler-cleaner')));
            return;
        }

        delete_transient('wsacsc_save_backup');
        
        WSACSC_Scheduler::winningsolutions_cleanup_broken_hooks(array('wsacsc_cleanup_logs', 'wsacsc_cleanup_actions'));
        
        WSACSC_Scheduler::maybe_schedule_cleanup();
        wp_send_json_success(array('message' => __('Schedule saved successfully!', 'ws-action-scheduler-cleaner')));
    }

    /**
     * Save selected statuses
     */
    public static function save_selected_statuses(): void {
        nocache_headers();
        
        check_ajax_referer('wsacsc_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        $statuses_input = isset($_POST['statuses']) && is_array($_POST['statuses']) ? $_POST['statuses'] : array();
        $statuses = array_map('sanitize_text_field', wp_unslash($statuses_input));
        update_option('wsacsc_selected_statuses', $statuses);
        
        wp_cache_delete('wsacsc_selected_statuses', 'options');
        wp_cache_delete('alloptions', 'options');
        
        wp_send_json_success(array('message' => __('Statuses saved successfully!', 'ws-action-scheduler-cleaner')));
    }

    /**
     * Get single table size
     */
    public static function get_single_table_size(): void {
        nocache_headers();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        check_ajax_referer('wsacsc_nonce', 'nonce');
        
        $table_type = isset($_POST['table_type']) ? sanitize_text_field(wp_unslash($_POST['table_type'])) : '';
        if (!in_array($table_type, ['actions', 'logs'])) {
            wp_send_json_error(['message' => __('Invalid table type.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'actionscheduler_' . $table_type;
        
        $count_query = $wpdb->prepare("SELECT COUNT(*) FROM %i", $table_name);
        $count = $wpdb->get_var($count_query);

        $size_query = $wpdb->prepare(
            "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
            FROM information_schema.TABLES 
            WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table_name
        );
        $size = $wpdb->get_var($size_query);
        
        wp_send_json_success([
            'count' => number_format((int)($count ?? 0)),
            'size' => number_format((float)($size ?? 0), 2) . ' MB'
        ]);
    }

    /**
     * Optimize table
     */
    public static function optimize_table(): void {
        nocache_headers();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        check_ajax_referer('wsacsc_nonce', 'nonce');
        
        $table_type = isset($_POST['table_type']) ? sanitize_text_field(wp_unslash($_POST['table_type'])) : '';
        if (!in_array($table_type, ['actions', 'logs'])) {
            wp_send_json_error(['message' => __('Invalid table type.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        $result = false;
        if ($table_type === 'logs') {
            $result = WSACSC_Cleanup::optimize_logs_table();
        } elseif ($table_type === 'actions') {
            $result = WSACSC_Cleanup::optimize_actions_table();
        }
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Table optimization failed.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        wp_cache_delete('wsacsc_table_sizes', WSACSC_Database::CACHE_GROUP);
        
        wp_send_json_success(array_merge(
            WSACSC_Database::get_table_size_data(),
            array(
                'message' => sprintf(__('%s table optimized successfully!', 'ws-action-scheduler-cleaner'), ucfirst($table_type)),
                'table_type' => $table_type
            )
        ));
    }

    /**
     * Check cleanup progress
     */
    public static function check_cleanup_progress(): void {
        nocache_headers();
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        check_ajax_referer('wsacsc_nonce', 'nonce');
        
        $cleanup_id = isset($_POST['cleanup_id']) ? sanitize_text_field(wp_unslash($_POST['cleanup_id'])) : '';
        if (empty($cleanup_id)) {
            wp_send_json_error(['message' => __('Invalid cleanup ID.', 'ws-action-scheduler-cleaner')]);
            return;
        }
        
        $transient_key = 'wsacsc_cleanup_' . $cleanup_id;
        $progress = get_transient($transient_key);
        
        if ($progress === false) {
            wp_send_json_success(array(
                'completed' => true,
                'message' => __('Cleanup completed.', 'ws-action-scheduler-cleaner')
            ));
            return;
        }
        
        $result = WSACSC_Cleanup::process_ajax_batch(
            $cleanup_id,
            $progress['table'],
            $progress['where_clause'],
            $progress['where_params'],
            $progress['batch_size']
        );
        
        if ($result['completed']) {
            wp_cache_delete('wsacsc_table_sizes', WSACSC_Database::CACHE_GROUP);
            wp_send_json_success(array_merge(
                WSACSC_Database::get_table_size_data(),
                array(
                    'completed' => true,
                    'message' => __('Cleanup completed successfully!', 'ws-action-scheduler-cleaner')
                )
            ));
        } else {
            wp_send_json_success(array(
                'completed' => false,
                'message' => __('Clearing in progress...', 'ws-action-scheduler-cleaner')
            ));
        }
    }
}
