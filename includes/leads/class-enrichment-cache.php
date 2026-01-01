<?php
/**
 * Enrichment Cache.
 *
 * Multi-layer caching system for enrichment data to avoid duplicate API calls.
 * Supports transients, database, and object cache backends.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/leads
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Enrichment_Cache {

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
	 * Cache table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $table_name;

	/**
	 * In-memory cache for current request.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $memory_cache = array();

	/**
	 * Cache key prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_ai_chatbot_enrich_';

	/**
	 * Cache group for object cache.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CACHE_GROUP = 'wp_ai_chatbot_enrichment';

	/**
	 * Default TTL in seconds (7 days).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DEFAULT_TTL = 604800;

	/**
	 * Cache types.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const CACHE_TYPES = array(
		'email'   => 'Email-based cache',
		'domain'  => 'Domain-based cache',
		'company' => 'Company-based cache',
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
		$this->table_name = $wpdb->prefix . 'ai_chatbot_enrichment_cache';

		$this->maybe_create_table();
		$this->init_hooks();
	}

	/**
	 * Create cache table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key VARCHAR(64) NOT NULL,
			cache_type VARCHAR(20) DEFAULT 'email',
			lookup_value VARCHAR(255) NOT NULL,
			provider VARCHAR(50) DEFAULT NULL,
			data LONGTEXT NOT NULL,
			hit_count INT(11) DEFAULT 0,
			last_hit_at DATETIME DEFAULT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY cache_key (cache_key),
			KEY cache_type (cache_type),
			KEY lookup_value (lookup_value(191)),
			KEY provider (provider),
			KEY expires_at (expires_at),
			KEY hit_count (hit_count)
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
		// Cleanup expired cache daily
		add_action( 'wp_ai_chatbot_daily_cleanup', array( $this, 'cleanup_expired' ) );

		if ( ! wp_next_scheduled( 'wp_ai_chatbot_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_ai_chatbot_daily_cleanup' );
		}

		// AJAX handlers
		add_action( 'wp_ajax_wp_ai_chatbot_get_cache_stats', array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_clear_enrichment_cache', array( $this, 'ajax_clear_cache' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_warm_cache', array( $this, 'ajax_warm_cache' ) );
	}

	/**
	 * Get cached enrichment data.
	 *
	 * @since 1.0.0
	 * @param string $lookup_value Email, domain, or company name.
	 * @param string $cache_type   Cache type (email, domain, company).
	 * @param string $provider     Provider ID (optional, for provider-specific cache).
	 * @return array|null Cached data or null.
	 */
	public function get( $lookup_value, $cache_type = 'email', $provider = null ) {
		$cache_key = $this->generate_cache_key( $lookup_value, $cache_type, $provider );

		// Check memory cache first
		if ( isset( $this->memory_cache[ $cache_key ] ) ) {
			return $this->memory_cache[ $cache_key ];
		}

		// Try object cache (Redis, Memcached)
		if ( $this->is_object_cache_available() ) {
			$data = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( false !== $data ) {
				$this->memory_cache[ $cache_key ] = $data;
				$this->record_hit( $cache_key );
				return $data;
			}
		}

		// Try transient cache
		$transient_data = get_transient( self::CACHE_PREFIX . $cache_key );
		if ( false !== $transient_data ) {
			$this->memory_cache[ $cache_key ] = $transient_data;
			$this->record_hit( $cache_key );
			return $transient_data;
		}

		// Try database cache
		$db_data = $this->get_from_database( $cache_key );
		if ( $db_data ) {
			// Populate other cache layers
			$this->memory_cache[ $cache_key ] = $db_data;
			
			if ( $this->is_object_cache_available() ) {
				wp_cache_set( $cache_key, $db_data, self::CACHE_GROUP, $this->get_ttl() );
			}
			
			set_transient( self::CACHE_PREFIX . $cache_key, $db_data, $this->get_ttl() );
			
			$this->record_hit( $cache_key );
			return $db_data;
		}

		return null;
	}

	/**
	 * Set cached enrichment data.
	 *
	 * @since 1.0.0
	 * @param string $lookup_value Email, domain, or company name.
	 * @param array  $data         Data to cache.
	 * @param string $cache_type   Cache type (email, domain, company).
	 * @param string $provider     Provider ID (optional).
	 * @param int    $ttl          Time to live in seconds (optional).
	 * @return bool True on success.
	 */
	public function set( $lookup_value, $data, $cache_type = 'email', $provider = null, $ttl = null ) {
		$cache_key = $this->generate_cache_key( $lookup_value, $cache_type, $provider );
		$ttl = $ttl ?? $this->get_ttl();

		// Set in memory cache
		$this->memory_cache[ $cache_key ] = $data;

		// Set in object cache
		if ( $this->is_object_cache_available() ) {
			wp_cache_set( $cache_key, $data, self::CACHE_GROUP, $ttl );
		}

		// Set in transient
		set_transient( self::CACHE_PREFIX . $cache_key, $data, $ttl );

		// Set in database for persistence
		$this->set_in_database( $cache_key, $lookup_value, $data, $cache_type, $provider, $ttl );

		$this->logger->debug( 'Enrichment data cached', array(
			'lookup_value' => $lookup_value,
			'cache_type'   => $cache_type,
			'provider'     => $provider,
		) );

		return true;
	}

	/**
	 * Delete cached data.
	 *
	 * @since 1.0.0
	 * @param string $lookup_value Email, domain, or company name.
	 * @param string $cache_type   Cache type.
	 * @param string $provider     Provider ID (optional).
	 * @return bool True on success.
	 */
	public function delete( $lookup_value, $cache_type = 'email', $provider = null ) {
		$cache_key = $this->generate_cache_key( $lookup_value, $cache_type, $provider );

		// Remove from memory
		unset( $this->memory_cache[ $cache_key ] );

		// Remove from object cache
		if ( $this->is_object_cache_available() ) {
			wp_cache_delete( $cache_key, self::CACHE_GROUP );
		}

		// Remove transient
		delete_transient( self::CACHE_PREFIX . $cache_key );

		// Remove from database
		global $wpdb;
		$wpdb->delete( $this->table_name, array( 'cache_key' => $cache_key ) );

		return true;
	}

	/**
	 * Check if data exists in cache (without retrieving).
	 *
	 * @since 1.0.0
	 * @param string $lookup_value Email, domain, or company name.
	 * @param string $cache_type   Cache type.
	 * @param string $provider     Provider ID (optional).
	 * @return bool True if exists.
	 */
	public function exists( $lookup_value, $cache_type = 'email', $provider = null ) {
		$cache_key = $this->generate_cache_key( $lookup_value, $cache_type, $provider );

		// Check memory
		if ( isset( $this->memory_cache[ $cache_key ] ) ) {
			return true;
		}

		// Check object cache
		if ( $this->is_object_cache_available() ) {
			$data = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( false !== $data ) {
				return true;
			}
		}

		// Check transient
		if ( false !== get_transient( self::CACHE_PREFIX . $cache_key ) ) {
			return true;
		}

		// Check database
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$this->table_name} WHERE cache_key = %s AND expires_at > %s LIMIT 1",
				$cache_key,
				current_time( 'mysql' )
			)
		);

		return (bool) $exists;
	}

	/**
	 * Get data from database cache.
	 *
	 * @since 1.0.0
	 * @param string $cache_key Cache key.
	 * @return array|null Data or null.
	 */
	private function get_from_database( $cache_key ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT data FROM {$this->table_name} WHERE cache_key = %s AND expires_at > %s",
				$cache_key,
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		if ( $row && ! empty( $row['data'] ) ) {
			return json_decode( $row['data'], true );
		}

		return null;
	}

	/**
	 * Set data in database cache.
	 *
	 * @since 1.0.0
	 * @param string $cache_key    Cache key.
	 * @param string $lookup_value Lookup value.
	 * @param array  $data         Data to cache.
	 * @param string $cache_type   Cache type.
	 * @param string $provider     Provider ID.
	 * @param int    $ttl          Time to live.
	 * @return bool True on success.
	 */
	private function set_in_database( $cache_key, $lookup_value, $data, $cache_type, $provider, $ttl ) {
		global $wpdb;

		$expires_at = date( 'Y-m-d H:i:s', time() + $ttl );

		// Try update first
		$updated = $wpdb->update(
			$this->table_name,
			array(
				'data'         => wp_json_encode( $data ),
				'expires_at'   => $expires_at,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'cache_key' => $cache_key )
		);

		// Insert if not exists
		if ( ! $updated ) {
			$wpdb->insert(
				$this->table_name,
				array(
					'cache_key'    => $cache_key,
					'cache_type'   => $cache_type,
					'lookup_value' => $lookup_value,
					'provider'     => $provider,
					'data'         => wp_json_encode( $data ),
					'expires_at'   => $expires_at,
					'created_at'   => current_time( 'mysql' ),
				)
			);
		}

		return true;
	}

	/**
	 * Record a cache hit.
	 *
	 * @since 1.0.0
	 * @param string $cache_key Cache key.
	 */
	private function record_hit( $cache_key ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table_name} SET hit_count = hit_count + 1, last_hit_at = %s WHERE cache_key = %s",
				current_time( 'mysql' ),
				$cache_key
			)
		);
	}

	/**
	 * Generate cache key.
	 *
	 * @since 1.0.0
	 * @param string $lookup_value Lookup value.
	 * @param string $cache_type   Cache type.
	 * @param string $provider     Provider ID.
	 * @return string Cache key.
	 */
	private function generate_cache_key( $lookup_value, $cache_type, $provider = null ) {
		$normalized = strtolower( trim( $lookup_value ) );
		$key_parts = array( $cache_type, $normalized );

		if ( $provider ) {
			$key_parts[] = $provider;
		}

		return md5( implode( ':', $key_parts ) );
	}

	/**
	 * Get cache TTL.
	 *
	 * @since 1.0.0
	 * @return int TTL in seconds.
	 */
	private function get_ttl() {
		return $this->config->get( 'enrichment_cache_ttl', self::DEFAULT_TTL );
	}

	/**
	 * Check if object cache is available.
	 *
	 * @since 1.0.0
	 * @return bool True if available.
	 */
	private function is_object_cache_available() {
		return wp_using_ext_object_cache();
	}

	/**
	 * Cleanup expired cache entries.
	 *
	 * @since 1.0.0
	 * @return int Number of deleted entries.
	 */
	public function cleanup_expired() {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE expires_at < %s",
				current_time( 'mysql' )
			)
		);

		$this->logger->info( 'Enrichment cache cleanup', array( 'deleted' => $deleted ) );

		return intval( $deleted );
	}

	/**
	 * Clear all cache.
	 *
	 * @since 1.0.0
	 * @param string $cache_type Optional cache type to clear.
	 * @param string $provider   Optional provider to clear.
	 * @return int Number of deleted entries.
	 */
	public function clear( $cache_type = null, $provider = null ) {
		global $wpdb;

		// Clear memory cache
		$this->memory_cache = array();

		// Clear object cache group
		if ( $this->is_object_cache_available() ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}

		// Build delete query
		$where = array();
		$values = array();

		if ( $cache_type ) {
			$where[] = 'cache_type = %s';
			$values[] = $cache_type;
		}

		if ( $provider ) {
			$where[] = 'provider = %s';
			$values[] = $provider;
		}

		if ( empty( $where ) ) {
			// Clear all
			$deleted = $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
			
			// Clear all transients
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					'%' . $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
				)
			);
		} else {
			$where_clause = implode( ' AND ', $where );
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table_name} WHERE $where_clause",
					$values
				)
			);
		}

		$this->logger->info( 'Enrichment cache cleared', array(
			'cache_type' => $cache_type,
			'provider'   => $provider,
			'deleted'    => $deleted,
		) );

		return intval( $deleted );
	}

	/**
	 * Get cache by email.
	 *
	 * @since 1.0.0
	 * @param string $email Email address.
	 * @return array|null Cached data or null.
	 */
	public function get_by_email( $email ) {
		return $this->get( $email, 'email' );
	}

	/**
	 * Set cache by email.
	 *
	 * @since 1.0.0
	 * @param string $email Email address.
	 * @param array  $data  Data to cache.
	 * @return bool True on success.
	 */
	public function set_by_email( $email, $data ) {
		return $this->set( $email, $data, 'email' );
	}

	/**
	 * Get cache by domain.
	 *
	 * @since 1.0.0
	 * @param string $domain Domain name.
	 * @return array|null Cached data or null.
	 */
	public function get_by_domain( $domain ) {
		return $this->get( $domain, 'domain' );
	}

	/**
	 * Set cache by domain.
	 *
	 * @since 1.0.0
	 * @param string $domain Domain name.
	 * @param array  $data   Data to cache.
	 * @return bool True on success.
	 */
	public function set_by_domain( $domain, $data ) {
		return $this->set( $domain, $data, 'domain' );
	}

	/**
	 * Get cache by company.
	 *
	 * @since 1.0.0
	 * @param string $company Company name.
	 * @return array|null Cached data or null.
	 */
	public function get_by_company( $company ) {
		return $this->get( $company, 'company' );
	}

	/**
	 * Set cache by company.
	 *
	 * @since 1.0.0
	 * @param string $company Company name.
	 * @param array  $data    Data to cache.
	 * @return bool True on success.
	 */
	public function set_by_company( $company, $data ) {
		return $this->set( $company, $data, 'company' );
	}

	/**
	 * Warm cache for a list of emails.
	 *
	 * @since 1.0.0
	 * @param array $emails Email addresses.
	 * @return array Results.
	 */
	public function warm( $emails ) {
		if ( ! class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Enricher' ) ) {
			return array( 'error' => 'Enricher not available' );
		}

		$enricher = new WP_AI_Chatbot_LeadGen_Pro_Lead_Enricher();
		$results = array(
			'warmed'  => 0,
			'skipped' => 0,
			'failed'  => 0,
		);

		foreach ( $emails as $email ) {
			// Skip if already cached
			if ( $this->exists( $email, 'email' ) ) {
				$results['skipped']++;
				continue;
			}

			// Find lead with this email
			if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
				$storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
				$lead = $storage->get_by_email( $email );

				if ( $lead ) {
					$result = $enricher->enrich( $lead['id'], array( 'force' => false ) );

					if ( ! empty( $result['success'] ) ) {
						$results['warmed']++;
					} else {
						$results['failed']++;
					}
				} else {
					$results['skipped']++;
				}
			}

			// Rate limiting
			usleep( 500000 ); // 500ms between requests
		}

		return $results;
	}

	/**
	 * Get cache statistics.
	 *
	 * @since 1.0.0
	 * @return array Statistics.
	 */
	public function get_statistics() {
		global $wpdb;

		$stats = array();

		// Total entries
		$stats['total_entries'] = intval( $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name}"
		) );

		// Active (non-expired) entries
		$stats['active_entries'] = intval( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE expires_at > %s",
				current_time( 'mysql' )
			)
		) );

		// Expired entries
		$stats['expired_entries'] = $stats['total_entries'] - $stats['active_entries'];

		// By type
		$by_type = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cache_type, COUNT(*) as count FROM {$this->table_name} WHERE expires_at > %s GROUP BY cache_type",
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		$stats['by_type'] = array();
		foreach ( $by_type as $row ) {
			$stats['by_type'][ $row['cache_type'] ] = intval( $row['count'] );
		}

		// By provider
		$by_provider = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT provider, COUNT(*) as count FROM {$this->table_name} WHERE expires_at > %s AND provider IS NOT NULL GROUP BY provider",
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		$stats['by_provider'] = array();
		foreach ( $by_provider as $row ) {
			$stats['by_provider'][ $row['provider'] ] = intval( $row['count'] );
		}

		// Total hits
		$stats['total_hits'] = intval( $wpdb->get_var(
			"SELECT SUM(hit_count) FROM {$this->table_name}"
		) );

		// Most accessed
		$most_accessed = $wpdb->get_results(
			"SELECT lookup_value, cache_type, hit_count FROM {$this->table_name} ORDER BY hit_count DESC LIMIT 10",
			ARRAY_A
		);
		$stats['most_accessed'] = $most_accessed;

		// Cache size (approximate)
		$stats['size_bytes'] = intval( $wpdb->get_var(
			"SELECT SUM(LENGTH(data)) FROM {$this->table_name}"
		) );
		$stats['size_formatted'] = size_format( $stats['size_bytes'] );

		// Hit rate (if we track misses)
		$stats['avg_hits_per_entry'] = $stats['total_entries'] > 0 
			? round( $stats['total_hits'] / $stats['total_entries'], 2 ) 
			: 0;

		// Object cache status
		$stats['object_cache_available'] = $this->is_object_cache_available();

		return $stats;
	}

	/**
	 * Get cached entries for a lookup value pattern.
	 *
	 * @since 1.0.0
	 * @param string $pattern   Search pattern.
	 * @param int    $limit     Number of results.
	 * @return array Matching entries.
	 */
	public function search( $pattern, $limit = 20 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cache_key, cache_type, lookup_value, provider, hit_count, expires_at, created_at
				FROM {$this->table_name}
				WHERE lookup_value LIKE %s
				ORDER BY hit_count DESC
				LIMIT %d",
				'%' . $wpdb->esc_like( $pattern ) . '%',
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Extend TTL for frequently accessed entries.
	 *
	 * @since 1.0.0
	 * @param int $hit_threshold Minimum hits to extend.
	 * @param int $extension     Extension in seconds.
	 * @return int Number of entries extended.
	 */
	public function extend_popular( $hit_threshold = 5, $extension = null ) {
		global $wpdb;

		$extension = $extension ?? $this->get_ttl();
		$new_expiry = date( 'Y-m-d H:i:s', time() + $extension );

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table_name} SET expires_at = %s WHERE hit_count >= %d AND expires_at > %s",
				$new_expiry,
				$hit_threshold,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * AJAX handler for getting cache stats.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_stats() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$stats = $this->get_statistics();
		wp_send_json_success( $stats );
	}

	/**
	 * AJAX handler for clearing cache.
	 *
	 * @since 1.0.0
	 */
	public function ajax_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$cache_type = sanitize_text_field( $_POST['cache_type'] ?? '' ) ?: null;
		$provider = sanitize_text_field( $_POST['provider'] ?? '' ) ?: null;

		$deleted = $this->clear( $cache_type, $provider );

		wp_send_json_success( array(
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %d: number of entries deleted */
				__( 'Cleared %d cache entries', 'wp-ai-chatbot-leadgen-pro' ),
				$deleted
			),
		) );
	}

	/**
	 * AJAX handler for warming cache.
	 *
	 * @since 1.0.0
	 */
	public function ajax_warm_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$emails = isset( $_POST['emails'] ) ? array_map( 'sanitize_email', (array) $_POST['emails'] ) : array();

		if ( empty( $emails ) ) {
			wp_send_json_error( array( 'message' => 'No emails provided' ), 400 );
		}

		$results = $this->warm( $emails );
		wp_send_json_success( $results );
	}

	/**
	 * Export cache data for backup/migration.
	 *
	 * @since 1.0.0
	 * @return array Export data.
	 */
	public function export() {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cache_type, lookup_value, provider, data, expires_at FROM {$this->table_name} WHERE expires_at > %s",
				current_time( 'mysql' )
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Import cache data from export.
	 *
	 * @since 1.0.0
	 * @param array $data Export data.
	 * @return array Results.
	 */
	public function import( $data ) {
		$results = array(
			'imported' => 0,
			'skipped'  => 0,
		);

		foreach ( $data as $entry ) {
			$entry_data = is_string( $entry['data'] ) ? json_decode( $entry['data'], true ) : $entry['data'];

			if ( ! $this->exists( $entry['lookup_value'], $entry['cache_type'], $entry['provider'] ) ) {
				$this->set(
					$entry['lookup_value'],
					$entry_data,
					$entry['cache_type'],
					$entry['provider']
				);
				$results['imported']++;
			} else {
				$results['skipped']++;
			}
		}

		return $results;
	}
}

