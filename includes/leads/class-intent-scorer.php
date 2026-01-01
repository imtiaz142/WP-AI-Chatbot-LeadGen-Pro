<?php
/**
 * Intent Scorer.
 *
 * Identifies and scores high-value actions and intents such as
 * pricing inquiries, meeting requests, demo requests, and purchase signals.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Intent_Scorer {

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
	 * Intent definitions.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const INTENT_DEFINITIONS = array(
		// Highest intent signals (25-30 points each)
		'meeting_request' => array(
			'score'       => 30,
			'category'    => 'conversion',
			'label'       => 'Meeting/Demo Request',
			'description' => 'Requested a meeting, demo, or call',
			'keywords'    => array( 'schedule', 'meeting', 'demo', 'call', 'talk', 'speak with', 'discuss', 'consultation', 'appointment', 'book' ),
		),
		'pricing_inquiry' => array(
			'score'       => 25,
			'category'    => 'evaluation',
			'label'       => 'Pricing Inquiry',
			'description' => 'Asked about pricing, costs, or plans',
			'keywords'    => array( 'price', 'pricing', 'cost', 'how much', 'plan', 'subscription', 'fee', 'rate', 'quote', 'proposal', 'budget', 'afford' ),
		),
		'trial_request' => array(
			'score'       => 25,
			'category'    => 'conversion',
			'label'       => 'Trial/Signup Request',
			'description' => 'Requested a free trial or signup',
			'keywords'    => array( 'trial', 'free trial', 'try', 'test', 'signup', 'sign up', 'register', 'get started', 'start' ),
		),
		'purchase_intent' => array(
			'score'       => 30,
			'category'    => 'conversion',
			'label'       => 'Purchase Intent',
			'description' => 'Expressed intent to buy or purchase',
			'keywords'    => array( 'buy', 'purchase', 'order', 'subscribe', 'pay', 'checkout', 'add to cart', 'get it', 'take it', 'proceed' ),
		),

		// High intent signals (15-20 points each)
		'comparison' => array(
			'score'       => 20,
			'category'    => 'evaluation',
			'label'       => 'Comparison Shopping',
			'description' => 'Comparing with competitors or alternatives',
			'keywords'    => array( 'compare', 'comparison', 'vs', 'versus', 'alternative', 'competitor', 'better than', 'difference', 'similar to' ),
		),
		'feature_inquiry' => array(
			'score'       => 15,
			'category'    => 'research',
			'label'       => 'Feature Inquiry',
			'description' => 'Asked about specific features or capabilities',
			'keywords'    => array( 'feature', 'capability', 'can it', 'does it', 'support', 'integration', 'compatible', 'work with', 'include' ),
		),
		'implementation' => array(
			'score'       => 20,
			'category'    => 'evaluation',
			'label'       => 'Implementation Questions',
			'description' => 'Asked about setup, implementation, or migration',
			'keywords'    => array( 'implement', 'setup', 'set up', 'install', 'configure', 'migrate', 'onboard', 'deploy', 'integrate' ),
		),
		'timeline' => array(
			'score'       => 18,
			'category'    => 'evaluation',
			'label'       => 'Timeline Discussion',
			'description' => 'Discussing timelines or urgency',
			'keywords'    => array( 'when', 'timeline', 'how long', 'quickly', 'soon', 'urgent', 'deadline', 'ready', 'available' ),
		),
		'support_inquiry' => array(
			'score'       => 12,
			'category'    => 'research',
			'label'       => 'Support Inquiry',
			'description' => 'Asked about support or service',
			'keywords'    => array( 'support', 'help', 'service', 'assistance', 'customer service', 'training', 'documentation' ),
		),

		// Medium intent signals (8-12 points each)
		'use_case' => array(
			'score'       => 12,
			'category'    => 'research',
			'label'       => 'Use Case Discussion',
			'description' => 'Discussing specific use cases or needs',
			'keywords'    => array( 'use case', 'scenario', 'example', 'case study', 'success story', 'how do you', 'can you help with' ),
		),
		'team_size' => array(
			'score'       => 10,
			'category'    => 'qualification',
			'label'       => 'Team Size Mentioned',
			'description' => 'Mentioned team or company size',
			'keywords'    => array( 'team', 'employees', 'users', 'seats', 'licenses', 'company size', 'organization' ),
		),
		'decision_maker' => array(
			'score'       => 15,
			'category'    => 'qualification',
			'label'       => 'Decision Maker Signals',
			'description' => 'Indicates decision-making authority',
			'keywords'    => array( 'decide', 'decision', 'approve', 'authority', 'manager', 'director', 'ceo', 'cto', 'founder', 'owner', 'lead' ),
		),
		'enterprise' => array(
			'score'       => 12,
			'category'    => 'qualification',
			'label'       => 'Enterprise Interest',
			'description' => 'Interest in enterprise features',
			'keywords'    => array( 'enterprise', 'sso', 'security', 'compliance', 'sla', 'dedicated', 'custom', 'white label' ),
		),

		// Lower intent signals (5-8 points each)
		'general_info' => array(
			'score'       => 5,
			'category'    => 'awareness',
			'label'       => 'General Information',
			'description' => 'General information requests',
			'keywords'    => array( 'what is', 'tell me about', 'explain', 'how does', 'overview', 'learn more' ),
		),
		'contact_request' => array(
			'score'       => 18,
			'category'    => 'conversion',
			'label'       => 'Contact Request',
			'description' => 'Requested to contact or be contacted',
			'keywords'    => array( 'contact', 'email', 'phone', 'call me', 'reach out', 'get in touch', 'speak to someone' ),
		),
	);

	/**
	 * Page intent signals.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const PAGE_INTENT_SIGNALS = array(
		'pricing'  => 25,
		'demo'     => 22,
		'trial'    => 22,
		'contact'  => 18,
		'features' => 12,
		'products' => 10,
		'checkout' => 28,
		'signup'   => 25,
		'compare'  => 18,
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
	}

	/**
	 * Calculate intent score for a lead.
	 *
	 * @since 1.0.0
	 * @param array $data Lead and behavior data.
	 * @return array Score and breakdown.
	 */
	public function calculate_score( $data ) {
		$breakdown = array(
			'message_intents' => $this->analyze_message_intents( $data ),
			'page_intents'    => $this->analyze_page_intents( $data ),
			'action_intents'  => $this->analyze_action_intents( $data ),
		);

		// Calculate weighted total
		$total_score = 0;
		$detected_intents = array();

		// Message-based intents (40% weight, max 40 points)
		$message_score = min( 40, $breakdown['message_intents']['score'] * 0.4 );
		$total_score += $message_score;
		$detected_intents = array_merge( $detected_intents, $breakdown['message_intents']['detected'] );

		// Page-based intents (35% weight, max 35 points)
		$page_score = min( 35, $breakdown['page_intents']['score'] * 0.35 );
		$total_score += $page_score;

		// Action-based intents (25% weight, max 25 points)
		$action_score = min( 25, $breakdown['action_intents']['score'] * 0.25 );
		$total_score += $action_score;
		$detected_intents = array_merge( $detected_intents, $breakdown['action_intents']['detected'] );

		// Normalize score to 0-100
		$total_score = min( 100, round( $total_score ) );

		// Determine primary intent
		$primary_intent = $this->determine_primary_intent( $breakdown );

		// Get buying stage
		$buying_stage = $this->determine_buying_stage( $total_score, $detected_intents );

		return array(
			'score'          => $total_score,
			'breakdown'      => $breakdown,
			'primary_intent' => $primary_intent,
			'buying_stage'   => $buying_stage,
			'detected'       => array_unique( $detected_intents ),
			'signals'        => $this->extract_signals( $breakdown ),
		);
	}

	/**
	 * Analyze intents from messages.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Intent analysis.
	 */
	private function analyze_message_intents( $data ) {
		$messages = $data['messages'] ?? array();
		$classified_intents = $data['classified_intents'] ?? array();
		
		$detected = array();
		$raw_score = 0;
		$intent_counts = array();

		// Analyze from pre-classified intents
		foreach ( $classified_intents as $intent ) {
			$intent_type = $intent['type'] ?? $intent;
			if ( isset( self::INTENT_DEFINITIONS[ $intent_type ] ) ) {
				$definition = self::INTENT_DEFINITIONS[ $intent_type ];
				if ( ! isset( $intent_counts[ $intent_type ] ) ) {
					$intent_counts[ $intent_type ] = 0;
					$raw_score += $definition['score'];
					$detected[] = $intent_type;
				}
				$intent_counts[ $intent_type ]++;
			}
		}

		// Analyze from message content (keyword matching)
		foreach ( $messages as $message ) {
			$content = strtolower( $message['content'] ?? '' );
			if ( empty( $content ) ) {
				continue;
			}

			foreach ( self::INTENT_DEFINITIONS as $intent_type => $definition ) {
				// Skip if already detected at max value
				if ( isset( $intent_counts[ $intent_type ] ) && $intent_counts[ $intent_type ] >= 3 ) {
					continue;
				}

				foreach ( $definition['keywords'] as $keyword ) {
					if ( strpos( $content, $keyword ) !== false ) {
						if ( ! isset( $intent_counts[ $intent_type ] ) ) {
							$intent_counts[ $intent_type ] = 0;
							$raw_score += $definition['score'];
							$detected[] = $intent_type;
						}
						$intent_counts[ $intent_type ]++;
						break; // Only count once per message
					}
				}
			}
		}

		return array(
			'score'    => $raw_score,
			'detected' => $detected,
			'counts'   => $intent_counts,
		);
	}

	/**
	 * Analyze intents from page views.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Intent analysis.
	 */
	private function analyze_page_intents( $data ) {
		$behavior = $data['behavior'] ?? array();
		$pages = $behavior['page_list'] ?? array();
		
		if ( is_string( $pages ) ) {
			$pages = json_decode( $pages, true ) ?: array();
		}

		$detected_pages = array();
		$raw_score = 0;

		// Check each page for intent signals
		foreach ( $pages as $page_url ) {
			$page_url = strtolower( $page_url );

			foreach ( self::PAGE_INTENT_SIGNALS as $page_type => $score ) {
				if ( strpos( $page_url, '/' . $page_type ) !== false ) {
					if ( ! isset( $detected_pages[ $page_type ] ) ) {
						$detected_pages[ $page_type ] = array(
							'count' => 0,
							'score' => $score,
						);
						$raw_score += $score;
					}
					$detected_pages[ $page_type ]['count']++;
				}
			}
		}

		// High-value page specific checks
		$pricing_views = intval( $behavior['pricing_page_views'] ?? 0 );
		$demo_views = intval( $behavior['demo_page_views'] ?? 0 );
		$contact_views = intval( $behavior['contact_page_views'] ?? 0 );
		$product_views = intval( $behavior['product_page_views'] ?? 0 );

		// Add scores for tracked high-value pages
		if ( $pricing_views > 0 && ! isset( $detected_pages['pricing'] ) ) {
			$raw_score += self::PAGE_INTENT_SIGNALS['pricing'];
			$detected_pages['pricing'] = array( 'count' => $pricing_views, 'score' => self::PAGE_INTENT_SIGNALS['pricing'] );
		}
		if ( $demo_views > 0 && ! isset( $detected_pages['demo'] ) ) {
			$raw_score += self::PAGE_INTENT_SIGNALS['demo'];
			$detected_pages['demo'] = array( 'count' => $demo_views, 'score' => self::PAGE_INTENT_SIGNALS['demo'] );
		}

		// Multiple visits to high-value pages increase score
		if ( $pricing_views >= 2 ) {
			$raw_score += 10; // Bonus for return pricing views
		}
		if ( $demo_views >= 2 ) {
			$raw_score += 10; // Bonus for return demo page views
		}

		return array(
			'score'          => $raw_score,
			'detected_pages' => $detected_pages,
		);
	}

	/**
	 * Analyze intents from actions.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Intent analysis.
	 */
	private function analyze_action_intents( $data ) {
		$behavior = $data['behavior'] ?? array();
		$high_intent_actions = $behavior['high_intent_actions'] ?? array();
		
		if ( is_string( $high_intent_actions ) ) {
			$high_intent_actions = json_decode( $high_intent_actions, true ) ?: array();
		}

		$detected = array();
		$raw_score = 0;

		// Score based on actions taken
		$action_scores = array(
			'meeting_booked'   => 35,
			'file_download'    => 15,
			'lead_captured'    => 20,
			'form_completed'   => 18,
			'demo_requested'   => 30,
			'trial_started'    => 28,
			'pricing_viewed'   => 20,
			'quote_requested'  => 25,
		);

		foreach ( $high_intent_actions as $action ) {
			$action_type = $action['action'] ?? '';
			if ( isset( $action_scores[ $action_type ] ) && ! in_array( $action_type, $detected, true ) ) {
				$raw_score += $action_scores[ $action_type ];
				$detected[] = $action_type;
			}
		}

		// Check specific behavior metrics
		if ( intval( $behavior['meetings_booked'] ?? 0 ) > 0 && ! in_array( 'meeting_booked', $detected, true ) ) {
			$raw_score += $action_scores['meeting_booked'];
			$detected[] = 'meeting_booked';
		}

		if ( intval( $behavior['files_downloaded'] ?? 0 ) > 0 && ! in_array( 'file_download', $detected, true ) ) {
			$raw_score += $action_scores['file_download'];
			$detected[] = 'file_download';
		}

		if ( intval( $behavior['forms_completed'] ?? 0 ) > 0 && ! in_array( 'form_completed', $detected, true ) ) {
			$raw_score += $action_scores['form_completed'];
			$detected[] = 'form_completed';
		}

		return array(
			'score'    => $raw_score,
			'detected' => $detected,
		);
	}

	/**
	 * Determine primary intent.
	 *
	 * @since 1.0.0
	 * @param array $breakdown Score breakdown.
	 * @return array|null Primary intent info.
	 */
	private function determine_primary_intent( $breakdown ) {
		$all_intents = array();

		// Collect all detected intents with scores
		if ( ! empty( $breakdown['message_intents']['detected'] ) ) {
			foreach ( $breakdown['message_intents']['detected'] as $intent ) {
				if ( isset( self::INTENT_DEFINITIONS[ $intent ] ) ) {
					$all_intents[ $intent ] = self::INTENT_DEFINITIONS[ $intent ]['score'];
				}
			}
		}

		if ( ! empty( $breakdown['action_intents']['detected'] ) ) {
			$action_scores = array(
				'meeting_booked'  => 35,
				'demo_requested'  => 30,
				'trial_started'   => 28,
				'quote_requested' => 25,
				'file_download'   => 15,
			);
			foreach ( $breakdown['action_intents']['detected'] as $action ) {
				if ( isset( $action_scores[ $action ] ) ) {
					$all_intents[ $action ] = $action_scores[ $action ];
				}
			}
		}

		if ( empty( $all_intents ) ) {
			return null;
		}

		// Find highest scoring intent
		arsort( $all_intents );
		$primary = array_key_first( $all_intents );

		$definition = self::INTENT_DEFINITIONS[ $primary ] ?? null;

		return array(
			'type'        => $primary,
			'score'       => $all_intents[ $primary ],
			'label'       => $definition['label'] ?? ucfirst( str_replace( '_', ' ', $primary ) ),
			'category'    => $definition['category'] ?? 'unknown',
			'description' => $definition['description'] ?? '',
		);
	}

	/**
	 * Determine buying stage.
	 *
	 * @since 1.0.0
	 * @param int   $score           Intent score.
	 * @param array $detected_intents Detected intents.
	 * @return array Buying stage info.
	 */
	private function determine_buying_stage( $score, $detected_intents ) {
		// Check for specific high-intent signals
		$conversion_intents = array( 'purchase_intent', 'meeting_request', 'trial_request', 'meeting_booked', 'demo_requested' );
		$evaluation_intents = array( 'pricing_inquiry', 'comparison', 'implementation', 'timeline' );
		$research_intents = array( 'feature_inquiry', 'use_case', 'general_info' );

		$has_conversion_intent = ! empty( array_intersect( $detected_intents, $conversion_intents ) );
		$has_evaluation_intent = ! empty( array_intersect( $detected_intents, $evaluation_intents ) );
		$has_research_intent = ! empty( array_intersect( $detected_intents, $research_intents ) );

		if ( $has_conversion_intent || $score >= 80 ) {
			return array(
				'stage'       => 'decision',
				'label'       => __( 'Decision Stage', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Ready to make a purchase decision', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#22c55e',
				'priority'    => 'high',
			);
		} elseif ( $has_evaluation_intent || $score >= 50 ) {
			return array(
				'stage'       => 'consideration',
				'label'       => __( 'Consideration Stage', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Evaluating options and comparing solutions', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#eab308',
				'priority'    => 'medium',
			);
		} elseif ( $has_research_intent || $score >= 20 ) {
			return array(
				'stage'       => 'awareness',
				'label'       => __( 'Awareness Stage', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Researching and learning about solutions', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#3b82f6',
				'priority'    => 'low',
			);
		} else {
			return array(
				'stage'       => 'unknown',
				'label'       => __( 'Unknown Stage', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Insufficient data to determine buying stage', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#9ca3af',
				'priority'    => 'none',
			);
		}
	}

	/**
	 * Extract intent signals.
	 *
	 * @since 1.0.0
	 * @param array $breakdown Score breakdown.
	 * @return array Signals.
	 */
	private function extract_signals( $breakdown ) {
		$signals = array();

		// Message intent signals
		if ( ! empty( $breakdown['message_intents']['detected'] ) ) {
			foreach ( $breakdown['message_intents']['detected'] as $intent ) {
				if ( isset( self::INTENT_DEFINITIONS[ $intent ] ) ) {
					$definition = self::INTENT_DEFINITIONS[ $intent ];
					$signals[] = array(
						'type'     => $definition['category'] === 'conversion' ? 'high_intent' : 'positive',
						'signal'   => $intent,
						'label'    => $definition['label'],
						'category' => $definition['category'],
						'score'    => $definition['score'],
					);
				}
			}
		}

		// Page intent signals
		if ( ! empty( $breakdown['page_intents']['detected_pages'] ) ) {
			foreach ( $breakdown['page_intents']['detected_pages'] as $page_type => $data ) {
				$signals[] = array(
					'type'   => in_array( $page_type, array( 'pricing', 'demo', 'checkout', 'signup' ), true ) ? 'high_intent' : 'positive',
					'signal' => $page_type . '_page_viewed',
					'label'  => sprintf(
						/* translators: %1$s: page type, %2$d: view count */
						__( 'Viewed %1$s page %2$d time(s)', 'wp-ai-chatbot-leadgen-pro' ),
						ucfirst( $page_type ),
						$data['count']
					),
					'category' => 'page_view',
				);
			}
		}

		// Action signals
		if ( ! empty( $breakdown['action_intents']['detected'] ) ) {
			$action_labels = array(
				'meeting_booked'   => __( 'Booked a meeting', 'wp-ai-chatbot-leadgen-pro' ),
				'file_download'    => __( 'Downloaded a file', 'wp-ai-chatbot-leadgen-pro' ),
				'form_completed'   => __( 'Completed a form', 'wp-ai-chatbot-leadgen-pro' ),
				'demo_requested'   => __( 'Requested a demo', 'wp-ai-chatbot-leadgen-pro' ),
				'trial_started'    => __( 'Started a trial', 'wp-ai-chatbot-leadgen-pro' ),
				'lead_captured'    => __( 'Submitted contact info', 'wp-ai-chatbot-leadgen-pro' ),
			);

			foreach ( $breakdown['action_intents']['detected'] as $action ) {
				$signals[] = array(
					'type'     => 'high_intent',
					'signal'   => $action,
					'label'    => $action_labels[ $action ] ?? ucfirst( str_replace( '_', ' ', $action ) ),
					'category' => 'action',
				);
			}
		}

		return $signals;
	}

	/**
	 * Classify intent from text.
	 *
	 * @since 1.0.0
	 * @param string $text Text to classify.
	 * @return array Classified intents.
	 */
	public function classify_text( $text ) {
		$text = strtolower( $text );
		$detected = array();

		foreach ( self::INTENT_DEFINITIONS as $intent_type => $definition ) {
			foreach ( $definition['keywords'] as $keyword ) {
				if ( strpos( $text, $keyword ) !== false ) {
					$detected[] = array(
						'type'     => $intent_type,
						'keyword'  => $keyword,
						'score'    => $definition['score'],
						'category' => $definition['category'],
						'label'    => $definition['label'],
					);
					break;
				}
			}
		}

		// Sort by score descending
		usort( $detected, function( $a, $b ) {
			return $b['score'] - $a['score'];
		} );

		return $detected;
	}

	/**
	 * Get intent definitions.
	 *
	 * @since 1.0.0
	 * @return array Intent definitions.
	 */
	public function get_intent_definitions() {
		return self::INTENT_DEFINITIONS;
	}

	/**
	 * Get page intent signals.
	 *
	 * @since 1.0.0
	 * @return array Page intent signals.
	 */
	public function get_page_intent_signals() {
		return self::PAGE_INTENT_SIGNALS;
	}

	/**
	 * Score a lead.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array|null Score data or null if unable to score.
	 */
	public function score_lead( $lead_id ) {
		$data = $this->gather_lead_data( $lead_id );

		if ( empty( $data ) ) {
			return null;
		}

		return $this->calculate_score( $data );
	}

	/**
	 * Gather data for lead scoring.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array Lead data.
	 */
	private function gather_lead_data( $lead_id ) {
		$data = array();

		// Get lead info
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
			$storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
			$lead = $storage->get( $lead_id );
			if ( $lead ) {
				$data['lead'] = $lead;
			}
		}

		// Get behavior data
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker' ) ) {
			$tracker = new WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker();
			$behavior = $tracker->get_behavior_by_lead( $lead_id );
			if ( $behavior ) {
				$data['behavior'] = $behavior;
			}
		}

		// Get conversation messages
		if ( ! empty( $data['lead']['conversation_id'] ) ) {
			if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager' ) ) {
				$manager = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager();
				$messages = $manager->get_messages( $data['lead']['conversation_id'] );
				$data['messages'] = array_filter( $messages, function( $m ) {
					return ( $m['role'] ?? '' ) === 'user';
				} );
			}
		}

		return $data;
	}

	/**
	 * Batch score leads.
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
}

