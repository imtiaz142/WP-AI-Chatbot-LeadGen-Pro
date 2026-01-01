/**
 * Content Ingestion Admin JavaScript.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/assets/js
 * @since      1.0.0
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// Start full indexing
		$('#start-full-indexing').on('click', function() {
			if (!confirm('Are you sure you want to start full indexing? This may take a while.')) {
				return;
			}

			var $button = $(this);
			$button.prop('disabled', true).text('Starting...');

			$.ajax({
				url: wpAiChatbotIngestion.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_start_indexing',
					nonce: wpAiChatbotIngestion.nonce
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
					} else {
						alert('Error: ' + response.data.message);
					}
				},
				error: function() {
					alert('An error occurred. Please try again.');
				},
				complete: function() {
					$button.prop('disabled', false).text('Start Full Indexing');
				}
			});
		});

		// Re-index stale content
		$('#reindex-stale').on('click', function() {
			if (!confirm('Re-index all stale content?')) {
				return;
			}

			var $button = $(this);
			$button.prop('disabled', true).text('Re-indexing...');

			$.ajax({
				url: wpAiChatbotIngestion.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_reindex_stale',
					nonce: wpAiChatbotIngestion.nonce
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message || 'Stale content re-indexing started.');
						location.reload();
					} else {
						alert('Error: ' + (response.data.message || 'Failed to start re-indexing.'));
					}
				},
				error: function() {
					alert('An error occurred. Please try again.');
				},
				complete: function() {
					$button.prop('disabled', false).text('Re-index Stale Content');
				}
			});
		});

		// Refresh statistics
		$('#refresh-stats').on('click', function() {
			location.reload();
		});

		// Auto-refresh status every 30 seconds
		setInterval(function() {
			$.ajax({
				url: wpAiChatbotIngestion.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_get_indexing_status',
					nonce: wpAiChatbotIngestion.nonce
				},
				success: function(response) {
					if (response.success) {
						// Update status displays
						// TODO: Update UI with new status data
					}
				}
			});
		}, 30000);
	});
})(jQuery);

