<?php
/**
 * Cross-Encoder Re-Ranker.
 *
 * Re-ranks search results using cross-encoder models for improved relevance.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/rag
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Cross_Encoder_Reranker {

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
	 * Default number of results to re-rank.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_rerank_limit = 20;

	/**
	 * Maximum number of results to return after re-ranking.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_output_limit = 10;

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
	 * Re-rank search results using cross-encoder scoring.
	 *
	 * @since 1.0.0
	 * @param string $query   Search query.
	 * @param array  $results Search results to re-rank.
	 * @param array  $args    Optional. Re-ranking arguments.
	 * @return array Re-ranked results.
	 */
	public function rerank( $query, $results, $args = array() ) {
		if ( empty( $query ) || empty( $results ) ) {
			return $results;
		}

		$defaults = array(
			'rerank_limit'   => $this->default_rerank_limit,
			'output_limit'   => $this->default_output_limit,
			'provider'       => null,
			'model'          => null,
			'method'         => 'ai_scoring', // 'ai_scoring', 'heuristic', 'combined'
			'use_cache'      => true,
			'min_score'      => 0.0,
		);

		$args = wp_parse_args( $args, $defaults );

		// Limit number of results to re-rank
		$results_to_rerank = array_slice( $results, 0, $args['rerank_limit'] );

		if ( empty( $results_to_rerank ) ) {
			return $results;
		}

		// Re-rank based on method
		switch ( $args['method'] ) {
			case 'ai_scoring':
				$reranked = $this->rerank_with_ai( $query, $results_to_rerank, $args );
				break;

			case 'heuristic':
				$reranked = $this->rerank_with_heuristic( $query, $results_to_rerank, $args );
				break;

			case 'combined':
				$ai_scores = $this->rerank_with_ai( $query, $results_to_rerank, $args );
				$heuristic_scores = $this->rerank_with_heuristic( $query, $results_to_rerank, $args );
				$reranked = $this->combine_scores( $ai_scores, $heuristic_scores, 0.7, 0.3 );
				break;

			default:
				$reranked = $results_to_rerank;
				break;
		}

		// Apply minimum score threshold
		$reranked = array_filter( $reranked, function( $result ) use ( $args ) {
			return isset( $result['rerank_score'] ) && $result['rerank_score'] >= $args['min_score'];
		} );

		// Sort by re-rank score
		usort( $reranked, function( $a, $b ) {
			$score_a = isset( $a['rerank_score'] ) ? $a['rerank_score'] : 0;
			$score_b = isset( $b['rerank_score'] ) ? $b['rerank_score'] : 0;
			return $score_b <=> $score_a;
		} );

		// Limit output
		$reranked = array_slice( $reranked, 0, $args['output_limit'] );

		// Merge with remaining results that weren't re-ranked
		$remaining = array_slice( $results, $args['rerank_limit'] );
		$final_results = array_merge( $reranked, $remaining );

		return $final_results;
	}

	/**
	 * Re-rank results using AI model scoring.
	 *
	 * @since 1.0.0
	 * @param string $query   Search query.
	 * @param array  $results Results to re-rank.
	 * @param array  $args    Re-ranking arguments.
	 * @return array Results with rerank_score added.
	 */
	private function rerank_with_ai( $query, $results, $args ) {
		// Get provider
		$provider_name = $args['provider'] ?: $this->config->get( 'ai_provider', 'openai' );
		$provider = $this->provider_factory->get_provider( $provider_name );

		if ( is_wp_error( $provider ) ) {
			$this->logger->warning(
				'Failed to get provider for re-ranking',
				array( 'provider' => $provider_name )
			);
			return $this->add_default_scores( $results );
		}

		// Get model
		$model = $args['model'] ?: $this->config->get( 'default_model', '' );
		if ( empty( $model ) ) {
			$available_models = $provider->get_available_models();
			$model = ! empty( $available_models ) ? $available_models[0] : '';
		}

		if ( empty( $model ) ) {
			return $this->add_default_scores( $results );
		}

		// Score each result
		$scored_results = array();
		foreach ( $results as $index => $result ) {
			$score = $this->score_relevance_ai( $query, $result, $provider, $model, $args );

			$result['rerank_score'] = $score;
			$result['original_rank'] = $index + 1;
			$scored_results[] = $result;
		}

		return $scored_results;
	}

	/**
	 * Score relevance using AI model.
	 *
	 * @since 1.0.0
	 * @param string $query    Search query.
	 * @param array  $result   Search result.
	 * @param object $provider Provider instance.
	 * @param string $model    Model name.
	 * @param array  $args     Arguments.
	 * @return float Relevance score (0-1).
	 */
	private function score_relevance_ai( $query, $result, $provider, $model, $args ) {
		// Check cache
		if ( $args['use_cache'] ) {
			$cache_key = $this->get_cache_key( $query, $result['chunk_id'] );
			$cached = wp_cache_get( $cache_key, 'wp_ai_chatbot_rerank_scores' );
			if ( false !== $cached ) {
				return floatval( $cached );
			}
		}

		// Truncate content if too long (to save tokens)
		$content = $result['content'];
		$max_content_length = 500; // characters
		if ( strlen( $content ) > $max_content_length ) {
			$content = substr( $content, 0, $max_content_length ) . '...';
		}

		// Create scoring prompt
		$prompt = $this->create_scoring_prompt( $query, $content );

		// Get AI score
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a relevance scoring system. Rate how relevant the given document is to the query on a scale of 0.0 to 1.0. Respond with only a decimal number between 0.0 and 1.0, nothing else.',
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$response = $provider->chat_completion( $messages, array(
			'model'       => $model,
			'temperature' => 0.0, // Deterministic scoring
			'max_tokens'  => 10,   // Just need a number
		) );

		$score = 0.5; // Default score

		if ( ! is_wp_error( $response ) && isset( $response['content'] ) ) {
			// Extract numeric score from response
			$content = trim( $response['content'] );
			$numeric_score = filter_var( $content, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );

			if ( false !== $numeric_score ) {
				$score = max( 0.0, min( 1.0, floatval( $numeric_score ) ) );
			}
		}

		// Cache score
		if ( $args['use_cache'] ) {
			wp_cache_set( $cache_key, $score, 'wp_ai_chatbot_rerank_scores', HOUR_IN_SECONDS );
		}

		return $score;
	}

	/**
	 * Create scoring prompt for AI model.
	 *
	 * @since 1.0.0
	 * @param string $query   Search query.
	 * @param string $content Document content.
	 * @return string Scoring prompt.
	 */
	private function create_scoring_prompt( $query, $content ) {
		return sprintf(
			"Query: %s\n\nDocument: %s\n\nRate the relevance of this document to the query on a scale of 0.0 to 1.0, where 1.0 means highly relevant and 0.0 means not relevant at all. Respond with only the decimal number.",
			$query,
			$content
		);
	}

	/**
	 * Re-rank results using heuristic scoring.
	 *
	 * @since 1.0.0
	 * @param string $query   Search query.
	 * @param array  $results Results to re-rank.
	 * @param array  $args    Arguments.
	 * @return array Results with rerank_score added.
	 */
	private function rerank_with_heuristic( $query, $results, $args ) {
		$query_lower = strtolower( $query );
		$query_words = $this->extract_keywords( $query_lower );

		$scored_results = array();
		foreach ( $results as $index => $result ) {
			$score = $this->calculate_heuristic_score( $query_lower, $query_words, $result );

			$result['rerank_score'] = $score;
			$result['original_rank'] = $index + 1;
			$scored_results[] = $result;
		}

		return $scored_results;
	}

	/**
	 * Calculate heuristic relevance score.
	 *
	 * @since 1.0.0
	 * @param string $query_lower Lowercase query.
	 * @param array  $query_words Query keywords.
	 * @param array  $result      Search result.
	 * @return float Heuristic score (0-1).
	 */
	private function calculate_heuristic_score( $query_lower, $query_words, $result ) {
		$content = strtolower( $result['content'] );
		$score = 0.0;

		// Base score from original search
		if ( isset( $result['score'] ) ) {
			$score = floatval( $result['score'] ) * 0.3;
		}

		// Exact phrase match bonus
		if ( strpos( $content, $query_lower ) !== false ) {
			$score += 0.3;
		}

		// Keyword coverage
		$matched_keywords = 0;
		foreach ( $query_words as $word ) {
			if ( strpos( $content, $word ) !== false ) {
				$matched_keywords++;
			}
		}

		if ( ! empty( $query_words ) ) {
			$coverage = $matched_keywords / count( $query_words );
			$score += $coverage * 0.2;
		}

		// Keyword frequency
		$total_occurrences = 0;
		foreach ( $query_words as $word ) {
			$total_occurrences += substr_count( $content, $word );
		}

		$frequency_score = min( 0.2, log( 1 + $total_occurrences ) / 10 );
		$score += $frequency_score;

		// Length normalization (prefer medium-length content)
		$content_length = strlen( $content );
		$optimal_length = 500;
		$length_penalty = 1.0 - min( 0.1, abs( $content_length - $optimal_length ) / $optimal_length );
		$score *= $length_penalty;

		return min( 1.0, max( 0.0, $score ) );
	}

	/**
	 * Extract keywords from text.
	 *
	 * @since 1.0.0
	 * @param string $text Text to extract keywords from.
	 * @return array Array of keywords.
	 */
	private function extract_keywords( $text ) {
		$stop_words = array(
			'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have',
			'i', 'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you',
			'do', 'at', 'this', 'but', 'his', 'by', 'from',
		);

		$words = preg_split( '/\s+/', $text );
		$keywords = array_filter( $words, function( $word ) use ( $stop_words ) {
			$word = trim( $word, '.,!?;:"()[]{}' );
			return strlen( $word ) > 2 && ! in_array( $word, $stop_words, true );
		} );

		return array_values( array_unique( $keywords ) );
	}

	/**
	 * Combine AI and heuristic scores.
	 *
	 * @since 1.0.0
	 * @param array $ai_results       Results with AI scores.
	 * @param array $heuristic_results Results with heuristic scores.
	 * @param float $ai_weight        Weight for AI scores.
	 * @param float $heuristic_weight Weight for heuristic scores.
	 * @return array Combined results.
	 */
	private function combine_scores( $ai_results, $heuristic_results, $ai_weight, $heuristic_weight ) {
		// Create lookup by chunk_id
		$heuristic_map = array();
		foreach ( $heuristic_results as $result ) {
			$heuristic_map[ $result['chunk_id'] ] = $result['rerank_score'];
		}

		$combined = array();
		foreach ( $ai_results as $result ) {
			$ai_score = isset( $result['rerank_score'] ) ? $result['rerank_score'] : 0;
			$heuristic_score = isset( $heuristic_map[ $result['chunk_id'] ] ) ? $heuristic_map[ $result['chunk_id'] ] : 0;

			$result['rerank_score'] = ( $ai_score * $ai_weight ) + ( $heuristic_score * $heuristic_weight );
			$combined[] = $result;
		}

		return $combined;
	}

	/**
	 * Add default scores to results (fallback).
	 *
	 * @since 1.0.0
	 * @param array $results Results.
	 * @return array Results with default rerank_score.
	 */
	private function add_default_scores( $results ) {
		foreach ( $results as $index => $result ) {
			$results[ $index ]['rerank_score'] = isset( $result['score'] ) ? floatval( $result['score'] ) : 0.5;
			$results[ $index ]['original_rank'] = $index + 1;
		}
		return $results;
	}

	/**
	 * Get cache key for scoring.
	 *
	 * @since 1.0.0
	 * @param string $query    Query text.
	 * @param int    $chunk_id Chunk ID.
	 * @return string Cache key.
	 */
	private function get_cache_key( $query, $chunk_id ) {
		$hash = hash( 'sha256', $query . '_' . $chunk_id );
		return 'rerank_' . $hash;
	}
}

