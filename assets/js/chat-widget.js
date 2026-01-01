/**
 * Chat Widget JavaScript.
 *
 * Handles message sending, receiving, and display for the chat widget.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/assets/js
 * @since      1.0.0
 */
(function($) {
	'use strict';

	/**
	 * Chat Widget Class.
	 */
	class ChatWidget {
		constructor(config) {
			this.config = config || {};
			
			// State management
			this.state = {
				isOpen: false,
				isMinimized: false,
				conversationId: null,
				messages: [],
				isTyping: false,
				isSending: false,
				leadCaptured: false,
				lastMessageTime: null,
				unreadCount: 0,
				scrollPosition: 0,
				hasUnreadMessages: false,
			};
			
			// DOM elements
			this.$widget = $('#wp-ai-chatbot-widget');
			this.$toggle = $('#wp-ai-chatbot-toggle');
			this.$container = $('#wp-ai-chatbot-container');
			this.$messages = $('#wp-ai-chatbot-messages');
			this.$input = $('#wp-ai-chatbot-input');
			this.$form = $('#wp-ai-chatbot-form');
			this.$sendButton = $('#wp-ai-chatbot-send');
			this.$typingIndicator = $('#wp-ai-chatbot-typing');
			this.$charCount = $('.wp-ai-chatbot-char-count-current');
			this.$leadCapture = $('#wp-ai-chatbot-lead-capture');
			this.$leadForm = $('#wp-ai-chatbot-lead-form');
			this.$notificationBadge = $('.wp-ai-chatbot-notification-badge');
			
			// State persistence keys
			this.storageKeys = {
				conversationId: 'wp_ai_chatbot_conversation_id',
				messages: 'wp_ai_chatbot_messages',
				state: 'wp_ai_chatbot_state',
				leadCaptured: 'wp_ai_chatbot_lead_captured',
			};
			
			this.init();
		}

		/**
		 * Initialize the chat widget.
		 */
		init() {
			this.bindEvents();
			this.loadState();
			this.loadConversation();
			this.updateCharCount();
			this.restoreScrollPosition();
			this.updateUnreadBadge();
			this.trackScroll();
			
			// Restore open state if it was open (but don't auto-open on page load)
			// Only restore if user explicitly had it open
			if (this.state.isOpen && this.config.restoreOpenState !== false) {
				// Don't auto-open, let user decide
			}
		}

		/**
		 * Bind event handlers.
		 */
		bindEvents() {
			// Toggle chat
			this.$toggle.on('click', () => this.toggle());

			// Close button
			$('.wp-ai-chatbot-close').on('click', () => this.close());

			// Form submission
			this.$form.on('submit', (e) => {
				e.preventDefault();
				this.sendMessage();
			});

			// Input events
			this.$input.on('input', () => {
				this.updateCharCount();
				this.autoResize();
				this.toggleSendButton();
			});

			this.$input.on('keydown', (e) => {
				// Send on Enter (but allow Shift+Enter for new line)
				if (e.key === 'Enter' && !e.shiftKey) {
					e.preventDefault();
					if (!this.isSending && this.$input.val().trim()) {
						this.sendMessage();
					}
				}
			});

			// Quick question buttons
			$(document).on('click', '.wp-ai-chatbot-quick-question', (e) => {
				const question = $(e.currentTarget).data('question');
				if (question) {
					this.$input.val(question);
					this.sendMessage();
				}
			});

			// Lead capture form
			this.$leadForm.on('submit', (e) => {
				e.preventDefault();
				this.submitLeadForm();
			});

			$('#wp-ai-chatbot-lead-skip').on('click', () => {
				this.hideLeadCapture();
			});

			// Click outside to close
			$(document).on('click', (e) => {
				if (this.isOpen && !this.$widget.find(e.target).length && !$(e.target).closest(this.$widget).length) {
					this.close();
				}
			});

			// Prevent closing when clicking inside
			this.$container.on('click', (e) => {
				e.stopPropagation();
			});
		}

		/**
		 * Toggle chat open/close.
		 */
		toggle() {
			if (this.state.isOpen) {
				this.close();
			} else {
				this.open();
			}
		}

		/**
		 * Open chat widget.
		 */
		open() {
			if (this.state.isOpen) return;

			this.setState({ isOpen: true, isMinimized: false });
			this.$widget.attr('aria-hidden', 'false');
			this.$container.addClass('is-open');
			this.$toggle.attr('aria-expanded', 'true');
			this.$toggle.addClass('is-active');
			
			// Clear unread count
			this.setState({ unreadCount: 0, hasUnreadMessages: false });
			this.updateUnreadBadge();
			
			// Focus input
			setTimeout(() => {
				this.$input.focus();
			}, 100);

			// Scroll to bottom
			this.scrollToBottom();

			// Save state
			this.saveState();

			// Trigger event
			$(document).trigger('wpAiChatbot:opened', [this.state]);
		}

		/**
		 * Close chat widget.
		 */
		close() {
			if (!this.state.isOpen) return;

			// Save scroll position before closing
			this.saveScrollPosition();

			this.setState({ isOpen: false });
			this.$widget.attr('aria-hidden', 'true');
			this.$container.removeClass('is-open');
			this.$toggle.attr('aria-expanded', 'false');
			this.$toggle.removeClass('is-active');

			// Save state
			this.saveState();

			// Trigger event
			$(document).trigger('wpAiChatbot:closed', [this.state]);
		}

		/**
		 * Minimize chat widget.
		 */
		minimize() {
			if (!this.state.isOpen) return;
			
			this.setState({ isMinimized: true });
			this.$container.addClass('is-minimized');
			this.saveState();
			
			$(document).trigger('wpAiChatbot:minimized', [this.state]);
		}

		/**
		 * Restore minimized chat.
		 */
		restore() {
			if (!this.state.isMinimized) return;
			
			this.setState({ isMinimized: false });
			this.$container.removeClass('is-minimized');
			this.saveState();
			
			$(document).trigger('wpAiChatbot:restored', [this.state]);
		}

		/**
		 * Send a message.
		 */
		sendMessage() {
			const message = this.$input.val().trim();
			
			if (!message || this.state.isSending || this.state.isTyping) {
				return;
			}

			// Check if lead capture is required
			if (this.shouldShowLeadCapture()) {
				this.showLeadCapture();
				return;
			}

			// Clear input
			this.$input.val('');
			this.updateCharCount();
			this.autoResize();
			this.toggleSendButton();

			// Display user message
			this.addMessage('user', message);

			// Update state
			this.setState({ 
				isSending: true,
				lastMessageTime: new Date().toISOString(),
			});

			// Show typing indicator
			this.showTyping();

			// Disable send button
			this.$sendButton.prop('disabled', true);

			$.ajax({
				url: this.config.ajaxUrl || wpAiChatbot.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_send_message',
					nonce: this.config.nonce || wpAiChatbot.nonce,
					message: message,
					conversation_id: this.state.conversationId,
				},
				success: (response) => {
					this.handleMessageResponse(response);
				},
				error: (xhr, status, error) => {
					this.handleError(error);
				},
				complete: () => {
					this.setState({ isSending: false });
					this.$sendButton.prop('disabled', false);
					this.hideTyping();
					this.$input.focus();
					this.saveState();
				}
			});
		}

		/**
		 * Handle message response from server.
		 */
		handleMessageResponse(response) {
			if (!response.success) {
				this.handleError(response.data?.message || 'Failed to send message');
				return;
			}

			const data = response.data;

			// Update conversation ID
			if (data.conversation_id) {
				this.setState({ conversationId: data.conversation_id });
				this.saveConversationId();
			}

			// Add assistant message
			if (data.message) {
				this.addMessage('assistant', data.message, {
					citations: data.citations,
					message_id: data.message_id,
				});
			}

			// Handle lead capture trigger
			if (data.show_lead_capture) {
				this.showLeadCapture();
			}

			// Update unread count if chat is closed
			if (!this.state.isOpen) {
				this.setState({ 
					unreadCount: this.state.unreadCount + 1,
					hasUnreadMessages: true,
				});
				this.updateUnreadBadge();
			}

			// Save state and messages
			this.saveState();
			this.saveMessages();

			// Trigger event
			$(document).trigger('wpAiChatbot:messageReceived', [data, this.state]);
		}

		/**
		 * Add a message to the chat.
		 */
		addMessage(role, content, metadata = {}) {
			// Hide welcome message
			$('#wp-ai-chatbot-welcome').hide();

			const messageId = metadata.message_id || 'msg-' + Date.now();
			const $message = $('<div>')
				.addClass('wp-ai-chatbot-message')
				.addClass('wp-ai-chatbot-message-' + role)
				.attr('data-message-id', messageId)
				.attr('role', 'article')
				.attr('aria-label', role === 'user' ? 'Your message' : 'AI response');

			const $content = $('<div>').addClass('wp-ai-chatbot-message-content');
			
			if (role === 'user') {
				$content.text(content);
			} else {
				// Format assistant message with citations
				$content.html(this.formatAssistantMessage(content, metadata.citations));
				
				// Add feedback buttons
				if (metadata.message_id) {
					const $feedback = $('<div>')
						.addClass('wp-ai-chatbot-message-feedback')
						.attr('role', 'group')
						.attr('aria-label', 'Rate this response');
					
					$feedback.append(
						$('<button>')
							.addClass('wp-ai-chatbot-feedback-btn wp-ai-chatbot-feedback-up')
							.attr('type', 'button')
							.attr('aria-label', 'Helpful')
							.attr('data-message-id', metadata.message_id)
							.html('<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 14V8M8 8V2M8 8H4L6 4H10L8 8Z" stroke="currentColor" stroke-width="2"/></svg>')
					);
					
					$feedback.append(
						$('<button>')
							.addClass('wp-ai-chatbot-feedback-btn wp-ai-chatbot-feedback-down')
							.attr('type', 'button')
							.attr('aria-label', 'Not helpful')
							.attr('data-message-id', metadata.message_id)
							.html('<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2V8M8 8V14M8 8H12L10 12H6L8 8Z" stroke="currentColor" stroke-width="2"/></svg>')
					);
					
					$content.after($feedback);
					
					// Bind feedback handlers
					$feedback.find('.wp-ai-chatbot-feedback-btn').on('click', (e) => {
						const $btn = $(e.currentTarget);
						const msgId = $btn.data('message-id');
						const feedback = $btn.hasClass('wp-ai-chatbot-feedback-up') ? 'positive' : 'negative';
						this.submitFeedback(msgId, feedback);
						$feedback.find('.wp-ai-chatbot-feedback-btn').prop('disabled', true);
						$btn.addClass('is-active');
					});
				}
			}

			$message.append($content);
			this.$messages.append($message);

			// Scroll to bottom
			this.scrollToBottom();

			// Store message in state
			const messageData = {
				id: messageId,
				role: role,
				content: content,
				metadata: metadata,
				timestamp: new Date().toISOString(),
			};
			
			this.state.messages.push(messageData);
			
			// Save messages
			this.saveMessages();
			
			// Trigger event
			$(document).trigger('wpAiChatbot:messageAdded', [messageData, this.state]);
		}

		/**
		 * Format assistant message with citations.
		 */
		formatAssistantMessage(content, citations) {
			if (!citations || !Array.isArray(citations) || citations.length === 0) {
				return this.escapeHtml(content);
			}

			let formatted = this.escapeHtml(content);

			// Replace citation markers with links
			citations.forEach((citation, index) => {
				const marker = `[${index + 1}]`;
				const url = citation.source_url || citation.url || '#';
				const title = citation.title || citation.source_url || 'Source';
				
				const link = `<a href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="wp-ai-chatbot-citation" aria-label="Citation ${index + 1}: ${this.escapeHtml(title)}">${marker}</a>`;
				formatted = formatted.replace(new RegExp('\\[' + (index + 1) + '\\]', 'g'), link);
			});

			return formatted;
		}

		/**
		 * Escape HTML.
		 */
		escapeHtml(text) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return text.replace(/[&<>"']/g, m => map[m]);
		}

		/**
		 * Show typing indicator.
		 */
		showTyping() {
			this.setState({ isTyping: true });
			this.$typingIndicator.show().attr('aria-hidden', 'false');
			this.scrollToBottom();
			
			// Auto-hide after timeout (safety measure)
			clearTimeout(this.typingTimeout);
			this.typingTimeout = setTimeout(() => {
				this.hideTyping();
			}, 30000); // 30 seconds max
		}

		/**
		 * Hide typing indicator.
		 */
		hideTyping() {
			clearTimeout(this.typingTimeout);
			this.setState({ isTyping: false });
			this.$typingIndicator.hide().attr('aria-hidden', 'true');
		}

		/**
		 * Scroll messages to bottom.
		 */
		scrollToBottom() {
			setTimeout(() => {
				this.$messages.scrollTop(this.$messages[0].scrollHeight);
			}, 100);
		}

		/**
		 * Update character count.
		 */
		updateCharCount() {
			const length = this.$input.val().length;
			const max = parseInt(this.$input.attr('maxlength') || 2000);
			this.$charCount.text(length);
			
			if (length > max * 0.9) {
				this.$charCount.parent().addClass('is-warning');
			} else {
				this.$charCount.parent().removeClass('is-warning');
			}
		}

		/**
		 * Auto-resize textarea.
		 */
		autoResize() {
			this.$input.css('height', 'auto');
			const height = Math.min(this.$input[0].scrollHeight, 150);
			this.$input.css('height', height + 'px');
		}

		/**
		 * Toggle send button state.
		 */
		toggleSendButton() {
			const hasText = this.$input.val().trim().length > 0;
			this.$sendButton.prop('disabled', !hasText || this.isSending);
		}

		/**
		 * Handle error.
		 */
		handleError(error) {
			this.addMessage('assistant', 'Sorry, I encountered an error. Please try again.', {
				is_error: true,
			});
			console.error('Chat widget error:', error);
		}

		/**
		 * Check if lead capture should be shown.
		 */
		shouldShowLeadCapture() {
			// Check if user is already captured
			if (this.state.leadCaptured || this.config.leadCaptured) {
				return false;
			}

			// Check message count threshold
			const messageCount = this.state.messages.filter(m => m.role === 'user').length;
			const threshold = this.config.leadCaptureAfterMessages || 3;
			
			return messageCount >= threshold;
		}

		/**
		 * Show lead capture form.
		 */
		showLeadCapture() {
			this.$leadCapture.show().attr('aria-hidden', 'false');
			$('#wp-ai-chatbot-lead-name').focus();
		}

		/**
		 * Hide lead capture form.
		 */
		hideLeadCapture() {
			this.$leadCapture.hide().attr('aria-hidden', 'true');
		}

		/**
		 * Submit lead capture form.
		 */
		submitLeadForm() {
			const formData = {
				name: $('#wp-ai-chatbot-lead-name').val().trim(),
				email: $('#wp-ai-chatbot-lead-email').val().trim(),
				phone: $('#wp-ai-chatbot-lead-phone').val().trim(),
			};

			if (!formData.name || !formData.email) {
				alert('Please fill in all required fields.');
				return;
			}

			$.ajax({
				url: this.config.ajaxUrl || wpAiChatbot.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_capture_lead',
					nonce: this.config.nonce || wpAiChatbot.nonce,
					conversation_id: this.conversationId,
					...formData,
				},
				success: (response) => {
					if (response.success) {
						this.setState({ leadCaptured: true });
						this.config.leadCaptured = true;
						localStorage.setItem(this.storageKeys.leadCaptured, 'true');
						this.hideLeadCapture();
						this.addMessage('assistant', 'Thank you! How can I help you?');
						this.saveState();
					} else {
						alert(response.data?.message || 'Failed to save your information.');
					}
				},
				error: () => {
					alert('An error occurred. Please try again.');
				}
			});
		}

		/**
		 * Submit feedback.
		 */
		submitFeedback(messageId, feedback) {
			$.ajax({
				url: this.config.ajaxUrl || wpAiChatbot.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wp_ai_chatbot_submit_feedback',
					nonce: this.config.nonce || wpAiChatbot.nonce,
					message_id: messageId,
					feedback: feedback,
				},
				error: () => {
					console.error('Failed to submit feedback');
				}
			});
		}

		/**
		 * Load conversation history.
		 */
		loadConversation() {
			// Load conversation ID from storage
			const storedId = localStorage.getItem(this.storageKeys.conversationId);
			if (storedId) {
				this.setState({ conversationId: storedId });
			}

			// Load messages from storage
			const storedMessages = localStorage.getItem(this.storageKeys.messages);
			if (storedMessages) {
				try {
					const messages = JSON.parse(storedMessages);
					if (Array.isArray(messages) && messages.length > 0) {
						// Restore messages to UI
						messages.forEach((msg) => {
							this.addMessageToUI(msg.role, msg.content, msg.metadata || {}, false);
						});
						this.setState({ messages: messages });
					}
				} catch (e) {
					console.error('Failed to load messages:', e);
				}
			}

			// Load lead capture status
			const leadCaptured = localStorage.getItem(this.storageKeys.leadCaptured);
			if (leadCaptured === 'true') {
				this.setState({ leadCaptured: true });
				this.config.leadCaptured = true;
			}
		}

		/**
		 * Save conversation ID.
		 */
		saveConversationId() {
			if (this.state.conversationId) {
				localStorage.setItem(this.storageKeys.conversationId, this.state.conversationId);
			}
		}

		/**
		 * Save messages to storage.
		 */
		saveMessages() {
			try {
				// Only save last 50 messages to avoid storage limits
				const messagesToSave = this.state.messages.slice(-50);
				localStorage.setItem(this.storageKeys.messages, JSON.stringify(messagesToSave));
			} catch (e) {
				console.error('Failed to save messages:', e);
				// If storage is full, try to save fewer messages
				try {
					const messagesToSave = this.state.messages.slice(-25);
					localStorage.setItem(this.storageKeys.messages, JSON.stringify(messagesToSave));
				} catch (e2) {
					console.error('Failed to save messages (reduced):', e2);
				}
			}
		}

		/**
		 * Save state to storage.
		 */
		saveState() {
			try {
				const stateToSave = {
					isOpen: this.state.isOpen,
					isMinimized: this.state.isMinimized,
					conversationId: this.state.conversationId,
					leadCaptured: this.state.leadCaptured,
					lastMessageTime: this.state.lastMessageTime,
					scrollPosition: this.$messages.scrollTop(),
				};
				localStorage.setItem(this.storageKeys.state, JSON.stringify(stateToSave));
			} catch (e) {
				console.error('Failed to save state:', e);
			}
		}

		/**
		 * Load state from storage.
		 */
		loadState() {
			try {
				const storedState = localStorage.getItem(this.storageKeys.state);
				if (storedState) {
					const state = JSON.parse(storedState);
					if (state) {
						// Restore state (but don't auto-open)
						this.setState({
							conversationId: state.conversationId || null,
							leadCaptured: state.leadCaptured || false,
							lastMessageTime: state.lastMessageTime || null,
							scrollPosition: state.scrollPosition || 0,
						});
						
						if (state.leadCaptured) {
							this.config.leadCaptured = true;
						}
					}
				}
			} catch (e) {
				console.error('Failed to load state:', e);
			}
		}

		/**
		 * Restore scroll position.
		 */
		restoreScrollPosition() {
			if (this.state.scrollPosition > 0) {
				setTimeout(() => {
					this.$messages.scrollTop(this.state.scrollPosition);
				}, 100);
			}
		}

		/**
		 * Save scroll position.
		 */
		saveScrollPosition() {
			this.setState({ scrollPosition: this.$messages.scrollTop() });
		}

		/**
		 * Update unread badge.
		 */
		updateUnreadBadge() {
			if (this.state.unreadCount > 0) {
				this.$notificationBadge.text(this.state.unreadCount).show();
			} else {
				this.$notificationBadge.hide();
			}
		}

		/**
		 * Set state (with optional callback).
		 */
		setState(newState) {
			const oldState = { ...this.state };
			this.state = { ...this.state, ...newState };
			
			// Trigger state change event
			$(document).trigger('wpAiChatbot:stateChanged', [this.state, oldState]);
		}

		/**
		 * Get current state.
		 */
		getState() {
			return { ...this.state };
		}

		/**
		 * Clear conversation history.
		 */
		clearHistory() {
			this.setState({ messages: [] });
			this.$messages.empty();
			$('#wp-ai-chatbot-welcome').show();
			localStorage.removeItem(this.storageKeys.messages);
			this.saveState();
			
			$(document).trigger('wpAiChatbot:historyCleared');
		}

		/**
		 * Add message to UI without saving (for restoring history).
		 */
		addMessageToUI(role, content, metadata = {}, save = true) {
			// Hide welcome message
			$('#wp-ai-chatbot-welcome').hide();

			const messageId = metadata.message_id || 'msg-' + Date.now();
			const $message = $('<div>')
				.addClass('wp-ai-chatbot-message')
				.addClass('wp-ai-chatbot-message-' + role)
				.attr('data-message-id', messageId)
				.attr('role', 'article')
				.attr('aria-label', role === 'user' ? 'Your message' : 'AI response');

			const $content = $('<div>').addClass('wp-ai-chatbot-message-content');
			
			if (role === 'user') {
				$content.text(content);
			} else {
				$content.html(this.formatAssistantMessage(content, metadata.citations));
				
				// Add feedback buttons if message_id exists
				if (metadata.message_id) {
					const $feedback = $('<div>')
						.addClass('wp-ai-chatbot-message-feedback')
						.attr('role', 'group')
						.attr('aria-label', 'Rate this response');
					
					$feedback.append(
						$('<button>')
							.addClass('wp-ai-chatbot-feedback-btn wp-ai-chatbot-feedback-up')
							.attr('type', 'button')
							.attr('aria-label', 'Helpful')
							.attr('data-message-id', metadata.message_id)
							.html('<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 14V8M8 8V2M8 8H4L6 4H10L8 8Z" stroke="currentColor" stroke-width="2"/></svg>')
					);
					
					$feedback.append(
						$('<button>')
							.addClass('wp-ai-chatbot-feedback-btn wp-ai-chatbot-feedback-down')
							.attr('type', 'button')
							.attr('aria-label', 'Not helpful')
							.attr('data-message-id', metadata.message_id)
							.html('<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2V8M8 8V14M8 8H12L10 12H6L8 8Z" stroke="currentColor" stroke-width="2"/></svg>')
					);
					
					$content.after($feedback);
					
					// Bind feedback handlers
					$feedback.find('.wp-ai-chatbot-feedback-btn').on('click', (e) => {
						const $btn = $(e.currentTarget);
						const msgId = $btn.data('message-id');
						const feedback = $btn.hasClass('wp-ai-chatbot-feedback-up') ? 'positive' : 'negative';
						this.submitFeedback(msgId, feedback);
						$feedback.find('.wp-ai-chatbot-feedback-btn').prop('disabled', true);
						$btn.addClass('is-active');
					});
				}
			}

			$message.append($content);
			this.$messages.append($message);

			if (save) {
				// Scroll to bottom only if saving (new message)
				this.scrollToBottom();
			}
		}

		/**
		 * Track scroll position for message history loading.
		 */
		trackScroll() {
			this.$messages.on('scroll', () => {
				// Save scroll position
				this.saveScrollPosition();
				
				// Check if scrolled to top (for lazy loading)
				if (this.$messages.scrollTop() === 0 && this.state.messages.length > 0) {
					// Could load more messages here
					$(document).trigger('wpAiChatbot:scrollToTop');
				}
			});
		}
	}

	// Initialize when DOM is ready
	$(document).ready(function() {
		if (typeof wpAiChatbot !== 'undefined') {
			window.chatWidget = new ChatWidget(wpAiChatbot);
		}
	});

})(jQuery);

