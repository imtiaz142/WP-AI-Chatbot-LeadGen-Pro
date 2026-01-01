<?php
/**
 * AI Provider Interface.
 *
 * Defines the contract that all AI provider implementations must follow.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/providers
 * @since      1.0.0
 */
interface WP_AI_Chatbot_LeadGen_Pro_Provider_Interface {

	/**
	 * Get provider name.
	 *
	 * @since 1.0.0
	 * @return string Provider name.
	 */
	public function get_provider_name();

	/**
	 * Get available models for this provider.
	 *
	 * @since 1.0.0
	 * @return array Array of model identifiers.
	 */
	public function get_available_models();

	/**
	 * Check if a model is available.
	 *
	 * @since 1.0.0
	 * @param string $model Model identifier.
	 * @return bool True if model is available, false otherwise.
	 */
	public function is_model_available( $model );

	/**
	 * Generate a chat completion.
	 *
	 * @since 1.0.0
	 * @param array $messages Array of message objects with 'role' and 'content'.
	 * @param array $args     Optional. Additional arguments (model, temperature, max_tokens, etc.).
	 * @return array|WP_Error Response array with 'content', 'model', 'usage', or WP_Error on failure.
	 */
	public function chat_completion( $messages, $args = array() );

	/**
	 * Generate embeddings for text.
	 *
	 * @since 1.0.0
	 * @param string|array $text Text or array of texts to generate embeddings for.
	 * @param string       $model Optional. Embedding model to use.
	 * @return array|WP_Error Array of embeddings or WP_Error on failure.
	 */
	public function generate_embeddings( $text, $model = '' );

	/**
	 * Get model information.
	 *
	 * @since 1.0.0
	 * @param string $model Model identifier.
	 * @return array|WP_Error Model information array or WP_Error if model not found.
	 */
	public function get_model_info( $model );

	/**
	 * Get estimated cost for a request.
	 *
	 * @since 1.0.0
	 * @param string $model   Model identifier.
	 * @param int    $tokens  Number of tokens (input + output).
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( $model, $tokens );

	/**
	 * Get maximum tokens for a model.
	 *
	 * @since 1.0.0
	 * @param string $model Model identifier.
	 * @return int Maximum tokens or 0 if unknown.
	 */
	public function get_max_tokens( $model );

	/**
	 * Check if provider is configured and ready to use.
	 *
	 * @since 1.0.0
	 * @return bool True if configured, false otherwise.
	 */
	public function is_configured();

	/**
	 * Test provider connection and API key.
	 *
	 * @since 1.0.0
	 * @return bool|WP_Error True if connection successful, WP_Error on failure.
	 */
	public function test_connection();

	/**
	 * Get provider configuration status.
	 *
	 * @since 1.0.0
	 * @return array Configuration status array with 'configured', 'api_key_set', etc.
	 */
	public function get_config_status();
}

