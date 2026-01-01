<?php
/**
 * Escalation Manager.
 *
 * Routes frustrated users to human operators and manages escalation workflows.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Escalation_Manager {

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
	 * Sentiment analyzer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Sentiment_Analyzer
	 */
	private $sentiment_analyzer;

	/**
	 * Escalation statuses.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const STATUSES = array(
		'pending'     => 'pending',
		'assigned'    => 'assigned',
		'in_progress' => 'in_progress',
		'resolved'    => 'resolved',
		'closed'      => 'closed',
	);

	/**
	 * Escalation priorities.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const PRIORITIES = array(
		'low'      => 1,
		'medium'   => 2,
		'high'     => 3,
		'critical' => 4,
	);

	/**
	 * Escalation reasons.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const REASONS = array(
		'frustration'       => 'High user frustration detected',
		'negative_sentiment' => 'Very negative sentiment detected',
		'repeated_issues'   => 'User reporting repeated issues',
		'user_request'      => 'User explicitly requested human support',
		'complex_issue'     => 'Complex issue requiring human expertise',
		'sales_opportunity' => 'Potential sales opportunity detected',
		'complaint'         => 'Customer complaint requiring attention',
		'escalation_intent' => 'User expressed desire to escalate',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->sentiment_analyzer = new WP_AI_Chatbot_LeadGen_Pro_Sentiment_Analyzer();

		$this->maybe_create_escalations_table();
	}

	/**
	 * Create escalations table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_escalations_table() {
		global $wpdb;

		$table_name = $this->get_escalations_table();
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			$sql = "CREATE TABLE {$table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NOT NULL,
				reason varchar(50) NOT NULL,
				priority varchar(20) NOT NULL DEFAULT 'medium',
				status varchar(20) NOT NULL DEFAULT 'pending',
				assigned_to bigint(20) unsigned DEFAULT NULL,
				sentiment_data longtext,
				notes longtext,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				resolved_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				KEY conversation_id (conversation_id),
				KEY status (status),
				KEY priority (priority),
				KEY assigned_to (assigned_to),
				KEY created_at (created_at)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	/**
	 * Get escalations table name.
	 *
	 * @since 1.0.0
	 * @return string Table name.
	 */
	public function get_escalations_table() {
		global $wpdb;
		return $wpdb->prefix . 'wp_ai_chatbot_escalations';
	}

	/**
	 * Check if conversation should be escalated.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param array $context         Optional. Context data.
	 * @return array Escalation check result.
	 */
	public function check_escalation_needed( $conversation_id, $context = array() ) {
		$reasons = array();
		$priority = 'low';

		// Check sentiment if provided
		if ( ! empty( $context['sentiment'] ) ) {
			$sentiment = $context['sentiment'];

			// High frustration
			if ( isset( $sentiment['frustration_level'] ) ) {
				if ( $sentiment['frustration_level'] === 'critical' ) {
					$reasons[] = 'frustration';
					$priority = 'critical';
				} elseif ( $sentiment['frustration_level'] === 'high' ) {
					$reasons[] = 'frustration';
					$priority = max( $priority, 'high' );
				}
			}

			// Very negative sentiment
			if ( isset( $sentiment['sentiment_label'] ) && $sentiment['sentiment_label'] === 'very_negative' ) {
				$reasons[] = 'negative_sentiment';
				$priority = max( $priority, 'high' );
			}

			// Should escalate flag from sentiment analyzer
			if ( ! empty( $sentiment['should_escalate'] ) ) {
				if ( empty( $reasons ) ) {
					$reasons[] = 'frustration';
				}
				$priority = max( $priority, 'high' );
			}
		}

		// Check message content for explicit escalation requests
		if ( ! empty( $context['message'] ) ) {
			$message_lower = strtolower( $context['message'] );
			$escalation_phrases = array(
				'speak to a human',
				'talk to someone',
				'real person',
				'human agent',
				'speak to manager',
				'talk to manager',
				'supervisor',
				'escalate',
				'not helpful',
				'useless bot',
			);

			foreach ( $escalation_phrases as $phrase ) {
				if ( strpos( $message_lower, $phrase ) !== false ) {
					$reasons[] = 'user_request';
					$priority = max( $priority, 'high' );
					break;
				}
			}
		}

		// Check intent for complaint
		if ( ! empty( $context['intent'] ) && $context['intent'] === 'complaint' ) {
			if ( ! in_array( 'frustration', $reasons, true ) ) {
				$reasons[] = 'complaint';
			}
			$priority = max( $priority, 'medium' );
		}

		// Check conversation history for repeated issues
		$repeated = $this->check_repeated_issues( $conversation_id );
		if ( $repeated ) {
			$reasons[] = 'repeated_issues';
			$priority = max( $priority, 'medium' );
		}

		// Check if already escalated
		$existing = $this->get_active_escalation( $conversation_id );

		return array(
			'should_escalate' => ! empty( $reasons ) && empty( $existing ),
			'reasons'         => array_unique( $reasons ),
			'priority'        => $priority,
			'existing'        => $existing,
		);
	}

	/**
	 * Check for repeated issues in conversation.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return bool True if repeated issues detected.
	 */
	private function check_repeated_issues( $conversation_id ) {
		global $wpdb;
		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		// Get user messages
		$messages = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT content FROM {$messages_table} 
				 WHERE conversation_id = %d AND role = 'user' 
				 ORDER BY created_at DESC LIMIT 10",
				$conversation_id
			)
		);

		if ( count( $messages ) < 3 ) {
			return false;
		}

		// Check for similar consecutive messages (user repeating themselves)
		$repeated_count = 0;
		for ( $i = 0; $i < count( $messages ) - 1; $i++ ) {
			$similarity = similar_text( 
				strtolower( $messages[ $i ] ), 
				strtolower( $messages[ $i + 1 ] ), 
				$percent 
			);
			if ( $percent > 60 ) {
				$repeated_count++;
			}
		}

		return $repeated_count >= 2;
	}

	/**
	 * Create escalation.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $reason          Escalation reason.
	 * @param array  $data            Optional. Additional data.
	 * @return int|WP_Error Escalation ID or error.
	 */
	public function create_escalation( $conversation_id, $reason, $data = array() ) {
		global $wpdb;

		// Check for existing active escalation
		$existing = $this->get_active_escalation( $conversation_id );
		if ( $existing ) {
			return new WP_Error(
				'escalation_exists',
				__( 'An active escalation already exists for this conversation.', 'wp-ai-chatbot-leadgen-pro' ),
				array( 'escalation_id' => $existing['id'] )
			);
		}

		$priority = $data['priority'] ?? 'medium';
		$sentiment_data = $data['sentiment'] ?? array();
		$notes = $data['notes'] ?? '';

		$result = $wpdb->insert(
			$this->get_escalations_table(),
			array(
				'conversation_id' => $conversation_id,
				'reason'          => $reason,
				'priority'        => $priority,
				'status'          => 'pending',
				'sentiment_data'  => maybe_serialize( $sentiment_data ),
				'notes'           => $notes,
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'escalation_failed',
				__( 'Failed to create escalation.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$escalation_id = $wpdb->insert_id;

		// Update conversation status
		$this->update_conversation_status( $conversation_id, 'escalated' );

		// Send notifications
		$this->send_escalation_notifications( $escalation_id, $conversation_id, $reason, $priority );

		// Log the escalation
		$this->logger->info(
			'Escalation created',
			array(
				'escalation_id'   => $escalation_id,
				'conversation_id' => $conversation_id,
				'reason'          => $reason,
				'priority'        => $priority,
			)
		);

		/**
		 * Fires after an escalation is created.
		 *
		 * @since 1.0.0
		 * @param int    $escalation_id   Escalation ID.
		 * @param int    $conversation_id Conversation ID.
		 * @param string $reason          Escalation reason.
		 * @param string $priority        Escalation priority.
		 */
		do_action( 'wp_ai_chatbot_escalation_created', $escalation_id, $conversation_id, $reason, $priority );

		return $escalation_id;
	}

	/**
	 * Update conversation status.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $status          New status.
	 */
	private function update_conversation_status( $conversation_id, $status ) {
		global $wpdb;
		$conversations_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$wpdb->update(
			$conversations_table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Send escalation notifications.
	 *
	 * @since 1.0.0
	 * @param int    $escalation_id   Escalation ID.
	 * @param int    $conversation_id Conversation ID.
	 * @param string $reason          Escalation reason.
	 * @param string $priority        Escalation priority.
	 */
	private function send_escalation_notifications( $escalation_id, $conversation_id, $reason, $priority ) {
		$notification_enabled = $this->config->get( 'escalation_notifications_enabled', true );
		if ( ! $notification_enabled ) {
			return;
		}

		// Get notification recipients
		$recipients = $this->get_notification_recipients( $priority );

		if ( empty( $recipients ) ) {
			return;
		}

		// Get conversation summary
		$summary = $this->get_conversation_summary( $conversation_id );

		// Build email content
		$subject = sprintf(
			/* translators: 1: Priority level, 2: Escalation ID */
			__( '[%1$s] Chat Escalation #%2$d Requires Attention', 'wp-ai-chatbot-leadgen-pro' ),
			strtoupper( $priority ),
			$escalation_id
		);

		$reason_text = self::REASONS[ $reason ] ?? $reason;
		$admin_url = admin_url( 'admin.php?page=wp-ai-chatbot-escalations&action=view&id=' . $escalation_id );

		$message = sprintf(
			/* translators: Email body for escalation notification */
			__( "A chat conversation has been escalated and requires attention.\n\n" .
				"Escalation ID: %1\$d\n" .
				"Conversation ID: %2\$d\n" .
				"Reason: %3\$s\n" .
				"Priority: %4\$s\n\n" .
				"Conversation Summary:\n%5\$s\n\n" .
				"View and respond: %6\$s", 'wp-ai-chatbot-leadgen-pro' ),
			$escalation_id,
			$conversation_id,
			$reason_text,
			ucfirst( $priority ),
			$summary,
			$admin_url
		);

		// Send emails
		foreach ( $recipients as $email ) {
			wp_mail( $email, $subject, $message );
		}

		// Send webhook notification if configured
		$this->send_webhook_notification( $escalation_id, $conversation_id, $reason, $priority, $summary );
	}

	/**
	 * Get notification recipients based on priority.
	 *
	 * @since 1.0.0
	 * @param string $priority Escalation priority.
	 * @return array Email addresses.
	 */
	private function get_notification_recipients( $priority ) {
		$recipients = array();

		// Get configured recipients
		$configured = $this->config->get( 'escalation_recipients', array() );

		if ( ! empty( $configured ) ) {
			if ( is_string( $configured ) ) {
				$configured = array_map( 'trim', explode( ',', $configured ) );
			}
			$recipients = array_merge( $recipients, $configured );
		}

		// Add admins for critical escalations
		if ( in_array( $priority, array( 'critical', 'high' ), true ) ) {
			$admin_email = get_option( 'admin_email' );
			if ( $admin_email && ! in_array( $admin_email, $recipients, true ) ) {
				$recipients[] = $admin_email;
			}
		}

		// Filter valid emails
		$recipients = array_filter( $recipients, 'is_email' );

		return array_unique( $recipients );
	}

	/**
	 * Send webhook notification.
	 *
	 * @since 1.0.0
	 * @param int    $escalation_id   Escalation ID.
	 * @param int    $conversation_id Conversation ID.
	 * @param string $reason          Escalation reason.
	 * @param string $priority        Escalation priority.
	 * @param string $summary         Conversation summary.
	 */
	private function send_webhook_notification( $escalation_id, $conversation_id, $reason, $priority, $summary ) {
		$webhook_url = $this->config->get( 'escalation_webhook_url', '' );

		if ( empty( $webhook_url ) ) {
			return;
		}

		$payload = array(
			'event'           => 'escalation_created',
			'escalation_id'   => $escalation_id,
			'conversation_id' => $conversation_id,
			'reason'          => $reason,
			'reason_text'     => self::REASONS[ $reason ] ?? $reason,
			'priority'        => $priority,
			'summary'         => $summary,
			'timestamp'       => current_time( 'c' ),
			'site_url'        => home_url(),
		);

		wp_remote_post(
			$webhook_url,
			array(
				'body'    => wp_json_encode( $payload ),
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => 10,
			)
		);
	}

	/**
	 * Get conversation summary for notifications.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return string Summary text.
	 */
	private function get_conversation_summary( $conversation_id ) {
		global $wpdb;
		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		// Get last 5 messages
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$messages_table} 
				 WHERE conversation_id = %d 
				 ORDER BY created_at DESC LIMIT 5",
				$conversation_id
			),
			ARRAY_A
		);

		if ( empty( $messages ) ) {
			return __( 'No messages available.', 'wp-ai-chatbot-leadgen-pro' );
		}

		$summary = array();
		foreach ( array_reverse( $messages ) as $msg ) {
			$role = ucfirst( $msg['role'] );
			$content = wp_trim_words( $msg['content'], 30, '...' );
			$summary[] = "{$role}: {$content}";
		}

		return implode( "\n", $summary );
	}

	/**
	 * Assign escalation to operator.
	 *
	 * @since 1.0.0
	 * @param int $escalation_id Escalation ID.
	 * @param int $user_id       User ID of operator.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function assign_escalation( $escalation_id, $user_id ) {
		global $wpdb;

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'invalid_user',
				__( 'Invalid user ID.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$result = $wpdb->update(
			$this->get_escalations_table(),
			array(
				'assigned_to' => $user_id,
				'status'      => 'assigned',
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $escalation_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'assignment_failed',
				__( 'Failed to assign escalation.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$this->logger->info(
			'Escalation assigned',
			array(
				'escalation_id' => $escalation_id,
				'assigned_to'   => $user_id,
			)
		);

		/**
		 * Fires after an escalation is assigned.
		 *
		 * @since 1.0.0
		 * @param int $escalation_id Escalation ID.
		 * @param int $user_id       Assigned user ID.
		 */
		do_action( 'wp_ai_chatbot_escalation_assigned', $escalation_id, $user_id );

		return true;
	}

	/**
	 * Update escalation status.
	 *
	 * @since 1.0.0
	 * @param int    $escalation_id Escalation ID.
	 * @param string $status        New status.
	 * @param string $notes         Optional. Status notes.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function update_status( $escalation_id, $status, $notes = '' ) {
		global $wpdb;

		if ( ! isset( self::STATUSES[ $status ] ) ) {
			return new WP_Error(
				'invalid_status',
				__( 'Invalid escalation status.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$update_data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $status === 'resolved' || $status === 'closed' ) {
			$update_data['resolved_at'] = current_time( 'mysql' );
		}

		if ( ! empty( $notes ) ) {
			$escalation = $this->get_escalation( $escalation_id );
			$existing_notes = $escalation['notes'] ?? '';
			$update_data['notes'] = $existing_notes . "\n\n[" . current_time( 'mysql' ) . "] " . $notes;
		}

		$result = $wpdb->update(
			$this->get_escalations_table(),
			$update_data,
			array( 'id' => $escalation_id ),
			array_fill( 0, count( $update_data ), '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update escalation status.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Update conversation status if resolved
		if ( $status === 'resolved' || $status === 'closed' ) {
			$escalation = $this->get_escalation( $escalation_id );
			if ( $escalation ) {
				$this->update_conversation_status( $escalation['conversation_id'], 'resolved' );
			}
		}

		$this->logger->info(
			'Escalation status updated',
			array(
				'escalation_id' => $escalation_id,
				'status'        => $status,
			)
		);

		/**
		 * Fires after an escalation status is updated.
		 *
		 * @since 1.0.0
		 * @param int    $escalation_id Escalation ID.
		 * @param string $status        New status.
		 */
		do_action( 'wp_ai_chatbot_escalation_status_updated', $escalation_id, $status );

		return true;
	}

	/**
	 * Get escalation by ID.
	 *
	 * @since 1.0.0
	 * @param int $escalation_id Escalation ID.
	 * @return array|null Escalation data or null.
	 */
	public function get_escalation( $escalation_id ) {
		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_escalations_table()} WHERE id = %d",
				$escalation_id
			),
			ARRAY_A
		);

		if ( $result ) {
			$result['sentiment_data'] = maybe_unserialize( $result['sentiment_data'] );
		}

		return $result;
	}

	/**
	 * Get active escalation for conversation.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array|null Escalation data or null.
	 */
	public function get_active_escalation( $conversation_id ) {
		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_escalations_table()} 
				 WHERE conversation_id = %d 
				 AND status NOT IN ('resolved', 'closed') 
				 ORDER BY created_at DESC LIMIT 1",
				$conversation_id
			),
			ARRAY_A
		);

		if ( $result ) {
			$result['sentiment_data'] = maybe_unserialize( $result['sentiment_data'] );
		}

		return $result;
	}

	/**
	 * Get escalations list.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Escalations list.
	 */
	public function get_escalations( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'      => '',
			'priority'    => '',
			'assigned_to' => 0,
			'limit'       => 20,
			'offset'      => 0,
			'orderby'     => 'created_at',
			'order'       => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['priority'] ) ) {
			$where[] = 'priority = %s';
			$values[] = $args['priority'];
		}

		if ( $args['assigned_to'] > 0 ) {
			$where[] = 'assigned_to = %d';
			$values[] = $args['assigned_to'];
		}

		$where_sql = implode( ' AND ', $where );
		$orderby = sanitize_sql_orderby( "{$args['orderby']} {$args['order']}" );

		$sql = "SELECT * FROM {$this->get_escalations_table()} 
				WHERE {$where_sql} 
				ORDER BY {$orderby} 
				LIMIT %d OFFSET %d";

		$values[] = $args['limit'];
		$values[] = $args['offset'];

		$results = $wpdb->get_results(
			$wpdb->prepare( $sql, $values ),
			ARRAY_A
		);

		foreach ( $results as &$result ) {
			$result['sentiment_data'] = maybe_unserialize( $result['sentiment_data'] );
		}

		return $results;
	}

	/**
	 * Get escalation statistics.
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
		$table = $this->get_escalations_table();

		// Total escalations
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
				$since
			)
		);

		// By status
		$by_status = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as count FROM {$table} 
				 WHERE created_at >= %s GROUP BY status",
				$since
			),
			ARRAY_A
		);

		// By priority
		$by_priority = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT priority, COUNT(*) as count FROM {$table} 
				 WHERE created_at >= %s GROUP BY priority",
				$since
			),
			ARRAY_A
		);

		// By reason
		$by_reason = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reason, COUNT(*) as count FROM {$table} 
				 WHERE created_at >= %s GROUP BY reason",
				$since
			),
			ARRAY_A
		);

		// Average resolution time
		$avg_resolution = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) 
				 FROM {$table} 
				 WHERE created_at >= %s AND resolved_at IS NOT NULL",
				$since
			)
		);

		// Pending count
		$pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} 
				 WHERE status IN ('pending', 'assigned', 'in_progress') 
				 AND created_at >= %s",
				$since
			)
		);

		return array(
			'total'               => intval( $total ),
			'pending'             => intval( $pending ),
			'by_status'           => $by_status,
			'by_priority'         => $by_priority,
			'by_reason'           => $by_reason,
			'avg_resolution_mins' => $avg_resolution ? round( floatval( $avg_resolution ), 1 ) : null,
		);
	}

	/**
	 * Get user-facing escalation message.
	 *
	 * @since 1.0.0
	 * @param string $reason   Escalation reason.
	 * @param string $priority Escalation priority.
	 * @return string User-facing message.
	 */
	public function get_escalation_message( $reason, $priority ) {
		$messages = array(
			'critical' => __( 'I understand this is urgent. I\'m connecting you with a member of our team who can help you right away. They\'ll be with you shortly.', 'wp-ai-chatbot-leadgen-pro' ),
			'high'     => __( 'I\'m connecting you with one of our team members who can better assist you. Someone will be with you soon.', 'wp-ai-chatbot-leadgen-pro' ),
			'medium'   => __( 'I\'m going to connect you with a team member who can help. You should hear back from them shortly.', 'wp-ai-chatbot-leadgen-pro' ),
			'low'      => __( 'I\'ve noted your request and a team member will follow up with you. Is there anything else I can help with in the meantime?', 'wp-ai-chatbot-leadgen-pro' ),
		);

		return $messages[ $priority ] ?? $messages['medium'];
	}

	/**
	 * Auto-escalate conversation if needed.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param array $context         Context data.
	 * @return array|null Escalation result or null if not escalated.
	 */
	public function auto_escalate( $conversation_id, $context = array() ) {
		$check = $this->check_escalation_needed( $conversation_id, $context );

		if ( ! $check['should_escalate'] ) {
			return null;
		}

		$reason = $check['reasons'][0] ?? 'frustration';
		$priority = $check['priority'];

		$escalation_id = $this->create_escalation(
			$conversation_id,
			$reason,
			array(
				'priority'  => $priority,
				'sentiment' => $context['sentiment'] ?? array(),
				'notes'     => sprintf(
					__( 'Auto-escalated. Reasons: %s', 'wp-ai-chatbot-leadgen-pro' ),
					implode( ', ', $check['reasons'] )
				),
			)
		);

		if ( is_wp_error( $escalation_id ) ) {
			return array(
				'escalated' => false,
				'error'     => $escalation_id->get_error_message(),
			);
		}

		return array(
			'escalated'      => true,
			'escalation_id'  => $escalation_id,
			'reason'         => $reason,
			'priority'       => $priority,
			'message'        => $this->get_escalation_message( $reason, $priority ),
		);
	}
}

