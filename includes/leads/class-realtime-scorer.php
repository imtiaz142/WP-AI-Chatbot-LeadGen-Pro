<?php
/**
 * Real-time Lead Scorer.
 *
 * Updates lead scores in real-time as conversations progress,
 * triggering rescores based on events and providing live updates.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Realtime_Scorer {

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
	 * Lead scorer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Scorer
	 */
	private $lead_scorer;

	/**
	 * Lead grader instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Grader
	 */
	private $lead_grader;

	/**
	 * Behavior tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker
	 */
	private $behavior_tracker;

	/**
	 * Events that trigger immediate rescore.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const IMMEDIATE_RESCORE_EVENTS = array(
		'lead_captured',
		'meeting_booked',
		'pricing_inquiry',
		'demo_requested',
		'trial_started',
		'form_completed',
		'high_intent_detected',
	);

	/**
	 * Events that trigger deferred rescore.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const DEFERRED_RESCORE_EVENTS = array(
		'message_sent',
		'page_view',
		'scroll_depth',
		'link_clicked',
		'file_downloaded',
	);

	/**
	 * Score change thresholds for notifications.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const NOTIFICATION_THRESHOLDS = array(
		'score_increase' => 10, // Notify if score increases by 10+
		'score_decrease' => 15, // Notify if score decreases by 15+
		'grade_upgrade'  => true, // Always notify on grade upgrade
		'hot_lead'       => true, // Always notify on hot lead
	);

	/**
	 * Score cache transient prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_ai_chatbot_score_';

	/**
	 * Pending rescore queue.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $pending_rescores = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		$this->init_dependencies();
		$this->init_hooks();
	}

	/**
	 * Initialize dependencies.
	 *
	 * @since 1.0.0
	 */
	private function init_dependencies() {
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Scorer' ) ) {
			$this->lead_scorer = new WP_AI_Chatbot_LeadGen_Pro_Lead_Scorer();
		}

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Grader' ) ) {
			$this->lead_grader = new WP_AI_Chatbot_LeadGen_Pro_Lead_Grader();
		}

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker' ) ) {
			$this->behavior_tracker = new WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker();
		}
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Immediate rescore events
		add_action( 'wp_ai_chatbot_lead_created', array( $this, 'on_lead_created' ), 5, 2 );
		add_action( 'wp_ai_chatbot_meeting_booked', array( $this, 'on_meeting_booked' ), 10, 2 );
		add_action( 'wp_ai_chatbot_intent_detected', array( $this, 'on_intent_detected' ), 10, 3 );
		add_action( 'wp_ai_chatbot_form_completed', array( $this, 'on_form_completed' ), 10, 2 );

		// Deferred rescore events
		add_action( 'wp_ai_chatbot_message_processed', array( $this, 'on_message_processed' ), 10, 3 );
		add_action( 'wp_ai_chatbot_behavior_event', array( $this, 'on_behavior_event' ), 10, 3 );

		// Process pending rescores at shutdown
		add_action( 'shutdown', array( $this, 'process_pending_rescores' ) );

		// AJAX handlers
		add_action( 'wp_ajax_wp_ai_chatbot_get_realtime_score', array( $this, 'ajax_get_score' ) );
		add_action( 'wp_ajax_nopriv_wp_ai_chatbot_get_realtime_score', array( $this, 'ajax_get_score' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_subscribe_score_updates', array( $this, 'ajax_subscribe_updates' ) );
		add_action( 'wp_ajax_nopriv_wp_ai_chatbot_subscribe_score_updates', array( $this, 'ajax_subscribe_updates' ) );

		// Scheduled rescore
		add_action( 'wp_ai_chatbot_scheduled_rescore', array( $this, 'execute_scheduled_rescore' ) );
	}

	/**
	 * Handle lead created event.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id   Lead ID.
	 * @param array $lead_data Lead data.
	 */
	public function on_lead_created( $lead_id, $lead_data ) {
		// Immediate initial scoring
		$this->score_lead( $lead_id, 'lead_created', array(
			'immediate' => true,
			'data'      => $lead_data,
		) );
	}

	/**
	 * Handle meeting booked event.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $data       Meeting data.
	 */
	public function on_meeting_booked( $session_id, $data = array() ) {
		$lead_id = $this->get_lead_id_by_session( $session_id );

		if ( $lead_id ) {
			$this->score_lead( $lead_id, 'meeting_booked', array(
				'immediate'  => true,
				'boost'      => 15, // Significant score boost
				'data'       => $data,
			) );
		}
	}

	/**
	 * Handle intent detected event.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $intent     Detected intent.
	 * @param array  $data       Intent data.
	 */
	public function on_intent_detected( $session_id, $intent, $data = array() ) {
		$high_value_intents = array(
			'pricing_inquiry',
			'meeting_request',
			'demo_request',
			'purchase_intent',
			'trial_request',
		);

		if ( in_array( $intent, $high_value_intents, true ) ) {
			$lead_id = $this->get_lead_id_by_session( $session_id );

			if ( $lead_id ) {
				$this->score_lead( $lead_id, 'high_intent_detected', array(
					'immediate' => true,
					'intent'    => $intent,
					'data'      => $data,
				) );
			} else {
				// Store for later if no lead yet
				$this->store_session_intent( $session_id, $intent, $data );
			}
		}
	}

	/**
	 * Handle form completed event.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $form_data  Form data.
	 */
	public function on_form_completed( $session_id, $form_data = array() ) {
		$lead_id = $this->get_lead_id_by_session( $session_id );

		if ( $lead_id ) {
			$this->score_lead( $lead_id, 'form_completed', array(
				'immediate' => true,
				'data'      => $form_data,
			) );
		}
	}

	/**
	 * Handle message processed event.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $session_id      Session ID.
	 * @param array  $message         Message data.
	 */
	public function on_message_processed( $conversation_id, $session_id, $message ) {
		$lead_id = $this->get_lead_id_by_session( $session_id );

		if ( $lead_id ) {
			// Deferred scoring - batch multiple messages
			$this->queue_rescore( $lead_id, 'message_sent', array(
				'conversation_id' => $conversation_id,
				'message'         => $message,
			) );
		}
	}

	/**
	 * Handle behavior event.
	 *
	 * @since 1.0.0
	 * @param string $session_id  Session ID.
	 * @param string $event_type  Event type.
	 * @param array  $event_data  Event data.
	 */
	public function on_behavior_event( $session_id, $event_type, $event_data ) {
		// Only process significant events
		$significant_events = array(
			'pricing_page_view',
			'demo_page_view',
			'file_downloaded',
			'deep_scroll', // 90%+ scroll
			'long_session', // 5+ minutes
			'return_visit',
		);

		if ( in_array( $event_type, $significant_events, true ) ) {
			$lead_id = $this->get_lead_id_by_session( $session_id );

			if ( $lead_id ) {
				$this->queue_rescore( $lead_id, $event_type, $event_data );
			}
		}
	}

	/**
	 * Score a lead.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id Lead ID.
	 * @param string $trigger Trigger event.
	 * @param array  $context Scoring context.
	 * @return array|null Score result.
	 */
	public function score_lead( $lead_id, $trigger = 'manual', $context = array() ) {
		$immediate = $context['immediate'] ?? false;

		// Get previous score for comparison
		$previous_score = $this->get_cached_score( $lead_id );

		// Calculate new score
		if ( ! $this->lead_scorer ) {
			return null;
		}

		$score_result = $this->lead_scorer->score( $lead_id );

		if ( ! $score_result ) {
			return null;
		}

		$new_score = $score_result['composite_score'];

		// Apply any boost from context
		if ( isset( $context['boost'] ) ) {
			$new_score = min( 100, $new_score + intval( $context['boost'] ) );
			$score_result['composite_score'] = $new_score;
			$score_result['boost_applied'] = $context['boost'];
		}

		// Update grade
		if ( $this->lead_grader ) {
			$grade_result = $this->lead_grader->grade_lead( $lead_id, $score_result );
			$score_result['grade'] = $grade_result;
		}

		// Cache the new score
		$this->cache_score( $lead_id, $score_result );

		// Check for significant changes
		$this->check_score_changes( $lead_id, $previous_score, $score_result, $trigger );

		// Store scoring event
		$this->store_scoring_event( $lead_id, $trigger, $previous_score, $score_result );

		// Trigger action for real-time updates
		do_action( 'wp_ai_chatbot_score_updated', $lead_id, $score_result, $previous_score );

		$this->logger->debug( 'Lead scored in real-time', array(
			'lead_id'        => $lead_id,
			'trigger'        => $trigger,
			'previous_score' => $previous_score['composite_score'] ?? null,
			'new_score'      => $new_score,
		) );

		return $score_result;
	}

	/**
	 * Queue a rescore for deferred processing.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id Lead ID.
	 * @param string $trigger Trigger event.
	 * @param array  $data    Event data.
	 */
	private function queue_rescore( $lead_id, $trigger, $data = array() ) {
		if ( ! isset( $this->pending_rescores[ $lead_id ] ) ) {
			$this->pending_rescores[ $lead_id ] = array(
				'triggers' => array(),
				'data'     => array(),
			);
		}

		$this->pending_rescores[ $lead_id ]['triggers'][] = $trigger;
		$this->pending_rescores[ $lead_id ]['data'][ $trigger ] = $data;
	}

	/**
	 * Process pending rescores.
	 *
	 * @since 1.0.0
	 */
	public function process_pending_rescores() {
		foreach ( $this->pending_rescores as $lead_id => $queue ) {
			// Combine triggers for logging
			$triggers = array_unique( $queue['triggers'] );
			$trigger_string = implode( ', ', $triggers );

			// Score with combined context
			$this->score_lead( $lead_id, 'batch: ' . $trigger_string, array(
				'deferred'  => true,
				'triggers'  => $triggers,
				'data'      => $queue['data'],
			) );
		}

		$this->pending_rescores = array();
	}

	/**
	 * Check for significant score changes.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id        Lead ID.
	 * @param array $previous_score Previous score data.
	 * @param array $new_score      New score data.
	 * @param string $trigger       Trigger event.
	 */
	private function check_score_changes( $lead_id, $previous_score, $new_score, $trigger ) {
		$prev_value = $previous_score['composite_score'] ?? 0;
		$new_value = $new_score['composite_score'] ?? 0;
		$score_change = $new_value - $prev_value;

		$prev_grade = $previous_score['grade']['grade'] ?? 'F';
		$new_grade = $new_score['grade']['grade'] ?? 'F';
		$grade_changed = $prev_grade !== $new_grade;

		// Check thresholds
		$notifications = array();

		// Significant score increase
		if ( $score_change >= self::NOTIFICATION_THRESHOLDS['score_increase'] ) {
			$notifications[] = array(
				'type'    => 'score_increase',
				'message' => sprintf(
					/* translators: %d: score increase */
					__( 'Lead score increased by %d points', 'wp-ai-chatbot-leadgen-pro' ),
					$score_change
				),
			);
		}

		// Significant score decrease
		if ( $score_change <= -self::NOTIFICATION_THRESHOLDS['score_decrease'] ) {
			$notifications[] = array(
				'type'    => 'score_decrease',
				'message' => sprintf(
					/* translators: %d: score decrease */
					__( 'Lead score decreased by %d points', 'wp-ai-chatbot-leadgen-pro' ),
					abs( $score_change )
				),
			);
		}

		// Grade upgrade
		if ( $grade_changed && $this->is_grade_upgrade( $prev_grade, $new_grade ) ) {
			$notifications[] = array(
				'type'    => 'grade_upgrade',
				'message' => sprintf(
					/* translators: %1$s: previous grade, %2$s: new grade */
					__( 'Lead upgraded from %1$s to %2$s', 'wp-ai-chatbot-leadgen-pro' ),
					$prev_grade,
					$new_grade
				),
			);
		}

		// Hot lead detected
		if ( $new_grade === 'A+' && $prev_grade !== 'A+' ) {
			$notifications[] = array(
				'type'    => 'hot_lead',
				'message' => __( 'Hot lead detected! Immediate follow-up recommended.', 'wp-ai-chatbot-leadgen-pro' ),
				'urgent'  => true,
			);

			// Trigger hot lead action
			do_action( 'wp_ai_chatbot_hot_lead_realtime', $lead_id, $new_score );
		}

		// Send notifications
		if ( ! empty( $notifications ) ) {
			$this->send_score_notifications( $lead_id, $notifications, $new_score );
		}
	}

	/**
	 * Check if grade change is an upgrade.
	 *
	 * @since 1.0.0
	 * @param string $prev_grade Previous grade.
	 * @param string $new_grade  New grade.
	 * @return bool True if upgrade.
	 */
	private function is_grade_upgrade( $prev_grade, $new_grade ) {
		$order = array( 'A+' => 6, 'A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'F' => 1, 'DQ' => 0 );
		return ( $order[ $new_grade ] ?? 0 ) > ( $order[ $prev_grade ] ?? 0 );
	}

	/**
	 * Send score change notifications.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id       Lead ID.
	 * @param array $notifications Notifications to send.
	 * @param array $score_data    Score data.
	 */
	private function send_score_notifications( $lead_id, $notifications, $score_data ) {
		// Check if realtime notifications are enabled
		if ( ! $this->config->get( 'realtime_score_notifications', true ) ) {
			return;
		}

		// Store notification for admin dashboard
		$this->store_notification( $lead_id, $notifications, $score_data );

		// Check for urgent notifications
		foreach ( $notifications as $notification ) {
			if ( ! empty( $notification['urgent'] ) ) {
				// Send immediate notification (webhook, email, etc.)
				do_action( 'wp_ai_chatbot_urgent_score_notification', $lead_id, $notification, $score_data );
			}
		}
	}

	/**
	 * Store notification for later retrieval.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id       Lead ID.
	 * @param array $notifications Notifications.
	 * @param array $score_data    Score data.
	 */
	private function store_notification( $lead_id, $notifications, $score_data ) {
		$stored = get_transient( 'wp_ai_chatbot_score_notifications' ) ?: array();

		$stored[] = array(
			'lead_id'       => $lead_id,
			'notifications' => $notifications,
			'score'         => $score_data['composite_score'],
			'grade'         => $score_data['grade']['grade'] ?? 'F',
			'timestamp'     => current_time( 'mysql' ),
		);

		// Keep last 50 notifications
		$stored = array_slice( $stored, -50 );

		set_transient( 'wp_ai_chatbot_score_notifications', $stored, DAY_IN_SECONDS );
	}

	/**
	 * Store scoring event for analytics.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id        Lead ID.
	 * @param string $trigger        Trigger event.
	 * @param array  $previous_score Previous score.
	 * @param array  $new_score      New score.
	 */
	private function store_scoring_event( $lead_id, $trigger, $previous_score, $new_score ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_scoring_events';

		// Create table if not exists
		$this->maybe_create_events_table();

		$wpdb->insert(
			$table,
			array(
				'lead_id'        => $lead_id,
				'trigger_event'  => $trigger,
				'previous_score' => $previous_score['composite_score'] ?? null,
				'new_score'      => $new_score['composite_score'],
				'score_change'   => ( $new_score['composite_score'] ?? 0 ) - ( $previous_score['composite_score'] ?? 0 ),
				'previous_grade' => $previous_score['grade']['grade'] ?? null,
				'new_grade'      => $new_score['grade']['grade'] ?? null,
				'created_at'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Create scoring events table.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_events_table() {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_scoring_events';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT(20) UNSIGNED NOT NULL,
			trigger_event VARCHAR(100) NOT NULL,
			previous_score INT(11) DEFAULT NULL,
			new_score INT(11) NOT NULL,
			score_change INT(11) DEFAULT 0,
			previous_grade VARCHAR(10) DEFAULT NULL,
			new_grade VARCHAR(10) DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY trigger_event (trigger_event),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Cache score for fast retrieval.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id    Lead ID.
	 * @param array $score_data Score data.
	 */
	private function cache_score( $lead_id, $score_data ) {
		set_transient(
			self::CACHE_PREFIX . $lead_id,
			$score_data,
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Get cached score.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array|null Cached score or null.
	 */
	private function get_cached_score( $lead_id ) {
		return get_transient( self::CACHE_PREFIX . $lead_id ) ?: null;
	}

	/**
	 * Store session intent for later use.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $intent     Intent type.
	 * @param array  $data       Intent data.
	 */
	private function store_session_intent( $session_id, $intent, $data ) {
		$key = 'wp_ai_chatbot_session_intents_' . $session_id;
		$intents = get_transient( $key ) ?: array();

		$intents[] = array(
			'intent'    => $intent,
			'data'      => $data,
			'timestamp' => current_time( 'mysql' ),
		);

		set_transient( $key, $intents, DAY_IN_SECONDS );
	}

	/**
	 * Get lead ID by session.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @return int|null Lead ID or null.
	 */
	private function get_lead_id_by_session( $session_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_leads';

		return $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE session_id = %s ORDER BY created_at DESC LIMIT 1",
			$session_id
		) );
	}

	/**
	 * AJAX handler for getting real-time score.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_score() {
		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
		$lead_id = intval( $_POST['lead_id'] ?? 0 );

		// Get lead ID from session if not provided
		if ( ! $lead_id && $session_id ) {
			$lead_id = $this->get_lead_id_by_session( $session_id );
		}

		if ( ! $lead_id ) {
			// Return preliminary score based on behavior only
			$preliminary = $this->get_preliminary_score( $session_id );
			wp_send_json_success( array(
				'type'  => 'preliminary',
				'score' => $preliminary,
			) );
		}

		// Try cache first
		$cached = $this->get_cached_score( $lead_id );

		if ( $cached ) {
			wp_send_json_success( array(
				'type'   => 'cached',
				'score'  => $cached['composite_score'],
				'grade'  => $cached['grade'],
				'cached' => true,
			) );
		}

		// Calculate fresh score
		$score = $this->score_lead( $lead_id, 'ajax_request' );

		if ( $score ) {
			wp_send_json_success( array(
				'type'   => 'fresh',
				'score'  => $score['composite_score'],
				'grade'  => $score['grade'],
				'cached' => false,
			) );
		}

		wp_send_json_error( array( 'message' => 'Unable to calculate score' ), 500 );
	}

	/**
	 * Get preliminary score for session without lead.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @return array Preliminary score.
	 */
	private function get_preliminary_score( $session_id ) {
		$score = 0;

		if ( $this->behavior_tracker ) {
			$behavior = $this->behavior_tracker->get_behavior( $session_id );

			if ( $behavior ) {
				// Use engagement score as preliminary
				$score = intval( $behavior['engagement_score'] ?? 0 );

				// Add intent score
				$score += intval( $behavior['intent_score'] ?? 0 );

				// Normalize
				$score = min( 100, intval( $score / 2 ) );
			}
		}

		// Determine preliminary grade
		$grade = 'C';
		if ( $score >= 70 ) {
			$grade = 'A';
		} elseif ( $score >= 50 ) {
			$grade = 'B';
		} elseif ( $score >= 30 ) {
			$grade = 'C';
		} else {
			$grade = 'D';
		}

		return array(
			'score'       => $score,
			'grade'       => $grade,
			'preliminary' => true,
		);
	}

	/**
	 * AJAX handler for subscribing to score updates.
	 *
	 * @since 1.0.0
	 */
	public function ajax_subscribe_updates() {
		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
		$last_score = intval( $_POST['last_score'] ?? 0 );

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => 'Session ID required' ), 400 );
		}

		$lead_id = $this->get_lead_id_by_session( $session_id );

		if ( ! $lead_id ) {
			$preliminary = $this->get_preliminary_score( $session_id );
			wp_send_json_success( array(
				'updated'     => $preliminary['score'] !== $last_score,
				'score'       => $preliminary,
				'lead_exists' => false,
			) );
		}

		$cached = $this->get_cached_score( $lead_id );

		$current_score = $cached['composite_score'] ?? 0;
		$updated = $current_score !== $last_score;

		wp_send_json_success( array(
			'updated'     => $updated,
			'score'       => $cached ?: $this->score_lead( $lead_id, 'subscription_check' ),
			'lead_exists' => true,
		) );
	}

	/**
	 * Execute scheduled rescore.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 */
	public function execute_scheduled_rescore( $lead_id ) {
		$this->score_lead( $lead_id, 'scheduled' );
	}

	/**
	 * Schedule a rescore for later.
	 *
	 * @since 1.0.0
	 * @param int $lead_id     Lead ID.
	 * @param int $delay_seconds Delay in seconds.
	 */
	public function schedule_rescore( $lead_id, $delay_seconds = 60 ) {
		wp_schedule_single_event(
			time() + $delay_seconds,
			'wp_ai_chatbot_scheduled_rescore',
			array( $lead_id )
		);
	}

	/**
	 * Get recent notifications.
	 *
	 * @since 1.0.0
	 * @param int $limit Number of notifications.
	 * @return array Notifications.
	 */
	public function get_recent_notifications( $limit = 10 ) {
		$stored = get_transient( 'wp_ai_chatbot_score_notifications' ) ?: array();
		return array_slice( array_reverse( $stored ), 0, $limit );
	}

	/**
	 * Get scoring events for a lead.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id Lead ID.
	 * @param array $args    Query arguments.
	 * @return array Events.
	 */
	public function get_scoring_events( $lead_id, $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_scoring_events';

		$defaults = array(
			'limit'  => 20,
			'offset' => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE lead_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$lead_id,
				$args['limit'],
				$args['offset']
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get score trend for a lead.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @param int $days    Number of days.
	 * @return array Trend data.
	 */
	public function get_score_trend( $lead_id, $days = 7 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_scoring_events';
		$since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, AVG(new_score) as avg_score, MAX(new_score) as max_score, MIN(new_score) as min_score
				FROM {$table}
				WHERE lead_id = %d AND created_at >= %s
				GROUP BY DATE(created_at)
				ORDER BY date ASC",
				$lead_id,
				$since
			),
			ARRAY_A
		);

		return $events ?: array();
	}
}

