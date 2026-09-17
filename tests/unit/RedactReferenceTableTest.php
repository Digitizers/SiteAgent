<?php
/**
 * PR #112 Codex r2: every structural character the receiver patterns
 * accept encoded — `/`, `:` and `@` — is accepted in every HTML5 numeric
 * reference form, with the semicolon or without it, exactly when HTML5
 * would decode it: a semicolonless decimal reference only when no digit
 * follows, a semicolonless hex one only when no hex digit follows.
 *
 * Table-driven: {character} × {context} × {form} × {receiver}. The
 * expectation is derived from the character after the reference, so a
 * host or path that starts with a hex letter (`discord`, `canary`,
 * `api.telegram`, `abc…`) is a control for the semicolonless hex form —
 * except where the reference stands before the host: there the raw run
 * still holds the host, and stage 2 needs no left host boundary (#113, fix
 * round 4), so the whole run is replaced.
 *
 * Named references: HTML5 decodes only its legacy set without `;`
 * (`amp`, `lt`, `gt`, `quot`, `nbsp`, …). `sol`, `colon` and `commat` are
 * not in that set, so they are recognised only with `;` — see the
 * controls below.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactReferenceTableTest extends TestCase {

	/** char => [ decimal code, hex code ] */
	private const CHARS = array(
		'/' => array( '47', '2f' ),
		':' => array( '58', '3a' ),
		'@' => array( '64', '40' ),
	);

	/** name => [ host, path after the first slash, kind ] */
	private const RECEIVERS = array(
		'make hex path'      => array( 'hook.eu2.make.com', 'abc123secret', 'make' ),
		'make plain path'    => array( 'hook.eu2.make.com', 'zz123secret', 'make' ),
		'integromat digit'   => array( 'hook.integromat.com', '9secret', 'integromat' ),
		'zapier'             => array( 'hooks.zapier.com', 'hooks/catch/1/SECRET', 'zapier' ),
		'slack'              => array( 'hooks.slack.com', 'services/T/B/SECRET', 'slack' ),
		'discord'            => array( 'discord.com', 'api/webhooks/1/SECRET', 'discord' ),
		'canary discord'     => array( 'canary.discord.com', 'api/webhooks/1/SECRET', 'discord' ),
		'telegram'           => array( 'api.telegram.org', 'bot123:AAsecret/sendMessage', 'telegram' ),
		'ifttt'              => array( 'maker.ifttt.com', 'use/SECRET', 'ifttt' ),
	);

	protected function setUp(): void {
		sa_reset_state();
	}

	/** @return array<string,array{0:string,1:bool}> form name => [ reference, is hex ] */
	private static function forms( string $dec, string $hex ): array {
		$bare = array(
			'decimal'        => array( "&#{$dec}", false ),
			'decimal padded' => array( "&#00{$dec}", false ),
			'hex'            => array( "&#x{$hex}", true ),
			'hex upper X'    => array( '&#X' . strtoupper( $hex ), true ),
			'hex padded'     => array( "&#x00{$hex}", true ),
		);
		$out = array();
		foreach ( $bare as $name => $form ) {
			$out[ "{$name};" ]   = array( $form[0] . ';', $form[1] );
			$out[ "{$name} no;" ] = $form;
		}
		return $out;
	}

	/** Does HTML5 decode $ref when $next follows it? */
	private static function decodes( string $ref, bool $hex, string $next ): bool {
		if ( ';' === substr( $ref, -1 ) || '' === $next ) {
			return true;
		}
		return $hex ? ! ctype_xdigit( $next ) : ! ctype_digit( $next );
	}

	/** @return array<string,array{0:string,1:string}> input, expected */
	public static function table(): array {
		$cases = array();
		foreach ( self::RECEIVERS as $rname => list( $host, $path, $kind ) ) {
			$placeholder = 'aura-redacted:v1:' . $kind;
			foreach ( self::CHARS as $char => list( $dec, $hex ) ) {
				foreach ( self::forms( $dec, $hex ) as $fname => list( $ref, $is_hex ) ) {
					$contexts = array();
					if ( '/' === $char ) {
						// The slash that ends the host.
						$contexts['host end'] = array( "https://{$host}{$ref}{$path}", $path[0] );
						// Both scheme slashes; the second one precedes the host.
						$contexts['scheme'] = array( "https:{$ref}{$ref}{$host}/{$path}", $host[0] );
					}
					if ( ':' === $char ) {
						$contexts['scheme'] = array( "https{$ref}//{$host}/{$path}", '/' );
						// A port: a digit always follows, so only `;` decodes.
						$contexts['port'] = array( "https://{$host}{$ref}443/{$path}", '4' );
						if ( 'telegram' === $kind ) {
							foreach ( array( 'AAsecret', 'zzsecret', '9secret' ) as $token ) {
								$contexts[ "token {$token}" ] = array( "https://{$host}/bot123{$ref}{$token}/sendMessage", $token[0] );
							}
						}
					}
					if ( '@' === $char ) {
						$contexts['userinfo'] = array( "https://user{$ref}{$host}/{$path}", $host[0] );
					}
					// The character right before a bare host: a boundary.
					$contexts['boundary'] = array( "x{$ref}{$host}/{$path}", $host[0] );

					foreach ( $contexts as $cname => list( $in, $next ) ) {
						if ( ! self::decodes( $ref, $is_hex, $next ) ) {
							// HTML5 reads another character here: no receiver URL at the
							// host end or the port. Before the host, the encoded run's
							// raw layer holds the host, unbounded (#113, fix round 4).
							$expected = in_array( $cname, array( 'scheme', 'userinfo', 'boundary' ), true ) ? $placeholder : $in;
						} elseif ( 'boundary' === $cname ) {
							$expected = "x{$ref}{$placeholder}";
						} else {
							$expected = $placeholder;
						}
						$cases[ "{$rname} | {$char} {$cname} | {$fname}" ] = array( $in, $expected );
					}
				}
			}
		}
		return $cases;
	}

	/** @dataProvider table */
	public function test_every_reference_form_of_every_structural_character( string $in, string $expected ): void {
		$n   = 0;
		$out = Aura_Worker_Redact::redact_text( $in, $n );
		$this->assertSame( $expected, $out, $in );
		$this->assertSame( $in === $expected ? 0 : 1, $n );
	}

	/** @return array<string,array{0:string}> */
	public static function controls(): array {
		return array(
			// Not in HTML5's legacy no-semicolon set: not decoded without `;`.
			'sol without ;'     => array( 'hooks.zapier.com&solhooks/catch/1/SECRET' ),
			'commat without ;'  => array( 'https://user&commathooks.zapier.com/hooks/SECRET' ),
			'sol boundary'      => array( 'x&solhooks.zapier.com/hooks/SECRET' ),
			// A longer number is another character.
			'decimal 470'       => array( 'https://hooks.zapier.com&#470hooks/SECRET' ),
			'decimal 580 port'  => array( 'https://hooks.zapier.com&#580/hooks/SECRET' ),
			'hex 3aa token'     => array( 'https://api.telegram.org/bot1&#x3AAAsecret/sendMessage' ),
			// Not a receiver, in any form.
			'n8n all forms'     => array( 'https&#58&#47&#x2Fn8n.example.com&#47webhook&#X2F;abc' ),
		);
	}

	/**
	 * Kept only by the left host boundary; stage 2 needs none in an encoded
	 * run (#113, fix round 4).
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function left_boundary_flips(): array {
		return array(
			'prefixed host'      => array( 'x&#47myhooks.zapier.com/hooks/SECRET' ),
			'dot is no boundary' => array( 'x&#46hooks.zapier.com/hooks/SECRET' ),
		);
	}

	/** @dataProvider left_boundary_flips */
	public function test_encoded_runs_kept_only_by_the_left_boundary_are_redacted( string $in ): void {
		$n = 0;
		$this->assertSame( 'aura-redacted:v1:zapier', Aura_Worker_Redact::redact_text( $in, $n ) );
		$this->assertSame( 1, $n );
	}

	public function test_a_semicolonless_named_colon_is_not_a_scheme(): void {
		// `https&colon//…` is text, then a protocol-relative URL: only that is replaced.
		$n = 0;
		$this->assertSame( 'https&colon//aura-redacted:v1:zapier', Aura_Worker_Redact::redact_text( 'https&colon//hooks.zapier.com/hooks/SECRET', $n ) );
		$this->assertSame( 1, $n );
	}

	public function test_a_non_ascii_reference_before_a_host_is_a_boundary(): void {
		// #113: `&#640` is U+0280, not a hostname character, so the host
		// after it is at a boundary, as in plain text (was a control).
		$n = 0;
		$this->assertSame( 'aura-redacted:v1:zapier', Aura_Worker_Redact::redact_text( 'https://user&#640hooks.zapier.com/hooks/SECRET', $n ) );
		$this->assertSame( 1, $n );
	}

	/** @dataProvider controls */
	public function test_controls_stay_untouched( string $in ): void {
		$n = 0;
		$this->assertSame( $in, Aura_Worker_Redact::redact_text( $in, $n ) );
		$this->assertSame( 0, $n );
	}
}
