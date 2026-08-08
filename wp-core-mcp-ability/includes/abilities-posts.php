<?php
/**
 * Generic post CRUD abilities (any allowed post type).
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Posts {

	/** Advisory (non-blocking) threshold for update-post's large-field warning. */
	const LARGE_FIELD_WARNING_THRESHOLD = 102400; // 100KB.

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/list-posts',
			array(
				'label'               => __( 'List posts', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists posts of any public post type (post, page, attachment, or any custom post type registered public or show_in_rest). Filter by status and search term.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'default'     => 'post',
							'description' => 'Post type to list. Default "post".',
						),
						'status'    => array(
							'type'        => 'string',
							'default'     => 'publish',
							'description' => 'Post status filter, e.g. "publish", "draft", "any". Default "publish".',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Search term.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum posts to return. Default 20.',
						),
						'page'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => 'Result page. Default 1.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "total" and "posts" (array of {id, title, status, type, date, parent}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-post',
			array(
				'label'               => __( 'Get a post', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns a post\'s core fields: title, content, excerpt, status, type, author, dates, slug and parent (for hierarchical types).', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Post core fields.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( __CLASS__, 'permission_read_post' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/create-post',
			array(
				'label'               => __( 'Create a post', 'wp-core-mcp-ability' ),
				'description'         => __( 'Creates a new post of the given post type. title is required; content, excerpt, status (default "draft") and parent (hierarchical types only) are optional.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'default'     => 'post',
							'description' => 'Post type. Default "post".',
						),
						'title'     => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Post title.',
						),
						'content'   => array(
							'type'        => 'string',
							'description' => 'Post content.',
						),
						'excerpt'   => array(
							'type'        => 'string',
							'description' => 'Post excerpt.',
						),
						'status'    => array(
							'type'        => 'string',
							'default'     => 'draft',
							'description' => 'Post status. Default "draft".',
						),
						'parent'    => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Parent post id (hierarchical post types only).',
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created post.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( __CLASS__, 'permission_create' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-post',
			array(
				'label'               => __( 'Update a post', 'wp-core-mcp-ability' ),
				'description'         => __( 'Updates one or more core fields of an existing post: title, content, excerpt, status, parent. Returns {old,new} per changed field, read back after the write.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post id.',
						),
						'title'   => array( 'type' => 'string', 'minLength' => 1 ),
						'content' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
						'parent'  => array( 'type' => 'integer', 'minimum' => 0 ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/delete-post',
			array(
				'label'               => __( 'Delete a post', 'wp-core-mcp-ability' ),
				'description'         => __( 'Deletes a post. Requires confirm: true. By default the post is moved to trash (post types that support it); set force: true to delete permanently and bypass trash.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post id.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
						'force'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = delete permanently, false (default) = move to trash.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (previous status) and "new" ("trash" or "deleted").',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( __CLASS__, 'permission_delete_post' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-post-meta',
			array(
				'label'               => __( 'Get post meta', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns one post meta value, or all meta (as a key => [values] map) when key is omitted. Sensitive-looking keys are filtered out when returning all meta.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post id.',
						),
						'key' => array(
							'type'        => 'string',
							'description' => 'Meta key. Omit to return all meta.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "meta".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_meta' ),
				'permission_callback' => array( __CLASS__, 'permission_read_post' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-post-meta',
			array(
				'label'               => __( 'Update post meta', 'wp-core-mcp-ability' ),
				'description'         => __( 'Sets one post meta key to a value (empty string deletes the key). Keys matching the sensitive-key pattern are refused. Returns {old,new}.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post id.',
						),
						'key'   => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Meta key.',
						),
						'value' => array(
							'description' => 'New value. Empty string deletes the key.',
						),
					),
					'required'             => array( 'id', 'key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "key", "old" and "new".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_meta' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		// Revisions are deliberately excluded from Plugin::is_allowed_post_type()
		// (list-posts/get-post reject post_type "revision" by design — a
		// revision has no meaningful status/parent semantics as a browsable
		// content item). These 3 verbs are the dedicated, correct surface
		// for revision access instead of loosening that general exclusion.

		wp_register_ability(
			'wp-core-mcp/list-revisions',
			array(
				'label'               => __( 'List post revisions', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists the revision history of a post: id, author, date and whether each is an autosave.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Parent post id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" (parent) and "revisions" (array of {id, author, date, is_autosave}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_revisions' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-revision',
			array(
				'label'               => __( 'Get a post revision', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns one revision\'s stored title, content and excerpt, plus its parent post id.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'revision_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Revision id (from list-revisions).',
						),
					),
					'required'             => array( 'revision_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "parent_id", "title", "content", "excerpt", "author", "date".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_revision' ),
				'permission_callback' => array( __CLASS__, 'permission_read_revision' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/restore-revision',
			array(
				'label'               => __( 'Restore a post revision', 'wp-core-mcp-ability' ),
				'description'         => __( 'Restores the parent post\'s title, content and excerpt to match the given revision. Requires confirm: true. Returns {old,new} for the parent post\'s content fields, read back after the write. WordPress automatically saves the parent\'s pre-restore state as a new revision first, so this is itself reversible via another restore-revision call.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'revision_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Revision id to restore (from list-revisions).',
						),
						'confirm'     => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed. Overwrites the parent post\'s current content.',
						),
					),
					'required'             => array( 'revision_id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "parent_id" and "updated": {title,content,excerpt} each {old,new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_restore_revision' ),
				'permission_callback' => array( __CLASS__, 'permission_restore_revision' ),
				'meta'                => Plugin::meta( false, true, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/patch-post',
			array(
				'label'               => __( 'Find-and-replace within a post field', 'wp-core-mcp-ability' ),
				'description'         => __( 'Replaces text within one field (title, content or excerpt) of a post, without resending the whole field — for surgical fixes to large pages where update-post would require transmitting the entire content. dry_run and confirm are REQUIRED on every call — there is no default. Call once with dry_run: true first: it reports match_count and a ~80-character context snippet per match, and never writes. On a real write (dry_run: false, confirm: true), resolve which match(es) to touch via occurrence: omit it when "find" is unique (auto-resolves to that one); otherwise pass "first", "all", or a 1-based integer — an ambiguous multi-match call with occurrence omitted is refused. Pass expected_count to refuse the write outright if the live match count no longer equals what an earlier dry_run reported (guards against the content having changed since). find is a literal substring by default; set regex: true to match it as a PCRE pattern (no delimiters — wrapped internally; backreferences in replace are NOT supported, replace is always literal). Every write verifies, before returning, that everything outside the matched span(s) is byte-for-byte unchanged (old_hash/new_hash sha256 + integrity_verified), and is rejected with a concurrent_modification error if the post was modified by anything else between this call\'s read and write.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'              => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post id.',
						),
						'field'           => array(
							'type'        => 'string',
							'enum'        => array( 'title', 'content', 'excerpt' ),
							'default'     => 'content',
							'description' => 'Which field to patch. Default "content".',
						),
						'find'            => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Literal substring, or a PCRE pattern body (no delimiters) if regex: true.',
						),
						'regex'           => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = treat find as a PCRE pattern (matched with the u modifier). Default false (literal substring).',
						),
						'replace'         => array(
							'type'        => 'string',
							'description' => 'Replacement text. Always literal, even in regex mode (no $1/\\1 backreferences).',
						),
						'occurrence'      => array(
							'anyOf'       => array(
								array( 'type' => 'string', 'enum' => array( 'first', 'all' ) ),
								array( 'type' => 'integer', 'minimum' => 1 ),
							),
							'description' => '"first", "all", or a 1-based match index. Required when "find" matches more than once; ignored/optional when it matches exactly once.',
						),
						'expected_count'  => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Optional. If given, the write is refused when the live match count differs from this (e.g. content changed since an earlier dry_run).',
						),
						'confirm'         => array(
							'type'        => 'boolean',
							'description' => 'REQUIRED. Must be true to write (with dry_run: false).',
						),
						'dry_run'         => array(
							'type'        => 'boolean',
							'description' => 'REQUIRED. true = report matches only, write nothing.',
						),
					),
					'required'             => array( 'id', 'find', 'confirm', 'dry_run' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'dry_run: {dry_run:true, match_count, matches:[{occurrence,context}]}. Real write: {id, field, old_length, new_length, bytes_changed, old_hash, new_hash, integrity_verified, replaced_count}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_patch_post' ),
				'permission_callback' => array( __CLASS__, 'permission_edit_post' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	private static function post_type_of_input( $input ): string {
		if ( isset( $input['post_type'] ) ) {
			return (string) $input['post_type'];
		}
		if ( isset( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
			if ( $post ) {
				return $post->post_type;
			}
		}
		return 'post';
	}

	public static function permission_read( $input = null ): bool {
		$post_type = self::post_type_of_input( $input );
		if ( ! Plugin::is_allowed_post_type( $post_type ) ) {
			return false;
		}
		$obj = get_post_type_object( $post_type );
		return current_user_can( $obj->cap->edit_posts );
	}

	public static function permission_read_post( $input = null ): bool {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( ! $id ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post || ! Plugin::is_allowed_post_type( $post->post_type ) ) {
			return false;
		}
		return current_user_can( 'read_post', $id ) || current_user_can( 'edit_post', $id );
	}

	public static function permission_create( $input = null ): bool {
		$post_type = self::post_type_of_input( $input );
		if ( ! Plugin::is_allowed_post_type( $post_type ) ) {
			return false;
		}
		$obj = get_post_type_object( $post_type );
		return current_user_can( $obj->cap->create_posts ?? $obj->cap->edit_posts );
	}

	public static function permission_edit_post( $input = null ): bool {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	public static function permission_delete_post( $input = null ): bool {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		return $id > 0 && current_user_can( 'delete_post', $id );
	}

	/**
	 * Revisions can hold unpublished draft content, so — matching
	 * wp-admin's own revision.php — reading one requires edit_post on the
	 * PARENT post, not just read_post.
	 */
	private static function revision_parent_id( int $revision_id ): int {
		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return 0;
		}
		return (int) $revision->post_parent;
	}

	public static function permission_read_revision( $input = null ): bool {
		$revision_id = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
		$parent_id   = $revision_id > 0 ? self::revision_parent_id( $revision_id ) : 0;
		return $parent_id > 0 && current_user_can( 'edit_post', $parent_id );
	}

	public static function permission_restore_revision( $input = null ): bool {
		return self::permission_read_revision( $input );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		if ( ! Plugin::is_allowed_post_type( $post_type ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'Unknown or disallowed post type.', 'wp-core-mcp-ability' ) );
		}

		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => isset( $input['status'] ) ? (string) $input['status'] : 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		$query = new \WP_Query( $args );

		$posts = array();
		foreach ( $query->posts as $post ) {
			$posts[] = array(
				'id'     => (int) $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
				'type'   => $post->post_type,
				'date'   => $post->post_date,
				'parent' => (int) $post->post_parent,
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'posts' => $posts,
		);
	}

	public static function cb_get( $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post || ! Plugin::is_allowed_post_type( $post->post_type ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		return array(
			'id'        => (int) $post->ID,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'content'   => $post->post_content,
			'excerpt'   => $post->post_excerpt,
			'status'    => $post->post_status,
			'slug'      => $post->post_name,
			'author'    => (int) $post->post_author,
			'date'      => $post->post_date,
			'modified'  => $post->post_modified,
			'parent'    => (int) $post->post_parent,
			'permalink' => get_permalink( $post ),
		);
	}

	public static function cb_create( $input ) {
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		if ( ! Plugin::is_allowed_post_type( $post_type ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'Unknown or disallowed post type.', 'wp-core-mcp-ability' ) );
		}

		$args = array(
			'post_type'    => $post_type,
			'post_title'   => (string) $input['title'],
			'post_content' => isset( $input['content'] ) ? (string) $input['content'] : '',
			'post_excerpt' => isset( $input['excerpt'] ) ? (string) $input['excerpt'] : '',
			'post_status'  => isset( $input['status'] ) ? (string) $input['status'] : 'draft',
		);
		if ( isset( $input['parent'] ) && is_post_type_hierarchical( $post_type ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		// wp_insert_post()/wp_update_post() expect SLASHED data (the classic
		// WP core "magic quotes" contract) — passing raw content strips any
		// literal backslash they contain (e.g. `-` in Gutenberg block
		// JSON attributes becomes `u002d`), corrupting block validation.
		// Confirmed empirically 2026-08-08; see docs/KOPRU-EKSIKLERI.md.
		$id = wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return self::cb_get( array( 'id' => $id ) );
	}

	public static function cb_update( $input ) {
		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! $post || ! Plugin::is_allowed_post_type( $post->post_type ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$field_map = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
		);

		$args    = array( 'ID' => $id );
		$updated = array();
		foreach ( $field_map as $in_key => $post_key ) {
			if ( array_key_exists( $in_key, $input ) ) {
				$args[ $post_key ]  = (string) $input[ $in_key ];
				$updated[ $in_key ] = array( 'old' => $post->$post_key );
			}
		}
		if ( array_key_exists( 'parent', $input ) && is_post_type_hierarchical( $post->post_type ) ) {
			$args['post_parent']   = (int) $input['parent'];
			$updated['parent']     = array( 'old' => (int) $post->post_parent );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'wp-core-mcp-ability' ) );
		}

		// See cb_create()'s identical wp_slash() note.
		$result = wp_update_post( wp_slash( $args ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fresh = get_post( $id );
		foreach ( $field_map as $in_key => $post_key ) {
			if ( isset( $updated[ $in_key ] ) ) {
				$updated[ $in_key ]['new'] = $fresh->$post_key;
			}
		}
		if ( isset( $updated['parent'] ) ) {
			$updated['parent']['new'] = (int) $fresh->post_parent;
		}

		$response = array(
			'id'      => $id,
			'updated' => $updated,
		);

		// Advisory only — never blocks the write. A large text field
		// resent whole is the exact "168 KB anasayfa" scenario that
		// prompted patch-post's expected_count/regex/lock upgrade; this
		// surfaces the better tool for NEXT time instead of only
		// discovering it after the fact.
		$large_field = self::largest_text_field_over_threshold( $updated, array( 'title', 'content', 'excerpt' ) );
		if ( null !== $large_field ) {
			$response['warning'] = sprintf(
				/* translators: 1: field name, 2: byte size, 3: threshold in KB */
				__( 'The "%1$s" field is %2$d bytes — over the %3$dKB advisory threshold. For small future edits to this field, consider patch-post instead of resending the whole field.', 'wp-core-mcp-ability' ),
				$large_field['field'],
				$large_field['size'],
				self::LARGE_FIELD_WARNING_THRESHOLD / 1024
			);
		}

		return $response;
	}

	/**
	 * @return array{field: string, size: int}|null
	 */
	private static function largest_text_field_over_threshold( array $updated, array $fields ): ?array {
		$largest = null;
		foreach ( $fields as $field ) {
			if ( ! isset( $updated[ $field ]['new'] ) ) {
				continue;
			}
			$size = strlen( (string) $updated[ $field ]['new'] );
			if ( $size > self::LARGE_FIELD_WARNING_THRESHOLD && ( null === $largest || $size > $largest['size'] ) ) {
				$largest = array( 'field' => $field, 'size' => $size );
			}
		}
		return $largest;
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a post.', 'wp-core-mcp-ability' ) );
		}

		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! $post || ! Plugin::is_allowed_post_type( $post->post_type ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$old   = $post->post_status;
		$force = ! empty( $input['force'] );

		$result = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The post could not be deleted.', 'wp-core-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => $force ? 'deleted' : 'trash',
		);
	}

	public static function cb_get_meta( $input ) {
		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! $post || ! Plugin::is_allowed_post_type( $post->post_type ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		if ( isset( $input['key'] ) && '' !== $input['key'] ) {
			$key = (string) $input['key'];
			return array(
				'id'   => $id,
				'meta' => array( $key => get_post_meta( $id, $key ) ),
			);
		}

		return array(
			'id'   => $id,
			'meta' => Plugin::strip_sensitive( get_post_meta( $id ) ),
		);
	}

	public static function cb_update_meta( $input ) {
		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! $post || ! Plugin::is_allowed_post_type( $post->post_type ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$key = (string) $input['key'];
		if ( Plugin::is_sensitive_key( $key ) ) {
			return new \WP_Error( 'sensitive_key', __( 'This key may contain secrets and cannot be written through this ability.', 'wp-core-mcp-ability' ) );
		}

		$old = get_post_meta( $id, $key, true );

		if ( '' === $input['value'] ) {
			delete_post_meta( $id, $key );
		} else {
			// update_post_meta() expects SLASHED data, same as
			// wp_insert_post()/wp_update_post() — see cb_create()'s note.
			// wp_slash() recurses into arrays/objects, so this is correct
			// whether value is a scalar or a structured value.
			update_post_meta( $id, $key, wp_slash( $input['value'] ) );
		}

		return array(
			'id'  => $id,
			'key' => $key,
			'old' => '' === $old ? null : $old,
			'new' => get_post_meta( $id, $key, true ) ?: null,
		);
	}

	public static function cb_list_revisions( $input ) {
		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! $post || ! Plugin::is_allowed_post_type( $post->post_type ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$revisions = wp_get_post_revisions( $id, array( 'order' => 'DESC' ) );

		$out = array();
		foreach ( $revisions as $revision ) {
			$out[] = array(
				'id'          => (int) $revision->ID,
				'author'      => (int) $revision->post_author,
				'date'        => $revision->post_date,
				'is_autosave' => wp_is_post_autosave( $revision ) ? true : false,
			);
		}

		return array(
			'id'        => $id,
			'revisions' => $out,
		);
	}

	public static function cb_get_revision( $input ) {
		$revision_id = (int) $input['revision_id'];
		$revision    = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new \WP_Error( 'revision_not_found', __( 'No revision exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		return array(
			'id'        => (int) $revision->ID,
			'parent_id' => (int) $revision->post_parent,
			'title'     => $revision->post_title,
			'content'   => $revision->post_content,
			'excerpt'   => $revision->post_excerpt,
			'author'    => (int) $revision->post_author,
			'date'      => $revision->post_date,
		);
	}

	public static function cb_restore_revision( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to restore a revision. This overwrites the parent post\'s current content.', 'wp-core-mcp-ability' ) );
		}

		$revision_id = (int) $input['revision_id'];
		$revision    = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new \WP_Error( 'revision_not_found', __( 'No revision exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$parent_id = (int) $revision->post_parent;
		$before    = get_post( $parent_id );
		if ( ! $before ) {
			return new \WP_Error( 'post_not_found', __( 'The revision\'s parent post no longer exists.', 'wp-core-mcp-ability' ) );
		}

		$result = wp_restore_post_revision( $revision_id );
		if ( ! $result || is_wp_error( $result ) ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'restore_failed', __( 'The revision could not be restored.', 'wp-core-mcp-ability' ) );
		}

		$after = get_post( $parent_id );

		return array(
			'parent_id' => $parent_id,
			'updated'   => array(
				'title'   => array( 'old' => $before->post_title, 'new' => $after->post_title ),
				'content' => array( 'old' => $before->post_content, 'new' => $after->post_content ),
				'excerpt' => array( 'old' => $before->post_excerpt, 'new' => $after->post_excerpt ),
			),
		);
	}

	/**
	 * Short before/after snippet around a byte offset, for match context
	 * in patch-post's dry_run report and error messages. ~80 characters
	 * of surrounding context (40 each side).
	 */
	private static function context_snippet( string $text, int $offset, int $length ): string {
		$pad   = 40;
		$start = max( 0, $offset - $pad );
		$end   = min( strlen( $text ), $offset + $length + $pad );
		$snip  = substr( $text, $start, $end - $start );
		return ( $start > 0 ? '…' : '' ) . $snip . ( $end < strlen( $text ) ? '…' : '' );
	}

	/**
	 * Every occurrence of $find in $text, as [{offset, length, matched_text}]
	 * sorted ascending, non-overlapping. Literal mode: byte-offset strpos
	 * scan, matched_text is always $find verbatim. Regex mode: $find is
	 * wrapped as a PCRE pattern (caller-supplied body, no delimiters) and
	 * matched with preg_match_all/PREG_OFFSET_CAPTURE — offsets from that
	 * are already bytes (not characters), consistent with the literal
	 * path, so both feed the same byte-precise replacement logic below.
	 *
	 * @return array|\WP_Error
	 */
	private static function find_matches( string $text, string $find, bool $regex ) {
		if ( ! $regex ) {
			$matches     = array();
			$search_from = 0;
			while ( false !== ( $pos = strpos( $text, $find, $search_from ) ) ) {
				$matches[]   = array( 'offset' => $pos, 'length' => strlen( $find ), 'matched_text' => $find );
				$search_from = $pos + max( 1, strlen( $find ) );
			}
			return $matches;
		}

		if ( strlen( $find ) > 500 ) {
			return new \WP_Error( 'regex_too_long', __( 'Pattern is too long (500 char max) — split into a simpler pattern or use literal find.', 'wp-core-mcp-ability' ) );
		}
		$pattern = '/' . str_replace( '/', '\\/', $find ) . '/u';
		// @ suppresses the E_WARNING preg_match_all itself would raise for
		// an invalid pattern; we surface it as a WP_Error instead.
		$ok = @preg_match_all( $pattern, $text, $raw, PREG_OFFSET_CAPTURE );
		if ( false === $ok ) {
			return new \WP_Error(
				'invalid_regex',
				sprintf(
					/* translators: %s: PCRE error message */
					__( 'Invalid regular expression: %s', 'wp-core-mcp-ability' ),
					preg_last_error_msg()
				)
			);
		}

		$matches = array();
		foreach ( $raw[0] as $m ) {
			$matches[] = array( 'offset' => (int) $m[1], 'length' => strlen( $m[0] ), 'matched_text' => $m[0] );
		}
		return $matches;
	}

	/**
	 * Applies $replace at every given match (ascending, non-overlapping
	 * offsets) in one pass, and returns both the new text and where each
	 * replacement landed in it (needed to verify integrity afterward).
	 *
	 * @return array{0: string, 1: array} [new_text, new_spans]
	 */
	private static function apply_matches( string $text, array $matches, string $replace ): array {
		$result    = '';
		$cursor    = 0;
		$new_spans = array();
		foreach ( $matches as $m ) {
			$result   .= substr( $text, $cursor, $m['offset'] - $cursor );
			$new_start = strlen( $result );
			$result   .= $replace;
			$new_spans[] = array( 'offset' => $new_start, 'length' => strlen( $replace ) );
			$cursor      = $m['offset'] + $m['length'];
		}
		$result .= substr( $text, $cursor );
		return array( $result, $new_spans );
	}

	/**
	 * Proves everything OUTSIDE the intended edit spans is byte-for-byte
	 * unchanged: rebuilds $original from $patched by substituting each
	 * matched span's ORIGINAL text back into where it now sits in
	 * $patched, then hash-compares the reconstruction against the real
	 * pre-write content. If they differ, something other than the
	 * declared replacement(s) changed — a stronger guarantee than a
	 * simple length check, since it catches any surrounding corruption
	 * regardless of whether it happened to net out to the same length.
	 */
	private static function verify_integrity( string $original, string $patched, array $matches, array $new_spans ): bool {
		$reconstructed = '';
		$cursor        = 0;
		foreach ( $new_spans as $i => $span ) {
			$reconstructed .= substr( $patched, $cursor, $span['offset'] - $cursor );
			$reconstructed .= $matches[ $i ]['matched_text'];
			$cursor         = $span['offset'] + $span['length'];
		}
		$reconstructed .= substr( $patched, $cursor );
		return hash( 'sha256', $reconstructed ) === hash( 'sha256', $original );
	}

	public static function cb_patch_post( $input ) {
		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! $post || ! Plugin::is_allowed_post_type( $post->post_type ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'wp-core-mcp-ability' ) );
		}
		// Version token for the optimistic per-post write lock below —
		// captured now, re-checked immediately before the actual write so
		// a concurrent patch-post/update-post call in between is detected
		// instead of silently overwritten.
		$base_modified_gmt = $post->post_modified_gmt;

		$field_map = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		);
		$field     = isset( $input['field'] ) ? (string) $input['field'] : 'content';
		if ( ! isset( $field_map[ $field ] ) ) {
			return new \WP_Error( 'invalid_field', __( 'field must be one of: title, content, excerpt.', 'wp-core-mcp-ability' ) );
		}
		$post_key = $field_map[ $field ];
		$current  = (string) $post->$post_key;

		$find    = (string) $input['find'];
		$regex   = ! empty( $input['regex'] );
		$replace = isset( $input['replace'] ) ? (string) $input['replace'] : '';
		$dry_run = (bool) $input['dry_run'];

		$matches = self::find_matches( $current, $find, $regex );
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}
		$count = count( $matches );

		if ( $dry_run ) {
			$report = array();
			foreach ( $matches as $i => $m ) {
				$report[] = array(
					'occurrence' => $i + 1,
					'context'    => self::context_snippet( $current, $m['offset'], $m['length'] ),
				);
			}
			return array(
				'dry_run'     => true,
				'field'       => $field,
				'match_count' => $count,
				'matches'     => $report,
			);
		}

		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to write (with dry_run: false).', 'wp-core-mcp-ability' ) );
		}

		if ( isset( $input['expected_count'] ) && (int) $input['expected_count'] !== $count ) {
			return new \WP_Error(
				'count_mismatch',
				sprintf(
					/* translators: 1: expected count, 2: actual count */
					__( 'expected_count was %1$d but "find" currently matches %2$d time(s) — the content likely changed since you checked. Run dry_run: true again before writing.', 'wp-core-mcp-ability' ),
					(int) $input['expected_count'],
					$count
				)
			);
		}

		if ( 0 === $count ) {
			return new \WP_Error( 'no_match', __( 'The "find" text was not found in this field. Nothing was written.', 'wp-core-mcp-ability' ) );
		}

		if ( 1 === $count ) {
			$target = array( $matches[0] );
		} elseif ( ! isset( $input['occurrence'] ) ) {
			return new \WP_Error(
				'ambiguous_match',
				sprintf(
					/* translators: %d: number of matches found */
					__( '"find" occurs %1$d times in this field — a write would be ambiguous. Run with dry_run: true to see each match\'s context, then pass occurrence: "first", "all", or a 1-based index.', 'wp-core-mcp-ability' ),
					$count
				)
			);
		} elseif ( 'all' === $input['occurrence'] ) {
			$target = $matches;
		} elseif ( 'first' === $input['occurrence'] ) {
			$target = array( $matches[0] );
		} else {
			$occurrence = (int) $input['occurrence'];
			if ( $occurrence < 1 || $occurrence > $count ) {
				return new \WP_Error(
					'invalid_occurrence',
					sprintf(
						/* translators: %d: number of matches found */
						__( 'occurrence must be "first", "all", or between 1 and %d (the number of matches found).', 'wp-core-mcp-ability' ),
						$count
					)
				);
			}
			$target = array( $matches[ $occurrence - 1 ] );
		}

		list( $new_value, $new_spans ) = self::apply_matches( $current, $target, $replace );

		if ( ! self::verify_integrity( $current, $new_value, $target, $new_spans ) ) {
			// Should be unreachable by construction (apply_matches only
			// touches the declared spans) — a hard stop if it ever isn't.
			return new \WP_Error( 'integrity_check_failed', __( 'Internal error: the patch would alter content outside the matched span(s). Nothing was written.', 'wp-core-mcp-ability' ) );
		}

		// Optimistic lock: re-check the post hasn't been modified by
		// anything else since we read it above, right before writing.
		// MUST bypass get_post()/WP_Post's object cache here: within a
		// single PHP request, WordPress's non-persistent post cache is
		// process-local and never invalidates itself just because another
		// process wrote to the DB row in between — a second get_post($id)
		// call silently returns the SAME cached copy the first call
		// populated, making the check a no-op (found by testing: a real
		// two-process race showed the "recheck" still reporting the
		// pre-race timestamp even after the other process's write had
		// completed and was independently confirmed in the DB). A direct
		// $wpdb read is unambiguous regardless of cache configuration.
		global $wpdb;
		$latest_modified_gmt = $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ) );
		if ( null === $latest_modified_gmt || $latest_modified_gmt !== $base_modified_gmt ) {
			return new \WP_Error( 'concurrent_modification', __( 'This post was modified by another request since it was read. Nothing was written — re-run dry_run: true and retry.', 'wp-core-mcp-ability' ) );
		}

		// wp_update_post() expects SLASHED data — passing $new_value raw
		// let WordPress's own unslash step strip every literal backslash
		// in the field, not just inside the matched span (e.g. `-` in
		// Gutenberg block JSON attributes becomes `u002d`, breaking block
		// validation and producing invalid CSS var() values on the front
		// end). Confirmed empirically 2026-08-08; see docs/KOPRU-EKSIKLERI.md.
		$result = wp_update_post( wp_slash( array( 'ID' => $id, $post_key => $new_value ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Post-write verification: the pre-write verify_integrity() call
		// above only proves apply_matches()'s own string manipulation left
		// the untouched spans alone — it says nothing about what actually
		// landed in the database (which is exactly how the missing-slash
		// bug above went undetected: old_hash/new_hash were computed from
		// $current/$new_value, never from what was actually persisted, so
		// integrity_verified: true kept reporting success on a corrupted
		// write). Read the real row back directly ($wpdb, bypassing the
		// process-local object cache for the same reason the write lock
		// above does) and compare it byte-for-byte to what was intended.
		global $wpdb;
		$column = array(
			'post_title'   => 'post_title',
			'post_content' => 'post_content',
			'post_excerpt' => 'post_excerpt',
		)[ $post_key ];
		$actual = $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$integrity_verified = ( null !== $actual && hash( 'sha256', $actual ) === hash( 'sha256', $new_value ) );

		if ( ! $integrity_verified ) {
			return new \WP_Error(
				'write_not_verified',
				__( 'The write completed, but the content actually saved does not match what was intended — something (a WordPress or plugin filter, or an unslashing edge case) altered it in transit. The post has already been changed; inspect it directly before writing again.', 'wp-core-mcp-ability' ),
				array(
					'id'         => $id,
					'field'      => $field,
					'expected'   => $new_value,
					'actual'     => $actual,
					'old_hash'   => hash( 'sha256', $current ),
					'new_hash'   => hash( 'sha256', (string) $actual ),
				)
			);
		}

		return array(
			'id'                 => $id,
			'field'              => $field,
			'old_length'         => strlen( $current ),
			'new_length'         => strlen( $new_value ),
			'bytes_changed'      => strlen( $new_value ) - strlen( $current ),
			'old_hash'           => hash( 'sha256', $current ),
			'new_hash'           => hash( 'sha256', $actual ),
			'integrity_verified' => true,
			'replaced_count'     => count( $target ),
		);
	}
}
