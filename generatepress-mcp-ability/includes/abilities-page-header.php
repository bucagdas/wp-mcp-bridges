<?php
/**
 * Legacy Page Header module abilities: the generate_page_header CPT, its
 * 46 post metas, per-post/per-term assignment and the global locations map.
 *
 * This is NOT the Elements "hero". The two are separate systems with no
 * shared code path — see Plugin::PAGE_HEADER_FIELDS. Hero settings live on
 * gp_elements posts and are handled by the element abilities in
 * abilities-gp.php.
 *
 * CACHE SURFACE (measured 2026-08-09, CLAUDE.md A2): none of these metas
 * or options feed GeneratePress' compiled/cached CSS. The module builds its
 * CSS in generate_page_header_css() and prints it with wp_add_inline_style()
 * on wp_enqueue_scripts @100, i.e. fresh on every request. Verified by
 * measurement with a positive control: writing these metas changed the
 * module's own CSS (0 -> 423 bytes) while md5(generate_get_dynamic_css())
 * stayed identical, and a known-cached setting (background_color) moved it.
 * So no flush_theme_css_cache() calls belong here. See HEDEF-SURUM.md.
 *
 * @package GeneratePressMCPAbility
 */

namespace GeneratePressMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageHeader {

	const POST_TYPE = 'generate_page_header';

	/** Meta key that assigns a page header to a single post or term. */
	const SELECT_META = '_generate-select-page-header';

	public static function register(): void {
		// The whole module is optional: without it GP registers neither the
		// CPT nor the metabox, and these metas are read by nobody. Register
		// nothing rather than offering verbs that write dead data.
		if ( ! Plugin::has_gp_premium() || ! Plugin::is_module_active( 'page_header' ) ) {
			return;
		}

		$settings_schema = array(
			'type'                 => 'object',
			'description'          => 'Page header fields to write. Keys as listed in get-page-header. IMPORTANT: an empty string or "0" REMOVES the field, mirroring GP Premium\'s own metabox, which saves with "if ( $value ) update else delete". The response reports new: null for a removed field so you can see it was cleared rather than set to zero.',
			'additionalProperties' => false,
			'properties'           => self::settings_properties(),
		);

		wp_register_ability(
			'generatepress-mcp/list-page-headers',
			array(
				'label'               => __( 'List page headers', 'generatepress-mcp-ability' ),
				'description'         => __( 'Lists the reusable page headers stored in GP Premium\'s generate_page_header post type, with id, title, status and modified date. These are the ones you can attach to a post, a term or a global location.', 'generatepress-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum number of page headers to return. Default 20.',
						),
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => 'Result page. Default 1.',
						),
						'status'   => array(
							'type'        => 'string',
							'description' => 'Post status filter. Default "any".',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "total" and "page_headers" (array of {id, title, status, modified}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'generatepress-mcp/get-page-header',
			array(
				'label'               => __( 'Get page header settings', 'generatepress-mcp-ability' ),
				'description'         => __( 'Returns all 46 page header fields for one post. The id can be a reusable page header (generate_page_header post) or any ordinary post/page that carries its own page header settings. Fields that were never set come back as empty strings, which is how GP Premium itself reads them.', 'generatepress-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post id: a generate_page_header post, or any post/page with its own page header settings.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "post_type", "title", "assigned_page_header" (id of the reusable page header attached to this post, or null) and "settings".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( __CLASS__, 'permission_read_post' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'generatepress-mcp/create-page-header',
			array(
				'label'               => __( 'Create a page header', 'generatepress-mcp-ability' ),
				'description'         => __( 'Creates a reusable page header (a generate_page_header post) and optionally sets its fields in the same call. Attach it afterwards with assign-page-header or update-page-header-global-location.', 'generatepress-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Admin-facing title for this page header.',
						),
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft' ),
							'default'     => 'publish',
							'description' => 'Post status. Default "publish".',
						),
						'settings' => $settings_schema,
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "title", "status" and "settings" (read back after the write).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( __CLASS__, 'permission_create' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'generatepress-mcp/update-page-header',
			array(
				'label'               => __( 'Update page header settings', 'generatepress-mcp-ability' ),
				'description'         => __( 'Writes page header fields on one post — a reusable generate_page_header post, or any ordinary post/page that should carry its own page header. Only the fields you pass are touched. Returns the old and new value of each field that actually changed; new is null when a field was removed.', 'generatepress-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post id to write the page header fields on.',
						),
						'title'    => array(
							'type'        => 'string',
							'description' => 'New title. Only accepted for generate_page_header posts — renaming an ordinary post through this verb is refused.',
						),
						'settings' => $settings_schema,
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "changed" (map of field => {old, new}; new is null when the field was removed). Unchanged fields are omitted.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'generatepress-mcp/delete-page-header',
			array(
				'label'               => __( 'Delete a page header', 'generatepress-mcp-ability' ),
				'description'         => __( 'Permanently deletes one reusable page header (generate_page_header post). Requires confirm: true. Posts, terms and global locations that pointed at it fall back to having no page header. Only generate_page_header posts can be deleted here.', 'generatepress-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'generate_page_header post id.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed. Deletion is permanent.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "title" and "deleted".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( __CLASS__, 'permission_delete' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'generatepress-mcp/assign-page-header',
			array(
				'label'               => __( 'Assign a page header to a post or term', 'generatepress-mcp-ability' ),
				'description'         => __( 'Attaches a reusable page header to one post or one term by writing GP Premium\'s _generate-select-page-header meta. Pass id: 0 to detach. Exactly one of post_id or term_id must be given.', 'generatepress-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'generate_page_header post id to attach, or 0 to detach.',
						),
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post to attach it to. Mutually exclusive with term_id.',
						),
						'term_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Term to attach it to. Mutually exclusive with post_id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "target" ({type, id}), "old" and "new" (page header ids, null when none), read back after the write.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_assign' ),
				'permission_callback' => array( __CLASS__, 'permission_assign' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		// No input_schema on purpose: an ability with no inputs must not
		// declare one, or the core REST /run route rejects the empty call
		// with a 400. See CLAUDE.md schema traps.
		wp_register_ability(
			'generatepress-mcp/get-page-header-global-locations',
			array(
				'label'               => __( 'Get page header global locations', 'generatepress-mcp-ability' ),
				'description'         => __( 'Returns the generate_page_header_global_locations map — which reusable page header is shown on the blog page, search results, 404, each post type, each post type archive and each taxonomy. Also lists every location key this site accepts.', 'generatepress-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "locations" (map of location key => {page_header_id, title}) and "available_locations" (every accepted key with its label).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_global_locations' ),
				'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'generatepress-mcp/update-page-header-global-location',
			array(
				'label'               => __( 'Update one page header global location', 'generatepress-mcp-ability' ),
				'description'         => __( 'Sets which reusable page header is shown at one global location. Pass id: 0 to clear that location. Valid location keys come from get-page-header-global-locations; an unknown key is refused with the list of accepted ones.', 'generatepress-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'location' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Location key, e.g. "blog", "404", "search_results", "page", "product_archives" or a taxonomy name.',
						),
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'generate_page_header post id to show there, or 0 to clear.',
						),
					),
					'required'             => array( 'location', 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "location", "old" and "new" (page header ids, null when none), read back after the write.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_global_location' ),
				'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);
	}

	// -------------------------------------------------------------------
	// Schema helpers
	// -------------------------------------------------------------------

	/**
	 * Builds the per-field schema for the settings object from the single
	 * source of truth, so a field can never be offered here that
	 * write_meta_fields() would then reject.
	 */
	private static function settings_properties(): array {
		$props = array();
		foreach ( Plugin::PAGE_HEADER_FIELDS as $key => $spec ) {
			$props[ $key ] = array(
				'type'        => array( 'string', 'integer', 'null' ),
				'description' => sprintf(
					/* translators: 1: WordPress meta key, 2: value kind */
					__( 'Maps to %1$s (%2$s).', 'generatepress-mcp-ability' ),
					$spec[0],
					$spec[1]
				),
			);
		}
		return $props;
	}

	// -------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------

	/**
	 * The generate_page_header CPT is registered with
	 * capability_type => 'page' and WITHOUT map_meta_cap
	 * (page-header/functions/post-type.php:44), so WordPress never maps a
	 * meta capability to a per-object one here: the primitive *_pages caps
	 * are what actually gate the admin screens. We match that rather than
	 * inventing a per-object rule GP itself does not apply.
	 */
	public static function permission_read( $input = null ): bool {
		return current_user_can( 'edit_pages' );
	}

	public static function permission_create( $input = null ): bool {
		return current_user_can( 'publish_pages' );
	}

	public static function permission_delete( $input = null ) {
		$id = Plugin::resolve_id( $input, 'id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'delete_pages' );
	}

	/**
	 * Reading/writing page header metas on an ORDINARY post is gated on
	 * that post, because there the metas belong to somebody's content. GP
	 * gates its own metabox on current_user_can( 'edit_post', $post_id )
	 * (page-header/functions/metabox.php:590), so we use the same check.
	 */
	public static function permission_read_post( $input = null ) {
		$id = Plugin::resolve_id( $input, 'id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'edit_post', $id );
	}

	public static function permission_edit_post( $input = null ) {
		return self::permission_read_post( $input );
	}

	public static function permission_assign( $input = null ) {
		if ( isset( $input['post_id'] ) && (int) $input['post_id'] > 0 ) {
			return current_user_can( 'edit_post', (int) $input['post_id'] );
		}
		if ( isset( $input['term_id'] ) && (int) $input['term_id'] > 0 ) {
			return current_user_can( 'edit_term', (int) $input['term_id'] );
		}
		return new \WP_Error(
			'missing_target',
			__( 'Provide exactly one of "post_id" or "term_id" (the object the page header should be attached to).', 'generatepress-mcp-ability' )
		);
	}

	// -------------------------------------------------------------------
	// Execute callbacks
	// -------------------------------------------------------------------

	public static function cb_list( $input ) {
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$status   = isset( $input['status'] ) ? (string) $input['status'] : 'any';

		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'modified' => $post->post_modified_gmt,
			);
		}

		return array(
			'total'        => (int) $query->found_posts,
			'page_headers' => $items,
		);
	}

	public static function cb_get( $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given id.', 'generatepress-mcp-ability' ) );
		}
		$assigned = (int) get_post_meta( $post->ID, self::SELECT_META, true );

		return array(
			'id'                    => $post->ID,
			'post_type'             => $post->post_type,
			'title'                 => $post->post_title,
			'assigned_page_header'  => $assigned > 0 ? $assigned : null,
			'settings'              => Plugin::read_meta_fields( $post->ID, Plugin::PAGE_HEADER_FIELDS ),
		);
	}

	public static function cb_create( $input ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => (string) $input['title'],
				'post_status' => isset( $input['status'] ) ? (string) $input['status'] : 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$written = Plugin::write_meta_fields( (int) $post_id, Plugin::PAGE_HEADER_FIELDS, $input['settings'] );
			if ( is_wp_error( $written ) ) {
				// The post exists but the settings were rejected: remove it
				// again so a schema mistake does not leave a stray header.
				wp_delete_post( (int) $post_id, true );
				return $written;
			}
		}

		$post = get_post( (int) $post_id );
		return array(
			'id'       => (int) $post_id,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'settings' => Plugin::read_meta_fields( (int) $post_id, Plugin::PAGE_HEADER_FIELDS ),
		);
	}

	public static function cb_update( $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given id.', 'generatepress-mcp-ability' ) );
		}
		if ( empty( $input['settings'] ) && ! isset( $input['title'] ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide "settings" and/or "title" to change.', 'generatepress-mcp-ability' ) );
		}

		$changed = array();

		if ( isset( $input['title'] ) ) {
			if ( self::POST_TYPE !== $post->post_type ) {
				return new \WP_Error(
					'title_not_editable',
					__( 'Titles can only be changed on generate_page_header posts here. Use the core post abilities to rename an ordinary post.', 'generatepress-mcp-ability' )
				);
			}
			$old = $post->post_title;
			$res = wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => (string) $input['title'],
				),
				true
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$changed['title'] = array(
				'old' => $old,
				'new' => get_post( $post->ID )->post_title,
			);
		}

		if ( ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$written = Plugin::write_meta_fields( $post->ID, Plugin::PAGE_HEADER_FIELDS, $input['settings'] );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$changed = array_merge( $changed, $written );
		}

		return array(
			'id'      => $post->ID,
			'changed' => $changed,
		);
	}

	public static function cb_delete( $input ) {
		if ( empty( $input['confirm'] ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true — deleting a page header is permanent.', 'generatepress-mcp-ability' ) );
		}
		$post = get_post( (int) $input['id'] );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'not_a_page_header',
				__( 'That id is not a generate_page_header post. Use list-page-headers to find the right id.', 'generatepress-mcp-ability' )
			);
		}
		$title = $post->post_title;
		$done  = wp_delete_post( $post->ID, true );

		return array(
			'id'      => (int) $input['id'],
			'title'   => $title,
			'deleted' => (bool) $done,
		);
	}

	public static function cb_assign( $input ) {
		$has_post = isset( $input['post_id'] ) && (int) $input['post_id'] > 0;
		$has_term = isset( $input['term_id'] ) && (int) $input['term_id'] > 0;
		if ( $has_post === $has_term ) {
			return new \WP_Error(
				'target_required',
				__( 'Provide exactly one of "post_id" or "term_id".', 'generatepress-mcp-ability' )
			);
		}

		$header_id = (int) $input['id'];
		if ( $header_id > 0 ) {
			$header = get_post( $header_id );
			if ( ! $header || self::POST_TYPE !== $header->post_type ) {
				return new \WP_Error(
					'not_a_page_header',
					__( 'The "id" must be a generate_page_header post, or 0 to detach.', 'generatepress-mcp-ability' )
				);
			}
		}

		if ( $has_post ) {
			$target_id = (int) $input['post_id'];
			if ( ! get_post( $target_id ) ) {
				return new \WP_Error( 'post_not_found', __( 'No post exists with the given post_id.', 'generatepress-mcp-ability' ) );
			}
			$old = (int) get_post_meta( $target_id, self::SELECT_META, true );
			if ( $header_id > 0 ) {
				update_post_meta( $target_id, self::SELECT_META, $header_id );
			} else {
				delete_post_meta( $target_id, self::SELECT_META );
			}
			$new  = (int) get_post_meta( $target_id, self::SELECT_META, true );
			$type = 'post';
		} else {
			$target_id = (int) $input['term_id'];
			if ( ! get_term( $target_id ) instanceof \WP_Term ) {
				return new \WP_Error( 'term_not_found', __( 'No term exists with the given term_id.', 'generatepress-mcp-ability' ) );
			}
			$old = (int) get_term_meta( $target_id, self::SELECT_META, true );
			if ( $header_id > 0 ) {
				update_term_meta( $target_id, self::SELECT_META, $header_id );
			} else {
				delete_term_meta( $target_id, self::SELECT_META );
			}
			$new  = (int) get_term_meta( $target_id, self::SELECT_META, true );
			$type = 'term';
		}

		return array(
			'target' => array(
				'type' => $type,
				'id'   => $target_id,
			),
			'old'    => $old > 0 ? $old : null,
			'new'    => $new > 0 ? $new : null,
		);
	}

	public static function cb_get_global_locations() {
		$stored    = (array) get_option( 'generate_page_header_global_locations', array() );
		$available = self::available_locations();

		$locations = array();
		foreach ( $stored as $key => $header_id ) {
			$header_id = (int) $header_id;
			if ( $header_id <= 0 ) {
				continue;
			}
			$header            = get_post( $header_id );
			$locations[ $key ] = array(
				'page_header_id' => $header_id,
				'title'          => $header ? $header->post_title : null,
			);
		}

		return array(
			'locations'           => $locations,
			'available_locations' => $available,
		);
	}

	public static function cb_update_global_location( $input ) {
		$location  = (string) $input['location'];
		$available = self::available_locations();
		if ( ! isset( $available[ $location ] ) ) {
			return new \WP_Error(
				'unknown_location',
				sprintf(
					/* translators: 1: rejected location key, 2: comma-separated list of accepted keys */
					__( 'Unknown location "%1$s". Accepted locations on this site: %2$s.', 'generatepress-mcp-ability' ),
					$location,
					implode( ', ', array_keys( $available ) )
				)
			);
		}

		$header_id = (int) $input['id'];
		if ( $header_id > 0 ) {
			$header = get_post( $header_id );
			if ( ! $header || self::POST_TYPE !== $header->post_type ) {
				return new \WP_Error(
					'not_a_page_header',
					__( 'The "id" must be a generate_page_header post, or 0 to clear this location.', 'generatepress-mcp-ability' )
				);
			}
		}

		$stored = (array) get_option( 'generate_page_header_global_locations', array() );
		$old    = isset( $stored[ $location ] ) ? (int) $stored[ $location ] : 0;

		if ( $header_id > 0 ) {
			$stored[ $location ] = $header_id;
		} else {
			unset( $stored[ $location ] );
		}
		update_option( 'generate_page_header_global_locations', $stored );

		$after = (array) get_option( 'generate_page_header_global_locations', array() );
		$new   = isset( $after[ $location ] ) ? (int) $after[ $location ] : 0;

		return array(
			'location' => $location,
			'old'      => $old > 0 ? $old : null,
			'new'      => $new > 0 ? $new : null,
		);
	}

	// -------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------

	/**
	 * Every location key this site accepts, mirroring the rows GP Premium's
	 * own Global Locations screen builds (page-header/functions/
	 * global-locations.php:71-162): blog, search results, 404, one row per
	 * public post type, one per post type archive (excluding
	 * attachment/page/post) and one per public taxonomy.
	 *
	 * Post types and taxonomies share this key space in GP's own option, so
	 * a taxonomy named like a post type would collide there too — we report
	 * the keys as GP builds them rather than inventing a namespace it would
	 * not read back.
	 *
	 * @return array Map of location key => human label.
	 */
	private static function available_locations(): array {
		$locations = array(
			'blog'           => __( 'Blog / posts page', 'generatepress-mcp-ability' ),
			'search_results' => __( 'Search results', 'generatepress-mcp-ability' ),
			'404'            => __( '404 page', 'generatepress-mcp-ability' ),
		);

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$locations[ $post_type->name ] = sprintf(
				/* translators: %s: post type label */
				__( 'Single: %s', 'generatepress-mcp-ability' ),
				$post_type->label
			);
			if ( in_array( $post_type->name, array( 'attachment', 'page', 'post' ), true ) ) {
				continue;
			}
			$locations[ $post_type->name . '_archives' ] = sprintf(
				/* translators: %s: post type label */
				__( 'Archive: %s', 'generatepress-mcp-ability' ),
				$post_type->label
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( isset( $locations[ $taxonomy->name ] ) ) {
				continue;
			}
			$locations[ $taxonomy->name ] = sprintf(
				/* translators: %s: taxonomy label */
				__( 'Taxonomy: %s', 'generatepress-mcp-ability' ),
				$taxonomy->label
			);
		}

		return $locations;
	}
}
