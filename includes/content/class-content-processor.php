<?php
/**
 * Content Processor.
 *
 * Extracts clean text from content, removes navigation/boilerplate, and chunks content optimally.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Content_Processor {

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
	 * Default chunk size in characters.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_chunk_size = 1000;

	/**
	 * Default chunk overlap in characters.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_chunk_overlap = 200;

	/**
	 * Minimum chunk size in characters.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $min_chunk_size = 100;

	/**
	 * Maximum chunk size in characters.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_chunk_size = 2000;

	/**
	 * Selectors for elements to remove (navigation, headers, footers, etc.).
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $remove_selectors = array(
		'nav',
		'header',
		'footer',
		'.navigation',
		'.nav',
		'.header',
		'.footer',
		'.sidebar',
		'.widget',
		'.comments',
		'.comment-form',
		'.breadcrumb',
		'.breadcrumbs',
		'[role="navigation"]',
		'[role="banner"]',
		'[role="contentinfo"]',
		'[role="complementary"]',
		'script',
		'style',
		'noscript',
		'iframe',
		'form',
		'.wp-block-navigation',
		'.wp-block-site-header',
		'.wp-block-site-footer',
	);

	/**
	 * Selectors for main content areas.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $content_selectors = array(
		'main',
		'article',
		'.entry-content',
		'.post-content',
		'.content',
		'[role="main"]',
		'.wp-block-post-content',
		'#content',
		'#main-content',
	);

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
	 * Process content from URL.
	 *
	 * @since 1.0.0
	 * @param string $url  URL to process.
	 * @param array  $args Optional. Processing arguments.
	 * @return array|WP_Error Processed content chunks or WP_Error on failure.
	 */
	public function process_url( $url, $args = array() ) {
		$defaults = array(
			'chunk_size'    => $this->default_chunk_size,
			'chunk_overlap' => $this->default_chunk_overlap,
			'remove_boilerplate' => true,
			'extract_main_content' => true,
		);

		$args = wp_parse_args( $args, $defaults );

		// Fetch content
		$html = $this->fetch_content( $url );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		// Extract clean text
		$clean_text = $this->extract_clean_text( $html, $args );

		if ( empty( $clean_text ) ) {
			return new WP_Error(
				'no_content',
				__( 'No content could be extracted from the URL.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Chunk content
		$chunks = $this->chunk_content( $clean_text, $args );

		return array(
			'url'         => $url,
			'content'     => $clean_text,
			'chunks'      => $chunks,
			'chunk_count' => count( $chunks ),
			'word_count'  => str_word_count( $clean_text ),
			'char_count'  => strlen( $clean_text ),
		);
	}

	/**
	 * Fetch content from URL.
	 *
	 * @since 1.0.0
	 * @param string $url URL to fetch.
	 * @return string|WP_Error HTML content or WP_Error on failure.
	 */
	public function fetch_content( $url ) {
		// Check if this is a WordPress post/page/product
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				return $this->get_post_content( $post );
			}
		}

		// Fetch from external URL
		$response = wp_remote_get( $url, array(
			'timeout'   => 30,
			'sslverify' => false,
			'headers'   => array(
				'User-Agent' => 'WP-AI-Chatbot-LeadGen-Pro/1.0',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->logger->warning(
				'Failed to fetch content',
				array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code !== 200 || empty( $body ) ) {
			return new WP_Error(
				'fetch_failed',
				sprintf( __( 'Failed to fetch content. Status code: %d', 'wp-ai-chatbot-leadgen-pro' ), $status_code )
			);
		}

		return $body;
	}

	/**
	 * Get content from WordPress post.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post Post object.
	 * @return string HTML content.
	 */
	private function get_post_content( $post ) {
		// Get post content
		$content = $post->post_content;

		// Apply WordPress filters
		$content = apply_filters( 'the_content', $content );

		// Get post title
		$title = $post->post_title;

		// Wrap in HTML structure for processing
		$html = '<article>';
		$html .= '<h1>' . esc_html( $title ) . '</h1>';
		$html .= $content;
		$html .= '</article>';

		return $html;
	}

	/**
	 * Extract clean text from HTML.
	 *
	 * @since 1.0.0
	 * @param string $html HTML content.
	 * @param array  $args Processing arguments.
	 * @return string Clean text.
	 */
	public function extract_clean_text( $html, $args = array() ) {
		if ( empty( $html ) ) {
			return '';
		}

		// Load HTML into DOMDocument
		$dom = $this->load_html( $html );

		if ( false === $dom ) {
			// Fallback: strip HTML tags
			return $this->strip_html_fallback( $html );
		}

		// Remove unwanted elements
		if ( $args['remove_boilerplate'] ) {
			$this->remove_elements( $dom, $this->remove_selectors );
		}

		// Extract main content area if requested
		if ( $args['extract_main_content'] ) {
			$content = $this->extract_main_content( $dom );
			if ( ! empty( $content ) ) {
				$dom = $this->load_html( $content );
			}
		}

		// Extract text
		$text = $this->extract_text_from_dom( $dom );

		// Clean up text
		$text = $this->clean_text( $text );

		return $text;
	}

	/**
	 * Load HTML into DOMDocument.
	 *
	 * @since 1.0.0
	 * @param string $html HTML content.
	 * @return DOMDocument|false DOMDocument object or false on failure.
	 */
	private function load_html( $html ) {
		// Suppress errors for malformed HTML
		libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		$dom->encoding = 'UTF-8';

		// Add UTF-8 meta tag if not present
		if ( stripos( $html, '<meta' ) === false || stripos( $html, 'charset' ) === false ) {
			$html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
		}

		// Load HTML
		$loaded = @$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );

		libxml_clear_errors();

		if ( ! $loaded ) {
			return false;
		}

		return $dom;
	}

	/**
	 * Remove elements from DOM using selectors.
	 *
	 * @since 1.0.0
	 * @param DOMDocument $dom       DOMDocument object.
	 * @param array       $selectors Array of CSS selectors.
	 */
	private function remove_elements( $dom, $selectors ) {
		$xpath = new DOMXPath( $dom );

		foreach ( $selectors as $selector ) {
			// Convert CSS selector to XPath (simplified)
			$xpath_query = $this->css_to_xpath( $selector );

			if ( ! empty( $xpath_query ) ) {
				$nodes = $xpath->query( $xpath_query );

				if ( $nodes ) {
					foreach ( $nodes as $node ) {
						if ( $node->parentNode ) {
							$node->parentNode->removeChild( $node );
						}
					}
				}
			}
		}
	}

	/**
	 * Convert CSS selector to XPath (simplified).
	 *
	 * @since 1.0.0
	 * @param string $selector CSS selector.
	 * @return string XPath query.
	 */
	private function css_to_xpath( $selector ) {
		// Simple conversion for common selectors
		$selector = trim( $selector );

		// Tag selector
		if ( preg_match( '/^[a-z][a-z0-9]*$/i', $selector ) ) {
			return '//' . $selector;
		}

		// Class selector
		if ( strpos( $selector, '.' ) === 0 ) {
			$class = substr( $selector, 1 );
			return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
		}

		// ID selector
		if ( strpos( $selector, '#' ) === 0 ) {
			$id = substr( $selector, 1 );
			return "//*[@id='{$id}']";
		}

		// Attribute selector
		if ( preg_match( '/^\[([^\]]+)\]$/', $selector, $matches ) ) {
			$attr = $matches[1];
			if ( strpos( $attr, '=' ) !== false ) {
				list( $name, $value ) = explode( '=', $attr, 2 );
				$value = trim( $value, '"\' ' );
				return "//*[@{$name}='{$value}']";
			} else {
				return "//*[@{$attr}]";
			}
		}

		// Fallback: try as tag name
		return '//' . $selector;
	}

	/**
	 * Extract main content area from DOM.
	 *
	 * @since 1.0.0
	 * @param DOMDocument $dom DOMDocument object.
	 * @return string HTML content or empty string.
	 */
	private function extract_main_content( $dom ) {
		$xpath = new DOMXPath( $dom );

		// Try each content selector
		foreach ( $this->content_selectors as $selector ) {
			$xpath_query = $this->css_to_xpath( $selector );
			$nodes = $xpath->query( $xpath_query );

			if ( $nodes && $nodes->length > 0 ) {
				$node = $nodes->item( 0 );
				return $dom->saveHTML( $node );
			}
		}

		// Fallback: get body content
		$body = $dom->getElementsByTagName( 'body' );
		if ( $body->length > 0 ) {
			return $dom->saveHTML( $body->item( 0 ) );
		}

		return '';
	}

	/**
	 * Extract text from DOMDocument.
	 *
	 * @since 1.0.0
	 * @param DOMDocument $dom DOMDocument object.
	 * @return string Extracted text.
	 */
	private function extract_text_from_dom( $dom ) {
		$body = $dom->getElementsByTagName( 'body' );

		if ( $body->length === 0 ) {
			return '';
		}

		return $this->extract_text_from_node( $body->item( 0 ) );
	}

	/**
	 * Extract text from DOM node recursively.
	 *
	 * @since 1.0.0
	 * @param DOMNode $node DOM node.
	 * @return string Extracted text.
	 */
	private function extract_text_from_node( $node ) {
		$text = '';

		// Add text content
		if ( $node->nodeType === XML_TEXT_NODE ) {
			$text .= $node->textContent;
		}

		// Process child nodes
		if ( $node->hasChildNodes() ) {
			foreach ( $node->childNodes as $child ) {
				$text .= $this->extract_text_from_node( $child );
			}
		}

		// Add line breaks for block elements
		$block_elements = array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'br' );
		if ( in_array( strtolower( $node->nodeName ), $block_elements, true ) ) {
			$text .= "\n";
		}

		return $text;
	}

	/**
	 * Clean extracted text.
	 *
	 * @since 1.0.0
	 * @param string $text Raw text.
	 * @return string Cleaned text.
	 */
	private function clean_text( $text ) {
		// Normalize whitespace
		$text = preg_replace( '/\s+/', ' ', $text );

		// Remove excessive line breaks
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		// Trim
		$text = trim( $text );

		// Remove non-printable characters (except newlines and tabs)
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		return $text;
	}

	/**
	 * Strip HTML tags (fallback method).
	 *
	 * @since 1.0.0
	 * @param string $html HTML content.
	 * @return string Plain text.
	 */
	private function strip_html_fallback( $html ) {
		// Remove script and style tags
		$html = preg_replace( '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi', '', $html );
		$html = preg_replace( '/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/gi', '', $html );

		// Strip HTML tags
		$text = wp_strip_all_tags( $html );

		// Decode HTML entities
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Clean up
		$text = $this->clean_text( $text );

		return $text;
	}

	/**
	 * Chunk content into optimally-sized pieces.
	 *
	 * @since 1.0.0
	 * @param string $text Content text.
	 * @param array  $args Chunking arguments.
	 * @return array Array of content chunks.
	 */
	public function chunk_content( $text, $args = array() ) {
		$defaults = array(
			'chunk_size'    => $this->default_chunk_size,
			'chunk_overlap' => $this->default_chunk_overlap,
			'chunk_method'  => 'sentence', // 'sentence', 'paragraph', 'fixed'
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate chunk size
		$chunk_size = max( $this->min_chunk_size, min( $this->max_chunk_size, intval( $args['chunk_size'] ) ) );
		$chunk_overlap = max( 0, min( $chunk_size / 2, intval( $args['chunk_overlap'] ) ) );

		switch ( $args['chunk_method'] ) {
			case 'sentence':
				return $this->chunk_by_sentence( $text, $chunk_size, $chunk_overlap );

			case 'paragraph':
				return $this->chunk_by_paragraph( $text, $chunk_size, $chunk_overlap );

			case 'fixed':
			default:
				return $this->chunk_fixed( $text, $chunk_size, $chunk_overlap );
		}
	}

	/**
	 * Chunk content by sentences.
	 *
	 * @since 1.0.0
	 * @param string $text         Content text.
	 * @param int    $chunk_size   Chunk size in characters.
	 * @param int    $chunk_overlap Overlap size in characters.
	 * @return array Array of chunks.
	 */
	private function chunk_by_sentence( $text, $chunk_size, $chunk_overlap ) {
		// Split into sentences
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		$chunks = array();
		$current_chunk = '';
		$current_size = 0;

		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			$sentence_size = strlen( $sentence );

			if ( $current_size + $sentence_size > $chunk_size && ! empty( $current_chunk ) ) {
				// Save current chunk
				$chunks[] = array(
					'content'    => trim( $current_chunk ),
					'char_count' => strlen( $current_chunk ),
					'word_count' => str_word_count( $current_chunk ),
				);

				// Start new chunk with overlap
				if ( $chunk_overlap > 0 ) {
					$overlap_text = substr( $current_chunk, -$chunk_overlap );
					$current_chunk = $overlap_text . ' ' . $sentence;
					$current_size = strlen( $current_chunk );
				} else {
					$current_chunk = $sentence;
					$current_size = $sentence_size;
				}
			} else {
				$current_chunk .= ( ! empty( $current_chunk ) ? ' ' : '' ) . $sentence;
				$current_size += $sentence_size + 1; // +1 for space
			}
		}

		// Add remaining chunk
		if ( ! empty( $current_chunk ) ) {
			$chunks[] = array(
				'content'    => trim( $current_chunk ),
				'char_count' => strlen( $current_chunk ),
				'word_count' => str_word_count( $current_chunk ),
			);
		}

		return $chunks;
	}

	/**
	 * Chunk content by paragraphs.
	 *
	 * @since 1.0.0
	 * @param string $text         Content text.
	 * @param int    $chunk_size   Chunk size in characters.
	 * @param int    $chunk_overlap Overlap size in characters.
	 * @return array Array of chunks.
	 */
	private function chunk_by_paragraph( $text, $chunk_size, $chunk_overlap ) {
		// Split into paragraphs
		$paragraphs = preg_split( '/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY );

		$chunks = array();
		$current_chunk = '';
		$current_size = 0;

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			$paragraph_size = strlen( $paragraph );

			if ( $current_size + $paragraph_size > $chunk_size && ! empty( $current_chunk ) ) {
				// Save current chunk
				$chunks[] = array(
					'content'    => trim( $current_chunk ),
					'char_count' => strlen( $current_chunk ),
					'word_count' => str_word_count( $current_chunk ),
				);

				// Start new chunk with overlap
				if ( $chunk_overlap > 0 ) {
					$overlap_text = substr( $current_chunk, -$chunk_overlap );
					$current_chunk = $overlap_text . "\n\n" . $paragraph;
					$current_size = strlen( $current_chunk );
				} else {
					$current_chunk = $paragraph;
					$current_size = $paragraph_size;
				}
			} else {
				$current_chunk .= ( ! empty( $current_chunk ) ? "\n\n" : '' ) . $paragraph;
				$current_size += $paragraph_size + 2; // +2 for newlines
			}
		}

		// Add remaining chunk
		if ( ! empty( $current_chunk ) ) {
			$chunks[] = array(
				'content'    => trim( $current_chunk ),
				'char_count' => strlen( $current_chunk ),
				'word_count' => str_word_count( $current_chunk ),
			);
		}

		return $chunks;
	}

	/**
	 * Chunk content with fixed-size chunks.
	 *
	 * @since 1.0.0
	 * @param string $text         Content text.
	 * @param int    $chunk_size   Chunk size in characters.
	 * @param int    $chunk_overlap Overlap size in characters.
	 * @return array Array of chunks.
	 */
	private function chunk_fixed( $text, $chunk_size, $chunk_overlap ) {
		$chunks = array();
		$text_length = strlen( $text );
		$position = 0;

		while ( $position < $text_length ) {
			$chunk = substr( $text, $position, $chunk_size );
			$chunk = trim( $chunk );

			if ( ! empty( $chunk ) ) {
				$chunks[] = array(
					'content'    => $chunk,
					'char_count' => strlen( $chunk ),
					'word_count' => str_word_count( $chunk ),
				);
			}

			$position += $chunk_size - $chunk_overlap;
		}

		return $chunks;
	}
}

