# SiteAgent 2.18.0 — agent read redaction v2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship SiteAgent 2.18.0 — the site half of Aura#419 v2: webhook endpoints and other known secrets never leave the site in a REST response an agent reads; a write carrying the one-way placeholder is refused; Aura's own page-snapshot capture stays unredacted when it proves itself with a signed grant; two hourly counters, `audit_rules` fields and a `/status` fragment report it.

**Architecture:** One new class, `Aura_Worker_Redact` (`includes/class-aura-worker-redact.php`). Its detectors are pure static functions (URL patterns, a closed key list, three string carriers). Two WordPress seams use them: `rest_pre_echo_response` (the read seam — core applies it only in `WP_REST_Server::serve_request()`, on the final data after `_envelope` and `_embed`) and `rest_request_before_callbacks` (the placeholder write guard, then the `X-Aura-Unredacted-Grant` check). The audience predicate reuses `Aura_Worker_Rules`' REST and cookie detection through two small public wrappers, and the counters reuse `Aura_Worker_Rules::bump()` through a third, so no new raw-SQL writer appears.

**Tech Stack:** PHP 7.4+ (CI matrix 7.4 / 8.1 / 8.2), WordPress 6.2+, PHPUnit (`composer test`, or `vendor/bin/phpunit --filter <name>`), PHPCS (`composer lint`). The harness stubs WordPress (`tests/bootstrap.php`, nothing from WordPress is loaded).

**Spec:** `Digitizers/Aura` — `docs/superpowers/specs/2026-09-16-agent-read-redaction-v2-design.md` (main @ `e90055d0`). This plan covers the SiteAgent side only: §1, §2, §3, §4 (site half), §6 (SiteAgent PHPUnit), §7 step 1. The Aura side (§1.3 capture grant, `SnapshotUnrestorableError`, parsing and display) is a separate plan and must ship before or with the first 2.18.0 site that holds a webhook (spec §7.2).

**Baseline:** `main` at `c66a2d6` (2.17.5). Record `composer test`'s test/assertion count and deprecation count before Task 1; they must only grow (deprecations must not).

## Global Constraints

- Placeholder, exactly: `aura-redacted:v1:<kind>`, `<kind>` ∈ `make`, `integromat`, `zapier`, `slack`, `discord`, `ifttt`, `telegram`, `field`. No hash, no part of the secret (spec §2.3).
- `URL_PATTERNS` are full-URL regexes anchored on the registrable host; they accept `/` and JSON-escaped `\/`; lookalike hosts (`hooks.zapier.com.evil.tld`, `myhooks.zapier.com`) never match (spec §2.1).
- `SECRET_KEYS` is a closed list of exact key names, starting as `webhooks` only, each entry commented with its plugin and setting. Never a substring match on `webhook` (spec §2.2, #418).
- Carriers decoded (spec §2.2a): (1) string under `_elementor_data` / `_elementor_page_settings`; (2) the `text` of a `type: "text"` object in an array under `content`; (3) the base64 `payload` of a `snapshot_get`-shaped answer, read with `unserialize( $bytes, array( 'allowed_classes' => false ) )`. At most two JSON decodes along one path. Re-encode with `wp_json_encode` only when something inside was replaced.
- A response with no match is returned unchanged — the same value, no re-encode (spec §2.4).
- Audience (spec §1.2): served REST request AND not cookie-authenticated AND (route exactly `/aura/mcp/tools/execute` OR (route outside `aura/v1`, `aura/v2`, `aura/mcp` AND user logged in)).
- Unredacted grant (spec §1.3): header `X-Aura-Unredacted-Grant`, verified with `Aura_Worker_Grant::verify()`; tool `unredacted-read:mcp/<server>#<params.name>` with `params.arguments` on a single-object JSON-RPC `tools/call` POST to `/mcp/<server>` (`[a-z0-9-]+`, one segment); tool `unredacted-read:aura/mcp#<tool>` with the executor's `params` on exactly `/aura/mcp/tools/execute`. Failure on a recognised shape → `403 aura_unredacted_grant_invalid`. No usable key (`Aura_Worker_Grant::has_usable_key()` false) → header ignored. Exemption is per request object (`spl_object_id`) and only for that response.
- Write guard (spec §3): methods other than `GET`/`HEAD`/`OPTIONS`, audience only; walks `get_query_params()`, `get_body_params()`, `get_json_params()`, `get_url_params()` separately — never `get_params()`; any decoded string containing `aura-redacted:` → `WP_Error( 'aura_redacted_placeholder', <spec message>, array( 'status' => 409 ) )`.
- Observability (spec §4): hourly counters `REDACTED_COUNTER` (one per response with ≥1 replacement) and `PLACEHOLDER_REFUSED_COUNTER`; `do_action( 'aura_worker_redacted', $count, $route )`, `do_action( 'aura_worker_placeholder_refused', $route )`; `audit_rules.enforcement` gains `redacted_24h`, `placeholder_refused_24h`, and `points` names the two seams; `/status` gains `redaction: { v: 1 }` (a JSON object).
- The fork (elementor-mcp) needs no change (spec §1.2). Nothing on the Aura side is planned here.
- Coding conventions: tabs; `if ( ! defined( 'ABSPATH' ) ) { exit; }`; `Aura_Worker_*` prefixes; `composer lint` green; every `@` carries the line-level `phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged` the codebase uses. PHP 7.4 syntax only (no `match`, no `str_contains`, no union types).
- Version bump touches **four places, always together** (CLAUDE.md "Releasing"): `Version:` header and `AURA_WORKER_VERSION` in `digitizer-site-worker/digitizer-site-worker.php`; `Stable tag:` **and** a `== Changelog ==` entry in `digitizer-site-worker/readme.txt`; a `## Changelog` entry **and** the `Stable-x.y.z` badge in `README.md`.
- `readme.txt` changelog headroom is **248 words** (`php .github/scripts/check-readme-limits.php` on `c66a2d6`). If the 2.18.0 entry does not fit, MOVE the oldest entries byte-for-byte to `docs/changelog-archive.md` and advance the stub — never compress or rewrite an entry.
- Git: explicit `git add <path>` per file, never `-A`; never `git stash`; commit trailer `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`; one PR, then the Codex review loop; merge and release only on the owner's approval.

## Rulings on spec gaps (made while writing this plan — reviewers, check these first)

- **R1 — the snapshot capture's meta entry is carrier 1.** `snapshot_posts()` stores each meta as `meta[<key>] = { existed, value }` (verified: `class-aura-worker-snapshots.php` `snapshot_posts()`, and Aura's fixture `apps/app/tests/fixtures/door/snapshot-get-complete.json`), so `_elementor_data`'s JSON string sits one level below the key. An array (or object) under a `JSON_META_KEYS` key has its string `value` treated as carrier 1. Without this, a door payload's webhook on a non-listed host would pass.
- **R2 — the payload decode does not count toward the two-JSON-decode bound; a payload inside a payload fails closed.** `snapshot_get` is also an ability, so it can come back through an MCP text carrier: text (decode 1) → payload → `_elementor_data` (decode 2). Counting the payload would leave that `_elementor_data` URL-only and leak a `webhooks` value on a non-listed host through the text copy. A second payload nested in a payload is not a real shape; it becomes `payload: null, payload_redacted: true`.
- **R3 — an opaque object in a payload.** `unserialize( …, allowed_classes => false )` yields `__PHP_Incomplete_Class`, whose properties cannot be written back. It is kept when its serialized bytes contain no receiver URL and no `SECRET_KEYS`/`JSON_META_KEYS` key name; otherwise the whole payload fails closed (R2's shape).
- **R4 — the write guard never unserializes agent-supplied bytes.** For a carrier-3-shaped object in a write it scans the base64-decoded bytes for `aura-redacted:` (serialize() stores strings verbatim). PHP's own docs warn against `unserialize()` on untrusted input even with `allowed_classes`; §3 is not a security boundary, so this is enough.
- **R5 — ordering on `rest_request_before_callbacks`.** One callback at priority **6** — after `Aura_Worker_Rules::guard_core_any()` (5), so a rule block or unbind refusal wins and no grant nonce is spent — that runs the write guard first, then the grant check (a refused write spends no nonce). Both run only when the request is in the audience; outside it the header is ignored (nothing would be redacted anyway) and no nonce is spent. A non-null `$response` (an earlier refusal or core's parameter error) passes straight through.
- **R6 — an unbound site answers `403 aura_site_unbound`, not `aura_unredacted_grant_invalid`.** `Aura_Worker_Grant::verify()` returns that `WP_Error` before looking at the grant; it is passed through unchanged, like every other `verify()` caller does.
- **R7 — routes compare lowercased.** WordPress matches routes case-insensitively (`@^…$@i`), so `/AURA/MCP/TOOLS/EXECUTE` reaches the executor; the redactor lowercases before comparing, for the gateway route, the own-namespace test and the `/mcp/<server>` shape.
- **R8 — "served REST request" at `rest_request_before_callbacks` is `REST_REQUEST`** (with the existing `Aura_Worker_Rules::$rest_request_override` test seam), exposed as `Aura_Worker_Rules::serving_rest()`. `rest_pre_echo_response` is served by construction.
- **R9 — fail closed on engine failure.** A PCRE failure (backtrack/JIT limit) or a failed re-encode replaces the whole string with `aura-redacted:v1:field`.
- **R10 — a `SECRET_KEYS` value that already starts with `aura-redacted:` is left alone and not counted.**
- **R11 — point names.** `enforcement.points` becomes `execute_tool`, `rest_updates`, `core_rest_content`, `read_redaction`, `placeholder_guard`. Aura's parser (`securityAudit.ts`, `EXPECTED_POINTS` + `includes`) accepts extra points, so this is compatible.
- **R12 — a fail-closed payload counts as one replacement** (it bumps `REDACTED_COUNTER` and fires `aura_worker_redacted`).
- **R13 — counters go through `Aura_Worker_Rules::bump_counter()`**, a public wrapper over the private `bump()` that accepts only the four known prefixes; the raw-SQL writer count `UninstallCoverageTest` acknowledges for `class-aura-worker-rules.php` (5) is unchanged, and both new prefixes sit under `aura_worker_`, which `uninstall.php` already sweeps.
- **R14 — objects in response data.** `stdClass` and other plain objects are walked by public properties and replaced with a `stdClass` only when something changed (the JSON output is the same); `JsonSerializable` is walked through `jsonSerialize()`.
- **R15 — `arguments` must be a JSON object.** Absent or `null` → `{}`; a non-empty list or a scalar → the shape is not recognised (header ignored, response redacted).
- **R16 — the URL patterns are a superset of the spec's.** They also accept optional `userinfo@` and `:port`, any number of backslashes before a slash, and are case-insensitive. IFTTT also matches `/trigger/<event>/[json/]with/key/<key>` (Codex r1 P1 on SiteAgent#109). The URL tail stops before `)`, `]`, `}`, and a match's trailing `.,;:!?` are handed back to the text (Codex r1 P2); the tail also stops before an HTML-encoded quote or angle bracket (`&quot;`, `&#39;`, `&gt;`, `&#x3c;`, …), while a bare `&` stays in the URL (Codex r2 P2). A pathological input (e.g. a million `a&`) can exhaust PCRE's backtrack limit; R9 then fails the whole string closed. A private/protected property's mangled name counts as the key name in the opaque-object check (Codex r2 P1). String keys and property names get the URL detector too, with `#2`, `#3`, … on a collision so no value is lost, and a placeholder used as a key refuses a write (Codex r3 P1). `https://hooks.zapier.com@evil.tld/` does not match (the host is `evil.tld`).

---

## File structure

| File | Responsibility |
|---|---|
| `digitizer-site-worker/includes/class-aura-worker-redact.php` (new) | `Aura_Worker_Redact`: detectors (Task 1), audience (Task 2), read seam + counters + `init()` (Task 3), unredacted grant (Task 4), write guard (Task 5), status fragment (Task 6) |
| `digitizer-site-worker/includes/class-aura-worker-rules.php` | Public wrappers `serving_rest()`, `cookie_authenticated()` (Task 2), `bump_counter()` (Task 3) |
| `digitizer-site-worker/digitizer-site-worker.php` | `require_once` of the new class after `class-aura-worker-rules.php` (line 79) (Task 3); version (Task 7) |
| `digitizer-site-worker/includes/class-aura-worker.php` | `Aura_Worker_Redact::init()` right after `Aura_Worker_Rules::init()` (~line 83) (Task 3) |
| `digitizer-site-worker/includes/tools/class-tool-audit-rules.php` | `redacted_24h`, `placeholder_refused_24h`, two new points (Task 6) |
| `digitizer-site-worker/includes/class-aura-worker-api.php` | `/status` → `redaction` (Task 6), beside `host` (~line 605) |
| `tests/bootstrap.php` | require the class (Task 1); `WP_REST_Request` parameter sources + body (Task 4); `sa_reset_state()` clears the exemption memo (Task 4) |
| `tests/unit/RedactDetectorsTest.php` (new) | Task 1 |
| `tests/unit/RedactAudienceTest.php` (new) | Task 2 |
| `tests/unit/RedactSeamTest.php` (new) | Task 3 |
| `tests/unit/RedactGrantTest.php` (new) | Task 4 |
| `tests/unit/RedactWriteGuardTest.php` (new) | Task 5 |
| `tests/unit/RedactReportingTest.php` (new), `tests/unit/AuditRulesTest.php` | Task 6 — AuditRulesTest line 37 asserts the exact `points` array and must change |
| `CLAUDE.md`, `digitizer-site-worker/readme.txt`, `README.md` | Task 7 |

Facts the implementer must not "fix":

- **`rest_post_dispatch` is the wrong seam.** Core runs it before `_envelope` and before `_embed` expands links (`serve_request()`), so a hook there returns embedded resources verbatim (spec §1.1, Codex r1). Do not add the redactor to `rest_post_dispatch` or `rest_request_after_callbacks`; Task 3 has a test that pins this.
- **`Aura_Worker_Rules::is_agent_rest_request()` is not the audience.** It excludes SiteAgent's own transport, and the gateway reaches every tool through `/aura/mcp/tools/execute` (spec §1.2). The redactor has its own predicate.
- **The unredacted-grant exemption is keyed on the request object, and the object is held** in `Aura_Worker_Redact::$exempt` until its echo, so its `spl_object_id` cannot be reused by another object meanwhile.

---

### Task 1: pre-flight facts, and the detectors as pure functions

**Files:**
- Create: `digitizer-site-worker/includes/class-aura-worker-redact.php`
- Modify: `tests/bootstrap.php:4322` (require the class after `class-aura-worker-rules.php`)
- Test: `tests/unit/RedactDetectorsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Aura_Worker_Redact::redact( $data, &$count = 0 )` — returns the redacted value (the same value when `$count` is 0).
  - `Aura_Worker_Redact::redact_text( $text, &$count ): string` — URL detector on one string.
  - Constants `PLACEHOLDER_MARK` (`'aura-redacted:'`), `PLACEHOLDER` (`'aura-redacted:v1:'`), `URL_PATTERNS` (list of `array( kind, regex )`), `SECRET_KEYS`, `JSON_META_KEYS`, `MAX_JSON_DECODES` (2), `REDACTED_COUNTER` (`'aura_worker_redacted_h'`), `PLACEHOLDER_REFUSED_COUNTER` (`'aura_worker_placeholder_refused_h'`), `GRANT_HEADER`, `GRANT_TOOL_PREFIX`, `GATEWAY_EXECUTE_ROUTE`, `MCP_ROUTE`, `STATUS_VERSION` (1).
  - Private helpers later tasks call: `is_snapshot_answer( array $node ): bool`, `is_text_block( $item ): bool`.

- [ ] **Step 1: Verify the two external facts the design rests on**

The read seam depends on core's order inside `WP_REST_Server::serve_request()`. Find a local WordPress copy (read-only):

Run: `find ~ /private/tmp /Applications -path '*wp-includes/rest-api/class-wp-rest-server.php' 2>/dev/null | head -3`

Then, on one of them:

Run: `grep -n "rest_post_dispatch\|isset( \$_GET\['_envelope'\] )\|rest_pre_serve_request\|response_to_data( \$result\|rest_pre_echo_response\|function respond_to_request\|rest_request_before_callbacks\|sanitize_params()" <path>`

Expected, in this line order inside `serve_request()`: `rest_post_dispatch` → the `_envelope` branch → `rest_pre_serve_request` → `$result = $this->response_to_data( $result, $embed )` → `rest_pre_echo_response`; and `rest_request_before_callbacks` only inside `respond_to_request()`, which `dispatch()` reaches after `has_valid_params()`/`sanitize_params()`. (Verified while writing this plan on WordPress 7.0.4 — Studio's bundled copy — at lines 464, 467, 516, 535, 539, 1256, 1119. `rest_pre_echo_response` exists since 4.8.1.) If no local copy exists, check the same lines in `https://github.com/WordPress/wordpress-develop/blob/6.2/src/wp-includes/rest-api/class-wp-rest-server.php`. If the order differs on 6.2, stop and report — the seam choice depends on it.

The spec requires checking that the MCP adapter's transport does not serve its own body:

Run: `grep -rn "rest_pre_serve_request" /Users/digitizer/Documents/GitHub/elementor-mcp/includes/vendors/mcp-adapter`
Expected: no output. (Verified while writing this plan: `HttpTransport::register_routes()` registers `<namespace>/<route>` with `handle_request()` returning a `WP_REST_Response`; the body is read with `get_json_params()`; the fork registers namespace `mcp`, route `elementor-mcp-server`, so the served route is `/mcp/elementor-mcp-server`.) If a hit appears, stop and report it — spec §5.6 records that limit.

- [ ] **Step 2: Require the new class in the test bootstrap**

In `tests/bootstrap.php`, directly after `require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-rules.php';` (line 4322):

```php
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-redact.php';
```

- [ ] **Step 3: Write the failing tests**

Create `tests/unit/RedactDetectorsTest.php`:

```php
<?php
/**
 * Aura_Worker_Redact's detectors (#419 v2, spec §2): known-receiver URLs,
 * known secret-holding keys, the three string carriers and the placeholder.
 * Pure functions — no hooks, no request.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * A serialized object inside a snapshot payload. unserialize() with
 * allowed_classes=false must never run __unserialize().
 */
final class SA_Redact_Unserialize_Probe {
	/** @var array The serialized properties. */
	private $data;

	public function __construct( array $data = array( 'note' => 'plain' ) ) {
		$this->data = $data;
	}

	public function __serialize(): array {
		return $this->data;
	}

	public function __unserialize( array $data ): void {
		$GLOBALS['_sa_redact_probe_woke'] = true;
	}
}

/** Serializes `webhooks` as a PRIVATE property: `s:…:"\0SA_Redact_Private_Probe\0webhooks"`. */
final class SA_Redact_Private_Probe {
	private $webhooks;

	public function __construct( string $webhooks ) {
		$this->webhooks = $webhooks;
	}
}

/** Serializes `webhooks` as a PROTECTED property: `s:…:"\0*\0webhooks"`. */
class SA_Redact_Protected_Probe {
	protected $webhooks;

	public function __construct( string $webhooks ) {
		$this->webhooks = $webhooks;
	}
}

final class RedactDetectorsTest extends TestCase {

	private const N8N = 'https://n8n.example.com/webhook/abc';

	protected function setUp(): void {
		sa_reset_state();
		unset( $GLOBALS['_sa_redact_probe_woke'] );
	}

	private function text( string $in, ?int &$count = null ): string {
		$count = 0;
		return Aura_Worker_Redact::redact_text( $in, $count );
	}

	/** An Elementor section holding one Pro form widget. */
	private function form_tree( string $webhook ): array {
		return array(
			array(
				'id'       => 'sec1',
				'elType'   => 'section',
				'settings' => new stdClass(),
				'elements' => array(
					array(
						'id'         => 'frm1',
						'elType'     => 'widget',
						'widgetType' => 'form',
						'settings'   => array(
							'form_name'              => 'Contact',
							'submit_actions'         => array( 'webhook', 'email' ),
							'webhooks'               => $webhook,
							'webhooks_advanced_data' => 'yes',
							'__globals__'            => new stdClass(),
						),
						'elements'   => array(),
					),
				),
			),
		);
	}

	/** An MCP `tools/call` result the way the adapter serves it. */
	private function mcp_result( string $text, $structured = null ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 3,
			'result'  => array(
				'content'           => array( array( 'type' => 'text', 'text' => $text ) ),
				'structuredContent' => $structured,
			),
		);
	}

	/** A real door page capture read back through snapshot_get. */
	private function door_answer( int $post_id, string $elementor_data ): array {
		$GLOBALS['_posts'][ $post_id ] = (object) array(
			'ID'             => $post_id,
			'post_title'     => 'Contact',
			'post_name'      => 'contact',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_parent'    => 0,
			'menu_order'     => 0,
			'post_author'    => 1,
			'post_date'      => '2026-01-01 00:00:00',
			'post_date_gmt'  => '2026-01-01 00:00:00',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);
		$GLOBALS['_post_meta'][ $post_id ]['_elementor_data'] = $elementor_data;
		$snap = ( new Aura_Worker_Snapshots() )->snapshot_posts( array( $post_id ), Aura_Worker_Elementor_Door::PAGE_META_KEYS, array( 'kind_label' => 'page' ) );
		$this->assertTrue( $snap['success'] );
		$answer = ( new Aura_Tool_Snapshot_Get() )->execute( array( 'id' => $snap['snapshot']['id'] ) );
		$this->assertIsString( $answer['payload'], 'fixture: a door capture inlines its payload' );
		return $answer;
	}

	private function payload_of( array $answer ): array {
		$captured = unserialize( (string) base64_decode( $answer['payload'], true ), array( 'allowed_classes' => false ) );
		$this->assertIsArray( $captured );
		return $captured;
	}

	// --- §2.1 known receivers -------------------------------------------

	/** @return array<string,array{0:string,1:string}> */
	public static function receivers(): array {
		return array(
			'make eu1'   => array( 'https://hook.eu1.make.com/abc123def456', 'make' ),
			'make us2'   => array( 'https://hook.us2.make.com/abc123def456', 'make' ),
			'integromat' => array( 'https://hook.integromat.com/abc123', 'integromat' ),
			'zapier'     => array( 'https://hooks.zapier.com/hooks/catch/123/abc/', 'zapier' ),
			'slack'      => array( 'https://hooks.slack.com/services/T000/B000/XXXX', 'slack' ),
			'discord'    => array( 'https://discord.com/api/webhooks/123/tok-en', 'discord' ),
			'discordapp' => array( 'https://discordapp.com/api/webhooks/123/tok-en', 'discord' ),
			'ifttt'      => array( 'https://maker.ifttt.com/use/abcDEF123', 'ifttt' ),
			'ifttt trig' => array( 'https://maker.ifttt.com/trigger/form_sent/with/key/abcDEF-123_x', 'ifttt' ),
			'ifttt json' => array( 'https://maker.ifttt.com/trigger/form_sent/json/with/key/abcDEF-123_x', 'ifttt' ),
			'telegram'   => array( 'https://api.telegram.org/bot123456:AA-bb_cc/sendMessage?chat_id=1', 'telegram' ),
		);
	}

	/** @dataProvider receivers */
	public function test_each_receiver_is_caught_in_a_plain_string( string $url, string $kind ): void {
		$this->assertSame( 'aura-redacted:v1:' . $kind, $this->text( $url, $n ) );
		$this->assertSame( 1, $n );
	}

	/** @dataProvider receivers */
	public function test_each_receiver_is_caught_at_the_start_and_the_end_of_text( string $url, string $kind ): void {
		$this->assertSame( "aura-redacted:v1:{$kind} is the hook", $this->text( "{$url} is the hook" ) );
		$this->assertSame( "the hook is aura-redacted:v1:{$kind}", $this->text( "the hook is {$url}" ) );
	}

	/** @dataProvider receivers */
	public function test_each_receiver_is_caught_json_escaped_and_the_json_stays_valid( string $url, string $kind ): void {
		$json = wp_json_encode( array( 'url' => $url, 'label' => 'x' ) );
		$this->assertStringContainsString( '\/', $json, 'fixture: slashes are escaped' );
		$out = $this->text( $json, $n );
		$this->assertSame( 1, $n );
		$this->assertSame( array( 'url' => 'aura-redacted:v1:' . $kind, 'label' => 'x' ), json_decode( $out, true ) );
	}

	/** @return array<string,array{0:string}> */
	public static function lookalikes(): array {
		return array(
			'suffixed host'     => array( 'https://hooks.zapier.com.evil.tld/hooks/catch/1/' ),
			'prefixed host'     => array( 'https://myhooks.zapier.com/hooks/catch/1/' ),
			'userinfo decoy'    => array( 'https://hooks.zapier.com@evil.tld/hooks/catch/1/' ),
			'slack non-hook'    => array( 'https://hooks.slack.com/other/T000' ),
			'discord non-hook'  => array( 'https://discord.com/channels/1/2' ),
			'make marketing'    => array( 'https://www.make.com/en/pricing' ),
			'ifttt non-hook'    => array( 'https://maker.ifttt.com/trigger/form_sent/without/key/abc' ),
			'ifttt other host'  => array( 'https://ifttt.com/maker_webhooks/settings' ),
			'telegram no token' => array( 'https://api.telegram.org/botfather/x' ),
			'self-hosted n8n'   => array( self::N8N ),
			'no scheme'         => array( 'hooks.zapier.com/hooks/catch/1/' ),
		);
	}

	/** @dataProvider lookalikes */
	public function test_lookalikes_are_not_caught( string $text ): void {
		$this->assertSame( $text, $this->text( $text, $n ) );
		$this->assertSame( 0, $n );
	}

	/** @return array<string,array{0:string,1:string}> input, expected */
	public static function surroundings(): array {
		return array(
			'html attribute'   => array( '<a href="https://hooks.zapier.com/hooks/catch/1/">send</a>', '<a href="aura-redacted:v1:zapier">send</a>' ),
			'markdown link'    => array( '[hook](https://hooks.zapier.com/hooks/catch/1/)', '[hook](aura-redacted:v1:zapier)' ),
			'parenthesised'    => array( 'the hook (https://hook.eu1.make.com/abc123) fires', 'the hook (aura-redacted:v1:make) fires' ),
			'bracketed'        => array( '[https://hooks.slack.com/services/T/B/X]', '[aura-redacted:v1:slack]' ),
			'end of sentence'  => array( 'see https://hook.eu1.make.com/abc.', 'see aura-redacted:v1:make.' ),
			'comma list'       => array( 'https://hook.eu1.make.com/a1, https://maker.ifttt.com/use/k2; done', 'aura-redacted:v1:make, aura-redacted:v1:ifttt; done' ),
			'question'         => array( 'is it https://hooks.zapier.com/hooks/catch/1/abc?', 'is it aura-redacted:v1:zapier?' ),
			'inner punctuation' => array( 'x https://api.telegram.org/bot1:AA-b/sendMessage?chat_id=1.5! y', 'x aura-redacted:v1:telegram! y' ),
			'encoded attribute' => array( '&lt;a href=&quot;https://hooks.zapier.com/hooks/catch/1/&quot;&gt;send&lt;/a&gt;', '&lt;a href=&quot;aura-redacted:v1:zapier&quot;&gt;send&lt;/a&gt;' ),
			'encoded close'     => array( 'go https://hook.eu1.make.com/abc&#62;x&#X3C;/a&#x3e;', 'go aura-redacted:v1:make&#62;x&#X3C;/a&#x3e;' ),
			'encoded apostrophe' => array( "href=&#39;https://maker.ifttt.com/use/k1.&#x27;", "href=&#39;aura-redacted:v1:ifttt.&#x27;" ),
			'query string'      => array( 'https://hooks.zapier.com/hooks/catch/1/?a=1&b=2 done', 'aura-redacted:v1:zapier done' ),
			'encoded query amp' => array( 'https://maker.ifttt.com/trigger/e/with/key/K?v=1&amp;x=2&quot;', 'aura-redacted:v1:ifttt&quot;' ),
		);
	}

	/** @dataProvider surroundings */
	public function test_only_the_url_is_replaced_and_the_surrounding_text_survives( string $in, string $expected ): void {
		$this->assertSame( $expected, $this->text( $in ) );
	}

	public function test_a_url_swallowed_behind_an_encoded_delimiter_is_redacted_too(): void {
		$out = $this->text( 'https://hooks.zapier.com/hooks/catch/1/&quot;&gt;https://hook.eu1.make.com/b2&lt;', $n );
		$this->assertSame( 'aura-redacted:v1:zapier&quot;&gt;aura-redacted:v1:make&lt;', $out );
		$this->assertSame( 2, $n );
	}

	public function test_a_secret_is_redacted_whole_before_trailing_punctuation(): void {
		$out = $this->text( 'key: https://maker.ifttt.com/trigger/ev/with/key/dK-9_zz.', $n );
		$this->assertSame( 'key: aura-redacted:v1:ifttt.', $out );
		$this->assertSame( 1, $n );
		$this->assertStringNotContainsString( 'dK-9', $out );
	}

	// --- §2.2 known fields -----------------------------------------------

	public function test_webhooks_on_an_unlisted_host_is_redacted_in_a_structured_tree(): void {
		$out = Aura_Worker_Redact::redact( $this->form_tree( self::N8N ), $n );

		$this->assertSame( 1, $n );
		$settings = $out[0]['elements'][0]['settings'];
		$this->assertSame( 'aura-redacted:v1:field', $settings['webhooks'] );
		$this->assertSame( 'Contact', $settings['form_name'] );
		$this->assertSame( array( 'webhook', 'email' ), $settings['submit_actions'], 'a value merely equal to "webhook" is not a secret' );
		$this->assertSame( 'yes', $settings['webhooks_advanced_data'], 'a key merely starting with "webhooks" is not in the list' );
	}

	public function test_webhooks_inside_elementor_data_is_redacted_and_the_json_round_trips(): void {
		$data = wp_json_encode( $this->form_tree( self::N8N ) );
		$post = array( 'id' => 7, 'meta' => array( '_elementor_data' => $data ) );

		$out = Aura_Worker_Redact::redact( $post, $n );

		$this->assertSame( 1, $n );
		$this->assertIsString( $out['meta']['_elementor_data'] );
		$expected = json_decode( $data );
		$expected[0]->elements[0]->settings->webhooks = 'aura-redacted:v1:field';
		$this->assertEquals( $expected, json_decode( $out['meta']['_elementor_data'] ), 'the same tree apart from the replaced value' );
		$this->assertStringContainsString( '"__globals__":{}', $out['meta']['_elementor_data'], 'an empty object stays an object' );
	}

	public function test_an_empty_webhooks_is_left_alone(): void {
		$tree = $this->form_tree( '' );
		$out  = Aura_Worker_Redact::redact( $tree, $n );
		$this->assertSame( 0, $n );
		$this->assertSame( $tree, $out );
	}

	public function test_a_key_merely_containing_webhook_is_left_alone(): void {
		$tree = array( 'webhook_label' => self::N8N, 'my_webhooks' => self::N8N );
		$this->assertSame( $tree, Aura_Worker_Redact::redact( $tree, $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_a_webhooks_value_that_is_already_a_placeholder_is_not_counted(): void {
		$tree = array( 'webhooks' => 'aura-redacted:v1:field' );
		$this->assertSame( $tree, Aura_Worker_Redact::redact( $tree, $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_elementor_data_that_is_not_json_gets_the_url_detector_only(): void {
		$post = array( '_elementor_data' => 'broken [ https://hooks.zapier.com/hooks/catch/1/ ' . self::N8N );
		$out  = Aura_Worker_Redact::redact( $post, $n );
		$this->assertSame( 1, $n );
		$this->assertSame( 'broken [ aura-redacted:v1:zapier ' . self::N8N, $out['_elementor_data'] );
	}

	public function test_a_carrier_with_no_match_is_returned_byte_for_byte(): void {
		$post = array( 'meta' => array( '_elementor_data' => '[ {"id": "a", "settings": {"title": "שלום"}} ]' ) );
		$this->assertSame( $post, Aura_Worker_Redact::redact( $post, $n ), 'no re-encode: spacing and escapes intact' );
		$this->assertSame( 0, $n );
	}

	public function test_a_url_used_as_a_key_is_redacted(): void {
		$data = array( 'first' => 1, 'https://hooks.zapier.com/hooks/catch/1/' => array( 'active' => true ), 'last' => 2 );
		$out  = Aura_Worker_Redact::redact( $data, $n );
		$this->assertSame( 1, $n );
		$this->assertSame( array( 'first' => 1, 'aura-redacted:v1:zapier' => array( 'active' => true ), 'last' => 2 ), $out, 'order kept' );
	}

	public function test_colliding_url_keys_keep_every_value(): void {
		$data = array(
			'https://hooks.zapier.com/hooks/catch/1/' => 'a',
			'aura-redacted:v1:zapier#2'               => 'stays',
			'https://hooks.zapier.com/hooks/catch/2/' => 'b',
			'https://hooks.zapier.com/hooks/catch/3/' => 'c',
		);
		$out = Aura_Worker_Redact::redact( $data, $n );
		$this->assertSame( 3, $n );
		$this->assertSame(
			array(
				'aura-redacted:v1:zapier'   => 'a',
				'aura-redacted:v1:zapier#2' => 'stays',
				'aura-redacted:v1:zapier#3' => 'b',
				'aura-redacted:v1:zapier#4' => 'c',
			),
			$out
		);
	}

	public function test_two_url_keys_become_the_placeholder_and_its_second(): void {
		$out = Aura_Worker_Redact::redact( array( 'https://hooks.zapier.com/hooks/catch/1/' => 1, 'https://hooks.zapier.com/hooks/catch/2/' => 2 ), $n );
		$this->assertSame( 2, $n );
		$this->assertSame( array( 'aura-redacted:v1:zapier' => 1, 'aura-redacted:v1:zapier#2' => 2 ), $out );
	}

	public function test_a_url_property_name_on_an_object_is_redacted(): void {
		$obj = new stdClass();
		$obj->{'https://hook.eu1.make.com/abc123'} = 'on';
		$obj->name = 'x';
		$out = Aura_Worker_Redact::redact( array( 'hooks' => $obj ), $n );
		$this->assertSame( 1, $n );
		$this->assertSame( '{"hooks":{"aura-redacted:v1:make":"on","name":"x"}}', wp_json_encode( $out ) );
	}

	public function test_url_keys_inside_a_carrier_are_redacted(): void {
		$post = array( '_elementor_data' => '{"https:\\/\\/hooks.slack.com\\/services\\/T\\/B\\/X":1}' );
		$out  = Aura_Worker_Redact::redact( $post, $n );
		$this->assertSame( 1, $n );
		$this->assertSame( array( 'aura-redacted:v1:slack' => 1 ), json_decode( $out['_elementor_data'], true ) );
	}

	public function test_a_key_named_webhooks_is_not_renamed_and_list_keys_are_untouched(): void {
		$list = array( 'x', 'y', array( 'webhooks' => '' ) );
		$this->assertSame( $list, Aura_Worker_Redact::redact( $list, $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_a_response_with_no_match_is_the_same_value(): void {
		$obj  = new stdClass();
		$data = array( 'id' => 7, 'title' => array( 'rendered' => 'Hi' ), 'links' => array( $obj ), 'n' => 1.5, 'b' => true, 'x' => null );
		$out  = Aura_Worker_Redact::redact( $data, $n );
		$this->assertSame( 0, $n );
		$this->assertSame( $data, $out );
		$this->assertSame( $obj, $out['links'][0], 'the very same object, not a copy' );
	}

	// --- §2.2a carrier 2: the MCP text carrier ---------------------------

	public function test_an_mcp_text_carrier_holding_an_export_tree_is_redacted_and_stays_valid_json(): void {
		$export = array( 'json' => $this->form_tree( self::N8N ) );
		$body   = $this->mcp_result( wp_json_encode( $export ), $export );

		$out = Aura_Worker_Redact::redact( $body, $n );

		$this->assertSame( 2, $n, 'the text copy and structuredContent' );
		$text = json_decode( $out['result']['content'][0]['text'] );
		$this->assertNotNull( $text, 'still valid JSON' );
		$this->assertSame( 'aura-redacted:v1:field', $text->json[0]->elements[0]->settings->webhooks );
		$this->assertSame( 'aura-redacted:v1:field', $out['result']['structuredContent']['json'][0]['elements'][0]['settings']['webhooks'] );
		$this->assertSame( 'text', $out['result']['content'][0]['type'] );
	}

	public function test_elementor_data_inside_an_mcp_text_carrier_is_redacted_two_decodes_deep(): void {
		$inner = array( 'post_id' => 7, 'meta' => array( '_elementor_data' => wp_json_encode( $this->form_tree( self::N8N ) ) ) );
		$out   = Aura_Worker_Redact::redact( $this->mcp_result( wp_json_encode( $inner ) ), $n );

		$this->assertSame( 1, $n );
		$text = json_decode( $out['result']['content'][0]['text'] );
		$tree = json_decode( $text->meta->_elementor_data );
		$this->assertSame( 'aura-redacted:v1:field', $tree[0]->elements[0]->settings->webhooks );
	}

	public function test_a_carrier_deeper_than_two_decodes_gets_the_url_detector_only(): void {
		// text (1) → _elementor_data (2) → _elementor_page_settings (would be 3).
		$third = wp_json_encode( array( 'webhooks' => self::N8N, 'hook' => 'https://hooks.zapier.com/hooks/catch/9/' ) );
		$inner = array( 'meta' => array( '_elementor_data' => wp_json_encode( array( '_elementor_page_settings' => $third ) ) ) );
		$out   = Aura_Worker_Redact::redact( $this->mcp_result( wp_json_encode( $inner ) ), $n );

		$this->assertSame( 1, $n, 'only the listed-host URL, by the text detector' );
		$text  = json_decode( $out['result']['content'][0]['text'] );
		$level = json_decode( $text->meta->_elementor_data );
		$this->assertStringContainsString( 'aura-redacted:v1:zapier', $level->_elementor_page_settings );
		$this->assertStringContainsString( 'n8n.example.com', $level->_elementor_page_settings );
	}

	public function test_a_content_text_that_is_not_json_gets_the_url_detector_only(): void {
		$out = Aura_Worker_Redact::redact( $this->mcp_result( 'Saved. Hook: https://hooks.zapier.com/hooks/catch/1/ and ' . self::N8N ), $n );
		$this->assertSame( 1, $n );
		$this->assertSame( 'Saved. Hook: aura-redacted:v1:zapier and ' . self::N8N, $out['result']['content'][0]['text'] );
	}

	public function test_an_mcp_carrier_with_no_match_is_the_same_value(): void {
		$body = $this->mcp_result( wp_json_encode( array( 'json' => $this->form_tree( '' ) ) ) );
		$this->assertSame( $body, Aura_Worker_Redact::redact( $body, $n ) );
		$this->assertSame( 0, $n );
	}

	// --- §2.2a carrier 3: the snapshot_get payload -----------------------

	public function test_a_door_capture_holding_a_webhook_comes_back_with_the_placeholder(): void {
		$answer = $this->door_answer( 7, wp_json_encode( $this->form_tree( self::N8N ) ) );
		$body   = array( 'success' => true, 'result' => $answer );

		$out = Aura_Worker_Redact::redact( $body, $n );

		$this->assertSame( 1, $n );
		$this->assertSame( $answer['record'], $out['result']['record'], 'the record is untouched' );
		$captured = $this->payload_of( $out['result'] );
		$this->assertSame( 'Contact', $captured[7]['fields']['post_title'] );
		$this->assertTrue( $captured[7]['meta']['_elementor_data']['existed'] );
		$tree = json_decode( $captured[7]['meta']['_elementor_data']['value'] );
		$this->assertSame( 'aura-redacted:v1:field', $tree[0]->elements[0]->settings->webhooks );
		$this->assertArrayNotHasKey( 'payload_redacted', $out['result'] );
	}

	public function test_a_door_capture_without_a_webhook_is_returned_byte_for_byte(): void {
		$answer = $this->door_answer( 8, wp_json_encode( $this->form_tree( '' ) ) );
		$body   = array( 'success' => true, 'result' => $answer );
		$this->assertSame( $body, Aura_Worker_Redact::redact( $body, $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_a_door_capture_read_through_an_mcp_text_carrier_is_redacted_too(): void {
		// Ruling R2: text (decode 1) → payload (not counted) → _elementor_data (decode 2).
		$answer = $this->door_answer( 9, wp_json_encode( $this->form_tree( self::N8N ) ) );
		$out    = Aura_Worker_Redact::redact( $this->mcp_result( wp_json_encode( $answer ) ), $n );

		$this->assertSame( 1, $n );
		$text     = json_decode( $out['result']['content'][0]['text'], true );
		$captured = $this->payload_of( $text );
		$tree     = json_decode( $captured[9]['meta']['_elementor_data']['value'] );
		$this->assertSame( 'aura-redacted:v1:field', $tree[0]->elements[0]->settings->webhooks );
	}

	public function test_a_serialized_object_is_read_without_instantiating_it_and_the_rest_is_walked(): void {
		$captured = array(
			7       => array(
				'existed' => true,
				'fields'  => array( 'post_title' => 'Contact' ),
				'meta'    => array( '_elementor_data' => array( 'existed' => true, 'value' => wp_json_encode( $this->form_tree( self::N8N ) ) ) ),
			),
			'extra' => new SA_Redact_Unserialize_Probe(),
		);
		$answer = array( 'found' => true, 'record' => array( 'id' => 'snap_x' ), 'payload' => base64_encode( serialize( $captured ) ) );

		$out = Aura_Worker_Redact::redact( $answer, $n );

		$this->assertSame( 1, $n );
		$this->assertArrayNotHasKey( '_sa_redact_probe_woke', $GLOBALS, 'no class was instantiated' );
		$back = $this->payload_of( $out );
		$this->assertInstanceOf( '__PHP_Incomplete_Class', $back['extra'] );
		$tree = json_decode( $back[7]['meta']['_elementor_data']['value'] );
		$this->assertSame( 'aura-redacted:v1:field', $tree[0]->elements[0]->settings->webhooks );
	}

	public function test_a_serialized_object_that_may_hold_a_secret_fails_the_payload_closed(): void {
		// Ruling R3: an opaque object cannot be rewritten, so a secret-looking one withholds the payload.
		$captured = array( 'extra' => new SA_Redact_Unserialize_Probe( array( 'webhooks' => self::N8N ) ) );
		$answer   = array( 'found' => true, 'record' => array( 'id' => 'snap_y' ), 'payload' => base64_encode( serialize( $captured ) ) );

		$out = Aura_Worker_Redact::redact( $answer, $n );

		$this->assertSame( 1, $n );
		$this->assertNull( $out['payload'] );
		$this->assertTrue( $out['payload_redacted'] );
		$this->assertSame( array( 'id' => 'snap_y' ), $out['record'] );
	}

	/** @return array<string,array{0:object,1:string}> */
	public static function mangled_secret_objects(): array {
		return array(
			'private property'   => array( new SA_Redact_Private_Probe( self::N8N ), "\0SA_Redact_Private_Probe\0webhooks" ),
			'protected property' => array( new SA_Redact_Protected_Probe( self::N8N ), "\0*\0webhooks" ),
		);
	}

	/** @dataProvider mangled_secret_objects */
	public function test_a_mangled_secret_property_fails_the_payload_closed( object $probe, string $mangled ): void {
		$bytes = serialize( array( 'extra' => $probe ) );
		$this->assertStringContainsString( $mangled . '";', $bytes, 'fixture: the property name is mangled' );
		$this->assertStringNotContainsString( 's:8:"webhooks";', $bytes, 'fixture: the plain key never appears' );
		$answer = array( 'found' => true, 'record' => array( 'id' => 'snap_m' ), 'payload' => base64_encode( $bytes ) );

		$out = Aura_Worker_Redact::redact( $answer, $n );

		$this->assertSame( 1, $n );
		$this->assertNull( $out['payload'], 'an unlisted host behind a mangled key is still withheld' );
		$this->assertTrue( $out['payload_redacted'] );
	}

	/** @return array<string,array{0:string}> */
	public static function unreadable_payloads(): array {
		return array(
			'not base64'           => array( '***not base64***' ),
			'not serialized'       => array( base64_encode( 'plain bytes' ) ),
			'serialized non-array' => array( base64_encode( serialize( 'a string' ) ) ),
			'serialized false'     => array( base64_encode( serialize( false ) ) ),
		);
	}

	/** @dataProvider unreadable_payloads */
	public function test_a_payload_that_does_not_unserialize_to_an_array_fails_closed( string $payload ): void {
		$answer = array( 'found' => true, 'record' => array( 'id' => 'snap_z', 'door_kind' => 'page' ), 'payload' => $payload );

		$out = Aura_Worker_Redact::redact( $answer, $n );

		$this->assertSame( 1, $n );
		$this->assertNull( $out['payload'] );
		$this->assertTrue( $out['payload_redacted'] );
		$this->assertTrue( $out['found'] );
		$this->assertSame( $answer['record'], $out['record'], 'the record is still returned' );
	}

	public function test_an_answer_without_a_payload_is_not_a_carrier(): void {
		$answer = array( 'found' => true, 'record' => array( 'id' => 'snap_w' ), 'payload' => null, 'withheld' => true );
		$this->assertSame( $answer, Aura_Worker_Redact::redact( $answer, $n ) );
		$this->assertSame( 0, $n );
	}
}
```

- [ ] **Step 4: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/unit/RedactDetectorsTest.php`
Expected: FAIL — `require_once(...class-aura-worker-redact.php): Failed to open stream` from the bootstrap (the class file does not exist yet).

- [ ] **Step 5: Create the class with its detectors**

Create `digitizer-site-worker/includes/class-aura-worker-redact.php`:

```php
<?php
/**
 * Agent read redaction (#419, v2).
 *
 * Operator rules govern writes only, so a read hands an agent whatever the
 * site stores — including a webhook endpoint, which is a bearer secret: a
 * Make or Zapier URL lets anyone who holds it post into the automation. This
 * class keeps those values out of every REST response an AGENT reads:
 *
 *  - the READ seam, `rest_pre_echo_response`. Core applies it in
 *    WP_REST_Server::serve_request() only, on the final data — after
 *    `_envelope`, and after `_embed` expanded linked resources through
 *    internal dispatches. An internal rest_do_request() is therefore never
 *    redacted on its own, and everything it contributes to a served body is;
 *  - the WRITE guard on `rest_request_before_callbacks`: the placeholder is
 *    one-way, so a write that carries it is refused (409);
 *  - the one unredacted read: Aura's own page-snapshot capture, which proves
 *    itself with an `X-Aura-Unredacted-Grant` on exactly two request shapes.
 *
 * The placeholder is `aura-redacted:v1:<kind>` — no hash, no part of the
 * secret. Spec: Digitizers/Aura
 * docs/superpowers/specs/2026-09-16-agent-read-redaction-v2-design.md.
 *
 * @package Aura_Worker
 * @since 2.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Redact {

	/** What every placeholder starts with — the write guard's needle. */
	const PLACEHOLDER_MARK = 'aura-redacted:';

	/** The placeholder, before its kind. */
	const PLACEHOLDER = 'aura-redacted:v1:';

	/** Regex: a slash, JSON-escaped any number of times (or not at all). */
	const RE_SLASH = '(?:\\\\)*/';

	/** Regex: scheme, `//`, optional userinfo — the host must follow at once. */
	const RE_HEAD = '~https?:(?:\\\\)*/(?:\\\\)*/(?:[^\s/\\\\@"\'<>]+@)?';

	/** Regex: an optional port, then the slash that ends the host. */
	const RE_HOST_END = '(?::[0-9]+)?(?:\\\\)*/';

	/**
	 * Regex (after `&`): the name of an HTML-encoded quote or angle bracket —
	 * `quot`, `#34`, `#x22`, `apos`, `#39`, `#x27`, `gt`, `#62`, `#x3e`,
	 * `lt`, `#60`, `#x3c` (the pattern is case-insensitive).
	 */
	const RE_ENTITY = '(?:quot|apos|gt|lt|#0*(?:34|39|60|62)|#x0*(?:22|27|3c|3e));';

	/**
	 * Regex: the rest of the URL. It stops at whitespace, a quote, `<`, `>`,
	 * a closing `)` `]` `}` or a bare backslash, so a Markdown link or a
	 * parenthesis keeps its delimiter. It also stops before an HTML-encoded
	 * quote or angle bracket (RE_ENTITY) — a URL inside encoded markup ends
	 * there — while a bare `&` (a query-string separator, or `&amp;`) stays
	 * in the URL (Codex r2 P2 on SiteAgent#109). Trailing sentence
	 * punctuation is handed back by redact_text() (TRAILING_PUNCTUATION).
	 * Possessive: no backtracking.
	 */
	const RE_TAIL = '(?:[^\s"\'<>\\\\)\]}&]++|(?:\\\\)++/|&(?!' . self::RE_ENTITY . '))*+~i';

	/**
	 * Punctuation that ends a sentence rather than a URL: stripped from the
	 * end of a match and kept in the text (`…/abc.` → `…:make.`). Webhook
	 * secrets are `[A-Za-z0-9_-]`, so a secret is always replaced whole.
	 */
	const TRAILING_PUNCTUATION = '/[.,;:!?]+$/';

	/**
	 * Known receivers (spec §2.1): full-URL patterns anchored on the
	 * registrable host, never a substring of a field name. A list of
	 * `array( kind, regex )`, because two hosts share the `discord` kind.
	 */
	const URL_PATTERNS = array(
		array( 'make', self::RE_HEAD . 'hook\.[a-z0-9-]+\.make\.com' . self::RE_HOST_END . self::RE_TAIL ),
		array( 'integromat', self::RE_HEAD . 'hook\.integromat\.com' . self::RE_HOST_END . self::RE_TAIL ),
		array( 'zapier', self::RE_HEAD . 'hooks\.zapier\.com' . self::RE_HOST_END . self::RE_TAIL ),
		array( 'slack', self::RE_HEAD . 'hooks\.slack\.com' . self::RE_HOST_END . 'services' . self::RE_SLASH . self::RE_TAIL ),
		array( 'discord', self::RE_HEAD . 'discord\.com' . self::RE_HOST_END . 'api' . self::RE_SLASH . 'webhooks' . self::RE_SLASH . self::RE_TAIL ),
		array( 'discord', self::RE_HEAD . 'discordapp\.com' . self::RE_HOST_END . 'api' . self::RE_SLASH . 'webhooks' . self::RE_SLASH . self::RE_TAIL ),
		// IFTTT Webhooks: `/use/<key>`, `/trigger/<event>/with/key/<key>` and `/trigger/<event>/json/with/key/<key>`.
		array( 'ifttt', self::RE_HEAD . 'maker\.ifttt\.com' . self::RE_HOST_END . '(?:use' . self::RE_SLASH . '|trigger' . self::RE_SLASH . '[^\s/\\\\"\'<>]+' . self::RE_SLASH . '(?:json' . self::RE_SLASH . ')?with' . self::RE_SLASH . 'key' . self::RE_SLASH . ')' . self::RE_TAIL ),
		array( 'telegram', self::RE_HEAD . 'api\.telegram\.org' . self::RE_HOST_END . 'bot[0-9]+:[a-z0-9_-]+' . self::RE_SLASH . self::RE_TAIL ),
	);

	/**
	 * Exact key names whose non-empty string value is a webhook endpoint
	 * whatever its host (spec §2.2). Grows one OBSERVED case at a time, each
	 * with its plugin and setting named. Never a substring match (#418).
	 */
	const SECRET_KEYS = array(
		'webhooks', // Elementor Pro form widget: the "Webhook" submit action's URL (settings.webhooks).
	);

	/** Carrier 1 (spec §2.2a): the Elementor post metas stored as JSON strings. */
	const JSON_META_KEYS = array( '_elementor_data', '_elementor_page_settings' );

	/** JSON decodes allowed along one path (spec §2.2a). A payload decode is not counted (Ruling R2). */
	const MAX_JSON_DECODES = 2;

	/** Hourly counter: responses with at least one replacement (spec §4). */
	const REDACTED_COUNTER = 'aura_worker_redacted_h';

	/** Hourly counter: writes refused for carrying a placeholder (spec §4). */
	const PLACEHOLDER_REFUSED_COUNTER = 'aura_worker_placeholder_refused_h';

	/** Aura's proof that a read is its own snapshot capture (spec §1.3). */
	const GRANT_HEADER = 'X-Aura-Unredacted-Grant';

	/** Prefix of the grant's `tool` field (spec §1.3). */
	const GRANT_TOOL_PREFIX = 'unredacted-read:';

	/** The gateway's tool execution — in the audience whoever calls it. */
	const GATEWAY_EXECUTE_ROUTE = '/aura/mcp/tools/execute';

	/** An MCP adapter server route: exactly one segment under `mcp`. */
	const MCP_ROUTE = '#^/mcp/([a-z0-9-]+)$#';

	/** `/status` → `redaction: { v }`. A site without the key predates the feature. */
	const STATUS_VERSION = 1;

	/**
	 * Redact one response body — or any tree. Pure: no hooks, no counters.
	 *
	 * @param mixed $data  Response data.
	 * @param int   $count Out: how many values were replaced.
	 * @return mixed The redacted data; the SAME value when $count is 0.
	 */
	public static function redact( $data, &$count = 0 ) {
		$count = 0;
		return self::walk( $data, 0, false, $count );
	}

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
		if ( false === stripos( $text, 'http' ) ) {
			return $text; // no scheme, no URL: the common case never runs a regex
		}
		foreach ( self::URL_PATTERNS as $pattern ) {
			$hits        = 0;
			$placeholder = self::PLACEHOLDER . $pattern[0];
			$out         = preg_replace_callback(
				$pattern[1],
				static function ( $m ) use ( $placeholder ) {
					return 1 === preg_match( self::TRAILING_PUNCTUATION, $m[0], $tail ) ? $placeholder . $tail[0] : $placeholder;
				},
				$text,
				-1,
				$hits
			);
			if ( null === $out ) {
				// PCRE gave up (backtrack or JIT limit). Whether the string
				// holds a receiver URL is unknown, so none of it goes out (R9).
				++$count;
				return self::PLACEHOLDER . 'field';
			}
			if ( $hits > 0 ) {
				$text   = $out;
				$count += $hits;
			}
		}
		return $text;
	}

	/**
	 * @param mixed $node       Any value.
	 * @param int   $depth      JSON decodes already made along this path.
	 * @param bool  $in_payload Inside a snapshot payload (carrier 3).
	 * @param int   $count      In/out.
	 * @return mixed
	 * @throws UnexpectedValueException Inside a payload only: an opaque object may hold a secret (R3).
	 */
	private static function walk( $node, $depth, $in_payload, &$count ) {
		if ( is_string( $node ) ) {
			return self::redact_text( $node, $count );
		}
		if ( is_array( $node ) ) {
			return self::walk_array( $node, $depth, $in_payload, $count );
		}
		if ( ! is_object( $node ) ) {
			return $node; // int, float, bool, null
		}
		if ( $in_payload && $node instanceof __PHP_Incomplete_Class ) {
			// unserialize() with allowed_classes=false made this; its
			// properties cannot be written back. Provably clean, or the
			// payload fails closed (Ruling R3).
			if ( self::opaque_may_hold_secret( $node ) ) {
				throw new UnexpectedValueException( 'an opaque object in a snapshot payload may hold a secret' );
			}
			return $node;
		}
		$before = $count;
		if ( $node instanceof JsonSerializable ) {
			$walked = self::walk( $node->jsonSerialize(), $depth, $in_payload, $count );
			return $count === $before ? $node : $walked;
		}
		// Public properties are exactly what the JSON response carries (R14).
		$walked = self::walk_array( get_object_vars( $node ), $depth, $in_payload, $count );
		return $count === $before ? $node : (object) $walked;
	}

	/**
	 * @param array $node       Array.
	 * @param int   $depth      JSON decodes so far.
	 * @param bool  $in_payload Inside a payload.
	 * @param int   $count      In/out.
	 * @return array The same array when nothing inside was replaced.
	 */
	private static function walk_array( array $node, $depth, $in_payload, &$count ) {
		$skip = null;
		if ( self::is_snapshot_answer( $node ) ) {
			$node = self::redact_snapshot_answer( $node, $depth, $in_payload, $count );
			$skip = 'payload'; // decided above; base64 holds no URL anyway
		}
		foreach ( $node as $key => $value ) {
			if ( $skip === $key ) {
				continue;
			}
			$before = $count;
			$new    = self::walk_entry( $key, $value, $depth, $in_payload, $count );
			if ( $count !== $before ) {
				$node[ $key ] = $new;
			}
		}
		return self::redact_keys( $node, $count );
	}

	/**
	 * The URL detector over an array's string KEYS (Codex r3 P1 on
	 * SiteAgent#109): a response keyed by endpoint would otherwise carry the
	 * URL in the key, and wp_json_encode() emits keys. Object property names
	 * go through here too — walk() walks an object as its property array.
	 *
	 * Order is kept. A renamed key that collides with a key that stays, or
	 * with one renamed earlier, gets `#2`, `#3`, … in first-come order, so no
	 * value is lost. Each renamed key counts as a replacement. SECRET_KEYS is
	 * about values: a key NAMED `webhooks` is not a URL and is not renamed.
	 *
	 * @param array $node  Array whose values are already walked.
	 * @param int   $count In/out.
	 * @return array The same array when no key held a receiver URL.
	 */
	private static function redact_keys( array $node, &$count ) {
		$renamed = array();
		foreach ( $node as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue; // list indices
			}
			$hits = 0;
			$name = self::redact_text( $key, $hits );
			if ( $hits > 0 ) {
				$renamed[ $key ] = $name;
				$count          += $hits;
			}
		}
		if ( array() === $renamed ) {
			return $node;
		}
		$taken = array();
		foreach ( $node as $key => $value ) {
			if ( ! isset( $renamed[ (string) $key ] ) || ! is_string( $key ) ) {
				$taken[ (string) $key ] = true; // keys that stay are never displaced
			}
		}
		$out = array();
		foreach ( $node as $key => $value ) {
			if ( is_string( $key ) && isset( $renamed[ $key ] ) ) {
				$name = $renamed[ $key ];
				for ( $n = 2; isset( $taken[ $name ] ); $n++ ) {
					$name = $renamed[ $key ] . '#' . $n;
				}
				$taken[ $name ] = true;
				$out[ $name ]   = $value;
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * One key/value pair: the key decides whether the value is a secret
	 * field (§2.2), a JSON carrier (§2.2a) or an ordinary subtree.
	 *
	 * @param int|string $key        Key.
	 * @param mixed      $value      Value.
	 * @param int        $depth      JSON decodes so far.
	 * @param bool       $in_payload Inside a payload.
	 * @param int        $count      In/out.
	 * @return mixed
	 */
	private static function walk_entry( $key, $value, $depth, $in_payload, &$count ) {
		if ( is_string( $key ) && in_array( $key, self::SECRET_KEYS, true ) && is_string( $value ) ) {
			if ( '' === $value || 0 === strpos( $value, self::PLACEHOLDER_MARK ) ) {
				return $value; // empty, or already a placeholder: no secret here (R10)
			}
			++$count;
			return self::PLACEHOLDER . 'field';
		}
		if ( is_string( $key ) && in_array( $key, self::JSON_META_KEYS, true ) ) {
			if ( is_string( $value ) ) {
				return self::redact_carrier( $value, $depth, $in_payload, $count );
			}
			// A snapshot capture stores each meta as { existed, value } (R1).
			return self::walk_with_carrier( $value, 'value', $depth, $in_payload, $count );
		}
		if ( 'content' === $key && is_array( $value ) ) {
			foreach ( $value as $i => $item ) {
				$before = $count;
				$new    = self::is_text_block( $item )
					? self::walk_with_carrier( $item, 'text', $depth, $in_payload, $count )
					: self::walk_entry( $i, $item, $depth, $in_payload, $count );
				if ( $count !== $before ) {
					$value[ $i ] = $new;
				}
			}
			return self::redact_keys( $value, $count );
		}
		return self::walk( $value, $depth, $in_payload, $count );
	}

	/**
	 * An array or stdClass one of whose string fields is a JSON carrier.
	 *
	 * @param mixed  $container  Array or stdClass (anything else is walked plainly).
	 * @param string $carrier    The carrier field's name.
	 * @param int    $depth      JSON decodes so far.
	 * @param bool   $in_payload Inside a payload.
	 * @param int    $count      In/out.
	 * @return mixed
	 */
	private static function walk_with_carrier( $container, $carrier, $depth, $in_payload, &$count ) {
		$is_object = $container instanceof stdClass;
		if ( ! $is_object && ! is_array( $container ) ) {
			return self::walk( $container, $depth, $in_payload, $count );
		}
		$fields = $is_object ? get_object_vars( $container ) : $container;
		$before = $count;
		foreach ( $fields as $key => $value ) {
			$was = $count;
			$new = ( $carrier === $key && is_string( $value ) )
				? self::redact_carrier( $value, $depth, $in_payload, $count )
				: self::walk_entry( $key, $value, $depth, $in_payload, $count );
			if ( $count !== $was ) {
				$fields[ $key ] = $new;
			}
		}
		$fields = self::redact_keys( $fields, $count );
		if ( $count === $before ) {
			return $container;
		}
		return $is_object ? (object) $fields : $fields;
	}

	/**
	 * Carrier 2's container: `{ type: "text", text: <string> }`.
	 *
	 * @param mixed $item Candidate.
	 * @return bool
	 */
	private static function is_text_block( $item ) {
		$fields = $item instanceof stdClass ? get_object_vars( $item ) : $item;
		return is_array( $fields )
			&& isset( $fields['type'], $fields['text'] )
			&& 'text' === $fields['type']
			&& is_string( $fields['text'] );
	}

	/**
	 * A JSON-carrying string: decoded, walked, and re-encoded only when
	 * something inside was replaced. Objects stay objects (`{}` survives).
	 *
	 * @param string $text       The string.
	 * @param int    $depth      JSON decodes so far.
	 * @param bool   $in_payload Inside a payload.
	 * @param int    $count      In/out.
	 * @return string
	 */
	private static function redact_carrier( $text, $depth, $in_payload, &$count ) {
		if ( $depth >= self::MAX_JSON_DECODES ) {
			return self::redact_text( $text, $count );
		}
		$decoded = json_decode( $text );
		if ( ! is_array( $decoded ) && ! is_object( $decoded ) ) {
			return self::redact_text( $text, $count ); // not a JSON tree: plain text
		}
		$before = $count;
		$walked = self::walk( $decoded, $depth + 1, $in_payload, $count );
		if ( $count === $before ) {
			return $text; // untouched, byte for byte
		}
		$encoded = wp_json_encode( $walked );
		return is_string( $encoded ) ? $encoded : self::PLACEHOLDER . 'field'; // R9
	}

	/**
	 * Carrier 3's container: an answer shaped like `snapshot_get`'s.
	 *
	 * @param array $node Candidate.
	 * @return bool
	 */
	private static function is_snapshot_answer( array $node ) {
		return isset( $node['found'], $node['payload'] )
			&& is_bool( $node['found'] )
			&& array_key_exists( 'record', $node )
			&& is_string( $node['payload'] );
	}

	/**
	 * The payload is `base64_encode( serialize( $captured ) )` — the site's
	 * own bytes. Read without instantiating any class; rewritten only when
	 * something was replaced; withheld when it cannot be read (spec §2.2a.3).
	 *
	 * @param array $answer     The answer.
	 * @param int   $depth      JSON decodes so far.
	 * @param bool  $in_payload Already inside a payload.
	 * @param int   $count      In/out.
	 * @return array
	 */
	private static function redact_snapshot_answer( array $answer, $depth, $in_payload, &$count ) {
		$before = $count;
		try {
			if ( $in_payload ) {
				throw new UnexpectedValueException( 'a payload inside a payload' ); // R2
			}
			$bytes = base64_decode( $answer['payload'], true );
			if ( ! is_string( $bytes ) ) {
				throw new UnexpectedValueException( 'payload is not base64' );
			}
			$captured = self::read_serialized( $bytes );
			if ( ! is_array( $captured ) ) {
				throw new UnexpectedValueException( 'payload is not a serialized array' );
			}
			$walked = self::walk( $captured, $depth, true, $count );
		} catch ( UnexpectedValueException $e ) {
			// Nothing here can be proven free of a key-held secret: fail
			// closed, keep the record (R12: one replacement).
			$count                      = $before + 1;
			$answer['payload']          = null;
			$answer['payload_redacted'] = true;
			return $answer;
		}
		if ( $count !== $before ) {
			$answer['payload'] = base64_encode( serialize( $walked ) );
		}
		return $answer;
	}

	/**
	 * @param string $bytes Serialized bytes.
	 * @return mixed The value, or false when they do not unserialize.
	 */
	private static function read_serialized( $bytes ) {
		try {
			return @unserialize( $bytes, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed bytes are an answer (false → fail closed), not a warning to surface.
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Could this opaque object hold a secret? Its serialized form is checked
	 * for a receiver URL and for any key name the detectors act on (R3).
	 *
	 * A key name is found as a whole serialized string (`s:8:"webhooks";`)
	 * or as the tail of a private/protected property's mangled name
	 * (`s:11:"\0X\0webhooks";`, `s:11:"\0*\0webhooks";`) — i.e. preceded by
	 * `s:<len>:"` or by a NUL byte (Codex r2 P1 on SiteAgent#109).
	 *
	 * @param object $object An __PHP_Incomplete_Class.
	 * @return bool
	 */
	private static function opaque_may_hold_secret( $object ) {
		$bytes   = serialize( $object );
		$scratch = 0;
		if ( self::redact_text( $bytes, $scratch ) !== $bytes ) {
			return true;
		}
		foreach ( array_merge( self::SECRET_KEYS, self::JSON_META_KEYS ) as $key ) {
			$name = '(?:s:' . strlen( $key ) . ':"|\x00)' . preg_quote( $key, '/' ) . '";';
			if ( 1 === preg_match( '/' . $name . '/', $bytes ) ) {
				return true;
			}
		}
		return false;
	}
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/RedactDetectorsTest.php`
Expected: PASS (all data-provider cases).

- [ ] **Step 7: Run the whole suite and the linter**

Run: `composer test` — expected: green; the count grows by this file's tests only.
Run: `composer lint` — expected: no errors. The new file adds five `WordPress.PHP.DiscouragedPHPFunctions` **warnings** (`base64_*`, `serialize`/`unserialize` in the carrier-3 code — checked while writing this plan with the repo's `phpcs.xml.dist`); the gate fails on errors only and the tree already carries 88 such warnings, so leave them visible rather than suppressing them. `UninstallCoverageTest` stays green: the class writes nothing.

- [ ] **Step 8: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-redact.php tests/bootstrap.php tests/unit/RedactDetectorsTest.php
git commit -m "feat(redact): read-redaction detectors — receiver URLs, secret keys, three string carriers (#419)

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 2: the audience predicate

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-rules.php` — two public wrappers beside `is_cookie_authenticated()` (~line 2696), and `is_agent_rest_request()` (~line 2745) reads the first
- Modify: `digitizer-site-worker/includes/class-aura-worker-redact.php` — `is_audience()`, `is_own_route()`
- Test: `tests/unit/RedactAudienceTest.php`

**Interfaces:**
- Consumes: Task 1's constants (`GATEWAY_EXECUTE_ROUTE`).
- Produces:
  - `Aura_Worker_Rules::serving_rest(): bool` — `REST_REQUEST`, or `$rest_request_override` when a test set it.
  - `Aura_Worker_Rules::cookie_authenticated(): bool` — the existing private `is_cookie_authenticated()`.
  - `Aura_Worker_Redact::is_audience( $request ): bool` (spec §1.2).

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/RedactAudienceTest.php`:

```php
<?php
/**
 * Who gets redacted (#419 v2, spec §1.2): agents, identified by how they
 * authenticated — never a person in wp-admin, never the anonymous public,
 * never the Aura server's own system routes.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactAudienceTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function audience( string $method, string $route ): bool {
		return Aura_Worker_Redact::is_audience( new WP_REST_Request( $method, $route ) );
	}

	/** @return array<string,array{0:string,1:string,2:bool,3:bool}> method, route, logged in, expected */
	public static function cases(): array {
		return array(
			'gateway execute, token-only'        => array( 'POST', '/aura/mcp/tools/execute', false, true ),
			'gateway execute, logged in'         => array( 'POST', '/aura/mcp/tools/execute', true, true ),
			'gateway execute, uppercase (R7)'    => array( 'POST', '/AURA/MCP/TOOLS/EXECUTE', false, true ),
			'local MCP client on mcp/v1'         => array( 'POST', '/mcp/mcp-adapter-default-server', true, true ),
			'Elementor MCP server'               => array( 'POST', '/mcp/elementor-mcp-server', true, true ),
			'agent on wp/v2 (read)'              => array( 'GET', '/wp/v2/pages/7', true, true ),
			'agent on wp/v2 (write)'             => array( 'POST', '/wp/v2/pages/7', true, true ),
			'another plugin route'               => array( 'GET', '/wc/v3/orders', true, true ),
			'lookalike namespace, logged in'     => array( 'GET', '/aura/mcpx/tools/execute', true, true ),
			'status'                             => array( 'GET', '/aura/v1/status', true, false ),
			'rules'                              => array( 'POST', '/aura/v2/rules', true, false ),
			'snapshots'                          => array( 'GET', '/aura/v2/snapshots', true, false ),
			'gateway tools/list'                 => array( 'POST', '/aura/mcp/tools/list', false, false ),
			'gateway context, logged in'         => array( 'GET', '/aura/mcp/context', true, false ),
			'lookalike execute-foo'              => array( 'POST', '/aura/mcp/tools/execute-foo', false, false ),
			'lookalike execute-foo, logged in'   => array( 'POST', '/aura/mcp/tools/execute-foo', true, false ),
			'lookalike namespace, anonymous'     => array( 'GET', '/aura/mcpx/tools/execute', false, false ),
			'anonymous public wp/v2'             => array( 'GET', '/wp/v2/posts', false, false ),
			'anonymous MCP'                      => array( 'POST', '/mcp/elementor-mcp-server', false, false ),
		);
	}

	/** @dataProvider cases */
	public function test_the_audience( string $method, string $route, bool $logged_in, bool $expected ): void {
		$GLOBALS['_logged_in'] = $logged_in;
		$this->assertSame( $expected, $this->audience( $method, $route ) );
	}

	public function test_a_cookie_session_is_never_the_audience(): void {
		$GLOBALS['_logged_in']                   = true;
		Aura_Worker_Rules::$cookie_auth_override = true;
		$this->assertFalse( $this->audience( 'GET', '/wp/v2/pages/7' ) );
		$this->assertFalse( $this->audience( 'POST', '/aura/mcp/tools/execute' ) );
	}

	public function test_an_application_password_is_never_a_cookie_session(): void {
		$GLOBALS['_logged_in']                   = true;
		Aura_Worker_Rules::$cookie_auth_override = true;
		$GLOBALS['_rest_app_password']           = 'uuid-1';
		$this->assertFalse( Aura_Worker_Rules::cookie_authenticated() );
		$this->assertTrue( $this->audience( 'GET', '/wp/v2/pages/7' ) );
	}

	public function test_outside_rest_nothing_is_the_audience(): void {
		$GLOBALS['_logged_in']                    = true;
		Aura_Worker_Rules::$rest_request_override = false;
		$this->assertFalse( Aura_Worker_Rules::serving_rest() );
		$this->assertFalse( $this->audience( 'POST', '/aura/mcp/tools/execute' ) );
		$this->assertFalse( $this->audience( 'GET', '/wp/v2/pages/7' ) );
	}

	public function test_a_non_request_is_not_the_audience(): void {
		$GLOBALS['_logged_in'] = true;
		$this->assertFalse( Aura_Worker_Redact::is_audience( null ) );
		$this->assertFalse( Aura_Worker_Redact::is_audience( array( 'route' => '/wp/v2/pages' ) ) );
	}

	public function test_the_wrappers_report_what_the_private_checks_decide(): void {
		Aura_Worker_Rules::$rest_request_override = true;
		$this->assertTrue( Aura_Worker_Rules::serving_rest() );
		Aura_Worker_Rules::$cookie_auth_override = false;
		$this->assertFalse( Aura_Worker_Rules::cookie_authenticated() );
		Aura_Worker_Rules::$cookie_auth_override = true;
		$this->assertTrue( Aura_Worker_Rules::cookie_authenticated() );
	}
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/unit/RedactAudienceTest.php`
Expected: FAIL — `Call to undefined method Aura_Worker_Redact::is_audience()`.

- [ ] **Step 3: Add the wrappers to `Aura_Worker_Rules`**

In `class-aura-worker-rules.php`, directly after `is_cookie_authenticated()` (it ends just before the `is_agent_rest_request()` docblock):

```php
	/**
	 * Is a REST request being served? `REST_REQUEST`, or the test seam.
	 *
	 * Public for Aura_Worker_Redact (2.18.0, #419), whose audience starts
	 * with exactly this question — one definition, so the two seams can never
	 * disagree about it.
	 *
	 * @return bool
	 */
	public static function serving_rest() {
		return null !== self::$rest_request_override
			? (bool) self::$rest_request_override
			: ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * is_cookie_authenticated(), for Aura_Worker_Redact (2.18.0, #419): a
	 * person in wp-admin is never redacted, and "is this a person" is decided
	 * here and nowhere else.
	 *
	 * @return bool
	 */
	public static function cookie_authenticated() {
		return self::is_cookie_authenticated();
	}
```

In `is_agent_rest_request()`, replace its first four lines:

```php
		$is_rest = null !== self::$rest_request_override
			? (bool) self::$rest_request_override
			: ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! $is_rest ) {
```

with:

```php
		if ( ! self::serving_rest() ) {
```

(no behaviour change; the rest of the method is untouched).

- [ ] **Step 4: Add the predicate to `Aura_Worker_Redact`**

In `class-aura-worker-redact.php`, after `redact_text()`:

```php
	/**
	 * Is this request's response one an AGENT reads (spec §1.2)?
	 *
	 * True when it is a served REST request, not cookie-authenticated, and
	 * either the gateway's tool execution (whoever authenticated it — the Aura
	 * gateway runs token-only) or a route outside SiteAgent's own namespaces
	 * reached by a logged-in user (an Application Password on `mcp/v1`, any
	 * agent on `wp/v2`). SiteAgent's system routes are called by the Aura
	 * server only and are never redacted; neither is a person nor the public.
	 *
	 * Routes compare lowercased: WordPress matches them case-insensitively (R7).
	 *
	 * @param mixed $request WP_REST_Request, or anything else (false).
	 * @return bool
	 */
	public static function is_audience( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}
		if ( ! Aura_Worker_Rules::serving_rest() || Aura_Worker_Rules::cookie_authenticated() ) {
			return false;
		}
		$route = strtolower( (string) $request->get_route() );
		if ( self::GATEWAY_EXECUTE_ROUTE === $route ) {
			return true;
		}
		if ( self::is_own_route( $route ) ) {
			return false;
		}
		return is_user_logged_in();
	}

	/**
	 * Is the route in one of SiteAgent's own namespaces? At a segment
	 * boundary — `aura/mcpx/...` is somebody else's (the same rule
	 * Aura_Worker_Call_Context::is_own_transport() applies).
	 *
	 * @param string $route Lowercased route.
	 * @return bool
	 */
	private static function is_own_route( $route ) {
		$path = ltrim( $route, '/' );
		foreach ( Aura_Worker_Call_Context::OWN_NAMESPACES as $ns ) {
			if ( $path === $ns || 0 === strpos( $path, $ns . '/' ) ) {
				return true;
			}
		}
		return false;
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/RedactAudienceTest.php`
Expected: PASS.

Run: `vendor/bin/phpunit --filter 'Rules'` — expected: green (the `is_agent_rest_request()` edit changes nothing).

- [ ] **Step 6: Run the whole suite and the linter**

Run: `composer test` and `composer lint` — expected: green / no errors.

- [ ] **Step 7: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-rules.php digitizer-site-worker/includes/class-aura-worker-redact.php tests/unit/RedactAudienceTest.php
git commit -m "feat(redact): the redaction audience — the gateway's tools/execute and logged-in non-cookie callers on foreign routes (#419)

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: the read seam, the counters, and the wiring

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-redact.php` — `init()`, `filter_echo()`, `record_redacted()`, `record_placeholder_refused()`
- Modify: `digitizer-site-worker/includes/class-aura-worker-rules.php` — `bump_counter()` after `record_warn()` (~line 118)
- Modify: `digitizer-site-worker/digitizer-site-worker.php:79` — require the class
- Modify: `digitizer-site-worker/includes/class-aura-worker.php:83` — `Aura_Worker_Redact::init()`
- Test: `tests/unit/RedactSeamTest.php`

**Interfaces:**
- Consumes: `redact()` (Task 1), `is_audience()` (Task 2).
- Produces:
  - `Aura_Worker_Redact::init(): void` — registers `rest_pre_echo_response` → `filter_echo` at `PHP_INT_MAX` (3 args), `aura_worker_redacted` → `record_redacted` (2 args), `aura_worker_placeholder_refused` → `record_placeholder_refused` (1 arg). Task 4 adds the `rest_request_before_callbacks` line.
  - `Aura_Worker_Redact::filter_echo( $result, $server = null, $request = null )`.
  - `Aura_Worker_Redact::record_redacted( $count = 0, $route = '', $now = null ): void`, `record_placeholder_refused( $route = '', $now = null ): void`.
  - `Aura_Worker_Rules::bump_counter( string $prefix, ?int $now = null ): void` — bumps only `BLOCKED_COUNTER`, `WARNED_COUNTER`, `Aura_Worker_Redact::REDACTED_COUNTER`, `Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER` (R13).

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/RedactSeamTest.php`:

```php
<?php
/**
 * The read seam (#419 v2, spec §1.1): `rest_pre_echo_response`, which core
 * applies only to a SERVED request, on its final data — after `_envelope`
 * and after `_embed`. Every HTTP method; system routes, people and the
 * public untouched; a response with no match returned as it was.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactSeamTest extends TestCase {

	private const N8N = 'https://n8n.example.com/webhook/abc';

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
		$GLOBALS['_logged_in']                    = true;
		Aura_Worker_Redact::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function echoed( $data, string $method, string $route ) {
		return apply_filters( 'rest_pre_echo_response', $data, null, new WP_REST_Request( $method, $route ) );
	}

	/** A wp/v2 page with Elementor's data exposed as meta. */
	private function page( string $webhook ): array {
		$tree = array(
			array(
				'id'       => 'frm1',
				'elType'   => 'widget',
				'settings' => array( 'form_name' => 'Contact', 'webhooks' => $webhook ),
			),
		);
		return array(
			'id'    => 7,
			'title' => array( 'rendered' => 'Contact' ),
			'meta'  => array( '_elementor_data' => wp_json_encode( $tree ) ),
		);
	}

	private function webhook_of( array $page ): string {
		return json_decode( $page['meta']['_elementor_data'], true )[0]['settings']['webhooks'];
	}

	private function redacted_count(): int {
		return Aura_Worker_Rules::count_24h( Aura_Worker_Redact::REDACTED_COUNTER );
	}

	// --- registration ------------------------------------------------------

	public function test_the_read_seam_is_rest_pre_echo_response_and_runs_last(): void {
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_pre_echo_response', array( 'Aura_Worker_Redact', 'filter_echo' ) ) );
	}

	public function test_nothing_is_hooked_on_a_seam_an_internal_dispatch_or_an_unembedded_body_passes(): void {
		// rest_post_dispatch runs before _envelope and _embed (serve_request()),
		// and rest_request_after_callbacks runs for every internal
		// rest_do_request(): a redactor on either would leak embedded
		// resources or redact internal reads (spec §1.1).
		foreach ( array( 'rest_post_dispatch', 'rest_request_after_callbacks', 'rest_pre_serve_request' ) as $tag ) {
			foreach ( $GLOBALS['_filters'][ $tag ] ?? array() as $entry ) {
				$cb = is_array( $entry ) && array_key_exists( 'callback', $entry ) ? $entry['callback'] : $entry;
				$this->assertFalse( is_array( $cb ) && 'Aura_Worker_Redact' === $cb[0], "Aura_Worker_Redact must not hook {$tag}" );
			}
		}
	}

	public function test_the_plugin_wires_the_redactor_in(): void {
		( new Aura_Worker() )->init();
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_pre_echo_response', array( 'Aura_Worker_Redact', 'filter_echo' ) ) );

		$main = (string) file_get_contents( SA_PLUGIN_DIR . '/digitizer-site-worker.php' );
		$this->assertStringContainsString( "require_once AURA_WORKER_DIR . 'includes/class-aura-worker-redact.php';", $main );
	}

	// --- who ---------------------------------------------------------------

	/** @return array<string,array{0:string,1:string,2:bool}> method, route, logged in */
	public static function redacted_callers(): array {
		return array(
			'gateway tools/execute (token-only)' => array( 'POST', '/aura/mcp/tools/execute', false ),
			'wp/v2 read'                         => array( 'GET', '/wp/v2/pages/7', true ),
			'mcp/v1 client'                      => array( 'POST', '/mcp/mcp-adapter-default-server', true ),
			'a write echo'                       => array( 'POST', '/wp/v2/pages/7', true ),
			'a delete echo'                      => array( 'DELETE', '/wp/v2/pages/7', true ),
		);
	}

	/** @dataProvider redacted_callers */
	public function test_an_agent_read_is_redacted( string $method, string $route, bool $logged_in ): void {
		$GLOBALS['_logged_in'] = $logged_in;
		$out = $this->echoed( $this->page( self::N8N ), $method, $route );
		$this->assertSame( 'aura-redacted:v1:field', $this->webhook_of( $out ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function system_routes(): array {
		return array(
			'status'    => array( 'GET', '/aura/v1/status' ),
			'rules'     => array( 'POST', '/aura/v2/rules' ),
			'snapshots' => array( 'GET', '/aura/v2/snapshots' ),
			'updates'   => array( 'GET', '/aura/v1/updates' ),
		);
	}

	/** @dataProvider system_routes */
	public function test_a_system_route_is_untouched( string $method, string $route ): void {
		$page = $this->page( self::N8N );
		$this->assertSame( $page, $this->echoed( $page, $method, $route ) );
	}

	public function test_a_snapshot_answer_on_a_system_route_keeps_its_payload(): void {
		$answer = array( 'found' => true, 'record' => array( 'id' => 's1' ), 'payload' => base64_encode( 'not serialized at all' ) );
		$this->assertSame( $answer, $this->echoed( $answer, 'GET', '/aura/v2/snapshots' ), 'never failed closed off the audience' );
	}

	public function test_a_person_in_wp_admin_is_untouched(): void {
		Aura_Worker_Rules::$cookie_auth_override = true;
		$page = $this->page( self::N8N );
		$this->assertSame( $page, $this->echoed( $page, 'GET', '/wp/v2/pages/7' ) );
	}

	public function test_the_anonymous_public_is_untouched(): void {
		$GLOBALS['_logged_in'] = false;
		$page = $this->page( self::N8N );
		$this->assertSame( $page, $this->echoed( $page, 'GET', '/wp/v2/pages/7' ) );
	}

	/** @return array<string,array{0:string}> */
	public static function lookalike_routes(): array {
		return array(
			'execute-foo' => array( '/aura/mcp/tools/execute-foo' ),
			'mcpx'        => array( '/aura/mcpx/tools/execute' ),
		);
	}

	/** @dataProvider lookalike_routes */
	public function test_a_lookalike_route_is_not_treated_as_tools_execute( string $route ): void {
		$GLOBALS['_logged_in'] = false; // what makes tools/execute special: no login needed
		$page = $this->page( self::N8N );
		$this->assertSame( $page, $this->echoed( $page, 'POST', $route ) );
	}

	// --- what core hands the seam -----------------------------------------

	public function test_an_embedded_resource_is_redacted(): void {
		// response_to_data() has already expanded `_embedded` when this runs.
		$body = array(
			'id'        => 9,
			'_links'    => array( 'up' => array( array( 'href' => 'https://example.com/wp-json/wp/v2/pages/7', 'embeddable' => true ) ) ),
			'_embedded' => array( 'up' => array( $this->page( 'https://hooks.zapier.com/hooks/catch/1/' ) ) ),
		);
		$out = $this->echoed( $body, 'GET', '/wp/v2/pages/9' );
		$this->assertSame( 'aura-redacted:v1:field', $this->webhook_of( $out['_embedded']['up'][0] ), 'a key match replaces the whole value, whatever the host' );
		$this->assertSame( $body['_links'], $out['_links'] );
	}

	public function test_an_envelope_response_is_redacted(): void {
		$body = array( 'body' => $this->page( self::N8N ), 'status' => 200, 'headers' => array( 'Allow' => 'GET' ) );
		$out  = $this->echoed( $body, 'GET', '/wp/v2/pages/7' );
		$this->assertSame( 'aura-redacted:v1:field', $this->webhook_of( $out['body'] ) );
		$this->assertSame( 200, $out['status'] );
	}

	public function test_a_response_with_no_match_is_the_same_value_and_counts_nothing(): void {
		$page = $this->page( '' );
		$this->assertSame( $page, $this->echoed( $page, 'GET', '/wp/v2/pages/7' ) );
		$this->assertSame( 0, $this->redacted_count() );
		$this->assertSame( array(), array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_redacted' === $a['tag'];
		} ) ) );
	}

	public function test_a_null_body_passes_through(): void {
		$this->assertNull( $this->echoed( null, 'DELETE', '/wp/v2/pages/7' ) );
	}

	// --- counters ------------------------------------------------------------

	public function test_one_redacted_response_bumps_the_counter_once_and_reports_its_replacements(): void {
		$body = array( 'a' => $this->page( self::N8N ), 'b' => 'https://hooks.slack.com/services/T/B/X' );
		$this->echoed( $body, 'GET', '/wp/v2/pages/7' );

		$this->assertSame( 1, $this->redacted_count(), 'one per response, however many replacements' );
		$fired = array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_redacted' === $a['tag'];
		} ) );
		$this->assertCount( 1, $fired );
		$this->assertSame( array( 2, '/wp/v2/pages/7' ), $fired[0]['args'] );

		$this->echoed( $body, 'GET', '/wp/v2/pages/7' );
		$this->assertSame( 2, $this->redacted_count() );
	}

	public function test_bump_counter_refuses_an_unknown_prefix(): void {
		Aura_Worker_Rules::bump_counter( 'someone_elses_h' );
		$this->assertSame( 0, Aura_Worker_Rules::count_24h( 'someone_elses_h' ) );
		Aura_Worker_Rules::bump_counter( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER );
		$this->assertSame( 1, Aura_Worker_Rules::count_24h( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER ) );
	}
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/unit/RedactSeamTest.php`
Expected: FAIL — `Call to undefined method Aura_Worker_Redact::init()`.

- [ ] **Step 3: Add `bump_counter()` to `Aura_Worker_Rules`**

In `class-aura-worker-rules.php`, after `record_warn()`:

```php
	/**
	 * bump(), for the other hourly counters this plugin keeps (2.18.0: read
	 * redaction, #419). Same storage, same sweep, same atomic increment —
	 * and still the one raw-SQL writer UninstallCoverageTest acknowledges.
	 * Closed to the known prefixes, all under `aura_worker_`, which
	 * uninstall.php sweeps.
	 *
	 * @param string   $prefix A known counter prefix.
	 * @param int|null $now    Unix time; injected for tests.
	 */
	public static function bump_counter( $prefix, $now = null ) {
		$known = array( self::BLOCKED_COUNTER, self::WARNED_COUNTER );
		if ( class_exists( 'Aura_Worker_Redact' ) ) {
			$known[] = Aura_Worker_Redact::REDACTED_COUNTER;
			$known[] = Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER;
		}
		if ( in_array( $prefix, $known, true ) ) {
			self::bump( $prefix, $now );
		}
	}
```

- [ ] **Step 4: Add the seam and the recorders to `Aura_Worker_Redact`**

In `class-aura-worker-redact.php`, after the constants (before `redact()`):

```php
	/**
	 * Hook the read seam and the counters. Called from Aura_Worker::init(),
	 * right after Aura_Worker_Rules::init().
	 */
	public static function init() {
		// LAST, so whatever any other plugin adds to the body is covered too.
		add_filter( 'rest_pre_echo_response', array( __CLASS__, 'filter_echo' ), PHP_INT_MAX, 3 );
		add_action( 'aura_worker_redacted', array( __CLASS__, 'record_redacted' ), 10, 2 );
		add_action( 'aura_worker_placeholder_refused', array( __CLASS__, 'record_placeholder_refused' ), 10, 1 );
	}

	/**
	 * `rest_pre_echo_response` — the served body, final (spec §1.1).
	 *
	 * @param mixed                $result  Response data about to be JSON-encoded.
	 * @param WP_REST_Server|null  $server  Server.
	 * @param WP_REST_Request|null $request The served request.
	 * @return mixed
	 */
	public static function filter_echo( $result, $server = null, $request = null ) {
		if ( null === $result || ! self::is_audience( $request ) ) {
			return $result;
		}
		$count    = 0;
		$redacted = self::redact( $result, $count );
		if ( 0 === $count ) {
			return $result;
		}
		/**
		 * A served response had values replaced before it left the site.
		 *
		 * @since 2.18.0
		 *
		 * @param int    $count How many values were replaced.
		 * @param string $route The route served.
		 */
		do_action( 'aura_worker_redacted', $count, (string) $request->get_route() );
		return $redacted;
	}

	/**
	 * One response redacted: bump the hourly counter (spec §4).
	 *
	 * @param int      $count Replacements (reported, not counted).
	 * @param string   $route Route.
	 * @param int|null $now   Unix time; injected for tests.
	 */
	public static function record_redacted( $count = 0, $route = '', $now = null ) {
		Aura_Worker_Rules::bump_counter( self::REDACTED_COUNTER, $now );
	}

	/**
	 * One write refused for a placeholder: bump the hourly counter (spec §4).
	 *
	 * @param string   $route Route.
	 * @param int|null $now   Unix time; injected for tests.
	 */
	public static function record_placeholder_refused( $route = '', $now = null ) {
		Aura_Worker_Rules::bump_counter( self::PLACEHOLDER_REFUSED_COUNTER, $now );
	}
```

- [ ] **Step 5: Wire the class into the plugin**

In `digitizer-site-worker/digitizer-site-worker.php`, directly after line 79 (`require_once AURA_WORKER_DIR . 'includes/class-aura-worker-rules.php';`):

```php
require_once AURA_WORKER_DIR . 'includes/class-aura-worker-redact.php';
```

In `digitizer-site-worker/includes/class-aura-worker.php`, directly after `Aura_Worker_Rules::init();`:

```php

		// Agent read redaction (2.18.0, #419): known secrets — webhook
		// endpoints first — never leave the site in a response an agent
		// reads, and a write carrying the placeholder is refused.
		Aura_Worker_Redact::init();
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/RedactSeamTest.php`
Expected: PASS.

- [ ] **Step 7: Run the whole suite and the linter**

Run: `composer test` — expected: green. Watch `UninstallCoverageTest` (the raw-write count for `class-aura-worker-rules.php` must still be 5 — `bump_counter()` adds none) and every test that calls `( new Aura_Worker() )->init()`.
Run: `composer lint` — expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-redact.php digitizer-site-worker/includes/class-aura-worker-rules.php digitizer-site-worker/digitizer-site-worker.php digitizer-site-worker/includes/class-aura-worker.php tests/unit/RedactSeamTest.php
git commit -m "feat(redact): redact agent responses on rest_pre_echo_response, with an hourly counter (#419)

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: the unredacted grant on its two request shapes

**Files:**
- Modify: `tests/bootstrap.php` — the `WP_REST_Request` stub (~line 1795) gains core's parameter sources and body; `sa_reset_state()` (~line 5125) clears the exemption memo
- Modify: `digitizer-site-worker/includes/class-aura-worker-redact.php` — `$exempt`, `before_callbacks()`, `verify_unredacted_grant()`, `grant_shape()`, `is_list()`, `take_exemption()`, `reset_for_tests()`; `init()` and `filter_echo()` change
- Test: `tests/unit/RedactGrantTest.php`

**Interfaces:**
- Consumes: `is_audience()` (Task 2), `filter_echo()` / `init()` (Task 3), `Aura_Worker_Grant::verify( $header, $tool, $params )` → `true|string|WP_Error`, `Aura_Worker_Grant::has_usable_key(): bool`.
- Produces:
  - `Aura_Worker_Redact::before_callbacks( $response, $handler = null, $request = null )` — registered on `rest_request_before_callbacks` at priority 6 (R5). Returns `$response` unchanged, or a `WP_Error`.
  - `Aura_Worker_Redact::grant_shape( $request ): ?array` — `array( 'tool' => string, 'params' => array )` for the two recognised shapes, else null.
  - `Aura_Worker_Redact::reset_for_tests(): void`.
  - Stub methods (tests only): `WP_REST_Request::set_query_params()/get_query_params()`, `set_body_params()/get_body_params()`, `set_url_params()/get_url_params()`, `set_body()/get_body()`, `get_json_params()` (the body decoded when `Content-Type` is JSON, else null — core's behaviour).

- [ ] **Step 1: Teach the request stub core's parameter sources**

In `tests/bootstrap.php`, inside `class WP_REST_Request`, add after `private string $method = 'GET';`:

```php
		private array $query_params = array();
		private array $body_params  = array();
		private array $url_params   = array();
		private string $body        = '';
```

and after `get_route()`:

```php
		/*
		 * Core keeps each parameter source apart ($_GET, the form body, the
		 * JSON body, the route's own matches) and get_params() merges them by
		 * precedence. The write guard (#419) walks each source separately, so
		 * the stub keeps them apart too. get_param() above is unchanged — the
		 * existing suite sets parameters through set_param().
		 */
		public function set_query_params( array $params ): void {
			$this->query_params = $params;
		}

		public function get_query_params(): array {
			return $this->query_params;
		}

		public function set_body_params( array $params ): void {
			$this->body_params = $params;
		}

		public function get_body_params(): array {
			return $this->body_params;
		}

		public function set_url_params( array $params ): void {
			$this->url_params = $params;
		}

		public function get_url_params(): array {
			return $this->url_params;
		}

		public function set_body( string $body ): void {
			$this->body = $body;
		}

		public function get_body(): string {
			return $this->body;
		}

		/**
		 * Core's get_json_params(): the raw body decoded (associative) when the
		 * request's Content-Type is JSON; null otherwise, and null for a body
		 * that does not decode — core records a parse error and leaves the JSON
		 * source empty.
		 *
		 * @return mixed
		 */
		public function get_json_params() {
			$type = (string) $this->get_header( 'content-type' );
			if ( '' === $this->body || false === stripos( $type, 'json' ) ) {
				return null;
			}
			$params = json_decode( $this->body, true );
			return ( null === $params && JSON_ERROR_NONE !== json_last_error() ) ? null : $params;
		}
```

In `sa_reset_state()`, directly after the `Aura_Worker_Call_Context::reset()` block:

```php
	if ( class_exists( 'Aura_Worker_Redact' ) ) {
		Aura_Worker_Redact::reset_for_tests(); // the unredacted-grant exemption memo is a static (#419)
	}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/unit/RedactGrantTest.php`:

```php
<?php
/**
 * Aura's own page snapshot — the one unredacted read (#419 v2, spec §1.3).
 * A signed `X-Aura-Unredacted-Grant`, honoured on exactly two request
 * shapes, exempts exactly that response; anything wrong with it on a
 * recognised shape is a loud 403, never a silent redaction.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactGrantTest extends TestCase {

	private const N8N    = 'https://n8n.example.com/webhook/abc';
	private const SERVER = 'elementor-mcp-server';
	private const EXPORT = 'unredacted-read:mcp/elementor-mcp-server#export-page';

	protected function setUp(): void {
		sa_reset_state();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		sa_install_gateway_key();
		sa_token_hash();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
		$GLOBALS['_logged_in']                    = true; // Aura's Application Password
		$GLOBALS['_rest_app_password']            = 'uuid-aura';
		Aura_Worker_Redact::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function grant( string $tool, array $params, array $over = array() ): string {
		return sa_sign_ruleset(
			array_merge(
				array(
					'v'             => 1,
					'tool'          => $tool,
					'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( $params ) ),
					'site'          => sa_token_hash(),
					'nonce'         => bin2hex( random_bytes( 16 ) ),
					'iat'           => time(),
					'exp'           => time() + 300,
				),
				$over
			)
		);
	}

	/** A JSON-RPC request to an MCP adapter server. */
	private function rpc( string $route, $body, string $method = 'POST' ): WP_REST_Request {
		$req = new WP_REST_Request( $method, $route );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
		return $req;
	}

	private function export_call( array $arguments = array( 'post_id' => 7 ), string $server = self::SERVER ): WP_REST_Request {
		return $this->rpc(
			'/mcp/' . $server,
			array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page', 'arguments' => $arguments ) )
		);
	}

	private function execute_call( string $tool, array $params ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/aura/mcp/tools/execute' );
		$req->set_param( 'tool', $tool );
		$req->set_param( 'params', $params );
		return $req;
	}

	/** What export-page answers through the adapter: a text carrier plus structuredContent. */
	private function export_body(): array {
		$export = array(
			'json' => array(
				array( 'id' => 'frm1', 'elType' => 'widget', 'settings' => array( 'webhooks' => self::N8N ) ),
			),
		);
		return array(
			'jsonrpc' => '2.0',
			'id'      => 5,
			'result'  => array(
				'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $export ) ) ),
				'structuredContent' => $export,
			),
		);
	}

	private function before( WP_REST_Request $req ) {
		return apply_filters( 'rest_request_before_callbacks', null, array(), $req );
	}

	private function echoed( WP_REST_Request $req, $body ) {
		return apply_filters( 'rest_pre_echo_response', $body, null, $req );
	}

	private function assertRefused( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result, 'the tool must not run' );
		$this->assertSame( 'aura_unredacted_grant_invalid', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_the_check_is_registered_after_the_rules_guard(): void {
		$this->assertSame( 6, has_filter( 'rest_request_before_callbacks', array( 'Aura_Worker_Redact', 'before_callbacks' ) ) );
	}

	// --- the MCP JSON-RPC row --------------------------------------------

	public function test_a_valid_export_page_grant_exempts_that_response_and_only_that_one(): void {
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( self::EXPORT, array( 'post_id' => 7 ) ) );
		$body = $this->export_body();

		$this->assertNull( $this->before( $req ), 'verified: the tool runs' );
		$this->assertSame( $body, $this->echoed( $req, $body ), 'the capture is served unredacted' );

		$again = $this->export_call();
		$this->assertNull( $this->before( $again ) );
		$this->assertNotSame( $body, $this->echoed( $again, $body ), 'a second request in the same process is redacted' );

		$this->assertNotSame( $body, $this->echoed( $req, $body ), 'the exemption is spent with its response' );
	}

	public function test_an_absent_arguments_binds_as_an_empty_object(): void {
		$req = $this->rpc( '/mcp/' . self::SERVER, array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page' ) ) );
		$this->assertSame( array( 'tool' => self::EXPORT, 'params' => array() ), Aura_Worker_Redact::grant_shape( $req ) );
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( self::EXPORT, array() ) );
		$this->assertNull( $this->before( $req ) );
	}

	/** @return array<string,array{0:string,1:array,2:array}> grant tool, grant params, grant overrides */
	public static function wrong_grants(): array {
		return array(
			'other arguments' => array( self::EXPORT, array( 'post_id' => 8 ), array() ),
			'other tool'      => array( 'unredacted-read:mcp/elementor-mcp-server#get-element-settings', array( 'post_id' => 7 ), array() ),
			'other server'    => array( 'unredacted-read:mcp/other-server#export-page', array( 'post_id' => 7 ), array() ),
			'gateway row'     => array( 'unredacted-read:aura/mcp#export-page', array( 'post_id' => 7 ), array() ),
			'no prefix'       => array( 'export-page', array( 'post_id' => 7 ), array() ),
			'other site'      => array( self::EXPORT, array( 'post_id' => 7 ), array( 'site' => hash( 'sha256', 'another-site' ) ) ),
			'expired'         => array( self::EXPORT, array( 'post_id' => 7 ), array( 'iat' => time() - 1000, 'exp' => time() - 700 ) ),
		);
	}

	/** @dataProvider wrong_grants */
	public function test_a_grant_that_does_not_bind_this_call_is_refused( string $tool, array $params, array $over ): void {
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( $tool, $params, $over ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_grant_signed_by_another_key_is_refused(): void {
		$req = $this->export_call();
		$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$payload = array(
			'v' => 1, 'tool' => self::EXPORT, 'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( array( 'post_id' => 7 ) ) ),
			'site' => sa_token_hash(), 'nonce' => bin2hex( random_bytes( 16 ) ), 'iat' => time(), 'exp' => time() + 300,
		);
		$req->set_header( 'X-Aura-Unredacted-Grant', sa_sign_ruleset( $payload, $other ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_replayed_grant_is_refused(): void {
		$grant = $this->grant( self::EXPORT, array( 'post_id' => 7 ) );
		$first = $this->export_call();
		$first->set_header( 'X-Aura-Unredacted-Grant', $grant );
		$this->assertNull( $this->before( $first ) );

		$second = $this->export_call();
		$second->set_header( 'X-Aura-Unredacted-Grant', $grant );
		$this->assertRefused( $this->before( $second ) );
	}

	/** @return array<string,array{0:string,1:mixed,2:string}> route, body, method */
	public static function unrecognised_shapes(): array {
		$call = array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page', 'arguments' => array( 'post_id' => 7 ) ) );
		return array(
			'a JSON-RPC batch'      => array( '/mcp/elementor-mcp-server', array( $call ), 'POST' ),
			'tools/list'            => array( '/mcp/elementor-mcp-server', array_merge( $call, array( 'method' => 'tools/list' ) ), 'POST' ),
			'initialize'            => array( '/mcp/elementor-mcp-server', array_merge( $call, array( 'method' => 'initialize' ) ), 'POST' ),
			'two segments'          => array( '/mcp/a/b', $call, 'POST' ),
			'a wp/v2 route'         => array( '/wp/v2/pages/7', $call, 'POST' ),
			'not POST'              => array( '/mcp/elementor-mcp-server', $call, 'DELETE' ),
			'arguments not object'  => array( '/mcp/elementor-mcp-server', array_merge( $call, array( 'params' => array( 'name' => 'export-page', 'arguments' => array( 7 ) ) ) ), 'POST' ),
			'no tool name'          => array( '/mcp/elementor-mcp-server', array_merge( $call, array( 'params' => array( 'arguments' => array( 'post_id' => 7 ) ) ) ), 'POST' ),
		);
	}

	/** @dataProvider unrecognised_shapes */
	public function test_on_any_other_shape_the_header_is_ignored_and_the_response_redacted( string $route, $body, string $method ): void {
		$grant = $this->grant( self::EXPORT, array( 'post_id' => 7 ) );
		$req   = $this->rpc( $route, $body, $method );
		$req->set_header( 'X-Aura-Unredacted-Grant', $grant );

		$this->assertNull( $this->before( $req ), 'ignored, not refused' );
		$this->assertNotSame( $this->export_body(), $this->echoed( $req, $this->export_body() ) );

		// Ignored means not spent: the same grant still works where it belongs.
		$real = $this->export_call();
		$real->set_header( 'X-Aura-Unredacted-Grant', $grant );
		$this->assertNull( $this->before( $real ) );
		$this->assertSame( $this->export_body(), $this->echoed( $real, $this->export_body() ) );
	}

	public function test_a_site_without_a_usable_gateway_key_ignores_the_header(): void {
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( 'too short' ); // configured, unusable
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', 'anything.at-all' );

		$this->assertNull( $this->before( $req ), 'the tool runs' );
		$this->assertNotSame( $this->export_body(), $this->echoed( $req, $this->export_body() ), 'and its answer is redacted' );
	}

	public function test_an_unbound_site_answers_its_own_refusal(): void {
		sa_set_marker();
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( self::EXPORT, array( 'post_id' => 7 ) ) );
		$res = $this->before( $req );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_site_unbound', $res->get_error_code() );
	}

	public function test_a_cookie_session_never_spends_a_grant(): void {
		Aura_Worker_Rules::$cookie_auth_override = true;
		$GLOBALS['_rest_app_password']           = null;
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', 'not-even-a-grant' );
		$this->assertNull( $this->before( $req ), 'not the audience: nothing to exempt, nothing refused' );
	}

	public function test_an_earlier_refusal_passes_through_untouched(): void {
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', 'garbage' );
		$earlier = new WP_Error( 'rest_invalid_param', 'bad', array( 'status' => 400 ) );
		$this->assertSame( $earlier, apply_filters( 'rest_request_before_callbacks', $earlier, array(), $req ) );
	}

	// --- the gateway row --------------------------------------------------

	public function test_a_valid_gateway_grant_exempts_that_tools_execute_response(): void {
		$req = $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) );
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( 'unredacted-read:aura/mcp#snapshot_get', array( 'id' => 'snap_1' ) ) );
		$body = array( 'success' => true, 'result' => $this->export_body() );

		$this->assertNull( $this->before( $req ) );
		$this->assertSame( $body, $this->echoed( $req, $body ) );
	}

	public function test_the_gateway_row_binds_the_params_the_executor_runs(): void {
		$req = $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) );
		$this->assertSame(
			array( 'tool' => 'unredacted-read:aura/mcp#snapshot_get', 'params' => array( 'id' => 'snap_1' ) ),
			Aura_Worker_Redact::grant_shape( $req )
		);
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( 'unredacted-read:aura/mcp#snapshot_get', array( 'id' => 'snap_2' ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_the_gateway_row_does_not_need_a_logged_in_user(): void {
		$GLOBALS['_logged_in']         = false; // token-only, as the gateway runs
		$GLOBALS['_rest_app_password'] = null;
		$req = $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) );
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( 'unredacted-read:mcp/elementor-mcp-server#snapshot_get', array( 'id' => 'snap_1' ) ) );
		$this->assertRefused( $this->before( $req ) );
	}
}
```

- [ ] **Step 3: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/unit/RedactGrantTest.php`
Expected: FAIL — `has_filter()` answers false for `before_callbacks`, and `Call to undefined method Aura_Worker_Redact::grant_shape()`.

- [ ] **Step 4: Implement the grant**

In `class-aura-worker-redact.php`, after the constants:

```php
	/**
	 * Requests whose unredacted grant verified, by spl_object_id. The object
	 * itself is held, so its id cannot be reused by another object while the
	 * entry stands; filter_echo() drops it with its response.
	 *
	 * @var array<int,object>
	 */
	private static $exempt = array();
```

In `init()`, add as its first statement:

```php
		// After Aura_Worker_Rules::guard_core_any() (5): a rule block or an
		// unbind refusal wins, and no grant nonce is spent on it (R5).
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'before_callbacks' ), 6, 3 );
```

In `filter_echo()`, add as its first statement:

```php
		if ( is_object( $request ) && self::take_exemption( $request ) ) {
			return $result; // Aura's own snapshot capture (spec §1.3) — this response only
		}
```

Then, after `filter_echo()`:

```php
	/**
	 * `rest_request_before_callbacks` — before the permission check and the
	 * handler. Task 5 puts the placeholder guard in front of the grant.
	 *
	 * @param mixed                $response Earlier short-circuit, or core's parameter error.
	 * @param array|null           $handler  Route handler.
	 * @param WP_REST_Request|null $request  Request.
	 * @return mixed
	 */
	public static function before_callbacks( $response, $handler = null, $request = null ) {
		if ( null !== $response || ! self::is_audience( $request ) ) {
			return $response;
		}
		$grant = self::verify_unredacted_grant( $request );
		return null === $grant ? $response : $grant;
	}

	/**
	 * Verify `X-Aura-Unredacted-Grant` on a recognised shape and, when it
	 * holds, exempt THIS request's response (spec §1.3).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|null A refusal, or null (verified, absent or ignored).
	 */
	private static function verify_unredacted_grant( $request ) {
		$header = trim( (string) $request->get_header( self::GRANT_HEADER ) );
		if ( '' === $header ) {
			return null;
		}
		$shape = self::grant_shape( $request );
		if ( null === $shape || ! Aura_Worker_Grant::has_usable_key() ) {
			// Not a shape the site honours, or nothing to verify with: the
			// header means nothing here and the response is redacted as usual.
			return null;
		}
		$verdict = Aura_Worker_Grant::verify( $header, $shape['tool'], $shape['params'] );
		if ( is_wp_error( $verdict ) ) {
			return $verdict; // an unbound site's own refusal, 403 aura_site_unbound (R6)
		}
		if ( true !== $verdict ) {
			// Loud, never a silent redaction: Aura must learn that its key or
			// its binding is wrong on this site (spec §1.3).
			return new WP_Error(
				'aura_unredacted_grant_invalid',
				/* translators: %s: why the grant did not verify. */
				sprintf( __( 'The X-Aura-Unredacted-Grant header did not verify for this request: %s.', 'digitizer-site-worker' ), $verdict ),
				array( 'status' => 403 )
			);
		}
		self::$exempt[ spl_object_id( $request ) ] = $request;
		return null;
	}

	/**
	 * The two request shapes a grant is honoured on, with the tool and
	 * params it must bind — derived from the request itself (spec §1.3).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{tool:string,params:array}|null
	 */
	public static function grant_shape( $request ) {
		$route = strtolower( (string) $request->get_route() );
		if ( self::GATEWAY_EXECUTE_ROUTE === $route ) {
			// Exactly what Aura_Worker_MCP::execute_tool() runs: `tool`, and
			// `params` with a non-array read as none.
			$params = $request->get_param( 'params' );
			return array(
				'tool'   => self::GRANT_TOOL_PREFIX . 'aura/mcp#' . (string) $request->get_param( 'tool' ),
				'params' => is_array( $params ) ? $params : array(),
			);
		}
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) || 1 !== preg_match( self::MCP_ROUTE, $route, $m ) ) {
			return null;
		}
		// The MCP adapter reads its message from the JSON body.
		$body = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		if ( ! is_array( $body ) || array() === $body || self::is_list( $body ) ) {
			return null; // one JSON object only — a batch is not honoured
		}
		if ( ! isset( $body['method'], $body['params'] ) || 'tools/call' !== $body['method'] || ! is_array( $body['params'] ) ) {
			return null;
		}
		$call = $body['params'];
		if ( ! isset( $call['name'] ) || ! is_string( $call['name'] ) || '' === $call['name'] ) {
			return null;
		}
		$args = array_key_exists( 'arguments', $call ) && null !== $call['arguments'] ? $call['arguments'] : array();
		if ( ! is_array( $args ) || self::is_list( $args ) ) {
			return null; // `arguments` is an object (R15)
		}
		return array(
			'tool'   => self::GRANT_TOOL_PREFIX . 'mcp/' . $m[1] . '#' . $call['name'],
			'params' => $args,
		);
	}

	/**
	 * A non-empty JSON array (as opposed to an object).
	 *
	 * @param array $value Decoded JSON.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		return array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Was this very request exempted? Answers once: the entry goes with it.
	 *
	 * @param object $request Request.
	 * @return bool
	 */
	private static function take_exemption( $request ) {
		$id = spl_object_id( $request );
		if ( isset( self::$exempt[ $id ] ) && self::$exempt[ $id ] === $request ) {
			unset( self::$exempt[ $id ] );
			return true;
		}
		return false;
	}

	/** Test seam: forget every exemption. */
	public static function reset_for_tests() {
		self::$exempt = array();
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/RedactGrantTest.php tests/unit/RedactSeamTest.php`
Expected: PASS.

- [ ] **Step 6: Run the whole suite and the linter**

Run: `composer test` — expected: green (the stub additions change no existing behaviour: nothing called the new methods before).
Run: `composer lint`.

- [ ] **Step 7: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-redact.php tests/bootstrap.php tests/unit/RedactGrantTest.php
git commit -m "feat(redact): X-Aura-Unredacted-Grant exempts Aura's own snapshot capture, on two request shapes only (#419)

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: the placeholder write guard

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-redact.php` — `before_callbacks()` changes; `refuse_placeholder_write()`, `holds_placeholder()`, `entry_holds_placeholder()`, `container_holds_placeholder()`, `carrier_holds_placeholder()`
- Test: `tests/unit/RedactWriteGuardTest.php`

**Interfaces:**
- Consumes: `is_audience()` (Task 2), `record_placeholder_refused()` (Task 3), `before_callbacks()`/`verify_unredacted_grant()` (Task 4), `is_snapshot_answer()`/`is_text_block()` (Task 1), the stub's parameter sources (Task 4).
- Produces: `Aura_Worker_Redact::holds_placeholder( $value, int $depth = 0 ): bool`; `before_callbacks()` answers `WP_Error( 'aura_redacted_placeholder', …, array( 'status' => 409 ) )` for an audience write carrying the placeholder, before the grant is looked at.

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/RedactWriteGuardTest.php`:

```php
<?php
/**
 * The write guard (#419 v2, spec §3). The placeholder is one-way: an agent
 * write that carries it would overwrite a real webhook with a dead string,
 * so it is refused (409) before anything runs. Not a security boundary —
 * a guard against the accident.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactWriteGuardTest extends TestCase {

	private const MARK = 'aura-redacted:v1:make';

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
		$GLOBALS['_logged_in']                    = true;
		$GLOBALS['_rest_app_password']            = 'uuid-agent';
		Aura_Worker_Redact::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function before( WP_REST_Request $req ) {
		return apply_filters( 'rest_request_before_callbacks', null, array(), $req );
	}

	private function json_request( string $method, string $route, string $raw_body ): WP_REST_Request {
		$req = new WP_REST_Request( $method, $route );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( $raw_body );
		return $req;
	}

	private function assertRefused( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result, 'refused before any callback runs — nothing is written' );
		$this->assertSame( 'aura_redacted_placeholder', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'Omit that field so the stored value is kept', $result->get_error_message() );
	}

	private function refused_count(): int {
		return Aura_Worker_Rules::count_24h( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER );
	}

	/** @return array<string,array{0:string}> */
	public static function sources(): array {
		return array(
			'query' => array( 'query' ),
			'body'  => array( 'body' ),
			'json'  => array( 'json' ),
			'url'   => array( 'url' ),
		);
	}

	/** @dataProvider sources */
	public function test_a_placeholder_in_any_parameter_source_is_refused( string $source ): void {
		$req    = new WP_REST_Request( 'POST', '/wp/v2/pages/7' );
		$params = array( 'title' => 'x', 'nested' => array( 'hook' => 'before ' . self::MARK ) );
		switch ( $source ) {
			case 'query':
				$req->set_query_params( $params );
				break;
			case 'body':
				$req->set_body_params( $params );
				break;
			case 'json':
				$req->set_header( 'Content-Type', 'application/json' );
				$req->set_body( (string) wp_json_encode( $params ) );
				break;
			case 'url':
				$req->set_url_params( array( 'id' => self::MARK ) );
				break;
		}

		$this->assertRefused( $this->before( $req ) );
		$this->assertSame( 1, $this->refused_count() );
		$fired = array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_placeholder_refused' === $a['tag'];
		} ) );
		$this->assertSame( array( '/wp/v2/pages/7' ), $fired[0]['args'] );
	}

	public function test_each_source_is_walked_on_its_own_not_through_the_merged_params(): void {
		// get_param() would answer the query's clean value; the body's copy of
		// the same key carries the placeholder.
		$req = new WP_REST_Request( 'POST', '/wp/v2/pages/7' );
		$req->set_param( 'title', 'clean' );
		$req->set_query_params( array( 'title' => 'clean' ) );
		$req->set_body_params( array( 'title' => self::MARK ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_placeholder_inside_elementor_data_under_meta_is_refused(): void {
		$tree = wp_json_encode( array( array( 'id' => 'f', 'settings' => array( 'webhooks' => 'aura-redacted:v1:field' ) ) ) );
		$req  = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'meta' => array( '_elementor_data' => $tree ) ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_json_escaped_placeholder_in_the_body_is_refused(): void {
		$req = $this->json_request( 'POST', '/wp/v2/pages/7', '{"title":"aura\\u002dredacted:v1:make"}' );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_json_escaped_placeholder_inside_elementor_data_is_refused(): void {
		// The carrier's raw text never contains "aura-redacted:"; only its decode does.
		$carrier = '[{"settings":{"webhooks":"aura\\u002dredacted:v1:field"}}]';
		$this->assertStringNotContainsString( 'aura-redacted:', $carrier );
		$req = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'meta' => array( '_elementor_data' => $carrier ) ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_an_import_template_of_an_exported_tree_is_refused(): void {
		$call = array(
			'jsonrpc' => '2.0',
			'id'      => 4,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'import-template',
				'arguments' => array(
					'post_id'  => 7,
					'template' => array( 'content' => array( array( 'id' => 'f', 'elType' => 'widget', 'settings' => array( 'webhooks' => 'aura-redacted:v1:field' ) ) ) ),
				),
			),
		);
		$req = $this->json_request( 'POST', '/mcp/elementor-mcp-server', (string) wp_json_encode( $call ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_placeholder_in_a_text_block_carrier_is_refused(): void {
		$body = array( 'content' => array( array( 'type' => 'text', 'text' => '{"webhooks":"aura\\u002dredacted:v1:field"}' ) ) );
		$req  = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( $body ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_snapshot_shaped_payload_is_checked_without_being_unserialized(): void {
		// Ruling R4: agent bytes are scanned, never unserialized.
		$payload = base64_encode( serialize( array( 7 => array( 'meta' => array( '_elementor_data' => array( 'value' => '[{"settings":{"webhooks":"aura-redacted:v1:field"}}]' ) ) ) ) ) );
		$req     = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'found' => true, 'record' => null, 'payload' => $payload ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_the_gateway_is_guarded_too(): void {
		$GLOBALS['_logged_in']         = false; // token-only
		$GLOBALS['_rest_app_password'] = null;
		$req = new WP_REST_Request( 'POST', '/aura/mcp/tools/execute' );
		$req->set_body_params( array( 'tool' => 'set_seo_meta', 'params' => array( 'title' => self::MARK ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_person_may_write_the_literal_string(): void {
		Aura_Worker_Rules::$cookie_auth_override = true;
		$GLOBALS['_rest_app_password']           = null;
		$req = new WP_REST_Request( 'POST', '/wp/v2/pages/7' );
		$req->set_body_params( array( 'content' => 'How to spot aura-redacted:v1:make in an export' ) );
		$this->assertNull( $this->before( $req ) );
		$this->assertSame( 0, $this->refused_count() );
	}

	/** @return array<string,array{0:string}> */
	public static function safe_methods(): array {
		return array( 'GET' => array( 'GET' ), 'HEAD' => array( 'HEAD' ), 'OPTIONS' => array( 'OPTIONS' ) );
	}

	/** @dataProvider safe_methods */
	public function test_a_read_is_never_checked( string $method ): void {
		$req = new WP_REST_Request( $method, '/wp/v2/pages' );
		$req->set_query_params( array( 'search' => self::MARK ) );
		$this->assertNull( $this->before( $req ) );
	}

	public function test_a_system_route_is_never_checked(): void {
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_body_params( array( 'note' => self::MARK ) );
		$this->assertNull( $this->before( $req ) );
	}

	public function test_an_update_widget_that_omits_webhooks_passes(): void {
		// The fork shallow-merges `settings` into the stored element, so
		// leaving `webhooks` out keeps the stored URL (spec: what changed from v1).
		$call = array(
			'jsonrpc' => '2.0',
			'id'      => 6,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'update-widget',
				'arguments' => array( 'post_id' => 7, 'element_id' => 'frm1', 'settings' => array( 'form_name' => 'Contact us' ) ),
			),
		);
		$req = $this->json_request( 'POST', '/mcp/elementor-mcp-server', (string) wp_json_encode( $call ) );
		$this->assertNull( $this->before( $req ) );
		$this->assertSame( 0, $this->refused_count() );
	}

	public function test_a_write_that_merely_mentions_redaction_passes(): void {
		$req = new WP_REST_Request( 'PATCH', '/wp/v2/pages/7' );
		$req->set_body_params( array( 'content' => 'aura redacted: v1 is our naming scheme' ) );
		$this->assertNull( $this->before( $req ) );
	}

	public function test_a_refused_write_spends_no_unredacted_grant(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		sa_install_gateway_key();
		$tool  = 'unredacted-read:mcp/elementor-mcp-server#export-page';
		$args  = array( 'post_id' => 7, 'note' => self::MARK );
		$grant = sa_sign_ruleset(
			array(
				'v'             => 1,
				'tool'          => $tool,
				'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( $args ) ),
				'site'          => sa_token_hash(),
				'nonce'         => bin2hex( random_bytes( 16 ) ),
				'iat'           => time(),
				'exp'           => time() + 300,
			)
		);
		$call = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page', 'arguments' => $args ) );
		$req  = $this->json_request( 'POST', '/mcp/elementor-mcp-server', (string) wp_json_encode( $call ) );
		$req->set_header( 'X-Aura-Unredacted-Grant', $grant );

		$this->assertRefused( $this->before( $req ) );
		$this->assertSame( true, Aura_Worker_Grant::verify( $grant, $tool, $args ), 'the nonce is still unspent' );
	}

	public function test_a_placeholder_used_as_a_key_is_refused(): void {
		$req = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'meta' => array( 'hooks' => array( 'aura-redacted:v1:zapier' => true ) ) ) ) );
		$this->assertRefused( $this->before( $req ) );
		$this->assertTrue( Aura_Worker_Redact::holds_placeholder( (object) array( 'aura-redacted:v1:make#2' => 1 ) ) );
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( array( 'webhooks' => 1, 0 => 'x' ) ) );
	}

	public function test_holds_placeholder_is_a_pure_walk(): void {
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( array( 'a' => array( 1, true, null, 2.5 ) ) ) );
		$this->assertTrue( Aura_Worker_Redact::holds_placeholder( array( 'a' => array( 'b' => (object) array( 'c' => 'AURA-REDACTED:v1:zapier' ) ) ) ) );
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( '{"x":"aura\\u002dredacted:"}', 0 ), 'a plain string is not a carrier: its raw text is what counts' );
	}
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/unit/RedactWriteGuardTest.php`
Expected: FAIL — writes carrying the placeholder come back `null` (not refused), and `Call to undefined method Aura_Worker_Redact::holds_placeholder()`.

- [ ] **Step 3: Implement the guard**

In `class-aura-worker-redact.php`, replace the body of `before_callbacks()` (its signature line through its closing brace; in the docblock, replace "Task 5 puts the placeholder guard in front of the grant." with "The placeholder guard runs first, then the grant.") with:

```php
	public static function before_callbacks( $response, $handler = null, $request = null ) {
		if ( null !== $response || ! self::is_audience( $request ) ) {
			return $response;
		}
		$refused = self::refuse_placeholder_write( $request );
		if ( null !== $refused ) {
			return $refused; // before the grant: a refused write spends no nonce (R5)
		}
		$grant = self::verify_unredacted_grant( $request );
		return null === $grant ? $response : $grant;
	}
```

Then add after `before_callbacks()`:

```php
	/**
	 * The write guard (spec §3): an agent write carrying the placeholder is
	 * refused — the site never swaps it back, so it could only overwrite a
	 * real value with a dead string. Each parameter source on its own:
	 * get_params()' precedence can hide one source's value behind a
	 * same-named key in another.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|null
	 */
	private static function refuse_placeholder_write( $request ) {
		$method = strtoupper( (string) $request->get_method() );
		if ( in_array( $method, Aura_Worker_Rules::SAFE_METHODS, true ) ) {
			return null;
		}
		foreach ( array( 'get_query_params', 'get_body_params', 'get_json_params', 'get_url_params' ) as $source ) {
			if ( ! method_exists( $request, $source ) || ! self::holds_placeholder( $request->$source() ) ) {
				continue;
			}
			/**
			 * An agent write was refused for carrying a redaction placeholder.
			 *
			 * @since 2.18.0
			 *
			 * @param string $route The route refused.
			 */
			do_action( 'aura_worker_placeholder_refused', (string) $request->get_route() );
			return new WP_Error(
				'aura_redacted_placeholder',
				__( 'This request contains a redacted value placeholder (aura-redacted:…). The site never restores it. Omit that field so the stored value is kept, and re-read the page if you need its structure.', 'digitizer-site-worker' ),
				array( 'status' => 409 )
			);
		}
		return null;
	}

	/**
	 * Does this (already decoded) value hold the placeholder anywhere? Walks
	 * arrays and objects, and decodes the same string carriers as the read
	 * side with the same depth bound — so a JSON escape inside a carrier is
	 * caught. A carrier-3 payload is scanned as bytes, never unserialized (R4).
	 *
	 * @param mixed $value Parameter value.
	 * @param int   $depth JSON decodes made so far.
	 * @return bool
	 */
	public static function holds_placeholder( $value, $depth = 0 ) {
		if ( is_string( $value ) ) {
			return false !== stripos( $value, self::PLACEHOLDER_MARK );
		}
		if ( $value instanceof stdClass ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( self::is_snapshot_answer( $value ) ) {
			$bytes = base64_decode( $value['payload'], true );
			if ( is_string( $bytes ) && false !== stripos( $bytes, self::PLACEHOLDER_MARK ) ) {
				return true;
			}
		}
		foreach ( $value as $key => $item ) {
			if ( self::entry_holds_placeholder( $key, $item, $depth ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int|string $key   Key.
	 * @param mixed      $item  Value.
	 * @param int        $depth JSON decodes so far.
	 * @return bool
	 */
	private static function entry_holds_placeholder( $key, $item, $depth ) {
		if ( is_string( $key ) && false !== stripos( $key, self::PLACEHOLDER_MARK ) ) {
			return true; // a placeholder used as a key is written back too (Codex r3 P1)
		}
		if ( is_string( $key ) && in_array( $key, self::JSON_META_KEYS, true ) ) {
			return self::container_holds_placeholder( $item, 'value', $depth );
		}
		if ( 'content' === $key && is_array( $item ) ) {
			foreach ( $item as $i => $block ) {
				$found = self::is_text_block( $block )
					? self::container_holds_placeholder( $block, 'text', $depth )
					: self::entry_holds_placeholder( $i, $block, $depth );
				if ( $found ) {
					return true;
				}
			}
			return false;
		}
		return self::holds_placeholder( $item, $depth );
	}

	/**
	 * A carrier string itself, or an array/stdClass whose `$carrier` field is one.
	 *
	 * @param mixed  $container Value under a carrier key.
	 * @param string $carrier   The carrier field's name.
	 * @param int    $depth     JSON decodes so far.
	 * @return bool
	 */
	private static function container_holds_placeholder( $container, $carrier, $depth ) {
		if ( is_string( $container ) ) {
			return self::carrier_holds_placeholder( $container, $depth );
		}
		$fields = $container instanceof stdClass ? get_object_vars( $container ) : $container;
		if ( ! is_array( $fields ) ) {
			return false;
		}
		foreach ( $fields as $key => $value ) {
			$found = ( $carrier === $key && is_string( $value ) )
				? self::carrier_holds_placeholder( $value, $depth )
				: self::entry_holds_placeholder( $key, $value, $depth );
			if ( $found ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $text  A JSON-carrying string.
	 * @param int    $depth JSON decodes so far.
	 * @return bool
	 */
	private static function carrier_holds_placeholder( $text, $depth ) {
		if ( false !== stripos( $text, self::PLACEHOLDER_MARK ) ) {
			return true;
		}
		if ( $depth >= self::MAX_JSON_DECODES ) {
			return false;
		}
		$decoded = json_decode( $text );
		return ( is_array( $decoded ) || is_object( $decoded ) ) && self::holds_placeholder( $decoded, $depth + 1 );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/RedactWriteGuardTest.php tests/unit/RedactGrantTest.php`
Expected: PASS (the grant tests still pass: their bodies carry no placeholder).

- [ ] **Step 5: Run the whole suite and the linter**

Run: `composer test` and `composer lint` — expected: green / no errors. Pay attention to `RulesCoreRestTest`, `RulesRestCoverageTest` and `UnbindCoreRestTest`: none registers the redactor, so none can change; if one does, it is calling `Aura_Worker::init()` and sending a placeholder — read the test before changing anything.

- [ ] **Step 6: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-redact.php tests/unit/RedactWriteGuardTest.php
git commit -m "feat(redact): refuse an agent write that carries a redaction placeholder (409 aura_redacted_placeholder) (#419)

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: `audit_rules` and `/status`

**Files:**
- Modify: `digitizer-site-worker/includes/tools/class-tool-audit-rules.php:36` (the `enforcement` return description) and `:62-67` (the `enforcement` block)
- Modify: `digitizer-site-worker/includes/class-aura-worker-redact.php` — `status_fragment()`
- Modify: `digitizer-site-worker/includes/class-aura-worker-api.php` — `get_status()`, just before `$unbound = Aura_Worker_Unbind::status_fragment();` (~line 610)
- Modify: `tests/unit/AuditRulesTest.php:37` — the exact `points` assertion
- Test: `tests/unit/RedactReportingTest.php`

**Interfaces:**
- Consumes: `REDACTED_COUNTER`, `PLACEHOLDER_REFUSED_COUNTER`, `STATUS_VERSION` (Task 1), the recorders (Task 3), the write guard (Task 5).
- Produces:
  - `audit_rules` → `enforcement: { blocked_24h, warned_24h, redacted_24h, placeholder_refused_24h, expired_active, points }`, `points` = `execute_tool`, `rest_updates`, `core_rest_content`, `read_redaction`, `placeholder_guard` (R11).
  - `Aura_Worker_Redact::status_fragment(): stdClass` — `{ v: 1 }`.
  - `/status` → `redaction: { "v": 1 }`, always present from 2.18.0 on.

- [ ] **Step 1: Write the failing tests**

In `tests/unit/AuditRulesTest.php`, replace line 37:

```php
		$this->assertSame( array( 'execute_tool', 'rest_updates', 'core_rest_content' ), $r['enforcement']['points'] );
```

with:

```php
		$this->assertSame(
			array( 'execute_tool', 'rest_updates', 'core_rest_content', 'read_redaction', 'placeholder_guard' ),
			$r['enforcement']['points']
		);
		$this->assertSame( 0, $r['enforcement']['redacted_24h'] );
		$this->assertSame( 0, $r['enforcement']['placeholder_refused_24h'] );
```

Create `tests/unit/RedactReportingTest.php`:

```php
<?php
/**
 * What read redaction reports (#419 v2, spec §4): the two 24h counts in
 * audit_rules, beside warned/blocked, and `/status` → `redaction: { v: 1 }`
 * so Aura can tell a 2.18.0 site from one that needs an upgrade.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactReportingTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::init();
		Aura_Worker_Redact::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function audit(): array {
		$res = ( new Aura_Worker_Tools() )->execute_tool( 'audit_rules', array() );
		$this->assertTrue( $res['success'] );
		return $res['result']['enforcement'];
	}

	public function test_both_counters_are_counted_and_reported(): void {
		do_action( 'aura_worker_redacted', 3, '/wp/v2/pages/7' );
		do_action( 'aura_worker_redacted', 1, '/mcp/elementor-mcp-server' );
		do_action( 'aura_worker_placeholder_refused', '/mcp/elementor-mcp-server' );

		$e = $this->audit();

		$this->assertSame( 2, $e['redacted_24h'], 'responses, not replacements' );
		$this->assertSame( 1, $e['placeholder_refused_24h'] );
		$this->assertSame( 0, $e['blocked_24h'], 'the rule counters are separate' );
		$this->assertSame( 0, $e['warned_24h'] );
	}

	public function test_the_counts_come_from_the_real_seams(): void {
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
		$GLOBALS['_logged_in']                    = true;

		$page = array( 'meta' => array( '_elementor_data' => wp_json_encode( array( array( 'settings' => array( 'webhooks' => 'https://hook.eu1.make.com/abc' ) ) ) ) ) );
		apply_filters( 'rest_pre_echo_response', $page, null, new WP_REST_Request( 'GET', '/wp/v2/pages/7' ) );

		$write = new WP_REST_Request( 'POST', '/wp/v2/pages/7' );
		$write->set_body_params( array( 'title' => 'aura-redacted:v1:make' ) );
		apply_filters( 'rest_request_before_callbacks', null, array(), $write );

		$e = $this->audit();
		$this->assertSame( 1, $e['redacted_24h'] );
		$this->assertSame( 1, $e['placeholder_refused_24h'] );
	}

	public function test_the_window_is_24_hours(): void {
		$now = 1_800_000_000;
		Aura_Worker_Redact::record_redacted( 1, '/wp/v2/pages/7', $now - DAY_IN_SECONDS - 2 * HOUR_IN_SECONDS );
		Aura_Worker_Redact::record_redacted( 1, '/wp/v2/pages/7', $now - HOUR_IN_SECONDS );
		$this->assertSame( 1, Aura_Worker_Rules::count_24h( Aura_Worker_Redact::REDACTED_COUNTER, $now ) );
	}

	public function test_status_reports_the_redaction_fragment_as_an_object(): void {
		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();

		$this->assertArrayHasKey( 'redaction', $body );
		$this->assertIsObject( $body['redaction'] );
		$this->assertSame( 1, $body['redaction']->v );
		$this->assertSame( '{"v":1}', wp_json_encode( $body['redaction'] ) );
	}
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/unit/RedactReportingTest.php tests/unit/AuditRulesTest.php`
Expected: FAIL — `Undefined array key "redacted_24h"`, the `points` assertion, and `redaction` missing from `/status`.

- [ ] **Step 3: Report the counters in `audit_rules`**

In `class-tool-audit-rules.php`, replace the `enforcement` line of `get_returns()` with:

```php
			'enforcement' => 'object — { blocked_24h, warned_24h, redacted_24h, placeholder_refused_24h, expired_active: string[], points: string[] } — points lists the enforcement seams in this build; redacted_24h counts agent responses that had a secret replaced, placeholder_refused_24h agent writes refused for carrying a redaction placeholder',
```

and replace the `'enforcement' => array( … ),` block of `execute()` with:

```php
			'enforcement' => array(
				'blocked_24h'             => Aura_Worker_Rules::count_24h( Aura_Worker_Rules::BLOCKED_COUNTER ),
				'warned_24h'              => Aura_Worker_Rules::count_24h( Aura_Worker_Rules::WARNED_COUNTER ),
				'redacted_24h'            => Aura_Worker_Rules::count_24h( Aura_Worker_Redact::REDACTED_COUNTER ),
				'placeholder_refused_24h' => Aura_Worker_Rules::count_24h( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER ),
				'expired_active'          => Aura_Worker_Rules::expired_keys(),
				// read_redaction = rest_pre_echo_response; placeholder_guard =
				// the write guard on rest_request_before_callbacks (2.18.0, #419).
				'points'                  => array( 'execute_tool', 'rest_updates', 'core_rest_content', 'read_redaction', 'placeholder_guard' ),
			),
```

- [ ] **Step 4: Add the `/status` fragment**

In `class-aura-worker-redact.php`, after `record_placeholder_refused()`:

```php
	/**
	 * `/status` → `redaction` (spec §4): this build redacts agent reads.
	 * An object on the wire, like every other `/status` fragment; a site
	 * without the key predates 2.18.0.
	 *
	 * @return stdClass
	 */
	public static function status_fragment() {
		return (object) array( 'v' => self::STATUS_VERSION );
	}
```

In `class-aura-worker-api.php` `get_status()`, directly before `$unbound = Aura_Worker_Unbind::status_fragment();`:

```php
		// Read redaction (2.18.0, #419): Aura shows per site whether agent
		// reads are redacted here or the site needs an upgrade.
		$status['redaction'] = Aura_Worker_Redact::status_fragment();
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/RedactReportingTest.php tests/unit/AuditRulesTest.php tests/unit/HostProbeTest.php tests/unit/UnbindMarkerTest.php`
Expected: PASS (the last two read `/status` and must be unaffected).

- [ ] **Step 6: Run the whole suite and the linter**

Run: `composer test` and `composer lint` — expected: green / no errors. `ToolContractTest` checks `audit_rules`' declared returns; only the `enforcement` description changed.

- [ ] **Step 7: Commit**

```bash
git add digitizer-site-worker/includes/tools/class-tool-audit-rules.php digitizer-site-worker/includes/class-aura-worker-redact.php digitizer-site-worker/includes/class-aura-worker-api.php tests/unit/AuditRulesTest.php tests/unit/RedactReportingTest.php
git commit -m "feat(redact): audit_rules reports redacted/refused counts and the two seams; /status carries redaction { v: 1 } (#419)

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: documentation and release 2.18.0

**Files:**
- Modify: `CLAUDE.md` — repository tree, class table, a new "Agent read redaction (2.18.0, #419)" section after the SA#95 section, WordPress Options table, Testing
- Modify: `digitizer-site-worker/digitizer-site-worker.php:6` (`Version:`) and `:21` (`AURA_WORKER_VERSION`)
- Modify: `digitizer-site-worker/readme.txt:7` (`Stable tag:`) and `== Changelog ==` (line 254)
- Modify: `README.md:20` (the `Stable-2.17.5-green` badge) and `## Changelog` (line 240)

**Interfaces:**
- Consumes: Tasks 1–6.
- Produces: the 2.18.0 build Aura's plan (spec §7.2) checks for through `/status` → `redaction`.

- [ ] **Step 1: Document the class in `CLAUDE.md`**

In the repository tree, after the `class-aura-worker-mcp.php` line:

```
        ├── class-aura-worker-redact.php     # Agent read redaction + placeholder write guard (2.18.0)
```

In the Class Responsibilities table, after the `Aura_Worker_Unbind` row:

```markdown
| `Aura_Worker_Redact` | `includes/class-aura-worker-redact.php` | Agent read redaction (2.18.0, #419): detectors (`redact`, `redact_text`), audience (`is_audience`), the `rest_pre_echo_response` read seam (`filter_echo`), the `rest_request_before_callbacks` placeholder guard and unredacted-grant check (`before_callbacks`, `grant_shape`), counters, `status_fragment` |
```

After the whole "Plugin-file mutations on hosts that block `.php` writes (SA#95)" section, before `---`, add:

```markdown
### Agent read redaction (2.18.0, #419)

Operator rules govern writes only, so a read used to hand an agent whatever the site
stores — including webhook endpoints (bearer secrets). `Aura_Worker_Redact` keeps them
out of every REST response an **agent** reads.

- **Seam.** `rest_pre_echo_response` at `PHP_INT_MAX` — core applies it only in
  `WP_REST_Server::serve_request()`, after `_envelope` and `_embed`. An internal
  `rest_do_request()` is never redacted on its own. Never move it to
  `rest_post_dispatch` (runs before `_embed`) or `rest_request_after_callbacks` (runs
  for internal dispatches). Every HTTP method is redacted. A route that serves its own
  body via `rest_pre_serve_request` bypasses it (none known on the agent path).
- **Audience** (`is_audience()`): a served REST request, not cookie-authenticated
  (`Aura_Worker_Rules::cookie_authenticated()`), and either exactly
  `/aura/mcp/tools/execute` or a route outside `aura/v1|v2|mcp` with a logged-in user.
  System routes, wp-admin and anonymous callers are never redacted. Routes compare
  lowercased.
- **Detectors.** `URL_PATTERNS` (Make, Integromat, Zapier, Slack, Discord, IFTTT,
  Telegram — full URLs anchored on the host, `\/` accepted); `SECRET_KEYS` — exact key
  names only (`webhooks`), each with its plugin/setting in a comment, never a
  substring match. Carriers decoded: `_elementor_data`/`_elementor_page_settings`
  strings (and a snapshot capture's `{ existed, value }` entry under those keys), the
  `text` of a `{type:"text"}` item under `content`, and a `snapshot_get` answer's
  base64 `payload` (`unserialize( …, allowed_classes => false )`; unreadable → `payload:
  null, payload_redacted: true`). At most two JSON decodes per path; the payload decode
  is not counted. A response with no match is returned as the same value.
- **Placeholder** `aura-redacted:v1:<kind>` (`make|integromat|zapier|slack|discord|
  ifttt|telegram|field`) — one-way, no hash.
- **Write guard** (`rest_request_before_callbacks`, priority 6, after the rules guard):
  an audience request with a method other than GET/HEAD/OPTIONS whose query, body,
  JSON or URL params (each walked separately, carriers decoded) contain
  `aura-redacted:` → `409 aura_redacted_placeholder`. Not a security boundary.
- **Unredacted grant.** `X-Aura-Unredacted-Grant`, verified by
  `Aura_Worker_Grant::verify()` on two shapes only: a single-object JSON-RPC
  `tools/call` POST to `/mcp/<server>` (tool `unredacted-read:mcp/<server>#<name>`,
  params = `arguments`) and `/aura/mcp/tools/execute` (tool
  `unredacted-read:aura/mcp#<tool>`, params = `params`). Verified → that request object
  alone is exempt. Recognised shape but invalid → `403 aura_unredacted_grant_invalid`
  (an unbound site answers `403 aura_site_unbound`). No usable gateway key, or another
  shape → header ignored. The write guard runs first, so a refused write spends no nonce.
- **Reporting.** `do_action( 'aura_worker_redacted', $count, $route )` /
  `do_action( 'aura_worker_placeholder_refused', $route )`; hourly counters through
  `Aura_Worker_Rules::bump_counter()`; `audit_rules.enforcement` carries `redacted_24h`,
  `placeholder_refused_24h` and the points `read_redaction`, `placeholder_guard`;
  `/status` carries `redaction: { v: 1 }` (an object; absent = pre-2.18.0).
```

In the WordPress Options table, after the `aura_worker_host_probe` row:

```markdown
| `aura_worker_redacted_h<hour>` | **2.18.0** — hourly count of agent responses that had a value redacted (raw-SQL increment via `Aura_Worker_Rules::bump_counter()`, swept after 24 h) |
| `aura_worker_placeholder_refused_h<hour>` | **2.18.0** — hourly count of agent writes refused for carrying `aura-redacted:` |
```

In the Testing section, append this bullet:

```markdown
- The `WP_REST_Request` stub keeps core's parameter sources apart since 2.18.0:
  `set_/get_query_params()`, `set_/get_body_params()`, `set_/get_url_params()`, and
  `set_body()` + a `Content-Type: application/json` header for `get_json_params()`.
  `get_param()` still reads only what `set_param()` stored.
```

- [ ] **Step 2: Bump the version in the plugin file**

```php
 * Version:           2.18.0
```
```php
define( 'AURA_WORKER_VERSION', '2.18.0' );
```

- [ ] **Step 3: Add the `readme.txt` entry and bump the stable tag**

`Stable tag: 2.18.0`, and directly under `== Changelog ==` (above `= 2.17.5 =`):

```
= 2.18.0 =
* Security: webhook endpoints no longer leave the site in a REST response an AI agent reads — Aura's gateway tools, an MCP client using an Application Password, or any logged-in non-browser caller. Make, Zapier, Slack, Discord, IFTTT and Telegram hook URLs, and Elementor Pro form webhooks on any host, are replaced with `aura-redacted:v1:<kind>`, including inside Elementor page data and snapshot payloads. People in wp-admin, public visitors and Aura's own system calls see the real values.
* An agent write that carries a redacted placeholder is refused (`aura_redacted_placeholder`): leave that field out and the stored value is kept.
* Aura's own page snapshots stay complete: a signed header proves the read is Aura's (`aura_unredacted_grant_invalid` when it does not verify).
* `audit_rules` reports `redacted_24h` and `placeholder_refused_24h`; `/status` reports `redaction`.
```

- [ ] **Step 4: Check the changelog budget**

Run: `php .github/scripts/check-readme-limits.php`
Expected: PASS with positive headroom (248 words before this entry; the entry is ~150). If it fails, MOVE the oldest `readme.txt` entries byte-for-byte to the top of `docs/changelog-archive.md` and advance the `= <version> and earlier =` stub to the newest version moved — never compress or rewrite an entry (CLAUDE.md "Releasing").

- [ ] **Step 5: Add the `README.md` entry and badge**

Badge on line 20: `Stable-2.18.0-green`. Under `## Changelog`, above `### 2.17.5`:

```markdown
### 2.18.0

- **Agent read redaction** (Digitizers/Aura#419). A REST response an agent reads — the gateway's `/aura/mcp/tools/execute`, or any logged-in, non-cookie caller on a route outside `aura/*` (an MCP client with an Application Password, `wp/v2`) — has known secrets replaced on `rest_pre_echo_response`, after `_embed` and `_envelope`: receiver URLs (Make/Integromat, Zapier, Slack, Discord, IFTTT, Telegram) and the value of the exact key `webhooks` (Elementor Pro form webhooks on any host). The scan decodes `_elementor_data`/`_elementor_page_settings`, MCP `content[].text`, and `snapshot_get`'s `base64(serialize())` payload (read with `allowed_classes => false`; unreadable → `payload: null, payload_redacted: true`). The placeholder `aura-redacted:v1:<kind>` is one-way. wp-admin, anonymous visitors and Aura's system routes are untouched.
- **Placeholder write guard.** An agent write whose query, body, JSON or URL params contain `aura-redacted:` is refused with `409 aura_redacted_placeholder`. Elementor element writes merge, so omitting the field keeps the stored webhook; a whole-tree import of a redacted export is refused.
- **The one unredacted read.** Aura's page-snapshot capture carries `X-Aura-Unredacted-Grant` (an Ed25519 grant bound to `unredacted-read:mcp/<server>#<tool>` and the exact `arguments`, or `unredacted-read:aura/mcp#<tool>` on the gateway). It exempts only that response; an invalid grant on those shapes is `403 aura_unredacted_grant_invalid`; a site without a usable gateway key ignores it.
- **Reporting.** `audit_rules` → `enforcement.redacted_24h`, `placeholder_refused_24h`, points `read_redaction` and `placeholder_guard`; `/status` → `redaction: { v: 1 }`.
```

- [ ] **Step 6: Verify the whole release**

Run: `composer test` (green), `composer lint` (clean), `php .github/scripts/check-readme-limits.php` (pass).
Run: `grep -rn "2\.18\.0" digitizer-site-worker/digitizer-site-worker.php digitizer-site-worker/readme.txt README.md`
Expected: the `Version:` header, the constant, `Stable tag:`, the `readme.txt` entry, the badge and the `README.md` entry.
Run: `git diff --stat main` — expected: exactly the files in this plan's File structure table.

- [ ] **Step 7: Commit**

```bash
git add CLAUDE.md digitizer-site-worker/digitizer-site-worker.php digitizer-site-worker/readme.txt README.md
git commit -m "chore(release): 2.18.0 — agent read redaction, the placeholder write guard, and the unredacted snapshot grant (#419)

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## After the tasks

1. Open the PR against `main` and run the Codex review loop, watching CI (PHP 7.4 / 8.1 / 8.2, `Readme limits`) in parallel.
2. Merge, pre-release, the staging check, stable release and rollout are **each** the owner's call. **Do not promote 2.18.0 to stable on any site that holds a webhook before Aura's §1.3 capture grant is deployed** (spec §7.2) — otherwise that site's new page snapshots are stored redacted.
3. Staging (`refineintelstg`), end to end (spec §6): a page with an Elementor Pro form whose webhook is a Make URL —
   - an agent read (`elementor-mcp/export-page` over `/mcp/elementor-mcp-server` with an Application Password, and `snapshot_get` through the gateway) shows `aura-redacted:v1:make`;
   - an agent `update-widget` on that form without `webhooks` leaves the stored URL byte-for-byte (`wp post meta get <id> _elementor_data`);
   - an `import-template` of the exported tree is refused with `aura_redacted_placeholder`;
   - wp-admin (the Elementor editor) shows the real URL;
   - `/wp-json/aura/v1/status` carries `"redaction":{"v":1}`, and `audit_rules` shows the two counts.
4. Promoting a pre-release to stable goes through the draft toggle (CLAUDE.md "Releasing").

## Self-review (done while writing)

- **Spec coverage.** §1.1 seam → Task 3 (+ Task 1 Step 1 verification of core's order and the adapter). §1.2 audience → Task 2, seam tests in Task 3. §1.3 grant, both rows, exemption per request, 403, no-key ignore → Task 4. §2.1 → Task 1. §2.2 → Task 1. §2.2a carriers 1–3, bound, byte-for-byte, fail-closed payload, object payload → Task 1 (with R1–R3). §2.3 placeholder → Task 1. §2.4 same value → Tasks 1 and 3. §3 → Task 5 (per source, decoded, escape, cookie allowed, GET skipped, update-widget omission). §4 site half → Tasks 3 and 6. §5 limits → documented in CLAUDE.md (Task 7) and the Rulings. §6 SiteAgent tests → Tasks 1–6; staging → "After the tasks". §7.1 → Task 7.
- **Placeholder scan.** No TBD/TODO; every code step carries the code.
- **Name consistency.** `redact`, `redact_text`, `is_audience`, `filter_echo`, `before_callbacks`, `grant_shape`, `holds_placeholder`, `status_fragment`, `record_redacted`, `record_placeholder_refused`, `reset_for_tests`; `Aura_Worker_Rules::serving_rest`, `cookie_authenticated`, `bump_counter`; constants `REDACTED_COUNTER`, `PLACEHOLDER_REFUSED_COUNTER`, `STATUS_VERSION` — used identically in every task.
- **Dry run.** The assembled class, the Rules/API/tool/bootstrap edits and all six new test files were run while writing this plan against the SiteAgent test bootstrap (PHP 8.5, PHPUnit 10.5 classes, in-memory file patches — nothing written to the repo): all 159 new test cases passed; after the Codex round-1 fixes (IFTTT trigger URLs, URL tail boundary) only `RedactDetectorsTest` was re-run — 76/76 after round 1, 84/84 after round 2 (mangled property names, encoded HTML delimiters); after round 3 (URL keys) all six new files plus the changed `AuditRulesTest` were re-run — 222/222, and the existing suite showed no new failure against an unpatched baseline under the same runner. `composer test` / `composer lint` on the real branch remain the gate, including PHP 7.4.
