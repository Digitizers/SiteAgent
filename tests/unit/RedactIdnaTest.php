<?php
/**
 * SiteAgent #116: the generated UTS-46 map (Aura_Worker_Redact_Idna). Pins the
 * table's shape and the mappings a URL parser applies to a hostname, so a
 * regeneration from another table — or a hand edit — fails here.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactIdnaTest extends TestCase {

	/** @dataProvider mapped */
	public function test_mapped_and_ignored_code_points( string $in, string $expected ): void {
		$this->assertSame( $expected, Aura_Worker_Redact_Idna::map( $in ) );
	}

	public static function mapped(): array {
		return array(
			'fullwidth h'              => array( "\u{FF48}ooks.zapier.com", 'hooks.zapier.com' ),
			'math bold h'              => array( "\u{1D421}ooks.zapier.com", 'hooks.zapier.com' ),
			'circled h'                => array( "\u{24D7}ooks.zapier.com", 'hooks.zapier.com' ),
			'ideographic full stop'    => array( "hooks\u{3002}zapier.com", 'hooks.zapier.com' ),
			'fullwidth full stop'      => array( "hooks\u{FF0E}zapier.com", 'hooks.zapier.com' ),
			'halfwidth full stop'      => array( "hooks\u{FF61}zapier.com", 'hooks.zapier.com' ),
			'soft hyphen deleted'      => array( "hooks\u{00AD}.zapier.com", 'hooks.zapier.com' ),
			'zero width space deleted' => array( "hooks\u{200B}.zapier.com", 'hooks.zapier.com' ),
			'word joiner deleted'      => array( "hooks\u{2060}.zapier.com", 'hooks.zapier.com' ),
			'bom deleted'              => array( "\u{FEFF}hooks.zapier.com", 'hooks.zapier.com' ),
			'variation selector'       => array( "hooks\u{FE0F}.zapier.com", 'hooks.zapier.com' ),
			'ascii unchanged'          => array( 'hooks.zapier.com/x', 'hooks.zapier.com/x' ),
			'uppercase ascii kept'     => array( 'HOOKS.zapier.com', 'HOOKS.zapier.com' ),
		);
	}

	/** @dataProvider unchanged */
	public function test_code_points_a_parser_keeps_are_not_mapped( string $in ): void {
		$this->assertSame( $in, Aura_Worker_Redact_Idna::map( $in ) );
	}

	public static function unchanged(): array {
		return array(
			'sharp s (deviation)'       => array( "stra\u{00DF}e.example" ),
			'zwj (deviation)'           => array( "hooks\u{200D}.zapier.com" ),
			'zwnj (deviation)'          => array( "hooks\u{200C}.zapier.com" ),
			'fullwidth solidus'         => array( "hooks.zapier.com\u{FF0F}x" ),
			'cyrillic shha'             => array( "\u{04BB}ooks.zapier.com" ),
			'hebrew'                    => array( "\u{05E9}\u{05DC}\u{05D5}\u{05DD}/x" ),
			'arabic with digits'        => array( "\u{0645}\u{0631}\u{062D}\u{0628}\u{0627} \u{0661}\u{0662}/x" ),
			'combining mark'            => array( "h\u{0301}ooks.zapier.com" ),
			'one leader (disallowed)'   => array( "hooks\u{2024}zapier.com" ),
			// U+2488 DIGIT ONE FULL STOP: the pinned table (verified against its
			// pinned SHA-256) lists 2488..249B as plain `disallowed` with no
			// mapping field, not `mapped`/`disallowed_STD3_mapped` to '1.' as the
			// design spec's §3.2 example assumed — so it is excluded from MAP by
			// the same inclusion rule as any other disallowed code point.
			'digit one full stop (disallowed)' => array( "\u{2488}" ),
		);
	}

	public function test_invalid_utf8_is_kept_around_a_mapped_character(): void {
		$this->assertSame( "\xFFh\xFE", Aura_Worker_Redact_Idna::map( "\xFF\u{FF48}\xFE" ) );
	}

	public function test_same_string_is_returned_when_nothing_maps(): void {
		$in = "\u{05E9}\u{05DC}\u{05D5}\u{05DD} hooks.zapier.com/x";
		$this->assertSame( $in, Aura_Worker_Redact_Idna::map( $in ) );
	}

	public function test_table_shape(): void {
		$map     = Aura_Worker_Redact_Idna::MAP;
		$single  = 0;
		$multi   = 0;
		$deleted = 0;
		$lead    = array();
		foreach ( $map as $key => $target ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9.-]{0,4}$/', $target, bin2hex( $key ) );
			$this->assertGreaterThanOrEqual( 0xC2, ord( $key[0] ), bin2hex( $key ) );
			$this->assertSame( 1, preg_match( '/^[\xC2-\xF4][\x80-\xBF]{1,3}$/', $key ), bin2hex( $key ) );
			$this->assertSame( 1, preg_match( '/^.$/su', $key ), 'one code point: ' . bin2hex( $key ) );
			$lead[ $key[0] ] = true;
			if ( '' === $target ) {
				++$deleted;
			} elseif ( 1 === strlen( $target ) ) {
				++$single;
			} else {
				++$multi;
			}
		}
		$this->assertSame( 1043, $single );
		$this->assertSame( 189, $multi );
		$this->assertSame( 294, $deleted );
		$this->assertCount( 1526, $map );
		$this->assertSame( implode( '', array_keys( $lead ) ), Aura_Worker_Redact_Idna::LEAD );
	}

	public function test_hebrew_and_arabic_lead_bytes_are_not_in_lead(): void {
		foreach ( array( "\xD6", "\xD7", "\xD8", "\xD9", "\xDA", "\xDB" ) as $byte ) {
			$this->assertFalse( strpos( Aura_Worker_Redact_Idna::LEAD, $byte ), bin2hex( $byte ) );
		}
	}

	public function test_header_names_the_pinned_source(): void {
		$src = file_get_contents( SA_PLUGIN_DIR . '/includes/class-aura-worker-redact-idna.php' );
		$this->assertStringContainsString( 'Unicode 18.0.0', $src );
		$this->assertStringContainsString( 'a03b1eb38032268c696406a83f0972d6a815acd2c8d4151d42ec0fda70ffced1', $src );
		$this->assertStringContainsString( 'GENERATED', $src );
	}
}
