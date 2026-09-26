<?php
/**
 * An install SiteAgent's own updater performs is recorded once, with
 * transport `siteagent` (spec §4.2, §8).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class InstallLedgerSiteAgentTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => 1790424000, 'versions' => array() ) );
		Aura_Worker_Install_Ledger::ensure_started();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_upgrade_effect'] );
	}

	/** What WP_Upgrader::run() does for one theme, as the upgrade effect. */
	private function theme_run(): void {
		$GLOBALS['_upgrade_effect'] = static function ( $upgrader ) {
			$o = Aura_Worker_Install_Ledger::on_package_options( array( 'hook_extra' => array( 'theme' => 'bar', 'type' => 'theme', 'action' => 'update' ) ) );
			Aura_Worker_Install_Ledger::on_pre_download( false, 'https://downloads.wordpress.org/theme/bar.zip', $upgrader, $o['hook_extra'] );
			Aura_Worker_Install_Ledger::on_install_result( array( 'destination_name' => 'bar', 'destination' => '/x/bar' ), $o['hook_extra'] );
		};
	}

	/** One full run through the three filters on $upgrader; returns its entry. */
	private function one_run( string $slug, $upgrader, array $extra = array( 'type' => 'plugin', 'action' => 'install' ) ): array {
		$o = Aura_Worker_Install_Ledger::on_package_options( array( 'hook_extra' => $extra ) );
		Aura_Worker_Install_Ledger::on_pre_download( false, 'https://cdn.example/' . $slug . '.zip', $upgrader, $o['hook_extra'] );
		Aura_Worker_Install_Ledger::on_install_result( array( 'destination_name' => $slug, 'destination' => '/x/' . $slug ), $o['hook_extra'] );
		return Aura_Worker_Install_Ledger::report()['entries'][0];
	}

	public function test_only_runs_through_siteagents_own_upgrader_are_siteagent(): void {
		$own     = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$entries = Aura_Worker_Install_Ledger::as_siteagent( function () use ( $own ) {
			// Nested runs a filter starts — before, and for the SAME plugin
			// (Codex r17/r18 on #595) — go through their own upgrader.
			$before = $this->one_run( 'companion', new Plugin_Upgrader() );
			$same   = $this->one_run( 'own', new Plugin_Upgrader(), array( 'plugin' => 'own/own.php' ) );
			$mine   = $this->one_run( 'own', $own, array( 'plugin' => 'own/own.php' ) );
			return array( $before, $same, $mine );
		}, $own );
		$this->assertSame( array( 'unknown', 'unknown', 'siteagent' ), array_column( $entries, 'transport' ) );
	}

	public function test_the_marker_ends_with_the_call_even_on_a_throw(): void {
		try {
			Aura_Worker_Install_Ledger::as_siteagent( static function () {
				throw new RuntimeException( 'boom' );
			}, new Plugin_Upgrader() );
		} catch ( RuntimeException $e ) {
			// expected
		}
		$this->assertSame( 'unknown', $this->one_run( 'after', new Plugin_Upgrader() )['transport'] );
	}

	public function test_update_theme_records_exactly_one_siteagent_entry(): void {
		$this->theme_run();
		( new Aura_Worker_Updater() )->update_theme( 'bar' );
		$entries = Aura_Worker_Install_Ledger::report()['entries'];
		$this->assertCount( 1, $entries );
		$this->assertSame( 'siteagent', $entries[0]['transport'] );
		$this->assertSame( 'update', $entries[0]['action'] );
	}

	/**
	 * Structural, not by argument name (Codex r3 on #595): EVERY call of an
	 * upgrader's install()/upgrade()/bulk_upgrade() in the updater, whatever
	 * its receiver or argument, is either inside an as_siteagent() closure or
	 * in one of the two functions whose runs the ledger ignores anyway (core
	 * and translations never reach the ledger's scope).
	 */
	public function test_every_upgrader_call_in_the_updater_is_wrapped(): void {
		$src    = (string) file_get_contents( SA_PLUGIN_DIR . '/includes/class-aura-worker-updater.php' );
		$exempt = array( 'update_core', 'update_translations' );
		preg_match_all( '/->(install|upgrade|bulk_upgrade)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE );
		$this->assertNotEmpty( $m[0] );
		$wrapped = 0;
		foreach ( $m[0] as $hit ) {
			$before = substr( $src, 0, $hit[1] );
			preg_match_all( '/function\s+(\w+)\s*\(/', $before, $fn );
			$func = end( $fn[1] );
			if ( in_array( $func, $exempt, true ) ) {
				continue;
			}
			$opened = strrpos( $before, 'Aura_Worker_Install_Ledger::as_siteagent(' );
			$this->assertNotFalse( $opened, "an upgrader call in {$func}() runs outside as_siteagent()" );
			// The closure opened there must still be open at the call: no
			// `} );` closing an as_siteagent() between it and the call.
			// Any closure terminator — `} );` or the wrapper's own `}, $upgrader );`
			// — between the opener and the call means the call is outside it
			// (Codex r19 on #595).
			$this->assertSame( 0, preg_match( '/\}\s*(,\s*\$\w+\s*)?\)\s*;/', substr( $before, $opened ) ), "an upgrader call in {$func}() runs outside as_siteagent()" );
			$wrapped++;
		}
		$this->assertSame( 4, $wrapped ); // self-update install, update_plugin, update_theme, update_single_plugin
	}
}
