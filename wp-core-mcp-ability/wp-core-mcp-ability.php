<?php
/**
 * Plugin Name: WP Core MCP Ability
 * Plugin URI: https://github.com/bucagdas/wp-mcp-bridges/tree/main/wp-core-mcp-ability
 * Description: Full-coverage WordPress core abilities for MCP. Exposes WordPress's own native core/* abilities to MCP, plus generic options, posts, taxonomies/terms, comments and users CRUD.
 * Version: 1.3.8
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author: bucagdas
 * Author URI: https://github.com/bucagdas/
 * Text Domain: wp-core-mcp-ability
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/abilities-options.php';
require_once __DIR__ . '/includes/abilities-posts.php';
require_once __DIR__ . '/includes/abilities-terms.php';
require_once __DIR__ . '/includes/abilities-comments.php';
require_once __DIR__ . '/includes/abilities-users.php';
require_once __DIR__ . '/includes/abilities-menus.php';
require_once __DIR__ . '/includes/abilities-widgets.php';
require_once __DIR__ . '/includes/abilities-media.php';
require_once __DIR__ . '/includes/abilities-system.php';

require_once __DIR__ . '/includes/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
	'https://raw.githubusercontent.com/bucagdas/wp-mcp-bridges/main/wp-core-mcp-ability/update.json',
	__FILE__,
	'wp-core-mcp-ability'
);

class Plugin {

	const CATEGORY = 'wp-core-mcp';

	/**
	 * Keys matching this pattern are stripped from read output and
	 * refused on write. WordPress core option/meta keys can legitimately
	 * hold secrets (auth keys, API credentials from other plugins).
	 */
	const SENSITIVE_PATTERN = '/(api[_-]?key|token|secret|password|licen[cs]e|credential|auth_key|auth_salt|logged_in_key|logged_in_salt|nonce_key|nonce_salt|secure_auth)/i';

	/**
	 * Options readable/writable via get-option/update-option. Deliberately
	 * narrow: general site identity, reading, discussion and permalink
	 * settings. Never the full options table (that would include the
	 * keys above, transients, and internal WP state).
	 */
	const OPTION_WHITELIST = array(
		'blogname', 'blogdescription', 'admin_email', 'siteurl', 'home',
		'timezone_string', 'gmt_offset', 'date_format', 'time_format',
		'start_of_week', 'WPLANG',
		'default_role', 'users_can_register',
		'posts_per_page', 'default_comment_status', 'default_ping_status',
		'comment_registration', 'close_comments_for_old_posts',
		'close_comments_days_old', 'thread_comments', 'thread_comments_depth',
		'page_comments', 'comments_per_page', 'default_comments_page',
		'comment_order',
		'permalink_structure', 'category_base', 'tag_base',
		'show_on_front', 'page_on_front', 'page_for_posts',
		'blog_public',
	);

	/**
	 * Writable subset of OPTION_WHITELIST — deliberately excludes
	 * siteurl/home. Unlike every other option here (including
	 * admin_email, gated instead via OPTION_CONFIRM_REQUIRED below), a
	 * wrong siteurl/home value can make the entire site — including
	 * wp-admin itself — unreachable through the web layer, with no
	 * recovery path short of direct database or wp-config.php access.
	 * A confirm gate doesn't meaningfully protect against this: the
	 * realistic failure mode is a mistaken value (typo, wrong protocol)
	 * that the caller already believes is correct, so confirming intent
	 * doesn't catch the actual mistake. Still readable via get-option/
	 * list-options (OPTION_WHITELIST) for legitimate diagnostics.
	 * See docs/KOPRU-EKSIKLERI.md's security section.
	 */
	const OPTION_WRITE_WHITELIST = array(
		'blogname', 'blogdescription', 'admin_email',
		'timezone_string', 'gmt_offset', 'date_format', 'time_format',
		'start_of_week', 'WPLANG',
		'default_role', 'users_can_register',
		'posts_per_page', 'default_comment_status', 'default_ping_status',
		'comment_registration', 'close_comments_for_old_posts',
		'close_comments_days_old', 'thread_comments', 'thread_comments_depth',
		'page_comments', 'comments_per_page', 'default_comments_page',
		'comment_order',
		'permalink_structure', 'category_base', 'tag_base',
		'show_on_front', 'page_on_front', 'page_for_posts',
		'blog_public',
	);

	/**
	 * Writable options that need explicit confirm: true. admin_email
	 * receives every critical site notification (including password
	 * resets) — WordPress's own wp-admin settings form only applies it
	 * after the site owner clicks a confirmation link emailed to the
	 * OLD address, but that flow lives in wp-admin's form handler, not
	 * in update_option() itself, so a direct update_option() call (like
	 * this ability's) bypasses it entirely and takes effect immediately.
	 * Confirmed empirically 2026-08-08 — see docs/KOPRU-EKSIKLERI.md.
	 */
	const OPTION_CONFIRM_REQUIRED = array( 'admin_email' );

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'expose_native_core_abilities' ), 10, 2 );
	}

	/**
	 * WordPress's own core/get-site-info, core/get-user-info and
	 * core/get-environment-info abilities exist but ship with no
	 * meta.mcp.public / meta.public flag, so the MCP Adapter default
	 * server never lists them. This bridge does not re-implement them
	 * (that would violate the "don't duplicate native abilities" rule);
	 * it flips the one flag that makes the already-registered native
	 * abilities visible to MCP. No new execute/permission logic is
	 * introduced — core's own callbacks and capability checks are used
	 * unmodified.
	 */
	public static function expose_native_core_abilities( array $args, string $ability_name ): array {
		$allowed = array( 'core/get-site-info', 'core/get-user-info', 'core/get-environment-info' );
		if ( ! in_array( $ability_name, $allowed, true ) ) {
			return $args;
		}
		$args['meta']['mcp']['public'] = true;
		return $args;
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WordPress Core MCP', 'wp-core-mcp-ability' ),
				'description' => __( 'Abilities for standard WordPress core operations: site options, posts, taxonomies, comments and users. Wave 1 of a multi-wave bridge.', 'wp-core-mcp-ability' ),
			)
		);
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		Options::register();
		Posts::register();
		Terms::register();
		Comments::register();
		Users::register();
		Menus::register();
		Widgets::register();
		Media::register();
		System::register();
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
	public static function strip_sensitive( array $data ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				continue;
			}
			$out[ $key ] = is_array( $value ) ? self::strip_sensitive( $value ) : $value;
		}
		return $out;
	}

	/**
	 * True for post types this bridge will operate on: public, or
	 * explicitly show_in_rest (covers e.g. attachments), and never the
	 * internal WP housekeeping types.
	 */
	public static function is_allowed_post_type( string $post_type ): bool {
		$excluded = array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
		if ( in_array( $post_type, $excluded, true ) ) {
			return false;
		}
		$obj = get_post_type_object( $post_type );
		return $obj && ( $obj->public || $obj->show_in_rest );
	}

	/**
	 * True for taxonomies this bridge will operate on: public taxonomies
	 * attached to allowed post types.
	 */
	public static function is_allowed_taxonomy( string $taxonomy ): bool {
		$obj = get_taxonomy( $taxonomy );
		return $obj && $obj->public;
	}

	/**
	 * Resolves a positive-integer identifier from $input[$key] (default
	 * key 'id'), or a WP_Error if missing/invalid. The MCP Adapter's
	 * execute-ability tool skips schema validation before calling an
	 * ability's permission callback, so a wrong/missing parameter would
	 * otherwise silently resolve to 0 here and surface only as a generic
	 * "Permission denied" — see docs/KOPRU-EKSIKLERI.md.
	 */
	public static function resolve_id( $input, string $key = 'id' ) {
		$id = isset( $input[ $key ] ) ? (int) $input[ $key ] : 0;
		if ( $id <= 0 ) {
			return new \WP_Error(
				'missing_id',
				sprintf(
					/* translators: %s: expected parameter name */
					__( 'Provide "%s" (a positive integer ID).', 'wp-core-mcp-ability' ),
					$key
				)
			);
		}
		return $id;
	}
}

add_action( 'plugins_loaded', array( __NAMESPACE__ . '\\Plugin', 'init' ) );
