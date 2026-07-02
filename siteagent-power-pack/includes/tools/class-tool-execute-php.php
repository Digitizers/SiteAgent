<?php
/**
 * Power tool: execute a PHP snippet (governed) — the Novamira-class capability,
 * made production-safe by governance rather than by a (bypassable) sandbox.
 *
 * Safety model (the human + the constant are the gate; the scan is advisory):
 *  1. OFF unless `AURA_POWER_EXECUTE_PHP` is set true in wp-config. Installing
 *     the pack does NOT enable eval.
 *  2. requires_approval=true — never inline; a human approves the exact code,
 *     seeing a static-scan verdict first (supports_preview / dry_run).
 *  3. Shell-outs are HARD-denied (exec/shell_exec/system/passthru/proc_open/
 *     popen/pcntl_exec + backticks) — use the run_wp_cli tool for shell work;
 *     execute_php is for PHP-level operations only.
 *  4. A static scan flags risky-but-allowed constructs (eval, base64, file
 *     writes, network, superglobals) so the approver sees them.
 *  5. Time-limited execution with output/return/error capture.
 *
 * The scan is NOT a security boundary (PHP is too dynamic to statically sandbox).
 * The real controls are the wp-config constant + human approval + audit trail.
 *
 * @package Aura_Worker_Power_Pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Power_Tool_Execute_Php extends Aura_Tool_Base {

	/**
	 * Execution time limit (seconds).
	 */
	const TIME_LIMIT = 30;

	/**
	 * Shell-out functions — hard-denied (use run_wp_cli instead).
	 */
	const HARD_DENY = array(
		'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
	);

	/**
	 * Risky-but-allowed constructs — surfaced in the scan for the approver.
	 */
	const WARN = array(
		'eval', 'assert', 'create_function', 'call_user_func', 'call_user_func_array',
		'base64_decode', 'gzinflate', 'gzuncompress', 'str_rot13',
		'file_put_contents', 'fwrite', 'fputs', 'fopen', 'unlink', 'rename', 'chmod', 'mkdir', 'rmdir',
		'curl_exec', 'fsockopen', 'stream_socket_client', 'file_get_contents',
		'ini_set', 'putenv', 'define', 'extract', 'parse_str',
	);

	public function get_name() {
		return 'execute_php';
	}

	public function get_description() {
		return 'Execute a PHP snippet with $wpdb and the full WP API. An approved snippet has full PHP (RCE-equivalent) power: the shell-out deny-list and static scan are BEST-EFFORT / advisory only, not a sandbox — the real controls are the wp-config constant, human approval of the exact code, and the audit trail. Disabled unless AURA_POWER_EXECUTE_PHP is set; always requires approval.';
	}

	public function get_parameters() {
		return array(
			'code' => array(
				'type'        => 'string',
				'description' => 'PHP code to execute (no opening <?php tag). Use `return` to return a value.',
				'required'    => true,
			),
		);
	}

	public function get_returns() {
		return array(
			'output' => array( 'type' => 'string' ),
			'return' => array( 'type' => 'mixed' ),
			'error'  => array( 'type' => 'string' ),
		);
	}

	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => true,
			'requires_approval' => true,
			'supports_preview'  => true,
		);
	}

	/**
	 * Detect a hard-denied shell construct. Returns an error string or true.
	 *
	 * @param string $code Source code.
	 * @return string|true
	 */
	private function hard_deny_reason( $code ) {
		if ( false !== strpos( $code, '`' ) ) {
			return 'Refused: backtick shell execution is not allowed. Use run_wp_cli.';
		}
		foreach ( self::HARD_DENY as $fn ) {
			if ( preg_match( '/\b' . preg_quote( $fn, '/' ) . '\s*\(/i', $code ) ) {
				return 'Refused: shell function ' . $fn . '() is not allowed. Use run_wp_cli.';
			}
		}
		return true;
	}

	/**
	 * Collect advisory warnings (risky-but-allowed constructs).
	 *
	 * @param string $code Source code.
	 * @return string[]
	 */
	private function scan_warnings( $code ) {
		$found = array();
		foreach ( self::WARN as $fn ) {
			if ( preg_match( '/\b' . preg_quote( $fn, '/' ) . '\s*\(/i', $code ) ) {
				$found[] = $fn;
			}
		}
		if ( preg_match( '/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES|ENV)\b/', $code ) ) {
			$found[] = 'superglobal';
		}
		// Statement-form / non-call constructs the \bFUNC\s*\( scan misses but
		// that still reach code/native execution — surface them for the approver.
		if ( preg_match( '/\b(include|include_once|require|require_once)\b/i', $code ) ) {
			$found[] = 'include/require';
		}
		if ( preg_match( '/\bFFI\b/i', $code ) ) {
			$found[] = 'FFI';
		}
		if ( preg_match( '/\bdl\s*\(/i', $code ) ) {
			$found[] = 'dl';
		}
		// Indirect calls can hide a shell-out from the deny-list (e.g. $f('system')).
		if ( preg_match( '/\$[a-zA-Z_]\w*\s*\(/', $code ) ) {
			$found[] = 'variable-function-call';
		}
		return array_values( array_unique( $found ) );
	}

	public function dry_run( $params ) {
		$code = isset( $params['code'] ) ? (string) $params['code'] : '';
		$deny = $this->hard_deny_reason( $code );
		return array(
			'hard_denied' => ( true === $deny ) ? null : $deny,
			'warnings'    => $this->scan_warnings( $code ),
			'bytes'       => strlen( $code ),
			'enabled'     => defined( 'AURA_POWER_EXECUTE_PHP' ) && AURA_POWER_EXECUTE_PHP,
			'would_run'   => ( true === $deny ) && defined( 'AURA_POWER_EXECUTE_PHP' ) && AURA_POWER_EXECUTE_PHP,
		);
	}

	public function execute( $params ) {
		if ( ! ( defined( 'AURA_POWER_EXECUTE_PHP' ) && AURA_POWER_EXECUTE_PHP ) ) {
			return array( 'error' => 'PHP execution is disabled. Set AURA_POWER_EXECUTE_PHP in wp-config to enable.' );
		}

		$code = isset( $params['code'] ) ? (string) $params['code'] : '';
		$deny = $this->hard_deny_reason( $code );
		if ( true !== $deny ) {
			return array( 'error' => $deny );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_LIMIT );
		}

		$return = null;
		$error  = null;
		ob_start();
		try {
			$return = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- governed power tool: constant-gated + approval-gated + audited.
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}
		$output = ob_get_clean();

		return array(
			'output' => (string) $output,
			'return' => $return,
			'error'  => $error,
		);
	}
}
