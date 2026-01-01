<?php
/**
 * Helper functions and utilities.
 *
 * Provides helper methods for file paths, URLs, and common utilities.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Helpers {

	/**
	 * Get plugin version.
	 *
	 * @since 1.0.0
	 * @return string Plugin version.
	 */
	public static function get_version() {
		return defined( 'WP_AI_CHATBOT_LEADGEN_PRO_VERSION' ) ? WP_AI_CHATBOT_LEADGEN_PRO_VERSION : '1.0.0';
	}

	/**
	 * Get plugin directory path.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Plugin directory path.
	 */
	public static function get_path( $path = '' ) {
		$base_path = defined( 'WP_AI_CHATBOT_LEADGEN_PRO_PATH' ) ? WP_AI_CHATBOT_LEADGEN_PRO_PATH : plugin_dir_path( dirname( __FILE__ ) );
		return $base_path . ltrim( $path, '/' );
	}

	/**
	 * Get plugin directory URL.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Plugin directory URL.
	 */
	public static function get_url( $path = '' ) {
		$base_url = defined( 'WP_AI_CHATBOT_LEADGEN_PRO_URL' ) ? WP_AI_CHATBOT_LEADGEN_PRO_URL : plugin_dir_url( dirname( __FILE__ ) );
		return $base_url . ltrim( $path, '/' );
	}

	/**
	 * Get plugin basename.
	 *
	 * @since 1.0.0
	 * @return string Plugin basename.
	 */
	public static function get_basename() {
		return defined( 'WP_AI_CHATBOT_LEADGEN_PRO_BASENAME' ) ? WP_AI_CHATBOT_LEADGEN_PRO_BASENAME : plugin_basename( dirname( dirname( __FILE__ ) ) . '/wp-ai-chatbot-leadgen-pro.php' );
	}

	/**
	 * Get includes directory path.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Includes directory path.
	 */
	public static function get_includes_path( $path = '' ) {
		return self::get_path( 'includes/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get includes directory URL.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Includes directory URL.
	 */
	public static function get_includes_url( $path = '' ) {
		return self::get_url( 'includes/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get admin directory path.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Admin directory path.
	 */
	public static function get_admin_path( $path = '' ) {
		return self::get_path( 'admin/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get admin directory URL.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Admin directory URL.
	 */
	public static function get_admin_url( $path = '' ) {
		return self::get_url( 'admin/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get assets directory path.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Assets directory path.
	 */
	public static function get_assets_path( $path = '' ) {
		return self::get_path( 'assets/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get assets directory URL.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Assets directory URL.
	 */
	public static function get_assets_url( $path = '' ) {
		return self::get_url( 'assets/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get JavaScript directory path.
	 *
	 * @since 1.0.0
	 * @param string $file Optional. JavaScript file name.
	 * @return string JavaScript directory path.
	 */
	public static function get_js_path( $file = '' ) {
		return self::get_assets_path( 'js/' . ltrim( $file, '/' ) );
	}

	/**
	 * Get JavaScript directory URL.
	 *
	 * @since 1.0.0
	 * @param string $file Optional. JavaScript file name.
	 * @return string JavaScript directory URL.
	 */
	public static function get_js_url( $file = '' ) {
		return self::get_assets_url( 'js/' . ltrim( $file, '/' ) );
	}

	/**
	 * Get CSS directory path.
	 *
	 * @since 1.0.0
	 * @param string $file Optional. CSS file name.
	 * @return string CSS directory path.
	 */
	public static function get_css_path( $file = '' ) {
		return self::get_assets_path( 'css/' . ltrim( $file, '/' ) );
	}

	/**
	 * Get CSS directory URL.
	 *
	 * @since 1.0.0
	 * @param string $file Optional. CSS file name.
	 * @return string CSS directory URL.
	 */
	public static function get_css_url( $file = '' ) {
		return self::get_assets_url( 'css/' . ltrim( $file, '/' ) );
	}

	/**
	 * Get images directory path.
	 *
	 * @since 1.0.0
	 * @param string $file Optional. Image file name.
	 * @return string Images directory path.
	 */
	public static function get_images_path( $file = '' ) {
		return self::get_assets_path( 'images/' . ltrim( $file, '/' ) );
	}

	/**
	 * Get images directory URL.
	 *
	 * @since 1.0.0
	 * @param string $file Optional. Image file name.
	 * @return string Images directory URL.
	 */
	public static function get_images_url( $file = '' ) {
		return self::get_assets_url( 'images/' . ltrim( $file, '/' ) );
	}

	/**
	 * Get languages directory path.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Languages directory path.
	 */
	public static function get_languages_path( $path = '' ) {
		return self::get_path( 'languages/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get templates directory path.
	 *
	 * @since 1.0.0
	 * @param string $file Optional. Template file name.
	 * @return string Templates directory path.
	 */
	public static function get_templates_path( $file = '' ) {
		return self::get_path( 'templates/' . ltrim( $file, '/' ) );
	}

	/**
	 * Get vendor directory path.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Vendor directory path.
	 */
	public static function get_vendor_path( $path = '' ) {
		return self::get_path( 'vendor/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get tests directory path.
	 *
	 * @since 1.0.0
	 * @param string $path Optional. Additional path to append.
	 * @return string Tests directory path.
	 */
	public static function get_tests_path( $path = '' ) {
		return self::get_path( 'tests/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get plugin file path.
	 *
	 * @since 1.0.0
	 * @return string Main plugin file path.
	 */
	public static function get_plugin_file() {
		return self::get_path( 'wp-ai-chatbot-leadgen-pro.php' );
	}

	/**
	 * Get plugin name.
	 *
	 * @since 1.0.0
	 * @return string Plugin name.
	 */
	public static function get_plugin_name() {
		return 'WP AI Chatbot LeadGen Pro';
	}

	/**
	 * Get plugin slug.
	 *
	 * @since 1.0.0
	 * @return string Plugin slug.
	 */
	public static function get_plugin_slug() {
		return 'wp-ai-chatbot-leadgen-pro';
	}

	/**
	 * Get plugin text domain.
	 *
	 * @since 1.0.0
	 * @return string Text domain.
	 */
	public static function get_text_domain() {
		return 'wp-ai-chatbot-leadgen-pro';
	}

	/**
	 * Get minimum WordPress version required.
	 *
	 * @since 1.0.0
	 * @return string Minimum WordPress version.
	 */
	public static function get_min_wp_version() {
		return '5.8';
	}

	/**
	 * Get minimum PHP version required.
	 *
	 * @since 1.0.0
	 * @return string Minimum PHP version.
	 */
	public static function get_min_php_version() {
		return '7.4';
	}

	/**
	 * Check if file exists in plugin directory.
	 *
	 * @since 1.0.0
	 * @param string $file_path File path relative to plugin root.
	 * @return bool True if file exists, false otherwise.
	 */
	public static function file_exists( $file_path ) {
		return file_exists( self::get_path( $file_path ) );
	}

	/**
	 * Require a file from plugin directory.
	 *
	 * @since 1.0.0
	 * @param string $file_path File path relative to plugin root.
	 * @return bool True if file was included, false otherwise.
	 */
	public static function require_file( $file_path ) {
		$full_path = self::get_path( $file_path );
		if ( file_exists( $full_path ) ) {
			require_once $full_path;
			return true;
		}
		return false;
	}

	/**
	 * Get file modification time.
	 *
	 * @since 1.0.0
	 * @param string $file_path File path relative to plugin root.
	 * @return int|false File modification time or false on failure.
	 */
	public static function get_file_time( $file_path ) {
		$full_path = self::get_path( $file_path );
		if ( file_exists( $full_path ) ) {
			return filemtime( $full_path );
		}
		return false;
	}

	/**
	 * Get asset URL with version for cache busting.
	 *
	 * @since 1.0.0
	 * @param string $file_path File path relative to assets directory.
	 * @return string Asset URL with version parameter.
	 */
	public static function get_asset_url( $file_path ) {
		$url = self::get_assets_url( $file_path );
		$file_time = self::get_file_time( 'assets/' . $file_path );
		
		if ( $file_time ) {
			$url = add_query_arg( 'v', $file_time, $url );
		} else {
			$url = add_query_arg( 'v', self::get_version(), $url );
		}

		return $url;
	}

	/**
	 * Sanitize file path.
	 *
	 * @since 1.0.0
	 * @param string $path File path.
	 * @return string Sanitized file path.
	 */
	public static function sanitize_path( $path ) {
		return str_replace( array( '\\', '/' ), DIRECTORY_SEPARATOR, $path );
	}

	/**
	 * Normalize file path for cross-platform compatibility.
	 *
	 * @since 1.0.0
	 * @param string $path File path.
	 * @return string Normalized file path.
	 */
	public static function normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '|/+|', '/', $path );
		return $path;
	}

	/**
	 * Get relative path from plugin root.
	 *
	 * @since 1.0.0
	 * @param string $full_path Full file path.
	 * @return string Relative path from plugin root.
	 */
	public static function get_relative_path( $full_path ) {
		$plugin_path = self::get_path();
		$full_path = self::normalize_path( $full_path );
		$plugin_path = self::normalize_path( $plugin_path );

		if ( strpos( $full_path, $plugin_path ) === 0 ) {
			return substr( $full_path, strlen( $plugin_path ) );
		}

		return $full_path;
	}

	/**
	 * Check if we're in admin area.
	 *
	 * @since 1.0.0
	 * @return bool True if in admin, false otherwise.
	 */
	public static function is_admin() {
		return is_admin();
	}

	/**
	 * Check if we're on frontend.
	 *
	 * @since 1.0.0
	 * @return bool True if on frontend, false otherwise.
	 */
	public static function is_frontend() {
		return ! is_admin();
	}

	/**
	 * Check if we're in AJAX request.
	 *
	 * @since 1.0.0
	 * @return bool True if AJAX, false otherwise.
	 */
	public static function is_ajax() {
		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}

	/**
	 * Check if we're in REST API request.
	 *
	 * @since 1.0.0
	 * @return bool True if REST API, false otherwise.
	 */
	public static function is_rest() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Check if we're in cron request.
	 *
	 * @since 1.0.0
	 * @return bool True if cron, false otherwise.
	 */
	public static function is_cron() {
		return defined( 'DOING_CRON' ) && DOING_CRON;
	}
}

