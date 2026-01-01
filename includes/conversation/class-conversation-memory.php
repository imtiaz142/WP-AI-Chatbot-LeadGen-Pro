<?php
/**
 * Conversation Memory.
 *
 * Remembers previous interactions and user preferences across sessions.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory {

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
	 * Memory types.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const MEMORY_TYPES = array(
		'preference'   => 'preference',
		'fact'         => 'fact',
		'interest'     => 'interest',
		'interaction'  => 'interaction',
		'context'      => 'context',
		'feedback'     => 'feedback',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		$this->maybe_create_memory_table();
	}

	/**
	 * Create memory table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_memory_table() {
		global $wpdb;

		$table_name = $this->get_memory_table();
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			$sql = "CREATE TABLE {$table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id varchar(64) NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				memory_type varchar(30) NOT NULL,
				memory_key varchar(100) NOT NULL,
				memory_value longtext NOT NULL,
				confidence float DEFAULT 1.0,
				source varchar(50) DEFAULT 'inferred',
				expires_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY session_id (session_id),
				KEY user_id (user_id),
				KEY memory_type (memory_type),
				KEY memory_key (memory_key),
				KEY expires_at (expires_at),
				UNIQUE KEY unique_memory (session_id, memory_type, memory_key)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	/**
	 * Get memory table name.
	 *
	 * @since 1.0.0
	 * @return string Table name.
	 */
	public function get_memory_table() {
		global $wpdb;
		return $wpdb->prefix . 'wp_ai_chatbot_memory';
	}

	/**
	 * Store a memory.
	 *
	 * @since 1.0.0
	 * @param string $session_id   Session ID.
	 * @param string $type         Memory type.
	 * @param string $key          Memory key.
	 * @param mixed  $value        Memory value.
	 * @param array  $args         Optional. Additional arguments.
	 * @return int|WP_Error Memory ID or error.
	 */
	public function store( $session_id, $type, $key, $value, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'user_id'    => get_current_user_id(),
			'confidence' => 1.0,
			'source'     => 'explicit',
			'expires_at' => null,
		);
		$args = wp_parse_args( $args, $defaults );

		// Validate type
		if ( ! isset( self::MEMORY_TYPES[ $type ] ) ) {
			return new WP_Error(
				'invalid_type',
				__( 'Invalid memory type.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Serialize value if array/object
		$value_to_store = is_array( $value ) || is_object( $value ) ? maybe_serialize( $value ) : $value;

		// Check if memory exists
		$existing = $this->get( $session_id, $type, $key );

		if ( $existing ) {
			// Update existing memory
			$result = $wpdb->update(
				$this->get_memory_table(),
				array(
					'memory_value' => $value_to_store,
					'confidence'   => $args['confidence'],
					'source'       => $args['source'],
					'expires_at'   => $args['expires_at'],
					'updated_at'   => current_time( 'mysql' ),
				),
				array( 'id' => $existing['id'] ),
				array( '%s', '%f', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				return new WP_Error(
					'update_failed',
					__( 'Failed to update memory.', 'wp-ai-chatbot-leadgen-pro' )
				);
			}

			return $existing['id'];
		}

		// Insert new memory
		$result = $wpdb->insert(
			$this->get_memory_table(),
			array(
				'session_id'   => $session_id,
				'user_id'      => $args['user_id'] ?: null,
				'memory_type'  => $type,
				'memory_key'   => $key,
				'memory_value' => $value_to_store,
				'confidence'   => $args['confidence'],
				'source'       => $args['source'],
				'expires_at'   => $args['expires_at'],
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'insert_failed',
				__( 'Failed to store memory.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return $wpdb->insert_id;
	}

	/**
	 * Get a specific memory.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $type       Memory type.
	 * @param string $key        Memory key.
	 * @return array|null Memory data or null.
	 */
	public function get( $session_id, $type, $key ) {
		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_memory_table()} 
				 WHERE session_id = %s AND memory_type = %s AND memory_key = %s 
				 AND (expires_at IS NULL OR expires_at > %s)",
				$session_id,
				$type,
				$key,
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		if ( $result ) {
			$result['memory_value'] = maybe_unserialize( $result['memory_value'] );
		}

		return $result;
	}

	/**
	 * Get all memories for a session.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $args       Optional. Query arguments.
	 * @return array Memories.
	 */
	public function get_all( $session_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'type'           => '',
			'min_confidence' => 0,
			'include_expired' => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array( 'session_id = %s' );
		$values = array( $session_id );

		if ( ! empty( $args['type'] ) ) {
			$where[] = 'memory_type = %s';
			$values[] = $args['type'];
		}

		if ( $args['min_confidence'] > 0 ) {
			$where[] = 'confidence >= %f';
			$values[] = $args['min_confidence'];
		}

		if ( ! $args['include_expired'] ) {
			$where[] = '(expires_at IS NULL OR expires_at > %s)';
			$values[] = current_time( 'mysql' );
		}

		$where_sql = implode( ' AND ', $where );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_memory_table()} 
				 WHERE {$where_sql} 
				 ORDER BY updated_at DESC",
				$values
			),
			ARRAY_A
		);

		foreach ( $results as &$result ) {
			$result['memory_value'] = maybe_unserialize( $result['memory_value'] );
		}

		return $results;
	}

	/**
	 * Get memories by type.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $type       Memory type.
	 * @return array Memories.
	 */
	public function get_by_type( $session_id, $type ) {
		return $this->get_all( $session_id, array( 'type' => $type ) );
	}

	/**
	 * Delete a memory.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $type       Memory type.
	 * @param string $key        Memory key.
	 * @return bool True on success.
	 */
	public function delete( $session_id, $type, $key ) {
		global $wpdb;

		$result = $wpdb->delete(
			$this->get_memory_table(),
			array(
				'session_id'  => $session_id,
				'memory_type' => $type,
				'memory_key'  => $key,
			),
			array( '%s', '%s', '%s' )
		);

		return $result !== false;
	}

	/**
	 * Clear all memories for a session.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $type       Optional. Memory type to clear.
	 * @return bool True on success.
	 */
	public function clear( $session_id, $type = '' ) {
		global $wpdb;

		$where = array( 'session_id' => $session_id );
		$format = array( '%s' );

		if ( ! empty( $type ) ) {
			$where['memory_type'] = $type;
			$format[] = '%s';
		}

		$result = $wpdb->delete(
			$this->get_memory_table(),
			$where,
			$format
		);

		return $result !== false;
	}

	/**
	 * Store user preference.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $key        Preference key.
	 * @param mixed  $value      Preference value.
	 * @return int|WP_Error Memory ID or error.
	 */
	public function store_preference( $session_id, $key, $value ) {
		return $this->store( $session_id, 'preference', $key, $value, array(
			'source' => 'explicit',
		) );
	}

	/**
	 * Get user preference.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $key        Preference key.
	 * @param mixed  $default    Default value.
	 * @return mixed Preference value or default.
	 */
	public function get_preference( $session_id, $key, $default = null ) {
		$memory = $this->get( $session_id, 'preference', $key );
		return $memory ? $memory['memory_value'] : $default;
	}

	/**
	 * Store user fact (e.g., name, company, role).
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $key        Fact key.
	 * @param mixed  $value      Fact value.
	 * @param float  $confidence Confidence level (0-1).
	 * @param string $source     Source (explicit, inferred, extracted).
	 * @return int|WP_Error Memory ID or error.
	 */
	public function store_fact( $session_id, $key, $value, $confidence = 1.0, $source = 'explicit' ) {
		return $this->store( $session_id, 'fact', $key, $value, array(
			'confidence' => $confidence,
			'source'     => $source,
		) );
	}

	/**
	 * Get user fact.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $key        Fact key.
	 * @return array|null Fact data with confidence.
	 */
	public function get_fact( $session_id, $key ) {
		$memory = $this->get( $session_id, 'fact', $key );
		if ( ! $memory ) {
			return null;
		}
		return array(
			'value'      => $memory['memory_value'],
			'confidence' => $memory['confidence'],
			'source'     => $memory['source'],
		);
	}

	/**
	 * Store user interest.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $interest   Interest topic.
	 * @param float  $score      Interest score (0-1).
	 * @return int|WP_Error Memory ID or error.
	 */
	public function store_interest( $session_id, $interest, $score = 1.0 ) {
		return $this->store( $session_id, 'interest', $interest, $score, array(
			'confidence' => $score,
			'source'     => 'inferred',
		) );
	}

	/**
	 * Get user interests.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @return array Interests with scores.
	 */
	public function get_interests( $session_id ) {
		$memories = $this->get_by_type( $session_id, 'interest' );
		$interests = array();

		foreach ( $memories as $memory ) {
			$interests[ $memory['memory_key'] ] = floatval( $memory['memory_value'] );
		}

		arsort( $interests );
		return $interests;
	}

	/**
	 * Store interaction summary.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $key        Interaction key.
	 * @param array  $data       Interaction data.
	 * @return int|WP_Error Memory ID or error.
	 */
	public function store_interaction( $session_id, $key, $data ) {
		return $this->store( $session_id, 'interaction', $key, $data, array(
			'source' => 'system',
		) );
	}

	/**
	 * Store context for current conversation.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $key        Context key.
	 * @param mixed  $value      Context value.
	 * @param int    $ttl        Time to live in seconds.
	 * @return int|WP_Error Memory ID or error.
	 */
	public function store_context( $session_id, $key, $value, $ttl = 3600 ) {
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		return $this->store( $session_id, 'context', $key, $value, array(
			'source'     => 'system',
			'expires_at' => $expires_at,
		) );
	}

	/**
	 * Get context.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $key        Context key.
	 * @param mixed  $default    Default value.
	 * @return mixed Context value or default.
	 */
	public function get_context( $session_id, $key, $default = null ) {
		$memory = $this->get( $session_id, 'context', $key );
		return $memory ? $memory['memory_value'] : $default;
	}

	/**
	 * Extract facts from message using patterns.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $message    User message.
	 * @return array Extracted facts.
	 */
	public function extract_facts_from_message( $session_id, $message ) {
		$extracted = array();

		// Name patterns
		$name_patterns = array(
			'/my name is ([A-Z][a-z]+ ?[A-Z]?[a-z]*)/i',
			'/i\'m ([A-Z][a-z]+ ?[A-Z]?[a-z]*)/i',
			'/call me ([A-Z][a-z]+)/i',
			'/this is ([A-Z][a-z]+ ?[A-Z]?[a-z]*)/i',
		);

		foreach ( $name_patterns as $pattern ) {
			if ( preg_match( $pattern, $message, $matches ) ) {
				$name = trim( $matches[1] );
				// Exclude common words that might be mistakenly captured
				$excluded = array( 'Looking', 'Interested', 'Having', 'Using', 'Working' );
				if ( ! in_array( $name, $excluded, true ) && strlen( $name ) > 1 ) {
					$this->store_fact( $session_id, 'name', $name, 0.9, 'extracted' );
					$extracted['name'] = $name;
					break;
				}
			}
		}

		// Email patterns
		if ( preg_match( '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $message, $matches ) ) {
			$email = $matches[1];
			$this->store_fact( $session_id, 'email', $email, 1.0, 'extracted' );
			$extracted['email'] = $email;
		}

		// Phone patterns
		$phone_patterns = array(
			'/(\+?1?[-.\s]?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4})/',
			'/(\d{10,11})/',
		);

		foreach ( $phone_patterns as $pattern ) {
			if ( preg_match( $pattern, $message, $matches ) ) {
				$phone = preg_replace( '/[^\d+]/', '', $matches[1] );
				if ( strlen( $phone ) >= 10 ) {
					$this->store_fact( $session_id, 'phone', $phone, 0.9, 'extracted' );
					$extracted['phone'] = $phone;
					break;
				}
			}
		}

		// Company patterns
		$company_patterns = array(
			'/i work at ([A-Za-z0-9\s&.,-]+?)(?:\.|,|$)/i',
			'/i\'m (?:from|with) ([A-Za-z0-9\s&.,-]+?)(?:\.|,|$)/i',
			'/(?:my|our) company (?:is|called) ([A-Za-z0-9\s&.,-]+?)(?:\.|,|$)/i',
		);

		foreach ( $company_patterns as $pattern ) {
			if ( preg_match( $pattern, $message, $matches ) ) {
				$company = trim( $matches[1] );
				if ( strlen( $company ) > 1 && strlen( $company ) < 100 ) {
					$this->store_fact( $session_id, 'company', $company, 0.8, 'extracted' );
					$extracted['company'] = $company;
					break;
				}
			}
		}

		// Role/title patterns
		$role_patterns = array(
			'/i\'m (?:a|an|the) ([A-Za-z\s]+?)(?:\.|,|$| at)/i',
			'/my (?:role|title|position) is ([A-Za-z\s]+?)(?:\.|,|$)/i',
			'/i work as (?:a|an|the) ([A-Za-z\s]+?)(?:\.|,|$)/i',
		);

		foreach ( $role_patterns as $pattern ) {
			if ( preg_match( $pattern, $message, $matches ) ) {
				$role = trim( $matches[1] );
				// Exclude non-role phrases
				$excluded_roles = array( 'looking', 'interested', 'having', 'trying', 'wondering' );
				$role_lower = strtolower( $role );
				if ( ! in_array( $role_lower, $excluded_roles, true ) && strlen( $role ) > 2 && strlen( $role ) < 50 ) {
					$this->store_fact( $session_id, 'role', $role, 0.7, 'extracted' );
					$extracted['role'] = $role;
					break;
				}
			}
		}

		// Location patterns
		$location_patterns = array(
			'/i\'m (?:based|located) in ([A-Za-z\s,]+?)(?:\.|$)/i',
			'/i\'m from ([A-Za-z\s,]+?)(?:\.|$)/i',
			'/we\'re (?:based|located) in ([A-Za-z\s,]+?)(?:\.|$)/i',
		);

		foreach ( $location_patterns as $pattern ) {
			if ( preg_match( $pattern, $message, $matches ) ) {
				$location = trim( $matches[1] );
				if ( strlen( $location ) > 1 && strlen( $location ) < 100 ) {
					$this->store_fact( $session_id, 'location', $location, 0.7, 'extracted' );
					$extracted['location'] = $location;
					break;
				}
			}
		}

		return $extracted;
	}

	/**
	 * Infer interests from message.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param string $message    User message.
	 * @param array  $context    Optional. Additional context.
	 * @return array Inferred interests.
	 */
	public function infer_interests_from_message( $session_id, $message, $context = array() ) {
		$interests = array();
		$message_lower = strtolower( $message );

		// Topic keywords mapping
		$topic_keywords = array(
			'pricing'       => array( 'price', 'cost', 'pricing', 'how much', 'quote', 'budget' ),
			'features'      => array( 'feature', 'functionality', 'capability', 'can it', 'does it' ),
			'integration'   => array( 'integrate', 'integration', 'connect', 'api', 'webhook' ),
			'support'       => array( 'support', 'help', 'issue', 'problem', 'troubleshoot' ),
			'demo'          => array( 'demo', 'demonstration', 'trial', 'test', 'try' ),
			'enterprise'    => array( 'enterprise', 'large scale', 'team', 'organization' ),
			'security'      => array( 'security', 'secure', 'privacy', 'gdpr', 'compliance' ),
			'performance'   => array( 'performance', 'speed', 'fast', 'scalable', 'scale' ),
			'customization' => array( 'customize', 'custom', 'tailor', 'configure' ),
		);

		foreach ( $topic_keywords as $topic => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( strpos( $message_lower, $keyword ) !== false ) {
					$current = $this->get( $session_id, 'interest', $topic );
					$current_score = $current ? floatval( $current['memory_value'] ) : 0;
					$new_score = min( 1.0, $current_score + 0.2 );

					$this->store_interest( $session_id, $topic, $new_score );
					$interests[ $topic ] = $new_score;
					break;
				}
			}
		}

		// Add intent-based interests
		if ( ! empty( $context['intent'] ) ) {
			$intent_to_interest = array(
				'pricing'            => 'pricing',
				'meeting_request'    => 'demo',
				'technical_question' => 'features',
				'feature_comparison' => 'features',
				'complaint'          => 'support',
			);

			if ( isset( $intent_to_interest[ $context['intent'] ] ) ) {
				$topic = $intent_to_interest[ $context['intent'] ];
				$current = $this->get( $session_id, 'interest', $topic );
				$current_score = $current ? floatval( $current['memory_value'] ) : 0;
				$new_score = min( 1.0, $current_score + 0.3 );

				$this->store_interest( $session_id, $topic, $new_score );
				$interests[ $topic ] = $new_score;
			}
		}

		return $interests;
	}

	/**
	 * Get memory context for AI prompt.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @return string Memory context string.
	 */
	public function get_memory_context( $session_id ) {
		$context_parts = array();

		// Get user facts
		$facts = $this->get_by_type( $session_id, 'fact' );
		if ( ! empty( $facts ) ) {
			$fact_strings = array();
			foreach ( $facts as $fact ) {
				if ( $fact['confidence'] >= 0.5 ) {
					$fact_strings[] = sprintf( '%s: %s', ucfirst( $fact['memory_key'] ), $fact['memory_value'] );
				}
			}
			if ( ! empty( $fact_strings ) ) {
				$context_parts[] = __( 'Known about user:', 'wp-ai-chatbot-leadgen-pro' ) . ' ' . implode( ', ', $fact_strings );
			}
		}

		// Get preferences
		$preferences = $this->get_by_type( $session_id, 'preference' );
		if ( ! empty( $preferences ) ) {
			$pref_strings = array();
			foreach ( $preferences as $pref ) {
				$pref_strings[] = sprintf( '%s: %s', $pref['memory_key'], $pref['memory_value'] );
			}
			$context_parts[] = __( 'User preferences:', 'wp-ai-chatbot-leadgen-pro' ) . ' ' . implode( ', ', $pref_strings );
		}

		// Get interests
		$interests = $this->get_interests( $session_id );
		if ( ! empty( $interests ) ) {
			$top_interests = array_slice( array_keys( $interests ), 0, 5 );
			$context_parts[] = __( 'User interests:', 'wp-ai-chatbot-leadgen-pro' ) . ' ' . implode( ', ', $top_interests );
		}

		// Get temporary context
		$contexts = $this->get_by_type( $session_id, 'context' );
		if ( ! empty( $contexts ) ) {
			foreach ( $contexts as $ctx ) {
				$context_parts[] = sprintf( '%s: %s', ucfirst( $ctx['memory_key'] ), $ctx['memory_value'] );
			}
		}

		return implode( "\n", $context_parts );
	}

	/**
	 * Get personalized greeting data.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @return array Greeting data.
	 */
	public function get_greeting_data( $session_id ) {
		$data = array(
			'is_returning' => false,
			'name'         => null,
			'last_topic'   => null,
			'interests'    => array(),
		);

		// Check for previous interactions
		$interactions = $this->get_by_type( $session_id, 'interaction' );
		$data['is_returning'] = ! empty( $interactions );

		// Get name
		$name_fact = $this->get_fact( $session_id, 'name' );
		if ( $name_fact && $name_fact['confidence'] >= 0.7 ) {
			$data['name'] = $name_fact['value'];
		}

		// Get last topic
		$last_topic = $this->get_context( $session_id, 'last_topic' );
		if ( $last_topic ) {
			$data['last_topic'] = $last_topic;
		}

		// Get interests
		$data['interests'] = array_slice( array_keys( $this->get_interests( $session_id ) ), 0, 3 );

		return $data;
	}

	/**
	 * Merge session memory when user logs in.
	 *
	 * @since 1.0.0
	 * @param string $anonymous_session_id Anonymous session ID.
	 * @param int    $user_id              WordPress user ID.
	 * @return bool True on success.
	 */
	public function merge_sessions( $anonymous_session_id, $user_id ) {
		global $wpdb;

		// Get or create user session
		$user_session_id = 'user_' . $user_id;

		// Get anonymous memories
		$anonymous_memories = $this->get_all( $anonymous_session_id );

		foreach ( $anonymous_memories as $memory ) {
			// Check if user already has this memory
			$existing = $this->get( $user_session_id, $memory['memory_type'], $memory['memory_key'] );

			if ( ! $existing ) {
				// Transfer memory to user session
				$this->store(
					$user_session_id,
					$memory['memory_type'],
					$memory['memory_key'],
					$memory['memory_value'],
					array(
						'user_id'    => $user_id,
						'confidence' => $memory['confidence'],
						'source'     => $memory['source'],
						'expires_at' => $memory['expires_at'],
					)
				);
			} elseif ( $memory['updated_at'] > $existing['updated_at'] ) {
				// Update if anonymous memory is newer
				$this->store(
					$user_session_id,
					$memory['memory_type'],
					$memory['memory_key'],
					$memory['memory_value'],
					array(
						'user_id'    => $user_id,
						'confidence' => max( $memory['confidence'], $existing['confidence'] ),
						'source'     => $memory['source'],
						'expires_at' => $memory['expires_at'],
					)
				);
			}
		}

		// Update all anonymous memories with user_id
		$wpdb->update(
			$this->get_memory_table(),
			array( 'user_id' => $user_id ),
			array( 'session_id' => $anonymous_session_id ),
			array( '%d' ),
			array( '%s' )
		);

		return true;
	}

	/**
	 * Clean up expired memories.
	 *
	 * @since 1.0.0
	 * @return int Number of deleted memories.
	 */
	public function cleanup_expired() {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->get_memory_table()} 
				 WHERE expires_at IS NOT NULL AND expires_at < %s",
				current_time( 'mysql' )
			)
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Get memory statistics.
	 *
	 * @since 1.0.0
	 * @return array Statistics.
	 */
	public function get_statistics() {
		global $wpdb;
		$table = $this->get_memory_table();

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$by_type = $wpdb->get_results(
			"SELECT memory_type, COUNT(*) as count FROM {$table} GROUP BY memory_type",
			ARRAY_A
		);

		$unique_sessions = $wpdb->get_var(
			"SELECT COUNT(DISTINCT session_id) FROM {$table}"
		);

		return array(
			'total_memories'   => intval( $total ),
			'by_type'          => $by_type,
			'unique_sessions'  => intval( $unique_sessions ),
		);
	}
}

