<?php
/**
 * Admin interface for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WSACSC_Admin
 */
class WSACSC_Admin {

    /**
     * Initialize admin functionality
     */
    public static function init(): void {
        add_filter(
            'plugin_action_links_' . plugin_basename(WSACSC_PLUGIN_FILE),
            array(__CLASS__, 'add_plugin_links')
        );
        add_filter('load_textdomain_mofile', array(__CLASS__, 'load_translations_locally'), 10, 2);
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /**
     * Add settings link to plugins page
     *
     * @param array $links Existing links
     * @return array
     */
    public static function add_plugin_links(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('tools.php?page=ws-action-scheduler-cleaner')),
            esc_html__('Settings', 'ws-action-scheduler-cleaner')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Load local translations
     *
     * @param string $mofile MO file path
     * @param string $domain Text domain
     * @return string
     */
    public static function load_translations_locally($mofile, $domain) {
        if ('ws-action-scheduler-cleaner' === $domain && false !== strpos($mofile, WP_LANG_DIR . '/plugins/')) {
            $locale = apply_filters('plugin_locale', determine_locale(), $domain);
            $mofile = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(WSACSC_PLUGIN_FILE)) . '/languages/' . $domain . '-' . $locale . '.mo';
        }
        return $mofile;
    }

    /**
     * Add menu item
     *
     * @return string
     */
    public static function menu(): string {
        $hook = add_submenu_page(
            'tools.php',
            'WS Action Scheduler Cleaner',
            'WS AS Cleaner',
            'manage_options',
            'ws-action-scheduler-cleaner',
            array(__CLASS__, 'page')
        );
        add_action("admin_print_styles-$hook", array(__CLASS__, 'admin_styles'));
        return $hook;
    }

    /**
     * Enqueue admin styles
     */
    public static function admin_styles(): void {
        wp_enqueue_style('wp-admin');
    }

    /**
     * Enqueue scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public static function assets(string $hook): void {
        if ($hook != 'tools_page_ws-action-scheduler-cleaner') {
            return;
        }
        wp_enqueue_style('dashicons');
        wp_enqueue_script('wsacsc-js', plugin_dir_url(WSACSC_PLUGIN_FILE) . 'assets/js/ws-as-cleaner.js', array('jquery'), '1.4', true);
        wp_enqueue_style('wsacsc-css', plugin_dir_url(WSACSC_PLUGIN_FILE) . 'assets/css/ws-as-cleaner.css', array(), '1.4');
        wp_localize_script('wsacsc-js', 'wsacsc_cleaner', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wsacsc_nonce'),
            'select_status_message' => __('Please select at least one status to clear.', 'ws-action-scheduler-cleaner'),
            'clearing_message' => __('Clearing in progress...', 'ws-action-scheduler-cleaner'),
            'in_progress_message' => __('Please wait...', 'ws-action-scheduler-cleaner'),
            'error_message' => __('An error occurred. Please try again.', 'ws-action-scheduler-cleaner'),
            'success_actions_cleared' => __('Selected actions cleared successfully!', 'ws-action-scheduler-cleaner'),
            'success_logs_cleared' => __('Logs cleared successfully!', 'ws-action-scheduler-cleaner'),
            'success_schedule_saved' => __('Schedule saved successfully!', 'ws-action-scheduler-cleaner'),
            'success_statuses_saved' => __('Statuses saved successfully!', 'ws-action-scheduler-cleaner'),
            'optimizing_message' => __('Optimizing table...', 'ws-action-scheduler-cleaner'),
            'updating_text' => __('Loading...', 'ws-action-scheduler-cleaner'),
            'table_optimization_failed' => __('Table optimization failed.', 'ws-action-scheduler-cleaner')
        ));
    }

    /**
     * Create the admin page
     */
    public static function page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        nocache_headers();

        if (!WSACSC_Database::check_tables_exist()) {
            echo '<div class="wrap wsacsc-cleaner">';
            echo '<h1>' . esc_html__('WS Action Scheduler Cleaner', 'ws-action-scheduler-cleaner') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('Action Scheduler tables do not exist. Please ensure Action Scheduler is properly installed and activated.', 'ws-action-scheduler-cleaner') . '</p></div>';
            echo '</div>';
            return;
        }
        ?>
        <div class="wrap wsacsc-cleaner">
            <h1><?php esc_html_e('WS Action Scheduler Cleaner', 'ws-action-scheduler-cleaner'); ?></h1>
            <div id="general-status-message" class="wsacsc-message" style="display: none;"></div>
            <div class="wsacsc-info">
                <p><?php esc_html_e('This tool allows you to clean up the Action Scheduler tables in your WordPress database. Action Scheduler is a library used by many plugins to schedule background tasks.', 'ws-action-scheduler-cleaner'); ?></p>
                <p><?php esc_html_e('The Action Scheduler uses two main tables:', 'ws-action-scheduler-cleaner'); ?></p>
                <ul>
                    <li><?php esc_html_e('Actions Table: Stores scheduled actions and their statuses.', 'ws-action-scheduler-cleaner'); ?></li>
                    <li><?php esc_html_e('Logs Table: Stores logs of action executions.', 'ws-action-scheduler-cleaner'); ?></li>
                </ul>
                <p><?php esc_html_e('Clearing these tables is generally safe and can improve database performance. However, please note:', 'ws-action-scheduler-cleaner'); ?></p>
                <ul>
                    <li><?php esc_html_e('Clearing can take a while depending on the size of the tables.', 'ws-action-scheduler-cleaner'); ?></li>
                    <li><?php esc_html_e('Cleared data will be permanently deleted.', 'ws-action-scheduler-cleaner'); ?></li>
                    <li><?php echo wp_kses(__('It\'s recommended to <strong>make a backup</strong> before proceeding.', 'ws-action-scheduler-cleaner'), array('strong' => array())); ?></li>
                    <li><?php esc_html_e('Clearing completed, failed, or canceled actions usually doesn\'t affect WordPress functionality.', 'ws-action-scheduler-cleaner'); ?></li>
                </ul>
                <p>
                    <?php
                    printf(
                        wp_kses(
                            /* translators: %s: URL to Action Scheduler admin page */
                            __('For more information, visit the <a href="%s">Action Scheduler admin page</a>.', 'ws-action-scheduler-cleaner'),
                            array('a' => array('href' => array()))
                        ),
                        esc_url(admin_url('tools.php?page=action-scheduler'))
                    );
                    ?>
                </p>
            </div>
            <div class="wsacsc-stats">
                <h2><?php esc_html_e('Current Table Sizes:', 'ws-action-scheduler-cleaner'); ?></h2>
                <ul>
                    <li><?php esc_html_e('Actions table:', 'ws-action-scheduler-cleaner'); ?>
                    <span id="actions-count"><?php esc_html_e('Loading...', 'ws-action-scheduler-cleaner'); ?></span>
                        <?php esc_html_e('rows', 'ws-action-scheduler-cleaner'); ?>
                        (<span id="actions-size"><?php esc_html_e('Loading...', 'ws-action-scheduler-cleaner'); ?></span>)
                        <span class="dashicons dashicons-update wsacsc-refresh wsacsc-refresh-actions"></span>
                    </li>
                    <li><?php esc_html_e('Logs table:', 'ws-action-scheduler-cleaner'); ?>
                        <span id="logs-count"><?php esc_html_e('Loading...', 'ws-action-scheduler-cleaner'); ?></span>
                        <?php esc_html_e('rows', 'ws-action-scheduler-cleaner'); ?>
                        (<span id="logs-size"><?php esc_html_e('Loading...', 'ws-action-scheduler-cleaner'); ?></span>)
                        <span class="dashicons dashicons-update wsacsc-refresh wsacsc-refresh-logs"></span>
                    </li>
                </ul>
            </div>
            <div class="wsacsc-section">
                <h2><?php esc_html_e('Clear Action Statuses', 'ws-action-scheduler-cleaner'); ?></h2>
                <div class="wsacsc-options">
                    <p><?php esc_html_e('Select which action statuses to clear:', 'ws-action-scheduler-cleaner'); ?></p>
                    <?php
                    $selected_statuses = get_option('wsacsc_selected_statuses', array('complete', 'failed', 'canceled'));
                    if (!is_array($selected_statuses)) {
                        $selected_statuses = array('complete', 'failed', 'canceled');
                    }
                    ?>
                    <label><input type="checkbox" name="status[]" value="complete" <?php checked(in_array('complete', $selected_statuses)); ?>> <?php esc_html_e('Complete', 'ws-action-scheduler-cleaner'); ?></label>
                    <label><input type="checkbox" name="status[]" value="failed" <?php checked(in_array('failed', $selected_statuses)); ?>> <?php esc_html_e('Failed', 'ws-action-scheduler-cleaner'); ?></label>
                    <label><input type="checkbox" name="status[]" value="canceled" <?php checked(in_array('canceled', $selected_statuses)); ?>> <?php esc_html_e('Canceled', 'ws-action-scheduler-cleaner'); ?></label>
                </div>
                <button id="clear-actions" class="button button-primary"><?php esc_html_e('Clear Selected Actions', 'ws-action-scheduler-cleaner'); ?></button>
                <div id="actions-status-message" class="wsacsc-message"></div>
                <div id="status-save-message" class="wsacsc-message" style="display: none;"></div>
                <div id="actions-progress" class="wsacsc-progress"></div>
            </div>
            <div class="wsacsc-section">
                <h2><?php esc_html_e('Clear Logs Table', 'ws-action-scheduler-cleaner'); ?></h2>
                <p><?php esc_html_e('This will clear all entries in the logs table.', 'ws-action-scheduler-cleaner'); ?></p>
                <button id="clear-logs" class="button button-primary"><?php esc_html_e('Clear Logs', 'ws-action-scheduler-cleaner'); ?></button>
                <div id="logs-status-message" class="wsacsc-message"></div>
                <div id="logs-progress" class="wsacsc-progress"></div>
            </div>
            <div class="wsacsc-section">
                <h2><?php esc_html_e('Optimize Tables', 'ws-action-scheduler-cleaner'); ?></h2>
                <p><?php esc_html_e('Optimize the database tables to reclaim unused space and potentially improve performance.', 'ws-action-scheduler-cleaner'); ?></p>
                <div class="wsacsc-optimize-buttons">
                    <button id="optimize-actions" class="button button-primary"><?php esc_html_e('Optimize Actions Table', 'ws-action-scheduler-cleaner'); ?></button>
                    <button id="optimize-logs" class="button button-primary"><?php esc_html_e('Optimize Logs Table', 'ws-action-scheduler-cleaner'); ?></button>
                </div>
                <div id="optimize-status-message" class="wsacsc-message" style="display: none;"></div>
            </div>
            <div class="wsacsc-section">
                <h2><?php esc_html_e('Scheduling Options', 'ws-action-scheduler-cleaner'); ?></h2>
                <p><?php esc_html_e('Setting the "Cleanup Schedule" will activate the plugin\'s own cleaning system, a high-throughput system with moderate server resource usage best suited for less busy times of the day. The "Retention Period" for actions cleanup affects both the plugin\'s and Action Scheduler\'s cleanup systems. Action Scheduler\'s own retention period only concerns the actions table and runs continuously in the background with low server resource usage. The "Retention Period" for logs cleanup only affects the plugin\'s cleanup system, as Action Scheduler does not have its own mechanism for cleaning logs.', 'ws-action-scheduler-cleaner'); ?></p>
                <div class="wsacsc-scheduling-options">
                    <div class="wsacsc-scheduling-group">
                        <h3><?php esc_html_e('Action Statuses', 'ws-action-scheduler-cleaner'); ?></h3>
                        <div class="wsacsc-scheduling-option">
                            <label for="actions-schedule-interval"><?php esc_html_e('Cleanup Schedule:', 'ws-action-scheduler-cleaner'); ?></label>
                            <div class="input-wrapper">
                                <input type="number" id="actions-schedule-interval" min="0" max="365" value="<?php echo esc_attr(get_option('wsacsc_actions_schedule_interval', '')); ?>">
                                <span><?php esc_html_e('days', 'ws-action-scheduler-cleaner'); ?></span>
                            </div>
                            <p class="description"><?php esc_html_e('How often to run cleanup (empty or 0 = disabled, 1-365 days).', 'ws-action-scheduler-cleaner'); ?></p>
                        </div>
                        <div class="wsacsc-scheduling-option">
                            <label for="actions-schedule-time"><?php esc_html_e('Schedule Time:', 'ws-action-scheduler-cleaner'); ?></label>
                            <div class="input-wrapper">
                                <input type="time" id="actions-schedule-time" value="<?php echo esc_attr(get_option('wsacsc_actions_schedule_time', '')); ?>">
                            </div>
                            <p class="description"><?php esc_html_e('Time of day to run cleanup (uses WordPress timezone).', 'ws-action-scheduler-cleaner'); ?></p>
                        </div>
                        <div class="wsacsc-scheduling-option">
                            <label for="actions-retention"><?php esc_html_e('Retention Period:', 'ws-action-scheduler-cleaner'); ?></label>
                            <div class="input-wrapper">
                                <input type="number" id="actions-retention" min="0" max="365" required value="<?php echo esc_attr(get_option('wsacsc_actions_retention', '30')); ?>">
                                <span><?php esc_html_e('days', 'ws-action-scheduler-cleaner'); ?></span>
                            </div>
                            <p class="description"><?php esc_html_e('Delete action statuses older than this (0 = delete all matching statuses, required field).', 'ws-action-scheduler-cleaner'); ?></p>
                        </div>
                    </div>
                    <div class="wsacsc-scheduling-group">
                        <h3><?php esc_html_e('Logs', 'ws-action-scheduler-cleaner'); ?></h3>
                        <div class="wsacsc-scheduling-option">
                            <label for="logs-schedule-interval"><?php esc_html_e('Cleanup Schedule:', 'ws-action-scheduler-cleaner'); ?></label>
                            <div class="input-wrapper">
                                <input type="number" id="logs-schedule-interval" min="0" max="365" value="<?php echo esc_attr(get_option('wsacsc_logs_schedule_interval', '')); ?>">
                                <span><?php esc_html_e('days', 'ws-action-scheduler-cleaner'); ?></span>
                            </div>
                            <p class="description"><?php esc_html_e('How often to run cleanup (empty or 0 = disabled, 1-365 days).', 'ws-action-scheduler-cleaner'); ?></p>
                        </div>
                        <div class="wsacsc-scheduling-option">
                            <label for="logs-schedule-time"><?php esc_html_e('Schedule Time:', 'ws-action-scheduler-cleaner'); ?></label>
                            <div class="input-wrapper">
                                <input type="time" id="logs-schedule-time" value="<?php echo esc_attr(get_option('wsacsc_logs_schedule_time', '')); ?>">
                            </div>
                            <p class="description"><?php esc_html_e('Time of day to run cleanup (uses WordPress timezone).', 'ws-action-scheduler-cleaner'); ?></p>
                        </div>
                        <div class="wsacsc-scheduling-option">
                            <label for="logs-retention"><?php esc_html_e('Retention Period:', 'ws-action-scheduler-cleaner'); ?></label>
                            <div class="input-wrapper">
                                <input type="number" id="logs-retention" min="0" max="365" required value="<?php echo esc_attr(get_option('wsacsc_logs_retention', '30')); ?>">
                                <span><?php esc_html_e('days', 'ws-action-scheduler-cleaner'); ?></span>
                            </div>
                            <p class="description"><?php esc_html_e('Delete logs older than this (0 = delete all logs, required field).', 'ws-action-scheduler-cleaner'); ?></p>
                        </div>
                    </div>
                </div>
                <button id="save-schedule" class="button button-secondary"><?php esc_html_e('Save Schedule', 'ws-action-scheduler-cleaner'); ?></button>
                <div id="schedule-status-message" class="wsacsc-message"></div>
            </div>
            <div class="wsacsc-powered-by">
                <a href="https://www.winning-solutions.de/" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo esc_url(plugin_dir_url(WSACSC_PLUGIN_FILE) . 'assets/images/ws-icon.png'); ?>" alt="Winning Solutions Icon" width="16" height="16">
                    <?php esc_html_e('Powered by Winning Solutions', 'ws-action-scheduler-cleaner'); ?>
                </a>
            </div>
        </div>
        <?php
    }
}
