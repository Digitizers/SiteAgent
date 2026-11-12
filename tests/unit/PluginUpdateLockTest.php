<?php
/**
 * One plugin mutation at a time per site (2.23.6, Aura#766).
 *
 * On goldbuy.co.il eight updates approved seconds apart ran side by side.
 * WP_Upgrader::unpack_package() clears all of wp-content/upgrade before it
 * unpacks, so they wiped each other's working files ("Could not copy file")
 * and one read the plugin list mid-replace ("imagifysimple-sitemap"). Every
 * plugin mutation now runs under the site's one claim; a second answers
 * aura_update_in_progress and runs nothing.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class PluginUpdateLockTest extends TestCase {

	private const PLUGIN = 'akismet/akismet.php';

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_fs_method'] = 'ftpext';
		$GLOBALS['_site_transients']['update_plugins'] = (object) array(
			'response' => array( self::PLUGIN => (object) array( 'new_version' => '2.0' ) ),
		);
	}

	public function test_an_update_while_another_holds_the_claim_runs_nothing_and_says_busy(): void {
		$holder = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_update_in_progress', $res['code'] );
		$this->assertTrue( $res['in_progress'] );
		$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'] );
		$this->assertStringStartsWith( $holder . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'a refused update never releases the holder\'s claim' );
	}

	public function test_an_update_takes_the_claim_while_it_runs_and_releases_it(): void {
		$seen = null;
		$GLOBALS['_upgrade_effect'] = function () use ( &$seen ) {
			$seen = sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
		};

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertTrue( $res['success'] );
		$this->assertNotNull( $seen, 'the claim is held while the upgrader runs' );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'and released after' );
	}

	public function test_the_batch_refuses_its_entries_while_another_holds_the_claim(): void {
		Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );

		$entry = ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::PLUGIN ), 5, false )['results'][0];

		$this->assertSame( 'failed', $entry['status'] );
		$this->assertSame( 'aura_update_in_progress', $entry['code'] );
		$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'] );
	}

	public function test_update_plugin_safely_reports_the_busy_code(): void {
		Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );

		$res = ( new Aura_Tool_Update_Plugin_Safely() )->execute( array( 'plugin_slug' => 'akismet', 'create_backup' => false ) );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_update_in_progress', $res['code'] );
	}
}
