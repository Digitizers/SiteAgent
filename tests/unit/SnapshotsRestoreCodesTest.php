<?php
/**
 * The REST surface of a file restore (Aura#520 §3.1): every designated
 * refusal is a 409 the caller can map, and a file restore never opens a
 * door-log entry — Aura relies on that, because a door entry would become a
 * second agent action for one restore.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SnapshotsRestoreCodesTest extends TestCase {

	/** @var Aura_Worker_API */
	private $api;

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0755, true );
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		$GLOBALS['_options']['aura_worker_site_token'] = Aura_Worker_Security::hash_token( 'tok' );
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 5,
			'issued_at'   => '2026-09-02T00:00:00Z',
			'received_at' => time(),
			'rules'       => array(),
		);
		$this->api = new Aura_Worker_API( new Aura_Worker_Security() );
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

	private function request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_header( 'X-Aura-Token', 'tok' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	public function test_a_changed_file_is_a_409_with_its_code(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/rest-changed.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];
		file_put_contents( $file, "<?php // edited\n" );

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['id'] ) ) );

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_file_changed_since', $res->get_data()['code'] );
	}

	public function test_an_unfenced_record_is_a_409_with_its_code(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/rest-unfenced.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->snapshot_file( $file )['snapshot'];
		file_put_contents( $file, "<?php // edited\n" );

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['id'] ) ) );

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_snapshot_unfenced', $res->get_data()['code'] );
	}

	public function test_a_fenced_restore_succeeds_with_200(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/rest-ok.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['id'] ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
	}

	public function test_a_file_restore_opens_no_door_log_entry(): void {
		// A `file` record has no door_kind, so restore_snapshot() never
		// reserves an entry. Aura counts on this: an entry would surface as a
		// second agent action for one restore (Aura#520 §3.1).
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/rest-door.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$before = Aura_Worker_Door_Log::log_after( 0 );
		$res    = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['id'], 'aura_ref' => 'act_file_1' ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $before, Aura_Worker_Door_Log::log_after( 0 ), 'the door log is untouched' );
		$this->assertNull( Aura_Worker_Door_Log::get( 1 ), 'no entry was opened' );
	}
}
