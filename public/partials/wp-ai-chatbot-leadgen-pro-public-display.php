<?php
/**
 * Chat Widget HTML Structure.
 *
 * This file contains the HTML structure for the chat widget.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/public/partials
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div id="wp-ai-chatbot-widget" class="wp-ai-chatbot-widget" role="dialog" aria-label="<?php esc_attr_e( 'AI Chatbot', 'wp-ai-chatbot-leadgen-pro' ); ?>" aria-hidden="true">
	<!-- Chat Button/Toggle -->
	<button 
		id="wp-ai-chatbot-toggle" 
		class="wp-ai-chatbot-toggle" 
		aria-label="<?php esc_attr_e( 'Open chat', 'wp-ai-chatbot-leadgen-pro' ); ?>"
		aria-expanded="false"
		aria-controls="wp-ai-chatbot-container"
	>
		<span class="wp-ai-chatbot-toggle-icon" aria-hidden="true">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
		</span>
		<span class="wp-ai-chatbot-toggle-text"><?php esc_html_e( 'Chat', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
		<span class="wp-ai-chatbot-notification-badge" aria-hidden="true" style="display: none;">0</span>
	</button>

	<!-- Chat Container -->
	<div id="wp-ai-chatbot-container" class="wp-ai-chatbot-container" role="region" aria-label="<?php esc_attr_e( 'Chat conversation', 'wp-ai-chatbot-leadgen-pro' ); ?>">
		<!-- Chat Header -->
		<header class="wp-ai-chatbot-header" role="banner">
			<div class="wp-ai-chatbot-header-content">
				<div class="wp-ai-chatbot-header-info">
					<h2 class="wp-ai-chatbot-title" id="wp-ai-chatbot-title">
						<?php esc_html_e( 'Chat with us', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</h2>
					<p class="wp-ai-chatbot-subtitle" id="wp-ai-chatbot-subtitle">
						<?php esc_html_e( 'We\'re here to help!', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</p>
				</div>
				<button 
					class="wp-ai-chatbot-close" 
					aria-label="<?php esc_attr_e( 'Close chat', 'wp-ai-chatbot-leadgen-pro' ); ?>"
					aria-controls="wp-ai-chatbot-container"
				>
					<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M15 5L5 15M5 5L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>
			</div>
		</header>

		<!-- Chat Messages Area -->
		<div 
			id="wp-ai-chatbot-messages" 
			class="wp-ai-chatbot-messages" 
			role="log" 
			aria-live="polite" 
			aria-atomic="false"
			aria-label="<?php esc_attr_e( 'Chat messages', 'wp-ai-chatbot-leadgen-pro' ); ?>"
		>
			<!-- Welcome Message -->
			<div class="wp-ai-chatbot-welcome" id="wp-ai-chatbot-welcome">
				<div class="wp-ai-chatbot-welcome-content">
					<p class="wp-ai-chatbot-welcome-text">
						<?php esc_html_e( 'Hello! How can I help you today?', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</p>
					<div class="wp-ai-chatbot-quick-questions" role="group" aria-label="<?php esc_attr_e( 'Quick start questions', 'wp-ai-chatbot-leadgen-pro' ); ?>" style="display: none;">
						<!-- Quick questions will be dynamically inserted here -->
					</div>
				</div>
			</div>

			<!-- Messages will be dynamically inserted here -->
		</div>

		<!-- Typing Indicator -->
		<div 
			id="wp-ai-chatbot-typing" 
			class="wp-ai-chatbot-typing" 
			role="status" 
			aria-live="polite" 
			aria-label="<?php esc_attr_e( 'AI is typing', 'wp-ai-chatbot-leadgen-pro' ); ?>"
			style="display: none;"
		>
			<div class="wp-ai-chatbot-typing-indicator">
				<span></span>
				<span></span>
				<span></span>
			</div>
			<span class="wp-ai-chatbot-typing-text"><?php esc_html_e( 'AI is typing...', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
		</div>

		<!-- Chat Input Area -->
		<div class="wp-ai-chatbot-input-area" role="region" aria-label="<?php esc_attr_e( 'Message input', 'wp-ai-chatbot-leadgen-pro' ); ?>">
			<form id="wp-ai-chatbot-form" class="wp-ai-chatbot-form" role="form" aria-label="<?php esc_attr_e( 'Send message', 'wp-ai-chatbot-leadgen-pro' ); ?>">
				<div class="wp-ai-chatbot-input-wrapper">
					<label for="wp-ai-chatbot-input" class="screen-reader-text">
						<?php esc_html_e( 'Type your message', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</label>
					<textarea 
						id="wp-ai-chatbot-input" 
						class="wp-ai-chatbot-input" 
						rows="1"
						placeholder="<?php esc_attr_e( 'Type your message...', 'wp-ai-chatbot-leadgen-pro' ); ?>"
						aria-label="<?php esc_attr_e( 'Type your message', 'wp-ai-chatbot-leadgen-pro' ); ?>"
						aria-required="true"
						maxlength="2000"
					></textarea>
					<button 
						type="submit" 
						id="wp-ai-chatbot-send" 
						class="wp-ai-chatbot-send" 
						aria-label="<?php esc_attr_e( 'Send message', 'wp-ai-chatbot-leadgen-pro' ); ?>"
						disabled
					>
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M18 2L9 11M18 2L12 18L9 11M18 2L2 8L9 11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Send', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
					</button>
				</div>
				<div class="wp-ai-chatbot-input-footer">
					<p class="wp-ai-chatbot-char-count" aria-live="polite" aria-atomic="true">
						<span class="wp-ai-chatbot-char-count-current">0</span> / <span class="wp-ai-chatbot-char-count-max">2000</span>
					</p>
					<p class="wp-ai-chatbot-powered-by">
						<?php esc_html_e( 'Powered by AI', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</p>
				</div>
			</form>
		</div>

		<!-- Lead Capture Form (shown conditionally) -->
		<div 
			id="wp-ai-chatbot-lead-capture" 
			class="wp-ai-chatbot-lead-capture" 
			role="dialog" 
			aria-label="<?php esc_attr_e( 'Contact information', 'wp-ai-chatbot-leadgen-pro' ); ?>"
			aria-hidden="true"
			style="display: none;"
		>
			<div class="wp-ai-chatbot-lead-capture-content">
				<h3 class="wp-ai-chatbot-lead-capture-title">
					<?php esc_html_e( 'Get in touch', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</h3>
				<p class="wp-ai-chatbot-lead-capture-description">
					<?php esc_html_e( 'Please provide your contact information to continue.', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</p>
				<form id="wp-ai-chatbot-lead-form" class="wp-ai-chatbot-lead-form" role="form">
					<div class="wp-ai-chatbot-form-field">
						<label for="wp-ai-chatbot-lead-name">
							<?php esc_html_e( 'Name', 'wp-ai-chatbot-leadgen-pro' ); ?>
							<span class="required" aria-label="<?php esc_attr_e( 'required', 'wp-ai-chatbot-leadgen-pro' ); ?>">*</span>
						</label>
						<input 
							type="text" 
							id="wp-ai-chatbot-lead-name" 
							name="name" 
							required
							aria-required="true"
							aria-label="<?php esc_attr_e( 'Your name', 'wp-ai-chatbot-leadgen-pro' ); ?>"
						>
					</div>
					<div class="wp-ai-chatbot-form-field">
						<label for="wp-ai-chatbot-lead-email">
							<?php esc_html_e( 'Email', 'wp-ai-chatbot-leadgen-pro' ); ?>
							<span class="required" aria-label="<?php esc_attr_e( 'required', 'wp-ai-chatbot-leadgen-pro' ); ?>">*</span>
						</label>
						<input 
							type="email" 
							id="wp-ai-chatbot-lead-email" 
							name="email" 
							required
							aria-required="true"
							aria-label="<?php esc_attr_e( 'Your email address', 'wp-ai-chatbot-leadgen-pro' ); ?>"
						>
					</div>
					<div class="wp-ai-chatbot-form-field">
						<label for="wp-ai-chatbot-lead-phone">
							<?php esc_html_e( 'Phone', 'wp-ai-chatbot-leadgen-pro' ); ?>
							<span class="optional"><?php esc_html_e( '(optional)', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
						</label>
						<input 
							type="tel" 
							id="wp-ai-chatbot-lead-phone" 
							name="phone"
							aria-label="<?php esc_attr_e( 'Your phone number', 'wp-ai-chatbot-leadgen-pro' ); ?>"
						>
					</div>
					<div class="wp-ai-chatbot-form-actions">
						<button type="submit" class="wp-ai-chatbot-button wp-ai-chatbot-button-primary">
							<?php esc_html_e( 'Continue', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</button>
						<button type="button" class="wp-ai-chatbot-button wp-ai-chatbot-button-secondary" id="wp-ai-chatbot-lead-skip">
							<?php esc_html_e( 'Skip for now', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

