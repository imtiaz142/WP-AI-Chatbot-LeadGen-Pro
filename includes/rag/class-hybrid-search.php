<?php
/**
 * Hybrid Search.
 *
 * Combines semantic similarity search with keyword matching for improved retrieval.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/rag
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Hybrid_Search {

	/**
	 * Vector store instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Vector_Store
	 */
	private $vector_store;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Default weight for semantic search (0.0 to 1.0).
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private $default_semantic_weight = 0.7;

	/**
	 * Default weight for keyword search (0.0 to 1.0).
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private $default_keyword_weight = 0.3;

	/**
	 * Minimum keyword match score to include in results.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private $min_keyword_score = 0.1;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->vector_store = new WP_AI_Chatbot_LeadGen_Pro_Vector_Store();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Perform hybrid search combining semantic and keyword matching.
	 *
	 * @since 1.0.0
	 * @param string $query      Search query text.
	 * @param array  $args       Optional. Search arguments.
	 * @return array|WP_Error Array of search results or WP_Error on failure.
	 */
	public function search( $query, $args = array() ) {
		if ( empty( $query ) ) {
			return new WP_Error(
				'empty_query',
				__( 'Search query cannot be empty.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$defaults = array(
			'limit'            => 10,
			'semantic_weight'  => $this->default_semantic_weight,
			'keyword_weight'   => $this->default_keyword_weight,
			'semantic_limit'   => null,  // If null, uses limit * 2
			'keyword_limit'    => null,  // If null, uses limit * 2
			'combine_method'   => 'weighted', // 'weighted', 'reciprocal_rank', 'rrf'
			'rrf_k'            => 60,    // Reciprocal rank fusion constant
			'threshold'        => 0.0,   // Minimum combined score
			'model'            => null,
			'provider'         => null,
			'source_type'      => null,
			'source_id'        => null,
		);

		$args = wp_parse_args( $args, $defaults );

		// Normalize weights
		$total_weight = $args['semantic_weight'] + $args['keyword_weight'];
		if ( $total_weight > 0 ) {
			$args['semantic_weight'] = $args['semantic_weight'] / $total_weight;
			$args['keyword_weight'] = $args['keyword_weight'] / $total_weight;
		}

		// Determine limits for each search type
		if ( null === $args['semantic_limit'] ) {
			$args['semantic_limit'] = $args['limit'] * 2;
		}
		if ( null === $args['keyword_limit'] ) {
			$args['keyword_limit'] = $args['limit'] * 2;
		}

		// Perform semantic search
		$semantic_results = $this->semantic_search( $query, $args );

		// Perform keyword search
		$keyword_results = $this->keyword_search( $query, $args );

		// Combine results
		$combined_results = $this->combine_results(
			$semantic_results,
			$keyword_results,
			$args
		);

		// Apply threshold and limit
		$filtered_results = array_filter( $combined_results, function( $result ) use ( $args ) {
			return $result['score'] >= $args['threshold'];
		} );

		// Sort by combined score (descending)
		usort( $filtered_results, function( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		// Return top results
		return array_slice( $filtered_results, 0, $args['limit'] );
	}

	/**
	 * Perform semantic similarity search.
	 *
	 * @since 1.0.0
	 * @param string $query Search query.
	 * @param array  $args  Search arguments.
	 * @return array Array of semantic search results.
	 */
	private function semantic_search( $query, $args ) {
		$search_args = array(
			'limit'       => $args['semantic_limit'],
			'threshold'   => 0.0, // Don't filter here, let hybrid scoring handle it
			'model'       => $args['model'],
			'source_type' => $args['source_type'],
			'source_id'   => $args['source_id'],
		);

		$results = $this->vector_store->search( $query, $search_args );

		if ( is_wp_error( $results ) ) {
			$this->logger->warning(
				'Semantic search failed',
				array(
					'query' => $query,
					'error' => $results->get_error_message(),
				)
			);
			return array();
		}

		// Normalize similarity scores to 0-1 range and add search type
		$normalized = array();
		foreach ( $results as $result ) {
			$normalized[] = array(
				'chunk_id'      => $result['chunk_id'],
				'score'         => max( 0, min( 1, $result['similarity'] ) ), // Ensure 0-1 range
				'semantic_score' => $result['similarity'],
				'keyword_score' => 0,
				'search_type'   => 'semantic',
				'content'       => $result['content'],
				'source_type'   => $result['source_type'],
				'source_url'    => $result['source_url'],
				'source_id'     => $result['source_id'],
				'chunk_index'   => $result['chunk_index'],
				'word_count'    => $result['word_count'],
				'token_count'   => $result['token_count'],
			);
		}

		return $normalized;
	}

	/**
	 * Perform keyword-based search.
	 *
	 * @since 1.0.0
	 * @param string $query Search query.
	 * @param array  $args  Search arguments.
	 * @return array Array of keyword search results.
	 */
	private function keyword_search( $query, $args ) {
		global $wpdb;

		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		// Extract keywords from query
		$keywords = $this->extract_keywords( $query );

		if ( empty( $keywords ) ) {
			return array();
		}

		// Build keyword search query
		$where_conditions = array();
		$where_params = array();

		// Build LIKE conditions for each keyword
		$like_conditions = array();
		foreach ( $keywords as $keyword ) {
			$like_conditions[] = 'c.content LIKE %s';
			$where_params[] = '%' . $wpdb->esc_like( $keyword ) . '%';
		}

		if ( ! empty( $like_conditions ) ) {
			$where_conditions[] = '(' . implode( ' OR ', $like_conditions ) . ')';
		}

		// Add filters
		if ( ! empty( $args['source_type'] ) ) {
			$where_conditions[] = 'c.source_type = %s';
			$where_params[] = $args['source_type'];
		}

		if ( ! empty( $args['source_id'] ) ) {
			$where_conditions[] = 'c.source_id = %d';
			$where_params[] = intval( $args['source_id'] );
		}

		$where_clause = ! empty( $where_conditions ) ? 'WHERE ' . implode( ' AND ', $where_conditions ) : '';

		// Get chunks matching keywords
		$query_sql = "SELECT 
			c.id as chunk_id,
			c.content,
			c.source_type,
			c.source_url,
			c.source_id,
			c.chunk_index,
			c.word_count,
			c.token_count
		FROM {$chunks_table} c
		{$where_clause}
		LIMIT %d";

		$where_params[] = $args['keyword_limit'];

		$results = $wpdb->get_results(
			$wpdb->prepare( $query_sql, $where_params )
		);

		if ( empty( $results ) ) {
			return array();
		}

		// Calculate keyword match scores
		$scored_results = array();
		foreach ( $results as $result ) {
			$score = $this->calculate_keyword_score( $result->content, $keywords );

			if ( $score < $this->min_keyword_score ) {
				continue;
			}

			$scored_results[] = array(
				'chunk_id'      => $result->chunk_id,
				'score'         => $score,
				'semantic_score' => 0,
				'keyword_score' => $score,
				'search_type'   => 'keyword',
				'content'       => $result->content,
				'source_type'   => $result->source_type,
				'source_url'    => $result->source_url,
				'source_id'     => $result->source_id,
				'chunk_index'   => $result->chunk_index,
				'word_count'    => $result->word_count,
				'token_count'   => $result->token_count,
			);
		}

		// Sort by keyword score
		usort( $scored_results, function( $a, $b ) {
			return $b['keyword_score'] <=> $a['keyword_score'];
		} );

		return $scored_results;
	}

	/**
	 * Extract keywords from query text.
	 *
	 * @since 1.0.0
	 * @param string $query Query text.
	 * @return array Array of keywords.
	 */
	private function extract_keywords( $query ) {
		// Remove common stop words
		$stop_words = array(
			'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have',
			'i', 'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you',
			'do', 'at', 'this', 'but', 'his', 'by', 'from', 'they',
			'we', 'say', 'her', 'she', 'or', 'an', 'will', 'my', 'one',
			'all', 'would', 'there', 'their', 'what', 'so', 'up', 'out',
			'if', 'about', 'who', 'get', 'which', 'go', 'me', 'when',
			'make', 'can', 'like', 'time', 'no', 'just', 'him', 'know',
			'take', 'people', 'into', 'year', 'your', 'good', 'some',
			'could', 'them', 'see', 'other', 'than', 'then', 'now',
			'look', 'only', 'come', 'its', 'over', 'think', 'also',
			'back', 'after', 'use', 'two', 'how', 'our', 'work', 'first',
			'well', 'way', 'even', 'new', 'want', 'because', 'any',
			'these', 'give', 'day', 'most', 'us', 'is', 'are', 'was',
			'were', 'been', 'being', 'has', 'had', 'having', 'does',
			'did', 'doing', 'may', 'might', 'must', 'shall', 'should',
			'will', 'would', 'can', 'could', 'ought', 'need', 'dare',
		);

		// Convert to lowercase and split into words
		$words = preg_split( '/\s+/', strtolower( trim( $query ) ) );

		// Remove stop words and short words
		$keywords = array_filter( $words, function( $word ) use ( $stop_words ) {
			$word = trim( $word, '.,!?;:"()[]{}' );
			return strlen( $word ) > 2 && ! in_array( $word, $stop_words, true );
		} );

		// Remove duplicates and return
		return array_values( array_unique( $keywords ) );
	}

	/**
	 * Calculate keyword match score for content.
	 *
	 * @since 1.0.0
	 * @param string $content Content text.
	 * @param array  $keywords Array of keywords to match.
	 * @return float Score between 0 and 1.
	 */
	private function calculate_keyword_score( $content, $keywords ) {
		if ( empty( $content ) || empty( $keywords ) ) {
			return 0.0;
		}

		$content_lower = strtolower( $content );
		$content_length = strlen( $content_lower );
		$total_matches = 0;
		$unique_matches = 0;
		$match_positions = array();

		foreach ( $keywords as $keyword ) {
			$keyword_lower = strtolower( $keyword );
			$count = substr_count( $content_lower, $keyword_lower );

			if ( $count > 0 ) {
				$total_matches += $count;
				$unique_matches++;

				// Find positions for proximity scoring
				$pos = 0;
				while ( ( $pos = strpos( $content_lower, $keyword_lower, $pos ) ) !== false ) {
					$match_positions[] = $pos;
					$pos += strlen( $keyword_lower );
				}
			}
		}

		if ( $unique_matches === 0 ) {
			return 0.0;
		}

		// Calculate base score from keyword coverage
		$coverage_score = $unique_matches / count( $keywords );

		// Calculate frequency score (logarithmic to prevent over-weighting)
		$frequency_score = min( 1.0, log( 1 + $total_matches ) / log( 10 ) );

		// Calculate proximity bonus (keywords close together are better)
		$proximity_bonus = 0.0;
		if ( count( $match_positions ) > 1 ) {
			sort( $match_positions );
			$min_distance = $content_length;
			for ( $i = 1; $i < count( $match_positions ); $i++ ) {
				$distance = $match_positions[ $i ] - $match_positions[ $i - 1 ];
				$min_distance = min( $min_distance, $distance );
			}
			// Closer keywords get higher bonus (normalized by content length)
			$proximity_bonus = max( 0, 0.2 * ( 1 - ( $min_distance / max( $content_length, 100 ) ) ) );
		}

		// Combine scores
		$score = ( $coverage_score * 0.5 ) + ( $frequency_score * 0.3 ) + ( $proximity_bonus * 0.2 );

		return min( 1.0, $score );
	}

	/**
	 * Combine semantic and keyword search results.
	 *
	 * @since 1.0.0
	 * @param array $semantic_results Semantic search results.
	 * @param array $keyword_results  Keyword search results.
	 * @param array $args             Combination arguments.
	 * @return array Combined results.
	 */
	private function combine_results( $semantic_results, $keyword_results, $args ) {
		$combined = array();

		// Create lookup maps by chunk_id
		$semantic_map = array();
		foreach ( $semantic_results as $result ) {
			$semantic_map[ $result['chunk_id'] ] = $result;
		}

		$keyword_map = array();
		foreach ( $keyword_results as $result ) {
			$keyword_map[ $result['chunk_id'] ] = $result;
		}

		// Get all unique chunk IDs
		$all_chunk_ids = array_unique( array_merge( array_keys( $semantic_map ), array_keys( $keyword_map ) ) );

		// Combine results based on method
		switch ( $args['combine_method'] ) {
			case 'reciprocal_rank':
			case 'rrf':
				$combined = $this->combine_reciprocal_rank_fusion( $semantic_results, $keyword_results, $args['rrf_k'] );
				break;

			case 'weighted':
			default:
				$combined = $this->combine_weighted( $semantic_map, $keyword_map, $all_chunk_ids, $args );
				break;
		}

		return $combined;
	}

	/**
	 * Combine results using weighted average.
	 *
	 * @since 1.0.0
	 * @param array $semantic_map Map of semantic results by chunk_id.
	 * @param array $keyword_map  Map of keyword results by chunk_id.
	 * @param array $all_chunk_ids All unique chunk IDs.
	 * @param array $args         Combination arguments.
	 * @return array Combined results.
	 */
	private function combine_weighted( $semantic_map, $keyword_map, $all_chunk_ids, $args ) {
		$combined = array();

		foreach ( $all_chunk_ids as $chunk_id ) {
			$semantic_result = isset( $semantic_map[ $chunk_id ] ) ? $semantic_map[ $chunk_id ] : null;
			$keyword_result = isset( $keyword_map[ $chunk_id ] ) ? $keyword_map[ $chunk_id ] : null;

			// Get base result data
			$result = $semantic_result ?: $keyword_result;
			if ( ! $result ) {
				continue;
			}

			// Calculate weighted combined score
			$semantic_score = $semantic_result ? $semantic_result['semantic_score'] : 0;
			$keyword_score = $keyword_result ? $keyword_result['keyword_score'] : 0;

			$combined_score = ( $semantic_score * $args['semantic_weight'] ) + ( $keyword_score * $args['keyword_weight'] );

			$combined[] = array(
				'chunk_id'      => $chunk_id,
				'score'         => $combined_score,
				'semantic_score' => $semantic_score,
				'keyword_score' => $keyword_score,
				'search_type'   => $semantic_result && $keyword_result ? 'hybrid' : ( $semantic_result ? 'semantic' : 'keyword' ),
				'content'       => $result['content'],
				'source_type'   => $result['source_type'],
				'source_url'    => $result['source_url'],
				'source_id'     => $result['source_id'],
				'chunk_index'   => $result['chunk_index'],
				'word_count'    => $result['word_count'],
				'token_count'   => $result['token_count'],
			);
		}

		return $combined;
	}

	/**
	 * Combine results using Reciprocal Rank Fusion (RRF).
	 *
	 * @since 1.0.0
	 * @param array $semantic_results Semantic search results.
	 * @param array $keyword_results  Keyword search results.
	 * @param int   $k                RRF constant (typically 60).
	 * @return array Combined results.
	 */
	private function combine_reciprocal_rank_fusion( $semantic_results, $keyword_results, $k = 60 ) {
		// Create rank maps
		$semantic_ranks = array();
		foreach ( $semantic_results as $rank => $result ) {
			$semantic_ranks[ $result['chunk_id'] ] = $rank + 1; // 1-based rank
		}

		$keyword_ranks = array();
		foreach ( $keyword_results as $rank => $result ) {
			$keyword_ranks[ $result['chunk_id'] ] = $rank + 1; // 1-based rank
		}

		// Get all unique chunk IDs
		$all_chunk_ids = array_unique( array_merge( array_keys( $semantic_ranks ), array_keys( $keyword_ranks ) ) );

		// Create result lookup
		$all_results = array();
		foreach ( $semantic_results as $result ) {
			$all_results[ $result['chunk_id'] ] = $result;
		}
		foreach ( $keyword_results as $result ) {
			if ( ! isset( $all_results[ $result['chunk_id'] ] ) ) {
				$all_results[ $result['chunk_id'] ] = $result;
			}
		}

		// Calculate RRF scores
		$combined = array();
		foreach ( $all_chunk_ids as $chunk_id ) {
			$rrf_score = 0.0;

			if ( isset( $semantic_ranks[ $chunk_id ] ) ) {
				$rrf_score += 1.0 / ( $k + $semantic_ranks[ $chunk_id ] );
			}

			if ( isset( $keyword_ranks[ $chunk_id ] ) ) {
				$rrf_score += 1.0 / ( $k + $keyword_ranks[ $chunk_id ] );
			}

			$result = $all_results[ $chunk_id ];

			$combined[] = array(
				'chunk_id'      => $chunk_id,
				'score'         => $rrf_score,
				'semantic_score' => isset( $semantic_ranks[ $chunk_id ] ) ? $result['semantic_score'] : 0,
				'keyword_score' => isset( $keyword_ranks[ $chunk_id ] ) ? $result['keyword_score'] : 0,
				'search_type'   => isset( $semantic_ranks[ $chunk_id ] ) && isset( $keyword_ranks[ $chunk_id ] ) ? 'hybrid' : ( isset( $semantic_ranks[ $chunk_id ] ) ? 'semantic' : 'keyword' ),
				'content'       => $result['content'],
				'source_type'   => $result['source_type'],
				'source_url'    => $result['source_url'],
				'source_id'     => $result['source_id'],
				'chunk_index'   => $result['chunk_index'],
				'word_count'    => $result['word_count'],
				'token_count'   => $result['token_count'],
			);
		}

		return $combined;
	}
}

