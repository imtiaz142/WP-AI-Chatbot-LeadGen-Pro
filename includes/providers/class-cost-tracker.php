<?php
/**
 * API Cost Tracker.
 *
 * Tracks API costs per conversation and provider for monitoring and optimization.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Cost_Tracker {

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
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
	}

	/**
	 * Track API cost for a conversation.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $provider        Provider name.
	 * @param string $model           Model name.
	 * @param array  $usage           Usage data (prompt_tokens, completion_tokens, total_tokens).
	 * @param float  $cost            Actual cost in USD.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function track_conversation_cost( $conversation_id, $provider, $model, $usage, $cost ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_analytics';

		$usage_data = array(
			'provider'          => $provider,
			'model'             => $model,
			'prompt_tokens'     => isset( $usage['prompt_tokens'] ) ? intval( $usage['prompt_tokens'] ) : 0,
			'completion_tokens' => isset( $usage['completion_tokens'] ) ? intval( $usage['completion_tokens'] ) : 0,
			'total_tokens'      => isset( $usage['total_tokens'] ) ? intval( $usage['total_tokens'] ) : 0,
			'cost'              => floatval( $cost ),
		);

		$event_data = array(
			'event_type'      => 'api_cost',
			'conversation_id' => intval( $conversation_id ),
			'event_data'      => wp_json_encode( $usage_data ),
		);

		$result = WP_AI_Chatbot_LeadGen_Pro_Database::insert_analytics_event( $event_data );

		if ( false === $result ) {
			$this->logger->error(
				'Failed to track conversation cost',
				array(
					'conversation_id' => $conversation_id,
					'provider'        => $provider,
					'model'           => $model,
					'cost'            => $cost,
				)
			);
			return new WP_Error( 'tracking_failed', __( 'Failed to track API cost.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		// Update conversation total cost
		$this->update_conversation_total_cost( $conversation_id, $cost );

		return true;
	}

	/**
	 * Update total cost for a conversation.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param float $additional_cost Additional cost to add.
	 * @return bool True on success, false on failure.
	 */
	private function update_conversation_total_cost( $conversation_id, $additional_cost ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_conversations';

		// Get current total cost
		$conversation = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversation( $conversation_id );
		if ( ! $conversation ) {
			return false;
		}

		// Calculate new total (stored as meta or in a custom field)
		// For now, we'll use the analytics table to calculate totals
		// This could be optimized with a dedicated cost field in conversations table
		return true;
	}

	/**
	 * Get total cost for a conversation.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return float Total cost in USD.
	 */
	public function get_conversation_cost( $conversation_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_analytics';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_data FROM {$table} 
				WHERE event_type = 'api_cost' 
				AND conversation_id = %d",
				$conversation_id
			)
		);

		$total_cost = 0.0;

		foreach ( $results as $result ) {
			$event_data = json_decode( $result->event_data, true );
			if ( isset( $event_data['cost'] ) ) {
				$total_cost += floatval( $event_data['cost'] );
			}
		}

		return $total_cost;
	}

	/**
	 * Get cost breakdown by provider.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Query arguments (date_from, date_to, provider, etc.).
	 * @return array Cost breakdown array.
	 */
	public function get_cost_by_provider( $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_analytics';

		$defaults = array(
			'date_from' => null,
			'date_to'   => null,
			'provider'  => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT event_data FROM {$table} WHERE event_type = 'api_cost'";

		$where = array();

		if ( $args['date_from'] ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $args['date_from'] );
		}

		if ( $args['date_to'] ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $args['date_to'] );
		}

		if ( ! empty( $where ) ) {
			$query .= ' AND ' . implode( ' AND ', $where );
		}

		$results = $wpdb->get_results( $query );

		$costs_by_provider = array();
		$total_cost = 0.0;
		$total_tokens = 0;

		foreach ( $results as $result ) {
			$event_data = json_decode( $result->event_data, true );
			if ( ! isset( $event_data['provider'] ) || ! isset( $event_data['cost'] ) ) {
				continue;
			}

			// Filter by provider if specified
			if ( $args['provider'] && $event_data['provider'] !== $args['provider'] ) {
				continue;
			}

			$provider = $event_data['provider'];

			if ( ! isset( $costs_by_provider[ $provider ] ) ) {
				$costs_by_provider[ $provider ] = array(
					'provider'          => $provider,
					'total_cost'        => 0.0,
					'total_tokens'      => 0,
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'request_count'     => 0,
					'models'            => array(),
				);
			}

			$costs_by_provider[ $provider ]['total_cost'] += floatval( $event_data['cost'] );
			$costs_by_provider[ $provider ]['total_tokens'] += isset( $event_data['total_tokens'] ) ? intval( $event_data['total_tokens'] ) : 0;
			$costs_by_provider[ $provider ]['prompt_tokens'] += isset( $event_data['prompt_tokens'] ) ? intval( $event_data['prompt_tokens'] ) : 0;
			$costs_by_provider[ $provider ]['completion_tokens'] += isset( $event_data['completion_tokens'] ) ? intval( $event_data['completion_tokens'] ) : 0;
			$costs_by_provider[ $provider ]['request_count']++;

			// Track by model
			$model = isset( $event_data['model'] ) ? $event_data['model'] : 'unknown';
			if ( ! isset( $costs_by_provider[ $provider ]['models'][ $model ] ) ) {
				$costs_by_provider[ $provider ]['models'][ $model ] = array(
					'model'             => $model,
					'cost'              => 0.0,
					'tokens'            => 0,
					'request_count'     => 0,
				);
			}

			$costs_by_provider[ $provider ]['models'][ $model ]['cost'] += floatval( $event_data['cost'] );
			$costs_by_provider[ $provider ]['models'][ $model ]['tokens'] += isset( $event_data['total_tokens'] ) ? intval( $event_data['total_tokens'] ) : 0;
			$costs_by_provider[ $provider ]['models'][ $model ]['request_count']++;

			$total_cost += floatval( $event_data['cost'] );
			$total_tokens += isset( $event_data['total_tokens'] ) ? intval( $event_data['total_tokens'] ) : 0;
		}

		return array(
			'by_provider' => array_values( $costs_by_provider ),
			'total_cost'  => $total_cost,
			'total_tokens' => $total_tokens,
			'period'      => array(
				'from' => $args['date_from'],
				'to'   => $args['date_to'],
			),
		);
	}

	/**
	 * Get cost statistics for a date range.
	 *
	 * @since 1.0.0
	 * @param string $date_from Start date (Y-m-d format).
	 * @param string $date_to   End date (Y-m-d format).
	 * @return array Cost statistics array.
	 */
	public function get_cost_statistics( $date_from = null, $date_to = null ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_analytics';

		// Default to last 30 days if no dates provided
		if ( null === $date_from ) {
			$date_from = date( 'Y-m-d', strtotime( '-30 days' ) );
		}
		if ( null === $date_to ) {
			$date_to = date( 'Y-m-d' );
		}

		// Get all events first, then process in PHP for better MySQL compatibility
		$query = $wpdb->prepare(
			"SELECT 
				DATE(created_at) as date,
				event_data
			FROM {$table}
			WHERE event_type = 'api_cost'
			AND DATE(created_at) BETWEEN %s AND %s
			ORDER BY created_at ASC",
			$date_from,
			$date_to
		);

		$results = $wpdb->get_results( $query );

		$daily_costs = array();
		$total_cost = 0.0;
		$total_tokens = 0;
		$total_requests = 0;
		$daily_data = array();

		// Group by date and calculate totals
		foreach ( $results as $result ) {
			$date = $result->date;
			$event_data = json_decode( $result->event_data, true );

			if ( ! isset( $daily_data[ $date ] ) ) {
				$daily_data[ $date ] = array(
					'cost'          => 0.0,
					'tokens'        => 0,
					'request_count' => 0,
				);
			}

			$cost = isset( $event_data['cost'] ) ? floatval( $event_data['cost'] ) : 0.0;
			$tokens = isset( $event_data['total_tokens'] ) ? intval( $event_data['total_tokens'] ) : 0;

			$daily_data[ $date ]['cost'] += $cost;
			$daily_data[ $date ]['tokens'] += $tokens;
			$daily_data[ $date ]['request_count']++;

			$total_cost += $cost;
			$total_tokens += $tokens;
			$total_requests++;
		}

		// Convert to array format
		foreach ( $daily_data as $date => $data ) {
			$daily_costs[] = array(
				'date'          => $date,
				'cost'          => $data['cost'],
				'tokens'        => $data['tokens'],
				'request_count' => $data['request_count'],
			);
		}

		$days = ( strtotime( $date_to ) - strtotime( $date_from ) ) / ( 60 * 60 * 24 ) + 1;
		$average_daily_cost = $days > 0 ? $total_cost / $days : 0;

		return array(
			'period'           => array(
				'from' => $date_from,
				'to'   => $date_to,
				'days' => $days,
			),
			'total_cost'       => $total_cost,
			'total_tokens'     => $total_tokens,
			'total_requests'   => $total_requests,
			'average_daily_cost' => $average_daily_cost,
			'average_cost_per_request' => $total_requests > 0 ? $total_cost / $total_requests : 0,
			'daily_breakdown'  => $daily_costs,
		);
	}

	/**
	 * Get average cost per conversation.
	 *
	 * @since 1.0.0
	 * @param array $args Optional. Query arguments.
	 * @return float Average cost per conversation.
	 */
	public function get_average_cost_per_conversation( $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_analytics';

		$defaults = array(
			'date_from' => null,
			'date_to'   => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT 
			conversation_id,
			SUM(JSON_EXTRACT(event_data, '$.cost')) as conversation_cost
		FROM {$table}
		WHERE event_type = 'api_cost'";

		$where = array();

		if ( $args['date_from'] ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $args['date_from'] );
		}

		if ( $args['date_to'] ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $args['date_to'] );
		}

		if ( ! empty( $where ) ) {
			$query .= ' AND ' . implode( ' AND ', $where );
		}

		$query .= ' GROUP BY conversation_id';

		$results = $wpdb->get_results( $query );

		if ( empty( $results ) ) {
			return 0.0;
		}

		$total_cost = 0.0;
		$conversation_count = 0;

		foreach ( $results as $result ) {
			$total_cost += floatval( $result->conversation_cost );
			$conversation_count++;
		}

		return $conversation_count > 0 ? $total_cost / $conversation_count : 0.0;
	}

	/**
	 * Get top cost drivers (most expensive conversations or models).
	 *
	 * @since 1.0.0
	 * @param string $type    'conversation' or 'model'.
	 * @param int    $limit   Number of results to return.
	 * @param array  $args    Optional. Additional query arguments.
	 * @return array Array of top cost drivers.
	 */
	public function get_top_cost_drivers( $type = 'conversation', $limit = 10, $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_analytics';

		$defaults = array(
			'date_from' => null,
			'date_to'   => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT conversation_id, event_data
			FROM {$table}
			WHERE event_type = 'api_cost'";

		$where = array();

		if ( $args['date_from'] ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $args['date_from'] );
		}

		if ( $args['date_to'] ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $args['date_to'] );
		}

		if ( ! empty( $where ) ) {
			$query .= ' AND ' . implode( ' AND ', $where );
		}

		$results = $wpdb->get_results( $query );

		$drivers_data = array();

		foreach ( $results as $result ) {
			$event_data = json_decode( $result->event_data, true );
			if ( ! isset( $event_data['cost'] ) ) {
				continue;
			}

			$cost = floatval( $event_data['cost'] );

			if ( 'conversation' === $type ) {
				$id = $result->conversation_id;
			} else {
				$id = isset( $event_data['model'] ) ? $event_data['model'] : 'unknown';
			}

			if ( ! isset( $drivers_data[ $id ] ) ) {
				$drivers_data[ $id ] = array(
					'id'            => $id,
					'total_cost'    => 0.0,
					'request_count' => 0,
				);
			}

			$drivers_data[ $id ]['total_cost'] += $cost;
			$drivers_data[ $id ]['request_count']++;
		}

		// Sort by total_cost descending
		usort( $drivers_data, function( $a, $b ) {
			return $b['total_cost'] <=> $a['total_cost'];
		} );

		// Return top N
		return array_slice( $drivers_data, 0, $limit );
	}

	/**
	 * Estimate cost for a request before making it.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $model    Model name.
	 * @param int    $tokens   Estimated token count.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( $provider, $model, $tokens ) {
		$provider_instance = WP_AI_Chatbot_LeadGen_Pro_Provider_Factory::get_instance()->get_provider( $provider );
		
		if ( is_wp_error( $provider_instance ) ) {
			return 0.0;
		}

		return $provider_instance->estimate_cost( $model, $tokens );
	}

	/**
	 * Get cost summary for dashboard.
	 *
	 * @since 1.0.0
	 * @param string $period Optional. Time period ('today', 'week', 'month', 'year', 'all').
	 * @return array Cost summary array.
	 */
	public function get_cost_summary( $period = 'month' ) {
		$date_ranges = array(
			'today'  => array( date( 'Y-m-d' ), date( 'Y-m-d' ) ),
			'week'   => array( date( 'Y-m-d', strtotime( '-7 days' ) ), date( 'Y-m-d' ) ),
			'month'  => array( date( 'Y-m-d', strtotime( '-30 days' ) ), date( 'Y-m-d' ) ),
			'year'   => array( date( 'Y-m-d', strtotime( '-365 days' ) ), date( 'Y-m-d' ) ),
			'all'    => array( null, null ),
		);

		$range = isset( $date_ranges[ $period ] ) ? $date_ranges[ $period ] : $date_ranges['month'];

		$statistics = $this->get_cost_statistics( $range[0], $range[1] );
		$by_provider = $this->get_cost_by_provider( array(
			'date_from' => $range[0],
			'date_to'   => $range[1],
		) );

		return array(
			'period'                    => $period,
			'total_cost'                => $statistics['total_cost'],
			'total_tokens'              => $statistics['total_tokens'],
			'total_requests'            => $statistics['total_requests'],
			'average_daily_cost'        => $statistics['average_daily_cost'],
			'average_cost_per_request'  => $statistics['average_cost_per_request'],
			'average_cost_per_conversation' => $this->get_average_cost_per_conversation( array(
				'date_from' => $range[0],
				'date_to'   => $range[1],
			) ),
			'by_provider'               => $by_provider['by_provider'],
			'top_cost_drivers'          => $this->get_top_cost_drivers( 'model', 5, array(
				'date_from' => $range[0],
				'date_to'   => $range[1],
			) ),
		);
	}
}

