<?php
/**
 * Lead Grader.
 *
 * Assigns grades to leads based on composite scores and custom rules.
 * Supports Hot A+, Warm A, Qualified B, Engaged C, Cold D grades with
 * grade history tracking and automation triggers.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Lead_Grader {

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
	private $lead_storage;

	/**
	 * Grade history table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $history_table;

	/**
	 * Grade definitions.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const GRADES = array(
		'A+' => array(
			'name'        => 'Hot Lead',
			'description' => 'Highly engaged, high intent, well-qualified - ready for immediate sales contact',
			'color'       => '#22c55e',
			'bg_color'    => '#dcfce7',
			'priority'    => 'urgent',
			'min_score'   => 85,
			'max_score'   => 100,
			'sla_hours'   => 1,
			'actions'     => array( 'notify_sales', 'sync_crm_priority', 'schedule_call' ),
		),
		'A' => array(
			'name'        => 'Warm Lead',
			'description' => 'Strong engagement and qualification - prioritize for follow-up',
			'color'       => '#84cc16',
			'bg_color'    => '#ecfccb',
			'priority'    => 'high',
			'min_score'   => 70,
			'max_score'   => 84,
			'sla_hours'   => 4,
			'actions'     => array( 'notify_sales', 'sync_crm', 'send_pricing' ),
		),
		'B' => array(
			'name'        => 'Qualified Lead',
			'description' => 'Good potential, meets qualification criteria - worth nurturing',
			'color'       => '#eab308',
			'bg_color'    => '#fef9c3',
			'priority'    => 'medium',
			'min_score'   => 55,
			'max_score'   => 69,
			'sla_hours'   => 24,
			'actions'     => array( 'sync_crm', 'add_to_nurture', 'send_case_studies' ),
		),
		'C' => array(
			'name'        => 'Engaged Lead',
			'description' => 'Shows interest but needs more qualification or nurturing',
			'color'       => '#f97316',
			'bg_color'    => '#ffedd5',
			'priority'    => 'normal',
			'min_score'   => 40,
			'max_score'   => 54,
			'sla_hours'   => 72,
			'actions'     => array( 'add_to_nurture', 'send_educational_content' ),
		),
		'D' => array(
			'name'        => 'Cold Lead',
			'description' => 'Low engagement or poor qualification - long-term nurture',
			'color'       => '#6b7280',
			'bg_color'    => '#f3f4f6',
			'priority'    => 'low',
			'min_score'   => 25,
			'max_score'   => 39,
			'sla_hours'   => 168, // 7 days
			'actions'     => array( 'add_to_cold_nurture' ),
		),
		'F' => array(
			'name'        => 'Unqualified',
			'description' => 'Does not meet minimum criteria - likely not a good fit',
			'color'       => '#ef4444',
			'bg_color'    => '#fef2f2',
			'priority'    => 'none',
			'min_score'   => 0,
			'max_score'   => 24,
			'sla_hours'   => null,
			'actions'     => array(),
		),
		'DQ' => array(
			'name'        => 'Disqualified',
			'description' => 'Manually or automatically disqualified - do not contact',
			'color'       => '#991b1b',
			'bg_color'    => '#fee2e2',
			'priority'    => 'disqualified',
			'min_score'   => null,
			'max_score'   => null,
			'sla_hours'   => null,
			'actions'     => array( 'remove_from_sequences' ),
		),
	);

	/**
	 * Grade upgrade rules.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const UPGRADE_RULES = array(
		// Instant upgrade to A+ if these conditions are met
		'instant_hot' => array(
			'conditions' => array(
				'meeting_booked'      => true,
				'min_score'           => 60,
			),
			'target_grade' => 'A+',
		),
		// Upgrade to A if decision maker with good intent
		'decision_maker_high_intent' => array(
			'conditions' => array(
				'is_decision_maker'   => true,
				'intent_score_min'    => 60,
				'min_score'           => 50,
			),
			'target_grade' => 'A',
		),
		// Upgrade to B if pricing viewed multiple times
		'pricing_interest' => array(
			'conditions' => array(
				'pricing_views_min'   => 3,
				'min_score'           => 40,
			),
			'target_grade' => 'B',
		),
	);

	/**
	 * Grade downgrade rules.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const DOWNGRADE_RULES = array(
		// Downgrade to DQ if disposable email
		'disposable_email' => array(
			'conditions' => array(
				'is_disposable_email' => true,
			),
			'target_grade' => 'DQ',
		),
		// Downgrade to F if spam behavior
		'spam_behavior' => array(
			'conditions' => array(
				'is_spam' => true,
			),
			'target_grade' => 'F',
		),
		// Downgrade if no activity for 30 days
		'inactive' => array(
			'conditions' => array(
				'days_inactive_min' => 30,
				'current_grade_in'  => array( 'A+', 'A', 'B' ),
			),
			'grade_decrease' => 2, // Drop 2 grades
		),
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->history_table = $wpdb->prefix . 'ai_chatbot_lead_grade_history';

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
			$this->lead_storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
		}

		$this->maybe_create_tables();
		$this->init_hooks();
	}

	/**
	 * Create history table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->history_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT(20) UNSIGNED NOT NULL,
			previous_grade VARCHAR(10) DEFAULT NULL,
			new_grade VARCHAR(10) NOT NULL,
			previous_score INT(11) DEFAULT NULL,
			new_score INT(11) NOT NULL,
			change_reason VARCHAR(255) DEFAULT NULL,
			rule_applied VARCHAR(100) DEFAULT NULL,
			changed_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY new_grade (new_grade),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Grade on lead scored
		add_action( 'wp_ai_chatbot_lead_scored', array( $this, 'grade_lead' ), 10, 2 );

		// AJAX handlers
		add_action( 'wp_ajax_wp_ai_chatbot_get_lead_grade', array( $this, 'ajax_get_grade' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_set_lead_grade', array( $this, 'ajax_set_grade' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_get_grade_history', array( $this, 'ajax_get_history' ) );
	}

	/**
	 * Grade a lead based on score and rules.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id    Lead ID.
	 * @param array $score_data Score data from Lead Scorer.
	 * @return array Grade result.
	 */
	public function grade_lead( $lead_id, $score_data = null ) {
		// Get score data if not provided
		if ( ! $score_data ) {
			$score_data = $this->get_lead_score_data( $lead_id );
		}

		if ( ! $score_data ) {
			return null;
		}

		$score = $score_data['composite_score'] ?? 0;

		// Get current grade
		$current_grade = $this->get_current_grade( $lead_id );

		// Calculate grade from score
		$calculated_grade = $this->calculate_grade_from_score( $score );

		// Apply upgrade rules
		$upgraded_grade = $this->apply_upgrade_rules( $calculated_grade, $score_data, $lead_id );

		// Apply downgrade rules
		$final_grade = $this->apply_downgrade_rules( $upgraded_grade, $score_data, $lead_id, $current_grade );

		// Get grade definition
		$grade_def = self::GRADES[ $final_grade ] ?? self::GRADES['F'];

		// Check if grade changed
		$grade_changed = $current_grade !== $final_grade;

		// Build result
		$result = array(
			'lead_id'     => $lead_id,
			'grade'       => $final_grade,
			'name'        => $grade_def['name'],
			'description' => $grade_def['description'],
			'color'       => $grade_def['color'],
			'bg_color'    => $grade_def['bg_color'],
			'priority'    => $grade_def['priority'],
			'sla_hours'   => $grade_def['sla_hours'],
			'score'       => $score,
			'changed'     => $grade_changed,
			'previous'    => $current_grade,
		);

		// Store grade and history
		$this->store_grade( $lead_id, $final_grade, $score, $current_grade, $score_data );

		// Execute grade actions if grade changed
		if ( $grade_changed ) {
			$this->execute_grade_actions( $lead_id, $final_grade, $current_grade, $grade_def, $score_data );
		}

		return $result;
	}

	/**
	 * Calculate grade from score.
	 *
	 * @since 1.0.0
	 * @param int $score Lead score.
	 * @return string Grade letter.
	 */
	private function calculate_grade_from_score( $score ) {
		foreach ( self::GRADES as $letter => $def ) {
			if ( $def['min_score'] === null ) {
				continue;
			}

			if ( $score >= $def['min_score'] && $score <= $def['max_score'] ) {
				return $letter;
			}
		}

		return 'F';
	}

	/**
	 * Apply upgrade rules.
	 *
	 * @since 1.0.0
	 * @param string $current_grade Current calculated grade.
	 * @param array  $score_data    Score data.
	 * @param int    $lead_id       Lead ID.
	 * @return string Potentially upgraded grade.
	 */
	private function apply_upgrade_rules( $current_grade, $score_data, $lead_id ) {
		$behavior = $score_data['scores']['behavioral']['breakdown'] ?? array();
		$intent = $score_data['scores']['intent'] ?? array();
		$qualification = $score_data['scores']['qualification'] ?? array();
		$score = $score_data['composite_score'] ?? 0;

		// Get behavior data
		$behavior_data = array();
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker' ) ) {
			$tracker = new WP_AI_Chatbot_LeadGen_Pro_Behavior_Tracker();
			$lead = $this->lead_storage ? $this->lead_storage->get( $lead_id ) : null;
			if ( $lead && ! empty( $lead['session_id'] ) ) {
				$behavior_data = $tracker->get_behavior( $lead['session_id'] ) ?: array();
			}
		}

		// Check each upgrade rule
		foreach ( self::UPGRADE_RULES as $rule_name => $rule ) {
			$conditions = $rule['conditions'];
			$all_met = true;

			// Check meeting booked
			if ( isset( $conditions['meeting_booked'] ) && $conditions['meeting_booked'] ) {
				if ( intval( $behavior_data['meetings_booked'] ?? 0 ) === 0 ) {
					$all_met = false;
				}
			}

			// Check minimum score
			if ( isset( $conditions['min_score'] ) ) {
				if ( $score < $conditions['min_score'] ) {
					$all_met = false;
				}
			}

			// Check decision maker
			if ( isset( $conditions['is_decision_maker'] ) && $conditions['is_decision_maker'] ) {
				$decision_type = $qualification['breakdown']['decision_maker']['type'] ?? 'unknown';
				$decision_maker_types = array( 'c_level', 'vp_director', 'owner_founder', 'decision_authority' );
				if ( ! in_array( $decision_type, $decision_maker_types, true ) ) {
					$all_met = false;
				}
			}

			// Check intent score
			if ( isset( $conditions['intent_score_min'] ) ) {
				$intent_score = $intent['score'] ?? 0;
				if ( $intent_score < $conditions['intent_score_min'] ) {
					$all_met = false;
				}
			}

			// Check pricing views
			if ( isset( $conditions['pricing_views_min'] ) ) {
				$pricing_views = intval( $behavior_data['pricing_page_views'] ?? 0 );
				if ( $pricing_views < $conditions['pricing_views_min'] ) {
					$all_met = false;
				}
			}

			// If all conditions met, upgrade
			if ( $all_met && isset( $rule['target_grade'] ) ) {
				$target_grade = $rule['target_grade'];
				if ( $this->is_grade_higher( $target_grade, $current_grade ) ) {
					$this->logger->debug( 'Grade upgrade rule applied', array(
						'lead_id'    => $lead_id,
						'rule'       => $rule_name,
						'from_grade' => $current_grade,
						'to_grade'   => $target_grade,
					) );
					$current_grade = $target_grade;
				}
			}
		}

		return $current_grade;
	}

	/**
	 * Apply downgrade rules.
	 *
	 * @since 1.0.0
	 * @param string $current_grade   Current grade after upgrades.
	 * @param array  $score_data      Score data.
	 * @param int    $lead_id         Lead ID.
	 * @param string $previous_grade  Previous stored grade.
	 * @return string Potentially downgraded grade.
	 */
	private function apply_downgrade_rules( $current_grade, $score_data, $lead_id, $previous_grade ) {
		$qualification = $score_data['scores']['qualification'] ?? array();

		// Check disposable email
		$email_type = $qualification['breakdown']['email']['type'] ?? 'unknown';
		if ( $email_type === 'disposable' ) {
			return 'DQ';
		}

		// Check spam behavior
		$modifiers = $score_data['modifiers'] ?? array();
		if ( isset( $modifiers['applied']['spam_behavior'] ) ) {
			return 'F';
		}

		// Check inactivity (only for existing leads with stored grade)
		if ( $previous_grade && $this->lead_storage ) {
			$lead = $this->lead_storage->get( $lead_id );
			if ( $lead ) {
				$last_activity = strtotime( $lead['updated_at'] ?? $lead['created_at'] );
				$days_inactive = ( time() - $last_activity ) / DAY_IN_SECONDS;

				foreach ( self::DOWNGRADE_RULES as $rule_name => $rule ) {
					if ( ! isset( $rule['conditions']['days_inactive_min'] ) ) {
						continue;
					}

					if ( $days_inactive >= $rule['conditions']['days_inactive_min'] ) {
						$current_grades = $rule['conditions']['current_grade_in'] ?? array();
						if ( in_array( $previous_grade, $current_grades, true ) ) {
							$grade_decrease = $rule['grade_decrease'] ?? 1;
							$current_grade = $this->decrease_grade( $previous_grade, $grade_decrease );

							$this->logger->debug( 'Grade downgrade rule applied', array(
								'lead_id'       => $lead_id,
								'rule'          => $rule_name,
								'days_inactive' => round( $days_inactive ),
								'from_grade'    => $previous_grade,
								'to_grade'      => $current_grade,
							) );
						}
					}
				}
			}
		}

		return $current_grade;
	}

	/**
	 * Check if grade A is higher than grade B.
	 *
	 * @since 1.0.0
	 * @param string $grade_a Grade A.
	 * @param string $grade_b Grade B.
	 * @return bool True if grade A is higher.
	 */
	private function is_grade_higher( $grade_a, $grade_b ) {
		$order = array( 'A+' => 6, 'A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'F' => 1, 'DQ' => 0 );
		return ( $order[ $grade_a ] ?? 0 ) > ( $order[ $grade_b ] ?? 0 );
	}

	/**
	 * Decrease grade by N levels.
	 *
	 * @since 1.0.0
	 * @param string $grade    Current grade.
	 * @param int    $decrease Number of levels to decrease.
	 * @return string New grade.
	 */
	private function decrease_grade( $grade, $decrease ) {
		$grades = array( 'A+', 'A', 'B', 'C', 'D', 'F' );
		$current_index = array_search( $grade, $grades, true );

		if ( $current_index === false ) {
			return 'F';
		}

		$new_index = min( $current_index + $decrease, count( $grades ) - 1 );
		return $grades[ $new_index ];
	}

	/**
	 * Store grade and history.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id        Lead ID.
	 * @param string $grade          New grade.
	 * @param int    $score          Score.
	 * @param string $previous_grade Previous grade.
	 * @param array  $score_data     Score data.
	 */
	private function store_grade( $lead_id, $grade, $score, $previous_grade, $score_data ) {
		global $wpdb;

		// Update lead meta with grade
		if ( $this->lead_storage ) {
			$this->lead_storage->update_meta( $lead_id, 'grade', $grade );
			$this->lead_storage->update_meta( $lead_id, 'grade_updated_at', current_time( 'mysql' ) );
		}

		// Record history
		$reason = '';
		if ( $previous_grade !== $grade ) {
			if ( $this->is_grade_higher( $grade, $previous_grade ?: 'F' ) ) {
				$reason = 'Grade upgraded';
			} else {
				$reason = 'Grade downgraded';
			}
		} else {
			$reason = 'Score updated';
		}

		$wpdb->insert(
			$this->history_table,
			array(
				'lead_id'        => $lead_id,
				'previous_grade' => $previous_grade,
				'new_grade'      => $grade,
				'previous_score' => $score_data['previous_score'] ?? null,
				'new_score'      => $score,
				'change_reason'  => $reason,
				'changed_by'     => get_current_user_id() ?: null,
				'created_at'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Execute grade-based actions.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id        Lead ID.
	 * @param string $new_grade      New grade.
	 * @param string $previous_grade Previous grade.
	 * @param array  $grade_def      Grade definition.
	 * @param array  $score_data     Score data.
	 */
	private function execute_grade_actions( $lead_id, $new_grade, $previous_grade, $grade_def, $score_data ) {
		$actions = $grade_def['actions'] ?? array();

		// Trigger general action
		do_action( 'wp_ai_chatbot_lead_grade_changed', $lead_id, $new_grade, $previous_grade, $score_data );

		// Check for upgrade to hot lead
		if ( $new_grade === 'A+' && $previous_grade !== 'A+' ) {
			do_action( 'wp_ai_chatbot_hot_lead_detected', $lead_id, $score_data );
		}

		// Execute specific actions
		foreach ( $actions as $action ) {
			switch ( $action ) {
				case 'notify_sales':
					$this->notify_sales_team( $lead_id, $new_grade, $score_data );
					break;

				case 'sync_crm':
				case 'sync_crm_priority':
					do_action( 'wp_ai_chatbot_sync_lead_to_crm', $lead_id, array(
						'priority' => $action === 'sync_crm_priority',
						'grade'    => $new_grade,
					) );
					break;

				case 'add_to_nurture':
				case 'add_to_cold_nurture':
					$sequence = $action === 'add_to_cold_nurture' ? 'cold_nurture' : 'standard_nurture';
					do_action( 'wp_ai_chatbot_add_to_email_sequence', $lead_id, $sequence );
					break;

				case 'send_pricing':
					do_action( 'wp_ai_chatbot_send_automated_email', $lead_id, 'pricing_info' );
					break;

				case 'send_case_studies':
					do_action( 'wp_ai_chatbot_send_automated_email', $lead_id, 'case_studies' );
					break;

				case 'send_educational_content':
					do_action( 'wp_ai_chatbot_send_automated_email', $lead_id, 'educational' );
					break;

				case 'schedule_call':
					do_action( 'wp_ai_chatbot_schedule_sales_call', $lead_id );
					break;

				case 'remove_from_sequences':
					do_action( 'wp_ai_chatbot_remove_from_all_sequences', $lead_id );
					break;
			}
		}
	}

	/**
	 * Notify sales team about grade change.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id    Lead ID.
	 * @param string $grade      New grade.
	 * @param array  $score_data Score data.
	 */
	private function notify_sales_team( $lead_id, $grade, $score_data ) {
		$recipients = $this->config->get( 'lead_notification_emails', array() );

		if ( empty( $recipients ) ) {
			$recipients = array( get_option( 'admin_email' ) );
		}

		$lead = $this->lead_storage ? $this->lead_storage->get( $lead_id ) : null;
		if ( ! $lead ) {
			return;
		}

		$grade_def = self::GRADES[ $grade ] ?? self::GRADES['B'];

		$subject = sprintf(
			/* translators: %1$s: grade name, %2$s: lead name or email */
			__( '[%1$s Lead] %2$s', 'wp-ai-chatbot-leadgen-pro' ),
			$grade_def['name'],
			$lead['name'] ?: $lead['email']
		);

		$message = $this->build_notification_email( $lead, $grade, $grade_def, $score_data );

		foreach ( $recipients as $recipient ) {
			wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}

		$this->logger->info( 'Sales team notified of grade change', array(
			'lead_id' => $lead_id,
			'grade'   => $grade,
		) );
	}

	/**
	 * Build notification email.
	 *
	 * @since 1.0.0
	 * @param array  $lead       Lead data.
	 * @param string $grade      Grade letter.
	 * @param array  $grade_def  Grade definition.
	 * @param array  $score_data Score data.
	 * @return string Email HTML.
	 */
	private function build_notification_email( $lead, $grade, $grade_def, $score_data ) {
		$admin_url = admin_url( 'admin.php?page=wp-ai-chatbot-leads&lead_id=' . $lead['id'] );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; }
				.container { max-width: 600px; margin: 0 auto; padding: 20px; }
				.header { background: <?php echo esc_attr( $grade_def['bg_color'] ); ?>; padding: 20px; border-radius: 8px 8px 0 0; }
				.grade-badge { display: inline-block; background: <?php echo esc_attr( $grade_def['color'] ); ?>; color: white; padding: 8px 16px; border-radius: 20px; font-weight: bold; }
				.content { background: #f9fafb; padding: 20px; border-radius: 0 0 8px 8px; }
				.field { margin-bottom: 12px; }
				.field-label { color: #6b7280; font-size: 12px; text-transform: uppercase; }
				.field-value { color: #1f2937; font-size: 14px; }
				.score-bar { background: #e5e7eb; border-radius: 4px; height: 8px; margin-top: 4px; }
				.score-fill { background: <?php echo esc_attr( $grade_def['color'] ); ?>; border-radius: 4px; height: 8px; }
				.button { display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin-top: 16px; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<span class="grade-badge"><?php echo esc_html( $grade ); ?></span>
					<h2 style="margin: 12px 0 4px;"><?php echo esc_html( $grade_def['name'] ); ?></h2>
					<p style="margin: 0; color: #4b5563;"><?php echo esc_html( $grade_def['description'] ); ?></p>
				</div>
				<div class="content">
					<div class="field">
						<div class="field-label"><?php esc_html_e( 'Name', 'wp-ai-chatbot-leadgen-pro' ); ?></div>
						<div class="field-value"><?php echo esc_html( $lead['name'] ?: '-' ); ?></div>
					</div>
					<div class="field">
						<div class="field-label"><?php esc_html_e( 'Email', 'wp-ai-chatbot-leadgen-pro' ); ?></div>
						<div class="field-value"><?php echo esc_html( $lead['email'] ); ?></div>
					</div>
					<?php if ( ! empty( $lead['phone'] ) ) : ?>
					<div class="field">
						<div class="field-label"><?php esc_html_e( 'Phone', 'wp-ai-chatbot-leadgen-pro' ); ?></div>
						<div class="field-value"><?php echo esc_html( $lead['phone'] ); ?></div>
					</div>
					<?php endif; ?>
					<?php if ( ! empty( $lead['company'] ) ) : ?>
					<div class="field">
						<div class="field-label"><?php esc_html_e( 'Company', 'wp-ai-chatbot-leadgen-pro' ); ?></div>
						<div class="field-value"><?php echo esc_html( $lead['company'] ); ?></div>
					</div>
					<?php endif; ?>
					<div class="field">
						<div class="field-label"><?php esc_html_e( 'Lead Score', 'wp-ai-chatbot-leadgen-pro' ); ?></div>
						<div class="field-value"><?php echo esc_html( $score_data['composite_score'] ?? $lead['score'] ); ?>/100</div>
						<div class="score-bar">
							<div class="score-fill" style="width: <?php echo esc_attr( $score_data['composite_score'] ?? $lead['score'] ); ?>%;"></div>
						</div>
					</div>
					<?php if ( ! empty( $grade_def['sla_hours'] ) ) : ?>
					<div class="field">
						<div class="field-label"><?php esc_html_e( 'Response SLA', 'wp-ai-chatbot-leadgen-pro' ); ?></div>
						<div class="field-value" style="color: <?php echo esc_attr( $grade_def['color'] ); ?>; font-weight: bold;">
							<?php
							if ( $grade_def['sla_hours'] < 24 ) {
								printf(
									/* translators: %d: number of hours */
									esc_html__( 'Within %d hours', 'wp-ai-chatbot-leadgen-pro' ),
									$grade_def['sla_hours']
								);
							} else {
								printf(
									/* translators: %d: number of days */
									esc_html__( 'Within %d days', 'wp-ai-chatbot-leadgen-pro' ),
									$grade_def['sla_hours'] / 24
								);
							}
							?>
						</div>
					</div>
					<?php endif; ?>
					<a href="<?php echo esc_url( $admin_url ); ?>" class="button">
						<?php esc_html_e( 'View Lead Details', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</a>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get current grade for a lead.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return string|null Current grade or null.
	 */
	public function get_current_grade( $lead_id ) {
		if ( ! $this->lead_storage ) {
			return null;
		}

		return $this->lead_storage->get_meta( $lead_id, 'grade', true );
	}

	/**
	 * Get lead score data.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array|null Score data or null.
	 */
	private function get_lead_score_data( $lead_id ) {
		if ( ! $this->lead_storage ) {
			return null;
		}

		$lead = $this->lead_storage->get( $lead_id );
		if ( ! $lead ) {
			return null;
		}

		return array(
			'composite_score' => intval( $lead['score'] ?? 0 ),
			'scores'          => array(
				'behavioral'    => array( 'score' => 0 ),
				'intent'        => array( 'score' => 0 ),
				'qualification' => array(
					'score'     => 0,
					'breakdown' => array(),
				),
			),
			'modifiers' => array(),
		);
	}

	/**
	 * Manually set grade for a lead.
	 *
	 * @since 1.0.0
	 * @param int    $lead_id Lead ID.
	 * @param string $grade   Grade to set.
	 * @param string $reason  Reason for manual grade change.
	 * @return bool True on success.
	 */
	public function set_grade( $lead_id, $grade, $reason = '' ) {
		if ( ! isset( self::GRADES[ $grade ] ) ) {
			return false;
		}

		$current_grade = $this->get_current_grade( $lead_id );
		$lead = $this->lead_storage ? $this->lead_storage->get( $lead_id ) : null;
		$score = $lead ? intval( $lead['score'] ) : 0;

		// Store with manual reason
		global $wpdb;
		$wpdb->insert(
			$this->history_table,
			array(
				'lead_id'        => $lead_id,
				'previous_grade' => $current_grade,
				'new_grade'      => $grade,
				'previous_score' => $score,
				'new_score'      => $score,
				'change_reason'  => $reason ?: __( 'Manual grade change', 'wp-ai-chatbot-leadgen-pro' ),
				'rule_applied'   => 'manual',
				'changed_by'     => get_current_user_id(),
				'created_at'     => current_time( 'mysql' ),
			)
		);

		if ( $this->lead_storage ) {
			$this->lead_storage->update_meta( $lead_id, 'grade', $grade );
			$this->lead_storage->update_meta( $lead_id, 'grade_updated_at', current_time( 'mysql' ) );
			$this->lead_storage->update_meta( $lead_id, 'grade_manual', true );
		}

		// Execute grade actions
		if ( $current_grade !== $grade ) {
			$grade_def = self::GRADES[ $grade ];
			$this->execute_grade_actions( $lead_id, $grade, $current_grade, $grade_def, array() );
		}

		return true;
	}

	/**
	 * Get grade history for a lead.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id Lead ID.
	 * @param array $args    Query arguments.
	 * @return array History records.
	 */
	public function get_history( $lead_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'  => 20,
			'offset' => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->history_table} WHERE lead_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$lead_id,
				$args['limit'],
				$args['offset']
			),
			ARRAY_A
		);

		return $history ?: array();
	}

	/**
	 * AJAX handler for getting lead grade.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_grade() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_id = intval( $_POST['lead_id'] ?? 0 );

		if ( ! $lead_id ) {
			wp_send_json_error( array( 'message' => 'Lead ID required' ), 400 );
		}

		$grade = $this->get_current_grade( $lead_id );
		$grade_def = self::GRADES[ $grade ] ?? self::GRADES['F'];

		wp_send_json_success( array(
			'grade'      => $grade,
			'definition' => $grade_def,
		) );
	}

	/**
	 * AJAX handler for setting lead grade.
	 *
	 * @since 1.0.0
	 */
	public function ajax_set_grade() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_id = intval( $_POST['lead_id'] ?? 0 );
		$grade = sanitize_text_field( $_POST['grade'] ?? '' );
		$reason = sanitize_text_field( $_POST['reason'] ?? '' );

		if ( ! $lead_id || ! $grade ) {
			wp_send_json_error( array( 'message' => 'Lead ID and grade required' ), 400 );
		}

		$result = $this->set_grade( $lead_id, $grade, $reason );

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Grade updated' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Invalid grade' ), 400 );
		}
	}

	/**
	 * AJAX handler for getting grade history.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_history() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_id = intval( $_POST['lead_id'] ?? 0 );

		if ( ! $lead_id ) {
			wp_send_json_error( array( 'message' => 'Lead ID required' ), 400 );
		}

		$history = $this->get_history( $lead_id );

		wp_send_json_success( array( 'history' => $history ) );
	}

	/**
	 * Get all grade definitions.
	 *
	 * @since 1.0.0
	 * @return array Grade definitions.
	 */
	public function get_grade_definitions() {
		return self::GRADES;
	}

	/**
	 * Get grade statistics.
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

		$meta_table = $wpdb->prefix . 'ai_chatbot_lead_meta';
		$leads_table = $wpdb->prefix . 'ai_chatbot_leads';

		// Get grade distribution
		$distribution = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.meta_value as grade, COUNT(*) as count
			FROM {$meta_table} m
			JOIN {$leads_table} l ON m.lead_id = l.id
			WHERE m.meta_key = 'grade'
			AND l.created_at BETWEEN %s AND %s
			GROUP BY m.meta_value
			ORDER BY count DESC",
			$args['date_from'],
			$args['date_to'] . ' 23:59:59'
		), ARRAY_A );

		$grade_counts = array();
		foreach ( $distribution as $row ) {
			$grade_counts[ $row['grade'] ] = intval( $row['count'] );
		}

		return array(
			'distribution' => $grade_counts,
			'total'        => array_sum( $grade_counts ),
		);
	}
}

