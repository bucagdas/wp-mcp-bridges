<?php
/**
 * GP Premium abilities: modules, module settings and GP Elements.
 *
 * @package GeneratePressMCPAbility
 */

namespace GeneratePressMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GP {

	public static function register(): void {
		// 3. GP Elements — only when GP Premium is installed.
		if ( Plugin::has_gp_premium() ) {
			wp_register_ability(
				'generatepress-mcp/list-gp-elements',
				array(
					'label'               => __( 'List GP Elements', 'generatepress-mcp-ability' ),
					'description'         => __( 'Lists GP Premium Elements (gp_elements posts) with id, title, element type, status and modified date. Returns an error explaining how to activate the Elements module if it is not active.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => array( 'object', 'null' ),
						'properties'           => array(
							'type'     => array(
								'type'        => 'string',
								'description' => 'Optional element type filter, e.g. "block", "header", "hook", "layout".',
							),
							'per_page' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 100,
								'default'     => 20,
								'description' => 'Maximum number of elements to return. Default 20.',
							),
							'page'     => array(
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
						'description' => 'Object with "total" and "elements" (array of {id, title, type, status, modified}).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_list_gp_elements' ),
					'permission_callback' => array( Plugin::class, 'permission_gp_elements' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);
		}
		// --- Block B: GP Premium read/write verbs — only when GP Premium is installed.
		if ( Plugin::has_gp_premium() ) {

			wp_register_ability(
				'generatepress-mcp/toggle-gp-module',
				array(
					'label'               => __( 'Toggle a GP Premium module', 'generatepress-mcp-ability' ),
					'description'         => __( 'Activates or deactivates one GP Premium module by writing its generate_package_* option. Requires confirm: true. The module list and current states come from get-status. Note: module code loads on the next request, so effects are visible after this call returns.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'module'  => array(
								'type'        => 'string',
								'enum'        => array_keys( Plugin::GP_MODULES ),
								'description' => 'Module key, e.g. "elements" or "typography".',
							),
							'active'  => array(
								'type'        => 'boolean',
								'description' => 'true to activate, false to deactivate.',
							),
							'confirm' => array(
								'type'        => 'boolean',
								'description' => 'Must be true to proceed. Toggling modules changes site behavior.',
							),
						),
						'required'             => array( 'module', 'active', 'confirm' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "module", "old" and "new" (active booleans, read back after the write).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_toggle_gp_module' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/get-module-settings',
				array(
					'label'               => __( 'Get GP Premium module settings', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns the settings option of one GP Premium module group: blog, menu_plus, secondary_nav, spacing, page_header, backgrounds or hooks. Also reports whether the module is currently active. Sensitive keys are filtered out.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'group' => array(
								'type'        => 'string',
								'enum'        => array_keys( Plugin::MODULE_SETTINGS ),
								'description' => 'Module settings group to read.',
							),
						),
						'required'             => array( 'group' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "group", "module_active" and "settings".',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_module_settings' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-module-setting',
				array(
					'label'               => __( 'Update a GP Premium module setting', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates one top-level key in a GP Premium module settings option (groups as in get-module-settings). Sensitive keys are refused. Returns the old and new value, read back after the write.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'group' => array(
								'type'        => 'string',
								'enum'        => array_keys( Plugin::MODULE_SETTINGS ),
								'description' => 'Module settings group containing the key.',
							),
							'key'   => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Top-level settings key to update.',
							),
							'value' => array(
								'description' => 'New value (string, number, boolean or array).',
							),
						),
						'required'             => array( 'group', 'key', 'value' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "group", "key", "old" and "new".',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_module_setting' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/get-gp-element',
				array(
					'label'               => __( 'Get a GP Element', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns the full detail of one GP Element: title, type, status, content, display/exclude/user conditions and internal notes.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'element_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the gp_elements post.',
							),
						),
						'required'             => array( 'element_id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Element detail: id, title, type, status, content, conditions, hook_name, hook_priority, notes.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_gp_element' ),
					'permission_callback' => array( Plugin::class, 'permission_gp_elements' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-gp-element-status',
				array(
					'label'               => __( 'Update GP Element status', 'generatepress-mcp-ability' ),
					'description'         => __( 'Publishes or drafts one GP Element. Drafting an element disables it on the site without deleting it. Publishing additionally requires the publish_post capability for this post (WordPress silently downgrades an unauthorized publish to "pending" rather than erroring; this ability detects that and returns an error naming the missing capability instead). Returns the old and new status, read back after the write.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'element_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the gp_elements post.',
							),
							'status'     => array(
								'type'        => 'string',
								'enum'        => array( 'publish', 'draft' ),
								'description' => 'New status.',
							),
						),
						'required'             => array( 'element_id', 'status' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "element_id", "old" and "new" (statuses).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_gp_element_status' ),
					'permission_callback' => array( Plugin::class, 'permission_gp_element_publish' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/delete-gp-element',
				array(
					'label'               => __( 'Delete a GP Element', 'generatepress-mcp-ability' ),
					'description'         => __( 'Deletes one GP Element. Requires confirm: true. By default the element is moved to trash; set force: true to delete permanently. This is destructive and cannot be undone when forced.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'element_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the gp_elements post.',
							),
							'confirm'    => array(
								'type'        => 'boolean',
								'description' => 'Must be true to proceed.',
							),
							'force'      => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'true = delete permanently, false (default) = move to trash.',
							),
						),
						'required'             => array( 'element_id', 'confirm' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "element_id", "old" (previous status) and "new" ("trash" or "deleted").',
					),
					'execute_callback'    => array( __CLASS__, 'cb_delete_gp_element' ),
					'permission_callback' => array( Plugin::class, 'permission_gp_element_delete' ),
					'meta'                => Plugin::meta( false, true, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/create-gp-element',
				array(
					'label'               => __( 'Create a GP Element', 'generatepress-mcp-ability' ),
					'description'         => __( 'Creates a new GP Premium Element. type is required (hook, layout, header or block); title and content are recommended. For type "hook", hook_name is required and hook_priority is optional (default 10); display_conditions defaults to the entire site if omitted, since an element with no display rule never renders anywhere. Content is validated before saving: block-comment markup must parse as valid blocks. If the acting user has unfiltered_html, raw content is accepted as-is (same as wp-admin); otherwise it must pass kses (extended with a safe inline-SVG tag/attribute whitelist — script/foreignObject/event-handler attributes stay rejected). Set dry_run: true to run validation only, without creating anything: on dry_run, invalid content never errors — it returns {dry_run:true, valid:false, reason} where reason names exactly what sanitization would change (which tag(s) were dropped entirely, which attribute(s) were dropped from which tag, or the first differing character), not just "content changed". A real (non-dry_run) write with invalid content still fails with that same detail in the error message. New elements default to status "draft"; creating directly with status "publish" additionally requires the publish_posts capability (checked before creation — gp_elements has no post ID yet at this point, so this is the site-wide capability rather than the per-post one update-gp-element-status uses). Never sets the PHP-execution hook flag — that remains a wp-admin-only, code-writing operation outside this bridge\'s scope.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'type'                => array(
								'type'        => 'string',
								'enum'        => array( 'hook', 'layout', 'header', 'block' ),
								'description' => 'Element type.',
							),
							'title'               => array(
								'type'        => 'string',
								'description' => 'Element title.',
							),
							'content'             => array(
								'type'        => 'string',
								'description' => 'Block/HTML markup content.',
							),
							'hook_name'           => array(
								'type'        => 'string',
								'description' => 'WordPress action hook name (required when type is "hook"), e.g. "generate_before_header" or a custom hook name.',
							),
							'hook_priority'       => array(
								'type'        => 'integer',
								'default'     => 10,
								'description' => 'Hook priority (type "hook" only). Default 10.',
							),
							'display_conditions'  => array(
								'type'        => 'array',
								'description' => 'Display condition rules (GP Premium condition format).',
							),
							'exclude_conditions'  => array(
								'type'        => 'array',
								'description' => 'Exclude condition rules.',
							),
							'user_conditions'     => array(
								'type'        => 'array',
								'description' => 'User/role condition rules.',
							),
							'internal_notes'      => array(
								'type'        => 'string',
								'description' => 'Internal notes (not shown on the front end).',
							),
							'status'              => array(
								'type'        => 'string',
								'enum'        => array( 'draft', 'publish' ),
								'default'     => 'draft',
								'description' => 'Initial status. Default "draft".',
							),
							'dry_run'             => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'true = validate content only, create nothing.',
							),
						),
						'required'             => array( 'type' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'The created element (or, when dry_run, {dry_run:true, valid:true}).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_create_gp_element' ),
					'permission_callback' => array( Plugin::class, 'permission_gp_element_create' ),
					'meta'                => Plugin::meta( false, false, false ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-gp-element',
				array(
					'label'               => __( 'Update a GP Element', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates one or more fields of an existing GP Element: title, content, hook_name, hook_priority, display/exclude/user conditions, internal_notes. At least one field is required. Content is validated the same way as create-gp-element (kses against a safe whitelist, unfiltered_html accounts bypass it); set dry_run: true to validate without saving — on dry_run, invalid content never errors, it returns {dry_run:true, valid:false, reason} naming exactly what would change (dropped tag(s)/attribute(s), or the first differing character). This can overwrite an element\'s working configuration, so no confirm gate — instead every call reads back and returns {old,new} per changed field so the effect is always visible. Never touches the PHP-execution hook flag.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'element_id'          => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the gp_elements post.',
							),
							'title'               => array( 'type' => 'string' ),
							'content'             => array( 'type' => 'string' ),
							'hook_name'           => array( 'type' => 'string' ),
							'hook_priority'       => array( 'type' => 'integer' ),
							'display_conditions'  => array( 'type' => 'array' ),
							'exclude_conditions'  => array( 'type' => 'array' ),
							'user_conditions'     => array( 'type' => 'array' ),
							'internal_notes'      => array( 'type' => 'string' ),
							'dry_run'             => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'true = validate content only, save nothing.',
							),
						),
						'required'             => array( 'element_id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "element_id" and "updated": per-field {old, new} (or, when dry_run, {dry_run:true, valid:true}).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_gp_element' ),
					'permission_callback' => array( Plugin::class, 'permission_gp_element_edit' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/list-disabled-elements',
				array(
					'label'               => __( 'List posts with disabled elements', 'generatepress-mcp-ability' ),
					'description'         => __( 'Lists posts and pages that have any GeneratePress Disable Elements flag set (disabled header, footer, headline, nav, etc.), with the list of disabled elements per post.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => array( 'object', 'null' ),
						'properties'           => array(
							'per_page' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 100,
								'default'     => 20,
								'description' => 'Maximum number of posts to return. Default 20.',
							),
							'page'     => array(
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
						'description' => 'Object with "total" and "posts": array of {id, title, post_type, disabled}.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_list_disabled_elements' ),
					'permission_callback' => array( Plugin::class, 'permission_edit_posts' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);
		}

	}

	// ---------------------------------------------------------------------
	// Execute callbacks
	// ---------------------------------------------------------------------

	public static function cb_list_gp_elements( $input ) {
		if ( ! post_type_exists( 'gp_elements' ) ) {
			return new \WP_Error(
				'elements_module_inactive',
				__( 'The GP Premium Elements module is not active, so no gp_elements post type exists. Activate it under Appearance > GeneratePress (option generate_package_elements).', 'generatepress-mcp-ability' )
			);
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;

		$args = array(
			'post_type'      => 'gp_elements',
			'post_status'    => 'any',
			'posts_per_page' => min( 100, max( 1, $per_page ) ),
			'paged'          => max( 1, $page ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! empty( $input['type'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				array(
					'key'   => '_generate_element_type',
					'value' => sanitize_text_field( (string) $input['type'] ),
				),
			);
		}

		$query = new \WP_Query( $args );

		$elements = array();
		foreach ( $query->posts as $post ) {
			$elements[] = array(
				'id'       => (int) $post->ID,
				'title'    => $post->post_title,
				'type'     => (string) get_post_meta( $post->ID, '_generate_element_type', true ),
				'status'   => $post->post_status,
				'modified' => $post->post_modified,
			);
		}

		return array(
			'total'    => (int) $query->found_posts,
			'elements' => $elements,
		);
	}

	public static function cb_toggle_gp_module( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to toggle a module. Toggling modules changes site behavior.', 'generatepress-mcp-ability' ) );
		}

		$module = (string) $input['module'];
		if ( ! isset( Plugin::GP_MODULES[ $module ] ) ) {
			return new \WP_Error( 'unknown_module', __( 'Unknown module key. See get-status for the module list.', 'generatepress-mcp-ability' ) );
		}

		$option   = 'generate_package_' . $module;
		$constant = Plugin::GP_MODULES[ $module ];
		$old      = function_exists( 'generatepress_is_module_active' )
			? generatepress_is_module_active( $option, $constant )
			: ( 'activated' === get_option( $option ) );

		if ( ! empty( $input['active'] ) ) {
			update_option( $option, 'activated' );
		} else {
			if ( defined( $constant ) ) {
				return new \WP_Error( 'module_forced_active', sprintf(
					/* translators: %s: constant name */
					__( 'This module is force-activated via the %s constant and cannot be deactivated by option.', 'generatepress-mcp-ability' ),
					$constant
				) );
			}
			delete_option( $option );
		}

		$new = 'activated' === get_option( $option ) || defined( $constant );

		return array(
			'module' => $module,
			'old'    => (bool) $old,
			'new'    => (bool) $new,
		);
	}

	public static function cb_get_module_settings( $input ) {
		$group = (string) $input['group'];
		if ( ! isset( Plugin::MODULE_SETTINGS[ $group ] ) ) {
			return new \WP_Error( 'invalid_group', __( 'Unknown module settings group.', 'generatepress-mcp-ability' ) );
		}

		$settings = get_option( Plugin::MODULE_SETTINGS[ $group ], array() );

		$module_active = null;
		if ( isset( Plugin::GP_MODULES[ $group ] ) && function_exists( 'generatepress_is_module_active' ) ) {
			$module_active = generatepress_is_module_active( 'generate_package_' . $group, Plugin::GP_MODULES[ $group ] );
		}

		return array(
			'group'         => $group,
			'module_active' => $module_active,
			'settings'      => is_array( $settings ) ? Plugin::strip_sensitive( $settings ) : $settings,
		);
	}

	public static function cb_update_module_setting( $input ) {
		$group = (string) $input['group'];
		$key   = (string) $input['key'];

		if ( ! isset( Plugin::MODULE_SETTINGS[ $group ] ) ) {
			return new \WP_Error( 'invalid_group', __( 'Unknown module settings group.', 'generatepress-mcp-ability' ) );
		}
		if ( Plugin::is_sensitive_key( $key ) ) {
			return new \WP_Error( 'sensitive_key', __( 'This key may contain secrets and cannot be written through this ability.', 'generatepress-mcp-ability' ) );
		}

		$option_name = Plugin::MODULE_SETTINGS[ $group ];
		$settings    = get_option( $option_name, array() );
		if ( ! is_array( $settings ) ) {
			return new \WP_Error( 'invalid_option', __( 'The module settings option is not stored as an array.', 'generatepress-mcp-ability' ) );
		}

		$old              = array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
		$settings[ $key ] = $input['value'];
		update_option( $option_name, $settings );

		$readback = get_option( $option_name, array() );
		$new      = is_array( $readback ) && array_key_exists( $key, $readback ) ? $readback[ $key ] : null;

		return array(
			'group' => $group,
			'key'   => $key,
			'old'   => $old,
			'new'   => $new,
		);
	}

	/**
	 * Validates block/HTML markup before it is saved to a GP Element or
	 * GB global style. Two checks:
	 *   1. If the content contains block comments ("<!-- wp:") but
	 *      has_blocks() finds none, the markup is malformed (unbalanced
	 *      or unrecognized block syntax).
	 *   2. wp_kses_post() must not change the content — if it does,
	 *      the input contained disallowed HTML/attributes and is
	 *      rejected rather than silently stripped.
	 * Returns true when valid, or a WP_Error describing what failed.
	 */
	private static function get_element_post( int $element_id ) {
		if ( ! post_type_exists( 'gp_elements' ) ) {
			return new \WP_Error(
				'elements_module_inactive',
				__( 'The GP Premium Elements module is not active. Activate it first (toggle-gp-module with module "elements").', 'generatepress-mcp-ability' )
			);
		}
		$post = get_post( $element_id );
		if ( ! $post || 'gp_elements' !== $post->post_type ) {
			return new \WP_Error( 'element_not_found', __( 'No GP Element exists with the given ID.', 'generatepress-mcp-ability' ) );
		}
		return $post;
	}

	/**
	 * GP Premium's own admin UI stores content in two DIFFERENT places
	 * depending on element type, and its hook/header renderers only ever
	 * read the meta copy — post_content is never consulted for those two
	 * types (confirmed in gp-premium/elements/class-metabox.php's
	 * "hook"/"header" textarea and class-hooks.php/class-hero.php's
	 * renderers):
	 *   - type hook/header: raw content lives in postmeta
	 *     "_generate_element_content" (a dedicated textarea in wp-admin,
	 *     NOT the block editor).
	 *   - type block/layout: content lives in the normal post_content
	 *     column (block editor).
	 * Writing content to post_content for a hook/header element is why
	 * such elements previously rendered as empty on the front end even
	 * once display_conditions were correct — GeneratePress_Hook::execute_hook()
	 * only ever echoes the postmeta copy.
	 */
	private static function uses_content_meta( string $type ): bool {
		return in_array( $type, array( 'hook', 'header' ), true );
	}

	private static function read_element_content( int $post_id, string $type ): string {
		if ( self::uses_content_meta( $type ) ) {
			return (string) get_post_meta( $post_id, '_generate_element_content', true );
		}
		return (string) get_post_field( 'post_content', $post_id );
	}

	/**
	 * Writes content to whichever storage GP Premium actually reads for
	 * this element type (see read_element_content()), and clears the
	 * other one so a stale copy never lingers in two places.
	 */
	private static function write_element_content( int $post_id, string $type, string $content ): void {
		// update_post_meta()/wp_update_post() both expect SLASHED data (the
		// classic WP core "magic quotes" contract) — passing raw content
		// let WordPress's own unslash step strip every literal backslash
		// the content contains, not just wherever a caller might expect
		// escaping (e.g. `-` in Gutenberg block JSON attributes becomes
		// `u002d`, breaking block validation). Confirmed empirically
		// 2026-08-08; see docs/KOPRU-EKSIKLERI.md.
		if ( self::uses_content_meta( $type ) ) {
			if ( '' !== $content ) {
				update_post_meta( $post_id, '_generate_element_content', wp_slash( $content ) );
			} else {
				delete_post_meta( $post_id, '_generate_element_content' );
			}
			wp_update_post( array( 'ID' => $post_id, 'post_content' => '' ), true );
		} else {
			wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content ) ), true );
			delete_post_meta( $post_id, '_generate_element_content' );
		}
	}

	/**
	 * GP Premium's own wp-admin UI refuses to consider a published,
	 * non-block element "live" without a Display Rules location (it shows
	 * an inline warning to that effect — see class-metabox.php's
	 * "elements-no-location-error"), and GeneratePress_Conditions::show_data()
	 * returns false (never shows) when _generate_element_display_conditions
	 * is empty. type "block" is exempt because block elements are placed by
	 * inserting a GP Element block, not by a location rule.
	 */
	const DEFAULT_DISPLAY_CONDITIONS = array(
		array( 'rule' => 'general:site', 'object' => '' ),
	);

	private static function needs_display_conditions( string $type ): bool {
		return 'block' !== $type;
	}

	public static function cb_get_gp_element( $input ) {
		$post = self::get_element_post( (int) $input['element_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$type = (string) get_post_meta( $post->ID, '_generate_element_type', true );

		return array(
			'id'                 => (int) $post->ID,
			'title'              => $post->post_title,
			'type'               => $type,
			'status'             => $post->post_status,
			'content'            => self::read_element_content( $post->ID, $type ),
			'display_conditions' => get_post_meta( $post->ID, '_generate_element_display_conditions', true ),
			'exclude_conditions' => get_post_meta( $post->ID, '_generate_element_exclude_conditions', true ),
			'user_conditions'    => get_post_meta( $post->ID, '_generate_element_user_conditions', true ),
			'hook_name'          => (string) get_post_meta( $post->ID, '_generate_hook', true ),
			'hook_priority'      => '' === get_post_meta( $post->ID, '_generate_hook_priority', true ) ? null : (int) get_post_meta( $post->ID, '_generate_hook_priority', true ),
			'internal_notes'     => (string) get_post_meta( $post->ID, '_generate_element_internal_notes', true ),
			'modified'           => $post->post_modified,
		);
	}

	public static function cb_update_gp_element_status( $input ) {
		$post = self::get_element_post( (int) $input['element_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$old            = $post->post_status;
		$requested      = (string) $input['status'];
		$result         = wp_update_post(
			wp_slash( array(
				'ID'          => $post->ID,
				'post_status' => $requested,
			) ),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new = get_post_status( $post->ID );
		// wp_update_post() does not error when the acting user lacks
		// publish_post — WordPress core silently stores "pending" instead
		// of "publish" (see map_meta_cap()/_wp_translate_postdata()).
		// permission_gp_element_publish() should already catch this before
		// we get here, but detect and name it explicitly as a fallback so
		// a caller never sees an unexplained status mismatch.
		if ( 'publish' === $requested && 'publish' !== $new ) {
			return new \WP_Error(
				'status_not_applied',
				sprintf(
					/* translators: 1: requested status, 2: actual resulting status */
					__( 'Requested status "%1$s" but the element was saved as "%2$s" instead. The acting user likely lacks the publish_post capability for this element.', 'generatepress-mcp-ability' ),
					$requested,
					$new
				)
			);
		}

		return array(
			'element_id' => (int) $post->ID,
			'old'        => $old,
			'new'        => $new,
		);
	}

	public static function cb_delete_gp_element( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a GP Element.', 'generatepress-mcp-ability' ) );
		}

		$post = self::get_element_post( (int) $input['element_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$old   = $post->post_status;
		$force = ! empty( $input['force'] );

		$result = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The element could not be deleted.', 'generatepress-mcp-ability' ) );
		}

		return array(
			'element_id' => (int) $post->ID,
			'old'        => $old,
			'new'        => $force ? 'deleted' : 'trash',
		);
	}

	public static function cb_create_gp_element( $input ) {
		if ( ! post_type_exists( 'gp_elements' ) ) {
			return new \WP_Error(
				'elements_module_inactive',
				__( 'The GP Premium Elements module is not active. Activate it first (toggle-gp-module with module "elements").', 'generatepress-mcp-ability' )
			);
		}

		$type = (string) $input['type'];
		if ( 'hook' === $type && empty( $input['hook_name'] ) ) {
			return new \WP_Error( 'missing_hook_name', __( 'hook_name is required when type is "hook".', 'generatepress-mcp-ability' ) );
		}

		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		$valid   = Plugin::validate_block_markup( $content );
		if ( is_wp_error( $valid ) ) {
			if ( ! empty( $input['dry_run'] ) ) {
				return array(
					'dry_run' => true,
					'valid'   => false,
					'reason'  => $valid->get_error_message(),
				);
			}
			return $valid;
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'valid'   => true,
			);
		}

		// wp_insert_post()/update_post_meta() expect SLASHED data — see
		// write_element_content()'s note above.
		$post_id = wp_insert_post(
			wp_slash( array(
				'post_type'   => 'gp_elements',
				'post_title'  => isset( $input['title'] ) ? (string) $input['title'] : __( '(no title)', 'generatepress-mcp-ability' ),
				'post_status' => isset( $input['status'] ) ? (string) $input['status'] : 'draft',
			) ),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_generate_element_type', $type );
		self::write_element_content( $post_id, $type, $content );
		if ( 'hook' === $type ) {
			update_post_meta( $post_id, '_generate_hook', wp_slash( (string) $input['hook_name'] ) );
			update_post_meta( $post_id, '_generate_hook_priority', isset( $input['hook_priority'] ) ? (int) $input['hook_priority'] : 10 );
		}
		foreach ( array(
			'display_conditions' => '_generate_element_display_conditions',
			'exclude_conditions' => '_generate_element_exclude_conditions',
			'user_conditions'    => '_generate_element_user_conditions',
		) as $in_key => $meta_key ) {
			if ( isset( $input[ $in_key ] ) ) {
				update_post_meta( $post_id, $meta_key, wp_slash( (array) $input[ $in_key ] ) );
			}
		}
		if ( ! isset( $input['display_conditions'] ) && self::needs_display_conditions( $type ) ) {
			update_post_meta( $post_id, '_generate_element_display_conditions', self::DEFAULT_DISPLAY_CONDITIONS );
		}
		if ( isset( $input['internal_notes'] ) ) {
			update_post_meta( $post_id, '_generate_element_internal_notes', wp_slash( sanitize_textarea_field( (string) $input['internal_notes'] ) ) );
		}

		return self::cb_get_gp_element( array( 'element_id' => $post_id ) );
	}

	public static function cb_update_gp_element( $input ) {
		$post = self::get_element_post( (int) $input['element_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$type = (string) get_post_meta( $post->ID, '_generate_element_type', true );

		if ( isset( $input['content'] ) ) {
			$valid = Plugin::validate_block_markup( (string) $input['content'] );
			if ( is_wp_error( $valid ) ) {
				if ( ! empty( $input['dry_run'] ) ) {
					return array(
						'dry_run' => true,
						'valid'   => false,
						'reason'  => $valid->get_error_message(),
					);
				}
				return $valid;
			}
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'valid'   => true,
			);
		}

		$updated = array();

		// wp_update_post()/update_post_meta() expect SLASHED data — see
		// write_element_content()'s note above.
		if ( isset( $input['title'] ) ) {
			$old = $post->post_title;
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_title' => (string) $input['title'] ) ), true );
			$updated['title'] = array( 'old' => $old, 'new' => get_the_title( $post->ID ) );
		}
		if ( isset( $input['content'] ) ) {
			$old = self::read_element_content( $post->ID, $type );
			self::write_element_content( $post->ID, $type, (string) $input['content'] );
			$updated['content'] = array( 'old' => $old, 'new' => self::read_element_content( $post->ID, $type ) );
		}
		if ( isset( $input['hook_name'] ) ) {
			$old = get_post_meta( $post->ID, '_generate_hook', true );
			update_post_meta( $post->ID, '_generate_hook', wp_slash( (string) $input['hook_name'] ) );
			$updated['hook_name'] = array( 'old' => $old, 'new' => get_post_meta( $post->ID, '_generate_hook', true ) );
		}
		if ( isset( $input['hook_priority'] ) ) {
			$old = get_post_meta( $post->ID, '_generate_hook_priority', true );
			update_post_meta( $post->ID, '_generate_hook_priority', (int) $input['hook_priority'] );
			$updated['hook_priority'] = array( 'old' => $old, 'new' => get_post_meta( $post->ID, '_generate_hook_priority', true ) );
		}
		foreach ( array(
			'display_conditions' => '_generate_element_display_conditions',
			'exclude_conditions' => '_generate_element_exclude_conditions',
			'user_conditions'    => '_generate_element_user_conditions',
		) as $in_key => $meta_key ) {
			if ( isset( $input[ $in_key ] ) ) {
				$old = get_post_meta( $post->ID, $meta_key, true );
				update_post_meta( $post->ID, $meta_key, wp_slash( (array) $input[ $in_key ] ) );
				$updated[ $in_key ] = array( 'old' => $old, 'new' => get_post_meta( $post->ID, $meta_key, true ) );
			}
		}
		// Self-heal: an element saved before this fix (or created with no
		// rule) has no display_conditions meta at all and can never show,
		// regardless of status. If this call isn't already setting one,
		// give it the same "entire site" default create uses.
		if ( ! isset( $input['display_conditions'] ) && self::needs_display_conditions( $type )
			&& ! get_post_meta( $post->ID, '_generate_element_display_conditions', true ) ) {
			update_post_meta( $post->ID, '_generate_element_display_conditions', self::DEFAULT_DISPLAY_CONDITIONS );
			$updated['display_conditions'] = array( 'old' => array(), 'new' => self::DEFAULT_DISPLAY_CONDITIONS );
		}
		if ( isset( $input['internal_notes'] ) ) {
			$old   = get_post_meta( $post->ID, '_generate_element_internal_notes', true );
			$clean = sanitize_textarea_field( (string) $input['internal_notes'] );
			update_post_meta( $post->ID, '_generate_element_internal_notes', wp_slash( $clean ) );
			$updated['internal_notes'] = array( 'old' => $old, 'new' => get_post_meta( $post->ID, '_generate_element_internal_notes', true ) );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'generatepress-mcp-ability' ) );
		}

		return array(
			'element_id' => (int) $post->ID,
			'updated'    => $updated,
		);
	}

	public static function cb_list_disabled_elements( $input ) {
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;

		$meta_query = array( 'relation' => 'OR' );
		foreach ( Plugin::DISABLE_FIELDS as $meta_key ) {
			$meta_query[] = array(
				'key'   => $meta_key,
				'value' => 'true',
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => min( 100, max( 1, $per_page ) ),
				'paged'          => max( 1, $page ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => $meta_query,
			)
		);

		$posts = array();
		foreach ( $query->posts as $post ) {
			$disabled = array();
			foreach ( Plugin::DISABLE_FIELDS as $field => $meta_key ) {
				if ( 'true' === get_post_meta( $post->ID, $meta_key, true ) ) {
					$disabled[] = $field;
				}
			}
			$posts[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'disabled'  => $disabled,
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'posts' => $posts,
		);
	}


}
