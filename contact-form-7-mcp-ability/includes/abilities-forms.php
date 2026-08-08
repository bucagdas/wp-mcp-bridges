<?php
/**
 * Form CRUD, form tags and config validation abilities.
 *
 * @package CF7MCPAbility
 */

namespace CF7MCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Forms {

	public static function register(): void {

		wp_register_ability(
			'contact-form-7-mcp/list-forms',
			array(
				'label'               => __( 'List contact forms', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Lists Contact Form 7 forms with id, title, name (slug) and locale. Optionally search by title.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Substring to look for in the form title.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum forms to return. Default 20.',
						),
						'page'     => array(
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
					'description' => 'Object with "total" and "forms" (array of {id, title, name, locale}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( Plugin::class, 'permission_read_forms' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/get-form',
			array(
				'label'               => __( 'Get a contact form', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Returns the full definition of one Contact Form 7 form: title, locale, form HTML/tags, both mail templates, messages and additional settings.', 'contact-form-7-mcp-ability' ),
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
					'description' => 'Full form definition.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( Plugin::class, 'permission_read_form' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/create-form',
			array(
				'label'               => __( 'Create a contact form', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Creates a new Contact Form 7 form. Only title is required; form/mail/mail_2/messages/additional_settings default to the built-in template when omitted (same as clicking "Add New" in wp-admin). Returns the created form.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'                => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Form title.',
						),
						'locale'               => array(
							'type'        => 'string',
							'description' => 'Locale code, e.g. "en_US". Defaults to site locale.',
						),
						'form'                 => array(
							'type'        => 'string',
							'description' => 'Form HTML with CF7 form-tags. Defaults to the built-in template.',
						),
						'mail'                 => array(
							'type'        => 'object',
							'description' => 'Mail template (subject, sender, recipient, body, additional_headers, attachments, use_html, exclude_blank). Defaults to the built-in template.',
						),
						'messages'             => array(
							'type'        => 'object',
							'description' => 'Response messages keyed by status. Defaults to the built-in template.',
						),
						'additional_settings'  => array(
							'type'        => 'string',
							'description' => 'Raw additional-settings text (one "key: value" per line).',
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created form.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_forms' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/update-form',
			array(
				'label'               => __( 'Update contact form title/locale', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Updates the title and/or locale of a Contact Form 7 form. Use update-form-content, update-mail-settings, update-message or update-additional-settings for the other properties. Returns {old,new} read back after the write.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
						'title'  => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'New title.',
						),
						'locale' => array(
							'type'        => 'string',
							'description' => 'New locale code, e.g. "en_US".',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_form' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/delete-form',
			array(
				'label'               => __( 'Delete a contact form', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Permanently deletes a Contact Form 7 form. Requires confirm: true. Contact Form 7 has no trash for forms — deletion is immediate and irreversible.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (deleted form title) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( Plugin::class, 'permission_delete_form' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/duplicate-form',
			array(
				'label'               => __( 'Duplicate a contact form', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Creates a copy of an existing Contact Form 7 form (title suffixed with "_copy"), including all its properties. Returns the new form.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Id of the form to duplicate.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The newly created copy.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_duplicate' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_forms' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/get-form-tags',
			array(
				'label'               => __( 'Get form tags', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Scans a form\'s HTML and returns its CF7 form-tags (input fields): type, name, required flag, options and raw content. Useful for understanding what data a form collects before editing its mail template.', 'contact-form-7-mcp-ability' ),
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
					'description' => 'Object with "tags": array of {type, name, required, basetype, options, raw}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_tags' ),
				'permission_callback' => array( Plugin::class, 'permission_read_form' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/update-form-content',
			array(
				'label'               => __( 'Update form content', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Replaces the form HTML (CF7 form-tags) of a form. Returns {old,new}. Run validate-form afterwards to check for tag/mail mismatches.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
						'form' => array(
							'type'        => 'string',
							'description' => 'New form HTML with CF7 form-tags.',
						),
					),
					'required'             => array( 'id', 'form' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" and "new" form HTML.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_content' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_form' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/validate-form',
			array(
				'label'               => __( 'Validate a contact form', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Runs Contact Form 7\'s own configuration validator on a form (the same checks shown as a warning icon in wp-admin): mismatched mail-tags, invalid form-tags, deprecated settings, and more. Returns whether the form is valid and the list of errors found.', 'contact-form-7-mcp-ability' ),
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
					'description' => 'Object with "valid" (boolean), "error_count" and "errors" (array of {section, code, message}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_validate' ),
				'permission_callback' => array( Plugin::class, 'permission_read_form' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'posts_per_page' => $per_page,
			'offset'         => ( $page - 1 ) * $per_page,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$forms = \WPCF7_ContactForm::find( $args );

		return array(
			'total' => \WPCF7_ContactForm::count(),
			'forms' => array_map(
				static function ( $form ) {
					return array(
						'id'     => $form->id(),
						'title'  => $form->title(),
						'name'   => $form->name(),
						'locale' => $form->locale(),
					);
				},
				$forms
			),
		);
	}

	public static function cb_get( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return Plugin::form_to_array( $form );
	}

	public static function cb_create( $input ) {
		$form = \WPCF7_ContactForm::get_template( array(
			'locale' => isset( $input['locale'] ) ? (string) $input['locale'] : null,
			'title'  => (string) $input['title'],
		) );

		$properties = $form->get_properties();
		foreach ( array( 'form', 'mail', 'mail_2', 'messages', 'additional_settings' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$properties[ $key ] = $input[ $key ];
			}
		}
		$form->set_properties( $properties );

		$id = $form->save();
		if ( ! $id ) {
			return new \WP_Error( 'create_failed', __( 'The form could not be created.', 'contact-form-7-mcp-ability' ) );
		}

		return Plugin::form_to_array( Plugin::get_form( $id ) );
	}

	public static function cb_update( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$updated = array();

		if ( isset( $input['title'] ) ) {
			$old = $form->title();
			$form->set_title( (string) $input['title'] );
			$updated['title'] = array( 'old' => $old, 'new' => $form->title() );
		}
		if ( isset( $input['locale'] ) ) {
			$old = $form->locale();
			$form->set_locale( (string) $input['locale'] );
			$updated['locale'] = array( 'old' => $old, 'new' => $form->locale() );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide title and/or locale to change.', 'contact-form-7-mcp-ability' ) );
		}

		$form->save();

		return array(
			'id'      => $form->id(),
			'updated' => $updated,
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a form.', 'contact-form-7-mcp-ability' ) );
		}

		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$id    = $form->id();
		$title = $form->title();

		if ( ! $form->delete() ) {
			return new \WP_Error( 'delete_failed', __( 'The form could not be deleted.', 'contact-form-7-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $title,
			'new' => null,
		);
	}

	public static function cb_duplicate( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$copy = $form->copy();
		$id   = $copy->save();
		if ( ! $id ) {
			return new \WP_Error( 'duplicate_failed', __( 'The form could not be duplicated.', 'contact-form-7-mcp-ability' ) );
		}

		return Plugin::form_to_array( Plugin::get_form( $id ) );
	}

	public static function cb_get_tags( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$tags = $form->scan_form_tags();

		return array(
			'tags' => array_map(
				static function ( $tag ) {
					return array(
						'type'     => $tag->type,
						'basetype' => $tag->basetype,
						'name'     => $tag->name,
						'required' => (bool) $tag->is_required(),
						'options'  => $tag->options,
						'raw'      => $tag->raw_name,
					);
				},
				$tags
			),
		);
	}

	public static function cb_update_content( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$old = $form->prop( 'form' );

		$properties         = $form->get_properties();
		$properties['form'] = (string) $input['form'];
		$form->set_properties( $properties );
		$form->save();

		return array(
			'id'  => $form->id(),
			'old' => $old,
			'new' => $form->prop( 'form' ),
		);
	}

	public static function cb_validate( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		if ( ! class_exists( '\WPCF7_ConfigValidator' ) ) {
			return new \WP_Error( 'validator_unavailable', __( 'The Contact Form 7 config validator is not loaded.', 'contact-form-7-mcp-ability' ) );
		}

		$validator = new \WPCF7_ConfigValidator( $form );
		$validator->validate();

		$errors = array();
		foreach ( $validator->collect_error_messages() as $section => $messages ) {
			foreach ( $messages as $error ) {
				$errors[] = array(
					'section' => $section,
					'message' => wp_strip_all_tags( (string) ( $error['message'] ?? '' ) ),
				);
			}
		}

		return array(
			'valid'       => $validator->is_valid(),
			'error_count' => $validator->count_errors(),
			'errors'      => $errors,
		);
	}
}
