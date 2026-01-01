<?php
/**
 * Intent Router.
 *
 * Routes different intent types to specialized handlers.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Intent_Router {

	/**
	 * Intent classifier instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Intent_Classifier
	 */
	private $intent_classifier;

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
	 * Registered intent handlers.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $handlers = array();

	/**
	 * Default handler callback.
	 *
	 * @since 1.0.0
	 * @var callable|null
	 */
	private $default_handler = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->intent_classifier = new WP_AI_Chatbot_LeadGen_Pro_Intent_Classifier();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		$this->register_default_handlers();
	}

	/**
	 * Register default intent handlers.
	 *
	 * @since 1.0.0
	 */
	private function register_default_handlers() {
		// Greeting handler
		$this->register_handler( 'greeting', array( $this, 'handle_greeting' ) );

		// Pricing handler
		$this->register_handler( 'pricing', array( $this, 'handle_pricing' ) );

		// Meeting request handler
		$this->register_handler( 'meeting_request', array( $this, 'handle_meeting_request' ) );

		// Technical question handler
		$this->register_handler( 'technical_question', array( $this, 'handle_technical_question' ) );

		// Feature comparison handler
		$this->register_handler( 'feature_comparison', array( $this, 'handle_feature_comparison' ) );

		// Service inquiry handler
		$this->register_handler( 'service_inquiry', array( $this, 'handle_service_inquiry' ) );

		// Complaint handler
		$this->register_handler( 'complaint', array( $this, 'handle_complaint' ) );

		// Farewell handler
		$this->register_handler( 'farewell', array( $this, 'handle_farewell' ) );

		// Default handler for general and unhandled intents
		$this->set_default_handler( array( $this, 'handle_default' ) );
	}

	/**
	 * Route message based on intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text    User message text.
	 * @param array  $context         Optional. Routing context.
	 * @return array|WP_Error Handler response or WP_Error on failure.
	 */
	public function route( $message_text, $context = array() ) {
		// Classify intent
		$classification = $this->intent_classifier->classify( $message_text, $context );

		if ( empty( $classification ) || ! isset( $classification['intent'] ) ) {
			return new WP_Error(
				'classification_failed',
				__( 'Failed to classify message intent.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$intent = $classification['intent'];
		$confidence = $classification['confidence'] ?? 0.5;

		// Check if we should use specialized handler or fallback to default
		$use_handler = $confidence >= $this->intent_classifier->get_min_confidence();

		if ( $use_handler && isset( $this->handlers[ $intent ] ) ) {
			// Use specialized handler
			$handler = $this->handlers[ $intent ];
			$this->logger->debug(
				'Routing to specialized handler',
				array(
					'intent'     => $intent,
					'confidence' => $confidence,
				)
			);

			return $this->execute_handler( $handler, $message_text, $classification, $context );
		}

		// Use default handler
		if ( $this->default_handler ) {
			$this->logger->debug(
				'Routing to default handler',
				array(
					'intent'     => $intent,
					'confidence' => $confidence,
				)
			);

			return $this->execute_handler( $this->default_handler, $message_text, $classification, $context );
		}

		// No handler available
		return new WP_Error(
			'no_handler',
			__( 'No handler available for this intent.', 'wp-ai-chatbot-leadgen-pro' )
		);
	}

	/**
	 * Execute intent handler.
	 *
	 * @since 1.0.0
	 * @param callable $handler       Handler callback.
	 * @param string   $message_text  User message.
	 * @param array    $classification Intent classification.
	 * @param array    $context       Routing context.
	 * @return array|WP_Error Handler response.
	 */
	private function execute_handler( $handler, $message_text, $classification, $context ) {
		if ( ! is_callable( $handler ) ) {
			return new WP_Error(
				'invalid_handler',
				__( 'Handler is not callable.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		try {
			$result = call_user_func( $handler, $message_text, $classification, $context );

			// Ensure result has required structure
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! is_array( $result ) ) {
				// Convert string to array format
				$result = array(
					'response' => $result,
					'action'   => 'respond',
				);
			}

			// Add classification metadata
			$result['intent'] = $classification['intent'];
			$result['confidence'] = $classification['confidence'];

			return $result;
		} catch ( Exception $e ) {
			$this->logger->error(
				'Handler execution failed',
				array(
					'error'   => $e->getMessage(),
					'intent'  => $classification['intent'],
				)
			);
			return new WP_Error(
				'handler_error',
				__( 'Handler execution failed.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}
	}

	/**
	 * Register intent handler.
	 *
	 * @since 1.0.0
	 * @param string   $intent  Intent type.
	 * @param callable $handler Handler callback.
	 * @return bool True on success, false on failure.
	 */
	public function register_handler( $intent, $handler ) {
		if ( ! is_callable( $handler ) ) {
			return false;
		}

		$this->handlers[ $intent ] = $handler;

		$this->logger->debug(
			'Intent handler registered',
			array( 'intent' => $intent )
		);

		return true;
	}

	/**
	 * Unregister intent handler.
	 *
	 * @since 1.0.0
	 * @param string $intent Intent type.
	 * @return bool True on success, false on failure.
	 */
	public function unregister_handler( $intent ) {
		if ( isset( $this->handlers[ $intent ] ) ) {
			unset( $this->handlers[ $intent ] );
			return true;
		}

		return false;
	}

	/**
	 * Set default handler.
	 *
	 * @since 1.0.0
	 * @param callable $handler Default handler callback.
	 * @return bool True on success, false on failure.
	 */
	public function set_default_handler( $handler ) {
		if ( ! is_callable( $handler ) ) {
			return false;
		}

		$this->default_handler = $handler;
		return true;
	}

	/**
	 * Handle greeting intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_greeting( $message_text, $classification, $context ) {
		$greetings = array(
			__( 'Hello! How can I help you today?', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'Hi there! What can I do for you?', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'Hey! I\'m here to help. What would you like to know?', 'wp-ai-chatbot-leadgen-pro' ),
		);

		$greeting = $greetings[ array_rand( $greetings ) ];

		return array(
			'response' => $greeting,
			'action'   => 'respond',
			'metadata' => array(
				'handler' => 'greeting',
			),
		);
	}

	/**
	 * Handle pricing intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_pricing( $message_text, $classification, $context ) {
		// Get pricing information from config or custom handler
		$pricing_url = $this->config->get( 'pricing_page_url', '' );
		$pricing_info = $this->config->get( 'pricing_info', '' );

		$response = __( 'I\'d be happy to help you with pricing information! ', 'wp-ai-chatbot-leadgen-pro' );

		if ( ! empty( $pricing_info ) ) {
			$response .= $pricing_info;
		} else {
			$response .= __( 'Let me search our knowledge base for the most current pricing details.', 'wp-ai-chatbot-leadgen-pro' );
		}

		if ( ! empty( $pricing_url ) ) {
			$response .= ' ' . sprintf(
				__( 'You can also view our <a href="%s" target="_blank">pricing page</a> for detailed information.', 'wp-ai-chatbot-leadgen-pro' ),
				esc_url( $pricing_url )
			);
		}

		return array(
			'response' => $response,
			'action'   => 'respond',
			'metadata' => array(
				'handler'      => 'pricing',
				'pricing_url'  => $pricing_url,
			),
		);
	}

	/**
	 * Handle meeting request intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_meeting_request( $message_text, $classification, $context ) {
		$calendly_url = $this->config->get( 'calendly_url', '' );
		$booking_enabled = $this->config->get( 'meeting_booking_enabled', true );

		$response = __( 'I\'d be happy to help you schedule a meeting! ', 'wp-ai-chatbot-leadgen-pro' );

		if ( $booking_enabled && ! empty( $calendly_url ) ) {
			$response .= sprintf(
				__( 'You can <a href="%s" target="_blank" class="wp-ai-chatbot-booking-link">book a time directly here</a>.', 'wp-ai-chatbot-leadgen-pro' ),
				esc_url( $calendly_url )
			);
		} else {
			$response .= __( 'Please provide your contact information and preferred time, and our team will get back to you shortly.', 'wp-ai-chatbot-leadgen-pro' );
		}

		return array(
			'response' => $response,
			'action'   => 'respond',
			'show_lead_capture' => true,
			'metadata' => array(
				'handler'       => 'meeting_request',
				'calendly_url'  => $calendly_url,
				'booking_enabled' => $booking_enabled,
			),
		);
	}

	/**
	 * Handle technical question intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_technical_question( $message_text, $classification, $context ) {
		$docs_url = $this->config->get( 'documentation_url', '' );

		$response = __( 'I\'ll help you with that technical question. Let me search our knowledge base for the most relevant information.', 'wp-ai-chatbot-leadgen-pro' );

		if ( ! empty( $docs_url ) ) {
			$response .= ' ' . sprintf(
				__( 'You can also check our <a href="%s" target="_blank">documentation</a> for detailed technical information.', 'wp-ai-chatbot-leadgen-pro' ),
				esc_url( $docs_url )
			);
		}

		return array(
			'response' => $response,
			'action'   => 'respond_with_rag', // Use RAG system for technical questions
			'metadata' => array(
				'handler'      => 'technical_question',
				'docs_url'     => $docs_url,
			),
		);
	}

	/**
	 * Handle feature comparison intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_feature_comparison( $message_text, $classification, $context ) {
		$comparison_url = $this->config->get( 'comparison_page_url', '' );

		$response = __( 'I can help you compare our features and plans. Let me find the most relevant comparison information for you.', 'wp-ai-chatbot-leadgen-pro' );

		if ( ! empty( $comparison_url ) ) {
			$response .= ' ' . sprintf(
				__( 'You can also view our <a href="%s" target="_blank">detailed comparison page</a>.', 'wp-ai-chatbot-leadgen-pro' ),
				esc_url( $comparison_url )
			);
		}

		return array(
			'response' => $response,
			'action'   => 'respond_with_rag',
			'metadata' => array(
				'handler'        => 'feature_comparison',
				'comparison_url' => $comparison_url,
			),
		);
	}

	/**
	 * Handle service inquiry intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_service_inquiry( $message_text, $classification, $context ) {
		$response = __( 'I\'d be happy to tell you about our services! Let me search our knowledge base for the most relevant information.', 'wp-ai-chatbot-leadgen-pro' );

		return array(
			'response' => $response,
			'action'   => 'respond_with_rag',
			'metadata' => array(
				'handler' => 'service_inquiry',
			),
		);
	}

	/**
	 * Handle complaint intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_complaint( $message_text, $classification, $context ) {
		$support_email = $this->config->get( 'support_email', '' );
		$support_url = $this->config->get( 'support_url', '' );

		$response = __( 'I\'m sorry to hear you\'re experiencing an issue. I\'ll do my best to help you resolve this. ', 'wp-ai-chatbot-leadgen-pro' );

		if ( ! empty( $support_email ) ) {
			$response .= sprintf(
				__( 'You can also reach out to our support team at <a href="mailto:%s">%s</a>.', 'wp-ai-chatbot-leadgen-pro' ),
				esc_attr( $support_email ),
				esc_html( $support_email )
			);
		} elseif ( ! empty( $support_url ) ) {
			$response .= sprintf(
				__( 'You can also <a href="%s" target="_blank">contact our support team</a> for immediate assistance.', 'wp-ai-chatbot-leadgen-pro' ),
				esc_url( $support_url )
			);
		}

		$response .= ' ' . __( 'Let me search our knowledge base to see if I can help resolve your issue right away.', 'wp-ai-chatbot-leadgen-pro' );

		return array(
			'response' => $response,
			'action'   => 'respond_with_rag',
			'escalate' => true, // Flag for escalation
			'show_lead_capture' => true,
			'metadata' => array(
				'handler'       => 'complaint',
				'support_email' => $support_email,
				'support_url'   => $support_url,
			),
		);
	}

	/**
	 * Handle farewell intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_farewell( $message_text, $classification, $context ) {
		$farewells = array(
			__( 'You\'re welcome! Feel free to come back if you have any more questions.', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'Happy to help! Have a great day!', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'Thanks for chatting! Don\'t hesitate to reach out if you need anything else.', 'wp-ai-chatbot-leadgen-pro' ),
		);

		$farewell = $farewells[ array_rand( $farewells ) ];

		return array(
			'response' => $farewell,
			'action'   => 'respond',
			'metadata' => array(
				'handler' => 'farewell',
			),
		);
	}

	/**
	 * Handle default/general intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text  User message.
	 * @param array  $classification Intent classification.
	 * @param array  $context       Routing context.
	 * @return array Handler response.
	 */
	public function handle_default( $message_text, $classification, $context ) {
		// Default handler uses RAG system for general questions
		return array(
			'response' => '', // Empty response triggers RAG processing
			'action'   => 'respond_with_rag',
			'metadata' => array(
				'handler' => 'default',
				'intent'  => $classification['intent'],
			),
		);
	}

	/**
	 * Get registered handlers.
	 *
	 * @since 1.0.0
	 * @return array Registered handlers.
	 */
	public function get_handlers() {
		return $this->handlers;
	}

	/**
	 * Check if handler is registered for intent.
	 *
	 * @since 1.0.0
	 * @param string $intent Intent type.
	 * @return bool True if handler is registered.
	 */
	public function has_handler( $intent ) {
		return isset( $this->handlers[ $intent ] );
	}
}

