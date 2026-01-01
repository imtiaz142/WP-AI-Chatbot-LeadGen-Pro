<?php
/**
 * Vector Store.
 *
 * Handles storage and querying of content embeddings for semantic search.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/rag
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Vector_Store {

	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Database
	 */
	private $database;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Embedding generator instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Embedding_Generator
	 */
	private $embedding_generator;

	/**
	 * Maximum number of results to return by default.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_limit = 10;

	/**
	 * Maximum number of embeddings to load for similarity search.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_embeddings_to_compare = 10000;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->database = new WP_AI_Chatbot_LeadGen_Pro_Database();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->embedding_generator = new WP_AI_Chatbot_LeadGen_Pro_Embedding_Generator();
	}

	/**
	 * Store embedding for a content chunk.
	 *
	 * @since 1.0.0
	 * @param int    $chunk_id        Content chunk ID.
	 * @param array  $embedding_vector Embedding vector.
	 * @param string $embedding_model  Model used to generate embedding.
	 * @return int|WP_Error Embedding ID on success, WP_Error on failure.
	 */
	public function store( $chunk_id, $embedding_vector, $embedding_model ) {
		global $wpdb;

		if ( empty( $chunk_id ) || empty( $embedding_vector ) || empty( $embedding_model ) ) {
			return new WP_Error(
				'invalid_parameters',
				__( 'Chunk ID, embedding vector, and model are required.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Validate embedding vector
		if ( ! is_array( $embedding_vector ) || empty( $embedding_vector ) ) {
			return new WP_Error(
				'invalid_embedding',
				__( 'Embedding vector must be a non-empty array.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$dimension = count( $embedding_vector );

		// Check if embedding already exists for this chunk and model
		$existing = $this->get_embedding_by_chunk( $chunk_id, $embedding_model );
		if ( $existing ) {
			// Update existing embedding
			return $this->update( $existing->id, $embedding_vector, $embedding_model );
		}

		// Insert new embedding
		$data = array(
			'chunk_id'        => intval( $chunk_id ),
			'embedding_model' => sanitize_text_field( $embedding_model ),
			'embedding_vector' => $embedding_vector,
			'dimension'       => $dimension,
		);

		$embedding_id = WP_AI_Chatbot_LeadGen_Pro_Database::insert_embedding( $data );

		if ( false === $embedding_id ) {
			$this->logger->error(
				'Failed to store embedding',
				array(
					'chunk_id' => $chunk_id,
					'model'    => $embedding_model,
				)
			);
			return new WP_Error(
				'storage_failed',
				__( 'Failed to store embedding in database.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return $embedding_id;
	}

	/**
	 * Update existing embedding.
	 *
	 * @since 1.0.0
	 * @param int    $embedding_id     Embedding ID.
	 * @param array  $embedding_vector New embedding vector.
	 * @param string $embedding_model  Model used.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update( $embedding_id, $embedding_vector, $embedding_model ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_embeddings_table();

		$dimension = count( $embedding_vector );
		$vector_json = wp_json_encode( $embedding_vector );

		$result = $wpdb->update(
			$table,
			array(
				'embedding_vector' => $vector_json,
				'embedding_model'  => sanitize_text_field( $embedding_model ),
				'dimension'        => $dimension,
			),
			array( 'id' => intval( $embedding_id ) ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update embedding.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return true;
	}

	/**
	 * Get embedding by chunk ID and model.
	 *
	 * @since 1.0.0
	 * @param int    $chunk_id Chunk ID.
	 * @param string $model    Embedding model.
	 * @return object|null Embedding object or null if not found.
	 */
	public function get_embedding_by_chunk( $chunk_id, $model ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_embeddings_table();

		$embedding = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE chunk_id = %d AND embedding_model = %s LIMIT 1",
				$chunk_id,
				$model
			)
		);

		if ( $embedding && ! empty( $embedding->embedding_vector ) ) {
			$embedding->embedding_vector = json_decode( $embedding->embedding_vector, true );
		}

		return $embedding;
	}

	/**
	 * Search for similar content using vector similarity.
	 *
	 * @since 1.0.0
	 * @param array  $query_embedding Query embedding vector.
	 * @param array  $args            Optional. Query arguments.
	 * @return array|WP_Error Array of similar chunks with similarity scores or WP_Error on failure.
	 */
	public function similarity_search( $query_embedding, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'          => $this->default_limit,
			'threshold'      => 0.0,  // Minimum similarity score
			'model'          => null,  // Filter by embedding model
			'source_type'    => null,  // Filter by source type
			'source_id'      => null,  // Filter by source ID
			'exclude_chunks' => array(), // Chunk IDs to exclude
		);

		$args = wp_parse_args( $args, $defaults );

		if ( ! is_array( $query_embedding ) || empty( $query_embedding ) ) {
			return new WP_Error(
				'invalid_query',
				__( 'Query embedding must be a non-empty array.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$query_dimension = count( $query_embedding );

		// Build query to get embeddings with chunk data
		$embeddings_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_embeddings_table();
		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$query = "SELECT 
			e.id as embedding_id,
			e.chunk_id,
			e.embedding_vector,
			e.embedding_model,
			e.dimension,
			c.source_type,
			c.source_url,
			c.source_id,
			c.content,
			c.chunk_index,
			c.word_count,
			c.token_count
		FROM {$embeddings_table} e
		INNER JOIN {$chunks_table} c ON e.chunk_id = c.id
		WHERE e.dimension = %d";

		$query_params = array( $query_dimension );

		// Add filters
		if ( ! empty( $args['model'] ) ) {
			$query .= ' AND e.embedding_model = %s';
			$query_params[] = $args['model'];
		}

		if ( ! empty( $args['source_type'] ) ) {
			$query .= ' AND c.source_type = %s';
			$query_params[] = $args['source_type'];
		}

		if ( ! empty( $args['source_id'] ) ) {
			$query .= ' AND c.source_id = %d';
			$query_params[] = intval( $args['source_id'] );
		}

		if ( ! empty( $args['exclude_chunks'] ) && is_array( $args['exclude_chunks'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['exclude_chunks'] ), '%d' ) );
			$query .= " AND c.id NOT IN ({$placeholders})";
			$query_params = array_merge( $query_params, array_map( 'intval', $args['exclude_chunks'] ) );
		}

		// Limit number of embeddings to compare for performance
		$query .= ' LIMIT %d';
		$query_params[] = $this->max_embeddings_to_compare;

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $query_params )
		);

		if ( empty( $results ) ) {
			return array();
		}

		// Calculate similarity scores
		$similarities = array();
		foreach ( $results as $result ) {
			$stored_embedding = json_decode( $result->embedding_vector, true );

			if ( ! is_array( $stored_embedding ) ) {
				continue;
			}

			$similarity = $this->embedding_generator->cosine_similarity( $query_embedding, $stored_embedding );

			if ( is_wp_error( $similarity ) ) {
				continue;
			}

			// Apply threshold
			if ( $similarity < $args['threshold'] ) {
				continue;
			}

			$similarities[] = array(
				'embedding_id'  => $result->embedding_id,
				'chunk_id'      => $result->chunk_id,
				'similarity'    => $similarity,
				'source_type'   => $result->source_type,
				'source_url'    => $result->source_url,
				'source_id'     => $result->source_id,
				'content'       => $result->content,
				'chunk_index'   => $result->chunk_index,
				'word_count'    => $result->word_count,
				'token_count'   => $result->token_count,
				'embedding_model' => $result->embedding_model,
			);
		}

		// Sort by similarity (descending)
		usort( $similarities, function( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		// Return top results
		return array_slice( $similarities, 0, $args['limit'] );
	}

	/**
	 * Search for similar content using a text query.
	 *
	 * @since 1.0.0
	 * @param string $query_text Query text.
	 * @param array  $args       Optional. Query arguments.
	 * @return array|WP_Error Array of similar chunks or WP_Error on failure.
	 */
	public function search( $query_text, $args = array() ) {
		if ( empty( $query_text ) ) {
			return new WP_Error(
				'empty_query',
				__( 'Query text cannot be empty.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Generate embedding for query text
		$defaults = array(
			'provider' => null,
			'model'    => null,
		);

		$search_args = wp_parse_args( $args, $defaults );

		// Get embedding model from args or use default
		$embedding_model = $search_args['model'];
		if ( empty( $embedding_model ) && ! empty( $search_args['provider'] ) ) {
			$embedding_model = $this->embedding_generator->get_default_model( $search_args['provider'] );
		}

		// Generate query embedding
		$query_embedding = $this->embedding_generator->generate( $query_text, $search_args['provider'], $embedding_model );

		if ( is_wp_error( $query_embedding ) ) {
			return $query_embedding;
		}

		// Use the embedding model for filtering if specified
		if ( ! empty( $embedding_model ) ) {
			$args['model'] = $embedding_model;
		}

		// Perform similarity search
		return $this->similarity_search( $query_embedding, $args );
	}

	/**
	 * Delete embeddings for a chunk.
	 *
	 * @since 1.0.0
	 * @param int $chunk_id Chunk ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_by_chunk( $chunk_id ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_embeddings_table();

		$result = $wpdb->delete(
			$table,
			array( 'chunk_id' => intval( $chunk_id ) ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete embeddings by model.
	 *
	 * @since 1.0.0
	 * @param string $model Embedding model.
	 * @return int|false Number of rows deleted or false on failure.
	 */
	public function delete_by_model( $model ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_embeddings_table();

		$result = $wpdb->delete(
			$table,
			array( 'embedding_model' => sanitize_text_field( $model ) ),
			array( '%s' )
		);

		return $result;
	}

	/**
	 * Get statistics about stored embeddings.
	 *
	 * @since 1.0.0
	 * @return array Statistics array.
	 */
	public function get_statistics() {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_embeddings_table();

		$total_embeddings = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$models = $wpdb->get_results(
			"SELECT embedding_model, COUNT(*) as count, AVG(dimension) as avg_dimension
			FROM {$table}
			GROUP BY embedding_model"
		);

		$model_stats = array();
		foreach ( $models as $model ) {
			$model_stats[ $model->embedding_model ] = array(
				'count'        => intval( $model->count ),
				'avg_dimension' => floatval( $model->avg_dimension ),
			);
		}

		return array(
			'total_embeddings' => intval( $total_embeddings ),
			'models'           => $model_stats,
		);
	}

	/**
	 * Batch store embeddings.
	 *
	 * @since 1.0.0
	 * @param array $embeddings Array of embedding data arrays (chunk_id, vector, model).
	 * @return array Array of results (embedding_id or WP_Error for each).
	 */
	public function batch_store( $embeddings ) {
		if ( ! is_array( $embeddings ) || empty( $embeddings ) ) {
			return array();
		}

		$results = array();

		foreach ( $embeddings as $embedding_data ) {
			if ( ! isset( $embedding_data['chunk_id'] ) || ! isset( $embedding_data['vector'] ) || ! isset( $embedding_data['model'] ) ) {
				$results[] = new WP_Error(
					'invalid_data',
					__( 'Each embedding must have chunk_id, vector, and model.', 'wp-ai-chatbot-leadgen-pro' )
				);
				continue;
			}

			$result = $this->store(
				$embedding_data['chunk_id'],
				$embedding_data['vector'],
				$embedding_data['model']
			);

			$results[] = $result;
		}

		return $results;
	}
}

