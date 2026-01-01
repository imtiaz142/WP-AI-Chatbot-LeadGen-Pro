/**
 * Content Manager Admin JavaScript.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/assets/js
 * @since      1.0.0
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// Select all checkbox
		$('#select-all-checkbox').on('change', function() {
			$('.page-checkbox').prop('checked', $(this).prop('checked'));
		});

		// Select all button
		$('#select-all-pages').on('click', function() {
			$('.page-checkbox').prop('checked', true);
			$('#select-all-checkbox').prop('checked', true);
		});

		// Deselect all button
		$('#deselect-all-pages').on('click', function() {
			$('.page-checkbox').prop('checked', false);
			$('#select-all-checkbox').prop('checked', false);
		});

		// Update select all checkbox when individual checkboxes change
		$(document).on('change', '.page-checkbox', function() {
			var total = $('.page-checkbox').length;
			var checked = $('.page-checkbox:checked').length;
			$('#select-all-checkbox').prop('checked', total === checked);
		});

		// Bulk re-index
		$('#bulk-reindex-pages').on('click', function() {
			var selected = $('.page-checkbox:checked');
			if (selected.length === 0) {
				alert('Please select at least one page to re-index.');
				return;
			}

			if (!confirm('Are you sure you want to re-index ' + selected.length + ' selected page(s)?')) {
				return;
			}

			var $button = $(this);
			$button.prop('disabled', true).text('Re-indexing...');

			var urls = [];
			selected.each(function() {
				urls.push($(this).val());
			});

			$.ajax({
				url: wpAiChatbotContentManager.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_bulk_reindex',
					nonce: wpAiChatbotContentManager.nonce,
					urls: urls
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message || 'Re-indexing started successfully.');
						location.reload();
					} else {
						alert('Error: ' + (response.data.message || 'Failed to start re-indexing.'));
					}
				},
				error: function() {
					alert('An error occurred. Please try again.');
				},
				complete: function() {
					$button.prop('disabled', false).text('Re-index Selected');
				}
			});
		});

		// Re-index stale content
		$('#refresh-stale-pages').on('click', function() {
			if (!confirm('Re-index all stale content? This may take a while.')) {
				return;
			}

			var $button = $(this);
			$button.prop('disabled', true).text('Re-indexing...');

			// Select all stale pages
			$('.stale-content .page-checkbox').prop('checked', true);
			$('#select-all-checkbox').prop('checked', false);

			// Trigger bulk re-index
			$('#bulk-reindex-pages').trigger('click');
		});

		// Individual re-index button (if using AJAX instead of form submission)
		$(document).on('click', '.reindex-single', function(e) {
			e.preventDefault();
			var url = $(this).data('url');
			var $button = $(this);

			if (!confirm('Re-index this page?')) {
				return;
			}

			$button.prop('disabled', true).text('Re-indexing...');

			$.ajax({
				url: wpAiChatbotContentManager.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_reindex_url',
					nonce: wpAiChatbotContentManager.nonce,
					url: url
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						location.reload();
					} else {
						alert('Error: ' + (response.data.message || 'Failed to re-index.'));
					}
				},
				error: function() {
					alert('An error occurred. Please try again.');
				},
				complete: function() {
					$button.prop('disabled', false).text('Re-index');
				}
			});
		});
	});
})(jQuery);

