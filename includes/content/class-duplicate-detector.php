<?php
/**
 * Duplicate Content Detector.
 *
 * Detects and tracks duplicate content across the knowledge base.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Duplicate_Detector {

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
	 * Similarity threshold for duplicate detection (0.0 to 1.0).
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private $similarity_threshold = 0.95;

	/**
	 * Minimum content length to check for duplicates (in characters).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $min_content_length = 50;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
	}

	/**
	 * Check if content is duplicate.
	 *
	 * @since 1.0.0
	 * @param string $content     Content to check.
	 * @param string $source_url  Optional. Source URL (to exclude from check).
	 * @param array  $args        Optional. Detection arguments.
	 * @return array|false Duplicate information or false if not duplicate.
	 */
	public function detect_duplicate( $content, $source_url = '', $args = array() ) {
		$defaults = array(
			'method'            => 'hash', // 'hash', 'similarity', 'both'
			'similarity_threshold' => $this->similarity_threshold,
			'min_length'        => $this->min_content_length,
		);

		$args = wp_parse_args( $args, $defaults );

		// Skip if content is too short
		if ( strlen( $content ) < $args['min_length'] ) {
			return false;
		}

		// Generate content hash
		$content_hash = hash( 'sha256', $content );

		// Check for exact duplicate (hash match)
		if ( in_array( $args['method'], array( 'hash', 'both' ), true ) ) {
			$exact_duplicate = $this->find_exact_duplicate( $content_hash, $source_url );
			if ( $exact_duplicate ) {
				return array(
					'type'        => 'exact',
					'similarity'  => 1.0,
					'chunk_id'    => $exact_duplicate->id,
					'source_url'  => $exact_duplicate->source_url,
					'source_type' => $exact_duplicate->source_type,
					'content_hash' => $content_hash,
				);
			}
		}

		// Check for similar content (semantic similarity)
		if ( in_array( $args['method'], array( 'similarity', 'both' ), true ) ) {
			$similar_content = $this->find_similar_content( $content, $source_url, $args['similarity_threshold'] );
			if ( $similar_content ) {
				return array(
					'type'        => 'similar',
					'similarity'  => $similar_content['similarity'],
					'chunk_id'    => $similar_content['chunk_id'],
					'source_url'  => $similar_content['source_url'],
					'source_type' => $similar_content['source_type'],
					'content_hash' => $content_hash,
				);
			}
		}

		return false;
	}

	/**
	 * Find exact duplicate by content hash.
	 *
	 * @since 1.0.0
	 * @param string $content_hash Content hash.
	 * @param string $exclude_url  Optional. URL to exclude from search.
	 * @return object|null Chunk object or null if not found.
	 */
	private function find_exact_duplicate( $content_hash, $exclude_url = '' ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$query = "SELECT id, source_url, source_type, content_hash FROM {$table} WHERE content_hash = %s";
		$params = array( $content_hash );

		if ( ! empty( $exclude_url ) ) {
			$query .= " AND source_url != %s";
			$params[] = $exclude_url;
		}

		$query .= " LIMIT 1";

		return $wpdb->get_row(
			$wpdb->prepare( $query, $params )
		);
	}

	/**
	 * Find similar content using semantic similarity.
	 *
	 * @since 1.0.0
	 * @param string $content           Content to check.
	 * @param string $exclude_url       Optional. URL to exclude from search.
	 * @param float  $similarity_threshold Minimum similarity threshold.
	 * @return array|false Similar content info or false if not found.
	 */
	private function find_similar_content( $content, $exclude_url = '', $similarity_threshold = 0.95 ) {
		// Generate embedding for content
		$embedding_generator = new WP_AI_Chatbot_LeadGen_Pro_Embedding_Generator();
		$embedding = $embedding_generator->generate( $content );

		if ( is_wp_error( $embedding ) || empty( $embedding ) ) {
			return false;
		}

		// Search for similar embeddings
		$vector_store = new WP_AI_Chatbot_LeadGen_Pro_Vector_Store();
		
		// Get chunk IDs to exclude if exclude_url is provided
		$exclude_chunks = array();
		if ( ! empty( $exclude_url ) ) {
			global $wpdb;
			$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();
			$exclude_chunks = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$chunks_table} WHERE source_url = %s",
					$exclude_url
				)
			);
		}
		
		$results = $vector_store->similarity_search( $embedding, array(
			'limit'          => 10,
			'threshold'      => $similarity_threshold,
			'exclude_chunks' => $exclude_chunks,
		) );

		if ( empty( $results ) ) {
			return false;
		}

		// Return most similar result
		$most_similar = $results[0];

		return array(
			'chunk_id'    => $most_similar['chunk_id'],
			'similarity'  => $most_similar['similarity'],
			'source_url'  => $most_similar['source_url'],
			'source_type' => $most_similar['source_type'],
		);
	}

	/**
	 * Track duplicate relationship.
	 *
	 * @since 1.0.0
	 * @param int    $chunk_id      Chunk ID.
	 * @param int    $duplicate_id  Duplicate chunk ID.
	 * @param string $duplicate_type Type of duplicate ('exact' or 'similar').
	 * @param float  $similarity    Similarity score (0.0 to 1.0).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function track_duplicate( $chunk_id, $duplicate_id, $duplicate_type, $similarity = 1.0 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_content_duplicates';

		// Create table if it doesn't exist
		$this->maybe_create_duplicates_table();

		// Check if relationship already exists
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE chunk_id = %d AND duplicate_chunk_id = %d",
				$chunk_id,
				$duplicate_id
			)
		);

		if ( $existing ) {
			// Update existing relationship
			return false !== $wpdb->update(
				$table,
				array(
					'duplicate_type' => $duplicate_type,
					'similarity'     => $similarity,
					'updated_at'     => current_time( 'mysql' ),
				),
				array(
					'chunk_id'        => $chunk_id,
					'duplicate_chunk_id' => $duplicate_id,
				),
				array( '%s', '%f', '%s' ),
				array( '%d', '%d' )
			);
		}

		// Insert new relationship
		$result = $wpdb->insert(
			$table,
			array(
				'chunk_id'          => $chunk_id,
				'duplicate_chunk_id' => $duplicate_id,
				'duplicate_type'    => $duplicate_type,
				'similarity'        => $similarity,
				'created_at'        => current_time( 'mysql' ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%f', '%s', '%s' )
		);

		if ( false === $result ) {
			$this->logger->error(
				'Failed to track duplicate',
				array(
					'chunk_id'      => $chunk_id,
					'duplicate_id'  => $duplicate_id,
					'error'         => $wpdb->last_error,
				)
			);
			return new WP_Error(
				'db_error',
				__( 'Failed to track duplicate relationship.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return true;
	}

	/**
	 * Get duplicates for a chunk.
	 *
	 * @since 1.0.0
	 * @param int $chunk_id Chunk ID.
	 * @return array Array of duplicate chunks.
	 */
	public function get_duplicates( $chunk_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_content_duplicates';
		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$duplicates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					d.duplicate_chunk_id as chunk_id,
					d.duplicate_type,
					d.similarity,
					c.source_url,
					c.source_type,
					c.content_hash
				FROM {$table} d
				INNER JOIN {$chunks_table} c ON d.duplicate_chunk_id = c.id
				WHERE d.chunk_id = %d
				ORDER BY d.similarity DESC, d.created_at DESC",
				$chunk_id
			)
		);

		return $duplicates ? $duplicates : array();
	}

	/**
	 * Get all duplicate groups (chunks that are duplicates of each other).
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Query arguments.
	 * @return array Array of duplicate groups.
	 */
	public function get_duplicate_groups( $args = array() ) {
		$defaults = array(
			'min_group_size' => 2,
			'duplicate_type' => null, // 'exact', 'similar', or null for all
			'limit'          => 100,
		);

		$args = wp_parse_args( $args, $defaults );

		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_content_duplicates';
		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		// Find groups of duplicates using content hash
		$query = "SELECT 
			c.content_hash,
			COUNT(DISTINCT c.id) as group_size,
			GROUP_CONCAT(DISTINCT c.id ORDER BY c.id) as chunk_ids,
			GROUP_CONCAT(DISTINCT c.source_url ORDER BY c.source_url SEPARATOR '|') as source_urls
		FROM {$chunks_table} c
		GROUP BY c.content_hash
		HAVING group_size >= %d";

		$params = array( $args['min_group_size'] );

		if ( ! empty( $args['duplicate_type'] ) && $args['duplicate_type'] === 'exact' ) {
			// Only exact duplicates (same hash)
			// Query already handles this
		}

		$query .= " ORDER BY group_size DESC LIMIT %d";
		$params[] = $args['limit'];

		$groups = $wpdb->get_results(
			$wpdb->prepare( $query, $params ),
			ARRAY_A
		);

		// Format results
		$formatted_groups = array();
		foreach ( $groups as $group ) {
			$chunk_ids = explode( ',', $group['chunk_ids'] );
			$source_urls = explode( '|', $group['source_urls'] );

			$formatted_groups[] = array(
				'content_hash' => $group['content_hash'],
				'group_size'   => intval( $group['group_size'] ),
				'chunk_ids'    => array_map( 'intval', $chunk_ids ),
				'source_urls'  => $source_urls,
			);
		}

		return $formatted_groups;
	}

	/**
	 * Get duplicate statistics.
	 *
	 * @since 1.0.0
	 * @return array Statistics.
	 */
	public function get_duplicate_stats() {
		global $wpdb;

		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();
		$duplicates_table = $wpdb->prefix . 'ai_chatbot_content_duplicates';

		$this->maybe_create_duplicates_table();

		$stats = array(
			'total_duplicate_relationships' => 0,
			'exact_duplicates'              => 0,
			'similar_duplicates'            => 0,
			'duplicate_groups'              => 0,
			'chunks_with_duplicates'        => 0,
			'unique_content_percentage'     => 0,
		);

		// Total duplicate relationships
		$stats['total_duplicate_relationships'] = intval(
			$wpdb->get_var( "SELECT COUNT(*) FROM {$duplicates_table}" )
		);

		// Exact duplicates
		$stats['exact_duplicates'] = intval(
			$wpdb->get_var( "SELECT COUNT(*) FROM {$duplicates_table} WHERE duplicate_type = 'exact'" )
		);

		// Similar duplicates
		$stats['similar_duplicates'] = intval(
			$wpdb->get_var( "SELECT COUNT(*) FROM {$duplicates_table} WHERE duplicate_type = 'similar'" )
		);

		// Duplicate groups (chunks with same hash)
		$stats['duplicate_groups'] = intval(
			$wpdb->get_var(
				"SELECT COUNT(*) FROM (
					SELECT content_hash FROM {$chunks_table} 
					GROUP BY content_hash 
					HAVING COUNT(*) > 1
				) as groups"
			)
		);

		// Chunks with duplicates
		$stats['chunks_with_duplicates'] = intval(
			$wpdb->get_var( "SELECT COUNT(DISTINCT chunk_id) FROM {$duplicates_table}" )
		);

		// Unique content percentage
		$total_chunks = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table}" ) );
		$unique_chunks = intval(
			$wpdb->get_var( "SELECT COUNT(DISTINCT content_hash) FROM {$chunks_table}" )
		);

		if ( $total_chunks > 0 ) {
			$stats['unique_content_percentage'] = round( ( $unique_chunks / $total_chunks ) * 100, 2 );
		}

		return $stats;
	}

	/**
	 * Remove duplicate tracking for a chunk.
	 *
	 * @since 1.0.0
	 * @param int $chunk_id Chunk ID.
	 * @return bool True on success, false on failure.
	 */
	public function remove_duplicate_tracking( $chunk_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_content_duplicates';

		return false !== $wpdb->delete(
			$table,
			array( 'chunk_id' => $chunk_id ),
			array( '%d' )
		) && false !== $wpdb->delete(
			$table,
			array( 'duplicate_chunk_id' => $chunk_id ),
			array( '%d' )
		);
	}

	/**
	 * Scan all content for duplicates.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Scan arguments.
	 * @return array Scan results.
	 */
	public function scan_for_duplicates( $args = array() ) {
		$defaults = array(
			'batch_size'         => 100,
			'method'             => 'hash', // 'hash', 'similarity', 'both'
			'similarity_threshold' => $this->similarity_threshold,
			'update_tracking'    => true,
		);

		$args = wp_parse_args( $args, $defaults );

		global $wpdb;

		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		// Get all chunks
		$chunks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, content, content_hash, source_url, source_type FROM {$chunks_table} ORDER BY id ASC LIMIT %d",
				$args['batch_size']
			)
		);

		$results = array(
			'scanned'    => 0,
			'duplicates_found' => 0,
			'relationships_created' => 0,
			'errors'     => array(),
		);

		foreach ( $chunks as $chunk ) {
			$results['scanned']++;

			// Check for duplicates
			$duplicate = $this->detect_duplicate(
				$chunk->content,
				$chunk->source_url,
				$args
			);

			if ( $duplicate ) {
				$results['duplicates_found']++;

				// Track duplicate relationship
				if ( $args['update_tracking'] ) {
					$tracked = $this->track_duplicate(
						$chunk->id,
						$duplicate['chunk_id'],
						$duplicate['type'],
						$duplicate['similarity']
					);

					if ( ! is_wp_error( $tracked ) ) {
						$results['relationships_created']++;
					} else {
						$results['errors'][] = sprintf(
							__( 'Failed to track duplicate for chunk %d: %s', 'wp-ai-chatbot-leadgen-pro' ),
							$chunk->id,
							$tracked->get_error_message()
						);
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Create duplicates table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_duplicates_table() {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_content_duplicates';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			return; // Table already exists
		}

		$charset_collate = $wpdb->get_charset_collate();
		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			chunk_id bigint(20) UNSIGNED NOT NULL,
			duplicate_chunk_id bigint(20) UNSIGNED NOT NULL,
			duplicate_type varchar(20) NOT NULL DEFAULT 'exact',
			similarity decimal(5,4) DEFAULT 1.0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY chunk_duplicate (chunk_id, duplicate_chunk_id),
			KEY chunk_id (chunk_id),
			KEY duplicate_chunk_id (duplicate_chunk_id),
			KEY duplicate_type (duplicate_type),
			KEY similarity (similarity),
			FOREIGN KEY (chunk_id) REFERENCES {$chunks_table}(id) ON DELETE CASCADE,
			FOREIGN KEY (duplicate_chunk_id) REFERENCES {$chunks_table}(id) ON DELETE CASCADE
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Set similarity threshold.
	 *
	 * @since 1.0.0
	 * @param float $threshold Similarity threshold (0.0 to 1.0).
	 */
	public function set_similarity_threshold( $threshold ) {
		$this->similarity_threshold = max( 0.0, min( 1.0, floatval( $threshold ) ) );
	}

	/**
	 * Get similarity threshold.
	 *
	 * @since 1.0.0
	 * @return float Similarity threshold.
	 */
	public function get_similarity_threshold() {
		return $this->similarity_threshold;
	}
}

