<?php
/**
 * OpenAI Provider Implementation.
 *
 * Handles interactions with OpenAI API including GPT-4, GPT-3.5, and embedding models.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_OpenAI_Provider implements WP_AI_Chatbot_LeadGen_Pro_Provider_Interface {

	/**
	 * Provider name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $provider_name = 'openai';

	/**
	 * API endpoint base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $api_base_url = 'https://api.openai.com/v1';

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
		'gpt-4-turbo-preview' => array(
			'name'          => 'GPT-4 Turbo',
			'max_tokens'    => 128000,
			'input_cost'    => 0.01,   // per 1K tokens
			'output_cost'   => 0.03,   // per 1K tokens
			'context_window' => 128000,
		),
		'gpt-4' => array(
			'name'          => 'GPT-4',
			'max_tokens'    => 8192,
			'input_cost'    => 0.03,
			'output_cost'   => 0.06,
			'context_window' => 8192,
		),
		'gpt-4o-mini' => array(
			'name'          => 'GPT-4o Mini',
			'max_tokens'    => 128000,
			'input_cost'    => 0.00015,
			'output_cost'   => 0.0006,
			'context_window' => 128000,
		),
		'gpt-3.5-turbo' => array(
			'name'          => 'GPT-3.5 Turbo',
			'max_tokens'    => 16385,
			'input_cost'    => 0.0005,
			'output_cost'   => 0.0015,
			'context_window' => 16385,
		),
	);

	/**
	 * Available embedding models.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $embedding_models = array(
		'text-embedding-3-large' => array(
			'name'       => 'text-embedding-3-large',
			'dimension'  => 3072,
			'cost'       => 0.00013, // per 1K tokens
		),
		'text-embedding-3-small' => array(
			'name'       => 'text-embedding-3-small',
			'dimension'  => 1536,
			'cost'       => 0.00002, // per 1K tokens
		),
		'text-embedding-ada-002' => array(
			'name'       => 'text-embedding-ada-002',
			'dimension'  => 1536,
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
		$this->api_key = $api_key_manager->get_api_key( 'openai' );
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
				__( 'OpenAI provider is not configured. Please set your API key.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$defaults = array(
			'model'       => 'gpt-4-turbo-preview',
			'temperature' => 0.7,
			'max_tokens'  => 2000,
			'top_p'       => 1,
			'frequency_penalty' => 0,
			'presence_penalty'  => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate model
		if ( ! isset( $this->chat_models[ $args['model'] ] ) ) {
			return new WP_Error(
				'invalid_model',
				sprintf( __( 'Model %s is not available.', 'wp-ai-chatbot-leadgen-pro' ), $args['model'] )
			);
		}

		// Prepare request body
		$body = array(
			'model'       => $args['model'],
			'messages'    => $messages,
			'temperature' => floatval( $args['temperature'] ),
			'max_tokens'  => intval( $args['max_tokens'] ),
			'top_p'       => floatval( $args['top_p'] ),
			'frequency_penalty' => floatval( $args['frequency_penalty'] ),
			'presence_penalty'  => floatval( $args['presence_penalty'] ),
		);

		// Make API request
		$response = $this->make_request( 'chat/completions', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse response
		$content = isset( $response['choices'][0]['message']['content'] ) 
			? $response['choices'][0]['message']['content'] 
			: '';

		$usage = isset( $response['usage'] ) ? $response['usage'] : array();

		return array(
			'content' => $content,
			'model'   => $args['model'],
			'usage'   => $usage,
			'finish_reason' => isset( $response['choices'][0]['finish_reason'] ) 
				? $response['choices'][0]['finish_reason'] 
				: 'stop',
		);
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
				__( 'OpenAI provider is not configured. Please set your API key.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Default to text-embedding-3-small if no model specified
		if ( empty( $model ) ) {
			$model = 'text-embedding-3-small';
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

		// Prepare request body
		$body = array(
			'model' => $model,
			'input' => $texts,
		);

		// Make API request
		$response = $this->make_request( 'embeddings', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Extract embeddings
		$embeddings = array();
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			foreach ( $response['data'] as $item ) {
				$embeddings[] = isset( $item['embedding'] ) ? $item['embedding'] : array();
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
			
			$input_cost = ( $input_tokens / 1000 ) * $model_info['input_cost'];
			$output_cost = ( $output_tokens / 1000 ) * $model_info['output_cost'];
			
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
				__( 'OpenAI API key is not set.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Make a simple test request
		$test_messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$response = $this->chat_completion( $test_messages, array( 'model' => 'gpt-3.5-turbo', 'max_tokens' => 5 ) );

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
	 * Make API request to OpenAI.
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
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
		);

		$args = array(
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		);

		// Use retry handler
		$retry_handler = new WP_AI_Chatbot_LeadGen_Pro_Retry_Handler( array(
			'max_retries' => $retries,
		) );

		$callback = WP_AI_Chatbot_LeadGen_Pro_Retry_Handler::create_wp_remote_callback( $url, $args );
		$response = $retry_handler->execute_with_retry( $callback );

		// Handle WP_Error
		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'OpenAI API request failed',
				array(
					'endpoint'     => $endpoint,
					'error_code'   => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				)
			);
			return $response;
		}

		// Handle response array from retry handler
		if ( is_array( $response ) && isset( $response['status_code'] ) ) {
			$status_code = intval( $response['status_code'] );

			// Success
			if ( $status_code >= 200 && $status_code < 300 ) {
				return $response['data'];
			}

			// Error response
			$error_message = isset( $response['error']['message'] )
				? $response['error']['message']
				: sprintf( __( 'API request failed with status %d', 'wp-ai-chatbot-leadgen-pro' ), $status_code );

			$this->logger->error(
				'OpenAI API error',
				array(
					'endpoint'    => $endpoint,
					'status_code' => $status_code,
					'error'       => $error_message,
					'response'    => $response['data'],
				)
			);

			return new WP_Error(
				'api_error',
				$error_message,
				array( 'status' => $status_code )
			);
		}

		// Return response data directly if it's already parsed
		return $response;
	}
}

