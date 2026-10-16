<?php
/**
 * Scheduler functionality for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WSACSC_Scheduler
 *
 * Owns the two plugin WP-Cron hooks. All writes to these hooks go through
 * reconciliation, which runs only while this request holds the schedule lock.
 * The per-request check is read-only while the events match the applied state.
 */
class WSACSC_Scheduler {

	/**
	 * Cache group for plugin cache operations
	 */
	const CACHE_GROUP = 'wsacsc';

	/**
	 * Autoloaded option with the version, the applied revision and the last applied configuration.
	 */
	const STATE_OPTION = 'wsacsc_schedule_state';

	/**
	 * Autoloaded option with a random token. Every change of a schedule option writes a new token.
	 *
	 * It is a separate row, so a reconciliation that stores its state cannot overwrite a newer change.
	 */
	const REVISION_OPTION = 'wsacsc_schedule_revision';

	/**
	 * Options that define the schedule configuration.
	 */
	const CONFIG_OPTIONS = array(
		'wsacsc_logs_schedule_interval',
		'wsacsc_logs_schedule_time',
		'wsacsc_actions_schedule_interval',
		'wsacsc_actions_schedule_time',
	);

	/**
	 * Options table row used as an exclusive lock. Never read through get_option().
	 */
	const LOCK_OPTION = 'wsacsc_schedule_lock';

	/**
	 * Seconds a lock stays valid while a reconciliation runs.
	 */
	const LOCK_TTL = 60;

	/**
	 * Seconds before a new attempt after a failed reconciliation.
	 */
	const FAILURE_BACKOFF = 300;

	/**
	 * Object cache key that mirrors the lock expiry, to skip lock queries during contention or backoff.
	 */
	const LOCK_UNTIL_CACHE_KEY = 'schedule_lock_until';

	/**
	 * Lock value owned by this request, or an empty string.
	 *
	 * @var string
	 */
	private static $lock_value = '';

	/**
	 * Day counts of the configuration snapshot that the current reconciliation applies.
	 *
	 * The method add_custom_cron_intervals() registers them too, so wp_schedule_event() accepts
	 * the recurrence even if the request copy of the options is older than the snapshot.
	 *
	 * @var int[]
	 */
	private static $snapshot_days = array();

	/**
	 * Initialize scheduler functionality
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_cron_intervals' ) );
		add_action( 'init', array( __CLASS__, 'check_schedule_on_request' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'verify_cron_health_on_plugin_screen' ), 30 );

		// Every write through the options API (admin screen, migration, WP-CLI, other code) marks a new revision.
		foreach ( self::CONFIG_OPTIONS as $option_name ) {
			add_action( 'add_option_' . $option_name, array( __CLASS__, 'bump_revision' ) );
			add_action( 'update_option_' . $option_name, array( __CLASS__, 'bump_revision' ) );
			add_action( 'delete_option_' . $option_name, array( __CLASS__, 'bump_revision' ) );
		}
	}

	/**
	 * Mark the schedule configuration as changed.
	 *
	 * Writes a new random token to its own option row. A reconciliation stores the token it
	 * read before its configuration snapshot, so a change during a reconciliation is detected
	 * by the next request.
	 */
	public static function bump_revision(): void {
		update_option( self::REVISION_OPTION, wp_generate_uuid4(), true );
	}

	/**
	 * Plugin hooks mapped to their configuration key.
	 *
	 * @return array<string, string> Hook name => configuration key.
	 */
	private static function get_hooks(): array {
		return array(
			'wsacsc_cleanup_logs'    => 'logs',
			'wsacsc_cleanup_actions' => 'actions',
		);
	}

	/**
	 * Whether the current request is the plugin admin screen.
	 *
	 * @return bool
	 */
	private static function is_plugin_admin_screen(): bool {
		$screen = get_current_screen();
		if ( $screen && 'tools_page_ws-action-scheduler-cleaner' === $screen->id ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin screen detection only.
		if ( isset( $_GET['page'] ) && 'ws-action-scheduler-cleaner' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			global $pagenow;
			return isset( $pagenow ) && 'tools.php' === $pagenow;
		}

		return false;
	}

	/**
	 * Verify cron health when viewing the plugin settings screen.
	 */
	public static function verify_cron_health_on_plugin_screen(): void {
		if ( ! self::is_plugin_admin_screen() ) {
			return;
		}

		self::verify_cron_health();
	}

	/**
	 * Read one schedule configuration from the plugin options.
	 *
	 * @param string $key Configuration key: 'logs' or 'actions'.
	 * @return array{days:int,time:string} Days is 0 when the schedule is disabled or invalid.
	 */
	private static function get_desired_config_for( string $key ): array {
		return self::parse_config(
			get_option( 'wsacsc_' . $key . '_schedule_interval', '' ),
			get_option( 'wsacsc_' . $key . '_schedule_time', '' )
		);
	}

	/**
	 * Normalize raw schedule option values.
	 *
	 * @param mixed $interval Raw interval value.
	 * @param mixed $time     Raw time value.
	 * @return array{days:int,time:string} Days is 0 when the schedule is disabled or invalid.
	 */
	private static function parse_config( $interval, $time ): array {
		$days = 0;
		if ( ! empty( $interval ) && ctype_digit( (string) $interval ) ) {
			$days = (int) $interval;
			if ( $days < 1 || $days > 365 ) {
				$days = 0;
			}
		}

		$time = is_string( $time ) ? $time : '';
		if ( '' !== $time && ! preg_match( '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time ) ) {
			$time = '';
		}

		return array(
			'days' => $days,
			'time' => $time,
		);
	}

	/**
	 * Recurrence name for a number of days.
	 *
	 * @param int $days Interval in days (1-365).
	 * @return string
	 */
	private static function get_recurrence( int $days ): string {
		return ( $days <= 1 ) ? 'daily' : 'wsacsc_every_' . $days . '_days';
	}

	/**
	 * Whether a stored configuration array has the expected shape.
	 *
	 * @param mixed $config Value to test.
	 * @return bool
	 */
	private static function is_config( $config ): bool {
		return is_array( $config ) && isset( $config['days'], $config['time'] ) && is_int( $config['days'] ) && is_string( $config['time'] );
	}

	/**
	 * Add custom cron intervals
	 *
	 * @param array $schedules Existing schedules
	 * @return array
	 */
	public static function add_custom_cron_intervals( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$day_counts = self::$snapshot_days;
		foreach ( self::get_hooks() as $key ) {
			$config = self::get_desired_config_for( $key );
			if ( $config['days'] > 1 ) {
				$day_counts[] = $config['days'];
			}
		}

		// Translations must not load before init (WordPress 6.7+ notice), so use the English label until then.
		$can_translate = (bool) did_action( 'init' );

		foreach ( array_unique( $day_counts ) as $days ) {
			$interval_key = 'wsacsc_every_' . $days . '_days';
			if ( ! isset( $schedules[ $interval_key ] ) ) {
				$schedules[ $interval_key ] = array(
					'interval' => $days * DAY_IN_SECONDS,
					'display'  => $can_translate
						/* translators: %d: number of days. */
						? sprintf( _n( 'Every %d day', 'Every %d days', $days, 'ws-action-scheduler-cleaner' ), $days )
						: sprintf( 'Every %d days', $days ),
				);
			}
		}

		return $schedules;
	}

	/**
	 * Calculate next run time with time of day consideration
	 *
	 * @param int    $schedule_interval_days Schedule interval in days
	 * @param string $schedule_time Time of day in HH:MM format (empty for immediate)
	 * @return int Timestamp for next run
	 */
	private static function calculate_next_run_time( $schedule_interval_days, $schedule_time ): int {
		$now      = time();
		$timezone = wp_timezone();

		if ( empty( $schedule_time ) || ! preg_match( '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $schedule_time ) ) {
			return $now + ( $schedule_interval_days * DAY_IN_SECONDS );
		}

		list($hour, $minute) = explode( ':', $schedule_time );
		$hour                = (int) $hour;
		$minute              = (int) $minute;

		$today = new DateTime( 'today', $timezone );
		$today->setTime( $hour, $minute, 0 );
		$target_timestamp = $today->getTimestamp();

		if ( $target_timestamp <= $now ) {
			$target_timestamp += DAY_IN_SECONDS;
		}

		return $target_timestamp;
	}

	/**
	 * Read-only comparison of the actual events with the applied state.
	 *
	 * Compares the recurrence name and the interval in seconds only. An overdue
	 * event, or an event whose local time moved because of DST, stays valid.
	 *
	 * @param array $state Applied state.
	 * @return bool True if all plugin events agree with the applied state.
	 */
	private static function events_match_state( array $state ): bool {
		foreach ( self::get_hooks() as $hook => $key ) {
			if ( ! self::is_config( $state[ $key ] ?? null ) ) {
				return false;
			}

			$days = $state[ $key ]['days'];
			if ( $days < 1 ) {
				if ( false !== wp_next_scheduled( $hook ) ) {
					return false;
				}
				continue;
			}

			$event = wp_get_scheduled_event( $hook );
			if ( ! $event || ! isset( $event->schedule, $event->interval ) ) {
				return false;
			}

			if ( self::get_recurrence( $days ) !== $event->schedule || $days * DAY_IN_SECONDS !== (int) $event->interval ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the stored state needs a reconciliation regardless of the events.
	 *
	 * @param mixed $state Stored state.
	 * @return bool
	 */
	private static function state_needs_reconciliation( $state ): bool {
		if ( ! is_array( $state ) ) {
			return true;
		}

		if ( ! isset( $state['version'] ) || ! defined( 'WSACSC_VERSION' ) || WSACSC_VERSION !== $state['version'] ) {
			return true;
		}

		return ! isset( $state['revision'] ) || (string) get_option( self::REVISION_OPTION, '' ) !== $state['revision'];
	}

	/**
	 * Lightweight per-request check.
	 *
	 * Reads only autoloaded data. While the events match the applied state, it
	 * runs no lock query, no write and no logging. Otherwise it starts one
	 * reconciliation, which also handles the upgrade from earlier versions.
	 */
	public static function check_schedule_on_request(): void {
		if ( wp_installing() ) {
			return;
		}

		$state = get_option( self::STATE_OPTION );

		if ( self::state_needs_reconciliation( $state ) || ! self::events_match_state( $state ) ) {
			self::run_reconciliation();
		}
	}

	/**
	 * Try to get the exclusive schedule lock.
	 *
	 * Uses one atomic INSERT IGNORE on the options table. An expired row is taken
	 * over with an UPDATE that has a condition on the old value, so a worker
	 * never removes a lock that a newer worker owns.
	 *
	 * @return bool True if this request owns the lock.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;

		if ( '' !== self::$lock_value ) {
			return false;
		}

		$now   = time();
		$until = (int) wp_cache_get( self::LOCK_UNTIL_CACHE_KEY, self::CACHE_GROUP );
		if ( $until > $now ) {
			return false;
		}

		$value = ( $now + self::LOCK_TTL ) . ':' . wp_generate_uuid4();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock row; must bypass the object cache.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, self::LOCK_OPTION, $value ) );
		if ( 1 === $inserted ) {
			self::$lock_value = $value;
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row must be read from the database.
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION ) );
		if ( ! is_string( $current ) ) {
			return false;
		}

		$expiry = (int) strtok( $current, ':' );
		if ( $expiry > $now ) {
			wp_cache_set( self::LOCK_UNTIL_CACHE_KEY, $expiry, self::CACHE_GROUP, $expiry - $now );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional takeover of an expired lock row.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, self::LOCK_OPTION, $current ) );
		if ( 1 === $updated ) {
			self::$lock_value = $value;
			return true;
		}

		return false;
	}

	/**
	 * Whether this request still owns the lock.
	 *
	 * Reads the lock row from the database. If another worker took over an expired lock,
	 * or deactivation took the lock, this request stops owning it.
	 *
	 * @return bool
	 */
	private static function owns_lock(): bool {
		global $wpdb;

		if ( '' === self::$lock_value ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row must be read from the database.
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION ) );
		if ( $current !== self::$lock_value ) {
			self::$lock_value = '';
			return false;
		}

		return true;
	}

	/**
	 * Take the lock regardless of its current owner or backoff.
	 *
	 * Used only on deactivation and uninstall. A running reconciliation sees the new value at
	 * its next ownership check and stops before its next cron write.
	 */
	private static function take_over_lock(): void {
		global $wpdb;

		$value = ( time() + self::LOCK_TTL ) . ':' . wp_generate_uuid4();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock row; must bypass the object cache.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, self::LOCK_OPTION, $value ) );
		if ( 1 !== $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Unconditional takeover of the lock row.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $value, self::LOCK_OPTION ) );
		}

		self::$lock_value = $value;
	}

	/**
	 * Release the lock if this request owns it.
	 */
	private static function release_lock(): void {
		global $wpdb;

		if ( '' === self::$lock_value ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the lock row this request owns.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::LOCK_OPTION, self::$lock_value ) );

		self::$lock_value = '';
		wp_cache_delete( self::LOCK_UNTIL_CACHE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Keep the own lock row as a failure backoff.
	 *
	 * The row expires after FAILURE_BACKOFF seconds. If this update fails, the
	 * row still expires after LOCK_TTL seconds.
	 */
	private static function start_backoff(): void {
		global $wpdb;

		if ( '' === self::$lock_value ) {
			return;
		}

		$until  = time() + self::FAILURE_BACKOFF;
		$token  = substr( self::$lock_value, strpos( self::$lock_value, ':' ) + 1 );
		$backed = $until . ':' . $token;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Extend only the lock row this request owns.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $backed, self::LOCK_OPTION, self::$lock_value ) );

		self::$lock_value = '';
		if ( 1 === $updated ) {
			wp_cache_set( self::LOCK_UNTIL_CACHE_KEY, $until, self::CACHE_GROUP, self::FAILURE_BACKOFF );
		}
	}

	/**
	 * Whether a cron function result is a failure.
	 *
	 * @param mixed $result Result of wp_schedule_event(), wp_unschedule_event() or wp_unschedule_hook().
	 * @return bool
	 */
	private static function is_cron_failure( $result ): bool {
		return false === $result || is_wp_error( $result );
	}

	/**
	 * Format a failed cron result for the log.
	 *
	 * @param string $operation Operation description.
	 * @param mixed  $result    Failed result.
	 * @return string
	 */
	private static function describe_failure( string $operation, $result ): string {
		if ( is_wp_error( $result ) ) {
			return sprintf( '%s: %s', $operation, $result->get_error_message() );
		}

		return sprintf( '%s failed', $operation );
	}

	/**
	 * Read all cron entries of one hook. Read-only.
	 *
	 * @param string $hook Hook name.
	 * @return array<int, array{timestamp:int,args:array,schedule:mixed,interval:mixed}>
	 */
	private static function get_hook_entries( string $hook ): array {
		$entries = array();
		$crons   = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return $entries;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! isset( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
				continue;
			}

			foreach ( $hooks[ $hook ] as $event ) {
				$entries[] = array(
					'timestamp' => (int) $timestamp,
					'args'      => ( isset( $event['args'] ) && is_array( $event['args'] ) ) ? $event['args'] : array(),
					'schedule'  => $event['schedule'] ?? false,
					'interval'  => $event['interval'] ?? null,
				);
			}
		}

		return $entries;
	}

	/**
	 * Split the entries of a hook into one valid entry and the rest.
	 *
	 * A valid entry has no arguments, the expected recurrence and interval, and
	 * a timestamp no later than the longest supported interval from now.
	 * Overdue entries stay valid because WP-Cron runs them. If several entries
	 * are valid, the latest one stays: while WP-Cron runs an event, it adds the
	 * next occurrence before it removes the current one.
	 *
	 * @param array $entries Entries from get_hook_entries(), in timestamp order.
	 * @param int   $days    Expected interval in days.
	 * @return array{valid:array|null,remove:array}
	 */
	private static function classify_entries( array $entries, int $days ): array {
		$recurrence = self::get_recurrence( $days );
		$seconds    = $days * DAY_IN_SECONDS;
		$latest     = time() + ( 366 * DAY_IN_SECONDS );
		$valid      = null;
		$remove     = array();

		foreach ( array_reverse( $entries ) as $entry ) {
			$is_valid = null === $valid
				&& array() === $entry['args']
				&& $recurrence === $entry['schedule']
				&& $seconds === (int) $entry['interval']
				&& $entry['timestamp'] <= $latest;

			if ( $is_valid ) {
				$valid = $entry;
			} else {
				$remove[] = $entry;
			}
		}

		return array(
			'valid'  => $valid,
			'remove' => $remove,
		);
	}

	/**
	 * Remove single cron entries of a plugin hook.
	 *
	 * Checks the lock ownership before each write.
	 *
	 * @param string $hook    Hook name.
	 * @param array  $entries Entries to remove.
	 * @param array  $errors  Collected error messages.
	 * @return bool True if all entries were removed.
	 */
	private static function unschedule_entries( string $hook, array $entries, array &$errors ): bool {
		foreach ( $entries as $entry ) {
			if ( ! self::owns_lock() ) {
				$errors[] = 'Schedule lock lost';
				return false;
			}

			$result = wp_unschedule_event( $entry['timestamp'], $hook, $entry['args'], true );
			if ( self::is_cron_failure( $result ) ) {
				$errors[] = self::describe_failure( sprintf( 'Unschedule %s at %d', $hook, $entry['timestamp'] ), $result );
				return false;
			}
		}

		return true;
	}

	/**
	 * Bring the events of one plugin hook in line with the desired configuration.
	 *
	 * Must run while this request owns the lock.
	 *
	 * @param string     $hook    Hook name.
	 * @param array      $desired Desired configuration.
	 * @param array|null $applied Last applied configuration, or null if unknown (upgrade).
	 * @param array      $errors  Collected error messages.
	 */
	private static function apply_hook( string $hook, array $desired, $applied, array &$errors ): void {
		if ( $desired['days'] < 1 ) {
			if ( false !== wp_next_scheduled( $hook ) ) {
				if ( ! self::owns_lock() ) {
					$errors[] = 'Schedule lock lost';
					return;
				}

				$result = wp_unschedule_hook( $hook, true );
				if ( self::is_cron_failure( $result ) ) {
					$errors[] = self::describe_failure( sprintf( 'Unschedule %s', $hook ), $result );
				}
			}
			return;
		}

		$sorted = self::classify_entries( self::get_hook_entries( $hook ), $desired['days'] );
		if ( ! self::unschedule_entries( $hook, $sorted['remove'], $errors ) ) {
			// Do not add an event while a removal failed, to avoid duplicates.
			return;
		}

		$changed = null !== $applied && ( $applied['days'] !== $desired['days'] || $applied['time'] !== $desired['time'] );

		if ( null !== $sorted['valid'] ) {
			if ( ! $changed ) {
				return;
			}
			if ( ! self::unschedule_entries( $hook, array( $sorted['valid'] ), $errors ) ) {
				return;
			}
		}

		if ( ! self::owns_lock() ) {
			$errors[] = 'Schedule lock lost';
			return;
		}

		$result = wp_schedule_event(
			self::calculate_next_run_time( $desired['days'], $desired['time'] ),
			self::get_recurrence( $desired['days'] ),
			$hook,
			array(),
			true
		);
		if ( self::is_cron_failure( $result ) ) {
			$errors[] = self::describe_failure( sprintf( 'Schedule %s', $hook ), $result );
		}
	}

	/**
	 * Write the applied state.
	 *
	 * @param array $state State to store.
	 * @return bool True if the stored value equals the state.
	 */
	private static function save_state( array $state ): bool {
		update_option( self::STATE_OPTION, $state, true );

		return get_option( self::STATE_OPTION ) === $state;
	}

	/**
	 * Read the revision, the applied state and the schedule options from the database.
	 *
	 * The object cache can hold older copies of these rows, and the schedule options are
	 * not autoloaded. One query gives a coherent snapshot for the reconciliation.
	 *
	 * @return array{revision:string,state:array,logs:array,actions:array}
	 */
	private static function read_config_snapshot(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Snapshot must bypass the object cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s, %s, %s, %s, %s)',
				$wpdb->options,
				self::REVISION_OPTION,
				self::STATE_OPTION,
				self::CONFIG_OPTIONS[0],
				self::CONFIG_OPTIONS[1],
				self::CONFIG_OPTIONS[2],
				self::CONFIG_OPTIONS[3]
			),
			ARRAY_A
		);

		$values = array();
		foreach ( (array) $rows as $row ) {
			$values[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
		}

		$snapshot = array(
			'revision' => isset( $values[ self::REVISION_OPTION ] ) ? (string) $values[ self::REVISION_OPTION ] : '',
			'state'    => ( isset( $values[ self::STATE_OPTION ] ) && is_array( $values[ self::STATE_OPTION ] ) ) ? $values[ self::STATE_OPTION ] : array(),
		);

		foreach ( self::get_hooks() as $key ) {
			$snapshot[ $key ] = self::parse_config(
				$values[ 'wsacsc_' . $key . '_schedule_interval' ] ?? '',
				$values[ 'wsacsc_' . $key . '_schedule_time' ] ?? ''
			);
		}

		return $snapshot;
	}

	/**
	 * Run one reconciliation under the lock.
	 *
	 * Steps: get the lock, read a database snapshot of the revision and the configuration,
	 * fix the events of both hooks, then store the applied state with the snapshot revision.
	 * The revision is read in the same query as the configuration. A configuration change
	 * after the snapshot writes a newer revision, so the next request reconciles again.
	 * Before each cron write and before the state write, the lock ownership is checked.
	 * After a failure, the lock stays as a backoff and the old state stays.
	 *
	 * @return bool True if the events and the applied state agree with the snapshot.
	 */
	private static function run_reconciliation(): bool {
		if ( ! self::acquire_lock() ) {
			return false;
		}

		// Refresh the request copy of autoloaded options, including cron, from the persistent cache.
		// It does not read the database. The configuration comes from the snapshot below.
		wp_load_alloptions( true );

		$snapshot = self::read_config_snapshot();
		if ( '' === $snapshot['revision'] ) {
			// Create the revision row, so that the request check never queries a missing option.
			add_option( self::REVISION_OPTION, wp_generate_uuid4(), '', true );
			$snapshot = self::read_config_snapshot();
		}

		$state  = $snapshot['state'];
		$errors = array();
		$new    = array(
			'version'  => defined( 'WSACSC_VERSION' ) ? WSACSC_VERSION : '',
			'revision' => $snapshot['revision'],
		);

		self::$snapshot_days = array();
		foreach ( self::get_hooks() as $key ) {
			if ( $snapshot[ $key ]['days'] > 1 ) {
				self::$snapshot_days[] = $snapshot[ $key ]['days'];
			}
		}

		foreach ( self::get_hooks() as $hook => $key ) {
			$applied     = self::is_config( $state[ $key ] ?? null ) ? $state[ $key ] : null;
			$new[ $key ] = $snapshot[ $key ];

			self::apply_hook( $hook, $snapshot[ $key ], $applied, $errors );
			if ( ! empty( $errors ) ) {
				break;
			}
		}

		self::$snapshot_days = array();

		if ( empty( $errors ) && ! self::owns_lock() ) {
			$errors[] = 'Schedule lock lost';
		}

		if ( ! empty( $errors ) ) {
			if ( wsacsc_should_log() ) {
				error_log( sprintf( 'WS Action Scheduler Cleaner: Schedule reconciliation failed, next attempt in up to %d seconds: %s', self::FAILURE_BACKOFF, implode( '; ', $errors ) ) );
			}
			// Only a request that still owns the lock turns it into a backoff.
			self::start_backoff();
			return false;
		}

		if ( ! self::save_state( $new ) ) {
			if ( wsacsc_should_log() ) {
				error_log( sprintf( 'WS Action Scheduler Cleaner: Schedule state could not be saved, next attempt in %d seconds', self::FAILURE_BACKOFF ) );
			}
			self::start_backoff();
			return false;
		}

		self::release_lock();

		return true;
	}

	/**
	 * Apply the saved schedule configuration to the plugin cron events.
	 *
	 * Keeps a valid event and its timestamp when the configuration did not change.
	 * If the lock is busy or an operation fails, the newer revision stays unapplied and a
	 * later request retries after the backoff.
	 *
	 * @return bool True if the schedule now matches the configuration.
	 */
	public static function apply_schedule_configuration(): bool {
		self::bump_revision();

		return self::run_reconciliation();
	}

	/**
	 * Schedule or unschedule cleanup tasks
	 */
	public static function maybe_schedule_cleanup(): void {
		self::apply_schedule_configuration();
	}

	/**
	 * Verify cron health and auto-fix issues
	 *
	 * Read-only while the events and the applied state agree with the configuration.
	 *
	 * @return array Health status with 'healthy', 'issues', and 'fixed' keys
	 */
	public static function verify_cron_health(): array {
		$health = array(
			'healthy' => true,
			'issues'  => array(),
			'fixed'   => array(),
		);

		$state = get_option( self::STATE_OPTION );
		if ( self::state_needs_reconciliation( $state ) ) {
			$health['issues'][] = 'Schedule state is missing, outdated or has an older revision';
		}

		foreach ( self::get_hooks() as $hook => $key ) {
			$desired = self::get_desired_config_for( $key );
			$entries = self::get_hook_entries( $hook );

			if ( is_array( $state ) && self::is_config( $state[ $key ] ?? null ) && $state[ $key ] !== $desired ) {
				$health['issues'][] = sprintf( '%s: configuration changed since the last applied schedule', $hook );
			}

			if ( $desired['days'] < 1 ) {
				if ( ! empty( $entries ) ) {
					$health['issues'][] = sprintf( '%s: event exists but the schedule is disabled', $hook );
				}
				continue;
			}

			$sorted = self::classify_entries( $entries, $desired['days'] );
			if ( null === $sorted['valid'] ) {
				$health['issues'][] = sprintf( 'Missing scheduled event for %s', $hook );
			}
			if ( ! empty( $sorted['remove'] ) ) {
				$health['issues'][] = sprintf( '%s: %d invalid or duplicate entries', $hook, count( $sorted['remove'] ) );
			}
		}

		if ( empty( $health['issues'] ) ) {
			return $health;
		}

		$health['healthy'] = false;

		if ( self::run_reconciliation() ) {
			$health['fixed'][] = 'Reconciled plugin cron events with the saved configuration';
		} else {
			$health['issues'][] = 'Reconciliation did not run or failed; it retries automatically';
		}

		if ( wsacsc_should_log() ) {
			error_log(
				sprintf(
					'WS Action Scheduler Cleaner: Cron health check - Healthy: %s, Issues: %d, Fixed: %d',
					$health['healthy'] ? 'Yes' : 'No',
					count( $health['issues'] ),
					count( $health['fixed'] )
				)
			);
		}

		return $health;
	}

	/**
	 * Detect and remove broken cron hooks
	 *
	 * An entry is broken if:
	 * - It has no schedule, or an unknown schedule name
	 * - Its custom interval is not between 1 and 3650 days
	 * - Its timestamp is more than one year in the past or ten years in the future
	 *
	 * Only broken entries are removed, one by one. Valid entries stay.
	 * Only the plugin hooks are processed.
	 *
	 * @param array $hook_names Hook names to check
	 * @return int Number of hooks from which entries were removed
	 */
	public static function winningsolutions_cleanup_broken_hooks( $hook_names ): int {
		if ( ! is_array( $hook_names ) ) {
			$hook_names = array( $hook_names );
		}

		$hook_names = array_intersect( $hook_names, array_keys( self::get_hooks() ) );
		if ( empty( $hook_names ) || ! self::acquire_lock() ) {
			return 0;
		}

		$removed_count   = 0;
		$errors          = array();
		$valid_intervals = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
		$now             = time();
		$max_timestamp   = $now + ( 10 * YEAR_IN_SECONDS );
		$min_timestamp   = $now - YEAR_IN_SECONDS;

		foreach ( $hook_names as $hook_name ) {
			$broken = array();

			foreach ( self::get_hook_entries( $hook_name ) as $entry ) {
				$schedule  = $entry['schedule'];
				$is_broken = ! is_string( $schedule );

				if ( ! $is_broken && 0 === strpos( $schedule, 'wsacsc_every_' ) ) {
					$days_str  = str_replace( 'wsacsc_every_', '', str_replace( '_days', '', $schedule ) );
					$is_broken = ! ctype_digit( $days_str ) || (int) $days_str < 1 || (int) $days_str > 3650;
				} elseif ( ! $is_broken ) {
					$is_broken = ! in_array( $schedule, $valid_intervals, true );
				}

				if ( $entry['timestamp'] < $min_timestamp || $entry['timestamp'] > $max_timestamp ) {
					$is_broken = true;
				}

				if ( $is_broken ) {
					$broken[] = $entry;
				}
			}

			if ( ! empty( $broken ) ) {
				$error_count = count( $errors );
				self::unschedule_entries( $hook_name, $broken, $errors );
				if ( count( $errors ) === $error_count ) {
					++$removed_count;
				}
			}
		}

		if ( ! empty( $errors ) ) {
			// The state no longer reflects the events, so a later request reconciles them.
			self::bump_revision();
			if ( wsacsc_should_log() ) {
				error_log( sprintf( 'WS Action Scheduler Cleaner: Broken hook cleanup failed: %s', implode( '; ', $errors ) ) );
			}
		}

		self::release_lock();

		return $removed_count;
	}

	/**
	 * Remove all events of the given plugin hooks.
	 *
	 * Used on deactivation and uninstall. It takes the lock even if another request holds
	 * it or a backoff is active. A running reconciliation then stops at its next ownership
	 * check, before its next cron write.
	 *
	 * @param string|array $hook_names Hook name(s) to clear
	 * @return bool True if no event of the hooks remains
	 */
	public static function winningsolutions_force_clear_cron_hooks( $hook_names ): bool {
		if ( ! is_array( $hook_names ) ) {
			$hook_names = array( $hook_names );
		}

		self::take_over_lock();

		$success = true;
		foreach ( $hook_names as $hook_name ) {
			$result = wp_unschedule_hook( $hook_name, true );
			if ( self::is_cron_failure( $result ) || false !== wp_next_scheduled( $hook_name ) ) {
				$success = false;
			}
		}

		self::release_lock();

		return $success;
	}
}
