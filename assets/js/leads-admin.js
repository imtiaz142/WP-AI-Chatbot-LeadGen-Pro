/**
 * Leads Admin JavaScript
 *
 * Handles interactive functionality for the leads management interface.
 *
 * @package WP_AI_Chatbot_LeadGen_Pro
 * @since 1.0.0
 */

(function($) {
	'use strict';

	/**
	 * Leads Admin Controller
	 */
	const LeadsAdmin = {
		/**
		 * Configuration
		 */
		config: {
			ajaxUrl: wpAiChatbotLeadsAdmin?.ajaxUrl || ajaxurl,
			nonce: wpAiChatbotLeadsAdmin?.nonce || '',
			strings: wpAiChatbotLeadsAdmin?.strings || {}
		},

		/**
		 * Initialize
		 */
		init: function() {
			this.bindEvents();
			this.initAdvancedFilters();
			this.initBulkActions();
			this.initLeadActions();
		},

		/**
		 * Bind events
		 */
		bindEvents: function() {
			// Advanced filters toggle
			$(document).on('click', '#toggle-advanced-filters', this.toggleAdvancedFilters.bind(this));

			// Confirm delete
			$(document).on('click', '.submitdelete', this.confirmDelete.bind(this));

			// Enrich lead button
			$(document).on('click', '#enrich-lead', this.enrichLead.bind(this));

			// Rescore lead button
			$(document).on('click', '#rescore-lead', this.rescoreLead.bind(this));

			// Bulk action confirmation
			$(document).on('submit', '#leads-filter', this.confirmBulkAction.bind(this));

			// Score filter range validation
			$(document).on('change', 'input[name="score_min"], input[name="score_max"]', this.validateScoreRange.bind(this));
		},

		/**
		 * Toggle advanced filters
		 */
		toggleAdvancedFilters: function(e) {
			e.preventDefault();
			
			const $filters = $('.advanced-filters');
			const $button = $('#toggle-advanced-filters');

			$filters.slideToggle(200, function() {
				const isVisible = $filters.is(':visible');
				$button.text(isVisible 
					? wpAiChatbotLeadsAdmin?.strings?.hideAdvanced || 'Hide Advanced'
					: wpAiChatbotLeadsAdmin?.strings?.showAdvanced || 'Advanced'
				);
			});
		},

		/**
		 * Initialize advanced filters
		 */
		initAdvancedFilters: function() {
			// Show advanced filters if any are active
			const urlParams = new URLSearchParams(window.location.search);
			const advancedParams = ['date_from', 'date_to', 'score_min', 'score_max'];
			
			const hasAdvanced = advancedParams.some(param => urlParams.has(param) && urlParams.get(param));
			
			if (hasAdvanced) {
				$('.advanced-filters').show();
				$('#toggle-advanced-filters').text(
					wpAiChatbotLeadsAdmin?.strings?.hideAdvanced || 'Hide Advanced'
				);
			}
		},

		/**
		 * Validate score range
		 */
		validateScoreRange: function() {
			const min = parseInt($('input[name="score_min"]').val()) || 0;
			const max = parseInt($('input[name="score_max"]').val()) || 100;

			if (min > max) {
				$('input[name="score_min"]').val(max);
			}
		},

		/**
		 * Initialize bulk actions
		 */
		initBulkActions: function() {
			// Select all checkbox
			$(document).on('click', '#cb-select-all-1, #cb-select-all-2', function() {
				const checked = $(this).prop('checked');
				$('input[name="lead[]"]').prop('checked', checked);
				LeadsAdmin.updateBulkActionsState();
			});

			// Individual checkboxes
			$(document).on('change', 'input[name="lead[]"]', this.updateBulkActionsState.bind(this));
		},

		/**
		 * Update bulk actions state
		 */
		updateBulkActionsState: function() {
			const checkedCount = $('input[name="lead[]"]:checked').length;
			const $bulkButtons = $('select[name="action"], select[name="action2"]');

			// Optionally disable bulk actions if nothing selected
			// $bulkButtons.prop('disabled', checkedCount === 0);
		},

		/**
		 * Confirm bulk action
		 */
		confirmBulkAction: function(e) {
			const action = $('select[name="action"]').val() || $('select[name="action2"]').val();
			const checkedCount = $('input[name="lead[]"]:checked').length;

			if (action === 'delete' && checkedCount > 0) {
				if (!confirm(this.config.strings.confirmBulkDelete || 'Are you sure you want to delete the selected leads?')) {
					e.preventDefault();
					return false;
				}
			}

			return true;
		},

		/**
		 * Confirm single delete
		 */
		confirmDelete: function(e) {
			if (!confirm(this.config.strings.confirmDelete || 'Are you sure you want to delete this lead?')) {
				e.preventDefault();
				return false;
			}
			return true;
		},

		/**
		 * Initialize lead action buttons
		 */
		initLeadActions: function() {
			// Nothing specific to initialize
		},

		/**
		 * Enrich lead data
		 */
		enrichLead: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const leadId = $button.data('lead-id');

			if (!leadId) return;

			$button.prop('disabled', true).addClass('updating-message');

			$.ajax({
				url: this.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_enqueue_enrichment',
					lead_id: leadId,
					priority: 'high',
					force: true
				},
				success: function(response) {
					if (response.success) {
						LeadsAdmin.showNotice('success', response.data.message || 'Lead added to enrichment queue');
					} else {
						LeadsAdmin.showNotice('error', response.data?.message || 'Failed to enqueue enrichment');
					}
				},
				error: function() {
					LeadsAdmin.showNotice('error', LeadsAdmin.config.strings.error || 'An error occurred');
				},
				complete: function() {
					$button.prop('disabled', false).removeClass('updating-message');
				}
			});
		},

		/**
		 * Rescore lead
		 */
		rescoreLead: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const leadId = $button.data('lead-id');

			if (!leadId) return;

			$button.prop('disabled', true).addClass('updating-message');

			$.ajax({
				url: this.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_rescore_lead',
					lead_id: leadId,
					nonce: this.config.nonce
				},
				success: function(response) {
					if (response.success) {
						LeadsAdmin.showNotice('success', 'Lead score recalculated');
						// Reload to show new score
						setTimeout(function() {
							window.location.reload();
						}, 1000);
					} else {
						LeadsAdmin.showNotice('error', response.data?.message || 'Failed to rescore lead');
					}
				},
				error: function() {
					LeadsAdmin.showNotice('error', LeadsAdmin.config.strings.error || 'An error occurred');
				},
				complete: function() {
					$button.prop('disabled', false).removeClass('updating-message');
				}
			});
		},

		/**
		 * Show admin notice
		 */
		showNotice: function(type, message) {
			const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
			
			// Remove existing notices
			$('.wrap > .notice').remove();

			// Insert after h1
			$('.wrap h1').first().after($notice);

			// Make dismissible
			this.makeDismissible($notice);

			// Auto-hide success notices
			if (type === 'success') {
				setTimeout(function() {
					$notice.fadeOut(300, function() {
						$(this).remove();
					});
				}, 5000);
			}
		},

		/**
		 * Make notice dismissible
		 */
		makeDismissible: function($notice) {
			const $button = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
			
			$notice.append($button);
			
			$button.on('click', function() {
				$notice.fadeOut(200, function() {
					$(this).remove();
				});
			});
		}
	};

	/**
	 * Lead Score Animation
	 */
	const ScoreAnimation = {
		init: function() {
			this.animateScoreBars();
			this.animateScoreCircle();
		},

		animateScoreBars: function() {
			$('.score-bar span').each(function() {
				const $bar = $(this);
				const width = $bar.css('width');
				
				$bar.css('width', '0').animate({
					width: width
				}, 800, 'swing');
			});
		},

		animateScoreCircle: function() {
			const $value = $('.score-circle .score-value');
			if (!$value.length) return;

			const targetValue = parseInt($value.text()) || 0;
			
			$({ value: 0 }).animate({ value: targetValue }, {
				duration: 1000,
				easing: 'swing',
				step: function(now) {
					$value.text(Math.round(now));
				}
			});
		}
	};

	/**
	 * Keyboard Navigation
	 */
	const KeyboardNav = {
		init: function() {
			$(document).on('keydown', this.handleKeydown.bind(this));
		},

		handleKeydown: function(e) {
			// Escape to close modals/notices
			if (e.key === 'Escape') {
				$('.notice.is-dismissible .notice-dismiss').trigger('click');
			}

			// Ctrl+S to save form
			if (e.key === 's' && (e.ctrlKey || e.metaKey)) {
				const $form = $('form.leads-form');
				if ($form.length) {
					e.preventDefault();
					$form.submit();
				}
			}
		}
	};

	/**
	 * Quick Actions
	 */
	const QuickActions = {
		init: function() {
			this.initStatusChange();
		},

		initStatusChange: function() {
			// Quick status change on list table
			$(document).on('click', '.quick-status-change', function(e) {
				e.preventDefault();
				
				const $link = $(this);
				const leadId = $link.data('lead-id');
				const newStatus = $link.data('status');

				if (!leadId || !newStatus) return;

				$.ajax({
					url: LeadsAdmin.config.ajaxUrl,
					type: 'POST',
					data: {
						action: 'wp_ai_chatbot_update_lead_status',
						lead_id: leadId,
						status: newStatus,
						nonce: LeadsAdmin.config.nonce
					},
					success: function(response) {
						if (response.success) {
							// Update status badge
							const $row = $link.closest('tr');
							const $statusCell = $row.find('.column-status .lead-status');
							
							$statusCell
								.removeClass()
								.addClass('lead-status status-' + newStatus)
								.text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
						}
					}
				});
			});
		}
	};

	/**
	 * Real-time Updates
	 */
	const RealtimeUpdates = {
		pollInterval: null,

		init: function() {
			// Poll for updates every 30 seconds on list view
			if ($('.wp-list-table.leads').length) {
				this.startPolling();
			}
		},

		startPolling: function() {
			this.pollInterval = setInterval(this.checkUpdates.bind(this), 30000);
		},

		stopPolling: function() {
			if (this.pollInterval) {
				clearInterval(this.pollInterval);
			}
		},

		checkUpdates: function() {
			// Get visible lead IDs
			const leadIds = [];
			$('input[name="lead[]"]').each(function() {
				leadIds.push($(this).val());
			});

			if (!leadIds.length) return;

			$.ajax({
				url: LeadsAdmin.config.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_check_lead_updates',
					lead_ids: leadIds,
					nonce: LeadsAdmin.config.nonce
				},
				success: function(response) {
					if (response.success && response.data?.updates) {
						RealtimeUpdates.applyUpdates(response.data.updates);
					}
				}
			});
		},

		applyUpdates: function(updates) {
			$.each(updates, function(leadId, data) {
				const $row = $('input[name="lead[]"][value="' + leadId + '"]').closest('tr');
				
				if (data.score !== undefined) {
					const $score = $row.find('.column-score .lead-score');
					if ($score.text() !== String(data.score)) {
						$score.text(data.score).addClass('score-updated');
						setTimeout(function() {
							$score.removeClass('score-updated');
						}, 2000);
					}
				}

				if (data.grade !== undefined) {
					const $grade = $row.find('.column-grade .lead-grade');
					$grade.text(data.grade)
						.removeClass()
						.addClass('lead-grade grade-' + data.grade.toLowerCase().replace('+', '-plus'));
				}
			});
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		LeadsAdmin.init();
		ScoreAnimation.init();
		KeyboardNav.init();
		QuickActions.init();
		// RealtimeUpdates.init(); // Uncomment to enable polling
	});

	// Expose for external use
	window.WPAIChatbotLeadsAdmin = LeadsAdmin;

})(jQuery);






