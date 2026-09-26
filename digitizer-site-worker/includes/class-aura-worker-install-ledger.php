<?php
/**
 * The install ledger (Aura spec 2026-09-21-agent-installed-packages-design
 * §4, amended in Aura#594).
 *
 * Records every plugin and theme install or update this site performs, with
 * the door it came through — transport, auth, the application password's
 * name, the REST route — and where the package came from. It RECORDS AND
 * NEVER DECIDES: every upgrader callback returns its first argument unchanged
 * (the correlation token aside) and swallows its own failures, so recording
 * can never break an upgrade.
 *
 * Personal data by owner decision (spec §4.2, 2026-09-26): `user_id`,
 * `app_password_name` and `route` can identify an account or a person. Kept
 * at most 90 days / 200 entries, never autoloaded, removed on uninstall, and
 * exposed only through `audit_agent_code`.
 *
 * @package Aura_Worker
 * @since 2.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Install_Ledger {

	/** The entries, newest first. */
	const OPTION = 'aura_worker_install_ledger';

	/** `{ started, count_edge, evicted }` — the edge of coverage (spec §4.3). */
	const STATE_OPTION = 'aura_worker_install_ledger_state';

	/** The per-run correlation key this class adds to `hook_extra`. */
	const TOKEN_KEY = 'aura_install_ledger';

	const MAX_ENTRIES = 200;

	/** 90 days. */
	const MAX_AGE = 7776000;

	/** How far ahead of now a stored stamp may be — a backward clock correction, not corruption (365 days). */
	const MAX_CLOCK_SKEW = 31536000;

	const STRING_MAX = 200;

	/**
	 * Test overrides for probe() (Ruling R2). Empty in production.
	 *
	 * @var array
	 */
	private static $probe_overrides = array();

	/**
	 * Frames by token, this request only: `{ type, source, context }` stored at
	 * pre-download, taken at install-result (spec §4.1).
	 *
	 * @var array
	 */
	private static $frames = array();

	/**
	 * SiteAgent's active as_siteagent() claims — one per frame, `{ upgrader,
	 * package }`. A run is `transport: siteagent` only when
	 * `upgrader_pre_download` hands us the very object a claim's `upgrader`
	 * holds, so a nested run a filter starts — any order, any priority, even
	 * for the same plugin — goes through its own upgrader and is recorded as
	 * what it is (Codex r16/r17/r18 on #595: position, "first run" and
	 * target-name matching all proved spoofable; object identity is not).
	 *
	 * `package` is the claim's optional ORIGINAL package (Codex r1 on #139):
	 * a verified self-update downloads its zip_url to a temp file and installs
	 * from that local path, so without this the ledger would record the temp
	 * path — never the real source — for every verified self-update.
	 *
	 * @var array[]
	 */
	private static $siteagent_claims = array();

	/** Core's own automatic-update action (Ruling R1). */
	const AUTO_UPDATE_ACTION = 'wp_maybe_auto_update';

	/**
	 * Run SiteAgent's own upgrader call. Runs through $upgrader — the object
	 * SiteAgent created — record `transport: siteagent`, approved and audited
	 * on our side (spec §4.2); any other run inside the call does not.
	 *
	 * @param callable    $work            The upgrader call.
	 * @param object      $upgrader        The Plugin_Upgrader / Theme_Upgrader it uses.
	 * @param string|null $source_package  The call's own original package, when
	 *                                     it differs from what $upgrader will
	 *                                     actually be told to install (Codex r1
	 *                                     on #139): a verified self-update
	 *                                     downloads $zip_url to a temp file and
	 *                                     installs from THAT path, so on_pre_download()
	 *                                     would otherwise see and classify only the
	 *                                     temp path, never the real source. Omitted
	 *                                     or not a non-empty string, the installed
	 *                                     package is classified exactly as before.
	 * @return mixed $work's return.
	 */
	public static function as_siteagent( $work, $upgrader, $source_package = null ) {
		self::$siteagent_claims[] = array(
			'upgrader' => $upgrader,
			'package'  => is_string( $source_package ) && '' !== $source_package ? $source_package : null,
		);
		try {
			return $work();
		} finally {
			array_pop( self::$siteagent_claims );
		}
	}

	/**
	 * Append one entry: newest first, then retention — 90 days, then 200
	 * entries. Read-modify-write without a lock: two installs finishing in
	 * the same instant can lose one entry (spec §4.3, stated, not fixed).
	 *
	 * @param array $entry The entry (spec §4.2).
	 * @return bool Whether the entry was valid and reached commit() — false
	 *              when valid_entry() rejected it and nothing was written.
	 *              A caller recording an in-scope install must treat a false
	 *              return the same as a thrown exception (Codex review round
	 *              1, Task 2): the install happened and could not be
	 *              recorded, so coverage moves to a boundary.
	 */
	public static function append( array $entry ) {
		$now    = self::now();
		$state  = self::read_state();
		$stored = self::read_entries();
		if ( self::storage_corrupt( $stored, $state, $now ) ) {
			// Corrupted storage: coverage restarts HERE — a recovery boundary,
			// never the old `started` over a ring that no longer holds what that
			// date promises (Codex r1 on #595). Readable orphaned rows are kept
			// (Codex r10); anything unreadable is dropped.
			$orphan           = self::orphan_ring( $stored, $state, $now );
			$state            = self::fresh_state( $now );
			$state['evicted'] = true;
			$stored           = $orphan ? $stored : array();
		} elseif ( ! is_array( $state ) ) {
			$state = self::fresh_state( $now );
		}
		$entries = is_array( $stored ) ? array_values( $stored ) : array();
		// Newest first is an invariant (entries_corrupt()), so a clock that
		// stepped backward must not break it: the new row takes the head's
		// stamp instead (Codex r15 on #595) — but only when the entry's OWN
		// `when` parses. An unparseable one must reach valid_entry() below
		// unmasked, or a malformed entry would look clamp-repaired and slip
		// past it (controller ruling 2, Task 1 review round 1). The clamped
		// row's true order is still right — it happened after the head.
		if ( isset( $entries[0]['when'], $entry['when'] ) ) {
			$entry_when = strtotime( (string) $entry['when'] );
			if ( false !== $entry_when && $entry_when < strtotime( (string) $entries[0]['when'] ) ) {
				$entry['when'] = $entries[0]['when'];
			}
		}
		// RECORDS AND NEVER DECIDES cuts both ways: an entry this class did
		// not itself shape is not stored either. Unvalidated, a malformed row
		// would make the NEXT read call the whole ring unreadable, and the
		// read-modify-write after that would wipe it (controller ruling 2,
		// Task 1 review round 1) — silently, on an append that has nothing to
		// do with the bad row. Reject it here instead: nothing is written.
		if ( ! self::valid_entry( $entry, $now ) ) {
			return false;
		}
		array_unshift( $entries, $entry );

		// The count edge already on disk marks rows an earlier — possibly
		// interrupted — rotation already dropped (controller ruling 1, Task 1
		// review round 1): expired() ages them out here exactly like a
		// 90-day-old row, rather than storage_corrupt() calling their mere
		// presence corruption.
		$edge = self::edge_timestamp( $state );

		$kept = array();
		foreach ( $entries as $e ) {
			if ( self::expired( $e, $now, $edge ) ) {
				$state['evicted'] = true;
				continue;
			}
			$kept[] = $e;
		}
		if ( count( $kept ) > self::MAX_ENTRIES ) {
			$kept                = array_slice( $kept, 0, self::MAX_ENTRIES );
			$oldest              = $kept[ self::MAX_ENTRIES - 1 ];
			$state['count_edge'] = is_array( $oldest ) && isset( $oldest['when'] ) ? (string) $oldest['when'] : null;
			$state['evicted']    = true;
		}
		// State FIRST (Codex r2 on #595): the two writes are separately
		// visible, and a read between them must only ever under-claim — the
		// new edge over the old, longer ring, never the old edge over a ring
		// that has already lost an entry. (That in-between state — the new
		// edge landed, the old ring still on disk — is exactly the reading
		// report() now gives it: readable, the pre-edge row dropped like any
		// other expired one, never `ledger_unreadable`; controller ruling 1,
		// Task 1 review round 1.) And only once the state is PROVEN stored
		// (Codex r5 on #595): a refused state write followed by a landed
		// entries write would truncate the ring under the old edge. Losing
		// this one entry is the stated cost of a failed write.
		self::commit( $state, $kept, $now );
		return true;
	}

	/**
	 * The `installs` subtree of audit_agent_code (spec §4.3). Purges rows past
	 * 90 days first — retention is physical, the rows hold personal data — and
	 * still leaves them out of the answer should that write fail.
	 *
	 * On multisite, checked BEFORE any storage read (final review Task 2):
	 * this class always stores network-wide (read_entries()/read_state()), so
	 * when SiteAgent is active on this site but not network-activated, that
	 * one ledger cannot see what every OTHER site's own (non-network) copy of
	 * this plugin is installing — the network option only ever holds what
	 * ran through THIS site's requests. A single site never checks (Ruling,
	 * final review Task 2, binding).
	 *
	 * @return array { since, entries, total, evicted } | { error }
	 */
	public static function report() {
		if ( is_multisite() && ! (bool) self::probe()['network_active'] ) {
			return array( 'error' => 'ledger_partial_network' );
		}
		self::purge_rows();
		$now    = self::now();
		$stored = self::read_entries();
		$state  = self::read_state();
		if ( self::storage_corrupt( $stored, $state, $now ) ) {
			return array( 'error' => 'ledger_unreadable' );
		}
		$evicted = is_array( $state ) && ! empty( $state['evicted'] );
		$edge    = self::edge_timestamp( $state );
		$entries = array();
		foreach ( is_array( $stored ) ? $stored : array() as $e ) {
			if ( self::expired( $e, $now, $edge ) ) {
				$evicted = true;
				continue;
			}
			$entries[] = $e;
		}
		$since = $now - self::MAX_AGE;
		if ( is_array( $state ) ) {
			$started = isset( $state['started'] ) ? strtotime( (string) $state['started'] ) : false;
			$since   = max( $since, false === $started ? $now : $started );
			$edge    = isset( $state['count_edge'] ) ? strtotime( (string) $state['count_edge'] ) : false;
			if ( false !== $edge ) {
				$since = max( $since, $edge );
			}
		} else {
			$since = $now; // never stamped: nothing was observed before now (Ruling R3)
		}
		return array(
			'since'   => gmdate( 'c', $since ),
			'entries' => $entries,
			'total'   => count( $entries ),
			'evicted' => $evicted,
		);
	}

	/**
	 * The daily retention pass, on core's `wp_scheduled_delete` (spec §4.2:
	 * personal data kept at most 90 days — Codex r3/r5 on #595). Like core's
	 * own trash retention on the same event, it runs when WP-Cron (or the
	 * site's system cron) does; report() and every append() also age rows
	 * out, so a row past 90 days is deleted at the next of those three
	 * (spec §4.2, stated — Codex r12). Deletes rows
	 * past 90 days, and — unlike a read — also RECOVERS corrupted storage: an
	 * unreadable ledger may still hold personal data no expiry can reach, so
	 * it is deleted behind a recovery boundary (coverage restarts now,
	 * `evicted: true`). Until that pass, report() names the corruption.
	 * Takes no argument: do_action() passes '' to a callback with none.
	 */
	public static function purge_expired() {
		try {
			$now    = self::now();
			$stored = self::read_entries();
			$state  = self::read_state();
			if ( self::storage_corrupt( $stored, $state, $now ) ) {
				$orphan           = self::orphan_ring( $stored, $state, $now );
				$fresh            = self::fresh_state( $now );
				$fresh['evicted'] = true;
				// An orphaned ring keeps its rows (Codex r10 on #595); purge_rows()
				// then ages them out as usual.
				self::commit( $fresh, $orphan ? array_values( $stored ) : array(), $now );
				if ( ! $orphan ) {
					return;
				}
			}
			self::purge_rows();
		} catch ( \Throwable $e ) {
			// A failed purge is retried by the next read or the next day.
		}
	}

	/**
	 * Delete rows past 90 days from READABLE storage — the part of the daily
	 * pass report() also runs. Corrupted storage is left for purge_expired().
	 * State first, and only once it is proven stored, like append().
	 */
	private static function purge_rows() {
		try {
			$now    = self::now();
			$stored = self::read_entries();
			$state  = self::read_state();
			if ( null === $stored || self::storage_corrupt( $stored, $state, $now ) ) {
				return;
			}
			$edge = self::edge_timestamp( $state );
			$kept = array();
			foreach ( $stored as $e ) {
				if ( ! self::expired( $e, $now, $edge ) ) {
					$kept[] = $e;
				}
			}
			if ( count( $kept ) === count( $stored ) ) {
				return;
			}
			$state            = is_array( $state ) ? $state : self::fresh_state( $now );
			$state['evicted'] = true;
			self::commit( $state, $kept, $now );
		} catch ( \Throwable $e ) {
			// Retried by the next read or the daily pass.
		}
	}

	/**
	 * Stamp `started` the first time — called from aura_worker_maybe_upgrade()
	 * (Ruling R3). Never moves an existing stamp.
	 */
	public static function ensure_started() {
		// A ring without a state is an ORPHAN, not a new site: never
		// overwritten here — the daily pass recovers it (Codex r10 on #595).
		if ( null !== self::read_state() || null !== self::read_entries() ) {
			return;
		}
		// The ring is created WITH the state (Codex r8 on #595): from here on
		// a state without a ring means the rows disappeared, never "none yet".
		self::commit( self::fresh_state( self::now() ), array(), self::now() );
	}

	/**
	 * Restart coverage on (re)activation — called from
	 * aura_worker_activate_site() for every site (final review Task 1).
	 * Deactivate → reactivate must not leave `started` unchanged: the plugin
	 * observed nothing while inactive, and an unmoved `started` would have
	 * report() claim coverage over that gap.
	 *
	 * A genuinely fresh site (no state, no ring at all) is not a
	 * reactivation — ensure_started() itself is correct there, `evicted:
	 * false`. Everything else moves coverage to a boundary at NOW, exactly
	 * like an install that could not be recorded, keeping whatever ring is on
	 * disk. The one shape this must never produce is a state with no ring at
	 * all (storage_corrupt() treats that as corruption, Codex r8 on #595): a
	 * state alone is repaired by committing an empty ring alongside the
	 * boundary rather than writing the state in isolation.
	 */
	public static function restart_coverage() {
		try {
			$ring = self::read_entries();
			if ( null === self::read_state() && null === $ring ) {
				self::ensure_started();
				return;
			}
			if ( null === $ring ) {
				$now              = self::now();
				$state            = self::fresh_state( $now );
				$state['evicted'] = true;
				self::commit( $state, array(), $now );
				return;
			}
			self::write_boundary( self::now() ); // the ring already on disk is left untouched
		} catch ( \Throwable $e ) {
			// Reactivation must not fail because of the ledger.
		}
	}

	/** @return int */
	public static function now() {
		$p = self::probe();
		return (int) $p['now'];
	}

	/**
	 * Clip to STRING_MAX characters, multibyte-safe (audit_agent_code's clip()).
	 *
	 * @param mixed $s Value.
	 * @return string
	 */
	public static function clip( $s ) {
		$s = (string) $s;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, self::STRING_MAX );
		}
		return preg_match( '/^.{0,' . (int) self::STRING_MAX . '}/us', $s, $m ) ? $m[0] : '';
	}

	/**
	 * Characters, counted the way clip() cuts them — mb_strlen(), else the
	 * same /u regex clip() falls back to (Codex r12 on #595): a byte count
	 * would reject a 101-character Hebrew name clip() itself wrote.
	 *
	 * @param string $s Value.
	 * @return int
	 */
	private static function char_len( $s ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $s );
		}
		$n = preg_match_all( '/./us', $s );
		return false === $n ? PHP_INT_MAX : $n;
	}

	/**
	 * Test seam (Ruling R2): override any subset of probe()'s facts.
	 *
	 * @param array $overrides Fact => value.
	 */
	public static function _set_probe_for_tests( array $overrides ) {
		self::$probe_overrides = $overrides;
	}

	/**
	 * Test seam: how many pre-download frames are still held, this request
	 * (Codex review round 1, Task 2) — a failed or ignored run must not leave
	 * one behind for the rest of a long bulk request.
	 *
	 * @return int
	 */
	public static function _frame_count_for_tests() {
		return count( self::$frames );
	}

	/** Clear every static (sa_reset_state()). */
	public static function reset_for_tests() {
		self::$probe_overrides  = array();
		self::$frames           = array();
		self::$siteagent_claims = array();
	}

	/**
	 * Every environment fact this class reads, in one place (Ruling R2).
	 *
	 * @return array
	 */
	private static function probe() {
		$real = array(
			'now'               => time(),
			'siteagent'         => null, // null = decided by the run's token (context()); tests may force a bool
			'wp_cli'            => defined( 'WP_CLI' ) && WP_CLI,
			'auto_update'       => function_exists( 'doing_action' ) && doing_action( self::AUTO_UPDATE_ACTION ),
			'cron'              => function_exists( 'wp_doing_cron' ) && wp_doing_cron(),
			'rest'              => class_exists( 'Aura_Worker_Rules' ) && Aura_Worker_Rules::serving_rest(),
			'admin'             => function_exists( 'is_admin' ) && is_admin(),
			'rest_cookie'       => isset( $GLOBALS['wp_rest_auth_cookie'] ) && true === $GLOBALS['wp_rest_auth_cookie'],
			'user_id'           => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'app_password_uuid' => self::authenticated_uuid(),
			'route'             => class_exists( 'Aura_Worker_Call_Context' ) ? Aura_Worker_Call_Context::rest_route() : null,
			'uploads'           => self::uploads_dir(),
			'attachment_ids'    => null,
			'versions'          => null,
			// report()'s multisite gate (final review Task 2): computed lazily
			// — non-multisite never checks, so a single site never pays for
			// requiring wp-admin/includes/plugin.php. Overridable in tests so
			// they never depend on WP's real plugin-activation internals.
			'network_active'    => is_multisite() ? self::network_active_now() : true,
		);
		return array_merge( $real, self::$probe_overrides );
	}

	/**
	 * Whether SiteAgent is network-activated (spec, final review Task 2).
	 * Only ever consulted on multisite. A fact this class cannot resolve
	 * (the constant or the function missing) answers true — unable to prove
	 * partial coverage is not the same as proving it, and this call must
	 * never itself break a read.
	 *
	 * @return bool
	 */
	private static function network_active_now() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) || ! defined( 'AURA_WORKER_FILE' ) ) {
			return true;
		}
		return (bool) is_plugin_active_for_network( plugin_basename( AURA_WORKER_FILE ) );
	}

	/** Register the three filters (spec §4.1). */
	public static function init() {
		add_filter( 'upgrader_package_options', array( __CLASS__, 'on_package_options' ), PHP_INT_MAX, 1 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'on_pre_download' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_install_package_result', array( __CLASS__, 'on_install_result' ), PHP_INT_MAX, 2 );
		// Retention is physical (Codex r3 on #595): core's daily event, always scheduled.
		add_action( 'wp_scheduled_delete', array( __CLASS__, 'purge_expired' ) );
	}

	/**
	 * Stamp a per-run token into hook_extra — plugin and theme runs only.
	 *
	 * @param mixed $options WP_Upgrader::run() options.
	 * @return mixed The options, with only the token added.
	 */
	public static function on_package_options( $options ) {
		try {
			if ( is_array( $options ) && isset( $options['hook_extra'] ) && is_array( $options['hook_extra'] ) && null !== self::type_of( $options['hook_extra'] ) ) {
				$options['hook_extra'][ self::TOKEN_KEY ] = wp_generate_uuid4();
			}
		} catch ( \Throwable $e ) {
			// Recording never breaks an upgrade.
		}
		return $options;
	}

	/**
	 * Classify the package and capture the request context, under the token.
	 *
	 * A run that stops here — download_url() itself fails, or anything after
	 * this filter throws before WordPress ever calls
	 * upgrader_install_package_result — leaves its frame in $frames with no
	 * on_install_result() call to clear it. That is not a leak: $frames is a
	 * static, so it lives only for THIS request and is gone when the request
	 * ends, and every token is a fresh wp_generate_uuid4() (on_package_options()),
	 * so a stray frame from an earlier aborted run in the same request can
	 * never be mistaken for a different one's.
	 *
	 * @param mixed $reply      An earlier filter's answer: false, a local file, or a WP_Error.
	 * @param mixed $package    The package WordPress would download.
	 * @param mixed $upgrader   Compared by identity with SiteAgent's own (as_siteagent()); its skin is never read (Ruling R1).
	 * @param mixed $hook_extra The run's hook_extra.
	 * @return mixed $reply, unchanged.
	 */
	public static function on_pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		try {
			if ( is_wp_error( $reply ) ) {
				return $reply; // the run aborts before anything is downloaded — nothing to record
			}
			$type  = self::type_of( $hook_extra );
			$token = self::token_of( $hook_extra );
			if ( null === $type || null === $token ) {
				return $reply;
			}
			$claim = self::matching_claim( $upgrader );
			self::$frames[ $token ] = array(
				'type'    => $type,
				// A non-false reply is the file that will be installed, and its
				// origin is not ours to know (Codex r2 on #594) — a non-false
				// reply still means unknown even when the run carries a claimed
				// package. A claimed run whose claim carries its own original
				// package classifies THAT instead of $package (Codex r1 on
				// #139): a verified self-update swaps the URL for a local temp
				// file before calling install(), and that temp path is never
				// the real source.
				'source'  => false === $reply
					? self::classify_source( null !== $claim && null !== $claim['package'] ? $claim['package'] : $package )
					: array( 'kind' => 'unknown' ),
				'context' => self::context( null !== $claim ),
			);
		} catch ( \Throwable $e ) {
			// Recording never breaks an upgrade.
		}
		return $reply;
	}

	/**
	 * Write the entry for a package that installed.
	 *
	 * A frame this call never sees — because on_pre_download() never staked
	 * one for this token, or a run stopped before reaching here at all — is
	 * simply gone: $frames lives only for this request, and tokens are unique
	 * per run (wp_generate_uuid4() in on_package_options()), so there is never
	 * a stale frame from a PREVIOUS run to mistake for this one's.
	 *
	 * @param mixed $result     install_package()'s result, or a WP_Error.
	 * @param mixed $hook_extra The run's hook_extra.
	 * @return mixed $result, unchanged.
	 */
	public static function on_install_result( $result, $hook_extra = array() ) {
		try {
			$token = self::token_of( $hook_extra );
			if ( is_wp_error( $result ) || ! is_array( $result ) ) {
				// A failed install: nothing to record, and the frame this token
				// staked at pre-download must not outlive it — left in place, it
				// would leak for the rest of a long bulk request (Codex review
				// round 1, Task 2).
				if ( null !== $token ) {
					unset( self::$frames[ $token ] );
				}
				return $result;
			}
			$type = self::type_of( $hook_extra );
			if ( null === $type ) {
				return $result;
			}
			$in_scope = true; // a plugin/theme package installed: from here a failure loses an install
			$frame    = null;
			if ( null !== $token && isset( self::$frames[ $token ] ) ) {
				$frame = self::$frames[ $token ];
				unset( self::$frames[ $token ] );
			}
			$slug        = isset( $result['destination_name'] ) ? (string) $result['destination_name'] : '';
			$destination = isset( $result['destination'] ) ? (string) $result['destination'] : '';
			$recorded    = self::append(
				array_merge(
					array(
						'when'    => gmdate( 'c', self::now() ),
						'type'    => $type,
						'action'  => self::action_of( $hook_extra ),
						'slug'    => self::clip( $slug ),
						'version' => self::installed_version( $type, $destination, $hook_extra, $slug ),
					),
					null === $frame ? self::context( false ) : $frame['context'], // no frame: the upgrader was never seen, so never siteagent
					array( 'source' => null === $frame ? array( 'kind' => 'unknown' ) : $frame['source'] )
				)
			);
			if ( ! $recorded ) {
				// append() itself rejected the entry (valid_entry() failed) —
				// no exception was thrown, so this is not caught below. The
				// install still happened and still could not be recorded, so
				// coverage moves to a boundary the same as a thrown probe
				// would (Codex review round 1, Task 2).
				self::mark_boundary();
			}
		} catch ( \Throwable $e ) {
			// Recording never breaks an upgrade — but an install that could not
			// be recorded moves coverage to NOW (Codex r10 on #595), so the
			// ledger never claims the stretch that just lost it.
			if ( ! empty( $in_scope ) ) {
				self::mark_boundary();
			}
		}
		return $result;
	}

	/**
	 * `plugin` | `theme` | null (spec §4.1 scope).
	 *
	 * @param mixed $hook_extra hook_extra.
	 * @return string|null
	 */
	public static function type_of( $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return null;
		}
		$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : null;
		if ( 'plugin' === $type || 'theme' === $type ) {
			return $type;
		}
		if ( ! empty( $hook_extra['plugin'] ) ) {
			return 'plugin';
		}
		if ( ! empty( $hook_extra['theme'] ) ) {
			return 'theme';
		}
		return null;
	}

	/**
	 * Where the package comes from (spec §4.2 `source`).
	 *
	 * @param mixed $package Package URL or path.
	 * @return array
	 */
	public static function classify_source( $package ) {
		if ( ! is_string( $package ) || '' === $package ) {
			return array( 'kind' => 'unknown' );
		}
		if ( preg_match( '#^https?://#i', $package ) ) {
			$host = strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) );
			if ( '' === $host ) {
				return array( 'kind' => 'unknown' );
			}
			if ( 'downloads.wordpress.org' === $host ) {
				return array( 'kind' => 'wporg' );
			}
			return array( 'kind' => 'remote_host', 'host' => self::clip( $host ) );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $package ) ) {
			return array( 'kind' => 'unknown' ); // ftp://, phar://, … — not a kind the spec names
		}
		$p       = self::probe();
		$uploads = $p['uploads'];
		$path    = str_replace( '\\', '/', $package );
		// A traversal segment is never resolved as though it were safely
		// under uploads — `/../` (or a leading `../`) always answers
		// local_path, whatever the string looks like it starts with (final
		// review Task 5).
		if ( preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) ) {
			return array( 'kind' => 'local_path' );
		}
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$base = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' ) . '/';
			if ( 0 === strpos( $path, $base ) ) {
				$out = array( 'kind' => 'uploaded_zip' );
				$id  = self::attachment_id( rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/' ) . '/' . substr( $path, strlen( $base ) ) );
				if ( $id > 0 ) {
					$out['attachment_id'] = $id;
				}
				return $out;
			}
		}
		return array( 'kind' => 'local_path' );
	}

	/**
	 * The request context, read now (spec §4.2).
	 *
	 * @return array { transport, user_id, auth, app_password_name, route }
	 */
	public static function context( $siteagent_run = false ) {
		$p    = self::probe();
		$user = (int) $p['user_id'];
		$uuid = (string) $p['app_password_uuid'];
		$sa   = null !== $p['siteagent'] ? (bool) $p['siteagent'] : (bool) $siteagent_run;

		if ( $sa ) {
			$transport = 'siteagent';
		} elseif ( $p['wp_cli'] ) {
			$transport = 'wp_cli';
		} elseif ( $p['auto_update'] ) {
			$transport = 'auto_update';
		} elseif ( $p['cron'] ) {
			$transport = 'cron';
		} elseif ( $p['rest'] ) {
			$transport = 'rest';
		} elseif ( $p['admin'] && $user > 0 ) {
			$transport = 'wp_admin';
		} else {
			$transport = 'unknown';
		}

		if ( '' !== $uuid ) {
			$auth = 'application_password';
		} elseif ( $user <= 0 ) {
			$auth = 'none';
		} elseif ( $p['rest'] ) {
			$auth = $p['rest_cookie'] ? 'cookie' : 'unknown';
		} elseif ( $p['admin'] ) {
			$auth = 'cookie'; // an admin screen authenticates only by cookie
		} else {
			$auth = 'unknown';
		}

		$route = null;
		if ( $p['rest'] && is_string( $p['route'] ) && '' !== $p['route'] ) {
			$route = self::clip( explode( '?', $p['route'], 2 )[0] ); // never strtok(): it shares global tokenizer state
		}

		return array(
			'transport'         => $transport,
			'user_id'           => $user,
			'auth'              => $auth,
			'app_password_name' => '' === $uuid ? null : self::app_password_name( $user, $uuid ),
			'route'             => $route,
		);
	}

	/**
	 * Coverage restarts NOW (`evicted: true`); the ring is kept. For an
	 * install that happened but could not be recorded. Swallows its own
	 * failure — the upgrade must still complete.
	 */
	private static function mark_boundary() {
		try {
			self::write_boundary( self::now() ); // proven and retried once (Codex r14 on #595)
		} catch ( \Throwable $e ) {
			// Nothing more can be done from inside an upgrader filter.
		}
	}

	/**
	 * The claim one of SiteAgent's own active as_siteagent() calls made for
	 * $upgrader (Task 3), if any — object identity only, never position,
	 * "first run", or a target-name match (Codex r16/r17/r18 on #595: all
	 * proved spoofable; object identity is not). Always null until
	 * as_siteagent() ever populates $siteagent_claims.
	 *
	 * @param mixed $upgrader The upgrader instance upgrader_pre_download hands us.
	 * @return array|null `{ upgrader, package }`, the exact claim as_siteagent() stored.
	 */
	private static function matching_claim( $upgrader ) {
		if ( ! is_object( $upgrader ) ) {
			return null;
		}
		foreach ( self::$siteagent_claims as $claim ) {
			if ( $claim['upgrader'] === $upgrader ) {
				return $claim;
			}
		}
		return null;
	}

	/** @return string|null */
	private static function token_of( $hook_extra ) {
		return is_array( $hook_extra ) && isset( $hook_extra[ self::TOKEN_KEY ] ) && is_string( $hook_extra[ self::TOKEN_KEY ] ) && '' !== $hook_extra[ self::TOKEN_KEY ]
			? $hook_extra[ self::TOKEN_KEY ]
			: null;
	}

	/** `update` when hook_extra names the package; else `install` unless it says `update`. */
	private static function action_of( $hook_extra ) {
		if ( ! empty( $hook_extra['plugin'] ) || ! empty( $hook_extra['theme'] ) ) {
			return 'update';
		}
		return isset( $hook_extra['action'] ) && 'update' === $hook_extra['action'] ? 'update' : 'install';
	}

	/** The uuid core recorded at authentication, else the one SiteAgent captured. Never stored. */
	private static function authenticated_uuid() {
		$uuid = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
		if ( ( null === $uuid || '' === $uuid ) && class_exists( 'Aura_Worker_Security' ) ) {
			$uuid = Aura_Worker_Security::authenticating_app_password_uuid();
		}
		return null === $uuid ? '' : (string) $uuid;
	}

	/** The password's NAME — never its uuid, never its hash. */
	private static function app_password_name( $user, $uuid ) {
		if ( $user <= 0 || ! class_exists( 'WP_Application_Passwords' ) ) {
			return null;
		}
		$pw = WP_Application_Passwords::get_user_application_password( $user, $uuid );
		return is_array( $pw ) && isset( $pw['name'] ) ? self::clip( $pw['name'] ) : null;
	}

	/** @return array|null { basedir, baseurl } */
	private static function uploads_dir() {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}
		$u = wp_upload_dir( null, false );
		return is_array( $u ) && empty( $u['error'] ) ? array( 'basedir' => (string) $u['basedir'], 'baseurl' => (string) $u['baseurl'] ) : null;
	}

	/** @return int 0 when none resolves */
	private static function attachment_id( $url ) {
		$p = self::probe();
		if ( is_array( $p['attachment_ids'] ) ) {
			return (int) ( $p['attachment_ids'][ $url ] ?? 0 );
		}
		return function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
	}

	/**
	 * The installed version, from the files under $destination (Ruling R4).
	 * A plugin's MAIN file, never merely the first header found (Codex r21 on
	 * #595 — a folder can carry a bundled companion): on an update the file
	 * hook_extra['plugin'] names; on an install `<slug>.php` when it carries a
	 * header, else the ONLY header file — several candidates and no
	 * `<slug>.php` is null, never a guess by sort order (Codex r22 on #595).
	 *
	 * @param string $type        plugin|theme.
	 * @param string $destination Installed directory.
	 * @param mixed  $hook_extra  The run's hook_extra.
	 * @param string $slug        The destination directory name.
	 * @return string|null
	 */
	private static function installed_version( $type, $destination, $hook_extra = array(), $slug = '' ) {
		$p = self::probe();
		if ( is_array( $p['versions'] ) ) {
			return isset( $p['versions'][ $destination ] ) ? self::clip( $p['versions'][ $destination ] ) : null;
		}
		if ( '' === $destination || ! function_exists( 'get_file_data' ) || ! is_dir( $destination ) ) {
			return null;
		}
		$dir = rtrim( $destination, '/\\' );
		if ( 'theme' === $type ) {
			$v = get_file_data( $dir . '/style.css', array( 'Version' => 'Version' ) );
			return '' !== (string) ( $v['Version'] ?? '' ) ? self::clip( $v['Version'] ) : null;
		}
		$read = static function ( $file ) {
			if ( ! is_file( $file ) ) {
				return null;
			}
			$h = get_file_data( $file, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
			return '' !== (string) ( $h['Name'] ?? '' ) ? (string) ( $h['Version'] ?? '' ) : null; // null = no plugin header
		};
		$named = null;
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$named = $dir . '/' . basename( $hook_extra['plugin'] ); // an update: WordPress names the main file
		} elseif ( '' !== (string) $slug && null !== $read( $dir . '/' . basename( (string) $slug ) . '.php' ) ) {
			$named = $dir . '/' . basename( (string) $slug ) . '.php';
		}
		if ( null !== $named ) {
			$v = $read( $named );
		} else {
			$found = array();
			foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
				$v = $read( $file );
				if ( null !== $v ) {
					$found[] = $v;
				}
			}
			$v = 1 === count( $found ) ? $found[0] : null; // several headers: not ours to guess
		}
		return null !== $v && '' !== $v ? self::clip( $v ) : null;
	}

	/** @return array */
	private static function fresh_state( $now ) {
		return array( 'started' => gmdate( 'c', $now ), 'count_edge' => null, 'evicted' => false );
	}

	/**
	 * A stored value that is not a list of entries — or holds one row that is
	 * not an entry — is unreadable, never a shorter clean ledger: a dropped
	 * row would be an install Aura never grades (Codex r2 on #595).
	 *
	 * @param mixed $stored The entries option, null when absent.
	 * @return bool
	 */
	private static function entries_corrupt( $stored, $now ) {
		if ( null === $stored ) {
			return false;
		}
		if ( ! is_array( $stored ) || count( $stored ) > self::MAX_ENTRIES ) {
			return true; // more than the cap is not a ring this class wrote (Codex r13 on #595)
		}
		$prev = null;
		foreach ( $stored as $e ) {
			if ( ! self::valid_entry( $e, $now ) ) {
				return true;
			}
			// Newest first, always (Codex r14 on #595): rotation drops the LAST
			// row and takes the edge from the new last, so a reordered ring
			// could discard a recent install under a coverage claim.
			$t = strtotime( $e['when'] );
			if ( null !== $prev && $t > $prev ) {
				return true;
			}
			$prev = $t;
		}
		return false;
	}

	/**
	 * Store a new state and ring, in the one order that can only under-claim
	 * coverage (Codex r2/r5/r6 on #595):
	 *   1. the state, proven by read-back — refused, nothing else is written;
	 *   2. the entries, proven by read-back — refused, the state is moved to a
	 *      recovery boundary (coverage from NOW, `evicted: true`), because the
	 *      ring on disk is not the one that state describes and may be missing
	 *      the install that just finished.
	 *
	 * @param array $state The new state.
	 * @param array $kept  The new ring.
	 * @param int   $now   Now.
	 */
	private static function commit( array $state, array $kept, $now ) {
		// The state, retried once; refused twice, the ring is left as it is and
		// coverage moves to a boundary instead — the install this call carries
		// is lost, and the ledger must not claim the stretch it happened in
		// (Codex r23 on #595).
		if ( ! self::write_state( $state ) && ! self::write_state( $state ) ) {
			self::write_boundary( $now );
			return;
		}
		self::write_entries( $kept );
		if ( self::read_entries() !== $kept ) {
			self::write_boundary( $now );
		}
	}

	/**
	 * Coverage restarts at $now (`evicted: true`), the ring untouched — proven
	 * and retried once (Codex r9/r14/r23 on #595). A database that refuses
	 * this as well refuses every write: nothing can be recorded there, and the
	 * spec says so (§4.3, stated, not fixed — like the unlocked
	 * read-modify-write).
	 *
	 * @param int $now Now.
	 */
	private static function write_boundary( $now ) {
		$boundary            = self::fresh_state( $now );
		$boundary['evicted'] = true;
		if ( ! self::write_state( $boundary ) ) {
			self::write_state( $boundary );
		}
	}

	/**
	 * Either option unreadable, or the ring missing beside a state: every
	 * writer stores the two together (ensure_started(), commit()), so a state
	 * alone means the rows were lost — never an empty ledger that still
	 * claims the old coverage (Codex r8 on #595).
	 *
	 * @return bool
	 */
	private static function storage_corrupt( $stored, $state, $now ) {
		return self::entries_corrupt( $stored, $now )
			|| self::state_corrupt( $state, $now )
			|| ( null === $stored && null !== $state )
			|| self::orphan_ring( $stored, $state, $now )
			|| self::edge_contradicts_ring( $state );
	}

	/**
	 * A count edge stored without `evicted: true` (Codex r15 on #595, narrowed
	 * by controller ruling 1, Task 1 review round 1): a count eviction always
	 * sets `evicted` alongside the edge, so the two disagreeing is corruption
	 * no read can make sense of.
	 *
	 * A ring ROW older than the edge is deliberately NOT checked here anymore
	 * — it is not corruption, it is a row an earlier rotation already dropped.
	 * commit() writes the state (the new, narrower edge) before the entries
	 * (the new, shorter ring); when the entries write does not land — refused,
	 * or the process ends between the two writes — the edge is on disk ahead
	 * of a ring that is still the old, longer one, with exactly one row past
	 * it. That is the state append() and purge_rows() already expect: expired()
	 * ages a pre-edge row out the same way it ages out a 90-day-old one, and
	 * report() answers it as a normal, readable, `evicted: true` ledger. An
	 * edge corrupted to an EARLIER, still-valid instant is not detectable from
	 * storage — stated in spec §4.3.
	 *
	 * @param mixed $state The state option.
	 * @return bool
	 */
	private static function edge_contradicts_ring( $state ) {
		if ( ! is_array( $state ) || null === $state['count_edge'] ) {
			return false;
		}
		return true !== $state['evicted'];
	}

	/**
	 * Readable rows whose state is gone (Codex r10 on #595): not a new
	 * ledger — recovered behind a boundary WITH the rows kept, never
	 * overwritten by ensure_started().
	 *
	 * @return bool
	 */
	private static function orphan_ring( $stored, $state, $now ) {
		return null !== $stored && null === $state && ! self::entries_corrupt( $stored, $now );
	}

	/**
	 * A state option that is present but not exactly `{ started, count_edge,
	 * evicted }` — a real ISO `started`, a null-or-ISO `count_edge`, a bool
	 * `evicted` — is corrupted: a half state can advertise coverage the ring
	 * no longer has (Codex r6 on #595). Absent (null) is not corrupted.
	 *
	 * @param mixed $state The state option.
	 * @param int   $now   Now.
	 * @return bool
	 */
	private static function state_corrupt( $state, $now ) {
		if ( null === $state ) {
			return false;
		}
		return ! is_array( $state )
			|| array_keys( $state ) !== array( 'started', 'count_edge', 'evicted' )
			|| ! self::valid_when( $state['started'], $now )
			|| ( null !== $state['count_edge'] && ! self::valid_when( $state['count_edge'], $now ) )
			|| ! is_bool( $state['evicted'] );
	}

	/**
	 * This class's own gmdate( 'c' ): UTC "+00:00", a real calendar day, and
	 * at most a year ahead of now. Far-future stamps are corruption — they would
	 * never expire (Codex r5) — but a stamp a few days ahead is this class's own
	 * write after the host clock was corrected BACKWARD, and must stay readable
	 * (Codex r20 on #595); such a row simply expires later, by the size of the
	 * correction.
	 *
	 * @param mixed $v   Value.
	 * @param int   $now Now.
	 * @return bool
	 */
	private static function valid_when( $v, $now ) {
		return is_string( $v )
			&& preg_match( '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\d\+00:00$/', $v, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
			&& strtotime( $v ) <= $now + self::MAX_CLOCK_SKEW;
	}

	/**
	 * The §4.2 shape every entry this class writes has. A row without a
	 * parseable `when` could never expire — personal data past 90 days —
	 * and one without its door or source could never be graded (Codex r4
	 * on #595), so either makes the ledger unreadable.
	 *
	 * @param mixed $e A stored row.
	 * @return bool
	 */
	private static function valid_entry( $e, $now ) {
		static $keys = array( 'when', 'type', 'action', 'slug', 'version', 'transport', 'user_id', 'auth', 'app_password_name', 'route', 'source' );
		if ( ! is_array( $e ) || array_keys( $e ) !== $keys ) {
			return false; // exactly the §4.2 keys, in the order this class writes them
		}
		// Every string this class stores is clipped to STRING_MAX (Codex r11 on
		// #595): a longer one is not a row this class wrote.
		$bounded         = static function ( $v ) {
			return is_string( $v ) && self::char_len( $v ) <= self::STRING_MAX;
		};
		$nullable_string = static function ( $v ) use ( $bounded ) {
			return null === $v || $bounded( $v );
		};
		if ( ! self::valid_when( $e['when'], $now ) ) {
			return false;
		}
		$src = $e['source'];
		return in_array( $e['type'], array( 'plugin', 'theme' ), true )
			&& in_array( $e['action'], array( 'install', 'update' ), true )
			&& $bounded( $e['slug'] )
			&& $nullable_string( $e['version'] )
			&& in_array( $e['transport'], array( 'wp_admin', 'rest', 'wp_cli', 'cron', 'auto_update', 'siteagent', 'unknown' ), true )
			&& is_int( $e['user_id'] ) && $e['user_id'] >= 0
			&& in_array( $e['auth'], array( 'cookie', 'application_password', 'none', 'unknown' ), true )
			&& $nullable_string( $e['app_password_name'] )
			&& $nullable_string( $e['route'] )
			&& self::valid_source( $e['source'] );
	}

	/**
	 * Exactly what classify_source() writes, per kind (Codex r7 on #595): no
	 * missing field, no extra key — a corrupted nested payload must reach the
	 * recovery boundary, never report() verbatim.
	 *
	 * @param mixed $src The source.
	 * @return bool
	 */
	private static function valid_source( $src ) {
		if ( ! is_array( $src ) || ! isset( $src['kind'] ) ) {
			return false;
		}
		$keys = array_keys( $src );
		switch ( $src['kind'] ) {
			case 'wporg':
			case 'local_path':
			case 'unknown':
				return array( 'kind' ) === $keys;
			case 'remote_host':
				return array( 'kind', 'host' ) === $keys && is_string( $src['host'] ) && '' !== $src['host']
					&& self::char_len( $src['host'] ) <= self::STRING_MAX;
			case 'uploaded_zip':
				return array( 'kind' ) === $keys
					|| ( array( 'kind', 'attachment_id' ) === $keys && is_int( $src['attachment_id'] ) && $src['attachment_id'] > 0 );
			default:
				return false;
		}
	}

	/**
	 * Past 90 days, OR past the count edge (controller ruling 1, Task 1
	 * review round 1) — a row an earlier, possibly interrupted, rotation
	 * already dropped is aged out here exactly like one past 90 days, not
	 * flagged as corruption by storage_corrupt().
	 *
	 * @param mixed    $e    A stored row.
	 * @param int      $now  Now.
	 * @param int|null $edge The count edge as a timestamp, or null when unset.
	 * @return bool
	 */
	private static function expired( $e, $now, $edge = null ) {
		if ( ! is_array( $e ) || ! isset( $e['when'] ) ) {
			return false;
		}
		$t = strtotime( (string) $e['when'] );
		if ( false === $t ) {
			return false;
		}
		return $t < $now - self::MAX_AGE || ( null !== $edge && $t < $edge );
	}

	/**
	 * The state's count edge, as a timestamp — null when unset or unparseable
	 * (state_corrupt() already refuses a $state whose count_edge is set but
	 * does not parse, so null here means genuinely unset).
	 *
	 * @param mixed $state The state option.
	 * @return int|null
	 */
	private static function edge_timestamp( $state ) {
		if ( ! is_array( $state ) || ! isset( $state['count_edge'] ) || null === $state['count_edge'] ) {
			return null;
		}
		$t = strtotime( (string) $state['count_edge'] );
		return false !== $t ? $t : null;
	}

	/** @return mixed null when absent */
	private static function read_entries() {
		return is_multisite() ? get_site_option( self::OPTION, null ) : get_option( self::OPTION, null );
	}

	/** @return mixed null when absent */
	private static function read_state() {
		return is_multisite() ? get_site_option( self::STATE_OPTION, null ) : get_option( self::STATE_OPTION, null );
	}

	// Order matters: append() writes the state before the entries (see there).
	// The four writes name their option by constant, never through a variable:
	// UninstallCoverageTest resolves storage keys from source (#434 Task 10).

	private static function write_entries( array $entries ) {
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $entries );
		} else {
			update_option( self::OPTION, $entries, false );
		}
	}

	/**
	 * @return bool Whether the state is now stored — read back, because
	 *              update_option() answers false for an unchanged value too.
	 */
	private static function write_state( array $state ) {
		if ( is_multisite() ) {
			update_site_option( self::STATE_OPTION, $state );
		} else {
			update_option( self::STATE_OPTION, $state, false );
		}
		return self::read_state() === $state;
	}
}
