<?php
/**
 * Lead Capture Handler.
 *
 * Handles AJAX submissions and processing of lead capture forms.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Lead_Capture_Handler {

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Lead form instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Capture_Form
	 */
	private $form;

	/**
	 * Lead storage instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->form = new WP_AI_Chatbot_LeadGen_Pro_Lead_Capture_Form();
		$this->storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();

		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'wp_ajax_wp_ai_chatbot_submit_lead', array( $this, 'handle_submission' ) );
		add_action( 'wp_ajax_nopriv_wp_ai_chatbot_submit_lead', array( $this, 'handle_submission' ) );

		add_action( 'wp_ajax_wp_ai_chatbot_check_lead_trigger', array( $this, 'check_trigger' ) );
		add_action( 'wp_ajax_nopriv_wp_ai_chatbot_check_lead_trigger', array( $this, 'check_trigger' ) );

		add_action( 'wp_ajax_wp_ai_chatbot_dismiss_lead_form', array( $this, 'handle_dismiss' ) );
		add_action( 'wp_ajax_nopriv_wp_ai_chatbot_dismiss_lead_form', array( $this, 'handle_dismiss' ) );

		add_action( 'wp_ajax_wp_ai_chatbot_get_lead_form', array( $this, 'get_form_html' ) );
		add_action( 'wp_ajax_nopriv_wp_ai_chatbot_get_lead_form', array( $this, 'get_form_html' ) );
	}

	/**
	 * Handle lead form submission.
	 *
	 * @since 1.0.0
	 */
	public function handle_submission() {
		// Verify nonce
		if ( ! check_ajax_referer( 'wp_ai_chatbot_lead_capture', 'nonce', false ) ) {
			wp_send_json_error( array(
				'message' => __( 'Security check failed. Please refresh and try again.', 'wp-ai-chatbot-leadgen-pro' ),
			), 403 );
		}

		// Rate limiting
		if ( $this->is_rate_limited() ) {
			wp_send_json_error( array(
				'message' => __( 'Too many submissions. Please wait a moment.', 'wp-ai-chatbot-leadgen-pro' ),
			), 429 );
		}

		// Honeypot check
		if ( ! empty( $_POST['website_url'] ) ) {
			// Fake success for bots
			wp_send_json_success( array(
				'message' => __( 'Thank you! We\'ll be in touch soon.', 'wp-ai-chatbot-leadgen-pro' ),
			) );
		}

		// Get form data
		$form_data = $this->sanitize_form_data( $_POST );

		// Validate form data
		$validated = $this->form->validate( $form_data );

		if ( is_wp_error( $validated ) ) {
			wp_send_json_error( array(
				'message' => $validated->get_error_message(),
				'errors'  => $validated->get_error_data(),
			), 400 );
		}

		// Check for duplicates
		if ( ! empty( $validated['email'] ) ) {
			$duplicate = $this->storage->find_duplicate( $validated['email'], 1 ); // 1 hour window
			if ( $duplicate ) {
				// Silently update existing lead instead of creating duplicate
				$this->storage->update( $duplicate['id'], array(
					'name'    => $validated['name'] ?? $duplicate['name'],
					'phone'   => $validated['phone'] ?? $duplicate['phone'],
					'company' => $validated['company'] ?? $duplicate['company'],
					'message' => $validated['message'] ?? $duplicate['message'],
				) );

				$config = $this->form->get_form_config();
				wp_send_json_success( array(
					'message' => $config['success_message'],
					'lead_id' => $duplicate['id'],
				) );
			}
		}

		// Add context data
		$validated['conversation_id'] = intval( $form_data['conversation_id'] ?? 0 );
		$validated['session_id']      = sanitize_text_field( $form_data['session_id'] ?? '' );
		$validated['source_url']      = esc_url_raw( $form_data['source_url'] ?? wp_get_referer() );
		$validated['gdpr_consent']    = ! empty( $form_data['gdpr_consent'] );

		// Add UTM parameters if available
		$validated['utm'] = $this->extract_utm_params( $form_data );

		// Store lead
		$lead_id = $this->storage->store( $validated );

		if ( is_wp_error( $lead_id ) ) {
			$this->logger->error( 'Lead storage failed', array(
				'error' => $lead_id->get_error_message(),
				'data'  => $validated,
			) );

			wp_send_json_error( array(
				'message' => __( 'Unable to save your information. Please try again.', 'wp-ai-chatbot-leadgen-pro' ),
			), 500 );
		}

		// Calculate initial lead score
		$this->score_lead( $lead_id, $validated );

		// Mark conversation as having lead capture
		if ( ! empty( $validated['conversation_id'] ) ) {
			$this->mark_conversation_lead( $validated['conversation_id'], $lead_id );
		}

		// Store submission timestamp for rate limiting
		$this->record_submission();

		// Get config for success message
		$config = $this->form->get_form_config();

		$this->logger->info( 'Lead captured successfully', array(
			'lead_id' => $lead_id,
			'email'   => $validated['email'] ?? '',
		) );

		wp_send_json_success( array(
			'message' => $config['success_message'],
			'lead_id' => $lead_id,
		) );
	}

	/**
	 * Check if lead form should be triggered.
	 *
	 * @since 1.0.0
	 */
	public function check_trigger() {
		$context = array(
			'trigger'        => sanitize_text_field( $_POST['trigger'] ?? '' ),
			'message_count'  => intval( $_POST['message_count'] ?? 0 ),
			'intent'         => sanitize_text_field( $_POST['intent'] ?? '' ),
			'has_submitted'  => ! empty( $_POST['has_submitted'] ),
			'has_dismissed'  => ! empty( $_POST['has_dismissed'] ),
			'page_url'       => esc_url_raw( $_POST['page_url'] ?? '' ),
			'is_exit_intent' => ! empty( $_POST['is_exit_intent'] ),
		);

		$should_display = $this->form->should_display( $context );

		wp_send_json_success( array(
			'should_display' => $should_display,
			'trigger_settings' => $this->form->get_trigger_settings(),
		) );
	}

	/**
	 * Handle form dismissal.
	 *
	 * @since 1.0.0
	 */
	public function handle_dismiss() {
		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
		$conversation_id = intval( $_POST['conversation_id'] ?? 0 );

		// Store dismissal timestamp
		$dismissal_key = 'wp_ai_chatbot_lead_dismissed_' . ( $session_id ?: 'unknown' );
		set_transient( $dismissal_key, time(), DAY_IN_SECONDS );

		// Log dismissal for analytics
		$this->logger->debug( 'Lead form dismissed', array(
			'session_id'      => $session_id,
			'conversation_id' => $conversation_id,
		) );

		wp_send_json_success( array(
			'dismissed' => true,
		) );
	}

	/**
	 * Get form HTML via AJAX.
	 *
	 * @since 1.0.0
	 */
	public function get_form_html() {
		$args = array(
			'conversation_id' => intval( $_POST['conversation_id'] ?? 0 ),
			'session_id'      => sanitize_text_field( $_POST['session_id'] ?? '' ),
			'prefill'         => array(),
		);

		// Prefill from memory if available
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory' ) && ! empty( $args['session_id'] ) ) {
			$memory = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory();
			$name = $memory->get( $args['session_id'], 'name' );
			$email = $memory->get( $args['session_id'], 'email' );
			
			if ( $name ) {
				$args['prefill']['name'] = $name;
			}
			if ( $email ) {
				$args['prefill']['email'] = $email;
			}
		}

		$html = $this->form->render( $args );

		wp_send_json_success( array(
			'html' => $html,
		) );
	}

	/**
	 * Sanitize form data.
	 *
	 * @since 1.0.0
	 * @param array $data Raw form data.
	 * @return array Sanitized data.
	 */
	private function sanitize_form_data( $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = array_map( 'sanitize_text_field', $value );
			} else {
				$sanitized[ $key ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Extract UTM parameters.
	 *
	 * @since 1.0.0
	 * @param array $data Form data.
	 * @return array UTM parameters.
	 */
	private function extract_utm_params( $data ) {
		$utm_keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' );
		$utm = array();

		foreach ( $utm_keys as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$short_key = str_replace( 'utm_', '', $key );
				$utm[ $short_key ] = sanitize_text_field( $data[ $key ] );
			}
		}

		// Try to get from referer URL
		if ( empty( $utm ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer_parts = wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			if ( ! empty( $referer_parts['query'] ) ) {
				parse_str( $referer_parts['query'], $query_params );
				foreach ( $utm_keys as $key ) {
					if ( ! empty( $query_params[ $key ] ) ) {
						$short_key = str_replace( 'utm_', '', $key );
						$utm[ $short_key ] = sanitize_text_field( $query_params[ $key ] );
					}
				}
			}
		}

		return $utm;
	}

	/**
	 * Score the lead.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id Lead ID.
	 * @param array $data    Lead data.
	 */
	private function score_lead( $lead_id, $data ) {
		// Initial scoring based on data quality
		$score = 0;
		$breakdown = array();

		// Has email
		if ( ! empty( $data['email'] ) ) {
			$score += 10;
			$breakdown['email'] = 10;

			// Business email bonus
			$free_domains = array( 'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com' );
			$domain = substr( strrchr( $data['email'], '@' ), 1 );
			if ( ! in_array( strtolower( $domain ), $free_domains, true ) ) {
				$score += 10;
				$breakdown['business_email'] = 10;
			}
		}

		// Has name
		if ( ! empty( $data['name'] ) ) {
			$score += 5;
			$breakdown['name'] = 5;
		}

		// Has phone
		if ( ! empty( $data['phone'] ) ) {
			$score += 10;
			$breakdown['phone'] = 10;
		}

		// Has company
		if ( ! empty( $data['company'] ) ) {
			$score += 10;
			$breakdown['company'] = 10;
		}

		// Has message
		if ( ! empty( $data['message'] ) ) {
			$score += 5;
			$breakdown['message'] = 5;
		}

		// GDPR consent (shows engagement)
		if ( ! empty( $data['gdpr_consent'] ) ) {
			$score += 5;
			$breakdown['consent'] = 5;
		}

		// UTM source (came from marketing)
		if ( ! empty( $data['utm'] ) ) {
			$score += 5;
			$breakdown['utm'] = 5;
		}

		// Update lead score
		$this->storage->update_score( $lead_id, $score, $breakdown );
	}

	/**
	 * Mark conversation as having a lead.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @param int $lead_id         Lead ID.
	 */
	private function mark_conversation_lead( $conversation_id, $lead_id ) {
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager' ) ) {
			$manager = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager();
			// Add lead_id to conversation metadata
			global $wpdb;
			$table = $wpdb->prefix . 'ai_chatbot_conversations';
			$wpdb->update(
				$table,
				array( 'lead_id' => $lead_id ),
				array( 'id' => $conversation_id )
			);
		}
	}

	/**
	 * Check if user is rate limited.
	 *
	 * @since 1.0.0
	 * @return bool True if rate limited.
	 */
	private function is_rate_limited() {
		$ip = $this->get_client_ip();
		$key = 'wp_ai_chatbot_lead_rate_' . md5( $ip );
		$submissions = get_transient( $key ) ?: array();

		// Clean old submissions (keep last 5 minutes)
		$cutoff = time() - 300;
		$submissions = array_filter( $submissions, function( $timestamp ) use ( $cutoff ) {
			return $timestamp > $cutoff;
		} );

		// Allow 3 submissions per 5 minutes
		return count( $submissions ) >= 3;
	}

	/**
	 * Record submission for rate limiting.
	 *
	 * @since 1.0.0
	 */
	private function record_submission() {
		$ip = $this->get_client_ip();
		$key = 'wp_ai_chatbot_lead_rate_' . md5( $ip );
		$submissions = get_transient( $key ) ?: array();

		$submissions[] = time();
		set_transient( $key, $submissions, 300 );
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.0.0
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}
}

