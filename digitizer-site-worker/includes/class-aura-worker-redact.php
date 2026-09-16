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
		array( 'telegram', self::RE_HEAD . 'api\.telegram\.org' . self::RE_HOST_END . '(?:file' . self::RE_SLASH . ')?bot[0-9]+:[a-z0-9_-]+' . self::RE_SLASH . self::RE_TAIL ),
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
