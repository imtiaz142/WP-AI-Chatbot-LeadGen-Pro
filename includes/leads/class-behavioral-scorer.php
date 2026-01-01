<?php
/**
 * Behavioral Scorer.
 *
 * Calculates behavioral scores based on user engagement metrics
 * including message count, session duration, pages viewed, and return visits.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Behavioral_Scorer {

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
	 * Behavior tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker
	 */
	private $behavior_tracker;

	/**
	 * Scoring weights.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $weights;

	/**
	 * Scoring thresholds.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $thresholds;

	/**
	 * Default weights configuration.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const DEFAULT_WEIGHTS = array(
		// Chat engagement (max 30 points)
		'messages_sent'       => 4,    // Points per message (max 20)
		'messages_received'   => 1,    // Points per response (max 10)
		'chat_opens'          => 2,    // Points per chat open (max 6)
		
		// Page engagement (max 25 points)
		'pages_viewed'        => 2,    // Points per page (max 10)
		'unique_pages'        => 3,    // Points per unique page (max 15)
		
		// Time engagement (max 20 points)
		'session_duration'    => 0.5,  // Points per minute (max 15)
		'avg_time_per_page'   => 1,    // Points per 30 seconds avg (max 5)
		
		// Return visits (max 15 points)
		'return_visits'       => 5,    // Points per return visit (max 15)
		
		// Scroll depth (max 10 points)
		'scroll_depth'        => 0.1,  // Points per percentage (max 10)
	);

	/**
	 * Default thresholds configuration.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const DEFAULT_THRESHOLDS = array(
		// Maximum points caps
		'max_message_points'     => 20,
		'max_response_points'    => 10,
		'max_chat_open_points'   => 6,
		'max_page_points'        => 10,
		'max_unique_page_points' => 15,
		'max_duration_points'    => 15,
		'max_avg_time_points'    => 5,
		'max_return_points'      => 15,
		'max_scroll_points'      => 10,
		
		// Diminishing returns thresholds
		'message_diminish_after' => 5,   // Reduce weight after N messages
		'page_diminish_after'    => 5,   // Reduce weight after N pages
		
		// Minimum thresholds for scoring
		'min_duration_seconds'   => 10,  // Don't score sessions < 10 seconds
		'min_scroll_depth'       => 10,  // Don't score scroll < 10%
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		$this->load_config();
	}

	/**
	 * Load scoring configuration.
	 *
	 * @since 1.0.0
	 */
	private function load_config() {
		$saved_weights = $this->config->get( 'behavioral_scoring_weights', array() );
		$saved_thresholds = $this->config->get( 'behavioral_scoring_thresholds', array() );

		$this->weights = wp_parse_args( $saved_weights, self::DEFAULT_WEIGHTS );
		$this->thresholds = wp_parse_args( $saved_thresholds, self::DEFAULT_THRESHOLDS );
	}

	/**
	 * Calculate behavioral score for a session.
	 *
	 * @since 1.0.0
	 * @param array $behavior Behavior data from tracker.
	 * @return array Score and breakdown.
	 */
	public function calculate_score( $behavior ) {
		$breakdown = array();
		$total_score = 0;

		// Calculate each component
		$breakdown['chat_engagement'] = $this->calculate_chat_score( $behavior );
		$breakdown['page_engagement'] = $this->calculate_page_score( $behavior );
		$breakdown['time_engagement'] = $this->calculate_time_score( $behavior );
		$breakdown['return_visits'] = $this->calculate_return_score( $behavior );
		$breakdown['scroll_engagement'] = $this->calculate_scroll_score( $behavior );

		// Sum all components
		foreach ( $breakdown as $component ) {
			$total_score += $component['score'];
		}

		// Apply any global modifiers
		$total_score = $this->apply_modifiers( $total_score, $behavior, $breakdown );

		// Ensure score is between 0 and 100
		$total_score = max( 0, min( 100, round( $total_score ) ) );

		return array(
			'score'     => $total_score,
			'breakdown' => $breakdown,
			'grade'     => $this->get_grade( $total_score ),
			'signals'   => $this->extract_signals( $behavior, $breakdown ),
		);
	}

	/**
	 * Calculate chat engagement score.
	 *
	 * @since 1.0.0
	 * @param array $behavior Behavior data.
	 * @return array Score breakdown.
	 */
	private function calculate_chat_score( $behavior ) {
		$messages_sent = intval( $behavior['messages_sent'] ?? 0 );
		$messages_received = intval( $behavior['messages_received'] ?? 0 );
		$chat_opens = intval( $behavior['chat_opened_count'] ?? 0 );

		// Messages sent with diminishing returns
		$message_score = $this->calculate_with_diminishing_returns(
			$messages_sent,
			$this->weights['messages_sent'],
			$this->thresholds['message_diminish_after'],
			$this->thresholds['max_message_points']
		);

		// Messages received (indicates engagement)
		$response_score = min(
			$messages_received * $this->weights['messages_received'],
			$this->thresholds['max_response_points']
		);

		// Chat opens
		$open_score = min(
			$chat_opens * $this->weights['chat_opens'],
			$this->thresholds['max_chat_open_points']
		);

		$total = $message_score + $response_score + $open_score;

		return array(
			'score'   => round( $total, 1 ),
			'details' => array(
				'messages_sent'     => $messages_sent,
				'messages_received' => $messages_received,
				'chat_opens'        => $chat_opens,
				'message_score'     => round( $message_score, 1 ),
				'response_score'    => round( $response_score, 1 ),
				'open_score'        => round( $open_score, 1 ),
			),
		);
	}

	/**
	 * Calculate page engagement score.
	 *
	 * @since 1.0.0
	 * @param array $behavior Behavior data.
	 * @return array Score breakdown.
	 */
	private function calculate_page_score( $behavior ) {
		$pages_viewed = intval( $behavior['pages_viewed'] ?? 0 );
		$unique_pages = intval( $behavior['unique_pages'] ?? 0 );

		// Total page views with diminishing returns
		$page_score = $this->calculate_with_diminishing_returns(
			$pages_viewed,
			$this->weights['pages_viewed'],
			$this->thresholds['page_diminish_after'],
			$this->thresholds['max_page_points']
		);

		// Unique pages (valued higher)
		$unique_score = min(
			$unique_pages * $this->weights['unique_pages'],
			$this->thresholds['max_unique_page_points']
		);

		$total = $page_score + $unique_score;

		return array(
			'score'   => round( $total, 1 ),
			'details' => array(
				'pages_viewed' => $pages_viewed,
				'unique_pages' => $unique_pages,
				'page_score'   => round( $page_score, 1 ),
				'unique_score' => round( $unique_score, 1 ),
			),
		);
	}

	/**
	 * Calculate time engagement score.
	 *
	 * @since 1.0.0
	 * @param array $behavior Behavior data.
	 * @return array Score breakdown.
	 */
	private function calculate_time_score( $behavior ) {
		$total_duration = intval( $behavior['total_duration'] ?? 0 );
		$pages_viewed = max( 1, intval( $behavior['pages_viewed'] ?? 1 ) );
		$avg_time_per_page = intval( $behavior['avg_time_on_page'] ?? ( $total_duration / $pages_viewed ) );

		// Skip scoring for very short sessions
		if ( $total_duration < $this->thresholds['min_duration_seconds'] ) {
			return array(
				'score'   => 0,
				'details' => array(
					'total_duration'     => $total_duration,
					'avg_time_per_page'  => $avg_time_per_page,
					'duration_score'     => 0,
					'avg_time_score'     => 0,
					'skipped'            => true,
				),
			);
		}

		// Duration in minutes
		$duration_minutes = $total_duration / 60;
		$duration_score = min(
			$duration_minutes * $this->weights['session_duration'],
			$this->thresholds['max_duration_points']
		);

		// Average time per page (30-second units)
		$avg_time_units = $avg_time_per_page / 30;
		$avg_time_score = min(
			$avg_time_units * $this->weights['avg_time_per_page'],
			$this->thresholds['max_avg_time_points']
		);

		$total = $duration_score + $avg_time_score;

		return array(
			'score'   => round( $total, 1 ),
			'details' => array(
				'total_duration'    => $total_duration,
				'duration_minutes'  => round( $duration_minutes, 1 ),
				'avg_time_per_page' => $avg_time_per_page,
				'duration_score'    => round( $duration_score, 1 ),
				'avg_time_score'    => round( $avg_time_score, 1 ),
			),
		);
	}

	/**
	 * Calculate return visit score.
	 *
	 * @since 1.0.0
	 * @param array $behavior Behavior data.
	 * @return array Score breakdown.
	 */
	private function calculate_return_score( $behavior ) {
		$session_count = intval( $behavior['session_count'] ?? 1 );
		$return_visits = max( 0, $session_count - 1 );

		$score = min(
			$return_visits * $this->weights['return_visits'],
			$this->thresholds['max_return_points']
		);

		return array(
			'score'   => round( $score, 1 ),
			'details' => array(
				'session_count' => $session_count,
				'return_visits' => $return_visits,
			),
		);
	}

	/**
	 * Calculate scroll engagement score.
	 *
	 * @since 1.0.0
	 * @param array $behavior Behavior data.
	 * @return array Score breakdown.
	 */
	private function calculate_scroll_score( $behavior ) {
		$max_scroll = intval( $behavior['max_scroll_depth'] ?? 0 );

		// Skip scoring for minimal scrolling
		if ( $max_scroll < $this->thresholds['min_scroll_depth'] ) {
			return array(
				'score'   => 0,
				'details' => array(
					'max_scroll_depth' => $max_scroll,
					'skipped'          => true,
				),
			);
		}

		$score = min(
			$max_scroll * $this->weights['scroll_depth'],
			$this->thresholds['max_scroll_points']
		);

		return array(
			'score'   => round( $score, 1 ),
			'details' => array(
				'max_scroll_depth' => $max_scroll,
			),
		);
	}

	/**
	 * Calculate score with diminishing returns.
	 *
	 * @since 1.0.0
	 * @param int   $count          Current count.
	 * @param float $weight         Weight per item.
	 * @param int   $diminish_after Reduce weight after this count.
	 * @param float $max_score      Maximum score cap.
	 * @return float Calculated score.
	 */
	private function calculate_with_diminishing_returns( $count, $weight, $diminish_after, $max_score ) {
		if ( $count <= 0 ) {
			return 0;
		}

		$score = 0;

		// Full weight for items up to threshold
		$full_weight_count = min( $count, $diminish_after );
		$score += $full_weight_count * $weight;

		// Half weight for items after threshold
		if ( $count > $diminish_after ) {
			$remaining = $count - $diminish_after;
			$score += $remaining * ( $weight * 0.5 );
		}

		return min( $score, $max_score );
	}

	/**
	 * Apply global modifiers to score.
	 *
	 * @since 1.0.0
	 * @param float $score     Current score.
	 * @param array $behavior  Behavior data.
	 * @param array $breakdown Score breakdown.
	 * @return float Modified score.
	 */
	private function apply_modifiers( $score, $behavior, $breakdown ) {
		// Bonus for completing specific actions
		$bonus = 0;

		// Completing a form
		if ( intval( $behavior['forms_completed'] ?? 0 ) > 0 ) {
			$bonus += 5;
		}

		// Downloading a file
		if ( intval( $behavior['files_downloaded'] ?? 0 ) > 0 ) {
			$bonus += 3;
		}

		// Booking a meeting (highest value action)
		if ( intval( $behavior['meetings_booked'] ?? 0 ) > 0 ) {
			$bonus += 10;
		}

		// Giving feedback (shows engagement)
		if ( intval( $behavior['feedback_given'] ?? 0 ) > 0 ) {
			$bonus += 2;
		}

		// Penalty for very short sessions with chat engagement
		// (potential spam or accidental clicks)
		$duration = intval( $behavior['total_duration'] ?? 0 );
		$messages = intval( $behavior['messages_sent'] ?? 0 );
		if ( $messages > 3 && $duration < 30 ) {
			$score = $score * 0.5; // 50% penalty
		}

		return $score + $bonus;
	}

	/**
	 * Get grade for score.
	 *
	 * @since 1.0.0
	 * @param int $score Behavioral score.
	 * @return array Grade info.
	 */
	private function get_grade( $score ) {
		if ( $score >= 80 ) {
			return array(
				'letter' => 'A',
				'label'  => __( 'Highly Engaged', 'wp-ai-chatbot-leadgen-pro' ),
				'color'  => '#22c55e', // Green
			);
		} elseif ( $score >= 60 ) {
			return array(
				'letter' => 'B',
				'label'  => __( 'Engaged', 'wp-ai-chatbot-leadgen-pro' ),
				'color'  => '#84cc16', // Lime
			);
		} elseif ( $score >= 40 ) {
			return array(
				'letter' => 'C',
				'label'  => __( 'Moderate Engagement', 'wp-ai-chatbot-leadgen-pro' ),
				'color'  => '#eab308', // Yellow
			);
		} elseif ( $score >= 20 ) {
			return array(
				'letter' => 'D',
				'label'  => __( 'Low Engagement', 'wp-ai-chatbot-leadgen-pro' ),
				'color'  => '#f97316', // Orange
			);
		} else {
			return array(
				'letter' => 'F',
				'label'  => __( 'Minimal Engagement', 'wp-ai-chatbot-leadgen-pro' ),
				'color'  => '#ef4444', // Red
			);
		}
	}

	/**
	 * Extract behavioral signals from data.
	 *
	 * @since 1.0.0
	 * @param array $behavior  Behavior data.
	 * @param array $breakdown Score breakdown.
	 * @return array Signals.
	 */
	private function extract_signals( $behavior, $breakdown ) {
		$signals = array();

		// High chat engagement
		if ( intval( $behavior['messages_sent'] ?? 0 ) >= 5 ) {
			$signals[] = array(
				'type'   => 'positive',
				'signal' => 'high_chat_engagement',
				'label'  => __( 'Active chat user', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Return visitor
		if ( intval( $behavior['session_count'] ?? 1 ) > 1 ) {
			$signals[] = array(
				'type'   => 'positive',
				'signal' => 'return_visitor',
				'label'  => sprintf(
					/* translators: %d: number of visits */
					__( 'Returned %d times', 'wp-ai-chatbot-leadgen-pro' ),
					intval( $behavior['session_count'] )
				),
			);
		}

		// Long session duration
		$duration_minutes = intval( $behavior['total_duration'] ?? 0 ) / 60;
		if ( $duration_minutes >= 10 ) {
			$signals[] = array(
				'type'   => 'positive',
				'signal' => 'long_session',
				'label'  => sprintf(
					/* translators: %d: minutes */
					__( 'Spent %d+ minutes', 'wp-ai-chatbot-leadgen-pro' ),
					floor( $duration_minutes )
				),
			);
		}

		// Deep scroll
		if ( intval( $behavior['max_scroll_depth'] ?? 0 ) >= 75 ) {
			$signals[] = array(
				'type'   => 'positive',
				'signal' => 'deep_scroll',
				'label'  => __( 'Read content thoroughly', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Multiple pages
		if ( intval( $behavior['unique_pages'] ?? 0 ) >= 5 ) {
			$signals[] = array(
				'type'   => 'positive',
				'signal' => 'multi_page',
				'label'  => sprintf(
					/* translators: %d: number of pages */
					__( 'Viewed %d pages', 'wp-ai-chatbot-leadgen-pro' ),
					intval( $behavior['unique_pages'] )
				),
			);
		}

		// File downloads
		if ( intval( $behavior['files_downloaded'] ?? 0 ) > 0 ) {
			$signals[] = array(
				'type'   => 'positive',
				'signal' => 'downloaded_content',
				'label'  => __( 'Downloaded content', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Meeting booked
		if ( intval( $behavior['meetings_booked'] ?? 0 ) > 0 ) {
			$signals[] = array(
				'type'   => 'high_intent',
				'signal' => 'meeting_booked',
				'label'  => __( 'Booked a meeting', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Negative signals
		// Quick bounce
		if ( $duration_minutes < 0.5 && intval( $behavior['pages_viewed'] ?? 0 ) <= 1 ) {
			$signals[] = array(
				'type'   => 'negative',
				'signal' => 'quick_bounce',
				'label'  => __( 'Quick bounce', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		return $signals;
	}

	/**
	 * Score a lead based on their behavior.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array|null Score data or null if no behavior found.
	 */
	public function score_lead( $lead_id ) {
		if ( ! class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker' ) ) {
			return null;
		}

		$tracker = new WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker();
		$behavior = $tracker->get_behavior_by_lead( $lead_id );

		if ( ! $behavior ) {
			return null;
		}

		return $this->calculate_score( $behavior );
	}

	/**
	 * Score a session.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @return array|null Score data or null if no behavior found.
	 */
	public function score_session( $session_id ) {
		if ( ! class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker' ) ) {
			return null;
		}

		$tracker = new WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker();
		$behavior = $tracker->get_behavior( $session_id );

		if ( ! $behavior ) {
			return null;
		}

		return $this->calculate_score( $behavior );
	}

	/**
	 * Get scoring configuration.
	 *
	 * @since 1.0.0
	 * @return array Configuration.
	 */
	public function get_config() {
		return array(
			'weights'    => $this->weights,
			'thresholds' => $this->thresholds,
		);
	}

	/**
	 * Update scoring configuration.
	 *
	 * @since 1.0.0
	 * @param array $weights    Weight configuration.
	 * @param array $thresholds Threshold configuration.
	 * @return bool True on success.
	 */
	public function update_config( $weights = array(), $thresholds = array() ) {
		if ( ! empty( $weights ) ) {
			$this->weights = wp_parse_args( $weights, self::DEFAULT_WEIGHTS );
			$this->config->set( 'behavioral_scoring_weights', $this->weights );
		}

		if ( ! empty( $thresholds ) ) {
			$this->thresholds = wp_parse_args( $thresholds, self::DEFAULT_THRESHOLDS );
			$this->config->set( 'behavioral_scoring_thresholds', $this->thresholds );
		}

		return true;
	}

	/**
	 * Get default weights.
	 *
	 * @since 1.0.0
	 * @return array Default weights.
	 */
	public function get_default_weights() {
		return self::DEFAULT_WEIGHTS;
	}

	/**
	 * Get default thresholds.
	 *
	 * @since 1.0.0
	 * @return array Default thresholds.
	 */
	public function get_default_thresholds() {
		return self::DEFAULT_THRESHOLDS;
	}

	/**
	 * Batch score multiple leads.
	 *
	 * @since 1.0.0
	 * @param array $lead_ids Lead IDs.
	 * @return array Scores indexed by lead ID.
	 */
	public function batch_score( $lead_ids ) {
		$scores = array();

		foreach ( $lead_ids as $lead_id ) {
			$score = $this->score_lead( $lead_id );
			if ( $score ) {
				$scores[ $lead_id ] = $score;
			}
		}

		return $scores;
	}

	/**
	 * Get score distribution for analytics.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Distribution data.
	 */
	public function get_score_distribution( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'date_from' => date( 'Y-m-d', strtotime( '-30 days' ) ),
			'date_to'   => date( 'Y-m-d' ),
		);
		$args = wp_parse_args( $args, $defaults );

		$table = $wpdb->prefix . 'ai_chatbot_behavior';

		$distribution = $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				CASE 
					WHEN engagement_score >= 80 THEN 'A (80-100)'
					WHEN engagement_score >= 60 THEN 'B (60-79)'
					WHEN engagement_score >= 40 THEN 'C (40-59)'
					WHEN engagement_score >= 20 THEN 'D (20-39)'
					ELSE 'F (0-19)'
				END as grade,
				COUNT(*) as count
			FROM {$table}
			WHERE created_at BETWEEN %s AND %s
			GROUP BY grade
			ORDER BY grade",
			$args['date_from'],
			$args['date_to'] . ' 23:59:59'
		), ARRAY_A );

		return $distribution ?: array();
	}
}

