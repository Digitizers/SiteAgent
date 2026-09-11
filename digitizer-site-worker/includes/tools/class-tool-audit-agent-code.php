<?php
/**
 * MCP Tool: audit_agent_code
 *
 * Read-only inventory of executable code an agent AUTHORED or CAN author on
 * this site — the axis `audit_mcp_exposure` does not cover (that tool reports
 * doors an agent enters; this one reports code an agent leaves behind).
 *
 * Three populations (Aura spec 2026-09-09-agent-code-detection-design §1):
 * Angie's code-snippets module (CPT `angie_snippet`, files under
 * wp-content/angie-snippets/{prod,dev}/snippet-<post_id>/main.php, loaded on
 * every request from the active environment, no snapshot, no approval); our
 * own Power Pack's execute_php / write_file / run_wp_cli flags; and third-party
 * exec stores (EMCP Pro's sandbox, Atarim's execute-php / run-wp-cli abilities).
 *
 * Facts, not verdicts, under audit_mcp_exposure's contract: every subtree is
 * independent and answers { error } on a throw; `null` is unreadable, never
 * zero; walks are bounded and say so; strings are clipped. Never reads a
 * file's contents, never executes anything, never touches the database
 * beyond the CPT query and get_post_meta().
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Audit_Agent_Code extends Aura_Tool_Base {

	/** Directory entries walked per environment, at most. `coverage` is about THIS. */
	const DIR_CAP = 200;

	/** `recent` rows, at most. Informational; never bounds a count. */
	const RECENT_CAP = 20;

	/** CPT rows read, at most. Hitting it makes every CPT-derived count `null`. */
	const CPT_CAP = 500;

	/** Every string the tool returns is clipped to this many characters. */
	const STRING_MAX = 200;

	const CPT            = 'angie_snippet';
	const ARTIFACT_META  = '_angie_snippet_artifact_id';
	const DEV_MODE_CLASS = '\\Angie\\Modules\\CodeSnippets\\Classes\\Dev_Mode_Manager';
	const SNIPPETS_ROOT  = '/angie-snippets/';

	public function get_name() {
		return 'audit_agent_code';
	}

	public function get_description() {
		return 'Read-only audit of executable code an AI agent authored or can author on this site: Angie code snippets (recorded, agent-authored, and which are live in the environment the loader includes), the SiteAgent Power Pack\'s execute-php / file-write / wp-cli flags, and third-party exec stores (EMCP Pro sandbox, Atarim exec abilities). Reports counts and presence per source, never file contents and never a verdict. Makes no changes.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'angie_snippets' => 'object — { installed, version } (installed = loaded at runtime OR present in the installed-plugin inventory, so a deactivated Angie still reports its dormant rows and directories) and, when installed: module_active, total|published|drafts|agent_authored (int|null — null when the CPT read failed or hit its cap of 500), active_env ("prod"|"dev"|null — the environment Angie\'s loader includes for THIS request; null when the dev-mode API is not callable or the snippet module is inactive), deployed: { prod: { dirs, agent_authored, orphan }, dev: {…} } (int|null per environment — directories named snippet-<post_id> that contain main.php, joined to the CPT by id; orphan = no row, which the loader still includes), latest_deploy_at: { prod, dev } (ISO8601|null, per environment, never a max across both), recent: [{ id, title, status, agent_authored, environments, modified }] (newest first, cap 20; recent_truncated when cut — never bounds a count), coverage: { total_seen, returned, truncated, cap } (the DIRECTORY WALK only, 200 entries per environment). Absent Angie: { installed: false, version: "" }. A scan that threw: { error }.',
			'power_pack'     => 'object — { installed, version, execute_php, fs_write, wp_cli, create_publish } from AURA_POWER_PACK_VERSION / AURA_POWER_EXECUTE_PHP / AURA_POWER_ALLOW_FS_WRITE / AURA_POWER_ALLOW_WP_CLI; every flag false when not installed',
			'third_party'    => 'object — { emcp_sandbox: { present, version }, atarim_exec: { present } }',
			'counters_as_of' => 'string — ISO8601 instant the counts were taken',
		);
	}

	/** Read-only: never mutates the site. */
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		$out = array();
		foreach ( array( 'angie_snippets', 'power_pack', 'third_party' ) as $subtree ) {
			try {
				$out[ $subtree ] = $this->{ $subtree }();
			} catch ( \Throwable $e ) {
				$out[ $subtree ] = $this->subtree_error( $e );
			}
		}
		$out['counters_as_of'] = gmdate( 'c' );
		return $out;
	}

	// ---- angie_snippets ------------------------------------------------------

	/**
	 * The Angie subtree (spec §3).
	 *
	 * @return array
	 */
	protected function angie_snippets() {
		if ( ! $this->angie_installed() ) {
			return array( 'installed' => false, 'version' => '' );
		}

		$rows = $this->snippet_rows(); // post objects; null when the query FAILED (a Throwable is caught by execute())
		// Two ways the rows are not a count: the query failed (null — get_posts()
		// answers [] on a database error and only $wpdb->last_error tells, Codex
		// #91 round-2 P2), or it hit CPT_CAP (a partial count is not a count).
		// Either way every CPT-derived figure is null and the join is unknown;
		// the directory walk below is independent and still answers.
		$unreadable = null === $rows;
		$capped     = $unreadable || count( $rows ) > self::CPT_CAP;
		if ( $unreadable ) {
			$rows = array();
		} elseif ( $capped ) {
			$rows = array_slice( $rows, 0, self::CPT_CAP );
		}
		$by_id = array();
		foreach ( $rows as $p ) {
			$id = (int) ( $p->ID ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$artifact     = (string) get_post_meta( $id, self::ARTIFACT_META, true );
			$by_id[ $id ] = array(
				'status'   => (string) ( $p->post_status ?? '' ),
				'artifact' => '' !== $artifact,
				'title'    => $this->clip( $p->post_title ?? '' ),
				'modified' => (string) ( $p->post_modified_gmt ?? '' ),
				'envs'     => array(),
			);
		}

		$envs      = $this->env_dirs(); // array( 'prod' => <dir name>, 'dev' => <dir name> ) or null
		$deployed  = array();
		$latest    = array();
		$seen      = 0;
		$returned  = 0;
		$truncated = null === $envs;
		foreach ( array( 'prod', 'dev' ) as $env ) {
			if ( null === $envs ) {
				$deployed[ $env ] = array( 'dirs' => null, 'agent_authored' => null, 'orphan' => null );
				$latest[ $env ]   = null;
				continue;
			}
			$walk = $this->walk_env( WP_CONTENT_DIR . self::SNIPPETS_ROOT . $envs[ $env ] );
			$seen += $walk['seen'];
			if ( $walk['truncated'] ) {
				$truncated = true;
			}
			if ( null === $walk['dirs'] ) {
				$deployed[ $env ] = array( 'dirs' => null, 'agent_authored' => null, 'orphan' => null );
				$latest[ $env ]   = null;
				$truncated        = true;
				continue;
			}
			$returned += $walk['dirs'];
			$agent  = 0;
			$orphan = 0;
			foreach ( $walk['ids'] as $id ) {
				if ( isset( $by_id[ $id ] ) ) {
					$by_id[ $id ]['envs'][] = $env;
					if ( $by_id[ $id ]['artifact'] ) {
						$agent++;
					}
				} else {
					$orphan++;
				}
			}
			$deployed[ $env ] = array(
				'dirs'           => $walk['dirs'],
				'agent_authored' => $capped ? null : $agent,   // the join needs every row
				'orphan'         => $capped ? null : $orphan,
			);
			$latest[ $env ]   = null === $walk['latest'] ? null : gmdate( 'c', $walk['latest'] );
		}

		// Counts from the rows — null when the read was capped (a partial count is not a count).
		$total = $published = $drafts = $agent_authored = 0;
		foreach ( $by_id as $row ) {
			$total++;
			if ( 'publish' === $row['status'] ) {
				$published++;
			} elseif ( 'draft' === $row['status'] ) {
				$drafts++;
			}
			if ( $row['artifact'] ) {
				$agent_authored++;
			}
		}

		// recent: newest first by modified, capped, never bounding anything else.
		uasort( $by_id, static function ( $a, $b ) {
			return strcmp( $b['modified'], $a['modified'] );
		} );
		$recent = array();
		foreach ( $by_id as $id => $row ) {
			if ( count( $recent ) >= self::RECENT_CAP ) {
				break;
			}
			$recent[] = array(
				'id'             => $id,
				'title'          => $row['title'],
				'status'         => $row['status'],
				'agent_authored' => $row['artifact'],
				'environments'   => $row['envs'],
				'modified'       => $this->iso( $row['modified'] ),
			);
		}

		// active_env is the LOADER's answer, and with the snippet module off no
		// loader runs — a callable dev-mode API must not make dormant code look
		// live (Codex #94 round-3 P2). null, the same value as "not callable".
		$module = $this->module_active();
		$active = $module ? $this->dev_mode_enabled() : null; // bool|null
		return array(
			'installed'        => true,
			'version'          => $this->clip( $this->angie_version() ),
			'module_active'    => $module,
			'total'            => $capped ? null : $total,
			'published'        => $capped ? null : $published,
			'drafts'           => $capped ? null : $drafts,
			'agent_authored'   => $capped ? null : $agent_authored,
			'active_env'       => null === $active ? null : ( $active ? 'dev' : 'prod' ),
			'deployed'         => $deployed,
			'latest_deploy_at' => $latest,
			'recent'           => $recent,
			'recent_truncated' => ( $capped && ! $unreadable ) || count( $by_id ) > self::RECENT_CAP,
			'coverage'         => array(
				'total_seen' => $seen,
				'returned'   => $returned,
				'truncated'  => $truncated,
				'cap'        => self::DIR_CAP,
			),
		);
	}

	/**
	 * Walk one environment directory, bounded at DIR_CAP entries.
	 *
	 * A directory that does not exist holds nothing (dirs: 0, readable). One
	 * that exists but cannot be opened is unreadable (dirs: null). Only
	 * entries named snippet-<n> that are directories containing main.php
	 * count — exactly what Angie's loader includes; everything else is seen
	 * and ignored (the loader ignores it too).
	 *
	 * `latest` (reported as `latest_deploy_at`) is the newest main.php mtime in
	 * THIS environment: Angie's own get_snippet_environment_timestamps() is
	 * deliberately not read — the mtime is the observable equivalent and needs
	 * no Angie internal.
	 *
	 * @param string $dir Environment directory.
	 * @return array { dirs: int|null, ids: int[], latest: int|null, seen: int, truncated: bool }
	 */
	protected function walk_env( $dir ) {
		$out = array( 'dirs' => 0, 'ids' => array(), 'latest' => null, 'seen' => 0, 'truncated' => false );
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$dh = $this->open_dir( $dir );
		if ( false === $dh ) {
			$out['dirs'] = null;
			return $out;
		}
		while ( false !== ( $entry = readdir( $dh ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( $out['seen'] >= self::DIR_CAP ) {
				$out['truncated'] = true;
				break;
			}
			$out['seen']++;
			if ( ! preg_match( '/^snippet-(\d+)$/', $entry, $m ) ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( ! is_dir( $path ) || ! is_file( $path . '/main.php' ) ) {
				continue;
			}
			$out['dirs']++;
			$out['ids'][] = (int) $m[1];
			$mtime        = filemtime( $path . '/main.php' );
			if ( false !== $mtime && ( null === $out['latest'] || $mtime > $out['latest'] ) ) {
				$out['latest'] = $mtime;
			}
		}
		closedir( $dh );
		return $out;
	}

	/**
	 * Open a directory for reading. Seam: a test models an unreadable one.
	 *
	 * @param string $dir Directory.
	 * @return resource|false
	 */
	protected function open_dir( $dir ) {
		if ( ! is_readable( $dir ) ) {
			return false;
		}
		return opendir( $dir );
	}

	/**
	 * Seam: Angie's presence — loaded at runtime (`ANGIE_VERSION` /
	 * `\Angie\Plugin`) OR on disk in the installed-plugin inventory. A
	 * deactivated Angie loads nothing, but its CPT rows and every deployed
	 * `snippet-<id>/main.php` are still there, dormant: exactly the code
	 * this audit exists to count (Codex #94 round-6 P2). `module_active` is
	 * the runtime signal; this one is not.
	 */
	protected function angie_installed() {
		return defined( 'ANGIE_VERSION' ) || class_exists( '\\Angie\\Plugin' ) || is_array( $this->angie_header() );
	}

	/** Seam: Angie's version — the loaded constant, else the inventory header, else ''. */
	protected function angie_version() {
		if ( defined( 'ANGIE_VERSION' ) ) {
			return (string) ANGIE_VERSION;
		}
		$header = $this->angie_header();
		return is_array( $header ) && isset( $header['Version'] ) ? (string) $header['Version'] : '';
	}

	/** Angie's main plugin file in the installed-plugin inventory. */
	const PLUGIN_FILE = 'angie/angie.php';

	/**
	 * Seam: the installed-plugin inventory's header for Angie's own file, or
	 * null when absent or unreadable. Same shape and precedent as
	 * audit_mcp_exposure's `elementor_plugin_header()`: `get_plugins()`,
	 * deliberately NOT `is_plugin_active()` — a deactivated plugin remaining
	 * on disk is the case this exists to report; any environment quirk
	 * degrades to "not in inventory", never a fatal in a read-only audit.
	 *
	 * @return array|null
	 */
	protected function angie_header() {
		if ( ! defined( 'ABSPATH' ) ) {
			return null;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return null;
		}
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) || ! isset( $plugins[ self::PLUGIN_FILE ] ) || ! is_array( $plugins[ self::PLUGIN_FILE ] ) ) {
			return null;
		}
		return $plugins[ self::PLUGIN_FILE ];
	}

	/** Seam: is the snippet module's CPT registered? */
	protected function module_active() {
		return function_exists( 'post_type_exists' ) && post_type_exists( self::CPT );
	}

	/**
	 * Seam: the environment DIRECTORY names, keyed by the fixed report keys.
	 * Read from Angie's constants when the class is present, else the
	 * literals; a rename on Angie's side (a constant that is not the literal)
	 * answers null so the walk degrades to unreadable, never to a wrong zero.
	 *
	 * @return array|null
	 */
	protected function env_dirs() {
		$prod = 'prod';
		$dev  = 'dev';
		$cls  = self::DEV_MODE_CLASS;
		if ( class_exists( $cls ) ) {
			if ( defined( $cls . '::ENV_PROD' ) ) {
				$prod = constant( $cls . '::ENV_PROD' );
			}
			if ( defined( $cls . '::ENV_DEV' ) ) {
				$dev = constant( $cls . '::ENV_DEV' );
			}
		}
		if ( 'prod' !== $prod || 'dev' !== $dev ) {
			return null;
		}
		return array( 'prod' => $prod, 'dev' => $dev );
	}

	/**
	 * Seam: the loader's own answer. Per SESSION in Angie 1.1.x (a cookie bound
	 * to the current user and IP) — a REST call from Aura carries none, so this
	 * is `false` (prod) on every real site today, which is the truth for every
	 * visitor: only the developer's own dev-mode session loads `dev`.
	 *
	 * @return bool|null null when the API is not callable.
	 */
	protected function dev_mode_enabled() {
		$cls = self::DEV_MODE_CLASS;
		if ( ! class_exists( $cls ) || ! is_callable( array( $cls, 'is_dev_mode_enabled' ) ) ) {
			return null;
		}
		return (bool) call_user_func( array( $cls, 'is_dev_mode_enabled' ) );
	}

	/**
	 * Seam: the CPT rows (publish + draft, the statuses Angie's own repository
	 * reads), CPT_CAP + 1 at most so a capped read is detectable.
	 *
	 * `null` when the query FAILED. get_posts() answers an empty array when
	 * the statement itself fails and records the failure only in
	 * `$wpdb->last_error`, so an array alone is never proof of a read — the
	 * same rule audit_mcp_exposure applies to its own `get_results()` calls
	 * (`results_failed()`): clear last_error, run, read last_error.
	 *
	 * @return object[]|null
	 */
	protected function snippet_rows() {
		global $wpdb;
		if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
		$rows = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => self::CPT_CAP + 1,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				// get_posts() already defaults suppress_filters to true; saying so
				// explicitly trips the wp.org Plugin Check (VIP sniff), so it is left implicit.
			)
		);
		if ( ! is_array( $rows ) ) {
			return null;
		}
		if ( is_object( $wpdb ) && isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return $rows;
	}

	// ---- power_pack ------------------------------------------------------------

	/** The Power Pack subtree (spec §3). */
	protected function power_pack() {
		$env = $this->power_pack_env();
		if ( null === $env ) {
			return array( 'installed' => false, 'version' => '', 'execute_php' => false, 'fs_write' => false, 'wp_cli' => false );
		}
		return array(
			'installed'      => true,
			'version'        => $this->clip( $env['version'] ),
			'execute_php'    => (bool) $env['execute_php'],
			'fs_write'       => (bool) $env['fs_write'],
			'wp_cli'         => (bool) $env['wp_cli'],
			// How a write_file CREATE lands on this host (SiteAgent#96): 'link'
			// (one atomic hard link), 'write' (exclusive create, bytes written
			// into the owned handle — a reader can see the file grow), or null
			// when no create can land here: no link() and FS_CHMOD_FILE carries
			// execute bits fopen() cannot recreate (or the engine is not loaded).
			'create_publish' => $this->create_publish(),
		);
	}

	/**
	 * Seam: the engine's publish mode on this host.
	 *
	 * @return string|null 'link' | 'write' | null
	 */
	protected function create_publish() {
		return class_exists( 'Aura_Worker_Snapshots' ) && method_exists( 'Aura_Worker_Snapshots', 'publish_mode' )
			? Aura_Worker_Snapshots::publish_mode()
			: null;
	}

	/**
	 * Seam: the Power Pack's constants. null when it is not installed.
	 *
	 * Flags are read by TRUTHINESS, not strict `true ===` — that is how the
	 * Power Pack's own tools gate on them (siteagent-power-pack
	 * class-tool-execute-php.php:153, class-tool-fs-write.php:195,
	 * class-tool-wp-cli.php:209 all read `defined( 'X' ) && X`), so
	 * `define( 'AURA_POWER_EXECUTE_PHP', 1 )` arms exec on the site and must
	 * report `execute_php: true` here too — a strict compare would have been
	 * a false negative against a site that can actually run PHP.
	 *
	 * @return array|null { version, execute_php, fs_write, wp_cli }
	 */
	protected function power_pack_env() {
		if ( ! defined( 'AURA_POWER_PACK_VERSION' ) ) {
			return null;
		}
		return array(
			'version'     => (string) AURA_POWER_PACK_VERSION,
			'execute_php' => defined( 'AURA_POWER_EXECUTE_PHP' ) && (bool) AURA_POWER_EXECUTE_PHP,
			'fs_write'    => defined( 'AURA_POWER_ALLOW_FS_WRITE' ) && (bool) AURA_POWER_ALLOW_FS_WRITE,
			'wp_cli'      => defined( 'AURA_POWER_ALLOW_WP_CLI' ) && (bool) AURA_POWER_ALLOW_WP_CLI,
		);
	}

	// ---- third_party -----------------------------------------------------------

	/** The third-party subtree (spec §3). */
	protected function third_party() {
		$env = $this->third_party_env();
		return array(
			'emcp_sandbox' => array(
				'present' => '' !== $env['emcp_version'] || (bool) $env['emcp_dir'],
				'version' => $this->clip( $env['emcp_version'] ),
			),
			'atarim_exec'  => array( 'present' => (bool) $env['atarim'] ),
		);
	}

	/**
	 * Seam: third-party facts. EMCP Pro's sandbox relocated to
	 * wp-content/emcp-sandbox in 3.14; Atarim 5.1.3 ships the two abilities.
	 *
	 * @return array { emcp_version: string, emcp_dir: bool, atarim: bool }
	 */
	protected function third_party_env() {
		return array(
			'emcp_version' => defined( 'EMCP_TOOLS_VERSION' ) ? (string) EMCP_TOOLS_VERSION : '',
			'emcp_dir'     => is_dir( WP_CONTENT_DIR . '/emcp-sandbox' ),
			'atarim'       => class_exists( 'AVCF_Abilities_ExecutePHP' ) || class_exists( 'AVCF_Abilities_WP_CLI' ),
		);
	}

	// ---- helpers ---------------------------------------------------------------

	/**
	 * Clip a string to STRING_MAX characters, multibyte-safe. Same contract as
	 * audit_mcp_exposure's clip(): WordPress polyfills mb_substr(), and without
	 * it a byte substr() could hand JSON encoding half a character.
	 *
	 * @param mixed $s Value.
	 * @return string
	 */
	protected function clip( $s ) {
		$s = (string) $s;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, self::STRING_MAX );
		}
		if ( preg_match( '/^.{0,' . (int) self::STRING_MAX . '}/us', $s, $m ) ) {
			return $m[0];
		}
		return '';
	}

	/**
	 * A `post_modified_gmt` wall clock ('Y-m-d H:i:s', UTC) as ISO8601; '' stays null.
	 *
	 * @param string $gmt The stamp.
	 * @return string|null
	 */
	protected function iso( $gmt ) {
		$gmt = trim( (string) $gmt );
		if ( '' === $gmt ) {
			return null;
		}
		$t = strtotime( $gmt . ' UTC' );
		return false === $t ? null : gmdate( 'c', $t );
	}

	/**
	 * The error subtree for a scan that threw.
	 *
	 * @param \Throwable $e The throw.
	 * @return array { error }
	 */
	protected function subtree_error( $e ) {
		$msg = $e->getMessage();
		return array( 'error' => $this->clip( '' === $msg ? get_class( $e ) : $msg ) );
	}
}
