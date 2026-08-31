<?php
/**
 * Plugin Name: Rank Math MCP Ability
 * Plugin URI: https://github.com/bucagdas/wp-mcp-bridges/tree/main/rank-math-mcp-ability
 * Description: Full-coverage Rank Math SEO abilities for MCP. Per-post SEO metadata (core, robots, social, schema), settings, redirections, 404 monitor, sitemap tools, module toggling and analytics status.
 * Version: 2.1.0
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
	 *
	 * The pattern deliberately errs towards hiding: a key it has never
	 * seen before is refused rather than leaked. What it must not do is
	 * hide ordinary settings, and a bare "auth" alternative did exactly
	 * that -- it matches the "auth" inside "author", so eight author
	 * archive settings (author_robots, url_author_base,
	 * disable_author_archives and friends) were unreadable and
	 * unwritable. "auth" now has to look like a credential to match.
	 */
	const SENSITIVE_PATTERN = '/(api[_-]?key|access[_-]?key|token|secret|password|licen[cs]e|licensing|credential|connect|activation|oauth|authoriz|auth[_-]?(?:key|token|code|secret))/i';

	/**
	 * Keys that match the pattern but are known to hold nothing secret,
	 * checked against Rank Math 1.0.277.1's four settings groups (182
	 * distinct keys): "password" occurs there only inside a robots
	 * directive, never as a credential.
	 */
	const SENSITIVE_ALLOWLIST = array(
		'noindex_password_protected',
	);

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
		// Late enough that Rank Math's own modules have booted and we can
		// see whether it registered its watcher for this request.
		add_action( 'wp_loaded', array( __CLASS__, 'register_sitemap_cache_watcher' ), 20 );
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

	const REDACTED = '***REDACTED***';

	public static function is_sensitive_key( string $key ): bool {
		if ( in_array( strtolower( $key ), self::SENSITIVE_ALLOWLIST, true ) ) {
			return false;
		}
		return (bool) preg_match( self::SENSITIVE_PATTERN, $key );
	}

	/**
	 * Recursively redact keys that may contain secrets. A matched key's
	 * value is replaced with REDACTED rather than removed outright: a
	 * key like "connect_data" matches on its own name (contains
	 * "connect") even though most of what it holds (e.g. a "plan"
	 * field) isn't secret — silently dropping the whole key made that
	 * sibling data disappear with no indication anything was ever there,
	 * which is over-hiding, not a leak, but still worth being precise
	 * about. Found during the 2026-08-08 security audit; see
	 * docs/KOPRU-EKSIKLERI.md.
	 */
	public static function strip_sensitive( array $settings ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				$out[ $key ] = self::REDACTED;
				continue;
			}
			$out[ $key ] = is_array( $value ) ? self::strip_sensitive( $value ) : $value;
		}
		return $out;
	}

	/**
	 * Rank Math keeps its settings in an in-memory object built once per
	 * request, so writing the option straight through leaves everything
	 * that reads Helper::get_settings() in that same request looking at
	 * the old value -- and nothing reports an error, the write really did
	 * land in the database. Measured on 1.0.277.1: after update-settings
	 * wrote sitemap.items_per_page = 207, get_option() returned 207 while
	 * Helper::get_settings('sitemap.items_per_page') still returned 200
	 * until reset() ran. Rank Math's own settings abilities call this
	 * straight after saving, so the bridge does too rather than inventing
	 * its own invalidation.
	 */
	public static function reset_settings_cache(): void {
		if ( ! function_exists( 'rank_math' ) ) {
			return;
		}
		$rank_math = rank_math();
		if ( isset( $rank_math->settings ) && method_exists( $rank_math->settings, 'reset' ) ) {
			$rank_math->settings->reset();
		}
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
	// Sitemap cache invalidation for non-admin writes
	// ---------------------------------------------------------------------

	/** True once we've attached our shutdown drain, so we only add it once. */
	private static $sitemap_drain_registered = false;

	/**
	 * Rank Math only invalidates its sitemap cache when it is running in
	 * wp-admin or WP-Cron. Its class-sitemap.php does:
	 *
	 *     if ( is_admin() || wp_doing_cron() ) { new Cache_Watcher(); }
	 *
	 * Cache_Watcher is the ONLY thing that registers the save_post /
	 * transition_post_status / term / author / shutdown hooks that clear
	 * the cached sitemap XML. So for any write that happens outside those
	 * two contexts — a REST request (which is how this bridge, and how
	 * the block editor itself, saves), or WP-CLI — the hooks are never
	 * registered at all and the cache is never invalidated. The guard is
	 * on REGISTRATION, not inside the callback, so a perfectly ordinary
	 * wp_insert_post() cannot possibly trigger it.
	 *
	 * Verified on a live site (2026-08-09) end to end over HTTP: with the
	 * cache warm, creating 3 posts through the bridge left /post-sitemap.xml
	 * serving the old 4 URLs; after invalidating, the same URL returned 7.
	 * This matches the field report (69 pages created, sitemap stuck at 2
	 * URLs for two days). Rank Math's own Redirections module gets this
	 * right one file over — its watcher gate is
	 * `is_admin() || Helper::is_rest()` — so this is a gap in the Sitemap
	 * module specifically, not an intentional design.
	 *
	 * We therefore register the same hooks ourselves when Rank Math has
	 * not, and route them to Rank Math's OWN public invalidation methods
	 * rather than inventing our own cache-clearing logic:
	 *   - Cache_Watcher::invalidate_object_type( 'post'|'term'|'user', $id )
	 *     dispatches to invalidate_post/invalidate_term/invalidate_author,
	 *     each of which honours Rank Math's own rules (skips revisions,
	 *     respects the per-taxonomy tax_<tax>_sitemap setting, ...).
	 *   - Those only QUEUE the affected types into a static array; the
	 *     queue is drained by Cache_Watcher::clear_queued(), which Rank
	 *     Math normally attaches to `shutdown`. Since that attachment is
	 *     part of the registration we're standing in for, we attach it too
	 *     — this is also what gives us free coalescing: N writes in one
	 *     request produce exactly one storage invalidation.
	 *
	 * Deliberately NOT debounced across requests. Measured on this install:
	 * invalidating a warm cache costs 1.78 ms, and invalidating an already
	 * empty one costs 0.415 ms — and after the first invalidation of a
	 * batch the cache stays empty (nothing re-populates it until someone
	 * actually requests the sitemap), so a 69-page bulk run costs about
	 * 30 ms in total. A cron- or timestamp-based debounce would add a
	 * staleness window and moving parts to save that. See
	 * docs/KOPRU-EKSIKLERI.md madde 15.
	 */
	public static function register_sitemap_cache_watcher(): void {
		// Rank Math already registered its own watcher for this request.
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}
		if ( ! class_exists( '\RankMath\Sitemap\Cache_Watcher' ) || ! self::is_module_active( 'sitemap' ) ) {
			return;
		}

		add_action( 'save_post', array( __CLASS__, 'sitemap_invalidate_post' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'sitemap_invalidate_post' ), 10, 1 );
		add_action( 'edited_term', array( __CLASS__, 'sitemap_invalidate_term' ), 10, 3 );
		add_action( 'created_term', array( __CLASS__, 'sitemap_invalidate_term' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'sitemap_invalidate_term' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'sitemap_invalidate_user' ), 10, 1 );
		add_action( 'user_register', array( __CLASS__, 'sitemap_invalidate_user' ), 10, 1 );
		add_action( 'delete_user', array( __CLASS__, 'sitemap_invalidate_user' ), 10, 1 );
	}

	/**
	 * Queues the drain exactly once, the first time something is actually
	 * invalidated — so a request that writes nothing adds no shutdown work.
	 */
	private static function queue_sitemap_drain(): void {
		if ( self::$sitemap_drain_registered ) {
			return;
		}
		self::$sitemap_drain_registered = true;
		add_action( 'shutdown', array( '\RankMath\Sitemap\Cache_Watcher', 'clear_queued' ) );
	}

	public static function sitemap_invalidate_post( $post_id, $post = null ): void {
		$post = $post ? $post : get_post( $post_id );
		// Mirrors Rank Math's own Cache_Watcher::save_post() guards: an
		// auto-draft or a password-protected post is not in the sitemap,
		// so invalidating for it would be pure noise. Revisions are
		// filtered by invalidate_post() itself.
		if ( $post && ( 'auto-draft' === $post->post_status || '' !== (string) $post->post_password ) ) {
			return;
		}
		\RankMath\Sitemap\Cache_Watcher::invalidate_object_type( 'post', (int) $post_id );
		self::queue_sitemap_drain();
	}

	public static function sitemap_invalidate_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		// invalidate_object_type('term', ...) re-reads the term to find its
		// taxonomy, which fails on delete_term (the term is already gone) —
		// so call the taxonomy-aware method directly with what the hook
		// hands us. It still honours the tax_<taxonomy>_sitemap setting.
		if ( $taxonomy ) {
			\RankMath\Sitemap\Cache_Watcher::invalidate_term( $term_id, $taxonomy );
		} else {
			\RankMath\Sitemap\Cache_Watcher::invalidate_object_type( 'term', (int) $term_id );
		}
		self::queue_sitemap_drain();
	}

	public static function sitemap_invalidate_user( $user_id ): void {
		\RankMath\Sitemap\Cache_Watcher::invalidate_object_type( 'user', (int) $user_id );
		self::queue_sitemap_drain();
	}

	// ---------------------------------------------------------------------
	// Shared permission callbacks
	// ---------------------------------------------------------------------

	public static function permission_edit_post( $input = null ) {
		$post_id = self::resolve_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Resolves a post id from the preferred `id` key, falling back to the
	 * deprecated `post_id` alias. Returns a WP_Error (surfaced verbatim by
	 * the MCP Adapter's execute-ability tool, instead of a generic
	 * "Permission denied" — see docs/KOPRU-EKSIKLERI.md) when neither is a
	 * positive integer, rather than silently defaulting to 0 and letting a
	 * capability check on a non-existent object fail with no explanation.
	 */
	public static function resolve_post_id( $input ) {
		$id = 0;
		if ( isset( $input['id'] ) ) {
			$id = (int) $input['id'];
		} elseif ( isset( $input['post_id'] ) ) {
			$id = (int) $input['post_id'];
		}
		if ( $id <= 0 ) {
			return new \WP_Error(
				'missing_id',
				__( 'Provide "id" (the post/page ID). "post_id" is also accepted as a deprecated alias for backward compatibility.', 'rank-math-mcp-ability' )
			);
		}
		return $id;
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
