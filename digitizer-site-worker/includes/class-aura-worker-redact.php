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

	/** Regex: `//`, JSON-escaped any number of times, then optional userinfo — shared by the schemed and protocol-relative prefixes. */
	const RE_DOUBLE_SLASH_USERINFO = '(?:\\\\)*/(?:\\\\)*/(?:[^\s/\\\\@"\'<>]+@)?';

	/**
	 * Regex: the URL's prefix — full scheme (`https://`), protocol-relative
	 * (`//`), or bare (neither) — the host must follow at once either way.
	 * A leading negative lookbehind enforces the host boundary (owner
	 * decision, fix round 1): whatever precedes the prefix — or, for the
	 * bare form, the host literal itself — must not be a character a
	 * hostname can contain, so `myhooks.zapier.com` and
	 * `evilhooks.zapier.com` never match at the `hooks.zapier.com`
	 * substring; only a genuine boundary (start of string, whitespace,
	 * quote, punctuation, …) does.
	 */
	const RE_HEAD = '~(?<![A-Za-z0-9.-])(?:https?:' . self::RE_DOUBLE_SLASH_USERINFO . '|' . self::RE_DOUBLE_SLASH_USERINFO . ')?';

	/**
	 * Regex: an optional trailing FQDN dot, an optional port, then the slash
	 * that ends the host (fix round 1, Codex r1 P3: `hooks.zapier.com./...`
	 * is the same host as `hooks.zapier.com/...`; the dot never lets a
	 * lookalike host — `hooks.zapier.com.evil.tld` — through, since nothing
	 * follows the single dot but the required port/slash).
	 */
	const RE_HOST_END = '\.?(?::[0-9]+)?(?:\\\\)*/';

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
		// Make: `hook.<region>.make.com/<id>`, and Make on Celonis `hook.<region>.make.celonis.com/<id>`.
		array( 'make', self::RE_HEAD . 'hook\.[a-z0-9-]+\.make\.(?:celonis\.)?com' . self::RE_HOST_END . self::RE_TAIL ),
		// Integromat (Make's former name): `hook.integromat.com/<id>` and `hook.<region>.integromat.com/<id>`.
		array( 'integromat', self::RE_HEAD . 'hook\.(?:[a-z0-9-]+\.)?integromat\.com' . self::RE_HOST_END . self::RE_TAIL ),
		// Zapier: every path on hooks.zapier.com (`/hooks/catch/…`, `/hooks/standard/…`).
		array( 'zapier', self::RE_HEAD . 'hooks\.zapier\.com' . self::RE_HOST_END . self::RE_TAIL ),
		// Slack: incoming webhooks `/services/…`, Workflow Builder triggers `/triggers/…`, legacy workflow webhooks `/workflows/…` (Codex r4 P1).
		array( 'slack', self::RE_HEAD . 'hooks\.slack\.com' . self::RE_HOST_END . '(?:services|triggers|workflows)' . self::RE_SLASH . self::RE_TAIL ),
		// Discord: `/api/webhooks/…` and the versioned `/api/v<n>/webhooks/…`, on discord.com (also ptb./canary.) and discordapp.com.
		array( 'discord', self::RE_HEAD . '(?:(?:ptb|canary)\.)?discord\.com' . self::RE_HOST_END . 'api' . self::RE_SLASH . '(?:v[0-9]+' . self::RE_SLASH . ')?webhooks' . self::RE_SLASH . self::RE_TAIL ),
		array( 'discord', self::RE_HEAD . '(?:(?:ptb|canary)\.)?discordapp\.com' . self::RE_HOST_END . 'api' . self::RE_SLASH . '(?:v[0-9]+' . self::RE_SLASH . ')?webhooks' . self::RE_SLASH . self::RE_TAIL ),
		// IFTTT Webhooks: `/use/<key>`, `/trigger/<event>/with/key/<key>` and `/trigger/<event>/json/with/key/<key>`.
		array( 'ifttt', self::RE_HEAD . 'maker\.ifttt\.com' . self::RE_HOST_END . '(?:use' . self::RE_SLASH . '|trigger' . self::RE_SLASH . '[^\s/\\\\"\'<>]+' . self::RE_SLASH . '(?:json' . self::RE_SLASH . ')?with' . self::RE_SLASH . 'key' . self::RE_SLASH . ')' . self::RE_TAIL ),
		// Telegram: Bot API `/bot<token>/<method>` and file downloads `/file/bot<token>/<path>`.
		// The token itself ends at `/`, `?`, `#` or the end of the URL — the
		// slash is only where a path follows (fix round 1, Codex r1 P3):
		// `.../bot123:AAbb` and `.../bot123:AAbb?x=1` are bare tokens too.
		array( 'telegram', self::RE_HEAD . 'api\.telegram\.org' . self::RE_HOST_END . '(?:file' . self::RE_SLASH . ')?bot[0-9]+:[a-z0-9_-]+' . '(?:' . self::RE_SLASH . ')?' . self::RE_TAIL ),
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

	/**
	 * Key names, beyond SECRET_KEYS and JSON_META_KEYS, that this class also
	 * treats structurally — checked the same way inside an opaque object
	 * (fix round 1, Codex r1 P4): `content` is the MCP text-carrier field
	 * (walk_entry's `'content' === $key` branch), so an opaque object naming
	 * a property `content` may itself hold a text carrier this class cannot
	 * verify clean, and fails the payload closed the same as a secret key.
	 */
	const STRUCTURAL_KEYS = array( 'content' );

	/** JSON decodes allowed along one path (spec §2.2a). A payload decode is not counted (Ruling R2). */
	const MAX_JSON_DECODES = 2;

	/**
	 * Containers walk() and walk_with_carrier() may be nested in along one
	 * path — json_encode()'s own default depth. Past it (a self-referencing
	 * object, or a tree no JSON response could carry) the subtree is the
	 * field placeholder: fail closed, never a fatal recursion (final review).
	 */
	const MAX_WALK_DEPTH = 512;

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

	/**
	 * An MCP adapter server route: exactly one segment under `mcp`. Matched
	 * the way core's WP_REST_Server::match_request_to_handler() dispatches —
	 * `@^…$@i`, anchored, case-insensitive, and (no `D` modifier) tolerating
	 * one trailing newline — never by string equality (Task 2 ruling, #419).
	 */
	const MCP_ROUTE = '@^/mcp/([a-z0-9-]+)$@i';

	/** `/status` → `redaction: { v }`. A site without the key predates the feature. */
	const STATUS_VERSION = 1;

	/**
	 * Requests whose unredacted grant verified, by spl_object_id. The object
	 * itself is held, so its id cannot be reused by another object while the
	 * entry stands; filter_echo() drops it with its response.
	 *
	 * @var array<int,object>
	 */
	private static $exempt = array();

	/**
	 * How many walk()/walk_with_carrier() containers the current path is in.
	 * Every recursion of the walk passes one of the two.
	 *
	 * @var int
	 */
	private static $walk_level = 0;

	/**
	 * Hook the read seam and the counters. Called from Aura_Worker::init(),
	 * right after Aura_Worker_Rules::init().
	 */
	public static function init() {
		// After Aura_Worker_Rules::guard_core_any() (5): a rule block or an
		// unbind refusal wins, and no grant nonce is spent on it (R5).
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'before_callbacks' ), 6, 3 );
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
		if ( is_object( $request ) && self::take_exemption( $request ) ) {
			return $result; // Aura's own snapshot capture (spec §1.3) — this response only
		}
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
	 * `rest_request_before_callbacks` — before the permission check and the
	 * handler. The placeholder guard runs first, then the grant.
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
		// The gateway row is in the audience whoever calls it, and this filter
		// runs before its permission callback checks X-Aura-Token. So nothing
		// here — no 409, no counter, no nonce — acts for a caller that does not
		// hold the site token: the permission callback answers it (final
		// review, #419). A pure comparison: no throttle, no captured auth, no
		// current user.
		if ( self::is_gateway_execute_route( (string) $request->get_route() ) && ! self::carries_site_token( $request ) ) {
			return $response;
		}
		$refused = self::refuse_placeholder_write( $request );
		if ( null !== $refused ) {
			return $refused; // before the grant: a refused write spends no nonce (R5)
		}
		$grant = self::verify_unredacted_grant( $request );
		return null === $grant ? $response : $grant;
	}

	/**
	 * Does the request carry the site token? Aura_Worker_Security's own
	 * comparison, without any of check_aura_token()'s side effects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function carries_site_token( $request ) {
		if ( ! method_exists( $request, 'get_header' ) || ! class_exists( 'Aura_Worker_Security' ) ) {
			return false;
		}
		return Aura_Worker_Security::token_matches( (string) $request->get_header( 'X-Aura-Token' ) );
	}

	/**
	 * The write guard (spec §3): an agent write carrying the placeholder is
	 * refused — the site never swaps it back, so it could only overwrite a
	 * real value with a dead string. Each parameter source on its own:
	 * get_params()' precedence can hide one source's value behind a
	 * same-named key in another. File parameters (`get_file_params()`) are
	 * out of scope: an upload is not a field the placeholder came back in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|null
	 */
	private static function refuse_placeholder_write( $request ) {
		$method = strtoupper( (string) $request->get_method() );
		if ( in_array( $method, Aura_Worker_Rules::SAFE_METHODS, true ) ) {
			return null;
		}
		$views = array();
		foreach ( array( 'get_query_params', 'get_body_params', 'get_json_params', 'get_url_params' ) as $source ) {
			if ( method_exists( $request, $source ) ) {
				$views[] = $request->$source();
			}
		}
		$views[] = self::lazy_form_body( $request, $method );
		foreach ( $views as $view ) {
			if ( ! self::holds_placeholder( $view ) ) {
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
	 * The form body core has not parsed yet. For a method other than POST,
	 * core fills get_body_params() from the raw body only in
	 * parse_body_params(), which it reaches lazily (get_parameter_order(),
	 * i.e. the first get_param() or the route's `args` check) — after this
	 * filter on a route without `args`. The handler's get_param() would
	 * still see the value, so the raw body is parsed here the way core
	 * would parse it, as one more view of the body source (never merged).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $method  Upper-case method.
	 * @return array|null
	 */
	private static function lazy_form_body( $request, $method ) {
		if ( 'POST' === $method || ! method_exists( $request, 'get_body' ) ) {
			return null;
		}
		$body = (string) $request->get_body();
		if ( empty( $body ) ) {
			return null; // core: `! empty( $body )`
		}
		// WP_REST_Request::get_content_type(), then parse_body_params(): a
		// missing or slash-less type counts as form-encoded; any other type
		// is never parsed by core.
		$type = method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'Content-Type' ) : '';
		if ( ! empty( $type ) ) {
			if ( strpos( $type, ';' ) ) {
				$type = explode( ';', $type, 2 )[0];
			}
			$type = trim( strtolower( $type ) );
			if ( false !== strpos( $type, '/' ) && 'application/x-www-form-urlencoded' !== $type ) {
				return null;
			}
		}
		parse_str( $body, $params );
		return $params;
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
		if ( self::is_snapshot_answer( $value ) && self::payload_holds_placeholder( $value['payload'] ) ) {
			return true;
		}
		foreach ( $value as $key => $item ) {
			if ( self::entry_holds_placeholder( $key, $item, $depth ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A carrier-3 payload, as bytes (R4: agent bytes are never unserialized).
	 * A serialized string keeps a JSON carrier's escapes verbatim, so the
	 * `\uXXXX` escapes are undone on the bytes before the scan.
	 *
	 * @param string $payload Base64 payload.
	 * @return bool
	 */
	private static function payload_holds_placeholder( $payload ) {
		$bytes = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the snapshot payload's own encoding
		if ( ! is_string( $bytes ) ) {
			return false;
		}
		if ( false !== stripos( $bytes, self::PLACEHOLDER_MARK ) ) {
			return true;
		}
		if ( false === strpos( $bytes, '\\u' ) ) {
			return false;
		}
		// One level of `\u00XX` only, over the whole byte string: a text that
		// was itself escaped twice may be refused where it need not be. The
		// guard prevents accidents; it is not a security boundary.
		$unescaped = preg_replace_callback(
			'/\\\\u00([0-7][0-9a-fA-F])/',
			static function ( $m ) {
				return chr( hexdec( $m[1] ) );
			},
			$bytes
		);
		return is_string( $unescaped ) && false !== stripos( $unescaped, self::PLACEHOLDER_MARK );
	}

	/**
	 * One key/value pair, routed the way walk_entry() routes it on the read side.
	 *
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
		// Only a LIST under `content` is MCP's text-block carrier, as on the
		// read side (Task 1 fix round 1): anything else is walked plainly, so
		// a snapshot answer there is still seen.
		if ( 'content' === $key && is_array( $item ) && self::is_list( $item ) ) {
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
		if ( self::is_snapshot_answer( $fields ) ) {
			return self::holds_placeholder( $fields, $depth ); // as walk_with_carrier() does
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
	 * A JSON-carrying string: its raw text, then (within the decode bound)
	 * its decoded tree.
	 *
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

	/**
	 * Verify `X-Aura-Unredacted-Grant` on a recognised shape and, when it
	 * holds, exempt THIS request's response (spec §1.3).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|null A refusal, or null (verified, absent or ignored).
	 */
	private static function verify_unredacted_grant( $request ) {
		if ( ! method_exists( $request, 'get_header' ) ) {
			return null;
		}
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
		// verify() binds the grant to sha256(raw token) as stored. A site
		// still storing a legacy plaintext token would refuse every grant —
		// and the MCP row never presents the token, so nothing else migrates
		// it on that path (PR #111 Codex r1 P2).
		if ( class_exists( 'Aura_Worker_Security' ) ) {
			Aura_Worker_Security::migrate_legacy_stored_token();
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
				sprintf( __( 'The X-Aura-Unredacted-Grant header did not verify for this request: %s.', 'digitizer-site-worker' ), (string) $verdict ),
				array( 'status' => 403 )
			);
		}
		self::$exempt[ spl_object_id( $request ) ] = $request;
		return null;
	}

	/**
	 * The two request shapes a grant is honoured on, with the tool and
	 * params it must bind — derived from the request itself (spec §1.3).
	 * Both routes are matched the way core dispatches them (see
	 * is_gateway_execute_route() and MCP_ROUTE).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{tool:string,params:array}|null
	 */
	public static function grant_shape( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return null;
		}
		$route = (string) $request->get_route();
		if ( self::is_gateway_execute_route( $route ) ) {
			// Exactly what Aura_Worker_MCP::execute_tool() runs: `tool`, and
			// `params` with a non-array read as none. A `tool` that is not a
			// string names no tool: the header is ignored, not stringified.
			$tool = $request->get_param( 'tool' );
			if ( ! is_string( $tool ) ) {
				return null;
			}
			$params = $request->get_param( 'params' );
			return array(
				'tool'   => self::GRANT_TOOL_PREFIX . 'aura/mcp#' . $tool,
				'params' => is_array( $params ) ? $params : array(),
			);
		}
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) || 1 !== preg_match( self::MCP_ROUTE, $route, $m ) ) {
			return null;
		}
		// The MCP adapter reads its message from the JSON body.
		$body = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		// The adapter's own batch test (JsonRpcResponseBuilder::is_batch_request())
		// is isset( $body[0] ): an object that also carries a "0" key is a
		// batch there, so it is one here too — never a single tools/call.
		if ( ! is_array( $body ) || array() === $body || isset( $body[0] ) ) {
			return null; // one JSON object only — a batch is not honoured
		}
		if ( ! isset( $body['method'], $body['params'] ) || 'tools/call' !== $body['method'] || ! is_array( $body['params'] ) ) {
			return null;
		}
		// Exactly the call the adapter runs: ToolsHandler::call_tool() takes
		// extract_params( params ) = `params['params'] ?? params`, so a nested
		// `params.params` wins over the outer name and arguments.
		$call = isset( $body['params']['params'] ) ? $body['params']['params'] : $body['params'];
		if ( ! is_array( $call ) ) {
			return null; // the adapter's array return type refuses it
		}
		if ( ! isset( $call['name'] ) || ! is_string( $call['name'] ) ) {
			return null;
		}
		$name = trim( $call['name'] ); // the adapter's ToolsHandler runs this name
		if ( '' === $name ) {
			return null;
		}
		$args = array_key_exists( 'arguments', $call ) && null !== $call['arguments'] ? $call['arguments'] : array();
		// `arguments` is an object (R15). An empty one decodes to array() —
		// which is_list() calls a list — so only a non-empty list is refused.
		if ( ! is_array( $args ) || ( array() !== $args && self::is_list( $args ) ) ) {
			return null;
		}
		return array(
			// The route matched case-insensitively; the server binds in the
			// lower case its route is registered in (`[a-z0-9-]+`).
			'tool'   => self::GRANT_TOOL_PREFIX . 'mcp/' . strtolower( $m[1] ) . '#' . $name,
			'params' => $args,
		);
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
		self::$exempt     = array();
		self::$walk_level = 0;
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
		if ( false === strpos( $text, '/' ) ) {
			// Every receiver's RE_HOST_END requires a slash right after the
			// host — schemed, protocol-relative or bare (owner decision, fix
			// round 1: `http` is no longer a reliable fast-reject signal now
			// that a scheme is optional) — and a JSON-escaped slash (`\/`)
			// still contains a literal `/`. No slash, no URL anywhere in the
			// string: the common case never runs a regex.
			return $text;
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
	 * Is this request's response one an AGENT reads (spec §1.2)?
	 *
	 * True when it is a served REST request and either the gateway's tool
	 * execution route (core-matched — see is_gateway_execute_route(); that
	 * route is SiteAgent-token-authenticated and never a wp-admin surface,
	 * so the cookie flag is not even consulted for it, fix round 1 #419) or,
	 * when not cookie-authenticated, a route outside SiteAgent's own
	 * namespaces reached by a logged-in user (an Application Password on
	 * `mcp/v1`, any agent on `wp/v2`). SiteAgent's system routes are called
	 * by the Aura server only and are never redacted; neither is a person
	 * nor the public.
	 *
	 * The namespace check compares lowercased: WordPress matches routes
	 * case-insensitively (R7). The gateway-route check is itself
	 * case-insensitive; see is_gateway_execute_route().
	 *
	 * @param mixed $request WP_REST_Request, or anything else (false).
	 * @return bool
	 */
	public static function is_audience( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}
		if ( ! Aura_Worker_Rules::serving_rest() ) {
			return false;
		}
		$route = (string) $request->get_route();
		if ( self::is_gateway_execute_route( $route ) ) {
			return true;
		}
		if ( Aura_Worker_Rules::cookie_authenticated() ) {
			return false;
		}
		if ( self::is_own_route( strtolower( $route ) ) ) {
			return false;
		}
		return is_user_logged_in();
	}

	/**
	 * Is $route the gateway's tool-execution route, matched exactly the way
	 * core's WP_REST_Server::match_request_to_handler() matches it:
	 * `preg_match( '@^' . $route . '$@i', $path )` — anchored, case-insensitive,
	 * and (no `D` modifier) `$` matches before a trailing newline. So a route
	 * with a trailing `\n` (e.g. `?rest_route=/aura/mcp/tools/execute%0A`)
	 * still dispatches to tools/execute in core, and this must say so too
	 * (fix round 1, IMPORTANT #1, #419) — a second trailing newline, or any
	 * character after the first one, is NOT what core would dispatch here.
	 * Public so Task 4's REST seam can reuse the same matching.
	 *
	 * @param string $route Unmodified route straight from the request.
	 * @return bool
	 */
	public static function is_gateway_execute_route( $route ) {
		return 1 === preg_match( '@^' . preg_quote( self::GATEWAY_EXECUTE_ROUTE, '@' ) . '$@i', (string) $route );
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
		if ( ! is_array( $node ) && ! is_object( $node ) ) {
			return $node; // int, float, bool, null
		}
		if ( self::$walk_level >= self::MAX_WALK_DEPTH ) {
			++$count;
			return self::PLACEHOLDER . 'field';
		}
		++self::$walk_level;
		try {
			return self::walk_container( $node, $depth, $in_payload, $count );
		} finally {
			--self::$walk_level;
		}
	}

	/**
	 * walk() for an array or an object, inside the nesting bound.
	 *
	 * @param array|object $node       Container.
	 * @param int          $depth      JSON decodes already made along this path.
	 * @param bool         $in_payload Inside a snapshot payload (carrier 3).
	 * @param int          $count      In/out.
	 * @return mixed
	 * @throws UnexpectedValueException Inside a payload only (see walk()).
	 */
	private static function walk_container( $node, $depth, $in_payload, &$count ) {
		if ( is_array( $node ) ) {
			return self::walk_array( $node, $depth, $in_payload, $count );
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
		// Public properties are exactly what the JSON response carries (R14) —
		// except for ArrayObject and ArrayIterator, whose storage json_encode()
		// emits instead (unless STD_PROP_LIST is set), always as a JSON object.
		// Only those two: json_encode() never iterates any other Traversable,
		// and iterating one (a generator) here would consume it.
		$fields = get_object_vars( $node );
		if ( ( $node instanceof ArrayObject || $node instanceof ArrayIterator )
			&& 0 === ( $node->getFlags() & ArrayObject::STD_PROP_LIST ) ) {
			$fields = $node->getArrayCopy();
		}
		$walked = self::walk_array( $fields, $depth, $in_payload, $count );
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
		// Only MCP's `content` — a LIST of `{ type, text }` blocks — takes
		// this path (fix round 1, Codex r1 P1): an associative array under
		// this key (a snapshot answer, or anything else object-shaped) is
		// not that carrier and must fall through to the ordinary walk below,
		// which is where is_snapshot_answer() is checked.
		if ( 'content' === $key && is_array( $value ) && self::is_list( $value ) ) {
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
	 * Whether $arr is a sequential, zero-based list — MCP's `content` field
	 * is one; a snapshot answer's `{ found, record, payload }` is not (fix
	 * round 1, Codex r1 P1).
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_list( array $arr ) {
		$i = 0;
		foreach ( $arr as $key => $value ) {
			if ( $key !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
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
		if ( self::$walk_level >= self::MAX_WALK_DEPTH ) {
			++$count;
			return self::PLACEHOLDER . 'field'; // as walk(): a carrier container can nest in itself too
		}
		++self::$walk_level;
		try {
			return self::walk_carrier_fields( $container, $is_object, $carrier, $depth, $in_payload, $count );
		} finally {
			--self::$walk_level;
		}
	}

	/**
	 * walk_with_carrier() for an array or stdClass, inside the nesting bound.
	 *
	 * @param array|stdClass $container  Container.
	 * @param bool           $is_object  Whether it is a stdClass.
	 * @param string         $carrier    The carrier field's name.
	 * @param int            $depth      JSON decodes so far.
	 * @param bool           $in_payload Inside a payload.
	 * @param int            $count      In/out.
	 * @return mixed
	 */
	private static function walk_carrier_fields( $container, $is_object, $carrier, $depth, $in_payload, &$count ) {
		if ( ! $is_object && self::is_snapshot_answer( $container ) ) {
			// The carrier field's own container is itself a snapshot answer
			// (fix round 1, Codex r1 P1) — e.g. `_elementor_data` decoded
			// into `{ found, record, payload }` rather than the ordinary
			// `{ existed, value }` meta shape. The ordinary array walk
			// already covers it, carrier field or not.
			return self::walk_array( $container, $depth, $in_payload, $count );
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
	 * for a receiver URL and for any key name the detectors act on (R3) —
	 * SECRET_KEYS, JSON_META_KEYS, and STRUCTURAL_KEYS.
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
		foreach ( array_merge( self::SECRET_KEYS, self::JSON_META_KEYS, self::STRUCTURAL_KEYS ) as $key ) {
			$name = '(?:s:' . strlen( $key ) . ':"|\x00)' . preg_quote( $key, '/' ) . '";';
			if ( 1 === preg_match( '/' . $name . '/', $bytes ) ) {
				return true;
			}
		}
		return false;
	}
}
