<?php
/**
 * MCP Tool: list_users
 *
 * Read-only listing of WordPress users with roles, for audits. Never returns
 * password hashes or secrets.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_List_Users extends Aura_Tool_Base {

	public function get_name() {
		return 'list_users';
	}

	public function get_description() {
		return 'Lists WordPress users with their roles, registration date, and post count, flagging administrators. Supports role filtering, search, and pagination. Read-only; never returns secrets.';
	}

	public function get_parameters() {
		return array(
			'role'   => array(
				'type'        => 'string',
				'description' => 'Filter by role slug (e.g. "administrator", "editor"). Optional.',
				'required'    => false,
			),
			'search' => array(
				'type'        => 'string',
				'description' => 'Search term matched against login, email, and display name. Optional.',
				'required'    => false,
			),
			'number' => array(
				'type'        => 'integer',
				'description' => 'Maximum users to return (default 50, max 200).',
				'required'    => false,
				'default'     => 50,
			),
			'offset' => array(
				'type'        => 'integer',
				'description' => 'Offset for pagination (default 0).',
				'required'    => false,
				'default'     => 0,
			),
		);
	}

	public function get_returns() {
		return array(
			'total'        => 'integer — total users matching the filter (ignoring pagination)',
			'admin_count'  => 'integer — total administrators on the site',
			'returned'     => 'integer — users in this response',
			'users'        => 'array — { id, login, display_name, email, roles, is_admin, registered, post_count }',
		);
	}

	public function execute( $params ) {
		$number = isset( $params['number'] ) ? max( 1, min( 200, (int) $params['number'] ) ) : 50;
		$offset = isset( $params['offset'] ) ? max( 0, (int) $params['offset'] ) : 0;

		$args = array(
			'number' => $number,
			'offset' => $offset,
			'fields' => 'all',
		);

		if ( ! empty( $params['role'] ) ) {
			$args['role'] = sanitize_text_field( $params['role'] );
		}
		if ( ! empty( $params['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( $params['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		// Total matching the filter, without pagination.
		$count_args           = $args;
		$count_args['number'] = -1;
		$count_args['offset'] = 0;
		$count_args['fields'] = 'ID';
		$total                = count( get_users( $count_args ) );

		$users  = get_users( $args );
		$result = array();
		foreach ( $users as $user ) {
			$roles      = (array) $user->roles;
			$result[]   = array(
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'roles'        => array_values( $roles ),
				'is_admin'     => in_array( 'administrator', $roles, true ),
				'registered'   => $user->user_registered,
				'post_count'   => (int) count_user_posts( $user->ID ),
			);
		}

		$admin_count = count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );

		return array(
			'total'        => $total,
			'admin_count'  => $admin_count,
			'returned'     => count( $result ),
			'users'        => $result,
			'generated_at' => gmdate( 'c' ),
		);
	}
}
