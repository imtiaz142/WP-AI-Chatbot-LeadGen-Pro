<?php
/**
 * Message Processor.
 *
 * Processes incoming user messages and generates AI responses.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Message_Processor {

	/**
	 * Conversation manager instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager
	 */
	private $conversation_manager;

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
	 * Cross-encoder reranker instance.
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
	 * Response formatter instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Response_Formatter
	 */
	private $response_formatter;

	/**
	 * Citation tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker
	 */
	private $citation_tracker;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->conversation_manager = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Manager();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->provider_factory = new WP_AI_Chatbot_LeadGen_Pro_Provider_Factory();
		$this->hybrid_search = new WP_AI_Chatbot_LeadGen_Pro_Hybrid_Search();
		$this->reranker = new WP_AI_Chatbot_LeadGen_Pro_Cross_Encoder_Reranker();
		$this->context_assembler = new WP_AI_Chatbot_LeadGen_Pro_Context_Assembler();
		$this->response_formatter = new WP_AI_Chatbot_LeadGen_Pro_Response_Formatter();
		$this->citation_tracker = new WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker();
	}

	/**
	 * Process incoming user message.
	 *
	 * @since 1.0.0
	 * @param string $message_text     User message text.
	 * @param int    $conversation_id  Optional. Conversation ID. If not provided, will get or create.
	 * @param array  $args             Optional. Additional arguments.
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	public function process_message( $message_text, $conversation_id = null, $args = array() ) {
		// Validate and sanitize input
		$message_text = $this->sanitize_message( $message_text );
		if ( empty( $message_text ) ) {
			return new WP_Error(
				'empty_message',
				__( 'Message cannot be empty.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Get or create conversation
		if ( empty( $conversation_id ) ) {
			$conversation_id = $this->conversation_manager->get_or_create_conversation( $args );
			if ( is_wp_error( $conversation_id ) ) {
				return $conversation_id;
			}
		}

		// Store user message
		$user_message_id = $this->conversation_manager->add_message(
			$conversation_id,
			'user',
			$message_text
		);

		if ( is_wp_error( $user_message_id ) ) {
			return $user_message_id;
		}

		// Get conversation history for context
		$conversation_history = $this->get_conversation_history( $conversation_id );

		// Process message and generate response
		$response_data = $this->generate_response( $message_text, $conversation_history, $args );

		if ( is_wp_error( $response_data ) ) {
			// Store error message
			$this->conversation_manager->add_message(
				$conversation_id,
				'assistant',
				__( 'I apologize, but I encountered an error processing your message. Please try again.', 'wp-ai-chatbot-leadgen-pro' ),
				array( 'is_error' => true )
			);
			return $response_data;
		}

		// Store assistant response
		$assistant_message_id = $this->conversation_manager->add_message(
			$conversation_id,
			'assistant',
			$response_data['response'],
			array(
				'token_count'      => $response_data['token_count'] ?? null,
				'similarity_score' => $response_data['similarity_score'] ?? null,
				'citations'        => $response_data['citations'] ?? null,
				'metadata'         => $response_data['metadata'] ?? null,
			)
		);

		if ( is_wp_error( $assistant_message_id ) ) {
			$this->logger->warning(
				'Failed to store assistant message',
				array(
					'conversation_id' => $conversation_id,
					'error'           => $assistant_message_id->get_error_message(),
				)
			);
		} else {
			// Record citations
			if ( ! empty( $response_data['citations'] ) ) {
				$this->citation_tracker->record_citations( $assistant_message_id, $response_data['citations'] );
			}
		}

		// Format response for display
		$formatted_response = $this->response_formatter->format_response(
			$response_data['response'],
			$response_data['citations'] ?? array(),
			array( 'format' => 'html' )
		);

		return array(
			'conversation_id' => $conversation_id,
			'message_id'      => $assistant_message_id,
			'response'        => $formatted_response,
			'raw_response'    => $response_data['response'],
			'citations'       => $response_data['citations'] ?? array(),
			'token_count'     => $response_data['token_count'] ?? 0,
			'similarity_score' => $response_data['similarity_score'] ?? null,
			'show_lead_capture' => $this->should_show_lead_capture( $conversation_id ),
		);
	}

	/**
	 * Generate AI response using RAG system.
	 *
	 * @since 1.0.0
	 * @param string $message_text         User message.
	 * @param array  $conversation_history Conversation history.
	 * @param array  $args                 Optional. Additional arguments.
	 * @return array|WP_Error Response data or WP_Error on failure.
	 */
	private function generate_response( $message_text, $conversation_history = array(), $args = array() ) {
		// Get AI provider
		$provider = $this->provider_factory->get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		// Search for relevant content using hybrid search
		$search_results = $this->hybrid_search->search( $message_text, array(
			'limit' => 10,
		) );

		if ( is_wp_error( $search_results ) ) {
			$this->logger->warning(
				'Hybrid search failed',
				array( 'error' => $search_results->get_error_message() )
			);
			$search_results = array();
		}

		// Re-rank results for better relevance
		if ( ! empty( $search_results ) ) {
			$search_results = $this->reranker->rerank( $message_text, $search_results, array(
				'limit' => 5,
			) );

			if ( is_wp_error( $search_results ) ) {
				$this->logger->warning(
					'Reranking failed',
					array( 'error' => $search_results->get_error_message() )
				);
			}
		}

		// Assemble context from retrieved chunks
		$context_data = $this->context_assembler->assemble_context(
			$message_text,
			$search_results,
			$conversation_history,
			$this->config->get( 'default_model', 'gpt-4-turbo-preview' ),
			array(
				'max_chunks' => 5,
				'reserve_response_tokens' => $this->config->get( 'max_tokens', 1000 ),
			)
		);

		if ( is_wp_error( $context_data ) ) {
			$this->logger->warning(
				'Context assembly failed',
				array( 'error' => $context_data->get_error_message() )
			);
			$context_data = array(
				'context_text'   => '',
				'chunk_metadata' => array(),
				'tokens_used'    => 0,
			);
		}

		// Build conversation context
		$conversation_context = $this->build_conversation_context( $conversation_history );

		// Build system prompt
		$system_prompt = $this->build_system_prompt( $context_data['context_text'] ?? '' );

		// Build messages array for chat completion
		$messages = $this->build_messages_array( $system_prompt, $conversation_context, $message_text );

		// Get model
		$model = $this->config->get( 'default_model', 'gpt-4-turbo-preview' );

		// Generate response
		$response = $provider->chat_completion( $messages, array(
			'model'       => $model,
			'temperature' => $this->config->get( 'temperature', 0.7 ),
			'max_tokens'  => $this->config->get( 'max_tokens', 1000 ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Extract response text
		$response_text = $this->extract_response_text( $response );

		// Calculate average similarity score
		$similarity_score = $this->calculate_average_similarity( $search_results );

		// Extract citations from chunks
		$citations = $this->extract_citations( $context_data['chunk_metadata'] ?? array() );

		// Estimate token count
		$token_count = $this->estimate_tokens( $response_text );

		return array(
			'response'        => $response_text,
			'citations'       => $citations,
			'token_count'     => $token_count,
			'similarity_score' => $similarity_score,
			'metadata'        => array(
				'model'           => $model,
				'chunks_used'     => $context_data['chunks_used'] ?? 0,
				'context_tokens'  => $context_data['tokens_used'] ?? 0,
			),
		);
	}

	/**
	 * Get conversation history.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $limit           Optional. Number of messages to retrieve.
	 * @return array Conversation history.
	 */
	private function get_conversation_history( $conversation_id, $limit = 20 ) {
		$messages = $this->conversation_manager->get_messages( $conversation_id, array(
			'limit' => $limit,
			'order' => 'DESC',
		) );

		// Reverse to get chronological order
		return array_reverse( $messages );
	}

	/**
	 * Build conversation context from history.
	 *
	 * @since 1.0.0
	 * @param array $history Conversation history.
	 * @return array Messages array for chat completion.
	 */
	private function build_conversation_context( $history ) {
		$context = array();

		// Only include user and assistant messages (exclude system)
		foreach ( $history as $message ) {
			if ( in_array( $message->role, array( 'user', 'assistant' ), true ) ) {
				$context[] = array(
					'role'    => $message->role,
					'content' => $message->message_text,
				);
			}
		}

		return $context;
	}

	/**
	 * Build system prompt with context.
	 *
	 * @since 1.0.0
	 * @param string $context Retrieved context from knowledge base.
	 * @return string System prompt.
	 */
	private function build_system_prompt( $context ) {
		$base_prompt = $this->config->get( 'system_prompt', $this->get_default_system_prompt() );

		if ( ! empty( $context ) ) {
			$context_prompt = "\n\nUse the following information from our knowledge base to answer questions accurately:\n\n" . $context;
			$context_prompt .= "\n\nWhen using information from the knowledge base, cite your sources using [1], [2], etc. format.";
		} else {
			$context_prompt = "\n\nIf you don't have specific information in the knowledge base, provide a helpful general response.";
		}

		return $base_prompt . $context_prompt;
	}

	/**
	 * Get default system prompt.
	 *
	 * @since 1.0.0
	 * @return string Default system prompt.
	 */
	private function get_default_system_prompt() {
		return __( 'You are a helpful AI assistant for our website. Answer questions accurately and helpfully based on the provided context. Be concise, friendly, and professional.', 'wp-ai-chatbot-leadgen-pro' );
	}

	/**
	 * Build messages array for chat completion.
	 *
	 * @since 1.0.0
	 * @param string $system_prompt        System prompt.
	 * @param array  $conversation_context Conversation context.
	 * @param string $user_message         Current user message.
	 * @return array Messages array.
	 */
	private function build_messages_array( $system_prompt, $conversation_context, $user_message ) {
		$messages = array();

		// Add system message
		if ( ! empty( $system_prompt ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $system_prompt,
			);
		}

		// Add conversation history
		foreach ( $conversation_context as $msg ) {
			$messages[] = $msg;
		}

		// Add current user message
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_message,
		);

		return $messages;
	}

	/**
	 * Extract response text from provider response.
	 *
	 * @since 1.0.0
	 * @param array $response Provider response.
	 * @return string Response text.
	 */
	private function extract_response_text( $response ) {
		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			return $response['choices'][0]['message']['content'];
		}

		if ( isset( $response['content'] ) ) {
			return $response['content'];
		}

		return '';
	}

	/**
	 * Extract citations from retrieved chunks.
	 *
	 * @since 1.0.0
	 * @param array $chunk_metadata Retrieved chunk metadata.
	 * @return array Citations array.
	 */
	private function extract_citations( $chunk_metadata ) {
		$citations = array();

		if ( empty( $chunk_metadata ) || ! is_array( $chunk_metadata ) ) {
			return $citations;
		}

		foreach ( $chunk_metadata as $index => $chunk ) {
			$citation = array(
				'chunk_id'    => isset( $chunk['chunk_id'] ) ? intval( $chunk['chunk_id'] ) : null,
				'source_url'  => isset( $chunk['source_url'] ) ? $chunk['source_url'] : '',
				'source_type' => isset( $chunk['source_type'] ) ? $chunk['source_type'] : 'unknown',
				'similarity'  => isset( $chunk['similarity'] ) ? floatval( $chunk['similarity'] ) : ( isset( $chunk['score'] ) ? floatval( $chunk['score'] ) : 0 ),
				'index'       => $index + 1,
			);

			// Enrich citation metadata
			$citation = $this->citation_tracker->enrich_citation_metadata( $citation );

			$citations[] = $citation;
		}

		return $citations;
	}

	/**
	 * Calculate average similarity score.
	 *
	 * @since 1.0.0
	 * @param array $search_results Search results.
	 * @return float Average similarity score.
	 */
	private function calculate_average_similarity( $search_results ) {
		if ( empty( $search_results ) ) {
			return 0.0;
		}

		$total = 0;
		$count = 0;

		foreach ( $search_results as $result ) {
			if ( isset( $result['similarity'] ) ) {
				$total += floatval( $result['similarity'] );
				$count++;
			}
		}

		return $count > 0 ? round( $total / $count, 4 ) : 0.0;
	}

	/**
	 * Get maximum context tokens.
	 *
	 * @since 1.0.0
	 * @return int Maximum context tokens.
	 */
	private function get_max_context_tokens() {
		$model = $this->config->get( 'default_model', 'gpt-4-turbo-preview' );
		$max_tokens = $this->config->get( 'max_tokens', 1000 );

		// Estimate model context window (conservative estimates)
		$context_windows = array(
			'gpt-4-turbo-preview' => 128000,
			'gpt-4'               => 8192,
			'gpt-3.5-turbo'       => 16385,
			'claude-3-opus'       => 200000,
			'claude-3-sonnet'     => 200000,
			'claude-3-haiku'      => 200000,
			'gemini-pro'          => 32768,
		);

		$context_window = $context_windows[ $model ] ?? 8192;

		// Reserve tokens for response and system prompt
		$reserved_tokens = $max_tokens + 500;

		return max( 1000, $context_window - $reserved_tokens );
	}

	/**
	 * Estimate token count.
	 *
	 * @since 1.0.0
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	private function estimate_tokens( $text ) {
		// Simple approximation: ~4 characters per token
		return intval( ceil( strlen( $text ) / 4 ) );
	}

	/**
	 * Sanitize message text.
	 *
	 * @since 1.0.0
	 * @param string $message Message text.
	 * @return string Sanitized message.
	 */
	private function sanitize_message( $message ) {
		$message = wp_strip_all_tags( $message );
		$message = trim( $message );
		$message = substr( $message, 0, 2000 ); // Max length

		return $message;
	}

	/**
	 * Check if lead capture should be shown.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return bool True if lead capture should be shown.
	 */
	private function should_show_lead_capture( $conversation_id ) {
		$stats = $this->conversation_manager->get_conversation_stats( $conversation_id );
		$user_message_count = $stats['user_messages'] ?? 0;

		$threshold = $this->config->get( 'lead_capture_after_messages', 3 );

		return $user_message_count >= $threshold;
	}
}

