<?php
/**
 * Nav menu and menu item abilities.
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menus {

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/list-menus',
			array(
				'label'               => __( 'List nav menus', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists registered navigation menus with id, name, slug, item count and the theme location(s) they are assigned to (if any).', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "menus": array of {id, name, slug, count, locations}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_menus' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-menu',
			array(
				'label'               => __( 'Get a nav menu', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns a menu\'s items in order: id, title, url, type (custom/post_type/post_type_archive/taxonomy), target object, and parent (for nested items).', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Menu id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "name" and "items" (array of {id, title, url, type, object, object_id, parent, order}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_menu' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/create-menu',
			array(
				'label'               => __( 'Create a nav menu', 'wp-core-mcp-ability' ),
				'description'         => __( 'Creates a new empty navigation menu. Use add-menu-item to populate it and assign-menu-location to attach it to a theme location.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Menu name.',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "name".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create_menu' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/delete-menu',
			array(
				'label'               => __( 'Delete a nav menu', 'wp-core-mcp-ability' ),
				'description'         => __( 'Permanently deletes a navigation menu and all its items. Requires confirm: true.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Menu id.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (deleted menu name) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete_menu' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/add-menu-item',
			array(
				'label'               => __( 'Add a menu item', 'wp-core-mcp-ability' ),
				'description'         => __( 'Adds one item to a menu. type "custom" needs title+url; "post_type" needs object (post type, e.g. "page") + object_id (post id); "post_type_archive" needs object (post type); "taxonomy" needs object (taxonomy, e.g. "category") + object_id (term id). parent nests it under another item in the same menu.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'menu_id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Menu id to add the item to.',
						),
						'type'      => array(
							'type'        => 'string',
							'enum'        => array( 'custom', 'post_type', 'post_type_archive', 'taxonomy' ),
							'default'     => 'custom',
							'description' => 'Item type. Default "custom".',
						),
						'title'     => array(
							'type'        => 'string',
							'description' => 'Link text. Required for "custom"; optional override otherwise (blank uses the target\'s own title).',
						),
						'url'       => array(
							'type'        => 'string',
							'description' => 'Target URL. Only used for type "custom".',
						),
						'object'    => array(
							'type'        => 'string',
							'description' => 'Post type or taxonomy slug (required for post_type/post_type_archive/taxonomy).',
						),
						'object_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Target post or term id (required for post_type/taxonomy).',
						),
						'parent'    => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => 0,
							'description' => 'Parent menu item id, for a sub-item. 0 = top level.',
						),
					),
					'required'             => array( 'menu_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created menu item.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_add_item' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-menu-item',
			array(
				'label'               => __( 'Update a menu item', 'wp-core-mcp-ability' ),
				'description'         => __( 'Updates one or more fields of an existing menu item: title, url (custom items), parent, order (position among siblings). Returns {old,new}.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Menu item id.',
						),
						'title'  => array( 'type' => 'string' ),
						'url'    => array( 'type' => 'string' ),
						'parent' => array( 'type' => 'integer', 'minimum' => 0 ),
						'order'  => array( 'type' => 'integer', 'minimum' => 0 ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_item' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/delete-menu-item',
			array(
				'label'               => __( 'Delete a menu item', 'wp-core-mcp-ability' ),
				'description'         => __( 'Removes one item from a menu. Requires confirm: true. Does not affect the linked post/term/page itself, only the menu entry.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Menu item id.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (deleted item title) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete_item' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/assign-menu-location',
			array(
				'label'               => __( 'Assign a menu to a theme location', 'wp-core-mcp-ability' ),
				'description'         => __( 'Assigns a menu to one of the active theme\'s registered menu locations (see get-status-like output via list-menus, or the theme\'s documentation for location names, e.g. "primary"). Pass menu_id: 0 to unassign whatever menu is at that location.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'location' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Theme menu location slug, e.g. "primary".',
						),
						'menu_id'  => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Menu id to assign, or 0 to unassign.',
						),
					),
					'required'             => array( 'location', 'menu_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "location", "old" and "new" (menu ids, 0 = none).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_assign_location' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public static function permission_manage( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	private static function locations_for_menu( int $menu_id ): array {
		$assigned = array();
		foreach ( (array) get_nav_menu_locations() as $location => $id ) {
			if ( (int) $id === $menu_id ) {
				$assigned[] = $location;
			}
		}
		return $assigned;
	}

	public static function cb_list_menus() {
		$menus = wp_get_nav_menus();
		return array(
			'menus' => array_map(
				static function ( $menu ) {
					return array(
						'id'        => (int) $menu->term_id,
						'name'      => $menu->name,
						'slug'      => $menu->slug,
						'count'     => (int) $menu->count,
						'locations' => Menus::locations_for_menu( (int) $menu->term_id ),
					);
				},
				$menus
			),
		);
	}

	public static function cb_get_menu( $input ) {
		$id   = (int) $input['id'];
		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu ) {
			return new \WP_Error( 'menu_not_found', __( 'No menu exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$items = wp_get_nav_menu_items( $id );

		return array(
			'id'    => (int) $menu->term_id,
			'name'  => $menu->name,
			'items' => array_map(
				static function ( $item ) {
					return array(
						'id'        => (int) $item->ID,
						'title'     => $item->title,
						'url'       => $item->url,
						'type'      => $item->type,
						'object'    => $item->object,
						'object_id' => (int) $item->object_id,
						'parent'    => (int) $item->menu_item_parent,
						'order'     => (int) $item->menu_order,
					);
				},
				$items ?: array()
			),
		);
	}

	public static function cb_create_menu( $input ) {
		$id = wp_update_nav_menu_object( 0, array( 'menu-name' => (string) $input['name'] ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array(
			'id'   => (int) $id,
			'name' => (string) $input['name'],
		);
	}

	public static function cb_delete_menu( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a menu.', 'wp-core-mcp-ability' ) );
		}

		$id   = (int) $input['id'];
		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu ) {
			return new \WP_Error( 'menu_not_found', __( 'No menu exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$name   = $menu->name;
		$result = wp_delete_nav_menu( $id );
		if ( is_wp_error( $result ) || ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The menu could not be deleted.', 'wp-core-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $name,
			'new' => null,
		);
	}

	public static function cb_add_item( $input ) {
		$menu_id = (int) $input['menu_id'];
		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			return new \WP_Error( 'menu_not_found', __( 'No menu exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$type = isset( $input['type'] ) ? (string) $input['type'] : 'custom';

		$args = array(
			'menu-item-type'      => $type,
			'menu-item-title'     => isset( $input['title'] ) ? (string) $input['title'] : '',
			'menu-item-parent-id' => isset( $input['parent'] ) ? (int) $input['parent'] : 0,
			'menu-item-status'    => 'publish',
		);

		if ( 'custom' === $type ) {
			if ( empty( $input['url'] ) || empty( $input['title'] ) ) {
				return new \WP_Error( 'missing_fields', __( 'type "custom" requires both title and url.', 'wp-core-mcp-ability' ) );
			}
			$args['menu-item-url'] = (string) $input['url'];
		} else {
			if ( empty( $input['object'] ) ) {
				return new \WP_Error( 'missing_fields', __( 'This type requires "object" (post type or taxonomy slug).', 'wp-core-mcp-ability' ) );
			}
			$args['menu-item-object'] = (string) $input['object'];
			if ( in_array( $type, array( 'post_type', 'taxonomy' ), true ) ) {
				if ( empty( $input['object_id'] ) ) {
					return new \WP_Error( 'missing_fields', __( 'This type requires "object_id".', 'wp-core-mcp-ability' ) );
				}
				$args['menu-item-object-id'] = (int) $input['object_id'];
			}
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		$items = wp_get_nav_menu_items( $menu_id );
		foreach ( (array) $items as $item ) {
			if ( (int) $item->ID === (int) $item_id ) {
				return array(
					'id'        => (int) $item->ID,
					'title'     => $item->title,
					'url'       => $item->url,
					'type'      => $item->type,
					'object'    => $item->object,
					'object_id' => (int) $item->object_id,
					'parent'    => (int) $item->menu_item_parent,
					'order'     => (int) $item->menu_order,
				);
			}
		}

		return array( 'id' => (int) $item_id );
	}

	public static function cb_update_item( $input ) {
		$id = (int) $input['id'];
		if ( ! is_nav_menu_item( $id ) ) {
			return new \WP_Error( 'item_not_found', __( 'No menu item exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$post = get_post( $id );

		// Menu items don't track their menu via post_parent; find it via term relationship.
		$menu_terms = wp_get_object_terms( $id, 'nav_menu' );
		$menu_id    = ! empty( $menu_terms ) && ! is_wp_error( $menu_terms ) ? (int) $menu_terms[0]->term_id : 0;

		$setup      = wp_setup_nav_menu_item( clone $post );
		$old_title  = $setup->title;
		$old_url    = $setup->url;
		$old_parent = (int) $setup->menu_item_parent;
		$old_order  = (int) $setup->menu_order;

		$args = array(
			'menu-item-db-id'     => $id,
			'menu-item-object-id' => get_post_meta( $id, '_menu_item_object_id', true ),
			'menu-item-object'    => get_post_meta( $id, '_menu_item_object', true ),
			'menu-item-type'      => get_post_meta( $id, '_menu_item_type', true ),
			'menu-item-title'     => $old_title,
			'menu-item-url'       => $old_url,
			'menu-item-parent-id' => $old_parent,
			'menu-item-position'  => $old_order,
			'menu-item-status'    => $post->post_status,
		);

		$updated = array();
		if ( array_key_exists( 'title', $input ) ) {
			$args['menu-item-title'] = (string) $input['title'];
			$updated['title']        = array( 'old' => $old_title );
		}
		if ( array_key_exists( 'url', $input ) ) {
			$args['menu-item-url'] = (string) $input['url'];
			$updated['url']        = array( 'old' => $old_url );
		}
		if ( array_key_exists( 'parent', $input ) ) {
			$args['menu-item-parent-id'] = (int) $input['parent'];
			$updated['parent']           = array( 'old' => $old_parent );
		}
		if ( array_key_exists( 'order', $input ) ) {
			$args['menu-item-position'] = (int) $input['order'];
			$updated['order']           = array( 'old' => $old_order );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'wp-core-mcp-ability' ) );
		}

		$result = wp_update_nav_menu_item( $menu_id, $id, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fresh = get_post( $id );
		if ( isset( $updated['title'] ) ) {
			$updated['title']['new'] = get_post_meta( $id, '_menu_item_title', true ) ?: $fresh->post_title;
		}
		if ( isset( $updated['url'] ) ) {
			$updated['url']['new'] = get_post_meta( $id, '_menu_item_url', true );
		}
		if ( isset( $updated['parent'] ) ) {
			$updated['parent']['new'] = (int) get_post_meta( $id, '_menu_item_menu_item_parent', true );
		}
		if ( isset( $updated['order'] ) ) {
			$updated['order']['new'] = (int) $fresh->menu_order;
		}

		return array(
			'id'      => $id,
			'updated' => $updated,
		);
	}

	public static function cb_delete_item( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a menu item.', 'wp-core-mcp-ability' ) );
		}

		$id = (int) $input['id'];
		if ( ! is_nav_menu_item( $id ) ) {
			return new \WP_Error( 'item_not_found', __( 'No menu item exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$setup = wp_setup_nav_menu_item( clone get_post( $id ) );
		$title = $setup->title;

		$result = wp_delete_post( $id, true );
		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The menu item could not be deleted.', 'wp-core-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $title,
			'new' => null,
		);
	}

	public static function cb_assign_location( $input ) {
		$location = (string) $input['location'];
		$locations = get_registered_nav_menus();
		if ( ! array_key_exists( $location, $locations ) ) {
			return new \WP_Error( 'unknown_location', __( 'This theme does not register that menu location.', 'wp-core-mcp-ability' ) );
		}

		$menu_id = (int) $input['menu_id'];
		if ( 0 !== $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
			return new \WP_Error( 'menu_not_found', __( 'No menu exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$assigned = (array) get_theme_mod( 'nav_menu_locations', array() );
		$old      = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;

		$assigned[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $assigned );

		$new_assigned = (array) get_theme_mod( 'nav_menu_locations', array() );

		return array(
			'location' => $location,
			'old'      => $old,
			'new'      => isset( $new_assigned[ $location ] ) ? (int) $new_assigned[ $location ] : 0,
		);
	}
}
