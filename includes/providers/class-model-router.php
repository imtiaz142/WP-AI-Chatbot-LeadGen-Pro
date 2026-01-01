<?php
/**
 * Model Router.
 *
 * Intelligently routes queries to the most appropriate AI model based on
 * query complexity, cost optimization preferences, and performance requirements.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Model_Router {

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
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Model complexity thresholds.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $complexity_thresholds = array(
		'simple'    => 50,   // Word count threshold for simple queries
		'medium'    => 200,  // Word count threshold for medium queries
		'complex'   => 500,  // Word count threshold for complex queries
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->factory = WP_AI_Chatbot_LeadGen_Pro_Provider_Factory::get_instance();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Route a query to the appropriate model.
	 *
	 * @since 1.0.0
	 * @param string $query User query text.
	 * @param array  $args  Optional. Additional routing arguments.
	 * @return array|WP_Error Array with 'provider', 'model', and 'provider_instance', or WP_Error on failure.
	 */
	public function route( $query, $args = array() ) {
		$defaults = array(
			'force_provider' => null,
			'force_model'    => null,
			'cost_priority'  => null, // 'cost', 'quality', 'balanced'
			'complexity'     => null, // 'simple', 'medium', 'complex', or null for auto-detect
		);

		$args = wp_parse_args( $args, $defaults );

		// Use forced provider/model if specified
		if ( ! empty( $args['force_provider'] ) && ! empty( $args['force_model'] ) ) {
			return $this->get_forced_route( $args['force_provider'], $args['force_model'] );
		}

		// Determine query complexity
		$complexity = $args['complexity'];
		if ( null === $complexity ) {
			$complexity = $this->analyze_complexity( $query );
		}

		// Get cost optimization preference
		$cost_priority = $args['cost_priority'];
		if ( null === $cost_priority ) {
			$cost_priority = $this->config->get( 'cost_optimization_enabled', true ) ? 'cost' : 'quality';
		}

		// Get routing rules
		$routing_rules = $this->get_routing_rules();

		// Find best model based on complexity and cost priority
		$route = $this->select_model( $complexity, $cost_priority, $routing_rules );

		if ( is_wp_error( $route ) ) {
			return $route;
		}

		// Get provider instance
		$provider = $this->factory->get_provider( $route['provider'] );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		// Verify model is available
		if ( ! $provider->is_model_available( $route['model'] ) ) {
			$this->logger->warning(
				'Selected model not available, falling back to default',
				array(
					'provider' => $route['provider'],
					'model'    => $route['model'],
				)
			);
			return $this->get_fallback_route();
		}

		return array(
			'provider'         => $route['provider'],
			'model'            => $route['model'],
			'provider_instance' => $provider,
			'complexity'       => $complexity,
			'cost_priority'    => $cost_priority,
		);
	}

	/**
	 * Analyze query complexity.
	 *
	 * @since 1.0.0
	 * @param string $query User query text.
	 * @return string Complexity level: 'simple', 'medium', or 'complex'.
	 */
	public function analyze_complexity( $query ) {
		// Count words
		$word_count = str_word_count( $query );

		// Count sentences
		$sentence_count = preg_match_all( '/[.!?]+/', $query, $matches );

		// Check for complex indicators
		$complex_indicators = array(
			'explain',
			'analyze',
			'compare',
			'difference',
			'how does',
			'why',
			'what is the relationship',
			'describe',
			'detail',
		);

		$has_complex_indicators = false;
		$query_lower = strtolower( $query );
		foreach ( $complex_indicators as $indicator ) {
			if ( strpos( $query_lower, $indicator ) !== false ) {
				$has_complex_indicators = true;
				break;
			}
		}

		// Check for question marks (questions might need more reasoning)
		$is_question = strpos( $query, '?' ) !== false;

		// Determine complexity
		if ( $word_count <= $this->complexity_thresholds['simple'] && ! $has_complex_indicators && ! $is_question ) {
			return 'simple';
		} elseif ( $word_count <= $this->complexity_thresholds['medium'] && ! $has_complex_indicators ) {
			return 'medium';
		} else {
			return 'complex';
		}
	}

	/**
	 * Get routing rules based on configuration.
	 *
	 * @since 1.0.0
	 * @return array Routing rules array.
	 */
	private function get_routing_rules() {
		// Get configured routing rules or use defaults
		$rules = $this->config->get( 'model_routing_rules', null );

		if ( null !== $rules && is_array( $rules ) ) {
			return $rules;
		}

		// Default routing rules
		return array(
			'simple' => array(
				'cost' => array(
					'provider' => 'openai',
					'model'    => 'gpt-3.5-turbo',
				),
				'balanced' => array(
					'provider' => 'openai',
					'model'    => 'gpt-4o-mini',
				),
				'quality' => array(
					'provider' => 'openai',
					'model'    => 'gpt-4-turbo-preview',
				),
			),
			'medium' => array(
				'cost' => array(
					'provider' => 'openai',
					'model'    => 'gpt-4o-mini',
				),
				'balanced' => array(
					'provider' => 'openai',
					'model'    => 'gpt-4-turbo-preview',
				),
				'quality' => array(
					'provider' => 'anthropic',
					'model'    => 'claude-sonnet-4',
				),
			),
			'complex' => array(
				'cost' => array(
					'provider' => 'openai',
					'model'    => 'gpt-4-turbo-preview',
				),
				'balanced' => array(
					'provider' => 'anthropic',
					'model'    => 'claude-sonnet-4',
				),
				'quality' => array(
					'provider' => 'anthropic',
					'model'    => 'claude-opus',
				),
			),
		);
	}

	/**
	 * Select model based on complexity and cost priority.
	 *
	 * @since 1.0.0
	 * @param string $complexity    Query complexity ('simple', 'medium', 'complex').
	 * @param string $cost_priority Cost priority ('cost', 'balanced', 'quality').
	 * @param array  $routing_rules Routing rules array.
	 * @return array|WP_Error Route array with 'provider' and 'model', or WP_Error on failure.
	 */
	private function select_model( $complexity, $cost_priority, $routing_rules ) {
		// Validate complexity
		if ( ! isset( $routing_rules[ $complexity ] ) ) {
			return new WP_Error(
				'invalid_complexity',
				sprintf( __( 'Invalid complexity level: %s', 'wp-ai-chatbot-leadgen-pro' ), $complexity )
			);
		}

		// Validate cost priority
		if ( ! isset( $routing_rules[ $complexity ][ $cost_priority ] ) ) {
			// Fallback to balanced if priority not found
			$cost_priority = 'balanced';
		}

		$route = $routing_rules[ $complexity ][ $cost_priority ];

		// Verify provider is available
		if ( ! $this->factory->is_provider_available( $route['provider'] ) ) {
			$this->logger->warning(
				'Routed provider not available, using fallback',
				array(
					'provider' => $route['provider'],
					'model'    => $route['model'],
				)
			);
			return $this->get_fallback_route();
		}

		return $route;
	}

	/**
	 * Get forced route (when provider and model are explicitly specified).
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $model    Model name.
	 * @return array|WP_Error Route array or WP_Error on failure.
	 */
	private function get_forced_route( $provider, $model ) {
		// Verify provider is available
		if ( ! $this->factory->is_provider_available( $provider ) ) {
			return new WP_Error(
				'provider_not_available',
				sprintf( __( 'Provider %s is not available.', 'wp-ai-chatbot-leadgen-pro' ), $provider )
			);
		}

		$provider_instance = $this->factory->get_provider( $provider );
		if ( is_wp_error( $provider_instance ) ) {
			return $provider_instance;
		}

		// Verify model is available
		if ( ! $provider_instance->is_model_available( $model ) ) {
			return new WP_Error(
				'model_not_available',
				sprintf( __( 'Model %s is not available for provider %s.', 'wp-ai-chatbot-leadgen-pro' ), $model, $provider )
			);
		}

		return array(
			'provider'         => $provider,
			'model'            => $model,
			'provider_instance' => $provider_instance,
			'complexity'       => null,
			'cost_priority'    => null,
		);
	}

	/**
	 * Get fallback route when primary routing fails.
	 *
	 * @since 1.0.0
	 * @return array|WP_Error Fallback route or WP_Error if no fallback available.
	 */
	private function get_fallback_route() {
		// Try to get default provider
		$default_provider = $this->factory->get_default_provider();
		if ( is_wp_error( $default_provider ) ) {
			// Try any configured provider
			$configured = $this->factory->get_configured_providers();
			if ( empty( $configured ) ) {
				return new WP_Error(
					'no_providers_available',
					__( 'No AI providers are configured.', 'wp-ai-chatbot-leadgen-pro' )
				);
			}
			$default_provider = $this->factory->get_provider( $configured[0] );
		}

		if ( is_wp_error( $default_provider ) ) {
			return $default_provider;
		}

		// Get default model for provider
		$default_model = $this->config->get( 'default_model', 'gpt-3.5-turbo' );
		$available_models = $default_provider->get_available_models();

		// Use default model if available, otherwise use first available model
		if ( in_array( $default_model, $available_models, true ) ) {
			$model = $default_model;
		} else {
			$model = ! empty( $available_models ) ? $available_models[0] : '';
		}

		if ( empty( $model ) ) {
			return new WP_Error(
				'no_models_available',
				__( 'No models are available for the provider.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return array(
			'provider'         => $default_provider->get_provider_name(),
			'model'            => $model,
			'provider_instance' => $default_provider,
			'complexity'       => null,
			'cost_priority'    => null,
		);
	}

	/**
	 * Get estimated cost for a route.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $model    Model name.
	 * @param int    $tokens   Estimated token count.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_route_cost( $provider, $model, $tokens ) {
		$provider_instance = $this->factory->get_provider( $provider );
		if ( is_wp_error( $provider_instance ) ) {
			return 0;
		}

		return $provider_instance->estimate_cost( $model, $tokens );
	}

	/**
	 * Get routing recommendation for a query.
	 *
	 * @since 1.0.0
	 * @param string $query User query text.
	 * @return array Recommendation array with provider, model, complexity, and estimated cost.
	 */
	public function get_recommendation( $query ) {
		$complexity = $this->analyze_complexity( $query );
		$cost_priority = $this->config->get( 'cost_optimization_enabled', true ) ? 'cost' : 'quality';

		$route = $this->route( $query, array(
			'complexity'    => $complexity,
			'cost_priority' => $cost_priority,
		) );

		if ( is_wp_error( $route ) ) {
			return $route;
		}

		// Estimate tokens (rough estimate: 1 token ≈ 0.75 words)
		$word_count = str_word_count( $query );
		$estimated_tokens = intval( $word_count / 0.75 * 2 ); // Estimate input + output

		$estimated_cost = $this->estimate_route_cost(
			$route['provider'],
			$route['model'],
			$estimated_tokens
		);

		return array(
			'provider'        => $route['provider'],
			'model'           => $route['model'],
			'complexity'      => $complexity,
			'cost_priority'   => $cost_priority,
			'estimated_tokens' => $estimated_tokens,
			'estimated_cost'  => $estimated_cost,
		);
	}
}

