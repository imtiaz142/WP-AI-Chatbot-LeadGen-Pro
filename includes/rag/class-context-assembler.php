<?php
/**
 * Context Assembler.
 *
 * Manages token limits and assembles optimal context windows for RAG responses.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/rag
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Context_Assembler {

	/**
	 * Provider factory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Provider_Factory
	 */
	private $provider_factory;

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
	 * Average characters per token (approximation).
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private $chars_per_token = 4.0;

	/**
	 * Default token reservation for system prompt.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_system_tokens = 200;

	/**
	 * Default token reservation for response.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_response_tokens = 1000;

	/**
	 * Default token reservation for formatting/overhead.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_overhead_tokens = 100;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->provider_factory = WP_AI_Chatbot_LeadGen_Pro_Provider_Factory::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Assemble context window from retrieved chunks.
	 *
	 * @since 1.0.0
	 * @param string $query            User query.
	 * @param array  $retrieved_chunks Retrieved content chunks.
	 * @param array  $conversation     Optional. Conversation history messages.
	 * @param string $model            Optional. Model identifier.
	 * @param array  $args             Optional. Assembly arguments.
	 * @return array Assembled context with 'context_text', 'chunks_used', 'tokens_used', 'tokens_available'.
	 */
	public function assemble_context( $query, $retrieved_chunks, $conversation = array(), $model = '', $args = array() ) {
		$defaults = array(
			'max_chunks'           => 10,
			'chunk_separator'      => "\n\n---\n\n",
			'include_metadata'     => true,
			'include_citations'    => true,
			'reserve_system_tokens' => null,
			'reserve_response_tokens' => null,
			'reserve_overhead_tokens' => null,
			'strategy'             => 'greedy', // 'greedy', 'balanced', 'quality'
			'min_chunk_score'      => 0.0,
		);

		$args = wp_parse_args( $args, $defaults );

		// Get model info
		$provider_name = $this->config->get( 'ai_provider', 'openai' );
		$provider = $this->provider_factory->get_provider( $provider_name );

		if ( is_wp_error( $provider ) ) {
			$this->logger->error(
				'Failed to get provider for context assembly',
				array( 'provider' => $provider_name )
			);
			return $this->create_empty_context();
		}

		// Get model if not provided
		if ( empty( $model ) ) {
			$model = $this->config->get( 'default_model', '' );
		}

		if ( empty( $model ) ) {
			$available_models = $provider->get_available_models();
			$model = ! empty( $available_models ) ? $available_models[0] : '';
		}

		// Get model context window
		$model_info = $provider->get_model_info( $model );
		if ( is_wp_error( $model_info ) ) {
			$this->logger->warning(
				'Could not get model info, using defaults',
				array( 'model' => $model )
			);
			$context_window = 8192; // Safe default
		} else {
			$context_window = isset( $model_info['context_window'] ) 
				? intval( $model_info['context_window'] ) 
				: ( isset( $model_info['max_tokens'] ) ? intval( $model_info['max_tokens'] ) : 8192 );
		}

		// Calculate token reservations
		$system_tokens = $args['reserve_system_tokens'] !== null 
			? intval( $args['reserve_system_tokens'] ) 
			: $this->default_system_tokens;

		$response_tokens = $args['reserve_response_tokens'] !== null 
			? intval( $args['reserve_response_tokens'] ) 
			: $this->default_response_tokens;

		$overhead_tokens = $args['reserve_overhead_tokens'] !== null 
			? intval( $args['reserve_overhead_tokens'] ) 
			: $this->default_overhead_tokens;

		// Calculate available tokens for context
		$query_tokens = $this->estimate_tokens( $query );
		$conversation_tokens = $this->estimate_conversation_tokens( $conversation );

		$reserved_tokens = $system_tokens + $response_tokens + $overhead_tokens + $query_tokens + $conversation_tokens;
		$available_tokens = max( 0, $context_window - $reserved_tokens );

		// Filter chunks by minimum score
		$filtered_chunks = array_filter( $retrieved_chunks, function( $chunk ) use ( $args ) {
			$score = isset( $chunk['score'] ) ? floatval( $chunk['score'] ) : 0;
			$rerank_score = isset( $chunk['rerank_score'] ) ? floatval( $chunk['rerank_score'] ) : $score;
			return $rerank_score >= $args['min_chunk_score'];
		} );

		// Select chunks based on strategy
		$selected_chunks = $this->select_chunks( $filtered_chunks, $available_tokens, $args );

		// Format context
		$context_text = $this->format_context( $selected_chunks, $args );

		// Calculate actual tokens used
		$context_tokens = $this->estimate_tokens( $context_text );
		$total_tokens = $reserved_tokens + $context_tokens;

		return array(
			'context_text'     => $context_text,
			'chunks_used'      => count( $selected_chunks ),
			'chunks_total'     => count( $retrieved_chunks ),
			'tokens_used'      => $context_tokens,
			'tokens_available' => $available_tokens,
			'tokens_total'     => $total_tokens,
			'context_window'   => $context_window,
			'chunk_metadata'   => $this->extract_chunk_metadata( $selected_chunks ),
		);
	}

	/**
	 * Select chunks based on strategy and token budget.
	 *
	 * @since 1.0.0
	 * @param array $chunks          Available chunks.
	 * @param int   $available_tokens Token budget.
	 * @param array $args            Arguments.
	 * @return array Selected chunks.
	 */
	private function select_chunks( $chunks, $available_tokens, $args ) {
		if ( empty( $chunks ) || $available_tokens <= 0 ) {
			return array();
		}

		// Sort chunks by relevance score
		usort( $chunks, function( $a, $b ) {
			$score_a = isset( $a['rerank_score'] ) ? floatval( $a['rerank_score'] ) : ( isset( $a['score'] ) ? floatval( $a['score'] ) : 0 );
			$score_b = isset( $b['rerank_score'] ) ? floatval( $b['rerank_score'] ) : ( isset( $b['score'] ) ? floatval( $b['score'] ) : 0 );
			return $score_b <=> $score_a;
		} );

		$selected = array();
		$tokens_used = 0;
		$max_chunks = intval( $args['max_chunks'] );

		switch ( $args['strategy'] ) {
			case 'greedy':
				// Greedy: take highest scoring chunks until budget is exhausted
				foreach ( $chunks as $chunk ) {
					if ( count( $selected ) >= $max_chunks ) {
						break;
					}

					$chunk_tokens = $this->estimate_chunk_tokens( $chunk );
					
					if ( $tokens_used + $chunk_tokens <= $available_tokens ) {
						$selected[] = $chunk;
						$tokens_used += $chunk_tokens;
					}
				}
				break;

			case 'balanced':
				// Balanced: try to include diverse chunks while respecting budget
				$chunks_by_source = array();
				foreach ( $chunks as $chunk ) {
					$source = isset( $chunk['source_url'] ) ? $chunk['source_url'] : 'unknown';
					if ( ! isset( $chunks_by_source[ $source ] ) ) {
						$chunks_by_source[ $source ] = array();
					}
					$chunks_by_source[ $source ][] = $chunk;
				}

				// Round-robin selection from different sources
				$source_keys = array_keys( $chunks_by_source );
				$source_indices = array_fill( 0, count( $source_keys ), 0 );
				$rounds = 0;
				$max_rounds = $max_chunks * 2;

				while ( count( $selected ) < $max_chunks && $tokens_used < $available_tokens && $rounds < $max_rounds ) {
					$added = false;
					foreach ( $source_keys as $idx => $source ) {
						if ( count( $selected ) >= $max_chunks ) {
							break 2;
						}

						if ( $source_indices[ $idx ] < count( $chunks_by_source[ $source ] ) ) {
							$chunk = $chunks_by_source[ $source ][ $source_indices[ $idx ] ];
							$chunk_tokens = $this->estimate_chunk_tokens( $chunk );

							if ( $tokens_used + $chunk_tokens <= $available_tokens ) {
								$selected[] = $chunk;
								$tokens_used += $chunk_tokens;
								$source_indices[ $idx ]++;
								$added = true;
							}
						}
					}

					if ( ! $added ) {
						break; // Can't fit any more chunks
					}

					$rounds++;
				}
				break;

			case 'quality':
				// Quality: only include chunks above a quality threshold
				$quality_threshold = 0.7;
				foreach ( $chunks as $chunk ) {
					if ( count( $selected ) >= $max_chunks ) {
						break;
					}

					$score = isset( $chunk['rerank_score'] ) ? floatval( $chunk['rerank_score'] ) : ( isset( $chunk['score'] ) ? floatval( $chunk['score'] ) : 0 );
					
					if ( $score < $quality_threshold ) {
						continue;
					}

					$chunk_tokens = $this->estimate_chunk_tokens( $chunk );
					
					if ( $tokens_used + $chunk_tokens <= $available_tokens ) {
						$selected[] = $chunk;
						$tokens_used += $chunk_tokens;
					}
				}
				break;

			default:
				// Default to greedy
				foreach ( array_slice( $chunks, 0, $max_chunks ) as $chunk ) {
					$chunk_tokens = $this->estimate_chunk_tokens( $chunk );
					if ( $tokens_used + $chunk_tokens <= $available_tokens ) {
						$selected[] = $chunk;
						$tokens_used += $chunk_tokens;
					}
				}
				break;
		}

		return $selected;
	}

	/**
	 * Format context text from selected chunks.
	 *
	 * @since 1.0.0
	 * @param array $chunks Selected chunks.
	 * @param array $args   Arguments.
	 * @return string Formatted context text.
	 */
	private function format_context( $chunks, $args ) {
		if ( empty( $chunks ) ) {
			return '';
		}

		$formatted_parts = array();

		foreach ( $chunks as $index => $chunk ) {
			$content = isset( $chunk['content'] ) ? $chunk['content'] : '';

			// Truncate if necessary (shouldn't happen if selection worked correctly, but safety check)
			$max_chunk_tokens = 2000; // Reasonable max per chunk
			$max_chunk_chars = intval( $max_chunk_tokens * $this->chars_per_token );
			if ( strlen( $content ) > $max_chunk_chars ) {
				$content = substr( $content, 0, $max_chunk_chars ) . '...';
			}

			$formatted = $content;

			// Add metadata if requested
			if ( $args['include_metadata'] ) {
				$metadata_parts = array();

				if ( isset( $chunk['source_url'] ) ) {
					$metadata_parts[] = sprintf( 'Source: %s', $chunk['source_url'] );
				}

				if ( isset( $chunk['chunk_index'] ) ) {
					$metadata_parts[] = sprintf( 'Section: %d', intval( $chunk['chunk_index'] ) );
				}

				if ( isset( $chunk['title'] ) ) {
					$metadata_parts[] = sprintf( 'Title: %s', $chunk['title'] );
				}

				if ( ! empty( $metadata_parts ) ) {
					$formatted = '[' . implode( ' | ', $metadata_parts ) . "]\n\n" . $formatted;
				}
			}

			// Add citation marker if requested
			if ( $args['include_citations'] && isset( $chunk['chunk_id'] ) ) {
				$formatted .= sprintf( ' [%d]', intval( $chunk['chunk_id'] ) );
			}

			$formatted_parts[] = $formatted;
		}

		return implode( $args['chunk_separator'], $formatted_parts );
	}

	/**
	 * Estimate tokens in text.
	 *
	 * @since 1.0.0
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	private function estimate_tokens( $text ) {
		if ( empty( $text ) ) {
			return 0;
		}

		// Simple approximation: ~4 characters per token for English
		// This is a rough estimate; actual tokenization varies by model
		return intval( ceil( strlen( $text ) / $this->chars_per_token ) );
	}

	/**
	 * Estimate tokens in a chunk.
	 *
	 * @since 1.0.0
	 * @param array $chunk Chunk data.
	 * @return int Estimated token count.
	 */
	private function estimate_chunk_tokens( $chunk ) {
		$content = isset( $chunk['content'] ) ? $chunk['content'] : '';
		$base_tokens = $this->estimate_tokens( $content );

		// Add overhead for metadata and formatting
		$overhead = 50; // Approximate overhead per chunk

		return $base_tokens + $overhead;
	}

	/**
	 * Estimate tokens in conversation history.
	 *
	 * @since 1.0.0
	 * @param array $conversation Conversation messages.
	 * @return int Estimated token count.
	 */
	private function estimate_conversation_tokens( $conversation ) {
		if ( empty( $conversation ) || ! is_array( $conversation ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $conversation as $message ) {
			if ( isset( $message['content'] ) ) {
				$total += $this->estimate_tokens( $message['content'] );
			}
			// Add overhead for message structure (role, formatting)
			$total += 10;
		}

		return $total;
	}

	/**
	 * Extract metadata from chunks for citation tracking.
	 *
	 * @since 1.0.0
	 * @param array $chunks Selected chunks.
	 * @return array Chunk metadata.
	 */
	private function extract_chunk_metadata( $chunks ) {
		$metadata = array();

		foreach ( $chunks as $chunk ) {
			$meta = array(
				'chunk_id' => isset( $chunk['chunk_id'] ) ? intval( $chunk['chunk_id'] ) : 0,
				'source_url' => isset( $chunk['source_url'] ) ? $chunk['source_url'] : '',
				'title' => isset( $chunk['title'] ) ? $chunk['title'] : '',
				'score' => isset( $chunk['score'] ) ? floatval( $chunk['score'] ) : 0,
				'rerank_score' => isset( $chunk['rerank_score'] ) ? floatval( $chunk['rerank_score'] ) : 0,
			);

			$metadata[] = $meta;
		}

		return $metadata;
	}

	/**
	 * Create empty context response.
	 *
	 * @since 1.0.0
	 * @return array Empty context array.
	 */
	private function create_empty_context() {
		return array(
			'context_text'     => '',
			'chunks_used'      => 0,
			'chunks_total'     => 0,
			'tokens_used'      => 0,
			'tokens_available' => 0,
			'tokens_total'     => 0,
			'context_window'   => 0,
			'chunk_metadata'   => array(),
		);
	}
}

