<?php
/**
 * Self-update failure recovery (SiteAgent #77 / PR #78).
 *
 * `self_update()` used to download, verify, overwrite and reactivate — with no
 * backup, no verification that the site still booted, and nothing to restore.
 *
 * The verdict is a FACT the new build writes, not an inference. Four review
 * rounds found a case where every inferential signal lies (a cached home page,
 * a 401 that means two different things, a 500 vs a 404 control, a log tail
 * that is too short or the wrong file). So the updater leaves a nonce, makes
 * one uncacheable request, and reads `aura_worker_boot`, which the new build
 * writes as the last line of its own init. These tests simulate the new build
 * by writing that beacon (or not) from the install effect.
 *
 * Breakage is a fact too: a shutdown handler in the dying process records a
 * fatal in one of this plugin's files against the same nonce (the "fatal
 * beacon"). The error-log scanner that preceded it produced review findings in
 * eight of ten rounds and is gone. These tests simulate the fatal beacon the
 * way the probe request would produce it: from `_http_effect`, via
 * `aura_worker_record_fatal_beacon()`, with a file path under the plugin dir.
 *
 * Rollback is held to a post-condition: the plugin header on disk must read
 * the version we came from.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SelfUpdateRecoveryTest extends TestCase {

	private string $slug = 'digitizer-site-worker';
	private string $dir;

	protected function setUp(): void {
		$this->dir = WP_PLUGIN_DIR . '/' . $this->slug;
		$this->rmdir( $this->dir );
		mkdir( $this->dir, 0777, true );
		file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'OLD BUILD', AURA_WORKER_VERSION ) );

		$GLOBALS['_mutations']      = array();
		// Remove any beacon or nonce a previous test left, through the stub's OWN
		// delete path: it keeps a row store beside `_options`, and clearing only
		// `_options` let a stale beacon leak into the next test — which then
		// read "stale beacon" for a build that had written nothing.
		delete_option( 'aura_worker_boot' );
		foreach ( array_keys( $GLOBALS['_options'] ?? array() ) as $k ) {
			if ( 0 === strpos( (string) $k, 'aura_worker_boot_fatal' ) ) {
				delete_option( $k );
			}
		}
		delete_option( 'aura_worker_boot_nonce' );
		delete_option( Aura_Worker_Updater::SELF_UPDATE_LOCK );
		// A deleted option is listed in `notoptions` and short-circuits get_option
		// until something writes it again; clear that too so absence is absence.
		$GLOBALS['_notoptions']     = array();
		$GLOBALS['_wp_http_calls']  = array();
		$GLOBALS['_http_error']     = false;
		$GLOBALS['_http_response']         = array( 'response' => array( 'code' => 401 ), 'body' => '{}' );
		$GLOBALS['_http_responses_by_url'] = array();
		$GLOBALS['_install_result'] = true;
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( true );
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_install_effect'], $GLOBALS['_install_result'], $GLOBALS['_http_error'], $GLOBALS['_http_effect'] );
		$GLOBALS['_is_multisite'] = false; // the two multisite refusal tests set it; nothing else in this class does
		delete_option( 'aura_worker_boot' );
		foreach ( array_keys( $GLOBALS['_options'] ?? array() ) as $k ) {
			if ( 0 === strpos( (string) $k, 'aura_worker_boot_fatal' ) ) {
				delete_option( $k );
			}
		}
		delete_option( 'aura_worker_boot_nonce' );
		delete_option( Aura_Worker_Updater::SELF_UPDATE_LOCK );
		$this->rmdir( $this->dir );
		foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
			unlink( $f );
		}
	}

	private function rmdir( string $d ): void {
		if ( ! is_dir( $d ) ) {
			return;
		}
		foreach ( scandir( $d ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $d . '/' . $f;
			is_dir( $p ) ? $this->rmdir( $p ) : unlink( $p );
		}
		rmdir( $d );
	}

	/** A plugin file with a real Version header, the way WordPress reads it. */
	private function build( string $marker, string $version, ?string $constant = null ): string {
		$constant = $constant ?? $version;
		return "<?php\n/**\n * Plugin Name: SiteAgent\n * Version: {$version}\n */\ndefine( 'AURA_WORKER_VERSION', '{$constant}' );\n// {$marker}\n";
	}

	/** The marker inside the plugin file on disk — OLD BUILD or NEW BUILD. */
	private function onDisk(): string {
		$f = $this->dir . '/digitizer-site-worker.php';
		if ( ! is_file( $f ) ) {
			return '';
		}
		return preg_match( '/(OLD BUILD|NEW BUILD)/', (string) file_get_contents( $f ), $m ) ? $m[1] : '';
	}

	/**
	 * What a real install does, then what the NEW build's own init would do on
	 * the loopback request: write the beacon echoing the nonce the updater left.
	 * `$boots = false` models a build that installs cleanly and fatals on load —
	 * files on disk, no beacon.
	 */
	private function installNewBuild( bool $boots, string $version = '9.9.9' ): void {
		file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', $version ) );
		// The beacon is written by the fresh process the LOOPBACK starts, not by
		// the install. Modelling it at install time was the round-8 finding: at
		// that moment the OLD build is what any request runs.
		$GLOBALS['_http_effect'] = $boots
			? function () use ( $version ) { Aura_Worker_Updater::write_boot_beacon( $version ); }
			: null;
	}

	/**
	 * What the dying process records when a fatal in OUR code ends the probe
	 * request: the fatal beacon. Runs from `_http_effect`, i.e. at request time
	 * with the nonce armed — the only moment it can be written.
	 */
	private function diedInOurCode(): void {
		$dir = $this->dir;
		$GLOBALS['_http_effect'] = function () use ( $dir ) {
			aura_worker_record_fatal_beacon(
				array( 'type' => E_ERROR, 'file' => $dir . '/includes/x.php', 'line' => 1, 'message' => 'Uncaught Error: boom' ),
				'9.9.9',
				$dir . '/'
			);
		};
	}

	/** An install whose build does not come up: no boot beacon, and a fatal beacon. */
	private function brokenBuild(): void {
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
			$this->diedInOurCode();
		};
	}

	private function selfUpdate(): array {
		$updater = new Aura_Worker_Updater();
		return $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );
	}

	public function test_a_healthy_update_keeps_the_new_build_and_reports_its_backup(): void {
		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['backed_up'] );
		$this->assertTrue( $res['health_checked'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk() );
	}

	public function test_a_build_that_breaks_the_site_is_rolled_back(): void {
		$this->brokenBuild();

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertFalse( $res['healthy'] );
		// The only claim that matters: the previous build is back on disk.
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_build_whose_header_and_constant_disagree_is_a_failed_install(): void {
		// Codex round-13: the beacon writers name the build by AURA_WORKER_VERSION;
		// the verdict looks records up by the header. If they differ, the records
		// are written under one name and read under another — nothing found,
		// inconclusive, broken build left standing. Malformed build: restored.
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9', '9.9.8' ) );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
		$this->assertStringContainsString( 'disagree', $res['error'] );
	}

	public function test_a_build_with_no_constant_at_all_is_a_failed_install(): void {
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', "<?php\\n/**\\n * Plugin Name: SiteAgent\\n * Version: 9.9.9\\n */\\n// NEW BUILD\\n" );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
	}

	public function test_a_fatal_recorded_BY_this_update_rolls_it_back(): void {
		// The build installs, the probe request dies in our code, the dying
		// process records it. Positive evidence of breakage; restored.
		$this->brokenBuild();

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
		$this->assertSame( 'fail', $res['health']['checks']['fatal_beacon']['status'] );
	}

	public function test_an_install_that_fails_partway_is_rolled_back(): void {
		// `install()` with overwrite_package deletes before it writes, so a
		// failure can leave the directory incomplete. This is the case the
		// backup most exists for.
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_wp_error_install_is_rolled_back_too(): void {
		$GLOBALS['_install_result'] = new WP_Error( 'fs', 'filesystem exploded' );
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_an_unbackable_site_still_updates_and_says_it_had_no_way_back(): void {
		// The #472 lesson, applied here: refusing would be safer for this one
		// site and would make every site without a usable backup directory
		// permanently un-updatable — a gate that can never pass. So the update
		// proceeds and the result records that it was unrecoverable.
		$backups = WP_CONTENT_DIR . '/aura-backups';
		foreach ( glob( $backups . '/*.zip' ) ?: array() as $f ) {
			unlink( $f );
		}
		// Make the backup impossible: no source directory to archive. The default
		// install effect writes INTO that directory, so it has to go too —
		// otherwise the test fails on its own fixture rather than on the
		// behaviour it is describing.
		$this->rmdir( $this->dir );
		$GLOBALS['_install_effect'] = function () {
			mkdir( $this->dir, 0777, true );
			$this->installNewBuild( true );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['backed_up'] );
		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
	}

	public function test_a_restore_that_cannot_write_is_not_reported_as_a_rollback(): void {
		// `extractTo()` returns false when the PHP process cannot write to
		// WP_PLUGIN_DIR — the ordinary case being a site that updates over
		// FTP/SSH. Reporting `rolled_back: true` there tells an operator the
		// site was recovered when the plugin may be missing (Codex round-1 P1).
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
			$this->diedInOurCode();
			// Corrupt the only backup so extraction cannot succeed.
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				file_put_contents( $f, 'not a zip' );
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertNotNull( $res['restore_error'] );
	}

	public function test_a_failed_restore_does_not_claim_in_its_MESSAGE_that_it_rolled_back(): void {
		// The fields said `rolled_back: false` while the human-facing `error`
		// still said the site "was rolled back" (Codex round-2 P2). A dashboard
		// showing only the message told an operator the site had recovered while
		// it was still carrying the broken build.
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
			$this->diedInOurCode();
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				file_put_contents( $f, 'not a zip' );
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['rolled_back'] );
		$this->assertStringNotContainsString( 'was rolled back', $res['error'] );
		$this->assertStringContainsString( 'could not be rolled back', $res['error'] );
	}

	public function test_a_restore_that_left_the_wrong_build_on_disk_is_not_a_rollback(): void {
		// The post-condition, and the reason it exists: every step can report
		// success and the site still be running the broken build. Here the
		// archive restores but the plugin file it puts back is not the version
		// we came from, so `rolled_back` must be false however cleanly the
		// extraction went.
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
			$this->diedInOurCode();
			// Rewrite the backup so it restores a DIFFERENT version.
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				$zip = new ZipArchive();
				$zip->open( $f, ZipArchive::OVERWRITE );
				$zip->addFromString(
					'digitizer-site-worker/digitizer-site-worker.php',
					$this->build( 'NEW BUILD', '7.7.7' )
				);
				$zip->close();
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['rolled_back'], 'a restore that did not bring back the old version is not a rollback' );
	}

	public function test_the_verdict_is_the_beacon_the_new_build_wrote(): void {
		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['verified'] );
		$this->assertSame( 'pass', $res['health']['checks']['boot_beacon']['status'] );
		// The request for a beacon is spent either way.
		$this->assertFalse( isset( $GLOBALS['_options']['aura_worker_boot_nonce'] ) );
	}

	public function test_a_build_that_installs_but_never_boots_is_rolled_back(): void {
		// Files on disk, loopback answered (so the request reached the site),
		// no beacon: the build did not come up. This is the case the whole
		// feature exists for, and the one every inferential probe got wrong
		// somewhere.
		$this->brokenBuild();

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_beacon_left_by_an_EARLIER_boot_does_not_count(): void {
		// A stale beacon with some other nonce is exactly what a "did it boot"
		// check must not be fooled by — the previous build booted; this one
		// did not.
		$GLOBALS['_options']['aura_worker_boot'] = array( 'version' => '9.9.9', 'nonce' => 'from-last-time' );
		// Neither boots nor dies: nothing of ours is written for THIS nonce.
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		// Not verified, and — with no fatal recorded either — inconclusive
		// rather than rolled back: a stale beacon is not evidence of anything.
		$this->assertFalse( $res['verified'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'stale beacon', $res['health']['checks']['boot_beacon']['detail'] );
	}

	public function test_a_beacon_from_the_WRONG_version_does_not_count(): void {
		// The nonce matches but the build that answered is not the one we
		// installed — an OPcache still serving the old files would look like this.
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9' ) );
			// The loopback is answered by the OLD version (OPcache still serving it).
			$GLOBALS['_http_effect'] = function () { Aura_Worker_Updater::write_boot_beacon( AURA_WORKER_VERSION ); };
		};

		$res = $this->selfUpdate();

		// Not verified — the wrong build answered. And with no attributed fatal
		// it is inconclusive rather than rolled back: absence of the right
		// beacon is not positive evidence of breakage.
		$this->assertFalse( $res['verified'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'a different build answered', $res['health']['checks']['boot_beacon']['detail'] );
	}

	public function test_no_beacon_and_no_attributed_fatal_is_inconclusive_and_the_update_stands(): void {
		// Files installed, loopback answered 200, no beacon, nothing in the log
		// naming this plugin. Two very different worlds look exactly like this
		// from inside the site — a CDN/WAF/proxy that answered before WordPress
		// ran, or a build that fatals on load with error logging off — and no
		// external signal tells them apart (five review rounds tried). Rolling
		// back here would make every edge-fronted site permanently
		// un-updatable, so the update stands and is reported UNVERIFIED. The
		// second world is the stated residual: the pre-#78 exposure, now
		// visible in the update log rather than invisible.
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['verified'] );
		$this->assertTrue( $res['health']['inconclusive'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk() );
	}

	public function test_an_edge_answering_before_WordPress_does_not_cause_a_rollback(): void {
		// Codex round-5 P1: a 301 canonical redirect or a 403 challenge from a
		// CDN/WAF is an HTTP response with no PHP behind it. "We got a response"
		// must not be read as "PHP ran".
		$GLOBALS['_http_response'] = array( 'response' => array( 'code' => 301 ), 'body' => '' );
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertTrue( $res['health']['inconclusive'] );
	}

	public function test_a_site_that_cannot_reach_itself_is_inconclusive_not_rolled_back(): void {
		$GLOBALS['_http_error'] = true;
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['verified'] );
		$this->assertFalse( $res['rolled_back'] );
	}

	public function test_ANOTHER_plugins_fatal_is_not_recorded_against_this_verdict(): void {
		// The probe request boots our build, then some other plugin dies. The
		// shutdown handler sees a fatal whose file is not under our directory
		// and records nothing; the boot beacon stands.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' );
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => WP_PLUGIN_DIR . '/some-other-plugin/x.php', 'line' => 1, 'message' => 'theirs' ),
					'9.9.9',
					$dir . '/'
				);
			};
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['verified'] );
	}

	public function test_an_archive_that_lost_its_main_file_is_a_failed_install_and_is_restored(): void {
		// Codex round-7 P1: Plugin_Upgrader accepts an archive whose main file
		// was renamed — some other PHP file has a valid header — while the
		// active-plugin entry still names the missing one. Nothing of ours loads
		// on the loopback, so there is no beacon and no attributed fatal; the
		// verdict would call that inconclusive and let a headless install stand.
		// The main file's header is an on-disk FACT, so it is checked before the
		// verdict is even asked.
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
			file_put_contents( $this->dir . '/renamed-main.php', $this->build( 'NEW BUILD', '9.9.9' ) );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
		// A clean rollback leaves nothing the broken release added: the restore
		// removes the directory and re-extracts the backup, so the stray file
		// must be gone. (The first version of this test tried to unlink it as
		// cleanup and failed on PHP 7.4 — because the rollback had already done
		// the job the test was about.)
		$this->assertFileDoesNotExist( $this->dir . '/renamed-main.php' );
	}

	public function test_the_OLD_build_cannot_consume_the_nonce_during_the_install(): void {
		// Codex round-8 P1: while install() runs, other requests are still
		// served by the old build. If the nonce were armed before the install,
		// one of them would write a beacon with the OLD version and delete the
		// nonce — and a broken new build would read as "stale beacon" rather
		// than "no beacon". The nonce must not exist until the install is done.
		$GLOBALS['_install_effect'] = function () {
			// A concurrent request on the old build, mid-install.
			Aura_Worker_Updater::write_boot_beacon( AURA_WORKER_VERSION );
			$this->installNewBuild( false );
		};

		$res = $this->selfUpdate();

		$this->assertSame( 'no beacon written', $res['health']['checks']['boot_beacon']['detail'] );
		$this->assertArrayNotHasKey( 'aura_worker_boot', $GLOBALS['_options'] );
	}

	public function test_an_OLD_build_dying_after_the_nonce_was_armed_does_not_roll_back_a_healthy_new_build(): void {
		// Codex round-11: a request that loaded the old build before the install
		// can still be running when the nonce is armed, and die in old code. Its
		// fatal record names the OLD version, so it is not about the build under
		// verdict, and the new build's clean boot stands.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' );
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => $dir . '/includes/old.php', 'line' => 1, 'message' => 'straggler' ),
					AURA_WORKER_VERSION, // the OLD build died
					$dir . '/'
				);
			};
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['verified'] );
	}

	public function test_a_fatal_recorded_BEFORE_a_clean_boot_on_another_request_still_rolls_back(): void {
		// Codex round-11: two records with two owners, so the boot write cannot
		// replace the fatal however the two requests interleave. Precedence is
		// decided when the verdict reads, not when either writes.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => $dir . '/includes/x.php', 'line' => 1, 'message' => 'died first' ),
					'9.9.9',
					$dir . '/'
				);
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' ); // a second, clean request, later
			};
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
	}

	public function test_an_OLD_build_dying_AFTER_the_new_build_died_does_not_erase_the_evidence(): void {
		// Codex round-12: with one shared fatal record, the straggler's later
		// death (old version) overwrote the new build's, the verdict ignored the
		// old-version record, and a build that had died after boot was reported
		// healthy. One record per version: both deaths are kept, and the verdict
		// reads the new build's.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' );
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => $dir . '/includes/x.php', 'line' => 1, 'message' => 'new build died' ),
					'9.9.9',
					$dir . '/'
				);
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => $dir . '/includes/old.php', 'line' => 1, 'message' => 'straggler died later' ),
					AURA_WORKER_VERSION,
					$dir . '/'
				);
			};
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
	}

	public function test_a_fatal_AFTER_the_boot_beacon_on_the_same_request_still_rolls_back(): void {
		// Init completed and the boot beacon was written; then our code died
		// later in the same request (dispatching the probe). The nonce is still
		// armed — the updater spends it, not the boot write — so the dying
		// process upgrades the beacon to fatal. Breakage wins.
		$dir = $this->dir;
		$GLOBALS['_install_effect'] = function () use ( $dir ) {
			$this->installNewBuild( true );
			$GLOBALS['_http_effect'] = function () use ( $dir ) {
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' );
				aura_worker_record_fatal_beacon(
					array( 'type' => E_ERROR, 'file' => $dir . '/includes/class-aura-worker-api.php', 'line' => 1, 'message' => 'died dispatching' ),
					'9.9.9',
					$dir . '/'
				);
			};
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_failed_install_is_held_to_the_same_post_condition_as_a_failed_boot(): void {
		// Codex round-4 P1: the install-failure exit trusted restore_plugin()'s
		// step result while the health-check exit checked the header. Here the
		// restore "succeeds" but puts back a different version.
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
			foreach ( glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array() as $f ) {
				$zip = new ZipArchive();
				$zip->open( $f, ZipArchive::OVERWRITE );
				$zip->addFromString( 'digitizer-site-worker/digitizer-site-worker.php', $this->build( 'NEW BUILD', '7.7.7' ) );
				$zip->close();
			}
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['rolled_back'] );
		$this->assertStringContainsString( 'could NOT be restored', $res['error'] );
	}

	public function test_an_unhealthy_update_with_no_backup_reports_failure_rather_than_success(): void {
		// No directory to back up, so no backup. The install itself lands (a
		// fresh directory, real main file), the build does not boot, and it dies
		// in our code — so the VERDICT is what fails, with nothing to restore.
		$this->rmdir( $this->dir );
		$GLOBALS['_install_effect'] = function () {
			mkdir( $this->dir, 0777, true );
			$this->installNewBuild( false );
			$this->diedInOurCode();
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['backed_up'] );
		$this->assertFalse( $res['rolled_back'] );
		// Nothing to restore is not the same as nothing wrong — an operator has
		// to know this one needs hands.
		$this->assertStringContainsString( 'no backup', strtolower( $res['error'] ) );
	}

	public function test_a_failed_install_is_rolled_back_even_without_a_filesystem_transport(): void {
		// ZipArchive writes the backup without a WP_Filesystem transport, so on
		// an FTP/SSH site with no stored credentials the backup exists and the
		// restore is reached — where the directory-replace step dereferenced
		// the null $wp_filesystem and fatalled instead of returning the
		// documented failed-restore result (Codex round-14 P1). The restore has
		// to complete, not merely fail politely: nothing here needs a transport.
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
		};
		$GLOBALS['_wp_filesystem_unavailable'] = true;
		$GLOBALS['wp_filesystem'] = null;

		try {
			$res = $this->selfUpdate();
		} finally {
			unset( $GLOBALS['_wp_filesystem_unavailable'] );
			$GLOBALS['wp_filesystem'] = null;
		}

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['rolled_back'], (string) ( $res['restore_error'] ?? '' ) );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_recovery_setup_that_cannot_be_built_does_not_fatal_a_successful_update(): void {
		// Round 13 wrapped `new Aura_Worker_Rollback()` in try/catch and continued
		// with null, so a site whose recovery setup ends the request can still
		// update with `backed_up: false`. The success path then called
		// `$rollback->cleanup_old_backups()` unconditionally — a fatal AFTER the
		// plugin was replaced, in place of the result (Codex round-14 P1).
		// The constructor only creates the directory when it is missing, so it
		// has to be GONE — recursively: the snapshot tests leave a subdirectory
		// in it, and a flat rmdir that fails quietly leaves this test asserting
		// on a fixture that never took the path it describes.
		$this->rmdir( WP_CONTENT_DIR . '/aura-backups' );
		$this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/aura-backups' );
		$GLOBALS['_wp_mkdir_p_throws'] = true;

		try {
			$res = $this->selfUpdate();
		} finally {
			unset( $GLOBALS['_wp_mkdir_p_throws'] );
		}

		$this->assertTrue( $res['success'] );
		$this->assertFalse( $res['backed_up'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk() );
	}

	public function test_a_second_self_update_while_one_is_running_is_refused_without_touching_the_site(): void {
		// Two overlapping requests shared one nonce option: the second overwrote
		// it before the first loopback wrote its beacon, so the first verifier
		// saw a record carrying a nonce it never armed — unrelated, inconclusive,
		// healthy — and a broken build stood with no rollback (Codex round-20 P1).
		// One update per site at a time; the loser is told, and does nothing.
		$holder = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		$this->assertNotSame( '', $holder );

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'already in progress', $res['error'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk(), 'the refused request must not install anything' );
		$this->assertStringStartsWith( $holder . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the refused request must not release a claim it does not hold' );
	}

	public function test_the_claim_is_released_after_a_successful_update_and_the_next_one_runs(): void {
		$first = $this->selfUpdate();
		$this->assertTrue( $first['success'] );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'a finished update must let go of the claim' );

		$second = $this->selfUpdate();
		$this->assertTrue( $second['success'], $second['error'] ?? '' );
	}

	public function test_the_claim_is_released_after_a_failed_install_too(): void {
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/digitizer-site-worker.php' );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'every exit releases the claim, failure included' );
	}

	public function test_a_claim_left_by_a_request_that_died_does_not_block_updates_for_ever(): void {
		// A fatal mid-update never reaches `finally`. A holder older than the
		// takeover window is presumed dead and seized, so a site is never wedged
		// past that window.
		update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, 'deadfence|' . ( time() - 11 * MINUTE_IN_SECONDS ) );

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'], $res['error'] ?? '' );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the seizing request releases what it seized' );
	}

	public function test_a_request_that_outlived_the_takeover_does_not_release_its_successors_claim(): void {
		// The round-20 lock released unconditionally. A self-update that ran past
		// the takeover window was seized by a second request; when the first
		// reached `finally` it deleted the SECOND's lock, and a third could then
		// start beside the second — the race the lock exists to prevent (Codex
		// round-21 P1). Release is fenced on the holder's own value.
		$successor = '';
		$GLOBALS['_install_effect'] = function () use ( &$successor ) {
			$this->installNewBuild( true );
			// Time passes past the window while this request is still working…
			$held = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
			$this->assertNotSame( '', $held );
			$fence = substr( $held, 0, strpos( $held, '|' ) );
			update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
			// …and another request seizes the stale claim.
			$successor = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
			$this->assertNotSame( '', $successor, 'the aged claim must be seizable' );
			$this->assertNotSame( $fence, $successor );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['in_progress'], 'the outlived request stops; the successor owns the files' );
		$this->assertStringStartsWith( $successor . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the outlived request removed its successor\'s claim' );
	}

	public function test_the_claim_is_renewed_between_phases_so_a_live_update_is_never_seizable(): void {
		// A fixed ten-minute age let a still-live update be seized as stale, and
		// the fenced release (round 21) only stopped the loser from deleting the
		// winner's claim — not the two from running side by side (Codex round-22
		// P1). The lease is renewed before backup, before install and after it;
		// here the row is aged during the install and must read fresh again by
		// the time the loopback runs.
		$seen_at_loopback = null;
		$GLOBALS['_install_effect'] = function () use ( &$seen_at_loopback ) {
			$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
			$fence = substr( $held, 0, (int) strpos( $held, '|' ) );
			update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
			$GLOBALS['_http_effect'] = function () use ( &$seen_at_loopback ) {
				$seen_at_loopback = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				Aura_Worker_Updater::write_boot_beacon( '9.9.9' );
			};
			file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9' ) );
		};

		$res = $this->selfUpdate();

		$this->assertTrue( $res['success'], $res['error'] ?? '' );
		$this->assertNotNull( $seen_at_loopback );
		$stamp = (int) substr( $seen_at_loopback, (int) strpos( $seen_at_loopback, '|' ) + 1 );
		$this->assertGreaterThan( time() - 60, $stamp, 'the lease was not renewed after the install: ' . $seen_at_loopback );
	}

	public function test_a_package_carrying_the_running_version_is_refused_and_the_old_files_restored(): void {
		// Records are matched by VERSION. A same-version package makes the new
		// build and the old one indistinguishable: a request that loaded the
		// pre-update files and fatals after the nonce is armed writes a record the
		// verdict would own, and a healthy replacement is rolled back on it (Codex
		// round-23 P1). Aura never sends one; the plugin refuses it before probing.
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( true, AURA_WORKER_VERSION );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'same-version', $res['error'] );
		$this->assertTrue( $res['rolled_back'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
		$this->assertSame( array(), $GLOBALS['_wp_http_calls'], 'no probe: there is nothing a probe could tell apart' );
	}

	// -----------------------------------------------------------------
	// SA#104 — the same-version refusal is decided from the VERIFIED
	// PACKAGE, before the backup and the install; SA#95 ask 2 — once the
	// package is known to carry another version, an unchanged header after
	// install() means the upgrader did not replace the files.
	// -----------------------------------------------------------------

	/** Temp files a test handed to download_url(); removed in cleanup. */
	private array $packages = array();

	/**
	 * A real zip the stubbed download_url() hands back, and its sha256 — the
	 * verified-download path. `$entries` maps archive path => contents.
	 */
	private function verifiedPackage( array $entries ): string {
		$tmp = tempnam( sys_get_temp_dir(), 'sa_pkg_' );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $tmp, ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $body ) {
			$zip->addFromString( $name, $body );
		}
		$zip->close();
		$this->packages[]                  = $tmp;
		$GLOBALS['_download_url_result'] = $tmp;
		return hash_file( 'sha256', $tmp );
	}

	/** A package whose main file carries `$version` (and `$constant`, default the same). */
	private function packageCarrying( string $version, ?string $constant = null ): string {
		return $this->verifiedPackage(
			array(
				'digitizer-site-worker/digitizer-site-worker.php' => $this->build( 'NEW BUILD', $version, $constant ),
				'digitizer-site-worker/readme.txt'                => "=== SiteAgent ===\n",
			)
		);
	}

	private function verifiedSelfUpdate( string $sha, ?Aura_Worker_Updater $updater = null ): array {
		$updater = $updater ?? new Aura_Worker_Updater();
		try {
			return $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip', $sha );
		} finally {
			unset( $GLOBALS['_download_url_result'] );
		}
	}

	/** Every file under the plugin directory, with its bytes' hash. */
	private function snapshotDir(): array {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$out[ substr( $f->getPathname(), strlen( $this->dir ) ) ] = hash_file( 'sha256', $f->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	private function backupsTaken(): array {
		return glob( WP_CONTENT_DIR . '/aura-backups/*.zip' ) ?: array();
	}

	private function cleanupPackages(): void {
		foreach ( $this->packages as $p ) {
			if ( file_exists( $p ) ) {
				unlink( $p );
			}
		}
		$this->packages = array();
	}

	public function test_a_verified_package_carrying_the_running_version_is_refused_before_anything_is_touched(): void {
		file_put_contents( $this->dir . '/readme.txt', "=== SiteAgent ===\n" );
		$sha    = $this->packageCarrying( AURA_WORKER_VERSION );
		$pkg    = $GLOBALS['_download_url_result'];
		$before = $this->snapshotDir();

		try {
			$res = $this->verifiedSelfUpdate( $sha );

			$this->assertFalse( $res['success'] );
			$this->assertSame( 'aura_self_update_same_version', $res['code'] ?? null );
			$this->assertStringContainsString( 'nothing', strtolower( $res['error'] ) );
			$this->assertStringContainsString( AURA_WORKER_VERSION, $res['error'] );
			$this->assertStringNotContainsString( 'could NOT be restored', $res['error'] );
			$this->assertFalse( $res['backed_up'] );
			$this->assertFalse( $res['rolled_back'] );
			$this->assertFalse( $res['installed'] );
			$this->assertSame( AURA_WORKER_VERSION, $res['old_version'] );
			$this->assertSame( AURA_WORKER_VERSION, $res['new_version'] );
			$this->assertNotContains( 'Plugin_Upgrader::install', $GLOBALS['_mutations'], 'no install for a request that is refused' );
			$this->assertSame( array(), $this->backupsTaken(), 'no backup for a request that is refused' );
			$this->assertSame( $before, $this->snapshotDir(), 'the live plugin directory must be untouched' );
			$this->assertSame( array(), $GLOBALS['_wp_http_calls'] );
			$this->assertFileDoesNotExist( $pkg, 'the downloaded package is cleaned up' );
			$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the refusal releases the claim' );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_a_first_ever_self_update_refused_early_does_not_create_the_backup_directory(): void {
		// Codex #105 round-3 P2: the rollback helper's constructor creates
		// wp-content/aura-backups/ with an .htaccess and index.php. Built before
		// the download, it made "Nothing on the site was changed" false on a
		// site's first self-update.
		$backups = WP_CONTENT_DIR . '/aura-backups';
		$this->rmdir( $backups );
		$this->assertDirectoryDoesNotExist( $backups );
		$sha = $this->packageCarrying( AURA_WORKER_VERSION );

		try {
			$res = $this->verifiedSelfUpdate( $sha );

			$this->assertSame( 'aura_self_update_same_version', $res['code'] ?? null );
			$this->assertFileDoesNotExist( $backups . '/.htaccess' );
			$this->assertFileDoesNotExist( $backups . '/index.php' );
			$this->assertDirectoryDoesNotExist( $backups, 'a refusal that changed nothing must not create the backup directory' );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_a_refused_download_or_digest_does_not_create_the_backup_directory_either(): void {
		$backups = WP_CONTENT_DIR . '/aura-backups';
		$this->rmdir( $backups );

		// A failed download.
		$GLOBALS['_download_url_result'] = new WP_Error( 'http', 'download failed' );
		$res                             = $this->verifiedSelfUpdate( str_repeat( 'a', 64 ) );
		$this->assertFalse( $res['success'] );
		$this->assertDirectoryDoesNotExist( $backups );

		// A digest that does not match.
		$this->packageCarrying( '9.9.9' );
		try {
			$res = $this->verifiedSelfUpdate( str_repeat( 'a', 64 ) );
			$this->assertFalse( $res['success'] );
			$this->assertStringContainsString( 'integrity', $res['error'] );
			$this->assertDirectoryDoesNotExist( $backups );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_a_verified_package_whose_CONSTANT_names_the_running_version_is_refused_early_too(): void {
		$sha    = $this->packageCarrying( '9.9.9', AURA_WORKER_VERSION );
		$before = $this->snapshotDir();

		try {
			$res = $this->verifiedSelfUpdate( $sha );

			$this->assertFalse( $res['success'] );
			$this->assertSame( 'aura_self_update_same_version', $res['code'] ?? null );
			$this->assertNotContains( 'Plugin_Upgrader::install', $GLOBALS['_mutations'] );
			$this->assertSame( array(), $this->backupsTaken() );
			$this->assertSame( $before, $this->snapshotDir() );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_a_verified_package_carrying_another_version_proceeds_as_before(): void {
		$sha = $this->packageCarrying( '9.9.9' );
		$pkg = $GLOBALS['_download_url_result'];

		try {
			$res = $this->verifiedSelfUpdate( $sha );

			$this->assertTrue( $res['success'], $res['error'] ?? '' );
			$this->assertArrayNotHasKey( 'code', $res );
			$this->assertTrue( $res['backed_up'] );
			$this->assertTrue( $res['verified'] );
			$this->assertContains( 'Plugin_Upgrader::install', $GLOBALS['_mutations'] );
			$this->assertSame( 'NEW BUILD', $this->onDisk() );
			$this->assertFileDoesNotExist( $pkg );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_without_ZipArchive_the_early_check_falls_through_to_the_post_install_backstop(): void {
		$sha     = $this->packageCarrying( AURA_WORKER_VERSION );
		$updater = new class() extends Aura_Worker_Updater {
			protected function package_inspection_available() {
				return false;
			}
		};
		$GLOBALS['_install_effect'] = function () {
			$this->installNewBuild( true, AURA_WORKER_VERSION );
		};

		try {
			$res = $this->verifiedSelfUpdate( $sha, $updater );

			$this->assertFalse( $res['success'] );
			$this->assertContains( 'Plugin_Upgrader::install', $GLOBALS['_mutations'], 'today\'s path: the install runs' );
			$this->assertTrue( $res['backed_up'] );
			$this->assertTrue( $res['rolled_back'] );
			$this->assertStringContainsString( 'same-version', $res['error'] );
			$this->assertSame( 'aura_self_update_version_unchanged', $res['code'] ?? null );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_an_unreadable_archive_falls_through_to_the_post_install_backstop(): void {
		// The bytes match the digest but are not a zip this process can open.
		$tmp = tempnam( sys_get_temp_dir(), 'sa_pkg_' );
		file_put_contents( $tmp, 'not a zip at all' );
		$this->packages[]                  = $tmp;
		$GLOBALS['_download_url_result'] = $tmp;
		$sha                             = hash_file( 'sha256', $tmp );

		try {
			$res = $this->verifiedSelfUpdate( $sha );

			$this->assertTrue( $res['success'], $res['error'] ?? '' );
			$this->assertContains( 'Plugin_Upgrader::install', $GLOBALS['_mutations'] );
			$this->assertTrue( $res['backed_up'] );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_an_archive_without_the_main_file_where_WordPress_loads_it_falls_through(): void {
		// A header in some OTHER php file, or the right file under a different
		// top-level directory, is not what the upgrader will put at
		// SELF_PLUGIN_FILE, so it decides nothing.
		$sha = $this->verifiedPackage(
			array(
				'digitizer-site-worker-main/digitizer-site-worker.php' => $this->build( 'NEW BUILD', AURA_WORKER_VERSION ),
				'digitizer-site-worker/other.php'                      => $this->build( 'NEW BUILD', AURA_WORKER_VERSION ),
			)
		);

		try {
			$res = $this->verifiedSelfUpdate( $sha );

			$this->assertArrayNotHasKey( 'code', $res );
			$this->assertContains( 'Plugin_Upgrader::install', $GLOBALS['_mutations'] );
			$this->assertTrue( $res['success'], $res['error'] ?? '' );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_a_verified_other_version_that_leaves_the_old_header_on_disk_says_the_files_were_not_replaced(): void {
		// SA#95 ask 2: install() reports success but the upgrader never replaced
		// the directory. The sha-bound package carries 9.9.9, so "a package
		// carrying the version already running" would be false.
		$sha = $this->packageCarrying( '9.9.9' );
		$GLOBALS['_install_effect'] = function () {
			// the upgrader "succeeds" and leaves the old files in place
		};

		try {
			$res = $this->verifiedSelfUpdate( $sha );

			$this->assertFalse( $res['success'] );
			$this->assertSame( 'aura_self_update_not_replaced', $res['code'] ?? null );
			$this->assertStringContainsString( 'did not replace', $res['error'] );
			$this->assertStringContainsString( '9.9.9', $res['error'] );
			$this->assertStringNotContainsString( 'same-version', $res['error'] );
			$this->assertStringNotContainsString( 'carrying the version already running', $res['error'] );
			// SA#95 ask 3: the directory is exactly what it was before install(),
			// so there is nothing to restore — and restoring would start a delete.
			$this->assertFalse( $res['rolled_back'] );
			$this->assertSame( 'unchanged', $res['restore_skipped'] ?? null );
			$this->assertSame( 'OLD BUILD', $this->onDisk() );
		} finally {
			$this->cleanupPackages();
		}
	}

	public function test_an_unverified_update_that_leaves_the_old_header_names_both_possible_causes(): void {
		// No digest: no local archive, so nothing can say which of the two
		// happened. The message must not claim either one.
		$GLOBALS['_install_effect'] = function () {
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_self_update_version_unchanged', $res['code'] ?? null );
		$this->assertStringContainsString( 'same-version', $res['error'] );
		$this->assertStringContainsString( 'did not replace', $res['error'] );
	}

	/** Make the plugin directory read-only to PHP; false when the mode is not enforced. */
	private function lockPluginDir(): bool {
		chmod( $this->dir, 0555 );
		if ( @file_put_contents( $this->dir . '/probe', 'x' ) !== false ) {
			chmod( $this->dir, 0777 );
			unlink( $this->dir . '/probe' );
			return false;
		}
		return true;
	}

	public function test_a_restore_that_could_not_clear_the_directory_does_not_report_a_missing_plugin_whose_main_file_is_there(): void {
		// SA#104 part 2: the rollback refuses to extract over a directory it
		// could not remove. That leaves what the install left — here the main
		// file, readable — not a missing plugin.
		$locked = false;
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () use ( &$locked ) {
			$locked = $this->lockPluginDir();
		};

		try {
			$res = $this->selfUpdate();
			if ( ! $locked ) {
				$this->markTestSkipped( 'filesystem does not enforce the mode (running as root?)' );
			}

			$this->assertFalse( $res['rolled_back'] );
			$this->assertStringContainsString( 'Could not remove', (string) $res['restore_error'] );
			$this->assertStringNotContainsString( 'may be missing or incomplete', $res['error'] );
			$this->assertStringContainsString( 'not extracted', $res['error'] );
			$this->assertStringContainsString( 'reads version ' . AURA_WORKER_VERSION, $res['error'] );
		} finally {
			chmod( $this->dir, 0777 );
		}
	}

	public function test_a_restore_that_could_not_clear_the_directory_and_left_no_main_file_still_warns(): void {
		$locked = false;
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () use ( &$locked ) {
			unlink( $this->dir . '/digitizer-site-worker.php' );
			// Not empty, or removing it needs no write access to it at all.
			file_put_contents( $this->dir . '/readme.txt', 'left behind' );
			$locked = $this->lockPluginDir();
		};

		try {
			$res = $this->selfUpdate();
			if ( ! $locked ) {
				$this->markTestSkipped( 'filesystem does not enforce the mode (running as root?)' );
			}

			$this->assertFalse( $res['rolled_back'] );
			$this->assertStringContainsString( 'could NOT be restored', $res['error'] );
			$this->assertStringContainsString( 'may be missing or incomplete', $res['error'] );
		} finally {
			chmod( $this->dir, 0777 );
		}
	}

	/**
	 * A warning-to-exception handler of the kind some hosts install. It throws
	 * on E_WARNING whatever the error_reporting mask says: PHPUnit runs the
	 * test body with a narrowed mask, so a handler that honoured it would
	 * never see the warning this guards against.
	 */
	public function warningsThrow( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
		if ( E_WARNING !== $errno ) {
			return false;
		}
		throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
	}

	public function test_a_restore_that_could_not_clear_the_directory_and_left_an_UNREADABLE_main_file_still_warns_without_throwing(): void {
		// Codex #105 round-1 P1: the post-failure version read reached
		// get_plugin_data() behind only file_exists(). An unreadable main file
		// raises a warning there, and under a warning-to-exception handler that
		// escaped and killed the request during recovery.
		$main   = $this->dir . '/digitizer-site-worker.php';
		$locked = false;
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () use ( &$locked, &$handler, $main ) {
			chmod( $main, 0000 );
			$locked = $this->lockPluginDir() && ! is_readable( $main );
			// From here on — the recovery — a warning is an exception.
			set_error_handler( array( $this, 'warningsThrow' ) );
			$handler = true;
		};
		$handler = false;

		try {
			$res = $this->selfUpdate();
		} finally {
			if ( $handler ) {
				restore_error_handler();
			}
			chmod( $this->dir, 0777 );
			chmod( $main, 0644 );
		}
		if ( ! $locked ) {
			$this->markTestSkipped( 'filesystem does not enforce the mode (running as root?)' );
		}

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertStringContainsString( 'Could not remove', (string) $res['restore_error'] );
		$this->assertStringContainsString( 'could NOT be restored', $res['error'] );
		$this->assertStringContainsString( 'may be missing or incomplete', $res['error'] );
	}

	public function test_an_unreadable_installed_main_file_reads_as_no_version_rather_than_throwing(): void {
		// The same guard, on the two helpers every caller shares.
		$main = $this->dir . '/digitizer-site-worker.php';
		chmod( $main, 0000 );
		if ( is_readable( $main ) ) {
			chmod( $main, 0644 );
			$this->markTestSkipped( 'filesystem does not enforce the mode (running as root?)' );
		}
		$updater = new Aura_Worker_Updater();
		$out     = array();
		set_error_handler(
			array( $this, 'warningsThrow' )
		);
		try {
			foreach ( array( 'installed_version', 'installed_constant_version' ) as $name ) {
				$m = new ReflectionMethod( Aura_Worker_Updater::class, $name );
				$m->setAccessible( true );
				$out[ $name ] = $m->invoke( $updater, Aura_Worker_Updater::SELF_PLUGIN_FILE );
			}
		} finally {
			restore_error_handler();
			chmod( $main, 0644 );
		}

		$this->assertNull( $out['installed_version'] );
		$this->assertNull( $out['installed_constant_version'] );
	}

	public function test_the_generic_single_update_of_siteagent_waits_on_the_same_claim(): void {
		// `/aura/v1/update/plugin` accepts SiteAgent's own file and replaced it
		// with no claim taken, so it could land between a self-update's backup,
		// install and probe (Codex round-23 P1). Another plugin is unaffected.
		$holder = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		$this->assertNotSame( '', $holder );
		$updater = new Aura_Worker_Updater();

		$self = $updater->update_plugin( Aura_Worker_Updater::SELF_PLUGIN_FILE );

		$this->assertFalse( $self['success'] );
		$this->assertTrue( $self['in_progress'] );
		$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'], 'SiteAgent must not have been upgraded while the claim is held' );

		$other = $updater->update_plugin( 'akismet/akismet.php' );
		$this->assertTrue( $other['success'], 'another plugin is not held up by the self-update claim' );
		$this->assertStringStartsWith( $holder . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the refused path must not release the claim' );
	}

	public function test_the_generic_single_update_of_siteagent_takes_and_releases_the_claim(): void {
		$res = ( new Aura_Worker_Updater() )->update_plugin( Aura_Worker_Updater::SELF_PLUGIN_FILE );
		$this->assertTrue( $res['success'] );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the generic path releases what it took' );
	}

	public function test_the_batch_skips_siteagent_while_a_self_update_holds_the_claim_and_does_the_rest(): void {
		$holder = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		$this->assertNotSame( '', $holder );

		$out = ( new Aura_Worker_Updater() )->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE, 'akismet/akismet.php' ), 5, false );

		$by = array();
		foreach ( $out['results'] as $r ) {
			$by[ $r['plugin'] ] = $r;
		}
		$this->assertSame( 'skipped', $by[ Aura_Worker_Updater::SELF_PLUGIN_FILE ]['status'] );
		$this->assertStringContainsString( 'self-update is in progress', $by[ Aura_Worker_Updater::SELF_PLUGIN_FILE ]['detail'] );
		$this->assertNotSame( 'skipped', $by['akismet/akismet.php']['status'], 'the rest of the batch runs' );
		$this->assertStringStartsWith( $holder . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ) );
	}

	public function test_a_generic_rollback_of_siteagent_waits_on_the_claim_and_releases_it_after(): void {
		// `/aura/v2/rollback/digitizer-site-worker` restored these files with no
		// claim taken (Codex round-24 P1), so it could delete and re-extract the
		// directory while a locked self-update was backing up, installing or
		// probing it. Same claim, same busy answer, and the guarded path lets go
		// of what it took.
		if ( ! class_exists( 'Aura_Worker_Rollback' ) ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-rollback.php';
		}
		$rollback = new Aura_Worker_Rollback();
		$backup   = $rollback->backup_plugin( $this->slug );
		$this->assertTrue( $backup['success'], $backup['error'] ?? '' );
		file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9' ) );
		$updater = new Aura_Worker_Updater();

		$holder = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		$this->assertNotSame( '', $holder );
		$busy = $updater->restore_plugin_guarded( $rollback, $this->slug, $backup['backup_path'] );
		$this->assertFalse( $busy['success'] );
		$this->assertTrue( $busy['in_progress'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk(), 'a refused rollback must not have touched the files' );
		$this->assertStringStartsWith( $holder . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the refused path must not release a claim it does not hold' );

		Aura_Worker_Magic_Link::release_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, $holder );
		$res = $updater->restore_plugin_guarded( $rollback, $this->slug, $backup['backup_path'] );
		$this->assertTrue( $res['success'], $res['error'] ?? '' );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the guarded restore releases what it took' );
	}

	public function test_a_batch_entry_for_siteagent_that_outlives_its_lease_stops_before_the_health_check(): void {
		// SA#80: the generic batch entry ran backup → update → health → rollback
		// under a claim it never renewed. Model the update phase running past the
		// takeover window and a successor seizing the claim: the entry must stop
		// there — no health check, no rollback over the successor's files — and
		// must not remove the successor's claim on its way out.
		$successor = '';
		$updater   = new class( $successor ) extends Aura_Worker_Updater {
			private $successor;
			public function __construct( &$successor ) { $this->successor = &$successor; }
			protected function update_single_plugin( $plugin_file ) {
				$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				$fence = substr( $held, 0, strpos( $held, '|' ) );
				update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
				$this->successor = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
				return array( 'success' => true );
			}
		};

		$out = $updater->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE ), 5, false );

		$entry = $out['results'][0];
		$this->assertSame( 'failed', $entry['status'] );
		$this->assertStringContainsString( 'Lost the self-update claim', $entry['detail'] );
		$this->assertNotSame( '', $successor, 'the aged claim must be seizable' );
		$this->assertStringStartsWith( $successor . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the outlived entry must not remove its successor\'s claim' );
		$this->assertSame( array(), array_filter( $GLOBALS['_wp_http_calls'] ), 'no health probe after the claim was lost' );
	}

	public function test_a_batch_entry_for_siteagent_renews_its_lease_between_phases(): void {
		// The lease is renewed after the backup and after the update, so a slow
		// phase is never mistaken for a dead holder. Observable as the claim's
		// timestamp moving forward across the entry.
		$stamps  = array();
		$updater = new class( $stamps ) extends Aura_Worker_Updater {
			private $stamps;
			public function __construct( &$stamps ) { $this->stamps = &$stamps; }
			protected function update_single_plugin( $plugin_file ) {
				// Age the lease by a minute inside the phase; the renewal after
				// this phase must bring it back to "now".
				$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				$fence = substr( $held, 0, strpos( $held, '|' ) );
				update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - MINUTE_IN_SECONDS ) );
				$this->stamps['aged'] = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				return array( 'success' => true );
			}
			protected function batch_update_one( $plugin_file, $rollback, $health, $create_backup, $fence = '' ) {
				$entry                   = parent::batch_update_one( $plugin_file, $rollback, $health, $create_backup, $fence );
				$this->stamps['renewed'] = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				return $entry;
			}
		};

		$out = $updater->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE ), 5, false );

		$this->assertSame( 'updated', $out['results'][0]['status'], $out['results'][0]['detail'] );
		$aged    = (int) substr( $stamps['aged'], strpos( $stamps['aged'], '|' ) + 1 );
		$renewed = (int) substr( $stamps['renewed'], strpos( $stamps['renewed'], '|' ) + 1 );
		$this->assertGreaterThan( $aged, $renewed, 'the lease was renewed after the update phase' );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'released on exit' );
	}

	public function test_the_lease_is_heartbeaten_inside_the_update_phase_through_the_upgraders_filters(): void {
		// Codex #91 round-2 P1: renewing only BETWEEN phases leaves a phase
		// that runs past the window seizable. The upgrader fires its own
		// sub-phase filters (download → source selection → pre-install →
		// post-install); a throttled heartbeat hooked on them keeps the lease
		// alive while the phase runs. Modelled: the phase ages the lease, then
		// fires a sub-phase filter; the lease must be fresh again after it.
		$stamps  = array();
		$updater = new class( $stamps ) extends Aura_Worker_Updater {
			const LEASE_HEARTBEAT_SECONDS = 0; // no throttle in the model
			private $stamps;
			public function __construct( &$stamps ) { $this->stamps = &$stamps; }
			protected function update_single_plugin( $plugin_file ) {
				$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				$fence = substr( $held, 0, strpos( $held, '|' ) );
				update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 5 * MINUTE_IN_SECONDS ) );
				$this->stamps['aged'] = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				apply_filters( 'upgrader_pre_install', true, array() ); // what Plugin_Upgrader fires mid-phase
				$this->stamps['beat'] = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				return array( 'success' => true );
			}
		};

		$out = $updater->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE ), 5, false );

		$this->assertSame( 'updated', $out['results'][0]['status'], $out['results'][0]['detail'] );
		$aged = (int) substr( $stamps['aged'], strpos( $stamps['aged'], '|' ) + 1 );
		$beat = (int) substr( $stamps['beat'], strpos( $stamps['beat'], '|' ) + 1 );
		$this->assertGreaterThan( $aged, $beat, 'the sub-phase filter renewed the lease inside the phase' );
		$this->assertSame( array(), array_filter( $GLOBALS['_filters']['upgrader_pre_install'] ?? array() ), 'the heartbeat filter is removed after the phase' );
	}

	public function test_a_seized_claim_after_a_null_install_result_reports_installed_false(): void {
		// Codex #94 round-1 P2: `installed` is a claim, and an upgrader that
		// answered null never proved it installed anything.
		$GLOBALS['_install_result'] = null;
		$GLOBALS['_install_effect'] = function () {
			$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
			$fence = substr( $held, 0, strpos( $held, '|' ) );
			update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
			$this->assertNotSame( '', Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS ) );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['in_progress'] );
		$this->assertFalse( $res['installed'], 'null is not proof of an install' );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertFalse( $res['health_checked'] );
	}

	public function test_a_heartbeat_that_loses_the_claim_aborts_the_upgraders_pre_stages_and_passes_post_install_through(): void {
		// Codex #94 round-3 P1: a claim seized between two sub-phases used to
		// be noticed only after the whole phase. The three pre-stage filters
		// are WordPress's own abort points (a WP_Error there runs nothing), so
		// the heartbeat answers one from the first failed check on; the
		// post-install filter, which fires after the files are replaced,
		// passes through and the boundary check stops the entry.
		$stamps  = array();
		$updater = new class( $stamps ) extends Aura_Worker_Updater {
			const LEASE_HEARTBEAT_SECONDS = 0;
			private $stamps;
			public function __construct( &$stamps ) { $this->stamps = &$stamps; }
			protected function update_single_plugin( $plugin_file ) {
				$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				$fence = substr( $held, 0, strpos( $held, '|' ) );
				update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
				$this->stamps['successor'] = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
				$this->stamps['pre']       = apply_filters( 'upgrader_pre_install', true, array() );
				$this->stamps['post']      = apply_filters( 'upgrader_post_install', true, array(), array() );
				$this->stamps['pre_again'] = apply_filters( 'upgrader_pre_download', false, 'pkg', null, array() );
				return array( 'success' => true );
			}
		};

		$out = $updater->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE ), 5, false );

		$this->assertNotSame( '', $stamps['successor'], 'the aged claim must be seizable' );
		$this->assertInstanceOf( WP_Error::class, $stamps['pre'], 'a lost claim aborts the pre-install stage' );
		$this->assertSame( 'aura_self_update_claim_lost', $stamps['pre']->get_error_code() );
		$this->assertTrue( $stamps['post'], 'post-install passes its value through — the files are already replaced' );
		$this->assertInstanceOf( WP_Error::class, $stamps['pre_again'], 'once lost, every later pre-stage aborts without re-checking' );
		$this->assertSame( 'failed', $out['results'][0]['status'] );
		$this->assertStringContainsString( 'Lost the self-update claim', $out['results'][0]['detail'] );
		$this->assertStringStartsWith( $stamps['successor'] . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the successor\'s claim is untouched' );
	}

	public function test_a_claim_seized_between_pre_install_and_the_clear_aborts_before_the_old_directory_is_deleted(): void {
		// Codex #94 round-8 P1: the beats sat only on the pre-stages and
		// post-install, so a claim seized after upgrader_pre_install was
		// noticed only after the install had already cleared and rewritten
		// the directory beside its successor. upgrader_clear_destination is
		// WordPress's own filter immediately before the delete (the delete
		// itself runs inside it, at priority 10); the beat at priority 1 renews
		// there and a lost claim answers a WP_Error that stops the phase with
		// the old files untouched.
		$stamps  = array();
		$updater = new class( $stamps ) extends Aura_Worker_Updater {
			const LEASE_HEARTBEAT_SECONDS = 0;
			private $stamps;
			public function __construct( &$stamps ) { $this->stamps = &$stamps; }
			protected function update_single_plugin( $plugin_file ) {
				$this->stamps['pre'] = apply_filters( 'upgrader_pre_install', true, array() ); // still ours
				$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				$fence = substr( $held, 0, strpos( $held, '|' ) );
				update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) ); // the clear runs long
				$this->stamps['successor'] = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
				$this->stamps['clear']     = apply_filters( 'upgrader_clear_destination', true, '/local', '/remote', array() );
				return array( 'success' => true );
			}
		};

		$out = $updater->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE ), 5, false );

		$this->assertTrue( $stamps['pre'], 'pre-install passed while the claim was ours' );
		$this->assertNotSame( '', $stamps['successor'], 'the aged claim must be seizable' );
		$this->assertInstanceOf( WP_Error::class, $stamps['clear'], 'a lost claim aborts at the clear, before the old directory is deleted' );
		$this->assertSame( 'aura_self_update_claim_lost', $stamps['clear']->get_error_code() );
		$this->assertSame( 'failed', $out['results'][0]['status'] );
		$this->assertStringContainsString( 'Lost the self-update claim', $out['results'][0]['detail'] );
		$this->assertSame( array(), array_filter( $GLOBALS['_filters']['upgrader_clear_destination'] ?? array() ), 'the beat is removed after the phase' );
	}

	public function test_a_generic_single_update_that_loses_its_claim_during_the_phase_is_not_reported_as_success(): void {
		// Codex #94 round-5 P2: update_plugin() had no check after its phase,
		// so a claim seized after upgrader_pre_install (post-install passes
		// through) came back as success while the successor owned the files.
		$successor = '';
		$GLOBALS['_upgrade_effect'] = function () use ( &$successor ) {
			$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
			$fence = substr( $held, 0, strpos( $held, '|' ) );
			update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
			$successor = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		};
		try {
			$res = ( new Aura_Worker_Updater() )->update_plugin( Aura_Worker_Updater::SELF_PLUGIN_FILE );
		} finally {
			unset( $GLOBALS['_upgrade_effect'] );
		}

		$this->assertNotSame( '', $successor );
		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['in_progress'], 'a successor owns the files; the outcome is its to report' );
		$this->assertStringStartsWith( $successor . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ) );
	}

	public function test_a_batch_entry_whose_claim_is_seized_during_the_health_probe_does_not_roll_back(): void {
		// SA#93 (closed): the probe is a loopback request; a claim lost across
		// it means the rollback belongs to the successor.
		$successor = '';
		$GLOBALS['_http_response'] = array( 'response' => array( 'code' => 500 ), 'body' => '' ); // the verdict would roll back
		$GLOBALS['_http_effect']   = function () use ( &$successor ) {
			if ( '' !== $successor ) {
				return;
			}
			$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
			$fence = substr( $held, 0, strpos( $held, '|' ) );
			update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
			$successor = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		};
		$updater = new class extends Aura_Worker_Updater {
			protected function update_single_plugin( $plugin_file ) {
				file_put_contents( WP_PLUGIN_DIR . '/digitizer-site-worker/digitizer-site-worker.php', "<?php\n// NEW BUILD 9.9.9\n" );
				return array( 'success' => true );
			}
		};

		$out = $updater->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE ), 5, true );

		$this->assertNotSame( '', $successor, 'the claim was seized during the probe' );
		$this->assertSame( 'failed', $out['results'][0]['status'] );
		$this->assertStringContainsString( 'Lost the self-update claim', $out['results'][0]['detail'] );
		$this->assertStringContainsString( 'NEW BUILD', file_get_contents( WP_PLUGIN_DIR . '/digitizer-site-worker/digitizer-site-worker.php' ), 'no rollback over the successor\'s files' );
		$this->assertStringStartsWith( $successor . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ) );
	}

	public function test_a_self_update_whose_claim_is_seized_during_the_verdict_does_not_roll_back(): void {
		// SA#93 (closed): same seam on the self-update path — the verdict took
		// a loopback round trip, and a claim lost across it leaves the rollback
		// to the successor.
		$successor = '';
		$GLOBALS['_install_effect'] = function () use ( &$successor ) {
			// A build that never boots (no beacon) — the verdict would roll back —
			// and the probe request is where the claim gets seized. Set AFTER
			// installNewBuild(), which installs its own `_http_effect`.
			$this->installNewBuild( false );
			$GLOBALS['_http_effect'] = function () use ( &$successor ) {
				if ( '' !== $successor ) {
					return;
				}
				$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
				$fence = substr( $held, 0, strpos( $held, '|' ) );
				update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
				$successor = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
			};
		};

		$res = $this->selfUpdate();

		$this->assertNotSame( '', $successor );
		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['in_progress'] );
		$this->assertTrue( $res['installed'] );
		$this->assertTrue( $res['health_checked'], 'the probe ran; its verdict is not acted on' );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk(), 'no restore over the successor\'s directory' );
		$this->assertStringStartsWith( $successor . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ) );
	}

	public function test_a_self_update_whose_claim_was_seized_during_install_neither_restores_nor_probes(): void {
		// Codex #91 round-3 P1: after install() the shipped code renewed the
		// lease and carried on "because the rollback is still owed". A lost
		// lease there means a SUCCESSOR self-update owns the directory now —
		// restoring our backup or rolling back on our verdict overwrites its
		// work. The request stops, says so, and leaves the successor's claim.
		$successor = '';
		$GLOBALS['_install_effect'] = function () use ( &$successor ) {
			$this->installNewBuild( true );
			$held  = (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK );
			$fence = substr( $held, 0, strpos( $held, '|' ) );
			update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
			$successor = Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
			$this->assertNotSame( '', $successor );
		};

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['in_progress'] );
		$this->assertTrue( $res['installed'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertFalse( $res['health_checked'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk(), 'no restore over the successor\'s directory' );
		$this->assertSame( array(), $GLOBALS['_wp_http_calls'], 'no probe: the verdict is the successor\'s to run' );
		$this->assertNull( get_option( 'aura_worker_boot_nonce', null ), 'no nonce armed for a probe that will not run' );
		$this->assertStringStartsWith( $successor . '|', (string) sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'the successor\'s claim is untouched' );
	}

	public function test_a_batch_entry_for_another_plugin_takes_no_claim_and_renews_nothing(): void {
		$out = ( new Aura_Worker_Updater() )->batch_update_plugins( array( 'akismet/akismet.php' ), 5, false );
		$this->assertSame( 'updated', $out['results'][0]['status'] );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ) );
	}

	public function test_a_guarded_rollback_that_lost_its_lease_does_not_restore(): void {
		if ( ! class_exists( 'Aura_Worker_Rollback' ) ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-rollback.php';
		}
		$rollback = new Aura_Worker_Rollback();
		$backup   = $rollback->backup_plugin( $this->slug );
		$this->assertTrue( $backup['success'], $backup['error'] ?? '' );
		file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9' ) );

		// The renewal happens right before restore_plugin() is called, so the
		// claim is aged and seized in the seam between take_claim and that renewal:
		$updater = new class extends Aura_Worker_Updater {
			protected function before_guarded_restore( $fence ) {
				// Age the claim past the takeover window and let a successor seize it.
				update_option( Aura_Worker_Updater::SELF_UPDATE_LOCK, $fence . '|' . ( time() - 11 * MINUTE_IN_SECONDS ) );
				Aura_Worker_Magic_Link::take_claim( Aura_Worker_Updater::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
			}
		};

		$res = $updater->restore_plugin_guarded( $rollback, $this->slug, $backup['backup_path'] );

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['in_progress'] );
		$this->assertStringContainsString( 'NEW BUILD', file_get_contents( $this->dir . '/digitizer-site-worker.php' ), 'nothing restored after the claim was lost' );
	}

	public function test_on_multisite_the_generic_paths_refuse_siteagent_and_leave_other_plugins_alone(): void {
		// Codex #91 round-1 P1: /v2/update/batch, update_plugin_safely and the
		// generic rollback reach SiteAgent's own directory under the same
		// per-blog claim, so refusing only self_update() left the race open.
		$GLOBALS['_is_multisite'] = true;
		$updater = new Aura_Worker_Updater();

		$single = $updater->update_plugin( Aura_Worker_Updater::SELF_PLUGIN_FILE );
		$this->assertFalse( $single['success'] );
		$this->assertSame( 'aura_self_update_multisite_unsupported', $single['code'] );
		$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'] );

		$other = $updater->update_plugin( 'akismet/akismet.php' );
		$this->assertTrue( $other['success'], 'another plugin is not held up' );

		$batch = $updater->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE, 'akismet/akismet.php' ), 5, false );
		$by    = array();
		foreach ( $batch['results'] as $r ) {
			$by[ $r['plugin'] ] = $r;
		}
		$this->assertSame( 'failed', $by[ Aura_Worker_Updater::SELF_PLUGIN_FILE ]['status'] );
		$this->assertStringContainsString( 'multisite', $by[ Aura_Worker_Updater::SELF_PLUGIN_FILE ]['detail'] );
		$this->assertSame( 'updated', $by['akismet/akismet.php']['status'] );

		if ( ! class_exists( 'Aura_Worker_Rollback' ) ) {
			require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-aura-worker-rollback.php';
		}
		$rollback = new Aura_Worker_Rollback();
		$backup   = $rollback->backup_plugin( $this->slug );
		$this->assertTrue( $backup['success'], $backup['error'] ?? '' );
		file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9' ) );
		$res = $updater->restore_plugin_guarded( $rollback, $this->slug, $backup['backup_path'] );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_self_update_multisite_unsupported', $res['code'] );
		$this->assertSame( 'NEW BUILD', $this->onDisk(), 'nothing restored' );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'no claim taken on any refused path' );
	}

	public function test_a_multisite_network_is_refused_before_any_claim_download_or_write(): void {
		// SA#79: the self-update claim is per blog, the plugin directory is
		// network-wide, so two subsites could update the same files at once.
		// Until the claim lives in network state, the self-update refuses on
		// a network rather than race.
		$GLOBALS['_is_multisite'] = true;

		$res = $this->selfUpdate();

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_self_update_multisite_unsupported', $res['code'] );
		$this->assertStringContainsString( 'multisite', $res['error'] );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'no claim taken' );
		$this->assertSame( array(), $GLOBALS['_wp_http_calls'], 'no download' );
		$this->assertSame( 'OLD BUILD', $this->onDisk(), 'nothing written' );
	}

	// -----------------------------------------------------------------
	// SA#95 — hosts where PHP cannot write or delete .php files.
	// -----------------------------------------------------------------

	/**
	 * An updater on a host whose probe answers `$verdict`, and whose recovery
	 * helper answers `$rollback_verdict` to its own pre-delete probe. Counts
	 * the probes and every restore_plugin() call, so a test can say what did
	 * NOT run.
	 */
	private function onHost( string $verdict, string $rollback_verdict = 'ok' ): Aura_Worker_Updater {
		return new class( $verdict, $rollback_verdict ) extends Aura_Worker_Updater {
			public $probes    = 0;
			public $rollbacks = array();
			private $verdict;
			private $rollback_verdict;
			public function __construct( $verdict, $rollback_verdict ) {
				$this->verdict          = $verdict;
				$this->rollback_verdict = $rollback_verdict;
			}
			protected function host_php_writes_verdict() {
				$this->probes++;
				return $this->verdict;
			}
			protected function new_rollback() {
				$r = new class( $this->rollback_verdict ) extends Aura_Worker_Rollback {
					public $restores = 0;
					private $v;
					public function __construct( $v ) {
						$this->v = $v;
						parent::__construct();
					}
					protected function host_php_writes_verdict() {
						return $this->v;
					}
					public function restore_plugin( $plugin_slug, $backup_path ) {
						$this->restores++;
						return parent::restore_plugin( $plugin_slug, $backup_path );
					}
				};
				$this->rollbacks[] = $r;
				return $r;
			}
		};
	}

	private function restoresRun( Aura_Worker_Updater $updater ): int {
		$n = 0;
		foreach ( $updater->rollbacks as $r ) {
			$n += $r->restores;
		}
		return $n;
	}

	/** Every file AND directory under $dir, with bytes' hash (files) — for "untouched". */
	private function treeOf( string $dir ): array {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			$rel         = substr( $f->getPathname(), strlen( $dir ) );
			$out[ $rel ] = $f->isDir() ? 'dir' : hash_file( 'sha256', $f->getPathname() ) . '@' . filemtime( $f->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	private function assertRefusedBeforeAnything( array $res, string $code ): void {
		$this->assertFalse( $res['success'] );
		$this->assertSame( $code, $res['code'] ?? null );
		$this->assertFalse( $res['in_progress'] );
		$this->assertFalse( $res['installed'] );
		$this->assertFalse( $res['backed_up'] );
		$this->assertFalse( $res['health_checked'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertNull( $res['restore_error'] );
		$this->assertStringContainsString( 'Nothing on the site was changed', $res['error'] );
	}

	public function test_a_host_that_blocks_php_writes_refuses_the_self_update_before_any_claim_download_backup_or_install(): void {
		file_put_contents( $this->dir . '/readme.txt', "=== SiteAgent ===\n" );
		$backups = WP_CONTENT_DIR . '/aura-backups';
		$this->rmdir( $backups );
		$sha    = $this->packageCarrying( '9.9.9' );
		$before = $this->treeOf( $this->dir );
		$GLOBALS['_download_url_calls'] = array();
		$updater = $this->onHost( 'blocked' );

		try {
			$res = $this->verifiedSelfUpdate( $sha, $updater );
		} finally {
			$this->cleanupPackages();
		}

		$this->assertRefusedBeforeAnything( $res, 'aura_php_writes_blocked' );
		$this->assertSame( 'blocked', $res['php_writes'] );
		$this->assertSame( 1, $updater->probes );
		$this->assertStringContainsString( 'does not let PHP write or delete .php files', $res['error'] );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'no claim taken' );
		$this->assertSame( array(), $GLOBALS['_download_url_calls'], 'no download' );
		$this->assertNotContains( 'Plugin_Upgrader::install', $GLOBALS['_mutations'], 'no install' );
		$this->assertDirectoryDoesNotExist( $backups, 'no backup, not even the backup directory' );
		$this->assertSame( array(), $updater->rollbacks, 'no recovery helper built' );
		$this->assertSame( $before, $this->treeOf( $this->dir ), 'the plugin directory is byte-for-byte unchanged' );
		$this->assertSame( array(), $GLOBALS['_wp_http_calls'], 'no loopback' );
	}

	public function test_an_unwritable_upgrade_directory_refuses_the_self_update_with_its_own_code(): void {
		$updater = $this->onHost( 'unwritable' );

		$res = $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );

		$this->assertRefusedBeforeAnything( $res, 'aura_upgrade_dir_unwritable' );
		$this->assertSame( 'unwritable', $res['php_writes'] );
		$this->assertNotContains( 'Plugin_Upgrader::install', $GLOBALS['_mutations'] );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ) );
	}

	public function test_a_host_whose_probe_answers_ok_updates_exactly_as_before(): void {
		$updater = $this->onHost( 'ok' );

		$res = $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $res['backed_up'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertArrayNotHasKey( 'code', $res );
		$this->assertSame( 1, $updater->probes );
		$this->assertSame( 'NEW BUILD', $this->onDisk() );
	}

	public function test_the_multisite_refusal_still_wins_over_a_blocked_host(): void {
		$GLOBALS['_is_multisite'] = true;
		$updater = $this->onHost( 'blocked' );

		$res = $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );

		$this->assertSame( 'aura_self_update_multisite_unsupported', $res['code'] );
		$this->assertSame( 0, $updater->probes, 'multisite refuses before the probe' );

		$single = $updater->update_plugin( Aura_Worker_Updater::SELF_PLUGIN_FILE );
		$this->assertSame( 'aura_self_update_multisite_unsupported', $single['code'] );

		$batch = $updater->batch_update_plugins( array( Aura_Worker_Updater::SELF_PLUGIN_FILE ), 5, false );
		$this->assertSame( 'aura_self_update_multisite_unsupported', $batch['results'][0]['code'] );
	}

	public function test_a_blocked_host_refuses_the_generic_single_update_of_any_plugin(): void {
		$updater = $this->onHost( 'blocked' );

		$res = $updater->update_plugin( 'akismet/akismet.php' );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'aura_php_writes_blocked', $res['code'] ?? null );
		$this->assertSame( 'blocked', $res['php_writes'] );
		$this->assertFalse( $res['in_progress'] );
		$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'] );

		$self = $updater->update_plugin( Aura_Worker_Updater::SELF_PLUGIN_FILE );
		$this->assertSame( 'aura_php_writes_blocked', $self['code'] ?? null );
		$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'no claim taken' );
		$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'] );
	}

	public function test_a_blocked_host_refuses_every_batch_entry_and_touches_nothing(): void {
		$other = WP_PLUGIN_DIR . '/akismet';
		$this->rmdir( $other );
		mkdir( $other . '/assets', 0777, true );
		file_put_contents( $other . '/akismet.php', "<?php\n/**\n * Plugin Name: Akismet\n * Version: 1.0\n */\n" );
		file_put_contents( $other . '/readme.txt', 'akismet' );
		file_put_contents( $other . '/assets/x.css', 'body{}' );
		$before  = $this->treeOf( $other );
		$backups = WP_CONTENT_DIR . '/aura-backups';
		$this->rmdir( $backups );
		$updater = $this->onHost( 'blocked' );

		try {
			$out = $updater->batch_update_plugins( array( 'akismet/akismet.php', Aura_Worker_Updater::SELF_PLUGIN_FILE ), 5, true );

			foreach ( $out['results'] as $entry ) {
				$this->assertSame( 'failed', $entry['status'] );
				$this->assertSame( 'aura_php_writes_blocked', $entry['code'] ?? null );
				$this->assertStringContainsString( '.php files', $entry['detail'] );
			}
			$this->assertSame( array( 'akismet/akismet.php', Aura_Worker_Updater::SELF_PLUGIN_FILE ), array_column( $out['results'], 'plugin' ) );
			$this->assertSame( 2, $out['summary']['failed'] );
			$this->assertSame( 1, $updater->probes, 'one probe per batch' );
			$this->assertNotContains( 'Plugin_Upgrader::upgrade', $GLOBALS['_mutations'] );
			$this->assertDirectoryDoesNotExist( $backups, 'no backup taken, no backup directory created' );
			$this->assertSame( $before, $this->treeOf( $other ) );
			$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ), 'no claim taken' );
		} finally {
			$this->rmdir( $other );
		}
	}

	public function test_a_blocked_host_refuses_the_guarded_rollback_before_deleting_anything(): void {
		// The regression that matters: a restore on such a host deleted every
		// non-PHP file, then stopped at the first .php one.
		$slug = 'sa-host-fixture';
		$dir  = WP_PLUGIN_DIR . '/' . $slug;
		$this->rmdir( $dir );
		mkdir( $dir . '/assets', 0777, true );
		file_put_contents( $dir . '/main.php', '<?php // ORIGINAL' );
		file_put_contents( $dir . '/readme.txt', 'readme' );
		file_put_contents( $dir . '/assets/x.css', 'body{}' );
		$rollback = new Aura_Worker_Rollback();
		$backup   = $rollback->backup_plugin( $slug );
		$this->assertTrue( $backup['success'], $backup['error'] ?? '' );
		file_put_contents( $dir . '/main.php', '<?php // CURRENT' );
		$before  = $this->treeOf( $dir );
		$updater = $this->onHost( 'blocked' );

		try {
			$res = $updater->restore_plugin_guarded( $rollback, $slug, $backup['backup_path'] );

			$this->assertFalse( $res['success'] );
			$this->assertSame( 'preflight', $res['stage'] ?? null );
			$this->assertSame( 'aura_php_writes_blocked', $res['code'] ?? null );
			$this->assertSame( $before, $this->treeOf( $dir ), 'every file is still there, readme.txt and assets/x.css included' );
			$this->assertFileExists( $dir . '/readme.txt' );
			$this->assertFileExists( $dir . '/assets/x.css' );
			$this->assertNotContains( 'SA_Test_Filesystem::delete', $GLOBALS['_mutations'] );

			// SiteAgent's own guarded restore: the same, and no claim.
			$self_backup = $rollback->backup_plugin( $this->slug );
			$this->assertTrue( $self_backup['success'] );
			file_put_contents( $this->dir . '/digitizer-site-worker.php', $this->build( 'NEW BUILD', '9.9.9' ) );
			$self = $updater->restore_plugin_guarded( $rollback, $this->slug, $self_backup['backup_path'] );
			$this->assertSame( 'preflight', $self['stage'] ?? null );
			$this->assertSame( 'aura_php_writes_blocked', $self['code'] ?? null );
			$this->assertSame( 'NEW BUILD', $this->onDisk() );
			$this->assertNull( sa_read_option_uncached( Aura_Worker_Updater::SELF_UPDATE_LOCK ) );
		} finally {
			$this->rmdir( $dir );
		}
	}

	public function test_a_failed_install_that_changed_nothing_is_not_restored(): void {
		// What WP Engine does: PclZip fails to unpack, install() answers null,
		// and the live directory was never touched. Restoring would only start
		// a delete the host will not let finish.
		file_put_contents( $this->dir . '/readme.txt', "=== SiteAgent ===\n" );
		mkdir( $this->dir . '/assets' );
		file_put_contents( $this->dir . '/assets/x.css', 'body{}' );
		$before = $this->treeOf( $this->dir );
		$GLOBALS['_install_result'] = null;
		$GLOBALS['_install_effect'] = null;
		$updater = $this->onHost( 'ok' );

		$res = $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );

		$this->assertFalse( $res['success'] );
		$this->assertTrue( $res['backed_up'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'unchanged', $res['restore_skipped'] ?? null );
		$this->assertNull( $res['restore_error'] );
		$this->assertSame( 0, $this->restoresRun( $updater ), 'restore_plugin() is not called' );
		$this->assertStringContainsString( 'before it changed any file', $res['error'] );
		$this->assertStringNotContainsString( 'could NOT be restored', $res['error'] );
		$this->assertSame( $before, $this->treeOf( $this->dir ), 'the directory is intact' );
	}

	public function test_a_wp_error_install_that_changed_nothing_is_not_restored_either(): void {
		file_put_contents( $this->dir . '/readme.txt', "=== SiteAgent ===\n" );
		$before = $this->treeOf( $this->dir );
		$GLOBALS['_install_result'] = new WP_Error( 'copy_failed_pclzip', 'Could not copy file.' );
		$GLOBALS['_install_effect'] = null;
		$updater = $this->onHost( 'ok' );

		$res = $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 'unchanged', $res['restore_skipped'] ?? null );
		$this->assertSame( 0, $this->restoresRun( $updater ) );
		$this->assertStringContainsString( 'Could not copy file.', $res['error'] );
		$this->assertSame( $before, $this->treeOf( $this->dir ) );
	}

	public function test_a_failed_install_that_did_change_the_directory_is_still_restored(): void {
		file_put_contents( $this->dir . '/readme.txt', "=== SiteAgent ===\n" );
		$GLOBALS['_install_result'] = new WP_Error( 'fs', 'filesystem exploded' );
		$GLOBALS['_install_effect'] = function () {
			unlink( $this->dir . '/readme.txt' );
		};
		$updater = $this->onHost( 'ok' );

		$res = $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 1, $this->restoresRun( $updater ) );
		$this->assertTrue( $res['rolled_back'], (string) ( $res['restore_error'] ?? '' ) );
		$this->assertArrayNotHasKey( 'restore_skipped', $res );
		$this->assertFileExists( $this->dir . '/readme.txt', 'the restore put it back' );
		$this->assertSame( 'OLD BUILD', $this->onDisk() );
	}

	public function test_a_restore_refused_by_its_preflight_says_the_directory_was_not_touched(): void {
		// The install changed the directory, so a restore is owed — but the
		// recovery helper's own probe says the host blocks .php writes. It
		// refuses before deleting, and the message says what is on disk.
		file_put_contents( $this->dir . '/readme.txt', "=== SiteAgent ===\n" );
		$GLOBALS['_install_result'] = false;
		$GLOBALS['_install_effect'] = function () {
			file_put_contents( $this->dir . '/new-file.txt', 'x' );
		};
		$updater = $this->onHost( 'ok', 'blocked' );

		$res = $updater->self_update( 'https://github.com/Digitizers/SiteAgent/releases/download/v9.9.9/x.zip' );

		$this->assertFalse( $res['success'] );
		$this->assertFalse( $res['rolled_back'] );
		$this->assertSame( 1, $this->restoresRun( $updater ) );
		$this->assertNotNull( $res['restore_error'] );
		$this->assertStringContainsString( 'refused before deleting anything', $res['error'] );
		$this->assertStringContainsString( 'reads version ' . AURA_WORKER_VERSION, $res['error'] );
		$this->assertStringNotContainsString( 'may be missing or incomplete', $res['error'] );
		$this->assertFileExists( $this->dir . '/readme.txt' );
		$this->assertFileExists( $this->dir . '/new-file.txt', 'nothing was deleted' );
	}
}
