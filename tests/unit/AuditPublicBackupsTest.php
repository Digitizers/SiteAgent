<?php
/**
 * SA#156: full-site backups and database dumps inside the webroot.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class AuditPublicBackupsTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		sa_reset_state();
		$this->root = sys_get_temp_dir() . '/sa-backups-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/ai1wm-backups/sub', 0777, true );
		mkdir( $this->root . '/updraft', 0777, true );
		file_put_contents( $this->root . '/ai1wm-backups/site-20261101.wpress', str_repeat( 'x', 10 ) );
		file_put_contents( $this->root . '/ai1wm-backups/index.php', '<?php' );
		file_put_contents( $this->root . '/ai1wm-backups/.htaccess', 'deny from all' );
		file_put_contents( $this->root . '/ai1wm-backups/sub/old.sql.gz', 'y' );
		file_put_contents( $this->root . '/updraft/backup_db.gz', 'z' );
		file_put_contents( $this->root . '/readme.txt', 'not a backup' );
		file_put_contents( $this->root . '/dump.SQL', 'q' );
	}

	protected function tearDown(): void {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $this->root );
	}

	/** A tool over the fixture tree, answering HTTP from a map of path suffix => status. */
	private function tool( array $statuses, ?array $locations = null ): Aura_Tool_Audit_Public_Backups {
		$root = $this->root;
		$locs = $locations ?? array(
			array( 'dir' => $root, 'url' => 'https://site.test/wp-content', 'depth' => 0 ),
			array( 'dir' => $root . '/ai1wm-backups', 'url' => 'https://site.test/wp-content/ai1wm-backups', 'depth' => 2 ),
			array( 'dir' => $root . '/updraft', 'url' => 'https://site.test/wp-content/updraft', 'depth' => 2 ),
			array( 'dir' => $root . '/missing', 'url' => 'https://site.test/wp-content/missing', 'depth' => 2 ),
		);
		return new class( $locs, $statuses ) extends Aura_Tool_Audit_Public_Backups {
			public $asked = array();
			private $locs;
			private $statuses;
			public function __construct( $locs = array(), $statuses = array() ) {
				$this->locs     = $locs;
				$this->statuses = $statuses;
			}
			protected function locations() {
				return $this->locs;
			}
			protected function http_status( $url ) {
				$this->asked[] = $url;
				foreach ( $this->statuses as $suffix => $code ) {
					if ( substr( $url, -strlen( $suffix ) ) === $suffix ) {
						return $code;
					}
				}
				return null;
			}
		};
	}

	private static function by_path( array $files ): array {
		$out = array();
		foreach ( $files as $f ) {
			$out[ basename( $f['path'] ) ] = $f;
		}
		return $out;
	}

	public function test_finds_backup_named_files_only_and_says_which_are_served(): void {
		$tool = $this->tool( array( '.wpress' => 200, 'old.sql.gz' => 403, 'backup_db.gz' => 302 ) );
		$res  = $tool->execute( array() );
		$f    = self::by_path( $res['files'] );

		$this->assertEqualsCanonicalizing( array( 'site-20261101.wpress', 'old.sql.gz', 'backup_db.gz', 'dump.SQL' ), array_keys( $f ) );
		$this->assertTrue( $f['site-20261101.wpress']['public'] );
		$this->assertSame( 10, $f['site-20261101.wpress']['size'] );
		$this->assertFalse( $f['old.sql.gz']['public'] );
		$this->assertNull( $f['backup_db.gz']['public'], 'a redirect proves nothing either way' );
		$this->assertSame( 302, $f['backup_db.gz']['http_status'] );
		$this->assertNull( $f['dump.SQL']['public'], 'no answer is not "not public"' );
		$this->assertArrayNotHasKey( 'url', $f['dump.SQL'] );
		$this->assertFalse( $res['coverage']['truncated'] );
		$this->assertSame( 4, $res['coverage']['http_checked'] );
	}

	public function test_asks_the_files_own_url_with_each_segment_encoded(): void {
		file_put_contents( $this->root . '/ai1wm-backups/my site.wpress', 'x' );
		$tool = $this->tool( array() );
		$tool->execute( array() );
		$this->assertContains( 'https://site.test/wp-content/ai1wm-backups/my%20site.wpress', $tool->asked );
		$this->assertContains( 'https://site.test/wp-content/ai1wm-backups/sub/old.sql.gz', $tool->asked );
	}

	public function test_http_checks_are_capped_and_the_rest_stay_unknown(): void {
		for ( $i = 0; $i < Aura_Tool_Audit_Public_Backups::MAX_HTTP_CHECKS + 5; $i++ ) {
			file_put_contents( $this->root . "/updraft/b{$i}.zip", 'x' );
		}
		$tool = $this->tool( array( '.zip' => 200 ) );
		$res  = $tool->execute( array() );
		$this->assertCount( Aura_Tool_Audit_Public_Backups::MAX_HTTP_CHECKS, $tool->asked );
		$this->assertSame( Aura_Tool_Audit_Public_Backups::MAX_HTTP_CHECKS, $res['coverage']['http_checked'] );
		$unchecked = array_filter( $res['files'], fn( $f ) => null === $f['http_status'] );
		$this->assertNotEmpty( $unchecked );
	}

	public function test_a_symlink_is_never_followed(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'no symlink()' );
		}
		$outside = sys_get_temp_dir() . '/sa-outside-' . bin2hex( random_bytes( 4 ) );
		mkdir( $outside );
		file_put_contents( $outside . '/secret.sql', 'x' );
		symlink( $outside, $this->root . '/updraft/link' );
		symlink( $outside . '/secret.sql', $this->root . '/updraft/linked.sql' );
		$res = $this->tool( array() )->execute( array() );
		unlink( $this->root . '/updraft/link' );
		unlink( $this->root . '/updraft/linked.sql' );
		unlink( $outside . '/secret.sql' );
		rmdir( $outside );
		$names = array_keys( self::by_path( $res['files'] ) );
		$this->assertNotContains( 'secret.sql', $names );
		$this->assertNotContains( 'linked.sql', $names );
	}

	public function test_served_reads_statuses_conservatively(): void {
		$this->assertTrue( Aura_Tool_Audit_Public_Backups::served( 200 ) );
		$this->assertTrue( Aura_Tool_Audit_Public_Backups::served( 206 ) );
		foreach ( array( 401, 403, 404, 410 ) as $c ) {
			$this->assertFalse( Aura_Tool_Audit_Public_Backups::served( $c ) );
		}
		foreach ( array( null, 301, 302, 500, 503 ) as $c ) {
			$this->assertNull( Aura_Tool_Audit_Public_Backups::served( $c ) );
		}
	}

	public function test_is_read_only(): void {
		$a = ( new Aura_Tool_Audit_Public_Backups() )->get_annotations();
		$this->assertTrue( $a['read_only'] );
		$this->assertFalse( $a['requires_approval'] );
	}
}
