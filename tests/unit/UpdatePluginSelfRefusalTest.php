<?php
/**
 * The generic update paths refuse SiteAgent's own file (2.23.1).
 *
 * `Plugin_Upgrader::upgrade()` deactivates its target at pre_install; for
 * SiteAgent that removes every `aura/*` route, and Aura can no longer reach
 * the site to repair it. SiteAgent updates itself only through
 * `POST /aura/v1/self-update` (install(), re-activation, health check,
 * rollback). The generic single update, the batch and `update_plugin_safely`
 * answer `aura_use_self_update` and never reach the upgrader; the multisite
 * and busy-claim refusals keep their precedence.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class UpdatePluginSelfRefusalTest extends TestCase {

	private const SELF = Aura_Worker_Updater::SELF_PLUGIN_FILE;

	protected function setUp(): void {
		sa_reset_state();
		$GLOBALS['_fs_method'] = 'ftpext'; // no host probe
		delete_option( Aura_Worker_Updater::SELF_UPDATE_LOCK );
		$GLOBALS['_notoptions'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_method'], $GLOBALS['_installed_plugins'] );
		delete_option( Aura_Worker_Updater::SELF_UPDATE_LOCK );
	}

	private function upgraded( string $plugin ): bool {
		return in_array( 'Plugin_Upgrader::upgrade:' . $plugin, $GLOBALS['_updater_calls'], true );
	}

	public function test_the_single_update_refuses_siteagent_and_never_reaches_the_upgrader(): void {
		$GLOBALS['_active_plugins'][ self::SELF ] = true;

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::SELF );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_use_self_update', $res['code'] );
		$this->assertStringContainsString( '/aura/v1/self-update', $res['error'] );
		$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'] );
		$this->assertSame( array(), $GLOBALS['_updater_calls'], 'no refresh and no upgrade' );
		$this->assertSame( array(), $GLOBALS['_activate_plugin_calls'] );
		$this->assertTrue( is_plugin_active( self::SELF ) );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'nothing left held' );
	}

	public function test_the_batch_refuses_siteagent_and_runs_the_other_entries(): void {
		$out = ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::SELF, 'akismet/akismet.php' ), 5, false );

		$by = array();
		foreach ( $out['results'] as $r ) {
			$by[ $r['plugin'] ] = $r;
		}
		$this->assertSame( 'failed', $by[ self::SELF ]['status'] );
		$this->assertSame( 'aura_use_self_update', $by[ self::SELF ]['code'] );
		$this->assertStringContainsString( '/aura/v1/self-update', $by[ self::SELF ]['detail'] );
		$this->assertFalse( $this->upgraded( self::SELF ), 'SiteAgent never reached the upgrader' );
		$this->assertTrue( $this->upgraded( 'akismet/akismet.php' ), 'the other entry ran' );
	}

	public function test_update_plugin_safely_refuses_siteagent_with_the_same_code(): void {
		$GLOBALS['_installed_plugins'] = array( self::SELF => array( 'Name' => 'SiteAgent', 'Version' => '2.23.0' ) );

		$res = ( new Aura_Tool_Update_Plugin_Safely() )->execute( array( 'plugin_slug' => 'digitizer-site-worker', 'create_backup' => false ) );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_use_self_update', $res['code'] );
		$this->assertFalse( $this->upgraded( self::SELF ) );
	}

	public function test_the_multisite_refusal_keeps_its_precedence(): void {
		$GLOBALS['_is_multisite'] = true;

		$single = ( new Aura_Worker_Updater() )->update_plugin( self::SELF );
		$batch  = ( new Aura_Worker_Updater() )->batch_update_plugins( array( self::SELF ), 5, false );

		$this->assertSame( 'aura_self_update_multisite_unsupported', $single['code'] );
		$this->assertSame( 'aura_self_update_multisite_unsupported', $batch['results'][0]['code'] );
		$this->assertFalse( $this->upgraded( self::SELF ) );
	}

	public function test_a_held_claim_still_answers_busy(): void {
		$holder = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		$this->assertNotSame( '', $holder );

		$res = ( new Aura_Worker_Updater() )->update_plugin( self::SELF );

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['in_progress'] );
		$this->assertFalse( $this->upgraded( self::SELF ) );
		$this->assertStringStartsWith( $holder . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ) );
	}
}
