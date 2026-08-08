<?php
/**
 * Comment CRUD abilities.
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Comments {

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/list-comments',
			array(
				'label'               => __( 'List comments', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists comments, optionally filtered by post id and/or status (hold, approve, spam, trash).', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'post_id'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Only comments on this post.',
						),
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'hold', 'approve', 'spam', 'trash', 'any' ),
							'default'     => 'approve',
							'description' => 'Comment status filter. Default "approve".',
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum comments to return. Default 20.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "total" and "comments" (array of {id, post_id, author, content, status, date}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-comment',
			array(
				'label'               => __( 'Get a comment', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns one comment by id.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Comment id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Comment detail.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/create-comment',
			array(
				'label'               => __( 'Create a comment', 'wp-core-mcp-ability' ),
				'description'         => __( 'Adds a comment to a post. content and post_id are required. When called by a logged-in user, author fields default to their profile; author_name/author_email can be set explicitly. New comments are held for moderation by default; pass status "approve" to publish immediately.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post to comment on.',
						),
						'content'      => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Comment text.',
						),
						'author_name'  => array( 'type' => 'string' ),
						'author_email' => array( 'type' => 'string' ),
						'status'       => array(
							'type'        => 'string',
							'enum'        => array( 'hold', 'approve' ),
							'default'     => 'hold',
							'description' => 'Initial status. Default "hold" (pending moderation).',
						),
					),
					'required'             => array( 'post_id', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created comment.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( __CLASS__, 'permission_create' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-comment-status',
			array(
				'label'               => __( 'Update comment status', 'wp-core-mcp-ability' ),
				'description'         => __( 'Moderates a comment: sets its status to hold, approve, spam or trash. Returns {old,new}.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Comment id.',
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'hold', 'approve', 'spam', 'trash' ),
							'description' => 'New status.',
						),
					),
					'required'             => array( 'id', 'status' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" and "new".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_status' ),
				'permission_callback' => array( __CLASS__, 'permission_moderate' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/delete-comment',
			array(
				'label'               => __( 'Delete a comment', 'wp-core-mcp-ability' ),
				'description'         => __( 'Deletes a comment. Requires confirm: true. By default it is moved to trash; set force: true to delete permanently.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Comment id.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
						'force'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = delete permanently, false (default) = move to trash.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (previous status) and "new" ("trash" or "deleted").',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( __CLASS__, 'permission_moderate' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public static function permission_read( $input = null ): bool {
		return current_user_can( 'moderate_comments' );
	}

	public static function permission_create( $input = null ): bool {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public static function permission_moderate( $input = null ): bool {
		return current_user_can( 'moderate_comments' );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	private static function format_comment( \WP_Comment $c ): array {
		return array(
			'id'      => (int) $c->comment_ID,
			'post_id' => (int) $c->comment_post_ID,
			'author'  => $c->comment_author,
			'content' => $c->comment_content,
			'status'  => wp_get_comment_status( $c ),
			'date'    => $c->comment_date,
			'parent'  => (int) $c->comment_parent,
		);
	}

	public static function cb_list( $input ) {
		$args = array(
			'status' => isset( $input['status'] ) ? (string) $input['status'] : 'approve',
			'number' => isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20,
		);
		if ( 'any' === $args['status'] ) {
			unset( $args['status'] );
		}
		if ( ! empty( $input['post_id'] ) ) {
			$args['post_id'] = (int) $input['post_id'];
		}

		$query    = new \WP_Comment_Query();
		$comments = $query->query( $args );

		return array(
			'total'    => count( $comments ),
			'comments' => array_map( array( __CLASS__, 'format_comment' ), $comments ),
		);
	}

	public static function cb_get( $input ) {
		$comment = get_comment( (int) $input['id'] );
		if ( ! $comment ) {
			return new \WP_Error( 'comment_not_found', __( 'No comment exists with the given ID.', 'wp-core-mcp-ability' ) );
		}
		return self::format_comment( $comment );
	}

	public static function cb_create( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'No post exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$user = wp_get_current_user();

		$args = array(
			'comment_post_ID'      => $post->ID,
			'comment_content'      => (string) $input['content'],
			'comment_author'       => isset( $input['author_name'] ) ? (string) $input['author_name'] : $user->display_name,
			'comment_author_email' => isset( $input['author_email'] ) ? (string) $input['author_email'] : $user->user_email,
			'user_id'              => $user->ID,
			'comment_approved'     => 'approve' === ( $input['status'] ?? 'hold' ) ? 1 : 0,
		);

		$id = wp_insert_comment( $args );
		if ( ! $id ) {
			return new \WP_Error( 'create_failed', __( 'The comment could not be created.', 'wp-core-mcp-ability' ) );
		}

		return self::cb_get( array( 'id' => $id ) );
	}

	public static function cb_update_status( $input ) {
		$id      = (int) $input['id'];
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return new \WP_Error( 'comment_not_found', __( 'No comment exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$old    = wp_get_comment_status( $comment );
		$status = (string) $input['status'];

		$result = wp_set_comment_status( $id, $status, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => wp_get_comment_status( get_comment( $id ) ),
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a comment.', 'wp-core-mcp-ability' ) );
		}

		$id      = (int) $input['id'];
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return new \WP_Error( 'comment_not_found', __( 'No comment exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$old   = wp_get_comment_status( $comment );
		$force = ! empty( $input['force'] );

		$result = wp_delete_comment( $id, ! $force );
		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The comment could not be deleted.', 'wp-core-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => $force ? 'deleted' : 'trash',
		);
	}
}
