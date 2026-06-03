<?php
/**
 * Cleanup functions for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
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
	 * Transient key for the active destructive job lock.
	 */
	const ACTIVE_JOB_KEY = 'wsacsc_active_job';

	/**
	 * Hook for background cleanup continuation.
	 */
	const CONTINUE_HOOK = 'wsacsc_continue_cleanup';

	/**
	 * Progress / lock TTL in seconds (45 minutes).
	 */
	const JOB_TTL = 2700;

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
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'memory_limit'       => ini_get( 'memory_limit' ),
		);

		@ini_set( 'max_execution_time', '0' );
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 0 );

		return $original_settings;
	}

	/**
	 * Restore original PHP execution time and memory limits
	 *
	 * @param array $original_settings Original settings to restore
	 */
	private static function restore_php_limits( array $original_settings ): void {
		if ( isset( $original_settings['max_execution_time'] ) ) {
			$max_exec_time = $original_settings['max_execution_time'];
			if ( $max_exec_time === false || $max_exec_time === '' ) {
				@ini_set( 'max_execution_time', '30' );
			} else {
				@ini_set( 'max_execution_time', (string) $max_exec_time );
				@set_time_limit( (int) $max_exec_time );
			}
		}

		if ( isset( $original_settings['memory_limit'] ) ) {
			$memory_limit = $original_settings['memory_limit'];
			if ( $memory_limit === false || $memory_limit === '' ) {
				@ini_set( 'memory_limit', '128M' );
			} else {
				@ini_set( 'memory_limit', $memory_limit );
			}
		}
	}

	/**
	 * Initialize cleanup functionality
	 */
	public static function init(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		add_filter( 'cron_memory_limit', array( __CLASS__, 'filter_cron_memory_limit' ) );

		if ( ! has_action( 'wsacsc_cleanup_logs', array( __CLASS__, 'cleanup_logs' ) ) ) {
			add_action( 'wsacsc_cleanup_logs', array( __CLASS__, 'cleanup_logs' ) );
		}

		if ( ! has_action( 'wsacsc_cleanup_actions', array( __CLASS__, 'cleanup_actions' ) ) ) {
			add_action( 'wsacsc_cleanup_actions', array( __CLASS__, 'cleanup_actions' ) );
		}

		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'continue_cleanup' ), 10, 1 );

		self::$hooks_registered = true;
	}

	/**
	 * Chunk tuning for AJAX (faster) vs cron (gentler).
	 *
	 * @param string $context 'ajax' or 'cron'.
	 * @return array{batch_size: int, max_seconds: int, sleep_us: int}
	 */
	public static function get_chunk_config( string $context ): array {
		if ( 'ajax' === $context ) {
			return array(
				'batch_size'  => (int) apply_filters( 'wsacsc_ajax_cleanup_batch_size', 10000 ),
				'max_seconds' => (int) apply_filters( 'wsacsc_ajax_cleanup_max_seconds', 18 ),
				'sleep_us'    => (int) apply_filters( 'wsacsc_ajax_cleanup_sleep_us', 5000 ),
			);
		}

		return array(
			'batch_size'  => (int) apply_filters( 'wsacsc_cron_cleanup_batch_size', 1000 ),
			'max_seconds' => (int) apply_filters( 'wsacsc_cron_cleanup_max_seconds', 45 ),
			'sleep_us'    => (int) apply_filters( 'wsacsc_cron_cleanup_sleep_us', 25000 ),
		);
	}

	/**
	 * Option key for durable cleanup progress.
	 *
	 * @param string $cleanup_id Cleanup ID.
	 * @return string
	 */
	private static function progress_option_key( string $cleanup_id ): string {
		return 'wsacsc_cleanup_job_' . sanitize_key( $cleanup_id );
	}

	/**
	 * Transient key for cleanup progress.
	 *
	 * @param string $cleanup_id Cleanup ID.
	 * @return string
	 */
	private static function progress_transient_key( string $cleanup_id ): string {
		return 'wsacsc_cleanup_' . $cleanup_id;
	}

	/**
	 * Load cleanup progress (transient with option fallback).
	 *
	 * @param string $cleanup_id Cleanup ID.
	 * @return array<string, mixed>|false
	 */
	public static function load_progress( string $cleanup_id ) {
		$progress = get_transient( self::progress_transient_key( $cleanup_id ) );
		if ( false !== $progress && is_array( $progress ) ) {
			return $progress;
		}

		$progress = get_option( self::progress_option_key( $cleanup_id ), false );
		if ( is_array( $progress ) && ! empty( $progress ) ) {
			return $progress;
		}

		return false;
	}

	/**
	 * Persist cleanup progress to transient and option.
	 *
	 * @param string               $cleanup_id Cleanup ID.
	 * @param array<string, mixed> $progress   Progress payload.
	 */
	public static function save_progress( string $cleanup_id, array $progress ): void {
		$progress['last_activity'] = time();
		set_transient( self::progress_transient_key( $cleanup_id ), $progress, self::JOB_TTL );
		update_option( self::progress_option_key( $cleanup_id ), $progress, false );
	}

	/**
	 * Remove cleanup progress and continuation events.
	 *
	 * @param string $cleanup_id Cleanup ID.
	 */
	public static function delete_progress( string $cleanup_id ): void {
		delete_transient( self::progress_transient_key( $cleanup_id ) );
		delete_option( self::progress_option_key( $cleanup_id ) );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK, array( $cleanup_id ) );
	}

	/**
	 * Whether a manual (AJAX-driven) destructive job is active.
	 *
	 * @return bool
	 */
	public static function is_manual_job_active(): bool {
		$lock = get_transient( self::ACTIVE_JOB_KEY );
		if ( ! is_array( $lock ) ) {
			return false;
		}

		if ( empty( $lock['started_at'] ) || ( time() - (int) $lock['started_at'] ) > self::JOB_TTL ) {
			delete_transient( self::ACTIVE_JOB_KEY );
			return false;
		}

		return ! empty( $lock['manual'] );
	}

	/**
	 * Acquire global job lock.
	 *
	 * @param string $cleanup_id Job ID.
	 * @param string $job_type   cleanup|optimize.
	 * @param string $table      Full table name (empty for optimize-only metadata).
	 * @param bool   $manual     True for admin-initiated jobs.
	 * @return bool True if lock acquired.
	 */
	public static function acquire_job_lock( string $cleanup_id, string $job_type, string $table = '', bool $manual = true ): bool {
		$existing = get_transient( self::ACTIVE_JOB_KEY );
		if ( is_array( $existing ) && ! empty( $existing['cleanup_id'] ) && $existing['cleanup_id'] !== $cleanup_id ) {
			if ( ! empty( $existing['started_at'] ) && ( time() - (int) $existing['started_at'] ) <= self::JOB_TTL ) {
				return false;
			}
		}

		set_transient(
			self::ACTIVE_JOB_KEY,
			array(
				'cleanup_id' => $cleanup_id,
				'job_type'   => $job_type,
				'table'      => $table,
				'manual'     => $manual,
				'started_at' => time(),
			),
			self::JOB_TTL
		);

		return true;
	}

	/**
	 * Release global job lock when it belongs to this job.
	 *
	 * @param string $cleanup_id Cleanup ID.
	 */
	public static function release_job_lock( string $cleanup_id ): void {
		$existing = get_transient( self::ACTIVE_JOB_KEY );
		if ( is_array( $existing ) && isset( $existing['cleanup_id'] ) && $existing['cleanup_id'] === $cleanup_id ) {
			delete_transient( self::ACTIVE_JOB_KEY );
		}
	}

	/**
	 * Schedule a single continuation event for an in-progress job.
	 *
	 * @param string $cleanup_id Cleanup ID.
	 */
	public static function schedule_background_continuation( string $cleanup_id ): void {
		if ( ! apply_filters( 'wsacsc_cleanup_use_background_continuation', true ) ) {
			return;
		}

		$delay = (int) apply_filters( 'wsacsc_cleanup_continuation_delay', 30 );
		$delay = max( 15, min( 120, $delay ) );

		if ( ! wp_next_scheduled( self::CONTINUE_HOOK, array( $cleanup_id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::CONTINUE_HOOK, array( $cleanup_id ) );
		}
	}

	/**
	 * Background continuation handler.
	 *
	 * @param string $cleanup_id Cleanup ID.
	 */
	public static function continue_cleanup( string $cleanup_id ): void {
		if ( self::is_manual_job_active() && 0 === strpos( $cleanup_id, 'cron_' ) ) {
			self::schedule_background_continuation( $cleanup_id );
			return;
		}

		$progress = self::load_progress( $cleanup_id );
		if ( false === $progress ) {
			self::release_job_lock( $cleanup_id );
			return;
		}

		$context = isset( $progress['context'] ) && 'cron' === $progress['context'] ? 'cron' : 'ajax';
		self::process_chunk( $cleanup_id, $context );
	}

	/**
	 * Run one DELETE batch.
	 *
	 * @param string $table         Table name.
	 * @param string $where_clause  WHERE clause (no WHERE keyword).
	 * @param array  $where_params  Prepared params.
	 * @param int    $batch_size    LIMIT.
	 * @return int|false Rows deleted or false on error.
	 */
	private static function execute_delete_batch( string $table, string $where_clause, array $where_params, int $batch_size ) {
		global $wpdb;

		$query = "DELETE FROM `{$table}` WHERE {$where_clause} LIMIT {$batch_size}";

		if ( ! empty( $where_params ) ) {
			$query = $wpdb->prepare( $query, $where_params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->query( $query );

		return $deleted;
	}

	/**
	 * Process deletions for one time-bounded chunk.
	 *
	 * @param string $cleanup_id Cleanup job ID.
	 * @param string $context    'ajax' or 'cron'.
	 * @return array{completed: bool, total_deleted: int, in_progress: bool, error?: bool}
	 */
	public static function process_chunk( string $cleanup_id, string $context = 'ajax' ): array {
		global $wpdb;

		$original_settings = self::increase_php_limits();

		try {
			$progress = self::load_progress( $cleanup_id );
			if ( false === $progress ) {
				return array(
					'completed'     => true,
					'total_deleted' => 0,
					'in_progress'   => false,
					'error'         => true,
				);
			}

			$progress = wsacsc_validate_cleanup_progress( $progress );
			if ( false === $progress ) {
				self::delete_progress( $cleanup_id );
				self::release_job_lock( $cleanup_id );
				return array(
					'completed'     => true,
					'total_deleted' => 0,
					'in_progress'   => false,
					'error'         => true,
				);
			}

			$config   = self::get_chunk_config( $context );
			$deadline = time() + max( 5, $config['max_seconds'] );
			$table    = $progress['table'];

			if ( ! isset( $progress['total_deleted'] ) ) {
				$progress['total_deleted'] = 0;
			}
			if ( ! isset( $progress['iterations'] ) ) {
				$progress['iterations'] = 0;
			}

			$progress['context']    = $context;
			$progress['batch_size'] = min( (int) $progress['batch_size'], $config['batch_size'] );
			if ( $progress['batch_size'] < 1 ) {
				$progress['batch_size'] = $config['batch_size'];
			}

			while ( time() < $deadline ) {
				$deleted = self::execute_delete_batch(
					$table,
					$progress['where_clause'],
					$progress['where_params'],
					(int) $progress['batch_size']
				);

				if ( false === $deleted ) {
					if ( wsacsc_should_log() ) {
						error_log( 'WS Action Scheduler Cleaner: Chunk deletion failed. Error: ' . $wpdb->last_error );
					}
					self::delete_progress( $cleanup_id );
					self::release_job_lock( $cleanup_id );
					return array(
						'completed'     => true,
						'total_deleted' => (int) $progress['total_deleted'],
						'in_progress'   => false,
						'error'         => true,
					);
				}

				$deleted_count              = (int) $deleted;
				$progress['total_deleted'] += $deleted_count;
				++$progress['iterations'];

				if ( $deleted_count > 0 ) {
					WSACSC_Database::flush_table_size_cache();
				}

				self::save_progress( $cleanup_id, $progress );

				if ( 0 === $deleted_count ) {
					if ( 'cron' === $context ) {
						self::maybe_auto_optimize_table_after_scheduled_cleanup( $table );
					}

					self::delete_progress( $cleanup_id );
					self::release_job_lock( $cleanup_id );
					WSACSC_Database::flush_table_size_cache();

					return array(
						'completed'     => true,
						'total_deleted' => (int) $progress['total_deleted'],
						'in_progress'   => false,
					);
				}

				if ( $config['sleep_us'] > 0 ) {
					usleep( $config['sleep_us'] );
				}
			}

			self::save_progress( $cleanup_id, $progress );
			self::schedule_background_continuation( $cleanup_id );

			return array(
				'completed'     => false,
				'total_deleted' => (int) $progress['total_deleted'],
				'in_progress'   => true,
			);
		} finally {
			self::restore_php_limits( $original_settings );
		}
	}

	/**
	 * Initialize progress for a new cleanup job.
	 *
	 * @param string $cleanup_id   Cleanup ID.
	 * @param string $table        Table name.
	 * @param string $where_clause WHERE clause.
	 * @param array  $where_params Params.
	 * @param string $context      ajax or cron.
	 * @return array<string, mixed>
	 */
	public static function create_progress_job( string $cleanup_id, string $table, string $where_clause, array $where_params, string $context = 'ajax' ): array {
		$config = self::get_chunk_config( $context );

		$progress = array(
			'total_deleted' => 0,
			'iterations'    => 0,
			'table'         => $table,
			'where_clause'  => $where_clause,
			'where_params'  => $where_params,
			'batch_size'    => $config['batch_size'],
			'context'       => $context,
			'start_time'    => time(),
		);

		self::save_progress( $cleanup_id, $progress );

		return $progress;
	}

	/**
	 * Process a single batch of deletions for AJAX cleanup (legacy entry point).
	 *
	 * @param string $cleanup_id   Unique cleanup identifier.
	 * @param string $table        Table name.
	 * @param string $where_clause WHERE clause.
	 * @param array  $where_params WHERE parameters.
	 * @param int    $batch_size   Batch size (stored on job; capped by AJAX config).
	 * @return array Status array with 'completed', 'total_deleted', 'in_progress' keys.
	 */
	public static function process_ajax_batch( $cleanup_id, $table, $where_clause, $where_params = array(), $batch_size = 5000 ): array {
		$progress = self::load_progress( $cleanup_id );

		if ( false === $progress ) {
			$config = self::get_chunk_config( 'ajax' );
			$batch  = min( max( 1, (int) $batch_size ), $config['batch_size'] );
			self::create_progress_job( $cleanup_id, $table, $where_clause, $where_params, 'ajax' );
			$progress = self::load_progress( $cleanup_id );
			if ( is_array( $progress ) ) {
				$progress['batch_size'] = $batch;
				self::save_progress( $cleanup_id, $progress );
			}
		}

		return self::process_chunk( $cleanup_id, 'ajax' );
	}

	/**
	 * Run one cron retention cleanup chunk (may schedule continuation).
	 *
	 * @param string $cleanup_id   Stable cron job ID.
	 * @param string $table        Table name.
	 * @param string $where_clause WHERE clause.
	 * @param array  $where_params Params.
	 * @return int Rows deleted this invocation (accumulated in progress total on complete).
	 */
	private static function run_cron_retention_chunk( string $cleanup_id, string $table, string $where_clause, array $where_params ): int {
		$progress = self::load_progress( $cleanup_id );

		if ( false === $progress ) {
			self::create_progress_job( $cleanup_id, $table, $where_clause, $where_params, 'cron' );
		}

		$result = self::process_chunk( $cleanup_id, 'cron' );

		return (int) $result['total_deleted'];
	}

	/**
	 * Raise WP-Cron memory when plugin cleanup hooks are due.
	 *
	 * @param int|string $limit Memory limit for the cron runner.
	 * @return int|string
	 */
	public static function filter_cron_memory_limit( $limit ) {
		if ( ! wp_doing_cron() ) {
			return $limit;
		}

		$ready_crons = wp_get_ready_cron_jobs();
		if ( ! is_array( $ready_crons ) ) {
			return $limit;
		}

		foreach ( $ready_crons as $cron_hooks ) {
			if ( ! is_array( $cron_hooks ) ) {
				continue;
			}
			if ( isset( $cron_hooks['wsacsc_cleanup_logs'] ) || isset( $cron_hooks['wsacsc_cleanup_actions'] ) || isset( $cron_hooks[ self::CONTINUE_HOOK ] ) ) {
				return '512M';
			}
		}

		return $limit;
	}

	/**
	 * Max remaining rows in a table before scheduled cleanup may run OPTIMIZE TABLE.
	 *
	 * @return int
	 */
	public static function get_scheduled_auto_optimize_max_rows(): int {
		$max_rows = (int) apply_filters( 'wsacsc_scheduled_auto_optimize_max_rows', 100000 );

		return max( 1000, $max_rows );
	}

	/**
	 * Seconds to wait after deletes finish before OPTIMIZE TABLE (scheduled cleanup only).
	 *
	 * @return int
	 */
	public static function get_scheduled_auto_optimize_delay_seconds(): int {
		$seconds = (int) apply_filters( 'wsacsc_scheduled_auto_optimize_delay_seconds', 5 );

		return max( 0, min( 60, $seconds ) );
	}

	/**
	 * Row count for an Action Scheduler table.
	 *
	 * @param string $table Full table name.
	 * @return int
	 */
	public static function get_table_row_count( string $table ): int {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		if ( ! in_array( $table, array( $actions_table, $logs_table ), true ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);

		return max( 0, (int) $count );
	}

	/**
	 * Run OPTIMIZE TABLE after scheduled retention cleanup when the table is small enough.
	 *
	 * Only called when a cron retention job has deleted all matching rows in this pass.
	 * Manual AJAX cleanup never auto-optimizes.
	 *
	 * @param string $table Full table name (actions or logs).
	 * @return void
	 */
	public static function maybe_auto_optimize_table_after_scheduled_cleanup( string $table ): void {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		if ( ! in_array( $table, array( $actions_table, $logs_table ), true ) ) {
			return;
		}

		$delay_seconds = self::get_scheduled_auto_optimize_delay_seconds();
		if ( $delay_seconds > 0 ) {
			sleep( $delay_seconds );
		}

		$row_count = self::get_table_row_count( $table );
		$max_rows  = self::get_scheduled_auto_optimize_max_rows();

		if ( $row_count > $max_rows ) {
			if ( wsacsc_should_log() ) {
				error_log(
					sprintf(
						'WS Action Scheduler Cleaner: Skipped auto OPTIMIZE for %s (%d rows > %d threshold).',
						$table,
						$row_count,
						$max_rows
					)
				);
			}
			return;
		}

		$optimized = false;
		if ( $logs_table === $table ) {
			$optimized = self::optimize_logs_table();
		} elseif ( $actions_table === $table ) {
			$optimized = self::optimize_actions_table();
		}

		if ( $optimized && wsacsc_should_log() ) {
			error_log(
				sprintf(
					'WS Action Scheduler Cleaner: Auto-optimized %s after scheduled cleanup (%d rows).',
					$table,
					$row_count
				)
			);
		}
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

			$table_exists_query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table );
			$table_exists       = $wpdb->get_var( $table_exists_query ) === $logs_table;

			if ( ! $table_exists ) {
				if ( wsacsc_should_log() ) {
					error_log( 'WS Action Scheduler Cleaner: Logs table does not exist for optimization.' );
				}
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( "OPTIMIZE TABLE `{$logs_table}`" );

			if ( $result === false ) {
				if ( wsacsc_should_log() ) {
					error_log( 'WS Action Scheduler Cleaner: Failed to optimize logs table. Error: ' . $wpdb->last_error );
				}
				return false;
			}

			return true;
		} finally {
			self::restore_php_limits( $original_settings );
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

			$table_exists_query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_table );
			$table_exists       = $wpdb->get_var( $table_exists_query ) === $actions_table;

			if ( ! $table_exists ) {
				if ( wsacsc_should_log() ) {
					error_log( 'WS Action Scheduler Cleaner: Actions table does not exist for optimization.' );
				}
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( "OPTIMIZE TABLE `{$actions_table}`" );

			if ( $result === false ) {
				if ( wsacsc_should_log() ) {
					error_log( 'WS Action Scheduler Cleaner: Failed to optimize actions table. Error: ' . $wpdb->last_error );
				}
				return false;
			}

			return true;
		} finally {
			self::restore_php_limits( $original_settings );
		}
	}

	/**
	 * Cleanup logs (scheduled retention; chunked; auto-optimize when retention pass completes).
	 */
	public static function cleanup_logs(): void {
		global $wpdb;

		if ( self::is_manual_job_active() ) {
			return;
		}

		$original_settings = self::increase_php_limits();

		try {
			wp_cache_delete( 'wsacsc_logs_retention', 'options' );
			$retention_days_option = get_option( 'wsacsc_logs_retention', '30' );
			if ( $retention_days_option === '' || ! ctype_digit( (string) $retention_days_option ) ) {
				return;
			}
			$retention_days = (int) $retention_days_option;
			$logs_table     = $wpdb->prefix . 'actionscheduler_logs';

			if ( ! WSACSC_Database::check_tables_exist() ) {
				return;
			}

			if ( $retention_days === 0 ) {
				$where_clause = '1=1';
				$where_params = array();
			} else {
				$where_clause = '`log_date_gmt` < DATE_SUB(NOW(), INTERVAL %d DAY)';
				$where_params = array( $retention_days );
			}

			$cleanup_id    = 'cron_logs';
			$total_deleted = self::run_cron_retention_chunk( $cleanup_id, $logs_table, $where_clause, $where_params );

			if ( $total_deleted > 0 ) {
				wp_cache_delete( 'wsacsc_table_sizes', self::CACHE_GROUP );
			}

			if ( wsacsc_should_log() && $total_deleted > 0 ) {
				error_log( 'WS Action Scheduler Cleaner: Cleaned up ' . $total_deleted . ' log entries (cron chunk).' );
			}
		} finally {
			self::restore_php_limits( $original_settings );
		}
	}

	/**
	 * Cleanup actions (scheduled retention; chunked; auto-optimize when retention pass completes).
	 */
	public static function cleanup_actions(): void {
		global $wpdb;

		if ( self::is_manual_job_active() ) {
			return;
		}

		$original_settings = self::increase_php_limits();

		try {
			wp_cache_delete( 'wsacsc_actions_retention', 'options' );
			$retention_days_option = get_option( 'wsacsc_actions_retention', '30' );
			if ( $retention_days_option === '' || ! ctype_digit( (string) $retention_days_option ) ) {
				return;
			}
			$retention_days = absint( $retention_days_option );

			wp_cache_delete( 'wsacsc_selected_statuses', 'options' );
			$selected_statuses_option = get_option( 'wsacsc_selected_statuses', array( 'complete', 'failed', 'canceled' ) );
			if ( ! is_array( $selected_statuses_option ) ) {
				$selected_statuses_option = array( 'complete', 'failed', 'canceled' );
			}
			$selected_statuses = array_map( 'sanitize_text_field', $selected_statuses_option );

			$allowed_statuses  = array( 'complete', 'failed', 'canceled' );
			$selected_statuses = array_intersect( $selected_statuses, $allowed_statuses );

			if ( ! empty( $selected_statuses ) ) {
				if ( ! WSACSC_Database::check_tables_exist() ) {
					return;
				}

				$actions_table   = $wpdb->prefix . 'actionscheduler_actions';
				$retention_where = wsacsc_build_actions_retention_where( $selected_statuses, $retention_days );

				if ( empty( $retention_where['where_clause'] ) ) {
					return;
				}

				$cleanup_id    = 'cron_actions';
				$total_deleted = self::run_cron_retention_chunk(
					$cleanup_id,
					$actions_table,
					$retention_where['where_clause'],
					$retention_where['where_params']
				);

				if ( $total_deleted > 0 ) {
					wp_cache_delete( 'wsacsc_table_sizes', self::CACHE_GROUP );
				}

				if ( wsacsc_should_log() && $total_deleted > 0 ) {
					error_log( 'WS Action Scheduler Cleaner: Cleaned up ' . $total_deleted . ' action entries (cron chunk).' );
				}
			}
		} finally {
			self::restore_php_limits( $original_settings );
		}
	}
}
