<?php
/**
 * Self-update zip integrity: the downloaded package must match the
 * gateway-bound SHA-256 before install (Part C). Tests the pure verification
 * helper directly with real files.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SelfUpdateIntegrityTest extends TestCase {

	private function verify( string $file, string $expected ) {
		$updater = ( new ReflectionClass( Aura_Worker_Updater::class ) )->newInstanceWithoutConstructor();
		$m       = new ReflectionMethod( Aura_Worker_Updater::class, 'verify_zip_integrity' );
		$m->setAccessible( true );
		return $m->invoke( $updater, $file, $expected );
	}

	public function test_matching_digest_passes_case_insensitively(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'sa_zip_' );
		file_put_contents( $tmp, 'PK-fake-zip-bytes' );
		$sha = hash_file( 'sha256', $tmp );

		$this->assertTrue( $this->verify( $tmp, $sha ) );
		$this->assertTrue( $this->verify( $tmp, strtoupper( $sha ) ) );

		unlink( $tmp );
	}

	public function test_mismatched_digest_is_rejected(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'sa_zip_' );
		file_put_contents( $tmp, 'the real package bytes' );

		$res = $this->verify( $tmp, str_repeat( 'a', 64 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_self_update_integrity', $res->get_error_code() );

		unlink( $tmp );
	}

	public function test_malformed_expected_digest_is_rejected(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'sa_zip_' );
		file_put_contents( $tmp, 'x' );

		$res = $this->verify( $tmp, 'not-a-valid-sha256' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_self_update_bad_digest', $res->get_error_code() );

		unlink( $tmp );
	}
}
