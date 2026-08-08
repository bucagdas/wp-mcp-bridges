<?php
/**
 * User CRUD abilities.
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Users {

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/list-users',
			array(
				'label'               => __( 'List users', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists site users. Filter by role and/or search term (matches login, email, display name). Never returns passwords or password hashes.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'role'     => array(
							'type'        => 'string',
							'description' => 'Filter by role, e.g. "administrator".',
						),
						'search'   => array(
							'type'        => 'string',
							'description' => 'Search term.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum users to return. Default 20.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "total" and "users" (array of {id, login, display_name, email, roles, registered}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( __CLASS__, 'permission_list' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-user',
			array(
				'label'               => __( 'Get a user', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns one user\'s profile: login, display name, email, roles, registration date, bio, website URL. Never returns the password hash.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'User id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'User profile.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( __CLASS__, 'permission_read_user' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/create-user',
			array(
				'label'               => __( 'Create a user', 'wp-core-mcp-ability' ),
				'description'         => __( 'Creates a new user. user_login and user_email are required; a strong random password is generated automatically (never accepted as input, never returned) and WordPress emails the user a password-reset link — same as creating a user from wp-admin. role defaults to the site\'s default role.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_login'   => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Login username.',
						),
						'user_email'   => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Email address.',
						),
						'display_name' => array(
							'type'        => 'string',
							'description' => 'Display name. Defaults to user_login.',
						),
						'role'         => array(
							'type'        => 'string',
							'description' => 'Role to assign. Defaults to the site default role.',
						),
					),
					'required'             => array( 'user_login', 'user_email' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created user.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( __CLASS__, 'permission_create' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-user',
			array(
				'label'               => __( 'Update a user profile', 'wp-core-mcp-ability' ),
				'description'         => __( 'Updates profile fields of a user: display_name, email, description (bio), url (website). Does not change the password or role — use update-user-role for roles. Returns {old,new} per changed field.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'           => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'User id.',
						),
						'display_name' => array( 'type' => 'string', 'minLength' => 1 ),
						'email'        => array( 'type' => 'string', 'minLength' => 1 ),
						'description'  => array( 'type' => 'string' ),
						'url'          => array( 'type' => 'string' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_user' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-user-role',
			array(
				'label'               => __( 'Change a user\'s role', 'wp-core-mcp-ability' ),
				'description'         => __( 'Replaces a user\'s role(s) with the given role. Kept separate from update-user because role changes are a distinct, higher-privilege operation (requires promote_users). Returns {old,new} roles.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'User id.',
						),
						'role' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'New role, e.g. "editor".',
						),
					),
					'required'             => array( 'id', 'role' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" and "new" (role arrays).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_role' ),
				'permission_callback' => array( __CLASS__, 'permission_promote' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/delete-user',
			array(
				'label'               => __( 'Delete a user', 'wp-core-mcp-ability' ),
				'description'         => __( 'Permanently deletes a user. Requires confirm: true. reassign (user id) optionally reassigns their posts to another user; omit to leave posts with no author (their author is set to 0).', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'User id to delete.',
						),
						'reassign' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Optional user id to reassign their posts to.',
						),
						'confirm'  => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (deleted user login) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( __CLASS__, 'permission_delete_user' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public static function permission_list( $input = null ): bool {
		return current_user_can( 'list_users' );
	}

	public static function permission_read_user( $input = null ): bool {
		return current_user_can( 'list_users' );
	}

	public static function permission_create( $input = null ): bool {
		return current_user_can( 'create_users' );
	}

	public static function permission_edit_user( $input = null ) {
		$id = Plugin::resolve_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'edit_user', $id );
	}

	public static function permission_promote( $input = null ) {
		$id = Plugin::resolve_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'promote_user', $id );
	}

	public static function permission_delete_user( $input = null ) {
		$id = Plugin::resolve_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'delete_user', $id );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	private static function format_user( \WP_User $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'roles'        => $user->roles,
			'registered'   => $user->user_registered,
			'description'  => $user->description,
			'url'          => $user->user_url,
		);
	}

	public static function cb_list( $input ) {
		$args = array(
			'number' => isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20,
		);
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = (string) $input['role'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = '*' . (string) $input['search'] . '*';
		}

		$query = new \WP_User_Query( $args );
		$users = $query->get_results();

		return array(
			'total' => (int) $query->get_total(),
			'users' => array_map( array( __CLASS__, 'format_user' ), $users ),
		);
	}

	public static function cb_get( $input ) {
		$user = get_userdata( (int) $input['id'] );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'No user exists with the given ID.', 'wp-core-mcp-ability' ) );
		}
		return self::format_user( $user );
	}

	public static function cb_create( $input ) {
		$password = wp_generate_password( 24, true, true );

		$args = array(
			'user_login'   => (string) $input['user_login'],
			'user_email'   => (string) $input['user_email'],
			'user_pass'    => $password,
			'display_name' => isset( $input['display_name'] ) ? (string) $input['display_name'] : (string) $input['user_login'],
		);
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = (string) $input['role'];
		}

		// wp_insert_user()/wp_update_user() expect SLASHED data, same
		// contract as wp_insert_post() — see abilities-posts.php's note.
		$id = wp_insert_user( wp_slash( $args ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		wp_new_user_notification( $id, null, 'user' );

		return self::cb_get( array( 'id' => $id ) );
	}

	public static function cb_update( $input ) {
		$id   = (int) $input['id'];
		$user = get_userdata( $id );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'No user exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$field_map = array(
			'display_name' => 'display_name',
			'email'        => 'user_email',
			'description'  => 'description',
			'url'          => 'user_url',
		);

		$args    = array( 'ID' => $id );
		$updated = array();
		foreach ( $field_map as $in_key => $user_key ) {
			if ( array_key_exists( $in_key, $input ) ) {
				$args[ $user_key ]  = (string) $input[ $in_key ];
				$updated[ $in_key ] = array( 'old' => $user->$user_key );
			}
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'wp-core-mcp-ability' ) );
		}

		// See cb_create()'s identical wp_slash() note.
		$result = wp_update_user( wp_slash( $args ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fresh = get_userdata( $id );
		foreach ( $field_map as $in_key => $user_key ) {
			if ( isset( $updated[ $in_key ] ) ) {
				$updated[ $in_key ]['new'] = $fresh->$user_key;
			}
		}

		return array(
			'id'      => $id,
			'updated' => $updated,
		);
	}

	public static function cb_update_role( $input ) {
		$id   = (int) $input['id'];
		$user = get_userdata( $id );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'No user exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$role = (string) $input['role'];
		if ( ! wp_roles()->is_role( $role ) ) {
			return new \WP_Error( 'unknown_role', __( 'Unknown role.', 'wp-core-mcp-ability' ) );
		}

		$old = $user->roles;
		$user->set_role( $role );

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => get_userdata( $id )->roles,
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a user.', 'wp-core-mcp-ability' ) );
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$id   = (int) $input['id'];
		$user = get_userdata( $id );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'No user exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$login    = $user->user_login;
		$reassign = ! empty( $input['reassign'] ) ? (int) $input['reassign'] : null;

		$result = wp_delete_user( $id, $reassign );
		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The user could not be deleted.', 'wp-core-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $login,
			'new' => null,
		);
	}
}
