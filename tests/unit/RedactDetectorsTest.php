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

/** An ArrayObject subclass with a public property of its own. */
final class SA_Redact_Array_Object_Probe extends ArrayObject {
	/** @var string Emitted by json_encode only under STD_PROP_LIST. */
	public $pub = 'plain';
}

/** A JsonSerializable whose serialization is itself — json_encode would recurse without end. */
final class SA_Redact_Self_Serializing_Probe implements JsonSerializable {
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this;
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
			'telegram file'       => array( 'https://api.telegram.org/file/bot123456:AA-bb_cc/photos/file_1.jpg', 'telegram' ),
			'slack triggers'      => array( 'https://hooks.slack.com/triggers/T000/1234/abcdef', 'slack' ),
			'slack workflows'     => array( 'https://hooks.slack.com/workflows/T000/A000/1234/abcdef', 'slack' ),
			'make celonis'        => array( 'https://hook.eu1.make.celonis.com/abc123def456', 'make' ),
			'integromat regional' => array( 'https://hook.eu1.integromat.com/abc123', 'integromat' ),
			'zapier standard'     => array( 'https://hooks.zapier.com/hooks/standard/123/abc/', 'zapier' ),
			'discord versioned'   => array( 'https://discord.com/api/v10/webhooks/123/tok-en', 'discord' ),
			'discord ptb'         => array( 'https://ptb.discord.com/api/webhooks/123/tok-en', 'discord' ),
			'discordapp canary'   => array( 'https://canary.discordapp.com/api/v9/webhooks/123/tok-en', 'discord' ),
			// Fix round 1 (Codex r1 P3): a trailing FQDN dot is still the same host.
			'zapier trailing dot' => array( 'https://hooks.zapier.com./hooks/catch/1/abc', 'zapier' ),
			// Fix round 1 (Codex r1 P3): the token ends at the end of the URL / a query string, not only at a path slash.
			'telegram bare'       => array( 'https://api.telegram.org/bot123:AAbb', 'telegram' ),
			'telegram bare query' => array( 'https://api.telegram.org/bot123:AAbb?x=1', 'telegram' ),
			// Fix round 1 (owner decision): a receiver URL written without a
			// scheme is still redacted — bare and protocol-relative — as
			// long as the host starts at a genuine boundary. Flips the old
			// lookalikes['no scheme'] case into a positive one.
			'zapier bare'              => array( 'hooks.zapier.com/hooks/catch/1/', 'zapier' ),
			'zapier protocol-relative' => array( '//hooks.zapier.com/hooks/catch/1/', 'zapier' ),
			// PR #111 Codex r2 P2: GovSlack's hook host, same paths and rules.
			'govslack services'          => array( 'https://hooks.slack-gov.com/services/T000/B000/XXXX', 'slack' ),
			'govslack triggers'          => array( 'https://hooks.slack-gov.com/triggers/T000/1234/abcdef', 'slack' ),
			'govslack workflows'         => array( 'https://hooks.slack-gov.com/workflows/T000/A000/1234/abcdef', 'slack' ),
			'govslack bare'              => array( 'hooks.slack-gov.com/services/T000/B000/XXXX', 'slack' ),
			'govslack protocol-relative' => array( '//hooks.slack-gov.com/triggers/T000/1234/abcdef', 'slack' ),
			'govslack trailing dot'      => array( 'https://hooks.slack-gov.com./workflows/T000/A000/1/x', 'slack' ),
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
			'slack help'        => array( 'https://hooks.slack.com/help/articles/1' ),
			'slack triggersx'   => array( 'https://hooks.slack.com/triggersx/T000/1' ),
			'discord api other' => array( 'https://discord.com/api/v10/channels/1/messages' ),
			'discord evil sub'  => array( 'https://evil.discord.com/api/webhooks/1/x' ),
			'celonis non-make'  => array( 'https://hook.eu1.celonis.com/abc' ),
			'discord non-hook'  => array( 'https://discord.com/channels/1/2' ),
			'make marketing'    => array( 'https://www.make.com/en/pricing' ),
			'ifttt non-hook'    => array( 'https://maker.ifttt.com/trigger/form_sent/without/key/abc' ),
			'ifttt other host'  => array( 'https://ifttt.com/maker_webhooks/settings' ),
			'telegram no token' => array( 'https://api.telegram.org/botfather/x' ),
			'self-hosted n8n'   => array( self::N8N ),
			// Fix round 1 (owner decision): the schemeless forms must keep
			// the same host-boundary discipline as the schemed ones.
			'bare prefixed host'      => array( 'myhooks.zapier.com/hooks/catch/1/' ),
			'bare evil prefixed host' => array( 'evilhooks.zapier.com/hooks/catch/1/' ),
			'bare suffixed host'      => array( 'hooks.zapier.com.evil.tld/hooks/catch/1/' ),
			'protocol-relative prefixed host' => array( '//myhooks.zapier.com/hooks/catch/1/' ),
			'protocol-relative suffixed host' => array( '//hooks.zapier.com.evil.tld/hooks/catch/1/' ),
			'govslack suffixed host'          => array( 'https://hooks.slack-gov.com.evil.tld/services/T/B/X' ),
			'govslack prefixed host'          => array( 'https://xhooks.slack-gov.com/services/T/B/X' ),
			'govslack bare prefixed host'     => array( 'xhooks.slack-gov.com/services/T/B/X' ),
			'govslack bare suffixed host'     => array( 'hooks.slack-gov.com.evil.tld/services/T/B/X' ),
			'govslack lookalike label'        => array( 'https://hooks.slack-govx.com/services/T/B/X' ),
			'govslack non-hook path'          => array( 'https://hooks.slack-gov.com/help/articles/1' ),
		);
	}

	/** @dataProvider lookalikes */
	public function test_lookalikes_are_not_caught( string $text ): void {
		$this->assertSame( $text, $this->text( $text, $n ) );
		$this->assertSame( 0, $n );
	}

	/**
	 * Fix round 1 (owner decision): the host boundary is a fixed-width
	 * negative lookbehind and RE_TAIL is possessive, so making the scheme
	 * optional must not open a catastrophic-backtracking path — a long
	 * near-miss (no scheme, so the host literal is attempted at every
	 * position) must still run in effectively linear time.
	 */
	public function test_a_long_near_miss_input_does_not_time_out(): void {
		$text  = str_repeat( 'xhooks.zapier.com/a evil.hooks.zapier.com/a ', 20000 );
		$start = microtime( true );
		$out   = $this->text( $text, $n );
		$this->assertLessThan( 2.0, microtime( true ) - $start, 'a near-miss host repeated thousands of times must not blow up' );
		$this->assertSame( $text, $out );
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

	// --- #121: an empty port after a receiver host is a port -------------

	/** name => [ host, path after the host's slash, kind, secret ] — one per URL_PATTERNS entry (copied from RedactUrlReadingTest::RECEIVERS, #121). */
	private const EMPTY_PORT_RECEIVERS = array(
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

	/**
	 * #121: RE_HOST_END's port is now possessive `[0-9]*+` — an EMPTY port
	 * (`host:/path`) is a port for a URL parser, so stage 1 now redacts
	 * plain text carrying one, with no `%`, `&`, `\` or non-ASCII byte
	 * needed to reach stage 2. Five forms per receiver: a full URL, a bare
	 * (schemeless) host, a trailing FQDN dot before the empty port, the
	 * empty port followed by an ENCODED slash (RE_SLASH already accepts
	 * `%2F` — only the slash right after the colon is encoded, so this is
	 * simple to build for every receiver), and inside prose.
	 *
	 * @return array<string,array{0:string,1:string,2:string}> input, expected output, secret
	 */
	public static function empty_port_cases(): array {
		$cases = array();
		foreach ( self::EMPTY_PORT_RECEIVERS as $name => $r ) {
			list( $host, $path, $kind, $secret ) = $r;
			$expect = 'aura-redacted:v1:' . $kind;
			$plain  = "https://{$host}:/{$path}";

			$cases[ "{$name} / schemed" ]       = array( $plain, $expect, $secret );
			$cases[ "{$name} / bare" ]          = array( "{$host}:/{$path}", $expect, $secret );
			$cases[ "{$name} / trailing dot" ]  = array( "{$host}.:/{$path}", $expect, $secret );
			$cases[ "{$name} / encoded slash" ] = array( "https://{$host}:%2F{$path}", $expect, $secret );
			$cases[ "{$name} / prose" ]         = array( "see {$plain} now", "see {$expect} now", $secret );
		}
		return $cases;
	}

	/** @dataProvider empty_port_cases */
	public function test_an_empty_port_after_a_receiver_host_is_a_port( string $in, string $expected, string $secret ): void {
		$out = $this->text( $in, $n );
		$this->assertStringNotContainsString( $secret, $out, $in );
		$this->assertSame( $expected, $out, $in );
		$this->assertSame( 1, $n, $in );
	}

	/**
	 * #121: a colon after a receiver host is a port only when zero or more
	 * DIGITS follow it and a slash follows those digits directly — anything
	 * else leaves RE_HOST_END with no match at that position, exactly as
	 * before this fix. `[0-9]*+` is possessive: once it has consumed every
	 * digit, it never gives one back to let RE_SLASH match earlier.
	 *
	 * @return array<string,array{0:string}> input
	 */
	public static function colon_not_a_port_cases(): array {
		return array(
			'letter, no slash right after' => array( 'https://hooks.zapier.com:evil/x' ),
			// Digits, but no slash right after them — and nothing for the
			// possessive quantifier to give back.
			'digits, no slash after them'  => array( 'hooks.zapier.com:8443x' ),
			// End of text: no RE_SLASH can follow at all.
			'colon at the end of the text' => array( 'hooks.zapier.com:' ),
			// A space, not a slash, follows the colon.
			'space after the colon'        => array( 'hooks.zapier.com: /hooks' ),
		);
	}

	/** @dataProvider colon_not_a_port_cases */
	public function test_a_colon_that_is_not_a_port_is_kept( string $in ): void {
		$this->assertSame( $in, $this->text( $in, $n ), $in );
		$this->assertSame( 0, $n, $in );
	}

	/** Control: a real (digit) port still works — the fix did not break the existing case. */
	public function test_a_real_port_still_works(): void {
		$out = $this->text( 'https://hooks.zapier.com:8443/hooks/catch/1/S', $n );
		$this->assertSame( 'aura-redacted:v1:zapier', $out );
		$this->assertSame( 1, $n );
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

	// --- fix round 1 (Codex r1 P1): 'content' is not a fail-open key name -

	/** An answer shaped like snapshot_get's, holding a webhooks secret in its payload. */
	private function snapshot_answer_with_secret( string $snapshot_id ): array {
		$captured = array( 'extra' => array( 'webhooks' => self::N8N ) );
		return array(
			'found'   => true,
			'record'  => array( 'id' => $snapshot_id ),
			'payload' => base64_encode( serialize( $captured ) ),
		);
	}

	public function test_a_snapshot_answer_under_an_associative_content_key_is_still_redacted(): void {
		// Before the fix, 'content' === $key short-circuited into the
		// MCP-list loop for ANY array value, so an associative array here —
		// this answer's { found, record, payload } — never reached
		// is_snapshot_answer() and its payload went out unredacted (n=0).
		$answer = $this->snapshot_answer_with_secret( 'snap_content' );

		$out = Aura_Worker_Redact::redact( array( 'content' => $answer ), $n );

		$this->assertGreaterThanOrEqual( 1, $n );
		$this->assertIsString( $out['content']['payload'] );
		$bytes = (string) base64_decode( $out['content']['payload'], true );
		$this->assertStringNotContainsString( 'n8n.example.com', $bytes );
		$back = unserialize( $bytes, array( 'allowed_classes' => false ) );
		$this->assertSame( 'aura-redacted:v1:field', $back['extra']['webhooks'] );
	}

	public function test_a_list_valued_content_still_takes_the_mcp_text_carrier_path(): void {
		// The list-only guard must not break the ordinary MCP carrier.
		$out = Aura_Worker_Redact::redact( $this->mcp_result( 'see ' . self::N8N . ' https://hooks.zapier.com/hooks/catch/1/' ), $n );
		$this->assertSame( 1, $n );
		$this->assertSame( 'see ' . self::N8N . ' aura-redacted:v1:zapier', $out['result']['content'][0]['text'] );
	}

	public function test_a_snapshot_answer_stored_as_an_array_under_elementor_data_is_still_redacted(): void {
		// Same gap, reached through walk_with_carrier(): '_elementor_data'
		// decoded into an ARRAY (not a string) is normally { existed, value }
		// (R1), but when it is itself a snapshot answer the container must
		// be checked for that shape before its fields are walked one by one.
		$answer = $this->snapshot_answer_with_secret( 'snap_elementor' );

		$out = Aura_Worker_Redact::redact( array( '_elementor_data' => $answer ), $n );

		$this->assertGreaterThanOrEqual( 1, $n );
		$this->assertIsString( $out['_elementor_data']['payload'] );
		$bytes = (string) base64_decode( $out['_elementor_data']['payload'], true );
		$this->assertStringNotContainsString( 'n8n.example.com', $bytes );
		$back = unserialize( $bytes, array( 'allowed_classes' => false ) );
		$this->assertSame( 'aura-redacted:v1:field', $back['extra']['webhooks'] );
	}

	public function test_an_ordinary_elementor_data_meta_shape_is_unaffected_by_the_fix(): void {
		// { existed, value } must still go through redact_carrier() on 'value'.
		$post = array( 'meta' => array( '_elementor_data' => array( 'existed' => true, 'value' => wp_json_encode( $this->form_tree( self::N8N ) ) ) ) );
		$out  = Aura_Worker_Redact::redact( $post, $n );
		$this->assertSame( 1, $n );
		$this->assertTrue( $out['meta']['_elementor_data']['existed'] );
		$tree = json_decode( $out['meta']['_elementor_data']['value'] );
		$this->assertSame( 'aura-redacted:v1:field', $tree[0]->elements[0]->settings->webhooks );
	}

	// --- fix round 1 (Codex r1 P4) ------------------------------------------

	public function test_an_opaque_object_naming_a_content_property_fails_the_payload_closed(): void {
		// 'content' — the MCP text-carrier key — must be checked the same
		// way SECRET_KEYS and JSON_META_KEYS are: an opaque object cannot be
		// unpacked to prove a nested text carrier clean. The webhook here is
		// a self-hosted (unlisted-host) URL, so neither the raw-bytes URL
		// scan nor the pre-fix key list would have caught it — the secret
		// would have gone out unredacted inside the otherwise-untouched
		// payload instead of the payload failing closed.
		$probe  = new SA_Redact_Unserialize_Probe( array( 'content' => self::N8N ) );
		$bytes  = serialize( $probe );
		$this->assertStringContainsString( 's:7:"content";', $bytes, 'fixture: the key is literally "content"' );
		$answer = array(
			'found'   => true,
			'record'  => array( 'id' => 'snap_opaque_content' ),
			'payload' => base64_encode( serialize( array( 'extra' => $probe ) ) ),
		);

		$out = Aura_Worker_Redact::redact( $answer, $n );

		$this->assertSame( 1, $n );
		$this->assertNull( $out['payload'] );
		$this->assertTrue( $out['payload_redacted'] );
		$this->assertArrayNotHasKey( '_sa_redact_probe_woke', $GLOBALS, 'no class was instantiated' );
	}

	// --- ArrayObject / ArrayIterator (final review minor 5) -------------------

	public function test_an_array_object_is_walked_as_json_encode_emits_its_storage(): void {
		$node = array( 'settings' => new ArrayObject( array( 'webhooks' => self::N8N, 'form_name' => 'Contact' ) ) );
		$this->assertStringContainsString( 'n8n.example.com', (string) wp_json_encode( $node ), 'fixture: json_encode emits the storage' );

		$out = Aura_Worker_Redact::redact( $node, $n );

		$this->assertSame( 1, $n );
		$this->assertSame( '{"settings":{"webhooks":"aura-redacted:v1:field","form_name":"Contact"}}', wp_json_encode( $out ), 'the same JSON shape, the value replaced' );
	}

	public function test_an_array_iterator_holding_a_list_keeps_its_object_shape(): void {
		$node = new ArrayIterator( array( 'see https://hooks.zapier.com/hooks/catch/1/abc', 'plain' ) );
		$this->assertSame( '{"0":"see https:\\/\\/hooks.zapier.com\\/hooks\\/catch\\/1\\/abc","1":"plain"}', wp_json_encode( $node ), 'fixture' );

		$out = Aura_Worker_Redact::redact( $node, $n );

		$this->assertSame( 1, $n );
		$this->assertSame( '{"0":"see aura-redacted:v1:zapier","1":"plain"}', wp_json_encode( $out ) );
	}

	public function test_an_array_object_under_std_prop_list_is_walked_through_its_properties(): void {
		$node      = new SA_Redact_Array_Object_Probe( array( 'a' => 1 ), ArrayObject::STD_PROP_LIST );
		$node->pub = self::N8N . ' https://hooks.zapier.com/hooks/catch/1/abc';
		$this->assertStringContainsString( 'hooks.zapier.com', (string) wp_json_encode( $node ), 'fixture: json_encode emits the properties' );

		$out = Aura_Worker_Redact::redact( $node, $n );

		$this->assertSame( 1, $n );
		$this->assertStringNotContainsString( 'hooks.zapier.com', (string) wp_json_encode( $out ) );
	}

	public function test_a_clean_array_object_is_returned_as_is(): void {
		$node = new ArrayObject( array( 'webhooks' => '', 'n' => array( 1, 2 ) ) );
		$this->assertSame( $node, Aura_Worker_Redact::redact( $node, $n ) );
		$this->assertSame( 0, $n );
	}

	// --- nesting bound (final review minor 9) ---------------------------------

	public function test_a_self_referencing_object_fails_closed_instead_of_recursing_forever(): void {
		$node       = new stdClass();
		$node->name = 'loop';
		$node->self = $node;

		$out = Aura_Worker_Redact::redact( $node, $n );

		$this->assertGreaterThan( 0, $n );
		$leaf = $out;
		for ( $i = 0; $i < Aura_Worker_Redact::MAX_WALK_DEPTH && $leaf instanceof stdClass; $i++ ) {
			$leaf = $leaf->self;
		}
		$this->assertSame( 'aura-redacted:v1:field', $leaf, 'the subtree past the bound is the field placeholder' );
	}

	public function test_a_self_referencing_carrier_container_fails_closed(): void {
		$node                  = new stdClass();
		$node->_elementor_data = $node; // walk_with_carrier() recursing into itself

		Aura_Worker_Redact::redact( array( 'meta' => $node ), $n );

		$this->assertGreaterThan( 0, $n );
	}

	public function test_a_json_serializable_that_serializes_to_itself_fails_closed(): void {
		$out = Aura_Worker_Redact::redact( array( 'x' => new SA_Redact_Self_Serializing_Probe() ), $n );
		$this->assertGreaterThan( 0, $n );
		$this->assertSame( 'aura-redacted:v1:field', $out['x'] );
	}

	private function nest( int $levels, $leaf ) {
		$node = $leaf;
		for ( $i = 0; $i < $levels; $i++ ) {
			$node = array( 'k' => $node );
		}
		return $node;
	}

	public function test_nesting_past_the_bound_fails_closed_and_within_it_is_untouched(): void {
		$within = $this->nest( Aura_Worker_Redact::MAX_WALK_DEPTH, 'plain' );
		$this->assertSame( $within, Aura_Worker_Redact::redact( $within, $n ) );
		$this->assertSame( 0, $n, 'a clean tree at the bound is left alone' );

		$past = $this->nest( Aura_Worker_Redact::MAX_WALK_DEPTH + 1, 'plain' );
		$out  = Aura_Worker_Redact::redact( $past, $n );
		$this->assertSame( 1, $n );
		$this->assertSame( $this->nest( Aura_Worker_Redact::MAX_WALK_DEPTH, 'aura-redacted:v1:field' ), $out );

		// The bound is per path, not per call: a second walk starts from zero.
		$this->assertSame( $within, Aura_Worker_Redact::redact( $within, $n ) );
		$this->assertSame( 0, $n );
	}
}
