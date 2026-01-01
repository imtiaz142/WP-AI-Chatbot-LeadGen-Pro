<?php
/**
 * Conversation Manager.
 *
 * Handles conversation and message storage and retrieval.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager {

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
		$this->database = new WP_AI_Chatbot_LeadGen_Pro_Database();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
	}

	/**
	 * Create a new conversation.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Conversation arguments.
	 * @return int|WP_Error Conversation ID on success, WP_Error on failure.
	 */
	public function create_conversation( $args = array() ) {
		$defaults = array(
			'user_id'        => get_current_user_id(),
			'lead_id'        => null,
			'session_id'     => $this->get_session_id(),
			'ip_address'     => $this->get_client_ip(),
			'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'referrer'       => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( $_SERVER['HTTP_REFERER'] ) : '',
			'page_url'       => $this->get_current_page_url(),
			'status'         => 'active',
			'metadata'       => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$conversation_data = array(
			'user_id'    => intval( $args['user_id'] ) ?: null,
			'lead_id'    => $args['lead_id'] ? intval( $args['lead_id'] ) : null,
			'session_id' => sanitize_text_field( $args['session_id'] ),
			'ip_address' => sanitize_text_field( $args['ip_address'] ),
			'user_agent' => sanitize_text_field( $args['user_agent'] ),
			'referrer'   => $args['referrer'],
			'page_url'   => $args['page_url'],
			'status'     => sanitize_text_field( $args['status'] ),
			'metadata'   => ! empty( $args['metadata'] ) ? wp_json_encode( $args['metadata'] ) : null,
		);

		$conversation_id = WP_AI_Chatbot_LeadGen_Pro_Database::insert_conversation( $conversation_data );

		if ( ! $conversation_id ) {
			$this->logger->error(
				'Failed to create conversation',
				array( 'args' => $args )
			);
			return new WP_Error(
				'conversation_creation_failed',
				__( 'Failed to create conversation.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$this->logger->debug(
			'Conversation created',
			array( 'conversation_id' => $conversation_id )
		);

		return $conversation_id;
	}

	/**
	 * Get conversation by ID.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return object|false Conversation object or false if not found.
	 */
	public function get_conversation( $conversation_id ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				intval( $conversation_id )
			)
		);

		if ( $conversation && ! empty( $conversation->metadata ) ) {
			$conversation->metadata = json_decode( $conversation->metadata, true );
		}

		return $conversation;
	}

	/**
	 * Get conversation by session ID.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $status     Optional. Conversation status. Default 'active'.
	 * @return object|false Conversation object or false if not found.
	 */
	public function get_conversation_by_session( $session_id, $status = 'active' ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$query = "SELECT * FROM {$table} WHERE session_id = %s";
		$params = array( sanitize_text_field( $session_id ) );

		if ( ! empty( $status ) ) {
			$query .= " AND status = %s";
			$params[] = sanitize_text_field( $status );
		}

		$query .= " ORDER BY created_at DESC LIMIT 1";

		$conversation = $wpdb->get_row(
			$wpdb->prepare( $query, $params )
		);

		if ( $conversation && ! empty( $conversation->metadata ) ) {
			$conversation->metadata = json_decode( $conversation->metadata, true );
		}

		return $conversation;
	}

	/**
	 * Get or create conversation for current session.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Conversation arguments.
	 * @return int|WP_Error Conversation ID on success, WP_Error on failure.
	 */
	public function get_or_create_conversation( $args = array() ) {
		$session_id = isset( $args['session_id'] ) ? $args['session_id'] : $this->get_session_id();

		// Try to get existing active conversation
		$conversation = $this->get_conversation_by_session( $session_id, 'active' );

		if ( $conversation ) {
			return intval( $conversation->id );
		}

		// Create new conversation
		return $this->create_conversation( $args );
	}

	/**
	 * Add a message to a conversation.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $role            Message role (user, assistant, system).
	 * @param string $content         Message content.
	 * @param array  $metadata        Optional. Message metadata.
	 * @return int|WP_Error Message ID on success, WP_Error on failure.
	 */
	public function add_message( $conversation_id, $role, $content, $metadata = array() ) {
		if ( empty( $conversation_id ) || empty( $role ) || empty( $content ) ) {
			return new WP_Error(
				'invalid_parameters',
				__( 'Conversation ID, role, and content are required.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Validate role
		$valid_roles = array( 'user', 'assistant', 'system' );
		if ( ! in_array( $role, $valid_roles, true ) ) {
			return new WP_Error(
				'invalid_role',
				__( 'Invalid message role.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$message_data = array(
			'conversation_id'  => intval( $conversation_id ),
			'role'             => sanitize_text_field( $role ),
			'message_text'     => wp_kses_post( $content ),
			'token_count'      => isset( $metadata['token_count'] ) ? intval( $metadata['token_count'] ) : null,
			'similarity_score' => isset( $metadata['similarity_score'] ) ? floatval( $metadata['similarity_score'] ) : null,
			'feedback'         => isset( $metadata['feedback'] ) ? sanitize_text_field( $metadata['feedback'] ) : null,
			'citations'        => isset( $metadata['citations'] ) ? wp_json_encode( $metadata['citations'] ) : null,
			'metadata'         => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
		);

		$message_id = WP_AI_Chatbot_LeadGen_Pro_Database::insert_message( $message_data );

		if ( ! $message_id ) {
			$this->logger->error(
				'Failed to add message',
				array(
					'conversation_id' => $conversation_id,
					'role'            => $role,
				)
			);
			return new WP_Error(
				'message_creation_failed',
				__( 'Failed to add message to conversation.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Update conversation last activity
		$this->update_conversation_activity( $conversation_id );

		$this->logger->debug(
			'Message added',
			array(
				'conversation_id' => $conversation_id,
				'message_id'      => $message_id,
				'role'            => $role,
			)
		);

		return $message_id;
	}

	/**
	 * Get messages for a conversation.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param array $args            Optional. Query arguments.
	 * @return array Array of message objects.
	 */
	public function get_messages( $conversation_id, $args = array() ) {
		$defaults = array(
			'limit'  => 100,
			'offset' => 0,
			'order'  => 'ASC', // ASC for chronological, DESC for reverse
			'role'   => null,  // Filter by role
		);

		$args = wp_parse_args( $args, $defaults );

		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$query = "SELECT * FROM {$table} WHERE conversation_id = %d";
		$params = array( intval( $conversation_id ) );

		if ( ! empty( $args['role'] ) ) {
			$query .= " AND role = %s";
			$params[] = sanitize_text_field( $args['role'] );
		}

		$order = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';
		$query .= " ORDER BY created_at {$order}";

		if ( $args['limit'] > 0 ) {
			$query .= " LIMIT %d";
			$params[] = intval( $args['limit'] );

			if ( $args['offset'] > 0 ) {
				$query .= " OFFSET %d";
				$params[] = intval( $args['offset'] );
			}
		}

		$messages = $wpdb->get_results(
			$wpdb->prepare( $query, $params )
		);

		// Decode JSON fields
		foreach ( $messages as $message ) {
			if ( ! empty( $message->citations ) ) {
				$message->citations = json_decode( $message->citations, true );
			}
			if ( ! empty( $message->metadata ) ) {
				$message->metadata = json_decode( $message->metadata, true );
			}
		}

		return $messages;
	}

	/**
	 * Get message by ID.
	 *
	 * @since 1.0.0
	 * @param int $message_id Message ID.
	 * @return object|false Message object or false if not found.
	 */
	public function get_message( $message_id ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$message = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				intval( $message_id )
			)
		);

		if ( $message ) {
			if ( ! empty( $message->citations ) ) {
				$message->citations = json_decode( $message->citations, true );
			}
			if ( ! empty( $message->metadata ) ) {
				$message->metadata = json_decode( $message->metadata, true );
			}
		}

		return $message;
	}

	/**
	 * Update message.
	 *
	 * @since 1.0.0
	 * @param int   $message_id Message ID.
	 * @param array $data       Message data to update.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_message( $message_id, $data ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$update_data = array();
		$format = array();

		if ( isset( $data['message_text'] ) ) {
			$update_data['message_text'] = wp_kses_post( $data['message_text'] );
			$format[] = '%s';
		}

		if ( isset( $data['token_count'] ) ) {
			$update_data['token_count'] = intval( $data['token_count'] );
			$format[] = '%d';
		}

		if ( isset( $data['similarity_score'] ) ) {
			$update_data['similarity_score'] = floatval( $data['similarity_score'] );
			$format[] = '%f';
		}

		if ( isset( $data['feedback'] ) ) {
			$update_data['feedback'] = sanitize_text_field( $data['feedback'] );
			$format[] = '%s';
		}

		if ( isset( $data['citations'] ) ) {
			$update_data['citations'] = is_array( $data['citations'] ) ? wp_json_encode( $data['citations'] ) : $data['citations'];
			$format[] = '%s';
		}

		if ( isset( $data['metadata'] ) ) {
			$update_data['metadata'] = is_array( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : $data['metadata'];
			$format[] = '%s';
		}

		if ( empty( $update_data ) ) {
			return new WP_Error(
				'no_data',
				__( 'No data provided to update.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => intval( $message_id ) ),
			$format,
			array( '%d' )
		);

		if ( false === $result ) {
			$this->logger->error(
				'Failed to update message',
				array(
					'message_id' => $message_id,
					'error'      => $wpdb->last_error,
				)
			);
			return new WP_Error(
				'update_failed',
				__( 'Failed to update message.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return true;
	}

	/**
	 * Update conversation activity timestamp.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return bool True on success, false on failure.
	 */
	public function update_conversation_activity( $conversation_id ) {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$result = $wpdb->update(
			$table,
			array( 'last_activity_at' => current_time( 'mysql' ) ),
			array( 'id' => intval( $conversation_id ) ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update conversation status.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $status          New status.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_conversation_status( $conversation_id, $status ) {
		$valid_statuses = array( 'active', 'closed', 'archived' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			return new WP_Error(
				'invalid_status',
				__( 'Invalid conversation status.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$result = $wpdb->update(
			$table,
			array( 'status' => sanitize_text_field( $status ) ),
			array( 'id' => intval( $conversation_id ) ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update conversation status.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return true;
	}

	/**
	 * Get conversation statistics.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array Statistics.
	 */
	public function get_conversation_stats( $conversation_id ) {
		global $wpdb;

		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();
		$conversations_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$conversation = $this->get_conversation( $conversation_id );
		if ( ! $conversation ) {
			return array();
		}

		$stats = array(
			'total_messages'      => 0,
			'user_messages'       => 0,
			'assistant_messages'  => 0,
			'total_tokens'        => 0,
			'avg_similarity'      => 0,
			'positive_feedback'   => 0,
			'negative_feedback'   => 0,
			'duration_minutes'    => 0,
		);

		// Get message counts
		$message_counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					COUNT(*) as total,
					SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) as user_count,
					SUM(CASE WHEN role = 'assistant' THEN 1 ELSE 0 END) as assistant_count,
					SUM(token_count) as total_tokens,
					AVG(similarity_score) as avg_similarity,
					SUM(CASE WHEN feedback = 'positive' THEN 1 ELSE 0 END) as positive_feedback,
					SUM(CASE WHEN feedback = 'negative' THEN 1 ELSE 0 END) as negative_feedback
				FROM {$messages_table}
				WHERE conversation_id = %d",
				$conversation_id
			)
		);

		if ( $message_counts ) {
			$stats['total_messages'] = intval( $message_counts->total );
			$stats['user_messages'] = intval( $message_counts->user_count );
			$stats['assistant_messages'] = intval( $message_counts->assistant_count );
			$stats['total_tokens'] = intval( $message_counts->total_tokens );
			$stats['avg_similarity'] = floatval( $message_counts->avg_similarity );
			$stats['positive_feedback'] = intval( $message_counts->positive_feedback );
			$stats['negative_feedback'] = intval( $message_counts->negative_feedback );
		}

		// Calculate duration
		if ( $conversation->created_at && $conversation->last_activity_at ) {
			$start = strtotime( $conversation->created_at );
			$end = strtotime( $conversation->last_activity_at );
			$stats['duration_minutes'] = round( ( $end - $start ) / 60, 1 );
		}

		return $stats;
	}

	/**
	 * Get session ID.
	 *
	 * @since 1.0.0
	 * @return string Session ID.
	 */
	private function get_session_id() {
		if ( ! session_id() ) {
			session_start();
		}

		if ( ! isset( $_SESSION['wp_ai_chatbot_session_id'] ) ) {
			$_SESSION['wp_ai_chatbot_session_id'] = wp_generate_uuid4();
		}

		return $_SESSION['wp_ai_chatbot_session_id'];
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.0.0
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( $_SERVER[ $key ] );
				// Handle comma-separated IPs (from proxies)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Get current page URL.
	 *
	 * @since 1.0.0
	 * @return string Current page URL.
	 */
	private function get_current_page_url() {
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$protocol = isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
			$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
			$uri = esc_url_raw( $_SERVER['REQUEST_URI'] );
			return $protocol . '://' . $host . $uri;
		}
		return '';
	}
}

