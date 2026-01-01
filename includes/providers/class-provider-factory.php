<?php
/**
 * Provider Factory.
 *
 * Creates and manages AI provider instances based on configuration.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Provider_Factory {

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
	 * Cached provider instances.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $providers = array();

	/**
	 * Available provider classes.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $provider_classes = array(
		'openai'    => 'WP_AI_Chatbot_LeadGen_Pro_OpenAI_Provider',
		'anthropic' => 'WP_AI_Chatbot_LeadGen_Pro_Anthropic_Provider',
		'google'    => 'WP_AI_Chatbot_LeadGen_Pro_Google_Provider',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Get a provider instance.
	 *
	 * @since 1.0.0
	 * @param string $provider_name Optional. Provider name. If not provided, uses configured provider.
	 * @return WP_AI_Chatbot_LeadGen_Pro_Provider_Interface|WP_Error Provider instance or WP_Error on failure.
	 */
	public function get_provider( $provider_name = null ) {
		// Use configured provider if none specified
		if ( null === $provider_name ) {
			$provider_name = $this->config->get( 'ai_provider', 'openai' );
		}

		// Normalize provider name
		$provider_name = strtolower( $provider_name );

		// Return cached instance if available
		if ( isset( $this->providers[ $provider_name ] ) ) {
			return $this->providers[ $provider_name ];
		}

		// Check if provider class exists
		if ( ! isset( $this->provider_classes[ $provider_name ] ) ) {
			$this->logger->error(
				'Unknown provider requested',
				array( 'provider' => $provider_name )
			);
			return new WP_Error(
				'unknown_provider',
				sprintf( __( 'Provider %s is not available.', 'wp-ai-chatbot-leadgen-pro' ), $provider_name )
			);
		}

		$provider_class = $this->provider_classes[ $provider_name ];

		// Check if class exists
		if ( ! class_exists( $provider_class ) ) {
			$this->logger->error(
				'Provider class not found',
				array(
					'provider'      => $provider_name,
					'provider_class' => $provider_class,
				)
			);
			return new WP_Error(
				'provider_class_not_found',
				sprintf( __( 'Provider class %s not found.', 'wp-ai-chatbot-leadgen-pro' ), $provider_class )
			);
		}

		// Create provider instance
		try {
			$provider = new $provider_class();

			// Verify it implements the interface
			if ( ! $provider instanceof WP_AI_Chatbot_LeadGen_Pro_Provider_Interface ) {
				$this->logger->error(
					'Provider does not implement interface',
					array(
						'provider'      => $provider_name,
						'provider_class' => $provider_class,
					)
				);
				return new WP_Error(
					'invalid_provider',
					sprintf( __( 'Provider %s does not implement the required interface.', 'wp-ai-chatbot-leadgen-pro' ), $provider_name )
				);
			}

			// Cache the instance
			$this->providers[ $provider_name ] = $provider;

			return $provider;

		} catch ( Exception $e ) {
			$this->logger->exception( $e, array( 'provider' => $provider_name ) );
			return new WP_Error(
				'provider_creation_failed',
				sprintf( __( 'Failed to create provider %s: %s', 'wp-ai-chatbot-leadgen-pro' ), $provider_name, $e->getMessage() )
			);
		}
	}

	/**
	 * Get the default/configured provider.
	 *
	 * @since 1.0.0
	 * @return WP_AI_Chatbot_LeadGen_Pro_Provider_Interface|WP_Error Provider instance or WP_Error on failure.
	 */
	public function get_default_provider() {
		return $this->get_provider();
	}

	/**
	 * Get all available providers.
	 *
	 * @since 1.0.0
	 * @return array Array of provider names.
	 */
	public function get_available_providers() {
		return array_keys( $this->provider_classes );
	}

	/**
	 * Check if a provider is available.
	 *
	 * @since 1.0.0
	 * @param string $provider_name Provider name.
	 * @return bool True if available, false otherwise.
	 */
	public function is_provider_available( $provider_name ) {
		$provider_name = strtolower( $provider_name );
		return isset( $this->provider_classes[ $provider_name ] ) && class_exists( $this->provider_classes[ $provider_name ] );
	}

	/**
	 * Get all configured providers (providers with API keys set).
	 *
	 * @since 1.0.0
	 * @return array Array of provider names that are configured.
	 */
	public function get_configured_providers() {
		$configured = array();

		foreach ( $this->provider_classes as $provider_name => $provider_class ) {
			if ( ! class_exists( $provider_class ) ) {
				continue;
			}

			try {
				$provider = new $provider_class();
				if ( $provider instanceof WP_AI_Chatbot_LeadGen_Pro_Provider_Interface && $provider->is_configured() ) {
					$configured[] = $provider_name;
				}
			} catch ( Exception $e ) {
				// Skip providers that can't be instantiated
				continue;
			}
		}

		return $configured;
	}

	/**
	 * Get provider status information.
	 *
	 * @since 1.0.0
	 * @return array Array of provider status information.
	 */
	public function get_providers_status() {
		$status = array();

		foreach ( $this->provider_classes as $provider_name => $provider_class ) {
			if ( ! class_exists( $provider_class ) ) {
				$status[ $provider_name ] = array(
					'available'  => false,
					'configured' => false,
					'error'      => 'Class not found',
				);
				continue;
			}

			try {
				$provider = new $provider_class();
				if ( $provider instanceof WP_AI_Chatbot_LeadGen_Pro_Provider_Interface ) {
					$config_status = $provider->get_config_status();
					$status[ $provider_name ] = array(
						'available'  => true,
						'configured' => $provider->is_configured(),
						'models'     => $provider->get_available_models(),
						'status'     => $config_status,
					);
				} else {
					$status[ $provider_name ] = array(
						'available'  => false,
						'configured' => false,
						'error'      => 'Does not implement interface',
					);
				}
			} catch ( Exception $e ) {
				$status[ $provider_name ] = array(
					'available'  => false,
					'configured' => false,
					'error'      => $e->getMessage(),
				);
			}
		}

		return $status;
	}

	/**
	 * Register a custom provider.
	 *
	 * @since 1.0.0
	 * @param string $provider_name Provider identifier.
	 * @param string $provider_class Provider class name.
	 * @return bool True on success, false on failure.
	 */
	public function register_provider( $provider_name, $provider_class ) {
		if ( ! class_exists( $provider_class ) ) {
			$this->logger->error(
				'Cannot register provider: class not found',
				array(
					'provider'      => $provider_name,
					'provider_class' => $provider_class,
				)
			);
			return false;
		}

		// Verify class implements interface
		$reflection = new ReflectionClass( $provider_class );
		if ( ! $reflection->implementsInterface( 'WP_AI_Chatbot_LeadGen_Pro_Provider_Interface' ) ) {
			$this->logger->error(
				'Cannot register provider: does not implement interface',
				array(
					'provider'      => $provider_name,
					'provider_class' => $provider_class,
				)
			);
			return false;
		}

		$this->provider_classes[ strtolower( $provider_name ) ] = $provider_class;
		return true;
	}

	/**
	 * Clear cached provider instances.
	 *
	 * @since 1.0.0
	 */
	public function clear_cache() {
		$this->providers = array();
	}

	/**
	 * Get factory instance (singleton pattern).
	 *
	 * @since 1.0.0
	 * @return WP_AI_Chatbot_LeadGen_Pro_Provider_Factory Factory instance.
	 */
	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}
}

