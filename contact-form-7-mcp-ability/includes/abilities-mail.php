<?php
/**
 * Mail templates, messages and additional settings abilities.
 *
 * @package CF7MCPAbility
 */

namespace CF7MCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mail {

	/**
	 * Writable keys of a mail template (mail/mail_2 property array).
	 */
	const MAIL_FIELDS = array(
		'subject', 'sender', 'recipient', 'body',
		'additional_headers', 'attachments', 'use_html', 'exclude_blank', 'active',
	);

	public static function register(): void {

		wp_register_ability(
			'contact-form-7-mcp/get-mail-settings',
			array(
				'label'               => __( 'Get mail templates', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Returns both mail templates of a form: "mail" (sent to the site admin on every submission) and "mail_2" (optional auto-reply to the sender, only sent when its "active" flag is true).', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "mail" and "mail_2" templates.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_mail' ),
				'permission_callback' => array( Plugin::class, 'permission_read_form' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/update-mail-settings',
			array(
				'label'               => __( 'Update a mail template', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Updates one or more fields of a form\'s mail template: "mail" (admin notification) or "mail_2" (auto-reply). Fields: subject, sender, recipient, body, additional_headers, attachments, use_html (0/1), exclude_blank (0/1), and for mail_2 also active (bool, whether the auto-reply is sent). Returns {old,new} per changed field.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
						'template' => array(
							'type'        => 'string',
							'enum'        => array( 'mail', 'mail_2' ),
							'description' => 'Which template to update.',
						),
						'fields'   => array(
							'type'        => 'object',
							'description' => 'Map of field => new value. Allowed keys: subject, sender, recipient, body, additional_headers, attachments, use_html, exclude_blank, active (mail_2 only).',
						),
					),
					'required'             => array( 'id', 'template', 'fields' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "template" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_mail' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_form' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/get-messages',
			array(
				'label'               => __( 'Get response messages', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Returns all response messages of a form (mail_sent_ok, mail_sent_ng, validation_error, spam, invalid_required, etc.) shown to visitors depending on submission outcome.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "messages" (key-value map).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_messages' ),
				'permission_callback' => array( Plugin::class, 'permission_read_form' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/update-message',
			array(
				'label'               => __( 'Update a response message', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Updates one response message key of a form (see get-messages for the full key list, e.g. mail_sent_ok, validation_error). Returns {old,new}.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
						'key'   => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Message key, e.g. "mail_sent_ok".',
						),
						'value' => array(
							'type'        => 'string',
							'description' => 'New message text.',
						),
					),
					'required'             => array( 'id', 'key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "key", "old" and "new".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_message' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_form' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/get-additional-settings',
			array(
				'label'               => __( 'Get additional settings', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Returns a form\'s additional settings: the raw text block plus recognized boolean flags parsed from it (demo_mode, skip_mail, subscribers_only, acceptance_as_validation, do_not_store). Sensitive-looking lines are filtered from the raw text.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "raw" and "flags" (known boolean settings).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_additional_settings' ),
				'permission_callback' => array( Plugin::class, 'permission_read_form' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/update-additional-settings',
			array(
				'label'               => __( 'Update additional settings', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Sets one "key: value" line in a form\'s additional settings text block, replacing any existing line with the same key (or appending it). Pass an empty value to remove the line. Lines whose key looks sensitive are refused. Returns {old,new} raw text.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
						'key'   => array(
							'type'        => 'string',
							'pattern'     => '^[a-zA-Z0-9_]+$',
							'description' => 'Setting key, e.g. "skip_mail" or "demo_mode".',
						),
						'value' => array(
							'type'        => 'string',
							'description' => 'Value, e.g. "on". Empty string removes the line.',
						),
					),
					'required'             => array( 'id', 'key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" and "new" raw text.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_additional_settings' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_form' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_get_mail( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return array(
			'mail'   => $form->prop( 'mail' ),
			'mail_2' => $form->prop( 'mail_2' ),
		);
	}

	public static function cb_update_mail( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$template = (string) $input['template'];
		$fields   = (array) $input['fields'];

		$allowed = 'mail_2' === $template ? array_merge( self::MAIL_FIELDS, array( 'active' ) ) : self::MAIL_FIELDS;
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( empty( $fields ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one valid mail field to change.', 'contact-form-7-mcp-ability' ) );
		}

		$properties = $form->get_properties();
		$current    = (array) $properties[ $template ];

		$updated = array();
		foreach ( $fields as $key => $value ) {
			$old               = $current[ $key ] ?? null;
			$current[ $key ]   = 'active' === $key ? (bool) $value : $value;
			$updated[ $key ]   = array( 'old' => $old, 'new' => $current[ $key ] );
		}

		$properties[ $template ] = $current;
		$form->set_properties( $properties );
		$form->save();
		Plugin::refresh_config_validation( $form );

		return array(
			'id'       => $form->id(),
			'template' => $template,
			'updated'  => $updated,
		);
	}

	public static function cb_get_messages( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return array( 'messages' => $form->prop( 'messages' ) );
	}

	public static function cb_update_message( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$key      = (string) $input['key'];
		$messages = (array) $form->prop( 'messages' );
		if ( ! array_key_exists( $key, $messages ) ) {
			return new \WP_Error( 'unknown_key', __( 'Unknown message key. Use get-messages to see valid keys.', 'contact-form-7-mcp-ability' ) );
		}

		$old               = $messages[ $key ];
		$messages[ $key ]  = (string) $input['value'];

		$properties               = $form->get_properties();
		$properties['messages']   = $messages;
		$form->set_properties( $properties );
		$form->save();
		Plugin::refresh_config_validation( $form );

		return array(
			'id'  => $form->id(),
			'key' => $key,
			'old' => $old,
			'new' => $form->prop( 'messages' )[ $key ] ?? null,
		);
	}

	public static function cb_get_additional_settings( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$raw = self::strip_sensitive_lines( (string) $form->prop( 'additional_settings' ) );

		$flags = array();
		foreach ( array( 'demo_mode', 'skip_mail', 'subscribers_only', 'acceptance_as_validation', 'do_not_store' ) as $flag ) {
			$flags[ $flag ] = $form->is_true( $flag );
		}

		return array(
			'raw'   => $raw,
			'flags' => $flags,
		);
	}

	public static function cb_update_additional_settings( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$key = (string) $input['key'];
		if ( Plugin::is_sensitive_key( $key ) ) {
			return new \WP_Error( 'sensitive_key', __( 'This key may contain secrets and cannot be written through this ability.', 'contact-form-7-mcp-ability' ) );
		}

		$old   = (string) $form->prop( 'additional_settings' );
		$lines = array_filter( explode( "\n", $old ), 'strlen' );

		$pattern = '/^' . preg_quote( $key, '/' ) . '\s*:/';
		$lines   = array_values( array_filter(
			$lines,
			static function ( $line ) use ( $pattern ) {
				return ! preg_match( $pattern, $line );
			}
		) );

		$value = (string) $input['value'];
		if ( '' !== $value ) {
			$lines[] = $key . ': ' . $value;
		}

		$new = implode( "\n", $lines );

		$properties                        = $form->get_properties();
		$properties['additional_settings'] = $new;
		$form->set_properties( $properties );
		$form->save();
		Plugin::refresh_config_validation( $form );

		return array(
			'id'  => $form->id(),
			'old' => self::strip_sensitive_lines( $old ),
			'new' => self::strip_sensitive_lines( $form->prop( 'additional_settings' ) ),
		);
	}

	/**
	 * Remove lines whose "key:" looks sensitive from a raw settings block.
	 */
	private static function strip_sensitive_lines( string $raw ): string {
		$lines = explode( "\n", $raw );
		$lines = array_filter(
			$lines,
			static function ( $line ) {
				if ( ! preg_match( '/^([a-zA-Z0-9_]+)\s*:/', $line, $m ) ) {
					return true;
				}
				return ! Plugin::is_sensitive_key( $m[1] );
			}
		);
		return implode( "\n", $lines );
	}
}
