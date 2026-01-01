<?php
/**
 * Ingestion Queue.
 *
 * Background job queue system for content ingestion using WordPress cron or Action Scheduler.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Ingestion_Queue {

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
	 * Queue hook name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $queue_hook = 'wp_ai_chatbot_process_ingestion_queue';

	/**
	 * Action Scheduler available flag.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $action_scheduler_available = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->action_scheduler_available = $this->check_action_scheduler();
		$this->register_hooks();
	}

	/**
	 * Check if Action Scheduler is available.
	 *
	 * @since 1.0.0
	 * @return bool True if Action Scheduler is available, false otherwise.
	 */
	private function check_action_scheduler() {
		return function_exists( 'as_schedule_single_action' ) && class_exists( 'ActionScheduler' );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function register_hooks() {
		// Register cron hook for processing queue
		add_action( $this->queue_hook, array( $this, 'process_queue' ) );

		// Schedule recurring queue processor if not using Action Scheduler
		if ( ! $this->action_scheduler_available ) {
			if ( ! wp_next_scheduled( $this->queue_hook ) ) {
				wp_schedule_event( time(), 'wp_ai_chatbot_queue_interval', $this->queue_hook );
			}
		}

		// Register custom cron interval
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
	}

	/**
	 * Add custom cron interval for queue processing.
	 *
	 * @since 1.0.0
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_interval( $schedules ) {
		$interval = $this->config->get( 'queue_processing_interval', 60 ); // Default: 60 seconds

		$schedules['wp_ai_chatbot_queue_interval'] = array(
			'interval' => $interval,
			'display'  => sprintf( __( 'Every %d seconds', 'wp-ai-chatbot-leadgen-pro' ), $interval ),
		);

		return $schedules;
	}

	/**
	 * Add job to queue.
	 *
	 * @since 1.0.0
	 * @param string $job_type    Job type (e.g., 'crawl_url', 'process_content', 'index_chunks').
	 * @param array  $job_data    Job data.
	 * @param array  $args        Optional. Job arguments (priority, delay, etc.).
	 * @return int|WP_Error Job ID or WP_Error on failure.
	 */
	public function add_job( $job_type, $job_data, $args = array() ) {
		$defaults = array(
			'priority' => 10, // Lower number = higher priority
			'delay'    => 0,  // Delay in seconds before execution
			'retries'  => 3,  // Maximum retry attempts
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate job type
		if ( ! $this->is_valid_job_type( $job_type ) ) {
			return new WP_Error(
				'invalid_job_type',
				sprintf( __( 'Invalid job type: %s', 'wp-ai-chatbot-leadgen-pro' ), $job_type )
			);
		}

		// Store job data
		$job_id = $this->store_job( $job_type, $job_data, $args );

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		// Schedule job execution
		$scheduled = $this->schedule_job( $job_id, $job_type, $job_data, $args );

		if ( is_wp_error( $scheduled ) ) {
			// Clean up stored job
			$this->delete_job( $job_id );
			return $scheduled;
		}

		$this->logger->info(
			'Job added to queue',
			array(
				'job_id'   => $job_id,
				'job_type' => $job_type,
				'priority' => $args['priority'],
			)
		);

		return $job_id;
	}

	/**
	 * Store job data in database.
	 *
	 * @since 1.0.0
	 * @param string $job_type Job type.
	 * @param array  $job_data Job data.
	 * @param array  $args     Job arguments.
	 * @return int|WP_Error Job ID or WP_Error on failure.
	 */
	private function store_job( $job_type, $job_data, $args ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue';

		// Create table if it doesn't exist
		$this->maybe_create_queue_table();

		$data = array(
			'job_type'    => $job_type,
			'job_data'    => wp_json_encode( $job_data ),
			'status'      => 'pending',
			'priority'    => intval( $args['priority'] ),
			'retry_count' => 0,
			'max_retries' => intval( $args['retries'] ),
			'created_at'  => current_time( 'mysql' ),
			'scheduled_at' => date( 'Y-m-d H:i:s', time() + intval( $args['delay'] ) ),
		);

		$result = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			$this->logger->error(
				'Failed to store job in queue',
				array(
					'job_type' => $job_type,
					'error'    => $wpdb->last_error,
				)
			);
			return new WP_Error(
				'db_error',
				__( 'Failed to store job in queue.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return $wpdb->insert_id;
	}

	/**
	 * Schedule job execution.
	 *
	 * @since 1.0.0
	 * @param int    $job_id   Job ID.
	 * @param string $job_type Job type.
	 * @param array  $job_data Job data.
	 * @param array  $args     Job arguments.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function schedule_job( $job_id, $job_type, $job_data, $args ) {
		$timestamp = time() + intval( $args['delay'] );

		if ( $this->action_scheduler_available ) {
			// Use Action Scheduler
			$action_id = as_schedule_single_action(
				$timestamp,
				$this->queue_hook,
				array( $job_id ),
				'wp-ai-chatbot-ingestion',
				false,
				$args['priority']
			);

			if ( $action_id ) {
				// Store Action Scheduler ID
				$this->update_job_meta( $job_id, 'action_scheduler_id', $action_id );
				return true;
			}

			return new WP_Error(
				'schedule_failed',
				__( 'Failed to schedule job with Action Scheduler.', 'wp-ai-chatbot-leadgen-pro' )
			);
		} else {
			// Use WordPress Cron
			$hook = $this->queue_hook . '_' . $job_id;
			$scheduled = wp_schedule_single_event( $timestamp, $hook, array( $job_id ) );

			if ( false === $scheduled && ! wp_next_scheduled( $hook ) ) {
				return new WP_Error(
					'schedule_failed',
					__( 'Failed to schedule job with WordPress Cron.', 'wp-ai-chatbot-leadgen-pro' )
				);
			}

			// Register hook for this specific job
			add_action( $hook, array( $this, 'execute_job' ), 10, 1 );

			return true;
		}
	}

	/**
	 * Process queue (called by cron).
	 *
	 * @since 1.0.0
	 * @param int $job_id Optional. Specific job ID to process.
	 */
	public function process_queue( $job_id = null ) {
		if ( ! empty( $job_id ) ) {
			// Process specific job
			$this->execute_job( $job_id );
			return;
		}

		// Process pending jobs
		$jobs = $this->get_pending_jobs( 10 ); // Process up to 10 jobs at a time

		foreach ( $jobs as $job ) {
			$this->execute_job( $job->id );
		}
	}

	/**
	 * Execute a specific job.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function execute_job( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( ! $job ) {
			return new WP_Error(
				'job_not_found',
				sprintf( __( 'Job with ID %d not found.', 'wp-ai-chatbot-leadgen-pro' ), $job_id )
			);
		}

		// Check if job is ready to execute
		if ( $job->status !== 'pending' && $job->status !== 'retry' ) {
			return new WP_Error(
				'invalid_job_status',
				sprintf( __( 'Job status is %s, cannot execute.', 'wp-ai-chatbot-leadgen-pro' ), $job->status )
			);
		}

		// Check scheduled time
		if ( strtotime( $job->scheduled_at ) > time() ) {
			return false; // Not ready yet
		}

		// Update job status to processing
		$this->update_job_status( $job_id, 'processing' );

		// Parse job data
		$job_data = json_decode( $job->job_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->update_job_status( $job_id, 'failed', 'Invalid job data JSON' );
			return new WP_Error( 'invalid_job_data', __( 'Invalid job data.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		// Execute job based on type
		$result = $this->execute_job_by_type( $job->job_type, $job_data, $job_id );

		// Handle result
		if ( is_wp_error( $result ) ) {
			// Check if we should retry
			if ( $job->retry_count < $job->max_retries ) {
				$this->retry_job( $job_id );
			} else {
				$this->update_job_status( $job_id, 'failed', $result->get_error_message() );
			}
			return $result;
		}

		// Job completed successfully
		$this->update_job_status( $job_id, 'completed' );

		$this->logger->info(
			'Job executed successfully',
			array(
				'job_id'   => $job_id,
				'job_type' => $job->job_type,
			)
		);

		return true;
	}

	/**
	 * Execute job based on type.
	 *
	 * @since 1.0.0
	 * @param string $job_type Job type.
	 * @param array  $job_data Job data.
	 * @param int    $job_id   Job ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function execute_job_by_type( $job_type, $job_data, $job_id ) {
		switch ( $job_type ) {
			case 'crawl_url':
				return $this->execute_crawl_url_job( $job_data, $job_id );

			case 'process_content':
				return $this->execute_process_content_job( $job_data, $job_id );

			case 'index_chunks':
				return $this->execute_index_chunks_job( $job_data, $job_id );

			case 'process_pdf':
				return $this->execute_process_pdf_job( $job_data, $job_id );

			case 'process_product':
				return $this->execute_process_product_job( $job_data, $job_id );

			case 'process_api':
				return $this->execute_process_api_job( $job_data, $job_id );

			default:
				return new WP_Error(
					'unknown_job_type',
					sprintf( __( 'Unknown job type: %s', 'wp-ai-chatbot-leadgen-pro' ), $job_type )
				);
		}
	}

	/**
	 * Execute crawl URL job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function execute_crawl_url_job( $job_data, $job_id ) {
		// This will be implemented by the content indexer
		do_action( 'wp_ai_chatbot_crawl_url', $job_data, $job_id );
		return true;
	}

	/**
	 * Execute process content job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function execute_process_content_job( $job_data, $job_id ) {
		// This will be implemented by the content indexer
		do_action( 'wp_ai_chatbot_process_content', $job_data, $job_id );
		return true;
	}

	/**
	 * Execute index chunks job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function execute_index_chunks_job( $job_data, $job_id ) {
		// This will be implemented by the content indexer
		do_action( 'wp_ai_chatbot_index_chunks', $job_data, $job_id );
		return true;
	}

	/**
	 * Execute process PDF job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function execute_process_pdf_job( $job_data, $job_id ) {
		// This will be implemented by the content indexer
		do_action( 'wp_ai_chatbot_process_pdf', $job_data, $job_id );
		return true;
	}

	/**
	 * Execute process product job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function execute_process_product_job( $job_data, $job_id ) {
		// This will be implemented by the content indexer
		do_action( 'wp_ai_chatbot_process_product', $job_data, $job_id );
		return true;
	}

	/**
	 * Execute process API job.
	 *
	 * @since 1.0.0
	 * @param array $job_data Job data.
	 * @param int   $job_id   Job ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function execute_process_api_job( $job_data, $job_id ) {
		// This will be implemented by the content indexer
		do_action( 'wp_ai_chatbot_process_api', $job_data, $job_id );
		return true;
	}

	/**
	 * Get pending jobs.
	 *
	 * @since 1.0.0
	 * @param int $limit Maximum number of jobs to return.
	 * @return array Array of job objects.
	 */
	private function get_pending_jobs( $limit = 10 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} 
				WHERE status IN ('pending', 'retry') 
				AND scheduled_at <= NOW() 
				ORDER BY priority ASC, created_at ASC 
				LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Get job by ID.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return object|null Job object or null if not found.
	 */
	public function get_job( $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$job_id
			)
		);
	}

	/**
	 * Update job status.
	 *
	 * @since 1.0.0
	 * @param int    $job_id  Job ID.
	 * @param string $status  New status.
	 * @param string $message Optional. Status message.
	 * @return bool True on success, false on failure.
	 */
	private function update_job_status( $job_id, $status, $message = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue';

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $status === 'processing' ) {
			$data['started_at'] = current_time( 'mysql' );
		} elseif ( in_array( $status, array( 'completed', 'failed' ), true ) ) {
			$data['completed_at'] = current_time( 'mysql' );
		}

		if ( ! empty( $message ) ) {
			$data['error_message'] = $message;
		}

		return false !== $wpdb->update(
			$table,
			$data,
			array( 'id' => $job_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Retry failed job.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	private function retry_job( $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue';

		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return false;
		}

		// Increment retry count
		$retry_count = $job->retry_count + 1;
		$delay = min( 300, pow( 2, $retry_count ) ); // Exponential backoff, max 5 minutes

		$data = array(
			'status'      => 'retry',
			'retry_count' => $retry_count,
			'scheduled_at' => date( 'Y-m-d H:i:s', time() + $delay ),
			'updated_at'  => current_time( 'mysql' ),
		);

		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => $job_id ),
			null,
			array( '%d' )
		);

		// Reschedule job
		if ( $updated ) {
			$job_data = json_decode( $job->job_data, true );
			$this->schedule_job( $job_id, $job->job_type, $job_data, array( 'delay' => $delay ) );
		}

		return $updated !== false;
	}

	/**
	 * Delete job.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_job( $job_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue';

		return false !== $wpdb->delete(
			$table,
			array( 'id' => $job_id ),
			array( '%d' )
		);
	}

	/**
	 * Update job meta.
	 *
	 * @since 1.0.0
	 * @param int    $job_id Job ID.
	 * @param string $key    Meta key.
	 * @param mixed  $value  Meta value.
	 * @return bool True on success, false on failure.
	 */
	private function update_job_meta( $job_id, $key, $value ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue_meta';

		// Create meta table if needed
		$this->maybe_create_meta_table();

		// Check if meta exists
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE job_id = %d AND meta_key = %s",
				$job_id,
				$key
			)
		);

		if ( $existing ) {
			return false !== $wpdb->update(
				$table,
				array( 'meta_value' => maybe_serialize( $value ) ),
				array( 'job_id' => $job_id, 'meta_key' => $key ),
				array( '%s' ),
				array( '%d', '%s' )
			);
		} else {
			return false !== $wpdb->insert(
				$table,
				array(
					'job_id'     => $job_id,
					'meta_key'   => $key,
					'meta_value' => maybe_serialize( $value ),
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Check if job type is valid.
	 *
	 * @since 1.0.0
	 * @param string $job_type Job type.
	 * @return bool True if valid, false otherwise.
	 */
	private function is_valid_job_type( $job_type ) {
		$valid_types = array(
			'crawl_url',
			'process_content',
			'index_chunks',
			'process_pdf',
			'process_product',
			'process_api',
		);

		return in_array( $job_type, $valid_types, true );
	}

	/**
	 * Create queue table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_queue_table() {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			return; // Table already exists
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_type varchar(50) NOT NULL,
			job_data longtext NOT NULL,
			status varchar(20) DEFAULT 'pending',
			priority int(11) DEFAULT 10,
			retry_count int(11) DEFAULT 0,
			max_retries int(11) DEFAULT 3,
			error_message text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			scheduled_at datetime DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY job_type (job_type),
			KEY scheduled_at (scheduled_at),
			KEY priority (priority)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create meta table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_meta_table() {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue_meta';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			return; // Table already exists
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id bigint(20) UNSIGNED NOT NULL,
			meta_key varchar(255) NOT NULL,
			meta_value longtext DEFAULT NULL,
			PRIMARY KEY (id),
			KEY job_id (job_id),
			KEY meta_key (meta_key)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get queue statistics.
	 *
	 * @since 1.0.0
	 * @return array Queue statistics.
	 */
	public function get_queue_stats() {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_ingestion_queue';

		$this->maybe_create_queue_table();

		$stats = array(
			'pending'   => 0,
			'processing' => 0,
			'completed' => 0,
			'failed'    => 0,
			'retry'     => 0,
			'total'     => 0,
		);

		$results = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
			ARRAY_A
		);

		foreach ( $results as $row ) {
			$status = $row['status'];
			$count = intval( $row['count'] );
			$stats[ $status ] = $count;
			$stats['total'] += $count;
		}

		return $stats;
	}
}

