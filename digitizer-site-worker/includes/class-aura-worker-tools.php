<?php
/**
 * MCP Tool Registry for SiteAgent.
 *
 * Loads all tool classes from includes/tools/ and provides list, get, and execute methods.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Tools {

	/**
	 * Registered tool instances, keyed by tool name.
	 *
	 * @var Aura_Tool_Base[]
	 */
	private $tools = array();

	/**
	 * Test-only override of the fork's touch declarer: null = real detection,
	 * false = no fork, a callable = the fork (see fork_declarer()).
	 *
	 * @since 2.21.0
	 * @var null|false|callable
	 */
	private static $fork_declarer_for_tests = null;

	/**
	 * Constructor — loads the base class and all tool files, then registers them.
	 */
	public function __construct() {
		$tools_dir = plugin_dir_path( __FILE__ ) . 'tools/';

		// Load the abstract base class first.
		require_once $tools_dir . 'class-tool-base.php';

		// Auto-load all tool files matching class-tool-*.php.
		$tool_files = glob( $tools_dir . 'class-tool-*.php' );
		if ( ! empty( $tool_files ) ) {
			foreach ( $tool_files as $file ) {
				// Skip the base class itself.
				if ( basename( $file ) === 'class-tool-base.php' ) {
					continue;
				}
				require_once $file;
			}
		}

		// Instantiate each loaded tool class.
		foreach ( get_declared_classes() as $class ) {
			if ( $class !== 'Aura_Tool_Base' && is_subclass_of( $class, 'Aura_Tool_Base' ) ) {
				$tool = new $class();
				$this->tools[ $tool->get_name() ] = $tool;
			}
		}

		$this->register_external_tools();
	}

	/**
	 * Let companion plugins contribute additional MCP tools.
	 *
	 * A companion (e.g. the SiteAgent Power Pack, which ships the approval-gated
	 * execute-php / wp-cli / filesystem / DB tools that must NOT live in the
	 * wordpress.org build) hooks this filter to register its own tool classes:
	 *
	 *     add_filter( 'aura_worker_register_tools', function ( $tools ) {
	 *         $tools[] = My_Power_Tool::class;      // class name, or
	 *         $tools[] = new My_Power_Tool();        // an instance
	 *         return $tools;
	 *     } );
	 *
	 * Every contributed entry must resolve to an Aura_Tool_Base subclass; anything
	 * else is ignored. This loads only locally-installed PHP — nothing remote.
	 */
	private function register_external_tools() {
		$external = apply_filters( 'aura_worker_register_tools', array() );
		if ( empty( $external ) || ! is_array( $external ) ) {
			return;
		}

		foreach ( $external as $entry ) {
			$tool = null;
			if ( $entry instanceof Aura_Tool_Base ) {
				$tool = $entry;
			} elseif ( is_string( $entry ) && class_exists( $entry ) && is_subclass_of( $entry, 'Aura_Tool_Base' ) ) {
				$tool = new $entry();
			}

			if ( $tool ) {
				// First registration wins — a companion cannot silently shadow a core tool.
				$name = $tool->get_name();
				if ( ! isset( $this->tools[ $name ] ) ) {
					$this->tools[ $name ] = $tool;
				}
			}
		}
	}

	/**
	 * Get metadata for all registered tools.
	 *
	 * @return array[]
	 */
	public function list_tools() {
		$list = array();
		foreach ( $this->tools as $tool ) {
			$list[] = $tool->get_metadata();
		}
		return $list;
	}

	/**
	 * Get a single tool instance by name.
	 *
	 * @param string $name Tool name.
	 * @return Aura_Tool_Base|null
	 */
	public function get_tool( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * Validate and execute a tool by name.
	 *
	 * @param string $name   Tool name.
	 * @param array  $params Parameters to pass to the tool.
	 * @return array { success: bool, result?: mixed, error?: string, errors?: string[] }
	 */
	public function execute_tool( $name, $params ) {
		$tool = $this->get_tool( $name );

		if ( null === $tool ) {
			return array(
				'success' => false,
				'error'   => "Unknown tool: $name",
			);
		}

		$validation = $tool->validate_params( $params );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => 'Parameter validation failed.',
				'errors'  => $validation['errors'],
			);
		}

		$annotations = $tool->get_annotations();

		// Operator rules, decided AFTER parameter validation (a malformed call
		// fails on its parameters, not on a rule) and BEFORE anything runs or
		// snapshots — and only for calls that could change something. A plain
		// read inherits the unknown sentinel too, and enforcing it would let a
		// freeze refuse audit_rules itself. Same predicate as the grant gate:
		// mutating, or an approval-bound read such as db_query.
		//
		// This is the one place every tool path passes through — the legacy REST
		// update handlers in class-aura-worker-api.php and core's own /wp/v2
		// content routes do not, and enforce separately; audit_rules lists all
		// three points so a fourth is visible when it is missing.
		$verdict = array( 'effect' => null );
		if ( Aura_Worker_Call_Context::tool_needs_grant( $annotations ) ) {
			$verdict = Aura_Worker_Rules::enforce( $tool->touches( $params ), $name );
			if ( 'block' === $verdict['effect'] ) {
				return Aura_Worker_Rules::blocked_result( $name, $verdict['rule'] );
			}
		}

		if ( ! empty( $annotations['requires_approval'] ) ) {
			/**
			 * Fires immediately before an approval-required (power) tool executes.
			 *
			 * The plugin cannot itself distinguish a gateway-approved call from a
			 * raw token call — both carry the same site token — so approval is
			 * enforced by the Aura gateway. This hook records EVERY execution of an
			 * approval-required tool for forensics, so an inline/unapproved call is
			 * at least auditable. (A signed one-time approval grant that the plugin
			 * verifies is the planned hard enforcement; see the Power Pack readme.)
			 *
			 * @param string $tool   Tool name.
			 * @param array  $params Parameters the tool was called with.
			 */
			do_action( 'aura_worker_power_execute', $name, $params );
		}

		// Decided before the call, attached after it, whichever way it went.
		// A failure does not retract the warning, and this path has no second
		// channel to recover it: execute_tool() answers its own caller
		// directly, and SiteAgent's own routes are exempt from the core REST
		// seam, so a warning dropped in the catch is one nobody ever hears.
		$warnings = 'warn' === $verdict['effect']
			? array( Aura_Worker_Rules::warning_entry( $verdict['rule'] ) )
			: array();

		try {
			$out = array(
				'success' => true,
				'result'  => $tool->execute( $params ),
			);
		} catch ( Throwable $e ) {
			// Throwable, not Exception. A TypeError or any other PHP Error
			// implements Throwable WITHOUT extending Exception, so the narrower
			// catch let it past — and past this point there is no failure
			// result, no warning, and no response: one tool's fatal takes down
			// the whole MCP request. Nothing is silenced by widening it; the
			// message still goes back to the caller, and the tool boundary is
			// exactly where a per-tool failure should stop being everyone's.
			$out = array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
		if ( ! empty( $warnings ) ) {
			$out['warnings'] = $warnings;
		}
		return $out;
	}

	/**
	 * Produce a preview of what a tool would do, without executing it.
	 *
	 * Backs the aura/mcp/tools/preview endpoint: the Aura gateway calls this
	 * before queuing a power action so a human can see the effect (static-scan
	 * verdict, planned command, file diff, SQL) at approval time. Tools that do
	 * not declare supports_preview return `supported: false` with a null preview.
	 *
	 * Both paths — SiteAgent's own tools and the elementor-mcp fork's
	 * abilities (2.21.0, preview_fork_tool()) — decide through
	 * Aura_Worker_Rules::preview_match(), the function enforce() decides
	 * through, so the preview and the call it previews cannot disagree.
	 *
	 * @param string $name   Tool name.
	 * @param array  $params Parameters to preview.
	 * @return array { success: bool, supported?: bool, preview?: mixed, error?: string, errors?: string[] }
	 */
	public function preview_tool( $name, $params ) {
		$tool = $this->get_tool( $name );

		if ( null === $tool ) {
			$fork = $this->preview_fork_tool( (string) $name, is_array( $params ) ? $params : array() );
			if ( null !== $fork ) {
				return $fork;
			}
			return array(
				'success' => false,
				'error'   => "Unknown tool: $name",
			);
		}

		$validation = $tool->validate_params( $params );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => 'Parameter validation failed.',
				'errors'  => $validation['errors'],
			);
		}

		// What this call touches, and which rule would decide it — without
		// enforcing. Previews are exempt from rules (an agent may SEE what would
		// happen), and the gateway reads these two fields to warn before approval.
		// A plain read touches nothing a rule governs, so it declares nothing.
		$annotations = $tool->get_annotations();
		$touches     = Aura_Worker_Call_Context::tool_needs_grant( $annotations ) ? $tool->touches( $params ) : array();
		// In THIS site's identity (2.12.0), through the SAME accessor
		// Aura_Worker_Rules::enforce() judges by (`enforceable_match()`): the
		// preview is what the gateway shows before approval, so it must name
		// the rule enforcement would actually apply. Without the identity
		// every scoped rule reads as applying here; without the shared
		// accessor an `allow` winner — which enforce() discards, because the
		// tools path already defaults to the approval queue — was reported as
		// a verdict the very next request never applies. Either way the two
		// disagree about the same call.
		$rule        = empty( $touches ) ? null : Aura_Worker_Rules::preview_match( $touches, $name );
		$rule_match  = null === $rule ? null : array(
			'key'    => isset( $rule['key'] ) ? (string) $rule['key'] : 'rule/?',
			'effect' => (string) $rule['effect'],
			'reason' => isset( $rule['reason'] ) ? (string) $rule['reason'] : '',
		);

		if ( empty( $annotations['supports_preview'] ) ) {
			return array(
				'success'    => true,
				'supported'  => false,
				'preview'    => null,
				'touches'    => $touches,
				'rule_match' => $rule_match,
			);
		}

		try {
			return array(
				'success'    => true,
				'supported'  => true,
				'preview'    => $tool->dry_run( $params ),
				'touches'    => $touches,
				'rule_match' => $rule_match,
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * A queued elementor-mcp fork write, previewed by the fork itself (Aura
	 * spec 2026-09-25 §5). The fork's abilities are not in this registry;
	 * Elementor_MCP_Governance::declare_touches() (elementor-mcp 1.38.0)
	 * answers with exactly the touches its early rules gate judges, and the
	 * rule is decided here the way enforce() decides the fork's run.
	 *
	 * Null means "not the fork's either" — the caller answers Unknown tool,
	 * exactly as before: no fork, an older fork, a name the fork does not
	 * govern, a throw, or anything malformed (never a partial list).
	 *
	 * @since 2.21.0
	 * @param string $name   The published MCP tool name.
	 * @param array  $params The call's params, as queued.
	 * @return array|null
	 */
	private function preview_fork_tool( $name, array $params ) {
		$declarer = self::fork_declarer();
		if ( null === $declarer ) {
			return null;
		}
		// Aura's routing key; its executor strips it before the fork sees the
		// input, so the fork must not declare from it here either.
		unset( $params['_mcpPath'] );
		try {
			$answer = call_user_func( $declarer, $name, $params );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $answer ) || ! isset( $answer['ability'], $answer['touches'] ) || ! is_string( $answer['ability'] ) || ! is_array( $answer['touches'] ) ) {
			return null;
		}
		$touches = self::validate_fork_touches( $answer['touches'] );
		if ( null === $touches ) {
			return null;
		}
		$rule = empty( $touches ) ? null : Aura_Worker_Rules::preview_match( $touches, $answer['ability'] );
		return array(
			'success'    => true,
			'supported'  => false,
			'preview'    => null,
			'touches'    => $touches,
			'rule_match' => null === $rule ? null : array(
				'key'    => isset( $rule['key'] ) ? (string) $rule['key'] : 'rule/?',
				'effect' => (string) $rule['effect'],
				'reason' => isset( $rule['reason'] ) ? (string) $rule['reason'] : '',
			),
		);
	}

	/**
	 * Every touch `{type: string, id: string}`; `precise` / `css_only` kept
	 * only as the literal true and only on custom_css (the evidence an allow
	 * may use — spec 2026-09-24 §3). One malformed touch → null.
	 *
	 * @since 2.21.0
	 * @param array $touches The fork's touches.
	 * @return array|null
	 */
	private static function validate_fork_touches( array $touches ) {
		$out = array();
		foreach ( $touches as $t ) {
			if ( ! is_array( $t ) || ! isset( $t['type'], $t['id'] ) || ! is_string( $t['type'] ) || ! is_string( $t['id'] ) ) {
				return null;
			}
			$clean = array( 'type' => $t['type'], 'id' => $t['id'] );
			if ( 'custom_css' === $t['type'] ) {
				if ( isset( $t['precise'] ) && true === $t['precise'] ) {
					$clean['precise'] = true;
				}
				if ( isset( $t['css_only'] ) && true === $t['css_only'] ) {
					$clean['css_only'] = true;
				}
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * The fork's touch declarer, or null when there is none.
	 *
	 * @since 2.21.0
	 * @return callable|null
	 */
	private static function fork_declarer() {
		if ( false === self::$fork_declarer_for_tests ) {
			return null;
		}
		if ( null !== self::$fork_declarer_for_tests ) {
			return self::$fork_declarer_for_tests;
		}
		if ( class_exists( 'Elementor_MCP_Governance' ) && method_exists( 'Elementor_MCP_Governance', 'declare_touches' ) ) {
			return array( 'Elementor_MCP_Governance', 'declare_touches' );
		}
		return null;
	}

	/**
	 * Test-only: override fork detection.
	 *
	 * @since 2.21.0
	 * @param null|false|callable $declarer See $fork_declarer_for_tests.
	 */
	public static function _set_fork_declarer_for_tests( $declarer ) {
		self::$fork_declarer_for_tests = $declarer;
	}
}
