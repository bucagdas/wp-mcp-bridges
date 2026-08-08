<?php
/**
 * Plugin/theme status, cron inventory and rewrite-rule abilities.
 *
 * All read-only except flush-rewrite-rules, which is safe/idempotent
 * (regenerates the rewrite cache, does not change configuration).
 * Plugin/theme activation, installation and deletion are out of scope
 * — GOLDEN RULE 8 ("no installs") and the A6 code-writing-surface gate.
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class System {

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/list-plugins',
			array(
				'label'               => __( 'List plugins', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists installed plugins with name, version, active status and description. Read-only — this bridge never activates, deactivates, installs or deletes plugins.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "plugins": array of {file, name, version, active, description}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_plugins' ),
				'permission_callback' => array( __CLASS__, 'permission_plugins' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-plugin',
			array(
				'label'               => __( 'Get plugin details', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns the header details of one installed plugin (name, version, author, plugin/author URI, requires-WP/PHP, description) and whether it is active.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'file' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Plugin file path relative to the plugins directory, e.g. "akismet/akismet.php" (see list-plugins).',
						),
					),
					'required'             => array( 'file' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Plugin header details.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_plugin' ),
				'permission_callback' => array( __CLASS__, 'permission_plugins' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/list-themes',
			array(
				'label'               => __( 'List themes', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists installed themes with name, version, active status and whether they are a child theme. Read-only — this bridge never switches, installs or deletes themes.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "themes": array of {slug, name, version, active, parent}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_themes' ),
				'permission_callback' => array( __CLASS__, 'permission_themes' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-active-theme',
			array(
				'label'               => __( 'Get the active theme', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns details of the currently active theme: name, version, template (parent theme slug for child themes), author, and registered menu locations / sidebars it supports.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Active theme details, menu locations and sidebars.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_active_theme' ),
				'permission_callback' => array( __CLASS__, 'permission_themes' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-site-health-summary',
			array(
				'label'               => __( 'Get Site Health summary', 'wp-core-mcp-ability' ),
				'description'         => __( 'Runs WordPress\'s built-in Site Health "direct" tests (the ones that don\'t require an outbound network request: PHP version/extensions, database, scheduled events, filesystem permissions, etc.) and returns each test\'s status (good/recommended/critical) and description.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "tests": array of {id, label, status, description}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_site_health_summary' ),
				'permission_callback' => array( __CLASS__, 'permission_site_health' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-site-health-info',
			array(
				'label'               => __( 'Get Site Health system info', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns the same system information shown under Tools > Site Health > Info: WordPress/PHP/database/server details, active theme/plugins, and filesystem permissions. Sensitive-looking fields are filtered out. section optionally limits the result to one panel (e.g. "wp-core", "wp-server", "wp-database").', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'section' => array(
							'type'        => 'string',
							'description' => 'Optional section key to return only that panel.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Site Health info data, keyed by section.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_site_health_info' ),
				'permission_callback' => array( __CLASS__, 'permission_site_health' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/create-cron-event',
			array(
				'label'               => __( 'Schedule a cron event', 'wp-core-mcp-ability' ),
				'description'         => __( 'Schedules a WP-Cron event. Provide recurrence ("hourly", "twicedaily", "daily", or another registered schedule) for a repeating event, or omit it for a one-time event. timestamp defaults to now. Note: this only schedules the hook to fire; unless a plugin/theme already has a handler attached to that hook, firing it does nothing.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hook'       => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Action hook name to fire.',
						),
						'recurrence' => array(
							'type'        => 'string',
							'description' => 'Recurrence key (e.g. "hourly", "daily"). Omit for a one-time event.',
						),
						'timestamp'  => array(
							'type'        => 'integer',
							'description' => 'Unix timestamp for the (first) run. Defaults to now.',
						),
						'args'       => array(
							'type'        => 'array',
							'description' => 'Arguments passed to the hook when it fires.',
						),
					),
					'required'             => array( 'hook' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "hook", "next_run" and "recurrence".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create_cron' ),
				'permission_callback' => array( __CLASS__, 'permission_manage_options' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/delete-cron-event',
			array(
				'label'               => __( 'Unschedule a cron event', 'wp-core-mcp-ability' ),
				'description'         => __( 'Removes a scheduled cron event by hook (and args, if the event was scheduled with any). Requires confirm: true — unscheduling a core or plugin hook (e.g. wp_version_check) can disable functionality that depends on it running.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hook'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Hook name to unschedule.',
						),
						'args'    => array(
							'type'        => 'array',
							'description' => 'Must match the args the event was scheduled with, if any.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'hook', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "hook" and "removed" (count of scheduled occurrences removed).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete_cron' ),
				'permission_callback' => array( __CLASS__, 'permission_manage_options' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/trigger-cron-event',
			array(
				'label'               => __( 'Trigger a cron event now', 'wp-core-mcp-ability' ),
				'description'         => __( 'Fires a hook immediately via do_action(), exactly as WP-Cron would when the event is due. Requires confirm: true. WARNING: this runs whatever code any active plugin or theme has attached to that hook — only trigger hooks whose behavior you understand (see list-cron-events for what is normally scheduled).', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'hook'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Hook name to fire.',
						),
						'args'    => array(
							'type'        => 'array',
							'description' => 'Arguments to pass to the hook.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'hook', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "hook" and "fired" (boolean).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_trigger_cron' ),
				'permission_callback' => array( __CLASS__, 'permission_manage_options' ),
				'meta'                => Plugin::meta( false, true, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/list-cron-events',
			array(
				'label'               => __( 'List scheduled cron events', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists WP-Cron scheduled events: hook name, next run time, recurrence and args. Read-only inventory — this bridge does not schedule, unschedule or trigger cron events.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "events": array of {hook, next_run, recurrence, args}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_cron' ),
				'permission_callback' => array( __CLASS__, 'permission_manage_options' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/flush-rewrite-rules',
			array(
				'label'               => __( 'Flush rewrite rules', 'wp-core-mcp-ability' ),
				'description'         => __( 'Regenerates WordPress\'s permalink rewrite rules — the same action as visiting Settings > Permalinks and clicking Save, useful after registering a custom post type/taxonomy or changing permalink-affecting settings. Safe and idempotent; does not change the permalink structure itself.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "flushed" (boolean) and "rule_count" (number of rules after flushing).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_flush_rewrite' ),
				'permission_callback' => array( __CLASS__, 'permission_manage_options' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public static function permission_plugins( $input = null ): bool {
		return current_user_can( 'activate_plugins' );
	}

	public static function permission_themes( $input = null ): bool {
		return current_user_can( 'switch_themes' );
	}

	public static function permission_manage_options( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	public static function permission_site_health( $input = null ): bool {
		return current_user_can( 'view_site_health_checks' );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );

		$plugins = array();
		foreach ( $all as $file => $data ) {
			$plugins[] = array(
				'file'        => $file,
				'name'        => $data['Name'],
				'version'     => $data['Version'],
				'active'      => in_array( $file, $active, true ),
				'description' => wp_strip_all_tags( $data['Description'] ),
			);
		}

		return array( 'plugins' => $plugins );
	}

	public static function cb_get_plugin( $input ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$file = (string) $input['file'];
		$all  = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			return new \WP_Error( 'plugin_not_found', __( 'No plugin exists at the given file path. See list-plugins for valid values.', 'wp-core-mcp-ability' ) );
		}

		$data   = $all[ $file ];
		$active = (array) get_option( 'active_plugins', array() );

		return array(
			'file'         => $file,
			'name'         => $data['Name'],
			'version'      => $data['Version'],
			'author'       => wp_strip_all_tags( $data['Author'] ),
			'plugin_uri'   => $data['PluginURI'],
			'requires_wp'  => $data['RequiresWP'],
			'requires_php' => $data['RequiresPHP'],
			'description'  => wp_strip_all_tags( $data['Description'] ),
			'active'       => in_array( $file, $active, true ),
		);
	}

	public static function cb_list_themes() {
		$themes       = wp_get_themes();
		$active_stylesheet = get_stylesheet();

		$out = array();
		foreach ( $themes as $slug => $theme ) {
			$out[] = array(
				'slug'    => $slug,
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'active'  => $slug === $active_stylesheet,
				'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			);
		}

		return array( 'themes' => $out );
	}

	public static function cb_get_active_theme() {
		$theme = wp_get_theme();

		return array(
			'slug'            => $theme->get_stylesheet(),
			'name'            => (string) $theme->get( 'Name' ),
			'version'         => (string) $theme->get( 'Version' ),
			'template'        => $theme->get_template(),
			'author'          => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'is_child_theme'  => $theme->parent() !== false,
			'menu_locations'  => array_keys( get_registered_nav_menus() ),
			'sidebars'        => array_keys( (array) $GLOBALS['wp_registered_sidebars'] ),
		);
	}

	/**
	 * Site Health's admin screen normally has these preloaded; several
	 * individual tests / debug-data fields call into plugin/theme/core
	 * update-check helpers that live here.
	 */
	private static function require_site_health_deps(): void {
		foreach ( array( 'update.php', 'plugin.php', 'theme.php', 'file.php', 'misc.php' ) as $file ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/' . $file ) ) {
				require_once ABSPATH . 'wp-admin/includes/' . $file;
			}
		}
	}

	public static function cb_site_health_summary() {
		if ( ! class_exists( '\WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		self::require_site_health_deps();

		$health = \WP_Site_Health::get_instance();
		$tests  = \WP_Site_Health::get_tests();

		$results = array();
		foreach ( (array) ( $tests['direct'] ?? array() ) as $id => $test ) {
			$method = 'get_test_' . $test['test'];
			if ( ! method_exists( $health, $method ) ) {
				continue;
			}
			try {
				$result = call_user_func( array( $health, $method ) );
			} catch ( \Throwable $e ) {
				$results[] = array(
					'id'          => $id,
					'label'       => $test['label'],
					'status'      => 'error',
					'description' => $e->getMessage(),
				);
				continue;
			}
			$results[] = array(
				'id'          => $id,
				'label'       => $result['label'] ?? $test['label'],
				'status'      => $result['status'] ?? 'unknown',
				'description' => wp_strip_all_tags( (string) ( $result['description'] ?? '' ) ),
			);
		}

		return array( 'tests' => $results );
	}

	public static function cb_site_health_info( $input ) {
		if ( ! class_exists( '\WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		self::require_site_health_deps();

		$info = \WP_Debug_Data::debug_data();

		$out = array();
		foreach ( $info as $section => $data ) {
			$fields = array();
			foreach ( (array) ( $data['fields'] ?? array() ) as $key => $field ) {
				if ( Plugin::is_sensitive_key( (string) $key ) ) {
					continue;
				}
				$fields[ $key ] = array(
					'label' => $field['label'] ?? $key,
					'value' => $field['value'] ?? null,
				);
			}
			$out[ $section ] = array(
				'label'  => $data['label'] ?? $section,
				'fields' => $fields,
			);
		}

		if ( ! empty( $input['section'] ) ) {
			$section = (string) $input['section'];
			return isset( $out[ $section ] ) ? array( $section => $out[ $section ] ) : new \WP_Error( 'section_not_found', __( 'Unknown Site Health info section.', 'wp-core-mcp-ability' ) );
		}

		return $out;
	}

	public static function cb_create_cron( $input ) {
		$hook      = (string) $input['hook'];
		$timestamp = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : time();
		$args      = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();

		if ( ! empty( $input['recurrence'] ) ) {
			$recurrence = (string) $input['recurrence'];
			$schedules  = wp_get_schedules();
			if ( ! isset( $schedules[ $recurrence ] ) ) {
				return new \WP_Error( 'unknown_recurrence', sprintf(
					/* translators: %s: comma-separated list of valid recurrence keys */
					__( 'Unknown recurrence. Valid values: %s.', 'wp-core-mcp-ability' ),
					implode( ', ', array_keys( $schedules ) )
				) );
			}
			$result = wp_schedule_event( $timestamp, $recurrence, $hook, $args );
		} else {
			$recurrence = null;
			$result     = wp_schedule_single_event( $timestamp, $hook, $args );
		}

		if ( false === $result || is_wp_error( $result ) ) {
			return new \WP_Error( 'schedule_failed', __( 'The event could not be scheduled.', 'wp-core-mcp-ability' ) );
		}

		$next = wp_next_scheduled( $hook, $args );

		return array(
			'hook'       => $hook,
			'next_run'   => $next ? gmdate( 'Y-m-d H:i:s', $next ) : null,
			'recurrence' => $recurrence ?? 'single',
		);
	}

	public static function cb_delete_cron( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to unschedule a cron event.', 'wp-core-mcp-ability' ) );
		}

		$hook = (string) $input['hook'];
		$args = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();

		$removed = wp_clear_scheduled_hook( $hook, $args );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		return array(
			'hook'    => $hook,
			'removed' => (int) $removed,
		);
	}

	public static function cb_trigger_cron( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to fire a hook immediately. This runs whatever code is attached to it.', 'wp-core-mcp-ability' ) );
		}

		$hook = (string) $input['hook'];
		$args = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();

		do_action_ref_array( $hook, $args );

		return array(
			'hook'  => $hook,
			'fired' => true,
		);
	}

	public static function cb_list_cron() {
		$crons  = _get_cron_array();
		$events = array();

		foreach ( (array) $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $entries ) {
				foreach ( $entries as $entry ) {
					$events[] = array(
						'hook'       => $hook,
						'next_run'   => gmdate( 'Y-m-d H:i:s', $timestamp ),
						'recurrence' => $entry['schedule'] ? $entry['schedule'] : 'single',
						'args'       => $entry['args'],
					);
				}
			}
		}

		return array( 'events' => $events );
	}

	public static function cb_flush_rewrite() {
		flush_rewrite_rules( false );

		$rules = get_option( 'rewrite_rules' );

		return array(
			'flushed'    => true,
			'rule_count' => is_array( $rules ) ? count( $rules ) : 0,
		);
	}
}
