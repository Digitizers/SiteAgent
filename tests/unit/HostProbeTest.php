<?php
/**
 * SA#95 — the host write probe, and what `/status` says about it.
 *
 * On WP Engine PHP cannot create, overwrite or delete a `.php` file while
 * every other extension works, so an Aura-driven plugin update always fails
 * there and the restore that followed deleted the plugin's non-PHP files.
 * The probe asks the filesystem, in the directory the upgrader works in,
 * whether a `.txt` and then a `.php` file can be created and deleted.
 *
 * The unit suite cannot fake a real permission, so the refusing hosts are
 * modelled through the probe's own `write_file()` / `delete_file()` seams;
 * the `ok` answer is proven against the real sandbox directory.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class HostProbeTest extends TestCase {

	private string $upgrade;

	protected function setUp(): void {
		sa_reset_state();
		$this->upgrade = WP_CONTENT_DIR . '/upgrade';
		$this->removeProbeFiles();
	}

	protected function tearDown(): void {
		$this->removeProbeFiles();
		unset( $GLOBALS['_wp_filesystem_unavailable'] );
		$GLOBALS['wp_filesystem'] = null;
	}

	private function removeProbeFiles(): void {
		foreach ( $this->leftovers() as $f ) {
			@unlink( $f );
		}
	}

	/** @return string[] */
	private function leftovers(): array {
		clearstatcache();
		return glob( $this->upgrade . '/aura-php-probe-*' ) ?: array();
	}

	private function probeRuns(): int {
		return count(
			array_filter(
				$GLOBALS['_did_actions'],
				static function ( $a ) {
					return 'aura_worker_host_probe_ran' === $a['tag'];
				}
			)
		);
	}

	/**
	 * A probe whose writes and deletes are decided by callbacks. Each receives
	 * the path and answers null to fall through to the real operation.
	 */
	private function probeWith( ?callable $write, ?callable $delete = null ): Aura_Worker_Host_Probe {
		return new class( $write, $delete ) extends Aura_Worker_Host_Probe {
			public $writes  = array();
			public $deletes = array();
			private $w;
			private $d;
			public function __construct( $w, $d ) {
				$this->w = $w;
				$this->d = $d;
			}
			protected function write_file( $path, $body ) {
				$this->writes[] = $path;
				$answer         = $this->w ? ( $this->w )( $path, $body ) : null;
				return null === $answer ? parent::write_file( $path, $body ) : $answer;
			}
			protected function delete_file( $path ) {
				$this->deletes[] = $path;
				$answer          = $this->d ? ( $this->d )( $path ) : null;
				return null === $answer ? parent::delete_file( $path ) : $answer;
			}
		};
	}

	public static function isPhp( string $path ): bool {
		return '.php' === substr( $path, -4 );
	}

	public function test_the_real_probe_answers_ok_in_the_sandbox_leaves_nothing_and_records_it(): void {
		$before = time();

		$verdict = Aura_Worker_Updater::host_php_writes();

		$this->assertSame( 'ok', $verdict );
		$this->assertSame( array(), $this->leftovers(), 'no aura-php-probe-* file left behind' );
		$row = get_option( 'aura_worker_host_probe' );
		$this->assertSame( 'ok', $row['php_writes'] );
		$this->assertIsInt( $row['checked_at'] );
		$this->assertGreaterThanOrEqual( $before, $row['checked_at'] );
		$this->assertFalse( $GLOBALS['_rows_autoload']['aura_worker_host_probe'] ?? null, 'recorded with autoload off' );
		$this->assertSame( 1, $this->probeRuns() );
	}

	public function test_the_real_probe_writes_the_files_it_is_asked_to_through_the_upgrade_directory(): void {
		$probe = $this->probeWith( null );

		$this->assertSame( 'ok', $probe->run() );

		$this->assertCount( 2, $probe->writes );
		$this->assertMatchesRegularExpression( '#/upgrade/aura-php-probe-[0-9a-f]+\.txt$#', $probe->writes[0] );
		$this->assertMatchesRegularExpression( '#/upgrade/aura-php-probe-[0-9a-f]+\.php$#', $probe->writes[1] );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_a_missing_upgrade_directory_is_created_first(): void {
		if ( is_dir( $this->upgrade ) && array() === array_diff( scandir( $this->upgrade ), array( '.', '..' ) ) ) {
			rmdir( $this->upgrade );
		}
		if ( is_dir( $this->upgrade ) ) {
			$this->markTestSkipped( 'the sandbox upgrade directory holds other files' );
		}

		$this->assertSame( 'ok', Aura_Worker_Updater::host_php_writes() );
		$this->assertDirectoryExists( $this->upgrade );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_without_a_filesystem_transport_the_probe_uses_plain_php(): void {
		$GLOBALS['_wp_filesystem_unavailable'] = true;
		$GLOBALS['wp_filesystem']              = null;

		$this->assertSame( 'ok', Aura_Worker_Updater::host_php_writes() );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_a_host_that_refuses_to_create_php_files_answers_blocked(): void {
		$probe = $this->probeWith(
			static function ( $path ) {
				return self::isPhp( $path ) ? false : null;
			}
		);

		$this->assertSame( 'blocked', $probe->run() );
		$this->assertSame( array(), $this->leftovers(), 'the .txt probe is cleaned up too' );
		$this->assertSame( 'blocked', get_option( 'aura_worker_host_probe' )['php_writes'] );
	}

	public function test_a_host_that_lets_a_php_file_be_created_but_not_deleted_answers_blocked_and_still_cleans_up(): void {
		// The WP_Filesystem delete refuses every time; the cleanup's second
		// attempt (PHP's own unlink) is what removes it in the sandbox.
		$probe = $this->probeWith(
			null,
			static function ( $path ) {
				return self::isPhp( $path ) ? false : null;
			}
		);

		$this->assertSame( 'blocked', $probe->run() );
		$php_deletes = array_values( array_filter( $probe->deletes, array( self::class, 'isPhp' ) ) );
		$this->assertCount( 2, $php_deletes, 'the probe deletes once, the cleanup tries once more' );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_a_directory_that_takes_no_file_at_all_answers_unwritable(): void {
		$probe = $this->probeWith(
			static function () {
				return false;
			}
		);

		$this->assertSame( 'unwritable', $probe->run() );
		$this->assertCount( 1, $probe->writes, 'the .php write is not attempted' );
		$this->assertSame( 'unwritable', get_option( 'aura_worker_host_probe' )['php_writes'] );
	}

	public function test_a_probe_that_throws_after_its_first_create_still_cleans_up(): void {
		// The .txt file is created, then deleting it throws: the probe must not
		// escape, and the file must not stay behind.
		$probe = $this->probeWith(
			null,
			static function ( $path ) {
				if ( ! self::isPhp( $path ) ) {
					throw new RuntimeException( 'filesystem went away' );
				}
				return null;
			}
		);

		$verdict = $probe->run();

		$this->assertSame( 'blocked', $verdict, 'not proven, so not ok' );
		$this->assertSame( array(), $this->leftovers() );
		$this->assertSame( 'blocked', get_option( 'aura_worker_host_probe' )['php_writes'] );
	}

	public function test_a_probe_that_throws_after_creating_the_php_file_still_cleans_it_up(): void {
		$probe = $this->probeWith(
			null,
			static function ( $path ) {
				if ( self::isPhp( $path ) ) {
					throw new RuntimeException( 'filesystem went away' );
				}
				return null;
			}
		);

		$this->assertSame( 'blocked', $probe->run() );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_a_probe_that_throws_before_anything_was_written_answers_unwritable(): void {
		$probe = $this->probeWith(
			static function () {
				throw new RuntimeException( 'no' );
			}
		);

		$this->assertSame( 'unwritable', $probe->run() );
		$this->assertSame( array(), $this->leftovers() );
	}

	// --- /status ------------------------------------------------------------

	private function statusBody(): array {
		$api = new Aura_Worker_API( new Aura_Worker_Security() );
		return $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
	}

	public function test_status_reports_the_recorded_probe(): void {
		update_option( 'aura_worker_host_probe', array( 'php_writes' => 'blocked', 'checked_at' => 1760000000 ), false );

		$body = $this->statusBody();

		$this->assertSame( array( 'php_writes' => 'blocked', 'checked_at' => 1760000000 ), $body['host'] );
		$this->assertSame( '{"php_writes":"blocked","checked_at":1760000000}', wp_json_encode( $body['host'] ) );
	}

	public function test_status_with_no_recorded_probe_reports_nulls(): void {
		$body = $this->statusBody();

		$this->assertSame( array( 'php_writes' => null, 'checked_at' => null ), $body['host'] );
	}

	public function test_status_reads_a_malformed_record_as_not_recorded(): void {
		update_option( 'aura_worker_host_probe', array( 'php_writes' => 'maybe', 'checked_at' => 'yesterday' ), false );

		$this->assertSame( array( 'php_writes' => null, 'checked_at' => null ), $this->statusBody()['host'] );
	}

	public function test_status_never_runs_the_probe(): void {
		$GLOBALS['_option_writes'] = array();

		$this->statusBody();

		$this->assertSame( 0, $this->probeRuns() );
		$this->assertNotContains( array( 'set', 'aura_worker_host_probe' ), $GLOBALS['_option_writes'] );
		$this->assertFalse( get_option( 'aura_worker_host_probe' ) );
	}
}
