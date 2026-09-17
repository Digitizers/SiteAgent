<?php
/**
 * SiteAgent #113: stage 2 of redact_text() — decode each run, then match.
 * A run (a maximal stretch without whitespace, `"`, `'`, `<`, `>`) whose
 * decoded form holds a receiver URL is replaced whole; an encoded form is
 * redacted exactly when its decoded form would be.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactEncodedRunTest extends TestCase {

	/** name => [ host, path after the host's slash, kind, secret ] — one per URL_PATTERNS entry */
	private const RECEIVERS = array(
		'make'       => array( 'hook.eu2.make.com', 'abc123secret', 'make', 'abc123secret' ),
		'celonis'    => array( 'hook.eu1.make.celonis.com', 'cel123secret', 'make', 'cel123secret' ),
		'integromat' => array( 'hook.integromat.com', 'isecret1', 'integromat', 'isecret1' ),
		'zapier'     => array( 'hooks.zapier.com', 'hooks/catch/1/zsecret9', 'zapier', 'zsecret9' ),
		'slack'      => array( 'hooks.slack.com', 'services/T000/B000/SLACKSECRET', 'slack', 'SLACKSECRET' ),
		'discord'    => array( 'discord.com', 'api/webhooks/123/dsecret-tok', 'discord', 'dsecret-tok' ),
		'discordapp' => array( 'discordapp.com', 'api/v10/webhooks/123/dsecret-tok', 'discord', 'dsecret-tok' ),
		'ifttt'      => array( 'maker.ifttt.com', 'trigger/ev/with/key/ksecret_1', 'ifttt', 'ksecret_1' ),
		'telegram'   => array( 'api.telegram.org', 'bot123456:AAsecret_x/sendMessage', 'telegram', 'AAsecret_x' ),
	);

	protected function setUp(): void {
		sa_reset_state();
	}

	private function text( string $in, ?int &$count = null ): string {
		$count = 0;
		return Aura_Worker_Redact::redact_text( $in, $count );
	}

	/**
	 * Every encoding stage 1 does not know, as a function of host and path.
	 *
	 * @return array<string,callable>
	 */
	private static function encodings(): array {
		return array(
			'pct double'            => static function ( $h, $p ) {
				return rawurlencode( rawurlencode( "https://{$h}/{$p}" ) );
			},
			'pct triple'            => static function ( $h, $p ) {
				return rawurlencode( rawurlencode( rawurlencode( "https://{$h}/{$p}" ) ) );
			},
			'pct 252F'              => static function ( $h, $p ) {
				return str_replace( '/', '%252F', "https://{$h}/{$p}" );
			},
			'kses at'               => static function ( $h, $p ) {
				return "https://user&amp;#64{$h}/{$p}";
			},
			'wptexturize at'        => static function ( $h, $p ) {
				return "https://user&#038;#64{$h}/{$p}";
			},
			'html double slash'     => static function ( $h, $p ) {
				return str_replace( '/', '&amp;#x2F;', "https://{$h}/{$p}" );
			},
			'html then pct'         => static function ( $h, $p ) {
				return rawurlencode( str_replace( '/', '&#47;', "https://{$h}/{$p}" ) );
			},
			'pct host letter'       => static function ( $h, $p ) {
				return 'https://%' . strtoupper( bin2hex( $h[0] ) ) . substr( $h, 1 ) . "/{$p}";
			},
			'html host letter'      => static function ( $h, $p ) {
				return 'https://&#' . ord( $h[0] ) . ';' . substr( $h, 1 ) . "/{$p}";
			},
			'html host letter no ;' => static function ( $h, $p ) {
				return 'https://&#' . ord( $h[0] ) . substr( $h, 1 ) . "/{$p}";
			},
			'pct host dot'          => static function ( $h, $p ) {
				return 'https://' . preg_replace( '/\./', '%2E', $h, 1 ) . "/{$p}";
			},
			'html host dot no ;'    => static function ( $h, $p ) {
				return 'https://' . preg_replace( '/\./', '&#46', $h, 1 ) . "/{$p}";
			},
			'named host dot'        => static function ( $h, $p ) {
				return 'https://' . str_replace( '.', '&period;', $h ) . "/{$p}";
			},
			'pct path letter'       => static function ( $h, $p ) {
				return "https://{$h}/%" . strtoupper( bin2hex( $p[0] ) ) . substr( $p, 1 );
			},
			'html path letter'      => static function ( $h, $p ) {
				return "https://{$h}/" . preg_replace( '/e/', '&#x65;', $p, 1 );
			},
			'json u002f'            => static function ( $h, $p ) {
				return str_replace( '/', '\\u002f', "https://{$h}/{$p}" );
			},
			'json host letter'      => static function ( $h, $p ) {
				return '\\u00' . bin2hex( $h[0] ) . substr( $h, 1 ) . "\\/{$p}";
			},
			'bare pct host letter'  => static function ( $h, $p ) {
				return '%' . bin2hex( $h[0] ) . substr( $h, 1 ) . "%2F{$p}";
			},
			'deep zero host letter' => static function ( $h, $p ) {
				return 'https://&#0000000000000000' . ord( $h[0] ) . substr( $h, 1 ) . "&#x0000000002F;{$p}";
			},
			'zero boundary'         => static function ( $h, $p ) {
				return "x&#0{$h}/{$p}";
			},
			'legacy amp boundary'   => static function ( $h, $p ) {
				return "&amp{$h}/{$p}";
			},
			'four layers'           => static function ( $h, $p ) {
				return "{$h}%2525252F{$p}";
			},
		);
	}

	/** @return array<string,array{0:string,1:string,2:string}> encoded, kind, secret */
	public static function table(): array {
		$cases = array();
		foreach ( self::RECEIVERS as $rname => list( $host, $path, $kind, $secret ) ) {
			foreach ( self::encodings() as $ename => $encode ) {
				$cases[ "{$rname} / {$ename}" ] = array( $encode( $host, $path ), $kind, $secret );
			}
		}
		return $cases;
	}

	/** @dataProvider table */
	public function test_an_encoded_receiver_run_is_replaced_whole( string $encoded, string $kind, string $secret ): void {
		$out = $this->text( $encoded, $n );
		$this->assertSame( 'aura-redacted:v1:' . $kind, $out, $encoded );
		$this->assertStringNotContainsString( $secret, $out );
		$this->assertSame( 1, $n );
	}

	/** @dataProvider table */
	public function test_an_encoded_receiver_run_is_replaced_inside_text( string $encoded, string $kind, string $secret ): void {
		$out = Aura_Worker_Redact::redact( array( 'content' => array( 'raw' => "<p>see {$encoded} now</p>" ) ), $n );
		$this->assertSame( "<p>see aura-redacted:v1:{$kind} now</p>", $out['content']['raw'] );
		$this->assertSame( 1, $n );
	}

	/** @return array<string,array{0:string,1:string}> input, expected */
	public static function spec_cases(): array {
		return array(
			'kses content.raw'          => array( 'https://user&amp;#64hook.eu2.make.com/SECRET', 'aura-redacted:v1:make' ),
			'wptexturize rendered'      => array( '<p>https://user&#038;#64hook.eu2.make.com/SECRET</p>', '<p>aura-redacted:v1:make</p>' ),
			'kses in an href'           => array( '<a href="https://user&amp;#64hook.eu2.make.com/SECRET">x</a>', '<a href="aura-redacted:v1:make">x</a>' ),
			'zero reference boundary'   => array( '&#0hooks.zapier.com/x', 'aura-redacted:v1:zapier' ),
			'legacy amp, no ;'          => array( '&amphooks.zapier.com/x', 'aura-redacted:v1:zapier' ),
			'legacy amp then numeric'   => array( '&amp#104;ooks.zapier.com/x', 'aura-redacted:v1:zapier' ),
			'exactly four layers'       => array( 'hooks.zapier.com%2525252Fx', 'aura-redacted:v1:zapier' ),
			'pct 252F'                  => array( 'hooks.zapier.com%252Fx', 'aura-redacted:v1:zapier' ),
			'encoded host dot'          => array( 'hook%2Eeu2.make.com/SECRET', 'aura-redacted:v1:make' ),
			'encoded host letter'       => array( '&#104;ooks.zapier.com/SECRET', 'aura-redacted:v1:zapier' ),
			'json u002f never decoded'  => array( 'hooks.zapier.com\\u002fSECRET', 'aura-redacted:v1:zapier' ),
			'non-ascii boundary'        => array( 'caf%C3%A9hooks.zapier.com%252Fx', 'aura-redacted:v1:zapier' ),
			'decimal 470 boundary'      => array( 'x&#470hooks.zapier.com/SECRET', 'aura-redacted:v1:zapier' ),
			'literal marker beside'     => array( 'aura-redacted:v1:make%252F%252Fhooks.zapier.com%252Fx', 'aura-redacted:v1:zapier' ),
			'literal marker html'       => array( 'aura-redacted:&amp;#47;hooks.zapier.com&amp;#47;x', 'aura-redacted:v1:zapier' ),
			'query parameter'           => array( 'https://example.com/login?redirect=https%253A%252F%252Fhooks.zapier.com%252Fx', 'aura-redacted:v1:zapier' ),
			'adjacent text in run'      => array( '(hooks.zapier.com%252Fx),', 'aura-redacted:v1:zapier,' ),
			'trailing punctuation'      => array( 'go hooks.zapier.com%252Fx.', 'go aura-redacted:v1:zapier.' ),
			'closing reference ;'       => array( 'hooks.zapier.com%252Fx&#x31;', 'aura-redacted:v1:zapier;' ),
			'stage 1 then stage 2'      => array( 'a https://hooks.zapier.com/hooks/x b 100%25 c', 'a aura-redacted:v1:zapier b 100%25 c' ),
			'five layers, only the run' => array( 'keep this %252525252F and this', 'keep this aura-redacted:v1:field and this' ),
		);
	}

	/** @dataProvider spec_cases */
	public function test_spec_cases( string $in, string $expected ): void {
		$out = $this->text( $in, $n );
		$this->assertSame( $expected, $out );
		$this->assertSame( 1, $n );
	}

	public function test_a_stage_1_placeholder_does_not_shield_the_rest_of_its_run(): void {
		// Stage 1's tail stops at `)`, so it replaces only the plain URL; the
		// run still holds an encoded one.
		$out = $this->text( 'https://hooks.zapier.com/a)hooks.slack.com%252Fservices%252FT%252FB%252FX', $n );
		$this->assertSame( 'aura-redacted:v1:slack', $out );
		$this->assertSame( 2, $n );
	}

	public function test_each_replaced_run_is_counted(): void {
		$out = $this->text( 'a hooks.zapier.com%252Fx b discord.com%252Fapi%252Fwebhooks%252F1%252Fy c %252525252F', $n );
		$this->assertSame( 'a aura-redacted:v1:zapier b aura-redacted:v1:discord c aura-redacted:v1:field', $out );
		$this->assertSame( 3, $n );
	}

	/**
	 * Runs whose receiver URL shows only in an INTERMEDIATE layer: a later
	 * pass decodes the reference in front of the host and glues it to the
	 * host (`A` + `hooks.zapier.com`), so the fixed point holds no receiver
	 * at a boundary. Every layer is checked, so they are still redacted.
	 * The single-encoded run is already caught by stage 1, which replaces
	 * only the plain URL after the reference; stage 2 then sees no receiver
	 * in what is left, so the reference stays in front of the placeholder.
	 *
	 * @return array<string,array{0:string,1:string}> run, expected output
	 */
	public static function intermediate_layer_cases(): array {
		return array(
			'html double, glued letter'   => array( '&amp;#65;hooks.zapier.com&amp;#x2f;hooks&amp;#x2f;catch&amp;#x2f;123&amp;#x2f;abcdef', 'aura-redacted:v1:zapier' ),
			'html single, glued letter'   => array( '&#65;hooks.zapier.com/hooks/catch/123/abcdef', '&#65;aura-redacted:v1:zapier' ),
			'pct triple, glued reference' => array( '%2526%252365%253Bhooks.zapier.com%252Fhooks%252Fcatch%252F123%252Fabcdef', 'aura-redacted:v1:zapier' ),
		);
	}

	/** @dataProvider intermediate_layer_cases */
	public function test_a_receiver_in_an_intermediate_layer_is_redacted( string $run, string $expected ): void {
		// Which stage replaces what differs per row, so only the final output
		// is asserted, not the count.
		$out = $this->text( $run, $n );
		$this->assertSame( $expected, $out );
		$this->assertStringNotContainsString( 'abcdef', $out );
		$this->assertGreaterThanOrEqual( 1, $n );
	}

	/** @dataProvider intermediate_layer_cases */
	public function test_a_receiver_in_an_intermediate_layer_is_redacted_inside_prose( string $run, string $expected ): void {
		$out = $this->text( "Before the hook {$run} and after it.", $n );
		$this->assertSame( "Before the hook {$expected} and after it.", $out );
		$this->assertStringNotContainsString( 'abcdef', $out );
		$this->assertGreaterThanOrEqual( 1, $n );
	}

	/**
	 * A JSON `\uXXXX` escape inside a receiver path (#113, fix round 1).
	 * Stage 1 stops a URL at a bare backslash, so it leaves its placeholder
	 * right before the escape (`…:zapierhooks…`); the original run's
	 * layers are checked too, so the whole run goes. The escape sits on the
	 * path's first letter or on the secret's first letter (plain JSON, and
	 * after punctuation stage 1 hands back: `.`), or on the secret's second
	 * letter (JSON-in-JSON, `\\u`). JSON-in-JSON also escapes the path's
	 * first letter — inside a receiver's FIXED prefix (`services`, `api`,
	 * `hooks`, `trigger`, `bot`) — and the secret's first letter (telegram:
	 * right after `bot<id>:`); the decoder reads `\\` as `\` (fix round 2).
	 *
	 * @return array<string,array{0:string,1:string,2:string}> run, kind, secret
	 */
	public static function json_path_cases(): array {
		$cases = array();
		foreach ( self::RECEIVERS as $rname => list( $host, $path, $kind, $secret ) ) {
			$at     = strpos( $path, $secret );
			$prefix = substr( $path, 0, $at );
			$after  = substr( $path, $at + 1 );
			$escape = '\\u00' . bin2hex( $secret[0] );

			$cases[ "{$rname} / json path letter" ]           = array( "https://{$host}/\\u00" . bin2hex( $path[0] ) . substr( $path, 1 ), $kind, $secret );
			$cases[ "{$rname} / json secret letter" ]         = array( "https://{$host}/{$prefix}{$escape}{$after}", $kind, $secret );
			$cases[ "{$rname} / json-in-json path letter" ]   = array( "https://{$host}/\\\\u00" . bin2hex( $path[0] ) . substr( $path, 1 ), $kind, $secret );
			$cases[ "{$rname} / json-in-json secret first" ]  = array( "https://{$host}/{$prefix}\\{$escape}{$after}", $kind, $secret );
			$cases[ "{$rname} / json-in-json secret letter" ] = array( "https://{$host}/{$prefix}{$secret[0]}\\\\u00" . bin2hex( $secret[1] ) . substr( $path, $at + 2 ), $kind, $secret );
			$cases[ "{$rname} / json after punctuation" ]     = array( "https://{$host}/{$prefix}x.{$escape}{$after}", $kind, $secret );
		}
		return $cases;
	}

	/** @dataProvider json_path_cases */
	public function test_a_json_escape_in_a_receiver_path_is_redacted( string $run, string $kind, string $secret ): void {
		$out = $this->text( $run, $n );
		$this->assertSame( 'aura-redacted:v1:' . $kind, $out, $run );
		$this->assertStringNotContainsString( substr( $secret, 1 ), $out );
		$this->assertGreaterThanOrEqual( 1, $n );
	}

	/** @dataProvider json_path_cases */
	public function test_a_json_escape_in_a_receiver_path_is_redacted_inside_prose( string $run, string $kind, string $secret ): void {
		$out = $this->text( "Posted to {$run} today, then {$run}. Done.", $n );
		$this->assertSame( "Posted to aura-redacted:v1:{$kind} today, then aura-redacted:v1:{$kind}. Done.", $out );
		$this->assertStringNotContainsString( substr( $secret, 1 ), $out );
		$this->assertGreaterThanOrEqual( 2, $n );
	}

	/**
	 * A boundary reference before a scheme-less host, and a JSON escape in
	 * the path (#113, fix round 3). Stage 1 matches from the host on and
	 * stops at the escape (`&#65;aura-redacted:v1:zapierc…`); only
	 * the original run's RAW layer has the host at a boundary (every
	 * decoded layer glues `A` to it), so when the cut starts a real JSON
	 * escape the raw original is checked too. The escape sits on the
	 * secret's second letter, so stage 1 matches every receiver's raw run.
	 *
	 * @return array<string,array{0:string,1:string,2:string}> run, kind, secret
	 */
	public static function boundary_reference_json_cases(): array {
		$cases = array();
		foreach ( self::RECEIVERS as $rname => list( $host, $path, $kind, $secret ) ) {
			$at      = strpos( $path, $secret );
			$escaped = substr( $path, 0, $at + 1 ) . '\\u00' . bin2hex( $secret[1] ) . substr( $path, $at + 2 );
			foreach ( array( '&#65;', '&#x41;', 'x&#65;' ) as $reference ) {
				$cases[ "{$rname} / {$reference}" ] = array( "{$reference}{$host}/{$escaped}", $kind, $secret );
			}
		}
		return $cases;
	}

	/** @dataProvider boundary_reference_json_cases */
	public function test_a_boundary_reference_and_a_json_escape_are_redacted( string $run, string $kind, string $secret ): void {
		$out = $this->text( $run, $n );
		$this->assertSame( 'aura-redacted:v1:' . $kind, $out, $run );
		$this->assertStringNotContainsString( substr( $secret, 2 ), $out );
		$this->assertSame( 2, $n );
	}

	/** @dataProvider boundary_reference_json_cases */
	public function test_a_boundary_reference_and_a_json_escape_are_redacted_inside_prose( string $run, string $kind, string $secret ): void {
		$out = $this->text( "Hook: {$run}, then {$run}. End", $n );
		$this->assertSame( "Hook: aura-redacted:v1:{$kind}, then aura-redacted:v1:{$kind}. End", $out );
		$this->assertStringNotContainsString( substr( $secret, 2 ), $out );
		$this->assertSame( 4, $n );
	}

	/**
	 * A boundary reference glued before the host, and a JSON escape where
	 * stage 1 never matches the raw run — in a receiver's FIXED path prefix
	 * (`services`, `api`, `hooks`, `trigger`, `bot`) or on the telegram
	 * token's first letter (#113, fix round 4). Stage 1 leaves no cut to
	 * hook onto, and every decoded layer glues the decoded letter to the
	 * host (`Ahooks.slack.com/services/…`). Owner decision: in stage 2 a
	 * receiver host needs no LEFT boundary, so the glued host counts.
	 *
	 * @return array<string,array{0:string,1:string,2:string}> run, kind, secret
	 */
	public static function glued_host_json_cases(): array {
		$cases = array(
			'slack literal'    => array( '&#65;hooks.slack.com/\\u0073ervices/T/B/SLACKSECRET', 'slack', 'SLACKSECRET' ),
			'discord literal'  => array( '&#65;discord.com/\\u0061pi/webhooks/1/dsecret-tok', 'discord', 'dsecret-tok' ),
			'telegram literal' => array( '&#65;api.telegram.org/bot1:\\u0041Asecret_x/sendMessage', 'telegram', 'AAsecret_x' ),
		);
		foreach ( self::RECEIVERS as $rname => list( $host, $path, $kind, $secret ) ) {
			$at    = strpos( $path, $secret );
			$first = '\\u00' . bin2hex( $path[0] ) . substr( $path, 1 );
			$token = substr( $path, 0, $at ) . '\\u00' . bin2hex( $secret[0] ) . substr( $path, $at + 1 );
			foreach ( array( '&#65;', '&#x41;', 'x&#65;' ) as $reference ) {
				$cases[ "{$rname} / {$reference} / path letter" ]   = array( "{$reference}{$host}/{$first}", $kind, $secret );
				$cases[ "{$rname} / {$reference} / secret letter" ] = array( "{$reference}{$host}/{$token}", $kind, $secret );
			}
		}
		return $cases;
	}

	/** @dataProvider glued_host_json_cases */
	public function test_a_glued_host_and_a_json_escape_are_redacted( string $run, string $kind, string $secret ): void {
		$out = $this->text( $run, $n );
		$this->assertSame( 'aura-redacted:v1:' . $kind, $out, $run );
		$this->assertStringNotContainsString( substr( $secret, 1 ), $out );
		$this->assertGreaterThanOrEqual( 1, $n );
	}

	/** @dataProvider glued_host_json_cases */
	public function test_a_glued_host_and_a_json_escape_are_redacted_inside_prose( string $run, string $kind, string $secret ): void {
		$out = $this->text( "Hook: {$run}, then {$run}. End", $n );
		$this->assertSame( "Hook: aura-redacted:v1:{$kind}, then aura-redacted:v1:{$kind}. End", $out );
		$this->assertStringNotContainsString( substr( $secret, 1 ), $out );
		$this->assertGreaterThanOrEqual( 2, $n );
	}

	/**
	 * The cost of the owner decision (#113, fix round 4): in an ENCODED run a
	 * lookalike host (a receiver host with a hostname character glued in
	 * front) is redacted — these were controls kept only by the left host
	 * boundary. The same lookalikes in plain text are still kept (stage 1
	 * keeps its boundary).
	 *
	 * @return array<string,array{0:string,1:string,2:string}> encoded run, kind, plain-text twin
	 */
	public static function lookalike_cases(): array {
		return array(
			'glued letters, pct slash' => array( 'myhooks.zapier.com%2Fx', 'zapier', 'myhooks.zapier.com/x' ),
			'glued letters, 252F'      => array( 'myhooks.zapier.com%252Fx', 'zapier', 'myhooks.zapier.com/x' ),
			'encoded digit prefix'     => array( 'x%31hooks.zapier.com%2Fx', 'zapier', 'x1hooks.zapier.com/x' ),
			'encoded dot prefix'       => array( 'evil%2Ehooks.zapier.com%252Fx', 'zapier', 'evil.hooks.zapier.com/x' ),
			'encoded prefixed host'    => array( 'myhook%2Eeu2.make.com%2Fabc', 'make', 'myhook.eu2.make.com/abc' ),
			'hex 40d before discord'   => array( '&#x40discord.com/api/webhooks/1/x', 'discord', 'Xdiscord.com/api/webhooks/1/x' ),
			'userinfo hex 40d'         => array( 'https://user&#x40discord.com/api/webhooks/1/x', 'discord', 'https://userXdiscord.com/api/webhooks/1/x' ),
		);
	}

	/** @dataProvider lookalike_cases */
	public function test_an_encoded_lookalike_host_run_is_redacted( string $run, string $kind, string $plain ): void {
		$this->assertSame( 'aura-redacted:v1:' . $kind, $this->text( $run, $n ) );
		$this->assertSame( 1, $n );
	}

	/** @dataProvider lookalike_cases */
	public function test_the_same_lookalike_host_in_plain_text_is_kept( string $run, string $kind, string $plain ): void {
		$this->assertSame( "see {$plain} now", $this->text( "see {$plain} now", $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_stage_2_patterns_are_the_stage_1_patterns_without_the_left_boundary(): void {
		$stage_2 = Aura_Worker_Redact::stage_2_patterns();
		$this->assertCount( count( Aura_Worker_Redact::URL_PATTERNS ), $stage_2 );
		foreach ( Aura_Worker_Redact::URL_PATTERNS as $i => list( $kind, $regex ) ) {
			$this->assertStringStartsWith( Aura_Worker_Redact::RE_HEAD, $regex, $kind );
			$rest = substr( $regex, strlen( Aura_Worker_Redact::RE_HEAD ) );
			$this->assertSame( array( $kind, Aura_Worker_Redact::RE_HEAD_UNBOUNDED . $rest ), $stage_2[ $i ] );
		}
		// The two heads differ only by the boundary group.
		$this->assertSame( '~', substr( Aura_Worker_Redact::RE_HEAD, 0, 1 ) );
		$this->assertStringEndsWith( substr( Aura_Worker_Redact::RE_HEAD_UNBOUNDED, 1 ), Aura_Worker_Redact::RE_HEAD );
		$this->assertStringNotContainsString( '(?<!', Aura_Worker_Redact::RE_HEAD_UNBOUNDED );
	}

	/** @return array<string,array{0:string}> */
	public static function controls(): array {
		return array(
			'encoded suffixed host'       => array( 'hooks.zapier.com%252Ecom.evil.tld%252Fx' ),
			'encoded userinfo decoy'      => array( 'https%253A%252F%252Fhooks.zapier.com%2540evil.tld%252Fx' ),
			'non-receiver double encoded' => array( 'https%253A%252F%252Fn8n.example.com%252Fwebhook%252Fabc' ),
			'non-receiver kses'           => array( 'https:&amp;#x2F;&amp;#x2F;www.make.com&amp;#x2F;en' ),
			'slack non-hook path'         => array( 'hooks.slack.com%252Fhelp%252Farticles' ),
			'non-legacy sol, no ;'        => array( 'hooks.zapier.com&solx' ),
			'plus is no space'            => array( 'q=a+hooks.zapier.com%2Bx' ),
			'wptexturize prose'           => array( '<p>It&#8217;s &#8220;done&#8221; &#8212; see&nbsp;page&#8230; 100% &amp; more</p>' ),
			'json prose'                  => array( '{"a":"caf\\u00e9 \\ud83d\\ude00 \\/x"}' ),
			'four layers, no receiver'    => array( 'a%2525252Fb' ),
			'placeholder only'            => array( 'x aura-redacted:v1:make y' ),
		);
	}

	/** @dataProvider controls */
	public function test_controls_stay_untouched( string $in ): void {
		$this->assertSame( $in, $this->text( $in, $n ) );
		$this->assertSame( 0, $n );
	}

	/**
	 * Run $test with the JIT off and pcre.backtrack_limit at $limit.
	 *
	 * @param int      $limit pcre.backtrack_limit.
	 * @param callable $test  The assertions.
	 */
	private function with_pcre_limit( int $limit, callable $test ): void {
		$jit = ini_get( 'pcre.jit' );
		$old = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		ini_set( 'pcre.backtrack_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			$test();
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
	}

	/**
	 * The lowest pcre.backtrack_limit (JIT off) at which $passes holds and
	 * $fails holds too. What a regex costs depends on the PCRE build, so
	 * the limit is probed rather than pinned; the test is skipped when this
	 * build has none. The tests that use it run in their own process: a
	 * pattern an earlier test JIT-compiled stays cached and keeps matching
	 * under the JIT after pcre.jit is turned off, and the JIT does not
	 * count against these limits the same way.
	 *
	 * @param callable $passes The regexes that must succeed: true when they did.
	 * @param callable $fails  The regex that must fail: true when it did.
	 */
	private function limit_between( callable $passes, callable $fails ): int {
		for ( $limit = 1; $limit <= 500; ++$limit ) {
			$found = false;
			$this->with_pcre_limit(
				$limit,
				static function () use ( $passes, $fails, &$found ) {
					$found = $passes() && $fails();
				}
			);
			if ( $found ) {
				return $limit;
			}
		}
		$this->markTestSkipped( 'this PCRE build has no limit that fails only the targeted regex' );
	}

	private static function split_succeeds( string $text ): bool {
		return null !== preg_replace_callback( Aura_Worker_Redact::RE_RUN, 'current', $text );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_pcre_failure_on_the_split_fails_the_field_closed(): void {
		// (a) The run split itself fails. No `/` and no encoded slash: stage 1
		// never runs a regex, stage 2 does.
		$text  = 'keep hooks.zapier.com%252Fx keep';
		$limit = $this->limit_between(
			'__return_true',
			static function () use ( $text ) {
				return ! self::split_succeeds( $text );
			}
		);
		$this->with_pcre_limit(
			$limit,
			function () use ( $text ) {
				$this->assertSame( 'aura-redacted:v1:field', $this->text( $text, $n ) );
				$this->assertSame( 1, $n );
				$this->assertSame( 'plain words only', $this->text( 'plain words only', $n ), 'the field fast path runs no regex' );
				$this->assertSame( 0, $n );
			}
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_pcre_failure_on_a_layer_check_fails_the_field_closed(): void {
		// (b) The split and the decoder succeed; a receiver pattern on the
		// decoded layer `hooks.zapier.com%2Fx` does not.
		$text  = 'keep hooks.zapier.com%252Fx keep';
		$limit = $this->limit_between(
			static function () use ( $text ) {
				return self::split_succeeds( $text ) && null !== Aura_Worker_Redact_Decode::decode_layers( 'hooks.zapier.com%252Fx' );
			},
			static function () {
				foreach ( Aura_Worker_Redact::stage_2_patterns() as $pattern ) {
					$found = preg_match( $pattern[1], 'hooks.zapier.com%2Fx' );
					if ( 1 === $found ) {
						return false; // a match before any failure: no failure is reached
					}
					if ( false === $found ) {
						return true;
					}
				}
				return false;
			}
		);
		$this->with_pcre_limit(
			$limit,
			function () use ( $text ) {
				$this->assertSame( 'aura-redacted:v1:field', $this->text( $text, $n ) );
				$this->assertSame( 1, $n );
			}
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_pcre_failure_in_the_decoder_fails_only_the_run(): void {
		// (c) The split succeeds; the numeric reference regex does not, so
		// decode_layers() is null and only that run becomes the placeholder.
		$run   = str_repeat( 'a&#8217;', 50 );
		$text  = "keep {$run} keep";
		$limit = $this->limit_between(
			static function () use ( $text ) {
				return self::split_succeeds( $text );
			},
			static function () use ( $run ) {
				return null === Aura_Worker_Redact_Decode::decode_layers( $run );
			}
		);
		$this->with_pcre_limit(
			$limit,
			function () use ( $text ) {
				$this->assertSame( 'keep aura-redacted:v1:field keep', $this->text( $text, $n ) );
				$this->assertSame( 1, $n );
			}
		);
	}

	// --- carriers ---------------------------------------------------------

	public function test_a_double_encoded_url_inside_elementor_data_is_redacted(): void {
		$tree = array(
			array(
				'id'         => 'w1',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array(
					'editor' => '<p><a href="https://user&amp;#64hook.eu2.make.com/abc123secret">x</a></p>',
					'link'   => array( 'url' => 'https://example.com/go?to=' . rawurlencode( rawurlencode( 'https://hooks.zapier.com/hooks/catch/1/zsecret9' ) ) ),
				),
				'elements'   => array(),
			),
		);
		$post = array( 'id' => 7, 'meta' => array( '_elementor_data' => wp_json_encode( $tree ) ) );

		$out = Aura_Worker_Redact::redact( $post, $n );

		$this->assertSame( 2, $n );
		$json = $out['meta']['_elementor_data'];
		$this->assertStringNotContainsString( 'abc123secret', $json );
		$this->assertStringNotContainsString( 'zsecret9', $json );
		$decoded = json_decode( $json, true );
		$this->assertSame( '<p><a href="aura-redacted:v1:make">x</a></p>', $decoded[0]['settings']['editor'] );
		$this->assertSame( 'aura-redacted:v1:zapier', $decoded[0]['settings']['link']['url'] );
	}

	public function test_a_double_encoded_url_in_an_mcp_text_carrier_is_redacted(): void {
		$inner  = wp_json_encode( array( 'href' => 'hooks.slack.com%252Fservices%252FT0%252FB0%252FSLACKSECRET' ) );
		$result = array( 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => $inner ) ) ) );

		$out = Aura_Worker_Redact::redact( $result, $n );

		$this->assertSame( 1, $n );
		$this->assertSame( array( 'href' => 'aura-redacted:v1:slack' ), json_decode( $out['result']['content'][0]['text'], true ) );
	}

	public function test_an_mcp_text_that_is_not_json_is_redacted_as_text(): void {
		$result = array( 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => 'Saved: https://user&#038;#64hook.eu2.make.com/abc123secret.' ) ) ) );

		$out = Aura_Worker_Redact::redact( $result, $n );

		$this->assertSame( 1, $n );
		$this->assertSame( 'Saved: aura-redacted:v1:make.', $out['result']['content'][0]['text'] );
	}

	public function test_a_double_encoded_url_in_a_snapshot_payload_string_is_redacted(): void {
		$captured = array(
			7 => array(
				'existed' => true,
				'fields'  => array(
					'post_title'   => 'Contact',
					'post_content' => '<p>https://user&#038;#64hook.eu2.make.com/abc123secret</p>',
				),
				'meta'    => array(),
			),
		);
		$answer   = array( 'found' => true, 'record' => array( 'id' => 'snap_113' ), 'payload' => base64_encode( serialize( $captured ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$out = Aura_Worker_Redact::redact( array( 'success' => true, 'result' => $answer ), $n );

		$this->assertSame( 1, $n );
		$back = unserialize( (string) base64_decode( $out['result']['payload'], true ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( '<p>aura-redacted:v1:make</p>', $back[7]['fields']['post_content'] );
		$this->assertSame( 'Contact', $back[7]['fields']['post_title'] );
		$this->assertArrayNotHasKey( 'payload_redacted', $out['result'] );
	}

	public function test_the_write_guard_still_matches_only_the_literal_marker(): void {
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( array( 'x' => 'aura-redacted&#58;v1:make' ) ), 'the guard does not decode' );
		$redacted = Aura_Worker_Redact::redact( array( 'x' => 'hooks.zapier.com%252Fx' ), $n );
		$this->assertSame( 1, $n );
		$this->assertTrue( Aura_Worker_Redact::holds_placeholder( $redacted ) );
	}

	// --- performance (spec §4) ---------------------------------------------

	/** @return array<string,array{0:bool}> */
	public static function jit_modes(): array {
		return array(
			'jit on'  => array( true ),
			'jit off' => array( false ),
		);
	}

	/**
	 * About 1 MB of wptexturize'd prose with nested encodings and no
	 * receiver: stays linear, never fails closed.
	 *
	 * @dataProvider jit_modes
	 */
	public function test_a_megabyte_of_encoded_prose_is_not_failed_closed( bool $jit ): void {
		$old = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', $jit ? '1' : '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			$chunk = 'It&#8217;s &#8220;done&#8221; &#8212; see&nbsp;https://example.com/a?b=1&#038;c=%252F&amp;amp;d caf%C3%A9 \\u00e9 &amp;#8230; ';
			$long  = 'https://example.com/?q=' . str_repeat( '%2525252Fa&amp;amp;', 2000 );
			$text  = str_repeat( $chunk, (int) ceil( 1000000 / strlen( $chunk ) ) ) . $long;
			$this->assertGreaterThan( 1000000, strlen( $text ) );

			$start = microtime( true );
			$out   = $this->text( $text, $n );
			$this->assertLessThan( 2.0, microtime( true ) - $start );
			$this->assertSame( $text, $out );
			$this->assertSame( 0, $n );
		} finally {
			ini_set( 'pcre.jit', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
	}

	public function test_a_cut_field_of_millions_of_runs_stays_within_four_times_its_size(): void {
		if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
			$this->markTestSkipped( 'memory_reset_peak_usage() needs PHP 8.2' );
		}
		$old = ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			// One stage 1 cut at a JSON escape, then ~2M one-letter runs.
			$field = 'https://hooks.zapier.com/x\u0041 ' . str_repeat( 'a ', 2000000 );
			$size  = strlen( $field );
			$before = memory_get_usage();
			memory_reset_peak_usage();
			$count = 0;
			$out   = Aura_Worker_Redact::redact_text( $field, $count );
			$peak  = memory_get_peak_usage() - $before;
			$this->assertStringStartsWith( 'aura-redacted:v1:zapier ', $out );
			$this->assertSame( 2, $count );
			// The 4x bound is for ONE cut; $cuts (original_runs()) grows with the
			// number of cut runs, not with the field size on its own.
			$this->assertLessThan( 4 * $size, $peak, sprintf( 'peak growth %d bytes on a %d-byte field', $peak, $size ) );
		} finally {
			ini_set( 'memory_limit', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
	}

	public function test_a_cut_at_a_json_escape_still_reads_the_original_run_whole(): void {
		// Unchanged from 2.18.2: the escape continues the URL for a JSON reader,
		// so the original run's raw layer is judged again, whole.
		$this->assertSame( 'aura-redacted:v1:make', $this->text( 'https://hook.eu2.make.com/abc\u0031def' ) );
		$this->assertSame( 'x aura-redacted:v1:zapier y', $this->text( 'x https://hooks.zapier.com/hooks\\/catch/1/S y' ) );
	}
}
