<?php
/**
 * Per-post SEO metadata abilities (core, robots, social, schema, bulk).
 *
 * @package RankMathMCPAbility
 */

namespace RankMathMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Meta {

	/**
	 * Simple text fields writable via update-post-seo: input => meta key.
	 */
	const CORE_FIELDS = array(
		'seo_title'        => 'rank_math_title',
		'seo_description'  => 'rank_math_description',
		'focus_keyword'    => 'rank_math_focus_keyword',
		'canonical_url'    => 'rank_math_canonical_url',
		'breadcrumb_title' => 'rank_math_breadcrumb_title',
	);

	/**
	 * Social fields writable via update-post-social: input => meta key.
	 */
	const SOCIAL_FIELDS = array(
		'facebook_title'       => 'rank_math_facebook_title',
		'facebook_description' => 'rank_math_facebook_description',
		'facebook_image'       => 'rank_math_facebook_image',
		'twitter_title'        => 'rank_math_twitter_title',
		'twitter_description'  => 'rank_math_twitter_description',
		'twitter_card_type'    => 'rank_math_twitter_card_type',
	);

	const ROBOTS_VALUES = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );

	public static function register(): void {

		wp_register_ability(
			'rank-math-mcp/get-post-seo',
			array(
				'label'               => __( 'Get post SEO metadata', 'rank-math-mcp-ability' ),
				'description'         => __( 'Returns the full Rank Math SEO metadata of a post: SEO title, meta description, focus keyword, robots and advanced robots, canonical URL, breadcrumb title, pillar flag, SEO score, Open Graph and Twitter overrides, and the schema types attached. Unset fields are null. Note: overlaps with the native rank-math/get-post-seo-meta ability; this bridge verb works regardless of Rank Math account connection.', 'rank-math-mcp-ability' ),
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
					'description' => 'Full Rank Math SEO metadata of the post.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_post_seo' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_post' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/list-posts-seo-status',
			array(
				'label'               => __( 'List posts by SEO status', 'rank-math-mcp-ability' ),
				'description'         => __( 'Lists published posts of a post type with their Rank Math SEO completeness: whether a focus keyword and meta description are set and the SEO score. Use the "missing" filter to find posts lacking a focus keyword or meta description. (Differs from native rank-math/get-seo-scores: this reports field completeness, not scores.)', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'default'     => 'post',
							'description' => 'Public post type to list. Default "post".',
						),
						'missing'   => array(
							'type'        => 'string',
							'enum'        => array( 'focus-keyword', 'description', 'none' ),
							'default'     => 'none',
							'description' => 'Only return posts missing this SEO field.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum number of posts. Default 20.',
						),
						'page'      => array(
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
					'description' => 'Object with "total", "page" and "posts".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_posts_seo_status' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_posts' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/update-post-seo',
			array(
				'label'               => __( 'Update post SEO metadata', 'rank-math-mcp-ability' ),
				'description'         => __( 'Updates Rank Math SEO metadata of a post. Provide only the fields to change: seo_title, seo_description, focus_keyword, canonical_url, breadcrumb_title (empty string clears each), robots (array of index/noindex/nofollow/noarchive/noimageindex/nosnippet; empty array clears), advanced_robots (object, e.g. {"max-snippet":-1}; empty object clears) and/or pillar_content (boolean). Returns {old,new} per changed field, read back after the write.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'          => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID of the post or page to update.',
						),
						'seo_title'        => array( 'type' => 'string', 'description' => 'SEO title. Empty string clears.' ),
						'seo_description'  => array( 'type' => 'string', 'description' => 'Meta description. Empty string clears.' ),
						'focus_keyword'    => array( 'type' => 'string', 'description' => 'Comma-separated focus keyword(s). Empty string clears.' ),
						'canonical_url'    => array( 'type' => 'string', 'description' => 'Canonical URL. Empty string clears.' ),
						'breadcrumb_title' => array( 'type' => 'string', 'description' => 'Breadcrumb title. Empty string clears.' ),
						'robots'           => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => self::ROBOTS_VALUES,
							),
							'description' => 'Robots directives. Empty array clears.',
						),
						'advanced_robots'  => array(
							'type'        => 'object',
							'description' => 'Advanced robots map (max-snippet, max-video-preview, max-image-preview). Empty object clears.',
						),
						'pillar_content'   => array( 'type' => 'boolean', 'description' => 'Mark as pillar/cornerstone content.' ),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "post_id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_post_seo' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_post' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/update-post-social',
			array(
				'label'               => __( 'Update post social (OG/Twitter) metadata', 'rank-math-mcp-ability' ),
				'description'         => __( 'Updates the Open Graph and Twitter overrides of a post: facebook_title, facebook_description, facebook_image (URL), twitter_title, twitter_description, twitter_card_type (summary_large_image or summary_card) and twitter_use_facebook (boolean; when true Twitter reuses the Facebook fields). Empty string clears a field. Returns {old,new} per changed field.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'              => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID of the post or page to update.',
						),
						'facebook_title'       => array( 'type' => 'string' ),
						'facebook_description' => array( 'type' => 'string' ),
						'facebook_image'       => array( 'type' => 'string', 'description' => 'Image URL. Empty string clears.' ),
						'twitter_title'        => array( 'type' => 'string' ),
						'twitter_description'  => array( 'type' => 'string' ),
						'twitter_card_type'    => array(
							'type' => 'string',
							'enum' => array( '', 'summary_large_image', 'summary_card' ),
						),
						'twitter_use_facebook' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "post_id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_post_social' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_post' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/update-post-schema',
			array(
				'label'               => __( 'Set post schema', 'rank-math-mcp-ability' ),
				'description'         => __( 'Sets (or replaces) one schema block of the given type on a post, stored as the rank_math_schema_<type> post meta. The schema object is stored as provided; it should follow the structure Rank Math uses (with @type etc.). Use native rank-math/get-post-schema to inspect existing schema and supported types first. Returns {old,new} of the stored value.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID of the post.',
						),
						'type'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Schema type, e.g. "Article" or "FAQPage" (used in the meta key).',
						),
						'schema'  => array(
							'type'        => 'object',
							'description' => 'Schema data object to store.',
						),
					),
					'required'             => array( 'post_id', 'type', 'schema' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "post_id", "type", "old" and "new".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_post_schema' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_post' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/delete-post-schema',
			array(
				'label'               => __( 'Delete post schema', 'rank-math-mcp-ability' ),
				'description'         => __( 'Deletes the schema block of the given type from a post (removes the rank_math_schema_<type> meta). Requires confirm: true. Destructive: the schema data is removed permanently.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID of the post.',
						),
						'type'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Schema type to delete.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'post_id', 'type', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "post_id", "type", "old" (removed value) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete_post_schema' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_post' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/list-schema-posts',
			array(
				'label'               => __( 'List posts with schema', 'rank-math-mcp-ability' ),
				'description'         => __( 'Site-wide inventory of posts that have Rank Math schema attached (rank_math_schema_* post meta), with the schema types found on each post. Optionally filter by schema type. Use this to find which content already has structured data before recommending more.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'type'      => array(
							'type'        => 'string',
							'description' => 'Optional schema type filter, e.g. "Article" or "FAQPage".',
						),
						'post_type' => array(
							'type'        => 'string',
							'default'     => 'post',
							'description' => 'Public post type to scan. Default "post".',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum posts. Default 20.',
						),
						'page'      => array(
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
					'description' => 'Object with "total" and "posts" (array of {id, title, schema_types}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_schema_posts' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_posts' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/bulk-update-seo-meta',
			array(
				'label'               => __( 'Bulk update SEO metadata', 'rank-math-mcp-ability' ),
				'description'         => __( 'Sets one SEO meta field (robots, seo_title or seo_description) to the same value on many published posts at once, optionally only on posts where the field is missing. ALWAYS run with dry_run: true first — it reports how many posts match without changing anything. The real run requires confirm: true. Returns affected post ids and {old,new} per post.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type'    => array(
							'type'        => 'string',
							'default'     => 'post',
							'description' => 'Public post type to target. Default "post".',
						),
						'field'        => array(
							'type'        => 'string',
							'enum'        => array( 'robots', 'seo_title', 'seo_description' ),
							'description' => 'Field to set on every matched post.',
						),
						'value'        => array(
							'description' => 'New value: array of directives for robots, string for the others.',
						),
						'only_missing' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Only touch posts where the field is currently empty. Default true.',
						),
						'limit'        => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 200,
							'default'     => 50,
							'description' => 'Maximum posts to affect in one call. Default 50.',
						),
						'dry_run'      => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = report matches only, change nothing.',
						),
						'confirm'      => array(
							'type'        => 'boolean',
							'description' => 'Must be true for a real (non-dry_run) run.',
						),
					),
					'required'             => array( 'field', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "dry_run", "matched", "changed" and "posts" (per-post {id, old, new}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_bulk_update_seo_meta' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_others_posts' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	private static function meta_or_null( int $post_id, string $key ) {
		$value = get_post_meta( $post_id, $key, true );
		return ( '' === $value || array() === $value ) ? null : $value;
	}

	public static function cb_get_post_seo( $input ) {
		$post_id = (int) $input['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'rank-math-mcp-ability' ) );
		}

		$robots = self::meta_or_null( $post_id, 'rank_math_robots' );
		$score  = self::meta_or_null( $post_id, 'rank_math_seo_score' );

		$schema_types = array();
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( str_starts_with( $key, 'rank_math_schema_' ) ) {
				$schema_types[] = substr( $key, strlen( 'rank_math_schema_' ) );
			}
		}

		return array(
			'post_id'          => $post_id,
			'post_title'       => $post->post_title,
			'post_type'        => $post->post_type,
			'post_status'      => $post->post_status,
			'permalink'        => get_permalink( $post_id ),
			'seo_title'        => self::meta_or_null( $post_id, 'rank_math_title' ),
			'seo_description'  => self::meta_or_null( $post_id, 'rank_math_description' ),
			'focus_keyword'    => self::meta_or_null( $post_id, 'rank_math_focus_keyword' ),
			'robots'           => is_array( $robots ) ? array_values( $robots ) : $robots,
			'advanced_robots'  => self::meta_or_null( $post_id, 'rank_math_advanced_robots' ),
			'canonical_url'    => self::meta_or_null( $post_id, 'rank_math_canonical_url' ),
			'breadcrumb_title' => self::meta_or_null( $post_id, 'rank_math_breadcrumb_title' ),
			'seo_score'        => null === $score ? null : (int) $score,
			'is_pillar'        => 'on' === get_post_meta( $post_id, 'rank_math_pillar_content', true ),
			'open_graph'       => array(
				'title'       => self::meta_or_null( $post_id, 'rank_math_facebook_title' ),
				'description' => self::meta_or_null( $post_id, 'rank_math_facebook_description' ),
				'image'       => self::meta_or_null( $post_id, 'rank_math_facebook_image' ),
			),
			'twitter'          => array(
				'use_facebook' => 'off' !== get_post_meta( $post_id, 'rank_math_twitter_use_facebook', true ),
				'card_type'    => self::meta_or_null( $post_id, 'rank_math_twitter_card_type' ),
				'title'        => self::meta_or_null( $post_id, 'rank_math_twitter_title' ),
				'description'  => self::meta_or_null( $post_id, 'rank_math_twitter_description' ),
			),
			'schema_types'     => $schema_types,
		);
	}

	public static function cb_list_posts_seo_status( $input ) {
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		$missing   = isset( $input['missing'] ) ? (string) $input['missing'] : 'none';
		$per_page  = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$page      = isset( $input['page'] ) ? (int) $input['page'] : 1;

		if ( ! in_array( $post_type, get_post_types( array( 'public' => true ) ), true ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'Unknown or non-public post type.', 'rank-math-mcp-ability' ) );
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => min( 100, max( 1, $per_page ) ),
			'paged'          => max( 1, $page ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( 'none' !== $missing ) {
			$key = 'focus-keyword' === $missing ? 'rank_math_focus_keyword' : 'rank_math_description';
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => $key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => $key,
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		$query = new \WP_Query( $args );

		$posts = array();
		foreach ( $query->posts as $post ) {
			$score   = get_post_meta( $post->ID, 'rank_math_seo_score', true );
			$posts[] = array(
				'post_id'           => (int) $post->ID,
				'title'             => $post->post_title,
				'permalink'         => get_permalink( $post ),
				'focus_keyword'     => (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
				'has_focus_keyword' => '' !== get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
				'has_description'   => '' !== get_post_meta( $post->ID, 'rank_math_description', true ),
				'seo_score'         => '' === $score ? null : (int) $score,
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'page'  => $page,
			'posts' => $posts,
		);
	}

	public static function cb_update_post_seo( $input ) {
		$post_id = (int) $input['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'rank-math-mcp-ability' ) );
		}

		$updated = array();

		foreach ( array_intersect_key( $input, self::CORE_FIELDS ) as $field => $value ) {
			$meta_key = self::CORE_FIELDS[ $field ];
			$old      = self::meta_or_null( $post_id, $meta_key );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				$clean = 'canonical_url' === $field ? esc_url_raw( (string) $value ) : sanitize_text_field( (string) $value );
				update_post_meta( $post_id, $meta_key, $clean );
			}
			$updated[ $field ] = array(
				'old' => $old,
				'new' => self::meta_or_null( $post_id, $meta_key ),
			);
		}

		if ( array_key_exists( 'robots', $input ) ) {
			$old    = self::meta_or_null( $post_id, 'rank_math_robots' );
			$robots = array_values( array_intersect( (array) $input['robots'], self::ROBOTS_VALUES ) );
			if ( empty( $robots ) ) {
				delete_post_meta( $post_id, 'rank_math_robots' );
			} else {
				update_post_meta( $post_id, 'rank_math_robots', $robots );
			}
			$updated['robots'] = array(
				'old' => $old,
				'new' => self::meta_or_null( $post_id, 'rank_math_robots' ),
			);
		}

		if ( array_key_exists( 'advanced_robots', $input ) ) {
			$old      = self::meta_or_null( $post_id, 'rank_math_advanced_robots' );
			$advanced = array_map( 'sanitize_text_field', array_map( 'strval', (array) $input['advanced_robots'] ) );
			if ( empty( $advanced ) ) {
				delete_post_meta( $post_id, 'rank_math_advanced_robots' );
			} else {
				update_post_meta( $post_id, 'rank_math_advanced_robots', $advanced );
			}
			$updated['advanced_robots'] = array(
				'old' => $old,
				'new' => self::meta_or_null( $post_id, 'rank_math_advanced_robots' ),
			);
		}

		if ( array_key_exists( 'pillar_content', $input ) ) {
			$old = 'on' === get_post_meta( $post_id, 'rank_math_pillar_content', true );
			if ( ! empty( $input['pillar_content'] ) ) {
				update_post_meta( $post_id, 'rank_math_pillar_content', 'on' );
			} else {
				delete_post_meta( $post_id, 'rank_math_pillar_content' );
			}
			$updated['pillar_content'] = array(
				'old' => $old,
				'new' => 'on' === get_post_meta( $post_id, 'rank_math_pillar_content', true ),
			);
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'rank-math-mcp-ability' ) );
		}

		return array(
			'post_id' => $post_id,
			'updated' => $updated,
		);
	}

	public static function cb_update_post_social( $input ) {
		$post_id = (int) $input['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'rank-math-mcp-ability' ) );
		}

		$updated = array();

		foreach ( array_intersect_key( $input, self::SOCIAL_FIELDS ) as $field => $value ) {
			$meta_key = self::SOCIAL_FIELDS[ $field ];
			$old      = self::meta_or_null( $post_id, $meta_key );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				$clean = 'facebook_image' === $field ? esc_url_raw( (string) $value ) : sanitize_text_field( (string) $value );
				update_post_meta( $post_id, $meta_key, $clean );
			}
			$updated[ $field ] = array(
				'old' => $old,
				'new' => self::meta_or_null( $post_id, $meta_key ),
			);
		}

		if ( array_key_exists( 'twitter_use_facebook', $input ) ) {
			$old = 'off' !== get_post_meta( $post_id, 'rank_math_twitter_use_facebook', true );
			update_post_meta( $post_id, 'rank_math_twitter_use_facebook', empty( $input['twitter_use_facebook'] ) ? 'off' : 'on' );
			$updated['twitter_use_facebook'] = array(
				'old' => $old,
				'new' => 'off' !== get_post_meta( $post_id, 'rank_math_twitter_use_facebook', true ),
			);
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'rank-math-mcp-ability' ) );
		}

		return array(
			'post_id' => $post_id,
			'updated' => $updated,
		);
	}

	public static function cb_update_post_schema( $input ) {
		$post_id = (int) $input['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'rank-math-mcp-ability' ) );
		}

		$type = preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['type'] );
		if ( '' === $type ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid schema type.', 'rank-math-mcp-ability' ) );
		}
		$meta_key = 'rank_math_schema_' . $type;

		$old = self::meta_or_null( $post_id, $meta_key );
		update_post_meta( $post_id, $meta_key, (array) $input['schema'] );

		return array(
			'post_id' => $post_id,
			'type'    => $type,
			'old'     => $old,
			'new'     => self::meta_or_null( $post_id, $meta_key ),
		);
	}

	public static function cb_delete_post_schema( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete schema data.', 'rank-math-mcp-ability' ) );
		}

		$post_id = (int) $input['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'rank-math-mcp-ability' ) );
		}

		$type     = preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['type'] );
		$meta_key = 'rank_math_schema_' . $type;

		$old = self::meta_or_null( $post_id, $meta_key );
		if ( null === $old ) {
			return new \WP_Error( 'schema_not_found', __( 'The post has no schema of that type.', 'rank-math-mcp-ability' ) );
		}
		delete_post_meta( $post_id, $meta_key );

		return array(
			'post_id' => $post_id,
			'type'    => $type,
			'old'     => $old,
			'new'     => null,
		);
	}

	public static function cb_list_schema_posts( $input ) {
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		$type      = isset( $input['type'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['type'] ) : '';
		$per_page  = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$page      = isset( $input['page'] ) ? (int) $input['page'] : 1;

		if ( ! in_array( $post_type, get_post_types( array( 'public' => true ) ), true ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'Unknown or non-public post type.', 'rank-math-mcp-ability' ) );
		}

		$meta_key = '' !== $type ? 'rank_math_schema_' . $type : 'rank_math_schema_';

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => min( 100, max( 1, $per_page ) ),
				'paged'          => max( 1, $page ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'compare' => '' !== $type ? 'EXISTS' : 'LIKE',
					),
				),
			)
		);

		$posts = array();
		foreach ( $query->posts as $post ) {
			$types = array();
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( str_starts_with( $key, 'rank_math_schema_' ) ) {
					$types[] = substr( $key, strlen( 'rank_math_schema_' ) );
				}
			}
			$posts[] = array(
				'id'           => (int) $post->ID,
				'title'        => $post->post_title,
				'schema_types' => $types,
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'posts' => $posts,
		);
	}

	public static function cb_bulk_update_seo_meta( $input ) {
		$dry_run = ! empty( $input['dry_run'] );
		if ( ! $dry_run && true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true for a real run, or use dry_run: true to preview.', 'rank-math-mcp-ability' ) );
		}

		$post_type    = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
		$field        = (string) $input['field'];
		$only_missing = ! array_key_exists( 'only_missing', $input ) || ! empty( $input['only_missing'] );
		$limit        = isset( $input['limit'] ) ? min( 200, max( 1, (int) $input['limit'] ) ) : 50;

		if ( ! in_array( $post_type, get_post_types( array( 'public' => true ) ), true ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'Unknown or non-public post type.', 'rank-math-mcp-ability' ) );
		}

		$field_keys = array(
			'robots'          => 'rank_math_robots',
			'seo_title'       => 'rank_math_title',
			'seo_description' => 'rank_math_description',
		);
		$meta_key   = $field_keys[ $field ];

		if ( 'robots' === $field ) {
			$value = array_values( array_intersect( (array) $input['value'], self::ROBOTS_VALUES ) );
			if ( empty( $value ) ) {
				return new \WP_Error( 'invalid_value', __( 'Robots value must contain at least one valid directive.', 'rank-math-mcp-ability' ) );
			}
		} else {
			$value = sanitize_text_field( (string) $input['value'] );
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $only_missing ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => $meta_key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => $meta_key,
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		$ids = ( new \WP_Query( $args ) )->posts;

		$posts   = array();
		$changed = 0;
		foreach ( $ids as $id ) {
			$old = self::meta_or_null( (int) $id, $meta_key );
			if ( ! $dry_run ) {
				update_post_meta( (int) $id, $meta_key, $value );
				++$changed;
			}
			$posts[] = array(
				'id'  => (int) $id,
				'old' => $old,
				'new' => $dry_run ? $old : self::meta_or_null( (int) $id, $meta_key ),
			);
		}

		return array(
			'dry_run' => $dry_run,
			'matched' => count( $ids ),
			'changed' => $changed,
			'posts'   => $posts,
		);
	}
}
