<?php
/**
 * Feedback Handler.
 *
 * Handles thumbs up/down feedback on AI responses.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Feedback_Handler {

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
	 * Feedback types.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const FEEDBACK_TYPES = array(
		'thumbs_up'   => 1,
		'thumbs_down' => -1,
		'neutral'     => 0,
	);

	/**
	 * Feedback reasons for negative feedback.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const NEGATIVE_REASONS = array(
		'inaccurate'    => 'Information was inaccurate',
		'irrelevant'    => 'Response was not relevant',
		'incomplete'    => 'Response was incomplete',
		'confusing'     => 'Response was confusing',
		'too_long'      => 'Response was too long',
		'too_short'     => 'Response was too short',
		'unhelpful'     => 'Response was not helpful',
		'technical'     => 'Too technical or complex',
		'other'         => 'Other reason',
	);

	/**
	 * Feedback reasons for positive feedback.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const POSITIVE_REASONS = array(
		'accurate'      => 'Information was accurate',
		'helpful'       => 'Very helpful response',
		'clear'         => 'Clear and easy to understand',
		'comprehensive' => 'Comprehensive answer',
		'fast'          => 'Quick and efficient',
		'other'         => 'Other reason',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		$this->maybe_create_feedback_table();
	}

	/**
	 * Create feedback table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_feedback_table() {
		global $wpdb;

		$table_name = $this->get_feedback_table();
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			$sql = "CREATE TABLE {$table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NOT NULL,
				message_id bigint(20) unsigned NOT NULL,
				session_id varchar(64) NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				feedback_type varchar(20) NOT NULL,
				feedback_value tinyint NOT NULL,
				reason varchar(50) DEFAULT NULL,
				comment text DEFAULT NULL,
				query_text text DEFAULT NULL,
				response_text text DEFAULT NULL,
				context_data longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY conversation_id (conversation_id),
				KEY message_id (message_id),
				KEY session_id (session_id),
				KEY feedback_type (feedback_type),
				KEY feedback_value (feedback_value),
				KEY created_at (created_at),
				UNIQUE KEY unique_feedback (message_id, session_id)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	/**
	 * Get feedback table name.
	 *
	 * @since 1.0.0
	 * @return string Table name.
	 */
	public function get_feedback_table() {
		global $wpdb;
		return $wpdb->prefix . 'wp_ai_chatbot_feedback';
	}

	/**
	 * Submit feedback for a message.
	 *
	 * @since 1.0.0
	 * @param int    $message_id  Message ID.
	 * @param string $feedback_type Feedback type (thumbs_up, thumbs_down, neutral).
	 * @param array  $data        Optional. Additional feedback data.
	 * @return int|WP_Error Feedback ID or error.
	 */
	public function submit_feedback( $message_id, $feedback_type, $data = array() ) {
		global $wpdb;

		// Validate feedback type
		if ( ! isset( self::FEEDBACK_TYPES[ $feedback_type ] ) ) {
			return new WP_Error(
				'invalid_feedback_type',
				__( 'Invalid feedback type.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Get message data
		$message = $this->get_message( $message_id );
		if ( ! $message ) {
			return new WP_Error(
				'message_not_found',
				__( 'Message not found.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$session_id = $data['session_id'] ?? $this->get_session_id();

		// Check for existing feedback
		$existing = $this->get_feedback_by_message( $message_id, $session_id );

		$feedback_data = array(
			'conversation_id' => $message['conversation_id'],
			'message_id'      => $message_id,
			'session_id'      => $session_id,
			'user_id'         => get_current_user_id() ?: null,
			'feedback_type'   => $feedback_type,
			'feedback_value'  => self::FEEDBACK_TYPES[ $feedback_type ],
			'reason'          => $data['reason'] ?? null,
			'comment'         => $data['comment'] ?? null,
			'query_text'      => $data['query_text'] ?? null,
			'response_text'   => $message['content'] ?? null,
			'context_data'    => maybe_serialize( $data['context'] ?? array() ),
			'created_at'      => current_time( 'mysql' ),
		);

		if ( $existing ) {
			// Update existing feedback
			$result = $wpdb->update(
				$this->get_feedback_table(),
				$feedback_data,
				array( 'id' => $existing['id'] ),
				array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				return new WP_Error(
					'update_failed',
					__( 'Failed to update feedback.', 'wp-ai-chatbot-leadgen-pro' )
				);
			}

			$feedback_id = $existing['id'];
		} else {
			// Insert new feedback
			$result = $wpdb->insert(
				$this->get_feedback_table(),
				$feedback_data,
				array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $result ) {
				return new WP_Error(
					'insert_failed',
					__( 'Failed to submit feedback.', 'wp-ai-chatbot-leadgen-pro' )
				);
			}

			$feedback_id = $wpdb->insert_id;
		}

		// Update message metadata with feedback
		$this->update_message_feedback( $message_id, $feedback_type, $feedback_data );

		// Log feedback
		$this->logger->info(
			'Feedback submitted',
			array(
				'feedback_id'   => $feedback_id,
				'message_id'    => $message_id,
				'feedback_type' => $feedback_type,
				'reason'        => $data['reason'] ?? null,
			)
		);

		/**
		 * Fires after feedback is submitted.
		 *
		 * @since 1.0.0
		 * @param int    $feedback_id   Feedback ID.
		 * @param int    $message_id    Message ID.
		 * @param string $feedback_type Feedback type.
		 * @param array  $data          Feedback data.
		 */
		do_action( 'wp_ai_chatbot_feedback_submitted', $feedback_id, $message_id, $feedback_type, $feedback_data );

		// Check for patterns that need attention
		$this->check_feedback_patterns( $message['conversation_id'] );

		return $feedback_id;
	}

	/**
	 * Get message by ID.
	 *
	 * @since 1.0.0
	 * @param int $message_id Message ID.
	 * @return array|null Message data or null.
	 */
	private function get_message( $message_id ) {
		global $wpdb;
		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$messages_table} WHERE id = %d",
				$message_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get feedback by message and session.
	 *
	 * @since 1.0.0
	 * @param int    $message_id Message ID.
	 * @param string $session_id Session ID.
	 * @return array|null Feedback data or null.
	 */
	public function get_feedback_by_message( $message_id, $session_id = '' ) {
		global $wpdb;

		if ( empty( $session_id ) ) {
			$session_id = $this->get_session_id();
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_feedback_table()} 
				 WHERE message_id = %d AND session_id = %s",
				$message_id,
				$session_id
			),
			ARRAY_A
		);
	}

	/**
	 * Update message metadata with feedback.
	 *
	 * @since 1.0.0
	 * @param int    $message_id    Message ID.
	 * @param string $feedback_type Feedback type.
	 * @param array  $feedback_data Feedback data.
	 */
	private function update_message_feedback( $message_id, $feedback_type, $feedback_data ) {
		global $wpdb;
		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		// Get current metadata
		$message = $this->get_message( $message_id );
		$metadata = maybe_unserialize( $message['metadata'] ?? '' ) ?: array();

		// Update feedback in metadata
		$metadata['feedback'] = array(
			'type'   => $feedback_type,
			'value'  => self::FEEDBACK_TYPES[ $feedback_type ],
			'reason' => $feedback_data['reason'],
			'time'   => current_time( 'mysql' ),
		);

		$wpdb->update(
			$messages_table,
			array( 'metadata' => maybe_serialize( $metadata ) ),
			array( 'id' => $message_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Check for concerning feedback patterns.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 */
	private function check_feedback_patterns( $conversation_id ) {
		global $wpdb;

		// Check for multiple negative feedbacks in conversation
		$negative_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->get_feedback_table()} 
				 WHERE conversation_id = %d AND feedback_value = -1",
				$conversation_id
			)
		);

		if ( $negative_count >= 3 ) {
			/**
			 * Fires when multiple negative feedbacks are detected.
			 *
			 * @since 1.0.0
			 * @param int $conversation_id Conversation ID.
			 * @param int $negative_count  Number of negative feedbacks.
			 */
			do_action( 'wp_ai_chatbot_negative_feedback_pattern', $conversation_id, $negative_count );

			$this->logger->warning(
				'Multiple negative feedbacks detected',
				array(
					'conversation_id' => $conversation_id,
					'count'           => $negative_count,
				)
			);
		}
	}

	/**
	 * Get feedback for a conversation.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array Feedback list.
	 */
	public function get_conversation_feedback( $conversation_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_feedback_table()} 
				 WHERE conversation_id = %d 
				 ORDER BY created_at DESC",
				$conversation_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get feedback statistics.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Statistics.
	 */
	public function get_statistics( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'days' => 30,
		);
		$args = wp_parse_args( $args, $defaults );

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$args['days']} days" ) );
		$table = $this->get_feedback_table();

		// Total feedback count
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
				$since
			)
		);

		// Positive count
		$positive = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE feedback_value = 1 AND created_at >= %s",
				$since
			)
		);

		// Negative count
		$negative = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE feedback_value = -1 AND created_at >= %s",
				$since
			)
		);

		// Satisfaction rate
		$satisfaction_rate = $total > 0 ? ( $positive / $total ) * 100 : 0;

		// By reason
		$by_reason = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reason, feedback_value, COUNT(*) as count 
				 FROM {$table} 
				 WHERE reason IS NOT NULL AND created_at >= %s 
				 GROUP BY reason, feedback_value 
				 ORDER BY count DESC",
				$since
			),
			ARRAY_A
		);

		// Daily trend
		$daily_trend = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, 
				        SUM(CASE WHEN feedback_value = 1 THEN 1 ELSE 0 END) as positive,
				        SUM(CASE WHEN feedback_value = -1 THEN 1 ELSE 0 END) as negative
				 FROM {$table} 
				 WHERE created_at >= %s 
				 GROUP BY DATE(created_at) 
				 ORDER BY date ASC",
				$since
			),
			ARRAY_A
		);

		return array(
			'total'             => intval( $total ),
			'positive'          => intval( $positive ),
			'negative'          => intval( $negative ),
			'neutral'           => intval( $total ) - intval( $positive ) - intval( $negative ),
			'satisfaction_rate' => round( $satisfaction_rate, 1 ),
			'by_reason'         => $by_reason,
			'daily_trend'       => $daily_trend,
		);
	}

	/**
	 * Get low-rated responses for improvement.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Low-rated responses.
	 */
	public function get_low_rated_responses( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'  => 20,
			'offset' => 0,
			'days'   => 30,
		);
		$args = wp_parse_args( $args, $defaults );

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$args['days']} days" ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, m.content as response_content 
				 FROM {$this->get_feedback_table()} f
				 LEFT JOIN " . WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table() . " m ON f.message_id = m.id
				 WHERE f.feedback_value = -1 AND f.created_at >= %s
				 ORDER BY f.created_at DESC
				 LIMIT %d OFFSET %d",
				$since,
				$args['limit'],
				$args['offset']
			),
			ARRAY_A
		);
	}

	/**
	 * Get common negative feedback reasons.
	 *
	 * @since 1.0.0
	 * @param int $limit Number of reasons to return.
	 * @return array Common reasons.
	 */
	public function get_common_negative_reasons( $limit = 10 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reason, COUNT(*) as count 
				 FROM {$this->get_feedback_table()} 
				 WHERE feedback_value = -1 AND reason IS NOT NULL
				 GROUP BY reason 
				 ORDER BY count DESC 
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get feedback with comments for review.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Feedback with comments.
	 */
	public function get_feedback_with_comments( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'         => 20,
			'offset'        => 0,
			'feedback_type' => '', // '' for all, 'thumbs_up', 'thumbs_down'
		);
		$args = wp_parse_args( $args, $defaults );

		$where = "comment IS NOT NULL AND comment != ''";
		$values = array();

		if ( ! empty( $args['feedback_type'] ) ) {
			$where .= " AND feedback_type = %s";
			$values[] = $args['feedback_type'];
		}

		$values[] = $args['limit'];
		$values[] = $args['offset'];

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_feedback_table()} 
				 WHERE {$where}
				 ORDER BY created_at DESC 
				 LIMIT %d OFFSET %d",
				$values
			),
			ARRAY_A
		);
	}

	/**
	 * Export feedback data.
	 *
	 * @since 1.0.0
	 * @param array $args Export arguments.
	 * @return array Feedback data for export.
	 */
	public function export_feedback( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'start_date' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			'end_date'   => gmdate( 'Y-m-d' ),
			'type'       => '', // '' for all
		);
		$args = wp_parse_args( $args, $defaults );

		$where = "created_at >= %s AND created_at <= %s";
		$values = array(
			$args['start_date'] . ' 00:00:00',
			$args['end_date'] . ' 23:59:59',
		);

		if ( ! empty( $args['type'] ) ) {
			$where .= " AND feedback_type = %s";
			$values[] = $args['type'];
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					id,
					conversation_id,
					message_id,
					feedback_type,
					reason,
					comment,
					query_text,
					response_text,
					created_at
				 FROM {$this->get_feedback_table()} 
				 WHERE {$where}
				 ORDER BY created_at DESC",
				$values
			),
			ARRAY_A
		);
	}

	/**
	 * Get session ID.
	 *
	 * @since 1.0.0
	 * @return string Session ID.
	 */
	private function get_session_id() {
		if ( isset( $_COOKIE['wp_ai_chatbot_session'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['wp_ai_chatbot_session'] ) );
		}
		return wp_generate_uuid4();
	}

	/**
	 * Get available feedback reasons.
	 *
	 * @since 1.0.0
	 * @param string $type Feedback type (thumbs_up, thumbs_down).
	 * @return array Available reasons.
	 */
	public function get_feedback_reasons( $type = 'thumbs_down' ) {
		if ( $type === 'thumbs_up' ) {
			return self::POSITIVE_REASONS;
		}
		return self::NEGATIVE_REASONS;
	}

	/**
	 * Delete feedback.
	 *
	 * @since 1.0.0
	 * @param int $feedback_id Feedback ID.
	 * @return bool True on success.
	 */
	public function delete_feedback( $feedback_id ) {
		global $wpdb;

		$result = $wpdb->delete(
			$this->get_feedback_table(),
			array( 'id' => $feedback_id ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Clean up old feedback data.
	 *
	 * @since 1.0.0
	 * @param int $days Keep feedback for this many days.
	 * @return int Number of deleted records.
	 */
	public function cleanup_old_feedback( $days = 365 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->get_feedback_table()} WHERE created_at < %s",
				$cutoff
			)
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Get feedback summary for a specific query pattern.
	 *
	 * @since 1.0.0
	 * @param string $query_pattern Query pattern to search.
	 * @return array Feedback summary.
	 */
	public function get_query_feedback_summary( $query_pattern ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT feedback_type, COUNT(*) as count 
				 FROM {$this->get_feedback_table()} 
				 WHERE query_text LIKE %s 
				 GROUP BY feedback_type",
				'%' . $wpdb->esc_like( $query_pattern ) . '%'
			),
			ARRAY_A
		);

		$summary = array(
			'thumbs_up'   => 0,
			'thumbs_down' => 0,
			'neutral'     => 0,
			'total'       => 0,
		);

		foreach ( $results as $row ) {
			$summary[ $row['feedback_type'] ] = intval( $row['count'] );
			$summary['total'] += intval( $row['count'] );
		}

		return $summary;
	}
}

