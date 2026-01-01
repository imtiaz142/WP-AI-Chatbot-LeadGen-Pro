/**
 * Behavior Tracker JavaScript
 *
 * Tracks user behavior including page views, scroll depth, time on page,
 * and chat interactions for lead scoring.
 *
 * @package WP_AI_Chatbot_LeadGen_Pro
 * @since   1.0.0
 */

(function($) {
	'use strict';

	/**
	 * BehaviorTracker class
	 */
	class BehaviorTracker {
		/**
		 * Constructor
		 * @param {Object} options Configuration options
		 */
		constructor(options = {}) {
			this.options = {
				ajaxUrl: window.wpAIChatbot?.ajaxUrl || '/wp-admin/admin-ajax.php',
				heartbeatInterval: 30000, // 30 seconds
				scrollThresholds: [25, 50, 75, 90, 100],
				trackPageViews: true,
				trackScrollDepth: true,
				trackTimeOnPage: true,
				trackClicks: true,
				...options
			};

			this.sessionId = this.getOrCreateSessionId();
			this.visitorId = this.getOrCreateVisitorId();
			this.pageLoadTime = Date.now();
			this.maxScrollDepth = 0;
			this.scrollDepthMilestones = new Set();
			this.heartbeatTimer = null;
			this.isPageVisible = true;
			this.totalActiveTime = 0;
			this.lastActiveTime = Date.now();

			this.init();
		}

		/**
		 * Initialize the tracker
		 */
		init() {
			// Track session start
			if (this.isNewSession()) {
				this.trackEvent('session_start');
			}

			// Track page view
			if (this.options.trackPageViews) {
				this.trackPageView();
			}

			// Set up scroll tracking
			if (this.options.trackScrollDepth) {
				this.initScrollTracking();
			}

			// Set up heartbeat for time tracking
			if (this.options.trackTimeOnPage) {
				this.initHeartbeat();
			}

			// Set up click tracking
			if (this.options.trackClicks) {
				this.initClickTracking();
			}

			// Set up visibility tracking
			this.initVisibilityTracking();

			// Set up unload tracking
			this.initUnloadTracking();

			// Listen for chat events
			this.initChatEventListeners();
		}

		/**
		 * Get or create session ID
		 * @returns {string}
		 */
		getOrCreateSessionId() {
			let sessionId = sessionStorage.getItem('wp_ai_chatbot_session_id');
			
			if (!sessionId) {
				sessionId = 'sess_' + this.generateId();
				sessionStorage.setItem('wp_ai_chatbot_session_id', sessionId);
			}

			return sessionId;
		}

		/**
		 * Get or create visitor ID (persistent across sessions)
		 * @returns {string}
		 */
		getOrCreateVisitorId() {
			let visitorId = localStorage.getItem('wp_ai_chatbot_visitor_id');
			
			if (!visitorId) {
				visitorId = 'vis_' + this.generateId();
				localStorage.setItem('wp_ai_chatbot_visitor_id', visitorId);
			}

			return visitorId;
		}

		/**
		 * Generate unique ID
		 * @returns {string}
		 */
		generateId() {
			return Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
		}

		/**
		 * Check if this is a new session
		 * @returns {boolean}
		 */
		isNewSession() {
			const lastActivity = sessionStorage.getItem('wp_ai_chatbot_last_activity');
			const sessionStarted = sessionStorage.getItem('wp_ai_chatbot_session_started');

			if (!sessionStarted) {
				sessionStorage.setItem('wp_ai_chatbot_session_started', 'true');
				return true;
			}

			// Consider new session if last activity was more than 30 minutes ago
			if (lastActivity) {
				const elapsed = Date.now() - parseInt(lastActivity);
				if (elapsed > 30 * 60 * 1000) {
					// Generate new session ID
					this.sessionId = 'sess_' + this.generateId();
					sessionStorage.setItem('wp_ai_chatbot_session_id', this.sessionId);
					return true;
				}
			}

			return false;
		}

		/**
		 * Update last activity timestamp
		 */
		updateLastActivity() {
			sessionStorage.setItem('wp_ai_chatbot_last_activity', Date.now().toString());
		}

		/**
		 * Track a page view
		 */
		trackPageView() {
			this.trackEvent('page_view', {
				page_url: window.location.href,
				page_title: document.title,
				referrer: document.referrer
			});
		}

		/**
		 * Initialize scroll tracking
		 */
		initScrollTracking() {
			let ticking = false;

			const handleScroll = () => {
				if (!ticking) {
					requestAnimationFrame(() => {
						this.updateScrollDepth();
						ticking = false;
					});
					ticking = true;
				}
			};

			window.addEventListener('scroll', handleScroll, { passive: true });
		}

		/**
		 * Update scroll depth tracking
		 */
		updateScrollDepth() {
			const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
			const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
			const scrollPercent = scrollHeight > 0 ? Math.round((scrollTop / scrollHeight) * 100) : 100;

			if (scrollPercent > this.maxScrollDepth) {
				this.maxScrollDepth = scrollPercent;

				// Check milestones
				for (const threshold of this.options.scrollThresholds) {
					if (scrollPercent >= threshold && !this.scrollDepthMilestones.has(threshold)) {
						this.scrollDepthMilestones.add(threshold);
						this.trackEvent('scroll_depth', {
							depth: threshold
						});
					}
				}
			}
		}

		/**
		 * Initialize heartbeat for time tracking
		 */
		initHeartbeat() {
			this.heartbeatTimer = setInterval(() => {
				if (this.isPageVisible) {
					this.sendHeartbeat();
				}
			}, this.options.heartbeatInterval);
		}

		/**
		 * Send heartbeat
		 */
		sendHeartbeat() {
			const now = Date.now();
			const activeTime = now - this.lastActiveTime;
			this.totalActiveTime += Math.min(activeTime, this.options.heartbeatInterval);
			this.lastActiveTime = now;

			this.trackSession('heartbeat');
			this.updateLastActivity();
		}

		/**
		 * Initialize visibility tracking
		 */
		initVisibilityTracking() {
			document.addEventListener('visibilitychange', () => {
				if (document.hidden) {
					this.isPageVisible = false;
					// Track time when page becomes hidden
					const now = Date.now();
					this.totalActiveTime += now - this.lastActiveTime;
				} else {
					this.isPageVisible = true;
					this.lastActiveTime = Date.now();
				}
			});
		}

		/**
		 * Initialize click tracking
		 */
		initClickTracking() {
			document.addEventListener('click', (e) => {
				const target = e.target.closest('a');
				
				if (target) {
					const href = target.getAttribute('href');
					const isExternal = target.hostname !== window.location.hostname;
					const isDownload = this.isDownloadLink(target);

					if (isDownload) {
						this.trackEvent('file_downloaded', {
							url: href,
							filename: this.getFilename(href)
						});
					} else if (isExternal) {
						this.trackEvent('link_clicked', {
							url: href,
							external: true
						});
					}
				}
			});
		}

		/**
		 * Check if link is a download
		 * @param {HTMLElement} link
		 * @returns {boolean}
		 */
		isDownloadLink(link) {
			if (link.hasAttribute('download')) return true;

			const href = link.getAttribute('href') || '';
			const downloadExtensions = ['.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.zip', '.rar', '.tar', '.gz'];
			
			return downloadExtensions.some(ext => href.toLowerCase().endsWith(ext));
		}

		/**
		 * Get filename from URL
		 * @param {string} url
		 * @returns {string}
		 */
		getFilename(url) {
			try {
				const pathname = new URL(url, window.location.origin).pathname;
				return pathname.split('/').pop() || 'unknown';
			} catch {
				return 'unknown';
			}
		}

		/**
		 * Initialize unload tracking
		 */
		initUnloadTracking() {
			window.addEventListener('beforeunload', () => {
				// Send final time on page
				this.trackEvent('time_on_page', {
					seconds: Math.round((Date.now() - this.pageLoadTime) / 1000)
				});

				// Send session end
				this.trackSession('end');
			});
		}

		/**
		 * Initialize chat event listeners
		 */
		initChatEventListeners() {
			// Chat opened
			$(document).on('wp_ai_chatbot_opened', () => {
				this.trackEvent('chat_open');
			});

			// Chat closed
			$(document).on('wp_ai_chatbot_closed', () => {
				this.trackEvent('chat_close');
			});

			// Message sent
			$(document).on('wp_ai_chatbot_message_sent', (e, message) => {
				this.trackEvent('message_sent', {
					message: message?.content || '',
					length: (message?.content || '').length
				});
			});

			// Message received
			$(document).on('wp_ai_chatbot_message_received', (e, message) => {
				this.trackEvent('message_received', {
					message_id: message?.id
				});
			});

			// Lead form shown
			$(document).on('wp_ai_chatbot_lead_form_shown', () => {
				this.trackEvent('form_started');
			});

			// Lead captured
			$(document).on('wp_ai_chatbot_lead_captured', (e, data) => {
				this.trackEvent('lead_captured', {
					lead_id: data?.lead_id
				});
				this.trackEvent('form_completed');
			});

			// Lead form dismissed
			$(document).on('wp_ai_chatbot_lead_form_dismissed', () => {
				this.trackEvent('form_abandoned');
			});

			// Feedback given
			$(document).on('wp_ai_chatbot_feedback_submitted', (e, data) => {
				this.trackEvent('feedback_given', {
					rating: data?.rating,
					message_id: data?.message_id
				});
			});

			// Exit intent
			$(document).on('wp_ai_chatbot_exit_intent', () => {
				this.trackEvent('exit_intent');
			});
		}

		/**
		 * Track an event
		 * @param {string} eventType Event type
		 * @param {Object} eventData Additional event data
		 */
		trackEvent(eventType, eventData = {}) {
			const data = {
				action: 'wp_ai_chatbot_track_event',
				session_id: this.sessionId,
				visitor_id: this.visitorId,
				event_type: eventType,
				event_data: JSON.stringify(eventData),
				page_url: window.location.href,
				page_title: document.title,
				...this.getContextData()
			};

			// Use sendBeacon for reliability on page unload
			if (eventType === 'time_on_page' && navigator.sendBeacon) {
				const formData = new FormData();
				Object.entries(data).forEach(([key, value]) => {
					formData.append(key, value);
				});
				navigator.sendBeacon(this.options.ajaxUrl, formData);
			} else {
				// Use fetch for normal events
				fetch(this.options.ajaxUrl, {
					method: 'POST',
					body: new URLSearchParams(data),
					credentials: 'same-origin',
					keepalive: true
				}).catch(() => {
					// Silent fail - tracking should not affect user experience
				});
			}
		}

		/**
		 * Track session action
		 * @param {string} action Session action (start, end, heartbeat)
		 */
		trackSession(action) {
			const data = {
				action: 'wp_ai_chatbot_track_session',
				session_id: this.sessionId,
				visitor_id: this.visitorId,
				session_action: action
			};

			if (action === 'end' && navigator.sendBeacon) {
				const formData = new FormData();
				Object.entries(data).forEach(([key, value]) => {
					formData.append(key, value);
				});
				navigator.sendBeacon(this.options.ajaxUrl, formData);
			} else {
				fetch(this.options.ajaxUrl, {
					method: 'POST',
					body: new URLSearchParams(data),
					credentials: 'same-origin',
					keepalive: true
				}).catch(() => {});
			}
		}

		/**
		 * Get context data for tracking
		 * @returns {Object}
		 */
		getContextData() {
			const urlParams = new URLSearchParams(window.location.search);
			
			return {
				referrer: document.referrer,
				utm_source: urlParams.get('utm_source') || '',
				utm_medium: urlParams.get('utm_medium') || '',
				utm_campaign: urlParams.get('utm_campaign') || '',
				device_type: this.getDeviceType(),
				browser: this.getBrowser(),
				os: this.getOS()
			};
		}

		/**
		 * Get device type
		 * @returns {string}
		 */
		getDeviceType() {
			const ua = navigator.userAgent;
			if (/tablet|ipad|playbook|silk/i.test(ua)) {
				return 'tablet';
			}
			if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) {
				return 'mobile';
			}
			return 'desktop';
		}

		/**
		 * Get browser name
		 * @returns {string}
		 */
		getBrowser() {
			const ua = navigator.userAgent;
			if (ua.indexOf('Firefox') > -1) return 'Firefox';
			if (ua.indexOf('SamsungBrowser') > -1) return 'Samsung Browser';
			if (ua.indexOf('Opera') > -1 || ua.indexOf('OPR') > -1) return 'Opera';
			if (ua.indexOf('Edge') > -1) return 'Edge';
			if (ua.indexOf('Chrome') > -1) return 'Chrome';
			if (ua.indexOf('Safari') > -1) return 'Safari';
			if (ua.indexOf('MSIE') > -1 || ua.indexOf('Trident') > -1) return 'Internet Explorer';
			return 'Unknown';
		}

		/**
		 * Get operating system
		 * @returns {string}
		 */
		getOS() {
			const ua = navigator.userAgent;
			if (ua.indexOf('Windows') > -1) return 'Windows';
			if (ua.indexOf('Mac') > -1) return 'macOS';
			if (ua.indexOf('Linux') > -1) return 'Linux';
			if (ua.indexOf('Android') > -1) return 'Android';
			if (ua.indexOf('iOS') > -1 || ua.indexOf('iPhone') > -1 || ua.indexOf('iPad') > -1) return 'iOS';
			return 'Unknown';
		}

		/**
		 * Get session ID
		 * @returns {string}
		 */
		getSessionId() {
			return this.sessionId;
		}

		/**
		 * Get visitor ID
		 * @returns {string}
		 */
		getVisitorId() {
			return this.visitorId;
		}

		/**
		 * Get current behavior metrics
		 * @returns {Object}
		 */
		getMetrics() {
			return {
				sessionId: this.sessionId,
				visitorId: this.visitorId,
				timeOnPage: Math.round((Date.now() - this.pageLoadTime) / 1000),
				maxScrollDepth: this.maxScrollDepth,
				scrollMilestones: Array.from(this.scrollDepthMilestones)
			};
		}

		/**
		 * Destroy the tracker
		 */
		destroy() {
			if (this.heartbeatTimer) {
				clearInterval(this.heartbeatTimer);
			}
		}
	}

	// Expose to window
	window.WPAIChatbotBehaviorTracker = BehaviorTracker;

	// Auto-initialize
	$(document).ready(function() {
		if (window.wpAIChatbot?.trackBehavior !== false) {
			window.wpAIChatbotTracker = new BehaviorTracker();
		}
	});

})(jQuery);

