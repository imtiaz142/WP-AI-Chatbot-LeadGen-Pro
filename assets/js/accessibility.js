/**
 * Accessibility Module.
 *
 * Implements keyboard navigation and screen reader support for the chat widget.
 * Follows WCAG 2.1 AA guidelines.
 *
 * @package WP_AI_Chatbot_LeadGen_Pro
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Accessibility Manager class.
     */
    class AccessibilityManager {
        /**
         * Constructor.
         *
         * @param {Object} config Configuration options.
         */
        constructor(config = {}) {
            this.config = {
                widgetSelector: '#wp-ai-chatbot-leadgen-pro-widget',
                toggleSelector: '.wp-ai-chatbot-toggle',
                windowSelector: '.wp-ai-chatbot-window',
                messagesSelector: '.wp-ai-chatbot-messages',
                inputSelector: '.wp-ai-chatbot-input',
                messageSelector: '.wp-ai-chatbot-message',
                quickReplySelector: '.wp-ai-chatbot-quick-reply',
                feedbackBtnSelector: '.wp-ai-chatbot-message__feedback-btn',
                closeSelector: '.wp-ai-chatbot-header__btn--close',
                
                // ARIA labels
                labels: {
                    widget: 'Chat assistant',
                    toggle: 'Open chat',
                    toggleClose: 'Close chat',
                    window: 'Chat conversation',
                    messages: 'Chat messages',
                    input: 'Type your message',
                    send: 'Send message',
                    close: 'Close chat',
                    minimize: 'Minimize chat',
                    newMessage: 'New message received',
                    typing: 'Assistant is typing',
                    userMessage: 'You said',
                    botMessage: 'Assistant said',
                    feedbackUp: 'Mark as helpful',
                    feedbackDown: 'Mark as not helpful',
                    quickReply: 'Quick reply option'
                },
                
                // Announcement settings
                announceNewMessages: true,
                announceTyping: true,
                
                ...config
            };

            this.widget = null;
            this.focusableElements = [];
            this.lastFocusedElement = null;
            this.liveRegion = null;
            this.isOpen = false;

            this.init();
        }

        /**
         * Initialize accessibility features.
         */
        init() {
            this.widget = document.querySelector(this.config.widgetSelector);
            if (!this.widget) {
                console.warn('AccessibilityManager: Widget not found');
                return;
            }

            this.createLiveRegion();
            this.setupARIA();
            this.setupKeyboardNavigation();
            this.setupFocusTrap();
            this.setupMessageAnnouncements();
            this.setupReducedMotion();
        }

        /**
         * Create ARIA live region for announcements.
         */
        createLiveRegion() {
            // Check if already exists
            this.liveRegion = document.getElementById('wp-ai-chatbot-live-region');
            
            if (!this.liveRegion) {
                this.liveRegion = document.createElement('div');
                this.liveRegion.id = 'wp-ai-chatbot-live-region';
                this.liveRegion.className = 'wp-ai-chatbot-sr-only';
                this.liveRegion.setAttribute('role', 'status');
                this.liveRegion.setAttribute('aria-live', 'polite');
                this.liveRegion.setAttribute('aria-atomic', 'true');
                document.body.appendChild(this.liveRegion);
            }

            // Also create an assertive region for urgent announcements
            this.assertiveRegion = document.getElementById('wp-ai-chatbot-assertive-region');
            
            if (!this.assertiveRegion) {
                this.assertiveRegion = document.createElement('div');
                this.assertiveRegion.id = 'wp-ai-chatbot-assertive-region';
                this.assertiveRegion.className = 'wp-ai-chatbot-sr-only';
                this.assertiveRegion.setAttribute('role', 'alert');
                this.assertiveRegion.setAttribute('aria-live', 'assertive');
                this.assertiveRegion.setAttribute('aria-atomic', 'true');
                document.body.appendChild(this.assertiveRegion);
            }
        }

        /**
         * Setup ARIA attributes.
         */
        setupARIA() {
            // Widget container
            this.widget.setAttribute('role', 'region');
            this.widget.setAttribute('aria-label', this.config.labels.widget);

            // Toggle button
            const toggle = this.widget.querySelector(this.config.toggleSelector);
            if (toggle) {
                toggle.setAttribute('aria-label', this.config.labels.toggle);
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-haspopup', 'dialog');
            }

            // Chat window
            const chatWindow = this.widget.querySelector(this.config.windowSelector);
            if (chatWindow) {
                chatWindow.setAttribute('role', 'dialog');
                chatWindow.setAttribute('aria-label', this.config.labels.window);
                chatWindow.setAttribute('aria-modal', 'true');
                chatWindow.setAttribute('aria-hidden', 'true');
            }

            // Messages container
            const messages = this.widget.querySelector(this.config.messagesSelector);
            if (messages) {
                messages.setAttribute('role', 'log');
                messages.setAttribute('aria-label', this.config.labels.messages);
                messages.setAttribute('aria-live', 'polite');
                messages.setAttribute('aria-relevant', 'additions');
            }

            // Input field
            const input = this.widget.querySelector(this.config.inputSelector);
            if (input) {
                input.setAttribute('aria-label', this.config.labels.input);
                input.setAttribute('aria-describedby', 'wp-ai-chatbot-input-hint');
                
                // Add hint
                const hint = document.createElement('span');
                hint.id = 'wp-ai-chatbot-input-hint';
                hint.className = 'wp-ai-chatbot-sr-only';
                hint.textContent = 'Press Enter to send, Shift+Enter for new line';
                input.parentNode.appendChild(hint);
            }

            // Send button
            const sendBtn = this.widget.querySelector('.wp-ai-chatbot-send-btn');
            if (sendBtn) {
                sendBtn.setAttribute('aria-label', this.config.labels.send);
            }

            // Close button
            const closeBtn = this.widget.querySelector(this.config.closeSelector);
            if (closeBtn) {
                closeBtn.setAttribute('aria-label', this.config.labels.close);
            }

            // Setup existing messages
            this.setupExistingMessages();
        }

        /**
         * Setup ARIA for existing messages.
         */
        setupExistingMessages() {
            const messages = this.widget.querySelectorAll(this.config.messageSelector);
            messages.forEach((message, index) => {
                this.setupMessageARIA(message, index);
            });
        }

        /**
         * Setup ARIA for a single message.
         *
         * @param {HTMLElement} message Message element.
         * @param {number} index Message index.
         */
        setupMessageARIA(message, index) {
            const isUser = message.classList.contains('wp-ai-chatbot-message--user');
            const role = isUser ? 'user' : 'assistant';
            const label = isUser ? this.config.labels.userMessage : this.config.labels.botMessage;
            
            message.setAttribute('role', 'article');
            message.setAttribute('aria-label', `${label}: Message ${index + 1}`);
            message.setAttribute('tabindex', '0');

            // Setup feedback buttons if present
            const feedbackBtns = message.querySelectorAll(this.config.feedbackBtnSelector);
            feedbackBtns.forEach(btn => {
                if (btn.classList.contains('wp-ai-chatbot-message__feedback-btn--positive')) {
                    btn.setAttribute('aria-label', this.config.labels.feedbackUp);
                } else {
                    btn.setAttribute('aria-label', this.config.labels.feedbackDown);
                }
                btn.setAttribute('aria-pressed', 'false');
            });
        }

        /**
         * Setup keyboard navigation.
         */
        setupKeyboardNavigation() {
            // Global keyboard handler
            document.addEventListener('keydown', this.handleGlobalKeydown.bind(this));

            // Widget-specific keyboard handler
            this.widget.addEventListener('keydown', this.handleWidgetKeydown.bind(this));

            // Input-specific keyboard handler
            const input = this.widget.querySelector(this.config.inputSelector);
            if (input) {
                input.addEventListener('keydown', this.handleInputKeydown.bind(this));
            }

            // Messages keyboard navigation
            const messages = this.widget.querySelector(this.config.messagesSelector);
            if (messages) {
                messages.addEventListener('keydown', this.handleMessagesKeydown.bind(this));
            }
        }

        /**
         * Handle global keyboard events.
         *
         * @param {KeyboardEvent} e Keyboard event.
         */
        handleGlobalKeydown(e) {
            // Alt + C to toggle chat
            if (e.altKey && e.key.toLowerCase() === 'c') {
                e.preventDefault();
                this.toggleChat();
            }
        }

        /**
         * Handle widget keyboard events.
         *
         * @param {KeyboardEvent} e Keyboard event.
         */
        handleWidgetKeydown(e) {
            // Escape to close
            if (e.key === 'Escape' && this.isOpen) {
                e.preventDefault();
                this.closeChat();
            }

            // Tab trap handled separately
        }

        /**
         * Handle input keyboard events.
         *
         * @param {KeyboardEvent} e Keyboard event.
         */
        handleInputKeydown(e) {
            // Enter to send (without shift)
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                const sendBtn = this.widget.querySelector('.wp-ai-chatbot-send-btn');
                if (sendBtn && !sendBtn.disabled) {
                    sendBtn.click();
                }
            }

            // Up arrow to edit last message (if input empty)
            if (e.key === 'ArrowUp' && e.target.value === '') {
                e.preventDefault();
                this.focusLastUserMessage();
            }
        }

        /**
         * Handle messages container keyboard events.
         *
         * @param {KeyboardEvent} e Keyboard event.
         */
        handleMessagesKeydown(e) {
            const messages = Array.from(this.widget.querySelectorAll(this.config.messageSelector));
            const currentIndex = messages.indexOf(document.activeElement);

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (currentIndex < messages.length - 1) {
                        messages[currentIndex + 1].focus();
                    } else {
                        // Focus input after last message
                        const input = this.widget.querySelector(this.config.inputSelector);
                        if (input) input.focus();
                    }
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    if (currentIndex > 0) {
                        messages[currentIndex - 1].focus();
                    }
                    break;

                case 'Home':
                    e.preventDefault();
                    if (messages.length > 0) {
                        messages[0].focus();
                    }
                    break;

                case 'End':
                    e.preventDefault();
                    if (messages.length > 0) {
                        messages[messages.length - 1].focus();
                    }
                    break;

                case 'Enter':
                case ' ':
                    // Activate feedback buttons if focused
                    if (e.target.matches(this.config.feedbackBtnSelector)) {
                        e.preventDefault();
                        e.target.click();
                    }
                    break;
            }
        }

        /**
         * Setup focus trap for modal behavior.
         */
        setupFocusTrap() {
            const chatWindow = this.widget.querySelector(this.config.windowSelector);
            if (!chatWindow) return;

            chatWindow.addEventListener('keydown', (e) => {
                if (e.key !== 'Tab' || !this.isOpen) return;

                this.updateFocusableElements();
                
                if (this.focusableElements.length === 0) return;

                const firstElement = this.focusableElements[0];
                const lastElement = this.focusableElements[this.focusableElements.length - 1];

                if (e.shiftKey && document.activeElement === firstElement) {
                    e.preventDefault();
                    lastElement.focus();
                } else if (!e.shiftKey && document.activeElement === lastElement) {
                    e.preventDefault();
                    firstElement.focus();
                }
            });
        }

        /**
         * Update list of focusable elements.
         */
        updateFocusableElements() {
            const chatWindow = this.widget.querySelector(this.config.windowSelector);
            if (!chatWindow) return;

            const focusableSelectors = [
                'button:not([disabled])',
                'input:not([disabled])',
                'textarea:not([disabled])',
                'select:not([disabled])',
                'a[href]',
                '[tabindex]:not([tabindex="-1"])'
            ].join(', ');

            this.focusableElements = Array.from(
                chatWindow.querySelectorAll(focusableSelectors)
            ).filter(el => {
                return el.offsetParent !== null; // Visible elements only
            });
        }

        /**
         * Setup message announcements for screen readers.
         */
        setupMessageAnnouncements() {
            // Listen for new messages
            document.addEventListener('wp_ai_chatbot_message_added', (e) => {
                if (this.config.announceNewMessages) {
                    const message = e.detail?.message;
                    if (message) {
                        this.announceMessage(message);
                    }
                }
            });

            // Listen for typing indicator
            document.addEventListener('wp_ai_chatbot_typing_start', () => {
                if (this.config.announceTyping) {
                    this.announce(this.config.labels.typing);
                }
            });

            // Mutation observer for dynamic messages
            const messages = this.widget.querySelector(this.config.messagesSelector);
            if (messages) {
                const observer = new MutationObserver((mutations) => {
                    mutations.forEach(mutation => {
                        mutation.addedNodes.forEach(node => {
                            if (node.nodeType === 1 && node.matches(this.config.messageSelector)) {
                                this.setupMessageARIA(node, messages.children.length);
                            }
                        });
                    });
                });

                observer.observe(messages, { childList: true });
            }
        }

        /**
         * Announce message to screen readers.
         *
         * @param {Object} message Message data.
         */
        announceMessage(message) {
            const isUser = message.role === 'user';
            const label = isUser ? this.config.labels.userMessage : this.config.labels.botMessage;
            const content = message.content || '';
            
            // Truncate long messages for announcement
            const truncated = content.length > 200 ? content.substring(0, 200) + '...' : content;
            
            this.announce(`${label}: ${truncated}`);
        }

        /**
         * Announce text to screen readers.
         *
         * @param {string} text Text to announce.
         * @param {boolean} assertive Use assertive announcement.
         */
        announce(text, assertive = false) {
            const region = assertive ? this.assertiveRegion : this.liveRegion;
            if (!region) return;

            // Clear and set new content
            region.textContent = '';
            
            // Use setTimeout to ensure the change is detected
            setTimeout(() => {
                region.textContent = text;
            }, 100);
        }

        /**
         * Setup reduced motion preference.
         */
        setupReducedMotion() {
            const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
            
            const handleChange = (e) => {
                if (e.matches) {
                    this.widget.classList.add('wp-ai-chatbot-reduced-motion');
                } else {
                    this.widget.classList.remove('wp-ai-chatbot-reduced-motion');
                }
            };

            handleChange(prefersReducedMotion);
            prefersReducedMotion.addEventListener('change', handleChange);
        }

        /**
         * Toggle chat open/closed.
         */
        toggleChat() {
            const toggle = this.widget.querySelector(this.config.toggleSelector);
            if (toggle) {
                toggle.click();
            }
        }

        /**
         * Open chat.
         */
        openChat() {
            this.isOpen = true;
            this.lastFocusedElement = document.activeElement;

            const toggle = this.widget.querySelector(this.config.toggleSelector);
            const chatWindow = this.widget.querySelector(this.config.windowSelector);

            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
                toggle.setAttribute('aria-label', this.config.labels.toggleClose);
            }

            if (chatWindow) {
                chatWindow.setAttribute('aria-hidden', 'false');
            }

            // Focus input after opening
            setTimeout(() => {
                const input = this.widget.querySelector(this.config.inputSelector);
                if (input) {
                    input.focus();
                }
            }, 100);

            this.announce('Chat opened');
        }

        /**
         * Close chat.
         */
        closeChat() {
            this.isOpen = false;

            const toggle = this.widget.querySelector(this.config.toggleSelector);
            const chatWindow = this.widget.querySelector(this.config.windowSelector);

            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', this.config.labels.toggle);
            }

            if (chatWindow) {
                chatWindow.setAttribute('aria-hidden', 'true');
            }

            // Restore focus
            if (this.lastFocusedElement) {
                this.lastFocusedElement.focus();
            } else if (toggle) {
                toggle.focus();
            }

            this.announce('Chat closed');
        }

        /**
         * Focus last user message.
         */
        focusLastUserMessage() {
            const userMessages = this.widget.querySelectorAll('.wp-ai-chatbot-message--user');
            if (userMessages.length > 0) {
                userMessages[userMessages.length - 1].focus();
            }
        }

        /**
         * Setup quick reply accessibility.
         *
         * @param {HTMLElement} container Quick replies container.
         */
        setupQuickReplies(container) {
            const replies = container.querySelectorAll(this.config.quickReplySelector);
            
            replies.forEach((reply, index) => {
                reply.setAttribute('role', 'button');
                reply.setAttribute('tabindex', index === 0 ? '0' : '-1');
                reply.setAttribute('aria-label', `${this.config.labels.quickReply}: ${reply.textContent}`);
            });

            // Arrow key navigation
            container.addEventListener('keydown', (e) => {
                const items = Array.from(container.querySelectorAll(this.config.quickReplySelector));
                const currentIndex = items.indexOf(document.activeElement);

                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextIndex = (currentIndex + 1) % items.length;
                    items[currentIndex].setAttribute('tabindex', '-1');
                    items[nextIndex].setAttribute('tabindex', '0');
                    items[nextIndex].focus();
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prevIndex = (currentIndex - 1 + items.length) % items.length;
                    items[currentIndex].setAttribute('tabindex', '-1');
                    items[prevIndex].setAttribute('tabindex', '0');
                    items[prevIndex].focus();
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.target.click();
                }
            });
        }

        /**
         * Update feedback button state.
         *
         * @param {HTMLElement} button Feedback button.
         * @param {boolean} isActive Whether button is active.
         */
        updateFeedbackButton(button, isActive) {
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            
            const type = button.classList.contains('wp-ai-chatbot-message__feedback-btn--positive') 
                ? 'helpful' 
                : 'not helpful';
            
            this.announce(isActive ? `Marked as ${type}` : `Removed ${type} mark`);
        }

        /**
         * Skip to chat link for keyboard users.
         */
        createSkipLink() {
            const skipLink = document.createElement('a');
            skipLink.href = '#wp-ai-chatbot-leadgen-pro-widget';
            skipLink.className = 'wp-ai-chatbot-skip-link wp-ai-chatbot-sr-only';
            skipLink.textContent = 'Skip to chat assistant';
            skipLink.style.cssText = `
                position: fixed;
                top: -100px;
                left: 0;
                background: var(--wp-ai-chatbot-primary, #4f46e5);
                color: white;
                padding: 8px 16px;
                z-index: 1000000;
                text-decoration: none;
            `;

            skipLink.addEventListener('focus', () => {
                skipLink.style.top = '0';
            });

            skipLink.addEventListener('blur', () => {
                skipLink.style.top = '-100px';
            });

            skipLink.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleChat();
            });

            document.body.insertBefore(skipLink, document.body.firstChild);
        }

        /**
         * Get current state.
         *
         * @returns {Object} Current state.
         */
        getState() {
            return {
                isOpen: this.isOpen,
                focusableCount: this.focusableElements.length
            };
        }

        /**
         * Destroy accessibility features.
         */
        destroy() {
            if (this.liveRegion) {
                this.liveRegion.remove();
            }
            if (this.assertiveRegion) {
                this.assertiveRegion.remove();
            }
        }
    }

    // Export
    window.WPAIChatbotAccessibility = AccessibilityManager;

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        // Initialize accessibility manager
        const accessibility = new AccessibilityManager();

        // Listen for chat state changes
        document.addEventListener('wp_ai_chatbot_opened', () => {
            accessibility.openChat();
        });

        document.addEventListener('wp_ai_chatbot_closed', () => {
            accessibility.closeChat();
        });

        // Expose globally
        window.wpAiChatbotAccessibility = accessibility;
    });

})(jQuery);

