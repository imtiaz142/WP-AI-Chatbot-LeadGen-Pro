<?php
/**
 * Plugin Name: WP AI Chatbot LeadGen Pro
 * Plugin URI: https://example.com/wp-ai-chatbot-leadgen-pro
 * Description: Enterprise-grade WordPress plugin that transforms how businesses engage with website visitors through intelligent, context-aware conversations using AI, RAG, and comprehensive lead intelligence.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-chatbot-leadgen-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Network: true
 *
 * @package WP_AI_Chatbot_LeadGen_Pro
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 */
define( 'WP_AI_CHATBOT_LEADGEN_PRO_VERSION', '1.0.0' );

/**
 * Plugin directory path.
 */
define( 'WP_AI_CHATBOT_LEADGEN_PRO_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'WP_AI_CHATBOT_LEADGEN_PRO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'WP_AI_CHATBOT_LEADGEN_PRO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-activator.php
 */
function activate_wp_ai_chatbot_leadgen_pro() {
	require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-activator.php';
	WP_AI_Chatbot_LeadGen_Pro_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-deactivator.php
 */
function deactivate_wp_ai_chatbot_leadgen_pro() {
	require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-deactivator.php';
	WP_AI_Chatbot_LeadGen_Pro_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_wp_ai_chatbot_leadgen_pro' );
register_deactivation_hook( __FILE__, 'deactivate_wp_ai_chatbot_leadgen_pro' );

/**
 * Autoloader for plugin classes.
 */
require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-autoloader.php';
new WP_AI_Chatbot_LeadGen_Pro_Autoloader();

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-plugin.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_wp_ai_chatbot_leadgen_pro() {
	$plugin = new WP_AI_Chatbot_LeadGen_Pro_Plugin();
	$plugin->run();
}
run_wp_ai_chatbot_leadgen_pro();

