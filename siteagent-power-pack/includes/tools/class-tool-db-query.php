<?php
/**
 * Power tool: run a read-only SQL query.
 *
 * SELECT / SHOW / EXPLAIN / DESCRIBE only, single statement, row-capped, with
 * file-access functions (INTO OUTFILE / LOAD_FILE) refused. Because a read
 * query can still surface sensitive rows (password hashes, tokens), the tool
 * declares requires_approval=true — the Aura gateway queues it for a human
 * before it runs.
 *
 * @package Aura_Worker_Power_Pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Power_Tool_Db_Query extends Aura_Tool_Base {

	/**
	 * Default and hard row caps.
	 */
	const DEFAULT_LIMIT = 200;
	const HARD_LIMIT    = 1000;

	public function get_name() {
		return 'db_query';
	}

	public function get_description() {
		return 'Run a single read-only SQL statement (SELECT/SHOW/EXPLAIN/DESCRIBE) against the WordPress database. Row-capped; refuses writes, multiple statements, and file access. Requires approval.';
	}

	public function get_parameters() {
		return array(
			'query' => array(
				'type'        => 'string',
				'description' => 'A single read-only SQL statement.',
				'required'    => true,
			),
			'limit' => array(
				'type'        => 'integer',
				'description' => 'Max rows to return (default 200, hard cap 1000).',
				'required'    => false,
			),
		);
	}

	public function get_returns() {
		return array(
			'row_count' => array( 'type' => 'integer' ),
			'truncated' => array( 'type' => 'boolean' ),
			'rows'      => array( 'type' => 'array' ),
		);
	}

	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => true,
			'supports_preview'  => false,
		);
	}

	/**
	 * Validate that a statement is a single read-only query.
	 *
	 * @param string $sql Raw SQL.
	 * @return string|true Error message, or true when the statement is allowed.
	 */
	private function reject_reason( $sql ) {
		$trimmed = trim( $sql );
		if ( '' === $trimmed ) {
			return 'Empty query.';
		}

		// Strip a single trailing semicolon, then refuse any remaining one
		// (defends against stacked statements).
		$trimmed = rtrim( $trimmed );
		$trimmed = preg_replace( '/;\s*$/', '', $trimmed );
		if ( false !== strpos( $trimmed, ';' ) ) {
			return 'Multiple statements are not allowed.';
		}

		if ( ! preg_match( '/^\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC)\b/i', $trimmed ) ) {
			return 'Only read-only queries (SELECT/SHOW/EXPLAIN/DESCRIBE) are allowed.';
		}

		if ( preg_match( '/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE)\b/i', $trimmed ) ) {
			return 'File access functions are not allowed.';
		}

		return true;
	}

	public function execute( $params ) {
		global $wpdb;

		$sql    = (string) $params['query'];
		$reason = $this->reject_reason( $sql );
		if ( true !== $reason ) {
			return array( 'error' => $reason );
		}

		$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : self::DEFAULT_LIMIT;
		if ( $limit < 1 || $limit > self::HARD_LIMIT ) {
			$limit = self::HARD_LIMIT;
		}

		$rows = $wpdb->get_results( trim( $sql ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$err = isset( $wpdb->last_error ) && '' !== $wpdb->last_error ? $wpdb->last_error : 'Query failed.';
			return array( 'error' => $err );
		}

		$truncated = count( $rows ) > $limit;
		if ( $truncated ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		return array(
			'row_count' => count( $rows ),
			'truncated' => $truncated,
			'rows'      => $rows,
		);
	}
}
