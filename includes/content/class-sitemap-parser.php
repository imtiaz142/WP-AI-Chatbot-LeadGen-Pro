<?php
/**
 * Sitemap Parser.
 *
 * Parses XML sitemaps to extract URLs and metadata.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Sitemap_Parser {

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Maximum URLs to parse per sitemap.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_urls_per_sitemap = 10000;

	/**
	 * Maximum depth for nested sitemap indexes.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_depth = 5;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Parse sitemap and extract URLs.
	 *
	 * @since 1.0.0
	 * @param string $sitemap_url Sitemap URL to parse.
	 * @param int    $depth       Current recursion depth (for nested sitemaps).
	 * @return array Array of URLs with metadata.
	 */
	public function parse( $sitemap_url, $depth = 0 ) {
		if ( $depth > $this->max_depth ) {
			$this->logger->warning(
				'Maximum sitemap depth reached',
				array(
					'sitemap_url' => $sitemap_url,
					'depth'       => $depth,
				)
			);
			return array();
		}

		// Fetch sitemap content
		$response = $this->fetch_sitemap( $sitemap_url );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = $response['body'];
		$status_code = $response['status_code'];

		if ( $status_code !== 200 || empty( $body ) ) {
			$this->logger->warning(
				'Invalid sitemap response',
				array(
					'sitemap_url' => $sitemap_url,
					'status_code' => $status_code,
				)
			);
			return array();
		}

		// Parse XML
		$xml = $this->parse_xml( $body, $sitemap_url );

		if ( false === $xml ) {
			return array();
		}

		// Check if this is a sitemap index
		if ( $this->is_sitemap_index( $xml ) ) {
			return $this->parse_sitemap_index( $xml, $sitemap_url, $depth );
		}

		// Parse regular sitemap with URLs
		return $this->parse_urlset( $xml, $sitemap_url );
	}

	/**
	 * Fetch sitemap content from URL.
	 *
	 * @since 1.0.0
	 * @param string $sitemap_url Sitemap URL.
	 * @return array|WP_Error Array with 'body' and 'status_code', or WP_Error on failure.
	 */
	public function fetch_sitemap( $sitemap_url ) {
		$response = wp_remote_get( $sitemap_url, array(
			'timeout'   => 30,
			'sslverify' => false, // Allow self-signed certificates
			'headers'   => array(
				'User-Agent' => 'WP-AI-Chatbot-LeadGen-Pro/1.0',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->logger->warning(
				'Failed to fetch sitemap',
				array(
					'sitemap_url' => $sitemap_url,
					'error'       => $response->get_error_message(),
				)
			);
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$status_code = wp_remote_retrieve_response_code( $response );

		return array(
			'body'       => $body,
			'status_code' => $status_code,
		);
	}

	/**
	 * Parse XML string.
	 *
	 * @since 1.0.0
	 * @param string $xml_string XML string to parse.
	 * @param string $sitemap_url Sitemap URL (for error logging).
	 * @return SimpleXMLElement|false Parsed XML object or false on failure.
	 */
	private function parse_xml( $xml_string, $sitemap_url ) {
		// Suppress XML errors
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_string );

		if ( false === $xml ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();

			$this->logger->warning(
				'Failed to parse sitemap XML',
				array(
					'sitemap_url' => $sitemap_url,
					'errors'      => array_map( function( $error ) {
						return array(
							'level'   => $error->level,
							'code'    => $error->code,
							'message' => trim( $error->message ),
							'line'    => $error->line,
						);
					}, $errors ),
				)
			);
			return false;
		}

		libxml_clear_errors();
		return $xml;
	}

	/**
	 * Check if XML is a sitemap index.
	 *
	 * @since 1.0.0
	 * @param SimpleXMLElement $xml Parsed XML object.
	 * @return bool True if sitemap index, false otherwise.
	 */
	private function is_sitemap_index( $xml ) {
		// Check for sitemapindex namespace or sitemap elements
		$namespaces = $xml->getNamespaces( true );

		// Check if it's a sitemap index
		if ( isset( $xml->sitemap ) ) {
			return true;
		}

		// Check namespaces for sitemap index
		foreach ( $namespaces as $prefix => $namespace ) {
			if ( strpos( $namespace, 'sitemap' ) !== false ) {
				$sitemaps = $xml->children( $namespace )->sitemap;
				if ( count( $sitemaps ) > 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Parse sitemap index (nested sitemaps).
	 *
	 * @since 1.0.0
	 * @param SimpleXMLElement $xml         Parsed XML object.
	 * @param string           $sitemap_url Parent sitemap URL.
	 * @param int              $depth       Current recursion depth.
	 * @return array Array of URLs with metadata.
	 */
	private function parse_sitemap_index( $xml, $sitemap_url, $depth ) {
		$urls = array();

		// Get namespaces
		$namespaces = $xml->getNamespaces( true );
		$default_namespace = isset( $namespaces[''] ) ? $namespaces[''] : '';

		// Parse sitemap entries
		foreach ( $xml->sitemap as $sitemap_entry ) {
			$loc = $this->extract_text( $sitemap_entry->loc, $default_namespace );

			if ( empty( $loc ) ) {
				continue;
			}

			$lastmod = $this->extract_text( $sitemap_entry->lastmod, $default_namespace );

			// Recursively parse nested sitemap
			$nested_urls = $this->parse( $loc, $depth + 1 );
			$urls = array_merge( $urls, $nested_urls );
		}

		// Also check namespaced sitemaps
		foreach ( $namespaces as $prefix => $namespace ) {
			if ( $prefix === '' ) {
				continue; // Already processed
			}

			$sitemaps = $xml->children( $namespace )->sitemap;
			foreach ( $sitemaps as $sitemap_entry ) {
				$loc = $this->extract_text( $sitemap_entry->loc, $namespace );

				if ( empty( $loc ) ) {
					continue;
				}

				$nested_urls = $this->parse( $loc, $depth + 1 );
				$urls = array_merge( $urls, $nested_urls );
			}
		}

		return $urls;
	}

	/**
	 * Parse URL set (regular sitemap with URLs).
	 *
	 * @since 1.0.0
	 * @param SimpleXMLElement $xml         Parsed XML object.
	 * @param string           $sitemap_url Sitemap URL.
	 * @return array Array of URLs with metadata.
	 */
	private function parse_urlset( $xml, $sitemap_url ) {
		$urls = array();

		// Get namespaces
		$namespaces = $xml->getNamespaces( true );
		$default_namespace = isset( $namespaces[''] ) ? $namespaces[''] : '';

		// Parse URL entries
		foreach ( $xml->url as $url_entry ) {
			$loc = $this->extract_text( $url_entry->loc, $default_namespace );

			if ( empty( $loc ) ) {
				continue;
			}

			$lastmod = $this->extract_text( $url_entry->lastmod, $default_namespace );
			$changefreq = $this->extract_text( $url_entry->changefreq, $default_namespace );
			$priority = $this->extract_text( $url_entry->priority, $default_namespace );

			// Extract image data if present
			$images = array();
			if ( isset( $url_entry->image ) || isset( $namespaces['image'] ) ) {
				$images = $this->extract_images( $url_entry, $namespaces );
			}

			// Extract news data if present
			$news = null;
			if ( isset( $url_entry->news ) || isset( $namespaces['news'] ) ) {
				$news = $this->extract_news( $url_entry, $namespaces );
			}

			$urls[] = array(
				'url'         => esc_url_raw( $loc ),
				'source_type' => 'sitemap',
				'source_id'   => null,
				'title'       => null,
				'last_modified' => ! empty( $lastmod ) ? $lastmod : null,
				'discovery_method' => 'sitemap',
				'changefreq'  => ! empty( $changefreq ) ? $changefreq : null,
				'priority'    => ! empty( $priority ) ? floatval( $priority ) : null,
				'images'      => $images,
				'news'        => $news,
			);

			// Limit URLs per sitemap
			if ( count( $urls ) >= $this->max_urls_per_sitemap ) {
				$this->logger->warning(
					'Sitemap URL limit reached',
					array(
						'sitemap_url' => $sitemap_url,
						'limit'       => $this->max_urls_per_sitemap,
					)
				);
				break;
			}
		}

		// Also check namespaced URLs
		foreach ( $namespaces as $prefix => $namespace ) {
			if ( $prefix === '' || in_array( $prefix, array( 'image', 'news' ), true ) ) {
				continue; // Already processed or handled separately
			}

			$urls_ns = $xml->children( $namespace )->url;
			foreach ( $urls_ns as $url_entry ) {
				$loc = $this->extract_text( $url_entry->loc, $namespace );

				if ( empty( $loc ) ) {
					continue;
				}

				$lastmod = $this->extract_text( $url_entry->lastmod, $namespace );

				$urls[] = array(
					'url'         => esc_url_raw( $loc ),
					'source_type' => 'sitemap',
					'source_id'   => null,
					'title'       => null,
					'last_modified' => ! empty( $lastmod ) ? $lastmod : null,
					'discovery_method' => 'sitemap',
					'changefreq'  => null,
					'priority'    => null,
				);

				if ( count( $urls ) >= $this->max_urls_per_sitemap ) {
					break 2;
				}
			}
		}

		return $urls;
	}

	/**
	 * Extract text from XML element, handling namespaces.
	 *
	 * @since 1.0.0
	 * @param SimpleXMLElement $element   XML element.
	 * @param string           $namespace Namespace.
	 * @return string Extracted text.
	 */
	private function extract_text( $element, $namespace = '' ) {
		if ( ! isset( $element ) ) {
			return '';
		}

		// Try direct access
		if ( is_string( $element ) ) {
			return trim( (string) $element );
		}

		// Try with namespace
		if ( ! empty( $namespace ) ) {
			$children = $element->children( $namespace );
			if ( count( $children ) > 0 ) {
				return trim( (string) $children[0] );
			}
		}

		// Fallback to string cast
		return trim( (string) $element );
	}

	/**
	 * Extract image data from URL entry.
	 *
	 * @since 1.0.0
	 * @param SimpleXMLElement $url_entry URL entry element.
	 * @param array            $namespaces XML namespaces.
	 * @return array Array of image data.
	 */
	private function extract_images( $url_entry, $namespaces ) {
		$images = array();
		$image_namespace = isset( $namespaces['image'] ) ? $namespaces['image'] : 'http://www.google.com/schemas/sitemap-image/1.1';

		if ( isset( $url_entry->image ) ) {
			foreach ( $url_entry->image as $image_entry ) {
				$loc = $this->extract_text( $image_entry->loc, $image_namespace );
				$title = $this->extract_text( $image_entry->title, $image_namespace );
				$caption = $this->extract_text( $image_entry->caption, $image_namespace );

				if ( ! empty( $loc ) ) {
					$images[] = array(
						'loc'     => esc_url_raw( $loc ),
						'title'   => $title,
						'caption' => $caption,
					);
				}
			}
		}

		return $images;
	}

	/**
	 * Extract news data from URL entry.
	 *
	 * @since 1.0.0
	 * @param SimpleXMLElement $url_entry URL entry element.
	 * @param array            $namespaces XML namespaces.
	 * @return array|null News data or null.
	 */
	private function extract_news( $url_entry, $namespaces ) {
		$news_namespace = isset( $namespaces['news'] ) ? $namespaces['news'] : 'http://www.google.com/schemas/sitemap-news/0.9';

		if ( ! isset( $url_entry->news ) ) {
			return null;
		}

		$news_entry = $url_entry->news;
		$publication = $this->extract_text( $news_entry->publication->name, $news_namespace );
		$publication_lang = $this->extract_text( $news_entry->publication->language, $news_namespace );
		$title = $this->extract_text( $news_entry->title, $news_namespace );
		$publication_date = $this->extract_text( $news_entry->publication_date, $news_namespace );

		return array(
			'publication'      => $publication,
			'publication_lang' => $publication_lang,
			'title'            => $title,
			'publication_date' => $publication_date,
		);
	}

	/**
	 * Detect common sitemap URLs for a site.
	 *
	 * @since 1.0.0
	 * @param string $site_url Optional. Site URL. Defaults to home_url().
	 * @return array Array of detected sitemap URLs.
	 */
	public function detect_sitemap_urls( $site_url = '' ) {
		if ( empty( $site_url ) ) {
			$site_url = home_url();
		}

		$sitemap_urls = array();

		// Common sitemap locations
		$common_paths = array(
			'/sitemap.xml',
			'/sitemap_index.xml',
			'/wp-sitemap.xml', // WordPress 5.5+
			'/sitemap-index.xml',
		);

		foreach ( $common_paths as $path ) {
			$url = trailingslashit( $site_url ) . ltrim( $path, '/' );
			if ( $this->url_exists( $url ) ) {
				$sitemap_urls[] = $url;
			}
		}

		// Check for Yoast SEO sitemap
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_sitemap = trailingslashit( $site_url ) . 'sitemap_index.xml';
			if ( $this->url_exists( $yoast_sitemap ) ) {
				$sitemap_urls[] = $yoast_sitemap;
			}
		}

		// Check for Rank Math sitemap
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rankmath_sitemap = trailingslashit( $site_url ) . 'sitemap_index.xml';
			if ( $this->url_exists( $rankmath_sitemap ) ) {
				$sitemap_urls[] = $rankmath_sitemap;
			}
		}

		return array_unique( $sitemap_urls );
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
			'timeout'   => 5,
			'sslverify' => false,
			'headers'   => array(
				'User-Agent' => 'WP-AI-Chatbot-LeadGen-Pro/1.0',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return $status_code === 200;
	}

	/**
	 * Set maximum URLs per sitemap.
	 *
	 * @since 1.0.0
	 * @param int $max Maximum URLs.
	 */
	public function set_max_urls_per_sitemap( $max ) {
		$this->max_urls_per_sitemap = intval( $max );
	}

	/**
	 * Set maximum recursion depth.
	 *
	 * @since 1.0.0
	 * @param int $depth Maximum depth.
	 */
	public function set_max_depth( $depth ) {
		$this->max_depth = intval( $depth );
	}
}

