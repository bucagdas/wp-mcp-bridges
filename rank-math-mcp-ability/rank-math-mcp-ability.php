<?php
/**
 * Plugin Name: Rank Math MCP Ability
 * Plugin URI: https://github.com/bucagdas/wp-mcp-bridges/tree/main/rank-math-mcp-ability
 * Description: Full-coverage Rank Math SEO abilities for MCP. Per-post SEO metadata (core, robots, social, schema), settings, redirections, 404 monitor, sitemap tools, module toggling and analytics status.
 * Version: 2.0.1
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author: bucagdas
 * Author URI: https://github.com/bucagdas/
 * Text Domain: rank-math-mcp-ability
 * Requires Plugins: seo-by-rank-math
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace RankMathMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/abilities-post-meta.php';
require_once __DIR__ . '/includes/abilities-redirections.php';
require_once __DIR__ . '/includes/abilities-monitor.php';
require_once __DIR__ . '/includes/abilities-site.php';

require_once __DIR__ . '/includes/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
	'https://raw.githubusercontent.com/bucagdas/wp-mcp-bridges/main/rank-math-mcp-ability/update.json',
	__FILE__,
	'rank-math-mcp-ability'
);

class Plugin {

	const CATEGORY = 'rank-math-mcp';

	/**
	 * Keys matching this pattern are stripped from read output and
	 * refused on write. License/connection data is a hard red line.
	 */
	const SENSITIVE_PATTERN = '/(api[_-]?key|token|secret|password|licen[cs]e|licensing|credential|auth|connect|activation)/i';

	/**
	 * Settings groups exposed by get/update-settings, mapped to option
	 * names and the Rank Math capability required.
	 */
	const SETTINGS_GROUPS = array(
		'general'          => array( 'rank-math-options-general', 'rank_math_general' ),
		'titles'           => array( 'rank-math-options-titles', 'rank_math_titles' ),
		'sitemap'          => array( 'rank-math-options-sitemap', 'rank_math_sitemap' ),
		'instant-indexing' => array( 'rank-math-options-instant-indexing', 'rank_math_general' ),
	);

	/**
	 * Toggleable Rank Math module ids (module dirs + ids seen in
	 * rank_math_modules; "rich-snippet" = schema, "link-counter" = links).
	 */
	const KNOWN_MODULES = array(
		'404-monitor', 'acf', 'ai-visibility', 'analytics', 'bbpress',
		'buddypress', 'content-ai', 'image-seo', 'instant-indexing',
		'link-counter', 'llms', 'local-seo', 'redirections', 'rich-snippet',
		'robots-txt', 'seo-analysis', 'sitemap', 'web-stories', 'woocommerce',
	);

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Rank Math MCP', 'rank-math-mcp-ability' ),
				'description' => __( 'Full-coverage abilities for Rank Math SEO: per-post metadata, settings, redirections, 404 monitor, sitemap tools and modules.', 'rank-math-mcp-ability' ),
			)
		);
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'RankMath\\Helper' ) ) {
			return;
		}
		Post_Meta::register();
		Redirections::register();
		Monitor::register();
		Site::register();
	}

	// ---------------------------------------------------------------------
	// Shared helpers
	// ---------------------------------------------------------------------

	public static function meta( bool $readonly, bool $destructive, bool $idempotent ): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
			),
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
	}

	public static function is_sensitive_key( string $key ): bool {
		return (bool) preg_match( self::SENSITIVE_PATTERN, $key );
	}

	/**
	 * Recursively remove keys that may contain secrets.
	 */
	public static function strip_sensitive( array $settings ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				continue;
			}
			$out[ $key ] = is_array( $value ) ? self::strip_sensitive( $value ) : $value;
		}
		return $out;
	}

	/**
	 * WP_Error shared by verbs whose module is not active.
	 */
	public static function module_inactive_error( string $module ): \WP_Error {
		return new \WP_Error(
			'module_inactive',
			sprintf(
				/* translators: %s: module id */
				__( 'The Rank Math "%s" module is not active (or its database table does not exist yet). Activate it with rank-math-mcp/toggle-module and visit the admin once so Rank Math creates its tables.', 'rank-math-mcp-ability' ),
				$module
			)
		);
	}

	/**
	 * True when the given Rank Math module id is in rank_math_modules.
	 */
	public static function is_module_active( string $module ): bool {
		return in_array( $module, (array) get_option( 'rank_math_modules', array() ), true );
	}

	/**
	 * Full table name if it exists, null otherwise.
	 */
	public static function table_or_null( string $suffix ): ?string {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ? $table : null;
	}

	// ---------------------------------------------------------------------
	// Shared permission callbacks
	// ---------------------------------------------------------------------

	public static function permission_edit_post( $input = null ): bool {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public static function permission_edit_posts( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function permission_edit_others_posts( $input = null ): bool {
		return current_user_can( 'edit_others_posts' );
	}

	public static function permission_manage_options( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	public static function permission_settings( $input = null ): bool {
		$group = isset( $input['group'] ) ? (string) $input['group'] : '';
		if ( ! isset( self::SETTINGS_GROUPS[ $group ] ) ) {
			return false;
		}
		return current_user_can( self::SETTINGS_GROUPS[ $group ][1] );
	}

	public static function permission_general( $input = null ): bool {
		return current_user_can( 'rank_math_general' );
	}

	public static function permission_redirections( $input = null ): bool {
		return current_user_can( 'rank_math_redirections' );
	}

	public static function permission_404( $input = null ): bool {
		return current_user_can( 'rank_math_404_monitor' );
	}

	public static function permission_sitemap( $input = null ): bool {
		return current_user_can( 'rank_math_sitemap' );
	}

	public static function permission_analytics( $input = null ): bool {
		return current_user_can( 'rank_math_analytics' );
	}
}

add_action( 'plugins_loaded', array( __NAMESPACE__ . '\\Plugin', 'init' ) );
