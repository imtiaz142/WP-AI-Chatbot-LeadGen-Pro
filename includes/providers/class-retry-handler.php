<?php
/**
 * Retry Handler with Exponential Backoff.
 *
 * Handles retry logic for API requests with exponential backoff, jitter, and configurable behavior.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Retry_Handler {

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
	 * Default retry configuration.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $default_config = array(
		'max_retries'           => 3,
		'initial_delay'         => 1,      // seconds
		'max_delay'             => 60,     // seconds
		'exponential_base'      => 2,
		'jitter'                => true,
		'jitter_max'            => 0.3,    // 30% jitter
		'retryable_status_codes' => array( 429, 500, 502, 503, 504 ),
		'retryable_error_codes'  => array( 'http_request_failed', 'timeout', 'connection_failed' ),
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param array $config Optional. Custom retry configuration.
	 */
	public function __construct( $config = array() ) {
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->default_config = wp_parse_args( $config, $this->default_config );
	}

	/**
	 * Execute a request with retry logic.
	 *
	 * @since 1.0.0
	 * @param callable $request_callback Callback function that makes the request. Should return WP_Error or response array.
	 * @param array    $retry_config     Optional. Custom retry configuration for this request.
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	public function execute_with_retry( $request_callback, $retry_config = array() ) {
		if ( ! is_callable( $request_callback ) ) {
			return new WP_Error(
				'invalid_callback',
				__( 'Request callback must be callable.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$config = wp_parse_args( $retry_config, $this->default_config );
		$max_retries = intval( $config['max_retries'] );
		$attempt = 0;
		$last_error = null;
		$last_response = null;

		while ( $attempt <= $max_retries ) {
			// Execute the request
			$result = call_user_func( $request_callback, $attempt );

			// Check if result is WP_Error
			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				$error_code = $result->get_error_code();

				// Check if error is retryable
				if ( ! $this->is_retryable_error( $result, $config ) ) {
					$this->logger->warning(
						'Non-retryable error encountered',
						array(
							'error_code'    => $error_code,
							'error_message' => $result->get_error_message(),
							'attempt'       => $attempt,
						)
					);
					return $result;
				}

				// Check if we should retry
				if ( $attempt >= $max_retries ) {
					$this->logger->error(
						'Maximum retry attempts reached',
						array(
							'error_code'    => $error_code,
							'error_message' => $result->get_error_message(),
							'attempts'      => $attempt,
						)
					);
					return $result;
				}

				// Calculate delay and wait
				$delay = $this->calculate_delay( $attempt, $config );
				$this->wait( $delay );

				$attempt++;
				continue;
			}

			// Check if result is a response array with status code
			if ( is_array( $result ) && isset( $result['status_code'] ) ) {
				$status_code = intval( $result['status_code'] );

				// Success
				if ( $status_code >= 200 && $status_code < 300 ) {
					return $result;
				}

				// Check if status code is retryable
				if ( ! $this->is_retryable_status_code( $status_code, $config ) ) {
					$this->logger->warning(
						'Non-retryable status code encountered',
						array(
							'status_code' => $status_code,
							'attempt'     => $attempt,
							'response'    => $result,
						)
					);
					return $this->create_error_from_response( $result );
				}

				// Check if we should retry
				if ( $attempt >= $max_retries ) {
					$this->logger->error(
						'Maximum retry attempts reached for status code',
						array(
							'status_code' => $status_code,
							'attempts'    => $attempt,
							'response'    => $result,
						)
					);
					return $this->create_error_from_response( $result );
				}

				// Handle rate limiting (429) with Retry-After header
				if ( 429 === $status_code && isset( $result['headers']['retry-after'] ) ) {
					$retry_after = intval( $result['headers']['retry-after'] );
					$delay = min( $retry_after, $config['max_delay'] );
					$this->logger->info(
						'Rate limited, using Retry-After header',
						array(
							'retry_after' => $retry_after,
							'delay'       => $delay,
							'attempt'     => $attempt,
						)
					);
				} else {
					// Calculate delay with exponential backoff
					$delay = $this->calculate_delay( $attempt, $config );
				}

				$this->wait( $delay );
				$last_response = $result;
				$attempt++;
				continue;
			}

			// Success - return result as-is
			return $result;
		}

		// Return last error or create error from last response
		if ( $last_error ) {
			return $last_error;
		}

		if ( $last_response ) {
			return $this->create_error_from_response( $last_response );
		}

		return new WP_Error(
			'max_retries',
			__( 'Maximum retry attempts reached', 'wp-ai-chatbot-leadgen-pro' ),
			array( 'attempts' => $attempt )
		);
	}

	/**
	 * Check if an error is retryable.
	 *
	 * @since 1.0.0
	 * @param WP_Error $error  Error object.
	 * @param array    $config Retry configuration.
	 * @return bool True if retryable, false otherwise.
	 */
	private function is_retryable_error( $error, $config ) {
		$error_code = $error->get_error_code();

		// Check against retryable error codes
		if ( in_array( $error_code, $config['retryable_error_codes'], true ) ) {
			return true;
		}

		// Check error message for common retryable patterns
		$error_message = strtolower( $error->get_error_message() );
		$retryable_patterns = array(
			'timeout',
			'connection',
			'network',
			'temporarily',
			'unavailable',
			'server error',
		);

		foreach ( $retryable_patterns as $pattern ) {
			if ( strpos( $error_message, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a status code is retryable.
	 *
	 * @since 1.0.0
	 * @param int   $status_code HTTP status code.
	 * @param array $config      Retry configuration.
	 * @return bool True if retryable, false otherwise.
	 */
	private function is_retryable_status_code( $status_code, $config ) {
		// Always retry 429 (rate limit)
		if ( 429 === $status_code ) {
			return true;
		}

		// Check against configured retryable status codes
		if ( in_array( $status_code, $config['retryable_status_codes'], true ) ) {
			return true;
		}

		// Retry 5xx server errors
		if ( $status_code >= 500 && $status_code < 600 ) {
			return true;
		}

		return false;
	}

	/**
	 * Calculate delay for exponential backoff.
	 *
	 * @since 1.0.0
	 * @param int   $attempt Attempt number (0-based).
	 * @param array $config  Retry configuration.
	 * @return float Delay in seconds.
	 */
	private function calculate_delay( $attempt, $config ) {
		// Calculate exponential delay
		$delay = $config['initial_delay'] * pow( $config['exponential_base'], $attempt );

		// Apply jitter if enabled
		if ( $config['jitter'] ) {
			$jitter_amount = $delay * $config['jitter_max'] * ( mt_rand( 0, 100 ) / 100 );
			$delay = $delay + $jitter_amount;
		}

		// Cap at max delay
		$delay = min( $delay, $config['max_delay'] );

		return $delay;
	}

	/**
	 * Wait for specified delay.
	 *
	 * @since 1.0.0
	 * @param float $delay Delay in seconds.
	 */
	private function wait( $delay ) {
		if ( $delay <= 0 ) {
			return;
		}

		// Use usleep for sub-second precision, sleep for whole seconds
		if ( $delay < 1 ) {
			usleep( intval( $delay * 1000000 ) );
		} else {
			sleep( intval( $delay ) );
		}
	}

	/**
	 * Create WP_Error from response array.
	 *
	 * @since 1.0.0
	 * @param array $response Response array with status_code and optional error data.
	 * @return WP_Error Error object.
	 */
	private function create_error_from_response( $response ) {
		$status_code = isset( $response['status_code'] ) ? intval( $response['status_code'] ) : 0;
		$error_message = isset( $response['error']['message'] )
			? $response['error']['message']
			: sprintf(
				/* translators: %d: HTTP status code */
				__( 'API request failed with status %d', 'wp-ai-chatbot-leadgen-pro' ),
				$status_code
			);

		$error_code = isset( $response['error']['code'] )
			? $response['error']['code']
			: 'api_error';

		return new WP_Error(
			$error_code,
			$error_message,
			array(
				'status'   => $status_code,
				'response' => $response,
			)
		);
	}

	/**
	 * Get retry statistics for monitoring.
	 *
	 * @since 1.0.0
	 * @return array Retry statistics.
	 */
	public function get_statistics() {
		// This could be extended to track retry statistics over time
		return array(
			'default_config' => $this->default_config,
		);
	}

	/**
	 * Create a request callback wrapper for wp_remote_request.
	 *
	 * @since 1.0.0
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return callable Callback function.
	 */
	public static function create_wp_remote_callback( $url, $args ) {
		return function( $attempt ) use ( $url, $args ) {
			$response = wp_remote_request( $url, $args );

			// Handle WP_Error
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract response data
			$status_code = wp_remote_retrieve_response_code( $response );
			$headers = wp_remote_retrieve_headers( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			// Return structured response
			return array(
				'status_code' => $status_code,
				'headers'     => $headers->getAll(),
				'body'        => $body,
				'data'        => $data,
				'error'       => isset( $data['error'] ) ? $data['error'] : null,
			);
		};
	}
}

