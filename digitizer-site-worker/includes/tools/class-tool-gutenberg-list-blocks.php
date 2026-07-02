<?php
/**
 * MCP tool: list the Gutenberg blocks on a post/page.
 *
 * Read-only structural view of a block-editor page — the discovery step before
 * an agent edits a block. Ends the "Elementor-only" gap: Gutenberg is core WP,
 * so this ships in the base plugin (wordpress.org-safe).
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Gutenberg_List_Blocks extends Aura_Tool_Base {

	public function get_name() {
		return 'list_page_blocks';
	}

	public function get_description() {
		return 'List the Gutenberg blocks on a post/page (index, block name, attributes) — the read step before editing a block.';
	}

	public function get_parameters() {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'description' => 'The post/page ID to inspect.',
				'required'    => true,
			),
		);
	}

	public function get_returns() {
		return array(
			'post_id'     => array( 'type' => 'integer' ),
			'block_count' => array( 'type' => 'integer' ),
			'blocks'      => array( 'type' => 'array' ),
		);
	}

	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		$post_id = (int) $params['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found: ' . $post_id );
		}

		$parsed = parse_blocks( (string) $post->post_content );
		$blocks = array();
		foreach ( $parsed as $i => $block ) {
			// parse_blocks emits null-name entries for the whitespace/freeform
			// between blocks; surface them so indexes line up with update_page_block.
			$blocks[] = array(
				'index'      => $i,
				'name'       => isset( $block['blockName'] ) ? $block['blockName'] : null,
				'attrs'      => isset( $block['attrs'] ) ? $block['attrs'] : array(),
				'has_inner'  => ! empty( $block['innerBlocks'] ),
			);
		}

		return array(
			'post_id'     => $post_id,
			'block_count' => count( $blocks ),
			'blocks'      => $blocks,
		);
	}
}
