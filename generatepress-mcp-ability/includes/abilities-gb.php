<?php
/**
 * GenerateBlocks + GenerateBlocks Pro abilities: settings, global
 * styles, pattern libraries.
 *
 * @package GeneratePressMCPAbility
 */

namespace GeneratePressMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GB {

	public static function register(): void {
		// 4. GenerateBlocks global styles — only when GB Pro is installed.
		if ( Plugin::has_gb_pro() ) {
			wp_register_ability(
				'generatepress-mcp/get-gb-global-styles',
				array(
					'label'               => __( 'Get GenerateBlocks global styles', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns GenerateBlocks Pro global styles: the compiled generateblocks_global_styles option and the list of gblocks_global_style posts (id, title, status, modified). Sensitive keys are filtered out.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "styles_option" (compiled global styles) and "posts" (array of global style posts).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_gb_global_styles' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);
		}
		// --- Block C (continued): GB Pro verbs — only when GB Pro is installed.
		if ( Plugin::has_gb_pro() ) {

			wp_register_ability(
				'generatepress-mcp/get-gb-global-style',
				array(
					'label'               => __( 'Get a GenerateBlocks global style', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns the full detail of one GenerateBlocks Pro global style: post content (block markup), status, and the compiled style data and editor attributes stored for it.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'style_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the gblocks_global_style post.',
							),
						),
						'required'             => array( 'style_id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Global style detail: id, title, status, content, compiled, attrs.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_gb_global_style' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/create-gb-global-style',
				array(
					'label'               => __( 'Create a GenerateBlocks global style', 'generatepress-mcp-ability' ),
					'description'         => __( 'Creates a new GenerateBlocks Pro global style: a gblocks_global_style post plus its matching entries in the generateblocks_global_styles (compiled) and generateblocks_global_style_attrs (editor attributes) options — both are written together so they stay in sync. content is block markup and is validated the same way as create-gp-element (block-parse + wp_kses_post integrity). attrs is a free-form JSON object (GB Pro\'s own editor writes it); it must be a JSON object and, if not empty, is expected to at least be an object keyed by attribute name — not a list or scalar. NOTE: the underlying gblocks_global_style post type is deprecated and only registered by GenerateBlocks Pro on demand; if it is not currently registered, this ability re-registers it for the request so creation can proceed. Set dry_run: true to validate without creating anything.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'title'   => array(
								'type'        => 'string',
								'description' => 'Style title.',
							),
							'content' => array(
								'type'        => 'string',
								'description' => 'Block markup content.',
							),
							'attrs'   => array(
								'type'        => 'object',
								'description' => 'Editor attribute map to store in generateblocks_global_style_attrs.',
							),
							'status'  => array(
								'type'        => 'string',
								'enum'        => array( 'draft', 'publish' ),
								'default'     => 'draft',
								'description' => 'Initial status. Default "draft".',
							),
							'dry_run' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'true = validate content/attrs only, create nothing.',
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'The created global style (or, when dry_run, {dry_run:true, valid:true}).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_create_gb_global_style' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( false, false, false ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-gb-global-style',
				array(
					'label'               => __( 'Update a GenerateBlocks global style', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates the content and/or attrs of an existing GenerateBlocks Pro global style, keeping the post content and the generateblocks_global_styles / generateblocks_global_style_attrs options in sync (never updates one without the other when both are provided). content is validated the same way as create-gb-global-style. At least one of title, content, attrs is required. No confirm gate — every call reads back and returns {old,new} so the effect is always visible. Set dry_run: true to validate without saving.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'style_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the gblocks_global_style post.',
							),
							'title'    => array( 'type' => 'string' ),
							'content'  => array( 'type' => 'string' ),
							'attrs'    => array( 'type' => 'object' ),
							'dry_run'  => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'true = validate content/attrs only, save nothing.',
							),
						),
						'required'             => array( 'style_id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "style_id" and "updated": per-field {old, new} (or, when dry_run, {dry_run:true, valid:true}).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_gb_global_style' ),
					'permission_callback' => array( Plugin::class, 'permission_gb_style_edit' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-gb-global-style-status',
				array(
					'label'               => __( 'Update GenerateBlocks global style status', 'generatepress-mcp-ability' ),
					'description'         => __( 'Publishes or drafts one GenerateBlocks Pro global style. Returns the old and new status, read back after the write.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'style_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the gblocks_global_style post.',
							),
							'status'   => array(
								'type'        => 'string',
								'enum'        => array( 'publish', 'draft' ),
								'description' => 'New status.',
							),
						),
						'required'             => array( 'style_id', 'status' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "style_id", "old" and "new" (statuses).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_gb_global_style_status' ),
					'permission_callback' => array( Plugin::class, 'permission_gb_style_edit' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/delete-gb-global-style',
				array(
					'label'               => __( 'Delete a GenerateBlocks global style', 'generatepress-mcp-ability' ),
					'description'         => __( 'Deletes one GenerateBlocks Pro global style. Requires confirm: true. By default the style is moved to trash (its compiled/attrs option entries are kept, so restoring the post restores its style data too); set force: true to delete permanently, which also removes its entries from generateblocks_global_styles and generateblocks_global_style_attrs. This is destructive and cannot be undone when forced.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'style_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the gblocks_global_style post.',
							),
							'confirm'  => array(
								'type'        => 'boolean',
								'description' => 'Must be true to proceed.',
							),
							'force'    => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'true = delete permanently, false (default) = move to trash.',
							),
						),
						'required'             => array( 'style_id', 'confirm' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "style_id", "old" (previous status) and "new" ("trash" or "deleted").',
					),
					'execute_callback'    => array( __CLASS__, 'cb_delete_gb_global_style' ),
					'permission_callback' => array( Plugin::class, 'permission_gb_style_delete' ),
					'meta'                => Plugin::meta( false, true, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/get-gb-pro-settings',
				array(
					'label'               => __( 'Get GenerateBlocks Pro settings', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns GenerateBlocks Pro configuration: admin UI settings, form integrations, classic menu support flag and registered pattern libraries. Licensing data is never included.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "admin", "form_integrations", "classic_menu_support" and "pattern_libraries".',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_gb_pro_settings' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/list-gb-pattern-libraries',
				array(
					'label'               => __( 'List GenerateBlocks pattern libraries', 'generatepress-mcp-ability' ),
					'description'         => __( 'Lists locally-registered GenerateBlocks Pro pattern libraries (the generateblocks_pattern_libraries option). This is local management only — no request is made to any remote library. Sensitive fields (publicKey, domain) are redacted.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "libraries": array of {id, name, isEnabled, isDefault, isLocal} (publicKey/domain redacted).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_list_gb_pattern_libraries' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/get-gb-pattern-library',
				array(
					'label'               => __( 'Get a GenerateBlocks pattern library', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns one locally-registered pattern library entry by id. Sensitive fields (publicKey, domain) are redacted.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'id' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Library id.',
							),
						),
						'required'             => array( 'id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Library entry (publicKey/domain redacted).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_gb_pattern_library' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-gb-pattern-library',
				array(
					'label'               => __( 'Update a GenerateBlocks pattern library', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates the name and/or isEnabled flag of a locally-registered pattern library entry. Does not accept or change publicKey/domain (connecting a new remote library is a GB Pro UI operation, out of scope here). At least one of name, is_enabled is required. Returns {old,new}, with publicKey/domain redacted in both.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'         => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Library id.',
							),
							'name'       => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'New display name.',
							),
							'is_enabled' => array(
								'type'        => 'boolean',
								'description' => 'Enable/disable the library.',
							),
						),
						'required'             => array( 'id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "id" and "updated": per-field {old, new}.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_gb_pattern_library' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/delete-gb-pattern-library',
				array(
					'label'               => __( 'Delete a GenerateBlocks pattern library', 'generatepress-mcp-ability' ),
					'description'         => __( 'Removes a locally-registered pattern library entry. Requires confirm: true. The built-in default PRO library (isDefault: true) is not stored in the option at all — it is always injected by GB Pro itself — so it cannot be found or deleted through this ability.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'      => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Library id.',
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
						'description' => 'Object with "id", "old" (removed name) and "new" (null).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_delete_gb_pattern_library' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( false, true, true ),
				)
			);
		}

		// --- Block C: GenerateBlocks read/write verbs — only when GB is installed.
		if ( Plugin::has_gb() ) {

			wp_register_ability(
				'generatepress-mcp/update-gb-setting',
				array(
					'label'               => __( 'Update a GenerateBlocks setting', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates one key in the generateblocks option. Valid keys: container_width, css_print_method, sync_responsive_previews, disable_google_fonts, enable_overlay_panels, enable_block_conditions, enable_forms. Returns the old and new effective value, read back after the write.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'   => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'GenerateBlocks setting key.',
							),
							'value' => array(
								'description' => 'New value (string, number or boolean).',
							),
						),
						'required'             => array( 'key', 'value' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "key", "old" and "new" (effective values).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_gb_setting' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/regenerate-gb-css',
				array(
					'label'               => __( 'Regenerate GenerateBlocks CSS', 'generatepress-mcp-ability' ),
					'description'         => __( 'Clears the GenerateBlocks per-post CSS file cache (generateblocks_dynamic_css_posts) so CSS files are regenerated on the next visit — the same operation as the "Regenerate CSS files" tool in the GenerateBlocks dashboard. Safe and idempotent.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "old" (number of cached posts before) and "new" (0 after clearing).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_regenerate_gb_css' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/list-gb-usage',
				array(
					'label'               => __( 'List posts using GenerateBlocks', 'generatepress-mcp-ability' ),
					'description'         => __( 'Lists posts and pages whose content contains GenerateBlocks blocks (wp:generateblocks/...), paginated, newest first.', 'generatepress-mcp-ability' ),
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
						'description' => 'Object with "total" and "posts": array of {id, title, post_type, status}.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_list_gb_usage' ),
					'permission_callback' => array( Plugin::class, 'permission_edit_posts' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);
		}
		// 5. GenerateBlocks settings — only when GenerateBlocks is installed.
		if ( Plugin::has_gb() ) {
			wp_register_ability(
				'generatepress-mcp/get-gb-settings',
				array(
					'label'               => __( 'Get GenerateBlocks settings', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns the saved GenerateBlocks settings option, plugin versions and dynamic CSS cache info (last regeneration time and number of posts with cached CSS). Sensitive keys such as licensing data are filtered out.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "settings", "versions" and "css_cache".',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_gb_settings' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);
		}

	}

	// ---------------------------------------------------------------------
	// Execute callbacks
	// ---------------------------------------------------------------------

	public static function cb_get_gb_global_styles() {
		$option = get_option( 'generateblocks_global_styles', array() );

		$posts = array();
		if ( post_type_exists( 'gblocks_global_style' ) ) {
			$query = new \WP_Query(
				array(
					'post_type'      => 'gblocks_global_style',
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
			foreach ( $query->posts as $post ) {
				$posts[] = array(
					'id'       => (int) $post->ID,
					'title'    => $post->post_title,
					'status'   => $post->post_status,
					'modified' => $post->post_modified,
				);
			}
		}

		return array(
			'styles_option' => is_array( $option ) ? Plugin::strip_sensitive( $option ) : $option,
			'posts'         => $posts,
		);
	}

	public static function cb_get_gb_settings() {
		$settings = get_option( 'generateblocks', array() );

		$css_posts = get_option( 'generateblocks_dynamic_css_posts', array() );

		return array(
			'settings'  => is_array( $settings ) ? Plugin::strip_sensitive( $settings ) : array(),
			'versions'  => array(
				'generateblocks'     => Plugin::has_gb() ? GENERATEBLOCKS_VERSION : null,
				'generateblocks_pro' => Plugin::has_gb_pro() ? GENERATEBLOCKS_PRO_VERSION : null,
			),
			'css_cache' => array(
				'last_regenerated' => (int) get_option( 'generateblocks_dynamic_css_time', 0 ),
				'cached_posts'     => is_array( $css_posts ) ? count( $css_posts ) : 0,
			),
		);
	}

	public static function cb_update_gb_setting( $input ) {
		$key = (string) $input['key'];

		if ( Plugin::is_sensitive_key( $key ) ) {
			return new \WP_Error( 'sensitive_key', __( 'This key may contain secrets and cannot be written through this ability.', 'generatepress-mcp-ability' ) );
		}
		if ( ! function_exists( 'generateblocks_get_option_defaults' ) || ! function_exists( 'generateblocks_get_option' ) ) {
			return new \WP_Error( 'gb_functions_missing', __( 'GenerateBlocks functions are not loaded.', 'generatepress-mcp-ability' ) );
		}
		if ( ! array_key_exists( $key, generateblocks_get_option_defaults() ) ) {
			return new \WP_Error( 'unknown_key', __( 'Unknown GenerateBlocks setting key. See the ability description for valid keys.', 'generatepress-mcp-ability' ) );
		}

		$old = generateblocks_get_option( $key );

		$saved         = (array) get_option( 'generateblocks', array() );
		$saved[ $key ] = $input['value'];
		update_option( 'generateblocks', $saved );

		$new = generateblocks_get_option( $key );

		return array(
			'key' => $key,
			'old' => $old,
			'new' => $new,
		);
	}

	public static function cb_regenerate_gb_css() {
		$old = get_option( 'generateblocks_dynamic_css_posts', array() );

		update_option( 'generateblocks_dynamic_css_posts', array() );

		$new = get_option( 'generateblocks_dynamic_css_posts', array() );

		return array(
			'old' => is_array( $old ) ? count( $old ) : 0,
			'new' => is_array( $new ) ? count( $new ) : 0,
		);
	}

	public static function cb_list_gb_usage( $input ) {
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;

		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => min( 100, max( 1, $per_page ) ),
				'paged'          => max( 1, $page ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				's'              => 'wp:generateblocks/',
			)
		);

		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( false === strpos( (string) $post->post_content, 'wp:generateblocks/' ) ) {
				continue;
			}
			$posts[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'posts' => $posts,
		);
	}

	/**
	 * GenerateBlocks Pro registers the deprecated gblocks_global_style
	 * CPT lazily: only when the admin screen is viewed with at least
	 * one existing style, or (on the front end/REST) when the
	 * generateblocks_global_styles option is non-empty. If neither is
	 * true, the CPT is not registered this request and create would
	 * otherwise fail. This mirrors GB Pro's own register_post_type()
	 * call (includes/class-global-styles.php) so create-gb-global-style
	 * can "re-birth" the CPT for this request when it's needed —
	 * matching GB Pro's own args exactly is a deliberate coupling that
	 * may need updating if GB Pro changes that registration.
	 */
	private static function ensure_global_style_cpt(): void {
		if ( post_type_exists( 'gblocks_global_style' ) ) {
			return;
		}
		register_post_type(
			'gblocks_global_style',
			array(
				'labels'              => array(
					'name'          => __( 'Global Styles (Legacy)', 'generatepress-mcp-ability' ),
					'singular_name' => __( 'Global Style', 'generatepress-mcp-ability' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'show_ui'             => true,
				'exclude_from_search' => true,
				'show_in_nav_menus'   => false,
				'rewrite'             => false,
				'hierarchical'        => false,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => true,
				'show_in_rest'        => true,
				'capabilities'        => array(
					'create_posts'        => false,
					'publish_posts'       => 'manage_options',
					'edit_posts'          => 'manage_options',
					'edit_others_posts'   => 'manage_options',
					'delete_posts'        => 'manage_options',
					'delete_others_posts' => 'manage_options',
					'read_private_posts'  => 'manage_options',
					'edit_post'           => 'manage_options',
					'delete_post'         => 'manage_options',
					'read_post'           => 'manage_options',
				),
				'supports'            => array( 'title', 'editor', 'thumbnail' ),
			)
		);
	}

	/**
	 * Fetch a gblocks_global_style post or a WP_Error.
	 */
	private static function get_global_style_post( int $style_id ) {
		if ( ! post_type_exists( 'gblocks_global_style' ) ) {
			return new \WP_Error( 'global_styles_unavailable', __( 'The gblocks_global_style post type is not registered.', 'generatepress-mcp-ability' ) );
		}
		$post = get_post( $style_id );
		if ( ! $post || 'gblocks_global_style' !== $post->post_type ) {
			return new \WP_Error( 'style_not_found', __( 'No global style exists with the given ID.', 'generatepress-mcp-ability' ) );
		}
		return $post;
	}

	public static function cb_get_gb_global_style( $input ) {
		$post = self::get_global_style_post( (int) $input['style_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$compiled = get_option( 'generateblocks_global_styles', array() );
		$attrs    = get_option( 'generateblocks_global_style_attrs', array() );

		return array(
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'content'  => $post->post_content,
			'compiled' => isset( $compiled[ $post->ID ] ) ? Plugin::strip_sensitive( (array) $compiled[ $post->ID ] ) : null,
			'attrs'    => isset( $attrs[ $post->ID ] ) ? Plugin::strip_sensitive( (array) $attrs[ $post->ID ] ) : null,
			'modified' => $post->post_modified,
		);
	}

	/**
	 * Validates that an attrs payload is a plain JSON object (assoc
	 * array), not a list or scalar — GB Pro's editor always stores it
	 * keyed by attribute name.
	 */
	private static function validate_style_attrs( $attrs ) {
		if ( null === $attrs ) {
			return true;
		}
		if ( ! is_array( $attrs ) ) {
			return new \WP_Error( 'invalid_attrs', __( 'attrs must be a JSON object.', 'generatepress-mcp-ability' ) );
		}
		if ( array() !== $attrs && array_keys( $attrs ) === range( 0, count( $attrs ) - 1 ) ) {
			return new \WP_Error( 'invalid_attrs', __( 'attrs must be a JSON object keyed by attribute name, not a list.', 'generatepress-mcp-ability' ) );
		}
		return true;
	}

	public static function cb_create_gb_global_style( $input ) {
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		$valid   = Plugin::validate_block_markup( $content );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$attrs_valid = self::validate_style_attrs( $input['attrs'] ?? null );
		if ( is_wp_error( $attrs_valid ) ) {
			return $attrs_valid;
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'valid'   => true,
			);
		}

		self::ensure_global_style_cpt();

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'gblocks_global_style',
				'post_title'   => isset( $input['title'] ) ? (string) $input['title'] : __( '(no title)', 'generatepress-mcp-ability' ),
				'post_content' => $content,
				'post_status'  => isset( $input['status'] ) ? (string) $input['status'] : 'draft',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Keep the compiled option and the attrs option in sync with the
		// post, exactly as GB Pro's own editor save does.
		$compiled                     = get_option( 'generateblocks_global_styles', array() );
		$compiled[ $post_id ]         = array( 'content' => $content );
		update_option( 'generateblocks_global_styles', $compiled );

		if ( isset( $input['attrs'] ) ) {
			$attrs               = get_option( 'generateblocks_global_style_attrs', array() );
			$attrs[ $post_id ]   = (array) $input['attrs'];
			update_option( 'generateblocks_global_style_attrs', $attrs );
		}

		return self::cb_get_gb_global_style( array( 'style_id' => $post_id ) );
	}

	public static function cb_update_gb_global_style( $input ) {
		$post = self::get_global_style_post( (int) $input['style_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( isset( $input['content'] ) ) {
			$valid = Plugin::validate_block_markup( (string) $input['content'] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		if ( array_key_exists( 'attrs', $input ) ) {
			$attrs_valid = self::validate_style_attrs( $input['attrs'] );
			if ( is_wp_error( $attrs_valid ) ) {
				return $attrs_valid;
			}
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'valid'   => true,
			);
		}

		$updated = array();

		// Read "old" values for the options BEFORE any wp_update_post()
		// call below: GB Pro's own save_post_gblocks_global_style hook
		// (build_css) rewrites generateblocks_global_styles /
		// _attrs as a side effect of saving the post, so reading "old"
		// after that call would capture GB Pro's intermediate state
		// instead of the value that was actually there before this
		// ability ran.
		$old_compiled = null;
		$old_attrs    = null;
		if ( isset( $input['content'] ) || array_key_exists( 'attrs', $input ) ) {
			$compiled_opt = get_option( 'generateblocks_global_styles', array() );
			$old_compiled = $compiled_opt[ $post->ID ] ?? null;
			$attrs_opt    = get_option( 'generateblocks_global_style_attrs', array() );
			$old_attrs    = $attrs_opt[ $post->ID ] ?? null;
		}

		if ( isset( $input['title'] ) ) {
			$old = $post->post_title;
			wp_update_post( array( 'ID' => $post->ID, 'post_title' => (string) $input['title'] ), true );
			$updated['title'] = array( 'old' => $old, 'new' => get_the_title( $post->ID ) );
		}

		if ( isset( $input['content'] ) ) {
			$old = $post->post_content;
			wp_update_post( array( 'ID' => $post->ID, 'post_content' => (string) $input['content'] ), true );

			$compiled                         = get_option( 'generateblocks_global_styles', array() );
			$compiled[ $post->ID ]['content'] = (string) $input['content'];
			update_option( 'generateblocks_global_styles', $compiled );

			$updated['content']  = array( 'old' => $old, 'new' => get_post_field( 'post_content', $post->ID ) );
			$updated['compiled'] = array( 'old' => $old_compiled, 'new' => $compiled[ $post->ID ] );
		}

		if ( array_key_exists( 'attrs', $input ) ) {
			$attrs              = get_option( 'generateblocks_global_style_attrs', array() );
			$attrs[ $post->ID ] = (array) $input['attrs'];
			update_option( 'generateblocks_global_style_attrs', $attrs );
			$updated['attrs'] = array( 'old' => $old_attrs, 'new' => $attrs[ $post->ID ] );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one of: title, content, attrs.', 'generatepress-mcp-ability' ) );
		}

		return array(
			'style_id' => (int) $post->ID,
			'updated'  => $updated,
		);
	}

	public static function cb_update_gb_global_style_status( $input ) {
		$post = self::get_global_style_post( (int) $input['style_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$old    = $post->post_status;
		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => (string) $input['status'],
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'style_id' => (int) $post->ID,
			'old'      => $old,
			'new'      => get_post_status( $post->ID ),
		);
	}

	public static function cb_delete_gb_global_style( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a global style.', 'generatepress-mcp-ability' ) );
		}

		$post = self::get_global_style_post( (int) $input['style_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$old   = $post->post_status;
		$force = ! empty( $input['force'] );

		$result = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The global style could not be deleted.', 'generatepress-mcp-ability' ) );
		}

		// Permanent deletion also drops the post's entries from the
		// compiled/attrs options — trashing does not, so a restored
		// post keeps its style data (matches create/update keeping the
		// post and these two options in sync).
		if ( $force ) {
			$compiled = get_option( 'generateblocks_global_styles', array() );
			if ( isset( $compiled[ $post->ID ] ) ) {
				unset( $compiled[ $post->ID ] );
				update_option( 'generateblocks_global_styles', $compiled );
			}
			$attrs = get_option( 'generateblocks_global_style_attrs', array() );
			if ( isset( $attrs[ $post->ID ] ) ) {
				unset( $attrs[ $post->ID ] );
				update_option( 'generateblocks_global_style_attrs', $attrs );
			}
		}

		return array(
			'style_id' => (int) $post->ID,
			'old'      => $old,
			'new'      => $force ? 'deleted' : 'trash',
		);
	}

	public static function cb_get_gb_pro_settings() {
		$admin     = get_option( 'generateblocks_admin', array() );
		$forms     = get_option( 'generateblocks_pro_form_integrations', array() );
		$classic   = get_option( 'generateblocks_pro_classic_menu_support', false );
		$libraries = get_option( 'generateblocks_pattern_libraries', array() );

		return array(
			'admin'                => is_array( $admin ) ? Plugin::strip_sensitive( $admin ) : $admin,
			'form_integrations'    => is_array( $forms ) ? Plugin::strip_sensitive( $forms ) : $forms,
			'classic_menu_support' => (bool) $classic,
			'pattern_libraries'    => is_array( $libraries ) ? Plugin::strip_sensitive( $libraries ) : $libraries,
		);
	}

	/**
	 * Redact the fields of a pattern-library entry that identify or
	 * authenticate against a remote library. "publicKey" doesn't match
	 * SENSITIVE_PATTERN (no "api"/"secret"/etc. substring) so it needs
	 * explicit handling here rather than relying on strip_sensitive().
	 */
	private static function redact_library_entry( array $entry ): array {
		unset( $entry['publicKey'], $entry['domain'] );
		return Plugin::strip_sensitive( $entry );
	}

	public static function cb_list_gb_pattern_libraries() {
		$libraries = (array) get_option( 'generateblocks_pattern_libraries', array() );

		return array(
			'libraries' => array_map(
				static function ( $entry ) {
					return self::redact_library_entry( (array) $entry );
				},
				$libraries
			),
		);
	}

	public static function cb_get_gb_pattern_library( $input ) {
		$id        = (string) $input['id'];
		$libraries = (array) get_option( 'generateblocks_pattern_libraries', array() );

		foreach ( $libraries as $entry ) {
			$entry = (array) $entry;
			if ( ( $entry['id'] ?? null ) === $id ) {
				return self::redact_library_entry( $entry );
			}
		}

		return new \WP_Error( 'library_not_found', __( 'No pattern library exists with the given id.', 'generatepress-mcp-ability' ) );
	}

	public static function cb_update_gb_pattern_library( $input ) {
		if ( ! isset( $input['name'] ) && ! array_key_exists( 'is_enabled', $input ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one of: name, is_enabled.', 'generatepress-mcp-ability' ) );
		}

		$id        = (string) $input['id'];
		$libraries = (array) get_option( 'generateblocks_pattern_libraries', array() );

		foreach ( $libraries as $index => $entry ) {
			$entry = (array) $entry;
			if ( ( $entry['id'] ?? null ) !== $id ) {
				continue;
			}

			$old = self::redact_library_entry( $entry );

			if ( isset( $input['name'] ) ) {
				$entry['name'] = sanitize_text_field( (string) $input['name'] );
			}
			if ( array_key_exists( 'is_enabled', $input ) ) {
				$entry['isEnabled'] = (bool) $input['is_enabled'];
			}
			$libraries[ $index ] = $entry;
			update_option( 'generateblocks_pattern_libraries', $libraries );

			return array(
				'id'  => $id,
				'old' => $old,
				'new' => self::redact_library_entry( $entry ),
			);
		}

		return new \WP_Error( 'library_not_found', __( 'No pattern library exists with the given id.', 'generatepress-mcp-ability' ) );
	}

	public static function cb_delete_gb_pattern_library( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a pattern library.', 'generatepress-mcp-ability' ) );
		}

		$id        = (string) $input['id'];
		$libraries = (array) get_option( 'generateblocks_pattern_libraries', array() );

		foreach ( $libraries as $index => $entry ) {
			$entry = (array) $entry;
			if ( ( $entry['id'] ?? null ) !== $id ) {
				continue;
			}

			$name = $entry['name'] ?? $id;
			unset( $libraries[ $index ] );
			update_option( 'generateblocks_pattern_libraries', array_values( $libraries ) );

			return array(
				'id'  => $id,
				'old' => $name,
				'new' => null,
			);
		}

		return new \WP_Error( 'library_not_found', __( 'No pattern library exists with the given id.', 'generatepress-mcp-ability' ) );
	}

	/**
	 * Recursively remove keys that may contain secrets from a settings array.
	 */

}
