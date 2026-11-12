<?php
/**
 * A vendor updater that registers only in wp-admin is registered before the
 * update list is refreshed (Aura#766).
 *
 * Crocoblock's JetDashboard adds JetEngine's offer only once JetEngine has
 * registered with it, and JetEngine registers only when is_admin(). A REST
 * update therefore saw no offer. The bridge runs the plugin's own registration
 * forced, builds the dashboard's managers, and the refresh then offers.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class VendorUpdaterBridgeTest extends TestCase {

	private const JET = 'jet-engine/jet-engine.php';

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_fs_method'] = 'ftpext';
		$GLOBALS['_jet_calls'] = array();
		require_once dirname( __DIR__ ) . '/fixtures/crocoblock-stubs.php';
		// The offer appears only if the refresh can ask Crocoblock: a BUILT
		// plugin manager, not merely its loaded class.
		$GLOBALS['_wp_update_plugins_effect'] = function () {
			if ( is_object( \Jet_Dashboard\Dashboard::get_instance()->plugin_manager ) ) {
				$GLOBALS['_site_transients']['update_plugins'] = (object) array(
					'response' => array( self::JET => (object) array( 'new_version' => '3.9.1.1' ) ),
				);
			}
		};
	}

	public function test_jet_engine_is_registered_forced_before_the_refresh_and_is_then_offered(): void {
		$res = ( new Aura_Worker_Updater() )->update_plugin( self::JET );

		$this->assertSame( array( 'jet_dashboard_init:force', 'register:' . self::JET, 'init_managers' ), $GLOBALS['_jet_calls'] );
		$this->assertSame( array( 'wp_update_plugins', 'Plugin_Upgrader::upgrade:' . self::JET ), $GLOBALS['_updater_calls'] );
		$this->assertTrue( $res['success'] );
		$this->assertSame( '3.9.1.1', $res['offered_version'] );
	}

	public function test_a_loaded_class_without_an_instance_still_builds_the_managers(): void {
		// Live on dev.holylandmint (2.23.4): the class was loaded, no instance
		// existed, and the class test skipped init_managers().
		$GLOBALS['_jet_class_preloaded'] = true;
		require dirname( __DIR__ ) . '/fixtures/crocoblock-plugin-manager.php';

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::JET );

		$this->assertContains( 'init_managers', $GLOBALS['_jet_calls'] );
		$this->assertTrue( $res['success'] );
		$this->assertSame( '3.9.1.1', $res['offered_version'] );
	}

	public function test_a_built_plugin_manager_is_not_rebuilt(): void {
		\Jet_Dashboard\Dashboard::get_instance()->registered[] = 'jet-engine/jet-engine.php';
		\Jet_Dashboard\Dashboard::get_instance()->init_managers();
		$GLOBALS['_jet_calls'] = array();

		( new Aura_Worker_Updater() )->update_plugin( self::JET );

		$this->assertNotContains( 'init_managers', $GLOBALS['_jet_calls'], 'init_managers() twice would double every hook' );
	}

	public function test_update_plugin_safely_says_which_vendor_updater_ran_when_nothing_is_offered(): void {
		$GLOBALS['_installed_plugins'] = array( self::JET => array( 'Name' => 'JetEngine', 'Version' => '3.7.3' ) );
		$GLOBALS['_upgrade_result']    = false;

		$res = ( new Aura_Tool_Update_Plugin_Safely() )->execute( array( 'plugin_slug' => 'jet-engine', 'create_backup' => false ) );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_no_update_offered', $res['code'] );
		$this->assertSame( 'crocoblock', $res['vendor_updater'] );
	}

	public function test_update_plugin_safely_reports_no_vendor_updater_for_an_ordinary_plugin(): void {
		$GLOBALS['_upgrade_result'] = false;

		$res = ( new Aura_Tool_Update_Plugin_Safely() )->execute( array( 'plugin_slug' => 'akismet', 'create_backup' => false ) );

		$this->assertSame( 'aura_no_update_offered', $res['code'] );
		$this->assertArrayHasKey( 'vendor_updater', $res );
		$this->assertNull( $res['vendor_updater'] );
	}

	public function test_an_offer_already_listed_needs_no_bridge(): void {
		$GLOBALS['_site_transients']['update_plugins'] = (object) array(
			'response' => array( self::JET => (object) array( 'new_version' => '3.9.1.1' ) ),
		);

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::JET );

		$this->assertSame( array(), $GLOBALS['_jet_calls'] );
		$this->assertTrue( $res['success'] );
	}

	public function test_a_non_jet_plugin_never_touches_the_dashboard(): void {
		$GLOBALS['_upgrade_result'] = false;

		( new Aura_Worker_Updater() )->update_plugin( 'akismet/akismet.php' );

		$this->assertSame( array(), $GLOBALS['_jet_calls'] );
	}

	public function test_a_jet_plugin_with_no_accessor_function_is_left_alone_and_says_not_offered(): void {
		$GLOBALS['_upgrade_result'] = false;

		$res = ( new Aura_Worker_Updater() )->update_plugin( 'jet-unknown/jet-unknown.php' );

		$this->assertSame( array(), $GLOBALS['_jet_calls'] );
		$this->assertSame( 'aura_no_update_offered', $res['code'] );
		$this->assertNull( $res['vendor_updater'] );
	}

	public function test_a_throwing_registration_is_contained(): void {
		$GLOBALS['_jet_throw']      = true;
		$GLOBALS['_upgrade_result'] = false;

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::JET );

		$this->assertSame( 'aura_no_update_offered', $res['code'] );
		$this->assertNull( $res['vendor_updater'] );
	}

	public function test_not_offered_names_the_installed_version(): void {
		$dir = WP_PLUGIN_DIR . '/jet-engine';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . '/jet-engine.php', "<?php\n/*\n * Plugin Name: JetEngine\n * Version: 3.9.1.1\n */\n" );
		$GLOBALS['_upgrade_result'] = false;

		try {
			$res = ( new Aura_Worker_Updater() )->update_plugin( self::JET );
		} finally {
			unlink( $dir . '/jet-engine.php' );
			rmdir( $dir );
		}

		$this->assertSame( 'aura_no_update_offered', $res['code'] );
		$this->assertSame( '3.9.1.1', $res['current_version'] );
		$this->assertStringContainsString( 'installed: 3.9.1.1', $res['error'] );
		$this->assertSame( 'crocoblock', $res['vendor_updater'] );
	}
}
