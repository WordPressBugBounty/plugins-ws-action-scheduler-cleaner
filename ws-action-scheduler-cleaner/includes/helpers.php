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
 * Allowed date columns for actions retention cleanup (SQL identifier whitelist).
 *
 * @return string[]
 */
function wsacsc_get_allowed_actions_retention_date_columns(): array {
	return array( 'completed_date_gmt', 'last_attempt_gmt', 'scheduled_date_gmt' );
}

/**
 * Date column used for scheduled actions retention cleanup.
 *
 * Prefers completion/last-attempt time to match Action Scheduler's built-in cleaner.
 *
 * @return string One of completed_date_gmt, last_attempt_gmt, scheduled_date_gmt.
 */
function wsacsc_get_actions_retention_date_column(): string {
	static $cached_column = null;

	if ( null !== $cached_column ) {
		return $cached_column;
	}

	$preferred     = array( 'completed_date_gmt', 'last_attempt_gmt', 'scheduled_date_gmt' );
	$cached_column = 'scheduled_date_gmt';

	if ( ! WSACSC_Database::check_tables_exist() ) {
		return $cached_column;
	}

	global $wpdb;

	$actions_table   = $wpdb->prefix . 'actionscheduler_actions';
	$columns_query   = $wpdb->prepare( 'DESC %i', $actions_table );
	$columns_result  = $wpdb->get_results( $columns_query );
	$columns         = is_array( $columns_result ) ? wp_list_pluck( $columns_result, 'Field' ) : array();
	$allowed_columns = wsacsc_get_allowed_actions_retention_date_columns();

	foreach ( $preferred as $column ) {
		if ( in_array( $column, $columns, true ) && in_array( $column, $allowed_columns, true ) ) {
			$cached_column = $column;
			break;
		}
	}

	return $cached_column;
}

/**
 * Build WHERE clause and parameters for scheduled actions retention cleanup.
 *
 * @param string[] $selected_statuses Status slugs to delete.
 * @param int      $retention_days    Retention in days (0 = all matching statuses).
 * @return array{where_clause: string, where_params: array} Empty where_clause if invalid.
 */
function wsacsc_build_actions_retention_where( array $selected_statuses, int $retention_days ): array {
	$allowed_statuses  = wsacsc_get_allowed_action_statuses();
	$selected_statuses = array_values( array_intersect( $selected_statuses, $allowed_statuses ) );

	if ( empty( $selected_statuses ) ) {
		return array(
			'where_clause' => '',
			'where_params' => array(),
		);
	}

	$placeholders = implode( ',', array_fill( 0, count( $selected_statuses ), '%s' ) );

	if ( 0 === $retention_days ) {
		return array(
			'where_clause' => 'status IN (' . $placeholders . ')',
			'where_params' => $selected_statuses,
		);
	}

	$date_column = wsacsc_get_actions_retention_date_column();

	return array(
		'where_clause' => 'status IN (' . $placeholders . ') AND `' . $date_column . '` < DATE_SUB(NOW(), INTERVAL %d DAY)',
		'where_params' => array_merge( $selected_statuses, array( $retention_days ) ),
	);
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
	} elseif ( preg_match( '/^status IN \((%s(?:,\s*%s)*)\) AND `(completed_date_gmt|last_attempt_gmt|scheduled_date_gmt)` < DATE_SUB\(NOW\(\), INTERVAL %d DAY\)$/', $where_clause, $matches ) ) {
		if ( $actions_table !== $table ) {
			return false;
		}

		if ( ! in_array( $matches[2], wsacsc_get_allowed_actions_retention_date_columns(), true ) ) {
			return false;
		}

		$status_placeholder_count = substr_count( $matches[1], '%s' );
		if ( count( $where_params ) !== $status_placeholder_count + 1 ) {
			return false;
		}

		$retention_days = (int) $where_params[ count( $where_params ) - 1 ];
		if ( $retention_days < 1 || $retention_days > 365 ) {
			return false;
		}

		foreach ( array_slice( $where_params, 0, -1 ) as $param ) {
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
