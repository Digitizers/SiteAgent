<?php
/**
 * Signed single-use approval grants (G-grants).
 *
 * Verifies a gateway-minted Ed25519 grant that authorizes ONE execution of ONE
 * approval-required tool, with EXACTLY the given parameters, on THIS site,
 * once, within a short window. This closes the gap where a stolen site token
 * alone could run a power tool: when a gateway public key is provisioned,
 * enforcement here means a fresh, human-approved, gateway-signed grant is also
 * required — so a leaked token is no longer sufficient.
 *
 * Asymmetric on purpose: the plugin stores only the gateway PUBLIC key
 * (`aura_worker_grant_pubkey`). Even a fully compromised site — one whose
 * database an attacker can read — cannot mint its own grants, because the
 * signing (private) key never leaves the Aura gateway.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Grant {

	/**
	 * Allowed clock skew, in seconds, between the gateway and this site.
	 */
	const CLOCK_SKEW = 60;

	/**
	 * Reject a grant whose declared lifetime (exp - iat) exceeds this, so a
	 * mis-minted long-lived grant can't be stockpiled.
	 */
	const MAX_TTL = 900;

	/**
	 * Transient key prefix for spent nonces (single-use enforcement).
	 */
	const NONCE_PREFIX = 'aura_grant_nonce_';

	/**
	 * Whether grant enforcement is active for this site.
	 *
	 * Enforcement turns ON once the gateway has provisioned its public key. Until
	 * then the site keeps its prior behavior (forensic hook only), so existing
	 * deployments don't break on upgrade.
	 *
	 * @return bool
	 */
	public static function is_enforced() {
		return '' !== self::pubkey_raw();
	}

	/**
	 * The raw 32-byte Ed25519 public key, or '' when not provisioned / invalid.
	 *
	 * @return string
	 */
	private static function pubkey_raw() {
		$b64 = (string) get_option( 'aura_worker_grant_pubkey', '' );
		if ( '' === $b64 ) {
			return '';
		}
		$raw = base64_decode( $b64, true );
		if ( false === $raw || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Verify a grant authorizes THIS tool + params on THIS site, right now, once.
	 *
	 * @param string $header X-Aura-Approval-Grant header value ("b64url.b64url").
	 * @param string $tool   Tool name being executed.
	 * @param array  $params Parameters the tool was called with.
	 * @return true|string   True when valid; otherwise a short failure reason.
	 */
	public static function verify( $header, $tool, $params ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return 'server is missing libsodium';
		}

		$pubkey = self::pubkey_raw();
		if ( '' === $pubkey ) {
			return 'no grant key provisioned';
		}

		$header = trim( (string) $header );
		if ( '' === $header ) {
			return 'missing grant';
		}

		$parts = explode( '.', $header );
		if ( 2 !== count( $parts ) ) {
			return 'malformed grant';
		}

		$payload_json = self::b64url_decode( $parts[0] );
		$sig          = self::b64url_decode( $parts[1] );
		if ( false === $payload_json || false === $sig ) {
			return 'malformed grant';
		}
		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return 'bad signature length';
		}

		// Verify the signature FIRST, over the exact bytes that were signed.
		if ( ! sodium_crypto_sign_verify_detached( $sig, $payload_json, $pubkey ) ) {
			return 'signature verification failed';
		}

		$g = json_decode( $payload_json, true );
		if ( ! is_array( $g ) ) {
			return 'unparseable grant';
		}
		if ( 1 !== ( isset( $g['v'] ) ? (int) $g['v'] : 0 ) ) {
			return 'unsupported grant version';
		}

		// Bind to the exact tool.
		if ( ! isset( $g['tool'] ) || ! hash_equals( (string) $g['tool'], (string) $tool ) ) {
			return 'tool mismatch';
		}

		// Bind to the exact parameters.
		$expected = hash( 'sha256', self::canonical_json( $params ) );
		if ( ! isset( $g['params_sha256'] ) || ! hash_equals( (string) $g['params_sha256'], $expected ) ) {
			return 'params mismatch';
		}

		// Bind to THIS site (a grant for site A can't be replayed on site B). The
		// gateway computes sha256(raw token); the plugin stores that same hash.
		$site = (string) get_option( 'aura_worker_site_token', '' );
		if ( '' === $site || ! isset( $g['site'] ) || ! hash_equals( $site, (string) $g['site'] ) ) {
			return 'site mismatch';
		}

		// Validity window.
		$now = time();
		$iat = isset( $g['iat'] ) ? (int) $g['iat'] : 0;
		$exp = isset( $g['exp'] ) ? (int) $g['exp'] : 0;
		if ( $iat <= 0 || $exp <= 0 ) {
			return 'missing validity window';
		}
		if ( $exp - $iat > self::MAX_TTL ) {
			return 'grant lifetime too long';
		}
		if ( $now + self::CLOCK_SKEW < $iat ) {
			return 'grant not yet valid';
		}
		if ( $now - self::CLOCK_SKEW > $exp ) {
			return 'grant expired';
		}

		// Single-use: a spent nonce is refused. Stored only until the grant would
		// expire anyway (the window check above bounds replay past that).
		$nonce = isset( $g['nonce'] ) ? (string) $g['nonce'] : '';
		if ( '' === $nonce || strlen( $nonce ) > 128 || ! ctype_xdigit( $nonce ) ) {
			return 'bad nonce';
		}
		$key = self::NONCE_PREFIX . hash( 'sha256', $nonce );
		if ( false !== get_transient( $key ) ) {
			return 'grant already used';
		}
		set_transient( $key, 1, max( 1, ( $exp - $now ) + self::CLOCK_SKEW ) );

		return true;
	}

	/**
	 * Canonical JSON used for the params hash. MUST match the gateway byte-for-byte.
	 *
	 * Rule: object keys are sorted recursively; list order is preserved; ANY empty
	 * container encodes as `{}`. The empty→`{}` rule sidesteps PHP's inability to
	 * distinguish an empty array from an empty object — the gateway applies the
	 * identical rule (empty array OR empty object ⇒ `{}`), so both sides agree.
	 *
	 * @param mixed $data Parameters.
	 * @return string
	 */
	public static function canonical_json( $data ) {
		return (string) wp_json_encode(
			self::canonicalize( $data ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	private static function canonicalize( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( empty( $data ) ) {
			return new stdClass(); // Emit {} deterministically for any empty container.
		}
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		$out     = array();
		foreach ( $data as $k => $v ) {
			$out[ $k ] = self::canonicalize( $v );
		}
		if ( ! $is_list ) {
			ksort( $out );
		}
		return $out;
	}

	/**
	 * URL-safe base64 decode (strict).
	 *
	 * @param string $s
	 * @return string|false
	 */
	private static function b64url_decode( $s ) {
		$s   = strtr( (string) $s, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $s, true );
	}
}
