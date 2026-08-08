<?php
/**
 * Theme settings abilities (GeneratePress core theme).
 *
 * @package GeneratePressMCPAbility
 */

namespace GeneratePressMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Theme {

	/**
	 * theme_mod keys readable/writable via get-theme-mods/update-theme-mod.
	 * custom_logo, generate_copyright, nav_menu_locations and custom_css
	 * get special handling in the callbacks; the rest are the GP Premium
	 * per-element Google Font selections (plain string mods).
	 */
	const THEME_MOD_WHITELIST = array(
		'custom_logo', 'generate_copyright', 'nav_menu_locations', 'custom_css',
		'font_body_category', 'font_body_variants',
		'font_site_title_category', 'font_site_title_variants',
		'font_site_tagline_category', 'font_site_tagline_variants',
		'font_navigation_category', 'font_navigation_variants',
		'font_secondary_navigation_category', 'font_secondary_navigation_variants',
		'font_buttons_category', 'font_buttons_variants',
		'font_heading_1_category', 'font_heading_1_variants',
		'font_heading_2_category', 'font_heading_2_variants',
		'font_heading_3_category', 'font_heading_3_variants',
		'font_heading_4_category', 'font_heading_4_variants',
		'font_heading_5_category', 'font_heading_5_variants',
		'font_heading_6_category', 'font_heading_6_variants',
		'font_widget_title_category', 'font_widget_title_variants',
		'font_footer_category', 'font_footer_variants',
	);

	/**
	 * theme_mods included in the "typography" export scope and in the
	 * "all" scope's "mods" section — mirrors GP Premium's own
	 * GeneratePress_Import_Export::get_theme_mods() (font selections +
	 * the Copyright mod). Deliberately excludes custom_logo/
	 * nav_menu_locations/custom_css: GP's own export does not include
	 * them either.
	 */
	const GP_EXPORT_MODS = array(
		'font_body_variants', 'font_body_category',
		'font_site_title_variants', 'font_site_title_category',
		'font_site_tagline_variants', 'font_site_tagline_category',
		'font_navigation_variants', 'font_navigation_category',
		'font_secondary_navigation_variants', 'font_secondary_navigation_category',
		'font_buttons_variants', 'font_buttons_category',
		'font_heading_1_variants', 'font_heading_1_category',
		'font_heading_2_variants', 'font_heading_2_category',
		'font_heading_3_variants', 'font_heading_3_category',
		'font_heading_4_variants', 'font_heading_4_category',
		'font_heading_5_variants', 'font_heading_5_category',
		'font_heading_6_variants', 'font_heading_6_category',
		'font_widget_title_variants', 'font_widget_title_category',
		'font_footer_variants', 'font_footer_category',
		'generate_copyright',
	);

	/**
	 * GP Premium module option names, mirrors
	 * GeneratePress_Import_Export::get_modules() — used for the "all"
	 * export scope's "modules" section.
	 */
	const GP_EXPORT_MODULES = array(
		'Backgrounds'       => 'generate_package_backgrounds',
		'Blog'              => 'generate_package_blog',
		'Colors'            => 'generate_package_colors',
		'Copyright'         => 'generate_package_copyright',
		'Elements'          => 'generate_package_elements',
		'Disable Elements'  => 'generate_package_disable_elements',
		'Hooks'             => 'generate_package_hooks',
		'Menu Plus'         => 'generate_package_menu_plus',
		'Page Header'       => 'generate_package_page_header',
		'Secondary Nav'     => 'generate_package_secondary_nav',
		'Sections'          => 'generate_package_sections',
		'Spacing'           => 'generate_package_spacing',
		'Typography'        => 'generate_package_typography',
		'WooCommerce'       => 'generate_package_woocommerce',
	);

	/**
	 * Option names included in the "all" export scope's "options"
	 * section, mirrors GeneratePress_Import_Export::get_settings().
	 */
	const GP_EXPORT_OPTIONS = array(
		'generate_settings',
		'generate_background_settings',
		'generate_blog_settings',
		'generate_hooks',
		'generate_page_header_settings',
		'generate_secondary_nav_settings',
		'generate_spacing_settings',
		'generate_menu_plus_settings',
		'generate_woocommerce_settings',
	);

	public static function register(): void {
		if ( Plugin::has_theme() ) {
			wp_register_ability(
				'generatepress-mcp/get-theme-settings',
				array(
					'label'               => __( 'Get GeneratePress theme settings', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns the effective GeneratePress theme settings: the saved generate_settings option merged over the theme defaults. Set only_modified to true to get only the keys that differ from the defaults. Sensitive keys are filtered out.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => array( 'object', 'null' ),
						'properties'           => array(
							'only_modified' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'Return only the saved (non-default) settings. Default false: full effective settings.',
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "only_modified" and "settings" (key-value map).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_theme_settings' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);
		}
		// --- Block A: theme read/write verbs — only when GeneratePress is the template.
		if ( Plugin::has_theme() ) {

			wp_register_ability(
				'generatepress-mcp/update-theme-setting',
				array(
					'label'               => __( 'Update a GeneratePress theme setting', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates one key in the generate_settings option. The key must exist in the theme defaults (see get-theme-settings for the full list). Sensitive keys are refused. Returns the old and new effective value, read back after the write.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'   => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Theme setting key, e.g. "container_width" or "hide_tagline".',
							),
							'value' => array(
								'description' => 'New value (string, number, boolean or array).',
							),
						),
						'required'             => array( 'key', 'value' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "key", "old" and "new" (effective values).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_theme_setting' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/reset-theme-setting',
				array(
					'label'               => __( 'Reset a GeneratePress theme setting', 'generatepress-mcp-ability' ),
					'description'         => __( 'Removes one key from the saved generate_settings option so the theme default applies again. Returns the old effective value and the default that now applies.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'key' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Theme setting key to reset to its default.',
							),
						),
						'required'             => array( 'key' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "key", "old" and "new" (the default now in effect).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_reset_theme_setting' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/search-theme-setting',
				array(
					'label'               => __( 'Search GeneratePress settings', 'generatepress-mcp-ability' ),
					'description'         => __( 'Searches setting keys (and string values) across the effective theme settings and all GP Premium module settings options. Useful for locating the right key before calling update-theme-setting or update-module-setting. Sensitive keys are excluded.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'query' => array(
								'type'        => 'string',
								'minLength'   => 2,
								'description' => 'Case-insensitive substring to look for in keys and string values.',
							),
						),
						'required'             => array( 'query' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "matches": array of {source, key, value}.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_search_theme_setting' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/get-global-colors',
				array(
					'label'               => __( 'Get GeneratePress global colors', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns the GeneratePress global color palette: an array of {name, slug, color} entries as used by the Customizer and CSS variables (--slug).', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "colors": array of {name, slug, color}.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_global_colors' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-global-color',
				array(
					'label'               => __( 'Update a GeneratePress global color', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates the color (and optionally the display name) of one global palette entry identified by slug, or appends a new entry when the slug does not exist. Color must be a hex value like #1e73be. Returns the old and new entry, read back after the write.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'slug'  => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Palette slug, e.g. "accent" or "contrast-2".',
							),
							'color' => array(
								'type'        => 'string',
								'description' => 'Hex color, e.g. "#1e73be".',
							),
							'name'  => array(
								'type'        => 'string',
								'description' => 'Optional display name; defaults to the slug for new entries.',
							),
						),
						'required'             => array( 'slug', 'color' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "slug", "old" (entry or null) and "new" (entry).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_global_color' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/get-post-layout',
				array(
					'label'               => __( 'Get per-post GeneratePress layout', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns the GeneratePress layout metadata of one post or page: sidebar layout, footer widget count, full width content mode, and the Disable Elements flags (which take effect when GP Premium\'s Disable Elements module is active). Unset fields are null.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'post_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the post or page to read.',
							),
						),
						'required'             => array( 'post_id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "post_id", "sidebar_layout", "footer_widgets", "full_width_content" and "disabled" (per-element boolean map).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_post_layout' ),
					'permission_callback' => array( Plugin::class, 'permission_edit_post' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-post-layout',
				array(
					'label'               => __( 'Update per-post GeneratePress layout', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates the GeneratePress layout metadata of one post or page. Provide only the fields to change: sidebar_layout (one of left-sidebar, right-sidebar, no-sidebar, both-sidebars, both-left, both-right; empty string resets to inherit), footer_widgets ("0"-"5", empty string resets), full_width_content ("true" for full width, "contained", empty string resets) and/or disable (per-element boolean map; false removes the flag). Returns old and new values per changed field, read back after the write.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'post_id'            => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the post or page to update.',
							),
							'sidebar_layout'     => array(
								'type'        => 'string',
								'enum'        => Plugin::SIDEBAR_LAYOUTS,
								'description' => 'Sidebar layout; empty string resets to inherit.',
							),
							'footer_widgets'     => array(
								'type'        => 'string',
								'enum'        => array( '', '0', '1', '2', '3', '4', '5' ),
								'description' => 'Footer widget count; empty string resets to inherit.',
							),
							'full_width_content' => array(
								'type'        => 'string',
								'enum'        => array( '', 'true', 'contained' ),
								'description' => '"true" = full width, "contained" = contained; empty string resets.',
							),
							'disable'            => array(
								'type'                 => 'object',
								'description'          => 'Disable Elements flags, e.g. {"header": true, "footer": false}. Allowed keys: header, footer, headline, mobile_header, nav, secondary_nav, top_bar, post_image.',
								'additionalProperties' => array( 'type' => 'boolean' ),
							),
						),
						'required'             => array( 'post_id' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "post_id" and "updated": per-field {old, new}.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_post_layout' ),
					'permission_callback' => array( Plugin::class, 'permission_edit_post' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/clear-theme-css-cache',
				array(
					'label'               => __( 'Clear GeneratePress dynamic CSS cache', 'generatepress-mcp-ability' ),
					'description'         => __( 'Deletes the generate_dynamic_css_output and generate_dynamic_css_cached_version options so the theme regenerates its dynamic CSS on the next page load. Same effect as the theme dashboard tool. Safe and idempotent.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "old" (previous cached version) and "new" (empty after clearing).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_clear_theme_css_cache' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/get-theme-mods',
				array(
					'label'               => __( 'Get GeneratePress theme mods', 'generatepress-mcp-ability' ),
					'description'         => __( 'Returns whitelisted theme_mods: custom_logo (resolved to attachment id + URL), generate_copyright (Copyright module text, GP Premium), nav_menu_locations (location => menu id map), custom_css (Additional CSS, via wp_get_custom_css — not a plain theme_mod), and the GP Premium per-element font selections (font_body_*, font_heading_1_* .. font_heading_6_*, font_navigation_*, font_secondary_navigation_*, font_buttons_*, font_widget_title_*, font_footer_*, font_site_title_*, font_site_tagline_*). Pass mods to return only those keys; omit for all.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => array( 'object', 'null' ),
						'properties'           => array(
							'mods' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Optional list of mod keys to return. Omit for all whitelisted mods.',
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Key-value map of theme_mod name to value (custom_logo is {id, url}).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_get_theme_mods' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/update-theme-mod',
				array(
					'label'               => __( 'Update a GeneratePress theme mod', 'generatepress-mcp-ability' ),
					'description'         => __( 'Updates one whitelisted theme_mod (see get-theme-mods for the list). Special handling: custom_logo requires value to be an existing attachment id (verified); nav_menu_locations requires value to be a full {location: menu_id} map — every location must be registered by the active theme and every non-zero menu_id must be a real menu (this overlaps wp-core-mcp/assign-menu-location, which does the same thing one location at a time; use whichever fits); custom_css is written through wp_update_custom_css_post(), not set_theme_mod(), because it is not really a plain mod. Returns {old,new} read back after the write.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'   => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Theme mod key (see get-theme-mods).',
							),
							'value' => array(
								'description' => 'New value. Type depends on key: integer (custom_logo), object (nav_menu_locations), string (everything else, including custom_css).',
							),
						),
						'required'             => array( 'key', 'value' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "key", "old" and "new".',
					),
					'execute_callback'    => array( __CLASS__, 'cb_update_theme_mod' ),
					'permission_callback' => array( Plugin::class, 'permission_theme_options' ),
					'meta'                => Plugin::meta( false, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/export-customizer-settings',
				array(
					'label'               => __( 'Export customizer settings', 'generatepress-mcp-ability' ),
					'description'         => __( 'Exports GeneratePress/GP Premium settings as JSON in the exact {modules, mods, options} shape GP Premium\'s own Appearance > GeneratePress > Import/Export panel produces (and that import-customizer-settings accepts). scope "all" matches GP\'s own full export; "global_colors" exports only the generate_settings.global_colors array; "typography" exports only the GP Premium per-element font theme_mods (font_body_*, font_heading_*, etc. plus generate_copyright). RECOMMENDED: run this with scope "all" before using any other write ability in this bridge, and keep the result — it is your rollback point; feed it back into import-customizer-settings if something goes wrong. Sensitive-looking option keys are filtered out.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'scope' => array(
								'type'        => 'string',
								'enum'        => array( 'all', 'global_colors', 'typography' ),
								'default'     => 'all',
								'description' => 'What to include. Default "all".',
							),
						),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "scope" and "data" ({modules, mods, options}, GP Premium export format).',
					),
					'execute_callback'    => array( __CLASS__, 'cb_export_customizer_settings' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);

			wp_register_ability(
				'generatepress-mcp/import-customizer-settings',
				array(
					'label'               => __( 'Import customizer settings', 'generatepress-mcp-ability' ),
					'description'         => __( 'Imports settings previously produced by export-customizer-settings (the {modules, mods, options} shape). Both dry_run and confirm are REQUIRED on every call — there is no default. Always call once with dry_run: true first: it reports, for every key the payload would touch, {old,new} without writing anything. Only call again with dry_run: false, confirm: true once you have reviewed that preview. Unrecognized keys in the payload (outside the same whitelist export-customizer-settings uses) are silently ignored, not applied.', 'generatepress-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'data'    => array(
								'type'        => 'object',
								'description' => 'Payload in the {modules, mods, options} shape (see export-customizer-settings).',
							),
							'dry_run' => array(
								'type'        => 'boolean',
								'description' => 'REQUIRED. true = preview only, write nothing.',
							),
							'confirm' => array(
								'type'        => 'boolean',
								'description' => 'REQUIRED. Must be true (when dry_run is false) to actually write.',
							),
						),
						'required'             => array( 'data', 'dry_run', 'confirm' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'Object with "dry_run" and "changes": array of {section, key, old, new}.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_import_customizer_settings' ),
					'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
					'meta'                => Plugin::meta( false, true, false ),
				)
			);
		}

	}

	// ---------------------------------------------------------------------
	// Execute callbacks
	// ---------------------------------------------------------------------

	public static function cb_get_theme_settings( $input ) {
		if ( ! function_exists( 'generate_get_defaults' ) ) {
			return new \WP_Error( 'theme_functions_missing', __( 'GeneratePress theme functions are not loaded.', 'generatepress-mcp-ability' ) );
		}

		$only_modified = ! empty( $input['only_modified'] );
		$saved         = (array) get_option( 'generate_settings', array() );

		$settings = $only_modified ? $saved : wp_parse_args( $saved, generate_get_defaults() );

		return array(
			'only_modified' => $only_modified,
			'settings'      => Plugin::strip_sensitive( $settings ),
		);
	}
	private static function effective_theme_settings(): array {
		return wp_parse_args( (array) get_option( 'generate_settings', array() ), generate_get_defaults() );
	}

	public static function cb_update_theme_setting( $input ) {
		$key = (string) $input['key'];

		if ( Plugin::is_sensitive_key( $key ) ) {
			return new \WP_Error( 'sensitive_key', __( 'This key may contain secrets and cannot be written through this ability.', 'generatepress-mcp-ability' ) );
		}
		if ( ! function_exists( 'generate_get_defaults' ) ) {
			return new \WP_Error( 'theme_functions_missing', __( 'GeneratePress theme functions are not loaded.', 'generatepress-mcp-ability' ) );
		}
		$defaults = generate_get_defaults();
		if ( ! array_key_exists( $key, $defaults ) ) {
			return new \WP_Error( 'unknown_key', __( 'Unknown theme setting key. Use get-theme-settings or search-theme-setting to find valid keys.', 'generatepress-mcp-ability' ) );
		}

		$old = self::effective_theme_settings()[ $key ] ?? null;

		$saved         = (array) get_option( 'generate_settings', array() );
		$saved[ $key ] = $input['value'];
		update_option( 'generate_settings', $saved );

		$new = self::effective_theme_settings()[ $key ] ?? null;

		return array(
			'key' => $key,
			'old' => $old,
			'new' => $new,
		);
	}

	public static function cb_reset_theme_setting( $input ) {
		$key = (string) $input['key'];

		if ( ! function_exists( 'generate_get_defaults' ) ) {
			return new \WP_Error( 'theme_functions_missing', __( 'GeneratePress theme functions are not loaded.', 'generatepress-mcp-ability' ) );
		}
		$defaults = generate_get_defaults();
		if ( ! array_key_exists( $key, $defaults ) ) {
			return new \WP_Error( 'unknown_key', __( 'Unknown theme setting key.', 'generatepress-mcp-ability' ) );
		}

		$old = self::effective_theme_settings()[ $key ] ?? null;

		$saved = (array) get_option( 'generate_settings', array() );
		unset( $saved[ $key ] );
		update_option( 'generate_settings', $saved );

		$new = self::effective_theme_settings()[ $key ] ?? null;

		return array(
			'key' => $key,
			'old' => $old,
			'new' => $new,
		);
	}

	public static function cb_search_theme_setting( $input ) {
		$query = strtolower( (string) $input['query'] );

		$sources = array(
			'theme' => function_exists( 'generate_get_defaults' ) ? self::effective_theme_settings() : array(),
		);
		foreach ( Plugin::MODULE_SETTINGS as $group => $option_name ) {
			$value = get_option( $option_name, array() );
			if ( is_array( $value ) && ! empty( $value ) ) {
				$sources[ $group ] = $value;
			}
		}

		$matches = array();
		foreach ( $sources as $source => $settings ) {
			foreach ( Plugin::strip_sensitive( (array) $settings ) as $key => $value ) {
				$key_hit   = false !== strpos( strtolower( (string) $key ), $query );
				$value_hit = is_string( $value ) && false !== strpos( strtolower( $value ), $query );
				if ( $key_hit || $value_hit ) {
					$matches[] = array(
						'source' => $source,
						'key'    => (string) $key,
						'value'  => $value,
					);
				}
			}
		}

		return array( 'matches' => $matches );
	}

	public static function cb_get_global_colors() {
		if ( ! function_exists( 'generate_get_defaults' ) ) {
			return new \WP_Error( 'theme_functions_missing', __( 'GeneratePress theme functions are not loaded.', 'generatepress-mcp-ability' ) );
		}
		$colors = self::effective_theme_settings()['global_colors'] ?? array();

		return array( 'colors' => array_values( (array) $colors ) );
	}

	public static function cb_update_global_color( $input ) {
		if ( ! function_exists( 'generate_get_defaults' ) ) {
			return new \WP_Error( 'theme_functions_missing', __( 'GeneratePress theme functions are not loaded.', 'generatepress-mcp-ability' ) );
		}

		$slug  = sanitize_title( (string) $input['slug'] );
		$color = sanitize_hex_color( (string) $input['color'] );
		if ( ! $slug ) {
			return new \WP_Error( 'invalid_slug', __( 'Invalid color slug.', 'generatepress-mcp-ability' ) );
		}
		if ( ! $color ) {
			return new \WP_Error( 'invalid_color', __( 'Color must be a hex value like #1e73be.', 'generatepress-mcp-ability' ) );
		}

		$colors = array_values( (array) ( self::effective_theme_settings()['global_colors'] ?? array() ) );

		$old   = null;
		$found = false;
		foreach ( $colors as $i => $entry ) {
			if ( isset( $entry['slug'] ) && $entry['slug'] === $slug ) {
				$old                   = $entry;
				$colors[ $i ]['color'] = $color;
				if ( isset( $input['name'] ) && '' !== $input['name'] ) {
					$colors[ $i ]['name'] = sanitize_text_field( (string) $input['name'] );
				}
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$colors[] = array(
				'name'  => isset( $input['name'] ) && '' !== $input['name'] ? sanitize_text_field( (string) $input['name'] ) : $slug,
				'slug'  => $slug,
				'color' => $color,
			);
		}

		$saved                  = (array) get_option( 'generate_settings', array() );
		$saved['global_colors'] = $colors;
		update_option( 'generate_settings', $saved );

		$new = null;
		foreach ( array_values( (array) ( self::effective_theme_settings()['global_colors'] ?? array() ) ) as $entry ) {
			if ( isset( $entry['slug'] ) && $entry['slug'] === $slug ) {
				$new = $entry;
				break;
			}
		}

		return array(
			'slug' => $slug,
			'old'  => $old,
			'new'  => $new,
		);
	}

	public static function cb_get_post_layout( $input ) {
		$post_id = (int) $input['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'generatepress-mcp-ability' ) );
		}

		$meta = static function ( string $key ) use ( $post_id ) {
			$value = get_post_meta( $post_id, $key, true );
			return '' === $value ? null : $value;
		};

		$disabled = array();
		foreach ( Plugin::DISABLE_FIELDS as $field => $meta_key ) {
			$disabled[ $field ] = 'true' === get_post_meta( $post_id, $meta_key, true );
		}

		return array(
			'post_id'            => $post_id,
			'sidebar_layout'     => $meta( Plugin::POST_LAYOUT_FIELDS['sidebar_layout'] ),
			'footer_widgets'     => $meta( Plugin::POST_LAYOUT_FIELDS['footer_widgets'] ),
			'full_width_content' => $meta( Plugin::POST_LAYOUT_FIELDS['full_width_content'] ),
			'disabled'           => $disabled,
		);
	}

	public static function cb_update_post_layout( $input ) {
		$post_id = (int) $input['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'generatepress-mcp-ability' ) );
		}

		$updated = array();

		foreach ( Plugin::POST_LAYOUT_FIELDS as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = (string) $input[ $field ];
			$old   = get_post_meta( $post_id, $meta_key, true );

			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				// update_post_meta() expects SLASHED data — see
				// abilities-gp.php's write_element_content() note.
				update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
			}
			$new = get_post_meta( $post_id, $meta_key, true );

			$updated[ $field ] = array(
				'old' => '' === $old ? null : $old,
				'new' => '' === $new ? null : $new,
			);
		}

		if ( isset( $input['disable'] ) && is_array( $input['disable'] ) ) {
			foreach ( $input['disable'] as $field => $flag ) {
				if ( ! isset( Plugin::DISABLE_FIELDS[ $field ] ) ) {
					return new \WP_Error( 'unknown_disable_field', sprintf(
						/* translators: %s: field name */
						__( 'Unknown disable field "%s". Allowed: header, footer, headline, mobile_header, nav, secondary_nav, top_bar, post_image.', 'generatepress-mcp-ability' ),
						$field
					) );
				}
				$meta_key = Plugin::DISABLE_FIELDS[ $field ];
				$old      = 'true' === get_post_meta( $post_id, $meta_key, true );

				if ( $flag ) {
					update_post_meta( $post_id, $meta_key, 'true' );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}
				$new = 'true' === get_post_meta( $post_id, $meta_key, true );

				$updated[ 'disable_' . $field ] = array(
					'old' => $old,
					'new' => $new,
				);
			}
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one of: sidebar_layout, footer_widgets, full_width_content, disable.', 'generatepress-mcp-ability' ) );
		}

		return array(
			'post_id' => $post_id,
			'updated' => $updated,
		);
	}

	public static function cb_clear_theme_css_cache() {
		$old = (string) get_option( 'generate_dynamic_css_cached_version', '' );

		delete_option( 'generate_dynamic_css_output' );
		delete_option( 'generate_dynamic_css_cached_version' );

		return array(
			'old' => $old,
			'new' => (string) get_option( 'generate_dynamic_css_cached_version', '' ),
		);
	}

	// ---------------------------------------------------------------------
	// Theme mods + customizer export/import
	// ---------------------------------------------------------------------

	/**
	 * Reads one whitelisted theme_mod, applying the same special-case
	 * handling used by update-theme-mod's read-back.
	 */
	private static function read_theme_mod( string $key ) {
		if ( 'custom_logo' === $key ) {
			$id = (int) get_theme_mod( 'custom_logo' );
			return array(
				'id'  => $id ?: null,
				'url' => $id ? wp_get_attachment_image_url( $id, 'full' ) : null,
			);
		}
		if ( 'custom_css' === $key ) {
			return wp_get_custom_css();
		}
		if ( 'nav_menu_locations' === $key ) {
			return (array) get_theme_mod( 'nav_menu_locations', array() );
		}
		$value = get_theme_mod( $key );
		return '' === $value || false === $value ? null : $value;
	}

	public static function cb_get_theme_mods( $input ) {
		$keys = ! empty( $input['mods'] ) && is_array( $input['mods'] )
			? array_values( array_intersect( $input['mods'], self::THEME_MOD_WHITELIST ) )
			: self::THEME_MOD_WHITELIST;

		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = self::read_theme_mod( $key );
		}
		return $out;
	}

	public static function cb_update_theme_mod( $input ) {
		$key = (string) $input['key'];
		if ( ! in_array( $key, self::THEME_MOD_WHITELIST, true ) ) {
			return new \WP_Error( 'not_whitelisted', __( 'This theme_mod is not on the writable whitelist. See get-theme-mods.', 'generatepress-mcp-ability' ) );
		}

		$old = self::read_theme_mod( $key );

		if ( 'custom_logo' === $key ) {
			$attachment_id = (int) $input['value'];
			if ( $attachment_id && ( ! get_post( $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) ) ) {
				return new \WP_Error( 'invalid_attachment', __( 'value must be the id of an existing attachment (or 0 to unset).', 'generatepress-mcp-ability' ) );
			}
			if ( $attachment_id ) {
				set_theme_mod( 'custom_logo', $attachment_id );
			} else {
				remove_theme_mod( 'custom_logo' );
			}
		} elseif ( 'nav_menu_locations' === $key ) {
			$value = (array) $input['value'];
			$registered = get_registered_nav_menus();
			foreach ( $value as $location => $menu_id ) {
				if ( ! array_key_exists( $location, $registered ) ) {
					return new \WP_Error( 'unknown_location', sprintf(
						/* translators: %s: theme location slug */
						__( 'This theme does not register the menu location "%s".', 'generatepress-mcp-ability' ),
						$location
					) );
				}
				if ( (int) $menu_id !== 0 && ! wp_get_nav_menu_object( (int) $menu_id ) ) {
					return new \WP_Error( 'menu_not_found', sprintf(
						/* translators: 1: menu id, 2: theme location slug */
						__( 'No menu exists with id %1$d for location "%2$s".', 'generatepress-mcp-ability' ),
						(int) $menu_id,
						$location
					) );
				}
			}
			set_theme_mod( 'nav_menu_locations', array_map( 'intval', $value ) );
		} elseif ( 'custom_css' === $key ) {
			$result = wp_update_custom_css_post( (string) $input['value'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			set_theme_mod( $key, (string) $input['value'] );
		}

		return array(
			'key' => $key,
			'old' => $old,
			'new' => self::read_theme_mod( $key ),
		);
	}

	public static function cb_export_customizer_settings( $input ) {
		$scope = isset( $input['scope'] ) ? (string) $input['scope'] : 'all';

		$data = array(
			'modules' => array(),
			'mods'    => array(),
			'options' => array(),
		);

		if ( 'global_colors' === $scope ) {
			$settings                        = (array) get_option( 'generate_settings', array() );
			$data['options']['generate_settings'] = array(
				'global_colors' => $settings['global_colors'] ?? array(),
			);
			return array( 'scope' => $scope, 'data' => $data );
		}

		if ( 'typography' === $scope ) {
			foreach ( self::GP_EXPORT_MODS as $mod ) {
				$data['mods'][ $mod ] = get_theme_mod( $mod );
			}
			return array( 'scope' => $scope, 'data' => $data );
		}

		// scope "all" — mirrors GeneratePress_Import_Export::export().
		foreach ( self::GP_EXPORT_MODULES as $name => $option_key ) {
			if ( 'activated' === get_option( $option_key ) ) {
				$data['modules'][ $name ] = $option_key;
			}
		}
		foreach ( self::GP_EXPORT_MODS as $mod ) {
			$data['mods'][ $mod ] = get_theme_mod( $mod );
		}
		foreach ( self::GP_EXPORT_OPTIONS as $option_name ) {
			$value = get_option( $option_name );
			if ( false !== $value ) {
				$data['options'][ $option_name ] = is_array( $value ) ? Plugin::strip_sensitive( $value ) : $value;
			}
		}

		return array( 'scope' => $scope, 'data' => $data );
	}

	public static function cb_import_customizer_settings( $input ) {
		$dry_run = (bool) $input['dry_run'];
		if ( ! $dry_run && true !== $input['confirm'] ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true (with dry_run: false) to actually write. Run with dry_run: true first to preview.', 'generatepress-mcp-ability' ) );
		}

		$data    = (array) $input['data'];
		$changes = array();

		foreach ( (array) ( $data['modules'] ?? array() ) as $name => $option_key ) {
			if ( ! in_array( $option_key, self::GP_EXPORT_MODULES, true ) ) {
				continue;
			}
			$old = get_option( $option_key );
			if ( ! $dry_run ) {
				update_option( $option_key, 'activated' );
			}
			$changes[] = array(
				'section' => 'modules',
				'key'     => $option_key,
				'old'     => $old,
				'new'     => 'activated',
			);
		}

		foreach ( (array) ( $data['mods'] ?? array() ) as $key => $value ) {
			if ( ! in_array( $key, self::GP_EXPORT_MODS, true ) && ! in_array( $key, self::THEME_MOD_WHITELIST, true ) ) {
				continue;
			}
			$old = get_theme_mod( $key );
			if ( ! $dry_run ) {
				set_theme_mod( $key, $value );
			}
			$changes[] = array(
				'section' => 'mods',
				'key'     => $key,
				'old'     => $old,
				'new'     => $value,
			);
		}

		foreach ( (array) ( $data['options'] ?? array() ) as $option_name => $value ) {
			if ( ! in_array( $option_name, self::GP_EXPORT_OPTIONS, true ) ) {
				continue;
			}
			$old = get_option( $option_name );
			if ( ! $dry_run ) {
				update_option( $option_name, $value );
			}
			$changes[] = array(
				'section' => 'options',
				'key'     => $option_name,
				'old'     => $old,
				'new'     => $value,
			);
		}

		return array(
			'dry_run' => $dry_run,
			'changes' => $changes,
		);
	}
}
