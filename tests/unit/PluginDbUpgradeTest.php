<?php
/**
 * A plugin's own database upgrade after an MCP update (2.23.7, Aura#766).
 *
 * On goldbuy.co.il update_plugin_safely left Elementor 4.4.0 files over a
 * 4.2.3 database: the migration runs on the next wp-admin load, which an
 * agent never makes. update_plugin_safely now reports whether it is due,
 * judged from the new file header (the request still runs the old build),
 * and run_plugin_db_upgrade runs it.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class PluginDbUpgradeTest extends TestCase {

	private const ELEMENTOR = 'elementor/elementor.php';

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_fs_method'] = 'ftpext';
		$this->writePlugin( 'elementor', 'elementor.php', '4.4.0' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_http_response'], $GLOBALS['_wp_http_calls'] ); // sa_reset_state() keeps the call log
		foreach ( array( 'elementor', 'fake-db' ) as $dir ) {
			foreach ( glob( WP_PLUGIN_DIR . '/' . $dir . '/*' ) ?: array() as $f ) {
				@unlink( $f );
			}
			@rmdir( WP_PLUGIN_DIR . '/' . $dir );
		}
	}

	private function writePlugin( string $dir, string $file, string $version ): void {
		$path = WP_PLUGIN_DIR . '/' . $dir;
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}
		file_put_contents( $path . '/' . $file, "<?php\n/*\n * Plugin Name: {$dir}\n * Version: {$version}\n */\n" );
	}

	public function test_pending_when_the_database_is_behind_the_new_file(): void {
		update_option( 'elementor_version', '4.2.3' );

		$st = ( new Aura_Worker_Updater() )->db_upgrade_status( self::ELEMENTOR );

		$this->assertSame( array( 'key' => 'elementor', 'status' => 'pending', 'from' => '4.2.3', 'to' => '4.4.0' ), $st );
	}

	public function test_not_needed_when_the_database_is_current(): void {
		update_option( 'elementor_version', '4.4.0' );

		$this->assertSame( 'not_needed', ( new Aura_Worker_Updater() )->db_upgrade_status( self::ELEMENTOR )['status'] );
	}

	public function test_a_never_migrated_plugin_is_pending(): void {
		$this->assertSame( 'pending', ( new Aura_Worker_Updater() )->db_upgrade_status( self::ELEMENTOR )['status'] );
	}

	public function test_a_plugin_without_a_registered_upgrade_answers_null(): void {
		$this->assertNull( ( new Aura_Worker_Updater() )->db_upgrade_status( 'akismet/akismet.php' ) );
	}

	public function test_update_plugin_safely_reports_db_upgrade_after_a_successful_update(): void {
		update_option( 'elementor_version', '4.2.3' );
		$GLOBALS['_http_response']     = array( 'response' => array( 'code' => 200 ), 'body' => '<html><body>ok</body></html>' ); // a healthy homepage
		$GLOBALS['_installed_plugins'] = array( self::ELEMENTOR => array( 'Name' => 'Elementor', 'Version' => '4.2.3' ) );
		$GLOBALS['_site_transients']['update_plugins'] = (object) array(
			'response' => array( self::ELEMENTOR => (object) array( 'new_version' => '4.4.0' ) ),
		);

		$res = ( new Aura_Tool_Update_Plugin_Safely() )->execute( array( 'plugin_slug' => 'elementor', 'create_backup' => false ) );

		$this->assertTrue( $res['success'], (string) json_encode( $res ) );
		$this->assertSame( 'pending', $res['db_upgrade']['status'] );
		$this->assertSame( 'elementor', $res['db_upgrade']['key'] );
	}

	public function test_a_failed_update_reports_no_db_upgrade(): void {
		$GLOBALS['_installed_plugins'] = array( self::ELEMENTOR => array( 'Name' => 'Elementor', 'Version' => '4.2.3' ) );
		$GLOBALS['_upgrade_result']    = false;

		$res = ( new Aura_Tool_Update_Plugin_Safely() )->execute( array( 'plugin_slug' => 'elementor', 'create_backup' => false ) );

		$this->assertFalse( $res['success'] );
		$this->assertArrayNotHasKey( 'db_upgrade', $res );
	}

	public function test_run_plugin_db_upgrade_refuses_an_unknown_key_and_never_runs_core(): void {
		$tool = ( new Aura_Worker_Tools() )->get_tool( 'run_plugin_db_upgrade' );

		foreach ( array( '', 'akismet', 'core' ) as $key ) {
			$res = $tool->execute( array( 'plugin_slug' => $key ) );
			$this->assertFalse( $res['success'], "key '{$key}'" );
		}
		$res = $tool->execute( array() );
		$this->assertFalse( $res['success'] );
	}

	public function test_run_plugin_db_upgrade_runs_the_registered_migration_and_reports_after(): void {
		$this->writePlugin( 'fake-db', 'fake-db.php', '2.0' );
		update_option( 'fake_db_version', '1.0' );
		add_filter(
			'aura_worker_migration_registry',
			static function ( $registry ) {
				$registry['fake-db'] = array(
					'label'     => 'Fake',
					'file'      => 'fake-db/fake-db.php',
					'db_option' => 'fake_db_version',
					'detect'    => static function () {
						return true;
					},
					'pending'   => static function () {
						return version_compare( get_option( 'fake_db_version' ), '2.0', '<' );
					},
					'run'       => static function () {
						update_option( 'fake_db_version', '2.0' );
					},
				);
				return $registry;
			}
		);

		$res = ( new Aura_Worker_Tools() )->get_tool( 'run_plugin_db_upgrade' )->execute( array( 'plugin_slug' => 'fake-db' ) );

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'fake-db', $res['plugin'] );
		$this->assertSame( 'not_needed', $res['db_upgrade']['status'] );
		$this->assertSame( '2.0', $res['db_upgrade']['from'] );
	}

	public function test_run_plugin_db_upgrade_requires_approval_and_declares_the_plugin(): void {
		$tool = ( new Aura_Worker_Tools() )->get_tool( 'run_plugin_db_upgrade' );

		$this->assertTrue( $tool->get_annotations()['requires_approval'] );
		$this->assertFalse( $tool->get_annotations()['read_only'] );
		$this->assertSame( array( array( 'type' => 'plugin', 'id' => 'elementor-pro' ) ), $tool->touches( array( 'plugin_slug' => 'elementor-pro' ) ) );
	}
}
