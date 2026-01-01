<?php
/**
 * Multisite support class.
 *
 * Handles site-specific configurations and data isolation in WordPress multisite networks.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Multisite {

	/**
	 * Current site ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $current_site_id;

	/**
	 * Whether we're in a multisite network.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $is_multisite;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->is_multisite     = is_multisite();
		$this->current_site_id  = get_current_blog_id();
	}

	/**
	 * Check if multisite is enabled.
	 *
	 * @since 1.0.0
	 * @return bool True if multisite, false otherwise.
	 */
	public function is_multisite() {
		return $this->is_multisite;
	}

	/**
	 * Get current site ID.
	 *
	 * @since 1.0.0
	 * @return int Current site ID.
	 */
	public function get_current_site_id() {
		return $this->current_site_id;
	}

	/**
	 * Switch to a specific site.
	 *
	 * @since 1.0.0
	 * @param int $site_id Site ID to switch to.
	 * @return bool True on success, false on failure.
	 */
	public function switch_to_site( $site_id ) {
		if ( ! $this->is_multisite ) {
			return false;
		}

		if ( ! $this->site_exists( $site_id ) ) {
			return false;
		}

		switch_to_blog( $site_id );
		$this->current_site_id = $site_id;
		return true;
	}

	/**
	 * Restore previous site after switching.
	 *
	 * @since 1.0.0
	 */
	public function restore_site() {
		if ( ! $this->is_multisite ) {
			return;
		}

		restore_current_blog();
		$this->current_site_id = get_current_blog_id();
	}

	/**
	 * Check if a site exists.
	 *
	 * @since 1.0.0
	 * @param int $site_id Site ID.
	 * @return bool True if site exists, false otherwise.
	 */
	public function site_exists( $site_id ) {
		if ( ! $this->is_multisite ) {
			return $site_id === get_current_blog_id();
		}

		$site = get_site( $site_id );
		return ! empty( $site );
	}

	/**
	 * Get all sites in the network.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Array of site objects.
	 */
	public function get_sites( $args = array() ) {
		if ( ! $this->is_multisite ) {
			return array( get_current_site() );
		}

		$defaults = array(
			'number' => 0, // Get all sites.
			'archived' => 0,
			'mature' => 0,
			'spam' => 0,
			'deleted' => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		return get_sites( $args );
	}

	/**
	 * Get site-specific option.
	 *
	 * @since 1.0.0
	 * @param string $option_name Option name.
	 * @param mixed  $default     Default value.
	 * @param int    $site_id     Site ID (optional, uses current site if not provided).
	 * @return mixed Option value.
	 */
	public function get_site_option( $option_name, $default = false, $site_id = null ) {
		if ( ! $this->is_multisite ) {
			return get_option( $option_name, $default );
		}

		if ( null === $site_id ) {
			$site_id = $this->current_site_id;
		}

		$switched = false;
		if ( $site_id !== get_current_blog_id() ) {
			$switched = $this->switch_to_site( $site_id );
		}

		$value = get_option( $option_name, $default );

		if ( $switched ) {
			$this->restore_site();
		}

		return $value;
	}

	/**
	 * Update site-specific option.
	 *
	 * @since 1.0.0
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 * @param int    $site_id     Site ID (optional, uses current site if not provided).
	 * @return bool True on success, false on failure.
	 */
	public function update_site_option( $option_name, $value, $site_id = null ) {
		if ( ! $this->is_multisite ) {
			return update_option( $option_name, $value );
		}

		if ( null === $site_id ) {
			$site_id = $this->current_site_id;
		}

		$switched = false;
		if ( $site_id !== get_current_blog_id() ) {
			$switched = $this->switch_to_site( $site_id );
		}

		$result = update_option( $option_name, $value );

		if ( $switched ) {
			$this->restore_site();
		}

		return $result;
	}

	/**
	 * Delete site-specific option.
	 *
	 * @since 1.0.0
	 * @param string $option_name Option name.
	 * @param int    $site_id     Site ID (optional, uses current site if not provided).
	 * @return bool True on success, false on failure.
	 */
	public function delete_site_option( $option_name, $site_id = null ) {
		if ( ! $this->is_multisite ) {
			return delete_option( $option_name );
		}

		if ( null === $site_id ) {
			$site_id = $this->current_site_id;
		}

		$switched = false;
		if ( $site_id !== get_current_blog_id() ) {
			$switched = $this->switch_to_site( $site_id );
		}

		$result = delete_option( $option_name );

		if ( $switched ) {
			$this->restore_site();
		}

		return $result;
	}

	/**
	 * Get network-wide option.
	 *
	 * @since 1.0.0
	 * @param string $option_name Option name.
	 * @param mixed  $default     Default value.
	 * @return mixed Option value.
	 */
	public function get_network_option( $option_name, $default = false ) {
		if ( ! $this->is_multisite ) {
			return get_option( $option_name, $default );
		}

		return get_site_option( $option_name, $default );
	}

	/**
	 * Update network-wide option.
	 *
	 * @since 1.0.0
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 * @return bool True on success, false on failure.
	 */
	public function update_network_option( $option_name, $value ) {
		if ( ! $this->is_multisite ) {
			return update_option( $option_name, $value );
		}

		return update_site_option( $option_name, $value );
	}

	/**
	 * Delete network-wide option.
	 *
	 * @since 1.0.0
	 * @param string $option_name Option name.
	 * @return bool True on success, false on failure.
	 */
	public function delete_network_option( $option_name ) {
		if ( ! $this->is_multisite ) {
			return delete_option( $option_name );
		}

		return delete_site_option( $option_name );
	}

	/**
	 * Get site-specific table name with site ID prefix.
	 *
	 * In multisite, each site has its own database tables with site ID prefix.
	 *
	 * @since 1.0.0
	 * @param string $table_name Base table name.
	 * @param int    $site_id    Site ID (optional, uses current site if not provided).
	 * @return string Full table name with site prefix.
	 */
	public function get_site_table_name( $table_name, $site_id = null ) {
		global $wpdb;

		if ( ! $this->is_multisite ) {
			return $wpdb->prefix . $table_name;
		}

		if ( null === $site_id ) {
			$site_id = $this->current_site_id;
		}

		// In multisite, table prefix includes site ID
		// e.g., wp_2_ai_chatbot_conversations for site ID 2
		return $wpdb->get_blog_prefix( $site_id ) . $table_name;
	}

	/**
	 * Check if current user is network admin.
	 *
	 * @since 1.0.0
	 * @return bool True if network admin, false otherwise.
	 */
	public function is_network_admin() {
		if ( ! $this->is_multisite ) {
			return current_user_can( 'manage_options' );
		}

		return is_super_admin();
	}

	/**
	 * Check if current user can manage plugin settings for a site.
	 *
	 * @since 1.0.0
	 * @param int $site_id Site ID (optional, uses current site if not provided).
	 * @return bool True if user can manage, false otherwise.
	 */
	public function can_manage_site( $site_id = null ) {
		if ( null === $site_id ) {
			$site_id = $this->current_site_id;
		}

		// Network admins can manage all sites
		if ( $this->is_network_admin() ) {
			return true;
		}

		// Check if user is admin of the specific site
		if ( $site_id !== get_current_blog_id() ) {
			$switched = $this->switch_to_site( $site_id );
			$can_manage = current_user_can( 'manage_options' );
			if ( $switched ) {
				$this->restore_site();
			}
			return $can_manage;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Get site-specific configuration instance.
	 *
	 * @since 1.0.0
	 * @param int $site_id Site ID (optional, uses current site if not provided).
	 * @return WP_AI_Chatbot_LeadGen_Pro_Config Configuration instance.
	 */
	public function get_site_config( $site_id = null ) {
		if ( null === $site_id ) {
			$site_id = $this->current_site_id;
		}

		$switched = false;
		if ( $this->is_multisite && $site_id !== get_current_blog_id() ) {
			$switched = $this->switch_to_site( $site_id );
		}

		$config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();

		if ( $switched ) {
			$this->restore_site();
		}

		return $config;
	}

	/**
	 * Get network-wide configuration instance.
	 *
	 * @since 1.0.0
	 * @return WP_AI_Chatbot_LeadGen_Pro_Config Network configuration instance.
	 */
	public function get_network_config() {
		return WP_AI_Chatbot_LeadGen_Pro_Config::get_network_config();
	}

	/**
	 * Initialize plugin for a new site (multisite).
	 *
	 * Called when a new site is created in a multisite network.
	 *
	 * @since 1.0.0
	 * @param int $site_id New site ID.
	 */
	public function init_site( $site_id ) {
		if ( ! $this->is_multisite ) {
			return;
		}

		$switched = $this->switch_to_site( $site_id );

		if ( $switched ) {
			// Create database tables for this site
			require_once WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'includes/class-activator.php';
			WP_AI_Chatbot_LeadGen_Pro_Activator::activate();

			// Set default options for this site
			$config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
			$config->reset_to_defaults();

			$this->restore_site();
		}
	}

	/**
	 * Clean up plugin data for a deleted site (multisite).
	 *
	 * Called when a site is deleted in a multisite network.
	 *
	 * @since 1.0.0
	 * @param int $site_id Deleted site ID.
	 */
	public function cleanup_site( $site_id ) {
		if ( ! $this->is_multisite ) {
			return;
		}

		// Note: WordPress automatically drops tables when a site is deleted
		// This method can be used for additional cleanup if needed

		// Delete site-specific options (if any remain)
		$switched = $this->switch_to_site( $site_id );

		if ( $switched ) {
			global $wpdb;

			// Delete all plugin options for this site
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					'wp_ai_chatbot_%'
				)
			);

			$this->restore_site();
		}
	}

	/**
	 * Get all sites with plugin active.
	 *
	 * @since 1.0.0
	 * @return array Array of site IDs.
	 */
	public function get_active_sites() {
		if ( ! $this->is_multisite ) {
			return array( get_current_blog_id() );
		}

		$active_sites = array();
		$sites        = $this->get_sites();

		foreach ( $sites as $site ) {
			$switched = $this->switch_to_site( $site->blog_id );

			if ( $switched ) {
				// Check if plugin is active for this site
				if ( is_plugin_active( WP_AI_CHATBOT_LEADGEN_PRO_BASENAME ) ) {
					$active_sites[] = $site->blog_id;
				}

				$this->restore_site();
			}
		}

		return $active_sites;
	}
}

