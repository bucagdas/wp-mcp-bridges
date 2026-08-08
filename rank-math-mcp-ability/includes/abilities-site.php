<?php
/**
 * Site-level abilities: settings, status, sitemap, modules, analytics.
 *
 * @package RankMathMCPAbility
 */

namespace RankMathMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site {

	public static function register(): void {

		wp_register_ability(
			'rank-math-mcp/get-settings',
			array(
				'label'               => __( 'Get Rank Math settings', 'rank-math-mcp-ability' ),
				'description'         => __( 'Returns one Rank Math settings group: "general", "titles", "sitemap" or "instant-indexing". Sensitive values such as API keys, tokens and secrets are removed from the response.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'group' => array(
							'type'        => 'string',
							'enum'        => array_keys( Plugin::SETTINGS_GROUPS ),
							'description' => 'Settings group to read.',
						),
					),
					'required'             => array( 'group' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "group" and "settings" (sensitive keys filtered out).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_settings' ),
				'permission_callback' => array( Plugin::class, 'permission_settings' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/update-settings',
			array(
				'label'               => __( 'Update a Rank Math setting', 'rank-math-mcp-ability' ),
				'description'         => __( 'Updates one top-level key in a Rank Math settings group ("general", "titles", "sitemap" or "instant-indexing"). Keys that may contain secrets are refused with an error. Returns the old and new value, read back after the write.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'group' => array(
							'type'        => 'string',
							'enum'        => array_keys( Plugin::SETTINGS_GROUPS ),
							'description' => 'Settings group containing the key.',
						),
						'key'   => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Top-level settings key to update.',
						),
						'value' => array(
							'description' => 'New value for the key.',
						),
					),
					'required'             => array( 'group', 'key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "group", "key", "old" and "new".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_settings' ),
				'permission_callback' => array( Plugin::class, 'permission_settings' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/toggle-module',
			array(
				'label'               => __( 'Toggle a Rank Math module', 'rank-math-mcp-ability' ),
				'description'         => __( 'Activates or deactivates one Rank Math module by updating the rank_math_modules option (same option Rank Math\'s own module screen writes). Requires confirm: true. When activating a module that needs database tables (redirections, 404-monitor), Rank Math creates them on the next admin page load if it has not already. See get-status for the current module map.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'module'  => array(
							'type'        => 'string',
							'enum'        => Plugin::KNOWN_MODULES,
							'description' => 'Module id, e.g. "redirections" or "sitemap".',
						),
						'active'  => array(
							'type'        => 'boolean',
							'description' => 'true to activate, false to deactivate.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'module', 'active', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "module", "old" and "new" (active booleans).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_toggle_module' ),
				'permission_callback' => array( Plugin::class, 'permission_manage_options' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/get-sitemap-status',
			array(
				'label'               => __( 'Get sitemap cache status', 'rank-math-mcp-ability' ),
				'description'         => __( 'Returns the Rank Math XML sitemap cache status: whether the sitemap module is active, the cache directory path, and the list of cached sitemap files with their type. Requires the sitemap module.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "module_active", "cache_directory" and "cached_files".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_sitemap_status' ),
				'permission_callback' => array( Plugin::class, 'permission_sitemap' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/clear-sitemap-cache',
			array(
				'label'               => __( 'Clear the sitemap cache', 'rank-math-mcp-ability' ),
				'description'         => __( 'Deletes all cached XML sitemap files so they regenerate on next request. Same effect as Rank Math\'s own cache-clearing on settings save. Safe and idempotent. Requires the sitemap module.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "old" (cached file count before) and "new" (0 after clearing).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_clear_sitemap_cache' ),
				'permission_callback' => array( Plugin::class, 'permission_sitemap' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/get-status',
			array(
				'label'               => __( 'Get Rank Math status', 'rank-math-mcp-ability' ),
				'description'         => __( 'Returns the Rank Math plugin status: version, database version, per-module activation map (with every toggleable module), presence of the redirections/404/analytics database tables, whether the plugin is connected to a Rank Math account, and the post types Rank Math manages.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Rank Math version, module map, table presence and connection status.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_status' ),
				'permission_callback' => array( Plugin::class, 'permission_general' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_get_settings( $input ) {
		$group = (string) $input['group'];
		if ( ! isset( Plugin::SETTINGS_GROUPS[ $group ] ) ) {
			return new \WP_Error( 'invalid_group', __( 'Unknown settings group.', 'rank-math-mcp-ability' ) );
		}

		$settings = get_option( Plugin::SETTINGS_GROUPS[ $group ][0], array() );

		return array(
			'group'    => $group,
			'settings' => is_array( $settings ) ? Plugin::strip_sensitive( $settings ) : array(),
		);
	}

	public static function cb_update_settings( $input ) {
		$group = (string) $input['group'];
		$key   = (string) $input['key'];

		if ( ! isset( Plugin::SETTINGS_GROUPS[ $group ] ) ) {
			return new \WP_Error( 'invalid_group', __( 'Unknown settings group.', 'rank-math-mcp-ability' ) );
		}
		if ( Plugin::is_sensitive_key( $key ) ) {
			return new \WP_Error( 'sensitive_key', __( 'This key may contain secrets and cannot be written through this ability.', 'rank-math-mcp-ability' ) );
		}

		$option_name = Plugin::SETTINGS_GROUPS[ $group ][0];
		$settings    = get_option( $option_name, array() );
		if ( ! is_array( $settings ) ) {
			return new \WP_Error( 'invalid_option', __( 'The settings group is not stored as an array.', 'rank-math-mcp-ability' ) );
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

	public static function cb_toggle_module( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to toggle a module.', 'rank-math-mcp-ability' ) );
		}

		$module = (string) $input['module'];
		if ( ! in_array( $module, Plugin::KNOWN_MODULES, true ) ) {
			return new \WP_Error( 'unknown_module', __( 'Unknown module id. See get-status for the module list.', 'rank-math-mcp-ability' ) );
		}

		$old = Plugin::is_module_active( $module );

		// Helper::update_modules() expects module_id => 'on'/'off' and
		// merges into the stored list itself (also creates DB tables for
		// activated modules); it does NOT take a full replacement array.
		if ( method_exists( '\RankMath\Helper', 'update_modules' ) ) {
			\RankMath\Helper::update_modules( array( $module => ! empty( $input['active'] ) ? 'on' : 'off' ) );
		} else {
			$modules = (array) get_option( 'rank_math_modules', array() );
			$modules = ! empty( $input['active'] )
				? array_unique( array_merge( $modules, array( $module ) ) )
				: array_values( array_diff( $modules, array( $module ) ) );
			update_option( 'rank_math_modules', $modules );
		}

		$new = Plugin::is_module_active( $module );

		return array(
			'module' => $module,
			'old'    => $old,
			'new'    => $new,
		);
	}

	public static function cb_get_sitemap_status() {
		if ( ! Plugin::is_module_active( 'sitemap' ) ) {
			return Plugin::module_inactive_error( 'sitemap' );
		}
		if ( ! class_exists( '\RankMath\Sitemap\Cache' ) ) {
			return new \WP_Error( 'sitemap_class_missing', __( 'Rank Math sitemap classes are not loaded.', 'rank-math-mcp-ability' ) );
		}

		$files = \RankMath\Sitemap\Cache::cached_files();

		return array(
			'module_active'   => true,
			'cache_directory' => \RankMath\Sitemap\Cache::get_cache_directory(),
			'cached_files'    => is_array( $files ) ? $files : array(),
		);
	}

	public static function cb_clear_sitemap_cache() {
		if ( ! Plugin::is_module_active( 'sitemap' ) ) {
			return Plugin::module_inactive_error( 'sitemap' );
		}
		if ( ! class_exists( '\RankMath\Sitemap\Cache' ) ) {
			return new \WP_Error( 'sitemap_class_missing', __( 'Rank Math sitemap classes are not loaded.', 'rank-math-mcp-ability' ) );
		}

		$before = \RankMath\Sitemap\Cache::cached_files();
		$old    = is_array( $before ) ? count( $before ) : 0;

		\RankMath\Sitemap\Cache::invalidate_storage();

		$after = \RankMath\Sitemap\Cache::cached_files();

		return array(
			'old' => $old,
			'new' => is_array( $after ) ? count( $after ) : 0,
		);
	}

	public static function cb_get_status() {
		$active  = (array) get_option( 'rank_math_modules', array() );
		$modules = array();
		foreach ( Plugin::KNOWN_MODULES as $module ) {
			$modules[ $module ] = in_array( $module, $active, true );
		}

		return array(
			'version'          => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
			'db_version'       => (string) get_option( 'rank_math_db_version', '' ),
			'modules'          => $modules,
			'tables'           => array(
				'redirections' => null !== Plugin::table_or_null( 'rank_math_redirections' ),
				'404_logs'     => null !== Plugin::table_or_null( 'rank_math_404_logs' ),
				'analytics'    => null !== Plugin::table_or_null( 'rank_math_analytics_gsc' ),
			),
			'is_connected'     => ! empty( get_option( 'rank_math_connect_data', false ) ),
			'known_post_types' => array_values( (array) get_option( 'rank_math_known_post_types', array() ) ),
			'install_date'     => (int) get_option( 'rank_math_install_date', 0 ),
		);
	}
}
