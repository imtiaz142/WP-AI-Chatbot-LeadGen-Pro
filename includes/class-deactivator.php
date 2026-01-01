<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * Performs cleanup tasks while optionally retaining data based on settings.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Clear scheduled events
		self::clear_scheduled_events();

		// Clear transients and cache
		self::clear_cache();

		// Optionally clean up data if retention policy is set
		$retention_policy = get_option( 'wp_ai_chatbot_data_retention_on_deactivation', 'retain' );
		
		if ( 'delete' === $retention_policy ) {
			self::cleanup_data();
		}

		// Flush rewrite rules if needed
		flush_rewrite_rules();
	}

	/**
	 * Clear all scheduled events (cron jobs).
	 *
	 * @since 1.0.0
	 */
	private static function clear_scheduled_events() {
		// Clear content re-indexing schedule
		$timestamp = wp_next_scheduled( 'wp_ai_chatbot_reindex_content' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_ai_chatbot_reindex_content' );
		}

		// Clear background job processing schedule
		$timestamp = wp_next_scheduled( 'wp_ai_chatbot_process_background_jobs' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_ai_chatbot_process_background_jobs' );
		}

		// Clear lead enrichment schedule
		$timestamp = wp_next_scheduled( 'wp_ai_chatbot_enrich_leads' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_ai_chatbot_enrich_leads' );
		}

		// Clear CRM sync schedule
		$timestamp = wp_next_scheduled( 'wp_ai_chatbot_sync_crm' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_ai_chatbot_sync_crm' );
		}

		// Clear analytics aggregation schedule
		$timestamp = wp_next_scheduled( 'wp_ai_chatbot_aggregate_analytics' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_ai_chatbot_aggregate_analytics' );
		}

		// Clear data retention cleanup schedule
		$timestamp = wp_next_scheduled( 'wp_ai_chatbot_cleanup_old_data' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_ai_chatbot_cleanup_old_data' );
		}
	}

	/**
	 * Clear all plugin-related transients and cache.
	 *
	 * @since 1.0.0
	 */
	private static function clear_cache() {
		global $wpdb;

		// Delete all transients with our prefix
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				OR option_name LIKE %s",
				'_transient_wp_ai_chatbot_%',
				'_transient_timeout_wp_ai_chatbot_%'
			)
		);

		// Clear site transients for multisite
		if ( is_multisite() ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} 
					WHERE meta_key LIKE %s 
					OR meta_key LIKE %s",
					'_site_transient_wp_ai_chatbot_%',
					'_site_transient_timeout_wp_ai_chatbot_%'
				)
			);
		}

		// Clear object cache if available
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'wp_ai_chatbot' );
		}
	}

	/**
	 * Clean up plugin data if retention policy is set to delete.
	 *
	 * This is only called if the admin has explicitly set the retention policy to 'delete'.
	 * By default, data is retained for reactivation.
	 *
	 * @since 1.0.0
	 */
	private static function cleanup_data() {
		global $wpdb;

		// Get table names
		$tables = array(
			$wpdb->prefix . 'ai_chatbot_conversations',
			$wpdb->prefix . 'ai_chatbot_messages',
			$wpdb->prefix . 'ai_chatbot_leads',
			$wpdb->prefix . 'ai_chatbot_lead_behavior',
			$wpdb->prefix . 'ai_chatbot_content_chunks',
			$wpdb->prefix . 'ai_chatbot_embeddings',
			$wpdb->prefix . 'ai_chatbot_segments',
			$wpdb->prefix . 'ai_chatbot_lead_segments',
			$wpdb->prefix . 'ai_chatbot_analytics',
			$wpdb->prefix . 'ai_chatbot_ab_tests',
			$wpdb->prefix . 'ai_chatbot_webhooks',
		);

		// Drop tables (CASCADE will handle foreign key constraints)
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		// Delete all plugin options
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'wp_ai_chatbot_%'
			)
		);

		// Delete site options for multisite
		if ( is_multisite() ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					'wp_ai_chatbot_%'
				)
			);
		}

		// Delete user meta if any
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				'wp_ai_chatbot_%'
			)
		);
	}
}

