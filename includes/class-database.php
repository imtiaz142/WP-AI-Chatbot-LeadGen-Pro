<?php
/**
 * Database operations class.
 *
 * Provides helper methods for database queries and schema management.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Database {

	/**
	 * Get table name with WordPress prefix.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name without prefix.
	 * @return string Full table name with prefix.
	 */
	public static function get_table_name( $table_name ) {
		global $wpdb;
		return $wpdb->prefix . 'ai_chatbot_' . $table_name;
	}

	/**
	 * Get conversations table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_conversations_table() {
		return self::get_table_name( 'conversations' );
	}

	/**
	 * Get messages table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_messages_table() {
		return self::get_table_name( 'messages' );
	}

	/**
	 * Get leads table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_leads_table() {
		return self::get_table_name( 'leads' );
	}

	/**
	 * Get lead behavior table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_lead_behavior_table() {
		return self::get_table_name( 'lead_behavior' );
	}

	/**
	 * Get content chunks table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_content_chunks_table() {
		return self::get_table_name( 'content_chunks' );
	}

	/**
	 * Get embeddings table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_embeddings_table() {
		return self::get_table_name( 'embeddings' );
	}

	/**
	 * Get segments table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_segments_table() {
		return self::get_table_name( 'segments' );
	}

	/**
	 * Get lead segments table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_lead_segments_table() {
		return self::get_table_name( 'lead_segments' );
	}

	/**
	 * Get analytics table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_analytics_table() {
		return self::get_table_name( 'analytics' );
	}

	/**
	 * Get A/B tests table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_ab_tests_table() {
		return self::get_table_name( 'ab_tests' );
	}

	/**
	 * Get webhooks table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_webhooks_table() {
		return self::get_table_name( 'webhooks' );
	}

	/**
	 * Check if database tables exist.
	 *
	 * @since 1.0.0
	 * @return bool True if all tables exist, false otherwise.
	 */
	public static function tables_exist() {
		global $wpdb;

		$tables = array(
			self::get_conversations_table(),
			self::get_messages_table(),
			self::get_leads_table(),
			self::get_lead_behavior_table(),
			self::get_content_chunks_table(),
			self::get_embeddings_table(),
			self::get_segments_table(),
			self::get_lead_segments_table(),
			self::get_analytics_table(),
			self::get_ab_tests_table(),
			self::get_webhooks_table(),
		);

		foreach ( $tables as $table ) {
			$table_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$table
				)
			);

			if ( $table !== $table_exists ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get database schema version.
	 *
	 * @since 1.0.0
	 * @return string Database version.
	 */
	public static function get_db_version() {
		return get_option( 'wp_ai_chatbot_leadgen_pro_db_version', '0.0.0' );
	}

	/**
	 * Update database schema version.
	 *
	 * @since 1.0.0
	 * @param string $version Version number.
	 */
	public static function update_db_version( $version ) {
		update_option( 'wp_ai_chatbot_leadgen_pro_db_version', $version );
	}

	/**
	 * Run database migrations if needed.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_run_migrations() {
		require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-migrations.php';
		$migrations = new WP_AI_Chatbot_LeadGen_Pro_Migrations();
		$migrations->maybe_run_migrations();
	}

	/**
	 * Insert a conversation.
	 *
	 * @since 1.0.0
	 * @param array $data Conversation data.
	 * @return int|false Conversation ID on success, false on failure.
	 */
	public static function insert_conversation( $data ) {
		global $wpdb;

		$table = self::get_conversations_table();

		$defaults = array(
			'session_id'     => '',
			'user_id'        => null,
			'visitor_id'     => null,
			'lead_id'        => null,
			'status'         => 'active',
			'channel'        => 'website',
			'page_url'       => '',
			'traffic_source' => null,
		);

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Insert a message.
	 *
	 * @since 1.0.0
	 * @param array $data Message data.
	 * @return int|false Message ID on success, false on failure.
	 */
	public static function insert_message( $data ) {
		global $wpdb;

		$table = self::get_messages_table();

		$defaults = array(
			'conversation_id'   => 0,
			'role'              => 'user',
			'content'           => '',
			'intent'            => null,
			'intent_confidence' => null,
			'sentiment'         => null,
			'sentiment_score'   => null,
			'citations'         => null,
			'model_used'        => null,
			'api_cost'          => null,
			'response_time'     => null,
			'feedback'          => null,
		);

		$data = wp_parse_args( $data, $defaults );

		// Serialize citations if array
		if ( is_array( $data['citations'] ) ) {
			$data['citations'] = wp_json_encode( $data['citations'] );
		}

		$result = $wpdb->insert(
			$table,
			$data,
			array( '%d', '%s', '%s', '%s', '%f', '%s', '%f', '%s', '%s', '%f', '%d', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Insert a lead.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return int|false Lead ID on success, false on failure.
	 */
	public static function insert_lead( $data ) {
		global $wpdb;

		$table = self::get_leads_table();

		$defaults = array(
			'email'                => '',
			'first_name'           => null,
			'last_name'            => null,
			'phone'                => null,
			'company'              => null,
			'job_title'            => null,
			'lead_score'           => 0,
			'lead_grade'           => 'D',
			'behavioral_score'     => 0,
			'intent_score'         => 0,
			'qualification_score'  => 0,
			'status'               => 'new',
			'conversation_id'      => null,
			'enriched_data'        => null,
			'enrichment_status'    => 'pending',
			'crm_synced'           => 0,
			'crm_id'               => null,
			'crm_provider'         => null,
		);

		$data = wp_parse_args( $data, $defaults );

		// Serialize enriched_data if array
		if ( is_array( $data['enriched_data'] ) ) {
			$data['enriched_data'] = wp_json_encode( $data['enriched_data'] );
		}

		$result = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Get conversation by ID.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return object|null Conversation object or null if not found.
	 */
	public static function get_conversation( $conversation_id ) {
		global $wpdb;

		$table = self::get_conversations_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$conversation_id
			)
		);
	}

	/**
	 * Get messages for a conversation.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param array $args Query arguments.
	 * @return array Array of message objects.
	 */
	public static function get_conversation_messages( $conversation_id, $args = array() ) {
		global $wpdb;

		$table = self::get_messages_table();

		$defaults = array(
			'orderby' => 'created_at',
			'order'   => 'ASC',
			'limit'   => 0,
			'offset'  => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT * FROM {$table} WHERE conversation_id = %d";

		$query .= ' ORDER BY ' . esc_sql( $args['orderby'] ) . ' ' . esc_sql( $args['order'] );

		if ( $args['limit'] > 0 ) {
			$query .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		}

		return $wpdb->get_results(
			$wpdb->prepare( $query, $conversation_id )
		);
	}

	/**
	 * Get lead by ID.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return object|null Lead object or null if not found.
	 */
	public static function get_lead( $lead_id ) {
		global $wpdb;

		$table = self::get_leads_table();

		$lead = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$lead_id
			)
		);

		// Unserialize enriched_data if present
		if ( $lead && ! empty( $lead->enriched_data ) ) {
			$lead->enriched_data = json_decode( $lead->enriched_data, true );
		}

		return $lead;
	}

	/**
	 * Get lead by email.
	 *
	 * @since 1.0.0
	 * @param string $email Email address.
	 * @return object|null Lead object or null if not found.
	 */
	public static function get_lead_by_email( $email ) {
		global $wpdb;

		$table = self::get_leads_table();

		$lead = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE email = %s",
				$email
			)
		);

		// Unserialize enriched_data if present
		if ( $lead && ! empty( $lead->enriched_data ) ) {
			$lead->enriched_data = json_decode( $lead->enriched_data, true );
		}

		return $lead;
	}

	/**
	 * Update lead.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id Lead ID.
	 * @param array $data    Data to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update_lead( $lead_id, $data ) {
		global $wpdb;

		$table = self::get_leads_table();

		// Serialize enriched_data if array
		if ( isset( $data['enriched_data'] ) && is_array( $data['enriched_data'] ) ) {
			$data['enriched_data'] = wp_json_encode( $data['enriched_data'] );
		}

		return false !== $wpdb->update(
			$table,
			$data,
			array( 'id' => $lead_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Insert content chunk.
	 *
	 * @since 1.0.0
	 * @param array $data Content chunk data.
	 * @return int|false Chunk ID on success, false on failure.
	 */
	public static function insert_content_chunk( $data ) {
		global $wpdb;

		$table = self::get_content_chunks_table();

		$defaults = array(
			'source_type'  => '',
			'source_url'   => '',
			'source_id'    => null,
			'chunk_index'  => 0,
			'content'      => '',
			'content_hash' => '',
			'word_count'   => 0,
			'token_count'  => 0,
			'embedding_model' => null,
			'last_updated' => null,
		);

		$data = wp_parse_args( $data, $defaults );

		// Generate content hash if not provided
		if ( empty( $data['content_hash'] ) && ! empty( $data['content'] ) ) {
			$data['content_hash'] = hash( 'sha256', $data['content'] );
		}

		$result = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Insert embedding.
	 *
	 * @since 1.0.0
	 * @param array $data Embedding data.
	 * @return int|false Embedding ID on success, false on failure.
	 */
	public static function insert_embedding( $data ) {
		global $wpdb;

		$table = self::get_embeddings_table();

		$defaults = array(
			'chunk_id'        => 0,
			'embedding_model' => '',
			'embedding_vector' => '',
			'dimension'       => 0,
		);

		$data = wp_parse_args( $data, $defaults );

		// Serialize embedding vector if array
		if ( is_array( $data['embedding_vector'] ) ) {
			$data['embedding_vector'] = wp_json_encode( $data['embedding_vector'] );
		}

		$result = $wpdb->insert(
			$table,
			$data,
			array( '%d', '%s', '%s', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Insert analytics event.
	 *
	 * @since 1.0.0
	 * @param array $data Analytics event data.
	 * @return int|false Event ID on success, false on failure.
	 */
	public static function insert_analytics_event( $data ) {
		global $wpdb;

		$table = self::get_analytics_table();

		$defaults = array(
			'event_type'      => '',
			'conversation_id' => null,
			'lead_id'         => null,
			'event_data'      => null,
		);

		$data = wp_parse_args( $data, $defaults );

		// Serialize event_data if array
		if ( is_array( $data['event_data'] ) ) {
			$data['event_data'] = wp_json_encode( $data['event_data'] );
		}

		$result = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%d', '%d', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}
}

