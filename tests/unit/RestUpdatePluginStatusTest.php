<?php
/**
 * POST /aura/v1/update/plugin answers 409 for the two outcomes that are not
 * a broken site (2.23.1): nothing offered (`aura_no_update_offered`) and
 * SiteAgent's own file (`aura_use_self_update`). A genuine failure is 500.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RestUpdatePluginStatusTest extends TestCase {

	private $api;

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_fs_method']                         = 'ftpext';
		$GLOBALS['_options']['aura_worker_site_token'] = Aura_Worker_Security::hash_token( 'tok' );
		$GLOBALS['_installed_plugins']                 = array(
			'akismet/akismet.php'                => array( 'Name' => 'Akismet', 'Version' => '1.0' ),
			Aura_Worker_Updater::SELF_PLUGIN_FILE => array( 'Name' => 'SiteAgent', 'Version' => '2.23.0' ),
		);
		$this->api = new Aura_Worker_API( new Aura_Worker_Security() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_method'], $GLOBALS['_installed_plugins'] );
		delete_option( Aura_Worker_Updater::SELF_UPDATE_LOCK );
	}

	private function call( string $plugin ): WP_REST_Response {
		$req = new WP_REST_Request();
		$req->set_header( 'X-Aura-Token', 'tok' );
		$req->set_param( 'plugin', $plugin );
		$res = $this->api->update_plugin( $req );
		$this->assertInstanceOf( WP_REST_Response::class, $res, is_wp_error( $res ) ? $res->get_error_code() : '' );
		return $res;
	}

	public function test_not_offered_is_409(): void {
		$GLOBALS['_upgrade_result'] = false;

		$res = $this->call( 'akismet/akismet.php' );

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_no_update_offered', $res->get_data()['code'] );
	}

	public function test_siteagent_itself_is_409_and_points_to_self_update(): void {
		$res = $this->call( Aura_Worker_Updater::SELF_PLUGIN_FILE );

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_use_self_update', $res->get_data()['code'] );
		$this->assertStringContainsString( 'POST /aura/v1/self-update', $res->get_data()['error'] );
		$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'] );
	}

	public function test_a_failed_run_is_500(): void {
		$GLOBALS['_site_transients']['update_plugins'] = (object) array(
			'response' => array( 'akismet/akismet.php' => (object) array( 'new_version' => '2.0' ) ),
		);
		$GLOBALS['_upgrade_result'] = null;

		$res = $this->call( 'akismet/akismet.php' );

		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_update_failed', $res->get_data()['code'] );
	}

	public function test_a_failed_reactivation_is_500(): void {
		// Codex r2 on #143: the files changed but the plugin is left disabled —
		// the site needs attention, so not 200 and not a caller error.
		$GLOBALS['_active_plugins']['akismet/akismet.php'] = true;
		$GLOBALS['_activate_plugin_result']                 = new WP_Error( 'plugin_invalid', 'Plugin file does not exist.' );
		$GLOBALS['_upgrade_effect']                          = static function () {
			unset( $GLOBALS['_active_plugins']['akismet/akismet.php'] );
		};

		$res = $this->call( 'akismet/akismet.php' );

		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'aura_reactivation_failed', $res->get_data()['code'] );
		$this->assertTrue( $res->get_data()['updated'] );
	}

	public function test_success_is_200_and_carries_reactivated(): void {
		$GLOBALS['_active_plugins']['akismet/akismet.php'] = true;
		$GLOBALS['_upgrade_effect']                          = static function () {
			unset( $GLOBALS['_active_plugins']['akismet/akismet.php'] );
		};

		$res = $this->call( 'akismet/akismet.php' );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['reactivated'] );
	}
}
