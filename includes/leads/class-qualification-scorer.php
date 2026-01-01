<?php
/**
 * Qualification Scorer.
 *
 * Scores leads based on qualification criteria including business email,
 * company size, budget mentions, decision-maker indicators, and timeline.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Qualification_Scorer {

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
	 * Free email domains (not business emails).
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const FREE_EMAIL_DOMAINS = array(
		'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
		'mail.com', 'protonmail.com', 'icloud.com', 'live.com', 'msn.com',
		'ymail.com', 'zoho.com', 'gmx.com', 'gmx.net', 'inbox.com',
		'fastmail.com', 'hushmail.com', 'tutanota.com', 'pm.me',
		'yandex.com', 'mail.ru', 'qq.com', '163.com', '126.com',
	);

	/**
	 * Education email patterns.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const EDUCATION_PATTERNS = array( '.edu', '.ac.', '.edu.', 'university', 'college', 'school' );

	/**
	 * Company size indicators.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const COMPANY_SIZE_INDICATORS = array(
		'enterprise' => array(
			'patterns' => array( '1000+', '5000+', '10000+', 'enterprise', 'fortune 500', 'large company', 'multinational', 'global' ),
			'score'    => 25,
			'label'    => 'Enterprise (1000+)',
		),
		'mid_market' => array(
			'patterns' => array( '100-', '200-', '500-', 'mid-size', 'medium', 'growing', '100 employees', '200 employees', '500 employees' ),
			'score'    => 20,
			'label'    => 'Mid-Market (100-999)',
		),
		'smb' => array(
			'patterns' => array( '10-', '20-', '50-', 'small business', 'smb', 'startup', 'small team', '10 employees', '20 employees', '50 employees' ),
			'score'    => 12,
			'label'    => 'SMB (10-99)',
		),
		'micro' => array(
			'patterns' => array( '1-', '2-', '5-', 'solo', 'freelance', 'individual', 'just me', 'myself', 'one person', 'couple of us' ),
			'score'    => 5,
			'label'    => 'Micro (1-9)',
		),
	);

	/**
	 * Budget indicators.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const BUDGET_INDICATORS = array(
		'enterprise_budget' => array(
			'patterns' => array( '$100k', '$500k', '$1m', 'million', 'unlimited budget', 'significant budget', 'substantial investment' ),
			'score'    => 25,
			'label'    => 'Enterprise Budget ($100K+)',
		),
		'professional_budget' => array(
			'patterns' => array( '$10k', '$20k', '$50k', 'healthy budget', 'allocated budget', 'approved budget', 'yearly budget' ),
			'score'    => 20,
			'label'    => 'Professional Budget ($10K-$100K)',
		),
		'standard_budget' => array(
			'patterns' => array( '$1k', '$2k', '$5k', 'budget for', 'set aside', 'willing to spend', 'can afford' ),
			'score'    => 12,
			'label'    => 'Standard Budget ($1K-$10K)',
		),
		'limited_budget' => array(
			'patterns' => array( 'tight budget', 'limited budget', 'small budget', 'free', 'cheapest', 'lowest cost', 'affordable', 'discount' ),
			'score'    => 3,
			'label'    => 'Limited Budget',
		),
		'budget_mentioned' => array(
			'patterns' => array( 'budget', 'cost', 'pricing', 'price', 'spend', 'invest', 'pay', 'afford' ),
			'score'    => 8,
			'label'    => 'Budget Discussed',
		),
	);

	/**
	 * Decision maker indicators.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const DECISION_MAKER_INDICATORS = array(
		'c_level' => array(
			'patterns' => array( 'ceo', 'cto', 'cfo', 'coo', 'cmo', 'cio', 'chief', 'c-level', 'c-suite' ),
			'score'    => 25,
			'label'    => 'C-Level Executive',
		),
		'vp_director' => array(
			'patterns' => array( 'vp', 'vice president', 'director', 'head of', 'svp', 'evp' ),
			'score'    => 22,
			'label'    => 'VP/Director',
		),
		'manager' => array(
			'patterns' => array( 'manager', 'lead', 'team lead', 'supervisor', 'coordinator' ),
			'score'    => 15,
			'label'    => 'Manager/Lead',
		),
		'owner_founder' => array(
			'patterns' => array( 'owner', 'founder', 'co-founder', 'partner', 'principal', 'proprietor' ),
			'score'    => 25,
			'label'    => 'Owner/Founder',
		),
		'decision_authority' => array(
			'patterns' => array( 'i decide', 'my decision', 'i approve', 'i can approve', 'decision maker', 'final say', 'sign off', 'authorize' ),
			'score'    => 20,
			'label'    => 'Decision Authority',
		),
		'influencer' => array(
			'patterns' => array( 'recommend', 'evaluate', 'research', 'looking into', 'exploring', 'considering' ),
			'score'    => 8,
			'label'    => 'Influencer/Researcher',
		),
		'no_authority' => array(
			'patterns' => array( 'check with', 'ask my', 'need approval', 'run it by', 'manager decides', 'boss', 'supervisor' ),
			'score'    => 3,
			'label'    => 'No Direct Authority',
		),
	);

	/**
	 * Timeline indicators.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const TIMELINE_INDICATORS = array(
		'immediate' => array(
			'patterns' => array( 'asap', 'immediately', 'urgent', 'today', 'this week', 'right away', 'now', 'as soon as possible' ),
			'score'    => 25,
			'label'    => 'Immediate Need',
		),
		'short_term' => array(
			'patterns' => array( 'this month', 'next week', 'next month', 'within 30 days', 'q1', 'q2', 'next quarter', 'soon' ),
			'score'    => 20,
			'label'    => 'Short-Term (1-3 months)',
		),
		'mid_term' => array(
			'patterns' => array( 'next quarter', '3 months', '6 months', 'this year', 'by end of year', 'within 6 months' ),
			'score'    => 12,
			'label'    => 'Mid-Term (3-6 months)',
		),
		'long_term' => array(
			'patterns' => array( 'next year', '12 months', 'eventually', 'planning ahead', 'future', 'down the road' ),
			'score'    => 5,
			'label'    => 'Long-Term (6+ months)',
		),
		'just_looking' => array(
			'patterns' => array( 'just looking', 'just browsing', 'no rush', 'no timeline', 'whenever', 'someday' ),
			'score'    => 2,
			'label'    => 'No Timeline',
		),
	);

	/**
	 * Industry scoring.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const INDUSTRY_SCORES = array(
		'technology'    => 15,
		'finance'       => 18,
		'healthcare'    => 16,
		'enterprise'    => 20,
		'saas'          => 15,
		'ecommerce'     => 12,
		'manufacturing' => 14,
		'consulting'    => 13,
		'retail'        => 10,
		'education'     => 8,
		'nonprofit'     => 5,
		'government'    => 12,
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
	 * Calculate qualification score.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Score and breakdown.
	 */
	public function calculate_score( $data ) {
		$breakdown = array(
			'email'         => $this->score_email( $data ),
			'company_size'  => $this->score_company_size( $data ),
			'budget'        => $this->score_budget( $data ),
			'decision_maker' => $this->score_decision_maker( $data ),
			'timeline'      => $this->score_timeline( $data ),
			'completeness'  => $this->score_completeness( $data ),
		);

		// Calculate weighted total
		$weights = array(
			'email'         => 0.15, // 15% weight
			'company_size'  => 0.20, // 20% weight
			'budget'        => 0.20, // 20% weight
			'decision_maker' => 0.20, // 20% weight
			'timeline'      => 0.15, // 15% weight
			'completeness'  => 0.10, // 10% weight
		);

		$weighted_score = 0;
		foreach ( $breakdown as $category => $result ) {
			$weight = $weights[ $category ] ?? 0.10;
			$weighted_score += $result['score'] * $weight;
		}

		// Normalize to 0-100
		$total_score = min( 100, round( $weighted_score ) );

		// Determine qualification level
		$qualification = $this->determine_qualification_level( $total_score, $breakdown );

		// Extract signals
		$signals = $this->extract_signals( $breakdown );

		return array(
			'score'         => $total_score,
			'breakdown'     => $breakdown,
			'qualification' => $qualification,
			'signals'       => $signals,
			'bant'          => $this->get_bant_analysis( $breakdown ),
		);
	}

	/**
	 * Score email quality.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Score result.
	 */
	private function score_email( $data ) {
		$email = strtolower( $data['email'] ?? '' );
		
		if ( empty( $email ) ) {
			return array(
				'score'   => 0,
				'type'    => 'none',
				'label'   => __( 'No email provided', 'wp-ai-chatbot-leadgen-pro' ),
				'details' => array(),
			);
		}

		$domain = substr( strrchr( $email, '@' ), 1 );

		// Check for free email
		if ( in_array( $domain, self::FREE_EMAIL_DOMAINS, true ) ) {
			return array(
				'score'   => 20,
				'type'    => 'personal',
				'label'   => __( 'Personal Email', 'wp-ai-chatbot-leadgen-pro' ),
				'details' => array( 'domain' => $domain ),
			);
		}

		// Check for education email
		foreach ( self::EDUCATION_PATTERNS as $pattern ) {
			if ( strpos( $domain, $pattern ) !== false ) {
				return array(
					'score'   => 40,
					'type'    => 'education',
					'label'   => __( 'Education Email', 'wp-ai-chatbot-leadgen-pro' ),
					'details' => array( 'domain' => $domain ),
				);
			}
		}

		// Check for disposable/temporary email
		$disposable_patterns = array( 'temp', 'disposable', 'throwaway', 'mailinator', '10minute', 'guerrilla' );
		foreach ( $disposable_patterns as $pattern ) {
			if ( strpos( $domain, $pattern ) !== false ) {
				return array(
					'score'   => 5,
					'type'    => 'disposable',
					'label'   => __( 'Disposable Email', 'wp-ai-chatbot-leadgen-pro' ),
					'details' => array( 'domain' => $domain ),
				);
			}
		}

		// Business email (custom domain)
		return array(
			'score'   => 100,
			'type'    => 'business',
			'label'   => __( 'Business Email', 'wp-ai-chatbot-leadgen-pro' ),
			'details' => array( 'domain' => $domain, 'company_domain' => $domain ),
		);
	}

	/**
	 * Score company size.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Score result.
	 */
	private function score_company_size( $data ) {
		$messages = $this->get_message_text( $data );
		$company = strtolower( $data['company'] ?? '' );
		$combined_text = $messages . ' ' . $company;

		$detected = null;
		$highest_score = 0;

		foreach ( self::COMPANY_SIZE_INDICATORS as $size_type => $config ) {
			foreach ( $config['patterns'] as $pattern ) {
				if ( stripos( $combined_text, $pattern ) !== false ) {
					if ( $config['score'] > $highest_score ) {
						$highest_score = $config['score'];
						$detected = array(
							'type'    => $size_type,
							'pattern' => $pattern,
							'label'   => $config['label'],
						);
					}
				}
			}
		}

		// Check for explicit employee count in enrichment data
		$enrichment = $data['enrichment'] ?? array();
		$employee_count = intval( $enrichment['employees'] ?? 0 );

		if ( $employee_count > 0 ) {
			if ( $employee_count >= 1000 ) {
				return array(
					'score'    => 100,
					'type'     => 'enterprise',
					'label'    => sprintf( __( 'Enterprise (%d employees)', 'wp-ai-chatbot-leadgen-pro' ), $employee_count ),
					'details'  => array( 'employee_count' => $employee_count, 'source' => 'enrichment' ),
				);
			} elseif ( $employee_count >= 100 ) {
				return array(
					'score'    => 80,
					'type'     => 'mid_market',
					'label'    => sprintf( __( 'Mid-Market (%d employees)', 'wp-ai-chatbot-leadgen-pro' ), $employee_count ),
					'details'  => array( 'employee_count' => $employee_count, 'source' => 'enrichment' ),
				);
			} elseif ( $employee_count >= 10 ) {
				return array(
					'score'    => 50,
					'type'     => 'smb',
					'label'    => sprintf( __( 'SMB (%d employees)', 'wp-ai-chatbot-leadgen-pro' ), $employee_count ),
					'details'  => array( 'employee_count' => $employee_count, 'source' => 'enrichment' ),
				);
			} else {
				return array(
					'score'    => 20,
					'type'     => 'micro',
					'label'    => sprintf( __( 'Micro (%d employees)', 'wp-ai-chatbot-leadgen-pro' ), $employee_count ),
					'details'  => array( 'employee_count' => $employee_count, 'source' => 'enrichment' ),
				);
			}
		}

		if ( $detected ) {
			// Convert indicator score to 0-100 scale
			$normalized_score = ( $detected['type'] === 'enterprise' ) ? 100 :
				( ( $detected['type'] === 'mid_market' ) ? 80 :
				( ( $detected['type'] === 'smb' ) ? 50 : 20 ) );

			return array(
				'score'   => $normalized_score,
				'type'    => $detected['type'],
				'label'   => $detected['label'],
				'details' => array( 'pattern' => $detected['pattern'], 'source' => 'message' ),
			);
		}

		return array(
			'score'   => 30, // Default middle score when unknown
			'type'    => 'unknown',
			'label'   => __( 'Unknown Company Size', 'wp-ai-chatbot-leadgen-pro' ),
			'details' => array(),
		);
	}

	/**
	 * Score budget indicators.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Score result.
	 */
	private function score_budget( $data ) {
		$messages = $this->get_message_text( $data );
		
		$detected = array();
		$highest_score = 0;
		$highest_indicator = null;

		foreach ( self::BUDGET_INDICATORS as $budget_type => $config ) {
			foreach ( $config['patterns'] as $pattern ) {
				if ( stripos( $messages, $pattern ) !== false ) {
					$detected[] = array(
						'type'    => $budget_type,
						'pattern' => $pattern,
						'score'   => $config['score'],
						'label'   => $config['label'],
					);
					
					if ( $config['score'] > $highest_score ) {
						$highest_score = $config['score'];
						$highest_indicator = array(
							'type'  => $budget_type,
							'label' => $config['label'],
						);
					}
					break; // Only count each type once
				}
			}
		}

		if ( empty( $detected ) ) {
			return array(
				'score'   => 20, // Default when no budget discussed
				'type'    => 'unknown',
				'label'   => __( 'No Budget Discussed', 'wp-ai-chatbot-leadgen-pro' ),
				'details' => array(),
			);
		}

		// Convert to 0-100 scale
		$normalized_score = min( 100, $highest_score * 4 );

		return array(
			'score'   => $normalized_score,
			'type'    => $highest_indicator['type'],
			'label'   => $highest_indicator['label'],
			'details' => array( 'indicators' => $detected ),
		);
	}

	/**
	 * Score decision maker indicators.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Score result.
	 */
	private function score_decision_maker( $data ) {
		$messages = $this->get_message_text( $data );
		$name = strtolower( $data['name'] ?? '' );
		$combined = $messages . ' ' . $name;

		// Check enrichment data for job title
		$enrichment = $data['enrichment'] ?? array();
		$job_title = strtolower( $enrichment['title'] ?? $enrichment['job_title'] ?? '' );

		$combined = $combined . ' ' . $job_title;

		$detected = array();
		$highest_score = 0;
		$highest_indicator = null;

		foreach ( self::DECISION_MAKER_INDICATORS as $role_type => $config ) {
			foreach ( $config['patterns'] as $pattern ) {
				if ( stripos( $combined, $pattern ) !== false ) {
					$detected[] = array(
						'type'    => $role_type,
						'pattern' => $pattern,
						'score'   => $config['score'],
						'label'   => $config['label'],
					);

					if ( $config['score'] > $highest_score ) {
						$highest_score = $config['score'];
						$highest_indicator = array(
							'type'  => $role_type,
							'label' => $config['label'],
						);
					}
					break;
				}
			}
		}

		if ( empty( $detected ) ) {
			return array(
				'score'   => 30, // Default when unknown
				'type'    => 'unknown',
				'label'   => __( 'Unknown Role', 'wp-ai-chatbot-leadgen-pro' ),
				'details' => array(),
			);
		}

		// Convert to 0-100 scale
		$normalized_score = min( 100, $highest_score * 4 );

		return array(
			'score'   => $normalized_score,
			'type'    => $highest_indicator['type'],
			'label'   => $highest_indicator['label'],
			'details' => array( 'indicators' => $detected, 'job_title' => $job_title ?: null ),
		);
	}

	/**
	 * Score timeline indicators.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Score result.
	 */
	private function score_timeline( $data ) {
		$messages = $this->get_message_text( $data );

		$detected = null;
		$highest_score = 0;

		foreach ( self::TIMELINE_INDICATORS as $timeline_type => $config ) {
			foreach ( $config['patterns'] as $pattern ) {
				if ( stripos( $messages, $pattern ) !== false ) {
					if ( $config['score'] > $highest_score ) {
						$highest_score = $config['score'];
						$detected = array(
							'type'    => $timeline_type,
							'pattern' => $pattern,
							'label'   => $config['label'],
						);
					}
				}
			}
		}

		if ( ! $detected ) {
			return array(
				'score'   => 25, // Default when no timeline mentioned
				'type'    => 'unknown',
				'label'   => __( 'No Timeline Mentioned', 'wp-ai-chatbot-leadgen-pro' ),
				'details' => array(),
			);
		}

		// Convert to 0-100 scale
		$normalized_score = min( 100, $highest_score * 4 );

		return array(
			'score'   => $normalized_score,
			'type'    => $detected['type'],
			'label'   => $detected['label'],
			'details' => array( 'pattern' => $detected['pattern'] ),
		);
	}

	/**
	 * Score data completeness.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return array Score result.
	 */
	private function score_completeness( $data ) {
		$fields = array(
			'name'    => 15,
			'email'   => 25,
			'phone'   => 20,
			'company' => 20,
			'message' => 10,
		);

		$total_weight = array_sum( $fields );
		$achieved = 0;
		$provided = array();
		$missing = array();

		foreach ( $fields as $field => $weight ) {
			if ( ! empty( $data[ $field ] ) ) {
				$achieved += $weight;
				$provided[] = $field;
			} else {
				$missing[] = $field;
			}
		}

		// Check for enrichment data
		$enrichment = $data['enrichment'] ?? array();
		if ( ! empty( $enrichment ) ) {
			$achieved += 10; // Bonus for enriched data
			$provided[] = 'enrichment';
		}

		$score = min( 100, round( ( $achieved / $total_weight ) * 100 ) );

		return array(
			'score'   => $score,
			'type'    => $score >= 80 ? 'complete' : ( $score >= 50 ? 'partial' : 'minimal' ),
			'label'   => sprintf(
				/* translators: %d: number of fields provided */
				__( '%d of %d fields provided', 'wp-ai-chatbot-leadgen-pro' ),
				count( $provided ),
				count( $fields )
			),
			'details' => array(
				'provided' => $provided,
				'missing'  => $missing,
			),
		);
	}

	/**
	 * Get combined message text.
	 *
	 * @since 1.0.0
	 * @param array $data Lead data.
	 * @return string Combined message text.
	 */
	private function get_message_text( $data ) {
		$messages = $data['messages'] ?? array();
		$text = '';

		foreach ( $messages as $message ) {
			if ( ( $message['role'] ?? '' ) === 'user' ) {
				$text .= ' ' . ( $message['content'] ?? '' );
			}
		}

		// Add lead message field if present
		if ( ! empty( $data['message'] ) ) {
			$text .= ' ' . $data['message'];
		}

		return strtolower( trim( $text ) );
	}

	/**
	 * Determine qualification level.
	 *
	 * @since 1.0.0
	 * @param int   $score     Total score.
	 * @param array $breakdown Score breakdown.
	 * @return array Qualification info.
	 */
	private function determine_qualification_level( $score, $breakdown ) {
		// Check for disqualifying factors
		$email_type = $breakdown['email']['type'] ?? 'unknown';
		if ( $email_type === 'disposable' ) {
			return array(
				'level'       => 'disqualified',
				'label'       => __( 'Disqualified', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Disposable email address detected', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#ef4444',
			);
		}

		// Check for high-value indicators
		$decision_type = $breakdown['decision_maker']['type'] ?? 'unknown';
		$timeline_type = $breakdown['timeline']['type'] ?? 'unknown';
		$budget_type = $breakdown['budget']['type'] ?? 'unknown';

		$has_decision_authority = in_array( $decision_type, array( 'c_level', 'vp_director', 'owner_founder', 'decision_authority' ), true );
		$has_urgent_timeline = in_array( $timeline_type, array( 'immediate', 'short_term' ), true );
		$has_good_budget = in_array( $budget_type, array( 'enterprise_budget', 'professional_budget', 'standard_budget' ), true );

		if ( $score >= 80 || ( $has_decision_authority && $has_urgent_timeline ) ) {
			return array(
				'level'       => 'highly_qualified',
				'label'       => __( 'Highly Qualified', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Strong fit with decision-making authority', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#22c55e',
			);
		} elseif ( $score >= 60 || ( $has_decision_authority || ( $has_urgent_timeline && $has_good_budget ) ) ) {
			return array(
				'level'       => 'qualified',
				'label'       => __( 'Qualified', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Good fit with relevant qualification criteria', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#84cc16',
			);
		} elseif ( $score >= 40 ) {
			return array(
				'level'       => 'marketing_qualified',
				'label'       => __( 'Marketing Qualified', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Shows interest but needs more information', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#eab308',
			);
		} elseif ( $score >= 20 ) {
			return array(
				'level'       => 'unqualified',
				'label'       => __( 'Unqualified', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Does not meet qualification criteria', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#f97316',
			);
		} else {
			return array(
				'level'       => 'insufficient_data',
				'label'       => __( 'Insufficient Data', 'wp-ai-chatbot-leadgen-pro' ),
				'description' => __( 'Not enough information to qualify', 'wp-ai-chatbot-leadgen-pro' ),
				'color'       => '#9ca3af',
			);
		}
	}

	/**
	 * Extract qualification signals.
	 *
	 * @since 1.0.0
	 * @param array $breakdown Score breakdown.
	 * @return array Signals.
	 */
	private function extract_signals( $breakdown ) {
		$signals = array();

		// Email signal
		$email_type = $breakdown['email']['type'] ?? 'unknown';
		if ( $email_type === 'business' ) {
			$signals[] = array(
				'type'     => 'positive',
				'signal'   => 'business_email',
				'label'    => __( 'Business email address', 'wp-ai-chatbot-leadgen-pro' ),
				'category' => 'email',
			);
		} elseif ( $email_type === 'personal' ) {
			$signals[] = array(
				'type'     => 'neutral',
				'signal'   => 'personal_email',
				'label'    => __( 'Personal email address', 'wp-ai-chatbot-leadgen-pro' ),
				'category' => 'email',
			);
		} elseif ( $email_type === 'disposable' ) {
			$signals[] = array(
				'type'     => 'negative',
				'signal'   => 'disposable_email',
				'label'    => __( 'Disposable email detected', 'wp-ai-chatbot-leadgen-pro' ),
				'category' => 'email',
			);
		}

		// Company size signal
		$size_type = $breakdown['company_size']['type'] ?? 'unknown';
		if ( in_array( $size_type, array( 'enterprise', 'mid_market' ), true ) ) {
			$signals[] = array(
				'type'     => 'positive',
				'signal'   => $size_type . '_company',
				'label'    => $breakdown['company_size']['label'],
				'category' => 'company',
			);
		}

		// Decision maker signal
		$decision_type = $breakdown['decision_maker']['type'] ?? 'unknown';
		if ( in_array( $decision_type, array( 'c_level', 'vp_director', 'owner_founder', 'decision_authority' ), true ) ) {
			$signals[] = array(
				'type'     => 'high_value',
				'signal'   => 'decision_maker',
				'label'    => $breakdown['decision_maker']['label'],
				'category' => 'authority',
			);
		} elseif ( $decision_type === 'no_authority' ) {
			$signals[] = array(
				'type'     => 'negative',
				'signal'   => 'no_authority',
				'label'    => __( 'Not a decision maker', 'wp-ai-chatbot-leadgen-pro' ),
				'category' => 'authority',
			);
		}

		// Timeline signal
		$timeline_type = $breakdown['timeline']['type'] ?? 'unknown';
		if ( in_array( $timeline_type, array( 'immediate', 'short_term' ), true ) ) {
			$signals[] = array(
				'type'     => 'high_value',
				'signal'   => 'urgent_timeline',
				'label'    => $breakdown['timeline']['label'],
				'category' => 'timeline',
			);
		} elseif ( $timeline_type === 'just_looking' ) {
			$signals[] = array(
				'type'     => 'negative',
				'signal'   => 'no_timeline',
				'label'    => __( 'Just browsing, no timeline', 'wp-ai-chatbot-leadgen-pro' ),
				'category' => 'timeline',
			);
		}

		// Budget signal
		$budget_type = $breakdown['budget']['type'] ?? 'unknown';
		if ( in_array( $budget_type, array( 'enterprise_budget', 'professional_budget' ), true ) ) {
			$signals[] = array(
				'type'     => 'high_value',
				'signal'   => 'good_budget',
				'label'    => $breakdown['budget']['label'],
				'category' => 'budget',
			);
		} elseif ( $budget_type === 'limited_budget' ) {
			$signals[] = array(
				'type'     => 'negative',
				'signal'   => 'limited_budget',
				'label'    => __( 'Limited budget indicated', 'wp-ai-chatbot-leadgen-pro' ),
				'category' => 'budget',
			);
		}

		return $signals;
	}

	/**
	 * Get BANT analysis.
	 *
	 * @since 1.0.0
	 * @param array $breakdown Score breakdown.
	 * @return array BANT analysis.
	 */
	private function get_bant_analysis( $breakdown ) {
		return array(
			'budget' => array(
				'identified' => ( $breakdown['budget']['type'] ?? 'unknown' ) !== 'unknown',
				'score'      => $breakdown['budget']['score'],
				'label'      => $breakdown['budget']['label'],
			),
			'authority' => array(
				'identified' => ( $breakdown['decision_maker']['type'] ?? 'unknown' ) !== 'unknown',
				'score'      => $breakdown['decision_maker']['score'],
				'label'      => $breakdown['decision_maker']['label'],
			),
			'need' => array(
				'identified' => true, // If they're chatting, there's some need
				'score'      => min( 100, ( $breakdown['company_size']['score'] + $breakdown['completeness']['score'] ) / 2 ),
				'label'      => __( 'Interest demonstrated via conversation', 'wp-ai-chatbot-leadgen-pro' ),
			),
			'timeline' => array(
				'identified' => ( $breakdown['timeline']['type'] ?? 'unknown' ) !== 'unknown',
				'score'      => $breakdown['timeline']['score'],
				'label'      => $breakdown['timeline']['label'],
			),
		);
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
				$data = array_merge( $data, $lead );
			}
		}

		// Get conversation messages
		if ( ! empty( $data['conversation_id'] ) ) {
			if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager' ) ) {
				$manager = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager();
				$messages = $manager->get_messages( $data['conversation_id'] );
				$data['messages'] = $messages;
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

	/**
	 * Check if email is a business email.
	 *
	 * @since 1.0.0
	 * @param string $email Email address.
	 * @return bool True if business email.
	 */
	public function is_business_email( $email ) {
		$result = $this->score_email( array( 'email' => $email ) );
		return $result['type'] === 'business';
	}

	/**
	 * Get scoring criteria configuration.
	 *
	 * @since 1.0.0
	 * @return array Scoring criteria.
	 */
	public function get_scoring_criteria() {
		return array(
			'email_types'            => self::FREE_EMAIL_DOMAINS,
			'company_size_indicators' => self::COMPANY_SIZE_INDICATORS,
			'budget_indicators'      => self::BUDGET_INDICATORS,
			'decision_maker_indicators' => self::DECISION_MAKER_INDICATORS,
			'timeline_indicators'    => self::TIMELINE_INDICATORS,
		);
	}
}

