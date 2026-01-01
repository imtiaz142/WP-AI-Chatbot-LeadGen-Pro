/**
 * Real-time Lead Score JavaScript
 *
 * Handles real-time score updates and displays for the chat widget.
 *
 * @package WP_AI_Chatbot_LeadGen_Pro
 * @since   1.0.0
 */

(function($) {
	'use strict';

	/**
	 * RealtimeScore class
	 */
	class RealtimeScore {
		/**
		 * Constructor
		 * @param {Object} options Configuration options
		 */
		constructor(options = {}) {
			this.options = {
				ajaxUrl: window.wpAIChatbot?.ajaxUrl || '/wp-admin/admin-ajax.php',
				pollInterval: 30000, // 30 seconds
				enablePolling: true,
				showScoreIndicator: false, // Only for admin/debug
				...options
			};

			this.state = {
				sessionId: this.getSessionId(),
				leadId: null,
				currentScore: 0,
				currentGrade: null,
				isPolling: false,
				lastUpdate: null
			};

			this.pollTimer = null;
			this.callbacks = {
				onScoreUpdate: [],
				onGradeChange: [],
				onHotLead: []
			};

			this.init();
		}

		/**
		 * Initialize
		 */
		init() {
			this.bindEvents();
			
			// Initial score fetch
			this.fetchScore();

			// Start polling if enabled
			if (this.options.enablePolling) {
				this.startPolling();
			}
		}

		/**
		 * Get session ID
		 * @returns {string}
		 */
		getSessionId() {
			return sessionStorage.getItem('wp_ai_chatbot_session_id') || '';
		}

		/**
		 * Bind events
		 */
		bindEvents() {
			// Listen for lead capture
			$(document).on('wp_ai_chatbot_lead_captured', (e, data) => {
				if (data?.lead_id) {
					this.state.leadId = data.lead_id;
					this.fetchScore(true); // Force refresh
				}
			});

			// Listen for high-value events
			$(document).on('wp_ai_chatbot_message_sent', () => {
				this.scheduleScoreUpdate(5000); // Update 5 seconds after message
			});

			$(document).on('wp_ai_chatbot_meeting_booked', () => {
				this.fetchScore(true); // Immediate update
			});

			// Visibility change - pause/resume polling
			document.addEventListener('visibilitychange', () => {
				if (document.hidden) {
					this.stopPolling();
				} else if (this.options.enablePolling) {
					this.startPolling();
				}
			});
		}

		/**
		 * Fetch current score
		 * @param {boolean} force Force fresh calculation
		 * @returns {Promise}
		 */
		async fetchScore(force = false) {
			try {
				const formData = new FormData();
				formData.append('action', 'wp_ai_chatbot_get_realtime_score');
				formData.append('session_id', this.state.sessionId);
				
				if (this.state.leadId) {
					formData.append('lead_id', this.state.leadId);
				}

				const response = await fetch(this.options.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				});

				const result = await response.json();

				if (result.success) {
					this.handleScoreUpdate(result.data);
				}

				return result.data;
			} catch (error) {
				console.error('RealtimeScore: Error fetching score', error);
				return null;
			}
		}

		/**
		 * Handle score update
		 * @param {Object} data Score data
		 */
		handleScoreUpdate(data) {
			const previousScore = this.state.currentScore;
			const previousGrade = this.state.currentGrade;

			// Update state
			this.state.currentScore = data.score?.score || data.score || 0;
			this.state.currentGrade = data.grade?.grade || data.grade || null;
			this.state.lastUpdate = new Date();

			if (data.lead_exists !== undefined) {
				this.state.leadId = data.lead_exists ? this.state.leadId : null;
			}

			// Check for significant changes
			const scoreChanged = this.state.currentScore !== previousScore;
			const gradeChanged = this.state.currentGrade !== previousGrade;

			// Trigger callbacks
			if (scoreChanged) {
				this.triggerCallbacks('onScoreUpdate', {
					previousScore,
					currentScore: this.state.currentScore,
					change: this.state.currentScore - previousScore
				});
			}

			if (gradeChanged && previousGrade) {
				this.triggerCallbacks('onGradeChange', {
					previousGrade,
					currentGrade: this.state.currentGrade
				});
			}

			// Check for hot lead
			if (this.state.currentGrade === 'A+' && previousGrade !== 'A+') {
				this.triggerCallbacks('onHotLead', {
					score: this.state.currentScore,
					grade: this.state.currentGrade
				});
			}

			// Update UI if indicator is enabled
			if (this.options.showScoreIndicator) {
				this.updateScoreIndicator();
			}

			// Emit event
			$(document).trigger('wp_ai_chatbot_score_updated', [{
				score: this.state.currentScore,
				grade: this.state.currentGrade,
				changed: scoreChanged || gradeChanged
			}]);
		}

		/**
		 * Schedule a score update
		 * @param {number} delay Delay in milliseconds
		 */
		scheduleScoreUpdate(delay = 5000) {
			setTimeout(() => {
				this.fetchScore();
			}, delay);
		}

		/**
		 * Start polling for updates
		 */
		startPolling() {
			if (this.state.isPolling) return;

			this.state.isPolling = true;
			this.pollTimer = setInterval(() => {
				this.checkForUpdates();
			}, this.options.pollInterval);
		}

		/**
		 * Stop polling
		 */
		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer);
				this.pollTimer = null;
			}
			this.state.isPolling = false;
		}

		/**
		 * Check for score updates (lightweight)
		 */
		async checkForUpdates() {
			try {
				const formData = new FormData();
				formData.append('action', 'wp_ai_chatbot_subscribe_score_updates');
				formData.append('session_id', this.state.sessionId);
				formData.append('last_score', this.state.currentScore);

				const response = await fetch(this.options.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				});

				const result = await response.json();

				if (result.success && result.data?.updated) {
					this.handleScoreUpdate(result.data);
				}
			} catch (error) {
				// Silent fail - polling should not affect user experience
			}
		}

		/**
		 * Update score indicator UI
		 */
		updateScoreIndicator() {
			let indicator = document.querySelector('.wp-ai-chatbot-score-indicator');

			if (!indicator) {
				indicator = this.createScoreIndicator();
			}

			const scoreEl = indicator.querySelector('.score-value');
			const gradeEl = indicator.querySelector('.grade-badge');

			if (scoreEl) {
				scoreEl.textContent = this.state.currentScore;
				scoreEl.style.setProperty('--score', this.state.currentScore);
			}

			if (gradeEl && this.state.currentGrade) {
				gradeEl.textContent = this.state.currentGrade;
				gradeEl.className = 'grade-badge grade-' + this.state.currentGrade.toLowerCase().replace('+', 'plus');
			}
		}

		/**
		 * Create score indicator element
		 * @returns {HTMLElement}
		 */
		createScoreIndicator() {
			const indicator = document.createElement('div');
			indicator.className = 'wp-ai-chatbot-score-indicator';
			indicator.innerHTML = `
				<div class="score-circle">
					<svg viewBox="0 0 36 36">
						<circle class="score-bg" cx="18" cy="18" r="16"/>
						<circle class="score-fill" cx="18" cy="18" r="16" 
							stroke-dasharray="100, 100"
							style="--score: ${this.state.currentScore}"/>
					</svg>
					<span class="score-value">${this.state.currentScore}</span>
				</div>
				<span class="grade-badge grade-${(this.state.currentGrade || 'c').toLowerCase()}">${this.state.currentGrade || '-'}</span>
			`;

			// Add to chat widget if exists
			const widget = document.querySelector('.wp-ai-chatbot-widget');
			if (widget) {
				widget.appendChild(indicator);
			}

			return indicator;
		}

		/**
		 * Register callback
		 * @param {string} event Event name
		 * @param {Function} callback Callback function
		 */
		on(event, callback) {
			if (this.callbacks[event]) {
				this.callbacks[event].push(callback);
			}
		}

		/**
		 * Trigger callbacks
		 * @param {string} event Event name
		 * @param {Object} data Event data
		 */
		triggerCallbacks(event, data) {
			if (this.callbacks[event]) {
				this.callbacks[event].forEach(callback => {
					try {
						callback(data);
					} catch (error) {
						console.error('RealtimeScore: Callback error', error);
					}
				});
			}
		}

		/**
		 * Get current state
		 * @returns {Object}
		 */
		getState() {
			return { ...this.state };
		}

		/**
		 * Get score
		 * @returns {number}
		 */
		getScore() {
			return this.state.currentScore;
		}

		/**
		 * Get grade
		 * @returns {string|null}
		 */
		getGrade() {
			return this.state.currentGrade;
		}

		/**
		 * Destroy instance
		 */
		destroy() {
			this.stopPolling();
			const indicator = document.querySelector('.wp-ai-chatbot-score-indicator');
			if (indicator) {
				indicator.remove();
			}
		}
	}

	/**
	 * Admin Score Display
	 * For displaying scores in the admin dashboard
	 */
	class AdminScoreDisplay {
		constructor(container, leadId) {
			this.container = typeof container === 'string' ? document.querySelector(container) : container;
			this.leadId = leadId;
			this.ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';

			if (this.container) {
				this.init();
			}
		}

		async init() {
			await this.fetchAndRender();
		}

		async fetchAndRender() {
			try {
				const formData = new FormData();
				formData.append('action', 'wp_ai_chatbot_get_lead_score');
				formData.append('lead_id', this.leadId);

				const response = await fetch(this.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				});

				const result = await response.json();

				if (result.success) {
					this.render(result.data);
				}
			} catch (error) {
				console.error('AdminScoreDisplay: Error fetching score', error);
			}
		}

		render(scoreData) {
			const grade = scoreData.grade || {};
			const scores = scoreData.scores || {};

			this.container.innerHTML = `
				<div class="score-overview">
					<div class="score-main">
						<div class="score-circle large" style="--score: ${scoreData.composite_score}; --color: ${grade.color || '#6b7280'}">
							<span class="score-value">${scoreData.composite_score}</span>
						</div>
						<div class="grade-info">
							<span class="grade-badge" style="background: ${grade.color || '#6b7280'}">${grade.letter || 'F'}</span>
							<span class="grade-label">${grade.label || 'Unknown'}</span>
						</div>
					</div>
					<div class="score-breakdown">
						<div class="score-component">
							<span class="component-label">Behavioral</span>
							<div class="component-bar">
								<div class="component-fill" style="width: ${scores.behavioral?.score || 0}%"></div>
							</div>
							<span class="component-value">${scores.behavioral?.score || 0}</span>
						</div>
						<div class="score-component">
							<span class="component-label">Intent</span>
							<div class="component-bar">
								<div class="component-fill" style="width: ${scores.intent?.score || 0}%"></div>
							</div>
							<span class="component-value">${scores.intent?.score || 0}</span>
						</div>
						<div class="score-component">
							<span class="component-label">Qualification</span>
							<div class="component-bar">
								<div class="component-fill" style="width: ${scores.qualification?.score || 0}%"></div>
							</div>
							<span class="component-value">${scores.qualification?.score || 0}</span>
						</div>
					</div>
					${this.renderSignals(scoreData.signals)}
					${this.renderRecommendations(scoreData.recommendations)}
				</div>
			`;
		}

		renderSignals(signals) {
			if (!signals) return '';

			const highValue = signals.high_value || [];
			const positive = signals.positive || [];
			const negative = signals.negative || [];

			if (highValue.length === 0 && positive.length === 0 && negative.length === 0) {
				return '';
			}

			let html = '<div class="score-signals"><h4>Signals</h4><div class="signals-list">';

			highValue.forEach(s => {
				html += `<span class="signal signal-high">${s.label}</span>`;
			});

			positive.forEach(s => {
				html += `<span class="signal signal-positive">${s.label}</span>`;
			});

			negative.forEach(s => {
				html += `<span class="signal signal-negative">${s.label}</span>`;
			});

			html += '</div></div>';
			return html;
		}

		renderRecommendations(recommendations) {
			if (!recommendations || recommendations.length === 0) return '';

			let html = '<div class="score-recommendations"><h4>Recommendations</h4><ul>';

			recommendations.forEach(rec => {
				html += `<li class="rec-${rec.priority}">${rec.message}</li>`;
			});

			html += '</ul></div>';
			return html;
		}

		async rescore() {
			const formData = new FormData();
			formData.append('action', 'wp_ai_chatbot_rescore_lead');
			formData.append('lead_id', this.leadId);

			const response = await fetch(this.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			});

			const result = await response.json();

			if (result.success) {
				this.render(result.data.score);
			}

			return result;
		}
	}

	// Expose to window
	window.WPAIChatbotRealtimeScore = RealtimeScore;
	window.WPAIChatbotAdminScoreDisplay = AdminScoreDisplay;

	// Auto-initialize for frontend
	$(document).on('wp_ai_chatbot_ready', function() {
		if (window.wpAIChatbot?.enableRealtimeScoring !== false) {
			window.wpAIChatbotScore = new RealtimeScore({
				showScoreIndicator: window.wpAIChatbot?.showScoreIndicator || false
			});
		}
	});

})(jQuery);

