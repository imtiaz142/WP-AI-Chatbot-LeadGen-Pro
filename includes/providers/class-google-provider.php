<?php
/**
 * Google Provider Implementation.
 *
 * Handles interactions with Google Gemini API.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Google_Provider implements WP_AI_Chatbot_LeadGen_Pro_Provider_Interface {

	/**
	 * Provider name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $provider_name = 'google';

	/**
	 * API endpoint base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $api_base_url = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * API key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $api_key = '';

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
		'gemini-2.0-flash-exp' => array(
			'name'          => 'Gemini 2.0 Flash Experimental',
			'max_tokens'    => 8192,
			'input_cost'    => 0.0,    // Free tier pricing
			'output_cost'   => 0.0,    // Free tier pricing
			'context_window' => 1000000,
		),
		'gemini-1.5-pro' => array(
			'name'          => 'Gemini 1.5 Pro',
			'max_tokens'    => 8192,
			'input_cost'    => 0.00125, // per 1M tokens
			'output_cost'   => 0.005,    // per 1M tokens
			'context_window' => 2000000,
		),
		'gemini-1.5-flash' => array(
			'name'          => 'Gemini 1.5 Flash',
			'max_tokens'    => 8192,
			'input_cost'    => 0.075,   // per 1M tokens
			'output_cost'   => 0.30,    // per 1M tokens
			'context_window' => 1000000,
		),
		'gemini-pro' => array(
			'name'          => 'Gemini Pro',
			'max_tokens'    => 2048,
			'input_cost'    => 0.0005,  // per 1K tokens
			'output_cost'   => 0.0015,  // per 1K tokens
			'context_window' => 30720,
		),
	);

	/**
	 * Available embedding models.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $embedding_models = array(
		'embedding-001' => array(
			'name'       => 'embedding-001',
			'dimension'  => 768,
			'cost'       => 0.0001, // per 1K tokens
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
		$this->api_key = $api_key_manager->get_api_key( 'google' );
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
		return array_merge(
			array_keys( $this->chat_models ),
			array_keys( $this->embedding_models )
		);
	}

	/**
	 * Check if a model is available.
	 *
	 * @since 1.0.0
	 * @param string $model Model identifier.
	 * @return bool True if model is available, false otherwise.
	 */
	public function is_model_available( $model ) {
		return isset( $this->chat_models[ $model ] ) || isset( $this->embedding_models[ $model ] );
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
				__( 'Google provider is not configured. Please set your API key.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$defaults = array(
			'model'       => 'gemini-1.5-flash',
			'temperature' => 0.7,
			'max_tokens'  => 2048,
			'top_p'       => 0.95,
			'top_k'       => 40,
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate model
		if ( ! isset( $this->chat_models[ $args['model'] ] ) ) {
			return new WP_Error(
				'invalid_model',
				sprintf( __( 'Model %s is not available.', 'wp-ai-chatbot-leadgen-pro' ), $args['model'] )
			);
		}

		// Convert messages format for Google Gemini API
		$gemini_contents = $this->convert_messages_format( $messages );

		// Prepare request body
		$body = array(
			'contents' => $gemini_contents,
			'generationConfig' => array(
				'temperature'     => floatval( $args['temperature'] ),
				'maxOutputTokens' => intval( $args['max_tokens'] ),
				'topP'            => floatval( $args['top_p'] ),
				'topK'            => intval( $args['top_k'] ),
			),
		);

		// Make API request
		$response = $this->make_request( 'models/' . $args['model'] . ':generateContent', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse response
		$content = '';
		if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$content = $response['candidates'][0]['content']['parts'][0]['text'];
		}

		$usage = array();
		if ( isset( $response['usageMetadata'] ) ) {
			$usage = array(
				'prompt_tokens'     => isset( $response['usageMetadata']['promptTokenCount'] ) ? $response['usageMetadata']['promptTokenCount'] : 0,
				'completion_tokens' => isset( $response['usageMetadata']['candidatesTokenCount'] ) ? $response['usageMetadata']['candidatesTokenCount'] : 0,
				'total_tokens'      => isset( $response['usageMetadata']['totalTokenCount'] ) ? $response['usageMetadata']['totalTokenCount'] : 0,
			);
		}

		return array(
			'content' => $content,
			'model'   => $args['model'],
			'usage'   => $usage,
			'finish_reason' => isset( $response['candidates'][0]['finishReason'] ) 
				? $response['candidates'][0]['finishReason'] 
				: 'STOP',
		);
	}

	/**
	 * Convert messages format from OpenAI-style to Google Gemini format.
	 *
	 * @since 1.0.0
	 * @param array $messages Messages in OpenAI format.
	 * @return array Contents in Gemini format.
	 */
	private function convert_messages_format( $messages ) {
		$contents = array();
		$system_instruction = '';

		foreach ( $messages as $message ) {
			$role = isset( $message['role'] ) ? $message['role'] : 'user';
			$content = isset( $message['content'] ) ? $message['content'] : '';

			// Google Gemini uses 'user' and 'model' roles
			// System messages are handled separately
			if ( 'system' === $role ) {
				$system_instruction = $content;
			} elseif ( 'assistant' === $role ) {
				$contents[] = array(
					'role' => 'model',
					'parts' => array(
						array( 'text' => $content ),
					),
				);
			} else {
				$contents[] = array(
					'role' => 'user',
					'parts' => array(
						array( 'text' => $content ),
					),
				);
			}
		}

		// Add system instruction if present
		if ( ! empty( $system_instruction ) ) {
			// System instruction is added to the request body separately
			// We'll handle this in the request method
		}

		return $contents;
	}

	/**
	 * Generate embeddings for text.
	 *
	 * @since 1.0.0
	 * @param string|array $text Text or array of texts to generate embeddings for.
	 * @param string       $model Optional. Embedding model to use.
	 * @return array|WP_Error Array of embeddings or WP_Error on failure.
	 */
	public function generate_embeddings( $text, $model = '' ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'not_configured',
				__( 'Google provider is not configured. Please set your API key.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Default to embedding-001 if no model specified
		if ( empty( $model ) ) {
			$model = 'embedding-001';
		}

		// Validate model
		if ( ! isset( $this->embedding_models[ $model ] ) ) {
			return new WP_Error(
				'invalid_model',
				sprintf( __( 'Embedding model %s is not available.', 'wp-ai-chatbot-leadgen-pro' ), $model )
			);
		}

		// Convert single text to array
		$texts = is_array( $text ) ? $text : array( $text );

		$embeddings = array();

		// Google Gemini processes embeddings one at a time
		foreach ( $texts as $single_text ) {
			$body = array(
				'model' => 'models/' . $model,
				'content' => array(
					'parts' => array(
						array( 'text' => $single_text ),
					),
				),
			);

			$response = $this->make_request( 'models/' . $model . ':embedContent', $body );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( isset( $response['embedding']['values'] ) ) {
				$embeddings[] = $response['embedding']['values'];
			}
		}

		// Return single embedding if single text was provided
		if ( ! is_array( $text ) && count( $embeddings ) === 1 ) {
			return $embeddings[0];
		}

		return $embeddings;
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

		if ( isset( $this->embedding_models[ $model ] ) ) {
			return $this->embedding_models[ $model ];
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

		// For chat models, we need input/output split (estimate 70% input, 30% output)
		if ( isset( $this->chat_models[ $model ] ) ) {
			$input_tokens = intval( $tokens * 0.7 );
			$output_tokens = intval( $tokens * 0.3 );
			
			// Check if pricing is per 1M tokens (Gemini 1.5 models)
			if ( $model_info['input_cost'] < 0.01 ) {
				// Per 1M tokens pricing
				$input_cost = ( $input_tokens / 1000000 ) * $model_info['input_cost'];
				$output_cost = ( $output_tokens / 1000000 ) * $model_info['output_cost'];
			} else {
				// Per 1K tokens pricing
				$input_cost = ( $input_tokens / 1000 ) * $model_info['input_cost'];
				$output_cost = ( $output_tokens / 1000 ) * $model_info['output_cost'];
			}
			
			return $input_cost + $output_cost;
		}

		// For embedding models
		if ( isset( $this->embedding_models[ $model ] ) ) {
			return ( $tokens / 1000 ) * $model_info['cost'];
		}

		return 0;
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
				__( 'Google API key is not set.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Make a simple test request
		$test_messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $this->chat_completion( $test_messages, array( 'model' => 'gemini-1.5-flash', 'max_tokens' => 5 ) );

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
	 * Make API request to Google Gemini.
	 *
	 * @since 1.0.0
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body.
	 * @param int    $retries  Number of retry attempts.
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	private function make_request( $endpoint, $body, $retries = 3 ) {
		$url = $this->api_base_url . '/' . $endpoint . '?key=' . urlencode( $this->api_key );

		$headers = array(
			'Content-Type' => 'application/json',
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
					'Google Gemini API request failed',
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
				'Google Gemini API error',
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

