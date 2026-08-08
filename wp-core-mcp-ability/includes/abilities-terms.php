<?php
/**
 * Generic taxonomy/term CRUD abilities.
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Terms {

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/list-taxonomies',
			array(
				'label'               => __( 'List taxonomies', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists public taxonomies (category, post_tag, and any custom public taxonomy) with their slug, label, hierarchical flag and the post types they apply to.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "taxonomies": array of {name, label, hierarchical, object_type}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_taxonomies' ),
				'permission_callback' => '__return_true',
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/list-terms',
			array(
				'label'               => __( 'List terms', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists terms of a public taxonomy (e.g. "category", "post_tag"). Filter by search term or parent (hierarchical taxonomies).', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => array(
							'type'        => 'string',
							'default'     => 'category',
							'description' => 'Taxonomy slug. Default "category".',
						),
						'search'   => array(
							'type'        => 'string',
							'description' => 'Search term.',
						),
						'parent'   => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Parent term id (hierarchical taxonomies only).',
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum terms to return. Default 20.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "total" and "terms" (array of {id, name, slug, parent, count}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_terms' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-term',
			array(
				'label'               => __( 'Get a term', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns one taxonomy term by id and taxonomy.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Term id.',
						),
						'taxonomy' => array(
							'type'        => 'string',
							'default'     => 'category',
							'description' => 'Taxonomy slug. Default "category".',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Term detail.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/create-term',
			array(
				'label'               => __( 'Create a term', 'wp-core-mcp-ability' ),
				'description'         => __( 'Creates a new term in a public taxonomy. name is required; slug, description and parent (hierarchical taxonomies) are optional.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy'    => array(
							'type'        => 'string',
							'default'     => 'category',
							'description' => 'Taxonomy slug. Default "category".',
						),
						'name'        => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Term name.',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Optional URL slug.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Optional description.',
						),
						'parent'      => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Parent term id (hierarchical taxonomies only).',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created term.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-term',
			array(
				'label'               => __( 'Update a term', 'wp-core-mcp-ability' ),
				'description'         => __( 'Updates one or more fields of a term: name, slug, description, parent. Returns {old,new} per changed field.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Term id.',
						),
						'taxonomy'    => array(
							'type'        => 'string',
							'default'     => 'category',
							'description' => 'Taxonomy slug. Default "category".',
						),
						'name'        => array( 'type' => 'string', 'minLength' => 1 ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer', 'minimum' => 0 ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/delete-term',
			array(
				'label'               => __( 'Delete a term', 'wp-core-mcp-ability' ),
				'description'         => __( 'Permanently deletes a taxonomy term. Requires confirm: true. Posts assigned to it are not deleted, just untagged.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Term id.',
						),
						'taxonomy' => array(
							'type'        => 'string',
							'default'     => 'category',
							'description' => 'Taxonomy slug. Default "category".',
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
					'description' => 'Object with "id", "old" (deleted term name) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	private static function taxonomy_of_input( $input ): string {
		return isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : 'category';
	}

	public static function permission_read( $input = null ): bool {
		return Plugin::is_allowed_taxonomy( self::taxonomy_of_input( $input ) );
	}

	public static function permission_manage( $input = null ): bool {
		$taxonomy = self::taxonomy_of_input( $input );
		if ( ! Plugin::is_allowed_taxonomy( $taxonomy ) ) {
			return false;
		}
		$obj = get_taxonomy( $taxonomy );
		return current_user_can( $obj->cap->manage_terms );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$out[] = array(
				'name'          => $tax->name,
				'label'         => $tax->label,
				'hierarchical'  => (bool) $tax->hierarchical,
				'object_type'   => array_values( $tax->object_type ),
			);
		}
		return array( 'taxonomies' => $out );
	}

	private static function format_term( \WP_Term $term ): array {
		return array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}

	public static function cb_list_terms( $input ) {
		$taxonomy = self::taxonomy_of_input( $input );
		if ( ! Plugin::is_allowed_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'Unknown or disallowed taxonomy.', 'wp-core-mcp-ability' ) );
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$count_args            = $args;
		$count_args['number']  = 0;
		$count_args['fields']  = 'count';
		$total                 = get_terms( $count_args );

		return array(
			'total' => is_wp_error( $total ) ? count( $terms ) : (int) $total,
			'terms' => array_map( array( __CLASS__, 'format_term' ), $terms ),
		);
	}

	public static function cb_get( $input ) {
		$taxonomy = self::taxonomy_of_input( $input );
		if ( ! Plugin::is_allowed_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'Unknown or disallowed taxonomy.', 'wp-core-mcp-ability' ) );
		}

		$term = get_term( (int) $input['id'], $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'term_not_found', __( 'No term exists with the given ID in this taxonomy.', 'wp-core-mcp-ability' ) );
		}

		return self::format_term( $term );
	}

	public static function cb_create( $input ) {
		$taxonomy = self::taxonomy_of_input( $input );
		if ( ! Plugin::is_allowed_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'Unknown or disallowed taxonomy.', 'wp-core-mcp-ability' ) );
		}

		$args = array();
		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = (string) $input['slug'];
		}
		if ( ! empty( $input['description'] ) ) {
			$args['description'] = (string) $input['description'];
		}
		if ( ! empty( $input['parent'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$result = wp_insert_term( (string) $input['name'], $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::cb_get( array( 'id' => $result['term_id'], 'taxonomy' => $taxonomy ) );
	}

	public static function cb_update( $input ) {
		$taxonomy = self::taxonomy_of_input( $input );
		if ( ! Plugin::is_allowed_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'Unknown or disallowed taxonomy.', 'wp-core-mcp-ability' ) );
		}

		$id   = (int) $input['id'];
		$term = get_term( $id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'term_not_found', __( 'No term exists with the given ID in this taxonomy.', 'wp-core-mcp-ability' ) );
		}

		$field_map = array( 'name' => 'name', 'slug' => 'slug', 'description' => 'description' );
		$args      = array();
		$updated   = array();
		foreach ( $field_map as $in_key => $term_key ) {
			if ( array_key_exists( $in_key, $input ) ) {
				$args[ $in_key ]    = (string) $input[ $in_key ];
				$updated[ $in_key ] = array( 'old' => $term->$term_key );
			}
		}
		if ( array_key_exists( 'parent', $input ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$args['parent']     = (int) $input['parent'];
			$updated['parent']  = array( 'old' => (int) $term->parent );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'wp-core-mcp-ability' ) );
		}

		$result = wp_update_term( $id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fresh = get_term( $id, $taxonomy );
		foreach ( $field_map as $in_key => $term_key ) {
			if ( isset( $updated[ $in_key ] ) ) {
				$updated[ $in_key ]['new'] = $fresh->$term_key;
			}
		}
		if ( isset( $updated['parent'] ) ) {
			$updated['parent']['new'] = (int) $fresh->parent;
		}

		return array(
			'id'      => $id,
			'updated' => $updated,
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a term.', 'wp-core-mcp-ability' ) );
		}

		$taxonomy = self::taxonomy_of_input( $input );
		if ( ! Plugin::is_allowed_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'Unknown or disallowed taxonomy.', 'wp-core-mcp-ability' ) );
		}

		$id   = (int) $input['id'];
		$term = get_term( $id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'term_not_found', __( 'No term exists with the given ID in this taxonomy.', 'wp-core-mcp-ability' ) );
		}

		$name   = $term->name;
		$result = wp_delete_term( $id, $taxonomy );
		if ( is_wp_error( $result ) || ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The term could not be deleted.', 'wp-core-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $name,
			'new' => null,
		);
	}
}
