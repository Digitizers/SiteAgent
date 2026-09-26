<?php
/**
 * The install ledger's store: a ring of 200 entries or 90 days, `since`
 * computed on read (Aura spec 2026-09-21 §4.3).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class InstallLedgerStoreTest extends TestCase {

	const NOW = 1790424000; // 2026-09-26T12:00:00Z

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
	}

	private function entry( int $t, string $slug = 'foo' ): array {
		return array(
			'when'              => gmdate( 'c', $t ),
			'type'              => 'plugin',
			'action'            => 'install',
			'slug'              => $slug,
			'version'           => '1.0',
			'transport'         => 'rest',
			'user_id'           => 1,
			'auth'              => 'application_password',
			'app_password_name' => 'claude',
			'route'             => '/mcp/elementor',
			'source'            => array( 'kind' => 'wporg' ),
		);
	}

	public function test_a_site_that_never_stamped_reports_since_now_and_nothing(): void {
		$this->assertSame(
			array( 'since' => gmdate( 'c', self::NOW ), 'entries' => array(), 'total' => 0, 'evicted' => false ),
			Aura_Worker_Install_Ledger::report()
		);
	}

	public function test_ensure_started_stamps_once_and_never_moves(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW + 3600 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		$state = get_option( Aura_Worker_Install_Ledger::STATE_OPTION );
		$this->assertSame( array( 'started' => gmdate( 'c', self::NOW ), 'count_edge' => null, 'evicted' => false ), $state );
		$this->assertSame( gmdate( 'c', self::NOW ), Aura_Worker_Install_Ledger::report()['since'] );
	}

	public function test_since_is_never_earlier_than_ninety_days(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 200 * 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		$this->assertSame( gmdate( 'c', self::NOW - 7776000 ), Aura_Worker_Install_Ledger::report()['since'] );
	}

	public function test_append_is_newest_first_and_not_autoloaded(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW - 60, 'old' ) );
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'new' ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'new', 'old' ), array_column( $r['entries'], 'slug' ) );
		$this->assertSame( 2, $r['total'] );
		$this->assertFalse( $r['evicted'] );
		$this->assertFalse( $GLOBALS['_rows_autoload'][ Aura_Worker_Install_Ledger::OPTION ] );
		$this->assertFalse( $GLOBALS['_rows_autoload'][ Aura_Worker_Install_Ledger::STATE_OPTION ] );
	}

	public function test_the_201st_entry_rotates_the_oldest_and_moves_since_to_the_count_edge(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		for ( $i = 0; $i < 201; $i++ ) {
			$t = self::NOW - 3600 + $i;
			Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => $t ) );
			Aura_Worker_Install_Ledger::append( $this->entry( $t, 'p' . $i ) );
		}
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( 200, $r['total'] );
		$this->assertSame( 'p200', $r['entries'][0]['slug'] );
		$this->assertSame( 'p1', $r['entries'][199]['slug'] ); // p0 rotated out
		$this->assertTrue( $r['evicted'] );
		$this->assertSame( gmdate( 'c', self::NOW - 3600 + 1 ), $r['since'] ); // the oldest entry still held
	}

	public function test_an_append_drops_entries_past_ninety_days_and_marks_evicted(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW - 91 * 86400, 'ancient' ) );
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'fresh' ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'fresh' ), array_column( $r['entries'], 'slug' ) );
		$this->assertTrue( $r['evicted'] );
		$this->assertTrue( get_option( Aura_Worker_Install_Ledger::STATE_OPTION )['evicted'] );
	}

	public function test_expired_entries_are_purged_by_the_read(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 200 * 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW - 10, 'fresh' ), $this->entry( self::NOW - 100 * 86400, 'stale' ) );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'fresh' ), array_column( $r['entries'], 'slug' ) );
		$this->assertTrue( $r['evicted'] );
		$this->assertSame( gmdate( 'c', self::NOW - 7776000 ), $r['since'] );
		// Personal data past 90 days is DELETED, not only hidden (Codex r3 on #595).
		$this->assertSame( array( 'fresh' ), array_column( get_option( Aura_Worker_Install_Ledger::OPTION ), 'slug' ) );
		$this->assertTrue( get_option( Aura_Worker_Install_Ledger::STATE_OPTION )['evicted'] );
	}

	public function test_the_daily_purge_removes_expired_rows_without_a_read(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW - 100 * 86400, 'stale' ) );
		Aura_Worker_Install_Ledger::purge_expired(); // what core's daily wp_scheduled_delete calls (hooked in Task 2)
		$this->assertSame( array(), get_option( Aura_Worker_Install_Ledger::OPTION ) );
	}

	public function test_the_daily_purge_deletes_an_unreadable_ledger_behind_a_boundary(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 30 * 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW - 60 ), 'broken' );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report() ); // a read names it…
		Aura_Worker_Install_Ledger::purge_expired(); // …the daily pass deletes it
		$this->assertSame( array(), get_option( Aura_Worker_Install_Ledger::OPTION ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( gmdate( 'c', self::NOW ), array(), true ), array( $r['since'], $r['entries'], $r['evicted'] ) );
	}

	public function test_a_refused_state_write_leaves_the_entries_untouched(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		$stored = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$stored[] = $this->entry( self::NOW - 3600 - $i, 'p' . $i );
		}
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ]              = $stored;
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = true;
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'the-201st' ) );
		$this->assertSame( $stored, get_option( Aura_Worker_Install_Ledger::OPTION ) ); // no truncation under the old edge
	}

	public function test_the_boundary_write_is_retried_once(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 30 * 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Install_Ledger::OPTION ] = true; // the ring write is refused
		$state_writes = 0;
		add_filter( 'sanitize_option_' . Aura_Worker_Install_Ledger::STATE_OPTION, static function ( $v ) use ( &$state_writes ) {
			if ( 1 === ++$state_writes ) {
				// The new state lands; the NEXT state write — the first boundary
				// attempt — is refused once (the stub's int counter).
				$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = 1;
			}
			return $v;
		} );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'lost' ) );
		$this->assertSame( 2, $state_writes ); // the new state, then the retried boundary
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( gmdate( 'c', self::NOW ), true ), array( $r['since'], $r['evicted'] ) );
	}

	public function test_a_state_write_refused_once_is_retried_and_the_install_recorded(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = 1; // one transient refusal
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'kept' ) );
		$this->assertSame( array( 'kept' ), array_column( Aura_Worker_Install_Ledger::report()['entries'], 'slug' ) ); // Codex r23 on #595
	}

	public function test_a_refused_entries_write_moves_coverage_to_a_boundary(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 30 * 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Install_Ledger::OPTION ] = true;
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'lost' ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( gmdate( 'c', self::NOW ), $r['since'] ); // not the stamp from 30 days ago
		$this->assertTrue( $r['evicted'] );
	}

	public function test_a_state_without_its_ring_is_unreadable_until_the_daily_pass(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$this->assertSame( array(), get_option( Aura_Worker_Install_Ledger::OPTION ) ); // the ring is created with the state
		delete_option( Aura_Worker_Install_Ledger::OPTION ); // the rows vanish; the state stays
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report() );
		Aura_Worker_Install_Ledger::purge_expired();
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( gmdate( 'c', self::NOW ), true ), array( $r['since'], $r['evicted'] ) );
	}

	public function test_an_orphaned_ring_is_kept_behind_a_boundary_never_overwritten(): void {
		$rows = array( $this->entry( self::NOW - 60, 'evidence' ) );
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = $rows; // the state is gone
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report() );
		Aura_Worker_Install_Ledger::ensure_started(); // the next version change
		$this->assertSame( $rows, get_option( Aura_Worker_Install_Ledger::OPTION ) ); // not overwritten
		Aura_Worker_Install_Ledger::purge_expired(); // the daily pass
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'evidence' ), array_column( $r['entries'], 'slug' ) );
		$this->assertSame( array( gmdate( 'c', self::NOW ), true ), array( $r['since'], $r['evicted'] ) );
	}

	public function test_a_half_state_is_unreadable(): void {
		foreach ( array(
			'started only'     => array( 'started' => gmdate( 'c', self::NOW - 86400 ) ),
			'evicted a string' => array( 'started' => gmdate( 'c', self::NOW ), 'count_edge' => null, 'evicted' => 'no' ),
			'bad started'      => array( 'started' => 'long ago', 'count_edge' => null, 'evicted' => false ),
			'bad count_edge'   => array( 'started' => gmdate( 'c', self::NOW ), 'count_edge' => 7, 'evicted' => true ),
			'future started'   => array( 'started' => gmdate( 'c', self::NOW + 400 * 86400 ), 'count_edge' => null, 'evicted' => false ),
		) as $label => $state ) {
			sa_reset_state();
			Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
			// A valid (empty) ring beside it, so only the state's own shape can
			// make this unreadable (Codex r9 on #595).
			$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ]       = array();
			$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = $state;
			$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report(), $label );
		}
	}

	public function test_a_purge_with_nothing_expired_writes_nothing(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW, 'fresh' ) );
		$writes = 0;
		foreach ( array( Aura_Worker_Install_Ledger::OPTION, Aura_Worker_Install_Ledger::STATE_OPTION ) as $opt ) {
			add_filter( "sanitize_option_{$opt}", static function ( $v ) use ( &$writes ) {
				$writes++;
				return $v;
			} );
		}
		Aura_Worker_Install_Ledger::purge_expired();
		$this->assertSame( 0, $writes );
	}

	public function test_a_corrupted_option_is_an_error_not_an_empty_ledger(): void {
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = 'garbage';
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report() );
		sa_reset_state();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = 7;
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report() );
		sa_reset_state();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW ), 'not-an-entry' );
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report() ); // one bad row, never a shorter clean ledger
		foreach ( array(
			'no when'        => array_diff_key( $this->entry( self::NOW ), array( 'when' => 1 ) ),
			'unparsable when' => array_merge( $this->entry( self::NOW ), array( 'when' => 'soon' ) ),
			'no source'      => array_diff_key( $this->entry( self::NOW ), array( 'source' => 1 ) ),
			'no transport'   => array_diff_key( $this->entry( self::NOW ), array( 'transport' => 1 ) ),
			'bad type'       => array_merge( $this->entry( self::NOW ), array( 'type' => 'core' ) ),
			'no version key' => array_diff_key( $this->entry( self::NOW ), array( 'version' => 1 ) ),
			'bad transport'  => array_merge( $this->entry( self::NOW ), array( 'transport' => 'rets' ) ),
			'bad auth'       => array_merge( $this->entry( self::NOW ), array( 'auth' => 'bearer' ) ),
			'bad kind'       => array_merge( $this->entry( self::NOW ), array( 'source' => array( 'kind' => 'martian' ) ) ),
			'host missing'   => array_merge( $this->entry( self::NOW ), array( 'source' => array( 'kind' => 'remote_host' ) ) ),
			'empty host'     => array_merge( $this->entry( self::NOW ), array( 'source' => array( 'kind' => 'remote_host', 'host' => '' ) ) ),
			'string attach'  => array_merge( $this->entry( self::NOW ), array( 'source' => array( 'kind' => 'uploaded_zip', 'attachment_id' => '9' ) ) ),
			'extra key'      => array_merge( $this->entry( self::NOW ), array( 'source' => array( 'kind' => 'wporg', 'uuid' => 'secret' ) ) ),
			'relative when'  => array_merge( $this->entry( self::NOW ), array( 'when' => '+1 year' ) ),
			'future when'    => array_merge( $this->entry( self::NOW ), array( 'when' => gmdate( 'c', self::NOW + 400 * 86400 ) ) ),
			'Feb 30'         => array_merge( $this->entry( self::NOW ), array( 'when' => '2026-02-30T00:00:00+00:00' ) ),
			'negative user'  => array_merge( $this->entry( self::NOW ), array( 'user_id' => -1 ) ),
			'long route'     => array_merge( $this->entry( self::NOW ), array( 'route' => str_repeat( 'r', 201 ) ) ),
			'long name'      => array_merge( $this->entry( self::NOW ), array( 'app_password_name' => str_repeat( 'n', 201 ) ) ),
			'long host'      => array_merge( $this->entry( self::NOW ), array( 'source' => array( 'kind' => 'remote_host', 'host' => str_repeat( 'h', 201 ) ) ) ),
		) as $label => $row ) {
			sa_reset_state();
			Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
			// A valid state beside the ring, so only the row itself can make
			// this unreadable (Codex r11 on #595).
			$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = array( 'started' => gmdate( 'c', self::NOW - 86400 ), 'count_edge' => null, 'evicted' => false );
			$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ]       = array( $this->entry( self::NOW ), $row );
			$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report(), $label );
		}
		// …and the same valid pair with a good second row reads fine.
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW ), $this->entry( self::NOW - 60, 'ok' ) );
		$this->assertSame( 2, Aura_Worker_Install_Ledger::report()['total'] );
	}

	public function test_an_append_writes_the_state_before_the_entries(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$order = array();
		foreach ( array( Aura_Worker_Install_Ledger::STATE_OPTION, Aura_Worker_Install_Ledger::OPTION ) as $opt ) {
			add_filter( "sanitize_option_{$opt}", static function ( $v ) use ( $opt, &$order ) {
				$order[] = $opt;
				return $v;
			} );
		}
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW ) );
		$this->assertSame( array( Aura_Worker_Install_Ledger::STATE_OPTION, Aura_Worker_Install_Ledger::OPTION ), $order );
	}

	public function test_an_append_over_corrupted_entries_restarts_coverage_there(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 30 * 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = 'garbage';
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'after' ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'after' ), array_column( $r['entries'], 'slug' ) );
		$this->assertSame( gmdate( 'c', self::NOW ), $r['since'] ); // not the stamp from 30 days ago
		$this->assertTrue( $r['evicted'] );
	}

	public function test_an_append_over_a_malformed_row_restarts_coverage_there(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 30 * 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW - 60, 'kept?' ), 42 );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'after' ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'after' ), array_column( $r['entries'], 'slug' ) );
		$this->assertSame( gmdate( 'c', self::NOW ), $r['since'] );
		$this->assertTrue( $r['evicted'] );
	}

	public function test_an_append_over_a_corrupted_state_restarts_coverage_there(): void {
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ]       = array( $this->entry( self::NOW - 60, 'before' ) );
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = 7;
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'after' ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'after' ), array_column( $r['entries'], 'slug' ) );
		$this->assertSame( gmdate( 'c', self::NOW ), $r['since'] );
		$this->assertTrue( $r['evicted'] );
	}

	public function test_multisite_keeps_both_rows_as_network_options(): void {
		$GLOBALS['_is_multisite'] = true;
		Aura_Worker_Install_Ledger::ensure_started();
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW ) );
		$this->assertArrayHasKey( Aura_Worker_Install_Ledger::OPTION, $GLOBALS['_site_options'] );
		$this->assertArrayHasKey( Aura_Worker_Install_Ledger::STATE_OPTION, $GLOBALS['_site_options'] );
		$this->assertArrayNotHasKey( Aura_Worker_Install_Ledger::OPTION, $GLOBALS['_options'] );
		$this->assertSame( 1, Aura_Worker_Install_Ledger::report()['total'] );
	}

	public function test_strings_are_clipped_multibyte_safe(): void {
		// Counted without mbstring, so this runs where clip()'s fallback does (Codex r13 on #595).
		$this->assertSame( 200, preg_match_all( '/./us', Aura_Worker_Install_Ledger::clip( str_repeat( 'ש', 300 ) ) ) );
	}

	public function test_a_clock_that_steps_back_keeps_the_ring_readable(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW, 'first' ) );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW - 3600 ) ); // NTP stepped the clock back
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW - 3600, 'second' ) );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'second', 'first' ), array_column( $r['entries'], 'slug' ) );
		$this->assertSame( gmdate( 'c', self::NOW ), $r['entries'][0]['when'] ); // clamped to the head
	}

	public function test_a_count_edge_that_contradicts_the_ring_is_unreadable(): void {
		$ring = array( $this->entry( self::NOW, 'a' ), $this->entry( self::NOW - 600, 'b' ) );
		foreach ( array(
			'edge without eviction'   => array( 'started' => gmdate( 'c', self::NOW - 86400 ), 'count_edge' => gmdate( 'c', self::NOW - 600 ), 'evicted' => false ),
			'edge newer than oldest'  => array( 'started' => gmdate( 'c', self::NOW - 86400 ), 'count_edge' => gmdate( 'c', self::NOW - 60 ), 'evicted' => true ),
		) as $label => $state ) {
			sa_reset_state();
			Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) );
			$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ]       = $ring;
			$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = $state;
			$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report(), $label );
		}
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = array( 'started' => gmdate( 'c', self::NOW - 86400 ), 'count_edge' => gmdate( 'c', self::NOW - 600 ), 'evicted' => true );
		$this->assertSame( 2, Aura_Worker_Install_Ledger::report()['total'] ); // the edge AT the oldest row is the normal case
	}

	public function test_rows_written_before_a_large_backward_clock_correction_stay_readable(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW + 3 * 86400 ) );
		Aura_Worker_Install_Ledger::ensure_started();
		Aura_Worker_Install_Ledger::append( $this->entry( self::NOW + 3 * 86400, 'before-correction' ) );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => self::NOW ) ); // the clock went back 3 days
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( 'before-correction' ), array_column( $r['entries'], 'slug' ) ); // not ledger_unreadable
	}

	public function test_a_ring_out_of_order_is_unreadable(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW - 60, 'older' ), $this->entry( self::NOW, 'newer' ) );
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report() );
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = array( $this->entry( self::NOW, 'a' ), $this->entry( self::NOW, 'same-second' ) );
		$this->assertSame( 2, Aura_Worker_Install_Ledger::report()['total'] ); // equal stamps are fine
	}

	public function test_a_ring_over_the_cap_is_unreadable(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$rows = array();
		for ( $i = 0; $i < 201; $i++ ) {
			$rows[] = $this->entry( self::NOW - $i, 'p' . $i );
		}
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = $rows;
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), Aura_Worker_Install_Ledger::report() );
		array_pop( $rows );
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = $rows; // exactly 200 is fine
		$this->assertSame( 200, Aura_Worker_Install_Ledger::report()['total'] );
	}

	public function test_a_long_multibyte_name_clip_wrote_is_a_valid_row(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		$name = Aura_Worker_Install_Ledger::clip( str_repeat( 'ש', 300 ) ); // 200 characters, 400 bytes
		Aura_Worker_Install_Ledger::append( array_merge( $this->entry( self::NOW ), array( 'app_password_name' => $name ) ) );
		$this->assertSame( $name, Aura_Worker_Install_Ledger::report()['entries'][0]['app_password_name'] );
	}
}
