<?php
/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Activator {

	/**
	 * Activate the plugin.
	 *
	 * Creates database tables and sets default options.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Table for conversations
		$table_conversations = $wpdb->prefix . 'ai_chatbot_conversations';
		$sql_conversations = "CREATE TABLE IF NOT EXISTS $table_conversations (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id varchar(255) NOT NULL,
			user_id bigint(20) UNSIGNED DEFAULT NULL,
			visitor_id varchar(255) DEFAULT NULL,
			lead_id bigint(20) UNSIGNED DEFAULT NULL,
			status varchar(50) DEFAULT 'active',
			started_at datetime DEFAULT CURRENT_TIMESTAMP,
			ended_at datetime DEFAULT NULL,
			session_duration int(11) DEFAULT 0,
			message_count int(11) DEFAULT 0,
			channel varchar(50) DEFAULT 'website',
			page_url text,
			traffic_source varchar(255) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY user_id (user_id),
			KEY visitor_id (visitor_id),
			KEY lead_id (lead_id),
			KEY status (status),
			KEY started_at (started_at)
		) $charset_collate;";

		// Table for messages
		$table_messages = $wpdb->prefix . 'ai_chatbot_messages';
		$sql_messages = "CREATE TABLE IF NOT EXISTS $table_messages (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) UNSIGNED NOT NULL,
			role varchar(20) NOT NULL,
			content longtext NOT NULL,
			intent varchar(100) DEFAULT NULL,
			intent_confidence decimal(5,4) DEFAULT NULL,
			sentiment varchar(50) DEFAULT NULL,
			sentiment_score decimal(5,4) DEFAULT NULL,
			citations text DEFAULT NULL,
			model_used varchar(100) DEFAULT NULL,
			api_cost decimal(10,6) DEFAULT NULL,
			response_time int(11) DEFAULT NULL,
			feedback varchar(20) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY conversation_id (conversation_id),
			KEY role (role),
			KEY intent (intent),
			KEY created_at (created_at),
			FOREIGN KEY (conversation_id) REFERENCES $table_conversations(id) ON DELETE CASCADE
		) $charset_collate;";

		// Table for leads
		$table_leads = $wpdb->prefix . 'ai_chatbot_leads';
		$sql_leads = "CREATE TABLE IF NOT EXISTS $table_leads (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			email varchar(255) NOT NULL,
			first_name varchar(100) DEFAULT NULL,
			last_name varchar(100) DEFAULT NULL,
			phone varchar(50) DEFAULT NULL,
			company varchar(255) DEFAULT NULL,
			job_title varchar(255) DEFAULT NULL,
			lead_score int(11) DEFAULT 0,
			lead_grade varchar(10) DEFAULT 'D',
			behavioral_score int(11) DEFAULT 0,
			intent_score int(11) DEFAULT 0,
			qualification_score int(11) DEFAULT 0,
			status varchar(50) DEFAULT 'new',
			conversation_id bigint(20) UNSIGNED DEFAULT NULL,
			enriched_data longtext DEFAULT NULL,
			enrichment_status varchar(50) DEFAULT 'pending',
			enriched_at datetime DEFAULT NULL,
			crm_synced tinyint(1) DEFAULT 0,
			crm_id varchar(255) DEFAULT NULL,
			crm_provider varchar(50) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY email (email),
			KEY lead_score (lead_score),
			KEY lead_grade (lead_grade),
			KEY status (status),
			KEY conversation_id (conversation_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// Table for lead behavior tracking
		$table_lead_behavior = $wpdb->prefix . 'ai_chatbot_lead_behavior';
		$sql_lead_behavior = "CREATE TABLE IF NOT EXISTS $table_lead_behavior (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) UNSIGNED NOT NULL,
			visitor_id varchar(255) NOT NULL,
			pages_viewed int(11) DEFAULT 0,
			return_visits int(11) DEFAULT 0,
			total_session_duration int(11) DEFAULT 0,
			topics_discussed text DEFAULT NULL,
			pain_points text DEFAULT NULL,
			buying_stage varchar(50) DEFAULT NULL,
			last_visit datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY visitor_id (visitor_id),
			FOREIGN KEY (lead_id) REFERENCES $table_leads(id) ON DELETE CASCADE
		) $charset_collate;";

		// Table for content chunks
		$table_content_chunks = $wpdb->prefix . 'ai_chatbot_content_chunks';
		$sql_content_chunks = "CREATE TABLE IF NOT EXISTS $table_content_chunks (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type varchar(50) NOT NULL,
			source_url text NOT NULL,
			source_id bigint(20) UNSIGNED DEFAULT NULL,
			chunk_index int(11) NOT NULL,
			content longtext NOT NULL,
			content_hash varchar(64) NOT NULL,
			word_count int(11) DEFAULT 0,
			token_count int(11) DEFAULT 0,
			embedding_model varchar(100) DEFAULT NULL,
			last_updated datetime DEFAULT NULL,
			indexed_at datetime DEFAULT CURRENT_TIMESTAMP,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY source_type (source_type),
			KEY source_id (source_id),
			KEY content_hash (content_hash),
			KEY indexed_at (indexed_at)
		) $charset_collate;";

		// Table for embeddings (vector storage)
		$table_embeddings = $wpdb->prefix . 'ai_chatbot_embeddings';
		$sql_embeddings = "CREATE TABLE IF NOT EXISTS $table_embeddings (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			chunk_id bigint(20) UNSIGNED NOT NULL,
			embedding_model varchar(100) NOT NULL,
			embedding_vector longtext NOT NULL,
			dimension int(11) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY chunk_id (chunk_id),
			KEY embedding_model (embedding_model),
			FOREIGN KEY (chunk_id) REFERENCES $table_content_chunks(id) ON DELETE CASCADE
		) $charset_collate;";

		// Table for lead segments
		$table_segments = $wpdb->prefix . 'ai_chatbot_segments';
		$sql_segments = "CREATE TABLE IF NOT EXISTS $table_segments (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text DEFAULT NULL,
			type varchar(50) DEFAULT 'custom',
			rules longtext DEFAULT NULL,
			lead_count int(11) DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY type (type)
		) $charset_collate;";

		// Table for lead-segment relationships
		$table_lead_segments = $wpdb->prefix . 'ai_chatbot_lead_segments';
		$sql_lead_segments = "CREATE TABLE IF NOT EXISTS $table_lead_segments (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) UNSIGNED NOT NULL,
			segment_id bigint(20) UNSIGNED NOT NULL,
			added_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY lead_segment (lead_id, segment_id),
			KEY lead_id (lead_id),
			KEY segment_id (segment_id),
			FOREIGN KEY (lead_id) REFERENCES $table_leads(id) ON DELETE CASCADE,
			FOREIGN KEY (segment_id) REFERENCES $table_segments(id) ON DELETE CASCADE
		) $charset_collate;";

		// Table for analytics events
		$table_analytics = $wpdb->prefix . 'ai_chatbot_analytics';
		$sql_analytics = "CREATE TABLE IF NOT EXISTS $table_analytics (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type varchar(100) NOT NULL,
			conversation_id bigint(20) UNSIGNED DEFAULT NULL,
			lead_id bigint(20) UNSIGNED DEFAULT NULL,
			event_data longtext DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY conversation_id (conversation_id),
			KEY lead_id (lead_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// Table for A/B tests
		$table_ab_tests = $wpdb->prefix . 'ai_chatbot_ab_tests';
		$sql_ab_tests = "CREATE TABLE IF NOT EXISTS $table_ab_tests (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			test_type varchar(100) NOT NULL,
			status varchar(50) DEFAULT 'draft',
			variants longtext NOT NULL,
			results longtext DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			ended_at datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY test_type (test_type)
		) $charset_collate;";

		// Table for webhooks
		$table_webhooks = $wpdb->prefix . 'ai_chatbot_webhooks';
		$sql_webhooks = "CREATE TABLE IF NOT EXISTS $table_webhooks (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			url text NOT NULL,
			event_type varchar(100) NOT NULL,
			secret_key varchar(255) DEFAULT NULL,
			status varchar(50) DEFAULT 'active',
			last_triggered datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_conversations );
		dbDelta( $sql_messages );
		dbDelta( $sql_leads );
		dbDelta( $sql_lead_behavior );
		dbDelta( $sql_content_chunks );
		dbDelta( $sql_embeddings );
		dbDelta( $sql_segments );
		dbDelta( $sql_lead_segments );
		dbDelta( $sql_analytics );
		dbDelta( $sql_ab_tests );
		dbDelta( $sql_webhooks );

		// Set default options
		self::set_default_options();

		// Store plugin version for future migrations
		update_option( 'wp_ai_chatbot_leadgen_pro_version', WP_AI_CHATBOT_LEADGEN_PRO_VERSION );
		update_option( 'wp_ai_chatbot_leadgen_pro_db_version', '1.0.0' );
	}

	/**
	 * Set default plugin options.
	 *
	 * @since 1.0.0
	 */
	private static function set_default_options() {
		$defaults = array(
			// AI Provider Settings
			'ai_provider'                    => 'openai',
			'default_model'                  => 'gpt-4-turbo-preview',
			'cost_optimization_enabled'      => true,
			'fallback_enabled'               => true,

			// Chat Widget Settings
			'widget_enabled'                 => true,
			'widget_position'                => 'bottom-right',
			'widget_theme'                   => 'light',
			'greeting_message'               => 'Hello! How can I help you today?',

			// Lead Capture Settings
			'lead_capture_enabled'           => true,
			'lead_capture_trigger'           => 'after_engagement',
			'lead_capture_after_messages'    => 3,
			'require_email'                  => true,

			// Content Ingestion Settings
			'auto_index_enabled'             => true,
			'index_sitemap'                  => true,
			'index_posts'                    => true,
			'index_pages'                    => true,
			'reindex_interval'               => 'weekly',

			// Lead Scoring Settings
			'lead_scoring_enabled'           => true,
			'behavioral_weight'              => 0.3,
			'intent_weight'                  => 0.4,
			'qualification_weight'           => 0.3,

			// Privacy & Compliance
			'gdpr_enabled'                   => true,
			'data_retention_days'            => 365,
			'pii_detection_enabled'          => true,

			// Performance
			'caching_enabled'                => true,
			'cache_duration'                 => 3600,
			'background_processing'          => true,

			// Rate Limiting
			'rate_limit_enabled'             => true,
			'rate_limit_per_minute'          => 10,
			'rate_limit_per_hour'            => 100,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( 'wp_ai_chatbot_' . $key ) ) {
				add_option( 'wp_ai_chatbot_' . $key, $value );
			}
		}
	}
}

