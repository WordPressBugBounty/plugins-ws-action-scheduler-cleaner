<?php
/**
 * Test Data Populator for WS Action Scheduler Cleaner
 *
 * This script populates the wp_actionscheduler_actions and wp_actionscheduler_logs tables with realistic test data up to 500 MB per table for testing cleanup functionality.
 *
 * WARNING: This script is for TESTING ONLY. Do not run on production sites.
 *
 * Usage: Place this file in the WordPress root directory and access via browser or run via WP-CLI: wp eval-file wsacsc-populate-test-data.php
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	// Load WordPress if not already loaded
	require_once __DIR__ . '/wp-load.php';
}

if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
	wp_die( 'This development utility is only available when WP_DEBUG is enabled.' );
}

// Security check - only allow administrators
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Insufficient permissions. Administrator access required.' );
}

// Set execution time limit
set_time_limit( 600 ); // 10 minutes max for larger data generation

global $wpdb;

// Target size in bytes (500 MB)
define( 'WSACSC_TARGET_SIZE_MB', 500 );
define( 'WSACSC_TARGET_SIZE_BYTES', WSACSC_TARGET_SIZE_MB * 1024 * 1024 );

// Batch size for inserts
define( 'WSACSC_BATCH_SIZE', 100 );

// Realistic action hooks that might appear in WordPress/WooCommerce
$realistic_hooks = array(
	'woocommerce_scheduled_subscription_payment',
	'woocommerce_scheduled_subscription_trial_end',
	'woocommerce_cleanup_sessions',
	'woocommerce_update_product_lookup_tables',
	'woocommerce_run_update_callback',
	'woocommerce_cleanup_logs',
	'woocommerce_cleanup_personal_data',
	'woocommerce_scheduled_sales',
	'woocommerce_scheduled_downloads',
	'woocommerce_cleanup_expired_transients',
	'action_scheduler/migration_hook',
	'wp_scheduled_auto_draft_delete',
	'wp_scheduled_delete',
	'wp_update_plugins',
	'wp_update_themes',
	'wp_version_check',
	'wp_update_core',
	'recovery_mode_clean_expired_keys',
	'wp_privacy_delete_old_export_files',
	'delete_expired_transients',
	'wp_scheduled_delete_revisions',
);

// Only completed, failed, and canceled statuses for testing cleanup
$statuses = array( 'complete', 'failed', 'canceled' );

/**
 * Get current table size in bytes
 */
function winningsolutions_get_table_size( $table_name ) {
	global $wpdb;

	$size = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT ROUND((DATA_LENGTH + INDEX_LENGTH)) AS size
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = %s
        AND TABLE_NAME = %s',
			DB_NAME,
			$table_name
		)
	);

	return $size ? (int) $size : 0;
}

/**
 * Format bytes to human readable
 */
function winningsolutions_format_bytes( $bytes, $precision = 2 ) {
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

	for ( $i = 0; $bytes > 1024 && $i < count( $units ) - 1; $i++ ) {
		$bytes /= 1024;
	}

	return round( $bytes, $precision ) . ' ' . $units[ $i ];
}

/**
 * Generate realistic action data
 */
function winningsolutions_generate_action_data( $hook, $status ) {
	$now       = current_time( 'mysql', true );
	$now_local = current_time( 'mysql', false );

	// Generate dates based on status - all actions are completed/failed/canceled
	$days_ago             = rand( 0, 90 ); // Actions from 0-90 days ago
	$scheduled_date       = date( 'Y-m-d H:i:s', strtotime( "-{$days_ago} days", strtotime( $now ) ) );
	$scheduled_date_local = date( 'Y-m-d H:i:s', strtotime( "-{$days_ago} days", strtotime( $now_local ) ) );

	// For completed/failed/canceled actions, last_attempt should be set
	$completed_days_ago = rand( 0, $days_ago );
	$last_attempt_gmt   = date( 'Y-m-d H:i:s', strtotime( "-{$completed_days_ago} days", strtotime( $now ) ) );
	$last_attempt_local = date( 'Y-m-d H:i:s', strtotime( "-{$completed_days_ago} days", strtotime( $now_local ) ) );

	// Generate realistic args - Action Scheduler uses PHP serialize() for args
	// The args field should be a serialized array of arguments passed to the hook
	$args_array = array();

	// Randomly generate 1-3 arguments (simple types work best)
	$arg_count = rand( 1, 3 );
	for ( $i = 0; $i < $arg_count; $i++ ) {
		// Use simple types that Action Scheduler commonly uses
		$arg_type = rand( 0, 1 );
		if ( $arg_type === 0 ) {
			$args_array[] = rand( 1, 99999 ); // Integer (common: order_id, product_id, etc.)
		} else {
			$args_array[] = (string) rand( 1, 1000 ); // String
		}
	}

	// Action Scheduler stores args using PHP serialize(), not JSON
	$args_serialized = maybe_serialize( $args_array );

	// Generate schedule (some actions are recurring)
	$schedules = array( '', 'hourly', 'daily', 'weekly', 'monthly' );
	$schedule  = $schedules[ array_rand( $schedules ) ];

	// Group ID (some actions belong to groups)
	$group_id = rand( 0, 100 ) > 70 ? rand( 1, 50 ) : 0;

	// Attempts - completed actions typically have 1 attempt, failed might have multiple
	$attempts = $status === 'failed' ? rand( 1, 5 ) : ( $status === 'complete' ? 1 : ( $status === 'canceled' ? 0 : 1 ) );

	// Claim ID should be NULL for completed/failed/canceled actions
	$claim_id = null;

	// Extended args (can be null or serialized)
	$extended_args = rand( 0, 100 ) > 80 ? maybe_serialize( array( 'priority' => rand( 1, 10 ) ) ) : null;

	return array(
		'hook'                 => $hook,
		'status'               => $status,
		'scheduled_date_gmt'   => $scheduled_date,
		'scheduled_date_local' => $scheduled_date_local,
		'args'                 => $args_serialized,
		'schedule'             => $schedule,
		'group_id'             => $group_id,
		'attempts'             => $attempts,
		'last_attempt_gmt'     => $last_attempt_gmt,
		'last_attempt_local'   => $last_attempt_local,
		'claim_id'             => $claim_id,
		'extended_args'        => $extended_args,
	);
}

/**
 * Generate realistic log data
 */
function winningsolutions_generate_log_data( $action_id ) {
	$now       = current_time( 'mysql', true );
	$now_local = current_time( 'mysql', false );

	// Log dates should be around the action's scheduled date, but can vary
	$days_ago       = rand( 0, 90 );
	$log_date_gmt   = date( 'Y-m-d H:i:s', strtotime( "-{$days_ago} days", strtotime( $now ) ) );
	$log_date_local = date( 'Y-m-d H:i:s', strtotime( "-{$days_ago} days", strtotime( $now_local ) ) );

	// Realistic log messages
	$messages = array(
		'Action started',
		'Action completed successfully',
		'Action failed: Timeout error',
		'Action failed: Database connection error',
		'Retrying action (attempt ' . rand( 1, 5 ) . ')',
		'Action canceled by user',
		'Action scheduled for execution',
		'Action execution in progress',
		'Action completed with warnings',
		'Action skipped: Conditions not met',
	);

	$message = $messages[ array_rand( $messages ) ];

	return array(
		'action_id'      => $action_id,
		'message'        => $message,
		'log_date_gmt'   => $log_date_gmt,
		'log_date_local' => $log_date_local,
	);
}

/**
 * Populate actions table
 */
function winningsolutions_populate_actions_table() {
	global $wpdb, $realistic_hooks, $statuses;

	$actions_table = $wpdb->prefix . 'actionscheduler_actions';
	$current_size  = winningsolutions_get_table_size( $actions_table );

	echo '<p>Populating actions table... Current size: ' . winningsolutions_format_bytes( $current_size ) . '</p>';
	flush();

	$inserted = 0;

	while ( $current_size < WSACSC_TARGET_SIZE_BYTES ) {
		$values = array();

		// Generate batch of actions
		for ( $i = 0; $i < WSACSC_BATCH_SIZE; $i++ ) {
			$hook        = $realistic_hooks[ array_rand( $realistic_hooks ) ];
			$status      = $statuses[ array_rand( $statuses ) ];
			$action_data = winningsolutions_generate_action_data( $hook, $status );

			// Build SQL values with proper escaping and NULL handling
			$hook_escaped            = esc_sql( $action_data['hook'] );
			$status_escaped          = esc_sql( $action_data['status'] );
			$scheduled_gmt_escaped   = esc_sql( $action_data['scheduled_date_gmt'] );
			$scheduled_local_escaped = esc_sql( $action_data['scheduled_date_local'] );
			$args_escaped            = esc_sql( $action_data['args'] );
			$schedule_escaped        = esc_sql( $action_data['schedule'] );
			$last_attempt_gmt        = $action_data['last_attempt_gmt'] ? "'" . esc_sql( $action_data['last_attempt_gmt'] ) . "'" : 'NULL';
			$last_attempt_local      = $action_data['last_attempt_local'] ? "'" . esc_sql( $action_data['last_attempt_local'] ) . "'" : 'NULL';
			$claim_id                = $action_data['claim_id'] !== null ? (int) $action_data['claim_id'] : 'NULL';
			$extended_args           = $action_data['extended_args'] ? "'" . esc_sql( $action_data['extended_args'] ) . "'" : 'NULL';

			$values[] = sprintf(
				"('%s', '%s', '%s', '%s', '%s', '%s', %d, %d, %s, %s, %s, %s)",
				$hook_escaped,
				$status_escaped,
				$scheduled_gmt_escaped,
				$scheduled_local_escaped,
				$args_escaped,
				$schedule_escaped,
				$action_data['group_id'],
				$action_data['attempts'],
				$last_attempt_gmt,
				$last_attempt_local,
				$claim_id,
				$extended_args
			);
		}

		// Insert batch
		$values_string = implode( ', ', $values );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$query = "INSERT INTO `{$actions_table}` 
            (hook, status, scheduled_date_gmt, scheduled_date_local, args, schedule, group_id, attempts, last_attempt_gmt, last_attempt_local, claim_id, extended_args)
            VALUES {$values_string}";

		$result = $wpdb->query( $query );

		if ( $result === false ) {
			echo "<p style='color: red;'>Error inserting actions: " . esc_html( $wpdb->last_error ) . '</p>';
			break;
		}

		$inserted += $result;

		// Check size every 10 batches
		if ( $inserted % ( WSACSC_BATCH_SIZE * 10 ) === 0 ) {
			$current_size = winningsolutions_get_table_size( $actions_table );
			echo "<p>Inserted {$inserted} actions. Current size: " . winningsolutions_format_bytes( $current_size ) . '</p>';
			flush();
		}

		// Prevent infinite loop
		if ( $inserted > 1000000 ) {
			echo "<p style='color: orange;'>Stopped after 1 million inserts to prevent infinite loop.</p>";
			break;
		}
	}

	// Final size check
	$final_size = winningsolutions_get_table_size( $actions_table );
	echo "<p><strong>Actions table populated:</strong> {$inserted} rows inserted. Final size: " . winningsolutions_format_bytes( $final_size ) . '</p>';

	return $inserted;
}

/**
 * Populate logs table
 */
function winningsolutions_populate_logs_table() {
	global $wpdb;

	$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
	$actions_table = $wpdb->prefix . 'actionscheduler_actions';

	// Get existing action IDs to link logs to
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$action_ids = $wpdb->get_col( "SELECT action_id FROM `{$actions_table}` ORDER BY RAND() LIMIT 10000" );

	if ( empty( $action_ids ) ) {
		echo "<p style='color: orange;'>No actions found. Please populate actions table first.</p>";
		return 0;
	}

	$current_size = winningsolutions_get_table_size( $logs_table );

	echo '<p>Populating logs table... Current size: ' . winningsolutions_format_bytes( $current_size ) . '</p>';
	flush();

	$inserted        = 0;
	$action_id_index = 0;

	while ( $current_size < WSACSC_TARGET_SIZE_BYTES ) {
		$values = array();

		// Generate batch of logs
		for ( $i = 0; $i < WSACSC_BATCH_SIZE; $i++ ) {
			// Cycle through action IDs
			$action_id = $action_ids[ $action_id_index % count( $action_ids ) ];
			++$action_id_index;

			$log_data = winningsolutions_generate_log_data( $action_id );

			$values[] = $wpdb->prepare(
				'(%d, %s, %s, %s)',
				$log_data['action_id'],
				$log_data['message'],
				$log_data['log_date_gmt'],
				$log_data['log_date_local']
			);
		}

		// Insert batch
		$values_string = implode( ', ', $values );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$query = "INSERT INTO `{$logs_table}` 
            (action_id, message, log_date_gmt, log_date_local)
            VALUES {$values_string}";

		$result = $wpdb->query( $query );

		if ( $result === false ) {
			echo "<p style='color: red;'>Error inserting logs: " . esc_html( $wpdb->last_error ) . '</p>';
			break;
		}

		$inserted += $result;

		// Check size every 10 batches
		if ( $inserted % ( WSACSC_BATCH_SIZE * 10 ) === 0 ) {
			$current_size = winningsolutions_get_table_size( $logs_table );
			echo "<p>Inserted {$inserted} logs. Current size: " . winningsolutions_format_bytes( $current_size ) . '</p>';
			flush();
		}

		// Prevent infinite loop
		if ( $inserted > 1000000 ) {
			echo "<p style='color: orange;'>Stopped after 1 million inserts to prevent infinite loop.</p>";
			break;
		}
	}

	// Final size check
	$final_size = winningsolutions_get_table_size( $logs_table );
	echo "<p><strong>Logs table populated:</strong> {$inserted} rows inserted. Final size: " . winningsolutions_format_bytes( $final_size ) . '</p>';

	return $inserted;
}

// Start output
?>
<!DOCTYPE html>
<html>
<head>
	<title>WS Action Scheduler Cleaner - Test Data Populator</title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			max-width: 800px;
			margin: 50px auto;
			padding: 20px;
			background: #f0f0f1;
		}
		.container {
			background: white;
			padding: 30px;
			border-radius: 4px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.1);
		}
		h1 {
			margin-top: 0;
			color: #1d2327;
		}
		p {
			line-height: 1.6;
			color: #50575e;
		}
		.warning {
			background: #fff3cd;
			border-left: 4px solid #ffb900;
			padding: 15px;
			margin: 20px 0;
		}
		.success {
			color: #00a32a;
		}
		.error {
			color: #d63638;
		}
	</style>
</head>
<body>
	<div class="container">
		<h1>WS Action Scheduler Cleaner - Test Data Populator</h1>
		
		<div class="warning">
			<strong>WARNING:</strong> This script will populate your Action Scheduler tables with test data. 
			Only run this on development/test environments. Make sure you have a database backup.
		</div>
		
		<?php
		// Check if tables exist
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		$actions_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_table ) ) === $actions_table;
		$logs_exist    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table;

		if ( ! $actions_exist || ! $logs_exist ) {
			echo "<p class='error'><strong>Error:</strong> Action Scheduler tables do not exist. Please ensure Action Scheduler is installed and activated.</p>";
		} else {
			echo '<p>Target size per table: <strong>' . WSACSC_TARGET_SIZE_MB . ' MB</strong></p>';

			// Diagnostic: Check existing actions structure
			echo '<p><strong>Diagnostic Information:</strong></p>';
			$sample_query  = $wpdb->prepare(
				"SELECT * FROM `{$actions_table}` WHERE status IN ('complete', 'failed', 'canceled') LIMIT 1"
			);
			$sample_action = $wpdb->get_row( $sample_query, ARRAY_A );
			if ( $sample_action ) {
				echo '<p>Sample existing action found. Hook: ' . esc_html( $sample_action['hook'] ) . ', Status: ' . esc_html( $sample_action['status'] ) . '</p>';
				echo '<p>Args format: ' . ( is_serialized( $sample_action['args'] ) ? 'Serialized' : 'Not serialized' ) . '</p>';
			} else {
				echo '<p>No existing actions found with these statuses. Will create new ones.</p>';
			}

			echo '<p>Starting population process...</p>';
			echo '<hr>';

			// Populate actions table
			$actions_inserted = winningsolutions_populate_actions_table();

			echo '<hr>';

			// Populate logs table
			$logs_inserted = winningsolutions_populate_logs_table();

			echo '<hr>';
			echo "<p class='success'><strong>Process completed!</strong></p>";
			echo "<p>Actions inserted: {$actions_inserted}</p>";
			echo "<p>Logs inserted: {$logs_inserted}</p>";

			// Clear WordPress object cache to ensure fresh data
			wp_cache_flush();
			echo "<p><em>WordPress cache cleared. If actions still don't appear in the admin page, try refreshing the page or clearing your browser cache.</em></p>";

			// Show final table sizes
			$final_actions_size = winningsolutions_get_table_size( $actions_table );
			$final_logs_size    = winningsolutions_get_table_size( $logs_table );

			echo '<p><strong>Final table sizes:</strong></p>';
			echo '<ul>';
			echo '<li>Actions table: ' . winningsolutions_format_bytes( $final_actions_size ) . '</li>';
			echo '<li>Logs table: ' . winningsolutions_format_bytes( $final_logs_size ) . '</li>';
			echo '</ul>';

			// Diagnostic: Verify inserted actions
			echo '<p><strong>Verification:</strong></p>';
			$verify_query   = $wpdb->prepare(
				"SELECT COUNT(*) as count, status FROM `{$actions_table}` WHERE status IN ('complete', 'failed', 'canceled') GROUP BY status"
			);
			$verify_results = $wpdb->get_results( $verify_query, ARRAY_A );
			if ( $verify_results ) {
				echo '<ul>';
				foreach ( $verify_results as $result ) {
					echo '<li>' . esc_html( ucfirst( $result['status'] ) ) . ': ' . number_format( (int) $result['count'] ) . ' actions</li>';
				}
				echo '</ul>';
			}

			// Check a sample of our inserted actions
			$sample_new = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM `{$actions_table}` WHERE status IN ('complete', 'failed', 'canceled') ORDER BY action_id DESC LIMIT 1" ),
				ARRAY_A
			);
			if ( $sample_new ) {
				echo '<p><strong>Sample inserted action:</strong></p>';
				echo '<ul>';
				echo '<li>Action ID: ' . (int) $sample_new['action_id'] . '</li>';
				echo '<li>Hook: ' . esc_html( $sample_new['hook'] ) . '</li>';
				echo '<li>Status: ' . esc_html( $sample_new['status'] ) . '</li>';
				echo '<li>Scheduled: ' . esc_html( $sample_new['scheduled_date_gmt'] ) . '</li>';
				echo '<li>Last Attempt: ' . ( $sample_new['last_attempt_gmt'] ? esc_html( $sample_new['last_attempt_gmt'] ) : 'NULL' ) . '</li>';
				echo '<li>Args (first 100 chars): ' . esc_html( substr( $sample_new['args'], 0, 100 ) ) . '</li>';
				echo '<li>Args is serialized: ' . ( is_serialized( $sample_new['args'] ) ? 'Yes' : 'No' ) . '</li>';
				echo '<li>Claim ID: ' . ( $sample_new['claim_id'] ? (int) $sample_new['claim_id'] : 'NULL' ) . '</li>';
				echo '</ul>';
			}
		}
		?>
	</div>
</body>
</html>
