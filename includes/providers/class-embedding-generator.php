<?php
/**
 * Embedding Generator.
 *
 * Unified interface for generating embeddings using multiple AI providers.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Embedding_Generator {

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
	 * Provider factory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Provider_Factory
	 */
	private $provider_factory;

	/**
	 * Default provider for embeddings.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $default_provider = 'openai';

	/**
	 * Default embedding model per provider.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $default_models = array(
		'openai'   => 'text-embedding-3-small',
		'google'   => 'embedding-001',
		'anthropic' => null, // Anthropic doesn't support embeddings
	);

	/**
	 * Maximum text length per embedding request (characters).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_text_length = 8000;

	/**
	 * Maximum batch size for batch processing.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_batch_size = 100;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->provider_factory = WP_AI_Chatbot_LeadGen_Pro_Provider_Factory::get_instance();

		// Get default provider from config
		$config_provider = $this->config->get( 'embedding_provider', '' );
		if ( ! empty( $config_provider ) ) {
			$this->default_provider = $config_provider;
		}
	}

	/**
	 * Generate embeddings for text.
	 *
	 * @since 1.0.0
	 * @param string|array $text    Text or array of texts to generate embeddings for.
	 * @param string       $provider Optional. Provider to use (openai, google). Defaults to configured provider.
	 * @param string       $model   Optional. Model to use. Defaults to provider's default model.
	 * @return array|WP_Error Array of embeddings or WP_Error on failure.
	 */
	public function generate( $text, $provider = null, $model = null ) {
		// Determine provider
		if ( null === $provider ) {
			$provider = $this->default_provider;
		}

		// Check if provider supports embeddings
		if ( ! $this->provider_supports_embeddings( $provider ) ) {
			return new WP_Error(
				'embeddings_not_supported',
				sprintf(
					/* translators: %s: Provider name */
					__( 'Provider %s does not support embeddings.', 'wp-ai-chatbot-leadgen-pro' ),
					$provider
				)
			);
		}

		// Get provider instance
		$provider_instance = $this->provider_factory->get_provider( $provider );
		if ( is_wp_error( $provider_instance ) ) {
			return $provider_instance;
		}

		// Determine model
		if ( null === $model ) {
			$model = $this->get_default_model( $provider );
		}

		// Validate model
		if ( ! $provider_instance->is_model_available( $model ) ) {
			return new WP_Error(
				'invalid_model',
				sprintf(
					/* translators: %1$s: Model name, %2$s: Provider name */
					__( 'Model %1$s is not available for provider %2$s.', 'wp-ai-chatbot-leadgen-pro' ),
					$model,
					$provider
				)
			);
		}

		// Convert single text to array
		$texts = is_array( $text ) ? $text : array( $text );

		// Validate text lengths
		$validation = $this->validate_texts( $texts );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Generate embeddings
		$embeddings = $provider_instance->generate_embeddings( $texts, $model );

		if ( is_wp_error( $embeddings ) ) {
			$this->logger->error(
				'Failed to generate embeddings',
				array(
					'provider' => $provider,
					'model'    => $model,
					'text_count' => count( $texts ),
					'error'    => $embeddings->get_error_message(),
				)
			);
			return $embeddings;
		}

		// Normalize embeddings (ensure consistent format)
		$embeddings = $this->normalize_embeddings( $embeddings );

		// Return single embedding if single text was provided
		if ( ! is_array( $text ) && is_array( $embeddings ) && count( $embeddings ) === 1 ) {
			return $embeddings[0];
		}

		return $embeddings;
	}

	/**
	 * Generate embeddings in batches for large datasets.
	 *
	 * @since 1.0.0
	 * @param array  $texts    Array of texts to generate embeddings for.
	 * @param string $provider Optional. Provider to use.
	 * @param string $model    Optional. Model to use.
	 * @param int    $batch_size Optional. Batch size. Defaults to max_batch_size.
	 * @return array|WP_Error Array of embeddings or WP_Error on failure.
	 */
	public function generate_batch( $texts, $provider = null, $model = null, $batch_size = null ) {
		if ( ! is_array( $texts ) || empty( $texts ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Texts must be a non-empty array.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		if ( null === $batch_size ) {
			$batch_size = $this->max_batch_size;
		}

		$all_embeddings = array();
		$batches = array_chunk( $texts, $batch_size );

		foreach ( $batches as $batch_index => $batch ) {
			$this->logger->info(
				'Processing embedding batch',
				array(
					'batch'     => $batch_index + 1,
					'total_batches' => count( $batches ),
					'batch_size' => count( $batch ),
				)
			);

			$batch_embeddings = $this->generate( $batch, $provider, $model );

			if ( is_wp_error( $batch_embeddings ) ) {
				$this->logger->error(
					'Batch embedding generation failed',
					array(
						'batch'     => $batch_index + 1,
						'error'     => $batch_embeddings->get_error_message(),
					)
				);
				return $batch_embeddings;
			}

			// Ensure batch_embeddings is an array
			if ( ! is_array( $batch_embeddings ) ) {
				$batch_embeddings = array( $batch_embeddings );
			}

			$all_embeddings = array_merge( $all_embeddings, $batch_embeddings );

			// Small delay between batches to avoid rate limiting
			if ( $batch_index < count( $batches ) - 1 ) {
				usleep( 100000 ); // 0.1 second
			}
		}

		return $all_embeddings;
	}

	/**
	 * Generate embedding for a single text with caching.
	 *
	 * @since 1.0.0
	 * @param string $text     Text to generate embedding for.
	 * @param string $provider Optional. Provider to use.
	 * @param string $model    Optional. Model to use.
	 * @return array|WP_Error Embedding vector or WP_Error on failure.
	 */
	public function generate_cached( $text, $provider = null, $model = null ) {
		if ( empty( $text ) ) {
			return new WP_Error(
				'empty_text',
				__( 'Text cannot be empty.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Determine provider and model
		if ( null === $provider ) {
			$provider = $this->default_provider;
		}
		if ( null === $model ) {
			$model = $this->get_default_model( $provider );
		}

		// Generate cache key
		$cache_key = $this->get_cache_key( $text, $provider, $model );

		// Try to get from cache
		$cached = wp_cache_get( $cache_key, 'wp_ai_chatbot_embeddings' );
		if ( false !== $cached ) {
			return $cached;
		}

		// Generate embedding
		$embedding = $this->generate( $text, $provider, $model );

		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		// Cache for 24 hours
		wp_cache_set( $cache_key, $embedding, 'wp_ai_chatbot_embeddings', DAY_IN_SECONDS );

		return $embedding;
	}

	/**
	 * Check if provider supports embeddings.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @return bool True if provider supports embeddings, false otherwise.
	 */
	public function provider_supports_embeddings( $provider ) {
		// Anthropic doesn't support embeddings
		if ( 'anthropic' === $provider ) {
			return false;
		}

		// Check if provider has embedding models
		$provider_instance = $this->provider_factory->get_provider( $provider );
		if ( is_wp_error( $provider_instance ) ) {
			return false;
		}

		$available_models = $provider_instance->get_available_models();
		if ( empty( $available_models ) ) {
			return false;
		}

		// Check if any embedding models are available
		// This is a simple check - in practice, we'd check model types
		return true;
	}

	/**
	 * Get default embedding model for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @return string|null Default model name or null if not available.
	 */
	public function get_default_model( $provider ) {
		if ( isset( $this->default_models[ $provider ] ) ) {
			return $this->default_models[ $provider ];
		}

		// Try to get first available embedding model
		$provider_instance = $this->provider_factory->get_provider( $provider );
		if ( is_wp_error( $provider_instance ) ) {
			return null;
		}

		$available_models = $provider_instance->get_available_models();
		if ( ! empty( $available_models ) ) {
			// Return first model (this could be improved to check model types)
			return $available_models[0];
		}

		return null;
	}

	/**
	 * Get available embedding models for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @return array Array of available embedding models.
	 */
	public function get_available_models( $provider = null ) {
		if ( null === $provider ) {
			$provider = $this->default_provider;
		}

		$provider_instance = $this->provider_factory->get_provider( $provider );
		if ( is_wp_error( $provider_instance ) ) {
			return array();
		}

		$all_models = $provider_instance->get_available_models();
		$embedding_models = array();

		// Filter to only embedding models using provider-specific detection
		// Check if model is in embedding_models array or has 'embedding' in name
		foreach ( $all_models as $model ) {
			$model_info = $provider_instance->get_model_info( $model );
			
			// Check if model info indicates it's an embedding model
			if ( $model_info && ! is_wp_error( $model_info ) ) {
				// Check for dimension field (embedding models have dimension)
				if ( isset( $model_info['dimension'] ) ) {
					$embedding_models[] = $model;
					continue;
				}
			}

			// Fallback: check model name for embedding indicators
			if ( 'openai' === $provider ) {
				if ( strpos( $model, 'embedding' ) !== false || strpos( $model, 'ada-002' ) !== false ) {
					$embedding_models[] = $model;
				}
			} elseif ( 'google' === $provider ) {
				if ( strpos( $model, 'embedding' ) !== false ) {
					$embedding_models[] = $model;
				}
			}
		}

		return array_values( $embedding_models );
	}

	/**
	 * Validate texts before generating embeddings.
	 *
	 * @since 1.0.0
	 * @param array $texts Array of texts to validate.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	private function validate_texts( $texts ) {
		foreach ( $texts as $index => $text ) {
			if ( empty( $text ) || ! is_string( $text ) ) {
				return new WP_Error(
					'invalid_text',
					sprintf(
						/* translators: %d: Text index */
						__( 'Text at index %d is invalid or empty.', 'wp-ai-chatbot-leadgen-pro' ),
						$index
					)
				);
			}

			if ( strlen( $text ) > $this->max_text_length ) {
				return new WP_Error(
					'text_too_long',
					sprintf(
						/* translators: %1$d: Text index, %2$d: Maximum length */
						__( 'Text at index %1$d exceeds maximum length of %2$d characters.', 'wp-ai-chatbot-leadgen-pro' ),
						$index,
						$this->max_text_length
					)
				);
			}
		}

		return true;
	}

	/**
	 * Normalize embeddings to ensure consistent format.
	 *
	 * @since 1.0.0
	 * @param array|string $embeddings Embeddings to normalize.
	 * @return array Normalized embeddings.
	 */
	private function normalize_embeddings( $embeddings ) {
		// If single embedding (not array of arrays), wrap it
		if ( ! is_array( $embeddings ) ) {
			return array( $embeddings );
		}

		// Check if it's an array of embeddings or a single embedding
		if ( ! empty( $embeddings ) && is_array( $embeddings[0] ) ) {
			// Already an array of embeddings
			return $embeddings;
		}

		// Single embedding wrapped in array
		return array( $embeddings );
	}

	/**
	 * Get cache key for text embedding.
	 *
	 * @since 1.0.0
	 * @param string $text     Text to generate cache key for.
	 * @param string $provider Provider name.
	 * @param string $model    Model name.
	 * @return string Cache key.
	 */
	private function get_cache_key( $text, $provider, $model ) {
		$hash = hash( 'sha256', $text . $provider . $model );
		return 'embedding_' . $hash;
	}

	/**
	 * Calculate cosine similarity between two embeddings.
	 *
	 * @since 1.0.0
	 * @param array $embedding1 First embedding vector.
	 * @param array $embedding2 Second embedding vector.
	 * @return float|WP_Error Cosine similarity score (-1 to 1) or WP_Error on failure.
	 */
	public function cosine_similarity( $embedding1, $embedding2 ) {
		if ( ! is_array( $embedding1 ) || ! is_array( $embedding2 ) ) {
			return new WP_Error(
				'invalid_embeddings',
				__( 'Both embeddings must be arrays.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		if ( count( $embedding1 ) !== count( $embedding2 ) ) {
			return new WP_Error(
				'dimension_mismatch',
				__( 'Embeddings must have the same dimension.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Calculate dot product
		$dot_product = 0;
		$magnitude1 = 0;
		$magnitude2 = 0;

		$count = count( $embedding1 );
		for ( $i = 0; $i < $count; $i++ ) {
			$dot_product += $embedding1[ $i ] * $embedding2[ $i ];
			$magnitude1 += $embedding1[ $i ] * $embedding1[ $i ];
			$magnitude2 += $embedding2[ $i ] * $embedding2[ $i ];
		}

		$magnitude1 = sqrt( $magnitude1 );
		$magnitude2 = sqrt( $magnitude2 );

		if ( 0 === $magnitude1 || 0 === $magnitude2 ) {
			return 0;
		}

		return $dot_product / ( $magnitude1 * $magnitude2 );
	}

	/**
	 * Find most similar embeddings using cosine similarity.
	 *
	 * @since 1.0.0
	 * @param array $query_embedding Query embedding vector.
	 * @param array $candidate_embeddings Array of candidate embedding vectors.
	 * @param int   $top_k Optional. Number of top results to return. Default 10.
	 * @return array Array of results with similarity scores, sorted by similarity (descending).
	 */
	public function find_most_similar( $query_embedding, $candidate_embeddings, $top_k = 10 ) {
		if ( ! is_array( $query_embedding ) || ! is_array( $candidate_embeddings ) ) {
			return array();
		}

		$similarities = array();

		foreach ( $candidate_embeddings as $index => $candidate ) {
			$similarity = $this->cosine_similarity( $query_embedding, $candidate );

			if ( ! is_wp_error( $similarity ) ) {
				$similarities[] = array(
					'index'      => $index,
					'similarity' => $similarity,
					'embedding'  => $candidate,
				);
			}
		}

		// Sort by similarity (descending)
		usort( $similarities, function( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		// Return top K
		return array_slice( $similarities, 0, $top_k );
	}
}

