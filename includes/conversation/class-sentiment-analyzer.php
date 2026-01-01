<?php
/**
 * Sentiment Analyzer.
 *
 * Detects emotional tone and frustration levels in user messages.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Sentiment_Analyzer {

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
	 * Provider factory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Provider_Factory
	 */
	private $provider_factory;

	/**
	 * Positive sentiment indicators.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $positive_indicators = array(
		'words' => array(
			'great', 'awesome', 'excellent', 'amazing', 'wonderful', 'fantastic',
			'perfect', 'love', 'thank', 'thanks', 'appreciate', 'helpful',
			'good', 'nice', 'happy', 'pleased', 'satisfied', 'brilliant',
			'superb', 'outstanding', 'impressive', 'delighted', 'excited',
		),
		'phrases' => array(
			'thank you', 'thanks a lot', 'that\'s great', 'well done',
			'much appreciated', 'you\'re the best', 'this is helpful',
			'exactly what i needed', 'that works', 'perfect solution',
		),
		'emojis' => array( '😊', '😀', '🙂', '👍', '❤️', '💯', '🎉', '✨', '🙏' ),
	);

	/**
	 * Negative sentiment indicators.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $negative_indicators = array(
		'words' => array(
			'terrible', 'awful', 'horrible', 'worst', 'hate', 'angry',
			'frustrated', 'annoying', 'useless', 'pathetic', 'disappointing',
			'ridiculous', 'unacceptable', 'stupid', 'broken', 'failed',
			'wrong', 'bad', 'poor', 'unhappy', 'upset', 'mad',
		),
		'phrases' => array(
			'doesn\'t work', 'not working', 'waste of time', 'this is broken',
			'i give up', 'fed up', 'sick of', 'not helpful', 'makes no sense',
			'completely wrong', 'total failure', 'very disappointed',
		),
		'emojis' => array( '😡', '😤', '😠', '👎', '💢', '🤬', '😞', '😢', '😭' ),
	);

	/**
	 * Frustration indicators.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $frustration_indicators = array(
		'words' => array(
			'frustrated', 'annoyed', 'irritated', 'exasperated', 'aggravated',
			'furious', 'livid', 'infuriated', 'enraged', 'outraged',
		),
		'phrases' => array(
			'i\'ve been trying', 'for the last', 'i already told you',
			'how many times', 'again and again', 'still not working',
			'this is ridiculous', 'i can\'t believe', 'why won\'t',
			'nothing works', 'keep getting', 'same problem',
		),
		'patterns' => array(
			'/!{2,}/',                    // Multiple exclamation marks
			'/\?{2,}/',                   // Multiple question marks
			'/[A-Z]{4,}/',                // Extended caps (shouting)
			'/\b(never|always|every time)\b/i', // Absolutes
		),
	);

	/**
	 * Urgency indicators.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $urgency_indicators = array(
		'words' => array(
			'urgent', 'asap', 'immediately', 'emergency', 'critical',
			'deadline', 'now', 'quickly', 'hurry', 'important',
		),
		'phrases' => array(
			'as soon as possible', 'right now', 'i need this urgently',
			'time sensitive', 'can\'t wait', 'need help immediately',
		),
	);

	/**
	 * Sentiment thresholds.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $thresholds = array(
		'frustration_escalation' => 0.7,
		'high_frustration'       => 0.6,
		'moderate_frustration'   => 0.4,
		'positive_threshold'     => 0.3,
		'negative_threshold'     => -0.3,
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->provider_factory = new WP_AI_Chatbot_LeadGen_Pro_Provider_Factory();
	}

	/**
	 * Analyze sentiment of a message.
	 *
	 * @since 1.0.0
	 * @param string $message User message text.
	 * @param array  $args    Optional. Analysis arguments.
	 * @return array Sentiment analysis results.
	 */
	public function analyze( $message, $args = array() ) {
		$defaults = array(
			'use_ai'           => false,
			'include_emotions' => true,
			'conversation_id'  => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		// Rule-based analysis
		$rule_based = $this->analyze_rule_based( $message );

		// AI-based analysis if enabled and needed
		if ( $args['use_ai'] ) {
			$ai_analysis = $this->analyze_with_ai( $message );
			if ( ! is_wp_error( $ai_analysis ) ) {
				$rule_based = $this->combine_analyses( $rule_based, $ai_analysis );
			}
		}

		// Add conversation context if available
		if ( $args['conversation_id'] > 0 ) {
			$rule_based['conversation_trend'] = $this->get_conversation_trend( $args['conversation_id'] );
		}

		// Determine if escalation is needed
		$rule_based['should_escalate'] = $this->should_escalate( $rule_based );

		// Add suggestions for response tone
		$rule_based['suggested_tone'] = $this->suggest_response_tone( $rule_based );

		return $rule_based;
	}

	/**
	 * Analyze sentiment using rule-based approach.
	 *
	 * @since 1.0.0
	 * @param string $message User message text.
	 * @return array Analysis results.
	 */
	private function analyze_rule_based( $message ) {
		$message_lower = strtolower( $message );

		// Calculate scores
		$positive_score = $this->calculate_indicator_score( $message_lower, $this->positive_indicators );
		$negative_score = $this->calculate_indicator_score( $message_lower, $this->negative_indicators );
		$frustration_score = $this->calculate_frustration_score( $message, $message_lower );
		$urgency_score = $this->calculate_indicator_score( $message_lower, $this->urgency_indicators );

		// Calculate overall sentiment (-1 to 1)
		$sentiment_score = $positive_score - $negative_score;
		$sentiment_score = max( -1, min( 1, $sentiment_score ) );

		// Determine sentiment label
		$sentiment_label = $this->get_sentiment_label( $sentiment_score );

		// Determine frustration level
		$frustration_level = $this->get_frustration_level( $frustration_score );

		// Detect emotions
		$emotions = $this->detect_emotions( $message_lower, $positive_score, $negative_score, $frustration_score );

		return array(
			'sentiment_score'    => round( $sentiment_score, 3 ),
			'sentiment_label'    => $sentiment_label,
			'positive_score'     => round( $positive_score, 3 ),
			'negative_score'     => round( $negative_score, 3 ),
			'frustration_score'  => round( $frustration_score, 3 ),
			'frustration_level'  => $frustration_level,
			'urgency_score'      => round( $urgency_score, 3 ),
			'emotions'           => $emotions,
			'analysis_method'    => 'rule-based',
		);
	}

	/**
	 * Calculate indicator score.
	 *
	 * @since 1.0.0
	 * @param string $message_lower Lowercase message.
	 * @param array  $indicators    Indicator arrays.
	 * @return float Score between 0 and 1.
	 */
	private function calculate_indicator_score( $message_lower, $indicators ) {
		$score = 0;
		$matches = 0;

		// Check words
		if ( ! empty( $indicators['words'] ) ) {
			foreach ( $indicators['words'] as $word ) {
				if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/', $message_lower ) ) {
					$score += 0.15;
					$matches++;
				}
			}
		}

		// Check phrases
		if ( ! empty( $indicators['phrases'] ) ) {
			foreach ( $indicators['phrases'] as $phrase ) {
				if ( strpos( $message_lower, $phrase ) !== false ) {
					$score += 0.25; // Phrases are more significant
					$matches++;
				}
			}
		}

		// Check emojis
		if ( ! empty( $indicators['emojis'] ) ) {
			foreach ( $indicators['emojis'] as $emoji ) {
				if ( strpos( $message_lower, $emoji ) !== false ) {
					$score += 0.2;
					$matches++;
				}
			}
		}

		// Normalize score
		return min( 1, $score );
	}

	/**
	 * Calculate frustration score.
	 *
	 * @since 1.0.0
	 * @param string $message       Original message.
	 * @param string $message_lower Lowercase message.
	 * @return float Frustration score between 0 and 1.
	 */
	private function calculate_frustration_score( $message, $message_lower ) {
		$score = 0;

		// Check frustration words
		foreach ( $this->frustration_indicators['words'] as $word ) {
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/', $message_lower ) ) {
				$score += 0.2;
			}
		}

		// Check frustration phrases
		foreach ( $this->frustration_indicators['phrases'] as $phrase ) {
			if ( strpos( $message_lower, $phrase ) !== false ) {
				$score += 0.3;
			}
		}

		// Check frustration patterns (on original message for caps)
		foreach ( $this->frustration_indicators['patterns'] as $pattern ) {
			if ( preg_match( $pattern, $message ) ) {
				$score += 0.15;
			}
		}

		// Check message length (longer messages often indicate frustration)
		$word_count = str_word_count( $message );
		if ( $word_count > 50 ) {
			$score += 0.1;
		}

		// Check for repetition indicators
		if ( preg_match( '/\b(\w+)\b.*\b\1\b.*\b\1\b/i', $message ) ) {
			$score += 0.15; // Word repeated 3+ times
		}

		return min( 1, $score );
	}

	/**
	 * Get sentiment label from score.
	 *
	 * @since 1.0.0
	 * @param float $score Sentiment score.
	 * @return string Sentiment label.
	 */
	private function get_sentiment_label( $score ) {
		if ( $score >= 0.6 ) {
			return 'very_positive';
		} elseif ( $score >= 0.3 ) {
			return 'positive';
		} elseif ( $score <= -0.6 ) {
			return 'very_negative';
		} elseif ( $score <= -0.3 ) {
			return 'negative';
		}
		return 'neutral';
	}

	/**
	 * Get frustration level from score.
	 *
	 * @since 1.0.0
	 * @param float $score Frustration score.
	 * @return string Frustration level.
	 */
	private function get_frustration_level( $score ) {
		if ( $score >= $this->thresholds['frustration_escalation'] ) {
			return 'critical';
		} elseif ( $score >= $this->thresholds['high_frustration'] ) {
			return 'high';
		} elseif ( $score >= $this->thresholds['moderate_frustration'] ) {
			return 'moderate';
		}
		return 'low';
	}

	/**
	 * Detect emotions in message.
	 *
	 * @since 1.0.0
	 * @param string $message_lower   Lowercase message.
	 * @param float  $positive_score  Positive score.
	 * @param float  $negative_score  Negative score.
	 * @param float  $frustration_score Frustration score.
	 * @return array Detected emotions with scores.
	 */
	private function detect_emotions( $message_lower, $positive_score, $negative_score, $frustration_score ) {
		$emotions = array();

		// Emotion indicators
		$emotion_indicators = array(
			'happy' => array(
				'words' => array( 'happy', 'glad', 'pleased', 'delighted', 'joyful', 'cheerful' ),
				'threshold' => 0.2,
			),
			'excited' => array(
				'words' => array( 'excited', 'thrilled', 'eager', 'enthusiastic', 'pumped' ),
				'threshold' => 0.2,
			),
			'grateful' => array(
				'words' => array( 'thank', 'thanks', 'grateful', 'appreciate', 'appreciated' ),
				'threshold' => 0.2,
			),
			'confused' => array(
				'words' => array( 'confused', 'confusing', 'unclear', 'don\'t understand', 'lost' ),
				'threshold' => 0.15,
			),
			'angry' => array(
				'words' => array( 'angry', 'furious', 'mad', 'livid', 'outraged' ),
				'threshold' => 0.3,
			),
			'sad' => array(
				'words' => array( 'sad', 'disappointed', 'unhappy', 'upset', 'let down' ),
				'threshold' => 0.2,
			),
			'worried' => array(
				'words' => array( 'worried', 'concerned', 'anxious', 'nervous', 'afraid' ),
				'threshold' => 0.2,
			),
			'impatient' => array(
				'words' => array( 'waiting', 'still', 'yet', 'when', 'how long' ),
				'threshold' => 0.15,
			),
		);

		foreach ( $emotion_indicators as $emotion => $data ) {
			$score = 0;
			foreach ( $data['words'] as $word ) {
				if ( strpos( $message_lower, $word ) !== false ) {
					$score += 0.3;
				}
			}

			if ( $score >= $data['threshold'] ) {
				$emotions[ $emotion ] = min( 1, $score );
			}
		}

		// Add frustration if score is high
		if ( $frustration_score >= $this->thresholds['moderate_frustration'] ) {
			$emotions['frustrated'] = $frustration_score;
		}

		// Sort by score
		arsort( $emotions );

		return $emotions;
	}

	/**
	 * Analyze sentiment using AI.
	 *
	 * @since 1.0.0
	 * @param string $message User message.
	 * @return array|WP_Error Analysis results or error.
	 */
	private function analyze_with_ai( $message ) {
		$provider_name = $this->config->get( 'ai_provider', 'openai' );
		$provider = $this->provider_factory->get_provider( $provider_name );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$system_prompt = __( "Analyze the emotional sentiment of the following message. Return a JSON object with:
- sentiment_score: float from -1 (very negative) to 1 (very positive)
- sentiment_label: 'very_negative', 'negative', 'neutral', 'positive', or 'very_positive'
- frustration_score: float from 0 to 1 indicating frustration level
- emotions: array of detected emotions with their intensity scores (0-1)
- urgency_score: float from 0 to 1 indicating urgency

Be accurate and consider context, sarcasm, and implicit emotions.", 'wp-ai-chatbot-leadgen-pro' );

		$messages = array(
			array( 'role' => 'system', 'content' => $system_prompt ),
			array( 'role' => 'user', 'content' => $message ),
		);

		$response = $provider->chat_completion(
			$messages,
			array(
				'model'       => 'gpt-3.5-turbo',
				'temperature' => 0.3,
				'max_tokens'  => 200,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_ai_response( $response['content'] );
	}

	/**
	 * Parse AI analysis response.
	 *
	 * @since 1.0.0
	 * @param string $response_content AI response.
	 * @return array Parsed analysis.
	 */
	private function parse_ai_response( $response_content ) {
		$decoded = json_decode( $response_content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'sentiment_score'   => 0,
				'sentiment_label'   => 'neutral',
				'frustration_score' => 0,
				'emotions'          => array(),
				'analysis_method'   => 'ai-failed',
			);
		}

		return array(
			'sentiment_score'   => floatval( $decoded['sentiment_score'] ?? 0 ),
			'sentiment_label'   => sanitize_text_field( $decoded['sentiment_label'] ?? 'neutral' ),
			'frustration_score' => floatval( $decoded['frustration_score'] ?? 0 ),
			'urgency_score'     => floatval( $decoded['urgency_score'] ?? 0 ),
			'emotions'          => is_array( $decoded['emotions'] ?? null ) ? $decoded['emotions'] : array(),
			'analysis_method'   => 'ai',
		);
	}

	/**
	 * Combine rule-based and AI analyses.
	 *
	 * @since 1.0.0
	 * @param array $rule_based Rule-based analysis.
	 * @param array $ai_analysis AI analysis.
	 * @return array Combined analysis.
	 */
	private function combine_analyses( $rule_based, $ai_analysis ) {
		// Weighted average (favor AI for nuanced analysis)
		$rule_weight = 0.4;
		$ai_weight = 0.6;

		$combined = array(
			'sentiment_score'   => ( $rule_based['sentiment_score'] * $rule_weight ) + ( $ai_analysis['sentiment_score'] * $ai_weight ),
			'frustration_score' => ( $rule_based['frustration_score'] * $rule_weight ) + ( $ai_analysis['frustration_score'] * $ai_weight ),
			'urgency_score'     => ( $rule_based['urgency_score'] * $rule_weight ) + ( $ai_analysis['urgency_score'] * $ai_weight ),
			'positive_score'    => $rule_based['positive_score'],
			'negative_score'    => $rule_based['negative_score'],
			'analysis_method'   => 'combined',
		);

		// Re-calculate labels based on combined scores
		$combined['sentiment_label'] = $this->get_sentiment_label( $combined['sentiment_score'] );
		$combined['frustration_level'] = $this->get_frustration_level( $combined['frustration_score'] );

		// Merge emotions
		$combined['emotions'] = array_merge( $rule_based['emotions'], $ai_analysis['emotions'] );
		arsort( $combined['emotions'] );

		return $combined;
	}

	/**
	 * Get conversation sentiment trend.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array Sentiment trend data.
	 */
	public function get_conversation_trend( $conversation_id ) {
		global $wpdb;
		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		// Get recent user messages
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT content, metadata, created_at FROM {$messages_table} 
				 WHERE conversation_id = %d AND role = 'user' 
				 ORDER BY created_at DESC LIMIT 10",
				$conversation_id
			),
			ARRAY_A
		);

		if ( empty( $messages ) ) {
			return array(
				'trend'        => 'stable',
				'direction'    => 0,
				'message_count' => 0,
			);
		}

		// Analyze sentiment trend
		$sentiments = array();
		foreach ( array_reverse( $messages ) as $msg ) {
			$metadata = maybe_unserialize( $msg['metadata'] );
			if ( isset( $metadata['sentiment_score'] ) ) {
				$sentiments[] = $metadata['sentiment_score'];
			} else {
				// Analyze if not stored
				$analysis = $this->analyze_rule_based( $msg['content'] );
				$sentiments[] = $analysis['sentiment_score'];
			}
		}

		// Calculate trend direction
		$count = count( $sentiments );
		if ( $count < 2 ) {
			return array(
				'trend'         => 'stable',
				'direction'     => 0,
				'message_count' => $count,
				'average'       => $sentiments[0] ?? 0,
			);
		}

		// Compare first half to second half
		$half = intval( $count / 2 );
		$first_half_avg = array_sum( array_slice( $sentiments, 0, $half ) ) / $half;
		$second_half_avg = array_sum( array_slice( $sentiments, $half ) ) / ( $count - $half );

		$direction = $second_half_avg - $first_half_avg;

		$trend = 'stable';
		if ( $direction > 0.2 ) {
			$trend = 'improving';
		} elseif ( $direction < -0.2 ) {
			$trend = 'declining';
		}

		return array(
			'trend'         => $trend,
			'direction'     => round( $direction, 3 ),
			'message_count' => $count,
			'average'       => round( array_sum( $sentiments ) / $count, 3 ),
			'first_half'    => round( $first_half_avg, 3 ),
			'second_half'   => round( $second_half_avg, 3 ),
		);
	}

	/**
	 * Determine if escalation is needed.
	 *
	 * @since 1.0.0
	 * @param array $analysis Analysis results.
	 * @return bool True if escalation needed.
	 */
	private function should_escalate( $analysis ) {
		// Critical frustration level
		if ( $analysis['frustration_level'] === 'critical' ) {
			return true;
		}

		// Very negative sentiment
		if ( $analysis['sentiment_label'] === 'very_negative' ) {
			return true;
		}

		// High frustration with negative sentiment
		if ( $analysis['frustration_level'] === 'high' && $analysis['sentiment_score'] < 0 ) {
			return true;
		}

		// Declining conversation trend
		if ( isset( $analysis['conversation_trend'] ) && $analysis['conversation_trend']['trend'] === 'declining' ) {
			if ( $analysis['frustration_level'] === 'moderate' || $analysis['frustration_level'] === 'high' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Suggest response tone based on sentiment.
	 *
	 * @since 1.0.0
	 * @param array $analysis Analysis results.
	 * @return array Suggested tone and guidelines.
	 */
	private function suggest_response_tone( $analysis ) {
		$suggestions = array(
			'tone'       => 'neutral',
			'guidelines' => array(),
		);

		// High frustration or very negative
		if ( $analysis['frustration_level'] === 'critical' || $analysis['frustration_level'] === 'high' ) {
			$suggestions['tone'] = 'empathetic';
			$suggestions['guidelines'] = array(
				__( 'Acknowledge their frustration explicitly', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Apologize for any inconvenience', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Focus on solutions, not problems', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Offer escalation to human support', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}
		// Moderate frustration or negative
		elseif ( $analysis['frustration_level'] === 'moderate' || $analysis['sentiment_label'] === 'negative' ) {
			$suggestions['tone'] = 'reassuring';
			$suggestions['guidelines'] = array(
				__( 'Show understanding of their concern', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Be patient and thorough', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Provide clear, step-by-step help', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}
		// Positive sentiment
		elseif ( $analysis['sentiment_label'] === 'positive' || $analysis['sentiment_label'] === 'very_positive' ) {
			$suggestions['tone'] = 'enthusiastic';
			$suggestions['guidelines'] = array(
				__( 'Match their positive energy', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Express appreciation for their feedback', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Offer additional value or information', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}
		// Neutral
		else {
			$suggestions['tone'] = 'professional';
			$suggestions['guidelines'] = array(
				__( 'Be friendly and helpful', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'Provide clear, concise answers', 'wp-ai-chatbot-leadgen-pro' ),
			);
		}

		// Add urgency handling
		if ( $analysis['urgency_score'] >= 0.5 ) {
			$suggestions['guidelines'][] = __( 'Respond with urgency and prioritize their request', 'wp-ai-chatbot-leadgen-pro' );
		}

		return $suggestions;
	}

	/**
	 * Analyze batch of messages.
	 *
	 * @since 1.0.0
	 * @param array $messages Array of messages.
	 * @param array $args     Analysis arguments.
	 * @return array Array of analysis results.
	 */
	public function analyze_batch( $messages, $args = array() ) {
		$results = array();
		foreach ( $messages as $message ) {
			$results[] = $this->analyze( $message, $args );
		}
		return $results;
	}

	/**
	 * Get sentiment statistics for a conversation.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array Sentiment statistics.
	 */
	public function get_conversation_stats( $conversation_id ) {
		$trend = $this->get_conversation_trend( $conversation_id );

		global $wpdb;
		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		// Count messages by sentiment
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT metadata FROM {$messages_table} 
				 WHERE conversation_id = %d AND role = 'user'",
				$conversation_id
			),
			ARRAY_A
		);

		$stats = array(
			'total_messages' => count( $results ),
			'positive'       => 0,
			'negative'       => 0,
			'neutral'        => 0,
			'frustrated'     => 0,
			'trend'          => $trend,
		);

		foreach ( $results as $row ) {
			$metadata = maybe_unserialize( $row['metadata'] );
			if ( isset( $metadata['sentiment_label'] ) ) {
				if ( in_array( $metadata['sentiment_label'], array( 'positive', 'very_positive' ), true ) ) {
					$stats['positive']++;
				} elseif ( in_array( $metadata['sentiment_label'], array( 'negative', 'very_negative' ), true ) ) {
					$stats['negative']++;
				} else {
					$stats['neutral']++;
				}

				if ( isset( $metadata['frustration_level'] ) && in_array( $metadata['frustration_level'], array( 'moderate', 'high', 'critical' ), true ) ) {
					$stats['frustrated']++;
				}
			}
		}

		return $stats;
	}

	/**
	 * Get global sentiment statistics.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Global statistics.
	 */
	public function get_global_stats( $args = array() ) {
		$defaults = array(
			'days' => 30,
		);
		$args = wp_parse_args( $args, $defaults );

		global $wpdb;
		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$args['days']} days" ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT metadata FROM {$messages_table} 
				 WHERE role = 'user' AND created_at >= %s",
				$since
			),
			ARRAY_A
		);

		$stats = array(
			'total_messages'        => count( $results ),
			'positive_count'        => 0,
			'negative_count'        => 0,
			'neutral_count'         => 0,
			'high_frustration_count' => 0,
			'escalation_needed'     => 0,
			'average_sentiment'     => 0,
			'average_frustration'   => 0,
		);

		$sentiment_sum = 0;
		$frustration_sum = 0;
		$analyzed = 0;

		foreach ( $results as $row ) {
			$metadata = maybe_unserialize( $row['metadata'] );
			if ( isset( $metadata['sentiment_score'] ) ) {
				$sentiment_sum += $metadata['sentiment_score'];
				$frustration_sum += $metadata['frustration_score'] ?? 0;
				$analyzed++;

				if ( $metadata['sentiment_score'] > 0.3 ) {
					$stats['positive_count']++;
				} elseif ( $metadata['sentiment_score'] < -0.3 ) {
					$stats['negative_count']++;
				} else {
					$stats['neutral_count']++;
				}

				if ( isset( $metadata['frustration_level'] ) && in_array( $metadata['frustration_level'], array( 'high', 'critical' ), true ) ) {
					$stats['high_frustration_count']++;
				}

				if ( ! empty( $metadata['should_escalate'] ) ) {
					$stats['escalation_needed']++;
				}
			}
		}

		if ( $analyzed > 0 ) {
			$stats['average_sentiment'] = round( $sentiment_sum / $analyzed, 3 );
			$stats['average_frustration'] = round( $frustration_sum / $analyzed, 3 );
		}

		return $stats;
	}
}

