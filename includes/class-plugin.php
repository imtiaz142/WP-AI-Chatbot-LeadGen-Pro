<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Plugin {

	/**
	 * The loader that's responsible for maintaining and registering all hooks.
	 *
	 * @since    1.0.0
	 * @var      WP_AI_Chatbot_LeadGen_Pro_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'WP_AI_CHATBOT_LEADGEN_PRO_VERSION' ) ) {
			$this->version = WP_AI_CHATBOT_LEADGEN_PRO_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'wp-ai-chatbot-leadgen-pro';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_common_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    1.0.0
	 */
	private function load_dependencies() {
		// Load helper classes
		require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-helpers.php';
		require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-database.php';
		require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-config.php';
		require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-multisite.php';
		require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-logger.php';
		require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-migrations.php';

		// Load hook loader class
		require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-loader.php';

		// Initialize loader
		$this->loader = new WP_AI_Chatbot_LeadGen_Pro_Loader();

		// Run database migrations if needed
		WP_AI_Chatbot_LeadGen_Pro_Database::maybe_run_migrations();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * @since    1.0.0
	 */
	private function set_locale() {
		$plugin_i18n = new WP_AI_Chatbot_LeadGen_Pro_i18n();
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all of the hooks related to the admin area functionality.
	 *
	 * @since    1.0.0
	 */
	private function define_admin_hooks() {
		// Content Ingestion Admin
		$content_ingestion_admin = new WP_AI_Chatbot_LeadGen_Pro_Content_Ingestion_Admin();
		$content_ingestion_admin->register_hooks();

		// Content Manager Admin
		$content_manager_admin = new WP_AI_Chatbot_LeadGen_Pro_Content_Manager_Admin();
		$content_manager_admin->register_hooks();

		// Scheduled Re-indexer
		$scheduled_reindexer = new WP_AI_Chatbot_LeadGen_Pro_Scheduled_Reindexer();
		$scheduled_reindexer->register_hooks();
		$scheduled_reindexer->init_scheduling();
	}

	/**
	 * Register all of the hooks related to the public-facing functionality.
	 *
	 * @since    1.0.0
	 */
	private function define_public_hooks() {
		// Public hooks will be added here when frontend classes are created
		// Example:
		// $plugin_public = new WP_AI_Chatbot_LeadGen_Pro_Public( $this->get_plugin_name(), $this->get_version() );
		// $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		// $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
	}

	/**
	 * Register common hooks that apply to both admin and public.
	 *
	 * @since    1.0.0
	 */
	private function define_common_hooks() {
		// Initialize multisite support
		$multisite = new WP_AI_Chatbot_LeadGen_Pro_Multisite();

		// Hook into new site creation (multisite)
		if ( is_multisite() ) {
			$this->loader->add_action( 'wp_initialize_site', $multisite, 'init_site', 10, 1 );
			$this->loader->add_action( 'wp_delete_site', $multisite, 'cleanup_site', 10, 1 );
		}

		// Register REST API routes
		$this->loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );

		// Register AJAX handlers
		$this->loader->add_action( 'wp_ajax_wp_ai_chatbot', $this, 'handle_ajax_request' );
		$this->loader->add_action( 'wp_ajax_nopriv_wp_ai_chatbot', $this, 'handle_ajax_request' );

		// Register activation/deactivation hooks for multisite
		if ( is_multisite() ) {
			register_activation_hook( WP_AI_CHATBOT_LEADGEN_PRO_BASENAME, array( $this, 'activate_multisite' ) );
		}
	}

	/**
	 * Register REST API routes.
	 *
	 * @since    1.0.0
	 */
	public function register_rest_routes() {
		// REST API routes will be registered here when API classes are created
		// Example:
		// $rest_api = new WP_AI_Chatbot_LeadGen_Pro_REST_API();
		// $rest_api->register_routes();
	}

	/**
	 * Handle AJAX requests.
	 *
	 * @since    1.0.0
	 */
	public function handle_ajax_request() {
		// AJAX handlers will be implemented here when needed
		// Check nonce, verify permissions, process request
		check_ajax_referer( 'wp_ai_chatbot_ajax', 'nonce' );

		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

		// Route to appropriate handler based on action
		// This will be expanded when AJAX functionality is implemented
		wp_send_json_error( array( 'message' => 'AJAX handler not implemented yet' ) );
	}

	/**
	 * Activate plugin for all sites in multisite network.
	 *
	 * @since    1.0.0
	 */
	public function activate_multisite() {
		if ( ! is_multisite() ) {
			return;
		}

		$multisite = new WP_AI_Chatbot_LeadGen_Pro_Multisite();
		$sites = $multisite->get_sites();

		foreach ( $sites as $site ) {
			$multisite->init_site( $site->blog_id );
		}
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    WP_AI_Chatbot_LeadGen_Pro_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}

