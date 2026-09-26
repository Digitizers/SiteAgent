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
	 * Append one entry: newest first, then retention — 90 days, then 200
	 * entries. Read-modify-write without a lock: two installs finishing in
	 * the same instant can lose one entry (spec §4.3, stated, not fixed).
	 *
	 * @param array $entry The entry (spec §4.2).
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
			return;
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
	}

	/**
	 * The `installs` subtree of audit_agent_code (spec §4.3). Purges rows past
	 * 90 days first — retention is physical, the rows hold personal data — and
	 * still leaves them out of the answer should that write fail.
	 *
	 * @return array { since, entries, total, evicted } | { error }
	 */
	public static function report() {
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

	/** Clear every static (sa_reset_state()). */
	public static function reset_for_tests() {
		self::$probe_overrides = array();
	}

	/**
	 * Every environment fact this class reads, in one place (Ruling R2).
	 *
	 * @return array
	 */
	private static function probe() {
		$real = array(
			'now' => time(),
		);
		return array_merge( $real, self::$probe_overrides );
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
