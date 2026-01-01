<?php
/**
 * Fallback Manager.
 *
 * Manages fallback chains for AI providers, attempting secondary and tertiary
 * providers when the primary provider fails.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Fallback_Manager {

	/**
	 * Configuration instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Provider factory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Provider_Factory
	 */
	private $factory;

	/**
	 * Model router instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Model_Router
	 */
	private $router;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->factory = WP_AI_Chatbot_LeadGen_Pro_Provider_Factory::get_instance();
		$this->router = new WP_AI_Chatbot_LeadGen_Pro_Model_Router();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Execute a chat completion with fallback support.
	 *
	 * @since 1.0.0
	 * @param array $messages Array of message objects with 'role' and 'content'.
	 * @param array $args     Optional. Additional arguments (model, temperature, max_tokens, etc.).
	 * @return array|WP_Error Response array or WP_Error if all providers fail.
	 */
	public function chat_completion_with_fallback( $messages, $args = array() ) {
		// Get fallback chain
		$fallback_chain = $this->get_fallback_chain( $args );

		if ( empty( $fallback_chain ) ) {
			return new WP_Error(
				'no_fallback_chain',
				__( 'No providers available in fallback chain.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$last_error = null;
		$attempts = array();

		// Try each provider in the fallback chain
		foreach ( $fallback_chain as $index => $route ) {
			$provider = $route['provider_instance'];
			$model = $route['model'];
			$provider_name = $route['provider'];

			$this->logger->info(
				'Attempting provider in fallback chain',
				array(
					'provider' => $provider_name,
					'model'    => $model,
					'attempt'  => $index + 1,
					'total'    => count( $fallback_chain ),
				)
			);

			// Prepare arguments for this provider
			$provider_args = $args;
			$provider_args['model'] = $model;

			// Attempt chat completion
			$start_time = microtime( true );
			$response = $provider->chat_completion( $messages, $provider_args );
			$response_time = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds

			// Track attempt
			$attempts[] = array(
				'provider'      => $provider_name,
				'model'         => $model,
				'success'       => ! is_wp_error( $response ),
				'response_time' => $response_time,
				'error'         => is_wp_error( $response ) ? $response->get_error_message() : null,
			);

			// Success - return response
			if ( ! is_wp_error( $response ) ) {
				// Add fallback metadata to response
				$response['fallback_used'] = $index > 0;
				$response['fallback_attempts'] = $index + 1;
				$response['fallback_chain'] = $attempts;

				$this->logger->info(
					'Chat completion successful with fallback',
					array(
						'provider'        => $provider_name,
						'model'           => $model,
						'attempt'         => $index + 1,
						'fallback_used'   => $index > 0,
						'response_time_ms' => $response_time,
					)
				);

				return $response;
			}

			// Store error for logging
			$last_error = $response;

			$this->logger->warning(
				'Provider attempt failed in fallback chain',
				array(
					'provider'      => $provider_name,
					'model'         => $model,
					'attempt'       => $index + 1,
					'error'         => $response->get_error_message(),
					'error_code'    => $response->get_error_code(),
					'response_time_ms' => $response_time,
				)
			);

			// Check if error is retryable
			if ( ! $this->is_retryable_error( $response ) ) {
				// Non-retryable error (e.g., invalid API key, invalid model)
				// Skip remaining providers in chain
				break;
			}

			// Continue to next provider in chain
		}

		// All providers failed
		$this->logger->error(
			'All providers in fallback chain failed',
			array(
				'attempts'    => $attempts,
				'last_error'  => is_wp_error( $last_error ) ? $last_error->get_error_message() : 'Unknown error',
			)
		);

		return new WP_Error(
			'all_providers_failed',
			__( 'All AI providers in the fallback chain failed. Please check your API keys and provider configurations.', 'wp-ai-chatbot-leadgen-pro' ),
			array(
				'attempts' => $attempts,
				'last_error' => is_wp_error( $last_error ) ? $last_error->get_error_message() : null,
			)
		);
	}

	/**
	 * Get fallback chain based on configuration and routing.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Routing arguments.
	 * @return array Array of route arrays with provider, model, and provider_instance.
	 */
	private function get_fallback_chain( $args = array() ) {
		// Check if fallback is enabled
		if ( ! $this->config->get( 'fallback_enabled', true ) ) {
			// Return only primary route
			$query = isset( $args['query'] ) ? $args['query'] : '';
			$route = $this->router->route( $query, $args );
			if ( is_wp_error( $route ) ) {
				return array();
			}
			return array( $route );
		}

		// Get configured fallback chain
		$fallback_chain_config = $this->config->get( 'fallback_chain', null );

		if ( null !== $fallback_chain_config && is_array( $fallback_chain_config ) ) {
			return $this->build_chain_from_config( $fallback_chain_config );
		}

		// Build default fallback chain
		return $this->build_default_fallback_chain( $args );
	}

	/**
	 * Build fallback chain from configuration.
	 *
	 * @since 1.0.0
	 * @param array $chain_config Fallback chain configuration.
	 * @return array Array of route arrays.
	 */
	private function build_chain_from_config( $chain_config ) {
		$chain = array();

		foreach ( $chain_config as $entry ) {
			if ( ! isset( $entry['provider'] ) || ! isset( $entry['model'] ) ) {
				continue;
			}

			$provider = $this->factory->get_provider( $entry['provider'] );
			if ( is_wp_error( $provider ) ) {
				continue;
			}

			if ( ! $provider->is_model_available( $entry['model'] ) ) {
				continue;
			}

			$chain[] = array(
				'provider'         => $entry['provider'],
				'model'            => $entry['model'],
				'provider_instance' => $provider,
			);
		}

		return $chain;
	}

	/**
	 * Build default fallback chain.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Routing arguments.
	 * @return array Array of route arrays.
	 */
	private function build_default_fallback_chain( $args = array() ) {
		$chain = array();

		// Get primary route
		$query = isset( $args['query'] ) ? $args['query'] : '';
		$primary_route = $this->router->route( $query, $args );

		if ( ! is_wp_error( $primary_route ) ) {
			$chain[] = $primary_route;
		}

		// Get configured providers for fallback
		$configured_providers = $this->factory->get_configured_providers();
		$primary_provider = isset( $primary_route['provider'] ) ? $primary_route['provider'] : null;

		// Add fallback providers (exclude primary)
		foreach ( $configured_providers as $provider_name ) {
			if ( $provider_name === $primary_provider ) {
				continue;
			}

			$provider = $this->factory->get_provider( $provider_name );
			if ( is_wp_error( $provider ) ) {
				continue;
			}

			// Get appropriate model for this provider
			// Use default model or first available model
			$available_models = $provider->get_available_models();
			if ( empty( $available_models ) ) {
				continue;
			}

			// Try to get a suitable model based on query complexity if query is available
			$query = isset( $args['query'] ) ? $args['query'] : '';
			$complexity = ! empty( $query ) ? $this->router->analyze_complexity( $query ) : 'medium';
			$fallback_route = $this->get_fallback_model_for_provider( $provider, $complexity );

			if ( ! is_wp_error( $fallback_route ) ) {
				$chain[] = $fallback_route;
			}
		}

		return $chain;
	}

	/**
	 * Get appropriate fallback model for a provider based on complexity.
	 *
	 * @since 1.0.0
	 * @param WP_AI_Chatbot_LeadGen_Pro_Provider_Interface $provider  Provider instance.
	 * @param string                                        $complexity Query complexity.
	 * @return array|WP_Error Route array or WP_Error on failure.
	 */
	private function get_fallback_model_for_provider( $provider, $complexity ) {
		$available_models = $provider->get_available_models();

		if ( empty( $available_models ) ) {
			return new WP_Error( 'no_models', __( 'No models available for provider.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		// Select model based on complexity
		$provider_name = $provider->get_provider_name();

		// Default model selection logic
		$model_preferences = array(
			'openai' => array(
				'simple'  => 'gpt-3.5-turbo',
				'medium'  => 'gpt-4o-mini',
				'complex' => 'gpt-4-turbo-preview',
			),
			'anthropic' => array(
				'simple'  => 'claude-haiku',
				'medium'  => 'claude-sonnet-4',
				'complex' => 'claude-opus',
			),
			'google' => array(
				'simple'  => 'gemini-1.5-flash',
				'medium'  => 'gemini-1.5-flash',
				'complex' => 'gemini-1.5-pro',
			),
		);

		$preferred_model = null;
		if ( isset( $model_preferences[ $provider_name ][ $complexity ] ) ) {
			$preferred_model = $model_preferences[ $provider_name ][ $complexity ];
		}

		// Use preferred model if available, otherwise use first available
		$selected_model = $preferred_model;
		if ( ! $provider->is_model_available( $selected_model ) ) {
			$selected_model = $available_models[0];
		}

		return array(
			'provider'         => $provider_name,
			'model'            => $selected_model,
			'provider_instance' => $provider,
		);
	}

	/**
	 * Check if an error is retryable (should try next provider).
	 *
	 * @since 1.0.0
	 * @param WP_Error $error Error object.
	 * @return bool True if retryable, false otherwise.
	 */
	private function is_retryable_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$error_code = $error->get_error_code();

		// Non-retryable errors (configuration issues)
		$non_retryable_codes = array(
			'not_configured',
			'invalid_model',
			'unknown_provider',
			'provider_class_not_found',
			'invalid_provider',
		);

		if ( in_array( $error_code, $non_retryable_codes, true ) ) {
			return false;
		}

		// Retryable errors (network issues, rate limits, timeouts)
		$retryable_codes = array(
			'api_error',
			'max_retries',
			'http_request_failed',
			'timeout',
		);

		if ( in_array( $error_code, $retryable_codes, true ) ) {
			return true;
		}

		// Check status code if available
		$error_data = $error->get_error_data();
		if ( isset( $error_data['status'] ) ) {
			$status = intval( $error_data['status'] );

			// 429 (rate limit) and 5xx (server errors) are retryable
			if ( 429 === $status || ( $status >= 500 && $status < 600 ) ) {
				return true;
			}

			// 4xx errors (except 429) are generally not retryable
			if ( $status >= 400 && $status < 500 ) {
				return false;
			}
		}

		// Default to retryable for unknown errors
		return true;
	}

	/**
	 * Get fallback chain status.
	 *
	 * @since 1.0.0
	 * @return array Status information about the fallback chain.
	 */
	public function get_fallback_chain_status() {
		$enabled = $this->config->get( 'fallback_enabled', true );
		$configured_providers = $this->factory->get_configured_providers();

		$chain = array();
		if ( $enabled ) {
			$chain = $this->get_fallback_chain();
		}

		return array(
			'enabled'             => $enabled,
			'configured_providers' => $configured_providers,
			'chain_length'        => count( $chain ),
			'chain'               => array_map(
				function( $route ) {
					return array(
						'provider' => $route['provider'],
						'model'    => $route['model'],
					);
				},
				$chain
			),
		);
	}
}

