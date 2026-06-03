<?php
/**
 * Admin interface for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
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
			'plugin_action_links_' . plugin_basename( WSACSC_PLUGIN_FILE ),
			array( __CLASS__, 'add_plugin_links' )
		);
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'load_translations_locally' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Add settings link to plugins page
	 *
	 * @param array $links Existing links
	 * @return array
	 */
	public static function add_plugin_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=ws-action-scheduler-cleaner' ) ),
			esc_html__( 'Settings', 'ws-action-scheduler-cleaner' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Load local translations
	 *
	 * @param string $mofile MO file path
	 * @param string $domain Text domain
	 * @return string
	 */
	public static function load_translations_locally( $mofile, $domain ) {
		if ( 'ws-action-scheduler-cleaner' === $domain && false !== strpos( $mofile, WP_LANG_DIR . '/plugins/' ) ) {
			$locale = apply_filters( 'plugin_locale', determine_locale(), $domain );
			$mofile = WP_PLUGIN_DIR . '/' . dirname( plugin_basename( WSACSC_PLUGIN_FILE ) ) . '/languages/' . $domain . '-' . $locale . '.mo';
		}
		return $mofile;
	}

	/**
	 * Add menu item
	 *
	 * @return string|false
	 */
	public static function menu() {
		$hook = add_submenu_page(
			'tools.php',
			__( 'WS Action Scheduler Cleaner', 'ws-action-scheduler-cleaner' ),
			__( 'WS AS Cleaner', 'ws-action-scheduler-cleaner' ),
			'manage_options',
			'ws-action-scheduler-cleaner',
			array( __CLASS__, 'page' )
		);
		if ( false !== $hook ) {
			add_action( "admin_print_styles-$hook", array( __CLASS__, 'admin_styles' ) );
		}
		return $hook;
	}

	/**
	 * Enqueue admin styles
	 */
	public static function admin_styles(): void {
		wp_enqueue_style( 'wp-admin' );
	}

	/**
	 * Enqueue scripts and styles
	 *
	 * @param string $hook Current admin page hook
	 */
	public static function assets( string $hook ): void {
		if ( 'tools_page_ws-action-scheduler-cleaner' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script(
			'wsacsc-js',
			WSACSC_PLUGIN_URL . 'assets/js/ws-as-cleaner.js',
			array( 'jquery' ),
			WSACSC_VERSION,
			true
		);
		wp_enqueue_style(
			'wsacsc-css',
			WSACSC_PLUGIN_URL . 'assets/css/ws-as-cleaner.css',
			array(),
			WSACSC_VERSION
		);
		wp_localize_script(
			'wsacsc-js',
			'wsacsc_cleaner',
			array(
				'ajax_url'                      => admin_url( 'admin-ajax.php' ),
				'nonce'                         => wp_create_nonce( 'wsacsc_nonce' ),
				'select_status_message'         => __( 'Please select at least one status to clear.', 'ws-action-scheduler-cleaner' ),
				'clearing_message'              => __( 'Clearing in progress...', 'ws-action-scheduler-cleaner' ),
				'in_progress_message'           => __( 'Please wait...', 'ws-action-scheduler-cleaner' ),
				'error_message'                 => __( 'An error occurred. Please try again.', 'ws-action-scheduler-cleaner' ),
				'success_actions_cleared'       => __( 'Selected actions cleared successfully!', 'ws-action-scheduler-cleaner' ),
				'success_logs_cleared'          => __( 'Logs cleared successfully!', 'ws-action-scheduler-cleaner' ),
				'success_schedule_saved'        => __( 'Schedule saved successfully!', 'ws-action-scheduler-cleaner' ),
				'success_statuses_saved'        => __( 'Statuses saved successfully!', 'ws-action-scheduler-cleaner' ),
				'optimizing_message'            => __( 'Optimizing table...', 'ws-action-scheduler-cleaner' ),
				'updating_text'                 => __( 'Loading...', 'ws-action-scheduler-cleaner' ),
				'table_optimization_failed'     => __( 'Table optimization failed.', 'ws-action-scheduler-cleaner' ),
				'validation_fix_fields_message' => __( 'Please fix the highlighted fields before saving.', 'ws-action-scheduler-cleaner' ),
				'refresh_actions_label'         => __( 'Refresh actions table size', 'ws-action-scheduler-cleaner' ),
				'refresh_logs_label'            => __( 'Refresh logs table size', 'ws-action-scheduler-cleaner' ),
				'retrying_message'              => __( 'Retrying…', 'ws-action-scheduler-cleaner' ),
			)
		);
	}

	/**
	 * Create the admin page
	 */
	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		nocache_headers();

		if ( ! WSACSC_Database::check_tables_exist() ) {
			echo '<div class="wrap wsacsc-cleaner">';
			echo '<h1>' . esc_html__( 'WS Action Scheduler Cleaner', 'ws-action-scheduler-cleaner' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Action Scheduler tables do not exist. Please ensure Action Scheduler is properly installed and activated.', 'ws-action-scheduler-cleaner' ) . '</p></div>';
			echo '</div>';
			return;
		}
		?>
		<div class="wrap wsacsc-cleaner">
			<h1><?php esc_html_e( 'WS Action Scheduler Cleaner', 'ws-action-scheduler-cleaner' ); ?></h1>
			<div id="general-status-message" class="wsacsc-message wsacsc-is-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
			<div class="wsacsc-info">
				<p><?php esc_html_e( 'This tool allows you to clean up the Action Scheduler tables in your WordPress database. Action Scheduler is a library used by many plugins to schedule background tasks.', 'ws-action-scheduler-cleaner' ); ?></p>
				<p><?php esc_html_e( 'The Action Scheduler uses two main tables:', 'ws-action-scheduler-cleaner' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Actions Table: Stores scheduled actions and their statuses.', 'ws-action-scheduler-cleaner' ); ?></li>
					<li><?php esc_html_e( 'Logs Table: Stores logs of action executions.', 'ws-action-scheduler-cleaner' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'Clearing these tables is generally safe and can improve database performance. However, please note:', 'ws-action-scheduler-cleaner' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Clearing can take a while depending on the size of the tables.', 'ws-action-scheduler-cleaner' ); ?></li>
					<li><?php esc_html_e( 'Cleared data will be permanently deleted.', 'ws-action-scheduler-cleaner' ); ?></li>
					<li><?php echo wp_kses( __( 'It\'s recommended to <strong>make a backup</strong> before proceeding.', 'ws-action-scheduler-cleaner' ), array( 'strong' => array() ) ); ?></li>
					<li><?php esc_html_e( 'Clearing completed, failed, or canceled actions usually doesn\'t affect WordPress functionality.', 'ws-action-scheduler-cleaner' ); ?></li>
				</ul>
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to Action Scheduler admin page */
							__( 'For more information, visit the <a href="%s">Action Scheduler admin page</a>.', 'ws-action-scheduler-cleaner' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( admin_url( 'tools.php?page=action-scheduler' ) )
					);
					?>
				</p>
			</div>
			<div class="wsacsc-stats">
				<h2><?php esc_html_e( 'Current Table Sizes:', 'ws-action-scheduler-cleaner' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'Actions table:', 'ws-action-scheduler-cleaner' ); ?>
						<span id="actions-count" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Loading...', 'ws-action-scheduler-cleaner' ); ?></span>
						<?php esc_html_e( 'rows', 'ws-action-scheduler-cleaner' ); ?>
						(<span id="actions-size" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Loading...', 'ws-action-scheduler-cleaner' ); ?></span>)
						<button type="button" class="button-link wsacsc-refresh wsacsc-refresh-actions" aria-label="<?php esc_attr_e( 'Refresh actions table size', 'ws-action-scheduler-cleaner' ); ?>">
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
						</button>
					</li>
					<li><?php esc_html_e( 'Logs table:', 'ws-action-scheduler-cleaner' ); ?>
						<span id="logs-count" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Loading...', 'ws-action-scheduler-cleaner' ); ?></span>
						<?php esc_html_e( 'rows', 'ws-action-scheduler-cleaner' ); ?>
						(<span id="logs-size" aria-live="polite" aria-atomic="true"><?php esc_html_e( 'Loading...', 'ws-action-scheduler-cleaner' ); ?></span>)
						<button type="button" class="button-link wsacsc-refresh wsacsc-refresh-logs" aria-label="<?php esc_attr_e( 'Refresh logs table size', 'ws-action-scheduler-cleaner' ); ?>">
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
						</button>
					</li>
				</ul>
			</div>
			<div class="wsacsc-section">
				<h2><?php esc_html_e( 'Clear Action Statuses', 'ws-action-scheduler-cleaner' ); ?></h2>
				<fieldset class="wsacsc-options">
					<legend class="screen-reader-text"><?php esc_html_e( 'Action statuses to clear', 'ws-action-scheduler-cleaner' ); ?></legend>
					<p id="wsacsc-status-options-desc"><?php esc_html_e( 'Select which statuses to remove when clearing manually, when this plugin runs scheduled action cleanup, and (if this plugin’s action cleanup schedule is disabled) which statuses Action Scheduler may delete in the background:', 'ws-action-scheduler-cleaner' ); ?></p>
					<?php
					$selected_statuses = get_option( 'wsacsc_selected_statuses', array( 'complete', 'failed', 'canceled' ) );
					if ( ! is_array( $selected_statuses ) ) {
						$selected_statuses = array( 'complete', 'failed', 'canceled' );
					}
					?>
					<label for="wsacsc-status-complete"><input type="checkbox" id="wsacsc-status-complete" name="status[]" value="complete" <?php checked( in_array( 'complete', $selected_statuses, true ) ); ?> aria-describedby="wsacsc-status-options-desc"> <?php esc_html_e( 'Complete', 'ws-action-scheduler-cleaner' ); ?></label>
					<label for="wsacsc-status-failed"><input type="checkbox" id="wsacsc-status-failed" name="status[]" value="failed" <?php checked( in_array( 'failed', $selected_statuses, true ) ); ?> aria-describedby="wsacsc-status-options-desc"> <?php esc_html_e( 'Failed', 'ws-action-scheduler-cleaner' ); ?></label>
					<label for="wsacsc-status-canceled"><input type="checkbox" id="wsacsc-status-canceled" name="status[]" value="canceled" <?php checked( in_array( 'canceled', $selected_statuses, true ) ); ?> aria-describedby="wsacsc-status-options-desc"> <?php esc_html_e( 'Canceled', 'ws-action-scheduler-cleaner' ); ?></label>
				</fieldset>
				<button type="button" id="clear-actions" class="button button-primary"><?php esc_html_e( 'Clear Selected Actions', 'ws-action-scheduler-cleaner' ); ?></button>
				<div id="actions-status-message" class="wsacsc-message" role="status" aria-live="polite" aria-atomic="true"></div>
				<div id="status-save-message" class="wsacsc-message wsacsc-is-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
			</div>
			<div class="wsacsc-section">
				<h2><?php esc_html_e( 'Clear Logs Table', 'ws-action-scheduler-cleaner' ); ?></h2>
				<p><?php esc_html_e( 'This will clear all entries in the logs table.', 'ws-action-scheduler-cleaner' ); ?></p>
				<button type="button" id="clear-logs" class="button button-primary"><?php esc_html_e( 'Clear Logs', 'ws-action-scheduler-cleaner' ); ?></button>
				<div id="logs-status-message" class="wsacsc-message" role="status" aria-live="polite" aria-atomic="true"></div>
			</div>
			<div class="wsacsc-section">
				<h2><?php esc_html_e( 'Optimize Tables', 'ws-action-scheduler-cleaner' ); ?></h2>
				<p><?php esc_html_e( 'Optimize the database tables to reclaim unused space and potentially improve performance.', 'ws-action-scheduler-cleaner' ); ?></p>
				<div class="wsacsc-optimize-buttons">
					<button type="button" id="optimize-actions" class="button button-primary"><?php esc_html_e( 'Optimize Actions Table', 'ws-action-scheduler-cleaner' ); ?></button>
					<button type="button" id="optimize-logs" class="button button-primary"><?php esc_html_e( 'Optimize Logs Table', 'ws-action-scheduler-cleaner' ); ?></button>
				</div>
				<div id="optimize-status-message" class="wsacsc-message wsacsc-is-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
			</div>
			<div class="wsacsc-section">
				<h2><?php esc_html_e( 'Scheduling Options', 'ws-action-scheduler-cleaner' ); ?></h2>
				<p><?php esc_html_e( 'Finished actions can be removed automatically in two ways: this plugin’s scheduled cleanup (settings below) and Action Scheduler’s own built-in background cleanup for the actions table. The status checkboxes in “Clear Action Statuses” above define which statuses are affected—manual clearing, this plugin’s scheduled action cleanup, and (when this plugin’s action cleanup schedule is disabled) Action Scheduler’s background deletions.', 'ws-action-scheduler-cleaner' ); ?></p>
				<p><?php esc_html_e( 'Cleanup schedule and retention period are separate. Cleanup schedule controls how often this plugin runs its own WordPress cron jobs (1–365 days, or empty/0 to disable). Retention period controls how old entries must be before they are deleted. For actions, age is measured by completion or last attempt time—not the original scheduled date.', 'ws-action-scheduler-cleaner' ); ?></p>
				<p><?php esc_html_e( 'If an actions cleanup schedule is enabled, only this plugin’s cron job deletes old actions (using the selected statuses above); Action Scheduler’s built-in action cleanup is turned off to avoid duplicate deletions. If that schedule is disabled, this plugin does not run scheduled action cleanup—the actions retention period is passed to Action Scheduler instead, which deletes actions in the selected statuses when they are older than that many days.', 'ws-action-scheduler-cleaner' ); ?></p>
				<p><?php esc_html_e( 'Logs cleanup schedule and logs retention apply only to this plugin; Action Scheduler does not clean the logs table on its own.', 'ws-action-scheduler-cleaner' ); ?></p>
				<p><?php esc_html_e( 'This plugin does not hide status filters on the Action Scheduler admin screen. Tabs such as Complete or Failed only appear when actions with those statuses exist in the database. On sites with a very large pending backlog, few actions may finish; if counts stay at zero, those tabs will not show.', 'ws-action-scheduler-cleaner' ); ?></p>
				<div class="wsacsc-scheduling-options">
					<div class="wsacsc-scheduling-group">
						<h3><?php esc_html_e( 'Action Statuses', 'ws-action-scheduler-cleaner' ); ?></h3>
						<div class="wsacsc-scheduling-option">
							<label for="actions-schedule-interval"><?php esc_html_e( 'Cleanup Schedule:', 'ws-action-scheduler-cleaner' ); ?></label>
							<div class="input-wrapper">
								<input type="number" id="actions-schedule-interval" min="0" max="365" value="<?php echo esc_attr( get_option( 'wsacsc_actions_schedule_interval', '' ) ); ?>" aria-describedby="schedule-status-message">
								<span><?php esc_html_e( 'days', 'ws-action-scheduler-cleaner' ); ?></span>
							</div>
							<p class="description"><?php esc_html_e( 'This plugin only: how often its WordPress cron job deletes old actions (empty or 0 = disabled, 1–365 days). When enabled, Action Scheduler’s built-in action cleanup is disabled. This is not the retention period.', 'ws-action-scheduler-cleaner' ); ?></p>
						</div>
						<div class="wsacsc-scheduling-option">
							<label for="actions-schedule-time"><?php esc_html_e( 'Schedule Time:', 'ws-action-scheduler-cleaner' ); ?></label>
							<div class="input-wrapper">
								<input type="time" id="actions-schedule-time" value="<?php echo esc_attr( get_option( 'wsacsc_actions_schedule_time', '' ) ); ?>" aria-describedby="schedule-status-message">
							</div>
							<p class="description"><?php esc_html_e( 'This plugin only: time of day for its action cleanup job (WordPress timezone).', 'ws-action-scheduler-cleaner' ); ?></p>
						</div>
						<div class="wsacsc-scheduling-option">
							<label for="actions-retention"><?php esc_html_e( 'Retention Period:', 'ws-action-scheduler-cleaner' ); ?></label>
							<div class="input-wrapper">
								<input type="number" id="actions-retention" min="0" max="365" required value="<?php echo esc_attr( get_option( 'wsacsc_actions_retention', '30' ) ); ?>" aria-describedby="schedule-status-message">
								<span><?php esc_html_e( 'days', 'ws-action-scheduler-cleaner' ); ?></span>
							</div>
							<p class="description"><?php esc_html_e( 'How long to keep actions before deletion (by completion or last attempt time, not scheduled date), for the statuses selected above. With a plugin action cleanup schedule enabled: used only by this plugin’s cron job. With that schedule disabled: passed to Action Scheduler’s background cleanup for the same selected statuses. 0 = delete all matching actions on every cleanup run. Required.', 'ws-action-scheduler-cleaner' ); ?></p>
						</div>
					</div>
					<div class="wsacsc-scheduling-group">
						<h3><?php esc_html_e( 'Logs', 'ws-action-scheduler-cleaner' ); ?></h3>
						<div class="wsacsc-scheduling-option">
							<label for="logs-schedule-interval"><?php esc_html_e( 'Cleanup Schedule:', 'ws-action-scheduler-cleaner' ); ?></label>
							<div class="input-wrapper">
								<input type="number" id="logs-schedule-interval" min="0" max="365" value="<?php echo esc_attr( get_option( 'wsacsc_logs_schedule_interval', '' ) ); ?>" aria-describedby="schedule-status-message">
								<span><?php esc_html_e( 'days', 'ws-action-scheduler-cleaner' ); ?></span>
							</div>
							<p class="description"><?php esc_html_e( 'This plugin only: how often its WordPress cron job deletes old log entries (empty or 0 = disabled, 1–365 days). Action Scheduler does not clean logs automatically. This is not the retention period.', 'ws-action-scheduler-cleaner' ); ?></p>
						</div>
						<div class="wsacsc-scheduling-option">
							<label for="logs-schedule-time"><?php esc_html_e( 'Schedule Time:', 'ws-action-scheduler-cleaner' ); ?></label>
							<div class="input-wrapper">
								<input type="time" id="logs-schedule-time" value="<?php echo esc_attr( get_option( 'wsacsc_logs_schedule_time', '' ) ); ?>" aria-describedby="schedule-status-message">
							</div>
							<p class="description"><?php esc_html_e( 'This plugin only: time of day for its log cleanup job (WordPress timezone).', 'ws-action-scheduler-cleaner' ); ?></p>
						</div>
						<div class="wsacsc-scheduling-option">
							<label for="logs-retention"><?php esc_html_e( 'Retention Period:', 'ws-action-scheduler-cleaner' ); ?></label>
							<div class="input-wrapper">
								<input type="number" id="logs-retention" min="0" max="365" required value="<?php echo esc_attr( get_option( 'wsacsc_logs_retention', '30' ) ); ?>" aria-describedby="schedule-status-message">
								<span><?php esc_html_e( 'days', 'ws-action-scheduler-cleaner' ); ?></span>
							</div>
							<p class="description"><?php esc_html_e( 'This plugin only: delete log entries older than this many days (0 = delete all logs on each cleanup run). Required.', 'ws-action-scheduler-cleaner' ); ?></p>
						</div>
					</div>
				</div>
				<button type="button" id="save-schedule" class="button button-secondary"><?php esc_html_e( 'Save Schedule', 'ws-action-scheduler-cleaner' ); ?></button>
				<div id="schedule-status-message" class="wsacsc-message" role="status" aria-live="polite" aria-atomic="true"></div>
			</div>
			<div class="wsacsc-powered-by">
				<a href="https://www.winning-solutions.de/" target="_blank" rel="noopener noreferrer">
					<img src="<?php echo esc_url( WSACSC_PLUGIN_URL . 'assets/images/ws-icon.png' ); ?>" alt="" width="16" height="16">
					<?php esc_html_e( 'Powered by Winning Solutions', 'ws-action-scheduler-cleaner' ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
