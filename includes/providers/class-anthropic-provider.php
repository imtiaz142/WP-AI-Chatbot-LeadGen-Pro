<?php
/**
 * Anthropic Provider Implementation.
 *
 * Handles interactions with Anthropic API including Claude Opus, Sonnet, and Haiku models.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Anthropic_Provider implements WP_AI_Chatbot_LeadGen_Pro_Provider_Interface {

	/**
	 * Provider name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $provider_name = 'anthropic';

	/**
	 * API endpoint base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $api_base_url = 'https://api.anthropic.com/v1';

	/**
	 * API key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $api_key = '';

	/**
	 * API version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $api_version = '2023-06-01';

	/**
	 * Configuration instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Available chat models.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $chat_models = array(
		'claude-opus' => array(
			'name'          => 'Claude Opus',
			'max_tokens'    => 4096,
			'input_cost'    => 0.015,   // per 1K tokens
			'output_cost'   => 0.075,   // per 1K tokens
			'context_window' => 200000,
		),
		'claude-sonnet-4' => array(
			'name'          => 'Claude Sonnet 4',
			'max_tokens'    => 8192,
			'input_cost'    => 0.003,   // per 1K tokens
			'output_cost'   => 0.015,   // per 1K tokens
			'context_window' => 200000,
		),
		'claude-sonnet-3-5' => array(
			'name'          => 'Claude Sonnet 3.5',
			'max_tokens'    => 8192,
			'input_cost'    => 0.003,   // per 1K tokens
			'output_cost'   => 0.015,   // per 1K tokens
			'context_window' => 200000,
		),
		'claude-haiku' => array(
			'name'          => 'Claude Haiku',
			'max_tokens'    => 4096,
			'input_cost'    => 0.00025, // per 1K tokens
			'output_cost'   => 0.00125, // per 1K tokens
			'context_window' => 200000,
		),
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->load_api_key();
	}

	/**
	 * Load API key from configuration.
	 *
	 * @since 1.0.0
	 */
	private function load_api_key() {
		$api_key_manager = new WP_AI_Chatbot_LeadGen_Pro_API_Key_Manager();
		$this->api_key = $api_key_manager->get_api_key( 'anthropic' );
	}

	/**
	 * Get provider name.
	 *
	 * @since 1.0.0
	 * @return string Provider name.
	 */
	public function get_provider_name() {
		return $this->provider_name;
	}

	/**
	 * Get available models for this provider.
	 *
	 * @since 1.0.0
	 * @return array Array of model identifiers.
	 */
	public function get_available_models() {
		return array_keys( $this->chat_models );
	}

	/**
	 * Check if a model is available.
	 *
	 * @since 1.0.0
	 * @param string $model Model identifier.
	 * @return bool True if model is available, false otherwise.
	 */
	public function is_model_available( $model ) {
		return isset( $this->chat_models[ $model ] );
	}

	/**
	 * Generate a chat completion.
	 *
	 * @since 1.0.0
	 * @param array $messages Array of message objects with 'role' and 'content'.
	 * @param array $args     Optional. Additional arguments (model, temperature, max_tokens, etc.).
	 * @return array|WP_Error Response array with 'content', 'model', 'usage', or WP_Error on failure.
	 */
	public function chat_completion( $messages, $args = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'not_configured',
				__( 'Anthropic provider is not configured. Please set your API key.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$defaults = array(
			'model'       => 'claude-sonnet-4',
			'temperature' => 0.7,
			'max_tokens'  => 4096,
			'top_p'       => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate model
		if ( ! isset( $this->chat_models[ $args['model'] ] ) ) {
			return new WP_Error(
				'invalid_model',
				sprintf( __( 'Model %s is not available.', 'wp-ai-chatbot-leadgen-pro' ), $args['model'] )
			);
		}

		// Convert messages format for Anthropic API
		$anthropic_messages = $this->convert_messages_format( $messages );

		// Prepare request body
		$body = array(
			'model'       => $args['model'],
			'messages'    => $anthropic_messages,
			'temperature' => floatval( $args['temperature'] ),
			'max_tokens'  => intval( $args['max_tokens'] ),
			'top_p'       => floatval( $args['top_p'] ),
		);

		// Make API request
		$response = $this->make_request( 'messages', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse response
		$content = isset( $response['content'][0]['text'] ) 
			? $response['content'][0]['text'] 
			: '';

		$usage = isset( $response['usage'] ) ? $response['usage'] : array();

		return array(
			'content' => $content,
			'model'   => $args['model'],
			'usage'   => $usage,
			'finish_reason' => isset( $response['stop_reason'] ) 
				? $response['stop_reason'] 
				: 'stop',
		);
	}

	/**
	 * Convert messages format from OpenAI-style to Anthropic-style.
	 *
	 * @since 1.0.0
	 * @param array $messages Messages in OpenAI format.
	 * @return array Messages in Anthropic format.
	 */
	private function convert_messages_format( $messages ) {
		$anthropic_messages = array();

		foreach ( $messages as $message ) {
			$role = isset( $message['role'] ) ? $message['role'] : 'user';
			$content = isset( $message['content'] ) ? $message['content'] : '';

			// Anthropic uses 'user' and 'assistant' roles
			// Convert 'system' to first user message with special handling
			if ( 'system' === $role ) {
				// Anthropic doesn't have system messages, so we prepend to first user message
				if ( empty( $anthropic_messages ) ) {
					$anthropic_messages[] = array(
						'role'    => 'user',
						'content' => $content,
					);
				} else {
					// Prepend system message to first user message
					$anthropic_messages[0]['content'] = $content . "\n\n" . $anthropic_messages[0]['content'];
				}
			} else {
				$anthropic_messages[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}
		}

		return $anthropic_messages;
	}

	/**
	 * Generate embeddings for text.
	 *
	 * Note: Anthropic doesn't provide embedding models directly.
	 * This method returns an error suggesting to use OpenAI or other providers for embeddings.
	 *
	 * @since 1.0.0
	 * @param string|array $text Text or array of texts to generate embeddings for.
	 * @param string       $model Optional. Embedding model to use (not used for Anthropic).
	 * @return array|WP_Error Array of embeddings or WP_Error on failure.
	 */
	public function generate_embeddings( $text, $model = '' ) {
		return new WP_Error(
			'not_supported',
			__( 'Anthropic does not provide embedding models. Please use OpenAI or another provider for embeddings.', 'wp-ai-chatbot-leadgen-pro' )
		);
	}

	/**
	 * Get model information.
	 *
	 * @since 1.0.0
	 * @param string $model Model identifier.
	 * @return array|WP_Error Model information array or WP_Error if model not found.
	 */
	public function get_model_info( $model ) {
		if ( isset( $this->chat_models[ $model ] ) ) {
			return $this->chat_models[ $model ];
		}

		return new WP_Error(
			'model_not_found',
			sprintf( __( 'Model %s not found.', 'wp-ai-chatbot-leadgen-pro' ), $model )
		);
	}

	/**
	 * Get estimated cost for a request.
	 *
	 * @since 1.0.0
	 * @param string $model   Model identifier.
	 * @param int    $tokens  Number of tokens (input + output).
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( $model, $tokens ) {
		$model_info = $this->get_model_info( $model );
		
		if ( is_wp_error( $model_info ) ) {
			return 0;
		}

		// Estimate 70% input, 30% output
		$input_tokens = intval( $tokens * 0.7 );
		$output_tokens = intval( $tokens * 0.3 );
		
		$input_cost = ( $input_tokens / 1000 ) * $model_info['input_cost'];
		$output_cost = ( $output_tokens / 1000 ) * $model_info['output_cost'];
		
		return $input_cost + $output_cost;
	}

	/**
	 * Get maximum tokens for a model.
	 *
	 * @since 1.0.0
	 * @param string $model Model identifier.
	 * @return int Maximum tokens or 0 if unknown.
	 */
	public function get_max_tokens( $model ) {
		$model_info = $this->get_model_info( $model );
		
		if ( is_wp_error( $model_info ) ) {
			return 0;
		}

		return isset( $model_info['max_tokens'] ) ? intval( $model_info['max_tokens'] ) : 0;
	}

	/**
	 * Check if provider is configured and ready to use.
	 *
	 * @since 1.0.0
	 * @return bool True if configured, false otherwise.
	 */
	public function is_configured() {
		return ! empty( $this->api_key );
	}

	/**
	 * Test provider connection and API key.
	 *
	 * @since 1.0.0
	 * @return bool|WP_Error True if connection successful, WP_Error on failure.
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'not_configured',
				__( 'Anthropic API key is not set.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Make a simple test request
		$test_messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $this->chat_completion( $test_messages, array( 'model' => 'claude-haiku', 'max_tokens' => 5 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Get provider configuration status.
	 *
	 * @since 1.0.0
	 * @return array Configuration status array with 'configured', 'api_key_set', etc.
	 */
	public function get_config_status() {
		return array(
			'configured'  => $this->is_configured(),
			'api_key_set' => ! empty( $this->api_key ),
			'provider'    => $this->provider_name,
			'models'      => count( $this->get_available_models() ),
		);
	}

	/**
	 * Make API request to Anthropic.
	 *
	 * @since 1.0.0
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body.
	 * @param int    $retries  Number of retry attempts.
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	private function make_request( $endpoint, $body, $retries = 3 ) {
		$url = $this->api_base_url . '/' . $endpoint;

		$headers = array(
			'x-api-key'      => $this->api_key,
			'anthropic-version' => $this->api_version,
			'Content-Type'   => 'application/json',
		);

		$args = array(
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		);

		$attempt = 0;
		$last_error = null;

		while ( $attempt < $retries ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				$attempt++;

				// Exponential backoff
				if ( $attempt < $retries ) {
					$delay = pow( 2, $attempt );
					sleep( $delay );
					continue;
				}

				$this->logger->error(
					'Anthropic API request failed',
					array(
						'endpoint' => $endpoint,
						'error'    => $response->get_error_message(),
						'attempt'  => $attempt,
					)
				);

				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$data = json_decode( $response_body, true );

			// Success
			if ( 200 === $status_code ) {
				return $data;
			}

			// Rate limit - retry with backoff
			if ( 429 === $status_code ) {
				$attempt++;
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$delay = $retry_after ? intval( $retry_after ) : pow( 2, $attempt );
				sleep( $delay );
				continue;
			}

			// Other errors
			$error_message = isset( $data['error']['message'] ) 
				? $data['error']['message'] 
				: sprintf( __( 'API request failed with status %d', 'wp-ai-chatbot-leadgen-pro' ), $status_code );

			$this->logger->error(
				'Anthropic API error',
				array(
					'endpoint'     => $endpoint,
					'status_code'  => $status_code,
					'error'        => $error_message,
					'response'     => $data,
				)
			);

			return new WP_Error(
				'api_error',
				$error_message,
				array( 'status' => $status_code )
			);
		}

		return $last_error ? $last_error : new WP_Error( 'max_retries', __( 'Maximum retry attempts reached', 'wp-ai-chatbot-leadgen-pro' ) );
	}
}

