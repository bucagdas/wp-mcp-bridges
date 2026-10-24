<?php
/**
 * Status, search, bulk and test-submission abilities.
 *
 * @package CF7MCPAbility
 */

namespace CF7MCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site {

	public static function register(): void {

		wp_register_ability(
			'contact-form-7-mcp/get-status',
			array(
				'label'               => __( 'Get Contact Form 7 status', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Returns Contact Form 7 plugin status: version, form count, and which optional integrations are present (Flamingo for storing submissions, Akismet, reCAPTCHA, Really Simple CAPTCHA, Constant Contact, Stripe, Turnstile). None of these are configured or read here — only presence is reported.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Version, form count and integration presence map.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_status' ),
				'permission_callback' => array( Plugin::class, 'permission_read_forms' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/search-forms',
			array(
				'label'               => __( 'Search form content', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Searches for a substring across all forms\' HTML (form-tags), useful for finding which forms use a specific field name, tag type (e.g. "file", "recaptcha") or text.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Substring to search for in form HTML.',
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "matches": array of {id, title}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_search' ),
				'permission_callback' => array( Plugin::class, 'permission_read_forms' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/bulk-update-message',
			array(
				'label'               => __( 'Bulk update a response message', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Sets the same response message key to the same text across all forms at once. ALWAYS run with dry_run: true first to preview how many forms match. The real run requires confirm: true.', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'key'     => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Message key to set on every form, e.g. "mail_sent_ok".',
						),
						'value'   => array(
							'type'        => 'string',
							'description' => 'New message text.',
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = report matches only, change nothing.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true for a real (non-dry_run) run.',
						),
					),
					'required'             => array( 'key', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "dry_run", "matched", "changed" and "forms" (per-form {id, old, new}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_bulk_update_message' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_forms' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'contact-form-7-mcp/submit-test',
			array(
				'label'               => __( 'Submit a test entry', 'contact-form-7-mcp-ability' ),
				'description'         => __( 'Submits a form with the given field values through Contact Form 7\'s real submission pipeline (validation, spam checks, mail). By default mail sending is skipped (skip_mail: true) so this is a dry run of validation only; set skip_mail: false AND confirm: true to actually trigger the admin notification email. Returns the submission status and response message. Values for required fields must be provided or validation will fail (which is a normal, non-error result).', 'contact-form-7-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Form id.',
						),
						'values'    => array(
							'type'        => 'object',
							'description' => 'Map of form-tag name => value, e.g. {"your-name": "Test", "your-email": "test@example.com"}.',
						),
						'skip_mail' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'true (default) = run validation only, do not send mail. false = send real mail.',
						),
						'confirm'   => array(
							'type'        => 'boolean',
							'description' => 'Must be true when skip_mail is false (real mail will be sent).',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "status", "message" and "skipped_mail".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_submit_test' ),
				'permission_callback' => array( Plugin::class, 'permission_edit_form' ),
				'meta'                => Plugin::meta( false, true, false ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_get_status() {
		\WPCF7_ContactForm::find( array( 'posts_per_page' => 1 ) );

		return array(
			'version'      => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : null,
			'form_count'   => \WPCF7_ContactForm::count(),
			'integrations' => array(
				'flamingo'              => class_exists( 'Flamingo_Contact' ),
				'akismet'               => defined( 'AKISMET_VERSION' ),
				'recaptcha'             => class_exists( 'WPCF7_RECAPTCHA' ),
				'really_simple_captcha' => class_exists( 'ReallySimpleCaptcha' ),
				'constant_contact'      => class_exists( 'WPCF7_ConstantContact' ),
				'stripe'                => class_exists( 'WPCF7_Stripe' ),
				'turnstile'             => class_exists( 'WPCF7_TURNSTILE' ),
			),
		);
	}

	public static function cb_search( $input ) {
		$query = (string) $input['query'];
		$forms = \WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) );

		$matches = array();
		foreach ( $forms as $form ) {
			if ( false !== stripos( (string) $form->prop( 'form' ), $query ) ) {
				$matches[] = array(
					'id'    => $form->id(),
					'title' => $form->title(),
				);
			}
		}

		return array( 'matches' => $matches );
	}

	public static function cb_bulk_update_message( $input ) {
		$dry_run = ! empty( $input['dry_run'] );
		if ( ! $dry_run && true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true for a real run, or use dry_run: true to preview.', 'contact-form-7-mcp-ability' ) );
		}

		$key   = (string) $input['key'];
		$value = (string) $input['value'];

		$forms   = \WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) );
		$results = array();
		$changed = 0;

		foreach ( $forms as $form ) {
			$messages = (array) $form->prop( 'messages' );
			if ( ! array_key_exists( $key, $messages ) ) {
				continue;
			}
			$old = $messages[ $key ];

			if ( ! $dry_run ) {
				$messages[ $key ] = $value;
				$properties       = $form->get_properties();
				$properties['messages'] = $messages;
				$form->set_properties( $properties );
				$form->save();
				++$changed;
			}

			$results[] = array(
				'id'  => $form->id(),
				'old' => $old,
				'new' => $dry_run ? $old : $value,
			);
		}

		return array(
			'dry_run' => $dry_run,
			'matched' => count( $results ),
			'changed' => $changed,
			'forms'   => $results,
		);
	}

	public static function cb_submit_test( $input ) {
		$form = Plugin::get_form( (int) $input['id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$skip_mail = ! array_key_exists( 'skip_mail', $input ) || ! empty( $input['skip_mail'] );
		if ( ! $skip_mail && true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true when skip_mail is false — a real email will be sent.', 'contact-form-7-mcp-ability' ) );
		}

		$values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();

		// Contact Form 7 builds one WPCF7_Submission per request; a second
		// submit() in the same request gets null from get_instance() and
		// fails inside CF7 with "Call to a member function get_result() on
		// null". Over MCP each call is its own request, but say what is
		// wrong instead of letting that surface.
		if ( class_exists( '\WPCF7_Submission' ) && null !== \WPCF7_Submission::get_instance() ) {
			return new \WP_Error(
				'submission_already_run',
				__( 'Contact Form 7 runs one submission per request and one has already run in this request. Call submit-test again in a separate request.', 'contact-form-7-mcp-ability' )
			);
		}

		$original_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Fill $_POST the way WordPress hands it to Contact Form 7 in a real
		// submission: raw values, slashed (wp_magic_quotes), with CF7 doing its
		// own unslashing and per-field sanitising. The previous
		// sanitize_text_field() on unslashed values meant CF7's wp_unslash()
		// stripped backslashes ("C:\dir" arrived as "C:dir") and every textarea
		// lost its line breaks, so the test did not see what a visitor would
		// send. Both measured. Multi-value fields (checkboxes) keep their
		// array shape.
		$_POST = array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && ! str_starts_with( $key, '_' ) ) {
				$raw = is_array( $value ) ? array_map( 'strval', array_values( $value ) ) : (string) $value;
				$_POST[ $key ] = wp_slash( $raw ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
		$_POST['_wpcf7'] = $form->id(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['_wpcf7_version'] = defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['_wpcf7_locale'] = $form->locale(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['_wpcf7_unit_tag'] = 'wpcf7-f' . $form->id() . '-p0-o1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['_wpcf7_container_post'] = 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = $form->submit( array( 'skip_mail' => $skip_mail ) );

		$_POST = $original_post; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return array(
			'status'       => $result['status'] ?? 'unknown',
			'message'      => $result['message'] ?? '',
			'skipped_mail' => $skip_mail,
		);
	}
}
