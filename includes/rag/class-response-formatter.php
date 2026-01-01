<?php
/**
 * Response Formatter.
 *
 * Formats AI-generated responses with embedded citations and clickable links.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/rag
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Response_Formatter {

	/**
	 * Citation tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker
	 */
	private $citation_tracker;

	/**
	 * Config instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->citation_tracker = new WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Format response with citations.
	 *
	 * @since 1.0.0
	 * @param string $response_content Response content from AI.
	 * @param int    $message_id       Optional. Message ID to retrieve citations.
	 * @param array  $citations        Optional. Direct citation data (if message_id not provided).
	 * @param array  $args             Optional. Formatting arguments.
	 * @return string Formatted response with citations.
	 */
	public function format_response( $response_content, $message_id = 0, $citations = array(), $args = array() ) {
		$defaults = array(
			'citation_style'      => 'inline', // 'inline', 'footnote', 'end', 'none'
			'show_citation_links' => true,
			'link_target'         => '_blank',
			'citation_separator'  => ', ',
			'citation_prefix'     => '',
			'citation_suffix'     => '',
			'auto_detect_citations' => true, // Try to detect citation markers in response
			'add_citations_section' => true, // Add citations section at end
		);

		$args = wp_parse_args( $args, $defaults );

		// Get citations if message_id provided
		if ( ! empty( $message_id ) && empty( $citations ) ) {
			$citations = $this->citation_tracker->get_citations( $message_id );
			if ( is_wp_error( $citations ) ) {
				$citations = array();
			}
		}

		// If no citations, return original content
		if ( empty( $citations ) ) {
			return $response_content;
		}

		// Format based on style
		switch ( $args['citation_style'] ) {
			case 'inline':
				return $this->format_inline_citations( $response_content, $citations, $args );

			case 'footnote':
				return $this->format_footnote_citations( $response_content, $citations, $args );

			case 'end':
				return $this->format_end_citations( $response_content, $citations, $args );

			case 'none':
				return $response_content;

			default:
				return $this->format_inline_citations( $response_content, $citations, $args );
		}
	}

	/**
	 * Format response with inline citations.
	 *
	 * @since 1.0.0
	 * @param string $response_content Response content.
	 * @param array  $citations        Citation data.
	 * @param array  $args             Formatting arguments.
	 * @return string Formatted response.
	 */
	private function format_inline_citations( $response_content, $citations, $args ) {
		$formatted = $response_content;

		// Auto-detect citation markers if enabled
		if ( $args['auto_detect_citations'] ) {
			$formatted = $this->replace_citation_markers( $formatted, $citations, $args );
		}

		// Add citations section at end if enabled
		if ( $args['add_citations_section'] ) {
			$citations_html = $this->format_citations_html_direct( $citations, $args );

			if ( ! empty( $citations_html ) ) {
				$formatted .= "\n\n" . '<div class="wp-ai-chatbot-citations-section">' . "\n";
				$formatted .= '<div class="wp-ai-chatbot-citations-title">' . esc_html__( 'Sources:', 'wp-ai-chatbot-leadgen-pro' ) . '</div>' . "\n";
				$formatted .= $citations_html . "\n";
				$formatted .= '</div>';
			}
		}

		return $formatted;
	}

	/**
	 * Format response with footnote-style citations.
	 *
	 * @since 1.0.0
	 * @param string $response_content Response content.
	 * @param array  $citations        Citation data.
	 * @param array  $args             Formatting arguments.
	 * @return string Formatted response.
	 */
	private function format_footnote_citations( $response_content, $citations, $args ) {
		$formatted = $response_content;

		// Auto-detect citation markers if enabled
		if ( $args['auto_detect_citations'] ) {
			$formatted = $this->replace_citation_markers( $formatted, $citations, $args );
		}

		// Add footnotes section
		$footnotes = array();
		foreach ( $citations as $index => $citation ) {
			$number = $index + 1;
			$source_url = isset( $citation['source_url'] ) ? esc_url( $citation['source_url'] ) : '';
			$source_title = isset( $citation['title'] ) ? esc_html( $citation['title'] ) : esc_html( $source_url );

			if ( ! empty( $source_url ) && $args['show_citation_links'] ) {
				$footnote = sprintf(
					'<sup id="fn-%d" class="wp-ai-chatbot-footnote"><a href="%s" target="%s" rel="noopener noreferrer">%d</a></sup>',
					$number,
					$source_url,
					esc_attr( $args['link_target'] ),
					$number
				);
			} else {
				$footnote = sprintf(
					'<sup id="fn-%d" class="wp-ai-chatbot-footnote">%d</sup>',
					$number,
					$number
				);
			}

			$footnotes[] = sprintf(
				'<li id="fnref-%d" class="wp-ai-chatbot-footnote-item">%s <a href="%s" target="%s" rel="noopener noreferrer">%s</a> <a href="#fn-%d" class="wp-ai-chatbot-footnote-back">↩</a></li>',
				$number,
				$number,
				$source_url,
				esc_attr( $args['link_target'] ),
				$source_title,
				$number
			);
		}

		if ( ! empty( $footnotes ) ) {
			$formatted .= "\n\n" . '<div class="wp-ai-chatbot-footnotes">' . "\n";
			$formatted .= '<div class="wp-ai-chatbot-footnotes-title">' . esc_html__( 'References:', 'wp-ai-chatbot-leadgen-pro' ) . '</div>' . "\n";
			$formatted .= '<ol class="wp-ai-chatbot-footnotes-list">' . implode( "\n", $footnotes ) . '</ol>' . "\n";
			$formatted .= '</div>';
		}

		return $formatted;
	}

	/**
	 * Format response with citations at the end.
	 *
	 * @since 1.0.0
	 * @param string $response_content Response content.
	 * @param array  $citations        Citation data.
	 * @param array  $args             Formatting arguments.
	 * @return string Formatted response.
	 */
	private function format_end_citations( $response_content, $citations, $args ) {
		$formatted = $response_content;

		// Add citations section at end
		$citations_html = $this->format_citations_html_direct( $citations, $args );

		if ( ! empty( $citations_html ) ) {
			$formatted .= "\n\n" . '<div class="wp-ai-chatbot-citations-section">' . "\n";
			$formatted .= '<div class="wp-ai-chatbot-citations-title">' . esc_html__( 'Sources:', 'wp-ai-chatbot-leadgen-pro' ) . '</div>' . "\n";
			$formatted .= $citations_html . "\n";
			$formatted .= '</div>';
		}

		return $formatted;
	}

	/**
	 * Replace citation markers in response with clickable links.
	 *
	 * @since 1.0.0
	 * @param string $response_content Response content.
	 * @param array  $citations        Citation data.
	 * @param array  $args             Formatting arguments.
	 * @return string Response with replaced citation markers.
	 */
	private function replace_citation_markers( $response_content, $citations, $args ) {
		// Look for citation markers like [1], [2], [chunk_id], etc.
		$pattern = '/\[(\d+)\]/';

		return preg_replace_callback( $pattern, function( $matches ) use ( $citations, $args ) {
			$marker_number = intval( $matches[1] );

			// Check if marker matches a citation index
			if ( isset( $citations[ $marker_number - 1 ] ) ) {
				$citation = $citations[ $marker_number - 1 ];
				$source_url = isset( $citation['source_url'] ) ? esc_url( $citation['source_url'] ) : '';

				if ( ! empty( $source_url ) && $args['show_citation_links'] ) {
					return sprintf(
						'<sup><a href="%s" target="%s" rel="noopener noreferrer" class="wp-ai-chatbot-citation-link" data-citation-id="%d">[%d]</a></sup>',
						$source_url,
						esc_attr( $args['link_target'] ),
						isset( $citation['chunk_id'] ) ? intval( $citation['chunk_id'] ) : 0,
						$marker_number
					);
				} else {
					return sprintf(
						'<sup class="wp-ai-chatbot-citation-marker">[%d]</sup>',
						$marker_number
					);
				}
			}

			// If no match, return original marker
			return $matches[0];
		}, $response_content );
	}

	/**
	 * Format citations HTML directly from citation data.
	 *
	 * @since 1.0.0
	 * @param array $citations Citation data.
	 * @param array $args      Formatting arguments.
	 * @return string HTML formatted citations.
	 */
	private function format_citations_html_direct( $citations, $args ) {
		if ( empty( $citations ) ) {
			return '';
		}

		$formatted = array();

		foreach ( $citations as $index => $citation ) {
			$parts = array();

			// Citation number
			$number = $index + 1;
			$parts[] = sprintf( '<span class="wp-ai-chatbot-citation-number">[%d]</span>', $number );

			// Source link
			$source_url = isset( $citation['source_url'] ) ? esc_url( $citation['source_url'] ) : '';
			$source_title = isset( $citation['title'] ) ? esc_html( $citation['title'] ) : esc_html( $source_url );

			if ( ! empty( $source_url ) && $args['show_citation_links'] ) {
				$link = sprintf(
					'<a href="%s" target="%s" rel="noopener noreferrer" class="wp-ai-chatbot-citation-link" data-citation-id="%d">%s</a>',
					$source_url,
					esc_attr( $args['link_target'] ),
					isset( $citation['chunk_id'] ) ? intval( $citation['chunk_id'] ) : 0,
					$source_title
				);
				$parts[] = $link;
			} else {
				$parts[] = sprintf( '<span class="wp-ai-chatbot-citation-title">%s</span>', $source_title );
			}

			$formatted[] = '<span class="wp-ai-chatbot-citation-item">' . implode( ' ', $parts ) . '</span>';
		}

		return '<div class="wp-ai-chatbot-citations">' . implode( $args['citation_separator'], $formatted ) . '</div>';
	}

	/**
	 * Format response for JSON API (for chat widget).
	 *
	 * @since 1.0.0
	 * @param string $response_content Response content.
	 * @param int    $message_id       Optional. Message ID.
	 * @param array  $citations        Optional. Citation data.
	 * @param array  $args             Optional. Formatting arguments.
	 * @return array Formatted response data.
	 */
	public function format_response_json( $response_content, $message_id = 0, $citations = array(), $args = array() ) {
		// Get citations if message_id provided
		if ( ! empty( $message_id ) && empty( $citations ) ) {
			$citations = $this->citation_tracker->get_citations( $message_id );
			if ( is_wp_error( $citations ) ) {
				$citations = array();
			}
		}

		// Format HTML response
		$html_content = $this->format_response( $response_content, 0, $citations, $args );

		// Format citations as array
		$citations_array = array();
		foreach ( $citations as $index => $citation ) {
			$citations_array[] = array(
				'number'     => $index + 1,
				'chunk_id'   => isset( $citation['chunk_id'] ) ? intval( $citation['chunk_id'] ) : 0,
				'source_url' => isset( $citation['source_url'] ) ? $citation['source_url'] : '',
				'title'      => isset( $citation['title'] ) ? $citation['title'] : '',
				'source_type' => isset( $citation['source_type'] ) ? $citation['source_type'] : '',
			);
		}

		return array(
			'content'   => $html_content,
			'raw_content' => $response_content,
			'citations' => $citations_array,
			'has_citations' => ! empty( $citations ),
		);
	}

	/**
	 * Extract plain text from formatted HTML response (for accessibility, email, etc.).
	 *
	 * @since 1.0.0
	 * @param string $html_content HTML formatted response.
	 * @return string Plain text version.
	 */
	public function extract_plain_text( $html_content ) {
		// Remove HTML tags but preserve citation markers
		$text = wp_strip_all_tags( $html_content );

		// Clean up whitespace
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		return $text;
	}
}

