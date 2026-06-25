<?php
/**
 * MCP Tool: set_seo_meta (write)
 *
 * Write a post/page's SEO meta (title, description, focus keyword) directly to
 * the active SEO plugin's post meta — Rank Math, Yoast, or SEOPress. Runs on-site
 * via update_post_meta, so it works even when a WAF blocks the plugin's own REST
 * endpoint. Only the provided fields are changed. Governed (approval-gated) by
 * Aura through the verb-led name.
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

class Aura_Tool_Set_Seo_Meta extends Aura_Tool_Base {

	public function get_name() {
		return 'set_seo_meta';
	}

	public function get_description() {
		return 'Sets a post/page SEO meta (title, description, and/or focus keyword) on the active SEO plugin (Rank Math, Yoast, or SEOPress). Only the fields you pass are changed. Runs on-site, so it succeeds even when a WAF blocks the plugin\'s own REST endpoint.';
	}

	public function get_parameters() {
		return array(
			'post_id'       => array(
				'type'        => 'integer',
				'description' => 'The post/page ID to update.',
				'required'    => true,
			),
			'title'         => array(
				'type'        => 'string',
				'description' => 'SEO meta title. Omit to leave unchanged.',
				'required'    => false,
			),
			'description'   => array(
				'type'        => 'string',
				'description' => 'SEO meta description. Omit to leave unchanged.',
				'required'    => false,
			),
			'focus_keyword' => array(
				'type'        => 'string',
				'description' => 'Primary focus keyword. Omit to leave unchanged.',
				'required'    => false,
			),
		);
	}

	public function get_returns() {
		return array(
			'plugin'  => 'string — rankmath|yoast|seopress',
			'post_id' => 'integer',
			'updated' => 'array — the field names that were written',
		);
	}

	public function execute( $params ) {
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array( 'error' => 'Post not found.' );
		}

		$fields = aura_seo_meta_fields();
		if ( ! $fields ) {
			return array( 'error' => 'No supported SEO plugin is active (Rank Math, Yoast, or SEOPress).' );
		}

		$updated = array();

		if ( isset( $params['title'] ) ) {
			update_post_meta( $post_id, $fields['title'], sanitize_text_field( (string) $params['title'] ) );
			$updated[] = 'title';
		}
		if ( isset( $params['description'] ) ) {
			update_post_meta( $post_id, $fields['description'], sanitize_textarea_field( (string) $params['description'] ) );
			$updated[] = 'description';
		}
		if ( isset( $params['focus_keyword'] ) ) {
			update_post_meta( $post_id, $fields['focus_keyword'], sanitize_text_field( (string) $params['focus_keyword'] ) );
			$updated[] = 'focus_keyword';
		}

		if ( empty( $updated ) ) {
			return array( 'error' => 'Nothing to update — provide title, description, and/or focus_keyword.' );
		}

		return array(
			'plugin'  => $fields['plugin'],
			'post_id' => $post_id,
			'updated' => $updated,
		);
	}
}
