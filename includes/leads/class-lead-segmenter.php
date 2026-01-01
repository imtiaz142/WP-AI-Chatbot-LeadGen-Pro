<?php
/**
 * Lead Segmenter.
 *
 * Segments leads into pre-built and custom segments based on behavior,
 * scores, and custom rules.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Lead_Segmenter {

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
	 * Lead storage instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Storage
	 */
	private $storage;

	/**
	 * Pre-built segments.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $prebuilt_segments = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
			$this->storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
		}

		$this->init_prebuilt_segments();
		$this->init_hooks();
	}

	/**
	 * Initialize pre-built segments.
	 *
	 * @since 1.0.0
	 */
	private function init_prebuilt_segments() {
		$this->prebuilt_segments = array(
			'hot_leads' => array(
				'id'          => 'hot_leads',
				'name'        => __( 'Hot Leads', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'High-scoring leads ready for immediate follow-up', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-fire',
				'color'       => '#d63638',
				'rules'       => array(
					array(
						'field'    => 'score',
						'operator' => '>=',
						'value'    => 80,
					),
					array(
						'field'    => 'grade',
						'operator' => 'in',
						'value'    => array( 'A+', 'A' ),
					),
				),
				'logic'       => 'OR',
				'priority'    => 1,
			),

			'pricing_focused' => array(
				'id'          => 'pricing_focused',
				'name'        => __( 'Pricing Focused', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Leads who have shown interest in pricing information', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-money-alt',
				'color'       => '#00a32a',
				'rules'       => array(
					array(
						'field'    => 'intent_signals',
						'operator' => 'contains',
						'value'    => 'pricing_inquiry',
					),
					array(
						'field'    => 'pages_visited',
						'operator' => 'contains_pattern',
						'value'    => '/pricing|plans|subscription|cost/i',
					),
					array(
						'field'    => 'messages',
						'operator' => 'contains_pattern',
						'value'    => '/price|cost|how much|pricing|plans|subscription|fee/i',
					),
				),
				'logic'       => 'OR',
				'priority'    => 2,
			),

			'technical_evaluators' => array(
				'id'          => 'technical_evaluators',
				'name'        => __( 'Technical Evaluators', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Leads evaluating technical features and integrations', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-admin-tools',
				'color'       => '#2271b1',
				'rules'       => array(
					array(
						'field'    => 'pages_visited',
						'operator' => 'contains_pattern',
						'value'    => '/api|integration|documentation|developer|technical|specs/i',
					),
					array(
						'field'    => 'messages',
						'operator' => 'contains_pattern',
						'value'    => '/api|integration|webhook|sdk|technical|developer|code|implement/i',
					),
					array(
						'field'    => 'custom_fields.job_title',
						'operator' => 'contains_pattern',
						'value'    => '/developer|engineer|technical|architect|devops|cto|tech lead/i',
					),
				),
				'logic'       => 'OR',
				'priority'    => 3,
			),

			'ready_to_buy' => array(
				'id'          => 'ready_to_buy',
				'name'        => __( 'Ready to Buy', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Leads showing strong purchase intent', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-cart',
				'color'       => '#9a6700',
				'rules'       => array(
					array(
						'field'    => 'intent_signals',
						'operator' => 'contains',
						'value'    => 'purchase_intent',
					),
					array(
						'field'    => 'intent_signals',
						'operator' => 'contains',
						'value'    => 'meeting_request',
					),
					array(
						'field'    => 'messages',
						'operator' => 'contains_pattern',
						'value'    => '/buy|purchase|order|sign up|get started|trial|demo|meeting|call/i',
					),
					array(
						'field'    => 'scoring_breakdown.intent',
						'operator' => '>=',
						'value'    => 70,
					),
				),
				'logic'       => 'OR',
				'priority'    => 1,
			),

			'enterprise_prospects' => array(
				'id'          => 'enterprise_prospects',
				'name'        => __( 'Enterprise Prospects', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Large company prospects with enterprise needs', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-building',
				'color'       => '#135e96',
				'rules'       => array(
					array(
						'field'    => 'custom_fields.company_size',
						'operator' => 'in',
						'value'    => array( '201-500', '501-1000', '1000+', '1001-5000', '5001-10000', '10001+' ),
					),
					array(
						'field'    => 'email',
						'operator' => 'not_contains_pattern',
						'value'    => '/@(gmail|yahoo|hotmail|outlook|aol)\./i',
					),
					array(
						'field'    => 'custom_fields.company_type',
						'operator' => '=',
						'value'    => 'enterprise',
					),
				),
				'logic'       => 'AND',
				'priority'    => 2,
			),

			'smb_prospects' => array(
				'id'          => 'smb_prospects',
				'name'        => __( 'SMB Prospects', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Small and medium business prospects', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-store',
				'color'       => '#ba5d04',
				'rules'       => array(
					array(
						'field'    => 'custom_fields.company_size',
						'operator' => 'in',
						'value'    => array( '1-10', '11-50', '51-200' ),
					),
				),
				'logic'       => 'AND',
				'priority'    => 4,
			),

			'returning_visitors' => array(
				'id'          => 'returning_visitors',
				'name'        => __( 'Returning Visitors', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Leads who have returned multiple times', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-update',
				'color'       => '#8c8f94',
				'rules'       => array(
					array(
						'field'    => 'return_visits',
						'operator' => '>=',
						'value'    => 3,
					),
				),
				'logic'       => 'AND',
				'priority'    => 5,
			),

			'high_engagement' => array(
				'id'          => 'high_engagement',
				'name'        => __( 'High Engagement', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Leads with high engagement metrics', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-chart-line',
				'color'       => '#3858e9',
				'rules'       => array(
					array(
						'field'    => 'message_count',
						'operator' => '>=',
						'value'    => 10,
					),
					array(
						'field'    => 'conversation_count',
						'operator' => '>=',
						'value'    => 2,
					),
					array(
						'field'    => 'total_time',
						'operator' => '>=',
						'value'    => 300, // 5 minutes
					),
				),
				'logic'       => 'OR',
				'priority'    => 4,
			),

			'decision_makers' => array(
				'id'          => 'decision_makers',
				'name'        => __( 'Decision Makers', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Leads in decision-making roles', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-businessman',
				'color'       => '#674399',
				'rules'       => array(
					array(
						'field'    => 'custom_fields.job_title',
						'operator' => 'contains_pattern',
						'value'    => '/ceo|cto|cfo|coo|cmo|founder|owner|president|vp|vice president|director|head of|manager|chief/i',
					),
					array(
						'field'    => 'custom_fields.is_decision_maker',
						'operator' => '=',
						'value'    => true,
					),
				),
				'logic'       => 'OR',
				'priority'    => 2,
			),

			'at_risk' => array(
				'id'          => 'at_risk',
				'name'        => __( 'At Risk', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Previously engaged leads showing declining interest', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-warning',
				'color'       => '#dba617',
				'rules'       => array(
					array(
						'field'    => 'last_activity',
						'operator' => 'days_ago',
						'value'    => 14,
					),
					array(
						'field'    => 'score',
						'operator' => '>=',
						'value'    => 40,
					),
					array(
						'field'    => 'status',
						'operator' => '!=',
						'value'    => 'converted',
					),
				),
				'logic'       => 'AND',
				'priority'    => 3,
			),

			'new_leads' => array(
				'id'          => 'new_leads',
				'name'        => __( 'New Leads', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Leads captured in the last 24 hours', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-star-filled',
				'color'       => '#4ab866',
				'rules'       => array(
					array(
						'field'    => 'created_at',
						'operator' => 'within_hours',
						'value'    => 24,
					),
				),
				'logic'       => 'AND',
				'priority'    => 1,
			),

			'competitors_evaluating' => array(
				'id'          => 'competitors_evaluating',
				'name'        => __( 'Evaluating Competitors', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Leads comparing with competitors', 'wp-ai-chatbot-leadgen-pro' ),
				'icon'        => 'dashicons-randomize',
				'color'       => '#e65054',
				'rules'       => array(
					array(
						'field'    => 'messages',
						'operator' => 'contains_pattern',
						'value'    => '/competitor|alternative|comparison|vs|versus|compare|better than|switch from/i',
					),
					array(
						'field'    => 'intent_signals',
						'operator' => 'contains',
						'value'    => 'competitor_comparison',
					),
				),
				'logic'       => 'OR',
				'priority'    => 2,
			),
		);

		// Allow filtering of pre-built segments
		$this->prebuilt_segments = apply_filters( 'wp_ai_chatbot_prebuilt_segments', $this->prebuilt_segments );
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Update segment membership on lead changes
		add_action( 'wp_ai_chatbot_lead_updated', array( $this, 'update_lead_segments' ), 10, 2 );
		add_action( 'wp_ai_chatbot_lead_scored', array( $this, 'update_lead_segments' ), 10, 2 );

		// AJAX handlers
		add_action( 'wp_ajax_wp_ai_chatbot_get_segment_leads', array( $this, 'ajax_get_segment_leads' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_get_segment_counts', array( $this, 'ajax_get_segment_counts' ) );
	}

	/**
	 * Get all pre-built segments.
	 *
	 * @since 1.0.0
	 * @return array Segments.
	 */
	public function get_prebuilt_segments() {
		return $this->prebuilt_segments;
	}

	/**
	 * Get a specific segment by ID.
	 *
	 * @since 1.0.0
	 * @param string $segment_id Segment ID.
	 * @return array|null Segment or null.
	 */
	public function get_segment( $segment_id ) {
		// Check pre-built segments
		if ( isset( $this->prebuilt_segments[ $segment_id ] ) ) {
			return $this->prebuilt_segments[ $segment_id ];
		}

		// Check custom segments
		$custom_segments = get_option( 'wp_ai_chatbot_custom_segments', array() );
		if ( isset( $custom_segments[ $segment_id ] ) ) {
			return $custom_segments[ $segment_id ];
		}

		return null;
	}

	/**
	 * Get leads in a segment.
	 *
	 * @since 1.0.0
	 * @param string $segment_id Segment ID.
	 * @param array  $args       Query args.
	 * @return array Leads and total count.
	 */
	public function get_segment_leads( $segment_id, $args = array() ) {
		$segment = $this->get_segment( $segment_id );

		if ( ! $segment ) {
			return array( 'leads' => array(), 'total' => 0 );
		}

		$defaults = array(
			'limit'  => 20,
			'offset' => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		// Get all leads and filter
		$all_leads = $this->storage->get_all( array(
			'limit' => 1000, // Get a large batch
		) );

		$matching_leads = array();

		foreach ( $all_leads['leads'] as $lead ) {
			if ( $this->lead_matches_segment( $lead, $segment ) ) {
				$matching_leads[] = $lead;
			}
		}

		// Sort by score descending
		usort( $matching_leads, function( $a, $b ) {
			return ( $b['score'] ?? 0 ) - ( $a['score'] ?? 0 );
		} );

		// Apply pagination
		$total = count( $matching_leads );
		$leads = array_slice( $matching_leads, $args['offset'], $args['limit'] );

		return array(
			'leads' => $leads,
			'total' => $total,
		);
	}

	/**
	 * Check if a lead matches a segment.
	 *
	 * @since 1.0.0
	 * @param array $lead    Lead data.
	 * @param array $segment Segment definition.
	 * @return bool True if matches.
	 */
	public function lead_matches_segment( $lead, $segment ) {
		$rules = $segment['rules'] ?? array();
		$logic = $segment['logic'] ?? 'AND';

		if ( empty( $rules ) ) {
			return false;
		}

		$results = array();

		foreach ( $rules as $rule ) {
			$results[] = $this->evaluate_rule( $lead, $rule );
		}

		if ( $logic === 'AND' ) {
			return ! in_array( false, $results, true );
		} else { // OR
			return in_array( true, $results, true );
		}
	}

	/**
	 * Evaluate a single rule against a lead.
	 *
	 * @since 1.0.0
	 * @param array $lead Lead data.
	 * @param array $rule Rule definition.
	 * @return bool True if rule matches.
	 */
	private function evaluate_rule( $lead, $rule ) {
		$field = $rule['field'];
		$operator = $rule['operator'];
		$value = $rule['value'];

		// Get the field value (supports dot notation for nested fields)
		$lead_value = $this->get_nested_value( $lead, $field );

		switch ( $operator ) {
			case '=':
			case 'equals':
				return $lead_value == $value;

			case '!=':
			case 'not_equals':
				return $lead_value != $value;

			case '>':
			case 'greater_than':
				return (float) $lead_value > (float) $value;

			case '>=':
			case 'greater_than_or_equals':
				return (float) $lead_value >= (float) $value;

			case '<':
			case 'less_than':
				return (float) $lead_value < (float) $value;

			case '<=':
			case 'less_than_or_equals':
				return (float) $lead_value <= (float) $value;

			case 'in':
				return is_array( $value ) && in_array( $lead_value, $value, true );

			case 'not_in':
				return is_array( $value ) && ! in_array( $lead_value, $value, true );

			case 'contains':
				if ( is_array( $lead_value ) ) {
					return in_array( $value, $lead_value, true );
				}
				return strpos( (string) $lead_value, (string) $value ) !== false;

			case 'not_contains':
				if ( is_array( $lead_value ) ) {
					return ! in_array( $value, $lead_value, true );
				}
				return strpos( (string) $lead_value, (string) $value ) === false;

			case 'contains_pattern':
				if ( is_array( $lead_value ) ) {
					$lead_value = implode( ' ', $lead_value );
				}
				return (bool) preg_match( $value, (string) $lead_value );

			case 'not_contains_pattern':
				if ( is_array( $lead_value ) ) {
					$lead_value = implode( ' ', $lead_value );
				}
				return ! preg_match( $value, (string) $lead_value );

			case 'is_empty':
				return empty( $lead_value );

			case 'is_not_empty':
				return ! empty( $lead_value );

			case 'starts_with':
				return strpos( (string) $lead_value, (string) $value ) === 0;

			case 'ends_with':
				$len = strlen( $value );
				return substr( (string) $lead_value, -$len ) === (string) $value;

			case 'days_ago':
				if ( empty( $lead_value ) ) {
					return false;
				}
				$timestamp = strtotime( $lead_value );
				$days = ( time() - $timestamp ) / DAY_IN_SECONDS;
				return $days >= (int) $value;

			case 'within_days':
				if ( empty( $lead_value ) ) {
					return false;
				}
				$timestamp = strtotime( $lead_value );
				$days = ( time() - $timestamp ) / DAY_IN_SECONDS;
				return $days <= (int) $value;

			case 'within_hours':
				if ( empty( $lead_value ) ) {
					return false;
				}
				$timestamp = strtotime( $lead_value );
				$hours = ( time() - $timestamp ) / HOUR_IN_SECONDS;
				return $hours <= (int) $value;

			default:
				return false;
		}
	}

	/**
	 * Get nested value from array using dot notation.
	 *
	 * @since 1.0.0
	 * @param array  $data Array to search.
	 * @param string $path Dot-notation path.
	 * @return mixed Value or null.
	 */
	private function get_nested_value( $data, $path ) {
		$keys = explode( '.', $path );
		$value = $data;

		foreach ( $keys as $key ) {
			if ( is_array( $value ) && isset( $value[ $key ] ) ) {
				$value = $value[ $key ];
			} else {
				return null;
			}
		}

		return $value;
	}

	/**
	 * Get segments a lead belongs to.
	 *
	 * @since 1.0.0
	 * @param int|array $lead Lead ID or data.
	 * @return array Segment IDs.
	 */
	public function get_lead_segments( $lead ) {
		if ( is_numeric( $lead ) ) {
			$lead = $this->storage->get( $lead );
		}

		if ( ! $lead ) {
			return array();
		}

		$segments = array();

		// Check pre-built segments
		foreach ( $this->prebuilt_segments as $segment_id => $segment ) {
			if ( $this->lead_matches_segment( $lead, $segment ) ) {
				$segments[] = $segment_id;
			}
		}

		// Check custom segments
		$custom_segments = get_option( 'wp_ai_chatbot_custom_segments', array() );
		foreach ( $custom_segments as $segment_id => $segment ) {
			if ( $this->lead_matches_segment( $lead, $segment ) ) {
				$segments[] = $segment_id;
			}
		}

		return $segments;
	}

	/**
	 * Update lead segment membership.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id   Lead ID.
	 * @param array $lead_data Lead data.
	 */
	public function update_lead_segments( $lead_id, $lead_data = null ) {
		if ( ! $lead_data && $this->storage ) {
			$lead_data = $this->storage->get( $lead_id );
		}

		if ( ! $lead_data ) {
			return;
		}

		$segments = $this->get_lead_segments( $lead_data );

		// Store segments in custom fields
		$custom_fields = $lead_data['custom_fields'] ?? array();
		$old_segments = $custom_fields['segments'] ?? array();

		if ( $segments !== $old_segments ) {
			$custom_fields['segments'] = $segments;
			$custom_fields['segments_updated'] = current_time( 'mysql' );

			$this->storage->update( $lead_id, array(
				'custom_fields' => $custom_fields,
			) );

			// Fire action for segment changes
			$added = array_diff( $segments, $old_segments );
			$removed = array_diff( $old_segments, $segments );

			if ( ! empty( $added ) ) {
				do_action( 'wp_ai_chatbot_lead_entered_segments', $lead_id, $added, $lead_data );
			}

			if ( ! empty( $removed ) ) {
				do_action( 'wp_ai_chatbot_lead_left_segments', $lead_id, $removed, $lead_data );
			}
		}
	}

	/**
	 * Get segment counts.
	 *
	 * @since 1.0.0
	 * @return array Counts by segment.
	 */
	public function get_segment_counts() {
		$counts = array();

		// Get all segments
		$all_segments = array_merge(
			$this->prebuilt_segments,
			get_option( 'wp_ai_chatbot_custom_segments', array() )
		);

		foreach ( $all_segments as $segment_id => $segment ) {
			$result = $this->get_segment_leads( $segment_id, array( 'limit' => 0 ) );
			$counts[ $segment_id ] = $result['total'];
		}

		return $counts;
	}

	/**
	 * AJAX handler for getting segment leads.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_segment_leads() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$segment_id = sanitize_text_field( $_POST['segment_id'] ?? '' );
		$page = max( 1, intval( $_POST['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, intval( $_POST['per_page'] ?? 20 ) ) );

		if ( ! $segment_id ) {
			wp_send_json_error( array( 'message' => 'Segment ID required' ), 400 );
		}

		$result = $this->get_segment_leads( $segment_id, array(
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		) );

		wp_send_json_success( array(
			'leads'       => $result['leads'],
			'total'       => $result['total'],
			'pages'       => ceil( $result['total'] / $per_page ),
			'current_page' => $page,
		) );
	}

	/**
	 * AJAX handler for getting segment counts.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_segment_counts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$counts = $this->get_segment_counts();
		$segments = array();

		foreach ( $this->prebuilt_segments as $id => $segment ) {
			$segments[] = array(
				'id'          => $id,
				'name'        => $segment['name'],
				'description' => $segment['description'],
				'icon'        => $segment['icon'],
				'color'       => $segment['color'],
				'count'       => $counts[ $id ] ?? 0,
				'type'        => 'prebuilt',
			);
		}

		$custom_segments = get_option( 'wp_ai_chatbot_custom_segments', array() );
		foreach ( $custom_segments as $id => $segment ) {
			$segments[] = array(
				'id'          => $id,
				'name'        => $segment['name'],
				'description' => $segment['description'] ?? '',
				'icon'        => $segment['icon'] ?? 'dashicons-tag',
				'color'       => $segment['color'] ?? '#8c8f94',
				'count'       => $counts[ $id ] ?? 0,
				'type'        => 'custom',
			);
		}

		// Sort by count (highest first), then by priority
		usort( $segments, function( $a, $b ) {
			return $b['count'] - $a['count'];
		} );

		wp_send_json_success( $segments );
	}
}






