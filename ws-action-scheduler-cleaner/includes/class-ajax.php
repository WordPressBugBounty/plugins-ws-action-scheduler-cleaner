<?php
/**
 * AJAX handlers for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
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
		add_action( 'wp_ajax_wsacsc_get_table_sizes', array( __CLASS__, 'get_table_sizes' ) );
		add_action( 'wp_ajax_wsacsc_clear_actions', array( __CLASS__, 'clear_actions' ) );
		add_action( 'wp_ajax_wsacsc_clear_logs', array( __CLASS__, 'clear_logs' ) );
		add_action( 'wp_ajax_wsacsc_save_schedule', array( __CLASS__, 'save_schedule' ) );
		add_action( 'wp_ajax_wsacsc_save_selected_statuses', array( __CLASS__, 'save_selected_statuses' ) );
		add_action( 'wp_ajax_wsacsc_get_single_table_size', array( __CLASS__, 'get_single_table_size' ) );
		add_action( 'wp_ajax_wsacsc_optimize_table', array( __CLASS__, 'optimize_table' ) );
		add_action( 'wp_ajax_wsacsc_check_cleanup_progress', array( __CLASS__, 'check_cleanup_progress' ) );
	}

	/**
	 * Send error when another destructive job is already running.
	 */
	private static function reject_concurrent_job(): void {
		wp_send_json_error(
			array(
				'message' => __( 'Another cleanup or optimization is already running. Please wait until it finishes.', 'ws-action-scheduler-cleaner' ),
				'code'    => 'job_locked',
			)
		);
	}

	/**
	 * Build JSON payload for an in-progress cleanup response.
	 *
	 * @param string $cleanup_id    Cleanup ID.
	 * @param array  $result        Result from process_chunk / process_ajax_batch.
	 * @param string $done_message  Message when completed successfully.
	 * @return array<string, mixed>
	 */
	private static function build_cleanup_response( string $cleanup_id, array $result, string $done_message ): array {
		$total_deleted = isset( $result['total_deleted'] ) ? (int) $result['total_deleted'] : 0;

		if ( ! empty( $result['completed'] ) && empty( $result['error'] ) ) {
			return array_merge(
				WSACSC_Database::get_table_size_data( true ),
				array(
					'message'       => $done_message,
					'cleanup_id'    => $cleanup_id,
					'completed'     => true,
					'total_deleted' => $total_deleted,
				)
			);
		}

		return array(
			'message'       => wsacsc_format_cleanup_progress_message( $total_deleted ),
			'cleanup_id'    => $cleanup_id,
			'completed'     => false,
			'total_deleted' => $total_deleted,
		);
	}

	/**
	 * Get table sizes
	 */
	public static function get_table_sizes(): void {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		check_ajax_referer( 'wsacsc_nonce', 'nonce' );

		wp_send_json_success( WSACSC_Database::get_table_size_data() );
	}

	/**
	 * Clear actions
	 */
	public static function clear_actions(): void {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		check_ajax_referer( 'wsacsc_nonce', 'nonce' );

		global $wpdb;
		$statuses_input = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ? $_POST['statuses'] : array();
		$statuses       = array_map( 'sanitize_text_field', wp_unslash( $statuses_input ) );

		$allowed_statuses = wsacsc_get_allowed_action_statuses();
		$statuses         = array_intersect( $statuses, $allowed_statuses );

		if ( empty( $statuses ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select at least one status to clear.', 'ws-action-scheduler-cleaner' ) ) );
		}

		if ( ! WSACSC_Database::check_tables_exist() ) {
			wp_send_json_error( array( 'message' => __( 'Action Scheduler tables do not exist.', 'ws-action-scheduler-cleaner' ) ) );
		}

		$cleanup_id = 'actions_' . wp_generate_password( 12, false );

		if ( ! WSACSC_Cleanup::acquire_job_lock( $cleanup_id, 'cleanup', $wpdb->prefix . 'actionscheduler_actions', true ) ) {
			self::reject_concurrent_job();
		}

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$placeholders  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$where_clause  = 'status IN (' . $placeholders . ')';
		$where_params  = $statuses;

		WSACSC_Cleanup::create_progress_job( $cleanup_id, $actions_table, $where_clause, $where_params, 'ajax' );

		$result = WSACSC_Cleanup::process_chunk( $cleanup_id, 'ajax' );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'An error occurred while clearing actions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		wp_send_json_success(
			self::build_cleanup_response(
				$cleanup_id,
				$result,
				__( 'Selected actions cleared successfully!', 'ws-action-scheduler-cleaner' )
			)
		);
	}

	/**
	 * Clear logs
	 */
	public static function clear_logs(): void {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		check_ajax_referer( 'wsacsc_nonce', 'nonce' );

		if ( ! WSACSC_Database::check_tables_exist() ) {
			wp_send_json_error( array( 'message' => __( 'Action Scheduler tables do not exist.', 'ws-action-scheduler-cleaner' ) ) );
		}

		global $wpdb;
		$logs_table   = $wpdb->prefix . 'actionscheduler_logs';
		$where_clause = '1=1';
		$where_params = array();

		$cleanup_id = 'logs_' . wp_generate_password( 12, false );

		if ( ! WSACSC_Cleanup::acquire_job_lock( $cleanup_id, 'cleanup', $logs_table, true ) ) {
			self::reject_concurrent_job();
		}

		WSACSC_Cleanup::create_progress_job( $cleanup_id, $logs_table, $where_clause, $where_params, 'ajax' );

		$result = WSACSC_Cleanup::process_chunk( $cleanup_id, 'ajax' );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'An error occurred while clearing logs.', 'ws-action-scheduler-cleaner' ) ) );
		}

		wp_send_json_success(
			self::build_cleanup_response(
				$cleanup_id,
				$result,
				__( 'Logs cleared successfully!', 'ws-action-scheduler-cleaner' )
			)
		);
	}

	/**
	 * Save schedule
	 */
	public static function save_schedule(): void {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		check_ajax_referer( 'wsacsc_nonce', 'nonce' );

		$actions_schedule_interval = isset( $_POST['actions_schedule_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['actions_schedule_interval'] ) ) : '';
		$actions_schedule_time     = isset( $_POST['actions_schedule_time'] ) ? sanitize_text_field( wp_unslash( $_POST['actions_schedule_time'] ) ) : '';
		$actions_retention         = isset( $_POST['actions_retention'] ) ? sanitize_text_field( wp_unslash( $_POST['actions_retention'] ) ) : '';

		$logs_schedule_interval = isset( $_POST['logs_schedule_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['logs_schedule_interval'] ) ) : '';
		$logs_schedule_time     = isset( $_POST['logs_schedule_time'] ) ? sanitize_text_field( wp_unslash( $_POST['logs_schedule_time'] ) ) : '';
		$logs_retention         = isset( $_POST['logs_retention'] ) ? sanitize_text_field( wp_unslash( $_POST['logs_retention'] ) ) : '';

		if ( $actions_schedule_interval === '0' ) {
			$actions_schedule_interval = '';
		}
		if ( $logs_schedule_interval === '0' ) {
			$logs_schedule_interval = '';
		}

		if ( $actions_schedule_interval !== '' && ( ! ctype_digit( (string) $actions_schedule_interval ) || (int) $actions_schedule_interval < 1 || (int) $actions_schedule_interval > 365 ) ) {
			wp_send_json_error( array( 'message' => __( 'Actions schedule interval must be empty or between 1 and 365 days.', 'ws-action-scheduler-cleaner' ) ) );
		}

		if ( $logs_schedule_interval !== '' && ( ! ctype_digit( (string) $logs_schedule_interval ) || (int) $logs_schedule_interval < 1 || (int) $logs_schedule_interval > 365 ) ) {
			wp_send_json_error( array( 'message' => __( 'Logs schedule interval must be empty or between 1 and 365 days.', 'ws-action-scheduler-cleaner' ) ) );
		}

		if ( $actions_schedule_time !== '' && ! preg_match( '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $actions_schedule_time ) ) {
			wp_send_json_error( array( 'message' => __( 'Actions schedule time must be in HH:MM format.', 'ws-action-scheduler-cleaner' ) ) );
		}

		if ( $logs_schedule_time !== '' && ! preg_match( '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $logs_schedule_time ) ) {
			wp_send_json_error( array( 'message' => __( 'Logs schedule time must be in HH:MM format.', 'ws-action-scheduler-cleaner' ) ) );
		}

		if ( $actions_retention === '' || ! ctype_digit( (string) $actions_retention ) || (int) $actions_retention < 0 || (int) $actions_retention > 365 ) {
			wp_send_json_error( array( 'message' => __( 'Actions retention period is required and must be between 0 and 365 days.', 'ws-action-scheduler-cleaner' ) ) );
		}

		if ( $logs_retention === '' || ! ctype_digit( (string) $logs_retention ) || (int) $logs_retention < 0 || (int) $logs_retention > 365 ) {
			wp_send_json_error( array( 'message' => __( 'Logs retention period is required and must be between 0 and 365 days.', 'ws-action-scheduler-cleaner' ) ) );
		}

		$options_to_save = array(
			'wsacsc_actions_schedule_interval' => $actions_schedule_interval,
			'wsacsc_actions_schedule_time'     => $actions_schedule_time,
			'wsacsc_actions_retention'         => $actions_retention,
			'wsacsc_logs_schedule_interval'    => $logs_schedule_interval,
			'wsacsc_logs_schedule_time'        => $logs_schedule_time,
			'wsacsc_logs_retention'            => $logs_retention,
		);

		$backup = array();
		foreach ( $options_to_save as $option_name => $value ) {
			$backup[ $option_name ] = get_option( $option_name, '' );
		}
		set_transient( 'wsacsc_save_backup', $backup, HOUR_IN_SECONDS );

		$save_success = false;
		$max_attempts = 2;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			update_option( 'wsacsc_actions_schedule_interval', $actions_schedule_interval, 'no' );
			update_option( 'wsacsc_actions_schedule_time', $actions_schedule_time, 'no' );
			update_option( 'wsacsc_actions_retention', $actions_retention );
			update_option( 'wsacsc_logs_schedule_interval', $logs_schedule_interval, 'no' );
			update_option( 'wsacsc_logs_schedule_time', $logs_schedule_time, 'no' );
			update_option( 'wsacsc_logs_retention', $logs_retention );

			wp_cache_delete( 'wsacsc_actions_schedule_interval', 'options' );
			wp_cache_delete( 'wsacsc_actions_schedule_time', 'options' );
			wp_cache_delete( 'wsacsc_actions_retention', 'options' );
			wp_cache_delete( 'wsacsc_logs_schedule_interval', 'options' );
			wp_cache_delete( 'wsacsc_logs_schedule_time', 'options' );
			wp_cache_delete( 'wsacsc_logs_retention', 'options' );
			wp_cache_delete( 'alloptions', 'options' );

			wp_cache_flush_group( 'options' );

			usleep( 50000 );

			$validation_passed      = true;
			$actions_interval_check = get_option( 'wsacsc_actions_schedule_interval', '' );
			$actions_time_check     = get_option( 'wsacsc_actions_schedule_time', '' );
			$logs_interval_check    = get_option( 'wsacsc_logs_schedule_interval', '' );
			$logs_time_check        = get_option( 'wsacsc_logs_schedule_time', '' );

			if ( $actions_interval_check !== $actions_schedule_interval ) {
				$validation_passed = false;
			}
			if ( $actions_time_check !== $actions_schedule_time ) {
				$validation_passed = false;
			}
			if ( $logs_interval_check !== $logs_schedule_interval ) {
				$validation_passed = false;
			}
			if ( $logs_time_check !== $logs_schedule_time ) {
				$validation_passed = false;
			}

			if ( $validation_passed ) {
				$save_success = true;
				break;
			}

			if ( $attempt < $max_attempts ) {
				wp_cache_delete( 'wsacsc_actions_schedule_interval', 'options' );
				wp_cache_delete( 'wsacsc_actions_schedule_time', 'options' );
				wp_cache_delete( 'wsacsc_logs_schedule_interval', 'options' );
				wp_cache_delete( 'wsacsc_logs_schedule_time', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
		}

		if ( ! $save_success ) {
			$backup_restore = get_transient( 'wsacsc_save_backup' );
			if ( false !== $backup_restore && is_array( $backup_restore ) ) {
				foreach ( $backup_restore as $option_name => $value ) {
					update_option( $option_name, $value, 'no' );
				}
				wp_cache_delete( 'alloptions', 'options' );
			}
			delete_transient( 'wsacsc_save_backup' );
			wp_send_json_error( array( 'message' => __( 'Failed to save schedule. Please try again.', 'ws-action-scheduler-cleaner' ) ) );
		}

		delete_transient( 'wsacsc_save_backup' );

		WSACSC_Scheduler::winningsolutions_cleanup_broken_hooks( array( 'wsacsc_cleanup_logs', 'wsacsc_cleanup_actions' ) );

		WSACSC_Scheduler::maybe_schedule_cleanup();
		WSACSC_Scheduler::verify_cron_health();
		wp_send_json_success( array( 'message' => __( 'Schedule saved successfully!', 'ws-action-scheduler-cleaner' ) ) );
	}

	/**
	 * Save selected statuses
	 */
	public static function save_selected_statuses(): void {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		check_ajax_referer( 'wsacsc_nonce', 'nonce' );

		$statuses_input = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ? $_POST['statuses'] : array();
		$statuses       = array_map( 'sanitize_text_field', wp_unslash( $statuses_input ) );
		$statuses       = array_intersect( $statuses, wsacsc_get_allowed_action_statuses() );

		if ( empty( $statuses ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select at least one status to clear.', 'ws-action-scheduler-cleaner' ) ) );
		}

		update_option( 'wsacsc_selected_statuses', $statuses );

		wp_cache_delete( 'wsacsc_selected_statuses', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		wp_send_json_success( array( 'message' => __( 'Statuses saved successfully!', 'ws-action-scheduler-cleaner' ) ) );
	}

	/**
	 * Get single table size
	 */
	public static function get_single_table_size(): void {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		check_ajax_referer( 'wsacsc_nonce', 'nonce' );

		$table_type = isset( $_POST['table_type'] ) ? sanitize_text_field( wp_unslash( $_POST['table_type'] ) ) : '';
		if ( ! in_array( $table_type, array( 'actions', 'logs' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid table type.', 'ws-action-scheduler-cleaner' ) ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'actionscheduler_' . $table_type;

		$count_query = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name );
		$count       = $wpdb->get_var( $count_query );

		$size_query = $wpdb->prepare(
			'SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
            FROM information_schema.TABLES 
            WHERE table_schema = %s AND table_name = %s',
			DB_NAME,
			$table_name
		);
		$size       = $wpdb->get_var( $size_query );

		wp_send_json_success(
			array(
				'count' => number_format( (int) ( $count ?? 0 ) ),
				'size'  => number_format( (float) ( $size ?? 0 ), 2 ) . ' MB',
			)
		);
	}

	/**
	 * Optimize table
	 */
	public static function optimize_table(): void {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		check_ajax_referer( 'wsacsc_nonce', 'nonce' );

		$table_type = isset( $_POST['table_type'] ) ? sanitize_text_field( wp_unslash( $_POST['table_type'] ) ) : '';
		if ( ! in_array( $table_type, array( 'actions', 'logs' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid table type.', 'ws-action-scheduler-cleaner' ) ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'actionscheduler_' . $table_type;
		$cleanup_id = 'optimize_' . $table_type . '_' . wp_generate_password( 8, false );

		if ( ! WSACSC_Cleanup::acquire_job_lock( $cleanup_id, 'optimize', $table_name, true ) ) {
			self::reject_concurrent_job();
		}

		$result = false;
		if ( 'logs' === $table_type ) {
			$result = WSACSC_Cleanup::optimize_logs_table();
		} elseif ( 'actions' === $table_type ) {
			$result = WSACSC_Cleanup::optimize_actions_table();
		}

		WSACSC_Cleanup::release_job_lock( $cleanup_id );

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Table optimization failed.', 'ws-action-scheduler-cleaner' ) ) );
		}

		wp_send_json_success(
			array_merge(
				WSACSC_Database::get_table_size_data( true ),
				array(
					/* translators: %s: table type (actions or logs). */
					'message'    => sprintf( __( '%s table optimized successfully!', 'ws-action-scheduler-cleaner' ), ucfirst( $table_type ) ),
					'table_type' => $table_type,
				)
			)
		);
	}

	/**
	 * Check cleanup progress
	 */
	public static function check_cleanup_progress(): void {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ws-action-scheduler-cleaner' ) ) );
		}

		check_ajax_referer( 'wsacsc_nonce', 'nonce' );

		$cleanup_id = isset( $_POST['cleanup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cleanup_id'] ) ) : '';
		if ( empty( $cleanup_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid cleanup ID.', 'ws-action-scheduler-cleaner' ) ) );
		}

		$progress = WSACSC_Cleanup::load_progress( $cleanup_id );

		if ( false === $progress ) {
			wp_send_json_success(
				array(
					'completed'     => false,
					'stale'         => true,
					'total_deleted' => 0,
					'message'       => __( 'Cleanup progress was lost or is continuing in the background. Refresh the page to check table sizes.', 'ws-action-scheduler-cleaner' ),
				)
			);
		}

		$progress = wsacsc_validate_cleanup_progress( $progress );
		if ( false === $progress ) {
			WSACSC_Cleanup::delete_progress( $cleanup_id );
			WSACSC_Cleanup::release_job_lock( $cleanup_id );
			wp_send_json_error( array( 'message' => __( 'Invalid cleanup progress data.', 'ws-action-scheduler-cleaner' ) ) );
		}

		$result = WSACSC_Cleanup::process_chunk( $cleanup_id, 'ajax' );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'An error occurred during cleanup.', 'ws-action-scheduler-cleaner' ) ) );
		}

		$total_deleted = (int) $result['total_deleted'];

		if ( ! empty( $result['completed'] ) ) {
			wp_send_json_success(
				array_merge(
					WSACSC_Database::get_table_size_data( true ),
					array(
						'completed'     => true,
						'total_deleted' => $total_deleted,
						'message'       => __( 'Cleanup completed successfully!', 'ws-action-scheduler-cleaner' ),
					)
				)
			);
		}

		wp_send_json_success(
			array(
				'completed'     => false,
				'total_deleted' => $total_deleted,
				'message'       => wsacsc_format_cleanup_progress_message( $total_deleted ),
			)
		);
	}
}
