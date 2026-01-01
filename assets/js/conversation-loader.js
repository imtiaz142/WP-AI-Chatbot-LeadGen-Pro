/**
 * Conversation Loader.
 *
 * Implements lazy loading for conversation history to improve performance.
 *
 * @package WP_AI_Chatbot_LeadGen_Pro
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Conversation Loader class.
     */
    class ConversationLoader {
        /**
         * Constructor.
         *
         * @param {Object} config Configuration options.
         */
        constructor(config = {}) {
            this.config = {
                containerSelector: '.wp-ai-chatbot-messages',
                messageClass: 'wp-ai-chatbot-message',
                loadingClass: 'wp-ai-chatbot-loading',
                loadMoreClass: 'wp-ai-chatbot-load-more',
                
                // Pagination settings
                pageSize: 20,
                initialLoad: 15,
                preloadThreshold: 200, // pixels from top to trigger load
                
                // API settings
                ajaxUrl: window.wpAiChatbotConfig?.ajaxUrl || '/wp-admin/admin-ajax.php',
                nonce: window.wpAiChatbotConfig?.nonce || '',
                
                // Callbacks
                onMessagesLoaded: null,
                onLoadStart: null,
                onLoadEnd: null,
                onError: null,
                
                // Rendering
                renderMessage: null, // Custom render function
                
                ...config
            };

            this.state = {
                conversationId: null,
                sessionId: null,
                messages: [],
                oldestMessageId: null,
                newestMessageId: null,
                hasMore: true,
                isLoading: false,
                page: 0,
                totalMessages: 0,
                loadedCount: 0,
                scrollPosition: 0,
                isInitialized: false
            };

            this.container = null;
            this.observer = null;
            this.loadMoreTrigger = null;

            this.init();
        }

        /**
         * Initialize the loader.
         */
        init() {
            this.container = document.querySelector(this.config.containerSelector);
            if (!this.container) {
                console.warn('ConversationLoader: Container not found');
                return;
            }

            this.setupScrollListener();
            this.setupIntersectionObserver();
            this.state.isInitialized = true;
        }

        /**
         * Load conversation.
         *
         * @param {number} conversationId Conversation ID.
         * @param {Object} options Load options.
         * @returns {Promise} Load promise.
         */
        async loadConversation(conversationId, options = {}) {
            const defaults = {
                sessionId: null,
                loadLatest: true,
                initialCount: this.config.initialLoad
            };
            options = { ...defaults, ...options };

            this.state.conversationId = conversationId;
            this.state.sessionId = options.sessionId;
            this.state.messages = [];
            this.state.page = 0;
            this.state.hasMore = true;
            this.state.oldestMessageId = null;
            this.state.newestMessageId = null;

            // Clear container
            this.clearMessages();

            // Load initial messages
            return this.loadMessages({
                limit: options.initialCount,
                direction: 'latest'
            });
        }

        /**
         * Load messages.
         *
         * @param {Object} options Load options.
         * @returns {Promise} Load promise.
         */
        async loadMessages(options = {}) {
            if (this.state.isLoading) {
                return Promise.resolve([]);
            }

            const defaults = {
                limit: this.config.pageSize,
                before: null,
                after: null,
                direction: 'older' // 'older' or 'newer' or 'latest'
            };
            options = { ...defaults, ...options };

            this.state.isLoading = true;
            this.showLoading(options.direction === 'older' ? 'top' : 'bottom');
            this.triggerCallback('onLoadStart', options);

            try {
                const response = await this.fetchMessages(options);
                
                if (response.success && response.data) {
                    const messages = response.data.messages || [];
                    const hasMore = response.data.has_more !== false;
                    const total = response.data.total || 0;

                    this.state.hasMore = hasMore;
                    this.state.totalMessages = total;
                    this.state.loadedCount += messages.length;

                    if (messages.length > 0) {
                        if (options.direction === 'older') {
                            this.prependMessages(messages);
                        } else {
                            this.appendMessages(messages);
                        }

                        // Update bounds
                        this.updateMessageBounds(messages, options.direction);
                    }

                    this.triggerCallback('onMessagesLoaded', messages, options);
                    return messages;
                } else {
                    throw new Error(response.data?.message || 'Failed to load messages');
                }
            } catch (error) {
                console.error('ConversationLoader: Error loading messages', error);
                this.triggerCallback('onError', error);
                return [];
            } finally {
                this.state.isLoading = false;
                this.hideLoading();
                this.triggerCallback('onLoadEnd');
            }
        }

        /**
         * Fetch messages from server.
         *
         * @param {Object} options Fetch options.
         * @returns {Promise} Fetch promise.
         */
        async fetchMessages(options) {
            const params = new URLSearchParams({
                action: 'wp_ai_chatbot_get_messages',
                nonce: this.config.nonce,
                conversation_id: this.state.conversationId,
                limit: options.limit
            });

            if (this.state.sessionId) {
                params.append('session_id', this.state.sessionId);
            }

            if (options.before) {
                params.append('before_id', options.before);
            }

            if (options.after) {
                params.append('after_id', options.after);
            }

            if (options.direction) {
                params.append('direction', options.direction);
            }

            const response = await fetch(this.config.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params.toString()
            });

            return response.json();
        }

        /**
         * Load older messages (scroll up).
         *
         * @returns {Promise} Load promise.
         */
        async loadOlderMessages() {
            if (!this.state.hasMore || this.state.isLoading) {
                return [];
            }

            return this.loadMessages({
                direction: 'older',
                before: this.state.oldestMessageId
            });
        }

        /**
         * Load newer messages (check for new).
         *
         * @returns {Promise} Load promise.
         */
        async loadNewerMessages() {
            if (this.state.isLoading) {
                return [];
            }

            return this.loadMessages({
                direction: 'newer',
                after: this.state.newestMessageId
            });
        }

        /**
         * Update message bounds after loading.
         *
         * @param {Array} messages Loaded messages.
         * @param {string} direction Load direction.
         */
        updateMessageBounds(messages, direction) {
            if (messages.length === 0) return;

            // Messages are ordered oldest to newest
            const oldest = messages[0];
            const newest = messages[messages.length - 1];

            if (direction === 'older' || !this.state.oldestMessageId) {
                this.state.oldestMessageId = oldest.id;
            }

            if (direction === 'newer' || direction === 'latest' || !this.state.newestMessageId) {
                this.state.newestMessageId = newest.id;
            }

            // Store messages
            if (direction === 'older') {
                this.state.messages = [...messages, ...this.state.messages];
            } else {
                this.state.messages = [...this.state.messages, ...messages];
            }
        }

        /**
         * Prepend messages to container (older messages at top).
         *
         * @param {Array} messages Messages to prepend.
         */
        prependMessages(messages) {
            // Save scroll position
            const scrollHeight = this.container.scrollHeight;
            const scrollTop = this.container.scrollTop;

            // Create fragment for batch insert
            const fragment = document.createDocumentFragment();
            
            // Messages come oldest first, so we add in order
            messages.forEach(message => {
                const element = this.renderMessage(message);
                if (element) {
                    fragment.appendChild(element);
                }
            });

            // Insert at beginning
            const firstChild = this.container.firstChild;
            if (firstChild) {
                this.container.insertBefore(fragment, firstChild);
            } else {
                this.container.appendChild(fragment);
            }

            // Restore scroll position (keep viewing same content)
            const newScrollHeight = this.container.scrollHeight;
            this.container.scrollTop = scrollTop + (newScrollHeight - scrollHeight);

            // Update load more trigger position
            this.updateLoadMoreTrigger();
        }

        /**
         * Append messages to container (newer messages at bottom).
         *
         * @param {Array} messages Messages to append.
         */
        appendMessages(messages) {
            const wasAtBottom = this.isScrolledToBottom();

            // Create fragment for batch insert
            const fragment = document.createDocumentFragment();
            
            messages.forEach(message => {
                const element = this.renderMessage(message);
                if (element) {
                    fragment.appendChild(element);
                }
            });

            this.container.appendChild(fragment);

            // Auto-scroll to bottom if was at bottom
            if (wasAtBottom) {
                this.scrollToBottom();
            }
        }

        /**
         * Render a single message.
         *
         * @param {Object} message Message data.
         * @returns {HTMLElement} Message element.
         */
        renderMessage(message) {
            // Use custom render function if provided
            if (typeof this.config.renderMessage === 'function') {
                return this.config.renderMessage(message);
            }

            // Default rendering
            const element = document.createElement('div');
            element.className = `${this.config.messageClass} ${this.config.messageClass}--${message.role}`;
            element.dataset.messageId = message.id;
            element.dataset.timestamp = message.created_at;

            const isUser = message.role === 'user';

            element.innerHTML = `
                <div class="${this.config.messageClass}__avatar">
                    ${isUser ? this.getUserAvatar() : this.getBotAvatar()}
                </div>
                <div class="${this.config.messageClass}__content">
                    <div class="${this.config.messageClass}__bubble">
                        <p class="${this.config.messageClass}__text">${this.escapeHtml(message.content)}</p>
                    </div>
                    <span class="${this.config.messageClass}__time">${this.formatTime(message.created_at)}</span>
                </div>
            `;

            return element;
        }

        /**
         * Get user avatar HTML.
         *
         * @returns {string} Avatar HTML.
         */
        getUserAvatar() {
            return `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>`;
        }

        /**
         * Get bot avatar HTML.
         *
         * @returns {string} Avatar HTML.
         */
        getBotAvatar() {
            return `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>`;
        }

        /**
         * Clear all messages from container.
         */
        clearMessages() {
            if (this.container) {
                this.container.innerHTML = '';
            }
            this.state.messages = [];
            this.state.loadedCount = 0;
        }

        /**
         * Setup scroll listener for lazy loading.
         */
        setupScrollListener() {
            let scrollTimeout;
            
            this.container.addEventListener('scroll', () => {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    this.handleScroll();
                }, 100);
            }, { passive: true });
        }

        /**
         * Handle scroll event.
         */
        handleScroll() {
            const scrollTop = this.container.scrollTop;
            
            // Check if near top for loading older messages
            if (scrollTop < this.config.preloadThreshold && this.state.hasMore) {
                this.loadOlderMessages();
            }

            this.state.scrollPosition = scrollTop;
        }

        /**
         * Setup Intersection Observer for load more trigger.
         */
        setupIntersectionObserver() {
            if (!('IntersectionObserver' in window)) {
                return;
            }

            this.observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting && this.state.hasMore && !this.state.isLoading) {
                            this.loadOlderMessages();
                        }
                    });
                },
                {
                    root: this.container,
                    rootMargin: `${this.config.preloadThreshold}px 0px 0px 0px`,
                    threshold: 0
                }
            );

            this.createLoadMoreTrigger();
        }

        /**
         * Create load more trigger element.
         */
        createLoadMoreTrigger() {
            this.loadMoreTrigger = document.createElement('div');
            this.loadMoreTrigger.className = this.config.loadMoreClass;
            this.loadMoreTrigger.style.height = '1px';
            this.loadMoreTrigger.style.width = '100%';
            
            if (this.container.firstChild) {
                this.container.insertBefore(this.loadMoreTrigger, this.container.firstChild);
            } else {
                this.container.appendChild(this.loadMoreTrigger);
            }

            if (this.observer) {
                this.observer.observe(this.loadMoreTrigger);
            }
        }

        /**
         * Update load more trigger position.
         */
        updateLoadMoreTrigger() {
            if (this.loadMoreTrigger && this.container.firstChild !== this.loadMoreTrigger) {
                this.container.insertBefore(this.loadMoreTrigger, this.container.firstChild);
            }

            // Show/hide based on hasMore state
            if (this.loadMoreTrigger) {
                this.loadMoreTrigger.style.display = this.state.hasMore ? 'block' : 'none';
            }
        }

        /**
         * Show loading indicator.
         *
         * @param {string} position 'top' or 'bottom'.
         */
        showLoading(position = 'bottom') {
            const loader = document.createElement('div');
            loader.className = `${this.config.loadingClass} ${this.config.loadingClass}--${position}`;
            loader.innerHTML = `
                <div class="${this.config.loadingClass}__spinner">
                    <div class="${this.config.loadingClass}__dot"></div>
                    <div class="${this.config.loadingClass}__dot"></div>
                    <div class="${this.config.loadingClass}__dot"></div>
                </div>
            `;

            if (position === 'top') {
                const firstMessage = this.container.querySelector(`.${this.config.messageClass}`);
                if (firstMessage) {
                    this.container.insertBefore(loader, firstMessage);
                } else {
                    this.container.appendChild(loader);
                }
            } else {
                this.container.appendChild(loader);
            }
        }

        /**
         * Hide loading indicator.
         */
        hideLoading() {
            const loaders = this.container.querySelectorAll(`.${this.config.loadingClass}`);
            loaders.forEach(loader => loader.remove());
        }

        /**
         * Check if scrolled to bottom.
         *
         * @returns {boolean} True if at bottom.
         */
        isScrolledToBottom() {
            const threshold = 50;
            return this.container.scrollHeight - this.container.scrollTop - this.container.clientHeight < threshold;
        }

        /**
         * Scroll to bottom.
         *
         * @param {boolean} smooth Use smooth scroll.
         */
        scrollToBottom(smooth = true) {
            this.container.scrollTo({
                top: this.container.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto'
            });
        }

        /**
         * Scroll to message.
         *
         * @param {number} messageId Message ID.
         * @param {boolean} smooth Use smooth scroll.
         */
        scrollToMessage(messageId, smooth = true) {
            const element = this.container.querySelector(`[data-message-id="${messageId}"]`);
            if (element) {
                element.scrollIntoView({
                    behavior: smooth ? 'smooth' : 'auto',
                    block: 'center'
                });
            }
        }

        /**
         * Add a new message (real-time).
         *
         * @param {Object} message Message data.
         */
        addMessage(message) {
            // Check if message already exists
            if (this.state.messages.find(m => m.id === message.id)) {
                return;
            }

            this.state.messages.push(message);
            this.state.newestMessageId = message.id;
            this.state.loadedCount++;
            this.state.totalMessages++;

            this.appendMessages([message]);
        }

        /**
         * Update a message.
         *
         * @param {number} messageId Message ID.
         * @param {Object} updates Updates to apply.
         */
        updateMessage(messageId, updates) {
            // Update in state
            const index = this.state.messages.findIndex(m => m.id === messageId);
            if (index !== -1) {
                this.state.messages[index] = { ...this.state.messages[index], ...updates };
            }

            // Update in DOM
            const element = this.container.querySelector(`[data-message-id="${messageId}"]`);
            if (element) {
                const textElement = element.querySelector(`.${this.config.messageClass}__text`);
                if (textElement && updates.content) {
                    textElement.innerHTML = this.escapeHtml(updates.content);
                }
            }
        }

        /**
         * Remove a message.
         *
         * @param {number} messageId Message ID.
         */
        removeMessage(messageId) {
            // Remove from state
            this.state.messages = this.state.messages.filter(m => m.id !== messageId);
            this.state.loadedCount--;

            // Remove from DOM
            const element = this.container.querySelector(`[data-message-id="${messageId}"]`);
            if (element) {
                element.remove();
            }
        }

        /**
         * Get message by ID.
         *
         * @param {number} messageId Message ID.
         * @returns {Object|null} Message or null.
         */
        getMessage(messageId) {
            return this.state.messages.find(m => m.id === messageId) || null;
        }

        /**
         * Get all loaded messages.
         *
         * @returns {Array} Messages.
         */
        getMessages() {
            return [...this.state.messages];
        }

        /**
         * Get load state.
         *
         * @returns {Object} State object.
         */
        getState() {
            return { ...this.state };
        }

        /**
         * Escape HTML.
         *
         * @param {string} text Text to escape.
         * @returns {string} Escaped text.
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML.replace(/\n/g, '<br>');
        }

        /**
         * Format timestamp.
         *
         * @param {string} timestamp Timestamp string.
         * @returns {string} Formatted time.
         */
        formatTime(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);

            if (diffMins < 1) {
                return 'Just now';
            } else if (diffMins < 60) {
                return `${diffMins}m ago`;
            } else if (diffHours < 24) {
                return `${diffHours}h ago`;
            } else if (diffDays < 7) {
                return `${diffDays}d ago`;
            } else {
                return date.toLocaleDateString();
            }
        }

        /**
         * Trigger callback.
         *
         * @param {string} name Callback name.
         * @param {...any} args Callback arguments.
         */
        triggerCallback(name, ...args) {
            if (typeof this.config[name] === 'function') {
                this.config[name](...args);
            }
        }

        /**
         * Refresh messages (reload latest).
         *
         * @returns {Promise} Refresh promise.
         */
        async refresh() {
            return this.loadNewerMessages();
        }

        /**
         * Start polling for new messages.
         *
         * @param {number} interval Polling interval in ms.
         */
        startPolling(interval = 5000) {
            this.stopPolling();
            this.pollingInterval = setInterval(() => {
                this.loadNewerMessages();
            }, interval);
        }

        /**
         * Stop polling.
         */
        stopPolling() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
                this.pollingInterval = null;
            }
        }

        /**
         * Destroy the loader.
         */
        destroy() {
            this.stopPolling();
            
            if (this.observer) {
                this.observer.disconnect();
            }

            this.clearMessages();
            this.state.isInitialized = false;
        }
    }

    // Export
    window.WPAIChatbotConversationLoader = ConversationLoader;

    // jQuery plugin
    $.fn.wpAiChatbotLoader = function(config) {
        return this.each(function() {
            if (!$.data(this, 'wpAiChatbotLoader')) {
                $.data(this, 'wpAiChatbotLoader', new ConversationLoader({
                    containerSelector: this,
                    ...config
                }));
            }
        });
    };

})(jQuery);

