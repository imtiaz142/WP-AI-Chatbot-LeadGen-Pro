<?php
/**
 * Content Freshness Tracker.
 *
 * Tracks content freshness and identifies stale content that needs re-indexing.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Content_Freshness_Tracker {

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
	 * Default freshness threshold in days.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_freshness_threshold_days = 30;

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
	 * Update content freshness timestamp.
	 *
	 * @since 1.0.0
	 * @param string $source_url Source URL.
	 * @param string $last_updated Optional. Last updated timestamp. Defaults to current time.
	 * @return bool True on success, false on failure.
	 */
	public function update_freshness( $source_url, $last_updated = null ) {
		if ( empty( $source_url ) ) {
			return false;
		}

		if ( null === $last_updated ) {
			$last_updated = current_time( 'mysql' );
		}

		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		// Update all chunks for this source URL
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_updated = %s WHERE source_url = %s",
				$last_updated,
				$source_url
			)
		);

		if ( false === $result ) {
			$this->logger->error(
				'Failed to update content freshness',
				array(
					'source_url' => $source_url,
					'error'      => $wpdb->last_error,
				)
			);
			return false;
		}

		$this->logger->debug(
			'Content freshness updated',
			array(
				'source_url'  => $source_url,
				'last_updated' => $last_updated,
				'chunks_updated' => $result,
			)
		);

		return true;
	}

	/**
	 * Get content freshness for a source URL.
	 *
	 * @since 1.0.0
	 * @param string $source_url Source URL.
	 * @return array|false Freshness data or false if not found.
	 */
	public function get_freshness( $source_url ) {
		if ( empty( $source_url ) ) {
			return false;
		}

		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					source_url,
					MIN(last_updated) as oldest_chunk,
					MAX(last_updated) as newest_chunk,
					COUNT(*) as chunk_count,
					MAX(indexed_at) as last_indexed
				FROM {$table}
				WHERE source_url = %s
				GROUP BY source_url",
				$source_url
			)
		);

		if ( ! $result ) {
			return false;
		}

		return array(
			'source_url'    => $result->source_url,
			'oldest_chunk'  => $result->oldest_chunk,
			'newest_chunk'  => $result->newest_chunk,
			'chunk_count'   => intval( $result->chunk_count ),
			'last_indexed'  => $result->last_indexed,
			'is_fresh'      => $this->is_fresh( $result->last_indexed ),
			'age_days'      => $this->calculate_age_days( $result->last_indexed ),
		);
	}

	/**
	 * Check if content is fresh.
	 *
	 * @since 1.0.0
	 * @param string $last_updated Last updated timestamp.
	 * @param int    $threshold_days Optional. Freshness threshold in days.
	 * @return bool True if fresh, false if stale.
	 */
	public function is_fresh( $last_updated, $threshold_days = null ) {
		if ( empty( $last_updated ) ) {
			return false;
		}

		if ( null === $threshold_days ) {
			$threshold_days = $this->config->get( 'content_freshness_threshold_days', $this->default_freshness_threshold_days );
		}

		$age_days = $this->calculate_age_days( $last_updated );
		return $age_days <= $threshold_days;
	}

	/**
	 * Calculate age of content in days.
	 *
	 * @since 1.0.0
	 * @param string $timestamp Timestamp.
	 * @return int Age in days.
	 */
	public function calculate_age_days( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return 9999; // Very old if no timestamp
		}

		$timestamp_time = strtotime( $timestamp );
		if ( false === $timestamp_time ) {
			return 9999;
		}

		$now = current_time( 'timestamp' );
		$diff_seconds = $now - $timestamp_time;
		$age_days = floor( $diff_seconds / DAY_IN_SECONDS );

		return max( 0, $age_days );
	}

	/**
	 * Get stale content that needs re-indexing.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Query arguments.
	 * @return array Array of stale content sources.
	 */
	public function get_stale_content( $args = array() ) {
		$defaults = array(
			'threshold_days' => null, // null = use config default
			'limit'          => 100,
			'source_type'    => null, // Filter by source type
			'min_chunks'     => 1,    // Minimum chunks per source
		);

		$args = wp_parse_args( $args, $defaults );

		if ( null === $args['threshold_days'] ) {
			$args['threshold_days'] = $this->config->get( 'content_freshness_threshold_days', $this->default_freshness_threshold_days );
		}

		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$threshold_date = date( 'Y-m-d H:i:s', strtotime( "-{$args['threshold_days']} days" ) );

		$query = "SELECT 
			source_url,
			source_type,
			MIN(last_updated) as oldest_chunk,
			MAX(last_updated) as newest_chunk,
			MAX(indexed_at) as last_indexed,
			COUNT(*) as chunk_count
		FROM {$table}
		WHERE (last_updated IS NULL OR last_updated < %s)
		AND source_url != ''";

		$query_params = array( $threshold_date );

		if ( ! empty( $args['source_type'] ) ) {
			$query .= ' AND source_type = %s';
			$query_params[] = $args['source_type'];
		}

		$query .= ' GROUP BY source_url, source_type HAVING chunk_count >= %d';
		$query_params[] = intval( $args['min_chunks'] );

		$query .= ' ORDER BY last_indexed ASC LIMIT %d';
		$query_params[] = intval( $args['limit'] );

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $query_params ),
			ARRAY_A
		);

		$stale_content = array();

		foreach ( $results as $row ) {
			$age_days = $this->calculate_age_days( $row['last_indexed'] );

			$stale_content[] = array(
				'source_url'   => $row['source_url'],
				'source_type'  => $row['source_type'],
				'oldest_chunk' => $row['oldest_chunk'],
				'newest_chunk' => $row['newest_chunk'],
				'last_indexed' => $row['last_indexed'],
				'chunk_count'  => intval( $row['chunk_count'] ),
				'age_days'     => $age_days,
				'is_stale'     => true,
			);
		}

		return $stale_content;
	}

	/**
	 * Check if source content has been updated since last indexing.
	 *
	 * @since 1.0.0
	 * @param string $source_url Source URL.
	 * @param string $source_type Optional. Source type (post, page, product, etc.).
	 * @param int    $source_id   Optional. Source ID.
	 * @return bool|WP_Error True if updated, false if not, WP_Error on failure.
	 */
	public function check_source_updated( $source_url, $source_type = '', $source_id = null ) {
		// Get indexed timestamp
		$freshness = $this->get_freshness( $source_url );
		if ( ! $freshness ) {
			// Not indexed yet
			return true;
		}

		$indexed_timestamp = $freshness['last_indexed'];

		// Get source last modified timestamp
		$source_timestamp = $this->get_source_timestamp( $source_url, $source_type, $source_id );

		if ( is_wp_error( $source_timestamp ) ) {
			return $source_timestamp;
		}

		if ( empty( $source_timestamp ) ) {
			return false; // Can't determine, assume not updated
		}

		// Compare timestamps
		$indexed_time = strtotime( $indexed_timestamp );
		$source_time = strtotime( $source_timestamp );

		if ( false === $indexed_time || false === $source_time ) {
			return false;
		}

		return $source_time > $indexed_time;
	}

	/**
	 * Get source content timestamp.
	 *
	 * @since 1.0.0
	 * @param string $source_url Source URL.
	 * @param string $source_type Optional. Source type.
	 * @param int    $source_id   Optional. Source ID.
	 * @return string|WP_Error Source timestamp or WP_Error on failure.
	 */
	public function get_source_timestamp( $source_url, $source_type = '', $source_id = null ) {
		// Try to get from WordPress post/page/product
		if ( ! empty( $source_id ) ) {
			switch ( $source_type ) {
				case 'post':
				case 'page':
					$post = get_post( $source_id );
					if ( $post ) {
						return $post->post_modified;
					}
					break;

				case 'product':
					if ( class_exists( 'WooCommerce' ) ) {
						$product = wc_get_product( $source_id );
						if ( $product ) {
							return $product->get_date_modified()->date( 'Y-m-d H:i:s' );
						}
					}
					break;
			}
		}

		// Try to get from URL (for external sources)
		if ( ! empty( $source_url ) ) {
			$response = wp_remote_head( $source_url, array(
				'timeout'   => 5,
				'sslverify' => false,
			) );

			if ( ! is_wp_error( $response ) ) {
				$headers = wp_remote_retrieve_headers( $response );
				if ( isset( $headers['last-modified'] ) ) {
					return $headers['last-modified'];
				}
			}
		}

		return '';
	}

	/**
	 * Get freshness statistics.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Query arguments.
	 * @return array Statistics.
	 */
	public function get_freshness_stats( $args = array() ) {
		$defaults = array(
			'threshold_days' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		if ( null === $args['threshold_days'] ) {
			$args['threshold_days'] = $this->config->get( 'content_freshness_threshold_days', $this->default_freshness_threshold_days );
		}

		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$stats = array(
			'total_sources'        => 0,
			'fresh_sources'        => 0,
			'stale_sources'        => 0,
			'average_age_days'     => 0,
			'oldest_content_days'  => 0,
			'newest_content_days'  => 0,
			'by_age_range'         => array(),
		);

		// Get all unique sources
		$sources = $wpdb->get_results(
			"SELECT 
				source_url,
				MAX(last_updated) as last_updated,
				MAX(indexed_at) as last_indexed
			FROM {$table}
			WHERE source_url != ''
			GROUP BY source_url",
			ARRAY_A
		);

		$stats['total_sources'] = count( $sources );

		$threshold_date = strtotime( "-{$args['threshold_days']} days" );
		$ages = array();
		$age_ranges = array(
			'0-7'   => 0,
			'8-30'  => 0,
			'31-90' => 0,
			'91-180' => 0,
			'181+'  => 0,
		);

		foreach ( $sources as $source ) {
			$last_indexed = $source['last_indexed'];
			$age_days = $this->calculate_age_days( $last_indexed );
			$ages[] = $age_days;

			// Categorize by age range
			if ( $age_days <= 7 ) {
				$age_ranges['0-7']++;
			} elseif ( $age_days <= 30 ) {
				$age_ranges['8-30']++;
			} elseif ( $age_days <= 90 ) {
				$age_ranges['31-90']++;
			} elseif ( $age_days <= 180 ) {
				$age_ranges['91-180']++;
			} else {
				$age_ranges['181+']++;
			}

			// Check if fresh
			$indexed_time = strtotime( $last_indexed );
			if ( $indexed_time && $indexed_time >= $threshold_date ) {
				$stats['fresh_sources']++;
			} else {
				$stats['stale_sources']++;
			}
		}

		if ( ! empty( $ages ) ) {
			$stats['average_age_days'] = round( array_sum( $ages ) / count( $ages ), 2 );
			$stats['oldest_content_days'] = max( $ages );
			$stats['newest_content_days'] = min( $ages );
		}

		$stats['by_age_range'] = $age_ranges;

		return $stats;
	}

	/**
	 * Mark content as updated.
	 *
	 * @since 1.0.0
	 * @param string $source_url Source URL.
	 * @param string $timestamp  Optional. Update timestamp. Defaults to current time.
	 * @return bool True on success, false on failure.
	 */
	public function mark_as_updated( $source_url, $timestamp = null ) {
		return $this->update_freshness( $source_url, $timestamp );
	}

	/**
	 * Get content that needs re-indexing based on source updates.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Query arguments.
	 * @return array Array of sources that need re-indexing.
	 */
	public function get_content_needing_reindex( $args = array() ) {
		$defaults = array(
			'limit'       => 100,
			'source_type' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$query = "SELECT DISTINCT source_url, source_type, source_id
			FROM {$table}
			WHERE source_url != ''";

		$query_params = array();

		if ( ! empty( $args['source_type'] ) ) {
			$query .= ' AND source_type = %s';
			$query_params[] = $args['source_type'];
		}

		$query .= ' LIMIT %d';
		$query_params[] = intval( $args['limit'] );

		$sources = $wpdb->get_results(
			$wpdb->prepare( $query, $query_params ),
			ARRAY_A
		);

		$needs_reindex = array();

		foreach ( $sources as $source ) {
			$updated = $this->check_source_updated(
				$source['source_url'],
				$source['source_type'],
				isset( $source['source_id'] ) ? intval( $source['source_id'] ) : null
			);

			if ( is_wp_error( $updated ) ) {
				continue;
			}

			if ( $updated ) {
				$freshness = $this->get_freshness( $source['source_url'] );
				$needs_reindex[] = array(
					'source_url'   => $source['source_url'],
					'source_type'  => $source['source_type'],
					'source_id'    => isset( $source['source_id'] ) ? intval( $source['source_id'] ) : null,
					'last_indexed' => $freshness ? $freshness['last_indexed'] : null,
					'age_days'     => $freshness ? $freshness['age_days'] : 0,
				);
			}
		}

		return $needs_reindex;
	}

	/**
	 * Set freshness threshold in days.
	 *
	 * @since 1.0.0
	 * @param int $days Number of days.
	 */
	public function set_freshness_threshold( $days ) {
		$this->default_freshness_threshold_days = max( 1, intval( $days ) );
	}

	/**
	 * Get freshness threshold in days.
	 *
	 * @since 1.0.0
	 * @return int Number of days.
	 */
	public function get_freshness_threshold() {
		return $this->default_freshness_threshold_days;
	}
}

