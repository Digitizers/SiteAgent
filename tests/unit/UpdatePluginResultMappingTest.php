<?php
/**
 * What a generic plugin update's upgrader result means (2.23.1).
 *
 * `Plugin_Upgrader::upgrade()` returns:
 * - `false` when `update_plugins->response[$plugin]` has no entry — nothing
 *   is offered and nothing was touched;
 * - `null` when `run()` failed: `$result` has no default and run()'s own
 *   return (the WP_Error) is discarded. It used to be reported as "No update
 *   available for this plugin." — the update WAS offered; the download,
 *   unpack or install failed. The reason survives only in the skin.
 * - a WP_Error, kept as it is.
 *
 * A missing offer is refreshed once (`wp_update_plugins()`) before upgrade().
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UpdatePluginResultMappingTest extends TestCase {

	private const PLUGIN = 'akismet/akismet.php';

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_fs_method'] = 'ftpext';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_method'] );
	}

	private function offer( string $version = '2.0' ): void {
		$GLOBALS['_site_transients']['update_plugins'] = (object) array(
			'response' => array( self::PLUGIN => (object) array( 'new_version' => $version ) ),
		);
	}

	public function test_null_is_an_update_failure_carrying_the_skins_reason(): void {
		$this->offer();
		$GLOBALS['_upgrade_result']   = null;
		$GLOBALS['_upgrade_messages'] = array(
			'Downloading update from https://example.com/akismet.zip&#8230;',
			'Download failed. Forbidden',
			'Plugin update failed.',
		);

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_update_failed', $res['code'] );
		$this->assertStringStartsWith( 'Update failed', $res['error'] );
		$this->assertStringContainsString( 'Download failed. Forbidden', $res['error'] );
		$this->assertStringNotContainsString( 'No update available', $res['error'] );
	}

	public function test_null_with_no_skin_messages_is_still_a_failure_and_not_no_update(): void {
		$this->offer();
		$GLOBALS['_upgrade_result'] = null;

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_update_failed', $res['code'] );
		$this->assertStringStartsWith( 'Update failed', $res['error'] );
		$this->assertStringNotContainsString( 'No update available', $res['error'] );
	}

	public function test_false_is_no_update_offered(): void {
		$this->offer();
		$GLOBALS['_upgrade_result'] = false;

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_no_update_offered', $res['code'] );
	}

	public function test_a_wp_error_keeps_its_message_and_code(): void {
		$this->offer();
		$GLOBALS['_upgrade_result'] = new WP_Error( 'fs_unavailable', 'Could not access filesystem.' );

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'fs_unavailable', $res['code'] );
		$this->assertSame( 'Could not access filesystem.', $res['error'] );
	}

	public function test_success_reports_the_offered_version(): void {
		$this->offer( '5.4.1' );

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertTrue( $res['success'] );
		$this->assertSame( '5.4.1', $res['offered_version'] );
		$this->assertNotContains( 'wp_update_plugins', $GLOBALS['_updater_calls'], 'an existing offer is not refreshed' );
	}

	public function test_a_missing_offer_is_refreshed_once_before_the_upgrade(): void {
		$GLOBALS['_wp_update_plugins_effect'] = function () {
			$this->offer( '3.1' );
		};

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertSame( array( 'wp_update_plugins', 'Plugin_Upgrader::upgrade:' . self::PLUGIN ), $GLOBALS['_updater_calls'] );
		$this->assertTrue( $res['success'] );
		$this->assertSame( '3.1', $res['offered_version'], 'the offer is re-read after the refresh' );
	}

	public function test_a_refresh_that_still_offers_nothing_upgrades_once_and_says_not_offered(): void {
		$GLOBALS['_upgrade_result'] = false; // what core answers with no entry

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertSame( array( 'wp_update_plugins', 'Plugin_Upgrader::upgrade:' . self::PLUGIN ), $GLOBALS['_updater_calls'] );
		$this->assertSame( 'aura_no_update_offered', $res['code'] );
		$this->assertArrayNotHasKey( 'offered_version', $res );
	}

	public function test_the_batch_maps_null_and_false_the_same_way(): void {
		$this->offer();
		$GLOBALS['_upgrade_result']   = null;
		$GLOBALS['_upgrade_messages'] = array( 'Unpacking the update&#8230;', 'The package could not be installed. PCLZIP_ERR_BAD_FORMAT' );

		$null = ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::PLUGIN ), 5, false )['results'][0];

		$this->assertSame( 'failed', $null['status'] );
		$this->assertSame( 'aura_update_failed', $null['code'] );
		$this->assertStringContainsString( 'PCLZIP_ERR_BAD_FORMAT', $null['detail'] );

		$GLOBALS['_upgrade_result'] = false;
		$false = ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::PLUGIN ), 5, false )['results'][0];

		$this->assertSame( 'failed', $false['status'] );
		$this->assertSame( 'aura_no_update_offered', $false['code'] );
	}

	public function test_the_batch_refreshes_a_missing_offer_once_per_entry_before_the_upgrade(): void {
		( new Aura_Worker_Updater() )->batch_update_plugins( array( self::PLUGIN ), 5, false );

		$this->assertSame( array( 'wp_update_plugins', 'Plugin_Upgrader::upgrade:' . self::PLUGIN ), $GLOBALS['_updater_calls'] );
	}
}
