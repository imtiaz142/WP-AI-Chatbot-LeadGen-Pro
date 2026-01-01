<?php
/**
 * Response Generator.
 *
 * Generates AI responses using the RAG system with accurate citations.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Response_Generator {

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
	 * Hybrid search instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Hybrid_Search
	 */
	private $hybrid_search;

	/**
	 * Cross encoder reranker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Cross_Encoder_Reranker
	 */
	private $reranker;

	/**
	 * Context assembler instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Context_Assembler
	 */
	private $context_assembler;

	/**
	 * Citation tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker
	 */
	private $citation_tracker;

	/**
	 * Response formatter instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Response_Formatter
	 */
	private $response_formatter;

	/**
	 * Conversation memory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory
	 */
	private $memory;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->provider_factory = new WP_AI_Chatbot_LeadGen_Pro_Provider_Factory();
		$this->hybrid_search = new WP_AI_Chatbot_LeadGen_Pro_Hybrid_Search();
		$this->reranker = new WP_AI_Chatbot_LeadGen_Pro_Cross_Encoder_Reranker();
		$this->context_assembler = new WP_AI_Chatbot_LeadGen_Pro_Context_Assembler();
		$this->citation_tracker = new WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker();
		$this->response_formatter = new WP_AI_Chatbot_LeadGen_Pro_Response_Formatter();
		$this->memory = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory();
	}

	/**
	 * Generate response for user query.
	 *
	 * @since 1.0.0
	 * @param string $query   User query.
	 * @param array  $context Generation context.
	 * @return array|WP_Error Response data or error.
	 */
	public function generate( $query, $context = array() ) {
		$defaults = array(
			'conversation_id'      => 0,
			'session_id'           => '',
			'message_id'           => 0,
			'conversation_history' => array(),
			'intent'               => null,
			'sentiment'            => null,
			'max_tokens'           => 1024,
			'temperature'          => 0.7,
			'include_citations'    => true,
			'tone'                 => 'professional',
		);
		$context = wp_parse_args( $context, $defaults );

		$start_time = microtime( true );

		try {
			// Step 1: Retrieve relevant content from knowledge base
			$retrieved_chunks = $this->retrieve_context( $query, $context );

			// Step 2: Rerank chunks for relevance
			$reranked_chunks = $this->rerank_chunks( $query, $retrieved_chunks );

			// Step 3: Assemble context within token limits
			$assembled_context = $this->assemble_context( $query, $reranked_chunks, $context );

			// Step 4: Build prompt with context and conversation history
			$prompt = $this->build_prompt( $query, $assembled_context, $context );

			// Step 5: Generate AI response
			$ai_response = $this->call_ai( $prompt, $context );

			if ( is_wp_error( $ai_response ) ) {
				return $ai_response;
			}

			// Step 6: Format response with citations
			$formatted_response = $this->format_response( $ai_response, $assembled_context, $context );

			// Step 7: Track citations
			if ( $context['include_citations'] && $context['conversation_id'] > 0 && $context['message_id'] > 0 ) {
				$this->track_citations( $formatted_response, $context );
			}

			// Step 8: Update memory with topic
			if ( ! empty( $context['session_id'] ) ) {
				$this->update_memory( $query, $context );
			}

			$elapsed = microtime( true ) - $start_time;

			$this->logger->debug(
				'Response generated',
				array(
					'query'           => substr( $query, 0, 100 ),
					'chunks_used'     => count( $assembled_context['chunks'] ?? array() ),
					'elapsed_seconds' => round( $elapsed, 3 ),
				)
			);

			return array(
				'response'         => $formatted_response['text'],
				'response_html'    => $formatted_response['html'],
				'citations'        => $formatted_response['citations'],
				'chunks_used'      => count( $assembled_context['chunks'] ?? array() ),
				'tokens_used'      => $assembled_context['tokens_used'] ?? 0,
				'confidence'       => $this->calculate_confidence( $reranked_chunks ),
				'elapsed_seconds'  => round( $elapsed, 3 ),
				'fallback_used'    => empty( $assembled_context['chunks'] ),
			);

		} catch ( Exception $e ) {
			$this->logger->error(
				'Response generation failed',
				array(
					'error' => $e->getMessage(),
					'query' => substr( $query, 0, 100 ),
				)
			);

			return new WP_Error(
				'generation_failed',
				__( 'Failed to generate response.', 'wp-ai-chatbot-leadgen-pro' ),
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Retrieve relevant context from knowledge base.
	 *
	 * @since 1.0.0
	 * @param string $query   User query.
	 * @param array  $context Generation context.
	 * @return array Retrieved chunks.
	 */
	private function retrieve_context( $query, $context ) {
		$search_args = array(
			'limit'           => 20,
			'semantic_weight' => 0.7,
			'keyword_weight'  => 0.3,
		);

		// Adjust search based on intent
		if ( ! empty( $context['intent'] ) ) {
			$intent_weights = array(
				'technical_question' => array( 'semantic_weight' => 0.6, 'keyword_weight' => 0.4 ),
				'pricing'            => array( 'semantic_weight' => 0.5, 'keyword_weight' => 0.5 ),
				'feature_comparison' => array( 'semantic_weight' => 0.6, 'keyword_weight' => 0.4 ),
			);

			if ( isset( $intent_weights[ $context['intent'] ] ) ) {
				$search_args = array_merge( $search_args, $intent_weights[ $context['intent'] ] );
			}
		}

		$results = $this->hybrid_search->search( $query, $search_args );

		if ( is_wp_error( $results ) ) {
			$this->logger->warning(
				'Hybrid search failed',
				array( 'error' => $results->get_error_message() )
			);
			return array();
		}

		return $results;
	}

	/**
	 * Rerank chunks for relevance.
	 *
	 * @since 1.0.0
	 * @param string $query  User query.
	 * @param array  $chunks Retrieved chunks.
	 * @return array Reranked chunks.
	 */
	private function rerank_chunks( $query, $chunks ) {
		if ( empty( $chunks ) ) {
			return array();
		}

		$reranked = $this->reranker->rerank( $query, $chunks, array(
			'top_k'         => 10,
			'min_score'     => 0.3,
			'use_ai'        => $this->config->get( 'use_ai_reranking', false ),
		) );

		if ( is_wp_error( $reranked ) ) {
			$this->logger->warning(
				'Reranking failed, using original order',
				array( 'error' => $reranked->get_error_message() )
			);
			return array_slice( $chunks, 0, 10 );
		}

		return $reranked;
	}

	/**
	 * Assemble context within token limits.
	 *
	 * @since 1.0.0
	 * @param string $query   User query.
	 * @param array  $chunks  Reranked chunks.
	 * @param array  $context Generation context.
	 * @return array Assembled context.
	 */
	private function assemble_context( $query, $chunks, $context ) {
		$model = $this->config->get( 'chat_model', 'gpt-4o-mini' );

		// Get max context tokens based on model
		$max_context_tokens = $this->get_max_context_tokens( $model );

		// Reserve tokens for response
		$response_tokens = $context['max_tokens'];

		// Reserve tokens for conversation history
		$history_tokens = $this->estimate_history_tokens( $context['conversation_history'] );

		// Reserve tokens for system prompt
		$system_prompt_tokens = 500;

		// Available tokens for RAG context
		$available_tokens = $max_context_tokens - $response_tokens - $history_tokens - $system_prompt_tokens;

		$assembled = $this->context_assembler->assemble_context(
			$query,
			$chunks,
			$context['conversation_history'],
			$model,
			array(
				'max_tokens' => $available_tokens,
				'strategy'   => 'quality',
			)
		);

		return $assembled;
	}

	/**
	 * Build prompt for AI generation.
	 *
	 * @since 1.0.0
	 * @param string $query             User query.
	 * @param array  $assembled_context Assembled context.
	 * @param array  $context           Generation context.
	 * @return array Messages array for AI.
	 */
	private function build_prompt( $query, $assembled_context, $context ) {
		$messages = array();

		// System prompt
		$system_prompt = $this->build_system_prompt( $context );
		$messages[] = array(
			'role'    => 'system',
			'content' => $system_prompt,
		);

		// Add RAG context
		if ( ! empty( $assembled_context['formatted_context'] ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => sprintf(
					/* translators: 1: Knowledge base context */
					__( "Here is relevant information from our knowledge base that you should use to answer the user's question:\n\n%s", 'wp-ai-chatbot-leadgen-pro' ),
					$assembled_context['formatted_context']
				),
			);
		}

		// Add memory context
		if ( ! empty( $context['session_id'] ) ) {
			$memory_context = $this->memory->get_memory_context( $context['session_id'] );
			if ( ! empty( $memory_context ) ) {
				$messages[] = array(
					'role'    => 'system',
					'content' => sprintf(
						/* translators: 1: Memory context */
						__( "Here is what we know about this user:\n%s", 'wp-ai-chatbot-leadgen-pro' ),
						$memory_context
					),
				);
			}
		}

		// Add conversation history
		if ( ! empty( $context['conversation_history'] ) ) {
			foreach ( $context['conversation_history'] as $msg ) {
				$messages[] = array(
					'role'    => $msg['role'] === 'user' ? 'user' : 'assistant',
					'content' => $msg['content'],
				);
			}
		}

		// Add current user query
		$messages[] = array(
			'role'    => 'user',
			'content' => $query,
		);

		return $messages;
	}

	/**
	 * Build system prompt.
	 *
	 * @since 1.0.0
	 * @param array $context Generation context.
	 * @return string System prompt.
	 */
	private function build_system_prompt( $context ) {
		$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );
		$custom_instructions = $this->config->get( 'custom_ai_instructions', '' );

		$tone_instructions = $this->get_tone_instructions( $context['tone'] );
		$sentiment_instructions = $this->get_sentiment_instructions( $context['sentiment'] );

		$prompt = sprintf(
			/* translators: 1: Company name */
			__( "You are a helpful AI assistant for %s. Your role is to assist visitors by answering their questions accurately and helpfully.", 'wp-ai-chatbot-leadgen-pro' ),
			$company_name
		);

		$prompt .= "\n\n" . __( "Guidelines:", 'wp-ai-chatbot-leadgen-pro' );
		$prompt .= "\n" . __( "1. Answer questions based primarily on the provided knowledge base context.", 'wp-ai-chatbot-leadgen-pro' );
		$prompt .= "\n" . __( "2. If information is not in the context, acknowledge this clearly rather than making up information.", 'wp-ai-chatbot-leadgen-pro' );
		$prompt .= "\n" . __( "3. Be conversational, friendly, and professional.", 'wp-ai-chatbot-leadgen-pro' );
		$prompt .= "\n" . __( "4. Keep responses concise but complete - aim for 2-4 paragraphs maximum.", 'wp-ai-chatbot-leadgen-pro' );
		$prompt .= "\n" . __( "5. When referencing information from the knowledge base, include citation markers like [1], [2], etc.", 'wp-ai-chatbot-leadgen-pro' );
		$prompt .= "\n" . __( "6. If the user seems interested in purchasing or learning more, offer to help them take the next step.", 'wp-ai-chatbot-leadgen-pro' );

		// Add tone instructions
		if ( ! empty( $tone_instructions ) ) {
			$prompt .= "\n\n" . $tone_instructions;
		}

		// Add sentiment-based instructions
		if ( ! empty( $sentiment_instructions ) ) {
			$prompt .= "\n\n" . $sentiment_instructions;
		}

		// Add custom instructions
		if ( ! empty( $custom_instructions ) ) {
			$prompt .= "\n\n" . __( "Additional Instructions:", 'wp-ai-chatbot-leadgen-pro' ) . "\n" . $custom_instructions;
		}

		return $prompt;
	}

	/**
	 * Get tone-specific instructions.
	 *
	 * @since 1.0.0
	 * @param string $tone Desired tone.
	 * @return string Tone instructions.
	 */
	private function get_tone_instructions( $tone ) {
		$tones = array(
			'professional' => __( 'Maintain a professional and business-like tone while remaining approachable.', 'wp-ai-chatbot-leadgen-pro' ),
			'friendly'     => __( 'Be warm, friendly, and conversational. Use a casual but helpful tone.', 'wp-ai-chatbot-leadgen-pro' ),
			'formal'       => __( 'Use a formal, polished tone appropriate for business communication.', 'wp-ai-chatbot-leadgen-pro' ),
			'enthusiastic' => __( 'Be enthusiastic and energetic! Show excitement about helping the user.', 'wp-ai-chatbot-leadgen-pro' ),
			'empathetic'   => __( 'Be empathetic and understanding. Show that you care about the user\'s concerns.', 'wp-ai-chatbot-leadgen-pro' ),
			'reassuring'   => __( 'Be reassuring and supportive. Help the user feel confident and supported.', 'wp-ai-chatbot-leadgen-pro' ),
		);

		return $tones[ $tone ] ?? $tones['professional'];
	}

	/**
	 * Get sentiment-based instructions.
	 *
	 * @since 1.0.0
	 * @param array|null $sentiment Sentiment analysis data.
	 * @return string Sentiment instructions.
	 */
	private function get_sentiment_instructions( $sentiment ) {
		if ( empty( $sentiment ) ) {
			return '';
		}

		$instructions = '';

		// High frustration
		if ( isset( $sentiment['frustration_level'] ) ) {
			if ( in_array( $sentiment['frustration_level'], array( 'high', 'critical' ), true ) ) {
				$instructions = __( 'IMPORTANT: The user appears frustrated. Acknowledge their frustration, apologize for any inconvenience, and focus on providing a helpful solution quickly.', 'wp-ai-chatbot-leadgen-pro' );
			} elseif ( $sentiment['frustration_level'] === 'moderate' ) {
				$instructions = __( 'Note: The user may be experiencing some frustration. Be patient and thorough in your response.', 'wp-ai-chatbot-leadgen-pro' );
			}
		}

		// Very negative sentiment
		if ( empty( $instructions ) && isset( $sentiment['sentiment_label'] ) ) {
			if ( $sentiment['sentiment_label'] === 'very_negative' ) {
				$instructions = __( 'IMPORTANT: The user seems unhappy. Be empathetic, acknowledge their concern, and focus on resolution.', 'wp-ai-chatbot-leadgen-pro' );
			} elseif ( in_array( $sentiment['sentiment_label'], array( 'positive', 'very_positive' ), true ) ) {
				$instructions = __( 'The user seems positive! Match their energy and enthusiasm in your response.', 'wp-ai-chatbot-leadgen-pro' );
			}
		}

		return $instructions;
	}

	/**
	 * Call AI provider for response generation.
	 *
	 * @since 1.0.0
	 * @param array $messages Messages array.
	 * @param array $context  Generation context.
	 * @return array|WP_Error AI response or error.
	 */
	private function call_ai( $messages, $context ) {
		$provider_name = $this->config->get( 'ai_provider', 'openai' );
		$provider = $this->provider_factory->get_provider( $provider_name );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$model = $this->config->get( 'chat_model', 'gpt-4o-mini' );

		$response = $provider->chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => $context['temperature'],
				'max_tokens'  => $context['max_tokens'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'content'      => $response['content'] ?? '',
			'model'        => $response['model'] ?? $model,
			'usage'        => $response['usage'] ?? array(),
			'finish_reason' => $response['finish_reason'] ?? 'stop',
		);
	}

	/**
	 * Format response with citations.
	 *
	 * @since 1.0.0
	 * @param array $ai_response       AI response data.
	 * @param array $assembled_context Assembled context.
	 * @param array $context           Generation context.
	 * @return array Formatted response.
	 */
	private function format_response( $ai_response, $assembled_context, $context ) {
		$response_text = $ai_response['content'];
		$chunks = $assembled_context['chunks'] ?? array();

		// Build citations array
		$citations = array();
		foreach ( $chunks as $index => $chunk ) {
			$citation_num = $index + 1;
			$citations[ $citation_num ] = array(
				'chunk_id'   => $chunk['chunk_id'] ?? $chunk['id'] ?? 0,
				'source_url' => $chunk['source_url'] ?? '',
				'title'      => $chunk['title'] ?? $this->extract_title_from_url( $chunk['source_url'] ?? '' ),
				'score'      => $chunk['score'] ?? 0,
			);
		}

		// Format with citations
		if ( $context['include_citations'] && ! empty( $citations ) ) {
			$formatted = $this->response_formatter->format_response(
				$response_text,
				$citations,
				array( 'format' => 'html' )
			);

			return array(
				'text'      => $this->strip_citation_markers( $response_text ),
				'html'      => $formatted,
				'citations' => array_values( $citations ),
			);
		}

		return array(
			'text'      => $response_text,
			'html'      => nl2br( esc_html( $response_text ) ),
			'citations' => array(),
		);
	}

	/**
	 * Strip citation markers from text.
	 *
	 * @since 1.0.0
	 * @param string $text Text with citations.
	 * @return string Clean text.
	 */
	private function strip_citation_markers( $text ) {
		return preg_replace( '/\[\d+\]/', '', $text );
	}

	/**
	 * Extract title from URL.
	 *
	 * @since 1.0.0
	 * @param string $url URL.
	 * @return string Title.
	 */
	private function extract_title_from_url( $url ) {
		if ( empty( $url ) ) {
			return __( 'Source', 'wp-ai-chatbot-leadgen-pro' );
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return wp_parse_url( $url, PHP_URL_HOST ) ?: __( 'Source', 'wp-ai-chatbot-leadgen-pro' );
		}

		$slug = basename( rtrim( $path, '/' ) );
		$title = str_replace( array( '-', '_' ), ' ', $slug );
		return ucwords( $title );
	}

	/**
	 * Track citations for analytics.
	 *
	 * @since 1.0.0
	 * @param array $formatted_response Formatted response.
	 * @param array $context            Generation context.
	 */
	private function track_citations( $formatted_response, $context ) {
		if ( empty( $formatted_response['citations'] ) ) {
			return;
		}

		$citations_data = array();
		foreach ( $formatted_response['citations'] as $citation ) {
			$citations_data[] = array(
				'chunk_id'   => $citation['chunk_id'],
				'source_url' => $citation['source_url'],
				'title'      => $citation['title'],
				'score'      => $citation['score'],
			);
		}

		$this->citation_tracker->record_citations(
			$context['conversation_id'],
			$context['message_id'],
			$citations_data
		);
	}

	/**
	 * Update memory with conversation topic.
	 *
	 * @since 1.0.0
	 * @param string $query   User query.
	 * @param array  $context Generation context.
	 */
	private function update_memory( $query, $context ) {
		// Extract topic from query
		$topic = $this->extract_topic( $query, $context['intent'] );

		if ( ! empty( $topic ) ) {
			$this->memory->store_context(
				$context['session_id'],
				'last_topic',
				$topic,
				86400 // 24 hours
			);
		}

		// Extract and store any facts from the query
		$this->memory->extract_facts_from_message( $context['session_id'], $query );

		// Infer interests
		$this->memory->infer_interests_from_message( $context['session_id'], $query, array(
			'intent' => $context['intent'],
		) );
	}

	/**
	 * Extract topic from query.
	 *
	 * @since 1.0.0
	 * @param string      $query  User query.
	 * @param string|null $intent Detected intent.
	 * @return string Topic.
	 */
	private function extract_topic( $query, $intent ) {
		// Use intent as topic if available
		$intent_topics = array(
			'pricing'            => __( 'pricing', 'wp-ai-chatbot-leadgen-pro' ),
			'meeting_request'    => __( 'scheduling a meeting', 'wp-ai-chatbot-leadgen-pro' ),
			'technical_question' => __( 'technical questions', 'wp-ai-chatbot-leadgen-pro' ),
			'feature_comparison' => __( 'feature comparison', 'wp-ai-chatbot-leadgen-pro' ),
			'service_inquiry'    => __( 'our services', 'wp-ai-chatbot-leadgen-pro' ),
			'complaint'          => __( 'an issue', 'wp-ai-chatbot-leadgen-pro' ),
		);

		if ( isset( $intent_topics[ $intent ] ) ) {
			return $intent_topics[ $intent ];
		}

		// Extract key words from query
		$words = str_word_count( strtolower( $query ), 1 );
		$stop_words = array( 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
			'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
			'may', 'might', 'must', 'shall', 'can', 'need', 'dare', 'ought', 'used',
			'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from', 'up', 'about',
			'into', 'over', 'after', 'and', 'but', 'or', 'as', 'if', 'when', 'than',
			'because', 'while', 'although', 'where', 'after', 'so', 'though', 'since',
			'until', 'unless', 'that', 'this', 'it', 'i', 'you', 'he', 'she', 'we', 'they',
			'what', 'which', 'who', 'whom', 'whose', 'how', 'why', 'where', 'when' );

		$keywords = array_diff( $words, $stop_words );
		$keywords = array_slice( $keywords, 0, 3 );

		if ( ! empty( $keywords ) ) {
			return implode( ' ', $keywords );
		}

		return '';
	}

	/**
	 * Calculate confidence score for response.
	 *
	 * @since 1.0.0
	 * @param array $chunks Reranked chunks.
	 * @return float Confidence score (0-1).
	 */
	private function calculate_confidence( $chunks ) {
		if ( empty( $chunks ) ) {
			return 0.3; // Low confidence without context
		}

		// Average score of top chunks
		$top_chunks = array_slice( $chunks, 0, 3 );
		$total_score = 0;

		foreach ( $top_chunks as $chunk ) {
			$total_score += $chunk['score'] ?? 0.5;
		}

		$avg_score = $total_score / count( $top_chunks );

		// Scale to 0.5-1.0 range (we have context, so at least moderate confidence)
		return 0.5 + ( $avg_score * 0.5 );
	}

	/**
	 * Get max context tokens for model.
	 *
	 * @since 1.0.0
	 * @param string $model Model name.
	 * @return int Max context tokens.
	 */
	private function get_max_context_tokens( $model ) {
		$model_limits = array(
			'gpt-4o'           => 128000,
			'gpt-4o-mini'      => 128000,
			'gpt-4-turbo'      => 128000,
			'gpt-4'            => 8192,
			'gpt-3.5-turbo'    => 16385,
			'claude-3-opus'    => 200000,
			'claude-3-sonnet'  => 200000,
			'claude-3-haiku'   => 200000,
		);

		return $model_limits[ $model ] ?? 8192;
	}

	/**
	 * Estimate tokens for conversation history.
	 *
	 * @since 1.0.0
	 * @param array $history Conversation history.
	 * @return int Estimated tokens.
	 */
	private function estimate_history_tokens( $history ) {
		if ( empty( $history ) ) {
			return 0;
		}

		$total_chars = 0;
		foreach ( $history as $msg ) {
			$total_chars += strlen( $msg['content'] ?? '' );
		}

		// Rough estimate: 4 chars per token
		return intval( $total_chars / 4 );
	}

	/**
	 * Generate fallback response when no context available.
	 *
	 * @since 1.0.0
	 * @param string $query   User query.
	 * @param array  $context Generation context.
	 * @return array|WP_Error Response data or error.
	 */
	public function generate_fallback( $query, $context = array() ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->build_system_prompt( $context ),
			),
			array(
				'role'    => 'user',
				'content' => $query,
			),
		);

		$ai_response = $this->call_ai( $messages, $context );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		return array(
			'response'         => $ai_response['content'],
			'response_html'    => nl2br( esc_html( $ai_response['content'] ) ),
			'citations'        => array(),
			'chunks_used'      => 0,
			'confidence'       => 0.5,
			'fallback_used'    => true,
		);
	}

	/**
	 * Generate streaming response.
	 *
	 * @since 1.0.0
	 * @param string   $query    User query.
	 * @param array    $context  Generation context.
	 * @param callable $callback Callback for each chunk.
	 * @return array|WP_Error Final response data or error.
	 */
	public function generate_streaming( $query, $context, $callback ) {
		// Retrieve and prepare context
		$retrieved_chunks = $this->retrieve_context( $query, $context );
		$reranked_chunks = $this->rerank_chunks( $query, $retrieved_chunks );
		$assembled_context = $this->assemble_context( $query, $reranked_chunks, $context );
		$prompt = $this->build_prompt( $query, $assembled_context, $context );

		$provider_name = $this->config->get( 'ai_provider', 'openai' );
		$provider = $this->provider_factory->get_provider( $provider_name );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$model = $this->config->get( 'chat_model', 'gpt-4o-mini' );

		// Check if provider supports streaming
		if ( ! method_exists( $provider, 'chat_completion_stream' ) ) {
			// Fallback to non-streaming
			return $this->generate( $query, $context );
		}

		$full_response = '';

		$result = $provider->chat_completion_stream(
			$prompt,
			array(
				'model'       => $model,
				'temperature' => $context['temperature'] ?? 0.7,
				'max_tokens'  => $context['max_tokens'] ?? 1024,
			),
			function( $chunk ) use ( &$full_response, $callback ) {
				$full_response .= $chunk;
				call_user_func( $callback, $chunk );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Build citations from assembled context
		$citations = array();
		foreach ( $assembled_context['chunks'] ?? array() as $index => $chunk ) {
			$citations[] = array(
				'chunk_id'   => $chunk['chunk_id'] ?? $chunk['id'] ?? 0,
				'source_url' => $chunk['source_url'] ?? '',
				'title'      => $chunk['title'] ?? '',
			);
		}

		return array(
			'response'      => $full_response,
			'citations'     => $citations,
			'chunks_used'   => count( $assembled_context['chunks'] ?? array() ),
			'fallback_used' => empty( $assembled_context['chunks'] ),
		);
	}
}

