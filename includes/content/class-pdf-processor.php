<?php
/**
 * PDF Processor.
 *
 * Extracts text from PDF documents for content ingestion.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_PDF_Processor {

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
	 * Maximum file size in bytes (default 10MB).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $max_file_size = 10485760;

	/**
	 * Allowed MIME types for PDF files.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $allowed_mime_types = array(
		'application/pdf',
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
	 * Process PDF file and extract text.
	 *
	 * @since 1.0.0
	 * @param string $file_path Path to PDF file or URL.
	 * @param array  $args      Optional. Processing arguments.
	 * @return array|WP_Error Extracted text and metadata, or WP_Error on failure.
	 */
	public function process_pdf( $file_path, $args = array() ) {
		$defaults = array(
			'method'           => 'auto', // 'auto', 'library', 'pdftotext', 'api'
			'extract_metadata' => true,
			'extract_images'   => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Download file if URL
		if ( filter_var( $file_path, FILTER_VALIDATE_URL ) ) {
			$file_path = $this->download_pdf( $file_path );
			if ( is_wp_error( $file_path ) ) {
				return $file_path;
			}
		}

		// Validate file
		$validation = $this->validate_pdf_file( $file_path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Extract text based on method
		$method = $args['method'];
		if ( 'auto' === $method ) {
			$method = $this->detect_best_method();
		}

		$text = '';
		$metadata = array();

		switch ( $method ) {
			case 'library':
				$result = $this->extract_with_library( $file_path, $args );
				break;

			case 'pdftotext':
				$result = $this->extract_with_pdftotext( $file_path, $args );
				break;

			case 'api':
				$result = $this->extract_with_api( $file_path, $args );
				break;

			default:
				// Try all methods in order
				$result = $this->extract_with_library( $file_path, $args );
				if ( is_wp_error( $result ) ) {
					$result = $this->extract_with_pdftotext( $file_path, $args );
				}
				if ( is_wp_error( $result ) ) {
					$result = $this->extract_with_api( $file_path, $args );
				}
				break;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = isset( $result['text'] ) ? $result['text'] : '';
		$metadata = isset( $result['metadata'] ) ? $result['metadata'] : array();

		if ( empty( $text ) ) {
			return new WP_Error(
				'no_text_extracted',
				__( 'No text could be extracted from the PDF file.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Clean extracted text
		$text = $this->clean_extracted_text( $text );

		return array(
			'text'     => $text,
			'metadata' => $metadata,
			'method'   => $method,
			'file_path' => $file_path,
			'word_count' => str_word_count( $text ),
			'char_count' => strlen( $text ),
		);
	}

	/**
	 * Download PDF from URL.
	 *
	 * @since 1.0.0
	 * @param string $url PDF URL.
	 * @return string|WP_Error Temporary file path or WP_Error on failure.
	 */
	private function download_pdf( $url ) {
		$response = wp_remote_get( $url, array(
			'timeout'   => 60,
			'sslverify' => false,
			'headers'   => array(
				'User-Agent' => 'WP-AI-Chatbot-LeadGen-Pro/1.0',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->logger->warning(
				'Failed to download PDF',
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
				'download_failed',
				sprintf( __( 'Failed to download PDF. Status code: %d', 'wp-ai-chatbot-leadgen-pro' ), $status_code )
			);
		}

		// Check if content is actually PDF
		if ( substr( $body, 0, 4 ) !== '%PDF' ) {
			return new WP_Error(
				'invalid_pdf',
				__( 'Downloaded file is not a valid PDF.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Save to temporary file
		$temp_file = wp_tempnam( 'pdf_' );
		file_put_contents( $temp_file, $body );

		return $temp_file;
	}

	/**
	 * Validate PDF file.
	 *
	 * @since 1.0.0
	 * @param string $file_path File path.
	 * @return bool|WP_Error True if valid, WP_Error on failure.
	 */
	private function validate_pdf_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'PDF file not found.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'file_not_readable',
				__( 'PDF file is not readable.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$file_size = filesize( $file_path );
		if ( $file_size > $this->max_file_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					__( 'PDF file is too large. Maximum size: %s', 'wp-ai-chatbot-leadgen-pro' ),
					size_format( $this->max_file_size )
				)
			);
		}

		// Check if file is actually a PDF
		$file_handle = fopen( $file_path, 'rb' );
		if ( $file_handle ) {
			$header = fread( $file_handle, 4 );
			fclose( $file_handle );

			if ( $header !== '%PDF' ) {
				return new WP_Error(
					'invalid_pdf',
					__( 'File is not a valid PDF.', 'wp-ai-chatbot-leadgen-pro' )
				);
			}
		}

		return true;
	}

	/**
	 * Detect best extraction method available.
	 *
	 * @since 1.0.0
	 * @return string Method name.
	 */
	private function detect_best_method() {
		// Check for PDF parser library
		if ( class_exists( 'Smalot\PdfParser\Parser' ) ) {
			return 'library';
		}

		// Check for pdftotext command
		if ( $this->is_pdftotext_available() ) {
			return 'pdftotext';
		}

		// Check for API method
		if ( $this->is_api_available() ) {
			return 'api';
		}

		// Default to library (will try to load if available)
		return 'library';
	}

	/**
	 * Extract text using PDF parser library (Smalot\PdfParser).
	 *
	 * @since 1.0.0
	 * @param string $file_path PDF file path.
	 * @param array  $args      Processing arguments.
	 * @return array|WP_Error Extracted text and metadata, or WP_Error on failure.
	 */
	private function extract_with_library( $file_path, $args ) {
		// Check if library is available
		if ( ! class_exists( 'Smalot\PdfParser\Parser' ) ) {
			// Try to load via Composer autoloader
			$autoloader = WP_AI_CHATBOT_LEADGEN_PRO_PATH . 'vendor/autoload.php';
			if ( file_exists( $autoloader ) ) {
				require_once $autoloader;
			}

			if ( ! class_exists( 'Smalot\PdfParser\Parser' ) ) {
				return new WP_Error(
					'library_not_available',
					__( 'PDF parser library is not available. Please install smalot/pdfparser via Composer.', 'wp-ai-chatbot-leadgen-pro' )
				);
			}
		}

		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf = $parser->parseFile( $file_path );

			$text = $pdf->getText();

			$metadata = array();
			if ( $args['extract_metadata'] ) {
				$details = $pdf->getDetails();
				$metadata = array(
					'title'       => isset( $details['Title'] ) ? $details['Title'] : '',
					'author'      => isset( $details['Author'] ) ? $details['Author'] : '',
					'subject'     => isset( $details['Subject'] ) ? $details['Subject'] : '',
					'creator'     => isset( $details['Creator'] ) ? $details['Creator'] : '',
					'producer'    => isset( $details['Producer'] ) ? $details['Producer'] : '',
					'creation_date' => isset( $details['CreationDate'] ) ? $details['CreationDate'] : '',
					'modification_date' => isset( $details['ModDate'] ) ? $details['ModDate'] : '',
					'pages'       => $pdf->getPages() ? count( $pdf->getPages() ) : 0,
				);
			}

			return array(
				'text'     => $text,
				'metadata' => $metadata,
			);
		} catch ( Exception $e ) {
			$this->logger->error(
				'PDF extraction failed with library',
				array(
					'file_path' => $file_path,
					'error'     => $e->getMessage(),
				)
			);

			return new WP_Error(
				'extraction_failed',
				sprintf( __( 'PDF extraction failed: %s', 'wp-ai-chatbot-leadgen-pro' ), $e->getMessage() )
			);
		}
	}

	/**
	 * Extract text using pdftotext command-line tool.
	 *
	 * @since 1.0.0
	 * @param string $file_path PDF file path.
	 * @param array  $args      Processing arguments.
	 * @return array|WP_Error Extracted text and metadata, or WP_Error on failure.
	 */
	private function extract_with_pdftotext( $file_path, $args ) {
		if ( ! $this->is_pdftotext_available() ) {
			return new WP_Error(
				'pdftotext_not_available',
				__( 'pdftotext command-line tool is not available on this server.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Create temporary output file
		$output_file = wp_tempnam( 'pdf_text_' );

		// Build command
		$command = sprintf(
			'pdftotext -layout "%s" "%s" 2>&1',
			escapeshellarg( $file_path ),
			escapeshellarg( $output_file )
		);

		// Execute command
		exec( $command, $output, $return_code );

		if ( $return_code !== 0 || ! file_exists( $output_file ) ) {
			@unlink( $output_file );
			return new WP_Error(
				'pdftotext_failed',
				sprintf( __( 'pdftotext extraction failed: %s', 'wp-ai-chatbot-leadgen-pro' ), implode( "\n", $output ) )
			);
		}

		// Read extracted text
		$text = file_get_contents( $output_file );
		@unlink( $output_file );

		if ( empty( $text ) ) {
			return new WP_Error(
				'no_text_extracted',
				__( 'No text could be extracted using pdftotext.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		return array(
			'text'     => $text,
			'metadata' => array(),
		);
	}

	/**
	 * Extract text using external API (placeholder for future implementation).
	 *
	 * @since 1.0.0
	 * @param string $file_path PDF file path.
	 * @param array  $args      Processing arguments.
	 * @return array|WP_Error Extracted text and metadata, or WP_Error on failure.
	 */
	private function extract_with_api( $file_path, $args ) {
		// This is a placeholder for future API integration
		// Could integrate with services like Google Cloud Vision API, AWS Textract, etc.

		return new WP_Error(
			'api_not_implemented',
			__( 'API-based PDF extraction is not yet implemented.', 'wp-ai-chatbot-leadgen-pro' )
		);
	}

	/**
	 * Check if pdftotext command is available.
	 *
	 * @since 1.0.0
	 * @return bool True if available, false otherwise.
	 */
	private function is_pdftotext_available() {
		// Check if command exists
		exec( 'which pdftotext 2>&1', $output, $return_code );

		if ( $return_code === 0 ) {
			return true;
		}

		// Try direct execution
		exec( 'pdftotext -v 2>&1', $output, $return_code );
		return $return_code === 0;
	}

	/**
	 * Check if API method is available.
	 *
	 * @since 1.0.0
	 * @return bool True if available, false otherwise.
	 */
	private function is_api_available() {
		// Check if API key is configured
		$api_key = $this->config->get( 'pdf_api_key', '' );
		return ! empty( $api_key );
	}

	/**
	 * Clean extracted text.
	 *
	 * @since 1.0.0
	 * @param string $text Raw extracted text.
	 * @return string Cleaned text.
	 */
	private function clean_extracted_text( $text ) {
		// Normalize line breaks
		$text = preg_replace( '/\r\n|\r/', "\n", $text );

		// Remove excessive whitespace
		$text = preg_replace( '/[ \t]+/', ' ', $text );

		// Remove excessive line breaks
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		// Remove non-printable characters (except newlines and tabs)
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		// Trim
		$text = trim( $text );

		return $text;
	}

	/**
	 * Process PDF from WordPress media library.
	 *
	 * @since 1.0.0
	 * @param int   $attachment_id Attachment ID.
	 * @param array $args          Optional. Processing arguments.
	 * @return array|WP_Error Extracted text and metadata, or WP_Error on failure.
	 */
	public function process_attachment( $attachment_id, $args = array() ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'PDF attachment file not found.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Get attachment metadata
		$attachment_metadata = wp_get_attachment_metadata( $attachment_id );

		$result = $this->process_pdf( $file_path, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add attachment metadata
		if ( isset( $attachment_metadata ) && is_array( $attachment_metadata ) ) {
			$result['attachment_id'] = $attachment_id;
			$result['attachment_url'] = wp_get_attachment_url( $attachment_id );
			$result['attachment_title'] = get_the_title( $attachment_id );
		}

		return $result;
	}

	/**
	 * Set maximum file size.
	 *
	 * @since 1.0.0
	 * @param int $size Maximum file size in bytes.
	 */
	public function set_max_file_size( $size ) {
		$this->max_file_size = intval( $size );
	}

	/**
	 * Get maximum file size.
	 *
	 * @since 1.0.0
	 * @return int Maximum file size in bytes.
	 */
	public function get_max_file_size() {
		return $this->max_file_size;
	}
}

