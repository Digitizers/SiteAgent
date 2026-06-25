<?php
/**
 * MCP Tool: scan_seo
 *
 * Read-only SEO posture scan. Reports reliably-checkable, no-AI-cost findings —
 * indexability, permalinks, sitemap, title — plus a sampled content audit
 * (missing excerpts/featured images, thin content). Makes no changes.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Scan_Seo extends Aura_Tool_Base {

	public function get_name() {
		return 'scan_seo';
	}

	public function get_description() {
		return 'Runs a read-only SEO posture scan: search-engine visibility, permalink structure, site title, XML sitemap, and a sampled content audit (missing excerpts/featured images, thin content). Returns scored findings. Makes no changes.';
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
			'content_sample' => 'array — { audited, missing_excerpt, missing_featured_image, thin_content }',
		);
	}

	public function execute( $params ) {
		$sample = isset( $params['sample'] ) ? max( 1, min( 200, (int) $params['sample'] ) ) : 50;

		$findings = array();

		// 1. Search-engine visibility (Settings → Reading).
		$indexable  = 1 === (int) get_option( 'blog_public' );
		$findings[] = array(
			'check'   => 'search_engine_visibility',
			'status'  => $indexable ? 'ok' : 'fail',
			'message' => $indexable
				? 'Search engines are allowed to index the site.'
				: 'Site discourages search engines (Settings → Reading) — pages will not be indexed.',
		);

		// 2. Permalink structure.
		$pretty     = (bool) get_option( 'permalink_structure' );
		$findings[] = array(
			'check'   => 'permalinks',
			'status'  => $pretty ? 'ok' : 'warning',
			'message' => $pretty
				? 'Pretty permalinks are enabled.'
				: 'Plain permalinks (?p=123) are SEO-unfriendly; choose a pretty structure.',
		);

		// 3. Site title.
		$title      = trim( (string) get_bloginfo( 'name' ) );
		$findings[] = array(
			'check'   => 'site_title',
			'status'  => '' !== $title ? 'ok' : 'warning',
			'message' => '' !== $title ? 'Site title is set.' : 'Site title is empty.',
		);

		// 4. XML sitemap (WordPress core or a known SEO plugin).
		$core_sitemap = function_exists( 'wp_sitemaps_get_server' ) && (bool) apply_filters( 'wp_sitemaps_enabled', true );
		$seo_plugin   = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || class_exists( 'All_in_One_SEO_Pack' );
		$has_sitemap  = $core_sitemap || $seo_plugin;
		$findings[]   = array(
			'check'   => 'xml_sitemap',
			'status'  => $has_sitemap ? 'ok' : 'warning',
			'message' => $has_sitemap
				? 'An XML sitemap is available (WordPress core or an SEO plugin).'
				: 'No XML sitemap detected (core sitemaps disabled and no SEO plugin).',
		);

		// 5. Sampled content audit.
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
		$ids               = $query->posts;
		$audited           = count( $ids );
		$missing_excerpt   = 0;
		$missing_featured  = 0;
		$thin_content      = 0;
		foreach ( $ids as $id ) {
			if ( '' === trim( (string) get_post_field( 'post_excerpt', $id ) ) ) {
				++$missing_excerpt;
			}
			if ( ! has_post_thumbnail( $id ) ) {
				++$missing_featured;
			}
			$words = str_word_count( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ) );
			if ( $words < 300 ) {
				++$thin_content;
			}
		}

		$findings[] = array(
			'check'   => 'thin_content',
			'status'  => 0 === $thin_content ? 'ok' : 'warning',
			'message' => 0 === $thin_content
				? 'No thin content in the sample.'
				: "$thin_content of $audited sampled posts/pages have under 300 words.",
		);
		$findings[] = array(
			'check'   => 'featured_images',
			'status'  => 0 === $missing_featured ? 'ok' : 'warning',
			'message' => 0 === $missing_featured
				? 'All sampled posts/pages have a featured image.'
				: "$missing_featured of $audited sampled posts/pages lack a featured image.",
		);

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
				'missing_excerpt'        => $missing_excerpt,
				'missing_featured_image' => $missing_featured,
				'thin_content'           => $thin_content,
			),
			'note'           => 'Content audit reads post_content (classic/Gutenberg); page-builder content stored in postmeta is not inspected here.',
			'generated_at'   => gmdate( 'c' ),
		);
	}
}
