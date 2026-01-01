<?php
/**
 * Intent Classifier.
 *
 * Classifies user messages into intent categories using AI.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Intent_Classifier {

	/**
	 * Provider factory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Provider_Factory
	 */
	private $provider_factory;

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
	 * Available intent types.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $intent_types = array(
		'greeting'           => array(
			'label'       => 'Greeting',
			'description' => 'User is greeting or saying hello',
			'examples'    => array( 'Hello', 'Hi', 'Hey', 'Good morning', 'Good afternoon' ),
		),
		'pricing'            => array(
			'label'       => 'Pricing Inquiry',
			'description' => 'User is asking about pricing, costs, or plans',
			'examples'    => array( 'How much does it cost?', 'What are your prices?', 'Pricing information', 'How much is it?' ),
		),
		'meeting_request'    => array(
			'label'       => 'Meeting Request',
			'description' => 'User wants to schedule a meeting, call, or demo',
			'examples'    => array( 'Schedule a call', 'Book a demo', 'Set up a meeting', 'I want to talk to someone' ),
		),
		'technical_question' => array(
			'label'       => 'Technical Question',
			'description' => 'User is asking technical questions about features or implementation',
			'examples'    => array( 'How does it work?', 'Technical specifications', 'API documentation', 'Integration details' ),
		),
		'feature_comparison' => array(
			'label'       => 'Feature Comparison',
			'description' => 'User wants to compare features or plans',
			'examples'    => array( 'What\'s the difference?', 'Compare plans', 'Feature comparison', 'Which plan is better?' ),
		),
		'service_inquiry'    => array(
			'label'       => 'Service Inquiry',
			'description' => 'User is asking about services, offerings, or general information',
			'examples'    => array( 'What do you offer?', 'Tell me about your services', 'What can you help with?' ),
		),
		'complaint'          => array(
			'label'       => 'Complaint or Issue',
			'description' => 'User is reporting a problem, complaint, or issue',
			'examples'    => array( 'I have a problem', 'This is not working', 'I need help with an issue', 'Something is wrong' ),
		),
		'farewell'           => array(
			'label'       => 'Farewell',
			'description' => 'User is saying goodbye or ending the conversation',
			'examples'    => array( 'Goodbye', 'Thanks', 'Thank you', 'Bye', 'See you later' ),
		),
		'general'            => array(
			'label'       => 'General Question',
			'description' => 'General question that doesn\'t fit other categories',
			'examples'    => array(),
		),
	);

	/**
	 * Minimum confidence threshold for intent classification.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private $min_confidence = 0.5;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->provider_factory = new WP_AI_Chatbot_LeadGen_Pro_Provider_Factory();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
	}

	/**
	 * Classify user message intent.
	 *
	 * @since 1.0.0
	 * @param string $message_text User message text.
	 * @param array  $context      Optional. Additional context (conversation history, etc.).
	 * @return array Intent classification result.
	 */
	public function classify( $message_text, $context = array() ) {
		if ( empty( $message_text ) ) {
			return $this->create_result( 'general', 0.0, 'Empty message' );
		}

		// Try rule-based classification first (faster, cheaper)
		$rule_based = $this->classify_by_rules( $message_text );
		if ( $rule_based['confidence'] >= 0.8 ) {
			return $rule_based;
		}

		// Use AI classification for better accuracy
		$ai_result = $this->classify_with_ai( $message_text, $context );

		// Combine results if rule-based had some confidence
		if ( $rule_based['confidence'] > 0.3 ) {
			return $this->combine_classifications( $rule_based, $ai_result );
		}

		return $ai_result;
	}

	/**
	 * Classify intent using rule-based patterns.
	 *
	 * @since 1.0.0
	 * @param string $message_text User message text.
	 * @return array Classification result.
	 */
	private function classify_by_rules( $message_text ) {
		$message_lower = strtolower( $message_text );
		$scores = array();

		// Greeting patterns
		if ( preg_match( '/\b(hello|hi|hey|greetings|good\s+(morning|afternoon|evening)|howdy)\b/i', $message_lower ) ) {
			$scores['greeting'] = 0.9;
		}

		// Pricing patterns
		if ( preg_match( '/\b(price|cost|pricing|how\s+much|pricing|plan|subscription|fee|charge)\b/i', $message_lower ) ) {
			$scores['pricing'] = 0.85;
		}

		// Meeting request patterns
		if ( preg_match( '/\b(schedule|book|meeting|call|demo|consultation|appointment|talk|speak|discuss)\b/i', $message_lower ) ) {
			$scores['meeting_request'] = 0.85;
		}

		// Technical question patterns
		if ( preg_match( '/\b(how\s+does|technical|api|integration|implementation|documentation|specification|code|develop)\b/i', $message_lower ) ) {
			$scores['technical_question'] = 0.8;
		}

		// Feature comparison patterns
		if ( preg_match( '/\b(compare|difference|vs|versus|better|which\s+(one|plan|option))\b/i', $message_lower ) ) {
			$scores['feature_comparison'] = 0.8;
		}

		// Complaint patterns
		if ( preg_match( '/\b(problem|issue|error|bug|broken|not\s+working|complaint|wrong|help\s+with)\b/i', $message_lower ) ) {
			$scores['complaint'] = 0.85;
		}

		// Farewell patterns
		if ( preg_match( '/\b(bye|goodbye|thanks|thank\s+you|see\s+you|later|farewell)\b/i', $message_lower ) ) {
			$scores['farewell'] = 0.9;
		}

		if ( empty( $scores ) ) {
			return $this->create_result( 'general', 0.3, 'No rule matches found' );
		}

		// Get highest scoring intent
		$intent = array_search( max( $scores ), $scores );
		$confidence = max( $scores );

		return $this->create_result( $intent, $confidence, 'Rule-based classification' );
	}

	/**
	 * Classify intent using AI.
	 *
	 * @since 1.0.0
	 * @param string $message_text User message text.
	 * @param array  $context      Optional. Additional context.
	 * @return array Classification result.
	 */
	private function classify_with_ai( $message_text, $context = array() ) {
		$provider = $this->provider_factory->get_provider();
		if ( is_wp_error( $provider ) ) {
			$this->logger->warning(
				'Failed to get provider for intent classification',
				array( 'error' => $provider->get_error_message() )
			);
			return $this->create_result( 'general', 0.5, 'AI classification failed, using fallback' );
		}

		// Build classification prompt
		$prompt = $this->build_classification_prompt( $message_text, $context );

		// Use a faster, cheaper model for classification
		$model = $this->config->get( 'intent_classification_model', 'gpt-3.5-turbo' );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $prompt,
			),
			array(
				'role'    => 'user',
				'content' => $message_text,
			),
		);

		$response = $provider->chat_completion( $messages, array(
			'model'       => $model,
			'temperature' => 0.3, // Lower temperature for more consistent classification
			'max_tokens'  => 200,
		) );

		if ( is_wp_error( $response ) ) {
			$this->logger->warning(
				'AI intent classification failed',
				array( 'error' => $response->get_error_message() )
			);
			return $this->create_result( 'general', 0.5, 'AI classification failed' );
		}

		// Parse response
		return $this->parse_classification_response( $response );
	}

	/**
	 * Build classification prompt.
	 *
	 * @since 1.0.0
	 * @param string $message_text User message.
	 * @param array  $context      Optional. Additional context.
	 * @return string Classification prompt.
	 */
	private function build_classification_prompt( $message_text, $context = array() ) {
		$intent_descriptions = array();
		foreach ( $this->intent_types as $intent => $info ) {
			$intent_descriptions[] = sprintf(
				'- %s: %s',
				$intent,
				$info['description']
			);
		}

		$prompt = "You are an intent classifier for a customer service chatbot. Classify the user's message into one of the following intent categories:\n\n";
		$prompt .= implode( "\n", $intent_descriptions );
		$prompt .= "\n\nRespond with ONLY a JSON object in this exact format:\n";
		$prompt .= '{"intent": "intent_name", "confidence": 0.0-1.0, "reasoning": "brief explanation"}\n\n';
		$prompt .= "Be accurate and confident. If the message doesn't clearly fit any category, use 'general' with lower confidence.";

		return $prompt;
	}

	/**
	 * Parse AI classification response.
	 *
	 * @since 1.0.0
	 * @param array $response Provider response.
	 * @return array Classification result.
	 */
	private function parse_classification_response( $response ) {
		$content = '';
		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			$content = $response['choices'][0]['message']['content'];
		} elseif ( isset( $response['content'] ) ) {
			$content = $response['content'];
		}

		if ( empty( $content ) ) {
			return $this->create_result( 'general', 0.5, 'Empty response from AI' );
		}

		// Try to extract JSON from response
		$json_match = array();
		if ( preg_match( '/\{[^}]+\}/', $content, $json_match ) ) {
			$data = json_decode( $json_match[0], true );
			if ( $data && isset( $data['intent'] ) ) {
				$intent = sanitize_text_field( $data['intent'] );
				$confidence = isset( $data['confidence'] ) ? floatval( $data['confidence'] ) : 0.7;
				$reasoning = isset( $data['reasoning'] ) ? sanitize_text_field( $data['reasoning'] ) : '';

				// Validate intent
				if ( ! isset( $this->intent_types[ $intent ] ) ) {
					$intent = 'general';
					$confidence = 0.5;
				}

				return $this->create_result( $intent, $confidence, $reasoning );
			}
		}

		// Fallback: try to find intent keyword in response
		$content_lower = strtolower( $content );
		foreach ( array_keys( $this->intent_types ) as $intent ) {
			if ( strpos( $content_lower, $intent ) !== false ) {
				return $this->create_result( $intent, 0.7, 'Extracted from AI response' );
			}
		}

		return $this->create_result( 'general', 0.5, 'Could not parse AI response' );
	}

	/**
	 * Combine multiple classification results.
	 *
	 * @since 1.0.0
	 * @param array $result1 First classification result.
	 * @param array $result2 Second classification result.
	 * @return array Combined result.
	 */
	private function combine_classifications( $result1, $result2 ) {
		// If both agree, increase confidence
		if ( $result1['intent'] === $result2['intent'] ) {
			$combined_confidence = min( 1.0, ( $result1['confidence'] + $result2['confidence'] ) / 2 + 0.1 );
			return $this->create_result(
				$result1['intent'],
				$combined_confidence,
				'Combined: ' . $result1['method'] . ' + ' . $result2['method']
			);
		}

		// If they disagree, use the one with higher confidence
		if ( $result1['confidence'] > $result2['confidence'] ) {
			return $result1;
		}

		return $result2;
	}

	/**
	 * Create classification result.
	 *
	 * @since 1.0.0
	 * @param string $intent     Intent type.
	 * @param float  $confidence Confidence score (0.0 to 1.0).
	 * @param string $method     Classification method.
	 * @return array Classification result.
	 */
	private function create_result( $intent, $confidence, $method = '' ) {
		// Ensure intent is valid
		if ( ! isset( $this->intent_types[ $intent ] ) ) {
			$intent = 'general';
		}

		return array(
			'intent'     => $intent,
			'confidence' => max( 0.0, min( 1.0, floatval( $confidence ) ) ),
			'method'     => $method,
			'label'      => $this->intent_types[ $intent ]['label'],
			'is_confident' => floatval( $confidence ) >= $this->min_confidence,
		);
	}

	/**
	 * Get all available intent types.
	 *
	 * @since 1.0.0
	 * @return array Intent types.
	 */
	public function get_intent_types() {
		return $this->intent_types;
	}

	/**
	 * Get intent type information.
	 *
	 * @since 1.0.0
	 * @param string $intent Intent type.
	 * @return array|false Intent information or false if not found.
	 */
	public function get_intent_info( $intent ) {
		return isset( $this->intent_types[ $intent ] ) ? $this->intent_types[ $intent ] : false;
	}

	/**
	 * Register custom intent type.
	 *
	 * @since 1.0.0
	 * @param string $intent       Intent identifier.
	 * @param string $label        Human-readable label.
	 * @param string $description  Description.
	 * @param array  $examples     Optional. Example phrases.
	 * @return bool True on success, false on failure.
	 */
	public function register_intent( $intent, $label, $description, $examples = array() ) {
		if ( empty( $intent ) || empty( $label ) || empty( $description ) ) {
			return false;
		}

		$this->intent_types[ $intent ] = array(
			'label'       => sanitize_text_field( $label ),
			'description' => sanitize_text_field( $description ),
			'examples'    => array_map( 'sanitize_text_field', $examples ),
		);

		return true;
	}

	/**
	 * Set minimum confidence threshold.
	 *
	 * @since 1.0.0
	 * @param float $threshold Confidence threshold (0.0 to 1.0).
	 */
	public function set_min_confidence( $threshold ) {
		$this->min_confidence = max( 0.0, min( 1.0, floatval( $threshold ) ) );
	}

	/**
	 * Get minimum confidence threshold.
	 *
	 * @since 1.0.0
	 * @return float Confidence threshold.
	 */
	public function get_min_confidence() {
		return $this->min_confidence;
	}

	/**
	 * Classify multiple messages in batch.
	 *
	 * @since 1.0.0
	 * @param array $messages Array of message texts.
	 * @return array Array of classification results.
	 */
	public function classify_batch( $messages ) {
		$results = array();

		foreach ( $messages as $message ) {
			$results[] = $this->classify( $message );
		}

		return $results;
	}

	/**
	 * Get intent statistics from conversation.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array Intent statistics.
	 */
	public function get_conversation_intent_stats( $conversation_id ) {
		global $wpdb;

		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					JSON_EXTRACT(metadata, '$.intent') as intent,
					COUNT(*) as count
				FROM {$messages_table}
				WHERE conversation_id = %d
				AND role = 'user'
				AND metadata IS NOT NULL
				AND JSON_EXTRACT(metadata, '$.intent') IS NOT NULL
				GROUP BY intent
				ORDER BY count DESC",
				$conversation_id
			),
			ARRAY_A
		);

		$stats = array();
		foreach ( $results as $row ) {
			$intent = trim( $row['intent'], '"' );
			$stats[ $intent ] = intval( $row['count'] );
		}

		return $stats;
	}
}

