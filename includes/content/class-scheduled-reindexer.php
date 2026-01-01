<?php
/**
 * Scheduled Re-indexer.
 *
 * Handles scheduled re-indexing of content based on configurable intervals.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Scheduled_Reindexer {

	/**
	 * Config instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Content crawler instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Crawler
	 */
	private $crawler;

	/**
	 * Content indexer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Indexer
	 */
	private $indexer;

	/**
	 * Ingestion queue instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Ingestion_Queue
	 */
	private $queue;

	/**
	 * Freshness tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Freshness_Tracker
	 */
	private $freshness_tracker;

	/**
	 * Cron hook name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $cron_hook = 'wp_ai_chatbot_reindex_content';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->crawler = new WP_AI_Chatbot_LeadGen_Pro_Content_Crawler();
		$this->indexer = new WP_AI_Chatbot_LeadGen_Pro_Content_Indexer();
		$this->queue = new WP_AI_Chatbot_LeadGen_Pro_Ingestion_Queue();
		$this->freshness_tracker = new WP_AI_Chatbot_LeadGen_Pro_Content_Freshness_Tracker();
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		add_action( $this->cron_hook, array( $this, 'execute_reindex' ) );
		add_action( 'wp_ai_chatbot_reindex_stale', array( $this, 'execute_stale_reindex' ) );
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );
	}

	/**
	 * Schedule re-indexing based on configured interval.
	 *
	 * @since 1.0.0
	 * @param string $interval Optional. Interval (daily, weekly, monthly, never). If not provided, uses config.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function schedule_reindex( $interval = null ) {
		// Unschedule existing cron job
		$this->unschedule_reindex();

		if ( null === $interval ) {
			$interval = $this->config->get( 'reindex_interval', 'weekly' );
		}

		if ( 'never' === $interval ) {
			$this->logger->info( 'Re-indexing scheduled as "never", no cron job created.' );
			return true;
		}

		// Calculate next run time
		$next_run = $this->calculate_next_run_time( $interval );

		if ( ! $next_run ) {
			return new WP_Error(
				'invalid_interval',
				sprintf( __( 'Invalid re-index interval: %s', 'wp-ai-chatbot-leadgen-pro' ), $interval )
			);
		}

		// Schedule the cron job
		$scheduled = wp_schedule_event( $next_run, $this->get_cron_schedule_name( $interval ), $this->cron_hook );

		if ( false === $scheduled ) {
			$this->logger->error(
				'Failed to schedule re-indexing',
				array( 'interval' => $interval, 'next_run' => $next_run )
			);
			return new WP_Error(
				'schedule_failed',
				__( 'Failed to schedule re-indexing cron job.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$this->logger->info(
			'Re-indexing scheduled',
			array(
				'interval' => $interval,
				'next_run' => date( 'Y-m-d H:i:s', $next_run ),
			)
		);

		return true;
	}

	/**
	 * Unschedule re-indexing.
	 *
	 * @since 1.0.0
	 * @return bool True on success, false on failure.
	 */
	public function unschedule_reindex() {
		$timestamp = wp_next_scheduled( $this->cron_hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->cron_hook );
			$this->logger->info( 'Re-indexing unscheduled' );
			return true;
		}
		return false;
	}

	/**
	 * Execute scheduled re-indexing.
	 *
	 * @since 1.0.0
	 */
	public function execute_reindex() {
		$this->logger->info( 'Starting scheduled re-indexing' );

		// Get all indexed URLs
		$urls = $this->get_all_indexed_urls();

		if ( empty( $urls ) ) {
			$this->logger->info( 'No URLs to re-index' );
			return;
		}

		$queued = 0;
		$errors = 0;

		foreach ( $urls as $url_data ) {
			$url = $url_data['source_url'];

			// Check if source has been updated
			$needs_reindex = $this->freshness_tracker->check_source_updated(
				$url,
				$url_data['source_type'],
				isset( $url_data['source_id'] ) ? intval( $url_data['source_id'] ) : null
			);

			if ( is_wp_error( $needs_reindex ) ) {
				$errors++;
				continue;
			}

			// Queue for re-indexing if updated or force re-index
			if ( $needs_reindex || $this->should_force_reindex( $url_data ) ) {
				$job_id = $this->queue->add_job( 'crawl_url', array(
					'url'           => $url,
					'force_reindex' => true,
				) );

				if ( ! is_wp_error( $job_id ) ) {
					$queued++;
				} else {
					$errors++;
				}
			}
		}

		$this->logger->info(
			'Scheduled re-indexing completed',
			array(
				'total_urls' => count( $urls ),
				'queued'     => $queued,
				'errors'     => $errors,
			)
		);
	}

	/**
	 * Execute re-indexing of stale content only.
	 *
	 * @since 1.0.0
	 */
	public function execute_stale_reindex() {
		$this->logger->info( 'Starting stale content re-indexing' );

		$stale_content = $this->freshness_tracker->get_stale_content( array( 'limit' => 1000 ) );

		if ( empty( $stale_content ) ) {
			$this->logger->info( 'No stale content to re-index' );
			return;
		}

		$queued = 0;
		$errors = 0;

		foreach ( $stale_content as $item ) {
			$job_id = $this->queue->add_job( 'crawl_url', array(
				'url'           => $item['source_url'],
				'force_reindex' => true,
			) );

			if ( ! is_wp_error( $job_id ) ) {
				$queued++;
			} else {
				$errors++;
			}
		}

		$this->logger->info(
			'Stale content re-indexing completed',
			array(
				'total_stale' => count( $stale_content ),
				'queued'      => $queued,
				'errors'      => $errors,
			)
		);
	}

	/**
	 * Get all indexed URLs.
	 *
	 * @since 1.0.0
	 * @return array Array of indexed URLs with metadata.
	 */
	private function get_all_indexed_urls() {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		$results = $wpdb->get_results(
			"SELECT DISTINCT source_url, source_type, source_id
			FROM {$table}
			WHERE source_url != ''
			ORDER BY source_url ASC",
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Check if URL should be force re-indexed.
	 *
	 * @since 1.0.0
	 * @param array $url_data URL data.
	 * @return bool True if should force re-index.
	 */
	private function should_force_reindex( $url_data ) {
		// Force re-index if content is very old (older than 90 days)
		$freshness = $this->freshness_tracker->get_freshness( $url_data['source_url'] );
		if ( $freshness && $freshness['age_days'] > 90 ) {
			return true;
		}

		return false;
	}

	/**
	 * Calculate next run time based on interval.
	 *
	 * @since 1.0.0
	 * @param string $interval Interval (daily, weekly, monthly).
	 * @return int|false Unix timestamp of next run, or false on error.
	 */
	private function calculate_next_run_time( $interval ) {
		$now = current_time( 'timestamp' );

		switch ( $interval ) {
			case 'daily':
				// Next day at 2 AM
				$next = strtotime( 'tomorrow 2:00', $now );
				break;

			case 'weekly':
				// Next Monday at 2 AM
				$next = strtotime( 'next Monday 2:00', $now );
				break;

			case 'monthly':
				// First day of next month at 2 AM
				$next = strtotime( 'first day of next month 2:00', $now );
				break;

			default:
				return false;
		}

		return $next;
	}

	/**
	 * Get cron schedule name for interval.
	 *
	 * @since 1.0.0
	 * @param string $interval Interval (daily, weekly, monthly).
	 * @return string Cron schedule name.
	 */
	private function get_cron_schedule_name( $interval ) {
		switch ( $interval ) {
			case 'daily':
				return 'wp_ai_chatbot_daily';
			case 'weekly':
				return 'wp_ai_chatbot_weekly';
			case 'monthly':
				return 'wp_ai_chatbot_monthly';
			default:
				return 'wp_ai_chatbot_daily';
		}
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @since 1.0.0
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified schedules.
	 */
	public function add_custom_cron_schedules( $schedules ) {
		$schedules['wp_ai_chatbot_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once Daily', 'wp-ai-chatbot-leadgen-pro' ),
		);

		$schedules['wp_ai_chatbot_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'wp-ai-chatbot-leadgen-pro' ),
		);

		$schedules['wp_ai_chatbot_monthly'] = array(
			'interval' => MONTH_IN_SECONDS,
			'display'  => __( 'Once Monthly', 'wp-ai-chatbot-leadgen-pro' ),
		);

		return $schedules;
	}

	/**
	 * Get next scheduled re-index time.
	 *
	 * @since 1.0.0
	 * @return int|false Unix timestamp of next run, or false if not scheduled.
	 */
	public function get_next_scheduled_time() {
		return wp_next_scheduled( $this->cron_hook );
	}

	/**
	 * Get formatted next scheduled time.
	 *
	 * @since 1.0.0
	 * @return string Formatted time string or "Not scheduled".
	 */
	public function get_next_scheduled_time_formatted() {
		$timestamp = $this->get_next_scheduled_time();
		if ( ! $timestamp ) {
			return __( 'Not scheduled', 'wp-ai-chatbot-leadgen-pro' );
		}

		return date_i18n(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Manually trigger re-indexing.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Re-indexing arguments.
	 * @return array Results.
	 */
	public function trigger_manual_reindex( $args = array() ) {
		$defaults = array(
			'only_stale' => false,
			'force'      => false,
		);

		$args = wp_parse_args( $args, $defaults );

		if ( $args['only_stale'] ) {
			$this->execute_stale_reindex();
		} else {
			$this->execute_reindex();
		}

		return array(
			'success' => true,
			'message' => __( 'Re-indexing triggered successfully.', 'wp-ai-chatbot-leadgen-pro' ),
		);
	}

	/**
	 * Initialize scheduling based on current config.
	 *
	 * @since 1.0.0
	 */
	public function init_scheduling() {
		$interval = $this->config->get( 'reindex_interval', 'weekly' );

		// Only schedule if not already scheduled
		if ( ! $this->get_next_scheduled_time() ) {
			$this->schedule_reindex( $interval );
		}
	}
}

