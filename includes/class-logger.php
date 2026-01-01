<?php
/**
 * Error logging class.
 *
 * Provides logging functionality using WordPress debug log.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Logger {

	/**
	 * Log prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $log_prefix = '[WP AI Chatbot LeadGen Pro]';

	/**
	 * Whether logging is enabled.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Minimum log level.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $min_level = 'error';

	/**
	 * Log levels in order of severity.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $log_levels = array(
		'debug'   => 0,
		'info'    => 1,
		'notice'  => 2,
		'warning' => 3,
		'error'   => 4,
		'critical' => 5,
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Enable logging if WP_DEBUG is enabled
		$this->enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;

		// Check if logging is explicitly enabled via option
		$config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$explicit_enabled = $config->get( 'debug_logging_enabled', false );

		if ( $explicit_enabled ) {
			$this->enabled = true;
		}

		// Get minimum log level from config
		$this->min_level = $config->get( 'debug_log_level', 'error' );
	}

	/**
	 * Log a debug message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function debug( $message, $context = array() ) {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function info( $message, $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log a notice message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function notice( $message, $context = array() ) {
		$this->log( 'notice', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function warning( $message, $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function error( $message, $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Log a critical message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function critical( $message, $context = array() ) {
		$this->log( 'critical', $message, $context );
	}

	/**
	 * Log a message with specified level.
	 *
	 * @since 1.0.0
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function log( $level, $message, $context = array() ) {
		if ( ! $this->enabled ) {
			return;
		}

		// Check if this log level should be logged
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		// Format the log message
		$log_message = $this->format_message( $level, $message, $context );

		// Write to WordPress debug log
		error_log( $log_message );
	}

	/**
	 * Check if a log level should be logged.
	 *
	 * @since 1.0.0
	 * @param string $level Log level.
	 * @return bool True if should log, false otherwise.
	 */
	private function should_log( $level ) {
		if ( ! isset( $this->log_levels[ $level ] ) ) {
			return false;
		}

		if ( ! isset( $this->log_levels[ $this->min_level ] ) ) {
			return true; // Default to logging if min_level is invalid
		}

		return $this->log_levels[ $level ] >= $this->log_levels[ $this->min_level ];
	}

	/**
	 * Format log message.
	 *
	 * @since 1.0.0
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return string Formatted log message.
	 */
	private function format_message( $level, $message, $context = array() ) {
		$timestamp = current_time( 'mysql' );
		$level_upper = strtoupper( $level );

		// Build base message
		$log_message = sprintf(
			'%s [%s] %s: %s',
			$this->log_prefix,
			$timestamp,
			$level_upper,
			$message
		);

		// Add context data if provided
		if ( ! empty( $context ) ) {
			// Sanitize context to avoid logging sensitive data
			$context = $this->sanitize_context( $context );
			$log_message .= ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
		}

		// Add stack trace for errors and critical messages
		if ( in_array( $level, array( 'error', 'critical' ), true ) ) {
			$trace = $this->get_stack_trace();
			if ( ! empty( $trace ) ) {
				$log_message .= ' | Trace: ' . $trace;
			}
		}

		return $log_message;
	}

	/**
	 * Sanitize context data to remove sensitive information.
	 *
	 * @since 1.0.0
	 * @param array $context Context data.
	 * @return array Sanitized context data.
	 */
	private function sanitize_context( $context ) {
		$sensitive_keys = array(
			'api_key',
			'password',
			'secret',
			'token',
			'authorization',
			'credit_card',
			'ssn',
			'social_security',
		);

		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( $key );

			// Check if key contains sensitive information
			foreach ( $sensitive_keys as $sensitive_key ) {
				if ( strpos( $key_lower, $sensitive_key ) !== false ) {
					$context[ $key ] = '[REDACTED]';
					break;
				}
			}

			// Recursively sanitize arrays
			if ( is_array( $value ) ) {
				$context[ $key ] = $this->sanitize_context( $value );
			}
		}

		return $context;
	}

	/**
	 * Get simplified stack trace.
	 *
	 * @since 1.0.0
	 * @param int $limit Number of stack frames to include.
	 * @return string Stack trace string.
	 */
	private function get_stack_trace( $limit = 5 ) {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 3 );
		
		// Remove logger class methods from trace
		$trace = array_filter(
			$trace,
			function( $frame ) {
				return ! isset( $frame['class'] ) || 
					   strpos( $frame['class'], 'WP_AI_Chatbot_LeadGen_Pro_Logger' ) === false;
			}
		);

		$trace = array_slice( $trace, 0, $limit );
		$trace_strings = array();

		foreach ( $trace as $frame ) {
			$file = isset( $frame['file'] ) ? basename( $frame['file'] ) : 'unknown';
			$line = isset( $frame['line'] ) ? $frame['line'] : '?';
			$function = isset( $frame['function'] ) ? $frame['function'] : 'unknown';
			$class = isset( $frame['class'] ) ? $frame['class'] . '::' : '';

			$trace_strings[] = sprintf( '%s%s() in %s:%s', $class, $function, $file, $line );
		}

		return implode( ' -> ', $trace_strings );
	}

	/**
	 * Log an exception.
	 *
	 * @since 1.0.0
	 * @param Exception $exception Exception object.
	 * @param array     $context   Additional context data.
	 */
	public function exception( $exception, $context = array() ) {
		$message = sprintf(
			'Exception: %s in %s:%s',
			$exception->getMessage(),
			$exception->getFile(),
			$exception->getLine()
		);

		$context['exception_class'] = get_class( $exception );
		$context['exception_code'] = $exception->getCode();
		$context['exception_trace'] = $exception->getTraceAsString();

		$this->error( $message, $context );
	}

	/**
	 * Enable logging.
	 *
	 * @since 1.0.0
	 */
	public function enable() {
		$this->enabled = true;
	}

	/**
	 * Disable logging.
	 *
	 * @since 1.0.0
	 */
	public function disable() {
		$this->enabled = false;
	}

	/**
	 * Check if logging is enabled.
	 *
	 * @since 1.0.0
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled() {
		return $this->enabled;
	}

	/**
	 * Set minimum log level.
	 *
	 * @since 1.0.0
	 * @param string $level Minimum log level.
	 */
	public function set_min_level( $level ) {
		if ( isset( $this->log_levels[ $level ] ) ) {
			$this->min_level = $level;
		}
	}

	/**
	 * Get logger instance.
	 *
	 * @since 1.0.0
	 * @return WP_AI_Chatbot_LeadGen_Pro_Logger Logger instance.
	 */
	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}
}

