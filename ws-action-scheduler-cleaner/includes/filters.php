<?php
/**
 * Action Scheduler filters for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize Action Scheduler filters
 */
function wsacsc_init_filters(): void {
    add_filter('action_scheduler_cleanup_batch_size', 'wsacsc_increase_cleanup_batch_size');
    add_filter('action_scheduler_queue_runner_time_limit', 'wsacsc_increase_queue_runner_time_limit');
    add_filter('action_scheduler_retention_period', 'wsacsc_change_retention_period');
    add_filter('action_scheduler_default_cleaner_statuses', 'wsacsc_set_cleaner_statuses');
}

/**
 * Increase cleanup batch size
 *
 * @param int $batch_size Default batch size
 * @return int
 */
function wsacsc_increase_cleanup_batch_size($batch_size): int {
    return 50;
}

/**
 * Increase queue runner time limit
 *
 * @param int $time_limit Default time limit
 * @return int
 */
function wsacsc_increase_queue_runner_time_limit($time_limit): int {
    return 60;
}

/**
 * Check if plugin's scheduled cleanup is active
 *
 * @return bool True if plugin's scheduled cleanup is active
 */
function wsacsc_is_plugin_schedule_active(): bool {
    wp_cache_delete('wsacsc_actions_schedule_interval', 'options');
    $actions_schedule_interval = get_option('wsacsc_actions_schedule_interval', '');
    
    if (!empty($actions_schedule_interval) && ctype_digit((string) $actions_schedule_interval)) {
        $interval = (int) $actions_schedule_interval;
        if ($interval > 0 && $interval <= 365) {
            return true;
        }
    }
    
    return false;
}

/**
 * Change retention period
 *
 * @param int $default_retention_period Default retention period
 * @return int
 */
function wsacsc_change_retention_period($default_retention_period): int {
    if (wsacsc_is_plugin_schedule_active()) {
        return PHP_INT_MAX;
    }
    
    wp_cache_delete('wsacsc_actions_retention', 'options');
    $retention_days_option = get_option('wsacsc_actions_retention', '30');
    if ($retention_days_option === '' || !ctype_digit((string) $retention_days_option)) {
        return $default_retention_period;
    }
    $retention_days = intval($retention_days_option);
    if ($retention_days === 0) {
        return 0;
    }
    return $retention_days * DAY_IN_SECONDS;
}

/**
 * Set cleaner statuses
 *
 * @param array $default_statuses Default statuses
 * @return array
 */
function wsacsc_set_cleaner_statuses($default_statuses): array {
    wp_cache_delete('wsacsc_selected_statuses', 'options');
    $selected_statuses_option = get_option('wsacsc_selected_statuses', ['complete', 'failed', 'canceled']);
    if (!is_array($selected_statuses_option)) {
        $selected_statuses_option = ['complete', 'failed', 'canceled'];
    }
    
    $allowed_statuses = ['complete', 'failed', 'canceled'];
    $selected_statuses = array_map('sanitize_text_field', $selected_statuses_option);
    $selected_statuses = array_intersect($selected_statuses, $allowed_statuses);
    
    return !empty($selected_statuses) ? $selected_statuses : $default_statuses;
}
