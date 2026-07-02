<?php
/**
 * Power tool: run a governed WP-CLI command.
 *
 * Safety model:
 *  1. OFF unless `AURA_POWER_ALLOW_WP_CLI` is set in wp-config.
 *  2. requires_approval=true — a human approves the exact command first.
 *  3. Allowlisted first subcommand + denied dangerous subcommands.
 *  4. No shell: arguments are tokenised and passed to proc_open as an argv
 *     array, and any token containing shell metacharacters is refused — so a
 *     command string can never break out into the shell.
 *
 * @package Aura_Worker_Power_Pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Power_Tool_Wp_Cli extends Aura_Tool_Base {

	/**
	 * First subcommands the tool will run (read + common ops). Everything else
	 * is refused.
	 */
	const ALLOWED = array(
		'cache', 'core', 'cron', 'option', 'plugin', 'post', 'rewrite', 'role',
		'taxonomy', 'term', 'theme', 'transient', 'user', 'menu', 'media',
		'comment', 'sidebar', 'widget', 'language',
	);

	/**
	 * Dangerous subcommand paths refused even inside an allowed family.
	 */
	const DENIED = array(
		'eval', 'eval-file', 'shell', 'server', 'package', 'config', 'db',
		'plugin install', 'theme install', 'core download', 'core update',
	);

	/**
	 * Shell metacharacters that must never appear in any argument token.
	 */
	const META = array( ';', '|', '&', '`', '$', '>', '<', '(', ')', '{', '}', "\n", "\r", '\\' );

	/**
	 * The ONLY `--flags` a caller may pass. Positive allowlist: any other `--flag`
	 * is refused. This blocks WP-CLI global runtime flags that load/run code —
	 * --require, --exec, --ssh, --http, --path, --url, --user, --prompt, --debug —
	 * which would otherwise turn an allowlisted subcommand into arbitrary code
	 * execution. `--allow-root` / `--no-color` / `--skip-packages` are added by
	 * this tool, never accepted from the caller.
	 */
	const SAFE_FLAGS = array(
		'status', 'format', 'field', 'fields', 'porcelain', 'skip-plugins', 'skip-themes',
		'all', 'network', 'quiet', 'name', 'slug', 'per-page', 'number', 'orderby', 'order',
		'post_type', 'post_status', 'role', 'parent', 'depth', 'search', 'columns',
	);

	/**
	 * Max wall-clock seconds for a WP-CLI run before it is killed.
	 */
	const TIME_LIMIT = 60;

	/**
	 * Max bytes captured per stream (stdout/stderr) before truncation.
	 */
	const MAX_OUTPUT = 262144;

	public function get_name() {
		return 'run_wp_cli';
	}

	public function get_description() {
		return 'Run an allowlisted WP-CLI command (no shell, no metacharacters). Refuses eval/config/db/shell and installs. Disabled unless AURA_POWER_ALLOW_WP_CLI is set in wp-config.';
	}

	public function get_parameters() {
		return array(
			'command' => array(
				'type'        => 'string',
				'description' => 'WP-CLI command without the leading "wp" (e.g. "plugin list --status=active").',
				'required'    => true,
			),
		);
	}

	public function get_returns() {
		return array(
			'exit_code' => array( 'type' => 'integer' ),
			'stdout'    => array( 'type' => 'string' ),
			'stderr'    => array( 'type' => 'string' ),
		);
	}

	public function get_annotations() {
		// Destructive: the allowed families include user/plugin/theme/post delete,
		// option update (siteurl/active_plugins), user password reset, etc. The
		// approver must see this as a high-impact action.
		return array(
			'read_only'         => false,
			'destructive'       => true,
			'requires_approval' => true,
			'supports_preview'  => true,
		);
	}

	/**
	 * Tokenise a command into whitespace-separated argv (no shell parsing).
	 *
	 * @param string $command Command string.
	 * @return string[]
	 */
	private function tokenize( $command ) {
		$parts = preg_split( '/\s+/', trim( (string) $command ) );
		return array_values( array_filter( $parts, static function ( $p ) {
			return '' !== $p;
		} ) );
	}

	/**
	 * Validate a command. Returns true when allowed, else an error string.
	 *
	 * @param string $command Command string.
	 * @return string|true
	 */
	private function reject_reason( $command ) {
		$tokens = $this->tokenize( $command );
		if ( empty( $tokens ) ) {
			return 'Empty command.';
		}

		// Split flags from positional args. WP-CLI parses flags regardless of
		// position, so ALLOWED/DENIED must be evaluated against the POSITIONAL
		// subcommand chain — otherwise inserting a safe flag between the family
		// and the real subcommand shifts it out of view (e.g.
		// "plugin --skip-plugins install <url>" would dodge a raw-index check).
		$positionals = array();
		foreach ( $tokens as $t ) {
			foreach ( self::META as $meta ) {
				if ( false !== strpos( $t, $meta ) ) {
					return 'Refused: shell metacharacter in "' . $t . '".';
				}
			}

			if ( '' !== $t && '-' === $t[0] ) {
				// Any dash-prefixed token is a flag: positive allowlist. This blocks
				// global runtime flags that load/run code (--require/--exec/--ssh/
				// --http/--path/--url/--user) and any unknown short flag.
				$flag = ltrim( $t, '-' );
				$eq   = strpos( $flag, '=' );
				if ( false !== $eq ) {
					$flag = substr( $flag, 0, $eq );
				}
				if ( ! in_array( strtolower( $flag ), self::SAFE_FLAGS, true ) ) {
					return 'Refused: flag "' . $t . '" is not permitted.';
				}
				continue;
			}

			$positionals[] = $t;
		}

		if ( empty( $positionals ) ) {
			return 'Refused: no subcommand.';
		}

		$first = strtolower( $positionals[0] );
		if ( ! in_array( $first, self::ALLOWED, true ) ) {
			return 'Refused: "' . $first . '" is not an allowed WP-CLI command family.';
		}

		$first_two = trim( $first . ' ' . ( isset( $positionals[1] ) ? strtolower( $positionals[1] ) : '' ) );
		foreach ( self::DENIED as $deny ) {
			if ( $first === $deny || $first_two === $deny ) {
				return 'Refused: "' . $deny . '" is not permitted.';
			}
		}

		return true;
	}

	/**
	 * Locate the wp-cli binary, if configured.
	 *
	 * @return string|null
	 */
	private function wp_binary() {
		if ( defined( 'AURA_POWER_WP_CLI_BIN' ) && AURA_POWER_WP_CLI_BIN ) {
			return (string) AURA_POWER_WP_CLI_BIN;
		}
		return 'wp';
	}

	public function dry_run( $params ) {
		$command = isset( $params['command'] ) ? (string) $params['command'] : '';
		$reason  = $this->reject_reason( $command );
		$tokens  = $this->tokenize( $command );
		return array(
			'allowed'     => ( true === $reason ),
			'reason'      => ( true === $reason ) ? null : $reason,
			'argv'        => array_merge( array( $this->wp_binary() ), $tokens, array( '--allow-root', '--no-color', '--skip-packages' ) ),
			'enabled'     => defined( 'AURA_POWER_ALLOW_WP_CLI' ) && AURA_POWER_ALLOW_WP_CLI,
		);
	}

	public function execute( $params ) {
		if ( ! ( defined( 'AURA_POWER_ALLOW_WP_CLI' ) && AURA_POWER_ALLOW_WP_CLI ) ) {
			return array( 'error' => 'WP-CLI execution is disabled. Set AURA_POWER_ALLOW_WP_CLI in wp-config to enable.' );
		}

		$command = isset( $params['command'] ) ? (string) $params['command'] : '';
		$reason  = $this->reject_reason( $command );
		if ( true !== $reason ) {
			return array( 'error' => $reason );
		}

		if ( ! function_exists( 'proc_open' ) ) {
			return array( 'error' => 'proc_open is disabled on this host; WP-CLI cannot run.' );
		}

		// --skip-packages: never autoload third-party WP-CLI packages (a code path).
		$argv = array_merge(
			array( $this->wp_binary() ),
			$this->tokenize( $command ),
			array( '--allow-root', '--no-color', '--skip-packages' )
		);

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// Pass argv as an array → no shell, no interpolation, no injection.
		$process = proc_open( $argv, $descriptors, $pipes, ABSPATH );
		if ( ! is_resource( $process ) ) {
			return array( 'error' => 'Failed to start WP-CLI process.' );
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout   = '';
		$stderr   = '';
		$deadline = time() + self::TIME_LIMIT;
		$timedout = false;

		// Bounded read loop: cap wall-clock and captured bytes so a hung or
		// noisy command can't pin a PHP worker or exhaust memory.
		do {
			$read   = array( $pipes[1], $pipes[2] );
			$write  = null;
			$except = null;
			$ready  = @stream_select( $read, $write, $except, 1 );

			if ( false !== $ready && $ready > 0 ) {
				foreach ( $read as $stream ) {
					$chunk = fread( $stream, 8192 );
					if ( '' === $chunk || false === $chunk ) {
						continue;
					}
					if ( $stream === $pipes[1] ) {
						if ( strlen( $stdout ) < self::MAX_OUTPUT ) {
							$stdout .= substr( $chunk, 0, self::MAX_OUTPUT - strlen( $stdout ) );
						}
					} elseif ( strlen( $stderr ) < self::MAX_OUTPUT ) {
						$stderr .= substr( $chunk, 0, self::MAX_OUTPUT - strlen( $stderr ) );
					}
				}
			}

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				break;
			}
			if ( time() >= $deadline ) {
				$timedout = true;
				proc_terminate( $process, 9 );
				break;
			}
		} while ( true );

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );

		return array(
			'exit_code' => (int) $exit,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'timed_out' => $timedout,
			'truncated' => ( strlen( $stdout ) >= self::MAX_OUTPUT || strlen( $stderr ) >= self::MAX_OUTPUT ),
		);
	}
}
