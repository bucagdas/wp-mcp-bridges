<?php
/**
 * Plugin Name: GeneratePress MCP Ability
 * Plugin URI: https://github.com/bucagdas/wp-mcp-bridges/tree/main/generatepress-mcp-ability
 * Description: GeneratePress ecosystem abilities for MCP. Theme settings, GP Premium module status, GP Elements (full CRUD), GenerateBlocks settings, global styles (full CRUD) and Pro pattern libraries. Components are detected at runtime; abilities of missing components are simply not registered.
 * Version: 1.4.1
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
require_once __DIR__ . '/includes/abilities-page-header.php';

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
	const SENSITIVE_PATTERN = '/(api[_-]?key|token|secret|password|licen[cs]e|licensing|credential|authoriz|connect)/i';

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
	 *
	 * page_header points at generate_page_header_SETTINGS, not
	 * generate_page_header_OPTIONS. The two are easy to confuse and this
	 * bridge had the wrong one until 2026-08-09: *_options is a legacy
	 * option read only by the one-shot migration
	 * generate_page_header_transfer_blog_header() (page-header/functions/
	 * functions.php, which even has a commented-out delete_option() for
	 * it), while *_settings is what the Customizer writes to
	 * ("generate_page_header_settings[page_header_position]"), what
	 * generate_get_page_header_location() reads at runtime, and what GP
	 * Premium's own export/reset whitelists list. Writing the legacy one
	 * was a silent no-op. See docs/KOPRU-EKSIKLERI.md.
	 */
	const MODULE_SETTINGS = array(
		'blog'          => 'generate_blog_settings',
		'menu_plus'     => 'generate_menu_plus_settings',
		'secondary_nav' => 'generate_secondary_nav_settings',
		'spacing'       => 'generate_spacing_settings',
		'page_header'   => 'generate_page_header_settings',
		'backgrounds'   => 'generate_background_settings',
		'hooks'         => 'generate_hooks',
		'woocommerce'   => 'generate_woocommerce_settings',
	);

	/**
	 * Every GP Premium module, as module key => activation constant. The
	 * key is also the generate_package_<key> option name GP Premium's own
	 * dashboard toggles (GeneratePress_Pro_Dashboard::get_modules()), and
	 * generatepress_is_module_active() treats "option === 'activated' OR
	 * constant defined" as active.
	 *
	 * font_library was missing here until 2026-08-09 (found by the
	 * "kapsam denetimi: gp-premium" audit while it was active on a live
	 * site): get-status silently omitted it from active_modules and
	 * toggle-gp-module schema-rejected it. Managing the fonts themselves
	 * is still out of scope (writes font files to the filesystem — A6
	 * code-writing gate), but the module's activation state is an
	 * ordinary option and belongs here.
	 *
	 * colors/typography/hooks/page_header/sections are deprecated in GP
	 * Premium 2.x — still listed because they remain toggleable and
	 * functional on sites that use them, but on a current GeneratePress
	 * theme colors/typography never load their PHP at all (superseded by
	 * the theme's own color/typography systems).
	 */
	const GP_MODULES = array(
		'backgrounds'      => 'GENERATE_BACKGROUNDS',
		'blog'             => 'GENERATE_BLOG',
		'copyright'        => 'GENERATE_COPYRIGHT',
		'disable_elements' => 'GENERATE_DISABLE_ELEMENTS',
		'elements'         => 'GENERATE_ELEMENTS',
		'font_library'     => 'GENERATE_FONT_LIBRARY',
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

	/**
	 * Valid _generate_block_type values for a "block" GP Element — the
	 * sub-type that decides which theme hook the element renders on
	 * (GeneratePress_Block_Element::__construct()'s switch, plus
	 * post-meta-template which branches on _generate_post_meta_location).
	 *
	 * "search-modal" is deliberately included even though GP Premium's
	 * own admin list-table filter dropdown omits it — it IS handled by
	 * the renderer and ships in the block editor bundle; the missing
	 * dropdown entry is a GP oversight, not a signal that it is invalid.
	 */
	const GP_BLOCK_TYPES = array(
		'hook',
		'site-header',
		'site-footer',
		'page-hero',
		'content-template',
		'loop-template',
		'post-meta-template',
		'post-navigation-template',
		'archive-navigation-template',
		'right-sidebar',
		'left-sidebar',
		'search-modal',
	);

	/**
	 * Layout-type GP Element settings: input key => [meta key, kind].
	 * Mirrors GP Premium's own save handler (elements/class-metabox.php's
	 * $layout_values map): "key" values go through sanitize_key(),
	 * "number" through absint(), and a falsy/omitted value DELETES the
	 * meta rather than storing an empty one (that is how GP represents
	 * "inherit the site default").
	 */
	const GP_ELEMENT_LAYOUT_FIELDS = array(
		'sidebar_layout'               => array( '_generate_sidebar_layout', 'key' ),
		'footer_widgets'               => array( '_generate_footer_widgets', 'key' ),
		'content_area'                 => array( '_generate_content_area', 'key' ),
		'content_width'                => array( '_generate_content_width', 'number' ),
		'disable_site_header'          => array( '_generate_disable_site_header', 'bool' ),
		'disable_mobile_header'        => array( '_generate_disable_mobile_header', 'bool' ),
		'disable_top_bar'              => array( '_generate_disable_top_bar', 'bool' ),
		'disable_primary_navigation'   => array( '_generate_disable_primary_navigation', 'bool' ),
		'disable_secondary_navigation' => array( '_generate_disable_secondary_navigation', 'bool' ),
		'disable_featured_image'       => array( '_generate_disable_featured_image', 'bool' ),
		'disable_content_title'        => array( '_generate_disable_content_title', 'bool' ),
		'disable_footer'               => array( '_generate_disable_footer', 'bool' ),
	);

	/**
	 * Footer-widget vocabulary for a LAYOUT ELEMENT — deliberately NOT
	 * the same as the theme's per-post _generate-footer-widget-meta
	 * (which this bridge exposes through update-post-layout using
	 * "0".."5"). A layout element spells "none" as the string
	 * "no-widgets" and has no "0". Confirmed from GP Premium's own
	 * radio inputs; conflating the two would silently store a value GP
	 * never reads.
	 */
	const GP_ELEMENT_FOOTER_WIDGETS = array( '', '1', '2', '3', '4', '5', 'no-widgets' );

	/** Layout element content-area vocabulary. */
	const GP_ELEMENT_CONTENT_AREAS = array( '', 'contained', 'full-width' );


	/**
	 * Legacy Page Header module post meta, as input key => [meta key, kind].
	 *
	 * These 46 keys are the exact $options list GP Premium's own metabox
	 * saves (page-header/functions/metabox.php:594-641) — generated from
	 * that source rather than transcribed, because an invented key name
	 * writes a meta GP never reads and measures as a silent no-op (see
	 * CLAUDE.md A4 measurement discipline).
	 *
	 * IMPORTANT — this is a DIFFERENT SYSTEM from the Elements "hero"
	 * (GP_ELEMENT_HEADER_FIELDS below). The legacy module stores these on
	 * any post/page and on its own generate_page_header CPT; the hero
	 * stores _generate_hero_* on a gp_elements post whose
	 * _generate_element_type is "header". They share no code path, and
	 * the legacy module has no "type" field at all.
	 *
	 * "kind" drives sanitization on write, mirroring GP's own filters:
	 * html = wp_kses_post (or raw for unfiltered_html), url = esc_url_raw,
	 * int = absint, text = sanitize_text_field.
	 */
	const PAGE_HEADER_FIELDS = array(
		'content'                           => array( '_meta-generate-page-header-content', 'html' ),
		'image'                             => array( '_meta-generate-page-header-image', 'url' ),
		'image_id'                          => array( '_meta-generate-page-header-image-id', 'int' ),
		'image_link'                        => array( '_meta-generate-page-header-image-link', 'url' ),
		'enable_image_crop'                 => array( '_meta-generate-page-header-enable-image-crop', 'text' ),
		'image_crop'                        => array( '_meta-generate-page-header-image-crop', 'text' ),
		'image_width'                       => array( '_meta-generate-page-header-image-width', 'int' ),
		'image_height'                      => array( '_meta-generate-page-header-image-height', 'int' ),
		'image_background_type'             => array( '_meta-generate-page-header-image-background-type', 'text' ),
		'inner_container'                   => array( '_meta-generate-page-header-inner-container', 'text' ),
		'image_background_alignment'        => array( '_meta-generate-page-header-image-background-alignment', 'text' ),
		'image_background_spacing'          => array( '_meta-generate-page-header-image-background-spacing', 'int' ),
		'image_background_spacing_unit'     => array( '_meta-generate-page-header-image-background-spacing-unit', 'text' ),
		'left_right_padding'                => array( '_meta-generate-page-header-left-right-padding', 'int' ),
		'left_right_padding_unit'           => array( '_meta-generate-page-header-left-right-padding-unit', 'text' ),
		'image_background_color'            => array( '_meta-generate-page-header-image-background-color', 'text' ),
		'image_background_text_color'       => array( '_meta-generate-page-header-image-background-text-color', 'text' ),
		'image_background_link_color'       => array( '_meta-generate-page-header-image-background-link-color', 'text' ),
		'image_background_link_color_hover' => array( '_meta-generate-page-header-image-background-link-color-hover', 'text' ),
		'navigation_background'             => array( '_meta-generate-page-header-navigation-background', 'text' ),
		'navigation_text'                   => array( '_meta-generate-page-header-navigation-text', 'text' ),
		'navigation_background_hover'       => array( '_meta-generate-page-header-navigation-background-hover', 'text' ),
		'navigation_text_hover'             => array( '_meta-generate-page-header-navigation-text-hover', 'text' ),
		'navigation_background_current'     => array( '_meta-generate-page-header-navigation-background-current', 'text' ),
		'navigation_text_current'           => array( '_meta-generate-page-header-navigation-text-current', 'text' ),
		'site_title'                        => array( '_meta-generate-page-header-site-title', 'text' ),
		'site_tagline'                      => array( '_meta-generate-page-header-site-tagline', 'text' ),
		'video'                             => array( '_meta-generate-page-header-video', 'url' ),
		'video_ogv'                         => array( '_meta-generate-page-header-video-ogv', 'url' ),
		'video_webm'                        => array( '_meta-generate-page-header-video-webm', 'url' ),
		'video_overlay'                     => array( '_meta-generate-page-header-video-overlay', 'text' ),
		'content_autop'                     => array( '_meta-generate-page-header-content-autop', 'text' ),
		'content_padding'                   => array( '_meta-generate-page-header-content-padding', 'text' ),
		'image_background'                  => array( '_meta-generate-page-header-image-background', 'text' ),
		'full_screen'                       => array( '_meta-generate-page-header-full-screen', 'text' ),
		'vertical_center'                   => array( '_meta-generate-page-header-vertical-center', 'text' ),
		'image_background_fixed'            => array( '_meta-generate-page-header-image-background-fixed', 'text' ),
		'image_background_overlay'          => array( '_meta-generate-page-header-image-background-overlay', 'text' ),
		'combine'                           => array( '_meta-generate-page-header-combine', 'text' ),
		'absolute_position'                 => array( '_meta-generate-page-header-absolute-position', 'text' ),
		'transparent_navigation'            => array( '_meta-generate-page-header-transparent-navigation', 'text' ),
		'add_to_excerpt'                    => array( '_meta-generate-page-header-add-to-excerpt', 'text' ),
		'logo'                              => array( '_meta-generate-page-header-logo', 'url' ),
		'logo_id'                           => array( '_meta-generate-page-header-logo-id', 'int' ),
		'navigation_logo'                   => array( '_meta-generate-page-header-navigation-logo', 'url' ),
		'navigation_logo_id'                => array( '_meta-generate-page-header-navigation-logo-id', 'int' ),
	);

	/**
	 * Elements "hero" (header type) post meta, as input key => [meta key,
	 * kind]. Generated from GP Premium's own $hero_values list
	 * (elements/class-metabox.php:1751-1802). Input keys drop the shared
	 * "_generate_" prefix, so _generate_hero_container => hero_container
	 * and _generate_navigation_colors => navigation_colors.
	 *
	 * "kind" mirrors GP's own sanitize branches: number = absint,
	 * key = sanitize_key, attribute = esc_attr, text/color =
	 * sanitize_text_field. Note GP does NOT use sanitize_hex_color for
	 * "color" — we match its behaviour rather than being stricter, so a
	 * value written here round-trips identically through GP's own metabox.
	 */
	const GP_ELEMENT_HEADER_FIELDS = array(
		'hero_custom_classes'                 => array( '_generate_hero_custom_classes', 'attribute' ),
		'hero_container'                      => array( '_generate_hero_container', 'text' ),
		'hero_inner_container'                => array( '_generate_hero_inner_container', 'text' ),
		'hero_horizontal_alignment'           => array( '_generate_hero_horizontal_alignment', 'text' ),
		'hero_full_screen'                    => array( '_generate_hero_full_screen', 'key' ),
		'hero_vertical_alignment'             => array( '_generate_hero_vertical_alignment', 'text' ),
		'hero_padding_top'                    => array( '_generate_hero_padding_top', 'number' ),
		'hero_padding_top_unit'               => array( '_generate_hero_padding_top_unit', 'text' ),
		'hero_padding_right'                  => array( '_generate_hero_padding_right', 'number' ),
		'hero_padding_right_unit'             => array( '_generate_hero_padding_right_unit', 'text' ),
		'hero_padding_bottom'                 => array( '_generate_hero_padding_bottom', 'number' ),
		'hero_padding_bottom_unit'            => array( '_generate_hero_padding_bottom_unit', 'text' ),
		'hero_padding_left'                   => array( '_generate_hero_padding_left', 'number' ),
		'hero_padding_left_unit'              => array( '_generate_hero_padding_left_unit', 'text' ),
		'hero_padding_top_mobile'             => array( '_generate_hero_padding_top_mobile', 'number' ),
		'hero_padding_top_unit_mobile'        => array( '_generate_hero_padding_top_unit_mobile', 'text' ),
		'hero_padding_right_mobile'           => array( '_generate_hero_padding_right_mobile', 'number' ),
		'hero_padding_right_unit_mobile'      => array( '_generate_hero_padding_right_unit_mobile', 'text' ),
		'hero_padding_bottom_mobile'          => array( '_generate_hero_padding_bottom_mobile', 'number' ),
		'hero_padding_bottom_unit_mobile'     => array( '_generate_hero_padding_bottom_unit_mobile', 'text' ),
		'hero_padding_left_mobile'            => array( '_generate_hero_padding_left_mobile', 'number' ),
		'hero_padding_left_unit_mobile'       => array( '_generate_hero_padding_left_unit_mobile', 'text' ),
		'hero_background_image'               => array( '_generate_hero_background_image', 'key' ),
		'hero_disable_featured_image'         => array( '_generate_hero_disable_featured_image', 'key' ),
		'hero_background_color'               => array( '_generate_hero_background_color', 'color' ),
		'hero_text_color'                     => array( '_generate_hero_text_color', 'color' ),
		'hero_link_color'                     => array( '_generate_hero_link_color', 'color' ),
		'hero_background_link_color_hover'    => array( '_generate_hero_background_link_color_hover', 'color' ),
		'hero_background_overlay'             => array( '_generate_hero_background_overlay', 'key' ),
		'hero_background_position'            => array( '_generate_hero_background_position', 'text' ),
		'hero_background_parallax'            => array( '_generate_hero_background_parallax', 'key' ),
		'site_header_merge'                   => array( '_generate_site_header_merge', 'key' ),
		'site_header_height'                  => array( '_generate_site_header_height', 'number' ),
		'site_header_height_mobile'           => array( '_generate_site_header_height_mobile', 'number' ),
		'navigation_colors'                   => array( '_generate_navigation_colors', 'key' ),
		'site_logo'                           => array( '_generate_site_logo', 'number' ),
		'retina_logo'                         => array( '_generate_retina_logo', 'number' ),
		'navigation_logo'                     => array( '_generate_navigation_logo', 'number' ),
		'mobile_logo'                         => array( '_generate_mobile_logo', 'number' ),
		'navigation_location'                 => array( '_generate_navigation_location', 'key' ),
		'site_header_background_color'        => array( '_generate_site_header_background_color', 'text' ),
		'site_header_title_color'             => array( '_generate_site_header_title_color', 'text' ),
		'site_header_tagline_color'           => array( '_generate_site_header_tagline_color', 'text' ),
		'navigation_background_color'         => array( '_generate_navigation_background_color', 'text' ),
		'navigation_text_color'               => array( '_generate_navigation_text_color', 'text' ),
		'navigation_background_color_hover'   => array( '_generate_navigation_background_color_hover', 'text' ),
		'navigation_text_color_hover'         => array( '_generate_navigation_text_color_hover', 'text' ),
		'navigation_background_color_current' => array( '_generate_navigation_background_color_current', 'text' ),
		'navigation_text_color_current'       => array( '_generate_navigation_text_color_current', 'text' ),
	);

	/**
	 * The only hero metas where a literal "0" is meaningful and must be
	 * preserved. GP's metabox deletes any falsy value, so it special-cases
	 * these four with a "zero" sentinel before the falsy test
	 * (elements/class-metabox.php:1819-1832). Everything else follows the
	 * plain empty-equals-delete rule.
	 */
	const GP_ELEMENT_HERO_ZERO_KEYS = array(
		'hero_padding_top_mobile',
		'hero_padding_right_mobile',
		'hero_padding_bottom_mobile',
		'hero_padding_left_mobile',
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
	 * Whether one GP Premium module is active, using GP's own helper so a
	 * site that activates a module by constant (the legacy standalone-plugin
	 * route) is recognised exactly as GP recognises it. Falls back to the
	 * raw option when the helper is unavailable.
	 *
	 * @param string $module Module key as in GP_MODULES.
	 */
	public static function is_module_active( string $module ): bool {
		if ( ! isset( self::GP_MODULES[ $module ] ) ) {
			return false;
		}
		if ( function_exists( 'generatepress_is_module_active' ) ) {
			return (bool) generatepress_is_module_active( 'generate_package_' . $module, self::GP_MODULES[ $module ] );
		}
		return 'activated' === get_option( 'generate_package_' . $module );
	}

	/**
	 * Reads a set of post metas described by a FIELDS map (input key =>
	 * [meta key, kind]). Absent metas come back as empty strings, which is
	 * exactly how GP's own readers treat them — none of these metas has a
	 * stored default.
	 */
	public static function read_meta_fields( int $post_id, array $fields ): array {
		$out = array();
		foreach ( $fields as $input_key => $spec ) {
			$out[ $input_key ] = (string) get_post_meta( $post_id, $spec[0], true );
		}
		return $out;
	}

	/**
	 * Writes a set of post metas, mirroring GP Premium's own save
	 * semantics, and reports honestly what happened.
	 *
	 * EMPTY EQUALS DELETE. Both GP modules save with a plain
	 * `if ( $value ) update_post_meta() else delete_post_meta()`
	 * (page-header/functions/metabox.php:664-668 and
	 * elements/class-metabox.php:1830-1837). So "" and "0" are BOTH falsy
	 * and BOTH remove the meta — there is no way, through GP's own UI, to
	 * end up with a stored "0". We deliberately mirror that instead of
	 * storing "0" faithfully: a value GP's own screens can never produce
	 * is a state its code is not written to read back.
	 *
	 * The consequence has to be visible to the caller rather than hidden,
	 * so each changed field reports {old, new} where `new` is null when the
	 * meta was removed — never the "0" that was asked for. A caller that
	 * sends "0" therefore sees `{"old": "120", "new": null}` and knows the
	 * field was cleared, not set to zero.
	 *
	 * $zero_keys names the fields where GP itself preserves a literal "0"
	 * via its "zero" sentinel; for those, "0" is stored and reported as
	 * "0".
	 *
	 * @return array Map of input key => {old, new} for CHANGED fields only.
	 */
	public static function write_meta_fields( int $post_id, array $fields, array $values, array $zero_keys = array() ) {
		$changed = array();
		foreach ( $values as $input_key => $value ) {
			if ( ! isset( $fields[ $input_key ] ) ) {
				return new \WP_Error(
					'unknown_field',
					sprintf(
						/* translators: 1: rejected field name, 2: comma-separated list of accepted names */
						__( 'Unknown field "%1$s". Accepted fields: %2$s.', 'generatepress-mcp-ability' ),
						$input_key,
						implode( ', ', array_keys( $fields ) )
					)
				);
			}
			list( $meta_key, $kind ) = $fields[ $input_key ];
			$old                     = (string) get_post_meta( $post_id, $meta_key, true );
			$clean                   = self::sanitize_meta_value( $value, $kind );

			$keeps_zero = in_array( $input_key, $zero_keys, true );
			if ( '' === $clean || ( '0' === $clean && ! $keeps_zero ) ) {
				delete_post_meta( $post_id, $meta_key );
				$new = null;
			} else {
				// update_post_meta() expects SLASHED data and unslashes on
				// the way in, so an unslashed write silently eats every
				// backslash. That matters here: the page header "content"
				// field is arbitrary HTML. See the slashing round in
				// docs/KOPRU-EKSIKLERI.md.
				update_post_meta( $post_id, $meta_key, wp_slash( $clean ) );
				$new = (string) get_post_meta( $post_id, $meta_key, true );
			}

			if ( ( null === $new ? '' : $new ) !== $old ) {
				$changed[ $input_key ] = array(
					'old' => '' === $old ? null : $old,
					'new' => $new,
				);
			}
		}
		return $changed;
	}

	/**
	 * Sanitizes one meta value the way GP's own metabox would for that
	 * field kind. Deliberately no stricter than GP: matching its behaviour
	 * keeps values round-tripping identically through its own screens.
	 *
	 * @param mixed  $value Raw input value.
	 * @param string $kind  One of html|url|int|text|number|key|attribute|color.
	 */
	private static function sanitize_meta_value( $value, string $kind ): string {
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( true === $value ) {
			$value = '1';
		}
		switch ( $kind ) {
			case 'html':
				return current_user_can( 'unfiltered_html' ) ? (string) $value : wp_kses_post( (string) $value );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'int':
			case 'number':
				return '' === trim( (string) $value ) ? '' : (string) absint( $value );
			case 'key':
				return sanitize_key( (string) $value );
			case 'attribute':
				return esc_attr( (string) $value );
			default:
				return sanitize_text_field( (string) $value );
		}
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
		PageHeader::register();
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

	/**
	 * Permission base for every GP Element verb.
	 *
	 * GP Premium gates its Elements ADMIN SCREEN on
	 * generate_elements_admin_menu_capability (default manage_options)
	 * and this bridge deliberately matches that gate — including the
	 * filter, so a site that widens it (e.g. to edit_posts, as GP's own
	 * generate_elements_metabox_ajax_allow_editors flow does for the
	 * Elements AJAX endpoints) automatically widens these verbs too.
	 *
	 * Worth knowing: the gp_elements post type itself registers NO custom
	 * capabilities, so at the pure data layer WordPress's default post
	 * capabilities apply — an Editor with edit_posts can already edit
	 * elements through wp-admin. Matching the admin-screen gate is
	 * therefore stricter than the raw data layer by default. That is
	 * intentional (elements can inject site-wide markup, and the Hook
	 * type can execute PHP), and the default is left as GP ships it —
	 * a site that wants Editors to reach these verbs should apply the
	 * same filter it would apply to reach GP's own Elements screen,
	 * rather than this bridge inventing its own looser rule. Confirmed
	 * against GP Premium 2.5.6 during the 2026-08-09 scope audit.
	 */
	public static function permission_gp_elements( $input = null ): bool {
		return current_user_can( apply_filters( 'generate_elements_admin_menu_capability', 'manage_options' ) );
	}

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
				__( 'Provide "id" (the post/page ID). "post_id" is also accepted as a deprecated alias for backward compatibility.', 'generatepress-mcp-ability' )
			);
		}
		return $id;
	}

	/**
	 * Resolves a positive-integer identifier from $input[$key], or a
	 * WP_Error if missing/invalid. Same rationale as resolve_post_id()
	 * above, generalized to non-post identifiers (element_id, style_id).
	 */
	public static function resolve_id( $input, string $key ) {
		$id = isset( $input[ $key ] ) ? (int) $input[ $key ] : 0;
		if ( $id <= 0 ) {
			return new \WP_Error(
				'missing_id',
				sprintf(
					/* translators: %s: expected parameter name */
					__( 'Provide "%s" (a positive integer ID).', 'generatepress-mcp-ability' ),
					$key
				)
			);
		}
		return $id;
	}

	/**
	 * Wipes GenerateBlocks' server-side dynamic CSS cache so every page's
	 * CSS is regenerated on its next front-end load.
	 *
	 * GenerateBlocks compiles per-page CSS and records which pages are
	 * "current" in the generateblocks_dynamic_css_posts option, keyed on
	 * the VIEWED page's id (never the id of an element/reusable block that
	 * merely supplies content to that page). Its own invalidation only
	 * covers a plain post/page edit:
	 *   - GB core's save_post hook unsets the saved post's OWN id and only
	 *     reads post_content — fine for a normal page edit (verified: a
	 *     wp-core-mcp update-post write is already invalidated correctly).
	 *   - GB Pro's save_post_gblocks_global_style hook full-wipes on global
	 *     style saves — so our global-style verbs are already covered too.
	 * But a GP Element (content in postmeta, displayed site-wide via a
	 * hook) and a GenerateBlocks global SETTING change render across many
	 * pages without living in their post_content, and GP Premium's own
	 * element-invalidation lives inside its classic-metabox save() handler
	 * behind a $_POST nonce a programmatic write never sends — so an API
	 * write leaves those pages' cached CSS stale (found on a live site
	 * 2026-08-09: a GP Element header icon rendered at the wrong size
	 * until the cache was cleared). A full wipe is the honest lever here —
	 * a site-wide element's host pages can't be cheaply enumerated, and it
	 * is exactly what GP Premium itself falls back to for a broad ("entire
	 * site") display condition and what GB Pro does for global styles.
	 * Regeneration is lazy (per page, on next visit), so the cost is
	 * spread out. Returns true if the cache actually held entries (so a
	 * caller can report whether a flush happened); a no-op when empty.
	 * See docs/KOPRU-EKSIKLERI.md.
	 */
	public static function flush_gb_css_cache(): bool {
		$current = get_option( 'generateblocks_dynamic_css_posts', array() );
		if ( empty( $current ) ) {
			return false;
		}
		update_option( 'generateblocks_dynamic_css_posts', array() );
		return true;
	}

	/**
	 * Invalidates GeneratePress' own compiled CSS after a settings write.
	 *
	 * GeneratePress compiles generate_settings/theme_mods into CSS and
	 * caches the result two different ways, depending on the site's
	 * css_print_method setting:
	 *   - "inline": options generate_dynamic_css_output +
	 *     generate_dynamic_css_cached_version (theme, inc/css-output.php).
	 *   - "file": a real stylesheet at uploads/generatepress/style.min.css,
	 *     rebuilt only when GeneratePress_External_CSS_File::needs_update()
	 *     says so — and that only checks the updated_time stamp in the
	 *     generatepress_dynamic_css_data option and the theme/plugin
	 *     versions. Nothing about it looks at whether the SETTINGS changed.
	 *
	 * Neither cache has any hook on generate_settings being written. The
	 * theme clears the inline cache on customize_save_after, and GP Premium
	 * clears the file's timestamp on customize_save_after too (plus a
	 * nonce-gated admin AJAX action) — so only a Customizer save refreshes
	 * them. A programmatic update_option( 'generate_settings', ... ), which
	 * is what every settings verb here does, leaves both untouched.
	 *
	 * Measured end to end on 2026-08-09 (file mode, real HTTP requests):
	 * with a clean baseline, writing background_color through
	 * update-theme-setting and then loading the front end left
	 * style.min.css byte-identical and missing the new colour entirely.
	 * Calling delete_saved_time() and loading the front end again rebuilt
	 * it correctly. See docs/KOPRU-EKSIKLERI.md madde 16.
	 *
	 * We call GeneratePress' OWN invalidation for each mode rather than
	 * writing the file ourselves; regeneration stays lazy (next front-end
	 * request), exactly as GeneratePress designed it — same shape as the
	 * GenerateBlocks flush above.
	 *
	 * @return string[] Which caches were actually invalidated.
	 */
	public static function flush_theme_css_cache(): array {
		$flushed = array();

		// Inline mode: the theme's own cached CSS + its version stamp.
		if ( false !== get_option( 'generate_dynamic_css_output', false )
			|| false !== get_option( 'generate_dynamic_css_cached_version', false ) ) {
			delete_option( 'generate_dynamic_css_output' );
			delete_option( 'generate_dynamic_css_cached_version' );
			$flushed[] = 'inline';
		}

		// File mode: GP Premium's external stylesheet. Clearing the saved
		// time is what makes needs_update() true; the file itself is
		// rewritten on the next front-end request.
		if ( class_exists( 'GeneratePress_External_CSS_File' ) ) {
			$css_file = \GeneratePress_External_CSS_File::get_instance();
			if ( method_exists( $css_file, 'delete_saved_time' ) ) {
				$css_file->delete_saved_time();
				$flushed[] = 'file';
			}
		}

		return $flushed;
	}

	public static function permission_edit_posts( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function permission_gp_element_edit( $input = null ) {
		if ( ! self::permission_gp_elements() ) {
			return false;
		}
		$id = self::resolve_id( $input, 'element_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'edit_post', $id );
	}

	public static function permission_gp_element_delete( $input = null ) {
		if ( ! self::permission_gp_elements() ) {
			return false;
		}
		$id = self::resolve_id( $input, 'element_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'delete_post', $id );
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
	public static function permission_gp_element_publish( $input = null ) {
		if ( ! self::permission_gp_elements() ) {
			return false;
		}
		$id = self::resolve_id( $input, 'element_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		if ( isset( $input['status'] ) && 'publish' === $input['status'] ) {
			return current_user_can( 'publish_post', $id );
		}
		return true;
	}

	/**
	 * create-gp-element accepts status: "publish" at creation time, before
	 * any post ID exists — so this checks the site-wide publish_posts
	 * capability rather than the per-post publish_post meta cap
	 * permission_gp_element_publish() uses. Found missing entirely during
	 * the v1.3.1 fix's re-verification (2026-08-08): wp_insert_post()
	 * does not enforce object capabilities on its own — the "silently
	 * downgraded to pending" behavior documented on the dedicated
	 * update-gp-element-status path is specific to wp-admin's classic
	 * post-editing form handler (_wp_translate_postdata()), not something
	 * wp_insert_post()/wp_update_post() do when called programmatically.
	 * Without this check, any caller who could create elements at all
	 * could create them pre-published regardless of publish_posts.
	 */
	public static function permission_gp_element_create( $input = null ): bool {
		if ( ! self::permission_gp_elements() ) {
			return false;
		}
		if ( isset( $input['status'] ) && 'publish' === $input['status'] ) {
			return current_user_can( 'publish_posts' );
		}
		return true;
	}

	public static function permission_gb_style_edit( $input = null ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		$id = self::resolve_id( $input, 'style_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Same gap as permission_gp_element_publish() (see that docblock),
	 * found and fixed alongside it (2026-08-08): the dedicated
	 * update-gb-global-style-status ability had no explicit publish
	 * capability check at all — permission_gb_style_edit() only checks
	 * edit_theme_options + edit_post, neither of which implies
	 * publish_posts, and wp_update_post() does not enforce it on its own.
	 */
	public static function permission_gb_style_publish( $input = null ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		$id = self::resolve_id( $input, 'style_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		if ( isset( $input['status'] ) && 'publish' === $input['status'] ) {
			return current_user_can( 'publish_post', $id );
		}
		return true;
	}

	/**
	 * create-gb-global-style accepts status: "publish" at creation time,
	 * before any post ID exists — same gap and same fix shape as
	 * permission_gp_element_create() (see that docblock).
	 */
	public static function permission_gb_style_create( $input = null ): bool {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		if ( isset( $input['status'] ) && 'publish' === $input['status'] ) {
			return current_user_can( 'publish_posts' );
		}
		return true;
	}

	public static function permission_gb_style_delete( $input = null ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}
		$id = self::resolve_id( $input, 'style_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'delete_post', $id );
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
	 * Extracts each opening/self-closing tag's name and attribute NAMES
	 * (not values) in document order. Regex-based, not a real parser —
	 * matches wp_kses()'s own approach and is only used for diffing, never
	 * for what gets saved.
	 */
	private static function extract_tag_attrs( string $html ): array {
		preg_match_all(
			'/<([a-zA-Z][a-zA-Z0-9-]*)((?:\s+[a-zA-Z][a-zA-Z0-9:_-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s\/>]+))?)*)\s*\/?>/',
			$html,
			$matches,
			PREG_SET_ORDER
		);
		$out = array();
		foreach ( $matches as $m ) {
			preg_match_all( '/([a-zA-Z][a-zA-Z0-9:_-]*)\s*(?:=|$)/', $m[2], $attr_matches );
			$out[] = array(
				'tag'   => strtolower( $m[1] ),
				'attrs' => array_map( 'strtolower', $attr_matches[1] ),
			);
		}
		return $out;
	}

	/**
	 * Best-effort human-readable summary of what sanitization changed, so
	 * dry_run/validation errors say WHAT was stripped/altered — which
	 * tag(s), which attribute(s) on which tag, which character — instead
	 * of just before/after lengths or a raw byte offset. A found-in-the-
	 * field gap: the original version only detected whole tags dropped and
	 * fell back to a raw byte diff otherwise, which mostly just reported
	 * wp_kses()'s own harmless tag/attribute-name lowercasing (e.g.
	 * viewBox -> viewbox) as the "difference", burying the actual dropped
	 * attribute (e.g. onclick) that mattered.
	 */
	public static function diff_summary( string $before, string $after ): string {
		if ( trim( $before ) === trim( $after ) ) {
			return '';
		}

		$before_tags = self::extract_tag_attrs( $before );
		$after_tags  = self::extract_tag_attrs( $after );

		// Group $after's tags by name so each $before tag occurrence can be
		// matched against the same-name, same-occurrence-index tag in
		// $after — keeps a dropped tag from misaligning every comparison
		// that follows it.
		$after_by_tag = array();
		foreach ( $after_tags as $t ) {
			$after_by_tag[ $t['tag'] ][] = $t['attrs'];
		}

		$notes               = array();
		$occurrence_index    = array();
		$dropped_tag_counts  = array();
		foreach ( $before_tags as $t ) {
			$tag = $t['tag'];
			$idx = $occurrence_index[ $tag ] ?? 0;
			$occurrence_index[ $tag ] = $idx + 1;

			if ( ! isset( $after_by_tag[ $tag ][ $idx ] ) ) {
				$dropped_tag_counts[ $tag ] = ( $dropped_tag_counts[ $tag ] ?? 0 ) + 1;
				continue;
			}

			$dropped_attrs = array_values( array_diff( $t['attrs'], $after_by_tag[ $tag ][ $idx ] ) );
			if ( ! empty( $dropped_attrs ) ) {
				$notes[] = sprintf(
					'On <%1$s>: dropped attribute(s) %2$s.',
					$tag,
					implode( ', ', $dropped_attrs )
				);
			}
		}
		foreach ( $dropped_tag_counts as $tag => $count ) {
			$notes[] = sprintf( 'Dropped %1$d <%2$s> tag(s) entirely.', $count, $tag );
		}

		// Fall back to a character diff only when nothing tag/attribute-
		// level explains the difference (e.g. an apostrophe inside an
		// attribute value became &#8217; or &#039;). Both sides are
		// name-case-normalized first so kses's harmless tag/attribute-name
		// lowercasing never masks the real difference underneath it.
		if ( empty( $notes ) ) {
			$norm_before = self::lowercase_tag_attr_names( $before );
			$norm_after  = self::lowercase_tag_attr_names( $after );
			$max         = 4000;
			for ( $i = 0, $len = min( strlen( $norm_before ), strlen( $norm_after ), $max ); $i < $len; $i++ ) {
				if ( $norm_before[ $i ] !== $norm_after[ $i ] ) {
					$notes[] = sprintf(
						'First character difference near byte %1$d: "%2$s" became "%3$s".',
						$i,
						substr( $norm_before, max( 0, $i - 15 ), 30 ),
						substr( $norm_after, max( 0, $i - 15 ), 30 )
					);
					break;
				}
			}
			if ( empty( $notes ) && strlen( $norm_before ) !== strlen( $norm_after ) ) {
				$notes[] = sprintf(
					'Length differs (%1$d vs %2$d bytes) with no byte-level difference in the shared prefix — likely trailing content removed.',
					strlen( $norm_before ),
					strlen( $norm_after )
				);
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
