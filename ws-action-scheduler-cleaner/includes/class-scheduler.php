<?php
/**
 * Scheduler functionality for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WSACSC_Scheduler
 */
class WSACSC_Scheduler {

    /**
     * Cache group for plugin cache operations
     */
    const CACHE_GROUP = 'wsacsc';

    /**
     * Initialize scheduler functionality
     */
    public static function init(): void {
        add_filter('cron_schedules', array(__CLASS__, 'add_custom_cron_intervals'));
        add_action('plugins_loaded', array(__CLASS__, 'maybe_schedule_cleanup'), 20);
        add_action('init', array(__CLASS__, 'maybe_schedule_cleanup'), 20);
        add_action('admin_init', array(__CLASS__, 'maybe_schedule_cleanup'));
        add_action('admin_init', array(__CLASS__, 'verify_cron_health'), 30);
    }

    /**
     * Get option with cache invalidation support
     *
     * @param string $option_name Option name
     * @param mixed  $default Default value
     * @param bool   $force_refresh Force refresh from database
     * @return mixed
     */
    private static function get_option_fresh($option_name, $default = false, $force_refresh = false): mixed {
        if ($force_refresh) {
            wp_cache_delete($option_name, 'options');
        }
        return get_option($option_name, $default);
    }

    /**
     * Add custom cron intervals
     *
     * @param array $schedules Existing schedules
     * @return array
     */
    public static function add_custom_cron_intervals($schedules): array {
        if (!is_array($schedules)) {
            $schedules = array();
        }

        $logs_schedule_interval = self::get_option_fresh('wsacsc_logs_schedule_interval', '');
        $actions_schedule_interval = self::get_option_fresh('wsacsc_actions_schedule_interval', '');
        
        $day_counts = array();
        
        if (!empty($logs_schedule_interval) && ctype_digit((string) $logs_schedule_interval)) {
            $days = (int) $logs_schedule_interval;
            if ($days > 0 && $days <= 365) {
                $day_counts[] = $days;
            }
        }
        
        if (!empty($actions_schedule_interval) && ctype_digit((string) $actions_schedule_interval)) {
            $days = (int) $actions_schedule_interval;
            if ($days > 0 && $days <= 365) {
                $day_counts[] = $days;
            }
        }
        
        foreach (array_unique($day_counts) as $days) {
            if ($days <= 1) {
                continue;
            }
            
            $interval_key = 'wsacsc_every_' . $days . '_days';
            if (!isset($schedules[$interval_key])) {
                $interval_seconds = $days * DAY_IN_SECONDS;
                $schedules[$interval_key] = array(
                    'interval' => $interval_seconds,
                    'display' => sprintf(_n('Every %d day', 'Every %d days', $days, 'ws-action-scheduler-cleaner'), $days)
                );
            }
        }
        
        return $schedules;
    }

    /**
     * Get fresh cron array by invalidating cache first
     *
     * @return array|false Cron array or false on failure
     */
    private static function get_fresh_cron_array() {
        wp_cache_delete('cron', 'options');
        wp_cache_delete('alloptions', 'options');
        return _get_cron_array();
    }

    /**
     * Get next scheduled time with cache invalidation
     *
     * @param string $hook_name Hook name
     * @return int|false Timestamp of next scheduled time or false
     */
    private static function get_next_scheduled_fresh($hook_name) {
        wp_cache_delete('cron', 'options');
        wp_cache_delete('alloptions', 'options');
        return wp_next_scheduled($hook_name);
    }

    /**
     * Validate if existing schedule matches desired configuration
     *
     * @param string $hook_name Hook name
     * @param string $interval Desired interval
     * @param int    $desired_timestamp Desired next run timestamp
     * @return array Validation result with 'valid' boolean and 'issues' array
     */
    private static function validate_schedule($hook_name, $interval, $desired_timestamp = null): array {
        $result = array(
            'valid' => false,
            'issues' => array()
        );

        $cron = self::get_fresh_cron_array();
        
        if (false === $cron || empty($cron)) {
            $result['issues'][] = 'Cron array is empty or unavailable';
            return $result;
        }
        
        $scheduled_timestamp = self::get_next_scheduled_fresh($hook_name);
        if (false === $scheduled_timestamp) {
            $result['issues'][] = 'No scheduled event found for hook';
            return $result;
        }

        $now = time();
        $max_reasonable_timestamp = $now + (10 * YEAR_IN_SECONDS);
        $min_reasonable_timestamp = $now - (1 * DAY_IN_SECONDS);

        if ($scheduled_timestamp < $min_reasonable_timestamp) {
            $result['issues'][] = 'Scheduled timestamp is in the past';
        }

        if ($scheduled_timestamp > $max_reasonable_timestamp) {
            $result['issues'][] = 'Scheduled timestamp is unreasonably far in the future';
        }
        
        $hook_found = false;
        foreach ($cron as $timestamp => $cronhooks) {
            if (isset($cronhooks[$hook_name])) {
                $hook_found = true;
                foreach ($cronhooks[$hook_name] as $key => $hook) {
                    if (!isset($hook['schedule'])) {
                        $result['issues'][] = 'Hook missing schedule key';
                        continue;
                    }

                    if ($hook['schedule'] !== $interval) {
                        $result['issues'][] = sprintf('Interval mismatch: expected %s, found %s', $interval, $hook['schedule']);
                        continue;
                    }

                    if ($desired_timestamp !== null) {
                        $timezone = wp_timezone();
                        $scheduled_datetime = new DateTime('@' . $scheduled_timestamp);
                        $scheduled_datetime->setTimezone($timezone);
                        $desired_datetime = new DateTime('@' . $desired_timestamp);
                        $desired_datetime->setTimezone($timezone);
                        
                        $scheduled_hour = (int) $scheduled_datetime->format('H');
                        $scheduled_minute = (int) $scheduled_datetime->format('i');
                        $desired_hour = (int) $desired_datetime->format('H');
                        $desired_minute = (int) $desired_datetime->format('i');
                        
                        if ($scheduled_hour !== $desired_hour || $scheduled_minute !== $desired_minute) {
                            $result['issues'][] = sprintf('Time mismatch: expected %02d:%02d, found %02d:%02d', $desired_hour, $desired_minute, $scheduled_hour, $scheduled_minute);
                            continue;
                        }
                    }

                    $result['valid'] = true;
                    return $result;
                }
            }
        }

        if (!$hook_found) {
            $result['issues'][] = 'Hook not found in cron array';
        }
        
        return $result;
    }

    /**
     * Invalidate cron cache after operations
     */
    private static function invalidate_cron_cache(): void {
        wp_cache_delete('cron', 'options');
        wp_cache_delete('alloptions', 'options');
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('options');
        }
        usleep(50000);
    }

    /**
     * Detect and remove broken cron hooks
     * 
     * A hook is considered broken if:
     * - It has an invalid/unknown schedule interval
     * - It has an extremely large timestamp (indicating corruption)
     * - The schedule interval doesn't match expected patterns
     * - The interval value is unreasonably large (e.g., > 3650 days)
     *
     * @param array $hook_names Hook names to check
     * @return int Number of broken hooks removed
     */
    public static function winningsolutions_cleanup_broken_hooks($hook_names): int {
        if (!is_array($hook_names)) {
            $hook_names = array($hook_names);
        }

        $cron = self::get_fresh_cron_array();
        if (false === $cron || empty($cron)) {
            return 0;
        }

        $removed_count = 0;
        $valid_intervals = array('hourly', 'twicedaily', 'daily', 'weekly');
        $max_reasonable_timestamp = time() + (10 * YEAR_IN_SECONDS);
        $min_reasonable_timestamp = time() - (1 * YEAR_IN_SECONDS);
        
        foreach ($hook_names as $hook_name) {
            $modified = false;

            foreach ($cron as $timestamp => $cronhooks) {
                if (!isset($cronhooks[$hook_name])) {
                    continue;
                }

                foreach ($cronhooks[$hook_name] as $key => $hook) {
                    $is_broken = false;

                    if (isset($hook['schedule'])) {
                        $schedule = $hook['schedule'];
                        
                        if (strpos($schedule, 'wsacsc_every_') === 0) {
                            $days_str = str_replace('wsacsc_every_', '', str_replace('_days', '', $schedule));
                            if (!ctype_digit($days_str)) {
                                $is_broken = true;
                            } else {
                                $days = (int)$days_str;
                                if ($days > 3650 || $days < 1) {
                                    $is_broken = true;
                                }
                            }
                        } elseif (!in_array($schedule, $valid_intervals, true)) {
                            $is_broken = true;
                        }
                    } else {
                        $is_broken = true;
                    }

                    if ($timestamp < $min_reasonable_timestamp || $timestamp > $max_reasonable_timestamp) {
                        $is_broken = true;
                    }

                    if ($is_broken) {
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
                self::invalidate_cron_cache();
                $removed_count++;
            }
        }

        if ($removed_count > 0) {
            foreach ($hook_names as $hook_name) {
                wp_clear_scheduled_hook($hook_name);
            }
            self::invalidate_cron_cache();
        }

        return $removed_count;
    }

    /**
     * Force clear cron hooks with fallback to direct manipulation
     *
     * @param string|array $hook_names Hook name(s) to clear
     * @return bool True if successful
     */
    public static function winningsolutions_force_clear_cron_hooks($hook_names): bool {
        if (!is_array($hook_names)) {
            $hook_names = array($hook_names);
        }

        $success = true;
        foreach ($hook_names as $hook_name) {
            wp_clear_scheduled_hook($hook_name);
            self::invalidate_cron_cache();

            $cron = self::get_fresh_cron_array();
            if (false !== $cron && !empty($cron)) {
                $modified = false;
                foreach ($cron as $timestamp => $cronhooks) {
                    if (isset($cronhooks[$hook_name])) {
                        unset($cron[$timestamp][$hook_name]);
                        $modified = true;
                        if (empty($cron[$timestamp])) {
                            unset($cron[$timestamp]);
                        }
                    }
                }

                if ($modified) {
                    _set_cron_array($cron);
                    self::invalidate_cron_cache();
                    
                    $still_exists = self::get_next_scheduled_fresh($hook_name);
                    if (false !== $still_exists) {
                        wp_cache_delete('cron', 'options');
                        wp_cache_delete('alloptions', 'options');
                        $cron_option = get_option('cron');
                        if (is_array($cron_option)) {
                            foreach ($cron_option as $ts => $events) {
                                if (isset($events[$hook_name])) {
                                    unset($cron_option[$ts][$hook_name]);
                                    if (empty($cron_option[$ts])) {
                                        unset($cron_option[$ts]);
                                    }
                                }
                            }
                            update_option('cron', $cron_option);
                            self::invalidate_cron_cache();
                        }
                    }
                }
            }

            $final_check = self::get_next_scheduled_fresh($hook_name);
            if (false !== $final_check) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Calculate next run time with time of day consideration
     *
     * @param int    $schedule_interval_days Schedule interval in days
     * @param string $schedule_time Time of day in HH:MM format (empty for immediate)
     * @return int Timestamp for next run
     */
    private static function calculate_next_run_time($schedule_interval_days, $schedule_time): int {
        $now = time();
        $timezone = wp_timezone();
        
        if (empty($schedule_time) || !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $schedule_time)) {
            return $now + ($schedule_interval_days * DAY_IN_SECONDS);
        }
        
        list($hour, $minute) = explode(':', $schedule_time);
        $hour = (int) $hour;
        $minute = (int) $minute;
        
        $today = new DateTime('today', $timezone);
        $today->setTime($hour, $minute, 0);
        $target_timestamp = $today->getTimestamp();
        
        if ($target_timestamp <= $now) {
            $target_timestamp += DAY_IN_SECONDS;
        }
        
        return $target_timestamp;
    }

    /**
     * Schedule event with retry logic
     *
     * @param int    $timestamp Timestamp for first run
     * @param string $interval Schedule interval
     * @param string $hook_name Hook name
     * @return bool True if successful
     */
    private static function schedule_with_retry($timestamp, $interval, $hook_name): bool {
        $max_attempts = 3;
        $delay_ms = 100;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            wp_clear_scheduled_hook($hook_name);
            self::invalidate_cron_cache();

            $result = wp_schedule_event($timestamp, $interval, $hook_name);
            
            if ($result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf('WS Action Scheduler Cleaner: Failed to schedule %s (attempt %d/%d)', $hook_name, $attempt, $max_attempts));
                }
                
                if ($attempt < $max_attempts) {
                    usleep($delay_ms * 1000);
                    $delay_ms *= 2;
                }
                continue;
            }

            self::invalidate_cron_cache();

            $scheduled = self::get_next_scheduled_fresh($hook_name);
            if (false !== $scheduled) {
                return true;
            }

            if ($attempt < $max_attempts) {
                usleep($delay_ms * 1000);
                $delay_ms *= 2;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('WS Action Scheduler Cleaner: Failed to schedule %s after %d attempts', $hook_name, $max_attempts));
        }

        return false;
    }

    /**
     * Ensure cron event is scheduled correctly
     *
     * @param string $hook_name Hook name
     * @param string $interval Schedule interval
     * @param int    $desired_timestamp Desired next run timestamp
     * @return bool True if cron is scheduled correctly
     */
    private static function ensure_cron_scheduled($hook_name, $interval, $desired_timestamp): bool {
        $validation = self::validate_schedule($hook_name, $interval, $desired_timestamp);
        
        if ($validation['valid']) {
            return true;
        }

        if (defined('WP_DEBUG') && WP_DEBUG && !empty($validation['issues'])) {
            error_log(sprintf('WS Action Scheduler Cleaner: Cron validation failed for %s: %s', $hook_name, implode(', ', $validation['issues'])));
        }

        return self::schedule_with_retry($desired_timestamp, $interval, $hook_name);
    }

    /**
     * Verify cron health and auto-fix issues
     *
     * @return array Health status with 'healthy', 'issues', and 'fixed' keys
     */
    public static function verify_cron_health(): array {
        $health = array(
            'healthy' => true,
            'issues' => array(),
            'fixed' => array()
        );

        $logs_schedule_interval = self::get_option_fresh('wsacsc_logs_schedule_interval', '');
        $logs_schedule_time = self::get_option_fresh('wsacsc_logs_schedule_time', '');
        $actions_schedule_interval = self::get_option_fresh('wsacsc_actions_schedule_interval', '');
        $actions_schedule_time = self::get_option_fresh('wsacsc_actions_schedule_time', '');

        $hooks_to_check = array();

        if (!empty($logs_schedule_interval) && ctype_digit((string) $logs_schedule_interval)) {
            $schedule_interval_days = (int) $logs_schedule_interval;
            if ($schedule_interval_days > 0 && $schedule_interval_days <= 365) {
                $first_run_time = self::calculate_next_run_time($schedule_interval_days, $logs_schedule_time);
                $interval = ($schedule_interval_days <= 1) ? 'daily' : 'wsacsc_every_' . $schedule_interval_days . '_days';
                $hooks_to_check['wsacsc_cleanup_logs'] = array(
                    'interval' => $interval,
                    'timestamp' => $first_run_time
                );
            }
        }

        if (!empty($actions_schedule_interval) && ctype_digit((string) $actions_schedule_interval)) {
            $schedule_interval_days = (int) $actions_schedule_interval;
            if ($schedule_interval_days > 0 && $schedule_interval_days <= 365) {
                $first_run_time = self::calculate_next_run_time($schedule_interval_days, $actions_schedule_time);
                $interval = ($schedule_interval_days <= 1) ? 'daily' : 'wsacsc_every_' . $schedule_interval_days . '_days';
                $hooks_to_check['wsacsc_cleanup_actions'] = array(
                    'interval' => $interval,
                    'timestamp' => $first_run_time
                );
            }
        }

        $cron = self::get_fresh_cron_array();
        if (false === $cron || empty($cron)) {
            if (!empty($hooks_to_check)) {
                $health['healthy'] = false;
                $health['issues'][] = 'Cron array is empty but hooks should be scheduled';
            }
            return $health;
        }

        $now = time();
        $max_reasonable_timestamp = $now + (10 * YEAR_IN_SECONDS);
        $min_reasonable_timestamp = $now - (1 * DAY_IN_SECONDS);

        foreach ($hooks_to_check as $hook_name => $config) {
            $scheduled_timestamp = self::get_next_scheduled_fresh($hook_name);
            
            if (false === $scheduled_timestamp) {
                $health['healthy'] = false;
                $health['issues'][] = sprintf('Missing scheduled event for %s', $hook_name);
                
                if (self::schedule_with_retry($config['timestamp'], $config['interval'], $hook_name)) {
                    $health['fixed'][] = sprintf('Recreated missing event for %s', $hook_name);
                }
                continue;
            }

            $validation = self::validate_schedule($hook_name, $config['interval'], $config['timestamp']);
            
            if (!$validation['valid']) {
                $health['healthy'] = false;
                $health['issues'][] = sprintf('%s: %s', $hook_name, implode(', ', $validation['issues']));
                
                if (self::schedule_with_retry($config['timestamp'], $config['interval'], $hook_name)) {
                    $health['fixed'][] = sprintf('Fixed mismatched event for %s', $hook_name);
                }
            }

            if ($scheduled_timestamp < $min_reasonable_timestamp || $scheduled_timestamp > $max_reasonable_timestamp) {
                $health['healthy'] = false;
                $health['issues'][] = sprintf('%s has invalid timestamp: %d', $hook_name, $scheduled_timestamp);
                
                if (self::schedule_with_retry($config['timestamp'], $config['interval'], $hook_name)) {
                    $health['fixed'][] = sprintf('Fixed invalid timestamp for %s', $hook_name);
                }
            }
        }

        $all_hooks = array('wsacsc_cleanup_logs', 'wsacsc_cleanup_actions');
        foreach ($all_hooks as $hook_name) {
            $found_orphaned = false;
            foreach ($cron as $timestamp => $cronhooks) {
                if (isset($cronhooks[$hook_name])) {
                    foreach ($cronhooks[$hook_name] as $key => $hook) {
                        if (!isset($hook['schedule'])) {
                            $found_orphaned = true;
                            break;
                        }
                        
                        $schedule = $hook['schedule'];
                        $is_valid_interval = in_array($schedule, array('hourly', 'twicedaily', 'daily', 'weekly'), true);
                        
                        if (!$is_valid_interval && strpos($schedule, 'wsacsc_every_') !== 0) {
                            $found_orphaned = true;
                            break;
                        }
                        
                        if (strpos($schedule, 'wsacsc_every_') === 0) {
                            $days_str = str_replace('wsacsc_every_', '', str_replace('_days', '', $schedule));
                            if (!ctype_digit($days_str)) {
                                $found_orphaned = true;
                                break;
                            }
                            $days = (int) $days_str;
                            if ($days < 1 || $days > 365) {
                                $found_orphaned = true;
                                break;
                            }
                        }
                    }
                    
                    if ($found_orphaned) {
                        break;
                    }
                }
            }
            
            if ($found_orphaned && !isset($hooks_to_check[$hook_name])) {
                wp_clear_scheduled_hook($hook_name);
                self::invalidate_cron_cache();
                $health['fixed'][] = sprintf('Removed orphaned event for %s', $hook_name);
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG && (!$health['healthy'] || !empty($health['fixed']))) {
            error_log(sprintf('WS Action Scheduler Cleaner: Cron health check - Healthy: %s, Issues: %d, Fixed: %d', 
                $health['healthy'] ? 'Yes' : 'No', 
                count($health['issues']), 
                count($health['fixed'])
            ));
        }

        return $health;
    }

    /**
     * Schedule or unschedule cleanup tasks
     */
    public static function maybe_schedule_cleanup(): void {
        $logs_schedule_interval = self::get_option_fresh('wsacsc_logs_schedule_interval', '');
        $logs_schedule_time = self::get_option_fresh('wsacsc_logs_schedule_time', '');
        
        if (!empty($logs_schedule_interval) && ctype_digit((string) $logs_schedule_interval)) {
            $schedule_interval_days = (int) $logs_schedule_interval;
            if ($schedule_interval_days > 0 && $schedule_interval_days <= 365) {
                $first_run_time = self::calculate_next_run_time($schedule_interval_days, $logs_schedule_time);
                
                if ($schedule_interval_days <= 1) {
                    $interval = 'daily';
                } else {
                    $interval = 'wsacsc_every_' . $schedule_interval_days . '_days';
                }
                
                $validation = self::validate_schedule('wsacsc_cleanup_logs', $interval, $first_run_time);
                if (!$validation['valid']) {
                    self::schedule_with_retry($first_run_time, $interval, 'wsacsc_cleanup_logs');
                }
                
                self::ensure_cron_scheduled('wsacsc_cleanup_logs', $interval, $first_run_time);
            } else {
                $existing = self::get_next_scheduled_fresh('wsacsc_cleanup_logs');
                if (false !== $existing) {
                    wp_clear_scheduled_hook('wsacsc_cleanup_logs');
                    self::invalidate_cron_cache();
                }
            }
        } else {
            $existing = self::get_next_scheduled_fresh('wsacsc_cleanup_logs');
            if (false !== $existing) {
                wp_clear_scheduled_hook('wsacsc_cleanup_logs');
                self::invalidate_cron_cache();
            }
        }
        
        $actions_schedule_interval = self::get_option_fresh('wsacsc_actions_schedule_interval', '');
        $actions_schedule_time = self::get_option_fresh('wsacsc_actions_schedule_time', '');
        
        if (!empty($actions_schedule_interval) && ctype_digit((string) $actions_schedule_interval)) {
            $schedule_interval_days = (int) $actions_schedule_interval;
            if ($schedule_interval_days > 0 && $schedule_interval_days <= 365) {
                $first_run_time = self::calculate_next_run_time($schedule_interval_days, $actions_schedule_time);
                
                if ($schedule_interval_days <= 1) {
                    $interval = 'daily';
                } else {
                    $interval = 'wsacsc_every_' . $schedule_interval_days . '_days';
                }
                
                $validation = self::validate_schedule('wsacsc_cleanup_actions', $interval, $first_run_time);
                if (!$validation['valid']) {
                    self::schedule_with_retry($first_run_time, $interval, 'wsacsc_cleanup_actions');
                }
                
                self::ensure_cron_scheduled('wsacsc_cleanup_actions', $interval, $first_run_time);
            } else {
                $existing = self::get_next_scheduled_fresh('wsacsc_cleanup_actions');
                if (false !== $existing) {
                    wp_clear_scheduled_hook('wsacsc_cleanup_actions');
                    self::invalidate_cron_cache();
                }
            }
        } else {
            $existing = self::get_next_scheduled_fresh('wsacsc_cleanup_actions');
            if (false !== $existing) {
                wp_clear_scheduled_hook('wsacsc_cleanup_actions');
                self::invalidate_cron_cache();
            }
        }
    }
}
