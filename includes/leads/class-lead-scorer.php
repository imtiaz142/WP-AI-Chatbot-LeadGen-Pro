<?php
/**
 * Lead Scorer.
 *
 * Combines behavioral, intent, and qualification scores into a
 * unified composite lead score (0-100).
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Lead_Scorer {

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
	 * Behavioral scorer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Behavioral_Scorer
	 */
	private $behavioral_scorer;

	/**
	 * Intent scorer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Intent_Scorer
	 */
	private $intent_scorer;

	/**
	 * Qualification scorer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Qualification_Scorer
	 */
	private $qualification_scorer;

	/**
	 * Lead storage instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Storage
	 */
	private $lead_storage;

	/**
	 * Behavior tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker
	 */
	private $behavior_tracker;

	/**
	 * Default scoring weights.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const DEFAULT_WEIGHTS = array(
		'behavioral'    => 0.30, // 30% weight
		'intent'        => 0.40, // 40% weight
		'qualification' => 0.30, // 30% weight
	);

	/**
	 * Score modifiers for special cases.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const SCORE_MODIFIERS = array(
		// Positive modifiers
		'meeting_booked'      => 10,
		'trial_started'       => 8,
		'pricing_viewed_3x'   => 5,
		'return_visitor_3x'   => 5,
		'form_completed'      => 5,
		'decision_maker'      => 8,
		'enterprise_company'  => 6,
		'immediate_timeline'  => 7,
		
		// Negative modifiers
		'disposable_email'    => -30,
		'no_engagement'       => -15,
		'spam_behavior'       => -50,
		'competitor_detected' => -20,
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		$this->init_scorers();
		$this->init_hooks();
	}

	/**
	 * Initialize scorer instances.
	 *
	 * @since 1.0.0
	 */
	private function init_scorers() {
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Behavioral_Scorer' ) ) {
			$this->behavioral_scorer = new WP_AI_Chatbot_LeadGen_Pro_Behavioral_Scorer();
		}

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Intent_Scorer' ) ) {
			$this->intent_scorer = new WP_AI_Chatbot_LeadGen_Pro_Intent_Scorer();
		}

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Qualification_Scorer' ) ) {
			$this->qualification_scorer = new WP_AI_Chatbot_LeadGen_Pro_Qualification_Scorer();
		}

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
			$this->lead_storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
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
		// Score lead on creation
		add_action( 'wp_ai_chatbot_lead_created', array( $this, 'score_new_lead' ), 10, 2 );

		// Re-score on significant events
		add_action( 'wp_ai_chatbot_message_sent', array( $this, 'maybe_rescore_lead' ), 10, 2 );
		add_action( 'wp_ai_chatbot_meeting_booked', array( $this, 'rescore_lead_by_session' ), 10, 1 );
		add_action( 'wp_ai_chatbot_form_completed', array( $this, 'rescore_lead_by_session' ), 10, 1 );

		// AJAX handlers
		add_action( 'wp_ajax_wp_ai_chatbot_get_lead_score', array( $this, 'ajax_get_score' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_rescore_lead', array( $this, 'ajax_rescore' ) );
	}

	/**
	 * Calculate composite score for a lead.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array|null Score data or null if unable to score.
	 */
	public function score( $lead_id ) {
		$lead = $this->lead_storage ? $this->lead_storage->get( $lead_id ) : null;

		if ( ! $lead ) {
			$this->logger->warning( 'Cannot score lead: Lead not found', array( 'lead_id' => $lead_id ) );
			return null;
		}

		// Gather all data needed for scoring
		$data = $this->gather_scoring_data( $lead );

		// Calculate individual scores
		$scores = array(
			'behavioral'    => $this->calculate_behavioral_score( $data ),
			'intent'        => $this->calculate_intent_score( $data ),
			'qualification' => $this->calculate_qualification_score( $data ),
		);

		// Get weights
		$weights = $this->get_weights();

		// Calculate weighted composite score
		$composite_score = 0;
		foreach ( $scores as $type => $score_data ) {
			$weight = $weights[ $type ] ?? 0.33;
			$composite_score += ( $score_data['score'] ?? 0 ) * $weight;
		}

		// Apply modifiers
		$modifiers = $this->calculate_modifiers( $data, $scores );
		$composite_score += $modifiers['total'];

		// Ensure score is between 0 and 100
		$composite_score = max( 0, min( 100, round( $composite_score ) ) );

		// Determine grade
		$grade = $this->determine_grade( $composite_score, $scores );

		// Build result
		$result = array(
			'lead_id'         => $lead_id,
			'composite_score' => $composite_score,
			'grade'           => $grade,
			'scores'          => $scores,
			'weights'         => $weights,
			'modifiers'       => $modifiers,
			'signals'         => $this->aggregate_signals( $scores ),
			'recommendations' => $this->generate_recommendations( $composite_score, $scores, $data ),
			'calculated_at'   => current_time( 'mysql' ),
		);

		// Store score in database
		$this->store_score( $lead_id, $result );

		$this->logger->debug( 'Lead scored', array(
			'lead_id' => $lead_id,
			'score'   => $composite_score,
			'grade'   => $grade['letter'],
		) );

		return $result;
	}

	/**
	 * Gather all data needed for scoring.
	 *
	 * @since 1.0.0
	 * @param array $lead Lead data.
	 * @return array Scoring data.
	 */
	private function gather_scoring_data( $lead ) {
		$data = array(
			'lead' => $lead,
		);

		// Get behavior data
		if ( $this->behavior_tracker && ! empty( $lead['session_id'] ) ) {
			$behavior = $this->behavior_tracker->get_behavior( $lead['session_id'] );
			if ( $behavior ) {
				$data['behavior'] = $behavior;
			}
		}

		// Get conversation messages
		if ( ! empty( $lead['conversation_id'] ) ) {
			if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager' ) ) {
				$manager = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager();
				$data['messages'] = $manager->get_messages( $lead['conversation_id'] );

				// Get classified intents from messages
				$data['classified_intents'] = $this->extract_classified_intents( $data['messages'] );
			}
		}

		// Copy lead fields to top level for easier access
		$data['name'] = $lead['name'] ?? '';
		$data['email'] = $lead['email'] ?? '';
		$data['phone'] = $lead['phone'] ?? '';
		$data['company'] = $lead['company'] ?? '';
		$data['message'] = $lead['message'] ?? '';

		// Get enrichment data if available
		if ( ! empty( $lead['custom_fields']['enrichment'] ) ) {
			$data['enrichment'] = $lead['custom_fields']['enrichment'];
		}

		return $data;
	}

	/**
	 * Extract classified intents from messages.
	 *
	 * @since 1.0.0
	 * @param array $messages Messages.
	 * @return array Classified intents.
	 */
	private function extract_classified_intents( $messages ) {
		$intents = array();

		foreach ( $messages as $message ) {
			$metadata = $message['metadata'] ?? array();
			if ( is_string( $metadata ) ) {
				$metadata = json_decode( $metadata, true ) ?: array();
			}

			if ( ! empty( $metadata['intent'] ) ) {
				$intents[] = $metadata['intent'];
			}
		}

		return $intents;
	}

	/**
	 * Calculate behavioral score.
	 *
	 * @since 1.0.0
	 * @param array $data Scoring data.
	 * @return array Score result.
	 */
	private function calculate_behavioral_score( $data ) {
		if ( ! $this->behavioral_scorer || empty( $data['behavior'] ) ) {
			return array(
				'score'     => 25, // Default score when no data
				'breakdown' => array(),
				'grade'     => array( 'letter' => 'C', 'label' => 'No Data' ),
				'signals'   => array(),
			);
		}

		return $this->behavioral_scorer->calculate_score( $data['behavior'] );
	}

	/**
	 * Calculate intent score.
	 *
	 * @since 1.0.0
	 * @param array $data Scoring data.
	 * @return array Score result.
	 */
	private function calculate_intent_score( $data ) {
		if ( ! $this->intent_scorer ) {
			return array(
				'score'          => 25,
				'breakdown'      => array(),
				'primary_intent' => null,
				'buying_stage'   => array( 'stage' => 'unknown' ),
				'signals'        => array(),
			);
		}

		return $this->intent_scorer->calculate_score( $data );
	}

	/**
	 * Calculate qualification score.
	 *
	 * @since 1.0.0
	 * @param array $data Scoring data.
	 * @return array Score result.
	 */
	private function calculate_qualification_score( $data ) {
		if ( ! $this->qualification_scorer ) {
			return array(
				'score'         => 25,
				'breakdown'     => array(),
				'qualification' => array( 'level' => 'unknown' ),
				'signals'       => array(),
				'bant'          => array(),
			);
		}

		return $this->qualification_scorer->calculate_score( $data );
	}

	/**
	 * Calculate score modifiers.
	 *
	 * @since 1.0.0
	 * @param array $data   Scoring data.
	 * @param array $scores Individual scores.
	 * @return array Modifiers.
	 */
	private function calculate_modifiers( $data, $scores ) {
		$modifiers = array(
			'applied' => array(),
			'total'   => 0,
		);

		$behavior = $data['behavior'] ?? array();
		$qualification = $scores['qualification']['breakdown'] ?? array();
		$intent = $scores['intent'] ?? array();

		// Meeting booked
		if ( intval( $behavior['meetings_booked'] ?? 0 ) > 0 ) {
			$modifiers['applied']['meeting_booked'] = self::SCORE_MODIFIERS['meeting_booked'];
			$modifiers['total'] += self::SCORE_MODIFIERS['meeting_booked'];
		}

		// Form completed
		if ( intval( $behavior['forms_completed'] ?? 0 ) > 0 ) {
			$modifiers['applied']['form_completed'] = self::SCORE_MODIFIERS['form_completed'];
			$modifiers['total'] += self::SCORE_MODIFIERS['form_completed'];
		}

		// Return visitor (3+ visits)
		if ( intval( $behavior['session_count'] ?? 1 ) >= 3 ) {
			$modifiers['applied']['return_visitor_3x'] = self::SCORE_MODIFIERS['return_visitor_3x'];
			$modifiers['total'] += self::SCORE_MODIFIERS['return_visitor_3x'];
		}

		// Pricing page viewed 3+ times
		if ( intval( $behavior['pricing_page_views'] ?? 0 ) >= 3 ) {
			$modifiers['applied']['pricing_viewed_3x'] = self::SCORE_MODIFIERS['pricing_viewed_3x'];
			$modifiers['total'] += self::SCORE_MODIFIERS['pricing_viewed_3x'];
		}

		// Decision maker
		$decision_type = $qualification['decision_maker']['type'] ?? 'unknown';
		if ( in_array( $decision_type, array( 'c_level', 'vp_director', 'owner_founder', 'decision_authority' ), true ) ) {
			$modifiers['applied']['decision_maker'] = self::SCORE_MODIFIERS['decision_maker'];
			$modifiers['total'] += self::SCORE_MODIFIERS['decision_maker'];
		}

		// Enterprise company
		$company_type = $qualification['company_size']['type'] ?? 'unknown';
		if ( $company_type === 'enterprise' ) {
			$modifiers['applied']['enterprise_company'] = self::SCORE_MODIFIERS['enterprise_company'];
			$modifiers['total'] += self::SCORE_MODIFIERS['enterprise_company'];
		}

		// Immediate timeline
		$timeline_type = $qualification['timeline']['type'] ?? 'unknown';
		if ( $timeline_type === 'immediate' ) {
			$modifiers['applied']['immediate_timeline'] = self::SCORE_MODIFIERS['immediate_timeline'];
			$modifiers['total'] += self::SCORE_MODIFIERS['immediate_timeline'];
		}

		// Negative: Disposable email
		$email_type = $qualification['email']['type'] ?? 'unknown';
		if ( $email_type === 'disposable' ) {
			$modifiers['applied']['disposable_email'] = self::SCORE_MODIFIERS['disposable_email'];
			$modifiers['total'] += self::SCORE_MODIFIERS['disposable_email'];
		}

		// Negative: No engagement (< 10 seconds, no messages)
		$duration = intval( $behavior['total_duration'] ?? 0 );
		$messages = intval( $behavior['messages_sent'] ?? 0 );
		if ( $duration < 10 && $messages === 0 ) {
			$modifiers['applied']['no_engagement'] = self::SCORE_MODIFIERS['no_engagement'];
			$modifiers['total'] += self::SCORE_MODIFIERS['no_engagement'];
		}

		// Negative: Spam behavior (many messages in short time)
		if ( $messages > 10 && $duration < 60 ) {
			$modifiers['applied']['spam_behavior'] = self::SCORE_MODIFIERS['spam_behavior'];
			$modifiers['total'] += self::SCORE_MODIFIERS['spam_behavior'];
		}

		return $modifiers;
	}

	/**
	 * Determine grade from composite score.
	 *
	 * @since 1.0.0
	 * @param int   $score  Composite score.
	 * @param array $scores Individual scores.
	 * @return array Grade info.
	 */
	private function determine_grade( $score, $scores ) {
		// Check for disqualifying factors
		$qualification = $scores['qualification']['qualification'] ?? array();
		if ( ( $qualification['level'] ?? '' ) === 'disqualified' ) {
			return array(
				'letter'      => 'D',
				'label'       => __( 'Disqualified', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => $qualification['description'] ?? '',
				'color'       => '#ef4444',
				'priority'    => 'low',
			);
		}

		if ( $score >= 85 ) {
			return array(
				'letter'      => 'A+',
				'label'       => __( 'Hot Lead', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Highly engaged, high intent, well-qualified lead ready for immediate contact', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#22c55e',
				'priority'    => 'urgent',
			);
		} elseif ( $score >= 70 ) {
			return array(
				'letter'      => 'A',
				'label'       => __( 'Warm Lead', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Strong engagement and qualification, should be prioritized for follow-up', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#84cc16',
				'priority'    => 'high',
			);
		} elseif ( $score >= 55 ) {
			return array(
				'letter'      => 'B',
				'label'       => __( 'Qualified Lead', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Good potential, meets qualification criteria, worth nurturing', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#eab308',
				'priority'    => 'medium',
			);
		} elseif ( $score >= 40 ) {
			return array(
				'letter'      => 'C',
				'label'       => __( 'Engaged Lead', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Shows interest but needs more qualification or nurturing', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#f97316',
				'priority'    => 'normal',
			);
		} elseif ( $score >= 25 ) {
			return array(
				'letter'      => 'D',
				'label'       => __( 'Cold Lead', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Low engagement or poor qualification, add to nurture campaign', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#6b7280',
				'priority'    => 'low',
			);
		} else {
			return array(
				'letter'      => 'F',
				'label'       => __( 'Unqualified', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Does not meet minimum criteria, likely not a good fit', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#ef4444',
				'priority'    => 'none',
			);
		}
	}

	/**
	 * Aggregate signals from all scorers.
	 *
	 * @since 1.0.0
	 * @param array $scores Individual scores.
	 * @return array Aggregated signals.
	 */
	private function aggregate_signals( $scores ) {
		$signals = array(
			'positive'    => array(),
			'high_value'  => array(),
			'negative'    => array(),
			'neutral'     => array(),
		);

		// Collect signals from each scorer
		$all_signals = array();

		if ( ! empty( $scores['behavioral']['signals'] ) ) {
			$all_signals = array_merge( $all_signals, $scores['behavioral']['signals'] );
		}

		if ( ! empty( $scores['intent']['signals'] ) ) {
			$all_signals = array_merge( $all_signals, $scores['intent']['signals'] );
		}

		if ( ! empty( $scores['qualification']['signals'] ) ) {
			$all_signals = array_merge( $all_signals, $scores['qualification']['signals'] );
		}

		// Categorize signals
		foreach ( $all_signals as $signal ) {
			$type = $signal['type'] ?? 'neutral';
			
			if ( $type === 'high_intent' || $type === 'high_value' ) {
				$signals['high_value'][] = $signal;
			} elseif ( $type === 'positive' ) {
				$signals['positive'][] = $signal;
			} elseif ( $type === 'negative' ) {
				$signals['negative'][] = $signal;
			} else {
				$signals['neutral'][] = $signal;
			}
		}

		// Add summary
		$signals['summary'] = array(
			'high_value_count' => count( $signals['high_value'] ),
			'positive_count'   => count( $signals['positive'] ),
			'negative_count'   => count( $signals['negative'] ),
		);

		return $signals;
	}

	/**
	 * Generate recommendations based on scores.
	 *
	 * @since 1.0.0
	 * @param int   $score  Composite score.
	 * @param array $scores Individual scores.
	 * @param array $data   Scoring data.
	 * @return array Recommendations.
	 */
	private function generate_recommendations( $score, $scores, $data ) {
		$recommendations = array();

		// High-priority actions for hot leads
		if ( $score >= 70 ) {
			$recommendations[] = array(
				'priority' => 'high',
				'action'   => 'immediate_contact',
				'message'  => __( 'Schedule a call within 24 hours - this lead is ready to buy', 'wp-ai-chatbot-leadgen-pro' ),
			);

			// Check if meeting not already booked
			$behavior = $data['behavior'] ?? array();
			if ( intval( $behavior['meetings_booked'] ?? 0 ) === 0 ) {
				$recommendations[] = array(
					'priority' => 'high',
					'action'   => 'schedule_meeting',
					'message'  => __( 'Send calendar link to book a meeting', 'wp-ai-chatbot-leadgen-pro' ),
				);
			}
		}

		// Intent-based recommendations
		$buying_stage = $scores['intent']['buying_stage']['stage'] ?? 'unknown';
		$primary_intent = $scores['intent']['primary_intent']['type'] ?? '';

		if ( $primary_intent === 'pricing_inquiry' ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'action'   => 'send_pricing',
				'message'  => __( 'Send detailed pricing information or proposal', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		if ( $buying_stage === 'consideration' ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'action'   => 'send_case_studies',
				'message'  => __( 'Share relevant case studies and success stories', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Qualification-based recommendations
		$qualification_level = $scores['qualification']['qualification']['level'] ?? 'unknown';

		if ( $qualification_level === 'marketing_qualified' ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'action'   => 'add_to_nurture',
				'message'  => __( 'Add to email nurture sequence to build engagement', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// BANT-based recommendations
		$bant = $scores['qualification']['bant'] ?? array();

		if ( ! ( $bant['budget']['identified'] ?? false ) ) {
			$recommendations[] = array(
				'priority' => 'low',
				'action'   => 'qualify_budget',
				'message'  => __( 'Discuss budget and investment expectations', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		if ( ! ( $bant['timeline']['identified'] ?? false ) ) {
			$recommendations[] = array(
				'priority' => 'low',
				'action'   => 'qualify_timeline',
				'message'  => __( 'Understand their timeline and urgency', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Data enrichment recommendation
		$email = $data['email'] ?? '';
		$has_enrichment = ! empty( $data['enrichment'] );
		if ( ! $has_enrichment && ! empty( $email ) ) {
			$recommendations[] = array(
				'priority' => 'low',
				'action'   => 'enrich_data',
				'message'  => __( 'Enrich lead data to improve qualification accuracy', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// CRM sync recommendation
		if ( $score >= 55 ) {
			$recommendations[] = array(
				'priority' => 'low',
				'action'   => 'sync_crm',
				'message'  => __( 'Sync lead to CRM for sales team follow-up', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Sort by priority
		usort( $recommendations, function( $a, $b ) {
			$priority_order = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
			return ( $priority_order[ $a['priority'] ] ?? 3 ) - ( $priority_order[ $b['priority'] ] ?? 3 );
		} );

		return $recommendations;
	}

	/**
	 * Store score in database.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id Lead ID.
	 * @param array $result  Score result.
	 */
	private function store_score( $lead_id, $result ) {
		if ( ! $this->lead_storage ) {
			return;
		}

		$this->lead_storage->update( $lead_id, array(
			'score'           => $result['composite_score'],
			'score_breakdown' => array(
				'behavioral'    => $result['scores']['behavioral']['score'] ?? 0,
				'intent'        => $result['scores']['intent']['score'] ?? 0,
				'qualification' => $result['scores']['qualification']['score'] ?? 0,
				'modifiers'     => $result['modifiers']['total'] ?? 0,
				'grade'         => $result['grade']['letter'] ?? 'F',
				'calculated_at' => $result['calculated_at'],
			),
		) );

		// Trigger action for other systems
		do_action( 'wp_ai_chatbot_lead_scored', $lead_id, $result );
	}

	/**
	 * Get scoring weights.
	 *
	 * @since 1.0.0
	 * @return array Weights.
	 */
	private function get_weights() {
		$custom_weights = $this->config->get( 'lead_scoring_weights', array() );
		return wp_parse_args( $custom_weights, self::DEFAULT_WEIGHTS );
	}

	/**
	 * Score new lead.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id   Lead ID.
	 * @param array $lead_data Lead data.
	 */
	public function score_new_lead( $lead_id, $lead_data ) {
		// Slight delay to ensure all data is stored
		wp_schedule_single_event( time() + 2, 'wp_ai_chatbot_score_lead', array( $lead_id ) );
	}

	/**
	 * Maybe rescore lead based on new activity.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param array  $message    Message data.
	 */
	public function maybe_rescore_lead( $session_id, $message ) {
		// Only rescore every 5 messages
		static $message_counts = array();

		if ( ! isset( $message_counts[ $session_id ] ) ) {
			$message_counts[ $session_id ] = 0;
		}

		$message_counts[ $session_id ]++;

		if ( $message_counts[ $session_id ] % 5 === 0 ) {
			$this->rescore_lead_by_session( $session_id );
		}
	}

	/**
	 * Rescore lead by session ID.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 */
	public function rescore_lead_by_session( $session_id ) {
		if ( ! $this->lead_storage ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ai_chatbot_leads';

		$lead_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE session_id = %s ORDER BY created_at DESC LIMIT 1",
			$session_id
		) );

		if ( $lead_id ) {
			$this->score( $lead_id );
		}
	}

	/**
	 * AJAX handler for getting lead score.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_score() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_id = intval( $_POST['lead_id'] ?? 0 );

		if ( ! $lead_id ) {
			wp_send_json_error( array( 'message' => 'Lead ID required' ), 400 );
		}

		$score = $this->score( $lead_id );

		if ( ! $score ) {
			wp_send_json_error( array( 'message' => 'Unable to score lead' ), 404 );
		}

		wp_send_json_success( $score );
	}

	/**
	 * AJAX handler for rescoring a lead.
	 *
	 * @since 1.0.0
	 */
	public function ajax_rescore() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_id = intval( $_POST['lead_id'] ?? 0 );

		if ( ! $lead_id ) {
			wp_send_json_error( array( 'message' => 'Lead ID required' ), 400 );
		}

		$score = $this->score( $lead_id );

		if ( ! $score ) {
			wp_send_json_error( array( 'message' => 'Unable to score lead' ), 404 );
		}

		wp_send_json_success( array(
			'message' => __( 'Lead rescored successfully', 'wp-ai-chatbot-leadgen-pro' ),
			'score'   => $score,
		) );
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
			$score = $this->score( $lead_id );
			if ( $score ) {
				$scores[ $lead_id ] = $score;
			}
		}

		return $scores;
	}

	/**
	 * Get score distribution statistics.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Statistics.
	 */
	public function get_statistics( $args = array() ) {
		if ( ! $this->lead_storage ) {
			return array();
		}

		return $this->lead_storage->get_statistics( $args );
	}

	/**
	 * Update scoring weights.
	 *
	 * @since 1.0.0
	 * @param array $weights New weights.
	 * @return bool True on success.
	 */
	public function update_weights( $weights ) {
		// Validate weights sum to 1.0
		$sum = array_sum( $weights );
		if ( abs( $sum - 1.0 ) > 0.01 ) {
			return false;
		}

		$this->config->set( 'lead_scoring_weights', $weights );
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
	 * Get score modifiers.
	 *
	 * @since 1.0.0
	 * @return array Score modifiers.
	 */
	public function get_score_modifiers() {
		return self::SCORE_MODIFIERS;
	}
}

