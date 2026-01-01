<?php
/**
 * API Key Manager.
 *
 * Handles secure storage and retrieval of API keys with encryption.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_API_Key_Manager {

	/**
	 * Config instance.
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
	 * Supported providers and their key option names.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $provider_keys = array(
		'openai'   => 'openai_api_key',
		'anthropic' => 'anthropic_api_key',
		'google'   => 'google_api_key',
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
	 * Store API key for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name (openai, anthropic, google).
	 * @param string $api_key  API key to store.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function store_api_key( $provider, $api_key ) {
		// Validate provider
		if ( ! isset( $this->provider_keys[ $provider ] ) ) {
			return new WP_Error(
				'invalid_provider',
				sprintf(
					/* translators: %s: Provider name */
					__( 'Invalid provider: %s', 'wp-ai-chatbot-leadgen-pro' ),
					$provider
				)
			);
		}

		// Validate API key format
		$validation = $this->validate_api_key_format( $provider, $api_key );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Encrypt the API key
		$encrypted_key = $this->encrypt( $api_key );

		if ( false === $encrypted_key ) {
			$this->logger->error(
				'Failed to encrypt API key',
				array(
					'provider' => $provider,
				)
			);
			return new WP_Error(
				'encryption_failed',
				__( 'Failed to encrypt API key. Please try again.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Store encrypted key
		$option_key = $this->provider_keys[ $provider ];
		$result = $this->config->set( $option_key, $encrypted_key );

		if ( false === $result ) {
			$this->logger->error(
				'Failed to store API key',
				array(
					'provider' => $provider,
				)
			);
			return new WP_Error(
				'storage_failed',
				__( 'Failed to store API key. Please try again.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Clear any cached keys
		$this->clear_cache( $provider );

		$this->logger->info(
			'API key stored successfully',
			array(
				'provider' => $provider,
			)
		);

		return true;
	}

	/**
	 * Retrieve API key for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name (openai, anthropic, google).
	 * @return string|false Decrypted API key on success, false on failure.
	 */
	public function get_api_key( $provider ) {
		// Validate provider
		if ( ! isset( $this->provider_keys[ $provider ] ) ) {
			$this->logger->error(
				'Invalid provider requested',
				array(
					'provider' => $provider,
				)
			);
			return false;
		}

		// Check cache first
		$cache_key = $this->get_cache_key( $provider );
		$cached = wp_cache_get( $cache_key, 'wp_ai_chatbot_api_keys' );
		if ( false !== $cached ) {
			return $cached;
		}

		// Get encrypted key from config
		$option_key = $this->provider_keys[ $provider ];
		$encrypted_key = $this->config->get( $option_key, '' );

		if ( empty( $encrypted_key ) ) {
			return false;
		}

		// Decrypt the key
		$decrypted_key = $this->decrypt( $encrypted_key );

		if ( false === $decrypted_key ) {
			$this->logger->error(
				'Failed to decrypt API key',
				array(
					'provider' => $provider,
				)
			);
			return false;
		}

		// Cache the decrypted key (short-lived cache for security)
		wp_cache_set( $cache_key, $decrypted_key, 'wp_ai_chatbot_api_keys', 300 ); // 5 minutes

		return $decrypted_key;
	}

	/**
	 * Check if API key exists for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @return bool True if key exists, false otherwise.
	 */
	public function has_api_key( $provider ) {
		if ( ! isset( $this->provider_keys[ $provider ] ) ) {
			return false;
		}

		$option_key = $this->provider_keys[ $provider ];
		$encrypted_key = $this->config->get( $option_key, '' );

		return ! empty( $encrypted_key );
	}

	/**
	 * Delete API key for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @return bool True on success, false on failure.
	 */
	public function delete_api_key( $provider ) {
		if ( ! isset( $this->provider_keys[ $provider ] ) ) {
			return false;
		}

		$option_key = $this->provider_keys[ $provider ];
		$result = $this->config->set( $option_key, '' );

		// Clear cache
		$this->clear_cache( $provider );

		if ( $result ) {
			$this->logger->info(
				'API key deleted',
				array(
					'provider' => $provider,
				)
			);
		}

		return $result;
	}

	/**
	 * Validate API key format for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $api_key  API key to validate.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_api_key_format( $provider, $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'empty_key',
				__( 'API key cannot be empty.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Basic length validation
		if ( strlen( $api_key ) < 10 ) {
			return new WP_Error(
				'invalid_length',
				__( 'API key appears to be invalid (too short).', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Provider-specific validation
		switch ( $provider ) {
			case 'openai':
				// OpenAI keys typically start with 'sk-'
				if ( ! preg_match( '/^sk-[a-zA-Z0-9]{32,}$/', $api_key ) ) {
					return new WP_Error(
						'invalid_format',
						__( 'OpenAI API key format appears to be invalid. Keys should start with "sk-" followed by alphanumeric characters.', 'wp-ai-chatbot-leadgen-pro' )
					);
				}
				break;

			case 'anthropic':
				// Anthropic keys typically start with 'sk-ant-'
				if ( ! preg_match( '/^sk-ant-[a-zA-Z0-9\-_]{95,}$/', $api_key ) ) {
					return new WP_Error(
						'invalid_format',
						__( 'Anthropic API key format appears to be invalid. Keys should start with "sk-ant-" followed by alphanumeric characters, hyphens, or underscores.', 'wp-ai-chatbot-leadgen-pro' )
					);
				}
				break;

			case 'google':
				// Google API keys are typically longer alphanumeric strings
				if ( strlen( $api_key ) < 30 ) {
					return new WP_Error(
						'invalid_format',
						__( 'Google API key format appears to be invalid. Keys should be at least 30 characters long.', 'wp-ai-chatbot-leadgen-pro' )
					);
				}
				break;
		}

		return true;
	}

	/**
	 * Test API key by making a simple API call.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $api_key  Optional. API key to test. If not provided, retrieves from storage.
	 * @return bool|WP_Error True if key is valid, WP_Error if invalid.
	 */
	public function test_api_key( $provider, $api_key = null ) {
		if ( null === $api_key ) {
			$api_key = $this->get_api_key( $provider );
			if ( false === $api_key ) {
				return new WP_Error(
					'no_key',
					__( 'No API key found for this provider.', 'wp-ai-chatbot-leadgen-pro' )
				);
			}
		}

		// Get provider instance
		$factory = WP_AI_Chatbot_LeadGen_Pro_Provider_Factory::get_instance();
		$provider_instance = $factory->get_provider( $provider );

		if ( is_wp_error( $provider_instance ) ) {
			return $provider_instance;
		}

		// Temporarily set the API key for testing
		$original_key = $provider_instance->api_key ?? null;
		$provider_instance->api_key = $api_key;

		// Test connection
		$result = $provider_instance->test_connection();

		// Restore original key
		if ( null !== $original_key ) {
			$provider_instance->api_key = $original_key;
		}

		return $result;
	}

	/**
	 * Encrypt a string using WordPress salts and AES-256-CBC.
	 *
	 * @since 1.0.0
	 * @param string $plaintext Plaintext to encrypt.
	 * @return string|false Encrypted string on success, false on failure.
	 */
	private function encrypt( $plaintext ) {
		if ( empty( $plaintext ) ) {
			return false;
		}

		// Use WordPress salt for encryption key
		$key = $this->get_encryption_key();

		// Generate initialization vector
		$iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );
		if ( false === $iv_length ) {
			return false;
		}

		$iv = openssl_random_pseudo_bytes( $iv_length );
		if ( false === $iv ) {
			return false;
		}

		// Encrypt
		$encrypted = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return false;
		}

		// Combine IV and encrypted data, then base64 encode
		$combined = $iv . $encrypted;
		return base64_encode( $combined );
	}

	/**
	 * Decrypt a string encrypted with encrypt().
	 *
	 * @since 1.0.0
	 * @param string $ciphertext Encrypted string.
	 * @return string|false Decrypted string on success, false on failure.
	 */
	private function decrypt( $ciphertext ) {
		if ( empty( $ciphertext ) ) {
			return false;
		}

		// Decode from base64
		$combined = base64_decode( $ciphertext, true );
		if ( false === $combined ) {
			return false;
		}

		// Get IV length
		$iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );
		if ( false === $iv_length || strlen( $combined ) < $iv_length ) {
			return false;
		}

		// Extract IV and encrypted data
		$iv = substr( $combined, 0, $iv_length );
		$encrypted = substr( $combined, $iv_length );

		// Get encryption key
		$key = $this->get_encryption_key();

		// Decrypt
		$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $decrypted ) {
			return false;
		}

		return $decrypted;
	}

	/**
	 * Get encryption key derived from WordPress salts.
	 *
	 * @since 1.0.0
	 * @return string Encryption key (32 bytes for AES-256).
	 */
	private function get_encryption_key() {
		// Use a combination of WordPress salts for the encryption key
		// This ensures the key is unique per WordPress installation
		$salt = AUTH_SALT . SECURE_AUTH_SALT . LOGGED_IN_SALT . NONCE_SALT;

		// Derive a 32-byte key using SHA-256
		$key = hash( 'sha256', $salt . 'wp_ai_chatbot_leadgen_pro', true );

		return $key;
	}

	/**
	 * Get cache key for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @return string Cache key.
	 */
	private function get_cache_key( $provider ) {
		return 'api_key_' . $provider;
	}

	/**
	 * Clear cache for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 */
	private function clear_cache( $provider ) {
		$cache_key = $this->get_cache_key( $provider );
		wp_cache_delete( $cache_key, 'wp_ai_chatbot_api_keys' );
	}

	/**
	 * Get all providers with their key status.
	 *
	 * @since 1.0.0
	 * @return array Array of providers with their key status.
	 */
	public function get_all_providers_status() {
		$status = array();

		foreach ( $this->provider_keys as $provider => $option_key ) {
			$has_key = $this->has_api_key( $provider );
			$status[ $provider ] = array(
				'has_key'     => $has_key,
				'is_valid'    => false,
				'last_tested' => null,
			);

			// Test key if it exists
			if ( $has_key ) {
				$test_result = $this->test_api_key( $provider );
				$status[ $provider ]['is_valid'] = ! is_wp_error( $test_result );
				if ( is_wp_error( $test_result ) ) {
					$status[ $provider ]['error'] = $test_result->get_error_message();
				}
			}
		}

		return $status;
	}

	/**
	 * Migrate plain text API keys to encrypted format.
	 *
	 * This method should be called during plugin updates to migrate
	 * any existing plain text keys to encrypted format.
	 *
	 * @since 1.0.0
	 * @return int Number of keys migrated.
	 */
	public function migrate_plain_text_keys() {
		$migrated = 0;

		foreach ( $this->provider_keys as $provider => $option_key ) {
			$current_value = $this->config->get( $option_key, '' );

			// Skip if empty
			if ( empty( $current_value ) ) {
				continue;
			}

			// Check if already encrypted (encrypted values are base64 and longer)
			// This is a simple heuristic - encrypted values are base64 encoded
			// and typically much longer than plain text keys
			$decoded = base64_decode( $current_value, true );
			if ( false !== $decoded && strlen( $current_value ) > 100 ) {
				// Likely already encrypted, try to decrypt to verify
				$test_decrypt = $this->decrypt( $current_value );
				if ( false !== $test_decrypt ) {
					// Already encrypted, skip
					continue;
				}
			}

			// Looks like plain text, encrypt it
			$encrypted = $this->encrypt( $current_value );
			if ( false !== $encrypted ) {
				$this->config->set( $option_key, $encrypted );
				$migrated++;
				$this->logger->info(
					'Migrated plain text API key to encrypted format',
					array(
						'provider' => $provider,
					)
				);
			}
		}

		return $migrated;
	}
}

