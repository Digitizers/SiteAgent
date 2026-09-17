<?php
/**
 * SiteAgent #116: stage 2 reads a run the way a URL parser does — a
 * backslash is a slash under a scheme (url_view + url_patterns), and a
 * hostname's UTS-46 mapping is a hostname (Aura_Worker_Redact_Idna::map) —
 * and keeps everything a parser would not resolve to a receiver.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactUrlReadingTest extends TestCase {

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

	/** Every prefix a WHATWG parser resolves to the host, as a function of host and a backslash path. */
	private static function schemed_forms(): array {
		return array(
			'https://'       => static function ( $h, $p ) { return "https://{$h}\\{$p}"; },
			'http://'        => static function ( $h, $p ) { return "http://{$h}\\{$p}"; },
			'ftp://'         => static function ( $h, $p ) { return "ftp://{$h}\\{$p}"; },
			'ws://'          => static function ( $h, $p ) { return "ws://{$h}\\{$p}"; },
			'wss://'         => static function ( $h, $p ) { return "wss://{$h}\\{$p}"; },
			'https:\\'       => static function ( $h, $p ) { return "https:\\{$h}\\{$p}"; },
			'https:/'        => static function ( $h, $p ) { return "https:/{$h}\\{$p}"; },
			'https: (none)'  => static function ( $h, $p ) { return "https:{$h}\\{$p}"; },
			'https:///'      => static function ( $h, $p ) { return "https:///{$h}\\{$p}"; },
			'//'             => static function ( $h, $p ) { return "//{$h}\\{$p}"; },
			'\\\\'           => static function ( $h, $p ) { return "\\\\{$h}\\{$p}"; },
			'mixed'          => static function ( $h, $p ) { return "https://{$h}/" . strtr( $p, '/', '\\' ); },
			'%5C'            => static function ( $h, $p ) { return "https://{$h}" . str_replace( '\\', '%5C', "\\{$p}" ); },
			'&#92;'          => static function ( $h, $p ) { return "https://{$h}" . str_replace( '\\', '&#92;', "\\{$p}" ); },
			'&#x5C;'         => static function ( $h, $p ) { return "https://{$h}" . str_replace( '\\', '&#x5C;', "\\{$p}" ); },
			'pct whole'      => static function ( $h, $p ) { return rawurlencode( "https://{$h}\\{$p}" ); },
			'json \\\\'      => static function ( $h, $p ) { return "https://{$h}" . str_replace( '\\', '\\\\', "\\{$p}" ); },
			'///'            => static function ( $h, $p ) { return "///{$h}\\{$p}"; },
			'json \\/\\/\\/' => static function ( $h, $p ) { return '\\/\\/\\/' . $h . str_replace( '\\', '\\\\', "\\{$p}" ); },
			'userinfo'              => static function ( $h, $p ) { return "https://user:pw@{$h}\\{$p}"; },
			'userinfo, two @'       => static function ( $h, $p ) { return "https://a@b@{$h}\\{$p}"; },
			'userinfo, three @, //' => static function ( $h, $p ) { return "//a@b@c@{$h}\\{$p}"; },
			'userinfo, encoded @'   => static function ( $h, $p ) { return "https://a%40b@{$h}\\{$p}"; },
			'userinfo, encoded slash'       => static function ( $h, $p ) { return "https://a%2Fb@{$h}\\{$p}"; },
			'userinfo, encoded slash ref'   => static function ( $h, $p ) { return "https://a&sol;b@{$h}\\{$p}"; },
			'userinfo, encoded slash + two @' => static function ( $h, $p ) { return "https://a%2Fb@c@{$h}\\{$p}"; },
			// #116, Codex r3 round 2 on PR #120: url_view() maps an ENCODED
			// backslash to an ENCODED slash (`%2f`), not a literal one, so it
			// stays userinfo (RE_USERINFO_ANY_AT already accepts `%2f`) while
			// still ending the host / a path segment (RE_SLASH, RE_TAIL already
			// accept `%2f`, #110) — one reading serves both positions.
			'userinfo, encoded backslash'   => static function ( $h, $p ) { return "https://a%5C@{$h}\\{$p}"; },
			'userinfo, encoded backslash ref' => static function ( $h, $p ) { return "https://a&#92;@{$h}\\{$p}"; },
			// The round-1 known limit, now closed by mapping the encoded
			// backslash to `%2f` everywhere in one pass: userinfo's `%5C` stays
			// userinfo, and the path's `%5C`s are still read as the slash.
			'userinfo AND path, encoded backslash' => static function ( $h, $p ) { return 'https://a%5C@' . $h . '%5C' . str_replace( '\\', '%5C', $p ); },
		);
	}

	public static function backslash_cases(): array {
		$cases = array();
		foreach ( self::RECEIVERS as $name => $r ) {
			$path = strtr( $r[1], '/', '\\' );
			foreach ( self::schemed_forms() as $form => $make ) {
				$cases[ "{$name} / {$form}" ] = array( $make( $r[0], $path ), $r[2], $r[3] );
			}
		}
		return $cases;
	}

	/** @dataProvider backslash_cases */
	public function test_a_backslash_path_under_a_scheme_is_redacted_whole( string $url, string $kind, string $secret ): void {
		foreach ( array( $url, "see {$url} now", "x=\"{$url}\"", "{$url}." ) as $in ) {
			$out = $this->text( $in, $count );
			$this->assertStringNotContainsString( $secret, $out, $in );
			$this->assertStringContainsString( 'aura-redacted:v1:' . $kind, $out, $in );
			$this->assertGreaterThanOrEqual( 1, $count, $in );
		}
		$this->assertSame( 'aura-redacted:v1:' . $kind . '.', $this->text( "{$url}." ) );
	}

	public function test_the_stage_1_cut_leaves_no_fragment_of_the_secret(): void {
		$this->assertSame( 'aura-redacted:v1:zapier', $this->text( 'https://hooks.zapier.com/hooks\\catch\\1\\SECRET' ) );
		$this->assertSame( 'a aura-redacted:v1:slack b', $this->text( 'a https://hooks.slack.com/services\\T0\\B0\\SEC b' ) );
	}

	/** @dataProvider carriers */
	public function test_inside_carriers( string $carrier ): void {
		$url = 'https://hooks.zapier.com\\hooks\\catch\\1\\zsecret9';
		$out = wp_json_encode( Aura_Worker_Redact::redact( json_decode( sprintf( $carrier, addcslashes( $url, '\\' ) ), true ) ) );
		$this->assertStringNotContainsString( 'zsecret9', $out );
		$this->assertStringContainsString( 'aura-redacted:v1:zapier', $out );
	}

	public static function carriers(): array {
		return array(
			'elementor meta' => array( '{"meta":{"_elementor_data":"[{\"settings\":{\"url\":\"%s\"}}]"}}' ),
			'mcp text'       => array( '{"content":[{"type":"text","text":"call %s"}]}' ),
			'plain string'   => array( '{"content":{"raw":"%s"}}' ),
		);
	}

	/** @dataProvider kept */
	public function test_what_a_parser_does_not_resolve_to_a_receiver_is_kept( string $in ): void {
		$this->assertSame( $in, $this->text( $in, $count ), $in );
		$this->assertSame( 0, $count );
	}

	public static function kept(): array {
		return array(
			'bare host, json newline'   => array( 'hooks.zapier.com\\nNext' ),
			'bare host, backslash path' => array( 'hooks.zapier.com\\hooks\\catch\\1\\S' ),
			'windows path'              => array( 'C:\\Users\\hooks.zapier.com\\x' ),
			'file url'                  => array( 'file://hooks.zapier.com\\x' ),
			'file:///host'              => array( 'file:///hooks.zapier.com\\x' ),
			'gopher (not special)'      => array( 'gopher://hooks.zapier.com\\x' ),
			'unc, other host'           => array( '\\\\server\\share\\hooks.zapier.com' ),
			'one slash: a path'         => array( '/hooks.zapier.com\\x' ),
			'prose with a backslash'    => array( 'either\\or, see hooks.zapier.com' ),
			'lookalike host'            => array( 'https://hooks.zapier.com.evil\\x' ),
			'other host, receiver in path' => array( 'https://example.com\\hooks.zapier.com\\x' ),
			'other host, mapped chars'  => array( "https://\u{FF45}xample.com/x" ),
			'userinfo with a slash before the host' => array( 'https://a@b/hooks.zapier.com\\x' ),
			'userinfo ended by a literal slash, encoded @ after' => array( 'https://a/b%40hooks.zapier.com\\x' ),
			// #116, Codex r3: a LITERAL backslash in userinfo is a real authority
			// delimiter for a parser (host becomes `a`, not `hooks.zapier.com`) —
			// the control case for the encoded-backslash-in-userinfo fix:
			// url_view() turns this one into `/`, same as a parser reads it.
			'literal backslash in userinfo ends the authority' => array( 'https://a\\@hooks.zapier.com\\x' ),
		);
	}

	/** Every UTS-46 example, as the literal character, as an HTML reference of the code point, and as percent escapes of its UTF-8 bytes. */
	public static function uts46_cases(): array {
		$hosts = array(
			'fullwidth h'     => array( "\u{FF48}ooks.zapier.com", 'hooks/catch/1/zsecret9', 'zapier', 'zsecret9' ),
			'math bold h'     => array( "\u{1D421}ooks.zapier.com", 'hooks/catch/1/zsecret9', 'zapier', 'zsecret9' ),
			'circled h'       => array( "\u{24D7}ooks.zapier.com", 'hooks/catch/1/zsecret9', 'zapier', 'zsecret9' ),
			'ideographic dot' => array( "hooks\u{3002}zapier.com", 'hooks/catch/1/zsecret9', 'zapier', 'zsecret9' ),
			'fullwidth dot'   => array( "hook.eu2\u{FF0E}make.com", 'abc123secret', 'make', 'abc123secret' ),
			'soft hyphen'     => array( "hooks\u{00AD}.slack.com", 'services/T0/B0/SLACKSECRET', 'slack', 'SLACKSECRET' ),
			'zwsp'            => array( "hooks\u{200B}.zapier.com", 'hooks/catch/1/zsecret9', 'zapier', 'zsecret9' ),
			'bom'             => array( "\u{FEFF}discord.com", 'api/webhooks/1/dsecret-tok', 'discord', 'dsecret-tok' ),
			'digit one dot'   => array( "api\u{2488}telegram.org", 'bot1:AAsecret_x/x', 'telegram', 'AAsecret_x' ), // "api1.telegram.org" is NOT a receiver — see the kept row below
		);
		unset( $hosts['digit one dot'] );
		$cases = array();
		foreach ( $hosts as $name => $r ) {
			// An HTML reference names a CODE POINT (`&#xFF48;`); a percent escape
			// names a BYTE (`%EF%BD%88`). Both decode to the same UTF-8.
			$refs = '';
			foreach ( preg_split( '//u', $r[0], -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
				$refs .= strlen( $ch ) > 1 ? '&#x' . strtoupper( dechex( self::code_point( $ch ) ) ) . ';' : $ch;
			}
			$pcts = '';
			foreach ( str_split( $r[0] ) as $byte ) {
				$pcts .= ord( $byte ) > 0x7F ? '%' . strtoupper( dechex( ord( $byte ) ) ) : $byte;
			}
			$cases[ "{$name} / literal" ]        = array( "https://{$r[0]}/{$r[1]}", $r[2], $r[3] );
			$cases[ "{$name} / bare literal" ]   = array( "{$r[0]}/{$r[1]}", $r[2], $r[3] );
			$cases[ "{$name} / code point refs" ] = array( "https://{$refs}/{$r[1]}", $r[2], $r[3] );
			$cases[ "{$name} / pct bytes" ]      = array( "https://{$pcts}/{$r[1]}", $r[2], $r[3] );
			$cases[ "{$name} / backslash path" ] = array( "https://{$r[0]}\\" . strtr( $r[1], '/', '\\' ), $r[2], $r[3] );
		}
		return $cases;
	}

	/** The code point of one UTF-8 character (no mbstring). */
	private static function code_point( string $ch ): int {
		$b = array_values( unpack( 'C*', $ch ) );
		switch ( count( $b ) ) {
			case 1:
				return $b[0];
			case 2:
				return ( ( $b[0] & 0x1F ) << 6 ) | ( $b[1] & 0x3F );
			case 3:
				return ( ( $b[0] & 0x0F ) << 12 ) | ( ( $b[1] & 0x3F ) << 6 ) | ( $b[2] & 0x3F );
			default:
				return ( ( $b[0] & 0x07 ) << 18 ) | ( ( $b[1] & 0x3F ) << 12 ) | ( ( $b[2] & 0x3F ) << 6 ) | ( $b[3] & 0x3F );
		}
	}

	/** @dataProvider uts46_cases */
	public function test_a_host_a_parser_normalises_is_redacted( string $url, string $kind, string $secret ): void {
		foreach ( array( $url, "see {$url} now", "{$url}." ) as $in ) {
			$out = $this->text( $in, $count );
			$this->assertStringNotContainsString( $secret, $out, $in );
			$this->assertStringContainsString( 'aura-redacted:v1:' . $kind, $out, $in );
		}
	}

	/** @dataProvider uts46_kept */
	public function test_a_host_a_parser_keeps_different_is_kept( string $in ): void {
		$this->assertSame( $in, $this->text( $in, $count ), $in );
		$this->assertSame( 0, $count );
	}

	public static function uts46_kept(): array {
		return array(
			'zwj inside the host (deviation)' => array( "https://hooks\u{200D}.zapier.com/x" ),
			'combining mark (no NFC)'         => array( "https://h\u{0301}ooks.zapier.com/x" ),
			'cyrillic shha'                   => array( "https://\u{04BB}ooks.zapier.com/x" ),
			'digit one dot: another host'     => array( "https://api\u{2488}telegram.org/bot1:AAx/x" ),
			'hebrew prose with slashes'       => array( "\u{05E9}\u{05DC}\u{05D5}\u{05DD}/\u{05E2}\u{05D5}\u{05DC}\u{05DD} hooks.example.com/x" ),
			'arabic prose with slashes'       => array( "\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}/\u{0628}\u{0643}" ),
			'emoji with slashes'              => array( "\u{1F600}/\u{1F601} example.com/x" ),
		);
	}

	public function test_a_mapped_character_inside_a_lookalike_host_is_the_view_2_lookalike_cost(): void {
		// Stage 1 (frozen) already reads the non-ASCII byte before `hooks` as a
		// host boundary and redacts the receiver; the fullwidth prefix stays.
		// Not a stage 2 case.
		$this->assertSame( "\u{FF4D}\u{FF59}aura-redacted:v1:zapier", $this->text( "\u{FF4D}\u{FF59}hooks.zapier.com/x" ) );
		// The view 2 lookalike cost proper: a mapped character INSIDE the host
		// (U+FF4F -> `o`) makes stage 1 miss it, and stage 2 — with no left
		// boundary — redacts the ASCII-prefixed lookalike whole (#113 owner
		// decision, extended to view 2).
		$this->assertSame( 'aura-redacted:v1:zapier', $this->text( "myhooks.zapier.c\u{FF4F}m/x" ) );
		// Its plain ASCII twin never enters stage 2 and is kept by stage 1's boundary.
		$this->assertSame( 'myhooks.zapier.com/x', $this->text( 'myhooks.zapier.com/x' ) );
	}

	/** @dataProvider encoded_backslash_then_json_escape */
	public function test_an_encoded_backslash_before_a_json_escape_is_read_in_the_raw_layer( string $in, string $kind ): void {
		// D11: `%5Cu0061` decodes to `a` within ONE pass, so no layer ever holds
		// the backslash; url_view() reads the encoded one in the raw layer.
		$out = $this->text( $in, $count );
		$this->assertSame( 'aura-redacted:v1:' . $kind, $out, $in );
		$this->assertSame( 1, $count );
	}

	public static function encoded_backslash_then_json_escape(): array {
		$cases = array();
		// Restricted to the receivers whose pattern accepts any path after the
		// host (make, celonis, integromat, zapier): slack/discord/discordapp/
		// ifttt/telegram require a fixed path segment (`services/`,
		// `api/webhooks/`, `trigger/…/with/key/`, `bot<digits>:`) right after
		// the host; with `u0061<secret>` glued there, no reader — percent-
		// decoding, JSON, or URL parser — resolves the text to a WORKING
		// receiver, so there is nothing to redact. Their encoded-backslash
		// forms with the real path are covered by schemed_forms()'s `%5C` /
		// `&#92;` / `&#x5C;` rows.
		foreach ( array( 'make', 'celonis', 'integromat', 'zapier' ) as $name ) {
			$r = self::RECEIVERS[ $name ];
			foreach ( array( '%5C', '%5c', '&#92;', '&#092', '&#x5C;', '&#x5c', '&bsol;' ) as $enc ) {
				// `u0061` + the secret: a JSON escape for a percent/HTML-decoding reader.
				$cases[ "{$name} / {$enc}" ] = array( "https://{$r[0]}{$enc}u0061{$r[3]}", $r[2] );
			}
		}
		$cases['zapier / encoded backslash, no escape after it'] = array( 'https://hooks.zapier.com%5Chooks%5Ccatch%5C1%5CS', 'zapier' );
		return $cases;
	}

	public function test_an_encoded_scheme_colon_before_a_receiver_host_is_the_lookalike_cost(): void {
		// D8: the plain file: URL is kept (RE_HEAD_SCHEMED's lookbehind sees its
		// colon); an ENCODED colon is not a colon in the raw layer's URL view, so
		// the run is redacted — stage 2's accepted lookalike cost, not a leak.
		$this->assertSame( 'file://hooks.zapier.com\\x', $this->text( 'file://hooks.zapier.com\\x' ) );
		foreach ( array( 'file&#58;//hooks.zapier.com\\x', 'file\\u003A//hooks.zapier.com\\x', 'file%253A//hooks.zapier.com\\x' ) as $in ) {
			$this->assertSame( 'aura-redacted:v1:zapier', $this->text( $in ), $in );
		}
	}

	public function test_the_write_guard_is_unchanged(): void {
		$this->assertTrue( Aura_Worker_Redact::holds_placeholder( array( 'content' => 'aura-redacted:v1:zapier' ) ) );
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( array( 'content' => "https://\u{FF48}ooks.zapier.com/x" ) ) );
	}

	public function test_hebrew_prose_without_a_slash_is_returned_as_the_same_string(): void {
		$in = str_repeat( "\u{05E9}\u{05DC}\u{05D5}\u{05DD} \u{05E2}\u{05D5}\u{05DC}\u{05DD} ", 50000 );
		$this->assertSame( $in, $this->text( $in ) );
	}

	public function test_a_megabyte_of_hebrew_prose_with_slashes_is_linear(): void {
		$word = "\u{05E9}\u{05DC}\u{05D5}\u{05DD}/\u{05E2}\u{05D5}\u{05DC}\u{05DD} ";
		$in   = str_repeat( $word, (int) ( 1048576 / strlen( $word ) ) );
		foreach ( array( 1, 0 ) as $jit ) {
			$old = ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			try {
				$start = microtime( true );
				$this->assertSame( $in, $this->text( $in ) );
				$this->assertLessThan( 2.0, microtime( true ) - $start, "pcre.jit={$jit}" );
			} finally {
				ini_set( 'pcre.jit', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			}
		}
	}

	public function test_a_megabyte_of_cjk_prose_with_slashes_is_linear(): void {
		// CJK lead bytes ARE in Aura_Worker_Redact_Idna::LEAD (U+3002 is mapped), so
		// every such run pays the strtr(): this pins that it is still linear.
		$word = "\u{65E5}\u{672C}\u{8A9E}\u{3002}/\u{30C6}\u{30B9}\u{30C8} ";
		$in   = str_repeat( $word, (int) ( 1048576 / strlen( $word ) ) );
		$start = microtime( true );
		$this->assertSame( $in, $this->text( $in ) );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}

	public function test_url_patterns_require_a_prefix_and_keep_kinds_and_order(): void {
		$stage_2 = Aura_Worker_Redact::stage_2_patterns();
		$url     = Aura_Worker_Redact::url_patterns();
		$this->assertCount( count( $stage_2 ), $url );
		foreach ( $url as $i => $pattern ) {
			$this->assertSame( $stage_2[ $i ][0], $pattern[0] );
			$this->assertSame( 0, preg_match( $pattern[1], 'hooks.zapier.com/hooks/catch/1/x' ), $pattern[0] . ': a bare host must not match' );
		}
		$this->assertSame( 1, preg_match( $url[2][1], 'https:hooks.zapier.com/hooks/catch/1/x' ) );
		$this->assertSame( 1, preg_match( $url[2][1], '//hooks.zapier.com/hooks/catch/1/x' ) );
		$this->assertSame( 0, preg_match( $url[2][1], '/hooks.zapier.com/hooks/catch/1/x' ) );
		$this->assertSame( 0, preg_match( $url[2][1], 'file://hooks.zapier.com/hooks/catch/1/x' ), 'another scheme\'s // is not protocol-relative' );
		$this->assertSame( 0, preg_match( $url[2][1], 'file%3A//hooks.zapier.com/hooks/catch/1/x' ) );
		$this->assertSame( 0, preg_match( $url[2][1], 'file:///hooks.zapier.com/hooks/catch/1/x' ), 'every two-slash start inside file:/// follows : or /' );
		$this->assertSame( 1, preg_match( $url[2][1], '////hooks.zapier.com/hooks/catch/1/x' ) );
		$this->assertSame( 1, preg_match( $url[2][1], 'https:////hooks.zapier.com/hooks/catch/1/x' ) );
		$this->assertSame( 1, preg_match( $url[2][1], 'ftp://hooks.zapier.com/hooks/catch/1/x' ) );
		// gopher is not a WHATWG special scheme, and the `//` right after
		// `gopher:` is blocked by the protocol-relative branch's own lookbehind
		// (a literal `:` precedes it).
		$this->assertSame( 0, preg_match( $url[2][1], 'gopher://hooks.zapier.com/hooks/catch/1/x' ) );
		// #116, Codex r1 on PR #120: userinfo reads to its LAST `@`, as a parser does.
		$this->assertSame( 1, preg_match( $url[2][1], 'https://a@b@hooks.zapier.com/hooks/catch/1/x' ) );
		$this->assertSame( 1, preg_match( $url[2][1], '//a@b@hooks.zapier.com/x' ) );
		// #116, Codex round 2: an encoded slash inside userinfo is not a delimiter
		// for a parser, unlike a literal one.
		$this->assertSame( 1, preg_match( $url[2][1], 'https://a%2Fb@hooks.zapier.com/hooks/catch/1/x' ) );
		$this->assertSame( 0, preg_match( $url[2][1], 'https://a/b@hooks.zapier.com/hooks/catch/1/x' ) );
	}

	/** @dataProvider url_view_cases */
	public function test_url_view_reads_exactly_the_backslash_forms( string $in, string $expect ): void {
		$method = new ReflectionMethod( Aura_Worker_Redact::class, 'url_view' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true ); // required before 8.1 for a private method; a deprecated no-op from 8.5
		}
		$this->assertSame( $expect, $method->invoke( null, $in ), $in );
	}

	public static function url_view_cases(): array {
		return array(
			// A literal backslash becomes `/`.
			'literal backslash'        => array( 'a\\b', 'a/b' ),
			// Every RE_ENC_BACKSLASH_ENCODED form becomes `%2f` — an ENCODED
			// slash, not `/` (#116, Codex r3 round 2 on PR #120): userinfo
			// keeps it (RE_USERINFO_ANY_AT accepts `%2f`), a host end or a
			// path segment reads it as the slash (RE_SLASH, RE_TAIL, #110).
			'%5C'                      => array( '%5C', '%2f' ),
			'%5c'                      => array( '%5c', '%2f' ),
			'&#92;'                    => array( '&#92;', '%2f' ),
			'&#092'                    => array( '&#092', '%2f' ),
			'&#0000092;'               => array( '&#0000092;', '%2f' ),
			'&#x5C;'                   => array( '&#x5C;', '%2f' ),
			'&#x5c'                    => array( '&#x5c', '%2f' ),
			'&#x005c;'                 => array( '&#x005c;', '%2f' ),
			'&bsol;'                   => array( '&bsol;', '%2f' ),
			// Everything else is left exactly as it is (the reject set is
			// unchanged from RE_ENC_BACKSLASH: only the accepted forms above
			// now map to `%2f` instead of `/`).
			'not 92: &#920;'           => array( '&#920;', '&#920;' ),
			'not 92: &#921;'           => array( '&#921;', '&#921;' ),
			'not 5c: &#x5cab'          => array( '&#x5cab', '&#x5cab' ),
			'double-encoded: %255C'    => array( '%255C', '%255C' ),
			'entity-escaped: &amp;#92;' => array( '&amp;#92;', '&amp;#92;' ),
			'tab stays'                => array( "a\tb", "a\tb" ),
			'a forward slash: %2F'     => array( '%2F', '%2F' ),
		);
	}
}
