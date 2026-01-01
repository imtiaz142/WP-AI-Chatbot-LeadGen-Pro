<?php
/**
 * Channel Continuity.
 *
 * Manages multi-channel conversation continuity across email, WhatsApp, and logged-in users.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Channel_Continuity {

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Config instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Conversation memory instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory
	 */
	private $memory;

	/**
	 * Supported channels.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const CHANNELS = array(
		'web'      => 'Web Chat',
		'email'    => 'Email',
		'whatsapp' => 'WhatsApp',
		'sms'      => 'SMS',
		'api'      => 'API',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->memory = new WP_AI_Chatbot_LeadGen_Pro_Conversation_Memory();

		$this->maybe_create_continuity_table();
	}

	/**
	 * Create continuity table if it doesn't exist.
	 *
	 * @since 1.0.0
	 */
	private function maybe_create_continuity_table() {
		global $wpdb;

		$table_name = $this->get_continuity_table();
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			$sql = "CREATE TABLE {$table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NOT NULL,
				session_id varchar(64) NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				email varchar(255) DEFAULT NULL,
				phone varchar(50) DEFAULT NULL,
				channel varchar(20) NOT NULL DEFAULT 'web',
				resume_token varchar(64) NOT NULL,
				resume_url text,
				metadata longtext,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				last_accessed_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY resume_token (resume_token),
				KEY conversation_id (conversation_id),
				KEY session_id (session_id),
				KEY user_id (user_id),
				KEY email (email),
				KEY phone (phone),
				KEY expires_at (expires_at)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	/**
	 * Get continuity table name.
	 *
	 * @since 1.0.0
	 * @return string Table name.
	 */
	public function get_continuity_table() {
		global $wpdb;
		return $wpdb->prefix . 'wp_ai_chatbot_continuity';
	}

	/**
	 * Create resume link for conversation.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param array $data            Contact and channel data.
	 * @return array|WP_Error Resume link data or error.
	 */
	public function create_resume_link( $conversation_id, $data = array() ) {
		global $wpdb;

		$defaults = array(
			'session_id' => '',
			'user_id'    => get_current_user_id(),
			'email'      => '',
			'phone'      => '',
			'channel'    => 'web',
			'expires_in' => 30 * DAY_IN_SECONDS, // 30 days default
			'metadata'   => array(),
		);
		$data = wp_parse_args( $data, $defaults );

		// Validate channel
		if ( ! isset( self::CHANNELS[ $data['channel'] ] ) ) {
			return new WP_Error(
				'invalid_channel',
				__( 'Invalid channel specified.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Generate unique resume token
		$resume_token = $this->generate_resume_token();

		// Build resume URL
		$resume_url = $this->build_resume_url( $resume_token );

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $data['expires_in'] );

		$result = $wpdb->insert(
			$this->get_continuity_table(),
			array(
				'conversation_id' => $conversation_id,
				'session_id'      => $data['session_id'],
				'user_id'         => $data['user_id'] ?: null,
				'email'           => $data['email'] ?: null,
				'phone'           => $data['phone'] ?: null,
				'channel'         => $data['channel'],
				'resume_token'    => $resume_token,
				'resume_url'      => $resume_url,
				'metadata'        => maybe_serialize( $data['metadata'] ),
				'expires_at'      => $expires_at,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create resume link.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$this->logger->info(
			'Resume link created',
			array(
				'conversation_id' => $conversation_id,
				'channel'         => $data['channel'],
				'token'           => substr( $resume_token, 0, 8 ) . '...',
			)
		);

		return array(
			'id'           => $wpdb->insert_id,
			'token'        => $resume_token,
			'url'          => $resume_url,
			'short_url'    => $this->get_short_url( $resume_token ),
			'expires_at'   => $expires_at,
			'channel'      => $data['channel'],
		);
	}

	/**
	 * Generate unique resume token.
	 *
	 * @since 1.0.0
	 * @return string Resume token.
	 */
	private function generate_resume_token() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Build resume URL.
	 *
	 * @since 1.0.0
	 * @param string $token Resume token.
	 * @return string Resume URL.
	 */
	private function build_resume_url( $token ) {
		return add_query_arg(
			array( 'chat_resume' => $token ),
			home_url( '/' )
		);
	}

	/**
	 * Get short URL for sharing.
	 *
	 * @since 1.0.0
	 * @param string $token Resume token.
	 * @return string Short URL.
	 */
	private function get_short_url( $token ) {
		// Use home URL with short parameter
		return add_query_arg(
			array( 'cr' => substr( $token, 0, 12 ) ),
			home_url( '/' )
		);
	}

	/**
	 * Resume conversation from token.
	 *
	 * @since 1.0.0
	 * @param string $token    Resume token.
	 * @param string $channel  Channel resuming from.
	 * @return array|WP_Error Conversation data or error.
	 */
	public function resume_conversation( $token, $channel = 'web' ) {
		global $wpdb;

		// Support short tokens
		$where_clause = strlen( $token ) <= 12 
			? "resume_token LIKE %s"
			: "resume_token = %s";
		$token_value = strlen( $token ) <= 12 
			? $token . '%'
			: $token;

		$continuity = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_continuity_table()} 
				 WHERE {$where_clause} AND expires_at > %s",
				$token_value,
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		if ( ! $continuity ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid or expired resume link.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Update last accessed
		$wpdb->update(
			$this->get_continuity_table(),
			array( 'last_accessed_at' => current_time( 'mysql' ) ),
			array( 'id' => $continuity['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		// Get conversation data
		$conversation = $this->get_conversation( $continuity['conversation_id'] );

		if ( ! $conversation ) {
			return new WP_Error(
				'conversation_not_found',
				__( 'Conversation not found.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Get conversation messages
		$messages = $this->get_conversation_messages( $continuity['conversation_id'] );

		$this->logger->info(
			'Conversation resumed',
			array(
				'conversation_id' => $continuity['conversation_id'],
				'channel'         => $channel,
				'original_channel' => $continuity['channel'],
			)
		);

		return array(
			'conversation_id' => $continuity['conversation_id'],
			'session_id'      => $continuity['session_id'],
			'messages'        => $messages,
			'metadata'        => maybe_unserialize( $continuity['metadata'] ),
			'original_channel' => $continuity['channel'],
			'current_channel' => $channel,
		);
	}

	/**
	 * Get conversation by ID.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array|null Conversation data or null.
	 */
	private function get_conversation( $conversation_id ) {
		global $wpdb;
		$conversations_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$conversations_table} WHERE id = %d",
				$conversation_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get conversation messages.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit           Message limit.
	 * @return array Messages.
	 */
	private function get_conversation_messages( $conversation_id, $limit = 50 ) {
		global $wpdb;
		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, role, content, created_at FROM {$messages_table} 
				 WHERE conversation_id = %d 
				 ORDER BY created_at ASC 
				 LIMIT %d",
				$conversation_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Link conversation to user account.
	 *
	 * @since 1.0.0
	 * @param string $session_id Session ID.
	 * @param int    $user_id    WordPress user ID.
	 * @return bool True on success.
	 */
	public function link_to_user( $session_id, $user_id ) {
		global $wpdb;

		// Update continuity records
		$wpdb->update(
			$this->get_continuity_table(),
			array( 'user_id' => $user_id ),
			array( 'session_id' => $session_id ),
			array( '%d' ),
			array( '%s' )
		);

		// Update conversations
		$conversations_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();
		$wpdb->update(
			$conversations_table,
			array( 'user_id' => $user_id ),
			array( 'session_id' => $session_id ),
			array( '%d' ),
			array( '%s' )
		);

		// Merge memory
		$this->memory->merge_sessions( $session_id, $user_id );

		$this->logger->info(
			'Session linked to user',
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
			)
		);

		return true;
	}

	/**
	 * Get user's conversations across channels.
	 *
	 * @since 1.0.0
	 * @param int   $user_id User ID.
	 * @param array $args    Query arguments.
	 * @return array Conversations.
	 */
	public function get_user_conversations( $user_id, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'   => 10,
			'offset'  => 0,
			'channel' => '', // '' for all channels
		);
		$args = wp_parse_args( $args, $defaults );

		$conversations_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		$where = "user_id = %d";
		$values = array( $user_id );

		if ( ! empty( $args['channel'] ) ) {
			$where .= " AND EXISTS (
				SELECT 1 FROM {$this->get_continuity_table()} c 
				WHERE c.conversation_id = conv.id AND c.channel = %s
			)";
			$values[] = $args['channel'];
		}

		$values[] = $args['limit'];
		$values[] = $args['offset'];

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT conv.*, 
				        (SELECT COUNT(*) FROM " . WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table() . " WHERE conversation_id = conv.id) as message_count
				 FROM {$conversations_table} conv
				 WHERE {$where}
				 ORDER BY conv.updated_at DESC
				 LIMIT %d OFFSET %d",
				$values
			),
			ARRAY_A
		);
	}

	/**
	 * Send conversation via email.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $email           Email address.
	 * @param array  $options         Send options.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function send_via_email( $conversation_id, $email, $options = array() ) {
		$defaults = array(
			'include_transcript' => true,
			'include_resume_link' => true,
			'subject'            => '',
			'session_id'         => '',
		);
		$options = wp_parse_args( $options, $defaults );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Invalid email address.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Create resume link
		$resume_data = null;
		if ( $options['include_resume_link'] ) {
			$resume_data = $this->create_resume_link( $conversation_id, array(
				'email'      => $email,
				'channel'    => 'email',
				'session_id' => $options['session_id'],
			) );

			if ( is_wp_error( $resume_data ) ) {
				return $resume_data;
			}
		}

		// Get conversation messages
		$messages = $this->get_conversation_messages( $conversation_id );

		// Build email content
		$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );

		$subject = $options['subject'] ?: sprintf(
			/* translators: %s: Company name */
			__( 'Your conversation with %s', 'wp-ai-chatbot-leadgen-pro' ),
			$company_name
		);

		$body = $this->build_email_body( $messages, $resume_data, $options );

		// Send email
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$sent = wp_mail( $email, $subject, $body, $headers );

		if ( ! $sent ) {
			return new WP_Error(
				'email_failed',
				__( 'Failed to send email.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$this->logger->info(
			'Conversation sent via email',
			array(
				'conversation_id' => $conversation_id,
				'email'           => $email,
			)
		);

		return true;
	}

	/**
	 * Build email body.
	 *
	 * @since 1.0.0
	 * @param array      $messages    Conversation messages.
	 * @param array|null $resume_data Resume link data.
	 * @param array      $options     Email options.
	 * @return string Email HTML body.
	 */
	private function build_email_body( $messages, $resume_data, $options ) {
		$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );
		$primary_color = $this->config->get( 'primary_color', '#4f46e5' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
		</head>
		<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f3f4f6;">
			<table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff;">
				<!-- Header -->
				<tr>
					<td style="padding: 32px 24px; background-color: <?php echo esc_attr( $primary_color ); ?>;">
						<h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
							<?php echo esc_html( $company_name ); ?>
						</h1>
						<p style="margin: 8px 0 0; color: rgba(255,255,255,0.9); font-size: 14px;">
							<?php esc_html_e( 'Your Conversation Transcript', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</p>
					</td>
				</tr>

				<?php if ( $resume_data ) : ?>
				<!-- Resume Link -->
				<tr>
					<td style="padding: 24px; background-color: #f0f9ff; border-bottom: 1px solid #e0e7ff;">
						<p style="margin: 0 0 12px; font-size: 14px; color: #1e40af;">
							<?php esc_html_e( 'Want to continue this conversation?', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</p>
						<a href="<?php echo esc_url( $resume_data['url'] ); ?>" 
						   style="display: inline-block; padding: 12px 24px; background-color: <?php echo esc_attr( $primary_color ); ?>; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;">
							<?php esc_html_e( 'Continue Conversation', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</a>
					</td>
				</tr>
				<?php endif; ?>

				<?php if ( $options['include_transcript'] && ! empty( $messages ) ) : ?>
				<!-- Transcript -->
				<tr>
					<td style="padding: 24px;">
						<h2 style="margin: 0 0 16px; font-size: 18px; color: #1f2937;">
							<?php esc_html_e( 'Conversation Transcript', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</h2>
						
						<?php foreach ( $messages as $msg ) : ?>
						<div style="margin-bottom: 16px; padding: 12px 16px; border-radius: 8px; <?php echo $msg['role'] === 'user' ? 'background-color: #f3f4f6;' : 'background-color: #eff6ff; border-left: 3px solid ' . esc_attr( $primary_color ) . ';'; ?>">
							<p style="margin: 0 0 4px; font-size: 12px; font-weight: 600; color: #6b7280;">
								<?php echo $msg['role'] === 'user' ? esc_html__( 'You', 'wp-ai-chatbot-leadgen-pro' ) : esc_html( $company_name ); ?>
							</p>
							<p style="margin: 0; font-size: 14px; color: #374151; line-height: 1.5;">
								<?php echo nl2br( esc_html( $msg['content'] ) ); ?>
							</p>
							<p style="margin: 8px 0 0; font-size: 11px; color: #9ca3af;">
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $msg['created_at'] ) ) ); ?>
							</p>
						</div>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php endif; ?>

				<!-- Footer -->
				<tr>
					<td style="padding: 24px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
						<p style="margin: 0; font-size: 12px; color: #6b7280; text-align: center;">
							<?php 
							printf(
								/* translators: %s: Company name */
								esc_html__( 'This email was sent by %s. If you did not request this, you can safely ignore it.', 'wp-ai-chatbot-leadgen-pro' ),
								esc_html( $company_name )
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generate WhatsApp share link.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param array $options         Options.
	 * @return array|WP_Error WhatsApp link data or error.
	 */
	public function generate_whatsapp_link( $conversation_id, $options = array() ) {
		$defaults = array(
			'phone'      => '',
			'session_id' => '',
			'message'    => '',
		);
		$options = wp_parse_args( $options, $defaults );

		// Create resume link
		$resume_data = $this->create_resume_link( $conversation_id, array(
			'phone'      => $options['phone'],
			'channel'    => 'whatsapp',
			'session_id' => $options['session_id'],
		) );

		if ( is_wp_error( $resume_data ) ) {
			return $resume_data;
		}

		$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );

		// Build WhatsApp message
		$message = $options['message'] ?: sprintf(
			/* translators: 1: Company name, 2: Resume URL */
			__( "Continue your conversation with %1\$s:\n%2\$s", 'wp-ai-chatbot-leadgen-pro' ),
			$company_name,
			$resume_data['url']
		);

		// Build WhatsApp URL
		$whatsapp_url = 'https://wa.me/';
		if ( ! empty( $options['phone'] ) ) {
			$phone = preg_replace( '/[^\d+]/', '', $options['phone'] );
			$whatsapp_url .= ltrim( $phone, '+' );
		}
		$whatsapp_url .= '?text=' . rawurlencode( $message );

		return array(
			'resume_url'   => $resume_data['url'],
			'whatsapp_url' => $whatsapp_url,
			'message'      => $message,
			'token'        => $resume_data['token'],
		);
	}

	/**
	 * Handle incoming webhook from external channel.
	 *
	 * @since 1.0.0
	 * @param string $channel      Channel name.
	 * @param array  $webhook_data Webhook data.
	 * @return array|WP_Error Response data or error.
	 */
	public function handle_webhook( $channel, $webhook_data ) {
		if ( ! isset( self::CHANNELS[ $channel ] ) ) {
			return new WP_Error(
				'invalid_channel',
				__( 'Invalid channel.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		/**
		 * Filter webhook data before processing.
		 *
		 * @since 1.0.0
		 * @param array  $webhook_data Webhook data.
		 * @param string $channel      Channel name.
		 */
		$webhook_data = apply_filters( 'wp_ai_chatbot_channel_webhook_data', $webhook_data, $channel );

		// Try to find existing conversation
		$continuity = $this->find_by_contact( $webhook_data, $channel );

		if ( $continuity ) {
			// Continue existing conversation
			return array(
				'action'          => 'continue',
				'conversation_id' => $continuity['conversation_id'],
				'session_id'      => $continuity['session_id'],
			);
		}

		// Create new conversation
		return array(
			'action'  => 'new',
			'channel' => $channel,
			'data'    => $webhook_data,
		);
	}

	/**
	 * Find continuity by contact info.
	 *
	 * @since 1.0.0
	 * @param array  $data    Contact data.
	 * @param string $channel Channel.
	 * @return array|null Continuity record or null.
	 */
	private function find_by_contact( $data, $channel ) {
		global $wpdb;

		$where = "channel = %s AND expires_at > %s";
		$values = array( $channel, current_time( 'mysql' ) );

		if ( ! empty( $data['email'] ) ) {
			$where .= " AND email = %s";
			$values[] = $data['email'];
		} elseif ( ! empty( $data['phone'] ) ) {
			$where .= " AND phone = %s";
			$values[] = preg_replace( '/[^\d+]/', '', $data['phone'] );
		} else {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_continuity_table()} 
				 WHERE {$where} 
				 ORDER BY created_at DESC LIMIT 1",
				$values
			),
			ARRAY_A
		);
	}

	/**
	 * Get conversation history for a contact.
	 *
	 * @since 1.0.0
	 * @param string $identifier Email or phone.
	 * @param string $type       Identifier type (email, phone).
	 * @return array Conversation history.
	 */
	public function get_contact_history( $identifier, $type = 'email' ) {
		global $wpdb;

		$column = $type === 'phone' ? 'phone' : 'email';
		$identifier = $type === 'phone' ? preg_replace( '/[^\d+]/', '', $identifier ) : $identifier;

		$continuity_records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT conversation_id FROM {$this->get_continuity_table()} 
				 WHERE {$column} = %s 
				 ORDER BY created_at DESC",
				$identifier
			),
			ARRAY_A
		);

		$conversations = array();
		foreach ( $continuity_records as $record ) {
			$conversation = $this->get_conversation( $record['conversation_id'] );
			if ( $conversation ) {
				$conversation['messages'] = $this->get_conversation_messages( $record['conversation_id'], 5 );
				$conversations[] = $conversation;
			}
		}

		return $conversations;
	}

	/**
	 * Cleanup expired continuity records.
	 *
	 * @since 1.0.0
	 * @return int Number of deleted records.
	 */
	public function cleanup_expired() {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->get_continuity_table()} WHERE expires_at < %s",
				current_time( 'mysql' )
			)
		);

		return $result !== false ? $result : 0;
	}

	/**
	 * Get channel statistics.
	 *
	 * @since 1.0.0
	 * @param int $days Number of days.
	 * @return array Statistics.
	 */
	public function get_statistics( $days = 30 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$by_channel = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT channel, COUNT(*) as count 
				 FROM {$this->get_continuity_table()} 
				 WHERE created_at >= %s 
				 GROUP BY channel",
				$since
			),
			ARRAY_A
		);

		$resume_rate = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					COUNT(*) as total,
					SUM(CASE WHEN last_accessed_at IS NOT NULL THEN 1 ELSE 0 END) as resumed
				 FROM {$this->get_continuity_table()} 
				 WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		return array(
			'by_channel'  => $by_channel,
			'total'       => intval( $resume_rate['total'] ?? 0 ),
			'resumed'     => intval( $resume_rate['resumed'] ?? 0 ),
			'resume_rate' => $resume_rate['total'] > 0 
				? round( ( $resume_rate['resumed'] / $resume_rate['total'] ) * 100, 1 ) 
				: 0,
		);
	}
}

