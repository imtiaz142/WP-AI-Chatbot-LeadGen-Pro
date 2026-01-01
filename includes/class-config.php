<?php
/**
 * Configuration management class.
 *
 * Handles plugin settings using WordPress options API with multisite support.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Config {

	/**
	 * Option prefix for all plugin options.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $option_prefix = 'wp_ai_chatbot_';

	/**
	 * Whether to use network options (multisite).
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $use_network_options = false;

	/**
	 * Default configuration values.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $defaults = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param bool $use_network Whether to use network options (multisite).
	 */
	public function __construct( $use_network = false ) {
		$this->use_network_options = $use_network && is_multisite();
		$this->load_defaults();
	}

	/**
	 * Load default configuration values.
	 *
	 * @since 1.0.0
	 */
	private function load_defaults() {
		$this->defaults = array(
			// AI Provider Settings
			'ai_provider'                    => 'openai',
			'default_model'                  => 'gpt-4-turbo-preview',
			'cost_optimization_enabled'      => true,
			'fallback_enabled'               => true,
			'openai_api_key'                 => '',
			'anthropic_api_key'              => '',
			'google_api_key'                 => '',

			// Chat Widget Settings
			'widget_enabled'                 => true,
			'widget_position'                => 'bottom-right',
			'widget_theme'                   => 'light',
			'greeting_message'               => 'Hello! How can I help you today?',
			'widget_color_primary'           => '#0073aa',
			'widget_color_secondary'         => '#ffffff',

			// Lead Capture Settings
			'lead_capture_enabled'           => true,
			'lead_capture_trigger'           => 'after_engagement',
			'lead_capture_after_messages'    => 3,
			'require_email'                  => true,
			'require_phone'                  => false,

			// Content Ingestion Settings
			'auto_index_enabled'             => true,
			'index_sitemap'                  => true,
			'index_posts'                    => true,
			'index_pages'                    => true,
			'index_woocommerce'              => false,
			'reindex_interval'               => 'weekly',
			'chunk_size'                     => 1000,
			'chunk_overlap'                  => 200,

			// Lead Scoring Settings
			'lead_scoring_enabled'           => true,
			'behavioral_weight'              => 0.3,
			'intent_weight'                  => 0.4,
			'qualification_weight'           => 0.3,
			'score_threshold_hot'            => 80,
			'score_threshold_warm'           => 60,
			'score_threshold_qualified'      => 40,
			'score_threshold_engaged'        => 20,

			// Privacy & Compliance
			'gdpr_enabled'                   => true,
			'ccpa_enabled'                   => false,
			'data_retention_days'            => 365,
			'pii_detection_enabled'          => true,
			'data_retention_on_deactivation' => 'retain',

			// Performance
			'caching_enabled'                => true,
			'cache_duration'                 => 3600,
			'background_processing'          => true,
			'use_redis'                      => false,
			'use_memcached'                  => false,

			// Rate Limiting
			'rate_limit_enabled'             => true,
			'rate_limit_per_minute'          => 10,
			'rate_limit_per_hour'            => 100,
			'rate_limit_per_day'             => 1000,

			// Integrations
			'crm_provider'                   => '',
			'email_provider'                 => '',
			'scheduling_provider'            => '',
			'webhook_enabled'                => false,

			// Analytics
			'analytics_enabled'              => true,
			'track_conversions'              => true,
			'track_sentiment'                => true,

			// Debug & Logging
			'debug_logging_enabled'          => false,
			'debug_log_level'                => 'error',
		);
	}

	/**
	 * Get option name with prefix.
	 *
	 * @since 1.0.0
	 * @param string $key Option key without prefix.
	 * @return string Full option name.
	 */
	private function get_option_name( $key ) {
		return $this->option_prefix . $key;
	}

	/**
	 * Get a configuration value.
	 *
	 * @since 1.0.0
	 * @param string $key     Option key.
	 * @param mixed  $default Default value if option doesn't exist.
	 * @return mixed Option value or default.
	 */
	public function get( $key, $default = null ) {
		$option_name = $this->get_option_name( $key );

		if ( $this->use_network_options ) {
			$value = get_site_option( $option_name, null );
		} else {
			$value = get_option( $option_name, null );
		}

		// Return default if value is null and default is provided
		if ( null === $value && null !== $default ) {
			return $default;
		}

		// Return default from defaults array if value is null
		if ( null === $value && isset( $this->defaults[ $key ] ) ) {
			return $this->defaults[ $key ];
		}

		return $value;
	}

	/**
	 * Set a configuration value.
	 *
	 * @since 1.0.0
	 * @param string $key   Option key.
	 * @param mixed  $value Option value.
	 * @return bool True on success, false on failure.
	 */
	public function set( $key, $value ) {
		$option_name = $this->get_option_name( $key );

		// Sanitize value based on type
		$value = $this->sanitize_value( $key, $value );

		if ( $this->use_network_options ) {
			return update_site_option( $option_name, $value );
		} else {
			return update_option( $option_name, $value );
		}
	}

	/**
	 * Update multiple configuration values at once.
	 *
	 * @since 1.0.0
	 * @param array $options Array of key => value pairs.
	 * @return bool True on success, false on failure.
	 */
	public function set_multiple( $options ) {
		$success = true;

		foreach ( $options as $key => $value ) {
			if ( ! $this->set( $key, $value ) ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Delete a configuration value.
	 *
	 * @since 1.0.0
	 * @param string $key Option key.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $key ) {
		$option_name = $this->get_option_name( $key );

		if ( $this->use_network_options ) {
			return delete_site_option( $option_name );
		} else {
			return delete_option( $option_name );
		}
	}

	/**
	 * Get all configuration values.
	 *
	 * @since 1.0.0
	 * @return array Array of all configuration values.
	 */
	public function get_all() {
		$config = array();

		foreach ( array_keys( $this->defaults ) as $key ) {
			$config[ $key ] = $this->get( $key );
		}

		return $config;
	}

	/**
	 * Reset configuration to defaults.
	 *
	 * @since 1.0.0
	 * @return bool True on success, false on failure.
	 */
	public function reset_to_defaults() {
		return $this->set_multiple( $this->defaults );
	}

	/**
	 * Get default value for a key.
	 *
	 * @since 1.0.0
	 * @param string $key Option key.
	 * @return mixed Default value or null if not found.
	 */
	public function get_default( $key ) {
		return isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : null;
	}

	/**
	 * Get all default values.
	 *
	 * @since 1.0.0
	 * @return array Array of default values.
	 */
	public function get_defaults() {
		return $this->defaults;
	}

	/**
	 * Check if an option exists.
	 *
	 * @since 1.0.0
	 * @param string $key Option key.
	 * @return bool True if option exists, false otherwise.
	 */
	public function has( $key ) {
		$option_name = $this->get_option_name( $key );

		if ( $this->use_network_options ) {
			return false !== get_site_option( $option_name, false );
		} else {
			return false !== get_option( $option_name, false );
		}
	}

	/**
	 * Sanitize configuration value based on key and type.
	 *
	 * @since 1.0.0
	 * @param string $key   Option key.
	 * @param mixed  $value Value to sanitize.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_value( $key, $value ) {
		// Sanitize API keys (remove whitespace)
		if ( strpos( $key, 'api_key' ) !== false ) {
			return sanitize_text_field( trim( $value ) );
		}

		// Sanitize boolean values
		if ( is_bool( $value ) || in_array( $key, array( 'widget_enabled', 'lead_capture_enabled', 'caching_enabled' ), true ) ) {
			return (bool) $value;
		}

		// Sanitize integer values
		if ( is_numeric( $value ) && strpos( $key, 'weight' ) === false && strpos( $key, 'threshold' ) === false ) {
			return absint( $value );
		}

		// Sanitize float values (weights, thresholds)
		if ( is_numeric( $value ) && ( strpos( $key, 'weight' ) !== false || strpos( $key, 'threshold' ) !== false ) ) {
			return floatval( $value );
		}

		// Sanitize text fields
		if ( is_string( $value ) ) {
			// Allow HTML in certain fields
			if ( in_array( $key, array( 'greeting_message' ), true ) ) {
				return wp_kses_post( $value );
			}
			return sanitize_text_field( $value );
		}

		// Sanitize arrays
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}

		return $value;
	}

	/**
	 * Validate configuration value.
	 *
	 * @since 1.0.0
	 * @param string $key   Option key.
	 * @param mixed  $value Value to validate.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate( $key, $value ) {
		// Validate weights sum to 1.0
		if ( in_array( $key, array( 'behavioral_weight', 'intent_weight', 'qualification_weight' ), true ) ) {
			$weights = array(
				'behavioral_weight'     => $key === 'behavioral_weight' ? $value : $this->get( 'behavioral_weight' ),
				'intent_weight'         => $key === 'intent_weight' ? $value : $this->get( 'intent_weight' ),
				'qualification_weight'  => $key === 'qualification_weight' ? $value : $this->get( 'qualification_weight' ),
			);

			$sum = array_sum( $weights );
			if ( abs( $sum - 1.0 ) > 0.01 ) {
				return new WP_Error(
					'invalid_weights',
					__( 'Lead scoring weights must sum to 1.0', 'wp-ai-chatbot-leadgen-pro' )
				);
			}
		}

		// Validate numeric ranges
		if ( strpos( $key, 'threshold' ) !== false ) {
			if ( $value < 0 || $value > 100 ) {
				return new WP_Error(
					'invalid_threshold',
					__( 'Threshold values must be between 0 and 100', 'wp-ai-chatbot-leadgen-pro' )
				);
			}
		}

		// Validate rate limits
		if ( strpos( $key, 'rate_limit' ) !== false ) {
			if ( $value < 0 ) {
				return new WP_Error(
					'invalid_rate_limit',
					__( 'Rate limit values must be positive', 'wp-ai-chatbot-leadgen-pro' )
				);
			}
		}

		return true;
	}

	/**
	 * Get site-specific configuration instance.
	 *
	 * @since 1.0.0
	 * @return WP_AI_Chatbot_LeadGen_Pro_Config Site-specific config instance.
	 */
	public static function get_site_config() {
		return new self( false );
	}

	/**
	 * Get network-wide configuration instance (multisite).
	 *
	 * @since 1.0.0
	 * @return WP_AI_Chatbot_LeadGen_Pro_Config Network config instance.
	 */
	public static function get_network_config() {
		return new self( true );
	}
}

