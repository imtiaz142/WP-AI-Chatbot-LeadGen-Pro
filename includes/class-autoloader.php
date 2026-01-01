<?php
/**
 * Autoloader class for the plugin.
 *
 * Handles automatic loading of plugin classes based on naming conventions.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Autoloader {

	/**
	 * Base namespace for plugin classes.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $namespace = 'WP_AI_Chatbot_LeadGen_Pro';

	/**
	 * Base directory for plugin classes.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $base_dir;

	/**
	 * Class name to file path mapping for special cases.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $class_map = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->base_dir = WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/';
		$this->register();
		$this->load_class_map();
	}

	/**
	 * Register the autoloader with PHP.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Autoload function.
	 *
	 * @since 1.0.0
	 * @param string $class_name The class name to load.
	 */
	public function autoload( $class_name ) {
		// Check if class is in our namespace
		if ( 0 !== strpos( $class_name, $this->namespace ) ) {
			return;
		}

		// Check class map first for special cases
		if ( isset( $this->class_map[ $class_name ] ) ) {
			$file = $this->class_map[ $class_name ];
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}

		// Remove namespace prefix
		$class_name = str_replace( $this->namespace . '_', '', $class_name );

		// Convert class name to file path
		$file_path = $this->get_file_path( $class_name );

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Convert class name to file path.
	 *
	 * @since 1.0.0
	 * @param string $class_name The class name without namespace.
	 * @return string The file path.
	 */
	private function get_file_path( $class_name ) {
		// Convert underscores to directory separators
		$parts = explode( '_', $class_name );

		// Handle special cases for directory structure
		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		// Build directory path based on class name parts
		$directory = $this->base_dir;

		// Map class prefixes to directories
		if ( in_array( 'Provider', $parts, true ) ) {
			$directory .= 'providers/';
		} elseif ( in_array( 'RAG', $parts, true ) || in_array( 'Embedding', $parts, true ) || in_array( 'Vector', $parts, true ) || in_array( 'Hybrid', $parts, true ) || in_array( 'Reranker', $parts, true ) || in_array( 'Context', $parts, true ) || in_array( 'Citation', $parts, true ) ) {
			$directory .= 'rag/';
		} elseif ( in_array( 'Ingestion', $parts, true ) || in_array( 'Crawler', $parts, true ) || in_array( 'Processor', $parts, true ) || in_array( 'Sitemap', $parts, true ) || in_array( 'Indexer', $parts, true ) || in_array( 'Queue', $parts, true ) ) {
			$directory .= 'ingestion/';
		} elseif ( in_array( 'Conversation', $parts, true ) || in_array( 'Message', $parts, true ) || in_array( 'Intent', $parts, true ) || in_array( 'Sentiment', $parts, true ) || in_array( 'Memory', $parts, true ) || in_array( 'Response', $parts, true ) || in_array( 'Feedback', $parts, true ) ) {
			$directory .= 'conversation/';
		} elseif ( in_array( 'Lead', $parts, true ) || in_array( 'Behavior', $parts, true ) || in_array( 'Scorer', $parts, true ) || in_array( 'Grader', $parts, true ) || in_array( 'Enricher', $parts, true ) || in_array( 'Segmenter', $parts, true ) ) {
			$directory .= 'leads/';
		} elseif ( in_array( 'Analytics', $parts, true ) || in_array( 'Metrics', $parts, true ) || in_array( 'Report', $parts, true ) || in_array( 'Funnel', $parts, true ) || in_array( 'Gap', $parts, true ) ) {
			$directory .= 'analytics/';
		} elseif ( in_array( 'Integration', $parts, true ) || in_array( 'Webhook', $parts, true ) || in_array( 'Enrichment', $parts, true ) ) {
			$directory .= 'integrations/';
			// Handle subdirectories for integrations
			if ( in_array( 'CRM', $parts, true ) || in_array( 'Salesforce', $parts, true ) || in_array( 'HubSpot', $parts, true ) || in_array( 'Pipedrive', $parts, true ) || in_array( 'Zoho', $parts, true ) ) {
				$directory .= 'crm/';
			} elseif ( in_array( 'Email', $parts, true ) || in_array( 'Mailchimp', $parts, true ) || in_array( 'ActiveCampaign', $parts, true ) || in_array( 'ConvertKit', $parts, true ) ) {
				$directory .= 'email/';
			} elseif ( in_array( 'Scheduling', $parts, true ) || in_array( 'Calendly', $parts, true ) ) {
				$directory .= 'scheduling/';
			} elseif ( in_array( 'Messaging', $parts, true ) || in_array( 'WhatsApp', $parts, true ) ) {
				$directory .= 'messaging/';
			}
		} elseif ( in_array( 'Email', $parts, true ) && ( in_array( 'Automation', $parts, true ) || in_array( 'Drip', $parts, true ) || in_array( 'Template', $parts, true ) || in_array( 'Personalizer', $parts, true ) ) ) {
			$directory .= 'email/';
		} elseif ( in_array( 'Security', $parts, true ) || in_array( 'Encryption', $parts, true ) || in_array( 'Rate', $parts, true ) || in_array( 'PII', $parts, true ) || in_array( 'Access', $parts, true ) || in_array( 'Audit', $parts, true ) ) {
			$directory .= 'security/';
		} elseif ( in_array( 'Privacy', $parts, true ) || in_array( 'GDPR', $parts, true ) || in_array( 'CCPA', $parts, true ) || in_array( 'Data', $parts, true ) && ( in_array( 'Export', $parts, true ) || in_array( 'Deletion', $parts, true ) ) ) {
			$directory .= 'privacy/';
		} elseif ( in_array( 'Cache', $parts, true ) || in_array( 'Transient', $parts, true ) || in_array( 'Redis', $parts, true ) || in_array( 'Memcached', $parts, true ) ) {
			$directory .= 'cache/';
		} elseif ( in_array( 'Queue', $parts, true ) && in_array( 'Background', $parts, true ) || in_array( 'Job', $parts, true ) ) {
			$directory .= 'queue/';
		} elseif ( in_array( 'Hook', $parts, true ) || in_array( 'Action', $parts, true ) || in_array( 'Filter', $parts, true ) ) {
			$directory .= 'hooks/';
		} elseif ( in_array( 'API', $parts, true ) || in_array( 'REST', $parts, true ) || in_array( 'Authentication', $parts, true ) || in_array( 'Endpoint', $parts, true ) ) {
			$directory .= 'api/';
			// Handle API endpoints subdirectory
			if ( in_array( 'Endpoint', $parts, true ) ) {
				$directory .= 'endpoints/';
			}
		} elseif ( in_array( 'Config', $parts, true ) && ( in_array( 'Industry', $parts, true ) || in_array( 'SaaS', $parts, true ) || in_array( 'Ecommerce', $parts, true ) || in_array( 'Services', $parts, true ) || in_array( 'Education', $parts, true ) ) ) {
			$directory .= 'configs/';
		} elseif ( in_array( 'Frontend', $parts, true ) || in_array( 'Widget', $parts, true ) || in_array( 'Asset', $parts, true ) || in_array( 'Shortcode', $parts, true ) ) {
			$directory .= 'frontend/';
		} elseif ( in_array( 'Admin', $parts, true ) || in_array( 'Dashboard', $parts, true ) || in_array( 'Settings', $parts, true ) ) {
			// Admin classes can be in admin/ or includes/admin/ directory
			// Check if it's a content ingestion admin (in includes/admin/)
			if ( in_array( 'Content', $parts, true ) && in_array( 'Ingestion', $parts, true ) ) {
				$directory .= 'admin/';
			} else {
				// Other admin classes are in admin/ directory at root
				$directory = WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'admin/';
			}
		}

		return $directory . $file_name;
	}

	/**
	 * Load class map for special cases.
	 *
	 * @since 1.0.0
	 */
	private function load_class_map() {
		// Add special class mappings here if needed
		// Example: $this->class_map['WP_AI_Chatbot_LeadGen_Pro_Special_Class'] = WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/special/class-special-class.php';
		
		// Core classes that don't follow the standard pattern
		$this->class_map['WP_AI_Chatbot_LeadGen_Pro_Plugin'] = $this->base_dir . 'class-plugin.php';
		$this->class_map['WP_AI_Chatbot_LeadGen_Pro_Activator'] = $this->base_dir . 'class-activator.php';
		$this->class_map['WP_AI_Chatbot_LeadGen_Pro_Deactivator'] = $this->base_dir . 'class-deactivator.php';
		$this->class_map['WP_AI_Chatbot_LeadGen_Pro_Database'] = $this->base_dir . 'class-database.php';
		$this->class_map['WP_AI_Chatbot_LeadGen_Pro_Config'] = $this->base_dir . 'class-config.php';
		$this->class_map['WP_AI_Chatbot_LeadGen_Pro_Multisite'] = $this->base_dir . 'class-multisite.php';
	}
}

