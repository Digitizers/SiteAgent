<?php
/**
 * SiteAgent #110 item 1: a receiver URL whose structural slashes (and the
 * scheme's colon) are percent-encoded or HTML-escaped is still a receiver
 * URL. `hook.eu2.make.com%2Fabc` is what a URL passed as a query parameter
 * looks like, and `hooks.zapier.com&#x2F;hooks…` is what an HTML-escaped
 * attribute looks like: both used to leave the secret path verbatim.
 *
 * Double encoding (`%252F`, `&amp;#x2F;`) is stage 2's (#113): see
 * RedactEncodedRunTest, and closed_limits() below.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactEncodedSlashTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	private function text( string $in, ?int &$count = null ): string {
		$count = 0;
		return Aura_Worker_Redact::redact_text( $in, $count );
	}

	/** @return array<string,array{0:string,1:string,2:string}> url, kind, the secret part */
	private static function urls(): array {
		return array(
			'make'           => array( 'https://hook.eu2.make.com/abc123secret', 'make', 'abc123secret' ),
			'zapier'         => array( 'https://hooks.zapier.com/hooks/catch/1/zsecret9', 'zapier', 'zsecret9' ),
			'slack'          => array( 'https://hooks.slack.com/services/T000/B000/SLACKSECRET', 'slack', 'SLACKSECRET' ),
			'discord'        => array( 'https://discord.com/api/webhooks/123/dsecret-tok', 'discord', 'dsecret-tok' ),
			'discord v10'    => array( 'https://discord.com/api/v10/webhooks/123/dsecret-tok', 'discord', 'dsecret-tok' ),
			'integromat'     => array( 'https://hook.integromat.com/isecret1', 'integromat', 'isecret1' ),
			'ifttt trigger'  => array( 'https://maker.ifttt.com/trigger/ev/with/key/ksecret_1', 'ifttt', 'ksecret_1' ),
			'telegram'       => array( 'https://api.telegram.org/bot123456:AAsecret_x/sendMessage', 'telegram', 'AAsecret_x' ),
			'telegram bare'  => array( 'https://api.telegram.org/bot123456:AAsecret_x', 'telegram', 'AAsecret_x' ),
			'zapier port'    => array( 'https://hooks.zapier.com:443/hooks/catch/1/zsecret9', 'zapier', 'zsecret9' ),
		);
	}

	/**
	 * Every encoding of the structural `/` (and of the scheme's `:`), for
	 * every receiver.
	 *
	 * @return array<string,array{0:string,1:string,2:string}> encoded, kind, secret
	 */
	public static function encoded(): array {
		$encodings = array(
			// A URL passed as a query parameter: encodeURIComponent / rawurlencode.
			'rawurlencode'      => static function ( $u ) {
				return rawurlencode( $u );
			},
			'rawurlencode lower' => static function ( $u ) {
				return preg_replace_callback(
					'/%[0-9A-F]{2}/',
					static function ( $m ) {
						return strtolower( $m[0] );
					},
					rawurlencode( $u )
				);
			},
			'pct slash only'    => static function ( $u ) {
				return str_replace( '/', '%2F', $u );
			},
			'pct slash lower bare' => static function ( $u ) {
				return str_replace( '/', '%2f', substr( $u, strlen( 'https://' ) ) );
			},
			'pct protocol-relative' => static function ( $u ) {
				return str_replace( '/', '%2F', substr( $u, strlen( 'https:' ) ) );
			},
			'html hex'          => static function ( $u ) {
				return str_replace( '/', '&#x2F;', $u );
			},
			'html hex lower'    => static function ( $u ) {
				return str_replace( '/', '&#x2f;', $u );
			},
			'html hex padded'   => static function ( $u ) {
				return str_replace( '/', '&#x002F;', $u );
			},
			'html decimal'      => static function ( $u ) {
				return str_replace( '/', '&#47;', $u );
			},
			'html named'        => static function ( $u ) {
				return str_replace( '/', '&sol;', $u );
			},
			'html bare'         => static function ( $u ) {
				return str_replace( '/', '&#x2F;', substr( $u, strlen( 'https://' ) ) );
			},
			'html colon 58'     => static function ( $u ) {
				return str_replace( array( ':', '/' ), array( '&#58;', '&#x2F;' ), $u );
			},
			'html colon named'  => static function ( $u ) {
				return str_replace( array( ':', '/' ), array( '&colon;', '&sol;' ), $u );
			},
			'mixed'             => static function ( $u ) {
				return preg_replace( '~^https://~', 'https:%2F&#x2F;', $u );
			},
		);
		$cases = array();
		foreach ( self::urls() as $name => $url ) {
			foreach ( $encodings as $label => $encode ) {
				$cases[ "{$name} / {$label}" ] = array( $encode( $url[0] ), $url[1], $url[2] );
			}
		}
		return $cases;
	}

	/** @dataProvider encoded */
	public function test_an_encoded_receiver_url_is_replaced_whole( string $encoded, string $kind, string $secret ): void {
		$this->assertStringNotContainsString( 'https://', $encoded, 'fixture: the slashes are encoded' );
		$this->assertSame( 'aura-redacted:v1:' . $kind, $this->text( $encoded, $n ), $encoded );
		$this->assertSame( 1, $n );
	}

	/** @dataProvider encoded */
	public function test_an_encoded_receiver_url_is_replaced_inside_text( string $encoded, string $kind, string $secret ): void {
		$out = $this->text( "see {$encoded} now", $n );
		$this->assertSame( "see aura-redacted:v1:{$kind} now", $out );
		$this->assertStringNotContainsString( $secret, $out );
		$this->assertSame( 1, $n );
	}

	/** @dataProvider encoded */
	public function test_an_encoded_receiver_url_is_replaced_as_a_query_parameter( string $encoded, string $kind, string $secret ): void {
		$out = $this->text( "https://example.com/login?redirect={$encoded}", $n );
		$this->assertSame( "https://example.com/login?redirect=aura-redacted:v1:{$kind}", $out );
		$this->assertStringNotContainsString( $secret, $out );
	}

	public function test_an_encoded_url_in_an_html_attribute_keeps_the_attribute(): void {
		$in  = '<a href="https:&#x2F;&#x2F;hooks.zapier.com&#x2F;hooks&#x2F;catch&#x2F;1&#x2F;x">go</a>';
		$out = $this->text( $in, $n );
		$this->assertSame( '<a href="aura-redacted:v1:zapier">go</a>', $out );
		$this->assertSame( 1, $n );
	}

	public function test_an_entity_closing_the_url_is_part_of_it_not_trailing_punctuation(): void {
		$this->assertSame( 'aura-redacted:v1:zapier', $this->text( 'hooks.zapier.com&#x2F;hooks&#x2F;catch&#x2F;1&#x2F;' ) );
		$this->assertSame( 'aura-redacted:v1:zapier', $this->text( 'hooks.zapier.com&sol;hooks&sol;catch&sol;1&sol;' ) );
		$this->assertSame( 'aura-redacted:v1:zapier', $this->text( 'hooks.zapier.com&#47;hooks&#47;catch&#47;1&#47;' ) );
		$this->assertSame( 'go aura-redacted:v1:zapier.', $this->text( 'go hooks.zapier.com&#x2F;hooks&#x2F;catch&#x2F;1&#x2F;.' ) );
	}

	/**
	 * The host boundary: a host right after an encoded slash (or an encoded
	 * `@`, `=`, …) is at a boundary, exactly as it is after the plain
	 * character — the preceding `F` / `0` / `D` is part of the escape.
	 */
	public function test_a_host_after_an_encoded_separator_is_at_a_boundary(): void {
		$out = $this->text( 'abc%2F%2Fhook.eu2.make.com%2Fabc123secret', $n );
		$this->assertSame( 'abc%2F%2Faura-redacted:v1:make', $out );
		$this->assertSame( 1, $n );

		$out = $this->text( 'abc&#x2F;&#x2F;hook.eu2.make.com&#x2F;abc123secret' );
		$this->assertSame( 'abc&#x2F;&#x2F;aura-redacted:v1:make', $out );

		$out = $this->text( 'next%3Dhttps%3A%2F%2Fhook.eu2.make.com%2Fabc123secret' );
		$this->assertSame( 'next%3Daura-redacted:v1:make', $out, 'an encoded `=` before the scheme is a boundary too' );

		$out = $this->text( 'user%40hook.eu2.make.com%2Fabc123secret' );
		$this->assertSame( 'user%40aura-redacted:v1:make', $out, 'as `user@hook.eu2.make.com/…` is' );
	}

	public function test_encoded_userinfo_is_part_of_the_url(): void {
		$out = $this->text( 'https%3A%2F%2Fuser%3Apw%40hook.eu2.make.com%2Fabc123secret', $n );
		$this->assertSame( 'aura-redacted:v1:make', $out );
		$this->assertSame( 1, $n );
	}

	public function test_an_encoded_path_boundary_behaves_like_the_plain_one(): void {
		// The existing rule: `example.com/hook.eu2.make.com/abc` redacts the
		// hook part (a `/` is a boundary). The encoded form does the same.
		$this->assertSame( 'example.com/aura-redacted:v1:make', $this->text( 'example.com/hook.eu2.make.com/abc123secret' ) );
		$this->assertSame( 'example.com%2Faura-redacted:v1:make', $this->text( 'example.com%2Fhook.eu2.make.com%2Fabc123secret' ) );
	}

	/** @return array<string,array{0:string}> */
	public static function negatives(): array {
		return array(
			'encoded non-webhook'        => array( rawurlencode( 'https://n8n.example.com/webhook/abc' ) ),
			'html non-webhook'           => array( 'https:&#x2F;&#x2F;n8n.example.com&#x2F;webhook&#x2F;abc' ),
			'encoded marketing page'     => array( rawurlencode( 'https://www.make.com/en/pricing' ) ),
			'no path after the host'     => array( 'example.com%2Fhook.eu2.make.com' ),
			'prefixed host'              => array( 'myhook.eu2.make.com%2Fabc123secret' ),
			'encoded-dot prefixed host'  => array( 'evil%2Ehook.eu2.make.com%2Fabc123secret' ),
			'encoded-dash prefixed host' => array( 'evil%2dhooks.zapier.com%2Fhooks%2Fcatch%2F1' ),
			'encoded-digit prefix'       => array( 'x%31hooks.zapier.com%2Fhooks%2Fcatch%2F1' ),
			'encoded suffixed host'      => array( rawurlencode( 'https://hooks.zapier.com.evil.tld/hooks/catch/1/' ) ),
			'encoded userinfo decoy'     => array( rawurlencode( 'https://hooks.zapier.com@evil.tld/hooks/catch/1/' ) ),
			'encoded slack non-hook'     => array( rawurlencode( 'https://hooks.slack.com/help/articles/1' ) ),
			'encoded discord non-hook'   => array( rawurlencode( 'https://discord.com/api/v10/channels/1/messages' ) ),
			'html slack non-hook'        => array( 'https:&#x2F;&#x2F;hooks.slack.com&#x2F;other&#x2F;T000' ),
			'host then an other entity'  => array( 'hooks.zapier.com&amp;hooks&#x2F;catch' ),
		);
	}

	/** @dataProvider negatives */
	public function test_non_receivers_stay_untouched( string $text ): void {
		$this->assertSame( $text, $this->text( $text, $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_a_string_whose_only_slashes_are_encoded_is_not_fast_rejected(): void {
		foreach ( array( 'hook.eu2.make.com%2fabc', 'hook.eu2.make.com%2Fabc', 'hook.eu2.make.com&#47;abc', 'hook.eu2.make.com&SOL;abc' ) as $in ) {
			$this->assertStringNotContainsString( '/', $in, 'fixture' );
			$this->assertSame( 'aura-redacted:v1:make', $this->text( $in ), $in );
		}
	}

	public function test_a_long_encoded_near_miss_does_not_time_out(): void {
		$text  = str_repeat( 'x%2Fxhooks.zapier.com%2Fa evil%2Ehooks.zapier.com%2Fa &#x2F;myhook.eu2.make.com&#47; ', 20000 );
		$start = microtime( true );
		$out   = $this->text( $text, $n );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
		$this->assertSame( $text, $out );
		$this->assertSame( 0, $n );
	}

	/**
	 * The encoded userinfo must not cost PCRE a stack frame per character: a
	 * long run after a scheme would exhaust the JIT stack and fail the whole
	 * string closed (a regression caught while building #110).
	 */
	public function test_a_long_run_after_a_scheme_is_not_failed_closed(): void {
		foreach ( array( 'https://', 'https%3A%2F%2F', 'https:&#x2F;&#x2F;', '//' ) as $scheme ) {
			foreach ( array( str_repeat( 'a', 100000 ), str_repeat( 'a.', 50000 ), str_repeat( 'a%20', 30000 ), str_repeat( 'a&amp;', 20000 ) ) as $run ) {
				$in = $scheme . $run;
				$this->assertSame( $in, $this->text( $in, $n ), $scheme );
				$this->assertSame( 0, $n );
			}
		}
		$long = 'https%3A%2F%2F' . str_repeat( 'u', 100000 ) . '%40hook.eu2.make.com%2Fabc123secret';
		$this->assertSame( 'aura-redacted:v1:make', $this->text( $long ) );
	}

	// --- fix round 1 ------------------------------------------------------

	/** @return array<string,array{0:string,1:string}> input, expected */
	public static function non_ascii_boundaries(): array {
		return array(
			'curly quotes'   => array( '%E2%80%9Chooks.zapier.com%2Fhooks%2Fcatch%2F1%2FSECRET', '%E2%80%9Caura-redacted:v1:zapier' ),
			'guillemets'     => array( 'q=%C2%ABhook.eu2.make.com%2Fsecret%C2%BB', 'q=%C2%ABaura-redacted:v1:make' ),
			'nbsp'           => array( '%C2%A0hooks.zapier.com%2Fhooks%2FSECRET', '%C2%A0aura-redacted:v1:zapier' ),
			'accented word'  => array( 'caf%C3%A9hooks.zapier.com%2Fx', 'caf%C3%A9aura-redacted:v1:zapier' ),
			'lower-case hex' => array( '%e2%80%9chooks.zapier.com%2fhooks%2fSECRET', '%e2%80%9caura-redacted:v1:zapier' ),
		);
	}

	/**
	 * I1: a percent-escaped non-ASCII byte is not a hostname character, so
	 * it is a host boundary.
	 *
	 * @dataProvider non_ascii_boundaries
	 */
	public function test_a_percent_escaped_non_ascii_byte_is_a_host_boundary( string $in, string $expected ): void {
		$this->assertSame( $expected, $this->text( $in, $n ) );
		$this->assertSame( 1, $n );
	}

	/** @return array<string,array{0:string,1:string}> input, expected */
	public static function unterminated_references(): array {
		return array(
			'decimal'              => array( 'hooks.zapier.com&#47hooks&#47catch&#47SECRET', 'aura-redacted:v1:zapier' ),
			'hex after host'       => array( 'hooks.zapier.com&#x2Fhooks/catch', 'aura-redacted:v1:zapier' ),
			'padded decimal'       => array( 'hook.eu2.make.com&#047SECRET', 'aura-redacted:v1:make' ),
			'scheme'               => array( 'https:&#47;&#47hooks.zapier.com/x', 'aura-redacted:v1:zapier' ),
			'boundary decimal'     => array( 'x&#47&#47hooks.zapier.com/SECRET', 'x&#47&#47aura-redacted:v1:zapier' ),
			'boundary hex'         => array( 'x&#x2F&#x2Fhooks.zapier.com/SECRET', 'x&#x2F&#x2Faura-redacted:v1:zapier' ),
			'boundary one decimal' => array( 'go&#47hooks.zapier.com&#47SECRET', 'go&#47aura-redacted:v1:zapier' ),
		);
	}

	/**
	 * M1: HTML5 decodes a numeric reference without its `;` (the hex form
	 * only when no hex digit follows).
	 *
	 * @dataProvider unterminated_references
	 */
	public function test_an_unterminated_numeric_slash_reference_is_a_slash( string $in, string $expected ): void {
		$this->assertSame( $expected, $this->text( $in, $n ) );
		$this->assertSame( 1, $n );
	}

	/** @return array<string,array{0:string}> */
	public static function round1_negatives(): array {
		return array(
			'encoded dot prefix'     => array( 'evil%2Ehook.eu2.make.com%2Fx' ),
			'encoded digit prefix'   => array( 'x%31hooks.zapier.com%2Fx' ),
			'decimal 475 is no slash' => array( 'hooks.zapier.com&#475hooks' ),
			'hex 2fa is no slash'     => array( 'hooks.zapier.com&#x2Fahooks' ),
			'hex 2fd before discord'  => array( 'x&#x2Fdiscord.com/api/webhooks/1/SECRET' ),
		);
	}

	/** @dataProvider round1_negatives */
	public function test_round1_controls_stay_untouched( string $in ): void {
		$this->assertSame( $in, $this->text( $in, $n ) );
		$this->assertSame( 0, $n );
	}

	/** M3: a padded entity closing the URL keeps its `;` inside the URL. */
	public function test_a_padded_entity_closing_the_url_is_not_trailing_punctuation(): void {
		$this->assertSame( 'x aura-redacted:v1:zapier', $this->text( 'x hooks.zapier.com&#x002F;SECRET&#x002F;' ) );
		$this->assertSame( 'x aura-redacted:v1:zapier', $this->text( 'x hooks.zapier.com&#0047;SECRET&#0047;' ) );
		$this->assertSame( 'x aura-redacted:v1:zapier.', $this->text( 'x hooks.zapier.com&#x002F;SECRET&#x002F;.' ) );
		$this->assertSame( 'x aura-redacted:v1:zapier;', $this->text( 'x hooks.zapier.com/SECRET;' ), 'a plain `;` is still punctuation' );
	}

	/** @return array<string,array{0:bool}> */
	public static function jit_modes(): array {
		return array(
			'jit on'  => array( true ),
			'jit off' => array( false ),
		);
	}

	/**
	 * M2: the IFTTT event segment must not span an encoded slash, nor
	 * backtrack over one.
	 *
	 * @dataProvider jit_modes
	 */
	public function test_long_ifttt_trigger_runs_do_not_fail_closed( bool $jit ): void {
		$old = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', $jit ? '1' : '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			$cases = array(
				'maker.ifttt.com%2Ftrigger%2F' . str_repeat( 'a%2F', 90000 ),
				str_repeat( 'maker.ifttt.com%2Ftrigger%2Fa', 30000 ),
				str_repeat( 'maker.ifttt.com&#x2F;trigger&#x2F;a ', 30000 ),
			);
			foreach ( $cases as $i => $in ) {
				$start = microtime( true );
				$this->assertSame( $in, $this->text( $in, $n ), "case {$i}" );
				$this->assertSame( 0, $n );
				$this->assertLessThan( 2.0, microtime( true ) - $start );
			}
			$hit = 'maker.ifttt.com%2Ftrigger%2F' . str_repeat( 'a%2F', 90000 ) . 'ev%2Fwith%2Fkey%2FSECRET';
			$this->assertSame( 'maker.ifttt.com%2Ftrigger%2F' . str_repeat( 'a%2F', 90000 ) . 'ev%2Fwith%2Fkey%2FSECRET', $this->text( $hit, $n ), 'a trigger path with extra segments is not the IFTTT shape' );
			$real = str_repeat( 'x ', 30000 ) . 'maker.ifttt.com%2Ftrigger%2Fev%2Fwith%2Fkey%2FSECRET';
			$this->assertSame( str_repeat( 'x ', 30000 ) . 'aura-redacted:v1:ifttt', $this->text( $real, $n ) );
			$this->assertSame( 1, $n );
		} finally {
			ini_set( 'pcre.jit', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
	}

	/** M7: typographic entities do not defeat the fast reject; encoded slashes do. */
	public function test_the_fast_reject_markers(): void {
		$m = new ReflectionMethod( Aura_Worker_Redact::class, 'may_hold_encoded_slash' );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		foreach ( array( 'it&#8217;s', 'a &amp; b', '&#8220;quoted&#8221;', '100%', '&#x2019;' ) as $no ) {
			$this->assertFalse( $m->invoke( null, $no ), $no );
		}
		foreach ( array( '%2F', '%2f', '&sol;', '&SOL;', '&#47', '&#047;', '&#x2F', '&#X002f;' ) as $yes ) {
			$this->assertTrue( $m->invoke( null, "x{$yes}y" ), $yes );
		}
	}

	// --- Codex round 1 (PR #112): semicolonless `@` references ------------

	/** @return array<string,array{0:string,1:string}> input, expected */
	public static function unterminated_at_references(): array {
		$cases = array();
		$urls  = array(
			'make'          => array( 'hook.eu2.make.com/secret', 'make' ),
			'zapier'        => array( 'hooks.zapier.com/hooks/catch/1/SECRET', 'zapier' ),
			'slack'         => array( 'hooks.slack.com/services/T/B/SECRET', 'slack' ),
			'discord'       => array( 'discord.com/api/webhooks/1/SECRET', 'discord' ),
			'canary'        => array( 'canary.discord.com/api/webhooks/1/SECRET', 'discord' ),
			'telegram'      => array( 'api.telegram.org/bot1:AASECRET/x', 'telegram' ),
			'ifttt'         => array( 'maker.ifttt.com/use/SECRET', 'ifttt' ),
		);
		foreach ( $urls as $name => $url ) {
			foreach ( array( '&#64', '&#064', '&#0064', '&#64;' ) as $at ) {
				$cases[ "{$name} {$at}" ] = array( "https://user{$at}{$url[0]}", 'aura-redacted:v1:' . $url[1] );
			}
			$cases[ "{$name} bare {$at}" ] = array( "user&#64{$url[0]}", 'user&#64aura-redacted:v1:' . $url[1] );
			$cases[ "{$name} hex;" ]       = array( "https://user&#x40;{$url[0]}", 'aura-redacted:v1:' . $url[1] );
		}
		// Hex without `;` is decoded only when no hex digit follows: a host
		// starting with a letter past `f`.
		foreach ( array( 'make', 'zapier', 'slack', 'ifttt' ) as $name ) {
			$cases[ "{$name} hex" ]        = array( "https://user&#x40{$urls[ $name ][0]}", 'aura-redacted:v1:' . $urls[ $name ][1] );
			$cases[ "{$name} hex padded" ] = array( "https://user&#x040{$urls[ $name ][0]}", 'aura-redacted:v1:' . $urls[ $name ][1] );
			$cases[ "{$name} hex bare" ]   = array( "user&#x40{$urls[ $name ][0]}", 'user&#x40aura-redacted:v1:' . $urls[ $name ][1] );
		}
		$cases['encoded scheme'] = array( 'https%3A%2F%2Fuser&#64hook.eu2.make.com%2Fsecret', 'aura-redacted:v1:make' );
		return $cases;
	}

	/** @dataProvider unterminated_at_references */
	public function test_an_unterminated_at_reference_ends_the_userinfo( string $in, string $expected ): void {
		$this->assertSame( $expected, $this->text( $in, $n ), $in );
		$this->assertSame( 1, $n );
	}

	/** @return array<string,array{0:string}> */
	public static function unterminated_at_negatives(): array {
		return array(
			// `&#x40d…` is U+040D to HTML5, not `@` + `d…`: no receiver there.
			'hex before discord'  => array( 'https://user&#x40discord.com/api/webhooks/1/SECRET' ),
			'hex before canary'   => array( 'https://user&#x40canary.discord.com/api/webhooks/1/SECRET' ),
			'hex before api'      => array( 'https://user&#x40api.telegram.org/bot1:AASECRET/x' ),
			'bare hex before api' => array( 'user&#x40api.telegram.org/bot1:AASECRET/x' ),
			'decoy after at'      => array( 'https://hooks.zapier.com&#64evil.tld/hooks/catch/1/' ),
			'hex decoy after at'  => array( 'https://hooks.zapier.com&#x40evil.tld/hooks/catch/1/' ),
		);
	}

	/** @dataProvider unterminated_at_negatives */
	public function test_unterminated_at_controls_stay_untouched( string $in ): void {
		$this->assertSame( $in, $this->text( $in, $n ) );
		$this->assertSame( 0, $n );
	}

	// --- #113: controls that only recorded a limit stage 2 closes ---------

	/**
	 * Each reference decodes to a non-ASCII character (U+01D6, U+0280,
	 * U+1900) — not a hostname character, so the host after it is at a
	 * boundary, exactly as in plain text; the double-encoded URL decodes to
	 * a plain receiver URL. Stage 2 replaces the whole run.
	 *
	 * @return array<string,array{0:string,1:string}> input, expected
	 */
	public static function closed_limits(): array {
		return array(
			'double encoded'          => array( 'https%253A%252F%252Fhook.eu2.make.com%252Fabc123secret', 'aura-redacted:v1:make' ),
			'decimal 470 before host' => array( 'x&#470hooks.zapier.com/SECRET', 'aura-redacted:v1:zapier' ),
			'decimal 640'             => array( 'user&#640hooks.zapier.com/hooks/SECRET', 'aura-redacted:v1:zapier' ),
			'decimal 6400 scheme'     => array( 'https://user&#6400hooks.zapier.com/hooks/SECRET', 'aura-redacted:v1:zapier' ),
		);
	}

	/** @dataProvider closed_limits */
	public function test_controls_that_recorded_a_decode_limit_are_now_redacted( string $in, string $expected ): void {
		$this->assertSame( $expected, $this->text( $in, $n ) );
		$this->assertSame( 1, $n );
	}

	// --- carriers ---------------------------------------------------------

	public function test_an_encoded_url_inside_elementor_data_is_redacted_and_the_json_round_trips(): void {
		$tree = array(
			array(
				'id'         => 'w1',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array(
					'editor' => '<p><a href="https:&#x2F;&#x2F;hooks.zapier.com&#x2F;hooks&#x2F;catch&#x2F;1&#x2F;zsecret9">x</a></p>',
					'link'   => array( 'url' => 'https://example.com/go?to=' . rawurlencode( 'https://hook.eu2.make.com/abc123secret' ) ),
					'empty'  => new stdClass(),
				),
				'elements'   => array(),
			),
		);
		$data = wp_json_encode( $tree );
		$post = array( 'id' => 7, 'meta' => array( '_elementor_data' => $data ) );

		$out = Aura_Worker_Redact::redact( $post, $n );

		$this->assertSame( 2, $n );
		$json = $out['meta']['_elementor_data'];
		$this->assertIsString( $json );
		$this->assertStringNotContainsString( 'zsecret9', $json );
		$this->assertStringNotContainsString( 'abc123secret', $json );
		$decoded = json_decode( $json, true );
		$this->assertSame( '<p><a href="aura-redacted:v1:zapier">x</a></p>', $decoded[0]['settings']['editor'] );
		$this->assertSame( 'https://example.com/go?to=aura-redacted:v1:make', $decoded[0]['settings']['link']['url'] );
		$this->assertStringContainsString( '"empty":{}', $json );
	}

	public function test_an_encoded_url_in_an_mcp_text_carrier_is_redacted(): void {
		$inner  = wp_json_encode( array( 'href' => 'hooks.slack.com%2Fservices%2FT0%2FB0%2FSLACKSECRET' ) );
		$result = array( 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => $inner ) ) ) );

		$out = Aura_Worker_Redact::redact( $result, $n );

		$this->assertSame( 1, $n );
		$this->assertSame( array( 'href' => 'aura-redacted:v1:slack' ), json_decode( $out['result']['content'][0]['text'], true ) );
	}

	// --- the write guard is unaffected -------------------------------------

	public function test_the_write_guard_sees_the_redacted_value_and_ignores_the_encoded_original(): void {
		$encoded = rawurlencode( 'https://discord.com/api/webhooks/1/dsecret-tok' );
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( array( 'url' => $encoded ) ), 'an encoded URL is not a placeholder' );

		$redacted = Aura_Worker_Redact::redact( array( 'url' => $encoded ), $n );
		$this->assertSame( 1, $n );
		$this->assertTrue( Aura_Worker_Redact::holds_placeholder( $redacted ), 'writing the redacted value back is refused' );
	}
}
