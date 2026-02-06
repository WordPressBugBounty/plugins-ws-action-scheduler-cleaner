<?php
/**
 * Activation and deactivation hooks for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WSACSC_Activation
 */
class WSACSC_Activation {

    /**
     * Handle plugin activation
     */
    public static function activate(): void {
        global $wpdb;
        
        if (!WSACSC_Database::check_tables_exist()) {
            return;
        }
        
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        
        $columns_query = $wpdb->prepare('DESC %i', $actions_table);
        $columns_result = $wpdb->get_results($columns_query);
        if (is_array($columns_result)) {
            $columns = wp_list_pluck($columns_result, 'Field');
        } else {
            $columns = [];
        }
        
        if (in_array('scheduled_date_gmt', $columns) && in_array('status', $columns)) {
            $wpdb->query($wpdb->prepare(
                "CREATE INDEX IF NOT EXISTS as_status_scheduled ON %i (status, scheduled_date_gmt)",
                $actions_table
            ));
        }
        
        if (in_array('completed_date_gmt', $columns) && in_array('status', $columns)) {
            $wpdb->query($wpdb->prepare(
                "CREATE INDEX IF NOT EXISTS as_status_completed ON %i (status, completed_date_gmt)",
                $actions_table
            ));
        }
        
        $default_retention = apply_filters('action_scheduler_retention_period', 30 * DAY_IN_SECONDS);
        $default_retention_days = (int)($default_retention / DAY_IN_SECONDS);
        
        add_option('wsacsc_logs_retention', $default_retention_days);
        add_option('wsacsc_actions_retention', $default_retention_days);
        add_option('wsacsc_selected_statuses', ['complete', 'failed', 'canceled']);
        
        add_option('wsacsc_actions_schedule_interval', '', '', 'no');
        add_option('wsacsc_logs_schedule_interval', '', '', 'no');
        add_option('wsacsc_actions_schedule_time', '', '', 'no');
        add_option('wsacsc_logs_schedule_time', '', '', 'no');
        
        self::migrate_existing_settings();
    }

    /**
     * Handle plugin deactivation
     */
    public static function deactivate(): void {
        global $wpdb;

        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        if (WSACSC_Database::check_tables_exist()) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP INDEX IF EXISTS as_status_scheduled ON `{$actions_table}`");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP INDEX IF EXISTS as_status_completed ON `{$actions_table}`");
        }

        $hook_names = array('wsacsc_cleanup_logs', 'wsacsc_cleanup_actions');
        WSACSC_Scheduler::winningsolutions_force_clear_cron_hooks($hook_names);

        wp_cache_delete('cron', 'options');
        wp_cache_delete('alloptions', 'options');

        foreach ($hook_names as $hook_name) {
            $still_exists = wp_next_scheduled($hook_name);
            if (false !== $still_exists) {
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
                wp_cache_delete('cron', 'options');
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
    }

    /**
     * Backup schedule options before migration
     *
     * @return bool True if backup was created
     */
    private static function backup_schedule_options(): bool {
        $options_to_backup = array(
            'wsacsc_actions_schedule_interval',
            'wsacsc_logs_schedule_interval',
            'wsacsc_actions_schedule_time',
            'wsacsc_logs_schedule_time',
            'wsacsc_actions_retention',
            'wsacsc_logs_retention',
        );

        $backup = array();
        foreach ($options_to_backup as $option_name) {
            $backup[$option_name] = get_option($option_name, '');
        }

        return set_transient('wsacsc_migration_backup', $backup, HOUR_IN_SECONDS);
    }

    /**
     * Restore schedule options from backup
     *
     * @return bool True if restore was successful
     */
    private static function restore_schedule_options(): bool {
        $backup = get_transient('wsacsc_migration_backup');
        if (false === $backup || !is_array($backup)) {
            return false;
        }

        foreach ($backup as $option_name => $value) {
            update_option($option_name, $value, 'no');
            wp_cache_delete($option_name, 'options');
        }

        wp_cache_delete('alloptions', 'options');
        delete_transient('wsacsc_migration_backup');

        return true;
    }

    /**
     * Detect and migrate old cron hook patterns
     */
    private static function detect_old_cron_hooks(): void {
        if (!class_exists('WSACSC_Scheduler')) {
            return;
        }

        $cron = _get_cron_array();
        if (false === $cron || empty($cron)) {
            return;
        }

        $hook_names = array('wsacsc_cleanup_logs', 'wsacsc_cleanup_actions');
        $valid_intervals = array('hourly', 'twicedaily', 'daily', 'weekly');
        $modified = false;

        foreach ($hook_names as $hook_name) {
            foreach ($cron as $timestamp => $cronhooks) {
                if (!isset($cronhooks[$hook_name])) {
                    continue;
                }

                foreach ($cronhooks[$hook_name] as $key => $hook) {
                    if (!isset($hook['schedule'])) {
                        continue;
                    }

                    $schedule = $hook['schedule'];
                    
                    if (in_array($schedule, $valid_intervals, true)) {
                        continue;
                    }

                    if (strpos($schedule, 'wsacsc_every_') === 0) {
                        $days_str = str_replace('wsacsc_every_', '', str_replace('_days', '', $schedule));
                        if (ctype_digit($days_str)) {
                            $days = (int) $days_str;
                            if ($days >= 1 && $days <= 365) {
                                continue;
                            }
                        }
                    }

                    unset($cron[$timestamp][$hook_name][$key]);
                    $modified = true;

                    if (empty($cron[$timestamp][$hook_name])) {
                        unset($cron[$timestamp][$hook_name]);
                    }
                    if (empty($cron[$timestamp])) {
                        unset($cron[$timestamp]);
                    }
                }
            }
        }

        if ($modified) {
            _set_cron_array($cron);
            wp_cache_delete('cron', 'options');
            wp_cache_delete('alloptions', 'options');
        }
    }

    /**
     * Get fallback value for schedule interval
     *
     * @param string $option_name Option name
     * @return string Fallback value
     */
    private static function get_fallback_schedule_interval($option_name): string {
        if ($option_name === 'wsacsc_actions_schedule_interval') {
            $retention = get_option('wsacsc_actions_retention', '');
            if (!empty($retention) && ctype_digit((string) $retention) && (int) $retention > 0) {
                return (string) (int) $retention;
            }
        } elseif ($option_name === 'wsacsc_logs_schedule_interval') {
            $retention = get_option('wsacsc_logs_retention', '');
            if (!empty($retention) && ctype_digit((string) $retention) && (int) $retention > 0) {
                return (string) (int) $retention;
            }
        }

        return '';
    }

    /**
     * Migrate existing settings to new structure
     */
    public static function migrate_existing_settings(): void {
        $migration_done = get_option('wsacsc_migration_v2_done', false);
        if ($migration_done) {
            return;
        }

        $backup_created = self::backup_schedule_options();

        try {
            self::detect_old_cron_hooks();

            $actions_retention = get_option('wsacsc_actions_retention', '');
            $logs_retention = get_option('wsacsc_logs_retention', '30');

            $actions_schedule_interval = get_option('wsacsc_actions_schedule_interval', '');
            if ($actions_schedule_interval === '' || !ctype_digit((string) $actions_schedule_interval)) {
                $actions_schedule_interval = self::get_fallback_schedule_interval('wsacsc_actions_schedule_interval');
            }

            $logs_schedule_interval = get_option('wsacsc_logs_schedule_interval', '');
            if ($logs_schedule_interval === '' || !ctype_digit((string) $logs_schedule_interval)) {
                $logs_schedule_interval = self::get_fallback_schedule_interval('wsacsc_logs_schedule_interval');
            }

            if (!empty($actions_schedule_interval) && ctype_digit((string) $actions_schedule_interval) && (int) $actions_schedule_interval > 0) {
                $actions_schedule_interval_int = (int) $actions_schedule_interval;
                if ($actions_schedule_interval_int > 0 && $actions_schedule_interval_int <= 365) {
                    update_option('wsacsc_actions_schedule_interval', $actions_schedule_interval_int, 'no');
                } else {
                    update_option('wsacsc_actions_schedule_interval', '', 'no');
                }
            } else {
                update_option('wsacsc_actions_schedule_interval', '', 'no');
            }

            if (!empty($logs_schedule_interval) && ctype_digit((string) $logs_schedule_interval) && (int) $logs_schedule_interval > 0) {
                $logs_schedule_interval_int = (int) $logs_schedule_interval;
                if ($logs_schedule_interval_int > 0 && $logs_schedule_interval_int <= 365) {
                    update_option('wsacsc_logs_schedule_interval', $logs_schedule_interval_int, 'no');
                } else {
                    update_option('wsacsc_logs_schedule_interval', '', 'no');
                }
            } else {
                update_option('wsacsc_logs_schedule_interval', '', 'no');
            }

            $actions_schedule_time = get_option('wsacsc_actions_schedule_time', '');
            if ($actions_schedule_time !== '' && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $actions_schedule_time)) {
                $actions_schedule_time = '';
            }
            update_option('wsacsc_actions_schedule_time', $actions_schedule_time, 'no');

            $logs_schedule_time = get_option('wsacsc_logs_schedule_time', '');
            if ($logs_schedule_time !== '' && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $logs_schedule_time)) {
                $logs_schedule_time = '';
            }
            update_option('wsacsc_logs_schedule_time', $logs_schedule_time, 'no');

            wp_cache_delete('wsacsc_actions_schedule_interval', 'options');
            wp_cache_delete('wsacsc_logs_schedule_interval', 'options');
            wp_cache_delete('wsacsc_actions_schedule_time', 'options');
            wp_cache_delete('wsacsc_logs_schedule_time', 'options');
            wp_cache_delete('alloptions', 'options');

            $validation_passed = true;
            $actions_interval_check = get_option('wsacsc_actions_schedule_interval', '');
            $logs_interval_check = get_option('wsacsc_logs_schedule_interval', '');

            if ($actions_schedule_interval !== '' && $actions_interval_check !== $actions_schedule_interval) {
                $validation_passed = false;
            }

            if ($logs_schedule_interval !== '' && $logs_interval_check !== $logs_schedule_interval) {
                $validation_passed = false;
            }

            if (!$validation_passed && $backup_created) {
                self::restore_schedule_options();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WS Action Scheduler Cleaner: Migration validation failed, restored from backup');
                }
                return;
            }

            update_option('wsacsc_migration_v2_done', true, 'no');
            wp_cache_delete('wsacsc_migration_v2_done', 'options');

            if (class_exists('WSACSC_Scheduler')) {
                WSACSC_Scheduler::winningsolutions_cleanup_broken_hooks(array('wsacsc_cleanup_logs', 'wsacsc_cleanup_actions'));
                WSACSC_Scheduler::maybe_schedule_cleanup();
                WSACSC_Scheduler::verify_cron_health();
            }

            if ($backup_created) {
                delete_transient('wsacsc_migration_backup');
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('WS Action Scheduler Cleaner: Migration error: %s', $e->getMessage()));
            }

            if ($backup_created) {
                self::restore_schedule_options();
            }
        }
    }
}
