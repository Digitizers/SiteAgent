<?php
/**
 * A generic plugin update keeps the plugin's activation state (2.23.1).
 *
 * `Plugin_Upgrader::upgrade()` hooks core's `deactivate_plugin_before_upgrade`
 * at `upgrader_pre_install`, which silently deactivates the target outside
 * cron. wp-admin pairs that with the skin's re-activation iframe; a REST
 * request has no such step, so every plugin updated through
 * `/aura/v1/update/plugin` or the batch came back inactive — on success, and
 * on a failure after pre_install. The updater now records the state before
 * the upgrade and restores it afterwards, whatever the upgrade returned.
 *
 * `_upgrade_effect` models core's pre_install deactivation.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UpdatePluginPreservesActivationTest extends TestCase {

	private const PLUGIN = 'akismet/akismet.php';

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_fs_method'] = 'ftpext'; // no host probe: these tests are about activation
		// The update is offered, so no refresh runs.
		$GLOBALS['_site_transients']['update_plugins'] = (object) array(
			'response' => array( self::PLUGIN => (object) array( 'new_version' => '2.0' ) ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_method'] );
	}

	/** Core's pre_install step: silently deactivate the target (site and network). */
	private function deactivate_during_upgrade(): void {
		$GLOBALS['_upgrade_effect'] = static function ( $upgrader, $plugin_file ) {
			unset( $GLOBALS['_active_plugins'][ $plugin_file ], $GLOBALS['_network_active_plugins'][ $plugin_file ] );
		};
	}

	private function batch_entry( array $out ): array {
		$this->assertCount( 1, $out['results'] );
		return $out['results'][0];
	}

	// ----- update_plugin() -----------------------------------------------------

	public function test_an_active_plugin_whose_upgrade_succeeds_is_active_afterwards(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$this->deactivate_during_upgrade();

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertTrue( $res['success'] );
		$this->assertTrue( is_plugin_active( self::PLUGIN ), 'the plugin is active again after the update' );
		$this->assertTrue( $res['reactivated'] );
		$this->assertCount( 1, $GLOBALS['_activate_plugin_calls'] );
		$call = $GLOBALS['_activate_plugin_calls'][0];
		$this->assertSame( self::PLUGIN, $call['plugin'] );
		$this->assertFalse( $call['network_wide'] );
		$this->assertTrue( $call['silent'], 're-activation is silent: no activation hooks run' );
	}

	public function test_an_active_plugin_that_stayed_active_is_not_activated_again(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['reactivated'] );
		$this->assertSame( array(), $GLOBALS['_activate_plugin_calls'] );
	}

	public function test_an_active_plugin_whose_upgrade_returns_null_is_reactivated(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$GLOBALS['_upgrade_result']                  = null;
		$this->deactivate_during_upgrade();

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['reactivated'] );
		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
	}

	public function test_an_active_plugin_whose_upgrade_returns_a_wp_error_is_reactivated(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$GLOBALS['_upgrade_result']                  = new WP_Error( 'copy_failed', 'Could not copy file.' );
		$this->deactivate_during_upgrade();

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['reactivated'] );
		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
	}

	public function test_an_upgrade_that_throws_still_reactivates_the_plugin(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$GLOBALS['_upgrade_effect']                  = static function ( $upgrader, $plugin_file ) {
			unset( $GLOBALS['_active_plugins'][ $plugin_file ] );
			throw new RuntimeException( 'boom' );
		};

		try {
			( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );
			$this->fail( 'the exception propagates' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertTrue( is_plugin_active( self::PLUGIN ), 're-activated in the finally, before the exception left' );
		$this->assertCount( 1, $GLOBALS['_activate_plugin_calls'] );
	}

	public function test_an_inactive_plugin_stays_inactive(): void {
		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['reactivated'] );
		$this->assertFalse( is_plugin_active( self::PLUGIN ) );
		$this->assertSame( array(), $GLOBALS['_activate_plugin_calls'], 'a plugin that was inactive is never activated' );
	}

	public function test_an_inactive_plugin_whose_upgrade_fails_stays_inactive(): void {
		$GLOBALS['_upgrade_result'] = null;

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['reactivated'] );
		$this->assertSame( array(), $GLOBALS['_activate_plugin_calls'] );
	}

	public function test_a_network_active_plugin_is_reactivated_network_wide(): void {
		$GLOBALS['_is_multisite']                            = true;
		$GLOBALS['_network_active_plugins'][ self::PLUGIN ] = true;
		$this->deactivate_during_upgrade();

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['reactivated'] );
		$this->assertCount( 1, $GLOBALS['_activate_plugin_calls'] );
		$this->assertTrue( $GLOBALS['_activate_plugin_calls'][0]['network_wide'] );
		$this->assertTrue( $GLOBALS['_activate_plugin_calls'][0]['silent'] );
		$this->assertTrue( is_plugin_active_for_network( self::PLUGIN ) );
	}

	public function test_a_failed_reactivation_is_reported_with_its_message(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$GLOBALS['_activate_plugin_result']          = new WP_Error( 'plugin_invalid', 'Plugin file does not exist.' );
		$this->deactivate_during_upgrade();

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertFalse( $res['reactivated'] );
		$this->assertSame( 'Plugin file does not exist.', $res['reactivation_error'] );
		$this->assertCount( 1, $GLOBALS['_activate_plugin_calls'] );
	}

	public function test_a_successful_reactivation_carries_no_reactivation_error(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$this->deactivate_during_upgrade();

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::PLUGIN );

		$this->assertArrayNotHasKey( 'reactivation_error', $res );
	}

	// ----- the batch (and update_plugin_safely, which goes through it) ---------

	public function test_a_batch_entry_for_an_active_plugin_is_reactivated(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$this->deactivate_during_upgrade();

		$entry = $this->batch_entry( ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::PLUGIN ), 5, false ) );

		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
		$this->assertTrue( $entry['reactivated'] );
		$this->assertCount( 1, $GLOBALS['_activate_plugin_calls'] );
		$this->assertTrue( $GLOBALS['_activate_plugin_calls'][0]['silent'] );
	}

	public function test_a_failed_batch_entry_for_an_active_plugin_is_reactivated(): void {
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$GLOBALS['_upgrade_result']                  = new WP_Error( 'copy_failed', 'Could not copy file.' );
		$this->deactivate_during_upgrade();

		$entry = $this->batch_entry( ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::PLUGIN ), 5, false ) );

		$this->assertSame( 'failed', $entry['status'] );
		$this->assertSame( 'Could not copy file.', $entry['detail'] );
		$this->assertSame( 'copy_failed', $entry['code'] );
		$this->assertTrue( $entry['reactivated'] );
		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
	}

	public function test_a_batch_entry_for_an_inactive_plugin_stays_inactive(): void {
		$entry = $this->batch_entry( ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::PLUGIN ), 5, false ) );

		$this->assertFalse( $entry['reactivated'] );
		$this->assertFalse( is_plugin_active( self::PLUGIN ) );
		$this->assertSame( array(), $GLOBALS['_activate_plugin_calls'] );
	}

	public function test_a_network_active_batch_entry_is_reactivated_network_wide(): void {
		$GLOBALS['_is_multisite']                            = true;
		$GLOBALS['_network_active_plugins'][ self::PLUGIN ] = true;
		$this->deactivate_during_upgrade();

		$entry = $this->batch_entry( ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::PLUGIN ), 5, false ) );

		$this->assertTrue( $entry['reactivated'] );
		$this->assertTrue( $GLOBALS['_activate_plugin_calls'][0]['network_wide'] );
	}

	public function test_update_plugin_safely_leaves_an_active_plugin_active(): void {
		$GLOBALS['_installed_plugins']              = array( self::PLUGIN => array( 'Name' => 'Akismet', 'Version' => '1.0' ) );
		$GLOBALS['_active_plugins'][ self::PLUGIN ] = true;
		$this->deactivate_during_upgrade();
		$tool = new Aura_Tool_Update_Plugin_Safely();

		try {
			$tool->execute( array( 'plugin_slug' => 'akismet', 'create_backup' => false ) );
		} finally {
			unset( $GLOBALS['_installed_plugins'] );
		}

		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
		$this->assertCount( 1, $GLOBALS['_activate_plugin_calls'] );
	}
}
