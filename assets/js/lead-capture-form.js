/**
 * Lead Capture Form JavaScript
 *
 * Handles lead capture form display, submission, and trigger logic.
 *
 * @package WP_AI_Chatbot_LeadGen_Pro
 * @since   1.0.0
 */

(function($) {
	'use strict';

	/**
	 * LeadCaptureForm class
	 */
	class LeadCaptureForm {
		/**
		 * Constructor
		 * @param {Object} options Configuration options
		 */
		constructor(options = {}) {
			this.options = {
				ajaxUrl: window.wpAIChatbot?.ajaxUrl || '/wp-admin/admin-ajax.php',
				nonce: window.wpAIChatbot?.nonce || '',
				triggerSettings: options.triggerSettings || {},
				...options
			};

			this.state = {
				isVisible: false,
				isSubmitting: false,
				hasSubmitted: this.loadSubmissionState(),
				hasDismissed: this.loadDismissalState(),
				messageCount: 0,
				currentIntent: '',
				conversationId: 0,
				sessionId: ''
			};

			this.container = null;
			this.form = null;
			this.triggerListeners = [];

			this.init();
		}

		/**
		 * Initialize the lead form
		 */
		init() {
			this.bindTriggerEvents();
			this.bindFormEvents();
		}

		/**
		 * Bind trigger-based events
		 */
		bindTriggerEvents() {
			const trigger = this.options.triggerSettings?.trigger;

			if (!trigger || trigger === 'manual') {
				return;
			}

			// Exit intent trigger
			if (trigger === 'exit_intent') {
				this.bindExitIntentTrigger();
			}

			// Message count is handled by the chat widget
			// High intent is handled when intent changes
		}

		/**
		 * Bind exit intent trigger
		 */
		bindExitIntentTrigger() {
			const handler = (e) => {
				if (e.clientY <= 10 && !this.state.hasSubmitted && !this.state.hasDismissed) {
					this.show();
				}
			};

			document.addEventListener('mouseleave', handler);
			this.triggerListeners.push({ type: 'mouseleave', handler, target: document });
		}

		/**
		 * Bind form events
		 */
		bindFormEvents() {
			$(document).on('submit', '.wp-ai-chatbot-lead-form__form', (e) => {
				e.preventDefault();
				this.handleSubmit(e.target);
			});

			$(document).on('click', '.wp-ai-chatbot-lead-form__dismiss', (e) => {
				e.preventDefault();
				this.handleDismiss();
			});

			// Real-time validation
			$(document).on('blur', '.wp-ai-chatbot-lead-form__input', (e) => {
				this.validateField(e.target);
			});

			$(document).on('input', '.wp-ai-chatbot-lead-form__input', (e) => {
				const field = $(e.target).closest('.wp-ai-chatbot-lead-form__field');
				if (field.hasClass('has-error')) {
					this.validateField(e.target);
				}
			});

			// Close modal on overlay click
			$(document).on('click', '.wp-ai-chatbot-lead-form-overlay', () => {
				this.handleDismiss();
			});

			// Close on escape
			$(document).on('keydown', (e) => {
				if (e.key === 'Escape' && this.state.isVisible) {
					this.handleDismiss();
				}
			});
		}

		/**
		 * Check if form should be shown based on current context
		 * @param {Object} context Current context
		 * @returns {boolean}
		 */
		shouldShow(context = {}) {
			if (this.state.hasSubmitted || this.state.hasDismissed || this.state.isVisible) {
				return false;
			}

			const settings = this.options.triggerSettings;
			const trigger = settings?.trigger;

			if (!trigger || !settings?.enabled) {
				return false;
			}

			switch (trigger) {
				case 'immediate':
					return true;

				case 'after_greeting':
					return context.messageCount >= 1;

				case 'after_messages':
					return context.messageCount >= (settings.triggerCount || 3);

				case 'high_intent':
					return settings.highIntentIntents?.includes(context.intent);

				case 'meeting_request':
					return context.intent === 'meeting_request';

				case 'pricing_inquiry':
					return context.intent === 'pricing';

				case 'exit_intent':
					return context.isExitIntent === true;

				default:
					return false;
			}
		}

		/**
		 * Update context and check for trigger
		 * @param {Object} context Updated context
		 */
		updateContext(context) {
			Object.assign(this.state, context);

			if (this.shouldShow(context)) {
				this.show();
			}
		}

		/**
		 * Show the lead form
		 * @param {Object} options Display options
		 */
		async show(options = {}) {
			if (this.state.isVisible || this.state.hasSubmitted) {
				return;
			}

			try {
				const response = await this.fetchFormHtml();
				if (!response.success || !response.data?.html) {
					return;
				}

				this.render(response.data.html, options);
				this.state.isVisible = true;

				// Focus first input for accessibility
				setTimeout(() => {
					const firstInput = this.container?.querySelector('.wp-ai-chatbot-lead-form__input');
					if (firstInput) {
						firstInput.focus();
					}
				}, 300);

			} catch (error) {
				console.error('LeadCaptureForm: Failed to show form', error);
			}
		}

		/**
		 * Fetch form HTML from server
		 * @returns {Promise<Object>}
		 */
		async fetchFormHtml() {
			const formData = new FormData();
			formData.append('action', 'wp_ai_chatbot_get_lead_form');
			formData.append('conversation_id', this.state.conversationId);
			formData.append('session_id', this.state.sessionId);

			const response = await fetch(this.options.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			});

			return response.json();
		}

		/**
		 * Render the form
		 * @param {string} html Form HTML
		 * @param {Object} options Display options
		 */
		render(html, options = {}) {
			const style = this.options.triggerSettings?.style || 'inline';
			const targetSelector = options.target || '.wp-ai-chatbot-messages';
			const target = $(targetSelector);

			if (style === 'modal') {
				// Create overlay
				const overlay = $('<div class="wp-ai-chatbot-lead-form-overlay"></div>');
				$('body').append(overlay);
			}

			// Create container
			this.container = document.createElement('div');
			this.container.className = 'wp-ai-chatbot-lead-form-container';
			this.container.innerHTML = html;

			if (style === 'inline' && target.length) {
				target.append(this.container);
				// Scroll to form
				target.animate({ scrollTop: target[0].scrollHeight }, 300);
			} else if (style === 'modal' || style === 'slide-up') {
				$('body').append(this.container);
			}

			this.form = this.container.querySelector('.wp-ai-chatbot-lead-form__form');

			// Trigger shown event
			$(document).trigger('wp_ai_chatbot_lead_form_shown', [this.container]);
		}

		/**
		 * Handle form submission
		 * @param {HTMLFormElement} form Form element
		 */
		async handleSubmit(form) {
			if (this.state.isSubmitting) {
				return;
			}

			// Validate all fields
			const inputs = form.querySelectorAll('.wp-ai-chatbot-lead-form__input');
			let isValid = true;

			inputs.forEach(input => {
				if (!this.validateField(input)) {
					isValid = false;
				}
			});

			// Check GDPR consent if present
			const gdprCheckbox = form.querySelector('input[name="gdpr_consent"]');
			if (gdprCheckbox && !gdprCheckbox.checked) {
				isValid = false;
				$(gdprCheckbox).closest('.wp-ai-chatbot-lead-form__field').addClass('has-error');
			}

			if (!isValid) {
				this.showError('Please correct the errors above.');
				return;
			}

			this.state.isSubmitting = true;
			this.showLoading(true);

			try {
				const formData = new FormData(form);
				formData.append('action', 'wp_ai_chatbot_submit_lead');
				formData.append('source_url', window.location.href);

				// Add UTM parameters from URL
				const urlParams = new URLSearchParams(window.location.search);
				['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(param => {
					if (urlParams.has(param)) {
						formData.append(param, urlParams.get(param));
					}
				});

				const response = await fetch(this.options.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				});

				const result = await response.json();

				if (result.success) {
					this.handleSuccess(result.data);
				} else {
					this.handleError(result.data);
				}

			} catch (error) {
				console.error('LeadCaptureForm: Submission error', error);
				this.showError('An error occurred. Please try again.');
			} finally {
				this.state.isSubmitting = false;
				this.showLoading(false);
			}
		}

		/**
		 * Handle successful submission
		 * @param {Object} data Response data
		 */
		handleSuccess(data) {
			this.state.hasSubmitted = true;
			this.saveSubmissionState();

			// Show success message
			if (this.container) {
				const form = this.container.querySelector('.wp-ai-chatbot-lead-form__form');
				const success = this.container.querySelector('.wp-ai-chatbot-lead-form__success');
				
				if (form) form.style.display = 'none';
				if (success) {
					success.textContent = data.message || 'Thank you! We\'ll be in touch soon.';
					success.hidden = false;
				}
			}

			// Trigger success event
			$(document).trigger('wp_ai_chatbot_lead_captured', [data]);

			// Auto-hide after delay
			setTimeout(() => {
				this.hide();
			}, 3000);
		}

		/**
		 * Handle submission error
		 * @param {Object} data Error data
		 */
		handleError(data) {
			// Show field-specific errors
			if (data?.errors) {
				Object.entries(data.errors).forEach(([field, message]) => {
					const input = this.form?.querySelector(`[name="${field}"]`);
					if (input) {
						const fieldContainer = input.closest('.wp-ai-chatbot-lead-form__field');
						if (fieldContainer) {
							fieldContainer.classList.add('has-error');
							const errorEl = fieldContainer.querySelector('.wp-ai-chatbot-lead-form__error');
							if (errorEl) {
								errorEl.textContent = message;
							}
						}
					}
				});
			}

			this.showError(data?.message || 'An error occurred. Please try again.');
		}

		/**
		 * Handle form dismissal
		 */
		handleDismiss() {
			this.state.hasDismissed = true;
			this.saveDismissalState();
			this.hide();

			// Send dismissal to server
			const formData = new FormData();
			formData.append('action', 'wp_ai_chatbot_dismiss_lead_form');
			formData.append('session_id', this.state.sessionId);
			formData.append('conversation_id', this.state.conversationId);

			fetch(this.options.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			}).catch(() => {});

			// Trigger dismissed event
			$(document).trigger('wp_ai_chatbot_lead_form_dismissed');
		}

		/**
		 * Validate a field
		 * @param {HTMLElement} input Input element
		 * @returns {boolean}
		 */
		validateField(input) {
			const field = $(input).closest('.wp-ai-chatbot-lead-form__field');
			const errorEl = field.find('.wp-ai-chatbot-lead-form__error');
			const value = input.value.trim();
			const isRequired = input.hasAttribute('required');
			const type = input.type;

			let error = '';

			// Required check
			if (isRequired && !value) {
				error = 'This field is required.';
			}
			// Email validation
			else if (type === 'email' && value) {
				const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
				if (!emailRegex.test(value)) {
					error = 'Please enter a valid email address.';
				}
			}
			// Phone validation
			else if (type === 'tel' && value) {
				const digits = value.replace(/\D/g, '');
				if (digits.length < 7 || digits.length > 15) {
					error = 'Please enter a valid phone number.';
				}
			}

			if (error) {
				field.addClass('has-error');
				errorEl.text(error);
				return false;
			} else {
				field.removeClass('has-error');
				errorEl.text('');
				return true;
			}
		}

		/**
		 * Show loading state
		 * @param {boolean} show Whether to show loading
		 */
		showLoading(show) {
			if (!this.container) return;

			const loading = this.container.querySelector('.wp-ai-chatbot-lead-form__loading');
			const submit = this.container.querySelector('.wp-ai-chatbot-lead-form__submit');

			if (loading) loading.hidden = !show;
			if (submit) submit.disabled = show;
		}

		/**
		 * Show error message
		 * @param {string} message Error message
		 */
		showError(message) {
			if (!this.container) return;

			const errorEl = this.container.querySelector('.wp-ai-chatbot-lead-form__error-message');
			if (errorEl) {
				errorEl.textContent = message;
				errorEl.hidden = false;
			}
		}

		/**
		 * Hide the form
		 */
		hide() {
			if (!this.state.isVisible) return;

			// Remove overlay
			$('.wp-ai-chatbot-lead-form-overlay').fadeOut(200, function() {
				$(this).remove();
			});

			// Remove form
			if (this.container) {
				$(this.container).fadeOut(200, () => {
					$(this.container).remove();
					this.container = null;
					this.form = null;
				});
			}

			this.state.isVisible = false;

			// Trigger hidden event
			$(document).trigger('wp_ai_chatbot_lead_form_hidden');
		}

		/**
		 * Save submission state to localStorage
		 */
		saveSubmissionState() {
			try {
				localStorage.setItem('wp_ai_chatbot_lead_submitted', 'true');
				localStorage.setItem('wp_ai_chatbot_lead_submitted_at', Date.now().toString());
			} catch (e) {
				// localStorage not available
			}
		}

		/**
		 * Load submission state from localStorage
		 * @returns {boolean}
		 */
		loadSubmissionState() {
			try {
				const submitted = localStorage.getItem('wp_ai_chatbot_lead_submitted');
				const submittedAt = localStorage.getItem('wp_ai_chatbot_lead_submitted_at');

				if (submitted && submittedAt) {
					// Check if submission was within last 24 hours
					const dayInMs = 24 * 60 * 60 * 1000;
					if (Date.now() - parseInt(submittedAt) < dayInMs) {
						return true;
					}
				}
			} catch (e) {
				// localStorage not available
			}
			return false;
		}

		/**
		 * Save dismissal state to localStorage
		 */
		saveDismissalState() {
			try {
				localStorage.setItem('wp_ai_chatbot_lead_dismissed', 'true');
				localStorage.setItem('wp_ai_chatbot_lead_dismissed_at', Date.now().toString());
			} catch (e) {
				// localStorage not available
			}
		}

		/**
		 * Load dismissal state from localStorage
		 * @returns {boolean}
		 */
		loadDismissalState() {
			try {
				const dismissed = localStorage.getItem('wp_ai_chatbot_lead_dismissed');
				const dismissedAt = localStorage.getItem('wp_ai_chatbot_lead_dismissed_at');

				if (dismissed && dismissedAt) {
					// Check if dismissal was within last hour (allow re-show after an hour)
					const hourInMs = 60 * 60 * 1000;
					if (Date.now() - parseInt(dismissedAt) < hourInMs) {
						return true;
					}
				}
			} catch (e) {
				// localStorage not available
			}
			return false;
		}

		/**
		 * Reset state (for testing)
		 */
		reset() {
			this.state.hasSubmitted = false;
			this.state.hasDismissed = false;
			try {
				localStorage.removeItem('wp_ai_chatbot_lead_submitted');
				localStorage.removeItem('wp_ai_chatbot_lead_submitted_at');
				localStorage.removeItem('wp_ai_chatbot_lead_dismissed');
				localStorage.removeItem('wp_ai_chatbot_lead_dismissed_at');
			} catch (e) {
				// localStorage not available
			}
		}

		/**
		 * Destroy instance
		 */
		destroy() {
			// Remove event listeners
			this.triggerListeners.forEach(({ type, handler, target }) => {
				target.removeEventListener(type, handler);
			});

			this.hide();
		}
	}

	// Expose to window
	window.WPAIChatbotLeadForm = LeadCaptureForm;

	// Auto-initialize when chat widget is ready
	$(document).on('wp_ai_chatbot_ready', function(e, chatWidget) {
		if (!window.wpAIChatbot?.leadFormSettings?.enabled) {
			return;
		}

		const leadForm = new LeadCaptureForm({
			triggerSettings: window.wpAIChatbot.leadFormSettings
		});

		// Connect to chat widget
		if (chatWidget) {
			// Update context when messages are sent
			$(document).on('wp_ai_chatbot_message_sent wp_ai_chatbot_message_received', function() {
				leadForm.updateContext({
					messageCount: chatWidget.getMessageCount?.() || 0,
					conversationId: chatWidget.conversationId,
					sessionId: chatWidget.sessionId
				});
			});

			// Update on intent detection
			$(document).on('wp_ai_chatbot_intent_detected', function(e, intent) {
				leadForm.updateContext({
					intent: intent,
					conversationId: chatWidget.conversationId,
					sessionId: chatWidget.sessionId
				});
			});
		}

		window.wpAIChatbotLeadForm = leadForm;
	});

})(jQuery);

