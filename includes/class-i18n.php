<?php
/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_i18n {

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'wp-ai-chatbot-leadgen-pro',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);
	}
}

