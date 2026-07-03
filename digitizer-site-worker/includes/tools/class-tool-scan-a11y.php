<?php
/**
 * MCP Tool: scan_a11y
 *
 * Read-only accessibility scan. Reports reliably-checkable, no-AI-cost findings
 * from sampled post content — images missing alt text, generic link text,
 * missing heading structure — plus the document language attribute. No changes.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Scan_A11y extends Aura_Tool_Base {

	public function get_name() {
		return 'scan_a11y';
	}

	public function get_description() {
		return 'Runs a read-only accessibility scan over a sample of published content: images missing alt text, non-descriptive link text (e.g. "click here"), missing heading structure, and the document language attribute. Returns scored findings. Makes no changes.';
	}

	public function get_parameters() {
		return array(
			'sample' => array(
				'type'        => 'integer',
				'description' => 'How many recent published posts/pages to audit (default 50, max 200).',
				'required'    => false,
				'default'     => 50,
			),
		);
	}

	public function get_returns() {
		return array(
			'score'          => 'integer — 0-100, higher is better',
			'passed'         => 'integer — number of checks passed',
			'total'          => 'integer — number of checks run',
			'findings'       => 'array — { check, status: ok|warning|fail, message }',
			'content_sample' => 'array — { audited, images, images_missing_alt, generic_links, posts_without_headings }',
		);
	}

	/**
	 * This is a read-only tool: it never mutates the site.
	 */
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		$sample = isset( $params['sample'] ) ? max( 1, min( 200, (int) $params['sample'] ) ) : 50;

		$generic_phrases = array( 'click here', 'read more', 'here', 'more', 'link', 'this', 'learn more' );

		$query = new WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $sample,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$ids                    = $query->posts;
		$audited                = count( $ids );
		$images                 = 0;
		$images_missing_alt     = 0;
		$generic_links          = 0;
		$posts_without_headings = 0;

		foreach ( $ids as $id ) {
			$content = (string) get_post_field( 'post_content', $id );

			// Images + alt text.
			if ( preg_match_all( '/<img\b[^>]*>/i', $content, $imgs ) ) {
				foreach ( $imgs[0] as $tag ) {
					++$images;
					// Require a non-empty alt value to count as present.
					if ( ! preg_match( '/\balt\s*=\s*("[^"]*\S[^"]*"|\'[^\']*\S[^\']*\')/i', $tag ) ) {
						++$images_missing_alt;
					}
				}
			}

			// Non-descriptive link text.
			if ( preg_match_all( '/<a\b[^>]*>(.*?)<\/a>/is', $content, $links ) ) {
				foreach ( $links[1] as $inner ) {
					$text = strtolower( trim( wp_strip_all_tags( $inner ) ) );
					if ( '' !== $text && in_array( $text, $generic_phrases, true ) ) {
						++$generic_links;
					}
				}
			}

			// Missing heading structure on a substantial post.
			$words = str_word_count( wp_strip_all_tags( $content ) );
			if ( $words > 150 && ! preg_match( '/<h[1-6]\b/i', $content ) ) {
				++$posts_without_headings;
			}
		}

		$findings = array();

		$findings[] = array(
			'check'   => 'image_alt_text',
			'status'  => 0 === $images_missing_alt ? 'ok' : ( $images_missing_alt > $images / 2 ? 'fail' : 'warning' ),
			'message' => 0 === $images_missing_alt
				? ( 0 === $images ? 'No images found in the sample.' : 'All sampled images have alt text.' )
				: "$images_missing_alt of $images sampled images are missing alt text.",
		);
		$findings[] = array(
			'check'   => 'descriptive_link_text',
			'status'  => 0 === $generic_links ? 'ok' : 'warning',
			'message' => 0 === $generic_links
				? 'No non-descriptive link text found in the sample.'
				: "$generic_links non-descriptive link(s) (e.g. \"click here\") found in the sample.",
		);
		$findings[] = array(
			'check'   => 'heading_structure',
			'status'  => 0 === $posts_without_headings ? 'ok' : 'warning',
			'message' => 0 === $posts_without_headings
				? 'Substantial sampled posts use headings.'
				: "$posts_without_headings substantial sampled post(s) have no headings.",
		);

		// Document language: inspect the RENDERED front page, not the configured
		// locale. A theme that omits language_attributes() ships <html> with no lang
		// attribute even though get_bloginfo('language') is set, so checking the
		// option alone gives a false pass. Fetch the home page and read the actual
		// <html ... lang="..."> attribute; fail gracefully if the fetch fails.
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 15, 'sslverify' => false ) );
		if ( is_wp_error( $response ) ) {
			$findings[] = array(
				'check'   => 'document_language',
				'status'  => 'warning',
				'message' => 'Could not verify the document language — fetching the home page failed (' . $response->get_error_message() . ').',
			);
		} else {
			$body      = (string) wp_remote_retrieve_body( $response );
			$html_lang = '';
			if ( preg_match( '/<html\b[^>]*\blang\s*=\s*("[^"]*"|\'[^\']*\')/i', $body, $m ) ) {
				$html_lang = trim( $m[1], "\"'" );
			}
			$findings[] = array(
				'check'   => 'document_language',
				'status'  => '' !== $html_lang ? 'ok' : 'warning',
				'message' => '' !== $html_lang
					? "Document language is set ($html_lang)."
					: 'No lang attribute on the rendered <html> tag — the theme may be missing language_attributes().',
			);
		}

		$total  = count( $findings );
		$passed = 0;
		foreach ( $findings as $f ) {
			if ( 'ok' === $f['status'] ) {
				++$passed;
			}
		}

		return array(
			'score'          => $total > 0 ? (int) round( ( $passed / $total ) * 100 ) : 100,
			'passed'         => $passed,
			'total'          => $total,
			'findings'       => $findings,
			'content_sample' => array(
				'audited'                => $audited,
				'images'                 => $images,
				'images_missing_alt'     => $images_missing_alt,
				'generic_links'          => $generic_links,
				'posts_without_headings' => $posts_without_headings,
			),
			'note'           => 'Reads post_content (classic/Gutenberg); page-builder content stored in postmeta is not inspected here. The document_language check fetches the rendered home page to read the actual <html lang> attribute.',
			'generated_at'   => gmdate( 'c' ),
		);
	}
}
