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
 * An intermediate layer can expose a receiver URL that a later layer's own
 * decoding hides again (e.g. a decoded numeric reference landing directly
 * against a host with no separator). `decode_layers()` returns every layer
 * — the raw run first, then each pass whose result differed from the one
 * before it — so Task 2 (`Aura_Worker_Redact::redact_encoded_runs()`) MUST
 * check every layer against URL_PATTERNS, not only the last one.
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
	 * code unit. JSON only defines lowercase `\u`, so the escape marker is
	 * case-sensitive (`\U0041` does not match); the hex digits themselves
	 * stay case-insensitive via explicit `[0-9A-Fa-f]`-style classes.
	 */
	const RE_JSON_UNICODE = '/\\\\u(?:([dD][89abAB][0-9A-Fa-f]{2})\\\\u([dD][c-fC-F][0-9A-Fa-f]{2})|([0-9A-Fa-f]{4}))/';

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
	 * Decode $run up to MAX_DECODE_PASSES layers, keeping every layer along
	 * the way.
	 *
	 * Every element is a distinct reading of $run a real consumer of it
	 * could land on — the raw run itself first (element 0), because that is
	 * what a reader who does no decoding at all sees, then each pass whose
	 * result differed from the one before it, up to the fixed point. The
	 * caller (Task 2) MUST check every element against URL_PATTERNS, not
	 * only the last one: an intermediate layer can expose a receiver URL
	 * that a later pass's own decoding goes on to hide again.
	 *
	 * @param string $run One run of text (no whitespace, quote or angle bracket).
	 * @return array<int,string>|null 1–(MAX_DECODE_PASSES+1) layers, `[ $run ]`
	 *                     when nothing decodes — or null: still changing
	 *                     past MAX_DECODE_PASSES layers plus the check pass,
	 *                     an intermediate value longer than MAX_DECODE_GROWTH
	 *                     × strlen( $run ), or a PCRE failure.
	 */
	public static function decode_layers( $run ) {
		$run     = (string) $run;
		$limit   = self::MAX_DECODE_GROWTH * strlen( $run );
		$layers  = array( $run );
		$current = $run;
		for ( $pass = 0; $pass < self::MAX_DECODE_PASSES; ++$pass ) {
			$next = self::decode_pass( $current );
			if ( null === $next || strlen( $next ) > $limit ) {
				return null;
			}
			if ( $next === $current ) {
				return $layers; // a fixed point: nothing more to decode
			}
			$layers[] = $next;
			$current  = $next;
		}
		// The check pass: a fifth layer is refused, never guessed at.
		return self::decode_pass( $current ) === $current ? $layers : null;
	}

	/**
	 * Decode $run up to MAX_DECODE_PASSES layers.
	 *
	 * @param string $run One run of text (no whitespace, quote or angle bracket).
	 * @return string|null The decoded run — $run itself when nothing decodes —
	 *                     or null: more than MAX_DECODE_PASSES layers, growth
	 *                     past MAX_DECODE_GROWTH, or a PCRE failure.
	 */
	public static function decode_run( $run ) {
		$layers = self::decode_layers( $run );
		return null === $layers ? null : $layers[ count( $layers ) - 1 ];
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
