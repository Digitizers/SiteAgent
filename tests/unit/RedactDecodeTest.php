<?php
/**
 * SiteAgent #113: Aura_Worker_Redact_Decode::decode_run() on its own —
 * each decoder, the pass bound, the growth bound.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactDecodeTest extends TestCase {

	private static function u( int $cp ): string {
		return Aura_Worker_Redact_Decode::utf8( $cp );
	}

	/** @return array<string,array{0:string,1:string}> input, one-run decode */
	public static function numeric(): array {
		return array(
			'decimal ;'              => array( '&#47;', '/' ),
			'decimal no ;'           => array( 'a&#47b', 'a/b' ),
			'hex ;'                  => array( '&#x2F;', '/' ),
			'hex upper X no ;'       => array( 'a&#X2fz', 'a/z' ),
			'longest hex run'        => array( '&#x2Fab', self::u( 0x2FAB ) ),
			'longest decimal run'    => array( '&#475x', self::u( 475 ) . 'x' ),
			'leading zeros'          => array( '&#0000047;', '/' ),
			'deep zero padding'      => array( '&#00000000000000000000047hooks', '/hooks' ),
			'deep hex zero padding'  => array( '&#x000000000000000040;', '@' ),
			'no digits after &#x'    => array( '&#x;', '&#x;' ),
			'no digits after &#'     => array( '&#;', '&#;' ),
			'bare &#x'               => array( 'a&#xyz', 'a&#xyz' ),
			'bare &#'                => array( 'a&#', 'a&#' ),
			'at, kses form'          => array( '&amp;#64', '@' ),
			'at, wptexturize form'   => array( '&#038;#64', '@' ),
			'host letter'            => array( '&#104;ooks.zapier.com', 'hooks.zapier.com' ),
			'host dot, no ;'         => array( 'hooks&#46zapier.com', 'hooks.zapier.com' ),
			'max code point'         => array( '&#x10FFFF;', self::u( 0x10FFFF ) ),
		);
	}

	/** @dataProvider numeric */
	public function test_numeric_references( string $in, string $expected ): void {
		$this->assertSame( $expected, Aura_Worker_Redact_Decode::decode_run( $in ) );
	}

	/** @return array<string,array{0:string}> */
	public static function invalid_numeric(): array {
		return array(
			'zero'              => array( '&#0;' ),
			'zero no ;'         => array( '&#0' ),
			'zero hex'          => array( '&#x0;' ),
			'high surrogate'    => array( '&#xD800;' ),
			'low surrogate dec' => array( '&#57343' ),
			'above max'         => array( '&#x110000;' ),
			'above max dec'     => array( '&#1114112;' ),
			'huge'              => array( '&#99999999999999999999999999;' ),
			'huge hex'          => array( '&#xFFFFFFFFFFFFFFFFFFFFFFFF;' ),
		);
	}

	/** @dataProvider invalid_numeric */
	public function test_an_invalid_numeric_value_is_the_replacement_character( string $in ): void {
		$this->assertSame( "\xEF\xBF\xBD", Aura_Worker_Redact_Decode::decode_run( $in ) );
	}

	public function test_a_zero_reference_is_a_boundary_before_the_host(): void {
		$this->assertSame( "\xEF\xBF\xBDhooks.zapier.com/x", Aura_Worker_Redact_Decode::decode_run( '&#0hooks.zapier.com/x' ) );
	}

	public function test_c1_values_follow_the_windows_1252_table(): void {
		$table = array(
			0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020,
			0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
			0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022,
			0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
			0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
		);
		$this->assertCount( 27, $table );
		for ( $v = 0x80; $v <= 0x9F; ++$v ) {
			$expected = self::u( isset( $table[ $v ] ) ? $table[ $v ] : $v );
			$this->assertSame( $expected, Aura_Worker_Redact_Decode::decode_run( '&#' . $v . ';' ), "decimal {$v}" );
			$this->assertSame( $expected, Aura_Worker_Redact_Decode::decode_run( '&#x' . dechex( $v ) ), 'hex ' . dechex( $v ) );
		}
	}

	public function test_utf8_matches_json_decode(): void {
		foreach ( array( 0x24, 0x7F, 0x80, 0xE9, 0x7FF, 0x800, 0x20AC, 0xFFFD, 0xFFFF, 0x10000, 0x1F600, 0x10FFFF ) as $cp ) {
			$units = $cp >= 0x10000
				? sprintf( '\\u%04x\\u%04x', 0xD800 + ( ( $cp - 0x10000 ) >> 10 ), 0xDC00 + ( ( $cp - 0x10000 ) & 0x3FF ) )
				: sprintf( '\\u%04x', $cp );
			$this->assertSame( json_decode( '"' . $units . '"' ), self::u( $cp ), dechex( $cp ) );
		}
	}

	/** @return array<string,array{0:string,1:string}> input, one-run decode */
	public static function named(): array {
		return array(
			'sol'                      => array( '&sol;', '/' ),
			'colon'                    => array( '&colon;', ':' ),
			'commat'                   => array( '&commat;', '@' ),
			'period'                   => array( 'hooks&period;zapier.com', 'hooks.zapier.com' ),
			'upper AMP'                => array( '&AMP;', '&' ),
			'nGt expands'              => array( '&nGt;', self::u( 0x226B ) . self::u( 0x20D2 ) ),
			'unknown name'             => array( '&bogus;', '&bogus;' ),
			'legacy amp, no ;'         => array( '&amphooks.zapier.com/x', '&hooks.zapier.com/x' ),
			'legacy amp then numeric'  => array( '&amp#104;ooks.zapier.com/x', 'hooks.zapier.com/x' ),
			'legacy longest match'     => array( '&notit;', self::u( 0xAC ) . 'it;' ),
			'full name wins over legacy' => array( '&notin;', self::u( 0x2209 ) ),
			'legacy, name continues'   => array( '&notin', self::u( 0xAC ) . 'in' ),
			'legacy frac12'            => array( '&frac12x', self::u( 0xBD ) . 'x' ),
			'legacy copy then digits'  => array( '&copy2026', self::u( 0xA9 ) . '2026' ),
			'legacy upper LT'          => array( 'a&LTb', 'a<b' ),
			'legacy, bad ; form'       => array( '&ampx;', '&x;' ),
			'non-legacy sol, no ;'     => array( '&sol', '&sol' ),
			'non-legacy solhooks'      => array( '&solhooks/x', '&solhooks/x' ),
			'non-legacy Lt, no ;'      => array( '&Lt', '&Lt' ),
			'non-legacy commat, no ;'  => array( 'user&commathook', 'user&commathook' ),
			'bare ampersand'           => array( 'a&b&', 'a&b&' ),
		);
	}

	/** @dataProvider named */
	public function test_named_references( string $in, string $expected ): void {
		$this->assertSame( $expected, Aura_Worker_Redact_Decode::decode_run( $in ) );
	}

	public function test_the_legacy_list_is_html5s_106_names_and_none_grows(): void {
		$names = Aura_Worker_Redact_Decode::LEGACY_NAMES;
		$this->assertCount( 106, $names );
		$this->assertSame( $names, array_values( array_unique( $names ) ) );
		$max = 0;
		foreach ( $names as $name ) {
			$ref   = '&' . $name . ';';
			$value = html_entity_decode( $ref, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$this->assertNotSame( $ref, $value, "{$name} is a named reference PHP knows" );
			$this->assertLessThanOrEqual( strlen( $name ) + 1, strlen( $value ), "&{$name} does not grow" );
			$this->assertSame( $value . 'z', Aura_Worker_Redact_Decode::decode_run( '&' . $name . 'z' ), "{$name} without ;" );
			$max = max( $max, strlen( $name ) );
		}
		$this->assertSame( Aura_Worker_Redact_Decode::LEGACY_MAX_LENGTH, $max );
	}

	/** @return array<string,array{0:string,1:string}> input, one-run decode */
	public static function percent(): array {
		return array(
			'slash'           => array( 'a%2Fb', 'a/b' ),
			'slash lower'     => array( 'a%2fb', 'a/b' ),
			'double'          => array( '%252F', '/' ),
			'utf-8'           => array( 'caf%C3%A9', "caf\xC3\xA9" ),
			'invalid'         => array( '%zz%2', '%zz%2' ),
			'trailing %'      => array( '100%', '100%' ),
			'plus stays'      => array( 'a+b', 'a+b' ),
			'encoded plus'    => array( 'a%2Bb', 'a+b' ),
			'host letter'     => array( '%68ooks.zapier.com', 'hooks.zapier.com' ),
			'host dot'        => array( 'hook%2Eeu2.make.com', 'hook.eu2.make.com' ),
		);
	}

	/** @dataProvider percent */
	public function test_percent_escapes( string $in, string $expected ): void {
		$this->assertSame( $expected, Aura_Worker_Redact_Decode::decode_run( $in ) );
	}

	/** @return array<string,array{0:string,1:string}> input, one-run decode */
	public static function json(): array {
		return array(
			'slash'                 => array( 'a\\u002fb', 'a/b' ),
			'slash upper'           => array( 'a\\u002Fb', 'a/b' ),
			'escaped slash'         => array( 'a\\/b', 'a/b' ),
			'two-byte'              => array( '\\u00e9', "\xC3\xA9" ),
			'surrogate pair'        => array( '\\ud83d\\ude00', "\xF0\x9F\x98\x80" ),
			'pair after a unit'     => array( '\\u0041\\uD83D\\uDE00', "A\xF0\x9F\x98\x80" ),
			'lone high'             => array( '\\ud83dx', '\\ud83dx' ),
			'lone low'              => array( '\\ude00x', '\\ude00x' ),
			'high then non-low'     => array( '\\ud83d\\u0041', '\\ud83dA' ),
			'short escape'          => array( '\\u12', '\\u12' ),
			'other escape'          => array( 'a\\nb', 'a\\nb' ),
		);
	}

	/** @dataProvider json */
	public function test_json_escapes( string $in, string $expected ): void {
		$this->assertSame( $expected, Aura_Worker_Redact_Decode::decode_run( $in ) );
	}

	public function test_a_fixed_point_is_returned_as_it_is(): void {
		foreach ( array( '', 'plain', 'hooks.zapier.com/x', "caf\xC3\xA9", '&bogus;' ) as $in ) {
			$this->assertSame( $in, Aura_Worker_Redact_Decode::decode_run( $in ) );
			$this->assertSame( $in, Aura_Worker_Redact_Decode::decode_pass( $in ) );
		}
	}

	/** @return array<string,array{0:string,1:?string}> input, decode */
	public static function layers(): array {
		return array(
			'pct 1'           => array( '%2F', '/' ),
			'pct 3'           => array( '%25252F', '/' ),
			'pct 4'           => array( '%2525252F', '/' ),
			'pct 5'           => array( '%252525252F', null ),
			'pct 6'           => array( '%25252525252F', null ),
			'html 4'          => array( '&amp;amp;amp;#47;', '/' ),
			'html 5'          => array( '&amp;amp;amp;amp;#47;', null ),
			'json then pct 2' => array( '\\u0025\\u0032F', '/' ),
			'mixed 3'         => array( '%26amp%3B%2523x2F%3B', '/' ),
		);
	}

	/** @dataProvider layers */
	public function test_up_to_four_layers_decode_and_a_fifth_is_refused( string $in, ?string $expected ): void {
		$this->assertSame( $expected, Aura_Worker_Redact_Decode::decode_run( $in ) );
	}

	/**
	 * Spec §3.1: one pass grows a value by at most 1.2× (`&nGt;`), so the
	 * decoded run never exceeds MAX_DECODE_GROWTH × the input.
	 */
	public function test_growth_is_bounded(): void {
		$tokens = array( '&nGt;', '&nLt;', 'nGt;', '&amp;', '&', '%26', '%25', '%2', '#', '&#38;', '&#x26;', '\\u0026', '\\', 'u0026', '&#0', '&#x110000', '\\ud83d\\ude00', 'a', '/', '%C3%A9', '&amp', ';' );
		mt_srand( 113 );
		$cases = array();
		for ( $k = 1; $k <= 60; ++$k ) {
			$cases[] = str_repeat( '&nGt;', $k );
			$cases[] = str_repeat( '&amp;nGt;', $k );
		}
		for ( $i = 0; $i < 3000; ++$i ) {
			$s = '';
			for ( $j = mt_rand( 1, 40 ); $j > 0; --$j ) {
				$s .= $tokens[ mt_rand( 0, count( $tokens ) - 1 ) ];
			}
			$cases[] = $s;
		}
		foreach ( $cases as $in ) {
			$pass = Aura_Worker_Redact_Decode::decode_pass( $in );
			$this->assertLessThanOrEqual( 1.2 * strlen( $in ), strlen( $pass ), "one pass: {$in}" );
			$out = Aura_Worker_Redact_Decode::decode_run( $in );
			if ( null !== $out ) {
				$this->assertLessThanOrEqual( Aura_Worker_Redact_Decode::MAX_DECODE_GROWTH * strlen( $in ), strlen( $out ), $in );
			}
		}
		$this->assertSame( str_repeat( self::u( 0x226B ) . self::u( 0x20D2 ), 60 ), Aura_Worker_Redact_Decode::decode_run( str_repeat( '&nGt;', 60 ) ) );
	}

	public function test_no_html5_named_reference_grows_by_more_than_a_fifth(): void {
		$worst = 0.0;
		foreach ( get_html_translation_table( HTML_ENTITIES, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) as $char => $ref ) {
			$worst = max( $worst, strlen( (string) $char ) / strlen( $ref ) );
		}
		$this->assertLessThanOrEqual( 1.2, $worst );
	}

	public function test_the_bounds(): void {
		$this->assertSame( 4, Aura_Worker_Redact_Decode::MAX_DECODE_PASSES );
		$this->assertSame( 3, Aura_Worker_Redact_Decode::MAX_DECODE_GROWTH );
	}
}
