# SiteAgent 2.18.3 — agent read redaction: the URL parser's reading — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two WHATWG-reading leak families of Digitizers/SiteAgent#116 — a backslash used as the path separator under a scheme, and hostname characters UTS-46 normalises away — and remove the `original_runs()` memory spike, without touching stage 1.

**Architecture:** Stage 2 (`Aura_Worker_Redact::redact_encoded_runs()`) judges every decoded layer of a run through up to four VIEWS: the layer as it is, its UTS-46 mapping (`Aura_Worker_Redact_Idna::map()`, a generated `strtr()` table), its URL-parser reading (`url_view()`: `\` → `/`, matched against `url_patterns()`, whose prefix is required), and both. `original_runs()` becomes two `preg_replace_callback()` passes that keep only the runs at cut indexes. Everything else — stage 1, the decoder, the walker, the write guard, the grant — is unchanged.

**Tech Stack:** PHP 7.4+ (CI matrix 7.4 / 8.1 / 8.2), WordPress 6.2+, PHPUnit (`vendor/bin/phpunit`, `--filter <name>`; PHPUnit 10.5 locally, 9 on the 7.4 job), PHPCS (`composer lint` — the gate covers `digitizer-site-worker/` only; `bin/` is outside it). The harness stubs WordPress (`tests/bootstrap.php`); nothing from WordPress is loaded. No `intl`, no `mbstring`.

**Spec:** `Digitizers/Aura` — `docs/superpowers/specs/2026-09-17-redaction-url-reading-design.md` (merged @ `1968be7c`, PR #569). This plan covers the whole spec except §7 steps 2–5: the version bump, the release and the spec amendment are separate PRs.

**Baseline:** `main` at `34712be` (2.18.2). On PHP 8.5 / PHPUnit 10.5, `vendor/bin/phpunit` reports `Tests: 4879, Assertions: 29938, Deprecations: 10, PHPUnit Deprecations: 1`. Record your own numbers before Task 1; the deprecation counts must not change.

## Global Constraints

- **Backslash reads as `/` only under a prefix** (spec §2.1, §3.1): `url_patterns()` require `https?` + `:` + ZERO or more slashes, or exactly `//`. A bare host followed by `\` (`hooks.zapier.com\nNext`), a Windows path, a UNC path whose host is not a receiver, a `file:` URL, and `/host` (one slash: a path) are KEPT.
- **`url_view( $s )` is `strtr( $s, '\\', '/' )` and nothing else** — TAB, LF and CR are NOT stripped (spec §3.1, owner decision).
- **UTS-46 by the generated table only** (spec §2.2, §3.2): Unicode 18.0.0 `IdnaMappingTable.txt`, SHA-256 `a03b1eb38032268c696406a83f0972d6a815acd2c8d4151d42ec0fda70ffced1`, URL `https://www.unicode.org/Public/18.0.0/idna/IdnaMappingTable.txt`. Entries: code point > 0x7F with status `mapped` or `disallowed_STD3_mapped` and a target entirely in `[a-z0-9.-]` (1043 single-character + 189 multi-character, longest target 4), plus every `ignored` code point deleted (294). Total 1526. `valid`, `disallowed`, `deviation` and non-hostname targets are left out.
- **Views per layer, in this order** (spec §3.1): (1) `L` vs `stage_2_patterns()`; (2) `map(L)` vs `stage_2_patterns()` when it differs from `L`; (3) `url(L)` vs `url_patterns()` when it differs from `L`; (4) `url(map(L))` vs `url_patterns()` when views 2 and 3 both apply and it differs from view 3. The first view that matches gives the kind; the whole run is replaced, `trailing_punctuation( <run> )` appended.
- **The raw layer when nothing decodes** gets views 2–4 but NOT view 1 (spec §3.1). The original run's layer 0 at a stage 1 cut gets view 1 only when the cut backslash starts a JSON escape (`RE_CUT_AT_JSON_ESCAPE`); its views 2–4 always.
- **Stage 2 entry** (spec §3.3): a field enters when it holds `%`, `&` or `\`, OR a byte ≥ 0x80 AND a slash (literal, or `may_hold_encoded_slash()`); a run is decoded and viewed when it holds `%`, `&`, `\` or a byte ≥ 0x80.
- **`original_runs()`** returns `array<int,string>` of the runs at cut indexes only, `null` when none are needed, `false` on a PCRE failure or a run-count mismatch (→ the field becomes `aura-redacted:v1:field`) (spec §3.4).
- Placeholder, exactly: `aura-redacted:v1:<kind>`, `<kind>` ∈ `make`, `integromat`, `zapier`, `slack`, `discord`, `ifttt`, `telegram`, `field`.
- **Unchanged** (spec §3.5): stage 1 (`redact_urls()`, `URL_PATTERNS`, `RE_HEAD`, `RE_TAIL`), `Aura_Worker_Redact_Decode`, `stage_2_patterns()`, the walker and its budgets, `holds_placeholder()`, the grant, the counters. Existing tests keep their expected outputs (no existing test pins a #116 limit — verified by grep before this plan was written).
- PHP 7.4 syntax only: no `match`, `str_contains`, union types, named arguments, trailing commas in calls. `memory_reset_peak_usage()` is PHP 8.2+: guard it with `function_exists()`.
- All new regexes are possessive or linear; a `preg_*` failure fails the field closed.
- Coding conventions: tabs; `if ( ! defined( 'ABSPATH' ) ) { exit; }` in every plugin file; `Aura_Worker_*` prefix; `composer lint` green; every `ini_set` in a test carries `// phpcs:ignore WordPress.PHP.IniSet.Risky`.
- **No version bump in this PR.** `Version:`, `AURA_WORKER_VERSION`, `Stable tag:`, the changelogs and the badge belong to the release PR.
- Git: `git add <path>` per file, never `-A` or `.`; never `git stash`; commit trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`; one implementation PR, then the Codex review loop; merge and release only on the owner's approval.

## Rulings on spec gaps (made while writing this plan — reviewers, check these first)

- **D1 — `map()`'s fast gate is a lead-byte scan, not a "any byte ≥ 0x80" scan.** `strtr()` with an array builds a hash of its 1526 keys on EVERY call, so calling it on every run of Hebrew or Arabic prose would cost more than the match it protects. The generator also emits `const LEAD`: the distinct FIRST bytes of all keys, as one string. `map()` returns `$s` at once when `strlen( $s ) === strcspn( $s, self::LEAD )`. Hebrew (lead bytes `\xD6`/`\xD7`) and Arabic (`\xD8`–`\xDB`) contain no mapped code point (Arabic-Indic digits are `valid` under UTS-46, not mapped), so those runs never reach `strtr()`; a test pins that neither byte is in `LEAD`. CJK and emoji runs (`\xE3`–`\xEF`, `\xF0`) do reach it.
- **D2 — `strtr()` is safe on invalid UTF-8.** Every key is one complete code point: a lead byte ≥ `\xC2` followed only by continuation bytes. A key can therefore match only at a code point's start and covers exactly that code point (spec §3.2). No `u` flag anywhere near it.
- **D3 — the generated file's keys are written as `"\u{XXXX}"`**, one entry per line, sorted by code point, so the PHP source is ASCII and invisible characters (U+200B, U+00AD) are readable in a diff. Double quotes are required by the escape, so `Squiz.Strings.DoubleQuoteUsage` (if enabled) does not fire. The generator writes `\t\t"\u{FF48}" => 'h',`.
- **D4 — a byte ≥ 0x80 is detected with `preg_match( '/[\x80-\xff]/', $s )`** (no `u` flag: a byte class). A `false` from it (PCRE failure) counts as "yes": the field enters stage 2, where any real failure fails it closed.
- **D5 — the callback consults `$originals` by `isset( $originals[ $index ] )`**, not by re-matching `RE_CUT_AT_BACKSLASH` on the run: `original_runs()` already decided which indexes are cuts, with the same regex on the same runs.
- **D6 — `receiver_kind()` takes the pattern list as its second argument.** Its fast reject (no `/` and no encoded slash → `''`) is unchanged and serves every view; `map()` never adds a slash and `url_view()` only ever adds one where a backslash stood.
- **D7 — the memory ceiling is `4 × strlen( $field )`**, asserted on a ~5 MB field with ~2M runs and one cut, after `memory_reset_peak_usage()`. Expected peak: the field, stage 1's copy, `preg_replace_callback()`'s transient output copy and the cut runs, ≈ 3×. The old code peaked at ~19× (100 MB on 5.4 MB). If the measured growth lands above 4×, report the number as a concern — do not raise the ceiling.
- **D8 — `RE_HEAD_SCHEMED` is `'~(?:https?' . RE_COLON . RE_SLASH . '*+' . RE_USERINFO . '|' . RE_DOUBLE_SLASH_USERINFO . ')'`.** `RE_SLASH` is already a `(?:…)` group, so `*+` applies to the whole slash alternative (literal, JSON-escaped or encoded). `RE_USERINFO` is an optional group, so `https:hooks.zapier.com/x` matches with no userinfo.
- **D9 — the generator is not tested by PHPUnit.** It is a hand-run script (`php bin/generate-idna-map.php`), and `bin/` is outside the lint gate. What IS tested is its output: `RedactIdnaTest` pins the entry counts, the key/target shapes, `LEAD`, and fixed mappings, so a regeneration from a different table or a hand edit fails CI.
- **D10 — `RedactUrlReadingTest`'s receivers table** reuses `RedactEncodedRunTest`'s nine rows (copied, not shared: test classes do not import from each other in this suite).

## File structure

| File | Responsibility |
|---|---|
| `bin/generate-idna-map.php` (new) | downloads the pinned table, checks its hash, writes the class below (Task 1) |
| `digitizer-site-worker/includes/class-aura-worker-redact-idna.php` (new, generated) | `Aura_Worker_Redact_Idna` — `MAP`, `LEAD`, `map()` (Task 1) |
| `digitizer-site-worker/digitizer-site-worker.php:80` | `require_once` the IDNA class before the decoder (Task 1) |
| `tests/bootstrap.php:4386` | the same `require_once` (Task 1) |
| `tests/unit/RedactIdnaTest.php` (new) | Task 1 |
| `digitizer-site-worker/includes/class-aura-worker-redact.php` | `original_runs()` rewrite (Task 2); `RE_HEAD_SCHEMED`, `$url_patterns`, `url_patterns()`, `url_view()`, `judge_layer()`, `has_high_byte()`, `needs_stage_2()`, `needs_stage_2_run()`, `receiver_kind( $decoded, $patterns )`, the callback in `redact_encoded_runs()` (Task 3) |
| `tests/unit/RedactEncodedRunTest.php` | the memory case and the JSON-escape-cut control (Task 2) |
| `tests/unit/RedactUrlReadingTest.php` (new) | Task 3 |
| `CLAUDE.md` | tree, class table, the stage 2 bullet, Limits (Task 4) |

Facts the implementer must not "fix":

- **Do not strip TAB/LF/CR in `url_view()`** — spec §6 lists both forms as a limit.
- **Do not add `\` to `RE_SLASH` or a boundary to `url_patterns()`.** The views exist so the patterns stay as they are.
- **Do not touch stage 1, `trailing_punctuation()`, `may_hold_encoded_slash()`, `encoded_slash_state()` or the decoder.**
- **Memory on a bare `php:7.4-cli` container.** Its default `memory_limit` is 128M; run with `-d memory_limit=-1` locally. CI (`setup-php`) has no such limit.

---

### Task 1: `Aura_Worker_Redact_Idna` — the generated UTS-46 map

**Files:**
- Create: `bin/generate-idna-map.php`
- Create: `digitizer-site-worker/includes/class-aura-worker-redact-idna.php` (by running the script)
- Modify: `digitizer-site-worker/digitizer-site-worker.php:80`
- Modify: `tests/bootstrap.php:4386`
- Test: `tests/unit/RedactIdnaTest.php`

**Interfaces:**
- Produces: `Aura_Worker_Redact_Idna::map( string $s ): string` — pure, byte-safe, returns the same string when nothing maps; `Aura_Worker_Redact_Idna::MAP` (`array<string,string>`, 1526 entries, key = one UTF-8 code point, value = `''` or `[a-z0-9.-]{1,4}`); `Aura_Worker_Redact_Idna::LEAD` (string of distinct first bytes of the keys).

- [ ] **Step 1: Write the failing test**

```php
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
			'digit one full stop'      => array( "\u{2488}", '1.' ),
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
```

(`$lead` is keyed by the first byte of each key in table order; the generator writes `LEAD` in the same first-seen order, so `implode( '', array_keys( $lead ) )` equals it exactly. `SA_PLUGIN_DIR` is defined by `tests/bootstrap.php`.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter RedactIdnaTest`
Expected: `Error: Class "Aura_Worker_Redact_Idna" not found`.

- [ ] **Step 3: Write the generator**

`bin/generate-idna-map.php`:

```php
<?php
/**
 * Generates includes/class-aura-worker-redact-idna.php from Unicode's UTS-46
 * mapping table (SiteAgent #116). Run by hand:
 *
 *     php bin/generate-idna-map.php            # downloads the pinned table
 *     php bin/generate-idna-map.php <file>     # reads a local copy instead
 *
 * then run the tests. The table version and its SHA-256 are pinned below; a
 * different file is refused. See the spec (Digitizers/Aura,
 * docs/superpowers/specs/2026-09-17-redaction-url-reading-design.md §3.2)
 * for what goes into the table and why.
 *
 * @package Aura_Worker
 */

const IDNA_VERSION = '18.0.0';
const IDNA_URL     = 'https://www.unicode.org/Public/18.0.0/idna/IdnaMappingTable.txt';
const IDNA_SHA256  = 'a03b1eb38032268c696406a83f0972d6a815acd2c8d4151d42ec0fda70ffced1';
const OUT          = __DIR__ . '/../digitizer-site-worker/includes/class-aura-worker-redact-idna.php';

$source = isset( $argv[1] ) ? $argv[1] : IDNA_URL;
$table  = file_get_contents( $source );
if ( false === $table ) {
	fwrite( STDERR, "cannot read {$source}\n" );
	exit( 1 );
}
$hash = hash( 'sha256', $table );
if ( IDNA_SHA256 !== $hash ) {
	fwrite( STDERR, "refusing: sha256 {$hash} is not the pinned " . IDNA_SHA256 . "\n" );
	exit( 1 );
}

$entries = array(); // code point => target ('' = deleted)
foreach ( explode( "\n", $table ) as $line ) {
	$line = trim( explode( '#', $line, 2 )[0] );
	if ( '' === $line ) {
		continue;
	}
	$fields = array_map( 'trim', explode( ';', $line ) );
	$range  = explode( '..', $fields[0] );
	$first  = hexdec( $range[0] );
	$last   = hexdec( $range[ count( $range ) - 1 ] );
	$status = $fields[1];
	if ( $last <= 0x7F ) {
		continue;
	}
	if ( 'ignored' === $status ) {
		$target = '';
	} elseif ( 'mapped' === $status || 'disallowed_STD3_mapped' === $status ) {
		if ( ! isset( $fields[2] ) || '' === $fields[2] ) {
			continue;
		}
		$target = '';
		foreach ( explode( ' ', $fields[2] ) as $cp ) {
			$target .= chr( hexdec( $cp ) > 0x7F ? 0 : hexdec( $cp ) );
		}
		if ( 1 !== preg_match( '/^[a-z0-9.-]+$/', $target ) ) {
			continue; // a non-hostname target, or a non-ASCII one (chr(0) never matches)
		}
	} else {
		continue; // valid, disallowed, deviation
	}
	for ( $cp = max( $first, 0x80 ); $cp <= $last; $cp++ ) {
		$entries[ $cp ] = $target;
	}
}
ksort( $entries );

$single  = 0;
$multi   = 0;
$deleted = 0;
$lead    = '';
$lines   = array();
foreach ( $entries as $cp => $target ) {
	$utf8 = utf8( $cp );
	if ( false === strpos( $lead, $utf8[0] ) ) {
		$lead .= $utf8[0];
	}
	if ( '' === $target ) {
		++$deleted;
	} elseif ( 1 === strlen( $target ) ) {
		++$single;
	} else {
		++$multi;
	}
	$lines[] = sprintf( "\t\t\"\\u{%04X}\" => '%s',", $cp, $target );
}

$lead_escaped = '';
for ( $i = 0; $i < strlen( $lead ); $i++ ) {
	$lead_escaped .= sprintf( '\x%02X', ord( $lead[ $i ] ) );
}

$out = "<?php\n"
	. "/**\n"
	. " * GENERATED by bin/generate-idna-map.php — do not edit by hand (SiteAgent #116).\n"
	. " *\n"
	. " * Source: Unicode " . IDNA_VERSION . " IdnaMappingTable.txt\n"
	. " *   " . IDNA_URL . "\n"
	. " *   sha256 " . IDNA_SHA256 . "\n"
	. " * Entries: {$single} single-character mappings, {$multi} multi-character mappings,\n"
	. " * {$deleted} deletions (UTS-46 `ignored`).\n"
	. " *\n"
	. " * Agent read redaction, stage 2 view 2: the characters a URL parser maps or\n"
	. " * drops in a hostname under UTS-46 — a code point above U+007F whose status is\n"
	. " * `mapped` or `disallowed_STD3_mapped` with a target made only of hostname\n"
	. " * characters (`a-z`, `0-9`, `.`, `-`), and every `ignored` code point (deleted).\n"
	. " * `valid`, `disallowed`, `deviation` (kept by a parser in non-transitional\n"
	. " * mode) and non-hostname targets are left out. No NFC normalisation.\n"
	. " *\n"
	. " * @package Aura_Worker\n"
	. " * @since 2.18.3\n"
	. " */\n"
	. "\n"
	. "if ( ! defined( 'ABSPATH' ) ) {\n"
	. "\texit;\n"
	. "}\n"
	. "\n"
	. "class Aura_Worker_Redact_Idna {\n"
	. "\n"
	. "\t/** The distinct first bytes of MAP's keys: a string without any of them holds nothing to map. */\n"
	. "\tconst LEAD = \"{$lead_escaped}\";\n"
	. "\n"
	. "\t/** One UTF-8 code point => its hostname reading ('' = deleted). */\n"
	. "\tconst MAP = array(\n"
	. implode( "\n", $lines ) . "\n"
	. "\t);\n"
	. "\n"
	. "\t/**\n"
	. "\t * The UTS-46 hostname reading of \$s: every code point in MAP replaced by\n"
	. "\t * its target. Byte-safe: strtr() matches each key — one whole code point,\n"
	. "\t * a lead byte then continuation bytes — only at a code point's start,\n"
	. "\t * also inside text that is not valid UTF-8. Returns \$s itself when no\n"
	. "\t * key's first byte occurs in it (LEAD) — Hebrew and Arabic prose never\n"
	. "\t * pay for the 1526-key strtr().\n"
	. "\t *\n"
	. "\t * @param string \$s Text.\n"
	. "\t * @return string\n"
	. "\t */\n"
	. "\tpublic static function map( \$s ) {\n"
	. "\t\tif ( strlen( \$s ) === strcspn( \$s, self::LEAD ) ) {\n"
	. "\t\t\treturn \$s;\n"
	. "\t\t}\n"
	. "\t\treturn strtr( \$s, self::MAP );\n"
	. "\t}\n"
	. "}\n";

file_put_contents( OUT, $out );
fwrite( STDOUT, "wrote " . realpath( OUT ) . ": {$single} single, {$multi} multi, {$deleted} deleted\n" );

/**
 * UTF-8 of one code point (no mbstring).
 *
 * @param int $cp Code point.
 * @return string
 */
function utf8( $cp ) {
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
```

Two details of the target loop: a mapping code point above 0x7F is turned into `chr( 0 )`, which the `[a-z0-9.-]` check rejects, so a target that is not pure ASCII drops the entry; and a range (`2060..2063`) is expanded one code point at a time, starting at 0x80.

- [ ] **Step 4: Run the generator and register the class**

Run: `php bin/generate-idna-map.php`
Expected: `wrote …/class-aura-worker-redact-idna.php: 1043 single, 189 multi, 294 deleted`. If the counts differ, the table is not the pinned one (the hash check should already have refused it) or the filter is wrong — stop and compare against spec §3.2.

In `digitizer-site-worker/digitizer-site-worker.php`, before line 80:

```php
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-redact-idna.php';
```

In `tests/bootstrap.php`, before line 4386:

```php
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-redact-idna.php';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter RedactIdnaTest`
Expected: all green. If `test_table_shape` fails on `LEAD`, the generator's first-seen order and the test's differ — both iterate `MAP` in file order, so this means the generator wrote `LEAD` from a different order; fix the generator, not the test.

Run: `composer lint`
Expected: green. If PHPCS complains about the generated file's line length or array formatting, adjust the generator's output format (never the file by hand) and regenerate.

- [ ] **Step 6: Commit**

```bash
git add bin/generate-idna-map.php digitizer-site-worker/includes/class-aura-worker-redact-idna.php digitizer-site-worker/digitizer-site-worker.php tests/bootstrap.php tests/unit/RedactIdnaTest.php
git commit -m "feat(redact): generated UTS-46 hostname map, Aura_Worker_Redact_Idna (#116)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: `original_runs()` collects only the cut runs

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-redact.php:1083-1099` (`original_runs()`), and the callback in `redact_encoded_runs()` at `:1029-1031` (the `isset` consult, D5)
- Test: `tests/unit/RedactEncodedRunTest.php` (two new cases)

**Interfaces:**
- Consumes: `RE_RUN`, `RE_CUT_AT_BACKSLASH`, `PLACEHOLDER_MARK` (all existing).
- Produces: `original_runs( $text, $original )` → `array<int,string>` keyed by run index, cut indexes only; `null` when stage 1 changed nothing or left no cut; `false` on a PCRE failure or a run-count mismatch. Task 3's callback relies on `isset( $originals[ $index ] )`.

- [ ] **Step 1: Write the failing tests**

Append to `RedactEncodedRunTest`:

```php
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
			$this->assertLessThan( 4 * $size, $peak, sprintf( 'peak growth %d bytes on a %d-byte field', $peak, $size ) );
		} finally {
			ini_set( 'memory_limit', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
	}

	public function test_a_cut_at_a_json_escape_still_reads_the_original_run_whole(): void {
		// Unchanged from 2.18.2: the escape continues the URL for a JSON reader,
		// so the original run's raw layer is judged again, whole.
		$this->assertSame( 'aura-redacted:v1:make', $this->text( 'https://hook.eu2.make.com/abc\u0031def' ) );
		$this->assertSame( 'x aura-redacted:v1:zapier y', $this->text( 'x https://hooks.zapier.com/hooks\\/catch/1/S y' ) );
	}
```

(Why `$count` is 2: stage 1 replaces `https://hooks.zapier.com/x` (its tail stops at the backslash) — one; the cut run `aura-redacted:v1:zapier\u0041` matches `RE_CUT_AT_JSON_ESCAPE`, so stage 2 judges the original run's raw layer, finds the receiver, and replaces the run — two. This is 2.18.2's behaviour; the memory ceiling is the assertion Task 2 changes.)

- [ ] **Step 2: Run them to verify the memory case fails**

Run: `vendor/bin/phpunit --filter 'RedactEncodedRunTest::test_a_cut_field' -d memory_limit=-1`
Expected: FAIL on `assertLessThan` — on `main` the peak is ~19× the field. The JSON-escape control passes already (it pins behaviour Task 2 must keep). Fix the `$count` expectation to what `main` reports if it is not 1.

- [ ] **Step 3: Rewrite `original_runs()`**

Replace the method (`:1083-1099`) with:

```php
	/**
	 * The ORIGINAL (pre-stage-1) run at every index where stage 1's output
	 * holds a placeholder cut at a backslash (RE_CUT_AT_BACKSLASH) — only
	 * those, so a field of millions of runs costs its own size, not an
	 * array per run (#116). Two passes with the run regex both texts are
	 * split by: the first records the cut indexes and counts the runs of
	 * $text, the second keeps the original runs at those indexes and counts
	 * again. The runs pair up one to one — a stage 1 match and its
	 * placeholder hold no run delimiter — and a count mismatch fails the
	 * field closed all the same.
	 *
	 * @param string $text     Stage 1's output.
	 * @param string $original The text before stage 1.
	 * @return array<int,string>|null|false index => original run; null when
	 *                                      none are needed; false on a PCRE
	 *                                      failure or a count mismatch.
	 */
	private static function original_runs( $text, $original ) {
		if ( $original === $text ) {
			return null; // stage 1 replaced nothing
		}
		$cut = preg_match( self::RE_CUT_AT_BACKSLASH, $text );
		if ( 1 !== $cut ) {
			return false === $cut ? false : null;
		}
		$cuts   = array();
		$failed = false;
		$index  = -1;
		$after  = preg_replace_callback(
			self::RE_RUN,
			static function ( $m ) use ( &$cuts, &$failed, &$index ) {
				++$index;
				if ( ! $failed && false !== strpos( $m[0], self::PLACEHOLDER_MARK ) ) {
					$is_cut = preg_match( self::RE_CUT_AT_BACKSLASH, $m[0] );
					if ( false === $is_cut ) {
						$failed = true;
					} elseif ( 1 === $is_cut ) {
						$cuts[ $index ] = '';
					}
				}
				return $m[0];
			},
			$text
		);
		if ( null === $after || $failed ) {
			return false;
		}
		$after_count = $index + 1;
		$index       = -1;
		$before      = preg_replace_callback(
			self::RE_RUN,
			static function ( $m ) use ( &$cuts, &$index ) {
				++$index;
				if ( isset( $cuts[ $index ] ) ) {
					$cuts[ $index ] = $m[0];
				}
				return $m[0];
			},
			$original
		);
		if ( null === $before || $index + 1 !== $after_count ) {
			return false;
		}
		return $cuts;
	}
```

`$after` and `$before` are only ever compared with `null`; their string values are discarded at once (the transient copy is what D7 budgets for).

- [ ] **Step 4: Consult `$originals` by index in the callback (D5)**

In `redact_encoded_runs()`'s callback, replace

```php
				if ( null !== $originals ) {
					$cut = preg_match( self::RE_CUT_AT_BACKSLASH, $run );
					if ( false === $cut ) {
						$failed = true;
						return $run;
					}
					if ( 1 === $cut && $originals[ $index ] !== $run ) {
```

with

```php
				if ( null !== $originals ) {
					if ( isset( $originals[ $index ] ) && $originals[ $index ] !== $run ) {
```

and drop the matching closing brace of the removed `if ( 1 === $cut … )` — the block's body is otherwise unchanged. (Task 3 rewrites this block again; the point here is that `original_runs()`'s new shape is consumed correctly so the suite is green at this commit.)

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter Redact -d memory_limit=-1`
Expected: all green, the memory case included. Record the peak the message prints (`peak growth N bytes on a M-byte field`) in your report.

Run: `composer lint`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-redact.php tests/unit/RedactEncodedRunTest.php
git commit -m "perf(redact): original_runs() keeps only the runs at cut indexes (#116)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: The views — `url_view()`, `url_patterns()`, `map()`, and the widened entry

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-redact.php` — constants after `RE_HEAD_UNBOUNDED` (`:178`), a static after `$stage_2_patterns` (`:~398`), `redact_encoded_runs()` (`:994-1081`), `receiver_kind()` (`:1107-1135`), new methods next to `stage_2_patterns()` (`:1137-1153`) and `has_encoding_marker()` (`:1155-1157`)
- Test: `tests/unit/RedactUrlReadingTest.php` (new)

**Interfaces:**
- Consumes: `Aura_Worker_Redact_Idna::map()` (Task 1); `original_runs()` → `array<int,string>|null|false` (Task 2); existing `RE_COLON`, `RE_SLASH`, `RE_USERINFO`, `RE_DOUBLE_SLASH_USERINFO`, `RE_HEAD`, `URL_PATTERNS`, `stage_2_patterns()`, `encoded_slash_state()`, `trailing_punctuation()`, `RE_CUT_AT_JSON_ESCAPE`, `Aura_Worker_Redact_Decode::decode_layers()`.
- Produces: `public static url_patterns(): array<int,array{0:string,1:string}>`; private `url_view( $s )`, `judge_layer( $layer, $judge_raw )`, `has_high_byte( $s )`, `needs_stage_2( $text )`, `needs_stage_2_run( $run )`; `receiver_kind( $decoded, $patterns )` (signature change — both existing call sites are inside this task).

- [ ] **Step 1: Write the failing tests**

```php
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
			'unc, other host'           => array( '\\\\server\\share\\hooks.zapier.com' ),
			'one slash: a path'         => array( '/hooks.zapier.com\\x' ),
			'prose with a backslash'    => array( 'either\\or, see hooks.zapier.com' ),
			'lookalike host'            => array( 'https://hooks.zapier.com.evil\\x' ),
			'other host, receiver in path' => array( 'https://example.com\\hooks.zapier.com\\x' ),
			'other host, mapped chars'  => array( "https://\u{FF45}xample.com/x" ),
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

	public function test_a_mapped_prefix_glued_to_a_receiver_host_is_redacted_the_lookalike_cost(): void {
		// Stage 2 has no left host boundary (#113 owner decision), extended to view 2.
		$this->assertSame( 'aura-redacted:v1:zapier', $this->text( "\u{FF4D}\u{FF59}hooks.zapier.com/x" ) );
		// Its plain ASCII twin never enters stage 2 and is kept by stage 1's boundary.
		$this->assertSame( 'myhooks.zapier.com/x', $this->text( 'myhooks.zapier.com/x' ) );
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
	}
}
```

Notes for the implementer: `wp_json_encode()` and `sa_reset_state()` come from `tests/bootstrap.php`; the carrier fixtures mirror the ones `RedactEncodedRunTest` uses — check its `carriers` provider and copy its exact envelope shapes if the ones above do not reach `redact_text()` (run the test with the class unpatched: a carrier that does not reach stage 2 leaks `zsecret9` and FAILS, which is the intended RED; a carrier shape the walker does not treat as a carrier at all also fails, but for the wrong reason — verify with `redact()` on a plain URL that the shape is redacted at all).

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter RedactUrlReadingTest`
Expected: `url_patterns` → `Error: Call to undefined method`; most `backslash_cases`, `uts46_cases` and the cut case FAIL with the secret in the output; `kept`, `uts46_kept`, the write guard and the Hebrew cases PASS already (they pin what must stay).

- [ ] **Step 3: Add the constants, the static and the helpers**

After `RE_HEAD_UNBOUNDED` (`:178`):

```php
	/**
	 * Regex: the prefix a WHATWG parser needs before it reads `\` as `/` —
	 * a special scheme (`http:` / `https:`) followed by ANY number of
	 * slashes, none included (the parser's "special authority ignore
	 * slashes" state: `https:\host`, `https:/host`, `https:host` and
	 * `https:///host` all name `host`), or exactly `//` (protocol-relative:
	 * with a base URL `//host` is an authority, `/host` a path). No left
	 * host boundary, as RE_HEAD_UNBOUNDED. The `:` and the slashes may be
	 * encoded, as in RE_URL_PREFIX. Used by url_patterns() only (#116).
	 */
	const RE_HEAD_SCHEMED = '~(?:https?' . self::RE_COLON . self::RE_SLASH . '*+' . self::RE_USERINFO . '|' . self::RE_DOUBLE_SLASH_USERINFO . ')';
```

After `$stage_2_patterns`:

```php
	/**
	 * URL_PATTERNS with RE_HEAD_SCHEMED in place of RE_HEAD, built once by
	 * url_patterns().
	 *
	 * @var array<int,array{0:string,1:string}>|null
	 */
	private static $url_patterns = null;
```

After `stage_2_patterns()`:

```php
	/**
	 * Stage 2's patterns for the URL parser's reading (#116): each
	 * URL_PATTERNS entry, same kind and order, with RE_HEAD swapped for
	 * RE_HEAD_SCHEMED — no left host boundary, prefix REQUIRED. Run against
	 * url_view() of a layer only: in plain text a backslash is not a slash.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function url_patterns() {
		if ( null === self::$url_patterns ) {
			$patterns = array();
			foreach ( self::URL_PATTERNS as $pattern ) {
				$patterns[] = array( $pattern[0], self::RE_HEAD_SCHEMED . substr( $pattern[1], strlen( self::RE_HEAD ) ) );
			}
			self::$url_patterns = $patterns;
		}
		return self::$url_patterns;
	}

	/**
	 * The URL parser's reading of a run (#116): every backslash is a slash
	 * (the special-scheme rule; url_patterns() then insist on the scheme).
	 * TAB, LF and CR are NOT stripped — a documented limit, see CLAUDE.md.
	 *
	 * @param string $s One layer of a run.
	 * @return string
	 */
	private static function url_view( $s ) {
		return strtr( $s, '\\', '/' );
	}

	/**
	 * The kind of the first VIEW of $layer that holds a receiver URL (spec
	 * §3.1, #116): the layer as it is (view 1 — only when $judge_raw: a
	 * layer stage 1 already judged with its host boundary is not judged
	 * again without it), its UTS-46 mapping (view 2), the URL parser's
	 * reading (view 3) and both (view 4). A view that equals the text it
	 * came from is not run again.
	 *
	 * @param string $layer     One layer of a run.
	 * @param bool   $judge_raw Run view 1?
	 * @return string|false The kind; '' when no view matches; false when PCRE failed.
	 */
	private static function judge_layer( $layer, $judge_raw ) {
		if ( $judge_raw ) {
			$kind = self::receiver_kind( $layer, self::stage_2_patterns() );
			if ( '' !== $kind ) {
				return $kind;
			}
		}
		$mapped = Aura_Worker_Redact_Idna::map( $layer );
		if ( $mapped !== $layer ) {
			$kind = self::receiver_kind( $mapped, self::stage_2_patterns() );
			if ( '' !== $kind ) {
				return $kind;
			}
		}
		$url = self::url_view( $layer );
		if ( $url === $layer ) {
			return '';
		}
		$kind = self::receiver_kind( $url, self::url_patterns() );
		if ( '' !== $kind ) {
			return $kind;
		}
		if ( $mapped !== $layer ) {
			$url_mapped = self::url_view( $mapped );
			if ( $url_mapped !== $url ) {
				return self::receiver_kind( $url_mapped, self::url_patterns() );
			}
		}
		return '';
	}
```

(`'' !== $kind` is also true for `false`, so a PCRE failure returns `false` from any view.)

After `has_encoding_marker()`:

```php
	/**
	 * Does $s hold a byte outside ASCII? A byte class, no `u` flag, so text
	 * that is not valid UTF-8 is scanned like any other. A PCRE failure
	 * counts as yes (fail towards checking).
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	private static function has_high_byte( $s ) {
		return 0 !== preg_match( '/[\x80-\xff]/', $s );
	}

	/**
	 * Does this field need stage 2 (#116)? When it holds an encoding
	 * marker, as before; or a byte outside ASCII AND a slash of either
	 * kind — a UTS-46-mapped host still needs its slash. Prose in Hebrew,
	 * Arabic or emoji without a slash never splits. A PCRE failure in the
	 * encoded-slash check counts as yes.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function needs_stage_2( $text ) {
		if ( self::has_encoding_marker( $text ) ) {
			return true;
		}
		if ( ! self::has_high_byte( $text ) ) {
			return false;
		}
		return false !== strpos( $text, '/' ) || false !== self::encoded_slash_state( $text );
	}

	/**
	 * Does this run need decoding and viewing (#116)? An encoding marker,
	 * or a byte outside ASCII; receiver_kind()'s own fast reject answers
	 * for a run without a slash.
	 *
	 * @param string $run One run.
	 * @return bool
	 */
	private static function needs_stage_2_run( $run ) {
		return self::has_encoding_marker( $run ) || self::has_high_byte( $run );
	}
```

`receiver_kind()` gains a second parameter and loops over it:

```php
	 * @param string                              $decoded  One view of a layer.
	 * @param array<int,array{0:string,1:string}> $patterns stage_2_patterns() or url_patterns().
	 …
	private static function receiver_kind( $decoded, $patterns ) {
		… (the fast reject is unchanged) …
		foreach ( $patterns as $pattern ) {
```

- [ ] **Step 4: Rewrite the callback in `redact_encoded_runs()`**

Replace the field guard and the callback body:

```php
	private static function redact_encoded_runs( $text, &$count, $original ) {
		if ( ! self::needs_stage_2( $text ) ) {
			return $text; // field fast path: nothing encoded, nothing non-ASCII next to a slash
		}
		$originals = self::original_runs( $text, $original );
		if ( false === $originals ) {
			++$count; // PCRE gave up, or the runs do not pair up: fail closed (R9)
			return self::PLACEHOLDER . 'field';
		}
		$failed = false;
		$hits   = 0;
		$index  = -1;
		$out    = preg_replace_callback(
			self::RE_RUN,
			static function ( $m ) use ( &$failed, &$hits, &$index, $originals ) {
				$run = $m[0];
				++$index;
				if ( $failed || ! self::needs_stage_2_run( $run ) ) {
					return $run; // run fast path
				}
				$layers = Aura_Worker_Redact_Decode::decode_layers( $run );
				if ( null === $layers ) {
					++$hits; // too deep, too large, or PCRE gave up: fail closed
					return self::PLACEHOLDER . 'field';
				}
				// Each entry: [ layer, judge its raw view (view 1)? ]. When nothing
				// decodes, layer 0 is stage 1's own output, already judged with the
				// host boundary — only its other views (spec §3.1) are new readings.
				$judged   = array();
				$raw_only = count( $layers ) < 2;
				foreach ( $layers as $i => $layer ) {
					$judged[] = array( $layer, ! ( $raw_only && 0 === $i ) );
				}
				if ( null !== $originals && isset( $originals[ $index ] ) && $originals[ $index ] !== $run ) {
					$before = Aura_Worker_Redact_Decode::decode_layers( $originals[ $index ] );
					if ( null === $before ) {
						++$hits;
						return self::PLACEHOLDER . 'field';
					}
					$escape = preg_match( self::RE_CUT_AT_JSON_ESCAPE, $run );
					if ( false === $escape ) {
						$failed = true;
						return $run;
					}
					// Stage 1 ended its match at this backslash under the plain-text/
					// JSON reading and replaced exactly what it matched, so the
					// original run's layer 0 as it is was judged already — unless the
					// backslash starts a JSON escape, which does not end the URL for
					// a JSON reader (view 1 again, whole). Its URL-parser and UTS-46
					// views are readings stage 1 never took (#116).
					foreach ( $before as $i => $layer ) {
						$judged[] = array( $layer, 1 === $escape || 0 !== $i );
					}
				}
				foreach ( $judged as $entry ) {
					$kind = self::judge_layer( $entry[0], $entry[1] );
					if ( false === $kind ) {
						$failed = true;
						return $run;
					}
					if ( '' !== $kind ) {
						++$hits;
						return self::PLACEHOLDER . $kind . self::trailing_punctuation( $run );
					}
				}
				return $run;
			},
			$text
		);
		if ( null === $out || $failed ) {
			++$count; // PCRE gave up on the split or on a pattern: none of the field goes out (R9)
			return self::PLACEHOLDER . 'field';
		}
		$count += $hits;
		return $out;
	}
```

Update the method's docblock: views (spec §3.1), the new entry condition, and that a run holding `aura-redacted:` is still decoded like any other.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --filter RedactUrlReadingTest`
Expected: all green.

Run: `vendor/bin/phpunit -d memory_limit=-1`
Expected: green; the total is the baseline plus Task 1's, Task 2's and this file's cases, deprecation counts unchanged. If an EXISTING assertion changed, stop: the spec says none should (Global Constraints). Report it as a concern with the exact test name and both outputs — do not edit the old test.

Run: `composer lint`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-redact.php tests/unit/RedactUrlReadingTest.php
git commit -m "feat(redact): stage 2 reads a run as a URL parser does — backslash under a scheme, UTS-46 hosts (#116)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: CLAUDE.md

**Files:**
- Modify: `CLAUDE.md` — the tree (`:49`), the class table (`:76`), the stage 2 bullet (`:305-350`), Limits (`:403-440`)

**Interfaces:** none (docs).

- [ ] **Step 1: Update the tree and the class table**

Add under `class-aura-worker-redact-decode.php` in the tree:

```
        ├── class-aura-worker-redact-idna.php   # Redaction stage 2: UTS-46 hostname map, GENERATED by bin/generate-idna-map.php (2.18.3)
```

Add a row after `Aura_Worker_Redact_Decode` in the class table:

```
| `Aura_Worker_Redact_Idna` | `includes/class-aura-worker-redact-idna.php` | Redaction stage 2 view 2 (2.18.3, #116), pure, GENERATED — `map()` applies Unicode 18.0.0's UTS-46 hostname mappings and deletions with one `strtr()`; regenerate with `php bin/generate-idna-map.php` (pinned URL + SHA-256), then run the tests |
```

- [ ] **Step 2: Describe the views in the stage 2 bullet**

After the sentence that says every layer is checked against the stage 2 patterns, add:

```
  Each layer is judged through up to four VIEWS, in order, and the first that
  matches names the placeholder (2.18.3, #116): (1) the layer as it is against
  `stage_2_patterns()`; (2) its UTS-46 mapping (`Aura_Worker_Redact_Idna::map()`:
  fullwidth and mathematical letters, `。` / `．` / `｡`, `⒈` → `1.`, and deletions
  of soft hyphen, zero-width space, word joiner, BOM, variation selectors — 1526
  entries) against the same patterns; (3) the URL parser's reading (`url_view()`:
  every `\` → `/`) against `url_patterns()` — the same patterns with the prefix
  REQUIRED: `https?:` + zero or more slashes (a WHATWG parser skips any number
  after a special scheme, so `https:\host`, `https:/host` and `https:host` all
  count), or exactly `//`; (4) both. A run whose layers hold nothing to decode
  gets views 2–4 of its raw layer but not view 1 (stage 1 judged that with its
  host boundary). A field enters stage 2 when it holds `%`, `&` or `\`, or a byte
  ≥ 0x80 together with a slash of either kind; a run is decoded and viewed when it
  holds `%`, `&`, `\` or a byte ≥ 0x80. `original_runs()` keeps only the runs at
  stage 1 cut indexes (two passes, no full run arrays), so a field of millions of
  runs costs a few times its own size.
```

- [ ] **Step 3: Rewrite the Limits**

Remove the item that begins `WHATWG URL-parser readings this design does not follow` (both the backslash and the UTS-46 halves). In the terminator item, replace the parenthesis `(so \"`, `\n`, `\t` end it, … a WHATWG URL parser instead treats a bare `\` as `/` in an http(s) URL, so `hooks.zapier.com\abc` is not matched here — a known limit, see below)` with `(so `\"`, `\n`, `\t` end it, but `\uXXXX`, `\\` and `\/` do not — that is stage 1's plain-text/JSON reading; stage 2 then re-reads the run the way a URL parser does whenever the URL carries a scheme or `//`, see the views above)`.

Add these items:

```
  - a backslash after a BARE host — no `https:`, `http:` or `//` — is not a path
    separator: `hooks.zapier.com\abc` and `hooks.zapier.com\nNext` are kept, as are
    Windows paths, UNC paths whose host is not a receiver and `file:` URLs (owner
    decision, 2.18.3, #116);
  - UTS-46 is applied as a character map only (2.18.3, #116): NFC normalisation is
    not (a base letter plus a combining mark stays two code points — a different
    host, nothing leaks), `deviation` code points (`ß`, `ς`, U+200C, U+200D) are
    kept as a parser in non-transitional mode keeps them, and a real IDN host
    (`xn--…`) is matched only if a receiver pattern names it (none does);
  - the lookalike cost above also covers a host preceded, in the same run, by
    non-ASCII text that maps to ASCII: `ｍｙhooks.zapier.com/x` is redacted (2.18.3,
    #116);
  - TAB, LF and CR inside a URL are not stripped, neither literal (the run ends
    there) nor encoded (`&#10;`, `%09`, `\u000A` stay in the decoded layer, where no
    pattern accepts them), although a WHATWG parser removes all three from anywhere
    in a URL before parsing it (owner decision, 2.18.3, #116);
```

- [ ] **Step 4: Check and commit**

Run: `grep -n "not matched here\|not normalised by" CLAUDE.md` — Expected: no output (the old limit text is gone).

```bash
git add CLAUDE.md
git commit -m "docs: redaction stage 2 views, the generated UTS-46 map, and the 2.18.3 limits (#116)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Self-review (done while writing)

- **Spec coverage:** §3.1 views → Task 3 (`judge_layer`, `url_view`, `url_patterns`, `RE_HEAD_SCHEMED`, the raw-layer and cut rules); §3.2 table + generator → Task 1; §3.3 fast paths → Task 3 (`needs_stage_2`, `needs_stage_2_run`, `receiver_kind( $decoded, $patterns )`); §3.4 memory → Task 2; §3.5 unchanged → Global Constraints; §4 performance → Task 3's Hebrew and CJK cases, Task 2's memory case; §5 tests → Tasks 1–3 (staging is the release PR's); §6 docs → Task 4.
- **Type consistency:** `original_runs()` returns `array<int,string>|null|false` in Task 2 and is consumed by `isset( $originals[ $index ] )` in Tasks 2 and 3; `receiver_kind( $decoded, $patterns )` is changed and called only inside Task 3; `Aura_Worker_Redact_Idna::map()` / `MAP` / `LEAD` are produced in Task 1 and consumed in Tasks 1 and 3.
- **Placeholders:** none; the one open number (Task 2's `$count` for the memory case) is pinned from `main` in Step 2 before the change, by instruction.
