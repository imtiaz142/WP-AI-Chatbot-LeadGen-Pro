<?php
/**
 * Content Indexer.
 *
 * Generates embeddings and stores content chunks in the database for RAG system.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Content_Indexer {

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Config instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Content processor instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Processor
	 */
	private $content_processor;

	/**
	 * PDF processor instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_PDF_Processor
	 */
	private $pdf_processor;

	/**
	 * WooCommerce processor instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_WooCommerce_Processor
	 */
	private $woocommerce_processor;

	/**
	 * API endpoint processor instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_API_Endpoint_Processor
	 */
	private $api_processor;

	/**
	 * Embedding generator instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Embedding_Generator
	 */
	private $embedding_generator;

	/**
	 * Vector store instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Vector_Store
	 */
	private $vector_store;

	/**
	 * Ingestion queue instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Ingestion_Queue
	 */
	private $queue;

	/**
	 * Duplicate detector instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Duplicate_Detector
	 */
	private $duplicate_detector;

	/**
	 * Freshness tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Freshness_Tracker
	 */
	private $freshness_tracker;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->content_processor = new WP_AI_Chatbot_LeadGen_Pro_Content_Processor();
		$this->pdf_processor = new WP_AI_Chatbot_LeadGen_Pro_PDF_Processor();
		$this->woocommerce_processor = new WP_AI_Chatbot_LeadGen_Pro_WooCommerce_Processor();
		$this->api_processor = new WP_AI_Chatbot_LeadGen_Pro_API_Endpoint_Processor();
		$this->embedding_generator = new WP_AI_Chatbot_LeadGen_Pro_Embedding_Generator();
		$this->vector_store = new WP_AI_Chatbot_LeadGen_Pro_Vector_Store();
		$this->queue = new WP_AI_Chatbot_LeadGen_Pro_Ingestion_Queue();
		$this->duplicate_detector = new WP_AI_Chatbot_LeadGen_Pro_Duplicate_Detector();
		$this->freshness_tracker = new WP_AI_Chatbot_LeadGen_Pro_Content_Freshness_Tracker();

		$this->register_hooks();
	}

	/**
	 * Register hooks for job execution.
	 *
	 * @since 1.0.0
	 */
	private function register_hooks() {
		// Register job execution hooks
		add_action( 'wp_ai_chatbot_crawl_url', array( $this, 'handle_crawl_url_job' ), 10, 2 );
		add_action( 'wp_ai_chatbot_process_content', array( $this, 'handle_process_content_job' ), 10, 2 );
		add_action( 'wp_ai_chatbot_index_chunks', array( $this, 'handle_index_chunks_job' ), 10, 2 );
		add_action( 'wp_ai_chatbot_process_pdf', array( $this, 'handle_process_pdf_job' ), 10, 2 );
		add_action( 'wp_ai_chatbot_process_product', array( $this, 'handle_process_product_job' ), 10, 2 );
		add_action( 'wp_ai_chatbot_process_api', array( $this, 'handle_process_api_job' ), 10, 2 );
	}

	/**
	 * Index content from URL.
	 *
	 * @since 1.0.0
	 * @param string $url  URL to index.
	 * @param array  $args Optional. Indexing arguments.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function index_url( $url, $args = array() ) {
		$defaults = array(
			'source_type' => 'url',
			'source_id'   => null,
			'force_reindex' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Check if already indexed (unless force reindex)
		if ( ! $args['force_reindex'] && $this->is_url_indexed( $url ) ) {
			$this->logger->info(
				'URL already indexed, skipping',
				array( 'url' => $url )
			);
			return true;
		}

		// Process content
		$processed = $this->content_processor->process_url( $url, $args );

		if ( is_wp_error( $processed ) ) {
			return $processed;
		}

		// Index chunks
		return $this->index_chunks( $processed['chunks'], array(
			'source_type' => $args['source_type'],
			'source_url'  => $url,
			'source_id'   => $args['source_id'],
		) );
	}

	/**
	 * Index content chunks with embeddings.
	 *
	 * @since 1.0.0
	 * @param array $chunks Content chunks.
	 * @param array $args   Optional. Indexing arguments.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function index_chunks( $chunks, $args = array() ) {
		$defaults = array(
			'source_type' => 'unknown',
			'source_url'  => '',
			'source_id'   => null,
			'embedding_model' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		if ( empty( $chunks ) || ! is_array( $chunks ) ) {
			return new WP_Error(
				'no_chunks',
				__( 'No chunks provided for indexing.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Get embedding model
		$embedding_model = $args['embedding_model'] ?: $this->config->get( 'embedding_model', '' );

		if ( empty( $embedding_model ) ) {
			$embedding_model = $this->embedding_generator->get_default_model();
		}

		$indexed_count = 0;
		$errors = array();

		foreach ( $chunks as $index => $chunk ) {
			$content = isset( $chunk['content'] ) ? $chunk['content'] : ( is_string( $chunk ) ? $chunk : '' );

			if ( empty( $content ) ) {
				continue;
			}

			// Calculate token count (approximate)
			$token_count = $this->estimate_tokens( $content );

			// Generate content hash
			$content_hash = hash( 'sha256', $content );

			// Check for duplicate content using duplicate detector
			$duplicate = $this->duplicate_detector->detect_duplicate( $content, $args['source_url'] );
			if ( $duplicate ) {
				$this->logger->debug(
					'Duplicate content detected, skipping',
					array(
						'source_url' => $args['source_url'],
						'chunk_index' => $index,
						'duplicate_type' => $duplicate['type'],
						'similarity' => $duplicate['similarity'],
					)
				);
				continue;
			}

			// Store chunk in database
			$chunk_data = array(
				'source_type'  => $args['source_type'],
				'source_url'   => $args['source_url'],
				'source_id'    => $args['source_id'],
				'chunk_index'  => $index,
				'content'      => $content,
				'content_hash' => $content_hash,
				'word_count'   => isset( $chunk['word_count'] ) ? intval( $chunk['word_count'] ) : str_word_count( $content ),
				'token_count'  => $token_count,
				'embedding_model' => $embedding_model,
				'last_updated' => current_time( 'mysql' ),
			);

			$chunk_id = WP_AI_Chatbot_LeadGen_Pro_Database::insert_content_chunk( $chunk_data );

			if ( ! $chunk_id ) {
				$errors[] = sprintf( __( 'Failed to store chunk %d', 'wp-ai-chatbot-leadgen-pro' ), $index );
				continue;
			}

			// Generate embedding
			$embedding = $this->embedding_generator->generate( $content, $embedding_model );

			if ( is_wp_error( $embedding ) ) {
				$this->logger->warning(
					'Failed to generate embedding',
					array(
						'chunk_id' => $chunk_id,
						'error'    => $embedding->get_error_message(),
					)
				);
				$errors[] = sprintf( __( 'Failed to generate embedding for chunk %d', 'wp-ai-chatbot-leadgen-pro' ), $index );
				continue;
			}

			// Store embedding in vector store
			$stored = $this->vector_store->store( $chunk_id, $embedding, $embedding_model );

			if ( is_wp_error( $stored ) ) {
				$this->logger->warning(
					'Failed to store embedding',
					array(
						'chunk_id' => $chunk_id,
						'error'    => $stored->get_error_message(),
					)
				);
				$errors[] = sprintf( __( 'Failed to store embedding for chunk %d', 'wp-ai-chatbot-leadgen-pro' ), $index );
				continue;
			}

			$indexed_count++;
		}

		// Update freshness timestamp
		if ( ! empty( $args['source_url'] ) && $indexed_count > 0 ) {
			// Get source timestamp if available
			$source_timestamp = null;
			if ( ! empty( $args['source_id'] ) && ! empty( $args['source_type'] ) ) {
				$source_timestamp = $this->freshness_tracker->get_source_timestamp(
					$args['source_url'],
					$args['source_type'],
					$args['source_id']
				);
				if ( is_wp_error( $source_timestamp ) ) {
					$source_timestamp = null;
				}
			}

			$this->freshness_tracker->update_freshness( $args['source_url'], $source_timestamp );
		}

		$this->logger->info(
			'Content chunks indexed',
			array(
				'source_url'    => $args['source_url'],
				'total_chunks'  => count( $chunks ),
				'indexed_count' => $indexed_count,
				'errors'        => count( $errors ),
			)
		);

		if ( ! empty( $errors ) && $indexed_count === 0 ) {
			return new WP_Error(
				'indexing_failed',
				__( 'Failed to index any chunks.', 'wp-ai-chatbot-leadgen-pro' ),
				array( 'errors' => $errors )
			);
		}

		return true;
	}

	/**
	 * Index PDF document.
	 *
	 * @since 1.0.0
	 * @param string $file_path PDF file path or URL.
	 * @param array  $args      Optional. Indexing arguments.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function index_pdf( $file_path, $args = array() ) {
		$defaults = array(
			'source_type' => 'pdf',
			'source_id'   => null,
			'force_reindex' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Process PDF
		$processed = $this->pdf_processor->process_pdf( $file_path, $args );

		if ( is_wp_error( $processed ) ) {
			return $processed;
		}

		// Chunk the extracted text
		$chunks = $this->content_processor->chunk_content( $processed['text'], $args );

		// Index chunks
		return $this->index_chunks( $chunks, array(
			'source_type' => $args['source_type'],
			'source_url'  => is_string( $file_path ) && filter_var( $file_path, FILTER_VALIDATE_URL ) ? $file_path : '',
			'source_id'   => $args['source_id'],
		) );
	}

	/**
	 * Index WooCommerce product.
	 *
	 * @since 1.0.0
	 * @param int   $product_id Product ID.
	 * @param array $args       Optional. Indexing arguments.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function index_product( $product_id, $args = array() ) {
		$defaults = array(
			'force_reindex' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Check if already indexed (unless force reindex)
		if ( ! $args['force_reindex'] && $this->is_product_indexed( $product_id ) ) {
			return true;
		}

		// Process product
		$processed = $this->woocommerce_processor->process_product( $product_id, $args );

		if ( is_wp_error( $processed ) ) {
			return $processed;
		}

		// Chunk the content
		$chunks = $this->content_processor->chunk_content( $processed['content'], $args );

		// Index chunks
		return $this->index_chunks( $chunks, array(
			'source_type' => 'product',
			'source_url'  => $processed['url'],
			'source_id'   => $product_id,
		) );
	}

	/**
	 * Index API endpoint data.
	 *
	 * @since 1.0.0
	 * @param string $endpoint_url API endpoint URL.
	 * @param array  $args         Optional. Indexing arguments.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function index_api_endpoint( $endpoint_url, $args = array() ) {
		$defaults = array(
			'source_type' => 'api',
			'force_reindex' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Process API endpoint
		$processed = $this->api_processor->process_endpoint( $endpoint_url, $args );

		if ( is_wp_error( $processed ) ) {
			return $processed;
		}

		// Chunk the content
		$chunks = $this->content_processor->chunk_content( $processed['content'], $args );

		// Index chunks
		return $this->index_chunks( $chunks, array(
			'source_type' => $args['source_type'],
			'source_url'  => $endpoint_url,
			'source_id'   => null,
		) );
	}

	/**
	 * Handle crawl URL job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 */
	public function handle_crawl_url_job( $job_data, $job_id ) {
		$url = isset( $job_data['url'] ) ? $job_data['url'] : '';
		if ( empty( $url ) ) {
			return;
		}

		$result = $this->index_url( $url, $job_data );
		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Crawl URL job failed',
				array(
					'job_id' => $job_id,
					'url'    => $url,
					'error'  => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Handle process content job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 */
	public function handle_process_content_job( $job_data, $job_id ) {
		$chunks = isset( $job_data['chunks'] ) ? $job_data['chunks'] : array();
		if ( empty( $chunks ) ) {
			return;
		}

		$result = $this->index_chunks( $chunks, $job_data );
		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Process content job failed',
				array(
					'job_id' => $job_id,
					'error'  => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Handle index chunks job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 */
	public function handle_index_chunks_job( $job_data, $job_id ) {
		$this->handle_process_content_job( $job_data, $job_id );
	}

	/**
	 * Handle process PDF job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 */
	public function handle_process_pdf_job( $job_data, $job_id ) {
		$file_path = isset( $job_data['file_path'] ) ? $job_data['file_path'] : '';
		if ( empty( $file_path ) ) {
			return;
		}

		$result = $this->index_pdf( $file_path, $job_data );
		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Process PDF job failed',
				array(
					'job_id' => $job_id,
					'file_path' => $file_path,
					'error'  => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Handle process product job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 */
	public function handle_process_product_job( $job_data, $job_id ) {
		$product_id = isset( $job_data['product_id'] ) ? intval( $job_data['product_id'] ) : 0;
		if ( empty( $product_id ) ) {
			return;
		}

		$result = $this->index_product( $product_id, $job_data );
		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Process product job failed',
				array(
					'job_id' => $job_id,
					'product_id' => $product_id,
					'error'  => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Handle process API job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 */
	public function handle_process_api_job( $job_data, $job_id ) {
		$endpoint_url = isset( $job_data['endpoint_url'] ) ? $job_data['endpoint_url'] : '';
		if ( empty( $endpoint_url ) ) {
			return;
		}

		$result = $this->index_api_endpoint( $endpoint_url, $job_data );
		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Process API job failed',
				array(
					'job_id' => $job_id,
					'endpoint_url' => $endpoint_url,
					'error'  => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Check if URL is already indexed.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return bool True if indexed, false otherwise.
	 */
	private function is_url_indexed( $url ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source_url = %s",
				$url
			)
		);

		return $count > 0;
	}

	/**
	 * Check if product is already indexed.
	 *
	 * @since 1.0.0
	 * @param int $product_id Product ID.
	 * @return bool True if indexed, false otherwise.
	 */
	private function is_product_indexed( $product_id ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source_type = 'product' AND source_id = %d",
				$product_id
			)
		);

		return $count > 0;
	}


	/**
	 * Estimate token count for text.
	 *
	 * @since 1.0.0
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	private function estimate_tokens( $text ) {
		// Simple approximation: ~4 characters per token
		return intval( ceil( strlen( $text ) / 4 ) );
	}

	/**
	 * Re-index content for a URL.
	 *
	 * @since 1.0.0
	 * @param string $url URL to re-index.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function reindex_url( $url ) {
		// Delete existing chunks for this URL
		$this->delete_url_chunks( $url );

		// Re-index
		return $this->index_url( $url, array( 'force_reindex' => true ) );
	}

	/**
	 * Delete chunks for a URL.
	 *
	 * @since 1.0.0
	 * @param string $url URL.
	 * @return bool True on success, false on failure.
	 */
	private function delete_url_chunks( $url ) {
		global $wpdb;

		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();
		$embeddings_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_embeddings_table();

		// Get chunk IDs
		$chunk_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$chunks_table} WHERE source_url = %s",
				$url
			)
		);

		if ( empty( $chunk_ids ) ) {
			return true;
		}

		// Delete embeddings first (foreign key constraint)
		$placeholders = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$embeddings_table} WHERE chunk_id IN ({$placeholders})",
				$chunk_ids
			)
		);

		// Delete chunks
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$chunks_table} WHERE source_url = %s",
				$url
			)
		);

		return true;
	}

	/**
	 * Get indexing statistics.
	 *
	 * @since 1.0.0
	 * @return array Statistics.
	 */
	public function get_indexing_stats() {
		global $wpdb;

		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();
		$embeddings_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_embeddings_table();

		$stats = array(
			'total_chunks'      => 0,
			'total_embeddings'  => 0,
			'by_source_type'    => array(),
			'unique_sources'    => 0,
			'total_words'       => 0,
			'total_tokens'      => 0,
		);

		// Total chunks
		$stats['total_chunks'] = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table}" ) );

		// Total embeddings
		$stats['total_embeddings'] = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$embeddings_table}" ) );

		// By source type
		$by_type = $wpdb->get_results(
			"SELECT source_type, COUNT(*) as count FROM {$chunks_table} GROUP BY source_type",
			ARRAY_A
		);

		foreach ( $by_type as $row ) {
			$stats['by_source_type'][ $row['source_type'] ] = intval( $row['count'] );
		}

		// Unique sources
		$stats['unique_sources'] = intval( $wpdb->get_var( "SELECT COUNT(DISTINCT source_url) FROM {$chunks_table} WHERE source_url != ''" ) );

		// Total words and tokens
		$totals = $wpdb->get_row(
			"SELECT SUM(word_count) as total_words, SUM(token_count) as total_tokens FROM {$chunks_table}",
			ARRAY_A
		);

		$stats['total_words'] = intval( $totals['total_words'] ?? 0 );
		$stats['total_tokens'] = intval( $totals['total_tokens'] ?? 0 );

		return $stats;
	}
}

