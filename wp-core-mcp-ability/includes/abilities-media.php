<?php
/**
 * Media/attachment abilities.
 *
 * CRUD on attachment posts themselves (title, description, alt text
 * meta, trash/delete) is already covered generically by the Posts
 * abilities (post_type: "attachment"). This file adds media-specific
 * lookups (mime type filtering, file/dimension metadata) plus base64
 * upload. Remote-URL sideload is deliberately NOT implemented — it
 * would let a caller make the server fetch an arbitrary URL (SSRF
 * risk); base64 upload avoids that by never making an outbound
 * request.
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media {

	/**
	 * Mime types accepted by upload-media, mapped to file extension.
	 * Narrow on purpose: common web-safe media only.
	 */
	const UPLOAD_MIME_WHITELIST = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'application/pdf' => 'pdf',
	);

	/**
	 * Max decoded upload size in bytes (5 MB) — independent of and
	 * always <= the server's own upload_max_filesize/post_max_size.
	 */
	const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/upload-media',
			array(
				'label'               => __( 'Upload a media file', 'wp-core-mcp-ability' ),
				'description'         => __( 'Uploads a file to the media library from base64-encoded content (never from a URL, to avoid server-side request forgery). filename must end in an allowed extension and content_base64 must decode to a matching, whitelisted mime type (jpg, png, gif, webp, pdf). Maximum 5MB decoded size. title and alt_text are optional. Returns the created attachment.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'filename'       => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Desired filename with extension, e.g. "photo.jpg".',
						),
						'content_base64' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Base64-encoded file content.',
						),
						'title'          => array(
							'type'        => 'string',
							'description' => 'Attachment title. Defaults to the filename.',
						),
						'alt_text'       => array(
							'type'        => 'string',
							'description' => 'Alt text (images only).',
						),
					),
					'required'             => array( 'filename', 'content_base64' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created attachment: {id, title, mime_type, url}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_upload' ),
				'permission_callback' => array( __CLASS__, 'permission_upload' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/list-media',
			array(
				'label'               => __( 'List media', 'wp-core-mcp-ability' ),
				'description'         => __( 'Lists media library items (attachments), optionally filtered by mime type (e.g. "image", "image/jpeg", "video", "application/pdf") and/or search term.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'mime_type' => array(
							'type'        => 'string',
							'description' => 'Mime type or type prefix filter, e.g. "image" or "image/png".',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Search term.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum items to return. Default 20.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "total" and "media" (array of {id, title, mime_type, url, date}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/get-media-details',
			array(
				'label'               => __( 'Get media file details', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns file-level details of one attachment: file path, URL, mime type, file size, and (for images) width/height plus generated thumbnail sizes. Alt text and caption/description are on the attachment post itself — use get-post / get-post-meta ("_wp_attachment_image_alt") for those.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Attachment id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'File details: url, mime_type, file, filesize, width, height, sizes.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_details' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public static function permission_read( $input = null ): bool {
		return current_user_can( 'upload_files' );
	}

	public static function permission_upload( $input = null ): bool {
		return current_user_can( 'upload_files' );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = (string) $input['mime_type'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		$query = new \WP_Query( $args );

		$media = array();
		foreach ( $query->posts as $post ) {
			$media[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'mime_type' => $post->post_mime_type,
				'url'       => wp_get_attachment_url( $post->ID ),
				'date'      => $post->post_date,
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'media' => $media,
		);
	}

	public static function cb_upload( $input ) {
		$filename = sanitize_file_name( (string) $input['filename'] );
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		$allowed_ext = array_values( self::UPLOAD_MIME_WHITELIST );
		if ( '' === $ext || ! in_array( $ext, $allowed_ext, true ) ) {
			return new \WP_Error(
				'unsupported_extension',
				sprintf(
					/* translators: %s: comma-separated list of extensions */
					__( 'filename must end in one of: %s.', 'wp-core-mcp-ability' ),
					implode( ', ', $allowed_ext )
				)
			);
		}

		$content = base64_decode( (string) $input['content_base64'], true );
		if ( false === $content ) {
			return new \WP_Error( 'invalid_base64', __( 'content_base64 is not valid base64.', 'wp-core-mcp-ability' ) );
		}
		if ( strlen( $content ) > self::MAX_UPLOAD_BYTES ) {
			return new \WP_Error( 'file_too_large', sprintf(
				/* translators: %d: max size in bytes */
				__( 'Decoded file exceeds the %d byte limit.', 'wp-core-mcp-ability' ),
				self::MAX_UPLOAD_BYTES
			) );
		}

		if ( ! function_exists( 'wp_handle_sideload' ) || ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp_path = wp_tempnam( $filename );
		$written  = file_put_contents( $tmp_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return new \WP_Error( 'write_failed', __( 'Could not write the uploaded content to a temporary file.', 'wp-core-mcp-ability' ) );
		}

		// Sniff the actual content, not just the claimed extension/name.
		$check = wp_check_filetype_and_ext( $tmp_path, $filename );
		if ( empty( $check['type'] ) || ! isset( self::UPLOAD_MIME_WHITELIST[ $check['type'] ] ) ) {
			wp_delete_file( $tmp_path );
			return new \WP_Error( 'mime_mismatch', __( 'File content does not match an allowed, whitelisted mime type.', 'wp-core-mcp-ability' ) );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_path,
			'type'     => $check['type'],
			'size'     => strlen( $content ),
		);

		$attachment_id = media_handle_sideload(
			$file_array,
			0,
			isset( $input['title'] ) ? (string) $input['title'] : null,
			array( 'post_title' => isset( $input['title'] ) ? (string) $input['title'] : pathinfo( $filename, PATHINFO_FILENAME ) )
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			return $attachment_id;
		}

		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
		}

		return array(
			'id'        => (int) $attachment_id,
			'title'     => get_the_title( $attachment_id ),
			'mime_type' => get_post_mime_type( $attachment_id ),
			'url'       => wp_get_attachment_url( $attachment_id ),
		);
	}

	public static function cb_get_details( $input ) {
		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'attachment_not_found', __( 'No attachment exists with the given ID.', 'wp-core-mcp-ability' ) );
		}

		$meta = wp_get_attachment_metadata( $id );
		$path = get_attached_file( $id );

		return array(
			'url'       => wp_get_attachment_url( $id ),
			'mime_type' => $post->post_mime_type,
			'file'      => $meta['file'] ?? basename( (string) $path ),
			'filesize'  => $meta['filesize'] ?? ( $path && file_exists( $path ) ? filesize( $path ) : null ),
			'width'     => $meta['width'] ?? null,
			'height'    => $meta['height'] ?? null,
			'sizes'     => isset( $meta['sizes'] ) ? array_map(
				static function ( $size ) {
					return array(
						'width'  => $size['width'] ?? null,
						'height' => $size['height'] ?? null,
						'file'   => $size['file'] ?? null,
					);
				},
				$meta['sizes']
			) : array(),
		);
	}
}
