<?php
/**
 * SiteAgent #110 item 1: a receiver URL whose structural slashes (and the
 * scheme's colon) are percent-encoded or HTML-escaped is still a receiver
 * URL. `hook.eu2.make.com%2Fabc` is what a URL passed as a query parameter
 * looks like, and `hooks.zapier.com&#x2F;hooks…` is what an HTML-escaped
 * attribute looks like: both used to leave the secret path verbatim.
 *
 * Double encoding (`%252F`, `&amp;#x2F;`) is out of scope.
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
			'double encoded (out of scope)' => array( 'https%253A%252F%252Fhook.eu2.make.com%252Fabc123secret' ),
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
