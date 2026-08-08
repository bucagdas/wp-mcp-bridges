<?php
/**
 * Plugin Name: GeneratePress MCP Ability
 * Plugin URI: https://github.com/bucagdas/wp-mcp-bridges/tree/main/generatepress-mcp-ability
 * Description: GeneratePress ecosystem abilities for MCP. Theme settings, GP Premium module status, GP Elements (full CRUD), GenerateBlocks settings, global styles (full CRUD) and Pro pattern libraries. Components are detected at runtime; abilities of missing components are simply not registered.
 * Version: 1.3.1
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author: bucagdas
 * Author URI: https://github.com/bucagdas/
 * Text Domain: generatepress-mcp-ability
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace GeneratePressMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/abilities-theme.php';
require_once __DIR__ . '/includes/abilities-gp.php';
require_once __DIR__ . '/includes/abilities-gb.php';

require_once __DIR__ . '/includes/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
	'https://raw.githubusercontent.com/bucagdas/wp-mcp-bridges/main/generatepress-mcp-ability/update.json',
	__FILE__,
	'generatepress-mcp-ability'
);

class Plugin {

	const CATEGORY = 'generatepress-mcp';

	/**
	 * Keys matching this pattern are stripped from read output and must be
	 * refused by any future write ability. Same pattern as the Rank Math
	 * bridge.
	 */
	const SENSITIVE_PATTERN = '/(api[_-]?key|token|secret|password|licen[cs]e|licensing|credential|auth|connect)/i';

	/**
	 * GP Premium modules as option-suffix => activation constant.
	 * Mirrors the generatepress_is_module_active() calls in gp-premium.php.
	 */
	/**
	 * Per-post layout meta: input field => meta key.
	 */
	const POST_LAYOUT_FIELDS = array(
		'sidebar_layout'     => '_generate-sidebar-layout-meta',
		'footer_widgets'     => '_generate-footer-widget-meta',
		'full_width_content' => '_generate-full-width-content',
	);

	/**
	 * SVG tags/attributes merged into the 'post' kses whitelist for GP
	 * Element content validation, so inline icon SVGs survive. Deliberately
	 * excludes <script>, <foreignObject>, <use>/<image> (remote/href-based
	 * loading) and all "on*" event-handler attributes — those stay
	 * unwhitelisted so kses strips them regardless of tag.
	 */
	const SVG_ALLOWED_TAGS = array(
		'svg'      => array(
			'xmlns'           => true,
			'viewbox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'class'           => true,
			'id'              => true,
			'aria-hidden'     => true,
			'aria-label'      => true,
			'focusable'       => true,
			'role'            => true,
			'preserveaspectratio' => true,
		),
		'path'     => array(
			'd'                => true,
			'fill'             => true,
			'stroke'           => true,
			'stroke-width'     => true,
			'stroke-linecap'   => true,
			'stroke-linejoin'  => true,
			'class'            => true,
			'fill-rule'        => true,
			'clip-rule'        => true,
		),
		'g'        => array(
			'fill'      => true,
			'stroke'    => true,
			'class'     => true,
			'transform' => true,
			'id'        => true,
		),
		'defs'     => array(),
		'clippath' => array( 'id' => true ),
		'circle'   => array(
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		),
		'rect'     => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'ry'     => true,
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		),
		'line'     => array(
			'x1'     => true,
			'y1'     => true,
			'x2'     => true,
			'y2'     => true,
			'stroke' => true,
			'class'  => true,
		),
		'polygon'  => array(
			'points' => true,
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		),
		'polyline' => array(
			'points' => true,
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		),
		'ellipse'  => array(
			'cx'     => true,
			'cy'     => true,
			'rx'     => true,
			'ry'     => true,
			'fill'   => true,
			'stroke' => true,
			'class'  => true,
		),
	);

	/**
	 * Disable Elements flags: input field => meta key. The metas take
	 * effect when GP Premium's Disable Elements module is active.
	 */
	const DISABLE_FIELDS = array(
		'header'         => '_generate-disable-header',
		'footer'         => '_generate-disable-footer',
		'headline'       => '_generate-disable-headline',
		'mobile_header'  => '_generate-disable-mobile-header',
		'nav'            => '_generate-disable-nav',
		'secondary_nav'  => '_generate-disable-secondary-nav',
		'top_bar'        => '_generate-disable-top-bar',
		'post_image'     => '_generate-disable-post-image',
	);

	const SIDEBAR_LAYOUTS = array( '', 'left-sidebar', 'right-sidebar', 'no-sidebar', 'both-sidebars', 'both-left', 'both-right' );

	/**
	 * GP Premium module settings groups: group => option name.
	 */
	const MODULE_SETTINGS = array(
		'blog'          => 'generate_blog_settings',
		'menu_plus'     => 'generate_menu_plus_settings',
		'secondary_nav' => 'generate_secondary_nav_settings',
		'spacing'       => 'generate_spacing_settings',
		'page_header'   => 'generate_page_header_options',
		'backgrounds'   => 'generate_background_settings',
		'hooks'         => 'generate_hooks',
	);

	const GP_MODULES = array(
		'backgrounds'      => 'GENERATE_BACKGROUNDS',
		'blog'             => 'GENERATE_BLOG',
		'copyright'        => 'GENERATE_COPYRIGHT',
		'disable_elements' => 'GENERATE_DISABLE_ELEMENTS',
		'elements'         => 'GENERATE_ELEMENTS',
		'secondary_nav'    => 'GENERATE_SECONDARY_NAV',
		'spacing'          => 'GENERATE_SPACING',
		'menu_plus'        => 'GENERATE_MENU_PLUS',
		'woocommerce'      => 'GENERATE_WOOCOMMERCE',
		'hooks'            => 'GENERATE_HOOKS',
		'page_header'      => 'GENERATE_PAGE_HEADER',
		'sections'         => 'GENERATE_SECTIONS',
		'typography'       => 'GENERATE_TYPOGRAPHY',
		'colors'           => 'GENERATE_COLORS',
		'site_library'     => 'GENERATE_SITE_LIBRARY',
	);

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_action( 'admin_notices', array( __CLASS__, 'missing_components_notice' ) );
	}

	// ---------------------------------------------------------------------
	// Component detection
	// ---------------------------------------------------------------------

	public static function has_theme(): bool {
		return 'generatepress' === get_template();
	}

	public static function has_gp_premium(): bool {
		return defined( 'GP_PREMIUM_VERSION' );
	}

	public static function has_gb(): bool {
		return defined( 'GENERATEBLOCKS_VERSION' );
	}

	public static function has_gb_pro(): bool {
		return defined( 'GENERATEBLOCKS_PRO_VERSION' );
	}

	/**
	 * One-line admin notice listing missing components (if any).
	 */
	public static function missing_components_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = array();
		if ( ! self::has_theme() ) {
			$missing[] = 'GeneratePress theme';
		}
		if ( ! self::has_gp_premium() ) {
			$missing[] = 'GP Premium';
		}
		if ( ! self::has_gb() ) {
			$missing[] = 'GenerateBlocks';
		}
		if ( ! self::has_gb_pro() ) {
			$missing[] = 'GenerateBlocks Pro';
		}
		if ( empty( $missing ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: %s: comma-separated component names. */
				__( 'GeneratePress MCP Ability: abilities for these missing components were not registered: %s.', 'generatepress-mcp-ability' ),
				implode( ', ', $missing )
			) )
		);
	}

	/**
	 * Register the ability category. Must run on wp_abilities_api_categories_init.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'GeneratePress MCP', 'generatepress-mcp-ability' ),
				'description' => __( 'Read-only abilities for inspecting the GeneratePress ecosystem: theme settings, GP Premium modules and Elements, GenerateBlocks settings and global styles.', 'generatepress-mcp-ability' ),
			)
		);
	}

	/**
	 * Register abilities per detected component. Must run on wp_abilities_api_init.
	 */

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// 1. Ecosystem status probe — always registered.
		wp_register_ability(
			'generatepress-mcp/get-status',
			array(
				'label'               => __( 'Get GeneratePress ecosystem status', 'generatepress-mcp-ability' ),
				'description'         => __( 'Returns the status of the GeneratePress ecosystem: active theme and version, GP Premium version and per-module activation map, GenerateBlocks and GenerateBlocks Pro versions, GP Element and global style counts, and dynamic CSS cache info. Reports missing components instead of failing.', 'generatepress-mcp-ability' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Component versions, GP Premium module activation map, content counts and CSS cache info.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_status' ),
				'permission_callback' => array( __CLASS__, 'permission_theme_options' ),
				'meta'                => self::meta( true, false, true ),
			)
		);


		Theme::register();
		GP::register();
		GB::register();
	}

	/**
	 * Build the meta array for an ability.
	 */
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

	// ---------------------------------------------------------------------
	// Permission callbacks
	// ---------------------------------------------------------------------

	public static function permission_theme_options( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	public static function permission_manage_options( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	public static function permission_gp_elements( $input = null ): bool {
		// Honor GP Premium's own capability filter for the Elements screen.
		return current_user_can( apply_filters( 'generate_elements_admin_menu_capability', 'manage_options' ) );
	}

	public static function permission_edit_post( $input = null ): bool {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public static function permission_edit_posts( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function permission_gp_element_edit( $input = null ): bool {
		$id = isset( $input['element_id'] ) ? (int) $input['element_id'] : 0;
		return self::permission_gp_elements() && $id > 0 && current_user_can( 'edit_post', $id );
	}

	public static function permission_gp_element_delete( $input = null ): bool {
		$id = isset( $input['element_id'] ) ? (int) $input['element_id'] : 0;
		return self::permission_gp_elements() && $id > 0 && current_user_can( 'delete_post', $id );
	}

	/**
	 * gp_elements has no custom capability_type, so it maps to the normal
	 * 'post' meta caps: edit_post covers draft edits, but transitioning to
	 * "publish" additionally needs publish_post (WordPress core silently
	 * downgrades an unauthorized publish to "pending" rather than erroring
	 * — see the read-back check in cb_update_gp_element_status). Checking
	 * this here, ability-level, gives an immediate named-capability error
	 * instead of a silent status mismatch.
	 */
	public static function permission_gp_element_publish( $input = null ): bool {
		$id = isset( $input['element_id'] ) ? (int) $input['element_id'] : 0;
		if ( ! self::permission_gp_elements() || $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		if ( isset( $input['status'] ) && 'publish' === $input['status'] ) {
			return current_user_can( 'publish_post', $id );
		}
		return true;
	}

	public static function permission_gb_style_edit( $input = null ): bool {
		$id = isset( $input['style_id'] ) ? (int) $input['style_id'] : 0;
		return current_user_can( 'edit_theme_options' ) && $id > 0 && current_user_can( 'edit_post', $id );
	}

	public static function permission_gb_style_delete( $input = null ): bool {
		$id = isset( $input['style_id'] ) ? (int) $input['style_id'] : 0;
		return current_user_can( 'edit_theme_options' ) && $id > 0 && current_user_can( 'delete_post', $id );
	}


	// ---------------------------------------------------------------------
	// Shared helpers
	// ---------------------------------------------------------------------

	public static function is_sensitive_key( string $key ): bool {
		return (bool) preg_match( self::SENSITIVE_PATTERN, $key );
	}

	public static function validate_block_markup( string $content ) {
		if ( '' === trim( $content ) ) {
			return true;
		}

		if ( false !== strpos( $content, '<!-- wp:' ) && ! has_blocks( $content ) ) {
			return new \WP_Error(
				'invalid_markup',
				__( 'Content contains block comments ("<!-- wp: -->") but no valid blocks could be parsed from it. Check for unbalanced or malformed block markup.', 'generatepress-mcp-ability' )
			);
		}

		// A user with unfiltered_html can already save this exact content
		// as-is via wp-admin (WP core's own content_save_pre/wp_filter_post_kses
		// is skipped for that capability). Pre-filtering it here would make
		// the bridge MORE restrictive than the wp-admin UI for the same
		// account, so it is skipped identically.
		if ( current_user_can( 'unfiltered_html' ) ) {
			return true;
		}

		$allowed_html = array_merge( wp_kses_allowed_html( 'post' ), self::SVG_ALLOWED_TAGS );
		$kses_content = wp_kses( $content, $allowed_html );
		// wp_kses() always lowercases tag/attribute NAMES in its output,
		// including SVG's case-sensitive camelCase names (viewBox,
		// clipPath, preserveAspectRatio...). That's a harmless artifact of
		// this comparison, not a real content change — the caller saves
		// the original $content either way, never $kses_content, so
		// nothing actually gets lowercased in storage. Comparing
		// name-lowercased copies of both sides means a real structural
		// change (a stripped tag/attribute) still fails the check, while
		// pure SVG name-casing does not.
		if ( self::lowercase_tag_attr_names( $kses_content ) !== self::lowercase_tag_attr_names( $content ) ) {
			return new \WP_Error(
				'invalid_markup',
				sprintf(
					/* translators: 1: original length, 2: sanitized length, 3: diff summary */
					__( 'Content was altered by allowed-HTML sanitization, which means it contained disallowed tags or attributes. Original length %1$d, sanitized length %2$d. %3$s Remove the disallowed markup and try again, or use an account with the unfiltered_html capability.', 'generatepress-mcp-ability' ),
					strlen( $content ),
					strlen( $kses_content ),
					self::diff_summary( $content, $kses_content )
				)
			);
		}

		return true;
	}

	/**
	 * Best-effort human-readable summary of what sanitization changed,
	 * so dry_run/validation errors say WHAT was stripped/altered instead of
	 * just before/after lengths.
	 */
	public static function diff_summary( string $before, string $after ): string {
		if ( trim( $before ) === trim( $after ) ) {
			return '';
		}

		// Tags present before but entirely absent after => likely stripped.
		preg_match_all( '/<([a-z][a-z0-9-]*)\b/i', $before, $before_tags );
		preg_match_all( '/<([a-z][a-z0-9-]*)\b/i', $after, $after_tags );
		$before_counts = array_count_values( array_map( 'strtolower', $before_tags[1] ) );
		$after_counts  = array_count_values( array_map( 'strtolower', $after_tags[1] ) );
		$dropped_tags  = array();
		foreach ( $before_counts as $tag => $count ) {
			$remaining = $after_counts[ $tag ] ?? 0;
			if ( $remaining < $count ) {
				$dropped_tags[] = $count - $remaining === $count
					? $tag
					: sprintf( '%s (%d of %d)', $tag, $count - $remaining, $count );
			}
		}

		$notes = array();
		if ( ! empty( $dropped_tags ) ) {
			$notes[] = sprintf( 'Dropped/removed tag(s): %s.', implode( ', ', $dropped_tags ) );
		}

		// Character-level diff for the common case where only entities/quotes
		// changed (e.g. an apostrophe inside an attribute became &#8217; or
		// &#039;) and no tag was dropped at all.
		if ( empty( $dropped_tags ) ) {
			$max = 4000;
			for ( $i = 0, $len = min( strlen( $before ), strlen( $after ), $max ); $i < $len; $i++ ) {
				if ( $before[ $i ] !== $after[ $i ] ) {
					$notes[] = sprintf(
						'First difference near byte %1$d: "%2$s" became "%3$s".',
						$i,
						substr( $before, max( 0, $i - 15 ), 30 ),
						substr( $after, max( 0, $i - 15 ), 30 )
					);
					break;
				}
			}
		}

		return implode( ' ', $notes );
	}

	/**
	 * Lowercases HTML/SVG tag and attribute NAMES only (not attribute
	 * values or text content), so a wp_kses()-processed string can be
	 * compared against its pre-kses original without pure name-casing
	 * differences (e.g. SVG's viewBox/clipPath/preserveAspectRatio, which
	 * kses always lowercases in its output) registering as a change.
	 * Deliberately approximate rather than a full HTML parse — it is only
	 * ever used for this comparison, never for what gets saved.
	 */
	public static function lowercase_tag_attr_names( string $html ): string {
		$html = preg_replace_callback(
			'/<\/?[a-zA-Z][a-zA-Z0-9-]*/',
			static function ( $m ) {
				return strtolower( $m[0] );
			},
			$html
		);
		return preg_replace_callback(
			'/<[a-zA-Z][^>]*>/',
			static function ( $m ) {
				return preg_replace_callback(
					'/(\s)([a-zA-Z_:][-a-zA-Z0-9_:.]*)(\s*=)/',
					static function ( $am ) {
						return $am[1] . strtolower( $am[2] ) . $am[3];
					},
					$m[0]
				);
			},
			$html
		);
	}

	/**
	 * Fetch a gp_elements post or a WP_Error.
	 */

	// ---------------------------------------------------------------------
	// Ecosystem-wide execute callback (no single component owns this)
	// ---------------------------------------------------------------------

	public static function cb_get_status() {
		$theme = wp_get_theme( get_template() );

		$modules = null;
		if ( self::has_gp_premium() && function_exists( 'generatepress_is_module_active' ) ) {
			$modules = array();
			foreach ( self::GP_MODULES as $suffix => $constant ) {
				$modules[ $suffix ] = generatepress_is_module_active( 'generate_package_' . $suffix, $constant );
			}
		}

		$gp_elements_count = null;
		if ( post_type_exists( 'gp_elements' ) ) {
			$counts            = (array) wp_count_posts( 'gp_elements' );
			$gp_elements_count = array_sum( array_map( 'intval', $counts ) );
		}

		$global_styles_count = null;
		if ( post_type_exists( 'gblocks_global_style' ) ) {
			$counts              = (array) wp_count_posts( 'gblocks_global_style' );
			$global_styles_count = array_sum( array_map( 'intval', $counts ) );
		}

		return array(
			'theme'              => array(
				'template'         => get_template(),
				'is_generatepress' => self::has_theme(),
				'version'          => $theme->exists() ? $theme->get( 'Version' ) : null,
			),
			'gp_premium'         => array(
				'installed'      => self::has_gp_premium(),
				'version'        => self::has_gp_premium() ? GP_PREMIUM_VERSION : null,
				'active_modules' => $modules,
			),
			'generateblocks'     => array(
				'installed' => self::has_gb(),
				'version'   => self::has_gb() ? GENERATEBLOCKS_VERSION : null,
			),
			'generateblocks_pro' => array(
				'installed' => self::has_gb_pro(),
				'version'   => self::has_gb_pro() ? GENERATEBLOCKS_PRO_VERSION : null,
			),
			'counts'             => array(
				'gp_elements'      => $gp_elements_count,
				'gb_global_styles' => $global_styles_count,
			),
			'css_cache'          => array(
				'theme_dynamic_css_version' => (string) get_option( 'generate_dynamic_css_cached_version', '' ),
				'gb_dynamic_css_time'       => (int) get_option( 'generateblocks_dynamic_css_time', 0 ),
			),
		);
	}


	public static function strip_sensitive( array $settings ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SENSITIVE_PATTERN, $key ) ) {
				continue;
			}
			$out[ $key ] = is_array( $value ) ? self::strip_sensitive( $value ) : $value;
		}
		return $out;
	}
}
add_action( 'plugins_loaded', array( __NAMESPACE__ . '\\Plugin', 'init' ) );
