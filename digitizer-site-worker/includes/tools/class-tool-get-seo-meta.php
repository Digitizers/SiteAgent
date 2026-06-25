<?php
/**
 * MCP Tool: get_seo_meta
 *
 * Read a post/page's SEO meta (title, description, focus keyword) directly from
 * the active SEO plugin's post meta — Rank Math, Yoast, or SEOPress. Runs on-site
 * so it is immune to WAF/REST restrictions that block the plugins' own endpoints.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'aura_seo_meta_fields' ) ) {
	/**
	 * Map the active SEO plugin to its post-meta keys.
	 *
	 * @return array|null { plugin, title, description, focus_keyword } or null if none supported.
	 */
	function aura_seo_meta_fields() {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return array(
				'plugin'        => 'rankmath',
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
			);
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return array(
				'plugin'        => 'yoast',
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
			);
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return array(
				'plugin'        => 'seopress',
				'title'         => '_seopress_titles_title',
				'description'   => '_seopress_titles_desc',
				'focus_keyword' => '_seopress_analysis_target_kw',
			);
		}
		return null;
	}
}

class Aura_Tool_Get_Seo_Meta extends Aura_Tool_Base {

	public function get_name() {
		return 'get_seo_meta';
	}

	public function get_description() {
		return 'Reads a post/page SEO meta (title, description, focus keyword) from the active SEO plugin (Rank Math, Yoast, or SEOPress). Read-only; runs on-site so it works even when a WAF blocks the SEO plugin\'s own REST endpoints.';
	}

	public function get_parameters() {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'description' => 'The post/page ID to read SEO meta from.',
				'required'    => true,
			),
		);
	}

	public function get_returns() {
		return array(
			'plugin'        => 'string|null — rankmath|yoast|seopress, or null if no supported SEO plugin is active',
			'post_id'       => 'integer',
			'title'         => 'string — SEO meta title (may be empty)',
			'description'   => 'string — SEO meta description (may be empty)',
			'focus_keyword' => 'string — primary focus keyword (may be empty)',
		);
	}

	public function execute( $params ) {
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array( 'error' => 'Post not found.' );
		}

		$fields = aura_seo_meta_fields();
		if ( ! $fields ) {
			return array(
				'plugin'    => null,
				'post_id'   => $post_id,
				'supported' => false,
				'message'   => 'No supported SEO plugin is active (Rank Math, Yoast, or SEOPress).',
			);
		}

		return array(
			'plugin'        => $fields['plugin'],
			'post_id'       => $post_id,
			'title'         => (string) get_post_meta( $post_id, $fields['title'], true ),
			'description'   => (string) get_post_meta( $post_id, $fields['description'], true ),
			'focus_keyword' => (string) get_post_meta( $post_id, $fields['focus_keyword'], true ),
		);
	}
}
