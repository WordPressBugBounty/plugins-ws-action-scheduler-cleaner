<?php
/**
 * Shared helpers for WS Action Scheduler Cleaner
 *
 * @package WS_Action_Scheduler_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether plugin diagnostic messages should be written to the error log.
 *
 * @return bool
 */
function wsacsc_should_log(): bool {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		return true;
	}

	return wp_is_development_mode( 'plugin' );
}

/**
 * Action statuses allowed for cleanup operations.
 *
 * @return string[]
 */
function wsacsc_get_allowed_action_statuses(): array {
	return array( 'complete', 'failed', 'canceled' );
}

/**
 * Validate cleanup progress payload stored in a transient.
 *
 * @param mixed $progress Progress array from transient.
 * @return array|false Sanitized progress array or false if invalid.
 */
function wsacsc_validate_cleanup_progress( $progress ) {
	global $wpdb;

	if ( ! is_array( $progress ) ) {
		return false;
	}

	$required_keys = array( 'table', 'where_clause', 'where_params', 'batch_size' );
	foreach ( $required_keys as $key ) {
		if ( ! array_key_exists( $key, $progress ) ) {
			return false;
		}
	}

	$actions_table = $wpdb->prefix . 'actionscheduler_actions';
	$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
	$table         = $progress['table'];

	if ( ! in_array( $table, array( $actions_table, $logs_table ), true ) ) {
		return false;
	}

	$where_clause = $progress['where_clause'];
	if ( ! is_string( $where_clause ) ) {
		return false;
	}

	$where_params = $progress['where_params'];
	if ( ! is_array( $where_params ) ) {
		return false;
	}

	$allowed_statuses = wsacsc_get_allowed_action_statuses();

	if ( '1=1' === $where_clause ) {
		if ( $logs_table !== $table || ! empty( $where_params ) ) {
			return false;
		}
	} elseif ( preg_match( '/^status IN \((%s(?:,\s*%s)*)\)$/', $where_clause ) ) {
		if ( $actions_table !== $table ) {
			return false;
		}

		$placeholder_count = substr_count( $where_clause, '%s' );
		if ( count( $where_params ) !== $placeholder_count ) {
			return false;
		}

		foreach ( $where_params as $param ) {
			if ( ! is_string( $param ) || ! in_array( $param, $allowed_statuses, true ) ) {
				return false;
			}
		}
	} else {
		return false;
	}

	$batch_size = absint( $progress['batch_size'] );
	if ( $batch_size < 1 || $batch_size > 50000 ) {
		return false;
	}

	$progress['batch_size'] = $batch_size;

	return $progress;
}
