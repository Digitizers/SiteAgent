<?php
/**
 * MCP Tool: scan_error_log
 *
 * Read-only triage of the PHP/WordPress error log: tails the log, groups recent
 * entries by severity, and surfaces the most recent fatals.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Scan_Error_Log extends Aura_Tool_Base {

	public function get_name() {
		return 'scan_error_log';
	}

	public function get_description() {
		return 'Tails the active PHP/WordPress error log and groups recent entries by severity (fatal, warning, notice, deprecated, database), returning counts and the most recent fatal samples. Read-only.';
	}

	public function get_parameters() {
		return array(
			'lines' => array(
				'type'        => 'integer',
				'description' => 'How many of the most recent log lines to analyze (default 200, max 1000).',
				'required'    => false,
				'default'     => 200,
			),
		);
	}

	public function get_returns() {
		return array(
			'log_found'     => 'boolean',
			'file'          => 'string|null — log file name (no path)',
			'size_human'    => 'string|null',
			'analyzed_lines' => 'integer',
			'counts'        => 'array — { fatal, warning, notice, deprecated, database }',
			'recent_fatals' => 'array — up to 10 most recent fatal/uncaught lines',
		);
	}

	public function execute( $params ) {
		$max_lines = isset( $params['lines'] ) ? max( 1, min( 1000, (int) $params['lines'] ) ) : 200;

		$log_file = ini_get( 'error_log' );
		if ( empty( $log_file ) || ! file_exists( $log_file ) ) {
			$log_file = WP_CONTENT_DIR . '/debug.log';
		}
		if ( ! file_exists( $log_file ) || ! is_readable( $log_file ) ) {
			return array(
				'log_found'      => false,
				'file'           => null,
				'size_human'     => null,
				'analyzed_lines' => 0,
				'counts'         => array(
					'fatal'      => 0,
					'warning'    => 0,
					'notice'     => 0,
					'deprecated' => 0,
					'database'   => 0,
				),
				'recent_fatals'  => array(),
			);
		}

		// Tail the last ~128KB — enough for recent activity without loading a
		// potentially huge log into memory.
		$size      = filesize( $log_file );
		$read_size = min( $size, 131072 );
		$content   = '';
		$fp        = fopen( $log_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fp ) {
			if ( $size > $read_size ) {
				fseek( $fp, -$read_size, SEEK_END );
			}
			$content = fread( $fp, $read_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( (string) $content ) );
		$lines = array_filter( $lines, 'strlen' );
		if ( count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, -$max_lines );
		}

		$counts = array(
			'fatal'      => 0,
			'warning'    => 0,
			'notice'     => 0,
			'deprecated' => 0,
			'database'   => 0,
		);
		$recent_fatals = array();

		foreach ( $lines as $line ) {
			if ( preg_match( '/PHP Fatal error|Uncaught/i', $line ) ) {
				++$counts['fatal'];
				$recent_fatals[] = mb_substr( trim( $line ), 0, 300 );
			} elseif ( preg_match( '/PHP Warning/i', $line ) ) {
				++$counts['warning'];
			} elseif ( preg_match( '/PHP Notice/i', $line ) ) {
				++$counts['notice'];
			} elseif ( preg_match( '/PHP Deprecated/i', $line ) ) {
				++$counts['deprecated'];
			} elseif ( preg_match( '/WordPress database error/i', $line ) ) {
				++$counts['database'];
			}
		}

		// Keep only the 10 most recent fatals.
		if ( count( $recent_fatals ) > 10 ) {
			$recent_fatals = array_slice( $recent_fatals, -10 );
		}

		return array(
			'log_found'      => true,
			'file'           => basename( $log_file ),
			'size_human'     => size_format( $size ),
			'analyzed_lines' => count( $lines ),
			'counts'         => $counts,
			'recent_fatals'  => array_values( $recent_fatals ),
			'generated_at'   => gmdate( 'c' ),
		);
	}
}
