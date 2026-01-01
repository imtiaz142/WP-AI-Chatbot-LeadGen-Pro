<?php
/**
 * Lead Capture Form.
 *
 * Creates and manages the lead capture form with configurable display triggers.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Lead_Capture_Form {

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Config instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Display triggers.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const TRIGGERS = array(
		'immediate'       => 'Show immediately when chat opens',
		'after_greeting'  => 'Show after initial greeting',
		'after_messages'  => 'Show after N messages',
		'high_intent'     => 'Show on high-intent detection',
		'meeting_request' => 'Show on meeting/demo request',
		'pricing_inquiry' => 'Show on pricing inquiry',
		'exit_intent'     => 'Show on exit intent',
		'manual'          => 'Manual trigger only',
	);

	/**
	 * Form fields configuration.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $default_fields = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		$this->init_default_fields();
	}

	/**
	 * Initialize default form fields.
	 *
	 * @since 1.0.0
	 */
	private function init_default_fields() {
		$this->default_fields = array(
			'name' => array(
				'type'        => 'text',
				'label'       => __( 'Name', 'wp-ai-chatbot-leadgen-pro' ),
				'placeholder' => __( 'Your name', 'wp-ai-chatbot-leadgen-pro' ),
				'required'    => true,
				'enabled'     => true,
				'order'       => 1,
				'validation'  => 'text',
			),
			'email' => array(
				'type'        => 'email',
				'label'       => __( 'Email', 'wp-ai-chatbot-leadgen-pro' ),
				'placeholder' => __( 'your@email.com', 'wp-ai-chatbot-leadgen-pro' ),
				'required'    => true,
				'enabled'     => true,
				'order'       => 2,
				'validation'  => 'email',
			),
			'phone' => array(
				'type'        => 'tel',
				'label'       => __( 'Phone', 'wp-ai-chatbot-leadgen-pro' ),
				'placeholder' => __( 'Your phone number', 'wp-ai-chatbot-leadgen-pro' ),
				'required'    => false,
				'enabled'     => true,
				'order'       => 3,
				'validation'  => 'phone',
			),
			'company' => array(
				'type'        => 'text',
				'label'       => __( 'Company', 'wp-ai-chatbot-leadgen-pro' ),
				'placeholder' => __( 'Your company', 'wp-ai-chatbot-leadgen-pro' ),
				'required'    => false,
				'enabled'     => false,
				'order'       => 4,
				'validation'  => 'text',
			),
			'message' => array(
				'type'        => 'textarea',
				'label'       => __( 'Message', 'wp-ai-chatbot-leadgen-pro' ),
				'placeholder' => __( 'How can we help?', 'wp-ai-chatbot-leadgen-pro' ),
				'required'    => false,
				'enabled'     => false,
				'order'       => 5,
				'validation'  => 'text',
			),
		);
	}

	/**
	 * Get form configuration.
	 *
	 * @since 1.0.0
	 * @return array Form configuration.
	 */
	public function get_form_config() {
		$saved_config = $this->config->get( 'lead_capture_form', array() );

		$defaults = array(
			'enabled'            => true,
			'title'              => __( 'Get in touch', 'wp-ai-chatbot-leadgen-pro' ),
			'subtitle'           => __( 'Leave your details and we\'ll get back to you shortly.', 'wp-ai-chatbot-leadgen-pro' ),
			'submit_text'        => __( 'Submit', 'wp-ai-chatbot-leadgen-pro' ),
			'success_message'    => __( 'Thank you! We\'ll be in touch soon.', 'wp-ai-chatbot-leadgen-pro' ),
			'dismiss_text'       => __( 'Maybe later', 'wp-ai-chatbot-leadgen-pro' ),
			'trigger'            => 'after_messages',
			'trigger_count'      => 3,
			'high_intent_intents' => array( 'pricing', 'meeting_request', 'demo' ),
			'show_on_pages'      => array(),
			'hide_on_pages'      => array(),
			'fields'             => $this->default_fields,
			'style'              => 'inline', // inline, modal, slide-up
			'position'           => 'after_messages', // after_messages, above_input
			'gdpr_enabled'       => false,
			'gdpr_text'          => __( 'I agree to the privacy policy', 'wp-ai-chatbot-leadgen-pro' ),
			'gdpr_link'          => '',
		);

		return wp_parse_args( $saved_config, $defaults );
	}

	/**
	 * Get enabled form fields.
	 *
	 * @since 1.0.0
	 * @return array Enabled fields.
	 */
	public function get_enabled_fields() {
		$config = $this->get_form_config();
		$fields = $config['fields'];

		// Filter enabled fields
		$enabled = array_filter( $fields, function( $field ) {
			return ! empty( $field['enabled'] );
		} );

		// Sort by order
		uasort( $enabled, function( $a, $b ) {
			return ( $a['order'] ?? 99 ) - ( $b['order'] ?? 99 );
		} );

		return $enabled;
	}

	/**
	 * Render form HTML.
	 *
	 * @since 1.0.0
	 * @param array $args Render arguments.
	 * @return string Form HTML.
	 */
	public function render( $args = array() ) {
		$config = $this->get_form_config();

		if ( ! $config['enabled'] ) {
			return '';
		}

		$defaults = array(
			'conversation_id' => 0,
			'session_id'      => '',
			'prefill'         => array(),
			'class'           => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$fields = $this->get_enabled_fields();
		$form_id = 'wp-ai-chatbot-lead-form-' . uniqid();

		ob_start();
		?>
		<div class="wp-ai-chatbot-lead-form <?php echo esc_attr( $args['class'] ); ?>" 
			 data-style="<?php echo esc_attr( $config['style'] ); ?>"
			 role="form"
			 aria-labelledby="<?php echo esc_attr( $form_id ); ?>-title">
			
			<div class="wp-ai-chatbot-lead-form__header">
				<h3 id="<?php echo esc_attr( $form_id ); ?>-title" class="wp-ai-chatbot-lead-form__title">
					<?php echo esc_html( $config['title'] ); ?>
				</h3>
				<?php if ( ! empty( $config['subtitle'] ) ) : ?>
				<p class="wp-ai-chatbot-lead-form__subtitle">
					<?php echo esc_html( $config['subtitle'] ); ?>
				</p>
				<?php endif; ?>
			</div>

			<form class="wp-ai-chatbot-lead-form__form" id="<?php echo esc_attr( $form_id ); ?>">
				<input type="hidden" name="conversation_id" value="<?php echo esc_attr( $args['conversation_id'] ); ?>">
				<input type="hidden" name="session_id" value="<?php echo esc_attr( $args['session_id'] ); ?>">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'wp_ai_chatbot_lead_capture' ) ); ?>">

				<?php foreach ( $fields as $field_name => $field ) : ?>
				<div class="wp-ai-chatbot-lead-form__field">
					<label for="<?php echo esc_attr( $form_id . '-' . $field_name ); ?>" class="wp-ai-chatbot-lead-form__label">
						<?php echo esc_html( $field['label'] ); ?>
						<?php if ( ! empty( $field['required'] ) ) : ?>
						<span class="wp-ai-chatbot-lead-form__required" aria-label="<?php esc_attr_e( 'Required', 'wp-ai-chatbot-leadgen-pro' ); ?>">*</span>
						<?php endif; ?>
					</label>

					<?php if ( $field['type'] === 'textarea' ) : ?>
					<textarea 
						id="<?php echo esc_attr( $form_id . '-' . $field_name ); ?>"
						name="<?php echo esc_attr( $field_name ); ?>"
						class="wp-ai-chatbot-lead-form__input wp-ai-chatbot-lead-form__textarea"
						placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
						<?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>
						rows="3"
					><?php echo esc_textarea( $args['prefill'][ $field_name ] ?? '' ); ?></textarea>
					<?php else : ?>
					<input 
						type="<?php echo esc_attr( $field['type'] ); ?>"
						id="<?php echo esc_attr( $form_id . '-' . $field_name ); ?>"
						name="<?php echo esc_attr( $field_name ); ?>"
						class="wp-ai-chatbot-lead-form__input"
						placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
						value="<?php echo esc_attr( $args['prefill'][ $field_name ] ?? '' ); ?>"
						<?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>
					>
					<?php endif; ?>

					<span class="wp-ai-chatbot-lead-form__error" aria-live="polite"></span>
				</div>
				<?php endforeach; ?>

				<?php if ( $config['gdpr_enabled'] ) : ?>
				<div class="wp-ai-chatbot-lead-form__field wp-ai-chatbot-lead-form__field--gdpr">
					<label class="wp-ai-chatbot-lead-form__checkbox-label">
						<input type="checkbox" name="gdpr_consent" required class="wp-ai-chatbot-lead-form__checkbox">
						<span class="wp-ai-chatbot-lead-form__checkbox-text">
							<?php if ( ! empty( $config['gdpr_link'] ) ) : ?>
								<?php echo wp_kses_post( sprintf( 
									'%s <a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_html( $config['gdpr_text'] ),
									esc_url( $config['gdpr_link'] ),
									esc_html__( 'Privacy Policy', 'wp-ai-chatbot-leadgen-pro' )
								) ); ?>
							<?php else : ?>
								<?php echo esc_html( $config['gdpr_text'] ); ?>
							<?php endif; ?>
						</span>
					</label>
				</div>
				<?php endif; ?>

				<div class="wp-ai-chatbot-lead-form__actions">
					<button type="submit" class="wp-ai-chatbot-lead-form__submit">
						<?php echo esc_html( $config['submit_text'] ); ?>
					</button>
					<button type="button" class="wp-ai-chatbot-lead-form__dismiss">
						<?php echo esc_html( $config['dismiss_text'] ); ?>
					</button>
				</div>

				<div class="wp-ai-chatbot-lead-form__status" aria-live="polite">
					<div class="wp-ai-chatbot-lead-form__loading" hidden>
						<span class="wp-ai-chatbot-lead-form__spinner"></span>
						<?php esc_html_e( 'Submitting...', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</div>
					<div class="wp-ai-chatbot-lead-form__success" hidden>
						<?php echo esc_html( $config['success_message'] ); ?>
					</div>
					<div class="wp-ai-chatbot-lead-form__error-message" hidden></div>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check if form should be displayed.
	 *
	 * @since 1.0.0
	 * @param array $context Display context.
	 * @return bool Whether to display form.
	 */
	public function should_display( $context = array() ) {
		$config = $this->get_form_config();

		if ( ! $config['enabled'] ) {
			return false;
		}

		$defaults = array(
			'trigger'          => '',
			'message_count'    => 0,
			'intent'           => '',
			'has_submitted'    => false,
			'has_dismissed'    => false,
			'page_url'         => '',
			'is_exit_intent'   => false,
		);
		$context = wp_parse_args( $context, $defaults );

		// Already submitted or dismissed
		if ( $context['has_submitted'] || $context['has_dismissed'] ) {
			return false;
		}

		// Check page restrictions
		if ( ! $this->check_page_restrictions( $context['page_url'], $config ) ) {
			return false;
		}

		// Check trigger
		return $this->check_trigger( $context, $config );
	}

	/**
	 * Check page restrictions.
	 *
	 * @since 1.0.0
	 * @param string $page_url Page URL.
	 * @param array  $config   Form config.
	 * @return bool Whether page passes restrictions.
	 */
	private function check_page_restrictions( $page_url, $config ) {
		if ( empty( $page_url ) ) {
			return true;
		}

		$page_url = strtolower( $page_url );

		// Check show_on_pages (whitelist)
		if ( ! empty( $config['show_on_pages'] ) ) {
			foreach ( $config['show_on_pages'] as $pattern ) {
				if ( strpos( $page_url, strtolower( $pattern ) ) !== false ) {
					return true;
				}
			}
			return false;
		}

		// Check hide_on_pages (blacklist)
		if ( ! empty( $config['hide_on_pages'] ) ) {
			foreach ( $config['hide_on_pages'] as $pattern ) {
				if ( strpos( $page_url, strtolower( $pattern ) ) !== false ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Check trigger conditions.
	 *
	 * @since 1.0.0
	 * @param array $context Display context.
	 * @param array $config  Form config.
	 * @return bool Whether trigger conditions are met.
	 */
	private function check_trigger( $context, $config ) {
		$trigger = $config['trigger'];

		switch ( $trigger ) {
			case 'immediate':
				return true;

			case 'after_greeting':
				return $context['message_count'] >= 1;

			case 'after_messages':
				$count = intval( $config['trigger_count'] ?? 3 );
				return $context['message_count'] >= $count;

			case 'high_intent':
				$high_intent_intents = $config['high_intent_intents'] ?? array();
				return in_array( $context['intent'], $high_intent_intents, true );

			case 'meeting_request':
				return $context['intent'] === 'meeting_request';

			case 'pricing_inquiry':
				return $context['intent'] === 'pricing';

			case 'exit_intent':
				return $context['is_exit_intent'];

			case 'manual':
				return $context['trigger'] === 'manual';

			default:
				return false;
		}
	}

	/**
	 * Validate form submission.
	 *
	 * @since 1.0.0
	 * @param array $data Form data.
	 * @return array|WP_Error Validated data or error.
	 */
	public function validate( $data ) {
		$fields = $this->get_enabled_fields();
		$errors = array();
		$validated = array();

		foreach ( $fields as $field_name => $field ) {
			$value = $data[ $field_name ] ?? '';

			// Required check
			if ( ! empty( $field['required'] ) && empty( $value ) ) {
				$errors[ $field_name ] = sprintf(
					/* translators: %s: Field label */
					__( '%s is required.', 'wp-ai-chatbot-leadgen-pro' ),
					$field['label']
				);
				continue;
			}

			// Skip empty non-required fields
			if ( empty( $value ) ) {
				continue;
			}

			// Type validation
			$validation_type = $field['validation'] ?? 'text';
			$validation_result = $this->validate_field( $value, $validation_type, $field );

			if ( is_wp_error( $validation_result ) ) {
				$errors[ $field_name ] = $validation_result->get_error_message();
			} else {
				$validated[ $field_name ] = $validation_result;
			}
		}

		// GDPR consent
		$config = $this->get_form_config();
		if ( $config['gdpr_enabled'] && empty( $data['gdpr_consent'] ) ) {
			$errors['gdpr_consent'] = __( 'You must agree to the privacy policy.', 'wp-ai-chatbot-leadgen-pro' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'validation_failed', __( 'Please correct the errors below.', 'wp-ai-chatbot-leadgen-pro' ), $errors );
		}

		return $validated;
	}

	/**
	 * Validate individual field.
	 *
	 * @since 1.0.0
	 * @param string $value           Field value.
	 * @param string $validation_type Validation type.
	 * @param array  $field           Field config.
	 * @return string|WP_Error Validated value or error.
	 */
	private function validate_field( $value, $validation_type, $field ) {
		$value = trim( $value );

		switch ( $validation_type ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'wp-ai-chatbot-leadgen-pro' ) );
				}
				return sanitize_email( $value );

			case 'phone':
				// Remove non-numeric characters except + for international
				$phone = preg_replace( '/[^\d+\-\(\)\s]/', '', $value );
				$digits = preg_replace( '/[^\d]/', '', $phone );
				
				if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
					return new WP_Error( 'invalid_phone', __( 'Please enter a valid phone number.', 'wp-ai-chatbot-leadgen-pro' ) );
				}
				return sanitize_text_field( $phone );

			case 'url':
				if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
					return new WP_Error( 'invalid_url', __( 'Please enter a valid URL.', 'wp-ai-chatbot-leadgen-pro' ) );
				}
				return esc_url_raw( $value );

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error( 'invalid_number', __( 'Please enter a valid number.', 'wp-ai-chatbot-leadgen-pro' ) );
				}
				return floatval( $value );

			case 'text':
			default:
				return sanitize_textarea_field( $value );
		}
	}

	/**
	 * Get trigger display settings for JavaScript.
	 *
	 * @since 1.0.0
	 * @return array Trigger settings.
	 */
	public function get_trigger_settings() {
		$config = $this->get_form_config();

		return array(
			'enabled'             => $config['enabled'],
			'trigger'             => $config['trigger'],
			'triggerCount'        => intval( $config['trigger_count'] ?? 3 ),
			'highIntentIntents'   => $config['high_intent_intents'] ?? array(),
			'style'               => $config['style'],
			'position'            => $config['position'],
			'showOnPages'         => $config['show_on_pages'],
			'hideOnPages'         => $config['hide_on_pages'],
		);
	}

	/**
	 * Register custom field.
	 *
	 * @since 1.0.0
	 * @param string $name  Field name.
	 * @param array  $field Field configuration.
	 * @return bool True on success.
	 */
	public function register_field( $name, $field ) {
		$defaults = array(
			'type'        => 'text',
			'label'       => ucfirst( $name ),
			'placeholder' => '',
			'required'    => false,
			'enabled'     => true,
			'order'       => 99,
			'validation'  => 'text',
		);

		$this->default_fields[ $name ] = wp_parse_args( $field, $defaults );
		return true;
	}

	/**
	 * Get available triggers.
	 *
	 * @since 1.0.0
	 * @return array Triggers.
	 */
	public function get_triggers() {
		return self::TRIGGERS;
	}

	/**
	 * Get default fields.
	 *
	 * @since 1.0.0
	 * @return array Default fields.
	 */
	public function get_default_fields() {
		return $this->default_fields;
	}
}

