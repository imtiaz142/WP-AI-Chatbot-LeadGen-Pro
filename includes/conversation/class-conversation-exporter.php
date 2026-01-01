<?php
/**
 * Conversation Exporter.
 *
 * Exports conversations in various formats (email, PDF, text, JSON).
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/conversation
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Conversation_Exporter {

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
	 * Channel continuity instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Channel_Continuity
	 */
	private $channel_continuity;

	/**
	 * Export formats.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const FORMATS = array(
		'text'  => 'Plain Text',
		'html'  => 'HTML',
		'json'  => 'JSON',
		'pdf'   => 'PDF',
		'email' => 'Email',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->channel_continuity = new WP_AI_Chatbot_LeadGen_Pro_Channel_Continuity();
	}

	/**
	 * Export conversation.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $format          Export format.
	 * @param array  $options         Export options.
	 * @return array|WP_Error Export data or error.
	 */
	public function export( $conversation_id, $format = 'text', $options = array() ) {
		$defaults = array(
			'include_metadata'   => false,
			'include_timestamps' => true,
			'include_citations'  => true,
			'session_id'         => '',
			'email'              => '',
			'filename'           => '',
		);
		$options = wp_parse_args( $options, $defaults );

		// Get conversation data
		$conversation = $this->get_conversation( $conversation_id );
		if ( ! $conversation ) {
			return new WP_Error(
				'not_found',
				__( 'Conversation not found.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Get messages
		$messages = $this->get_messages( $conversation_id );
		if ( empty( $messages ) ) {
			return new WP_Error(
				'no_messages',
				__( 'No messages to export.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Export based on format
		switch ( $format ) {
			case 'text':
				return $this->export_text( $conversation, $messages, $options );

			case 'html':
				return $this->export_html( $conversation, $messages, $options );

			case 'json':
				return $this->export_json( $conversation, $messages, $options );

			case 'pdf':
				return $this->export_pdf( $conversation, $messages, $options );

			case 'email':
				return $this->export_email( $conversation, $messages, $options );

			default:
				return new WP_Error(
					'invalid_format',
					__( 'Invalid export format.', 'wp-ai-chatbot-leadgen-pro' )
				);
		}
	}

	/**
	 * Get conversation by ID.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array|null Conversation data.
	 */
	private function get_conversation( $conversation_id ) {
		global $wpdb;
		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $conversation_id ),
			ARRAY_A
		);
	}

	/**
	 * Get conversation messages.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return array Messages.
	 */
	private function get_messages( $conversation_id ) {
		global $wpdb;
		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY created_at ASC",
				$conversation_id
			),
			ARRAY_A
		);
	}

	/**
	 * Export as plain text.
	 *
	 * @since 1.0.0
	 * @param array $conversation Conversation data.
	 * @param array $messages     Messages.
	 * @param array $options      Export options.
	 * @return array Export data.
	 */
	private function export_text( $conversation, $messages, $options ) {
		$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );
		$output = array();

		// Header
		$output[] = str_repeat( '=', 60 );
		$output[] = sprintf( __( 'Conversation Transcript - %s', 'wp-ai-chatbot-leadgen-pro' ), $company_name );
		$output[] = str_repeat( '=', 60 );
		$output[] = '';
		$output[] = sprintf( __( 'Date: %s', 'wp-ai-chatbot-leadgen-pro' ), date_i18n( get_option( 'date_format' ), strtotime( $conversation['created_at'] ) ) );
		$output[] = sprintf( __( 'Conversation ID: %d', 'wp-ai-chatbot-leadgen-pro' ), $conversation['id'] );
		$output[] = '';
		$output[] = str_repeat( '-', 60 );
		$output[] = '';

		// Messages
		foreach ( $messages as $msg ) {
			$role = $msg['role'] === 'user' ? __( 'You', 'wp-ai-chatbot-leadgen-pro' ) : $company_name;

			if ( $options['include_timestamps'] ) {
				$time = date_i18n( get_option( 'time_format' ), strtotime( $msg['created_at'] ) );
				$output[] = sprintf( '[%s] %s:', $time, $role );
			} else {
				$output[] = sprintf( '%s:', $role );
			}

			$output[] = $msg['content'];
			$output[] = '';
		}

		// Footer
		$output[] = str_repeat( '-', 60 );
		$output[] = sprintf( __( 'Exported on %s', 'wp-ai-chatbot-leadgen-pro' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );

		$content = implode( "\n", $output );
		$filename = $options['filename'] ?: sprintf( 'conversation-%d-%s.txt', $conversation['id'], date( 'Y-m-d' ) );

		return array(
			'format'   => 'text',
			'content'  => $content,
			'filename' => $filename,
			'mime'     => 'text/plain',
		);
	}

	/**
	 * Export as HTML.
	 *
	 * @since 1.0.0
	 * @param array $conversation Conversation data.
	 * @param array $messages     Messages.
	 * @param array $options      Export options.
	 * @return array Export data.
	 */
	private function export_html( $conversation, $messages, $options ) {
		$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );
		$primary_color = $this->config->get( 'primary_color', '#4f46e5' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php printf( esc_html__( 'Conversation Transcript - %s', 'wp-ai-chatbot-leadgen-pro' ), esc_html( $company_name ) ); ?></title>
			<style>
				* { margin: 0; padding: 0; box-sizing: border-box; }
				body { 
					font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
					background: #f3f4f6;
					padding: 20px;
					line-height: 1.6;
				}
				.container { max-width: 800px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
				.header { background: <?php echo esc_attr( $primary_color ); ?>; color: #fff; padding: 24px; }
				.header h1 { font-size: 24px; margin-bottom: 8px; }
				.header p { opacity: 0.9; font-size: 14px; }
				.messages { padding: 24px; }
				.message { margin-bottom: 20px; padding: 16px; border-radius: 8px; }
				.message.user { background: #f3f4f6; }
				.message.assistant { background: #eff6ff; border-left: 3px solid <?php echo esc_attr( $primary_color ); ?>; }
				.message-header { display: flex; justify-content: space-between; margin-bottom: 8px; }
				.message-role { font-weight: 600; color: #374151; font-size: 14px; }
				.message-time { color: #9ca3af; font-size: 12px; }
				.message-content { color: #4b5563; }
				.footer { padding: 24px; background: #f9fafb; border-top: 1px solid #e5e7eb; text-align: center; }
				.footer p { color: #6b7280; font-size: 12px; }
				@media print {
					body { background: #fff; padding: 0; }
					.container { box-shadow: none; }
				}
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<h1><?php echo esc_html( $company_name ); ?></h1>
					<p><?php esc_html_e( 'Conversation Transcript', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
					<p><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $conversation['created_at'] ) ) ); ?></p>
				</div>
				<div class="messages">
					<?php foreach ( $messages as $msg ) : ?>
					<div class="message <?php echo esc_attr( $msg['role'] ); ?>">
						<div class="message-header">
							<span class="message-role">
								<?php echo $msg['role'] === 'user' ? esc_html__( 'You', 'wp-ai-chatbot-leadgen-pro' ) : esc_html( $company_name ); ?>
							</span>
							<?php if ( $options['include_timestamps'] ) : ?>
							<span class="message-time">
								<?php echo esc_html( date_i18n( get_option( 'time_format' ), strtotime( $msg['created_at'] ) ) ); ?>
							</span>
							<?php endif; ?>
						</div>
						<div class="message-content">
							<?php echo nl2br( esc_html( $msg['content'] ) ); ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<div class="footer">
					<p><?php printf( esc_html__( 'Exported on %s', 'wp-ai-chatbot-leadgen-pro' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) ); ?></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		$content = ob_get_clean();
		$filename = $options['filename'] ?: sprintf( 'conversation-%d-%s.html', $conversation['id'], date( 'Y-m-d' ) );

		return array(
			'format'   => 'html',
			'content'  => $content,
			'filename' => $filename,
			'mime'     => 'text/html',
		);
	}

	/**
	 * Export as JSON.
	 *
	 * @since 1.0.0
	 * @param array $conversation Conversation data.
	 * @param array $messages     Messages.
	 * @param array $options      Export options.
	 * @return array Export data.
	 */
	private function export_json( $conversation, $messages, $options ) {
		$export_data = array(
			'conversation' => array(
				'id'         => intval( $conversation['id'] ),
				'created_at' => $conversation['created_at'],
				'updated_at' => $conversation['updated_at'],
				'status'     => $conversation['status'],
			),
			'messages'     => array(),
			'exported_at'  => current_time( 'c' ),
			'company'      => $this->config->get( 'company_name', get_bloginfo( 'name' ) ),
		);

		if ( $options['include_metadata'] ) {
			$export_data['conversation']['session_id'] = $conversation['session_id'];
			$export_data['conversation']['metadata'] = maybe_unserialize( $conversation['metadata'] );
		}

		foreach ( $messages as $msg ) {
			$message_data = array(
				'role'       => $msg['role'],
				'content'    => $msg['content'],
				'created_at' => $msg['created_at'],
			);

			if ( $options['include_metadata'] ) {
				$metadata = maybe_unserialize( $msg['metadata'] );
				if ( ! empty( $metadata ) ) {
					$message_data['metadata'] = $metadata;
				}
			}

			$export_data['messages'][] = $message_data;
		}

		$content = wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$filename = $options['filename'] ?: sprintf( 'conversation-%d-%s.json', $conversation['id'], date( 'Y-m-d' ) );

		return array(
			'format'   => 'json',
			'content'  => $content,
			'filename' => $filename,
			'mime'     => 'application/json',
		);
	}

	/**
	 * Export as PDF.
	 *
	 * @since 1.0.0
	 * @param array $conversation Conversation data.
	 * @param array $messages     Messages.
	 * @param array $options      Export options.
	 * @return array|WP_Error Export data or error.
	 */
	private function export_pdf( $conversation, $messages, $options ) {
		// First generate HTML
		$html_export = $this->export_html( $conversation, $messages, $options );

		// Check if TCPDF or similar is available
		if ( class_exists( 'TCPDF' ) ) {
			return $this->generate_pdf_tcpdf( $html_export['content'], $conversation, $options );
		}

		// Check if DOMPDF is available
		if ( class_exists( 'Dompdf\Dompdf' ) ) {
			return $this->generate_pdf_dompdf( $html_export['content'], $conversation, $options );
		}

		// Fallback: Return HTML with print instructions
		return array(
			'format'       => 'html',
			'content'      => $html_export['content'],
			'filename'     => str_replace( '.html', '.html', $options['filename'] ?: sprintf( 'conversation-%d-%s.html', $conversation['id'], date( 'Y-m-d' ) ) ),
			'mime'         => 'text/html',
			'print_notice' => __( 'PDF generation is not available. Please use your browser\'s Print function and select "Save as PDF".', 'wp-ai-chatbot-leadgen-pro' ),
		);
	}

	/**
	 * Generate PDF using TCPDF.
	 *
	 * @since 1.0.0
	 * @param string $html         HTML content.
	 * @param array  $conversation Conversation data.
	 * @param array  $options      Options.
	 * @return array Export data.
	 */
	private function generate_pdf_tcpdf( $html, $conversation, $options ) {
		$pdf = new TCPDF( PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false );

		$company_name = $this->config->get( 'company_name', get_bloginfo( 'name' ) );

		$pdf->SetCreator( $company_name );
		$pdf->SetAuthor( $company_name );
		$pdf->SetTitle( __( 'Conversation Transcript', 'wp-ai-chatbot-leadgen-pro' ) );

		$pdf->SetHeaderData( '', 0, $company_name, __( 'Conversation Transcript', 'wp-ai-chatbot-leadgen-pro' ) );
		$pdf->setHeaderFont( array( 'helvetica', '', 12 ) );
		$pdf->setFooterFont( array( 'helvetica', '', 8 ) );

		$pdf->SetDefaultMonospacedFont( 'courier' );
		$pdf->SetMargins( 15, 27, 15 );
		$pdf->SetHeaderMargin( 5 );
		$pdf->SetFooterMargin( 10 );
		$pdf->SetAutoPageBreak( true, 25 );

		$pdf->AddPage();
		$pdf->writeHTML( $html, true, false, true, false, '' );

		$content = $pdf->Output( '', 'S' );
		$filename = $options['filename'] ?: sprintf( 'conversation-%d-%s.pdf', $conversation['id'], date( 'Y-m-d' ) );

		return array(
			'format'   => 'pdf',
			'content'  => $content,
			'filename' => $filename,
			'mime'     => 'application/pdf',
		);
	}

	/**
	 * Generate PDF using DOMPDF.
	 *
	 * @since 1.0.0
	 * @param string $html         HTML content.
	 * @param array  $conversation Conversation data.
	 * @param array  $options      Options.
	 * @return array Export data.
	 */
	private function generate_pdf_dompdf( $html, $conversation, $options ) {
		$dompdf = new \Dompdf\Dompdf();
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		$content = $dompdf->output();
		$filename = $options['filename'] ?: sprintf( 'conversation-%d-%s.pdf', $conversation['id'], date( 'Y-m-d' ) );

		return array(
			'format'   => 'pdf',
			'content'  => $content,
			'filename' => $filename,
			'mime'     => 'application/pdf',
		);
	}

	/**
	 * Export via email.
	 *
	 * @since 1.0.0
	 * @param array $conversation Conversation data.
	 * @param array $messages     Messages.
	 * @param array $options      Export options.
	 * @return array|WP_Error Export result or error.
	 */
	private function export_email( $conversation, $messages, $options ) {
		if ( empty( $options['email'] ) || ! is_email( $options['email'] ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Valid email address is required.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Send via channel continuity
		$result = $this->channel_continuity->send_via_email(
			$conversation['id'],
			$options['email'],
			array(
				'include_transcript'  => true,
				'include_resume_link' => true,
				'session_id'          => $options['session_id'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'format'  => 'email',
			'sent_to' => $options['email'],
			'success' => true,
			'message' => __( 'Transcript sent successfully.', 'wp-ai-chatbot-leadgen-pro' ),
		);
	}

	/**
	 * Generate download URL for export.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id Conversation ID.
	 * @param string $format          Export format.
	 * @param array  $options         Options.
	 * @return string|WP_Error Download URL or error.
	 */
	public function generate_download_url( $conversation_id, $format = 'text', $options = array() ) {
		// Generate a temporary token
		$token = wp_generate_password( 32, false, false );
		$expiry = time() + HOUR_IN_SECONDS;

		// Store token temporarily
		set_transient(
			'wp_ai_chatbot_export_' . $token,
			array(
				'conversation_id' => $conversation_id,
				'format'          => $format,
				'options'         => $options,
				'expires'         => $expiry,
			),
			HOUR_IN_SECONDS
		);

		// Build download URL
		return add_query_arg(
			array(
				'action'               => 'wp_ai_chatbot_download_export',
				'token'                => $token,
				'wp_ai_chatbot_nonce'  => wp_create_nonce( 'export_download_' . $token ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Process download request.
	 *
	 * @since 1.0.0
	 * @param string $token Download token.
	 * @return array|WP_Error Export data or error.
	 */
	public function process_download( $token ) {
		$data = get_transient( 'wp_ai_chatbot_export_' . $token );

		if ( ! $data ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid or expired download link.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// Delete transient after use
		delete_transient( 'wp_ai_chatbot_export_' . $token );

		return $this->export(
			$data['conversation_id'],
			$data['format'],
			$data['options']
		);
	}

	/**
	 * Generate resume link for conversation.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param array $options         Options.
	 * @return array|WP_Error Resume link data or error.
	 */
	public function generate_resume_link( $conversation_id, $options = array() ) {
		return $this->channel_continuity->create_resume_link( $conversation_id, $options );
	}

	/**
	 * Get share links for conversation.
	 *
	 * @since 1.0.0
	 * @param int   $conversation_id Conversation ID.
	 * @param array $options         Options.
	 * @return array Share links.
	 */
	public function get_share_links( $conversation_id, $options = array() ) {
		$defaults = array(
			'session_id' => '',
			'phone'      => '',
		);
		$options = wp_parse_args( $options, $defaults );

		$links = array();

		// Resume link
		$resume = $this->generate_resume_link( $conversation_id, $options );
		if ( ! is_wp_error( $resume ) ) {
			$links['resume'] = array(
				'url'   => $resume['url'],
				'short' => $resume['short_url'],
			);
		}

		// WhatsApp link
		$whatsapp = $this->channel_continuity->generate_whatsapp_link( $conversation_id, $options );
		if ( ! is_wp_error( $whatsapp ) ) {
			$links['whatsapp'] = $whatsapp['whatsapp_url'];
		}

		// Download links
		$links['download'] = array(
			'text' => $this->generate_download_url( $conversation_id, 'text', $options ),
			'html' => $this->generate_download_url( $conversation_id, 'html', $options ),
			'json' => $this->generate_download_url( $conversation_id, 'json', $options ),
			'pdf'  => $this->generate_download_url( $conversation_id, 'pdf', $options ),
		);

		return $links;
	}

	/**
	 * Copy to clipboard text.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id Conversation ID.
	 * @return string|WP_Error Copyable text or error.
	 */
	public function get_copyable_text( $conversation_id ) {
		$export = $this->export( $conversation_id, 'text', array(
			'include_timestamps' => false,
		) );

		if ( is_wp_error( $export ) ) {
			return $export;
		}

		return $export['content'];
	}

	/**
	 * Get available export formats.
	 *
	 * @since 1.0.0
	 * @return array Available formats.
	 */
	public function get_available_formats() {
		$formats = self::FORMATS;

		// Check if PDF is available
		if ( ! class_exists( 'TCPDF' ) && ! class_exists( 'Dompdf\Dompdf' ) ) {
			$formats['pdf'] .= ' ' . __( '(Print to PDF)', 'wp-ai-chatbot-leadgen-pro' );
		}

		return $formats;
	}

	/**
	 * Bulk export conversations.
	 *
	 * @since 1.0.0
	 * @param array  $conversation_ids Conversation IDs.
	 * @param string $format           Export format.
	 * @param array  $options          Options.
	 * @return array|WP_Error Export data or error.
	 */
	public function bulk_export( $conversation_ids, $format = 'json', $options = array() ) {
		if ( empty( $conversation_ids ) ) {
			return new WP_Error(
				'no_conversations',
				__( 'No conversations to export.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		$exports = array();

		foreach ( $conversation_ids as $id ) {
			$export = $this->export( $id, $format, $options );
			if ( ! is_wp_error( $export ) ) {
				$exports[ $id ] = $export;
			}
		}

		if ( empty( $exports ) ) {
			return new WP_Error(
				'export_failed',
				__( 'Failed to export any conversations.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		// For JSON, combine into single file
		if ( $format === 'json' ) {
			$combined = array(
				'conversations' => array(),
				'exported_at'   => current_time( 'c' ),
				'count'         => count( $exports ),
			);

			foreach ( $exports as $id => $export ) {
				$combined['conversations'][ $id ] = json_decode( $export['content'], true );
			}

			return array(
				'format'   => 'json',
				'content'  => wp_json_encode( $combined, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
				'filename' => sprintf( 'conversations-export-%s.json', date( 'Y-m-d' ) ),
				'mime'     => 'application/json',
			);
		}

		// For other formats, create a zip
		if ( class_exists( 'ZipArchive' ) ) {
			return $this->create_zip_export( $exports, $format );
		}

		return array(
			'format'  => 'multiple',
			'exports' => $exports,
		);
	}

	/**
	 * Create ZIP archive of exports.
	 *
	 * @since 1.0.0
	 * @param array  $exports Exports array.
	 * @param string $format  Export format.
	 * @return array|WP_Error ZIP data or error.
	 */
	private function create_zip_export( $exports, $format ) {
		$upload_dir = wp_upload_dir();
		$temp_file = $upload_dir['basedir'] . '/wp-ai-chatbot-export-' . uniqid() . '.zip';

		$zip = new ZipArchive();
		if ( $zip->open( $temp_file, ZipArchive::CREATE ) !== true ) {
			return new WP_Error(
				'zip_failed',
				__( 'Failed to create ZIP archive.', 'wp-ai-chatbot-leadgen-pro' )
			);
		}

		foreach ( $exports as $id => $export ) {
			$zip->addFromString( $export['filename'], $export['content'] );
		}

		$zip->close();

		$content = file_get_contents( $temp_file );
		unlink( $temp_file );

		return array(
			'format'   => 'zip',
			'content'  => $content,
			'filename' => sprintf( 'conversations-export-%s.zip', date( 'Y-m-d' ) ),
			'mime'     => 'application/zip',
		);
	}
}

