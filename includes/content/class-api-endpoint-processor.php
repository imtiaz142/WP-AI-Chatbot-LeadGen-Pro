<?php
/**
 * API Endpoint Processor.
 *
 * Fetches and processes data from external API endpoints for content ingestion.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/content
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_API_Endpoint_Processor {

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
	 * Default timeout for API requests in seconds.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_timeout = 30;

	/**
	 * Default maximum retries.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $default_max_retries = 3;

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
	 * Process API endpoint and extract content.
	 *
	 * @since 1.0.0
	 * @param string $endpoint_url API endpoint URL.
	 * @param array  $args         Optional. Processing arguments.
	 * @return array|WP_Error Processed content and metadata, or WP_Error on failure.
	 */
	public function process_endpoint( $endpoint_url, $args = array() ) {
		$defaults = array(
			'method'          => 'GET',
			'headers'         => array(),
			'body'            => null,
			'auth_type'       => 'none', // 'none', 'api_key', 'bearer', 'basic', 'oauth2'
			'auth_credentials' => array(),
			'response_format' => 'auto', // 'auto', 'json', 'xml', 'text'
			'timeout'         => $this->default_timeout,
			'max_retries'     => $this->default_max_retries,
			'pagination'      => false,
			'pagination_key'  => 'page',
			'max_pages'       => 10,
			'data_path'       => null, // JSON path to extract data (e.g., 'data.items')
			'content_fields'  => array(), // Fields to extract as content
		);

		$args = wp_parse_args( $args, $defaults );

		// Fetch data from API
		$response = $this->fetch_api_data( $endpoint_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse response
		$parsed_data = $this->parse_response( $response, $args );

		if ( is_wp_error( $parsed_data ) ) {
			return $parsed_data;
		}

		// Handle pagination if needed
		if ( $args['pagination'] ) {
			$all_data = $this->fetch_paginated_data( $endpoint_url, $args, $parsed_data );
		} else {
			$all_data = $parsed_data;
		}

		// Extract content from data
		$content = $this->extract_content( $all_data, $args );

		return array(
			'url'         => $endpoint_url,
			'data'        => $all_data,
			'content'     => $content,
			'word_count'  => str_word_count( $content ),
			'char_count'  => strlen( $content ),
			'item_count'  => is_array( $all_data ) ? count( $all_data ) : 1,
		);
	}

	/**
	 * Fetch data from API endpoint.
	 *
	 * @since 1.0.0
	 * @param string $endpoint_url API endpoint URL.
	 * @param array  $args         Request arguments.
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	private function fetch_api_data( $endpoint_url, $args ) {
		// Prepare request arguments
		$request_args = array(
			'method'  => $args['method'],
			'timeout' => $args['timeout'],
			'headers' => $this->prepare_headers( $args ),
		);

		// Add body for POST/PUT requests
		if ( in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) && ! empty( $args['body'] ) ) {
			if ( is_array( $args['body'] ) ) {
				$request_args['body'] = wp_json_encode( $args['body'] );
				$request_args['headers']['Content-Type'] = 'application/json';
			} else {
				$request_args['body'] = $args['body'];
			}
		}

		// Add query parameters for GET requests
		if ( $args['method'] === 'GET' && ! empty( $args['body'] ) && is_array( $args['body'] ) ) {
			$endpoint_url = add_query_arg( $args['body'], $endpoint_url );
		}

		// Make request with retry logic
		$retry_handler = new WP_AI_Chatbot_LeadGen_Pro_Retry_Handler();
		
		$response = $retry_handler->execute_with_retry(
			function() use ( $endpoint_url, $request_args ) {
				return wp_remote_request( $endpoint_url, $request_args );
			},
			array(
				'max_attempts' => $args['max_retries'],
				'retryable_condition' => function( $result ) {
					if ( is_wp_error( $result ) ) {
						$error_code = $result->get_error_code();
						// Retry on network errors, timeouts, and 5xx errors
						return in_array( $error_code, array( 'http_request_failed', 'timeout' ), true );
					}

					$status_code = wp_remote_retrieve_response_code( $result );
					return $status_code >= 500 && $status_code < 600;
				},
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'API endpoint request failed',
				array(
					'endpoint' => $endpoint_url,
					'error'    => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// Check for HTTP errors
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->logger->warning(
				'API endpoint returned error status',
				array(
					'endpoint'   => $endpoint_url,
					'status_code' => $status_code,
					'response'   => substr( $body, 0, 500 ),
				)
			);

			return new WP_Error(
				'api_error',
				sprintf( __( 'API endpoint returned status code %d', 'wp-ai-chatbot-leadgen-pro' ), $status_code ),
				array( 'status_code' => $status_code, 'body' => $body )
			);
		}

		return array(
			'body'        => $body,
			'status_code' => $status_code,
			'headers'     => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * Prepare request headers including authentication.
	 *
	 * @since 1.0.0
	 * @param array $args Request arguments.
	 * @return array Headers array.
	 */
	private function prepare_headers( $args ) {
		$headers = array_merge(
			array(
				'User-Agent' => 'WP-AI-Chatbot-LeadGen-Pro/1.0',
				'Accept'     => 'application/json, application/xml, text/plain, */*',
			),
			$args['headers']
		);

		// Add authentication headers
		$auth_type = $args['auth_type'];
		$auth_credentials = $args['auth_credentials'];

		switch ( $auth_type ) {
			case 'api_key':
				$key_name = isset( $auth_credentials['key_name'] ) ? $auth_credentials['key_name'] : 'X-API-Key';
				$key_location = isset( $auth_credentials['key_location'] ) ? $auth_credentials['key_location'] : 'header';
				$api_key = isset( $auth_credentials['api_key'] ) ? $auth_credentials['api_key'] : '';

				if ( $key_location === 'header' ) {
					$headers[ $key_name ] = $api_key;
				} elseif ( $key_location === 'query' ) {
					// Will be added to URL in fetch_api_data
				}
				break;

			case 'bearer':
				$token = isset( $auth_credentials['token'] ) ? $auth_credentials['token'] : '';
				if ( ! empty( $token ) ) {
					$headers['Authorization'] = 'Bearer ' . $token;
				}
				break;

			case 'basic':
				$username = isset( $auth_credentials['username'] ) ? $auth_credentials['username'] : '';
				$password = isset( $auth_credentials['password'] ) ? $auth_credentials['password'] : '';
				if ( ! empty( $username ) && ! empty( $password ) ) {
					$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password );
				}
				break;

			case 'oauth2':
				$token = isset( $auth_credentials['access_token'] ) ? $auth_credentials['access_token'] : '';
				if ( ! empty( $token ) ) {
					$headers['Authorization'] = 'Bearer ' . $token;
				}
				break;
		}

		return $headers;
	}

	/**
	 * Parse API response based on format.
	 *
	 * @since 1.0.0
	 * @param array $response Response data.
	 * @param array $args     Processing arguments.
	 * @return array|WP_Error Parsed data or WP_Error on failure.
	 */
	private function parse_response( $response, $args ) {
		$body = $response['body'];
		$format = $args['response_format'];

		// Auto-detect format
		if ( $format === 'auto' ) {
			$content_type = isset( $response['headers']['content-type'] ) 
				? $response['headers']['content-type'] 
				: '';

			if ( strpos( $content_type, 'application/json' ) !== false ) {
				$format = 'json';
			} elseif ( strpos( $content_type, 'application/xml' ) !== false || strpos( $content_type, 'text/xml' ) !== false ) {
				$format = 'xml';
			} elseif ( strpos( $content_type, 'text/' ) !== false ) {
				$format = 'text';
			} else {
				// Try to detect JSON
				$json = json_decode( $body, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$format = 'json';
				} else {
					$format = 'text';
				}
			}
		}

		switch ( $format ) {
			case 'json':
				return $this->parse_json( $body, $args );

			case 'xml':
				return $this->parse_xml( $body, $args );

			case 'text':
			default:
				return array( 'content' => $body );
		}
	}

	/**
	 * Parse JSON response.
	 *
	 * @since 1.0.0
	 * @param string $body Response body.
	 * @param array  $args Processing arguments.
	 * @return array|WP_Error Parsed data or WP_Error on failure.
	 */
	private function parse_json( $body, $args ) {
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->logger->error(
				'Failed to parse JSON response',
				array(
					'error' => json_last_error_msg(),
					'body'  => substr( $body, 0, 500 ),
				)
			);

			return new WP_Error(
				'json_parse_error',
				sprintf( __( 'Failed to parse JSON: %s', 'wp-ai-chatbot-leadgen-pro' ), json_last_error_msg() )
			);
		}

		// Extract data using path if specified
		if ( ! empty( $args['data_path'] ) ) {
			$data = $this->extract_data_path( $data, $args['data_path'] );
		}

		return $data;
	}

	/**
	 * Parse XML response.
	 *
	 * @since 1.0.0
	 * @param string $body Response body.
	 * @param array  $args Processing arguments.
	 * @return array|WP_Error Parsed data or WP_Error on failure.
	 */
	private function parse_xml( $body, $args ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );

		if ( false === $xml ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();

			$this->logger->error(
				'Failed to parse XML response',
				array(
					'errors' => array_map( function( $error ) {
						return trim( $error->message );
					}, $errors ),
				)
			);

			return new WP_Error(
				'xml_parse_error',
				__( 'Failed to parse XML response', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Convert XML to array
		$data = json_decode( wp_json_encode( $xml ), true );

		// Extract data using path if specified
		if ( ! empty( $args['data_path'] ) ) {
			$data = $this->extract_data_path( $data, $args['data_path'] );
		}

		return $data;
	}

	/**
	 * Extract data using dot-notation path.
	 *
	 * @since 1.0.0
	 * @param array  $data Data array.
	 * @param string $path Dot-notation path (e.g., 'data.items').
	 * @return mixed Extracted data.
	 */
	private function extract_data_path( $data, $path ) {
		$keys = explode( '.', $path );
		$current = $data;

		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! isset( $current[ $key ] ) ) {
				return null;
			}
			$current = $current[ $key ];
		}

		return $current;
	}

	/**
	 * Fetch paginated data from API.
	 *
	 * @since 1.0.0
	 * @param string $endpoint_url API endpoint URL.
	 * @param array  $args         Request arguments.
	 * @param array  $first_page   First page data.
	 * @return array All paginated data.
	 */
	private function fetch_paginated_data( $endpoint_url, $args, $first_page ) {
		$all_data = is_array( $first_page ) ? $first_page : array( $first_page );
		$max_pages = $args['max_pages'];
		$pagination_key = $args['pagination_key'];

		// Determine if there are more pages
		// This is a simplified implementation - actual pagination logic depends on API
		for ( $page = 2; $page <= $max_pages; $page++ ) {
			// Add page parameter
			$page_args = $args;
			if ( is_array( $page_args['body'] ) ) {
				$page_args['body'][ $pagination_key ] = $page;
			} else {
				$page_args['body'] = array( $pagination_key => $page );
			}

			$response = $this->fetch_api_data( $endpoint_url, $page_args );

			if ( is_wp_error( $response ) ) {
				break; // Stop on error
			}

			$parsed = $this->parse_response( $response, $args );

			if ( is_wp_error( $parsed ) || empty( $parsed ) ) {
				break; // Stop if no more data
			}

			$page_data = is_array( $parsed ) ? $parsed : array( $parsed );
			$all_data = array_merge( $all_data, $page_data );

			// Check if this was the last page (implementation depends on API)
			if ( empty( $page_data ) ) {
				break;
			}
		}

		return $all_data;
	}

	/**
	 * Extract content from parsed data.
	 *
	 * @since 1.0.0
	 * @param mixed $data Parsed data.
	 * @param array $args Processing arguments.
	 * @return string Formatted content.
	 */
	private function extract_content( $data, $args ) {
		if ( empty( $data ) ) {
			return '';
		}

		// If specific content fields are specified, extract those
		if ( ! empty( $args['content_fields'] ) && is_array( $data ) ) {
			return $this->extract_specific_fields( $data, $args['content_fields'] );
		}

		// Convert data to text
		if ( is_string( $data ) ) {
			return $data;
		}

		if ( is_array( $data ) ) {
			return $this->array_to_text( $data );
		}

		if ( is_object( $data ) ) {
			return $this->array_to_text( (array) $data );
		}

		return (string) $data;
	}

	/**
	 * Extract specific fields from data.
	 *
	 * @since 1.0.0
	 * @param array $data   Data array.
	 * @param array $fields Fields to extract.
	 * @return string Formatted content.
	 */
	private function extract_specific_fields( $data, $fields ) {
		$content_parts = array();

		// Handle array of items
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			foreach ( $data as $item ) {
				$item_content = array();
				foreach ( $fields as $field ) {
					if ( isset( $item[ $field ] ) ) {
						$item_content[] = $item[ $field ];
					}
				}
				if ( ! empty( $item_content ) ) {
					$content_parts[] = implode( ' - ', $item_content );
				}
			}
		} else {
			// Single item
			foreach ( $fields as $field ) {
				if ( isset( $data[ $field ] ) ) {
					$content_parts[] = $data[ $field ];
				}
			}
		}

		return implode( "\n\n", $content_parts );
	}

	/**
	 * Convert array to readable text.
	 *
	 * @since 1.0.0
	 * @param array $data Data array.
	 * @return string Formatted text.
	 */
	private function array_to_text( $data ) {
		$text_parts = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$text_parts[] = ucfirst( $key ) . ': ' . $this->array_to_text( $value );
			} elseif ( is_object( $value ) ) {
				$text_parts[] = ucfirst( $key ) . ': ' . $this->array_to_text( (array) $value );
			} else {
				$text_parts[] = ucfirst( $key ) . ': ' . (string) $value;
			}
		}

		return implode( "\n", $text_parts );
	}
}

