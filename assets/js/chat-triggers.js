/**
 * Chat Triggers.
 *
 * Handles exit-intent detection, time-based triggers, scroll triggers,
 * and proactive message display for the chat widget.
 *
 * @package WP_AI_Chatbot_LeadGen_Pro
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Chat Triggers class.
     */
    class ChatTriggers {
        /**
         * Constructor.
         *
         * @param {Object} config Configuration options.
         */
        constructor(config = {}) {
            this.config = {
                // Exit intent settings
                exitIntentEnabled: true,
                exitIntentDelay: 5000, // ms before exit intent is active
                exitIntentSensitivity: 20, // pixels from top
                exitIntentCooldown: 86400000, // 24 hours in ms
                exitIntentMessage: 'Wait! Before you go, is there anything I can help you with?',

                // Time-based trigger settings
                timeTriggersEnabled: true,
                timeDelay: 30000, // ms (30 seconds default)
                timeMessage: 'Hi there! 👋 Need any help? I\'m here to answer your questions.',

                // Scroll trigger settings
                scrollTriggersEnabled: true,
                scrollPercentage: 50, // trigger at 50% scroll
                scrollMessage: 'Enjoying the content? Let me know if you have any questions!',

                // Inactivity trigger settings
                inactivityEnabled: true,
                inactivityDelay: 60000, // 1 minute
                inactivityMessage: 'Still there? Feel free to ask me anything!',

                // General settings
                maxTriggersPerSession: 3,
                triggerCooldown: 30000, // 30 seconds between triggers
                storageKey: 'wp_ai_chatbot_triggers',
                onTrigger: null, // Callback when trigger fires

                ...config
            };

            this.state = {
                triggersShown: 0,
                lastTriggerTime: 0,
                exitIntentShown: false,
                timeTriggered: false,
                scrollTriggered: false,
                inactivityTriggered: false,
                isActive: true,
                chatOpen: false,
                pageLoadTime: Date.now(),
                lastActivityTime: Date.now(),
                mouseY: 0,
                hasEngaged: false
            };

            this.timers = {
                time: null,
                inactivity: null,
                exitIntentDelay: null
            };

            this.init();
        }

        /**
         * Initialize triggers.
         */
        init() {
            this.loadState();
            this.bindEvents();
            this.startTimers();
        }

        /**
         * Load state from storage.
         */
        loadState() {
            try {
                const stored = localStorage.getItem(this.config.storageKey);
                if (stored) {
                    const data = JSON.parse(stored);
                    
                    // Check if it's a new session (page load time different)
                    if (data.sessionStart && Date.now() - data.sessionStart > 3600000) {
                        // More than 1 hour, reset session triggers
                        this.state.triggersShown = 0;
                    } else {
                        this.state.triggersShown = data.triggersShown || 0;
                    }
                    
                    // Check exit intent cooldown
                    if (data.exitIntentLastShown) {
                        const timeSinceExitIntent = Date.now() - data.exitIntentLastShown;
                        this.state.exitIntentShown = timeSinceExitIntent < this.config.exitIntentCooldown;
                    }
                }
            } catch (e) {
                console.warn('Failed to load trigger state:', e);
            }
        }

        /**
         * Save state to storage.
         */
        saveState() {
            try {
                const data = {
                    triggersShown: this.state.triggersShown,
                    sessionStart: this.state.pageLoadTime,
                    exitIntentLastShown: this.state.exitIntentShown ? Date.now() : null
                };
                localStorage.setItem(this.config.storageKey, JSON.stringify(data));
            } catch (e) {
                console.warn('Failed to save trigger state:', e);
            }
        }

        /**
         * Bind event listeners.
         */
        bindEvents() {
            // Exit intent detection
            if (this.config.exitIntentEnabled) {
                // Delay exit intent activation
                this.timers.exitIntentDelay = setTimeout(() => {
                    document.addEventListener('mouseleave', this.handleMouseLeave.bind(this));
                    document.addEventListener('mousemove', this.handleMouseMove.bind(this));
                }, this.config.exitIntentDelay);
            }

            // Scroll detection
            if (this.config.scrollTriggersEnabled) {
                window.addEventListener('scroll', this.handleScroll.bind(this), { passive: true });
            }

            // Activity tracking for inactivity trigger
            if (this.config.inactivityEnabled) {
                const activityEvents = ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'];
                activityEvents.forEach(event => {
                    document.addEventListener(event, this.handleActivity.bind(this), { passive: true });
                });
            }

            // Track chat widget state
            document.addEventListener('wp_ai_chatbot_opened', () => {
                this.state.chatOpen = true;
                this.state.hasEngaged = true;
                this.clearTimers();
            });

            document.addEventListener('wp_ai_chatbot_closed', () => {
                this.state.chatOpen = false;
            });

            document.addEventListener('wp_ai_chatbot_message_sent', () => {
                this.state.hasEngaged = true;
            });

            // Visibility change
            document.addEventListener('visibilitychange', this.handleVisibilityChange.bind(this));

            // Before unload (for mobile)
            window.addEventListener('beforeunload', this.handleBeforeUnload.bind(this));
        }

        /**
         * Start time-based timers.
         */
        startTimers() {
            // Time-based trigger
            if (this.config.timeTriggersEnabled && !this.state.timeTriggered) {
                this.timers.time = setTimeout(() => {
                    this.fireTrigger('time', this.config.timeMessage);
                }, this.config.timeDelay);
            }

            // Inactivity trigger
            this.resetInactivityTimer();
        }

        /**
         * Clear all timers.
         */
        clearTimers() {
            if (this.timers.time) {
                clearTimeout(this.timers.time);
                this.timers.time = null;
            }
            if (this.timers.inactivity) {
                clearTimeout(this.timers.inactivity);
                this.timers.inactivity = null;
            }
            if (this.timers.exitIntentDelay) {
                clearTimeout(this.timers.exitIntentDelay);
                this.timers.exitIntentDelay = null;
            }
        }

        /**
         * Reset inactivity timer.
         */
        resetInactivityTimer() {
            if (this.timers.inactivity) {
                clearTimeout(this.timers.inactivity);
            }

            if (this.config.inactivityEnabled && !this.state.inactivityTriggered && !this.state.chatOpen) {
                this.timers.inactivity = setTimeout(() => {
                    this.fireTrigger('inactivity', this.config.inactivityMessage);
                }, this.config.inactivityDelay);
            }
        }

        /**
         * Handle mouse leave event for exit intent.
         *
         * @param {MouseEvent} e Mouse event.
         */
        handleMouseLeave(e) {
            // Check if mouse is leaving through the top of the viewport
            if (e.clientY <= this.config.exitIntentSensitivity && 
                !this.state.exitIntentShown && 
                !this.state.chatOpen &&
                this.state.isActive) {
                
                this.fireTrigger('exit_intent', this.config.exitIntentMessage);
                this.state.exitIntentShown = true;
                this.saveState();
            }
        }

        /**
         * Handle mouse move event.
         *
         * @param {MouseEvent} e Mouse event.
         */
        handleMouseMove(e) {
            this.state.mouseY = e.clientY;
        }

        /**
         * Handle scroll event.
         */
        handleScroll() {
            if (this.state.scrollTriggered || this.state.chatOpen) {
                return;
            }

            const scrollPercent = this.getScrollPercentage();
            
            if (scrollPercent >= this.config.scrollPercentage) {
                this.fireTrigger('scroll', this.config.scrollMessage);
                this.state.scrollTriggered = true;
            }
        }

        /**
         * Get current scroll percentage.
         *
         * @returns {number} Scroll percentage (0-100).
         */
        getScrollPercentage() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const scrollHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            
            if (scrollHeight <= 0) {
                return 100;
            }
            
            return Math.round((scrollTop / scrollHeight) * 100);
        }

        /**
         * Handle user activity.
         */
        handleActivity() {
            this.state.lastActivityTime = Date.now();
            this.resetInactivityTimer();
        }

        /**
         * Handle visibility change.
         */
        handleVisibilityChange() {
            if (document.hidden) {
                this.state.isActive = false;
                this.clearTimers();
            } else {
                this.state.isActive = true;
                this.startTimers();
            }
        }

        /**
         * Handle before unload (for mobile exit intent).
         */
        handleBeforeUnload() {
            // Could show exit intent on mobile, but browsers restrict this
            // Instead, we can track the exit for analytics
            this.trackEvent('page_exit', {
                engaged: this.state.hasEngaged,
                time_on_page: Date.now() - this.state.pageLoadTime
            });
        }

        /**
         * Fire a trigger.
         *
         * @param {string} type Trigger type.
         * @param {string} message Message to display.
         * @param {Object} data Additional data.
         */
        fireTrigger(type, message, data = {}) {
            // Check if we can fire a trigger
            if (!this.canFireTrigger()) {
                return;
            }

            // Update state
            this.state.triggersShown++;
            this.state.lastTriggerTime = Date.now();

            // Mark specific trigger as fired
            switch (type) {
                case 'time':
                    this.state.timeTriggered = true;
                    break;
                case 'scroll':
                    this.state.scrollTriggered = true;
                    break;
                case 'inactivity':
                    this.state.inactivityTriggered = true;
                    break;
                case 'exit_intent':
                    this.state.exitIntentShown = true;
                    break;
            }

            this.saveState();

            // Fire callback
            if (typeof this.config.onTrigger === 'function') {
                this.config.onTrigger(type, message, data);
            }

            // Dispatch custom event
            const event = new CustomEvent('wp_ai_chatbot_trigger', {
                detail: { type, message, data }
            });
            document.dispatchEvent(event);

            // Track trigger
            this.trackEvent('trigger_fired', { type, message });

            console.log(`[ChatTriggers] Fired trigger: ${type}`);
        }

        /**
         * Check if we can fire a trigger.
         *
         * @returns {boolean} True if trigger can fire.
         */
        canFireTrigger() {
            // Check if chat is already open
            if (this.state.chatOpen) {
                return false;
            }

            // Check if user has already engaged
            if (this.state.hasEngaged) {
                return false;
            }

            // Check max triggers per session
            if (this.state.triggersShown >= this.config.maxTriggersPerSession) {
                return false;
            }

            // Check cooldown between triggers
            const timeSinceLastTrigger = Date.now() - this.state.lastTriggerTime;
            if (timeSinceLastTrigger < this.config.triggerCooldown) {
                return false;
            }

            // Check if page is active
            if (!this.state.isActive) {
                return false;
            }

            return true;
        }

        /**
         * Track an event.
         *
         * @param {string} eventName Event name.
         * @param {Object} data Event data.
         */
        trackEvent(eventName, data = {}) {
            // Dispatch for analytics
            const event = new CustomEvent('wp_ai_chatbot_analytics', {
                detail: { event: eventName, data }
            });
            document.dispatchEvent(event);
        }

        /**
         * Manually show a proactive message.
         *
         * @param {string} message Message to display.
         * @param {string} type Trigger type.
         */
        showProactiveMessage(message, type = 'manual') {
            this.fireTrigger(type, message);
        }

        /**
         * Set chat widget state.
         *
         * @param {boolean} isOpen Whether chat is open.
         */
        setChatOpen(isOpen) {
            this.state.chatOpen = isOpen;
            if (isOpen) {
                this.state.hasEngaged = true;
            }
        }

        /**
         * Reset triggers.
         */
        reset() {
            this.state = {
                triggersShown: 0,
                lastTriggerTime: 0,
                exitIntentShown: false,
                timeTriggered: false,
                scrollTriggered: false,
                inactivityTriggered: false,
                isActive: true,
                chatOpen: false,
                pageLoadTime: Date.now(),
                lastActivityTime: Date.now(),
                mouseY: 0,
                hasEngaged: false
            };
            
            this.clearTimers();
            this.startTimers();
            this.saveState();
        }

        /**
         * Update configuration.
         *
         * @param {Object} newConfig New configuration.
         */
        updateConfig(newConfig) {
            this.config = { ...this.config, ...newConfig };
            this.reset();
        }

        /**
         * Destroy triggers.
         */
        destroy() {
            this.clearTimers();
            document.removeEventListener('mouseleave', this.handleMouseLeave);
            document.removeEventListener('mousemove', this.handleMouseMove);
            window.removeEventListener('scroll', this.handleScroll);
            document.removeEventListener('visibilitychange', this.handleVisibilityChange);
        }
    }

    /**
     * Proactive Message Display component.
     */
    class ProactiveMessage {
        /**
         * Constructor.
         *
         * @param {Object} config Configuration options.
         */
        constructor(config = {}) {
            this.config = {
                containerSelector: '#wp-ai-chatbot-leadgen-pro-widget',
                messageClass: 'wp-ai-chatbot-proactive-message',
                animationDuration: 300,
                autoDismissDelay: 10000, // 10 seconds
                onDismiss: null,
                onClick: null,
                ...config
            };

            this.element = null;
            this.dismissTimer = null;
        }

        /**
         * Show proactive message.
         *
         * @param {string} message Message to display.
         * @param {Object} options Display options.
         */
        show(message, options = {}) {
            // Remove existing message
            this.hide();

            const container = document.querySelector(this.config.containerSelector);
            if (!container) {
                console.warn('Chat container not found');
                return;
            }

            // Create message element
            this.element = document.createElement('div');
            this.element.className = this.config.messageClass;
            this.element.innerHTML = `
                <div class="${this.config.messageClass}__content">
                    <div class="${this.config.messageClass}__avatar">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                    </div>
                    <div class="${this.config.messageClass}__text">${this.escapeHtml(message)}</div>
                    <button class="${this.config.messageClass}__close" aria-label="Close">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                </div>
            `;

            // Add event listeners
            this.element.querySelector(`.${this.config.messageClass}__text`).addEventListener('click', () => {
                this.handleClick();
            });

            this.element.querySelector(`.${this.config.messageClass}__close`).addEventListener('click', (e) => {
                e.stopPropagation();
                this.hide();
            });

            // Insert before toggle button
            const toggle = container.querySelector('.wp-ai-chatbot-toggle');
            if (toggle) {
                container.insertBefore(this.element, toggle);
            } else {
                container.appendChild(this.element);
            }

            // Animate in
            requestAnimationFrame(() => {
                this.element.classList.add(`${this.config.messageClass}--visible`);
            });

            // Auto dismiss
            if (this.config.autoDismissDelay > 0) {
                this.dismissTimer = setTimeout(() => {
                    this.hide();
                }, this.config.autoDismissDelay);
            }
        }

        /**
         * Hide proactive message.
         */
        hide() {
            if (this.dismissTimer) {
                clearTimeout(this.dismissTimer);
                this.dismissTimer = null;
            }

            if (!this.element) {
                return;
            }

            this.element.classList.remove(`${this.config.messageClass}--visible`);

            setTimeout(() => {
                if (this.element && this.element.parentNode) {
                    this.element.parentNode.removeChild(this.element);
                }
                this.element = null;

                if (typeof this.config.onDismiss === 'function') {
                    this.config.onDismiss();
                }
            }, this.config.animationDuration);
        }

        /**
         * Handle click on message.
         */
        handleClick() {
            if (typeof this.config.onClick === 'function') {
                this.config.onClick();
            }
            this.hide();
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
            return div.innerHTML;
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        // Get configuration from PHP
        const triggerConfig = window.wpAiChatbotTriggerConfig || {};

        // Initialize proactive message component
        const proactiveMessage = new ProactiveMessage({
            onClick: function() {
                // Open chat widget
                const event = new CustomEvent('wp_ai_chatbot_open_request');
                document.dispatchEvent(event);
            }
        });

        // Initialize triggers with callback
        const triggers = new ChatTriggers({
            ...triggerConfig,
            onTrigger: function(type, message, data) {
                proactiveMessage.show(message, { type });
            }
        });

        // Expose globally for external access
        window.wpAiChatbotTriggers = triggers;
        window.wpAiChatbotProactiveMessage = proactiveMessage;

        // Listen for manual trigger requests
        document.addEventListener('wp_ai_chatbot_show_proactive', function(e) {
            const { message, type } = e.detail || {};
            if (message) {
                triggers.showProactiveMessage(message, type);
            }
        });
    });

})(jQuery);

