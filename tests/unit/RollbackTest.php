<?php
/**
 * Tests for Aura_Worker_Rollback — plugin zip backup + restore roundtrip.
 *
 * Runs against a real filesystem sandbox under the system temp dir
 * (WP_CONTENT_DIR / WP_PLUGIN_DIR from bootstrap) using a real ZipArchive.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RollbackTest extends TestCase {

	private string $plugin_slug = 'testplugin';

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			self::markTestSkipped( 'ext-zip not available.' );
		}
	}

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_PLUGIN_DIR . '/' . $this->plugin_slug, 0777, true );
		file_put_contents( WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/main.php', "<?php // v1\n" );
	}

	protected function tearDown(): void {
		$this->rrmdir( WP_CONTENT_DIR );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	public function test_constructor_protects_backup_dir(): void {
		$rollback = new Aura_Worker_Rollback();
		$dir      = $rollback->get_backup_dir();

		// SA#158: nginx never reads .htaccess, so the archives live in a
		// directory whose name a visitor cannot guess.
		$this->assertMatchesRegularExpression( '#/aura-backups/rollback-[0-9a-f]{32}/$#', $dir );
		$this->assertDirectoryExists( $dir );
		foreach ( array( WP_CONTENT_DIR . '/aura-backups/', $dir ) as $d ) {
			$this->assertFileExists( $d . '.htaccess' );
			$this->assertStringContainsString( 'Deny from all', file_get_contents( $d . '.htaccess' ) );
			$this->assertFileExists( $d . 'index.php' );
		}
	}

	public function test_every_instance_uses_the_same_private_dir(): void {
		$a = ( new Aura_Worker_Rollback() )->get_backup_dir();
		$b = ( new Aura_Worker_Rollback() )->get_backup_dir();
		$this->assertSame( $a, $b );
		$this->assertCount( 1, glob( WP_CONTENT_DIR . '/aura-backups/rollback-*', GLOB_ONLYDIR ) );
	}

	public function test_a_raced_second_private_dir_converges_on_the_smallest_name(): void {
		mkdir( WP_CONTENT_DIR . '/aura-backups/rollback-' . str_repeat( 'f', 32 ), 0777, true );
		mkdir( WP_CONTENT_DIR . '/aura-backups/rollback-' . str_repeat( '0', 32 ), 0777, true );
		$dir = ( new Aura_Worker_Rollback() )->get_backup_dir();
		$this->assertSame( WP_CONTENT_DIR . '/aura-backups/rollback-' . str_repeat( '0', 32 ) . '/', $dir );
	}

	public function test_legacy_archives_move_out_of_the_guessable_dir(): void {
		$legacy = WP_CONTENT_DIR . '/aura-backups/';
		mkdir( $legacy, 0777, true );
		$name = $this->plugin_slug . '_2026-11-12_23-43-31-0a1b2c3d.zip';
		file_put_contents( $legacy . $name, 'PK' );
		touch( $legacy . $name, time() - HOUR_IN_SECONDS );
		file_put_contents( $legacy . 'unrelated.txt', 'x' );

		$rollback = new Aura_Worker_Rollback();

		$this->assertFileDoesNotExist( $legacy . $name );
		$this->assertFileExists( $rollback->get_backup_dir() . $name );
		$this->assertFileExists( $legacy . 'unrelated.txt', 'only SiteAgent archives are moved' );
		$this->assertCount( 1, $rollback->list_backups( $this->plugin_slug ) );
	}

	public function test_backup_keeps_only_the_newest_three_per_plugin(): void {
		$rollback = new Aura_Worker_Rollback();
		$dir      = $rollback->get_backup_dir();
		foreach ( array( '01', '02', '03', '04' ) as $d ) {
			file_put_contents( $dir . $this->plugin_slug . '_2026-11-' . $d . '_00-00-00-0a1b2c3d.zip', 'PK' );
			touch( $dir . $this->plugin_slug . '_2026-11-' . $d . '_00-00-00-0a1b2c3d.zip', time() - HOUR_IN_SECONDS );
		}
		file_put_contents( $dir . 'other_2026-11-01_00-00-00-0a1b2c3d.zip', 'PK' );

		$result = $rollback->backup_plugin( $this->plugin_slug );

		$this->assertTrue( $result['success'] );
		$this->assertFileExists( $result['backup_path'], 'the archive just taken survives the prune' );
		$this->assertCount( 3, $rollback->list_backups( $this->plugin_slug ) );
		$this->assertCount( 1, $rollback->list_backups( 'other' ), 'other plugins are pruned by their own count' );
	}

	public function test_the_prune_never_removes_the_archive_it_just_wrote(): void {
		$rollback = new Aura_Worker_Rollback();
		$dir      = $rollback->get_backup_dir();
		// Archives that sort NEWER than the one about to be written: the order
		// alone would prune the new one (a same-second tie behaves the same).
		foreach ( array( '01', '02', '03' ) as $d ) {
			file_put_contents( $dir . $this->plugin_slug . '_2099-01-' . $d . '_00-00-00-0a1b2c3d.zip', 'PK' );
			touch( $dir . $this->plugin_slug . '_2099-01-' . $d . '_00-00-00-0a1b2c3d.zip', time() - HOUR_IN_SECONDS );
		}

		$result = $rollback->backup_plugin( $this->plugin_slug );

		$this->assertFileExists( $result['backup_path'] );
		$this->assertCount( 3, $rollback->list_backups( $this->plugin_slug ) );
	}

	/** Codex P2 on #159: a raced first request may have written into a larger private dir. */
	public function test_archives_in_every_private_dir_are_listed(): void {
		$small = WP_CONTENT_DIR . '/aura-backups/rollback-' . str_repeat( '0', 32 ) . '/';
		$large = WP_CONTENT_DIR . '/aura-backups/rollback-' . str_repeat( 'f', 32 ) . '/';
		mkdir( $small, 0777, true );
		mkdir( $large, 0777, true );
		file_put_contents( $large . $this->plugin_slug . '_2026-11-12_00-00-00-0a1b2c3d.zip', 'PK' );

		$rollback = new Aura_Worker_Rollback();

		$this->assertSame( $small, $rollback->get_backup_dir() );
		$this->assertCount( 1, $rollback->list_backups( $this->plugin_slug ) );
	}

	/** Codex P1 on #159: the version upgrade moves the archives, not the next backup. */
	public function test_the_upgrade_migration_moves_legacy_archives(): void {
		$legacy = WP_CONTENT_DIR . '/aura-backups/';
		mkdir( $legacy, 0777, true );
		$name = $this->plugin_slug . '_2026-11-12_23-43-31-0a1b2c3d.zip';
		file_put_contents( $legacy . $name, 'PK' );
		touch( $legacy . $name, time() - HOUR_IN_SECONDS );

		Aura_Worker_Rollback::migrate_legacy_archives();

		$this->assertFileDoesNotExist( $legacy . $name );
		$this->assertCount( 1, glob( $legacy . 'rollback-*/' . $name ) );
	}

	public function test_the_upgrade_migration_creates_nothing_on_a_site_without_backups(): void {
		Aura_Worker_Rollback::migrate_legacy_archives();
		$this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/aura-backups' );
	}

	public function test_the_upgrade_routine_runs_the_migration(): void {
		$main = file_get_contents( __DIR__ . '/../../digitizer-site-worker/digitizer-site-worker.php' );
		$fn   = substr( $main, strpos( $main, 'function aura_worker_maybe_upgrade()' ) );
		$fn   = substr( $fn, 0, strpos( $fn, "\n}\n" ) );
		$this->assertStringContainsString( 'Aura_Worker_Rollback::migrate_legacy_archives()', $fn );
	}

	/** Codex P2 on #159: a restore naming an archive by its pre-2.24.1 path still finds it. */
	public function test_a_restore_by_legacy_path_finds_the_moved_archive(): void {
		$file  = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/main.php';
		$first = new Aura_Worker_Rollback();
		$taken = $first->backup_plugin( $this->plugin_slug );
		$this->assertTrue( $taken['success'] );
		// Put it where 2.24.0 wrote it, as an upgrade would find it.
		$legacy = WP_CONTENT_DIR . '/aura-backups/' . basename( $taken['backup_path'] );
		rename( $taken['backup_path'], $legacy );
		touch( $legacy, time() - HOUR_IN_SECONDS );
		file_put_contents( $file, "<?php // v2\n" );

		$rollback = new Aura_Worker_Rollback(); // moves it
		$this->assertFileDoesNotExist( $legacy );
		$restore = $rollback->restore_plugin( $this->plugin_slug, $legacy );

		$this->assertTrue( $restore['success'], (string) ( $restore['error'] ?? '' ) );
		$this->assertSame( "<?php // v1\n", file_get_contents( $file ) );
	}

	/**
	 * Codex P1 round 2 on #159: an in-flight self-update from 2.24.0 still
	 * runs the old rollback class, which holds the archive's legacy path. A
	 * fresh archive is left in place until that window has passed, and the
	 * migration schedules itself to come back for it.
	 */
	public function test_a_fresh_legacy_archive_is_left_for_an_in_flight_update(): void {
		$legacy = WP_CONTENT_DIR . '/aura-backups/';
		mkdir( $legacy, 0777, true );
		$name = 'digitizer-site-worker_2026-11-13_13-00-00-0a1b2c3d.zip';
		file_put_contents( $legacy . $name, 'PK' );
		$GLOBALS['_scheduled'] = array();

		new Aura_Worker_Rollback();
		Aura_Worker_Rollback::migrate_legacy_archives();

		$this->assertFileExists( $legacy . $name, 'the old updater can still find its recovery archive' );
		$hooks = array_column( (array) $GLOBALS['_scheduled'], 'hook' );
		$this->assertContains( Aura_Worker_Rollback::MIGRATE_HOOK, $hooks, 'the move is retried once the window has passed' );
		$ts = max( array_column( (array) $GLOBALS['_scheduled'], 'ts' ) );
		$this->assertGreaterThan( time() + Aura_Worker_Rollback::LEGACY_GRACE_SECONDS - 5, $ts );
	}

	/** Codex P2 round 3 on #159: the deferred archive stays discoverable and is never pruned. */
	public function test_a_deferred_legacy_archive_is_listed_and_never_pruned(): void {
		$legacy = WP_CONTENT_DIR . '/aura-backups/';
		mkdir( $legacy, 0777, true );
		$fresh = $this->plugin_slug . '_2020-01-01_00-00-00-0a1b2c3d.zip'; // oldest by name, newest on disk
		file_put_contents( $legacy . $fresh, 'PK' );

		$rollback = new Aura_Worker_Rollback();
		$this->assertCount( 1, $rollback->list_backups( $this->plugin_slug ), 'listed while it waits' );

		$dir = $rollback->get_backup_dir();
		foreach ( array( '01', '02', '03' ) as $d ) {
			file_put_contents( $dir . $this->plugin_slug . '_2026-11-' . $d . '_00-00-00-0a1b2c3d.zip', 'PK' );
		}
		$rollback->backup_plugin( $this->plugin_slug );

		$this->assertFileExists( $legacy . $fresh, 'an in-flight updater may still need it' );
	}

	/** Codex P2 round 4 on #159: retention never deletes an archive an in-flight update may hold. */
	public function test_retention_never_deletes_a_recent_archive(): void {
		$rollback = new Aura_Worker_Rollback();
		$taken    = $rollback->backup_plugin( $this->plugin_slug ); // e.g. a self-update's recovery archive
		// Sorts oldest by name (taken a while ago in the same update), still fresh on disk.
		$held = $rollback->get_backup_dir() . $this->plugin_slug . '_2026-01-01_00-00-00-0a1b2c3d.zip';
		rename( $taken['backup_path'], $held );
		for ( $i = 0; $i < 3; $i++ ) {
			$rollback->backup_plugin( $this->plugin_slug ); // backup-only calls meanwhile
		}
		$this->assertFileExists( $held );
	}

	public function test_the_retry_hook_is_wired(): void {
		$src = file_get_contents( __DIR__ . '/../../digitizer-site-worker/includes/class-aura-worker.php' );
		$this->assertStringContainsString( "add_action( Aura_Worker_Rollback::MIGRATE_HOOK, array( 'Aura_Worker_Rollback', 'migrate_legacy_archives' ) )", $src );
	}

	/**
	 * SA#160: make aura-backups/ unwritable to this process (left owned by
	 * another user), or skip where permissions are not enforced (root).
	 */
	private function lockBase(): string {
		$base = WP_CONTENT_DIR . '/aura-backups/';
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0777, true );
		}
		file_put_contents( $base . '.htaccess', 'Deny from all' );
		file_put_contents( $base . 'index.php', '<?php' );
		chmod( $base, 0555 );
		clearstatcache();
		if ( is_writable( $base ) ) {
			chmod( $base, 0777 );
			$this->markTestSkipped( 'permissions are not enforced for this user' );
		}
		return $base;
	}

	private function unlockBase(): void {
		@chmod( WP_CONTENT_DIR . '/aura-backups/', 0777 );
	}

	/** SA#160: no private dir means no backup, never one in the guessable dir. */
	public function test_an_unwritable_backup_dir_refuses_the_backup_instead_of_exposing_it(): void {
		$base = $this->lockBase();
		try {
			$rollback = new Aura_Worker_Rollback();
			$result   = $rollback->backup_plugin( $this->plugin_slug );

			$this->assertFalse( $result['success'] );
			$this->assertSame( 'aura_rollback_dir_unwritable', $result['code'] ?? null );
			$this->assertSame( array(), glob( $base . '*.zip' ) ?: array() );
		} finally {
			$this->unlockBase();
		}
	}

	/** SA#160: a legacy archive that could not be moved keeps a retry queued. */
	public function test_an_unmoved_legacy_archive_keeps_a_retry_queued(): void {
		$base = WP_CONTENT_DIR . '/aura-backups/';
		mkdir( $base, 0777, true );
		$name = $this->plugin_slug . '_2026-09-27_00-00-00-0a1b2c3d.zip';
		file_put_contents( $base . $name, 'PK' );
		touch( $base . $name, time() - DAY_IN_SECONDS );
		$this->lockBase();
		$GLOBALS['_scheduled'] = array();
		try {
			Aura_Worker_Rollback::migrate_legacy_archives();

			$this->assertFileExists( $base . $name );
			$this->assertContains( Aura_Worker_Rollback::MIGRATE_HOOK, array_column( (array) $GLOBALS['_scheduled'], 'hook' ) );
		} finally {
			$this->unlockBase();
		}
	}

	/** SA#160: the exposure is reported, read-only. */
	public function test_exposed_legacy_archives_are_reported_without_side_effects(): void {
		$this->assertNull( Aura_Worker_Rollback::exposure_issue() );
		$this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/aura-backups', 'reading creates nothing' );

		$base = WP_CONTENT_DIR . '/aura-backups/';
		mkdir( $base, 0777, true );
		file_put_contents( $base . $this->plugin_slug . '_2026-09-27_00-00-00-0a1b2c3d.zip', 'PK' );
		file_put_contents( $base . 'unrelated.zip', 'PK' );

		$issue = Aura_Worker_Rollback::exposure_issue();

		$this->assertSame( 'rollback_backups_exposed', $issue['type'] ?? null );
		$this->assertSame( 1, $issue['count'] );
		$this->assertArrayHasKey( 'writable', $issue );
		$this->assertSame( array(), glob( $base . 'rollback-*', GLOB_ONLYDIR ) ?: array(), 'reading creates nothing' );
	}

	public function test_site_context_reports_the_exposure(): void {
		$src = file_get_contents( __DIR__ . '/../../digitizer-site-worker/includes/tools/class-tool-site-context.php' );
		$this->assertStringContainsString( 'Aura_Worker_Rollback::exposure_issue()', $src );
	}

	public function test_backup_creates_zip_and_lists_it(): void {
		$rollback = new Aura_Worker_Rollback();
		$result   = $rollback->backup_plugin( $this->plugin_slug );

		$this->assertTrue( $result['success'] );
		$this->assertFileExists( $result['backup_path'] );

		$backups = $rollback->list_backups( $this->plugin_slug );
		$this->assertCount( 1, $backups );
		$this->assertSame( $this->plugin_slug, $backups[0]['plugin_slug'] );
	}

	public function test_backup_of_missing_plugin_fails(): void {
		$rollback = new Aura_Worker_Rollback();
		$result   = $rollback->backup_plugin( 'does-not-exist' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_restore_roundtrip_recovers_original_content(): void {
		$rollback = new Aura_Worker_Rollback();
		$file     = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/main.php';

		// Back up v1, then mutate the plugin to v2.
		$backup = $rollback->backup_plugin( $this->plugin_slug );
		$this->assertTrue( $backup['success'] );
		file_put_contents( $file, "<?php // v2 broken\n" );
		$this->assertStringContainsString( 'v2', file_get_contents( $file ) );

		// Restore returns the directory to v1.
		$restore = $rollback->restore_plugin( $this->plugin_slug, $backup['backup_path'] );
		$this->assertTrue( $restore['success'] );
		$this->assertStringContainsString( 'v1', file_get_contents( $file ) );
		$this->assertStringNotContainsString( 'v2', file_get_contents( $file ) );
	}

	public function test_restore_from_missing_backup_fails(): void {
		$rollback = new Aura_Worker_Rollback();
		$result   = $rollback->restore_plugin( $this->plugin_slug, WP_CONTENT_DIR . '/aura-backups/nope.zip' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}
}
