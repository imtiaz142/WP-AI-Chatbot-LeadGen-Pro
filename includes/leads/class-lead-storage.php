<?php
/**
 * Lead Storage.
 *
 * Handles storage and retrieval of lead data in the database.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Lead_Storage {

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
	 * Lead meta table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $meta_table_name;

	/**
	 * Lead statuses.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const STATUSES = array(
		'new'        => 'New',
		'contacted'  => 'Contacted',
		'qualified'  => 'Qualified',
		'unqualified' => 'Unqualified',
		'converted'  => 'Converted',
		'lost'       => 'Lost',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->table_name = $wpdb->prefix . 'ai_chatbot_leads';
		$this->meta_table_name = $wpdb->prefix . 'ai_chatbot_lead_meta';

		$this->maybe_create_tables();
	}

	/**
	 * Create database tables if they don't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Leads table
		$sql_leads = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT(20) UNSIGNED DEFAULT NULL,
			session_id VARCHAR(100) DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			name VARCHAR(255) DEFAULT NULL,
			email VARCHAR(255) DEFAULT NULL,
			phone VARCHAR(50) DEFAULT NULL,
			company VARCHAR(255) DEFAULT NULL,
			message TEXT DEFAULT NULL,
			source VARCHAR(100) DEFAULT 'chatbot',
			source_url TEXT DEFAULT NULL,
			referrer TEXT DEFAULT NULL,
			status VARCHAR(50) DEFAULT 'new',
			score INT(11) DEFAULT 0,
			score_breakdown TEXT DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			user_agent TEXT DEFAULT NULL,
			geo_data TEXT DEFAULT NULL,
			utm_source VARCHAR(255) DEFAULT NULL,
			utm_medium VARCHAR(255) DEFAULT NULL,
			utm_campaign VARCHAR(255) DEFAULT NULL,
			utm_content VARCHAR(255) DEFAULT NULL,
			utm_term VARCHAR(255) DEFAULT NULL,
			consent_given TINYINT(1) DEFAULT 0,
			consent_timestamp DATETIME DEFAULT NULL,
			assigned_to BIGINT(20) UNSIGNED DEFAULT NULL,
			last_contacted_at DATETIME DEFAULT NULL,
			converted_at DATETIME DEFAULT NULL,
			custom_fields TEXT DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY conversation_id (conversation_id),
			KEY session_id (session_id),
			KEY user_id (user_id),
			KEY email (email),
			KEY status (status),
			KEY score (score),
			KEY created_at (created_at)
		) $charset_collate;";

		// Lead meta table
		$sql_meta = "CREATE TABLE IF NOT EXISTS {$this->meta_table_name} (
			meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT(20) UNSIGNED NOT NULL,
			meta_key VARCHAR(255) NOT NULL,
			meta_value LONGTEXT DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (meta_id),
			KEY lead_id (lead_id),
			KEY meta_key (meta_key(191))
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_leads );
		dbDelta( $sql_meta );
	}

	/**
	 * Store a new lead.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return int|WP_Error Lead ID on success, WP_Error on failure.
	 */
	public function store( $data ) {
		global $wpdb;

		// Prepare data
		$lead_data = array(
			'conversation_id' => $data['conversation_id'] ?? null,
			'session_id'      => $data['session_id'] ?? null,
			'user_id'         => $data['user_id'] ?? get_current_user_id() ?: null,
			'name'            => $data['name'] ?? null,
			'email'           => $data['email'] ?? null,
			'phone'           => $data['phone'] ?? null,
			'company'         => $data['company'] ?? null,
			'message'         => $data['message'] ?? null,
			'source'          => $data['source'] ?? 'chatbot',
			'source_url'      => $data['source_url'] ?? null,
			'referrer'        => $data['referrer'] ?? ( $_SERVER['HTTP_REFERER'] ?? null ),
			'status'          => $data['status'] ?? 'new',
			'score'           => $data['score'] ?? 0,
			'ip_address'      => $this->get_client_ip(),
			'user_agent'      => $data['user_agent'] ?? ( $_SERVER['HTTP_USER_AGENT'] ?? null ),
			'consent_given'   => ! empty( $data['gdpr_consent'] ) ? 1 : 0,
			'consent_timestamp' => ! empty( $data['gdpr_consent'] ) ? current_time( 'mysql' ) : null,
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);

		// Add UTM parameters if present
		if ( ! empty( $data['utm'] ) ) {
			$lead_data['utm_source']   = $data['utm']['source'] ?? null;
			$lead_data['utm_medium']   = $data['utm']['medium'] ?? null;
			$lead_data['utm_campaign'] = $data['utm']['campaign'] ?? null;
			$lead_data['utm_content']  = $data['utm']['content'] ?? null;
			$lead_data['utm_term']     = $data['utm']['term'] ?? null;
		}

		// Store custom fields
		$custom_fields = array();
		$standard_fields = array( 'conversation_id', 'session_id', 'user_id', 'name', 'email', 'phone', 'company', 'message', 'source', 'source_url', 'referrer', 'status', 'score', 'gdpr_consent', 'utm', 'nonce' );
		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $standard_fields, true ) && ! empty( $value ) ) {
				$custom_fields[ $key ] = $value;
			}
		}
		if ( ! empty( $custom_fields ) ) {
			$lead_data['custom_fields'] = wp_json_encode( $custom_fields );
		}

		// Insert lead
		$result = $wpdb->insert( $this->table_name, $lead_data );

		if ( false === $result ) {
			$this->logger->error( 'Failed to store lead', array(
				'error' => $wpdb->last_error,
				'data'  => $lead_data,
			) );
			return new WP_Error( 'db_error', __( 'Failed to save lead data.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		$lead_id = $wpdb->insert_id;

		$this->logger->info( 'Lead stored successfully', array(
			'lead_id' => $lead_id,
			'email'   => $lead_data['email'],
		) );

		// Trigger action for integrations
		do_action( 'wp_ai_chatbot_lead_created', $lead_id, $lead_data );

		return $lead_id;
	}

	/**
	 * Get a lead by ID.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array|null Lead data or null.
	 */
	public function get( $lead_id ) {
		global $wpdb;

		$lead = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE id = %d",
				$lead_id
			),
			ARRAY_A
		);

		if ( ! $lead ) {
			return null;
		}

		// Decode JSON fields
		if ( ! empty( $lead['custom_fields'] ) ) {
			$lead['custom_fields'] = json_decode( $lead['custom_fields'], true );
		}
		if ( ! empty( $lead['score_breakdown'] ) ) {
			$lead['score_breakdown'] = json_decode( $lead['score_breakdown'], true );
		}
		if ( ! empty( $lead['geo_data'] ) ) {
			$lead['geo_data'] = json_decode( $lead['geo_data'], true );
		}

		return $lead;
	}

	/**
	 * Get lead by email.
	 *
	 * @since 1.0.0
	 * @param string $email Email address.
	 * @return array|null Lead data or null.
	 */
	public function get_by_email( $email ) {
		global $wpdb;

		$lead = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE email = %s ORDER BY created_at DESC LIMIT 1",
				$email
			),
			ARRAY_A
		);

		if ( ! $lead ) {
			return null;
		}

		// Decode JSON fields
		if ( ! empty( $lead['custom_fields'] ) ) {
			$lead['custom_fields'] = json_decode( $lead['custom_fields'], true );
		}

		return $lead;
	}

	/**
	 * Get leads by conversation ID.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array Leads.
	 */
	public function get_by_conversation( $conversation_id ) {
		global $wpdb;

		$leads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE conversation_id = %d ORDER BY created_at DESC",
				$conversation_id
			),
			ARRAY_A
		);

		return $leads ?: array();
	}

	/**
	 * Update a lead.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id Lead ID.
	 * @param array $data    Data to update.
	 * @return bool True on success.
	 */
	public function update( $lead_id, $data ) {
		global $wpdb;

		// JSON encode special fields
		if ( isset( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ) {
			$data['custom_fields'] = wp_json_encode( $data['custom_fields'] );
		}
		if ( isset( $data['score_breakdown'] ) && is_array( $data['score_breakdown'] ) ) {
			$data['score_breakdown'] = wp_json_encode( $data['score_breakdown'] );
		}
		if ( isset( $data['geo_data'] ) && is_array( $data['geo_data'] ) ) {
			$data['geo_data'] = wp_json_encode( $data['geo_data'] );
		}

		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $lead_id )
		);

		if ( false === $result ) {
			$this->logger->error( 'Failed to update lead', array(
				'lead_id' => $lead_id,
				'error'   => $wpdb->last_error,
			) );
			return false;
		}

		do_action( 'wp_ai_chatbot_lead_updated', $lead_id, $data );

		return true;
	}

	/**
	 * Update lead status.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id Lead ID.
	 * @param string $status  New status.
	 * @return bool True on success.
	 */
	public function update_status( $lead_id, $status ) {
		if ( ! array_key_exists( $status, self::STATUSES ) ) {
			return false;
		}

		$data = array( 'status' => $status );

		// Track conversion
		if ( $status === 'converted' ) {
			$data['converted_at'] = current_time( 'mysql' );
		}

		return $this->update( $lead_id, $data );
	}

	/**
	 * Update lead score.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id         Lead ID.
	 * @param int   $score           New score.
	 * @param array $score_breakdown Score breakdown.
	 * @return bool True on success.
	 */
	public function update_score( $lead_id, $score, $score_breakdown = array() ) {
		return $this->update( $lead_id, array(
			'score'           => $score,
			'score_breakdown' => $score_breakdown,
		) );
	}

	/**
	 * Assign lead to user.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @param int $user_id User ID.
	 * @return bool True on success.
	 */
	public function assign( $lead_id, $user_id ) {
		$result = $this->update( $lead_id, array( 'assigned_to' => $user_id ) );

		if ( $result ) {
			do_action( 'wp_ai_chatbot_lead_assigned', $lead_id, $user_id );
		}

		return $result;
	}

	/**
	 * Get leads with filters.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Leads and count.
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'      => '',
			'source'      => '',
			'assigned_to' => null,
			'search'      => '',
			'score_min'   => null,
			'score_max'   => null,
			'date_from'   => '',
			'date_to'     => '',
			'orderby'     => 'created_at',
			'order'       => 'DESC',
			'limit'       => 20,
			'offset'      => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$values = array();

		// Status filter
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$values[] = $args['status'];
		}

		// Source filter
		if ( ! empty( $args['source'] ) ) {
			$where[] = 'source = %s';
			$values[] = $args['source'];
		}

		// Assigned to filter
		if ( $args['assigned_to'] !== null ) {
			$where[] = 'assigned_to = %d';
			$values[] = $args['assigned_to'];
		}

		// Search filter
		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(name LIKE %s OR email LIKE %s OR company LIKE %s OR phone LIKE %s)';
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
			$values[] = $search;
		}

		// Score filters
		if ( $args['score_min'] !== null ) {
			$where[] = 'score >= %d';
			$values[] = $args['score_min'];
		}
		if ( $args['score_max'] !== null ) {
			$where[] = 'score <= %d';
			$values[] = $args['score_max'];
		}

		// Date filters
		if ( ! empty( $args['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$values[] = $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$values[] = $args['date_to'] . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// Validate orderby
		$allowed_orderby = array( 'id', 'name', 'email', 'score', 'status', 'created_at', 'updated_at' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Get total count
		$count_query = "SELECT COUNT(*) FROM {$this->table_name} WHERE $where_clause";
		if ( ! empty( $values ) ) {
			$count_query = $wpdb->prepare( $count_query, $values );
		}
		$total = $wpdb->get_var( $count_query );

		// Get leads
		$query = "SELECT * FROM {$this->table_name} WHERE $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d";
		$values[] = $args['limit'];
		$values[] = $args['offset'];
		
		$leads = $wpdb->get_results(
			$wpdb->prepare( $query, $values ),
			ARRAY_A
		);

		// Decode JSON fields
		foreach ( $leads as &$lead ) {
			if ( ! empty( $lead['custom_fields'] ) ) {
				$lead['custom_fields'] = json_decode( $lead['custom_fields'], true );
			}
			if ( ! empty( $lead['score_breakdown'] ) ) {
				$lead['score_breakdown'] = json_decode( $lead['score_breakdown'], true );
			}
		}

		return array(
			'leads' => $leads ?: array(),
			'total' => intval( $total ),
		);
	}

	/**
	 * Delete a lead.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return bool True on success.
	 */
	public function delete( $lead_id ) {
		global $wpdb;

		// Delete meta first
		$wpdb->delete( $this->meta_table_name, array( 'lead_id' => $lead_id ) );

		// Delete lead
		$result = $wpdb->delete( $this->table_name, array( 'id' => $lead_id ) );

		if ( $result ) {
			do_action( 'wp_ai_chatbot_lead_deleted', $lead_id );
		}

		return (bool) $result;
	}

	/**
	 * Store lead meta.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id    Lead ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool True on success.
	 */
	public function add_meta( $lead_id, $meta_key, $meta_value ) {
		global $wpdb;

		if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
			$meta_value = wp_json_encode( $meta_value );
		}

		return (bool) $wpdb->insert(
			$this->meta_table_name,
			array(
				'lead_id'    => $lead_id,
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get lead meta.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id  Lead ID.
	 * @param string $meta_key Meta key (optional).
	 * @param bool   $single   Return single value.
	 * @return mixed Meta value(s).
	 */
	public function get_meta( $lead_id, $meta_key = '', $single = false ) {
		global $wpdb;

		if ( ! empty( $meta_key ) ) {
			if ( $single ) {
				return $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$this->meta_table_name} WHERE lead_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
						$lead_id,
						$meta_key
					)
				);
			}

			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$this->meta_table_name} WHERE lead_id = %d AND meta_key = %s",
					$lead_id,
					$meta_key
				)
			);
		}

		// Get all meta
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$this->meta_table_name} WHERE lead_id = %d",
				$lead_id
			),
			ARRAY_A
		);

		$meta = array();
		foreach ( $results as $row ) {
			$meta[ $row['meta_key'] ][] = $row['meta_value'];
		}

		return $meta;
	}

	/**
	 * Update lead meta.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id    Lead ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool True on success.
	 */
	public function update_meta( $lead_id, $meta_key, $meta_value ) {
		global $wpdb;

		if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
			$meta_value = wp_json_encode( $meta_value );
		}

		// Check if exists
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$this->meta_table_name} WHERE lead_id = %d AND meta_key = %s LIMIT 1",
				$lead_id,
				$meta_key
			)
		);

		if ( $exists ) {
			return (bool) $wpdb->update(
				$this->meta_table_name,
				array( 'meta_value' => $meta_value ),
				array( 'lead_id' => $lead_id, 'meta_key' => $meta_key )
			);
		}

		return $this->add_meta( $lead_id, $meta_key, $meta_value );
	}

	/**
	 * Delete lead meta.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id  Lead ID.
	 * @param string $meta_key Meta key.
	 * @return bool True on success.
	 */
	public function delete_meta( $lead_id, $meta_key ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			$this->meta_table_name,
			array( 'lead_id' => $lead_id, 'meta_key' => $meta_key )
		);
	}

	/**
	 * Check for duplicate lead.
	 *
	 * @since 1.0.0
	 * @param string $email Email to check.
	 * @param int    $hours Hours to look back (0 = all time).
	 * @return array|null Existing lead or null.
	 */
	public function find_duplicate( $email, $hours = 24 ) {
		global $wpdb;

		if ( $hours > 0 ) {
			$date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$hours} hours" ) );
			
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE email = %s AND created_at >= %s ORDER BY created_at DESC LIMIT 1",
					$email,
					$date_threshold
				),
				ARRAY_A
			);
		}

		return $this->get_by_email( $email );
	}

	/**
	 * Get lead statistics.
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

		// Total leads
		$total = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// By status
		$by_status = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) as count FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s GROUP BY status",
			$date_from,
			$date_to
		), ARRAY_A );

		$status_counts = array();
		foreach ( $by_status as $row ) {
			$status_counts[ $row['status'] ] = intval( $row['count'] );
		}

		// By source
		$by_source = $wpdb->get_results( $wpdb->prepare(
			"SELECT source, COUNT(*) as count FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s GROUP BY source",
			$date_from,
			$date_to
		), ARRAY_A );

		$source_counts = array();
		foreach ( $by_source as $row ) {
			$source_counts[ $row['source'] ] = intval( $row['count'] );
		}

		// Average score
		$avg_score = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(score) FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s",
			$date_from,
			$date_to
		) );

		// Conversion rate
		$converted = $status_counts['converted'] ?? 0;
		$conversion_rate = $total > 0 ? round( ( $converted / $total ) * 100, 2 ) : 0;

		// Daily counts
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) as date, COUNT(*) as count FROM {$this->table_name} WHERE created_at BETWEEN %s AND %s GROUP BY DATE(created_at) ORDER BY date",
			$date_from,
			$date_to
		), ARRAY_A );

		return array(
			'total'           => intval( $total ),
			'by_status'       => $status_counts,
			'by_source'       => $source_counts,
			'average_score'   => round( floatval( $avg_score ), 1 ),
			'conversion_rate' => $conversion_rate,
			'daily'           => $daily,
		);
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
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '';
	}

	/**
	 * Get available statuses.
	 *
	 * @since 1.0.0
	 * @return array Statuses.
	 */
	public function get_statuses() {
		return self::STATUSES;
	}
}

