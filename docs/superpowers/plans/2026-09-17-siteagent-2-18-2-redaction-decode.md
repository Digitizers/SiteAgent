# SiteAgent 2.18.2 — agent read redaction: decode, then match — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the encoded-URL class of redaction leaks (Digitizers/SiteAgent#113) with one mechanism. A second stage in `Aura_Worker_Redact::redact_text()` decodes each run of text (HTML5 character references, `%XX`, JSON escapes, up to four layers). When the decoded run holds a receiver URL, stage 2 replaces the whole original run.

**Architecture:** A new pure class, `Aura_Worker_Redact_Decode` (`includes/class-aura-worker-redact-decode.php`), owns `decode_run()`. In `Aura_Worker_Redact`, the 2.18.1 matcher moves unchanged into a private `redact_urls()` (stage 1). `redact_text()` runs stage 1, then the new `redact_encoded_runs()` (stage 2). Stage 2 splits the field into runs (`RE_RUN`) and decodes each run that holds `%`, `&` or `\`. It checks the decoded run against the stage 1 `URL_PATTERNS` with `preg_match` only. Carriers, the walk, the write guard and the grant handling do not change: every string already reaches `redact_text()`.

**Tech Stack:** PHP 7.4+ (CI matrix 7.4 / 8.1 / 8.2), WordPress 6.2+, PHPUnit (`vendor/bin/phpunit`, `--filter <name>`; PHPUnit 10.5 locally, 9 on the 7.4 job), PHPCS (`composer lint`). The harness stubs WordPress (`tests/bootstrap.php`); nothing from WordPress is loaded.

**Spec:** `Digitizers/Aura` — `docs/superpowers/specs/2026-09-17-redaction-decode-then-match-design.md` (merged @ `df540f9f`). This plan covers the whole spec except §7 steps 2–4: the version bump and the release are a separate PR.

**Baseline:** `main` at `b1d0791` (2.18.1). On PHP 8.5 / PHPUnit 10.5, `vendor/bin/phpunit` reports `Tests: 4043, Assertions: 14621, Deprecations: 10, PHPUnit Deprecations: 1`. Record your own numbers before Task 1. Afterwards the test count must be exactly 529 higher (86 + 443), and the deprecation counts must not change.

## Global Constraints

- `MAX_DECODE_PASSES = 4`: up to four passes, stopping early after the first pass that changes nothing. After pass 4, one more pass runs as a check only; if it would change the run, `decode_run()` returns `null`. Exactly four layers (`%2525252F`) decode, and five or more fail closed (spec §3.1).
- `MAX_DECODE_GROWTH = 3`: `decode_run()` returns `null` as soon as an intermediate value is longer than 3 × the original run. Runs have no length cap (spec §3.1).
- One pass runs three decoders, in this order (spec §3.1):
  - (1) HTML character references as HTML5 parses them in text:
    - numeric: `&#` + decimal, or `&#x`/`&#X` + hex; leading zeros allowed; `;` optional; the longest digit run. 0, a surrogate or a value above 0x10FFFF → U+FFFD. 0x80–0x9F go through the Windows-1252 table. The result is UTF-8.
    - named with `;`: `html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' )`.
    - legacy names without `;`: the longest match, with text-content semantics.
  - (2) `%` + two hex digits only. `+` stays, and an invalid escape stays.
  - (3) JSON `\uXXXX` (a surrogate pair becomes one code point; a lone surrogate is left as it is) and `\/` → `/`.
- Run: a maximal sequence of characters other than whitespace, `"`, `'`, `<`, `>`, found with `preg_replace_callback( '/[^\s"\'<>]++/', … )`. A run is never skipped for holding `aura-redacted:` (spec §3.2).
- Stage 2 (spec §3.2):
  - Field fast path: no `%`, `&` or `\` → the field is returned unchanged.
  - Run fast path: the same test per run.
  - `null` from the decoder → `aura-redacted:v1:field`.
  - An unchanged run is kept.
  - Otherwise the first matching `URL_PATTERNS` entry gives the kind. The whole run is replaced, then `trailing_punctuation( <original run> )` is appended.
  - Any PCRE failure → the whole field becomes `aura-redacted:v1:field`.
  - `$count` goes up by one per replaced run (by one for a failed field).
- Placeholder, exactly: `aura-redacted:v1:<kind>`, `<kind>` ∈ `make`, `integromat`, `zapier`, `slack`, `discord`, `ifttt`, `telegram`, `field`.
- Stage 1 stays byte-for-byte what 2.18.1 ships; only its method name changes (`redact_text` → private `redact_urls`). Existing tests keep their expected outputs, except the five data sets Task 2 lists (spec §5).
- `holds_placeholder()` (the write guard) does not decode. Carriers, walk, node budget, gateway and grant do not change (spec §3.3).
- No mbstring or intl dependency: UTF-8 is built by hand (`Aura_Worker_Redact_Decode::utf8()`). PHP 7.4 syntax only: no `match`, `str_contains`, union types, named arguments or trailing commas in calls.
- All new regexes are possessive or linear. A `preg_*` failure in the decoder → `null`.
- Coding conventions: tabs; `if ( ! defined( 'ABSPATH' ) ) { exit; }` in every plugin file; `Aura_Worker_*` prefix; `composer lint` green (the gate covers `digitizer-site-worker/` only); every `ini_set` in a test carries `// phpcs:ignore WordPress.PHP.IniSet.Risky`.
- **No version bump in this PR.** `Version:`, `AURA_WORKER_VERSION`, `Stable tag:`, the changelogs and the badge belong to the release PR (spec §7).
- Git:
  - `git add <path>` per file, never `-A` or `.`;
  - never `git stash`;
  - commit trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`;
  - one implementation PR, then the Codex review loop;
  - merge and release only on the owner's approval.

## Rulings on spec gaps (made while writing this plan — reviewers, check these first)

- **D1 — stage 2 runs even when stage 1's fast path returned early.** Stage 1 skips a field that has no `/` and no encoded slash. `hooks.zapier.com%252Fx` is such a field, so `redact_text()` always calls `redact_encoded_runs()` on stage 1's output. Stage 2 has its own fast path.
- **D2 — named references are decided per reference, in one callback.**
  - `RE_NAMED` matches `&` + a name + an optional `;`.
  - A name with `;` that `html_entity_decode()` knows is decoded that way.
  - Otherwise the callback takes the longest `LEGACY_NAMES` prefix of the name, as the HTML5 tokenizer does: `&notit;` → `¬it;`, `&ampx;` → `&x;`.
  - This keeps the spec's order (with-`;` first, then legacy). Two separate regex steps would turn a cascaded `&amp;` into `&;`, because the legacy step would take `&amp` and leave the `;` behind.
- **D3 — `html_entity_decode()` only ever sees one matched named reference, never the whole string.** Numeric references are therefore decoded only by the HTML5-mapped code. PHP's own numeric handling (it leaves `&#0;` and `&#128;` alone, among others) never runs.
- **D4 — `%XX` is decoded with `rawurldecode()`.** It decodes exactly `%` + two hex digits, leaves `+` and invalid escapes alone, and needs no regex.
- **D5 — numeric overflow.** A reference with more than 6 significant hex digits, or 7 decimal ones, is above 0x10FFFF whatever its digits are, so it becomes U+FFFD without being converted to an int.
- **D6 — punctuation.**
  - A `field` placeholder from a `null` decode gets no trailing punctuation (it is a fail-closed path).
  - A kind placeholder gets `trailing_punctuation( <original run> )`. That function is unchanged, so a run that ends in a reference's `;` (`…%252Fx&#x31;`) comes back as `aura-redacted:v1:zapier;`.
  - A test pins this, and CLAUDE.md lists it under Limits. No secret character is punctuation.
- **D7 — `receiver_kind()` reuses stage 1's fast reject** (`/`, or `may_hold_encoded_slash()`) before it runs any pattern.
  - `decode_run()` returns only a fixed point, so the returned value has no decodable encoding left, and a receiver URL in it needs a literal `/`.
  - This keeps wptexturize'd prose cheap: about 75 ms per MB, with JIT on or off.
- **D8 — the growth guard checks every pass result, before the check pass.** The check pass result is only compared, never kept.
- **D9 — which existing assertions change (spec §5's list, made exact).** The whole suite was run with this plan's code (PHP 8.5 / PHPUnit 10.5, and the Redact tests on PHP 7.4 / PHPUnit 9). Exactly five data sets changed, and each one now redacts:
  - `RedactEncodedSlashTest::negatives` `double encoded (out of scope)`;
  - `RedactEncodedSlashTest::round1_negatives` `decimal 470 before host`;
  - `RedactEncodedSlashTest::unterminated_at_negatives` `decimal 640` and `decimal 6400 scheme`;
  - `RedactReferenceTableTest::controls` `decimal 640`.

  Why the last four change: each reference decodes to a non-ASCII character (U+01D6, U+0280, U+1900). That character is not a hostname character, so the host after it is at a boundary, as in plain text.

  The spec's other examples do not change an existing assertion:
  - `x&#46hooks…` stays a control: a `.` before the host is no boundary.
  - Zero padding before `/`, `:` and `@` was already accepted by stage 1, and `x hooks.zapier.com&#0047;SECRET&#0047;` keeps its expected output.
  - Deep padding on other characters, and `&#46` inside a host, are new positives in `RedactEncodedRunTest`.
- **D10 — forcing a PCRE failure.** The stage 2 fail-closed test sets `pcre.jit=0` and `pcre.backtrack_limit=1` on a field with no `/` (stage 1 runs no regex on it), then restores both values in `finally`.

## File structure

| File | Responsibility |
|---|---|
| `digitizer-site-worker/includes/class-aura-worker-redact-decode.php` (new) | `Aura_Worker_Redact_Decode` — `decode_run()`, `decode_pass()`, `html5_code_point()`, `utf8()`, the legacy-name and Windows-1252 tables (Task 1) |
| `digitizer-site-worker/digitizer-site-worker.php` | `require_once` the decoder right before `class-aura-worker-redact.php` (line 80) (Task 1) |
| `tests/bootstrap.php` | the same `require_once`, right before the redact class (line 4386) (Task 1) |
| `tests/unit/RedactDecodeTest.php` (new) | Task 1 |
| `digitizer-site-worker/includes/class-aura-worker-redact.php` | `RE_RUN`; `redact_text()` = stage 1 + stage 2; `redact_urls()` (stage 1, moved), `redact_encoded_runs()`, `receiver_kind()`, `has_encoding_marker()` (Task 2) |
| `tests/unit/RedactEncodedRunTest.php` (new) | Task 2 |
| `tests/unit/RedactEncodedSlashTest.php`, `tests/unit/RedactReferenceTableTest.php` | the five changed data sets (D9) (Task 2) |
| `CLAUDE.md` | tree, class table, the stage 2 bullet, Limits (Task 3) |

Facts the implementer must not "fix":

- **Some table rows in `RedactEncodedRunTest` already pass before stage 2 exists.** The `pct path letter` / `html path letter` rows pass because stage 1's tail takes any path characters. They are coverage, not a mistake. With the new test file in place and the class unpatched, the file reports 406 failures out of 443.
- **Do not skip runs that hold `aura-redacted:`**, and do not teach `holds_placeholder()` to decode (spec §3.2 step 3.1, §3.3).
- **Do not touch stage 1's patterns, `trailing_punctuation()` or `may_hold_encoded_slash()`.** Stage 2 reuses them as they are.
- **Memory on a bare `php:7.4-cli` container.** Its default `memory_limit` is 128M, and `--filter Redact` runs out of memory there on the unpatched `main` too. CI (`setup-php`) has no such limit. To reproduce locally, run with `-d memory_limit=-1`.

---

### Task 1: `Aura_Worker_Redact_Decode` — the pure decoder

**Files:**
- Create: `digitizer-site-worker/includes/class-aura-worker-redact-decode.php`
- Modify: `digitizer-site-worker/digitizer-site-worker.php:80`
- Modify: `tests/bootstrap.php:4386`
- Test: `tests/unit/RedactDecodeTest.php`

**Interfaces:**
- Consumes: nothing (pure PHP; `html_entity_decode`, `rawurldecode`, `preg_replace_callback`).
- Produces (all `public static`, used by Task 2 and the tests):
  - `Aura_Worker_Redact_Decode::decode_run( string $run ): ?string` — the decoded run; `$run` itself when nothing decodes; `null` past `MAX_DECODE_PASSES`, past `MAX_DECODE_GROWTH`, or on a PCRE failure.
  - `Aura_Worker_Redact_Decode::decode_pass( string $text ): ?string` — one pass; `null` on a PCRE failure.
  - `Aura_Worker_Redact_Decode::html5_code_point( int $value ): int`
  - `Aura_Worker_Redact_Decode::utf8( int $cp ): string`
  - constants `MAX_DECODE_PASSES` (4), `MAX_DECODE_GROWTH` (3), `LEGACY_NAMES` (106 strings), `LEGACY_MAX_LENGTH` (6), `WINDOWS_1252`, `REPLACEMENT_CHARACTER`, `RE_NUMERIC`, `RE_NAMED`, `RE_JSON_UNICODE`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/RedactDecodeTest.php`:

```php
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
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit tests/unit/RedactDecodeTest.php`
Expected: FAIL. PHPUnit 10.5 reports `Tests: 47, Assertions: 3, Errors: 48.`, with `Error: Class "Aura_Worker_Redact_Decode" not found`: the data providers call `utf8()`, so their data sets error out too.

- [ ] **Step 3: Write the decoder**

Create `digitizer-site-worker/includes/class-aura-worker-redact-decode.php`:

```php
<?php
/**
 * Agent read redaction, stage 2: decode a run before matching it (#113).
 *
 * Pure — no WordPress. `decode_run()` undoes the encodings a page author,
 * WordPress (kses, wptexturize) or a URL encoder can wrap a receiver URL in,
 * up to MAX_DECODE_PASSES layers, so Aura_Worker_Redact can judge the
 * decoded text with the same patterns it uses on plain text.
 *
 * One pass applies, in this order:
 *  1. HTML character references, as HTML5 parses them in text content —
 *     numeric (`&#N`, `&#xH`, `;` optional, longest digit run, the HTML5
 *     end-state mapping), then named: a name followed by `;` that
 *     html_entity_decode() knows, else the longest LEGACY name without `;`;
 *  2. percent escapes (`%XX` only — `+` stays, an invalid `%` stays);
 *  3. JSON escapes (`\uXXXX`, a surrogate pair as one code point, a lone
 *     surrogate left as it is; `\/`).
 * A later step in the same pass sees what an earlier one produced; that
 * only ever decodes more, never less.
 *
 * Spec: Digitizers/Aura
 * docs/superpowers/specs/2026-09-17-redaction-decode-then-match-design.md §3.1.
 *
 * @package Aura_Worker
 * @since 2.18.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Redact_Decode {

	/** Layers of encoding decoded; one more layer makes decode_run() return null. */
	const MAX_DECODE_PASSES = 4;

	/** An intermediate value longer than this × the original run makes decode_run() return null. */
	const MAX_DECODE_GROWTH = 3;

	/** U+FFFD, what HTML5 reads for an invalid numeric reference. */
	const REPLACEMENT_CHARACTER = 0xFFFD;

	/**
	 * Regex: an HTML5 numeric character reference — `&#` then decimal
	 * digits, or `&#x` / `&#X` then hex digits; leading zeros allowed; the
	 * longest digit run (possessive); `;` optional. `&#x` with no hex digit
	 * is not a reference (the decimal branch cannot match an `x` either).
	 */
	const RE_NUMERIC = '/&#(?:[xX]([0-9A-Fa-f]++)|([0-9]++));?+/';

	/**
	 * Regex: a named reference candidate — `&`, an ASCII letter, then
	 * alphanumerics (possessive: the whole name), then an optional `;`.
	 */
	const RE_NAMED = '/&([A-Za-z][A-Za-z0-9]*+)(;?+)/';

	/**
	 * Regex: a JSON `\u` escape — a valid surrogate pair first, else one
	 * code unit.
	 */
	const RE_JSON_UNICODE = '/\\\\u(?:(d[89ab][0-9a-f]{2})\\\\u(d[c-f][0-9a-f]{2})|([0-9a-f]{4}))/i';

	/** The longest name in LEGACY_NAMES. */
	const LEGACY_MAX_LENGTH = 6;

	/**
	 * HTML5's named character references that are also recognised WITHOUT
	 * `;` in text (the "legacy" entries of the WHATWG named character
	 * reference table) — 106 names.
	 */
	const LEGACY_NAMES = array(
		'AElig', 'AMP', 'Aacute', 'Acirc', 'Agrave', 'Aring', 'Atilde', 'Auml', 'COPY', 'Ccedil',
		'ETH', 'Eacute', 'Ecirc', 'Egrave', 'Euml', 'GT', 'Iacute', 'Icirc', 'Igrave', 'Iuml',
		'LT', 'Ntilde', 'Oacute', 'Ocirc', 'Ograve', 'Oslash', 'Otilde', 'Ouml', 'QUOT', 'REG',
		'THORN', 'Uacute', 'Ucirc', 'Ugrave', 'Uuml', 'Yacute', 'aacute', 'acirc', 'acute', 'aelig',
		'agrave', 'amp', 'aring', 'atilde', 'auml', 'brvbar', 'ccedil', 'cedil', 'cent', 'copy',
		'curren', 'deg', 'divide', 'eacute', 'ecirc', 'egrave', 'eth', 'euml', 'frac12', 'frac14',
		'frac34', 'gt', 'iacute', 'icirc', 'iexcl', 'igrave', 'iquest', 'iuml', 'laquo', 'lt',
		'macr', 'micro', 'middot', 'nbsp', 'not', 'ntilde', 'oacute', 'ocirc', 'ograve', 'ordf',
		'ordm', 'oslash', 'otilde', 'ouml', 'para', 'plusmn', 'pound', 'quot', 'raquo', 'reg',
		'sect', 'shy', 'sup1', 'sup2', 'sup3', 'szlig', 'thorn', 'times', 'uacute', 'ucirc',
		'ugrave', 'uml', 'uuml', 'yacute', 'yen', 'yuml',
	);

	/**
	 * HTML5's replacement table for numeric references in 0x80–0x9F
	 * (the Windows-1252 reading). A value in that range that is not a key
	 * here is used as it is.
	 */
	const WINDOWS_1252 = array(
		0x80 => 0x20AC,
		0x82 => 0x201A,
		0x83 => 0x0192,
		0x84 => 0x201E,
		0x85 => 0x2026,
		0x86 => 0x2020,
		0x87 => 0x2021,
		0x88 => 0x02C6,
		0x89 => 0x2030,
		0x8A => 0x0160,
		0x8B => 0x2039,
		0x8C => 0x0152,
		0x8E => 0x017D,
		0x91 => 0x2018,
		0x92 => 0x2019,
		0x93 => 0x201C,
		0x94 => 0x201D,
		0x95 => 0x2022,
		0x96 => 0x2013,
		0x97 => 0x2014,
		0x98 => 0x02DC,
		0x99 => 0x2122,
		0x9A => 0x0161,
		0x9B => 0x203A,
		0x9C => 0x0153,
		0x9E => 0x017E,
		0x9F => 0x0178,
	);

	/**
	 * LEGACY_NAMES as a lookup (name => true), built once.
	 *
	 * @var array<string,bool>|null
	 */
	private static $legacy = null;

	/**
	 * Decode $run up to MAX_DECODE_PASSES layers.
	 *
	 * @param string $run One run of text (no whitespace, quote or angle bracket).
	 * @return string|null The decoded run — $run itself when nothing decodes —
	 *                     or null: more than MAX_DECODE_PASSES layers, growth
	 *                     past MAX_DECODE_GROWTH, or a PCRE failure.
	 */
	public static function decode_run( $run ) {
		$run     = (string) $run;
		$limit   = self::MAX_DECODE_GROWTH * strlen( $run );
		$current = $run;
		for ( $pass = 0; $pass < self::MAX_DECODE_PASSES; ++$pass ) {
			$next = self::decode_pass( $current );
			if ( null === $next || strlen( $next ) > $limit ) {
				return null;
			}
			if ( $next === $current ) {
				return $current; // a fixed point: nothing more to decode
			}
			$current = $next;
		}
		// The check pass: a fifth layer is refused, never guessed at.
		return self::decode_pass( $current ) === $current ? $current : null;
	}

	/**
	 * One pass of every decoder, in the order of the class comment.
	 *
	 * @param string $text Text.
	 * @return string|null Null on a PCRE failure.
	 */
	public static function decode_pass( $text ) {
		if ( false !== strpos( $text, '&' ) ) {
			$text = preg_replace_callback( self::RE_NUMERIC, array( __CLASS__, 'numeric_reference' ), $text );
			if ( null === $text ) {
				return null;
			}
			$text = preg_replace_callback( self::RE_NAMED, array( __CLASS__, 'named_reference' ), $text );
			if ( null === $text ) {
				return null;
			}
		}
		if ( false !== strpos( $text, '%' ) ) {
			$text = rawurldecode( $text ); // `%XX` only: `+` stays, an invalid escape stays
		}
		if ( false !== strpos( $text, '\\' ) ) {
			$text = preg_replace_callback( self::RE_JSON_UNICODE, array( __CLASS__, 'json_unicode' ), $text );
			if ( null === $text ) {
				return null;
			}
			$text = str_replace( '\\/', '/', $text );
		}
		return $text;
	}

	/**
	 * The code point HTML5 reads for a numeric reference's value
	 * ("numeric character reference end state").
	 *
	 * @param int $value The reference's value.
	 * @return int
	 */
	public static function html5_code_point( $value ) {
		if ( 0 === $value || $value > 0x10FFFF || ( $value >= 0xD800 && $value <= 0xDFFF ) ) {
			return self::REPLACEMENT_CHARACTER;
		}
		return isset( self::WINDOWS_1252[ $value ] ) ? self::WINDOWS_1252[ $value ] : $value;
	}

	/**
	 * UTF-8 bytes of a code point (0..0x10FFFF, not a surrogate) — no
	 * mbstring.
	 *
	 * @param int $cp Code point.
	 * @return string
	 */
	public static function utf8( $cp ) {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	/**
	 * RE_NUMERIC callback.
	 *
	 * @param array<int,string> $m Match: [1] hex digits, or [2] decimal digits.
	 * @return string
	 */
	private static function numeric_reference( array $m ) {
		$hex    = '' !== $m[1];
		$digits = ltrim( $hex ? $m[1] : $m[2], '0' );
		// Past 6 hex / 7 decimal significant digits the value is above
		// 0x10FFFF whatever the digits are — and would overflow an int.
		if ( strlen( $digits ) > ( $hex ? 6 : 7 ) ) {
			$value = 0x110000;
		} elseif ( '' === $digits ) {
			$value = 0;
		} else {
			$value = $hex ? (int) hexdec( $digits ) : (int) $digits;
		}
		return self::utf8( self::html5_code_point( $value ) );
	}

	/**
	 * RE_NAMED callback: the name with its `;` when html_entity_decode()
	 * knows it, else the longest legacy name at the start of the name,
	 * as the HTML5 tokenizer matches it. Anything else stays as it is.
	 *
	 * @param array<int,string> $m Match: [0] whole, [1] name, [2] `;` or ''.
	 * @return string
	 */
	private static function named_reference( array $m ) {
		if ( ';' === $m[2] ) {
			$decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded !== $m[0] ) {
				return $decoded;
			}
		}
		if ( null === self::$legacy ) {
			self::$legacy = array_fill_keys( self::LEGACY_NAMES, true );
		}
		$name = $m[1];
		for ( $len = min( strlen( $name ), self::LEGACY_MAX_LENGTH ); $len >= 2; --$len ) {
			$prefix = substr( $name, 0, $len );
			if ( isset( self::$legacy[ $prefix ] ) ) {
				$value = html_entity_decode( '&' . $prefix . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return $value . substr( $name, $len ) . $m[2];
			}
		}
		return $m[0];
	}

	/**
	 * RE_JSON_UNICODE callback.
	 *
	 * @param array<int,string> $m Match: [1][2] a surrogate pair, or [3] one code unit.
	 * @return string
	 */
	private static function json_unicode( array $m ) {
		if ( isset( $m[3] ) && '' !== $m[3] ) {
			$unit = (int) hexdec( $m[3] );
			if ( $unit >= 0xD800 && $unit <= 0xDFFF ) {
				return $m[0]; // a lone surrogate stays as it is
			}
			return self::utf8( $unit );
		}
		$high = (int) hexdec( $m[1] );
		$low  = (int) hexdec( $m[2] );
		return self::utf8( 0x10000 + ( ( $high - 0xD800 ) << 10 ) + ( $low - 0xDC00 ) );
	}
}
```

- [ ] **Step 4: Load it — plugin and test harness**

In `digitizer-site-worker/digitizer-site-worker.php`, replace

```php
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-redact.php';
```

with

```php
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-redact-decode.php';
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-redact.php';
```

In `tests/bootstrap.php`, replace

```php
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-redact.php';
```

with

```php
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-redact-decode.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-redact.php';
```

- [ ] **Step 5: Run the test and make sure it passes**

Run: `vendor/bin/phpunit tests/unit/RedactDecodeTest.php`
Expected: `OK (86 tests, …)`: 86 tests, all passing. The assertion count depends on the PHP build (the HTML5 table size), so it is not a gate.

Then run: `vendor/bin/phpunit`
Expected: the baseline count + 86 tests, with no failures and the baseline deprecation counts. `UninstallCoverageTest` scans the plugin's PHP sources, and the new file must not trip it.

- [ ] **Step 6: Lint**

Run: `composer lint`
Expected: no errors (warnings are allowed by the gate; the new plugin file produces none).

- [ ] **Step 7: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-redact-decode.php digitizer-site-worker/digitizer-site-worker.php tests/bootstrap.php tests/unit/RedactDecodeTest.php
git commit -m "feat(redact): Aura_Worker_Redact_Decode — HTML5 references, %XX, JSON escapes, bounded passes (#113)

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: stage 2 in `redact_text()` — decode, then match

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-redact.php` (after `ENC_SLASH_END` ~line 211; `redact_text()` ~lines 855–899; before `trailing_punctuation()` ~line 901)
- Modify: `tests/unit/RedactEncodedSlashTest.php` (lines 9, 196, 295, 412–413, and after line 423)
- Modify: `tests/unit/RedactReferenceTableTest.php` (line 139, and before line 155)
- Test: `tests/unit/RedactEncodedRunTest.php`

**Interfaces:**
- Consumes: `Aura_Worker_Redact_Decode::decode_run( string ): ?string` (Task 1); the existing `Aura_Worker_Redact::URL_PATTERNS`, `PLACEHOLDER`, `trailing_punctuation( string ): string`, `may_hold_encoded_slash( string ): bool`.
- Produces:
  - `Aura_Worker_Redact::redact_text( $text, &$count ): string` — public, same signature, now two stages.
  - `Aura_Worker_Redact::RE_RUN` — `'/[^\s"\'<>]++/'`.
  - private `redact_urls( $text, &$count ): string` (stage 1, the old body), `redact_encoded_runs( $text, &$count ): string`, `receiver_kind( $decoded ): string|false` (`''` = no receiver, `false` = PCRE failure), `has_encoding_marker( $text ): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/RedactEncodedRunTest.php`:

```php
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

	/** @return array<string,array{0:string}> */
	public static function controls(): array {
		return array(
			'encoded digit prefix'        => array( 'x%31hooks.zapier.com%2Fx' ),
			'encoded dot prefix'          => array( 'evil%2Ehooks.zapier.com%252Fx' ),
			'encoded prefixed host'       => array( 'myhook%2Eeu2.make.com%2Fabc' ),
			'hex 40d before discord'      => array( '&#x40discord.com/api/webhooks/1/x' ),
			'userinfo hex 40d'            => array( 'https://user&#x40discord.com/api/webhooks/1/x' ),
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

	public function test_a_pcre_failure_fails_the_field_closed(): void {
		$jit   = ini_get( 'pcre.jit' );
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		ini_set( 'pcre.backtrack_limit', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			// No `/` and no encoded slash: stage 1 never runs a regex, stage 2 does.
			$this->assertSame( 'aura-redacted:v1:field', $this->text( 'keep hooks.zapier.com%252Fx keep', $n ) );
			$this->assertSame( 1, $n );
			$this->assertSame( 'plain words only', $this->text( 'plain words only', $n ), 'the field fast path runs no regex' );
			$this->assertSame( 0, $n );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
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
		$answer   = array( 'found' => true, 'record' => array( 'id' => 'snap_113' ), 'payload' => base64_encode( serialize( $captured ) ) );

		$out = Aura_Worker_Redact::redact( array( 'success' => true, 'result' => $answer ), $n );

		$this->assertSame( 1, $n );
		$back = unserialize( (string) base64_decode( $out['result']['payload'], true ), array( 'allowed_classes' => false ) );
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
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit tests/unit/RedactEncodedRunTest.php`
Expected: FAIL — `Tests: 443, …, Failures: 406.` The rows that already pass are the controls, the perf test, and the path-letter rows (see "Facts").

- [ ] **Step 3: Update the five existing data sets that recorded a limit #113 closes (D9)**

In `tests/unit/RedactEncodedSlashTest.php`:

Replace the header line

```php
 * Double encoding (`%252F`, `&amp;#x2F;`) is out of scope.
```

with

```php
 * Double encoding (`%252F`, `&amp;#x2F;`) is stage 2's (#113): see
 * RedactEncodedRunTest, and closed_limits() below.
```

In `negatives()`, delete the line

```php
			'double encoded (out of scope)' => array( 'https%253A%252F%252Fhook.eu2.make.com%252Fabc123secret' ),
```

In `round1_negatives()`, delete the line

```php
			'decimal 470 before host' => array( 'x&#470hooks.zapier.com/SECRET' ),
```

In `unterminated_at_negatives()`, delete the two lines

```php
			'decimal 640'         => array( 'user&#640hooks.zapier.com/hooks/SECRET' ),
			'decimal 6400 scheme' => array( 'https://user&#6400hooks.zapier.com/hooks/SECRET' ),
```

Then, between the end of `test_unterminated_at_controls_stay_untouched()` and the `// --- carriers ---` comment, insert:

```php
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

```

In `tests/unit/RedactReferenceTableTest.php`, in `controls()`, delete the line

```php
			'decimal 640'       => array( 'https://user&#640hooks.zapier.com/hooks/SECRET' ),
```

and insert, right before `	/** @dataProvider controls */`:

```php
	public function test_a_non_ascii_reference_before_a_host_is_a_boundary(): void {
		// #113: `&#640` is U+0280, not a hostname character, so the host
		// after it is at a boundary, as in plain text (was a control).
		$n = 0;
		$this->assertSame( 'aura-redacted:v1:zapier', Aura_Worker_Redact::redact_text( 'https://user&#640hooks.zapier.com/hooks/SECRET', $n ) );
		$this->assertSame( 1, $n );
	}

```

Run: `vendor/bin/phpunit --filter 'RedactEncodedSlashTest|RedactReferenceTableTest'`
Expected: FAIL — exactly 5 failures: the 4 `closed_limits` data sets and `test_a_non_ascii_reference_before_a_host_is_a_boundary`.

- [ ] **Step 4: Add `RE_RUN`**

In `digitizer-site-worker/includes/class-aura-worker-redact.php`, right after the line

```php
	const ENC_SLASH_END = '/(?:&#0*47|&#x0*2f|&sol);([.,;:!?]*)$/i';
```

and its following blank line, insert (before the `Known receivers (spec §2.1)` docblock):

```php
	/**
	 * Regex: one run for stage 2 (#113) — a maximal stretch of characters
	 * other than whitespace, `"`, `'`, `<` and `>`. Possessive.
	 */
	const RE_RUN = '/[^\s"\'<>]++/';
```

- [ ] **Step 5: Split `redact_text()` into stage 1 + stage 2**

Replace the docblock and first two lines of `redact_text()`:

```php
	/**
	 * The URL detector over one string (spec §2.1). Replaces exactly the
	 * matched URL, so surrounding text and JSON stay valid.
	 *
	 * @param string $text  Text.
	 * @param int    $count In/out: replacements so far.
	 * @return string
	 */
	public static function redact_text( $text, &$count ) {
		$text = (string) $text;
		if ( false === strpos( $text, '/' ) && ! self::may_hold_encoded_slash( $text ) ) {
```

with:

```php
	/**
	 * The URL detector over one string (spec §2.1). Stage 1 (redact_urls())
	 * replaces exactly the matched URL, so surrounding text and JSON stay
	 * valid; stage 2 (redact_encoded_runs(), #113) then replaces every run
	 * whose DECODED form holds a receiver URL.
	 *
	 * @param string $text  Text.
	 * @param int    $count In/out: replacements so far.
	 * @return string
	 */
	public static function redact_text( $text, &$count ) {
		$text = self::redact_urls( (string) $text, $count );
		return self::redact_encoded_runs( $text, $count );
	}

	/**
	 * Stage 1 (2.18.0/2.18.1, unchanged): the receiver patterns over the
	 * text as it is.
	 *
	 * @param string $text  Text.
	 * @param int    $count In/out: replacements so far.
	 * @return string
	 */
	private static function redact_urls( $text, &$count ) {
		if ( false === strpos( $text, '/' ) && ! self::may_hold_encoded_slash( $text ) ) {
```

The rest of the old body (the fast-reject comment, the `foreach ( self::URL_PATTERNS …)` loop, `return $text;` and the closing `}`) stays exactly as it is and is now the body of `redact_urls()`.

- [ ] **Step 6: Add stage 2**

Right after the closing `}` of `redact_urls()`, and before the docblock that starts `The sentence punctuation at the end of a matched URL`, insert:

```php
	/**
	 * Stage 2 (#113): decode, then match. Each run — a maximal stretch of
	 * characters other than whitespace, `"`, `'`, `<` and `>` (RE_RUN) —
	 * that holds a `%`, `&` or `\` is decoded
	 * (Aura_Worker_Redact_Decode::decode_run()). When the decoded run holds
	 * a receiver URL by the stage 1 patterns, the WHOLE original run is
	 * replaced with that receiver's placeholder, its trailing sentence
	 * punctuation handed back; a run that cannot be decoded within the
	 * bounds becomes the field placeholder. A run holding `aura-redacted:`
	 * is decoded like any other: a literal marker must not shield an
	 * encoded URL next to it.
	 *
	 * @param string $text  Stage 1's output.
	 * @param int    $count In/out: replacements so far.
	 * @return string
	 */
	private static function redact_encoded_runs( $text, &$count ) {
		if ( ! self::has_encoding_marker( $text ) ) {
			return $text; // field fast path: nothing is encoded
		}
		$failed = false;
		$hits   = 0;
		$out    = preg_replace_callback(
			self::RE_RUN,
			static function ( $m ) use ( &$failed, &$hits ) {
				$run = $m[0];
				if ( $failed || ! self::has_encoding_marker( $run ) ) {
					return $run; // run fast path
				}
				$decoded = Aura_Worker_Redact_Decode::decode_run( $run );
				if ( null === $decoded ) {
					++$hits; // too deep, too large, or PCRE gave up: fail closed
					return self::PLACEHOLDER . 'field';
				}
				if ( $decoded === $run ) {
					return $run;
				}
				$kind = self::receiver_kind( $decoded );
				if ( false === $kind ) {
					$failed = true;
					return $run;
				}
				if ( '' === $kind ) {
					return $run;
				}
				++$hits;
				return self::PLACEHOLDER . $kind . self::trailing_punctuation( $run );
			},
			$text
		);
		if ( null === $out || $failed ) {
			// PCRE gave up on the split or on a pattern: none of the field goes out (R9).
			++$count;
			return self::PLACEHOLDER . 'field';
		}
		$count += $hits;
		return $out;
	}

	/**
	 * The kind of the first stage 1 pattern that matches $decoded, as a
	 * check only — nothing is replaced.
	 *
	 * @param string $decoded A decoded run.
	 * @return string|false The kind; '' when no pattern matches; false when PCRE failed.
	 */
	private static function receiver_kind( $decoded ) {
		if ( false === strpos( $decoded, '/' ) && ! self::may_hold_encoded_slash( $decoded ) ) {
			return ''; // stage 1's own fast reject: no slash, no receiver URL
		}
		foreach ( self::URL_PATTERNS as $pattern ) {
			$found = preg_match( $pattern[1], $decoded );
			if ( false === $found ) {
				return false;
			}
			if ( 1 === $found ) {
				return $pattern[0];
			}
		}
		return '';
	}

	/**
	 * Does $text hold a character an encoding starts with — `%`, `&` or `\`?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function has_encoding_marker( $text ) {
		return false !== strpbrk( $text, '%&\\' );
	}
```

- [ ] **Step 7: Run the tests and make sure they pass**

Run: `vendor/bin/phpunit tests/unit/RedactEncodedRunTest.php`
Expected: `OK (443 tests, …)`: 443 tests, all passing. The assertion count is not a gate.

Run: `vendor/bin/phpunit --filter Redact`
Expected: OK, with no failures.

Run: `vendor/bin/phpunit`
Expected: the baseline count + 529 tests (on the reference machine: `Tests: 4572`; the deprecation counts must not grow; the assertion count is not a gate), with no failures.

- [ ] **Step 8: Lint, and a PHP 7.4 check if you can**

Run: `composer lint`
Expected: no errors.

Optional, when Docker is available (CI runs this matrix anyway):

```bash
curl -sSL -o /tmp/phpunit-9.phar https://phar.phpunit.de/phpunit-9.phar
docker run --rm -v "$PWD":/w -v /tmp/phpunit-9.phar:/phpunit-9.phar -w /w php:7.4-cli \
  php -d memory_limit=-1 /phpunit-9.phar --bootstrap tests/bootstrap.php --filter Redact tests/unit
```

Expected: `OK` (the same results as on PHP 8.x).

- [ ] **Step 9: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-redact.php tests/unit/RedactEncodedRunTest.php tests/unit/RedactEncodedSlashTest.php tests/unit/RedactReferenceTableTest.php
git commit -m "feat(redact): stage 2 — decode each run, then match; five limit controls now redact (#113)

Changed expectations (each decodes to a receiver URL by the plain-text rules):
- RedactEncodedSlashTest negatives 'double encoded (out of scope)'
- RedactEncodedSlashTest round1_negatives 'decimal 470 before host'
- RedactEncodedSlashTest unterminated_at_negatives 'decimal 640', 'decimal 6400 scheme'
- RedactReferenceTableTest controls 'decimal 640'

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: CLAUDE.md — stage 2, the run rule, the decoders, Limits

**Files:**
- Modify: `CLAUDE.md` (tree ~line 48; class table ~line 74; "Agent read redaction" section ~lines 295–303 and Limits ~lines 362–367)

**Interfaces:**
- Consumes: the names from Tasks 1–2 (`Aura_Worker_Redact_Decode`, `decode_run()`, `decode_pass()`, `html5_code_point()`, `utf8()`, `MAX_DECODE_PASSES`, `MAX_DECODE_GROWTH`, `LEGACY_NAMES`, `RE_RUN`, `redact_urls()`, `redact_encoded_runs()`).
- Produces: documentation only.

Each edit below replaces the exact **old** text (it occurs once in the file) with the **new** text. The Limits edit does what spec §6 asks:
- it removes the "not decoded before matching" bullet, which covered double encoding, encoded hostname and path characters, JSON `/` and padding;
- it adds the more-than-4-layers limit and the whole-run limit.

- [ ] **Step 1: Repository tree — add the decoder file**

Old:

```markdown
        ├── class-aura-worker-redact.php     # Agent read redaction + placeholder write guard (2.18.0)
```

New:

```markdown
        ├── class-aura-worker-redact.php     # Agent read redaction + placeholder write guard (2.18.0)
        ├── class-aura-worker-redact-decode.php # Redaction stage 2: decode a run before matching (2.18.2)
```

- [ ] **Step 2: Class Responsibilities table — add `Aura_Worker_Redact_Decode` after `Aura_Worker_Redact`**

Old:

```markdown
counters, `status_fragment` |
```

New:

```markdown
counters, `status_fragment` |
| `Aura_Worker_Redact_Decode` | `includes/class-aura-worker-redact-decode.php` | Redaction stage 2 (2.18.2, #113), pure: `decode_run()` (up to `MAX_DECODE_PASSES` layers of HTML5 character references, `%XX` and JSON escapes; null past the pass or `MAX_DECODE_GROWTH` bound), `decode_pass()`, `html5_code_point()`, `utf8()` |
```

- [ ] **Step 3: Detectors bullet — stage 1's fast reject is no longer the whole story**

Old:

```markdown
  defeat it). See Limits for what is not decoded; `SECRET_KEYS` — exact key
```

New:

```markdown
  defeat it) — that is stage 1's reject only: stage 2 (below) still runs. `SECRET_KEYS` — exact key
```

- [ ] **Step 4: Add the stage 2 bullet right before the Placeholder bullet**

Old:

```markdown
- **Placeholder** `aura-redacted:v1:<kind>` (`make|integromat|zapier|slack|discord|
```

New:

```markdown
- **Stage 2 — decode, then match** (2.18.2, #113). `redact_text()` runs the patterns above
  (stage 1, `redact_urls()`, unchanged), then `redact_encoded_runs()`. A field with no `%`,
  `&` or `\` is returned as it is. Otherwise it is split into runs (`RE_RUN`: a maximal
  stretch without whitespace, `"`, `'`, `<`, `>`), and each run holding one of those three
  characters goes through `Aura_Worker_Redact_Decode::decode_run()` (pure, no WordPress).
  A decoded run that differs from the original is checked against `URL_PATTERNS`
  (`preg_match` only, the first hit gives the kind); on a hit the WHOLE original run becomes
  `aura-redacted:v1:<kind>` plus the run's trailing sentence punctuation
  (`trailing_punctuation()`). A run that already holds `aura-redacted:` is decoded too — a
  literal marker never shields an encoded URL. A decoded run is judged by the plain-text
  rules, so an encoded form is redacted exactly when its decoded form would be
  (`x%31hooks…` stays; a non-ASCII character before a host is a boundary; `&#x40discord…`
  is U+40DC and stays). One pass, in this order: HTML character references as HTML5 reads
  them in text — numeric (`&#N` / `&#xH`, `;` optional, the longest digit run, any zero
  padding; 0, a surrogate or a value above 0x10FFFF → U+FFFD; 0x80–0x9F through the
  Windows-1252 table; UTF-8 built without mbstring), then named: `&name;` through
  `html_entity_decode( …, ENT_QUOTES | ENT_HTML5, 'UTF-8' )`, else the longest of HTML5's
  106 legacy names without `;` (`LEGACY_NAMES`: `&amphooks`, `&notit;` → `¬it;`; `&sol`
  stays); percent `%XX` only (`rawurldecode()`, `+` stays); JSON `\uXXXX` (a surrogate pair
  is one code point, a lone surrogate stays) and `\/`. Up to `MAX_DECODE_PASSES` (4)
  passes, stopping at the first that changes nothing. If a fifth, check-only pass would
  still change the run, or an intermediate value is longer than `MAX_DECODE_GROWTH` (3) ×
  the run (one pass grows at most 1.2×, `&nGt;`), `decode_run()` returns null and the run
  becomes `aura-redacted:v1:field`. A PCRE failure in the split or in a pattern fails the
  whole field closed, as in stage 1. The write guard does not decode.
- **Placeholder** `aura-redacted:v1:<kind>` (`make|integromat|zapier|slack|discord|
```

- [ ] **Step 5: Limits — replace the "not decoded before matching" bullet**

Old:

```markdown
  - not decoded before matching (#110): double encoding (`%252F`, `&amp;#x2F;`),
    encoded hostname or path LETTERS, DIGITS and DOTS — percent (`hook%2Eeu2…`) or HTML
    (`&#104;ooks…`, `&period;`, `&#46;`) — JSON `\u002F` escapes in a string that is
    never JSON-decoded; only the structural `/`, `:` and `@` are accepted encoded.
    Closing that class fully needs a decode-then-match design (decode a copy, map
    offsets back), not more alternatives in the patterns;
```

New:

```markdown
  - more than `MAX_DECODE_PASSES` (4) layers of encoding turn that run into
    `aura-redacted:v1:field`, whatever it holds (2.18.2, #113);
  - stage 2 replaces a run whole, with no map from decoded offsets back to the
    original: adjacent text in the same run (a `?redirect=` prefix, a parenthesis, or a
    closing tag written encoded) is replaced with the URL, and a run that ends in a
    reference's `;` (`…&#x31;`) keeps that `;` after the placeholder (2.18.2, #113);
```


- [ ] **Step 6: Check the result**

Run: `grep -n "Stage 2 — decode, then match\|MAX_DECODE_PASSES\|MAX_DECODE_GROWTH\|class-aura-worker-redact-decode.php" CLAUDE.md`
Expected: the tree line, the class-table row, the stage 2 bullet and the new Limits bullet all appear.

Run: `grep -n "not decoded before matching" CLAUDE.md`
Expected: no output.

- [ ] **Step 7: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude-md): redaction stage 2 — decode, then match; Limits updated (#113)

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## After the tasks

1. Open the implementation PR against `main`.
   - The PR body lists the five changed expectations from D9 (spec §5).
   - Run the Codex review loop, and watch CI in parallel (PHP 7.4 / 8.1 / 8.2, `phpcs`).
2. Staging on `refineintelstg` (spec §5): re-run the 2.18.1 harness with kses on and with kses off. `encoded_leaks` must be empty in both runs.
3. The release is a separate PR (spec §7): the version bump in four places, the changelogs and the badge. Then a pre-release, the staging check, and promotion to stable through the draft toggle. Every merge and release needs the owner's approval.

## Self-review (done while writing)

- **Spec coverage.**
  - §3.1 decoder (numeric HTML5 end state, Windows-1252, named, legacy longest match, percent, JSON, four passes + check pass, growth bound) → Task 1.
  - §3.2 stage 2 (field and run fast paths, the run regex, no `aura-redacted:` skip, null → field, first-hit kind, whole run, trailing punctuation, PCRE failure → field) → Task 2.
  - §3.3 unchanged surfaces → Task 2's write-guard test and the carrier tests.
  - §4 performance → Task 2's 1 MB test, with JIT on and off.
  - §5 tests → Tasks 1–2, and D9 for the changed expectations. Staging → "After the tasks".
  - §6 → Task 3.
  - §7 → "After the tasks" (no version bump here).
- **Placeholder scan.** No TBD or TODO. Every code step carries its complete code, spliced from the files that were run.
- **Name consistency.** `decode_run`, `decode_pass`, `html5_code_point`, `utf8`, `MAX_DECODE_PASSES`, `MAX_DECODE_GROWTH`, `LEGACY_NAMES`, `LEGACY_MAX_LENGTH`, `RE_RUN`, `redact_urls`, `redact_encoded_runs`, `receiver_kind`, `has_encoding_marker` are used identically in every task and in CLAUDE.md.
- **Dry run.** The plan's exact code was applied to a copy of `main@b1d0791` outside the repo and run there:
  - PHP 8.5.7 / PHPUnit 10.5: `RedactDecodeTest` 86/86, `RedactEncodedRunTest` 443/443, full suite 4572 tests with no failures and unchanged deprecation counts.
  - Before the stage 2 code: exactly the five D9 failures, plus 406/443 in the new file.
  - PHP 7.4 / PHPUnit 9 (docker `php:7.4-cli`, `-d memory_limit=-1`): both new files OK; `--filter Redact` shows the same five failures before the D9 edits, and is OK (2164 tests) after them.
  - Task 2 Step 3 (edited tests, unpatched class): exactly the five failures listed there.
  - `phpcs` on the three plugin files: clean.
  - The CLAUDE.md edits were applied to the copy with an exact-match script.
