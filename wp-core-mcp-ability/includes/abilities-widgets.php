<?php
/**
 * Sidebar / block-widget abilities.
 *
 * Targets the modern block-widget system (widget_block option +
 * sidebars_widgets mapping, widget ids like "block-N"), which is what
 * block-based themes use. Legacy WP_Widget-class widgets are read-only
 * here (see list-legacy-widgets); each class has its own settings
 * schema, so CRUD isn't generic across them.
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widgets {

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/list-sidebars',
			array(
				'label'               => __( 'List sidebars', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists registered widget areas (sidebars) with id, name, description and the ids of the widgets currently placed in each.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "sidebars": array of {id, name, description, widgets}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_sidebars' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-sidebar-widgets',
			array(
				'label'               => __( 'Get sidebar widgets', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns the block widgets placed in one sidebar, in order, with their id and block content.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'sidebar_id' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Sidebar id, e.g. "sidebar-1" (see list-sidebars).',
						),
					),
					'required'             => array( 'sidebar_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "sidebar_id" and "widgets": array of {id, content}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_sidebar_widgets' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/add-block-widget',
			array(
				'label'               => __( 'Add a block widget', 'wp-core-mcp-ability' ),
				'description'         => __( 'Appends a new block widget to a sidebar. content is raw block markup, e.g. "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->". Returns the created widget id.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'sidebar_id' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Sidebar id to add the widget to.',
						),
						'content'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Block markup for the widget.',
						),
					),
					'required'             => array( 'sidebar_id', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "sidebar_id" and "content".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_add_widget' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-block-widget',
			array(
				'label'               => __( 'Update a block widget', 'wp-core-mcp-ability' ),
				'description'         => __( 'Replaces the block content of an existing widget. Returns {old,new}.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Widget id, e.g. "block-2".',
						),
						'content' => array(
							'type'        => 'string',
							'description' => 'New block markup.',
						),
					),
					'required'             => array( 'id', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" and "new" content.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_widget' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/list-legacy-widgets',
			array(
				'label'               => __( 'List legacy PHP-class widgets', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists registered legacy WP_Widget classes (id_base, name) and any placed instances of them per sidebar (id, sidebar). Read-only inventory — legacy widget CRUD is not implemented because each widget class defines its own settings schema (there is no generic form), unlike block widgets. Most sites use block widgets exclusively; this ability helps confirm whether any legacy widgets are still in use.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "widget_types" (array of {id_base, name}) and "placed" (array of {id, sidebar_id}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_legacy' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/delete-block-widget',
			array(
				'label'               => __( 'Delete a block widget', 'wp-core-mcp-ability' ),
				'description'         => __( 'Removes a widget from its sidebar and deletes its stored content. Requires confirm: true.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Widget id, e.g. "block-2".',
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
					'description' => 'Object with "id", "old" (removed content) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete_widget' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, true, true ),
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
	// Helpers
	// ---------------------------------------------------------------------

	private static function next_widget_id(): string {
		$blocks   = (array) get_option( 'widget_block', array() );
		$max      = 1;
		foreach ( array_keys( $blocks ) as $key ) {
			if ( is_numeric( $key ) ) {
				$max = max( $max, (int) $key );
			}
		}
		return 'block-' . ( $max + 1 );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list_sidebars() {
		global $wp_registered_sidebars;

		$assignments = wp_get_sidebars_widgets();

		$sidebars = array();
		foreach ( (array) $wp_registered_sidebars as $id => $sidebar ) {
			$sidebars[] = array(
				'id'          => $id,
				'name'        => $sidebar['name'],
				'description' => $sidebar['description'] ?? '',
				'widgets'     => array_values( $assignments[ $id ] ?? array() ),
			);
		}

		return array( 'sidebars' => $sidebars );
	}

	public static function cb_get_sidebar_widgets( $input ) {
		$sidebar_id = (string) $input['sidebar_id'];

		global $wp_registered_sidebars;
		if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			return new \WP_Error( 'sidebar_not_found', __( 'No sidebar exists with the given id.', 'wp-core-mcp-ability' ) );
		}

		$assignments = wp_get_sidebars_widgets();
		$widget_ids  = array_values( $assignments[ $sidebar_id ] ?? array() );
		$blocks      = (array) get_option( 'widget_block', array() );

		$widgets = array();
		foreach ( $widget_ids as $widget_id ) {
			if ( ! str_starts_with( $widget_id, 'block-' ) ) {
				continue; // Legacy (non-block) widget; out of scope this wave.
			}
			$index     = substr( $widget_id, strlen( 'block-' ) );
			$widgets[] = array(
				'id'      => $widget_id,
				'content' => $blocks[ $index ]['content'] ?? '',
			);
		}

		return array(
			'sidebar_id' => $sidebar_id,
			'widgets'    => $widgets,
		);
	}

	public static function cb_list_legacy() {
		global $wp_widget_factory;

		$types = array();
		foreach ( (array) $wp_widget_factory->widgets as $widget ) {
			$types[] = array(
				'id_base' => $widget->id_base,
				'name'    => $widget->name,
			);
		}

		$placed = array();
		foreach ( wp_get_sidebars_widgets() as $sidebar_id => $ids ) {
			foreach ( (array) $ids as $id ) {
				if ( ! str_starts_with( $id, 'block-' ) ) {
					$placed[] = array(
						'id'         => $id,
						'sidebar_id' => $sidebar_id,
					);
				}
			}
		}

		return array(
			'widget_types' => $types,
			'placed'       => $placed,
		);
	}

	public static function cb_add_widget( $input ) {
		$sidebar_id = (string) $input['sidebar_id'];

		global $wp_registered_sidebars;
		if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			return new \WP_Error( 'sidebar_not_found', __( 'No sidebar exists with the given id.', 'wp-core-mcp-ability' ) );
		}

		$widget_id = self::next_widget_id();
		$index     = substr( $widget_id, strlen( 'block-' ) );

		$blocks            = (array) get_option( 'widget_block', array() );
		$blocks[ $index ]  = array( 'content' => (string) $input['content'] );
		update_option( 'widget_block', $blocks );

		$assignments                  = wp_get_sidebars_widgets();
		$assignments[ $sidebar_id ]   = array_values( $assignments[ $sidebar_id ] ?? array() );
		$assignments[ $sidebar_id ][] = $widget_id;
		wp_set_sidebars_widgets( $assignments );

		return array(
			'id'         => $widget_id,
			'sidebar_id' => $sidebar_id,
			'content'    => (string) $input['content'],
		);
	}

	public static function cb_update_widget( $input ) {
		$id = (string) $input['id'];
		if ( ! str_starts_with( $id, 'block-' ) ) {
			return new \WP_Error( 'invalid_widget', __( 'Only block widgets (id starting with "block-") are supported.', 'wp-core-mcp-ability' ) );
		}
		$index = substr( $id, strlen( 'block-' ) );

		$blocks = (array) get_option( 'widget_block', array() );
		if ( ! isset( $blocks[ $index ] ) ) {
			return new \WP_Error( 'widget_not_found', __( 'No widget exists with the given id.', 'wp-core-mcp-ability' ) );
		}

		$old                       = $blocks[ $index ]['content'] ?? '';
		$blocks[ $index ]['content'] = (string) $input['content'];
		update_option( 'widget_block', $blocks );

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => (string) $input['content'],
		);
	}

	public static function cb_delete_widget( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a widget.', 'wp-core-mcp-ability' ) );
		}

		$id = (string) $input['id'];
		if ( ! str_starts_with( $id, 'block-' ) ) {
			return new \WP_Error( 'invalid_widget', __( 'Only block widgets (id starting with "block-") are supported.', 'wp-core-mcp-ability' ) );
		}
		$index = substr( $id, strlen( 'block-' ) );

		$blocks = (array) get_option( 'widget_block', array() );
		if ( ! isset( $blocks[ $index ] ) ) {
			return new \WP_Error( 'widget_not_found', __( 'No widget exists with the given id.', 'wp-core-mcp-ability' ) );
		}

		$old = $blocks[ $index ]['content'] ?? '';
		unset( $blocks[ $index ] );
		update_option( 'widget_block', $blocks );

		$assignments = wp_get_sidebars_widgets();
		foreach ( $assignments as $sidebar_id => $ids ) {
			if ( is_array( $ids ) && in_array( $id, $ids, true ) ) {
				$assignments[ $sidebar_id ] = array_values( array_diff( $ids, array( $id ) ) );
			}
		}
		wp_set_sidebars_widgets( $assignments );

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => null,
		);
	}
}
