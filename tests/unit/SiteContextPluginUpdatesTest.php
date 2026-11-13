<?php
/**
 * SA#151: get_site_context reports outdated plugins from a list no older
 * than 12 hours — a stale or missing list is refreshed first.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SiteContextPluginUpdatesTest extends TestCase {

	private const NOW = 1800000000;

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_site_transients']          = array();
		$GLOBALS['_updater_calls']            = array();
		$GLOBALS['_wp_update_plugins_effect'] = null;
	}

	private static function list_checked( int $at, array $response = array() ): object {
		return (object) array( 'last_checked' => $at, 'response' => $response );
	}

	public function test_a_fresh_list_is_reported_as_stored_without_a_check(): void {
		$GLOBALS['_site_transients']['update_plugins'] = self::list_checked( self::NOW - 3600, array( 'a/a.php' => (object) array() ) );
		$r = Aura_Tool_Site_Context::plugin_updates( self::NOW );
		$this->assertFalse( $r['refreshed'] );
		$this->assertSame( self::NOW - 3600, $r['checked_at'] );
		$this->assertNotContains( 'wp_update_plugins', $GLOBALS['_updater_calls'] );
	}

	/** What core does around its request: stamp first, then ask. */
	private static function wporg_answers( int $code ): void {
		$GLOBALS['_site_transients']['update_plugins']->last_checked = self::NOW;
		$response = 0 === $code ? new WP_Error( 'http', 'down' ) : array( 'response' => array( 'code' => $code ) );
		do_action( 'http_api_debug', $response, 'response', 'Requests', array(), 'https://api.wordpress.org/plugins/update-check/1.1/' );
	}

	public function test_a_list_older_than_12_hours_is_refreshed_before_reporting(): void {
		// The goldbuy case: the stored list says nothing is outdated, from days ago.
		$GLOBALS['_site_transients']['update_plugins'] = self::list_checked( self::NOW - 5 * 86400 );
		$GLOBALS['_wp_update_plugins_effect']          = function () {
			self::wporg_answers( 200 );
			$GLOBALS['_site_transients']['update_plugins'] = self::list_checked(
				self::NOW,
				array( 'a/a.php' => (object) array(), 'b/b.php' => (object) array() )
			);
		};
		$r = Aura_Tool_Site_Context::plugin_updates( self::NOW );
		$this->assertTrue( $r['refreshed'] );
		$this->assertSame( self::NOW, $r['checked_at'] );
		$this->assertCount( 2, $r['transient']->response );
	}

	public function test_a_missing_list_is_refreshed(): void {
		$GLOBALS['_wp_update_plugins_effect'] = function () {
			$GLOBALS['_site_transients']['update_plugins'] = self::list_checked( self::NOW );
		};
		$r = Aura_Tool_Site_Context::plugin_updates( self::NOW );
		$this->assertTrue( $r['refreshed'] );
		$this->assertSame( self::NOW, $r['checked_at'] );
	}

	public function test_a_refresh_that_changes_nothing_reports_the_old_age_honestly(): void {
		// WordPress.org unreachable: the list stays old, and checked_at says so.
		$GLOBALS['_site_transients']['update_plugins'] = self::list_checked( self::NOW - 5 * 86400 );
		$r = Aura_Tool_Site_Context::plugin_updates( self::NOW );
		$this->assertTrue( $r['refreshed'] );
		$this->assertSame( self::NOW - 5 * 86400, $r['checked_at'] );
	}

	/** Codex r1 on #154: core stamps last_checked BEFORE asking; a failed ask is not a check. */
	public function test_a_check_that_failed_reports_the_age_from_before_it_asked(): void {
		foreach ( array( 0, 500, 429 ) as $code ) {
			$GLOBALS['_site_transients']['update_plugins'] = self::list_checked( self::NOW - 5 * 86400 );
			$GLOBALS['_wp_update_plugins_effect']          = function () use ( $code ) {
				self::wporg_answers( $code );
			};
			$r = Aura_Tool_Site_Context::plugin_updates( self::NOW );
			$this->assertFalse( $r['refreshed'], "code $code" );
			$this->assertTrue( $r['refresh_failed'], "code $code" );
			$this->assertSame( self::NOW - 5 * 86400, $r['checked_at'], "code $code" );
		}
	}

	public function test_the_observer_is_removed_after_the_check(): void {
		$GLOBALS['_site_transients']['update_plugins'] = self::list_checked( self::NOW - 5 * 86400 );
		Aura_Tool_Site_Context::plugin_updates( self::NOW );
		$this->assertEmpty( $GLOBALS['_filters']['http_api_debug'] ?? array() );
	}

	public function test_a_refresh_that_throws_keeps_the_old_list(): void {
		$GLOBALS['_site_transients']['update_plugins'] = self::list_checked( self::NOW - 5 * 86400 );
		$GLOBALS['_wp_update_plugins_effect']          = function () {
			throw new RuntimeException( 'http' );
		};
		$r = Aura_Tool_Site_Context::plugin_updates( self::NOW );
		$this->assertFalse( $r['refreshed'] );
		$this->assertTrue( $r['refresh_failed'] );
		$this->assertSame( self::NOW - 5 * 86400, $r['checked_at'] );
	}
}
