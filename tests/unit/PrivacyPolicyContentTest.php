<?php
/**
 * The suggested privacy-policy text names the install ledger's personal data
 * (2.22.0, Aura spec 2026-09-21 §4.2) — never "no personal data".
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
	function wp_add_privacy_policy_content( $plugin_name, $policy_text ) {
		$GLOBALS['_sa_privacy_policy'][ $plugin_name ] = $policy_text;
	}
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return $text;
	}
}

final class PrivacyPolicyContentTest extends TestCase {

	public function test_the_policy_text_discloses_the_install_ledger(): void {
		unset( $GLOBALS['_sa_privacy_policy'] );
		( new Aura_Worker() )->add_privacy_policy_content();
		$text = (string) ( $GLOBALS['_sa_privacy_policy']['SiteAgent'] ?? '' );
		$this->assertStringNotContainsString( 'No personal user data', $text );
		foreach ( array( 'ID of the user', 'application password', 'REST route', '90 days', 'uninstalled' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text );
		}
	}
}
