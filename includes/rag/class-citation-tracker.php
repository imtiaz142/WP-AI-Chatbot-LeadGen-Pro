<?php
/**
 * Citation Tracker.
 *
 * Tracks and manages citations for AI-generated responses, linking responses to source content chunks.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/rag
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker {

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
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->database = new WP_AI_Chatbot_LeadGen_Pro_Database();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Record citations for a message.
	 *
	 * @since 1.0.0
	 * @param int   $message_id      Message ID.
	 * @param array $chunk_metadata  Array of chunk metadata from context assembler.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function record_citations( $message_id, $chunk_metadata ) {
		if ( empty( $message_id ) || empty( $chunk_metadata ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Message ID and chunk metadata are required.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Enrich chunk metadata with additional details from database
		$enriched_citations = $this->enrich_citation_metadata( $chunk_metadata );

		// Format citations for storage
		$citations_data = array(
			'chunks'      => $enriched_citations,
			'count'       => count( $enriched_citations ),
			'recorded_at' => current_time( 'mysql' ),
		);

		// Update message with citations
		global $wpdb;
		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$result = $wpdb->update(
			$table,
			array(
				'citations' => wp_json_encode( $citations_data ),
			),
			array( 'id' => intval( $message_id ) ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			$this->logger->error(
				'Failed to record citations',
				array(
					'message_id' => $message_id,
					'error'      => $wpdb->last_error,
				)
			);
			return new WP_Error(
				'db_error',
				__( 'Failed to record citations in database.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return true;
	}

	/**
	 * Get citations for a message.
	 *
	 * @since 1.0.0
	 * @param int $message_id Message ID.
	 * @return array|WP_Error Array of citations or WP_Error on failure.
	 */
	public function get_citations( $message_id ) {
		global $wpdb;
		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$message = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT citations FROM {$table} WHERE id = %d",
				intval( $message_id )
			)
		);

		if ( ! $message ) {
			return new WP_Error(
				'message_not_found',
				__( 'Message not found.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		if ( empty( $message->citations ) ) {
			return array();
		}

		$citations = json_decode( $message->citations, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->logger->warning(
				'Failed to decode citations JSON',
				array(
					'message_id' => $message_id,
					'error'      => json_last_error_msg(),
				)
			);
			return array();
		}

		return isset( $citations['chunks'] ) ? $citations['chunks'] : array();
	}

	/**
	 * Get formatted citations for display.
	 *
	 * @since 1.0.0
	 * @param int   $message_id Message ID.
	 * @param array $args       Optional. Formatting arguments.
	 * @return string|array Formatted citations (string if format='html', array if format='array').
	 */
	public function get_formatted_citations( $message_id, $args = array() ) {
		$defaults = array(
			'format'         => 'html', // 'html', 'array', 'json'
			'show_numbers'   => true,
			'show_scores'    => false,
			'link_target'    => '_blank',
			'separator'      => ', ',
		);

		$args = wp_parse_args( $args, $defaults );

		$citations = $this->get_citations( $message_id );

		if ( is_wp_error( $citations ) || empty( $citations ) ) {
			return $args['format'] === 'array' ? array() : '';
		}

		switch ( $args['format'] ) {
			case 'html':
				return $this->format_citations_html( $citations, $args );

			case 'array':
				return $this->format_citations_array( $citations, $args );

			case 'json':
				return wp_json_encode( $citations );

			default:
				return $this->format_citations_html( $citations, $args );
		}
	}

	/**
	 * Format citations as HTML.
	 *
	 * @since 1.0.0
	 * @param array $citations Citation data.
	 * @param array $args      Formatting arguments.
	 * @return string HTML formatted citations.
	 */
	private function format_citations_html( $citations, $args ) {
		$formatted = array();

		foreach ( $citations as $index => $citation ) {
			$parts = array();

			// Citation number
			if ( $args['show_numbers'] ) {
				$parts[] = sprintf( '<span class="citation-number">[%d]</span>', $index + 1 );
			}

			// Source link
			$source_url = isset( $citation['source_url'] ) ? esc_url( $citation['source_url'] ) : '';
			$source_title = isset( $citation['title'] ) ? esc_html( $citation['title'] ) : esc_html( $source_url );

			if ( ! empty( $source_url ) ) {
				$link = sprintf(
					'<a href="%s" target="%s" rel="noopener noreferrer" class="citation-link">%s</a>',
					$source_url,
					esc_attr( $args['link_target'] ),
					$source_title
				);
				$parts[] = $link;
			} else {
				$parts[] = sprintf( '<span class="citation-title">%s</span>', $source_title );
			}

			// Score (if requested)
			if ( $args['show_scores'] ) {
				$score = isset( $citation['rerank_score'] ) ? floatval( $citation['rerank_score'] ) : ( isset( $citation['score'] ) ? floatval( $citation['score'] ) : 0 );
				$parts[] = sprintf( '<span class="citation-score">(%.2f)</span>', $score );
			}

			$formatted[] = '<span class="citation-item">' . implode( ' ', $parts ) . '</span>';
		}

		return '<div class="citations">' . implode( $args['separator'], $formatted ) . '</div>';
	}

	/**
	 * Format citations as array.
	 *
	 * @since 1.0.0
	 * @param array $citations Citation data.
	 * @param array $args      Formatting arguments.
	 * @return array Formatted citations array.
	 */
	private function format_citations_array( $citations, $args ) {
		$formatted = array();

		foreach ( $citations as $index => $citation ) {
			$item = array(
				'number'     => $args['show_numbers'] ? $index + 1 : null,
				'chunk_id'   => isset( $citation['chunk_id'] ) ? intval( $citation['chunk_id'] ) : 0,
				'source_url' => isset( $citation['source_url'] ) ? $citation['source_url'] : '',
				'title'      => isset( $citation['title'] ) ? $citation['title'] : '',
				'source_type' => isset( $citation['source_type'] ) ? $citation['source_type'] : '',
			);

			if ( $args['show_scores'] ) {
				$item['score'] = isset( $citation['rerank_score'] ) ? floatval( $citation['rerank_score'] ) : ( isset( $citation['score'] ) ? floatval( $citation['score'] ) : 0 );
			}

			$formatted[] = $item;
		}

		return $formatted;
	}

	/**
	 * Enrich citation metadata with additional details from database.
	 *
	 * @since 1.0.0
	 * @param array $chunk_metadata Chunk metadata from context assembler.
	 * @return array Enriched citation metadata.
	 */
	private function enrich_citation_metadata( $chunk_metadata ) {
		if ( empty( $chunk_metadata ) ) {
			return array();
		}

		global $wpdb;
		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$enriched = array();

		foreach ( $chunk_metadata as $chunk ) {
			$chunk_id = isset( $chunk['chunk_id'] ) ? intval( $chunk['chunk_id'] ) : 0;

			if ( empty( $chunk_id ) ) {
				continue;
			}

			// Get additional chunk details from database
			$chunk_data = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT source_type, source_url, source_id, chunk_index FROM {$chunks_table} WHERE id = %d",
					$chunk_id
				)
			);

			$citation = array(
				'chunk_id'    => $chunk_id,
				'source_url'  => isset( $chunk['source_url'] ) ? $chunk['source_url'] : '',
				'title'       => isset( $chunk['title'] ) ? $chunk['title'] : '',
				'source_type' => isset( $chunk['source_type'] ) ? $chunk['source_type'] : '',
				'score'       => isset( $chunk['score'] ) ? floatval( $chunk['score'] ) : 0,
				'rerank_score' => isset( $chunk['rerank_score'] ) ? floatval( $chunk['rerank_score'] ) : 0,
			);

			// Enrich with database data if available
			if ( $chunk_data ) {
				if ( empty( $citation['source_url'] ) && ! empty( $chunk_data->source_url ) ) {
					$citation['source_url'] = $chunk_data->source_url;
				}

				if ( empty( $citation['source_type'] ) && ! empty( $chunk_data->source_type ) ) {
					$citation['source_type'] = $chunk_data->source_type;
				}

				$citation['chunk_index'] = isset( $chunk_data->chunk_index ) ? intval( $chunk_data->chunk_index ) : 0;
				$citation['source_id'] = isset( $chunk_data->source_id ) ? intval( $chunk_data->source_id ) : 0;
			}

			// Try to get title from source if not available
			if ( empty( $citation['title'] ) && ! empty( $citation['source_id'] ) && ! empty( $citation['source_type'] ) ) {
				$citation['title'] = $this->get_source_title( $citation['source_type'], $citation['source_id'] );
			}

			// Fallback to URL as title
			if ( empty( $citation['title'] ) && ! empty( $citation['source_url'] ) ) {
				$citation['title'] = $this->extract_title_from_url( $citation['source_url'] );
			}

			$enriched[] = $citation;
		}

		return $enriched;
	}

	/**
	 * Get source title based on source type and ID.
	 *
	 * @since 1.0.0
	 * @param string $source_type Source type (post, page, product, etc.).
	 * @param int    $source_id   Source ID.
	 * @return string Source title or empty string.
	 */
	private function get_source_title( $source_type, $source_id ) {
		if ( empty( $source_type ) || empty( $source_id ) ) {
			return '';
		}

		switch ( $source_type ) {
			case 'post':
			case 'page':
				$post = get_post( intval( $source_id ) );
				return $post ? $post->post_title : '';

			case 'product':
				if ( function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( intval( $source_id ) );
					return $product ? $product->get_name() : '';
				}
				break;

			case 'custom':
				// For custom sources, try to get from post meta or custom table
				$title = get_post_meta( intval( $source_id ), '_source_title', true );
				return $title ? $title : '';

			default:
				break;
		}

		return '';
	}

	/**
	 * Extract title from URL.
	 *
	 * @since 1.0.0
	 * @param string $url URL.
	 * @return string Extracted title or URL.
	 */
	private function extract_title_from_url( $url ) {
		if ( empty( $url ) ) {
			return '';
		}

		// Try to get page title from URL
		$parsed = wp_parse_url( $url );

		if ( ! empty( $parsed['path'] ) ) {
			// Try to match WordPress post/page by path
			$path = trim( $parsed['path'], '/' );
			$post = get_page_by_path( $path );

			if ( $post ) {
				return $post->post_title;
			}
		}

		// Fallback: use domain + path
		if ( ! empty( $parsed['host'] ) ) {
			$path = isset( $parsed['path'] ) ? $parsed['path'] : '/';
			return $parsed['host'] . $path;
		}

		return $url;
	}

	/**
	 * Get citation statistics for a conversation.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array Citation statistics.
	 */
	public function get_conversation_citation_stats( $conversation_id ) {
		global $wpdb;
		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, citations FROM {$table} WHERE conversation_id = %d AND role = 'assistant' AND citations IS NOT NULL AND citations != ''",
				intval( $conversation_id )
			)
		);

		$stats = array(
			'total_messages'     => 0,
			'messages_with_citations' => 0,
			'total_citations'    => 0,
			'unique_sources'     => array(),
			'source_counts'      => array(),
		);

		foreach ( $messages as $message ) {
			$stats['total_messages']++;

			if ( empty( $message->citations ) ) {
				continue;
			}

			$citations = json_decode( $message->citations, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				continue;
			}

			if ( ! isset( $citations['chunks'] ) || empty( $citations['chunks'] ) ) {
				continue;
			}

			$stats['messages_with_citations']++;
			$stats['total_citations'] += count( $citations['chunks'] );

			// Track unique sources
			foreach ( $citations['chunks'] as $citation ) {
				$source_url = isset( $citation['source_url'] ) ? $citation['source_url'] : '';

				if ( ! empty( $source_url ) ) {
					if ( ! in_array( $source_url, $stats['unique_sources'], true ) ) {
						$stats['unique_sources'][] = $source_url;
					}

					if ( ! isset( $stats['source_counts'][ $source_url ] ) ) {
						$stats['source_counts'][ $source_url ] = 0;
					}

					$stats['source_counts'][ $source_url ]++;
				}
			}
		}

		$stats['unique_sources_count'] = count( $stats['unique_sources'] );
		$stats['avg_citations_per_message'] = $stats['messages_with_citations'] > 0 
			? round( $stats['total_citations'] / $stats['messages_with_citations'], 2 ) 
			: 0;

		return $stats;
	}

	/**
	 * Get most cited sources across all conversations.
	 *
	 * @since 1.0.0
	 * @param int $limit Optional. Number of sources to return. Default 10.
	 * @return array Array of sources with citation counts.
	 */
	public function get_most_cited_sources( $limit = 10 ) {
		global $wpdb;
		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$messages = $wpdb->get_results(
			"SELECT citations FROM {$table} WHERE role = 'assistant' AND citations IS NOT NULL AND citations != ''"
		);

		$source_counts = array();

		foreach ( $messages as $message ) {
			$citations = json_decode( $message->citations, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $citations['chunks'] ) ) {
				continue;
			}

			foreach ( $citations['chunks'] as $citation ) {
				$source_url = isset( $citation['source_url'] ) ? $citation['source_url'] : '';

				if ( ! empty( $source_url ) ) {
					if ( ! isset( $source_counts[ $source_url ] ) ) {
						$source_counts[ $source_url ] = array(
							'url'    => $source_url,
							'title'  => isset( $citation['title'] ) ? $citation['title'] : '',
							'count'  => 0,
						);
					}

					$source_counts[ $source_url ]['count']++;
				}
			}
		}

		// Sort by count descending
		usort( $source_counts, function( $a, $b ) {
			return $b['count'] <=> $a['count'];
		} );

		return array_slice( $source_counts, 0, intval( $limit ) );
	}
}

