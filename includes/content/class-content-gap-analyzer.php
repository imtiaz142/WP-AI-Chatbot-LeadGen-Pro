<?php
/**
 * Content Gap Analyzer.
 *
 * Identifies content gaps and unanswered questions in the knowledge base.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Content_Gap_Analyzer {

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
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
	}

	/**
	 * Get unanswered questions (content gaps).
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Query arguments.
	 * @return array Array of unanswered questions.
	 */
	public function get_unanswered_questions( $args = array() ) {
		$defaults = array(
			'limit'          => 50,
			'min_occurrences' => 2, // Minimum times question was asked
			'days_back'      => 30, // Look back N days
		);

		$args = wp_parse_args( $args, $defaults );

		global $wpdb;

		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();
		$conversations_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$args['days_back']} days" ) );

		// Get messages that didn't have citations (indicating no good answer found)
		// Check if the next assistant message has citations
		$query = "SELECT 
			m1.message_text,
			COUNT(*) as occurrence_count,
			MAX(m1.created_at) as last_asked,
			MIN(m1.created_at) as first_asked
		FROM {$messages_table} m1
		INNER JOIN {$conversations_table} c ON m1.conversation_id = c.id
		LEFT JOIN {$messages_table} m2 ON m2.conversation_id = m1.conversation_id 
			AND m2.role = 'assistant' 
			AND m2.created_at > m1.created_at
			AND m2.citations IS NOT NULL 
			AND m2.citations != ''
		WHERE m1.role = 'user'
		AND m1.created_at >= %s
		AND m2.id IS NULL
		GROUP BY m1.message_text
		HAVING occurrence_count >= %d
		ORDER BY occurrence_count DESC, last_asked DESC
		LIMIT %d";

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $date_threshold, $args['min_occurrences'], $args['limit'] ),
			ARRAY_A
		);

		$unanswered = array();
		foreach ( $results as $row ) {
			$unanswered[] = array(
				'question'         => $row['message_text'],
				'occurrence_count' => intval( $row['occurrence_count'] ),
				'first_asked'      => $row['first_asked'],
				'last_asked'       => $row['last_asked'],
			);
		}

		return $unanswered;
	}

	/**
	 * Get questions with low-quality answers.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Query arguments.
	 * @return array Array of questions with low-quality answers.
	 */
	public function get_low_quality_answers( $args = array() ) {
		$defaults = array(
			'limit'          => 50,
			'min_occurrences' => 2,
			'days_back'      => 30,
			'max_similarity' => 0.7, // Maximum similarity score to consider low quality
		);

		$args = wp_parse_args( $args, $defaults );

		global $wpdb;

		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();
		$conversations_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$args['days_back']} days" ) );

		// Get messages with negative feedback or low similarity scores
		$query = "SELECT 
			m.message_text,
			COUNT(*) as occurrence_count,
			AVG(m.similarity_score) as avg_similarity,
			SUM(CASE WHEN m.feedback = 'negative' THEN 1 ELSE 0 END) as negative_feedback_count,
			MAX(m.created_at) as last_asked
		FROM {$messages_table} m
		INNER JOIN {$conversations_table} c ON m.conversation_id = c.id
		WHERE m.role = 'user'
		AND m.created_at >= %s
		AND (m.similarity_score IS NULL OR m.similarity_score < %f OR m.feedback = 'negative')
		GROUP BY m.message_text
		HAVING occurrence_count >= %d
		ORDER BY negative_feedback_count DESC, occurrence_count DESC
		LIMIT %d";

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $date_threshold, $args['max_similarity'], $args['min_occurrences'], $args['limit'] ),
			ARRAY_A
		);

		$low_quality = array();
		foreach ( $results as $row ) {
			$low_quality[] = array(
				'question'              => $row['message_text'],
				'occurrence_count'      => intval( $row['occurrence_count'] ),
				'avg_similarity'        => floatval( $row['avg_similarity'] ),
				'negative_feedback'     => intval( $row['negative_feedback_count'] ),
				'last_asked'            => $row['last_asked'],
			);
		}

		return $low_quality;
	}

	/**
	 * Get content gap statistics.
	 *
	 * @since 1.0.0
	 * @return array Statistics.
	 */
	public function get_gap_statistics() {
		global $wpdb;

		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();
		$conversations_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$stats = array(
			'total_questions'        => 0,
			'unanswered_questions'   => 0,
			'low_quality_answers'    => 0,
			'answered_questions'     => 0,
			'answer_quality_score'   => 0,
		);

		// Total questions (last 30 days)
		$date_threshold = date( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$stats['total_questions'] = intval(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$messages_table} m
					INNER JOIN {$conversations_table} c ON m.conversation_id = c.id
					WHERE m.role = 'user' AND m.created_at >= %s",
					$date_threshold
				)
			)
		);

		// Unanswered questions (no citations in following assistant message)
		$stats['unanswered_questions'] = intval(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT m1.id) FROM {$messages_table} m1
					INNER JOIN {$conversations_table} c ON m1.conversation_id = c.id
					LEFT JOIN {$messages_table} m2 ON m2.conversation_id = m1.conversation_id 
						AND m2.role = 'assistant' 
						AND m2.created_at > m1.created_at
						AND m2.citations IS NOT NULL 
						AND m2.citations != ''
					WHERE m1.role = 'user' 
					AND m1.created_at >= %s
					AND m2.id IS NULL",
					$date_threshold
				)
			)
		);

		// Low quality answers (negative feedback or low similarity)
		$stats['low_quality_answers'] = intval(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT m.id) FROM {$messages_table} m
					INNER JOIN {$conversations_table} c ON m.conversation_id = c.id
					WHERE m.role = 'user' 
					AND m.created_at >= %s
					AND (m.feedback = 'negative' OR (m.similarity_score IS NOT NULL AND m.similarity_score < 0.7))",
					$date_threshold
				)
			)
		);

		$stats['answered_questions'] = $stats['total_questions'] - $stats['unanswered_questions'];

		// Calculate answer quality score (0-100)
		if ( $stats['total_questions'] > 0 ) {
			$quality_ratio = ( $stats['answered_questions'] - $stats['low_quality_answers'] ) / $stats['total_questions'];
			$stats['answer_quality_score'] = round( $quality_ratio * 100, 1 );
		}

		return $stats;
	}
}

