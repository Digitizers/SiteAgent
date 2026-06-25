<?php
/**
 * MCP Tool: scan_broken_links
 *
 * Read-only link triage over a sample of published content. Does NO outbound
 * HTTP — it resolves internal links locally (url_to_postid) and flags structural
 * problems (empty/anchor-only links, links to dev/staging hosts). Cheap and safe.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Scan_Broken_Links extends Aura_Tool_Base {

	public function get_name() {
		return 'scan_broken_links';
	}

	public function get_description() {
		return 'Triages links across a sample of published content WITHOUT any outbound HTTP: flags empty/anchor-only links, links pointing at dev/staging hosts, and internal links that do not resolve to a known post/page. Returns counts and samples. Makes no changes.';
	}

	public function get_parameters() {
		return array(
			'sample' => array(
				'type'        => 'integer',
				'description' => 'How many recent published posts/pages to scan (default 50, max 200).',
				'required'    => false,
				'default'     => 50,
			),
		);
	}

	public function get_returns() {
		return array(
			'scanned'             => 'integer — posts/pages scanned',
			'links_total'         => 'integer — links inspected',
			'empty_or_anchor'     => 'array — { count, samples }',
			'dev_staging_links'   => 'array — { count, samples }',
			'unresolved_internal' => 'array — { count, samples } — internal links not resolving to a known post/page (review; may include custom routes)',
		);
	}

	public function execute( $params ) {
		$sample = isset( $params['sample'] ) ? max( 1, min( 200, (int) $params['sample'] ) ) : 50;

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		$dev_markers = array( 'localhost', '127.0.0.1', '.local', '.test', 'staging.', 'stg.', 'dev.', ':8080', ':8888' );

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
		$ids     = $query->posts;
		$scanned = count( $ids );

		$links_total      = 0;
		$empty_anchor     = array();
		$dev_links        = array();
		$unresolved       = array();
		$sample_cap       = 10;

		foreach ( $ids as $id ) {
			$content = (string) get_post_field( 'post_content', $id );
			if ( ! preg_match_all( '/<a\b[^>]*\bhref\s*=\s*("[^"]*"|\'[^\']*\')/i', $content, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $raw ) {
				$href = trim( $raw, "\"'" );
				++$links_total;

				// Empty or anchor-only.
				if ( '' === $href || '#' === $href ) {
					if ( count( $empty_anchor ) < $sample_cap ) {
						$empty_anchor[] = array( 'post_id' => $id, 'href' => $href );
					}
					continue;
				}

				// mailto/tel/javascript — not link targets to validate.
				if ( preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $href ) ) {
					continue;
				}

				// Dev/staging host.
				$lower = strtolower( $href );
				foreach ( $dev_markers as $marker ) {
					if ( false !== strpos( $lower, $marker ) ) {
						if ( count( $dev_links ) < $sample_cap ) {
							$dev_links[] = array( 'post_id' => $id, 'href' => $href );
						}
						continue 2;
					}
				}

				// Internal links: resolve locally (no HTTP).
				$host = wp_parse_url( $href, PHP_URL_HOST );
				$is_internal = empty( $host ) || $host === $home_host;
				if ( ! $is_internal ) {
					continue; // External — not checked (no outbound HTTP).
				}

				$path = (string) wp_parse_url( $href, PHP_URL_PATH );
				// Skip non-post routes that url_to_postid can't resolve but are valid.
				if ( '' === $path || '/' === $path ) {
					continue;
				}
				if ( preg_match( '#/(wp-admin|wp-login|wp-json|feed|category|tag|author|page)/#i', $path ) ) {
					continue;
				}
				if ( preg_match( '#\.(jpg|jpeg|png|gif|svg|webp|pdf|zip|docx?|xlsx?)$#i', $path ) ) {
					continue; // File/media link, not a post.
				}

				if ( 0 === (int) url_to_postid( $href ) ) {
					if ( count( $unresolved ) < $sample_cap ) {
						$unresolved[] = array( 'post_id' => $id, 'href' => $href );
					}
				}
			}
		}

		return array(
			'scanned'             => $scanned,
			'links_total'         => $links_total,
			'empty_or_anchor'     => array( 'count' => count( $empty_anchor ), 'samples' => $empty_anchor ),
			'dev_staging_links'   => array( 'count' => count( $dev_links ), 'samples' => $dev_links ),
			'unresolved_internal' => array( 'count' => count( $unresolved ), 'samples' => $unresolved ),
			'note'               => 'No outbound HTTP performed; external links are not validated. Internal links resolved via url_to_postid — custom routes may appear unresolved.',
			'generated_at'       => gmdate( 'c' ),
		);
	}
}
