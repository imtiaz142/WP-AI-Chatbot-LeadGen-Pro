<?php
/**
 * Enrichment Queue.
 *
 * Manages asynchronous lead enrichment through a background job queue.
 * Handles batch processing, retry logic, and prioritization.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Enrichment_Queue {

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
	 * Lead enricher instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Enricher
	 */
	private $enricher;

	/**
	 * Queue table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $table_name;

	/**
	 * Job statuses.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const STATUSES = array(
		'pending'    => 'Pending',
		'processing' => 'Processing',
		'completed'  => 'Completed',
		'failed'     => 'Failed',
		'cancelled'  => 'Cancelled',
	);

	/**
	 * Job priorities.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const PRIORITIES = array(
		'urgent' => 1,   // Hot leads, immediate processing
		'high'   => 2,   // High-scored leads
		'normal' => 3,   // Standard leads
		'low'    => 4,   // Bulk/batch enrichment
	);

	/**
	 * Maximum retries.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Batch size for processing.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const BATCH_SIZE = 10;

	/**
	 * Lock timeout in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const LOCK_TIMEOUT = 300; // 5 minutes

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->table_name = $wpdb->prefix . 'ai_chatbot_enrichment_queue';

		$this->maybe_create_table();
		$this->init_hooks();
	}

	/**
	 * Create queue table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT(20) UNSIGNED NOT NULL,
			email VARCHAR(255) NOT NULL,
			providers TEXT DEFAULT NULL,
			priority TINYINT(1) DEFAULT 3,
			status VARCHAR(20) DEFAULT 'pending',
			attempts INT(11) DEFAULT 0,
			max_attempts INT(11) DEFAULT 3,
			result TEXT DEFAULT NULL,
			error_message TEXT DEFAULT NULL,
			locked_at DATETIME DEFAULT NULL,
			locked_by VARCHAR(50) DEFAULT NULL,
			scheduled_at DATETIME DEFAULT NULL,
			started_at DATETIME DEFAULT NULL,
			completed_at DATETIME DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY status (status),
			KEY priority (priority),
			KEY scheduled_at (scheduled_at),
			KEY email (email(191))
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
		// Register cron event
		add_action( 'wp_ai_chatbot_process_enrichment_queue', array( $this, 'process_queue' ) );

		// Schedule cron if not scheduled
		if ( ! wp_next_scheduled( 'wp_ai_chatbot_process_enrichment_queue' ) ) {
			wp_schedule_event( time(), 'every_minute', 'wp_ai_chatbot_process_enrichment_queue' );
		}

		// Add custom cron schedule
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Hook into lead creation
		add_action( 'wp_ai_chatbot_lead_created', array( $this, 'on_lead_created' ), 15, 2 );

		// Hook into lead scored (for priority adjustment)
		add_action( 'wp_ai_chatbot_lead_scored', array( $this, 'adjust_priority' ), 10, 2 );

		// AJAX handlers
		add_action( 'wp_ajax_wp_ai_chatbot_enqueue_enrichment', array( $this, 'ajax_enqueue' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_get_queue_status', array( $this, 'ajax_get_status' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_bulk_enqueue', array( $this, 'ajax_bulk_enqueue' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_cancel_enrichment', array( $this, 'ajax_cancel' ) );
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @since 1.0.0
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'wp-ai-chatbot-leadgen-pro' ),
		);

		$schedules['every_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'wp-ai-chatbot-leadgen-pro' ),
		);

		return $schedules;
	}

	/**
	 * Add job to queue.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id  Lead ID.
	 * @param array $options  Job options.
	 * @return int|WP_Error Job ID or error.
	 */
	public function enqueue( $lead_id, $options = array() ) {
		global $wpdb;

		$defaults = array(
			'providers'    => array(), // Empty = all configured
			'priority'     => 'normal',
			'scheduled_at' => null,    // Null = immediate
			'force'        => false,   // Force re-enrichment
		);
		$options = wp_parse_args( $options, $defaults );

		// Get lead email
		$lead = null;
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
			$storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
			$lead = $storage->get( $lead_id );
		}

		if ( ! $lead || empty( $lead['email'] ) ) {
			return new WP_Error( 'invalid_lead', __( 'Lead not found or no email address', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		// Check if already in queue
		if ( ! $options['force'] ) {
			$existing = $this->get_pending_job( $lead_id );
			if ( $existing ) {
				return new WP_Error( 'already_queued', __( 'Lead is already in enrichment queue', 'wp-ai-chatbot-leadgen-pro' ), $existing['id'] );
			}

			// Check if recently enriched
			$custom_fields = $lead['custom_fields'] ?? array();
			if ( ! empty( $custom_fields['enrichment_date'] ) ) {
				$enriched_at = strtotime( $custom_fields['enrichment_date'] );
				$cooldown = $this->config->get( 'enrichment_cooldown_days', 7 ) * DAY_IN_SECONDS;
				
				if ( ( time() - $enriched_at ) < $cooldown ) {
					return new WP_Error( 'recently_enriched', __( 'Lead was recently enriched', 'wp-ai-chatbot-leadgen-pro' ) );
				}
			}
		}

		// Map priority string to number
		$priority_num = self::PRIORITIES[ $options['priority'] ] ?? self::PRIORITIES['normal'];

		// Insert job
		$result = $wpdb->insert(
			$this->table_name,
			array(
				'lead_id'      => $lead_id,
				'email'        => $lead['email'],
				'providers'    => ! empty( $options['providers'] ) ? wp_json_encode( $options['providers'] ) : null,
				'priority'     => $priority_num,
				'status'       => 'pending',
				'max_attempts' => self::MAX_RETRIES,
				'scheduled_at' => $options['scheduled_at'] ? $options['scheduled_at'] : current_time( 'mysql' ),
				'created_at'   => current_time( 'mysql' ),
			)
		);

		if ( false === $result ) {
			$this->logger->error( 'Failed to enqueue enrichment job', array(
				'lead_id' => $lead_id,
				'error'   => $wpdb->last_error,
			) );
			return new WP_Error( 'db_error', __( 'Failed to add to queue', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		$job_id = $wpdb->insert_id;

		$this->logger->info( 'Enrichment job enqueued', array(
			'job_id'   => $job_id,
			'lead_id'  => $lead_id,
			'priority' => $options['priority'],
		) );

		// If urgent, trigger immediate processing
		if ( $options['priority'] === 'urgent' ) {
			$this->process_single_job( $job_id );
		}

		return $job_id;
	}

	/**
	 * Process the queue.
	 *
	 * @since 1.0.0
	 * @param int $batch_size Number of jobs to process.
	 * @return array Processing results.
	 */
	public function process_queue( $batch_size = null ) {
		if ( null === $batch_size ) {
			$batch_size = $this->config->get( 'enrichment_batch_size', self::BATCH_SIZE );
		}

		// Get lock to prevent concurrent processing
		$lock_key = 'wp_ai_chatbot_enrichment_queue_lock';
		$lock = get_transient( $lock_key );

		if ( $lock ) {
			$this->logger->debug( 'Enrichment queue is locked, skipping' );
			return array( 'skipped' => true, 'reason' => 'locked' );
		}

		// Acquire lock
		set_transient( $lock_key, time(), self::LOCK_TIMEOUT );

		try {
			// Release stale locks
			$this->release_stale_locks();

			// Get pending jobs
			$jobs = $this->get_pending_jobs( $batch_size );

			if ( empty( $jobs ) ) {
				delete_transient( $lock_key );
				return array( 'processed' => 0, 'jobs' => array() );
			}

			$results = array(
				'processed'  => 0,
				'successful' => 0,
				'failed'     => 0,
				'jobs'       => array(),
			);

			foreach ( $jobs as $job ) {
				$result = $this->process_job( $job );
				$results['jobs'][ $job['id'] ] = $result;
				$results['processed']++;

				if ( $result['success'] ) {
					$results['successful']++;
				} else {
					$results['failed']++;
				}

				// Check memory usage
				if ( memory_get_usage( true ) > $this->get_memory_limit() * 0.8 ) {
					$this->logger->warning( 'Memory limit approaching, stopping queue processing' );
					break;
				}

				// Small delay between jobs to respect rate limits
				usleep( 200000 ); // 200ms
			}

			$this->logger->info( 'Enrichment queue processed', $results );

			return $results;

		} finally {
			// Always release lock
			delete_transient( $lock_key );
		}
	}

	/**
	 * Process a single job.
	 *
	 * @since 1.0.0
	 * @param array|int $job Job data or ID.
	 * @return array Result.
	 */
	public function process_job( $job ) {
		global $wpdb;

		// Get job if ID provided
		if ( is_numeric( $job ) ) {
			$job = $this->get_job( $job );
		}

		if ( ! $job ) {
			return array( 'success' => false, 'error' => 'Job not found' );
		}

		$job_id = $job['id'];

		// Lock the job
		$locked = $this->lock_job( $job_id );
		if ( ! $locked ) {
			return array( 'success' => false, 'error' => 'Could not acquire lock' );
		}

		// Update status to processing
		$this->update_job_status( $job_id, 'processing', array(
			'started_at' => current_time( 'mysql' ),
			'attempts'   => intval( $job['attempts'] ) + 1,
		) );

		try {
			// Initialize enricher if needed
			if ( ! $this->enricher && class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Enricher' ) ) {
				$this->enricher = new WP_AI_Chatbot_LeadGen_Pro_Lead_Enricher();
			}

			if ( ! $this->enricher ) {
				throw new Exception( 'Enricher not available' );
			}

			// Parse providers
			$providers = ! empty( $job['providers'] ) ? json_decode( $job['providers'], true ) : array();

			// Perform enrichment
			$result = $this->enricher->enrich( $job['lead_id'], array(
				'providers' => $providers,
				'force'     => true,
				'async'     => false,
			) );

			if ( $result['success'] ) {
				// Mark as completed
				$this->update_job_status( $job_id, 'completed', array(
					'completed_at' => current_time( 'mysql' ),
					'result'       => wp_json_encode( $result['data'] ?? array() ),
				) );

				$this->unlock_job( $job_id );

				return array(
					'success'  => true,
					'lead_id'  => $job['lead_id'],
					'data'     => $result['data'] ?? array(),
				);
			} else {
				throw new Exception( $result['error'] ?? 'Enrichment failed' );
			}

		} catch ( Exception $e ) {
			$attempts = intval( $job['attempts'] ) + 1;
			$max_attempts = intval( $job['max_attempts'] );

			if ( $attempts >= $max_attempts ) {
				// Max retries reached, mark as failed
				$this->update_job_status( $job_id, 'failed', array(
					'completed_at'  => current_time( 'mysql' ),
					'error_message' => $e->getMessage(),
				) );
			} else {
				// Schedule retry with exponential backoff
				$delay = pow( 2, $attempts ) * 60; // 2, 4, 8 minutes
				$this->update_job_status( $job_id, 'pending', array(
					'scheduled_at'  => date( 'Y-m-d H:i:s', time() + $delay ),
					'error_message' => $e->getMessage(),
				) );
			}

			$this->unlock_job( $job_id );

			$this->logger->error( 'Enrichment job failed', array(
				'job_id'   => $job_id,
				'lead_id'  => $job['lead_id'],
				'error'    => $e->getMessage(),
				'attempts' => $attempts,
			) );

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
				'lead_id' => $job['lead_id'],
				'retry'   => $attempts < $max_attempts,
			);
		}
	}

	/**
	 * Process a single job by ID (for immediate processing).
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return array Result.
	 */
	public function process_single_job( $job_id ) {
		return $this->process_job( $job_id );
	}

	/**
	 * Get a job by ID.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return array|null Job data or null.
	 */
	public function get_job( $job_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE id = %d",
				$job_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get pending job for a lead.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 * @return array|null Job data or null.
	 */
	public function get_pending_job( $lead_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE lead_id = %d AND status IN ('pending', 'processing') ORDER BY created_at DESC LIMIT 1",
				$lead_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get pending jobs.
	 *
	 * @since 1.0.0
	 * @param int $limit Number of jobs to get.
	 * @return array Jobs.
	 */
	public function get_pending_jobs( $limit = 10 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} 
				WHERE status = 'pending' 
				AND scheduled_at <= %s 
				AND (locked_at IS NULL OR locked_at < %s)
				ORDER BY priority ASC, scheduled_at ASC 
				LIMIT %d",
				current_time( 'mysql' ),
				date( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT ),
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Update job status.
	 *
	 * @since 1.0.0
	 * @param int    $job_id Job ID.
	 * @param string $status New status.
	 * @param array  $data   Additional data to update.
	 * @return bool True on success.
	 */
	private function update_job_status( $job_id, $status, $data = array() ) {
		global $wpdb;

		$data['status'] = $status;
		$data['updated_at'] = current_time( 'mysql' );

		return (bool) $wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $job_id )
		);
	}

	/**
	 * Lock a job.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return bool True if locked.
	 */
	private function lock_job( $job_id ) {
		global $wpdb;

		$lock_id = uniqid( 'lock_', true );

		$result = $wpdb->update(
			$this->table_name,
			array(
				'locked_at' => current_time( 'mysql' ),
				'locked_by' => $lock_id,
			),
			array(
				'id'        => $job_id,
				'locked_at' => null,
			)
		);

		// Also try to lock stale locks
		if ( ! $result ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table_name} SET locked_at = %s, locked_by = %s WHERE id = %d AND locked_at < %s",
					current_time( 'mysql' ),
					$lock_id,
					$job_id,
					date( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT )
				)
			);
		}

		return (bool) $result;
	}

	/**
	 * Unlock a job.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return bool True on success.
	 */
	private function unlock_job( $job_id ) {
		global $wpdb;

		return (bool) $wpdb->update(
			$this->table_name,
			array(
				'locked_at' => null,
				'locked_by' => null,
			),
			array( 'id' => $job_id )
		);
	}

	/**
	 * Release stale locks.
	 *
	 * @since 1.0.0
	 */
	private function release_stale_locks() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table_name} SET locked_at = NULL, locked_by = NULL WHERE locked_at < %s AND status = 'processing'",
				date( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT )
			)
		);

		// Also reset stale processing jobs back to pending
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table_name} SET status = 'pending' WHERE status = 'processing' AND started_at < %s",
				date( 'Y-m-d H:i:s', time() - self::LOCK_TIMEOUT )
			)
		);
	}

	/**
	 * Handle lead created event.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id   Lead ID.
	 * @param array $lead_data Lead data.
	 */
	public function on_lead_created( $lead_id, $lead_data ) {
		// Check if auto-enrichment is enabled
		if ( ! $this->config->get( 'auto_enrich_leads', false ) ) {
			return;
		}

		// Determine priority based on lead score if available
		$priority = 'normal';
		$score = intval( $lead_data['score'] ?? 0 );

		if ( $score >= 70 ) {
			$priority = 'high';
		} elseif ( $score >= 85 ) {
			$priority = 'urgent';
		}

		$this->enqueue( $lead_id, array(
			'priority' => $priority,
		) );
	}

	/**
	 * Adjust job priority based on lead score.
	 *
	 * @since 1.0.0
	 * @param int   $lead_id    Lead ID.
	 * @param array $score_data Score data.
	 */
	public function adjust_priority( $lead_id, $score_data ) {
		global $wpdb;

		$job = $this->get_pending_job( $lead_id );

		if ( ! $job ) {
			return;
		}

		$score = $score_data['composite_score'] ?? 0;
		$new_priority = self::PRIORITIES['normal'];

		if ( $score >= 85 ) {
			$new_priority = self::PRIORITIES['urgent'];
		} elseif ( $score >= 70 ) {
			$new_priority = self::PRIORITIES['high'];
		}

		// Only upgrade priority, never downgrade
		if ( $new_priority < intval( $job['priority'] ) ) {
			$wpdb->update(
				$this->table_name,
				array( 'priority' => $new_priority ),
				array( 'id' => $job['id'] )
			);

			// If now urgent, process immediately
			if ( $new_priority === self::PRIORITIES['urgent'] ) {
				$this->process_single_job( $job['id'] );
			}
		}
	}

	/**
	 * Bulk enqueue leads.
	 *
	 * @since 1.0.0
	 * @param array $lead_ids Lead IDs.
	 * @param array $options  Options.
	 * @return array Results.
	 */
	public function bulk_enqueue( $lead_ids, $options = array() ) {
		$results = array(
			'enqueued' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		$options['priority'] = $options['priority'] ?? 'low'; // Bulk = low priority

		foreach ( $lead_ids as $lead_id ) {
			$result = $this->enqueue( $lead_id, $options );

			if ( is_wp_error( $result ) ) {
				if ( $result->get_error_code() === 'already_queued' || $result->get_error_code() === 'recently_enriched' ) {
					$results['skipped']++;
				} else {
					$results['errors'][ $lead_id ] = $result->get_error_message();
				}
			} else {
				$results['enqueued']++;
			}
		}

		return $results;
	}

	/**
	 * Cancel a job.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return bool True on success.
	 */
	public function cancel( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( ! $job || $job['status'] === 'completed' ) {
			return false;
		}

		return $this->update_job_status( $job_id, 'cancelled' );
	}

	/**
	 * Get queue statistics.
	 *
	 * @since 1.0.0
	 * @return array Statistics.
	 */
	public function get_statistics() {
		global $wpdb;

		$stats = array();

		// Status counts
		$status_counts = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$this->table_name} GROUP BY status",
			ARRAY_A
		);

		foreach ( $status_counts as $row ) {
			$stats['by_status'][ $row['status'] ] = intval( $row['count'] );
		}

		// Priority counts for pending
		$priority_counts = $wpdb->get_results(
			"SELECT priority, COUNT(*) as count FROM {$this->table_name} WHERE status = 'pending' GROUP BY priority",
			ARRAY_A
		);

		$priority_names = array_flip( self::PRIORITIES );
		foreach ( $priority_counts as $row ) {
			$name = $priority_names[ $row['priority'] ] ?? 'unknown';
			$stats['pending_by_priority'][ $name ] = intval( $row['count'] );
		}

		// Processing rate (last hour)
		$stats['completed_last_hour'] = intval( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'completed' AND completed_at >= %s",
				date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		) );

		// Failed rate (last hour)
		$stats['failed_last_hour'] = intval( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'failed' AND completed_at >= %s",
				date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		) );

		// Average processing time
		$stats['avg_processing_time'] = floatval( $wpdb->get_var(
			"SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) FROM {$this->table_name} WHERE status = 'completed' AND started_at IS NOT NULL AND completed_at IS NOT NULL"
		) );

		// Total counts
		$stats['total_pending'] = intval( $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'pending'"
		) );

		$stats['total_processing'] = intval( $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'processing'"
		) );

		return $stats;
	}

	/**
	 * Get memory limit in bytes.
	 *
	 * @since 1.0.0
	 * @return int Memory limit.
	 */
	private function get_memory_limit() {
		$limit = ini_get( 'memory_limit' );

		if ( preg_match( '/^(\d+)(.)$/', $limit, $matches ) ) {
			$value = intval( $matches[1] );
			switch ( strtoupper( $matches[2] ) ) {
				case 'G':
					return $value * 1024 * 1024 * 1024;
				case 'M':
					return $value * 1024 * 1024;
				case 'K':
					return $value * 1024;
			}
		}

		return 128 * 1024 * 1024; // Default 128MB
	}

	/**
	 * AJAX handler for enqueueing a lead.
	 *
	 * @since 1.0.0
	 */
	public function ajax_enqueue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_id = intval( $_POST['lead_id'] ?? 0 );
		$priority = sanitize_text_field( $_POST['priority'] ?? 'normal' );
		$force = ! empty( $_POST['force'] );

		if ( ! $lead_id ) {
			wp_send_json_error( array( 'message' => 'Lead ID required' ), 400 );
		}

		$result = $this->enqueue( $lead_id, array(
			'priority' => $priority,
			'force'    => $force,
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array(
			'job_id'  => $result,
			'message' => __( 'Lead added to enrichment queue', 'wp-ai-chatbot-leadgen-pro' ),
		) );
	}

	/**
	 * AJAX handler for getting queue status.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$stats = $this->get_statistics();

		wp_send_json_success( $stats );
	}

	/**
	 * AJAX handler for bulk enqueueing.
	 *
	 * @since 1.0.0
	 */
	public function ajax_bulk_enqueue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$lead_ids = isset( $_POST['lead_ids'] ) ? array_map( 'intval', (array) $_POST['lead_ids'] ) : array();
		$force = ! empty( $_POST['force'] );

		if ( empty( $lead_ids ) ) {
			wp_send_json_error( array( 'message' => 'No leads specified' ), 400 );
		}

		$results = $this->bulk_enqueue( $lead_ids, array( 'force' => $force ) );

		wp_send_json_success( $results );
	}

	/**
	 * AJAX handler for cancelling a job.
	 *
	 * @since 1.0.0
	 */
	public function ajax_cancel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$job_id = intval( $_POST['job_id'] ?? 0 );

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => 'Job ID required' ), 400 );
		}

		if ( $this->cancel( $job_id ) ) {
			wp_send_json_success( array( 'message' => __( 'Job cancelled', 'wp-ai-chatbot-leadgen-pro' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not cancel job', 'wp-ai-chatbot-leadgen-pro' ) ), 400 );
		}
	}

	/**
	 * Cleanup old completed/failed jobs.
	 *
	 * @since 1.0.0
	 * @param int $days Days to keep.
	 * @return int Number of deleted jobs.
	 */
	public function cleanup( $days = 30 ) {
		global $wpdb;

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE status IN ('completed', 'failed', 'cancelled') AND completed_at < %s",
				date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
	}
}

