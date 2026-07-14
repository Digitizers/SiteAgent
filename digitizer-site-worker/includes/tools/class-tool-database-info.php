<?php
/**
 * MCP Tool: get_database_info
 *
 * Read-only database health snapshot: total size, largest tables, autoloaded
 * options weight, and expired transient count.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Database_Info extends Aura_Tool_Base {

	public function get_name() {
		return 'get_database_info';
	}

	public function get_description() {
		return 'Returns database health info: total size, the largest tables, table prefix, autoloaded options weight with the heaviest options, and the number of expired transients. Read-only.';
	}

	public function get_parameters() {
		return array(
			'table_limit' => array(
				'type'        => 'integer',
				'description' => 'How many of the largest tables to return (default 10, max 50).',
				'required'    => false,
				'default'     => 10,
			),
		);
	}

	public function get_returns() {
		return array(
			'database'           => 'string — database name',
			'table_prefix'       => 'string',
			'table_count'        => 'integer',
			'total_size_bytes'   => 'integer',
			'total_size_human'   => 'string',
			'largest_tables'     => 'array — { name, size_bytes, size_human, rows (approx) }',
			'autoload'           => 'array — { total_bytes, total_human, option_count, heaviest: [{ name, bytes, human }] }',
			'expired_transients' => 'integer — expired transient rows still in the options table',
		);
	}

	/**
	 * This is a read-only tool: it never mutates the site.
	 */
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		global $wpdb;

		$limit = isset( $params['table_limit'] ) ? max( 1, min( 50, (int) $params['table_limit'] ) ) : 10;

		// This tool reports live database weight, so every query below is a
		// deliberate direct call with no cache: information_schema and autoload
		// sums have no core API, and a cached answer would defeat the purpose of
		// a diagnostic that exists to show the database's state right now. The
		// `IN (...)` lists are %s placeholders built from a counted array and fed
		// to prepare(), so they are parameterised despite the interpolation.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Per-table sizes from information_schema (prepared on the schema name).
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name AS name, ( data_length + index_length ) AS size_bytes, table_rows AS row_count
				 FROM information_schema.TABLES
				 WHERE table_schema = %s
				 ORDER BY size_bytes DESC",
				DB_NAME
			)
		);

		$total_bytes = 0;
		$table_count = 0;
		$largest     = array();
		if ( is_array( $tables ) ) {
			$table_count = count( $tables );
			foreach ( $tables as $i => $t ) {
				$total_bytes += (int) $t->size_bytes;
				if ( $i < $limit ) {
					$largest[] = array(
						'name'       => $t->name,
						'size_bytes' => (int) $t->size_bytes,
						'size_human' => size_format( (int) $t->size_bytes ),
						'rows'       => (int) $t->row_count,
					);
				}
			}
		}

		// Autoloaded options weight. WP 6.6+ stores several "autoload on" values
		// (yes, on, auto-on, auto), so build the IN clause from core's list
		// instead of hard-coding 'yes' (which would under-report on new sites).
		// Guarded by function_exists because the plugin still supports WP 6.2.
		$autoload_values = function_exists( 'wp_autoload_values_to_autoload' )
			? wp_autoload_values_to_autoload()
			: array( 'yes' );
		$placeholders    = implode( ', ', array_fill( 0, count( $autoload_values ), '%s' ) );

		$autoload_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ($placeholders)",
				$autoload_values
			)
		);

		$autoload_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ($placeholders)",
				$autoload_values
			)
		);

		$heaviest_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name AS name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY bytes DESC LIMIT 10",
				$autoload_values
			)
		);
		$heaviest      = array();
		if ( is_array( $heaviest_rows ) ) {
			foreach ( $heaviest_rows as $r ) {
				$heaviest[] = array(
					'name'  => $r->name,
					'bytes' => (int) $r->bytes,
					'human' => size_format( (int) $r->bytes ),
				);
			}
		}

		// Expired transients still sitting in the options table.
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array(
			'database'           => DB_NAME,
			'table_prefix'       => $wpdb->prefix,
			'table_count'        => $table_count,
			'total_size_bytes'   => $total_bytes,
			'total_size_human'   => size_format( $total_bytes ),
			'largest_tables'     => $largest,
			'autoload'           => array(
				'total_bytes'  => $autoload_total,
				'total_human'  => size_format( $autoload_total ),
				'option_count' => $autoload_count,
				'heaviest'     => $heaviest,
			),
			'expired_transients' => $expired,
			'generated_at'       => gmdate( 'c' ),
		);
	}
}
