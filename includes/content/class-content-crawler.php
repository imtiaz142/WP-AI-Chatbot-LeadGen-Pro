<?php
/**
 * Content Crawler.
 *
 * Discovers URLs from sitemaps, manual lists, and WordPress posts/pages for content ingestion.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Content_Crawler {

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
	 * Maximum URLs to crawl per source.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_urls_per_source = 10000;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
	}

	/**
	 * Discover URLs from all configured sources.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Crawler arguments.
	 * @return array Array of discovered URLs with metadata.
	 */
	public function discover_urls( $args = array() ) {
		$defaults = array(
			'sources'           => array(), // Empty = all enabled sources
			'include_posts'     => true,
			'include_pages'     => true,
			'include_products'  => true,
			'post_types'        => array( 'post', 'page' ),
			'post_status'       => 'publish',
			'limit'             => 0, // 0 = no limit
			'offset'            => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$discovered_urls = array();

		// Discover from WordPress posts/pages
		if ( $args['include_posts'] || $args['include_pages'] ) {
			$wp_urls = $this->discover_wordpress_urls( $args );
			$discovered_urls = array_merge( $discovered_urls, $wp_urls );
		}

		// Discover from WooCommerce products
		if ( $args['include_products'] && class_exists( 'WooCommerce' ) ) {
			$product_urls = $this->discover_product_urls( $args );
			$discovered_urls = array_merge( $discovered_urls, $product_urls );
		}

		// Discover from sitemaps
		$sitemap_urls = $this->discover_sitemap_urls( $args );
		$discovered_urls = array_merge( $discovered_urls, $sitemap_urls );

		// Discover from manual URL lists
		$manual_urls = $this->discover_manual_urls( $args );
		$discovered_urls = array_merge( $discovered_urls, $manual_urls );

		// Remove duplicates
		$discovered_urls = $this->deduplicate_urls( $discovered_urls );

		// Apply limit and offset
		if ( $args['limit'] > 0 ) {
			$discovered_urls = array_slice( $discovered_urls, $args['offset'], $args['limit'] );
		}

		$this->logger->info(
			'URL discovery completed',
			array(
				'total_urls' => count( $discovered_urls ),
				'sources'    => $args['sources'],
			)
		);

		return $discovered_urls;
	}

	/**
	 * Discover URLs from WordPress posts and pages.
	 *
	 * @since 1.0.0
	 * @param array $args Arguments.
	 * @return array Array of URLs with metadata.
	 */
	public function discover_wordpress_urls( $args = array() ) {
		$defaults = array(
			'post_types'  => array( 'post', 'page' ),
			'post_status' => 'publish',
			'limit'       => 0,
			'offset'      => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => $args['post_types'],
			'post_status'    => $args['post_status'],
			'posts_per_page' => $args['limit'] > 0 ? $args['limit'] : -1,
			'offset'         => $args['offset'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);

		$query = new WP_Query( $query_args );
		$urls = array();

		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$url = get_permalink( $post_id );

			if ( ! $url ) {
				continue;
			}

			$urls[] = array(
				'url'         => $url,
				'source_type' => $post->post_type,
				'source_id'   => $post_id,
				'title'       => $post->post_title,
				'last_modified' => $post->post_modified,
				'post_date'   => $post->post_date,
				'discovery_method' => 'wordpress',
			);
		}

		wp_reset_postdata();

		return $urls;
	}

	/**
	 * Discover URLs from WooCommerce products.
	 *
	 * @since 1.0.0
	 * @param array $args Arguments.
	 * @return array Array of URLs with metadata.
	 */
	public function discover_product_urls( $args = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$defaults = array(
			'limit'  => 0,
			'offset' => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $args['limit'] > 0 ? $args['limit'] : -1,
			'offset'         => $args['offset'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);

		$query = new WP_Query( $query_args );
		$urls = array();

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$url = get_permalink( $product_id );

			if ( ! $url ) {
				continue;
			}

			$urls[] = array(
				'url'         => $url,
				'source_type' => 'product',
				'source_id'   => $product_id,
				'title'       => $product->get_name(),
				'last_modified' => $product->get_date_modified()->date( 'Y-m-d H:i:s' ),
				'post_date'   => $product->get_date_created()->date( 'Y-m-d H:i:s' ),
				'discovery_method' => 'woocommerce',
			);
		}

		wp_reset_postdata();

		return $urls;
	}

	/**
	 * Discover URLs from sitemaps.
	 *
	 * @since 1.0.0
	 * @param array $args Arguments.
	 * @return array Array of URLs with metadata.
	 */
	public function discover_sitemap_urls( $args = array() ) {
		$sitemap_parser = new WP_AI_Chatbot_LeadGen_Pro_Sitemap_Parser();

		$sitemap_urls = $this->config->get( 'sitemap_urls', array() );

		if ( empty( $sitemap_urls ) ) {
			// Try to auto-detect common sitemap locations
			$sitemap_urls = $sitemap_parser->detect_sitemap_urls();
		}

		if ( empty( $sitemap_urls ) ) {
			return array();
		}

		$all_urls = array();

		foreach ( $sitemap_urls as $sitemap_url ) {
			$urls = $sitemap_parser->parse( $sitemap_url );
			$all_urls = array_merge( $all_urls, $urls );
		}

		return $all_urls;
	}

	/**
	 * Discover URLs from manual URL lists.
	 *
	 * @since 1.0.0
	 * @param array $args Arguments.
	 * @return array Array of URLs with metadata.
	 */
	public function discover_manual_urls( $args = array() ) {
		$manual_urls = $this->config->get( 'manual_urls', array() );

		if ( empty( $manual_urls ) ) {
			return array();
		}

		$urls = array();

		foreach ( $manual_urls as $url ) {
			if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			$urls[] = array(
				'url'         => esc_url_raw( $url ),
				'source_type' => 'manual',
				'source_id'   => null,
				'title'       => null,
				'last_modified' => null,
				'discovery_method' => 'manual',
			);
		}

		return $urls;
	}

	/**
	 * Remove duplicate URLs from discovered list.
	 *
	 * @since 1.0.0
	 * @param array $urls Array of URLs with metadata.
	 * @return array Deduplicated URLs.
	 */
	private function deduplicate_urls( $urls ) {
		$seen = array();
		$deduplicated = array();

		foreach ( $urls as $url_data ) {
			$url = isset( $url_data['url'] ) ? $url_data['url'] : '';

			if ( empty( $url ) ) {
				continue;
			}

			// Normalize URL for comparison
			$normalized = $this->normalize_url( $url );

			if ( isset( $seen[ $normalized ] ) ) {
				// URL already seen, merge metadata if needed
				$existing = $seen[ $normalized ];
				if ( empty( $existing['title'] ) && ! empty( $url_data['title'] ) ) {
					$deduplicated[ $existing['index'] ]['title'] = $url_data['title'];
				}
				if ( empty( $existing['last_modified'] ) && ! empty( $url_data['last_modified'] ) ) {
					$deduplicated[ $existing['index'] ]['last_modified'] = $url_data['last_modified'];
				}
				continue;
			}

			$seen[ $normalized ] = array(
				'index' => count( $deduplicated ),
			);

			$deduplicated[] = $url_data;
		}

		return array_values( $deduplicated );
	}

	/**
	 * Normalize URL for comparison.
	 *
	 * @since 1.0.0
	 * @param string $url URL to normalize.
	 * @return string Normalized URL.
	 */
	private function normalize_url( $url ) {
		$url = trim( $url );
		$url = rtrim( $url, '/' );
		$url = strtolower( $url );

		// Remove query parameters and fragments for comparison
		$parsed = wp_parse_url( $url );
		$normalized = '';

		if ( isset( $parsed['scheme'] ) ) {
			$normalized .= $parsed['scheme'] . '://';
		}

		if ( isset( $parsed['host'] ) ) {
			$normalized .= $parsed['host'];
		}

		if ( isset( $parsed['path'] ) ) {
			$normalized .= $parsed['path'];
		}

		return $normalized;
	}

	/**
	 * Check if URL exists (returns 200 status).
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return bool True if URL exists, false otherwise.
	 */
	private function url_exists( $url ) {
		$response = wp_remote_head( $url, array(
			'timeout' => 5,
			'sslverify' => false,
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return $status_code === 200;
	}
}

