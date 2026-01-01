<?php
/**
 * Behavior Tracker.
 *
 * Records and tracks user behavior including messages, pages viewed,
 * session duration, and return visits for lead scoring.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker {

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Database table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $table_name;

	/**
	 * Events table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $events_table;

	/**
	 * Event types.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const EVENT_TYPES = array(
		'page_view'        => 'Page View',
		'chat_open'        => 'Chat Opened',
		'chat_close'       => 'Chat Closed',
		'message_sent'     => 'Message Sent',
		'message_received' => 'Message Received',
		'lead_captured'    => 'Lead Captured',
		'link_clicked'     => 'Link Clicked',
		'file_downloaded'  => 'File Downloaded',
		'form_started'     => 'Form Started',
		'form_completed'   => 'Form Completed',
		'form_abandoned'   => 'Form Abandoned',
		'meeting_booked'   => 'Meeting Booked',
		'feedback_given'   => 'Feedback Given',
		'scroll_depth'     => 'Scroll Depth',
		'time_on_page'     => 'Time on Page',
		'exit_intent'      => 'Exit Intent Detected',
		'return_visit'     => 'Return Visit',
		'session_start'    => 'Session Started',
		'session_end'      => 'Session Ended',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->table_name = $wpdb->prefix . 'ai_chatbot_behavior';
		$this->events_table = $wpdb->prefix . 'ai_chatbot_behavior_events';

		$this->maybe_create_tables();
		$this->init_hooks();
	}

	/**
	 * Create database tables if they don't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Main behavior tracking table (aggregated per session)
		$sql_behavior = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(100) NOT NULL,
			visitor_id VARCHAR(100) DEFAULT NULL,
			lead_id BIGINT(20) UNSIGNED DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			
			-- Session metrics
			first_seen DATETIME DEFAULT NULL,
			last_seen DATETIME DEFAULT NULL,
			session_count INT(11) DEFAULT 1,
			total_duration INT(11) DEFAULT 0,
			current_session_duration INT(11) DEFAULT 0,
			
			-- Page metrics
			pages_viewed INT(11) DEFAULT 0,
			unique_pages INT(11) DEFAULT 0,
			page_list TEXT DEFAULT NULL,
			max_scroll_depth INT(11) DEFAULT 0,
			avg_time_on_page INT(11) DEFAULT 0,
			
			-- Chat metrics
			chat_opened_count INT(11) DEFAULT 0,
			messages_sent INT(11) DEFAULT 0,
			messages_received INT(11) DEFAULT 0,
			avg_message_length INT(11) DEFAULT 0,
			
			-- Engagement metrics
			links_clicked INT(11) DEFAULT 0,
			files_downloaded INT(11) DEFAULT 0,
			forms_completed INT(11) DEFAULT 0,
			meetings_booked INT(11) DEFAULT 0,
			feedback_given INT(11) DEFAULT 0,
			
			-- Intent signals
			pricing_page_views INT(11) DEFAULT 0,
			product_page_views INT(11) DEFAULT 0,
			demo_page_views INT(11) DEFAULT 0,
			contact_page_views INT(11) DEFAULT 0,
			high_intent_actions TEXT DEFAULT NULL,
			
			-- Source tracking
			referrer TEXT DEFAULT NULL,
			utm_source VARCHAR(255) DEFAULT NULL,
			utm_medium VARCHAR(255) DEFAULT NULL,
			utm_campaign VARCHAR(255) DEFAULT NULL,
			landing_page TEXT DEFAULT NULL,
			
			-- Device info
			device_type VARCHAR(50) DEFAULT NULL,
			browser VARCHAR(100) DEFAULT NULL,
			os VARCHAR(100) DEFAULT NULL,
			
			-- Calculated scores
			engagement_score INT(11) DEFAULT 0,
			intent_score INT(11) DEFAULT 0,
			
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			
			PRIMARY KEY (id),
			UNIQUE KEY session_id (session_id),
			KEY visitor_id (visitor_id),
			KEY lead_id (lead_id),
			KEY user_id (user_id),
			KEY engagement_score (engagement_score),
			KEY created_at (created_at)
		) $charset_collate;";

		// Individual events table
		$sql_events = "CREATE TABLE IF NOT EXISTS {$this->events_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(100) NOT NULL,
			visitor_id VARCHAR(100) DEFAULT NULL,
			event_type VARCHAR(50) NOT NULL,
			event_data TEXT DEFAULT NULL,
			page_url TEXT DEFAULT NULL,
			page_title VARCHAR(255) DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY visitor_id (visitor_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_behavior );
		dbDelta( $sql_events );
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'wp_ajax_wp_ai_chatbot_track_event', array( $this, 'ajax_track_event' ) );
		add_action( 'wp_ajax_nopriv_wp_ai_chatbot_track_event', array( $this, 'ajax_track_event' ) );

		add_action( 'wp_ajax_wp_ai_chatbot_track_session', array( $this, 'ajax_track_session' ) );
		add_action( 'wp_ajax_nopriv_wp_ai_chatbot_track_session', array( $this, 'ajax_track_session' ) );

		add_action( 'wp_ajax_wp_ai_chatbot_get_behavior', array( $this, 'ajax_get_behavior' ) );

		// Hook into lead creation
		add_action( 'wp_ai_chatbot_lead_created', array( $this, 'link_behavior_to_lead' ), 10, 2 );

		// Hook into message events
		add_action( 'wp_ai_chatbot_message_sent', array( $this, 'track_message_sent' ), 10, 2 );
		add_action( 'wp_ai_chatbot_message_received', array( $this, 'track_message_received' ), 10, 2 );
	}

	/**
	 * Track an event.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $event_type Event type.
	 * @param array  $data       Event data.
	 * @return int|false Event ID or false on failure.
	 */
	public function track_event( $session_id, $event_type, $data = array() ) {
		global $wpdb;

		if ( empty( $session_id ) || empty( $event_type ) ) {
			return false;
		}

		// Insert event
		$result = $wpdb->insert(
			$this->events_table,
			array(
				'session_id'  => $session_id,
				'visitor_id'  => $data['visitor_id'] ?? null,
				'event_type'  => $event_type,
				'event_data'  => ! empty( $data['event_data'] ) ? wp_json_encode( $data['event_data'] ) : null,
				'page_url'    => $data['page_url'] ?? null,
				'page_title'  => $data['page_title'] ?? null,
				'created_at'  => current_time( 'mysql' ),
			)
		);

		if ( false === $result ) {
			$this->logger->error( 'Failed to track event', array(
				'session_id'  => $session_id,
				'event_type'  => $event_type,
				'error'       => $wpdb->last_error,
			) );
			return false;
		}

		// Update aggregated behavior
		$this->update_behavior( $session_id, $event_type, $data );

		return $wpdb->insert_id;
	}

	/**
	 * Update aggregated behavior metrics.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $event_type Event type.
	 * @param array  $data       Event data.
	 */
	private function update_behavior( $session_id, $event_type, $data = array() ) {
		global $wpdb;

		// Get or create behavior record
		$behavior = $this->get_behavior( $session_id );
		$is_new = empty( $behavior );

		if ( $is_new ) {
			// Create new behavior record
			$wpdb->insert(
				$this->table_name,
				array(
					'session_id'     => $session_id,
					'visitor_id'     => $data['visitor_id'] ?? null,
					'first_seen'     => current_time( 'mysql' ),
					'last_seen'      => current_time( 'mysql' ),
					'session_count'  => 1,
					'referrer'       => $data['referrer'] ?? null,
					'utm_source'     => $data['utm_source'] ?? null,
					'utm_medium'     => $data['utm_medium'] ?? null,
					'utm_campaign'   => $data['utm_campaign'] ?? null,
					'landing_page'   => $data['page_url'] ?? null,
					'device_type'    => $data['device_type'] ?? null,
					'browser'        => $data['browser'] ?? null,
					'os'             => $data['os'] ?? null,
					'created_at'     => current_time( 'mysql' ),
				)
			);
			$behavior = $this->get_behavior( $session_id );
		}

		if ( ! $behavior ) {
			return;
		}

		$updates = array(
			'last_seen' => current_time( 'mysql' ),
		);

		// Update metrics based on event type
		switch ( $event_type ) {
			case 'page_view':
				$updates['pages_viewed'] = intval( $behavior['pages_viewed'] ) + 1;
				
				// Track unique pages
				$page_list = json_decode( $behavior['page_list'] ?? '[]', true ) ?: array();
				$page_url = $data['page_url'] ?? '';
				if ( $page_url && ! in_array( $page_url, $page_list, true ) ) {
					$page_list[] = $page_url;
					$updates['unique_pages'] = count( $page_list );
					$updates['page_list'] = wp_json_encode( array_slice( $page_list, -50 ) ); // Keep last 50
				}

				// Track high-value page views
				if ( $this->is_pricing_page( $page_url ) ) {
					$updates['pricing_page_views'] = intval( $behavior['pricing_page_views'] ) + 1;
				}
				if ( $this->is_product_page( $page_url ) ) {
					$updates['product_page_views'] = intval( $behavior['product_page_views'] ) + 1;
				}
				if ( $this->is_demo_page( $page_url ) ) {
					$updates['demo_page_views'] = intval( $behavior['demo_page_views'] ) + 1;
				}
				if ( $this->is_contact_page( $page_url ) ) {
					$updates['contact_page_views'] = intval( $behavior['contact_page_views'] ) + 1;
				}
				break;

			case 'chat_open':
				$updates['chat_opened_count'] = intval( $behavior['chat_opened_count'] ) + 1;
				break;

			case 'message_sent':
				$updates['messages_sent'] = intval( $behavior['messages_sent'] ) + 1;
				
				// Update average message length
				$message = $data['event_data']['message'] ?? '';
				$total_length = intval( $behavior['avg_message_length'] ) * intval( $behavior['messages_sent'] );
				$new_count = intval( $behavior['messages_sent'] ) + 1;
				$updates['avg_message_length'] = intval( ( $total_length + strlen( $message ) ) / $new_count );
				break;

			case 'message_received':
				$updates['messages_received'] = intval( $behavior['messages_received'] ) + 1;
				break;

			case 'link_clicked':
				$updates['links_clicked'] = intval( $behavior['links_clicked'] ) + 1;
				break;

			case 'file_downloaded':
				$updates['files_downloaded'] = intval( $behavior['files_downloaded'] ) + 1;
				$this->track_high_intent_action( $behavior, 'file_download', $data );
				break;

			case 'form_completed':
				$updates['forms_completed'] = intval( $behavior['forms_completed'] ) + 1;
				break;

			case 'meeting_booked':
				$updates['meetings_booked'] = intval( $behavior['meetings_booked'] ) + 1;
				$this->track_high_intent_action( $behavior, 'meeting_booked', $data );
				break;

			case 'feedback_given':
				$updates['feedback_given'] = intval( $behavior['feedback_given'] ) + 1;
				break;

			case 'scroll_depth':
				$depth = intval( $data['event_data']['depth'] ?? 0 );
				if ( $depth > intval( $behavior['max_scroll_depth'] ) ) {
					$updates['max_scroll_depth'] = $depth;
				}
				break;

			case 'time_on_page':
				$time = intval( $data['event_data']['seconds'] ?? 0 );
				$updates['current_session_duration'] = intval( $behavior['current_session_duration'] ) + $time;
				$updates['total_duration'] = intval( $behavior['total_duration'] ) + $time;
				break;

			case 'session_start':
				// Check if this is a return visit
				if ( ! empty( $behavior['first_seen'] ) ) {
					$updates['session_count'] = intval( $behavior['session_count'] ) + 1;
					$this->track_event( $session_id, 'return_visit', $data );
				}
				$updates['current_session_duration'] = 0;
				break;

			case 'return_visit':
				// This is tracked for analytics but doesn't update metrics directly
				break;

			case 'lead_captured':
				$this->track_high_intent_action( $behavior, 'lead_captured', $data );
				break;
		}

		// Recalculate engagement and intent scores
		$updates['engagement_score'] = $this->calculate_engagement_score( $behavior, $updates );
		$updates['intent_score'] = $this->calculate_intent_score( $behavior, $updates );

		// Update behavior record
		$wpdb->update(
			$this->table_name,
			$updates,
			array( 'session_id' => $session_id )
		);
	}

	/**
	 * Track high-intent action.
	 *
	 * @since 1.0.0
	 * @param array  $behavior Current behavior.
	 * @param string $action   Action type.
	 * @param array  $data     Action data.
	 */
	private function track_high_intent_action( $behavior, $action, $data ) {
		global $wpdb;

		$actions = json_decode( $behavior['high_intent_actions'] ?? '[]', true ) ?: array();
		$actions[] = array(
			'action'    => $action,
			'timestamp' => current_time( 'mysql' ),
			'data'      => $data['event_data'] ?? array(),
		);

		// Keep last 20 actions
		$actions = array_slice( $actions, -20 );

		$wpdb->update(
			$this->table_name,
			array( 'high_intent_actions' => wp_json_encode( $actions ) ),
			array( 'session_id' => $behavior['session_id'] )
		);
	}

	/**
	 * Calculate engagement score.
	 *
	 * @since 1.0.0
	 * @param array $behavior Current behavior.
	 * @param array $updates  Pending updates.
	 * @return int Engagement score (0-100).
	 */
	private function calculate_engagement_score( $behavior, $updates = array() ) {
		$score = 0;

		// Merge updates with current behavior
		$data = array_merge( $behavior, $updates );

		// Page views (max 20 points)
		$pages = intval( $data['pages_viewed'] ?? 0 );
		$score += min( 20, $pages * 2 );

		// Session duration (max 20 points) - 1 point per 30 seconds
		$duration = intval( $data['total_duration'] ?? 0 );
		$score += min( 20, floor( $duration / 30 ) );

		// Messages sent (max 20 points)
		$messages = intval( $data['messages_sent'] ?? 0 );
		$score += min( 20, $messages * 4 );

		// Return visits (max 15 points)
		$sessions = intval( $data['session_count'] ?? 1 );
		$score += min( 15, ( $sessions - 1 ) * 5 );

		// Chat interactions (max 10 points)
		$chat_opens = intval( $data['chat_opened_count'] ?? 0 );
		$score += min( 10, $chat_opens * 2 );

		// Scroll depth (max 10 points)
		$scroll = intval( $data['max_scroll_depth'] ?? 0 );
		$score += min( 10, floor( $scroll / 10 ) );

		// Bonus points for specific actions (max 5 points)
		$bonus = 0;
		if ( intval( $data['links_clicked'] ?? 0 ) > 0 ) $bonus += 1;
		if ( intval( $data['files_downloaded'] ?? 0 ) > 0 ) $bonus += 2;
		if ( intval( $data['forms_completed'] ?? 0 ) > 0 ) $bonus += 2;
		$score += min( 5, $bonus );

		return min( 100, $score );
	}

	/**
	 * Calculate intent score.
	 *
	 * @since 1.0.0
	 * @param array $behavior Current behavior.
	 * @param array $updates  Pending updates.
	 * @return int Intent score (0-100).
	 */
	private function calculate_intent_score( $behavior, $updates = array() ) {
		$score = 0;

		// Merge updates with current behavior
		$data = array_merge( $behavior, $updates );

		// Pricing page views (high intent - 25 points)
		if ( intval( $data['pricing_page_views'] ?? 0 ) > 0 ) {
			$score += min( 25, intval( $data['pricing_page_views'] ) * 10 );
		}

		// Demo/trial page views (high intent - 20 points)
		if ( intval( $data['demo_page_views'] ?? 0 ) > 0 ) {
			$score += min( 20, intval( $data['demo_page_views'] ) * 10 );
		}

		// Contact page views (medium intent - 15 points)
		if ( intval( $data['contact_page_views'] ?? 0 ) > 0 ) {
			$score += min( 15, intval( $data['contact_page_views'] ) * 8 );
		}

		// Product page views (shows interest - 10 points)
		if ( intval( $data['product_page_views'] ?? 0 ) > 0 ) {
			$score += min( 10, intval( $data['product_page_views'] ) * 2 );
		}

		// Meeting booked (highest intent - 20 points)
		if ( intval( $data['meetings_booked'] ?? 0 ) > 0 ) {
			$score += 20;
		}

		// Form completed (high intent - 10 points)
		if ( intval( $data['forms_completed'] ?? 0 ) > 0 ) {
			$score += 10;
		}

		return min( 100, $score );
	}

	/**
	 * Get behavior for a session.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @return array|null Behavior data or null.
	 */
	public function get_behavior( $session_id ) {
		global $wpdb;

		$behavior = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE session_id = %s",
				$session_id
			),
			ARRAY_A
		);

		if ( $behavior ) {
			// Decode JSON fields
			if ( ! empty( $behavior['page_list'] ) ) {
				$behavior['page_list'] = json_decode( $behavior['page_list'], true );
			}
			if ( ! empty( $behavior['high_intent_actions'] ) ) {
				$behavior['high_intent_actions'] = json_decode( $behavior['high_intent_actions'], true );
			}
		}

		return $behavior;
	}

	/**
	 * Get behavior by visitor ID.
	 *
	 * @since 1.0.0
	 * @param string $visitor_id Visitor ID.
	 * @return array Behavior records.
	 */
	public function get_behavior_by_visitor( $visitor_id ) {
		global $wpdb;

		$behaviors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE visitor_id = %s ORDER BY created_at DESC",
				$visitor_id
			),
			ARRAY_A
		);

		return $behaviors ?: array();
	}

	/**
	 * Get behavior by lead ID.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array|null Behavior data or null.
	 */
	public function get_behavior_by_lead( $lead_id ) {
		global $wpdb;

		$behavior = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE lead_id = %d ORDER BY created_at DESC LIMIT 1",
				$lead_id
			),
			ARRAY_A
		);

		return $behavior;
	}

	/**
	 * Get events for a session.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $args       Query arguments.
	 * @return array Events.
	 */
	public function get_events( $session_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'event_type' => '',
			'limit'      => 100,
			'offset'     => 0,
			'order'      => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array( 'session_id = %s' );
		$values = array( $session_id );

		if ( ! empty( $args['event_type'] ) ) {
			$where[] = 'event_type = %s';
			$values[] = $args['event_type'];
		}

		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$where_clause = implode( ' AND ', $where );

		$values[] = intval( $args['limit'] );
		$values[] = intval( $args['offset'] );

		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->events_table} WHERE $where_clause ORDER BY created_at $order LIMIT %d OFFSET %d",
				$values
			),
			ARRAY_A
		);

		// Decode event data
		foreach ( $events as &$event ) {
			if ( ! empty( $event['event_data'] ) ) {
				$event['event_data'] = json_decode( $event['event_data'], true );
			}
		}

		return $events ?: array();
	}

	/**
	 * Link behavior to lead.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id   Lead ID.
	 * @param array $lead_data Lead data.
	 */
	public function link_behavior_to_lead( $lead_id, $lead_data ) {
		global $wpdb;

		$session_id = $lead_data['session_id'] ?? '';

		if ( empty( $session_id ) ) {
			return;
		}

		$wpdb->update(
			$this->table_name,
			array( 'lead_id' => $lead_id ),
			array( 'session_id' => $session_id )
		);

		// Track lead capture event
		$this->track_event( $session_id, 'lead_captured', array(
			'event_data' => array( 'lead_id' => $lead_id ),
		) );
	}

	/**
	 * Track message sent.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $message    Message data.
	 */
	public function track_message_sent( $session_id, $message ) {
		$this->track_event( $session_id, 'message_sent', array(
			'event_data' => array(
				'message' => $message['content'] ?? '',
				'length'  => strlen( $message['content'] ?? '' ),
			),
		) );
	}

	/**
	 * Track message received.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $message    Message data.
	 */
	public function track_message_received( $session_id, $message ) {
		$this->track_event( $session_id, 'message_received', array(
			'event_data' => array(
				'message_id' => $message['id'] ?? null,
			),
		) );
	}

	/**
	 * AJAX handler for tracking events.
	 *
	 * @since 1.0.0
	 */
	public function ajax_track_event() {
		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
		$event_type = sanitize_text_field( $_POST['event_type'] ?? '' );

		if ( empty( $session_id ) || empty( $event_type ) ) {
			wp_send_json_error( array( 'message' => 'Missing required fields' ), 400 );
		}

		if ( ! array_key_exists( $event_type, self::EVENT_TYPES ) ) {
			wp_send_json_error( array( 'message' => 'Invalid event type' ), 400 );
		}

		$data = array(
			'visitor_id'   => sanitize_text_field( $_POST['visitor_id'] ?? '' ),
			'page_url'     => esc_url_raw( $_POST['page_url'] ?? '' ),
			'page_title'   => sanitize_text_field( $_POST['page_title'] ?? '' ),
			'referrer'     => esc_url_raw( $_POST['referrer'] ?? '' ),
			'utm_source'   => sanitize_text_field( $_POST['utm_source'] ?? '' ),
			'utm_medium'   => sanitize_text_field( $_POST['utm_medium'] ?? '' ),
			'utm_campaign' => sanitize_text_field( $_POST['utm_campaign'] ?? '' ),
			'device_type'  => sanitize_text_field( $_POST['device_type'] ?? '' ),
			'browser'      => sanitize_text_field( $_POST['browser'] ?? '' ),
			'os'           => sanitize_text_field( $_POST['os'] ?? '' ),
		);

		// Parse event_data if present
		if ( ! empty( $_POST['event_data'] ) ) {
			$event_data = $_POST['event_data'];
			if ( is_string( $event_data ) ) {
				$event_data = json_decode( stripslashes( $event_data ), true );
			}
			$data['event_data'] = $this->sanitize_event_data( $event_data );
		}

		$event_id = $this->track_event( $session_id, $event_type, $data );

		if ( $event_id ) {
			wp_send_json_success( array( 'event_id' => $event_id ) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to track event' ), 500 );
		}
	}

	/**
	 * AJAX handler for session tracking.
	 *
	 * @since 1.0.0
	 */
	public function ajax_track_session() {
		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
		$action = sanitize_text_field( $_POST['session_action'] ?? 'heartbeat' );

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing session ID' ), 400 );
		}

		$data = array(
			'visitor_id' => sanitize_text_field( $_POST['visitor_id'] ?? '' ),
		);

		switch ( $action ) {
			case 'start':
				$this->track_event( $session_id, 'session_start', $data );
				break;

			case 'end':
				$this->track_event( $session_id, 'session_end', $data );
				break;

			case 'heartbeat':
				// Update time on page
				$this->track_event( $session_id, 'time_on_page', array_merge( $data, array(
					'event_data' => array( 'seconds' => 30 ), // Heartbeat interval
				) ) );
				break;
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler for getting behavior data.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_behavior() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
		$lead_id = intval( $_POST['lead_id'] ?? 0 );

		if ( $lead_id ) {
			$behavior = $this->get_behavior_by_lead( $lead_id );
		} elseif ( $session_id ) {
			$behavior = $this->get_behavior( $session_id );
		} else {
			wp_send_json_error( array( 'message' => 'Missing identifier' ), 400 );
		}

		if ( ! $behavior ) {
			wp_send_json_error( array( 'message' => 'Behavior not found' ), 404 );
		}

		// Get recent events
		$events = $this->get_events( $behavior['session_id'], array( 'limit' => 50 ) );

		wp_send_json_success( array(
			'behavior' => $behavior,
			'events'   => $events,
		) );
	}

	/**
	 * Sanitize event data.
	 *
	 * @since 1.0.0
	 * @param mixed $data Event data.
	 * @return array Sanitized data.
	 */
	private function sanitize_event_data( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_event_data( $value );
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $key ] = floatval( $value );
			} else {
				$sanitized[ $key ] = sanitize_text_field( $value );
			}
		}
		return $sanitized;
	}

	/**
	 * Check if URL is a pricing page.
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return bool
	 */
	private function is_pricing_page( $url ) {
		$patterns = array( '/pricing', '/plans', '/packages', '/cost', '/price', '/subscription' );
		return $this->url_matches_patterns( $url, $patterns );
	}

	/**
	 * Check if URL is a product page.
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return bool
	 */
	private function is_product_page( $url ) {
		$patterns = array( '/product', '/products', '/shop', '/store', '/features', '/solutions' );
		return $this->url_matches_patterns( $url, $patterns );
	}

	/**
	 * Check if URL is a demo page.
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return bool
	 */
	private function is_demo_page( $url ) {
		$patterns = array( '/demo', '/trial', '/free-trial', '/get-started', '/signup', '/register' );
		return $this->url_matches_patterns( $url, $patterns );
	}

	/**
	 * Check if URL is a contact page.
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return bool
	 */
	private function is_contact_page( $url ) {
		$patterns = array( '/contact', '/contact-us', '/get-in-touch', '/talk-to-us', '/schedule', '/book' );
		return $this->url_matches_patterns( $url, $patterns );
	}

	/**
	 * Check if URL matches any patterns.
	 *
	 * @since 1.0.0
	 * @param string $url      URL to check.
	 * @param array  $patterns Patterns to match.
	 * @return bool
	 */
	private function url_matches_patterns( $url, $patterns ) {
		$url = strtolower( $url );
		foreach ( $patterns as $pattern ) {
			if ( strpos( $url, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get behavior statistics.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Statistics.
	 */
	public function get_statistics( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'date_from' => date( 'Y-m-d', strtotime( '-30 days' ) ),
			'date_to'   => date( 'Y-m-d' ),
		);
		$args = wp_parse_args( $args, $defaults );

		$date_from = $args['date_from'];
		$date_to = $args['date_to'] . ' 23:59:59';

		// Total unique sessions
		$total_sessions = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// Average engagement score
		$avg_engagement = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(engagement_score) FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// Average intent score
		$avg_intent = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(intent_score) FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// Average session duration
		$avg_duration = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(total_duration) FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// Average pages per session
		$avg_pages = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(pages_viewed) FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// Return visitors
		$return_visitors = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE session_count > 1 AND created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// Chat engagement rate
		$chat_engaged = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE messages_sent > 0 AND created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// Event breakdown
		$events = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_type, COUNT(*) as count FROM {$this->events_table} WHERE created_at BETWEEN %s AND %s GROUP BY event_type ORDER BY count DESC",
			$date_from,
			$date_to
		), ARRAY_A );

		$event_counts = array();
		foreach ( $events as $event ) {
			$event_counts[ $event['event_type'] ] = intval( $event['count'] );
		}

		return array(
			'total_sessions'       => intval( $total_sessions ),
			'avg_engagement_score' => round( floatval( $avg_engagement ), 1 ),
			'avg_intent_score'     => round( floatval( $avg_intent ), 1 ),
			'avg_session_duration' => round( floatval( $avg_duration ) ),
			'avg_pages_per_session' => round( floatval( $avg_pages ), 1 ),
			'return_visitor_rate'  => $total_sessions > 0 ? round( ( $return_visitors / $total_sessions ) * 100, 1 ) : 0,
			'chat_engagement_rate' => $total_sessions > 0 ? round( ( $chat_engaged / $total_sessions ) * 100, 1 ) : 0,
			'events'               => $event_counts,
		);
	}

	/**
	 * Clean up old behavior data.
	 *
	 * @since 1.0.0
	 * @param int $days Days to keep.
	 * @return int Number of deleted records.
	 */
	public function cleanup( $days = 90 ) {
		global $wpdb;

		$threshold = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Delete old events
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->events_table} WHERE created_at < %s",
			$threshold
		) );

		// Delete old behavior records without leads
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table_name} WHERE lead_id IS NULL AND created_at < %s",
			$threshold
		) );

		return intval( $deleted );
	}
}

