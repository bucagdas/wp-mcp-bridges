<?php
/**
 * Product permalink base abilities.
 *
 * WooCommerce keeps its product/category/tag/attribute bases in the
 * woocommerce_permalinks option, and the only thing that writes it is
 * WC_Admin_Permalink_Settings, hooked onto WordPress's own Settings ->
 * Permalinks screen. It is exposed nowhere else: the wc/v3 settings API
 * has no permalink entries in any group (checked against 11.0.1 --
 * products, general and advanced return none), and wp-core-mcp's option
 * whitelist deliberately excludes it. So an agent had no way to reach
 * arguably the most consequential URL setting a store has.
 *
 * Writing the option on its own is not enough, and the failure is silent.
 * Measured on WooCommerce 11.0.1: setting product_base to "shop" by hand
 * left get_permalink() returning /product/<slug>/ and changed nothing;
 * only after the rewrite rules were flushed did it become /shop/<slug>/
 * (200, with the old URL 301'ing). Two further details come from
 * WooCommerce's own screen rather than being invented here: the shop base
 * is the shop page's URI, not the literal string "shop" (the page can be
 * renamed or nested), and a base that contains the shop permalink needs
 * use_verbose_page_rules turned on or nested pages break.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Permalinks {

	/**
	 * The three presets WooCommerce's own Permalinks screen offers,
	 * besides "custom". Built the way WC_Admin_Permalink_Settings builds
	 * them so the values match what the screen would have written.
	 */
	private static function structures(): array {
		$shop_page_id = wc_get_page_id( 'shop' );
		$base_slug    = urldecode(
			( $shop_page_id > 0 && get_post( $shop_page_id ) )
				? get_page_uri( $shop_page_id )
				: _x( 'shop', 'default-slug', 'woocommerce' )
		);

		// These are what actually ends up in the option, not what the radio
		// buttons post. The "Default" radio posts an empty string and
		// WC_Admin_Permalink_Settings::settings_save() then turns an empty
		// base into the product slug, so a default store stores "product",
		// not "". Comparing against "" would report every default store as
		// "custom".
		return array(
			'default'                 => wc_sanitize_permalink( _x( 'product', 'slug', 'woocommerce' ) ),
			'shop_base'               => wc_sanitize_permalink( '/' . trailingslashit( $base_slug ) ),
			'shop_base_with_category' => wc_sanitize_permalink( '/' . trailingslashit( $base_slug ) . trailingslashit( '%product_cat%' ) ),
		);
	}

	public static function register(): void {
		wp_register_ability(
			'wc-mcp/get-permalink-settings',
			array(
				'label'               => __( 'Get product permalink settings', 'wc-mcp-ability' ),
				'description'         => __( 'Returns WooCommerce\'s product URL bases (product, category, tag, attribute, and brand where the product_brand taxonomy exists), which of the Permalinks screen\'s presets the current product base corresponds to ("default", "shop_base", "shop_base_with_category" or "custom"), the shop page\'s slug, and an example product URL. These live in the woocommerce_permalinks option and are not part of the wc/v3 settings API.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "product_base", "category_base", "tag_base", "attribute_base", "use_verbose_page_rules", "structure", "shop_page_slug" and "example_product_url".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/update-permalink-settings',
			array(
				'label'               => __( 'Update product permalink settings', 'wc-mcp-ability' ),
				'description'         => __( 'Sets the product URL base, the same setting as Settings > Permalinks > Product permalinks. Pass structure: "default" (/product/sample/), "shop_base" (/shop/sample/), "shop_base_with_category" (/shop/category/sample/), or "custom" together with product_base. The shop base is taken from the shop page\'s own slug, so a renamed or nested shop page is handled correctly, and use_verbose_page_rules is set when the base contains it, exactly as WooCommerce\'s own screen does. Category, tag, attribute and brand bases can be set in the same call. The brand base is stored separately by WooCommerce, in woocommerce_brand_permalink rather than woocommerce_permalinks, and is only accepted when the product_brand taxonomy exists. The stored rewrite rules are dropped afterwards so WordPress rebuilds them on the next request -- without that the option changes but every new product URL returns 404. Note that use_verbose_page_rules is only ever turned on, never off, matching WooCommerce\'s own screen: switching back to a base that does not contain the shop page leaves it enabled. Requires confirm: true, since every product URL on the site changes and old ones only survive if something redirects them. Returns {old,new} read back after the write.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'structure'      => array(
							'type'        => 'string',
							'enum'        => array( 'default', 'shop_base', 'shop_base_with_category', 'custom' ),
							'description' => 'Which preset to apply. "custom" requires product_base.',
						),
						'product_base'   => array(
							'type'        => 'string',
							'description' => 'Custom product base, only used when structure is "custom" (e.g. "store" or "shop/%product_cat%").',
						),
						'category_base'  => array(
							'type'        => 'string',
							'description' => 'Optional product category base. Empty string restores the default.',
						),
						'tag_base'       => array(
							'type'        => 'string',
							'description' => 'Optional product tag base. Empty string restores the default.',
						),
						'attribute_base' => array(
							'type'        => 'string',
							'description' => 'Optional attribute base. Empty string restores the default.',
						),
						'brand_base'     => array(
							'type'        => 'string',
							'description' => 'Optional product brand base. Empty string restores the default ("brand"). Only accepted when the product_brand taxonomy exists.',
						),
						'confirm'        => array(
							'type'        => 'boolean',
							'description' => 'Must be true. Every product URL on the site changes.',
						),
					),
					'required'             => array( 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "old" and "new" (both the full permalink settings) and "example_product_url".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_get() {
		return self::describe( self::report() );
	}

	public static function cb_update( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true — this rewrites every product URL on the site.', 'wc-mcp-ability' ) );
		}

		$old        = self::report();
		$permalinks = self::permalinks();
		$structures = self::structures();

		if ( isset( $input['structure'] ) ) {
			$structure = (string) $input['structure'];

			if ( 'custom' === $structure ) {
				if ( ! isset( $input['product_base'] ) || '' === trim( (string) $input['product_base'] ) ) {
					return new \WP_Error( 'product_base_required', __( 'structure "custom" needs a product_base.', 'wc-mcp-ability' ) );
				}
				// Same normalisation WC_Admin_Permalink_Settings applies to
				// the custom field: collapse slashes, drop "#", force a
				// leading slash.
				$base = preg_replace( '#/+#', '/', '/' . str_replace( '#', '', trim( (string) $input['product_base'] ) ) );

				// A base of just "/%product_cat%/" or "/%product_brand%/" would
				// collide with page URLs, so WooCommerce prefixes it with the
				// product slug (settings_save() for the first,
				// WC_Admin_Brands::validate_product_base() for the second).
				if ( in_array( trailingslashit( $base ), array( '/%product_cat%/', '/%product_brand%/' ), true ) ) {
					$base = '/' . _x( 'product', 'slug', 'woocommerce' ) . $base;
				}
				$permalinks['product_base'] = wc_sanitize_permalink( $base );
			} elseif ( isset( $structures[ $structure ] ) ) {
				// Already in stored form.
				$permalinks['product_base'] = $structures[ $structure ];
			} else {
				return new \WP_Error( 'invalid_structure', __( 'Unknown structure.', 'wc-mcp-ability' ) );
			}
		}

		foreach ( array( 'category_base', 'tag_base', 'attribute_base' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$permalinks[ $key ] = wc_sanitize_permalink( wc_clean( (string) $input[ $key ] ) );
			}
		}

		// The brand base is the odd one out: it lives in its own option
		// (woocommerce_brand_permalink), not in woocommerce_permalinks, and
		// WC_Brands::init_taxonomy() reads it when registering product_brand.
		// Written the same way WC_Admin_Brands::save_permalink_settings() does.
		if ( array_key_exists( 'brand_base', $input ) ) {
			if ( ! self::brands_available() ) {
				return new \WP_Error( 'brands_unavailable', __( 'This store has no product_brand taxonomy, so there is no brand base to set.', 'wc-mcp-ability' ) );
			}
			update_option( 'woocommerce_brand_permalink', wc_sanitize_permalink( trim( wc_clean( (string) $input['brand_base'] ) ) ) );
		}

		// Shop base may need verbose page rules if the shop page is nested.
		// Straight out of WC_Admin_Permalink_Settings::settings_save().
		$shop_page_id   = wc_get_page_id( 'shop' );
		$shop_permalink = ( $shop_page_id > 0 && get_post( $shop_page_id ) )
			? get_page_uri( $shop_page_id )
			: _x( 'shop', 'default-slug', 'woocommerce' );

		if ( $shop_page_id && stristr( trim( (string) $permalinks['product_base'], '/' ), $shop_permalink ) ) {
			$permalinks['use_verbose_page_rules'] = true;
		}

		update_option( 'woocommerce_permalinks', $permalinks );

		self::invalidate_rewrite_rules();

		$new = self::report();

		return array(
			'old'                 => $old,
			'new'                 => $new,
			'example_product_url' => self::example_url(),
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Drops the stored rewrite rules so WordPress rebuilds them on the next
	 * request.
	 *
	 * Flushing here instead would not work, and the reason is worth writing
	 * down. The product post type is registered on "init" with the base as
	 * it was when this request started, so right after the write the option
	 * says "/shop" while the post type's rewrite slug still says "product".
	 * flush_rewrite_rules() regenerates from the registration, not from the
	 * option, so it would store rules for the old base -- measured on
	 * 11.0.1: /shop/<product>/ returned 404 after a flush, while
	 * /product/<product>/ kept answering 200.
	 *
	 * Re-registering first does not help either: WC_Post_Types::register_post_types()
	 * returns immediately when post_type_exists('product'), so calling it
	 * again is a no-op (class-wc-post-types.php:334).
	 *
	 * Deleting the option is what WordPress itself treats as "these are
	 * stale": wp_rewrite_rules() regenerates and stores them when it finds
	 * the option empty. The next request registers the post type from the
	 * new base and rebuilds correct rules. The cost is that the very next
	 * front-end request pays for one regeneration.
	 */
	private static function invalidate_rewrite_rules(): void {
		delete_option( 'rewrite_rules' );
	}

	private static function permalinks(): array {
		$stored = wc_get_permalink_structure();

		$permalinks = array(
			'product_base'           => (string) ( $stored['product_base'] ?? '' ),
			'category_base'          => (string) ( $stored['category_base'] ?? '' ),
			'tag_base'               => (string) ( $stored['tag_base'] ?? '' ),
			'attribute_base'         => (string) ( $stored['attribute_base'] ?? '' ),
			'use_verbose_page_rules' => (bool) ( $stored['use_verbose_page_rules'] ?? false ),
		);

		return $permalinks;
	}

	/**
	 * The reported shape, which is deliberately not the stored shape: the
	 * brand base lives in its own option and must never be written into
	 * woocommerce_permalinks. Keeping the two apart is the whole reason
	 * this function exists -- merging them once put brand_base and
	 * brand_effective_base into WooCommerce's option, where nothing owns
	 * them and wc_get_permalink_structure() would keep rewriting the row.
	 */
	private static function report(): array {
		$permalinks = self::permalinks();

		if ( self::brands_available() ) {
			$brand_base = (string) get_option( 'woocommerce_brand_permalink', '' );

			$permalinks['brand_base'] = $brand_base;
			// An empty option means WooCommerce falls back to the translated
			// "brand" slug, so report what the URLs actually use as well.
			$permalinks['brand_effective_base'] = '' === $brand_base ? __( 'brand', 'woocommerce' ) : $brand_base;
		}

		return $permalinks;
	}

	/**
	 * Brands shipped with WooCommerce 9.4 and the taxonomy can still be
	 * filtered away, so the brand base is only reported and accepted when
	 * the taxonomy is actually registered.
	 */
	private static function brands_available(): bool {
		return taxonomy_exists( 'product_brand' );
	}

	private static function describe( array $permalinks ): array {
		$shop_page_id = wc_get_page_id( 'shop' );

		$structure  = 'custom';
		$normalised = trim( (string) $permalinks['product_base'], '/' );
		foreach ( self::structures() as $name => $value ) {
			if ( trim( (string) $value, '/' ) === $normalised ) {
				$structure = $name;
				break;
			}
		}

		return $permalinks + array(
			'structure'           => $structure,
			'shop_page_slug'      => ( $shop_page_id > 0 && get_post( $shop_page_id ) ) ? get_page_uri( $shop_page_id ) : null,
			'example_product_url' => self::example_url(),
		);
	}

	/**
	 * A real product URL when the store has one, so the caller can see the
	 * shape rather than guess it.
	 */
	private static function example_url(): ?string {
		$products = wc_get_products(
			array(
				'limit'  => 1,
				'status' => 'publish',
				'return' => 'ids',
			)
		);

		return empty( $products ) ? null : get_permalink( $products[0] );
	}
}
