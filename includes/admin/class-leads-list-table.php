<?php
/**
 * Leads List Table.
 *
 * Extends WP_List_Table to provide a sortable, filterable interface
 * for managing leads in the WordPress admin.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/admin
 * @since      1.0.0
 */

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WP_AI_Chatbot_LeadGen_Pro_Leads_List_Table extends WP_List_Table {

	/**
	 * Lead storage instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'lead',
			'plural'   => 'leads',
			'ajax'     => true,
		) );

		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
			$this->storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
		}
	}

	/**
	 * Get columns for the table.
	 *
	 * @since 1.0.0
	 * @return array Columns.
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'name'         => __( 'Name', 'wp-ai-chatbot-leadgen-pro' ),
			'email'        => __( 'Email', 'wp-ai-chatbot-leadgen-pro' ),
			'company'      => __( 'Company', 'wp-ai-chatbot-leadgen-pro' ),
			'score'        => __( 'Score', 'wp-ai-chatbot-leadgen-pro' ),
			'grade'        => __( 'Grade', 'wp-ai-chatbot-leadgen-pro' ),
			'status'       => __( 'Status', 'wp-ai-chatbot-leadgen-pro' ),
			'source'       => __( 'Source', 'wp-ai-chatbot-leadgen-pro' ),
			'conversations' => __( 'Chats', 'wp-ai-chatbot-leadgen-pro' ),
			'created_at'   => __( 'Created', 'wp-ai-chatbot-leadgen-pro' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @since 1.0.0
	 * @return array Sortable columns.
	 */
	public function get_sortable_columns() {
		return array(
			'name'       => array( 'name', false ),
			'email'      => array( 'email', false ),
			'company'    => array( 'company', false ),
			'score'      => array( 'score', true ), // Default sort desc
			'grade'      => array( 'grade', false ),
			'status'     => array( 'status', false ),
			'source'     => array( 'source', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @since 1.0.0
	 * @return array Bulk actions.
	 */
	public function get_bulk_actions() {
		return array(
			'delete'          => __( 'Delete', 'wp-ai-chatbot-leadgen-pro' ),
			'mark_qualified'  => __( 'Mark as Qualified', 'wp-ai-chatbot-leadgen-pro' ),
			'mark_contacted'  => __( 'Mark as Contacted', 'wp-ai-chatbot-leadgen-pro' ),
			'mark_converted'  => __( 'Mark as Converted', 'wp-ai-chatbot-leadgen-pro' ),
			'mark_disqualified' => __( 'Mark as Disqualified', 'wp-ai-chatbot-leadgen-pro' ),
			'export_csv'      => __( 'Export to CSV', 'wp-ai-chatbot-leadgen-pro' ),
			'enrich'          => __( 'Enrich Data', 'wp-ai-chatbot-leadgen-pro' ),
			'rescore'         => __( 'Recalculate Score', 'wp-ai-chatbot-leadgen-pro' ),
		);
	}

	/**
	 * Process bulk actions.
	 *
	 * @since 1.0.0
	 */
	public function process_bulk_action() {
		$action = $this->current_action();

		if ( ! $action ) {
			return;
		}

		// Verify nonce
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bulk-leads' ) ) {
			return;
		}

		$lead_ids = isset( $_REQUEST['lead'] ) ? array_map( 'intval', (array) $_REQUEST['lead'] ) : array();

		if ( empty( $lead_ids ) ) {
			return;
		}

		switch ( $action ) {
			case 'delete':
				foreach ( $lead_ids as $lead_id ) {
					$this->storage->delete( $lead_id );
				}
				$this->add_admin_notice( sprintf(
					/* translators: %d: number of leads */
					__( '%d lead(s) deleted.', 'wp-ai-chatbot-leadgen-pro' ),
					count( $lead_ids )
				), 'success' );
				break;

			case 'mark_qualified':
			case 'mark_contacted':
			case 'mark_converted':
			case 'mark_disqualified':
				$status = str_replace( 'mark_', '', $action );
				foreach ( $lead_ids as $lead_id ) {
					$this->storage->update( $lead_id, array( 'status' => $status ) );
				}
				$this->add_admin_notice( sprintf(
					/* translators: %d: number of leads, %s: status */
					__( '%d lead(s) marked as %s.', 'wp-ai-chatbot-leadgen-pro' ),
					count( $lead_ids ),
					$status
				), 'success' );
				break;

			case 'export_csv':
				$this->export_to_csv( $lead_ids );
				break;

			case 'enrich':
				if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Enrichment_Queue' ) ) {
					$queue = new WP_AI_Chatbot_LeadGen_Pro_Enrichment_Queue();
					$results = $queue->bulk_enqueue( $lead_ids );
					$this->add_admin_notice( sprintf(
						/* translators: %d: number enqueued, %d: number skipped */
						__( '%d lead(s) added to enrichment queue, %d skipped.', 'wp-ai-chatbot-leadgen-pro' ),
						$results['enqueued'],
						$results['skipped']
					), 'success' );
				}
				break;

			case 'rescore':
				if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Realtime_Scorer' ) ) {
					$scorer = new WP_AI_Chatbot_LeadGen_Pro_Realtime_Scorer();
					foreach ( $lead_ids as $lead_id ) {
						$scorer->force_recalculate( $lead_id );
					}
					$this->add_admin_notice( sprintf(
						/* translators: %d: number of leads */
						__( '%d lead(s) rescored.', 'wp-ai-chatbot-leadgen-pro' ),
						count( $lead_ids )
					), 'success' );
				}
				break;
		}
	}

	/**
	 * Add admin notice.
	 *
	 * @since 1.0.0
	 * @param string $message Notice message.
	 * @param string $type    Notice type (success, error, warning, info).
	 */
	private function add_admin_notice( $message, $type = 'info' ) {
		add_settings_error(
			'wp_ai_chatbot_leads',
			'bulk_action_' . $type,
			$message,
			$type
		);
	}

	/**
	 * Export leads to CSV.
	 *
	 * @since 1.0.0
	 * @param array $lead_ids Lead IDs to export.
	 */
	private function export_to_csv( $lead_ids ) {
		$leads = array();
		foreach ( $lead_ids as $lead_id ) {
			$lead = $this->storage->get( $lead_id );
			if ( $lead ) {
				$leads[] = $lead;
			}
		}

		if ( empty( $leads ) ) {
			return;
		}

		// Store for download
		set_transient( 'wp_ai_chatbot_export_leads', $leads, HOUR_IN_SECONDS );

		// Redirect to download
		wp_redirect( add_query_arg( array(
			'page'   => 'wp-ai-chatbot-leads',
			'action' => 'download_csv',
			'nonce'  => wp_create_nonce( 'download_csv' ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Prepare items for display.
	 *
	 * @since 1.0.0
	 */
	public function prepare_items() {
		// Process bulk actions first
		$this->process_bulk_action();

		// Set up columns
		$columns = $this->get_columns();
		$hidden = get_hidden_columns( $this->screen );
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Get current page
		$per_page = $this->get_items_per_page( 'leads_per_page', 20 );
		$current_page = $this->get_pagenum();

		// Build query args
		$args = array(
			'limit'  => $per_page,
			'offset' => ( $current_page - 1 ) * $per_page,
		);

		// Handle sorting
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( $_REQUEST['orderby'] ) : 'created_at';
		$order = isset( $_REQUEST['order'] ) ? sanitize_text_field( $_REQUEST['order'] ) : 'DESC';

		$args['orderby'] = $orderby;
		$args['order'] = $order;

		// Handle filters
		if ( ! empty( $_REQUEST['status'] ) ) {
			$args['status'] = sanitize_text_field( $_REQUEST['status'] );
		}

		if ( ! empty( $_REQUEST['grade'] ) ) {
			$args['grade'] = sanitize_text_field( $_REQUEST['grade'] );
		}

		if ( ! empty( $_REQUEST['source'] ) ) {
			$args['source'] = sanitize_text_field( $_REQUEST['source'] );
		}

		if ( ! empty( $_REQUEST['date_from'] ) ) {
			$args['date_from'] = sanitize_text_field( $_REQUEST['date_from'] );
		}

		if ( ! empty( $_REQUEST['date_to'] ) ) {
			$args['date_to'] = sanitize_text_field( $_REQUEST['date_to'] );
		}

		if ( ! empty( $_REQUEST['score_min'] ) ) {
			$args['score_min'] = intval( $_REQUEST['score_min'] );
		}

		if ( ! empty( $_REQUEST['score_max'] ) ) {
			$args['score_max'] = intval( $_REQUEST['score_max'] );
		}

		// Handle search
		if ( ! empty( $_REQUEST['s'] ) ) {
			$args['search'] = sanitize_text_field( $_REQUEST['s'] );
		}

		// Get leads
		$result = $this->storage->get_all( $args );

		$this->items = $result['leads'];
		$total_items = $result['total'];

		// Set pagination
		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		) );
	}

	/**
	 * Render the checkbox column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Checkbox HTML.
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="lead[]" value="%d" />',
			$item['id']
		);
	}

	/**
	 * Render the name column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_name( $item ) {
		$name = esc_html( $item['name'] ?: __( '(No name)', 'wp-ai-chatbot-leadgen-pro' ) );

		// Build row actions
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( array(
					'page'    => 'wp-ai-chatbot-leads',
					'action'  => 'view',
					'lead_id' => $item['id'],
				), admin_url( 'admin.php' ) ) ),
				__( 'View', 'wp-ai-chatbot-leadgen-pro' )
			),
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( array(
					'page'    => 'wp-ai-chatbot-leads',
					'action'  => 'edit',
					'lead_id' => $item['id'],
				), admin_url( 'admin.php' ) ) ),
				__( 'Edit', 'wp-ai-chatbot-leadgen-pro' )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( array(
					'page'    => 'wp-ai-chatbot-leads',
					'action'  => 'delete',
					'lead_id' => $item['id'],
				), admin_url( 'admin.php' ) ), 'delete_lead_' . $item['id'] ) ),
				esc_js( __( 'Are you sure you want to delete this lead?', 'wp-ai-chatbot-leadgen-pro' ) ),
				__( 'Delete', 'wp-ai-chatbot-leadgen-pro' )
			),
		);

		// Add avatar if email exists
		$avatar = '';
		if ( ! empty( $item['email'] ) ) {
			$avatar = get_avatar( $item['email'], 32, '', '', array( 'class' => 'lead-avatar' ) );
		}

		return sprintf(
			'<div class="lead-name-cell">%s<strong>%s</strong></div>%s',
			$avatar,
			$name,
			$this->row_actions( $actions )
		);
	}

	/**
	 * Render the email column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_email( $item ) {
		if ( empty( $item['email'] ) ) {
			return '<span class="no-value">—</span>';
		}

		$email = esc_html( $item['email'] );
		$verified = ! empty( $item['custom_fields']['email_verified'] );

		return sprintf(
			'<a href="mailto:%s">%s</a>%s',
			esc_attr( $item['email'] ),
			$email,
			$verified ? ' <span class="dashicons dashicons-yes-alt" title="' . esc_attr__( 'Verified', 'wp-ai-chatbot-leadgen-pro' ) . '"></span>' : ''
		);
	}

	/**
	 * Render the company column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_company( $item ) {
		$company = $item['company'] ?? '';
		$custom_fields = $item['custom_fields'] ?? array();

		if ( empty( $company ) && ! empty( $custom_fields['company_name'] ) ) {
			$company = $custom_fields['company_name'];
		}

		if ( empty( $company ) ) {
			return '<span class="no-value">—</span>';
		}

		$output = esc_html( $company );

		// Add company domain link if available
		if ( ! empty( $custom_fields['company_domain'] ) ) {
			$output = sprintf(
				'<a href="https://%s" target="_blank" rel="noopener">%s</a>',
				esc_attr( $custom_fields['company_domain'] ),
				$output
			);
		}

		// Add company size badge if available
		if ( ! empty( $custom_fields['company_size'] ) ) {
			$output .= sprintf(
				' <span class="company-size-badge">%s</span>',
				esc_html( $custom_fields['company_size'] )
			);
		}

		return $output;
	}

	/**
	 * Render the score column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_score( $item ) {
		$score = intval( $item['score'] ?? 0 );

		// Determine score color class
		$class = 'score-low';
		if ( $score >= 70 ) {
			$class = 'score-high';
		} elseif ( $score >= 40 ) {
			$class = 'score-medium';
		}

		// Score breakdown tooltip
		$breakdown = array();
		if ( ! empty( $item['scoring_breakdown'] ) ) {
			$scoring = $item['scoring_breakdown'];
			if ( isset( $scoring['behavioral'] ) ) {
				$breakdown[] = sprintf( __( 'Behavioral: %d', 'wp-ai-chatbot-leadgen-pro' ), $scoring['behavioral'] );
			}
			if ( isset( $scoring['intent'] ) ) {
				$breakdown[] = sprintf( __( 'Intent: %d', 'wp-ai-chatbot-leadgen-pro' ), $scoring['intent'] );
			}
			if ( isset( $scoring['qualification'] ) ) {
				$breakdown[] = sprintf( __( 'Qualification: %d', 'wp-ai-chatbot-leadgen-pro' ), $scoring['qualification'] );
			}
		}

		$tooltip = ! empty( $breakdown ) ? implode( "\n", $breakdown ) : '';

		return sprintf(
			'<span class="lead-score %s" title="%s">%d</span>',
			esc_attr( $class ),
			esc_attr( $tooltip ),
			$score
		);
	}

	/**
	 * Render the grade column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_grade( $item ) {
		$grade = $item['grade'] ?? 'N/A';

		$grade_classes = array(
			'A+' => 'grade-a-plus',
			'A'  => 'grade-a',
			'B'  => 'grade-b',
			'C'  => 'grade-c',
			'D'  => 'grade-d',
			'F'  => 'grade-f',
			'DQ' => 'grade-dq',
		);

		$class = $grade_classes[ $grade ] ?? 'grade-unknown';

		return sprintf(
			'<span class="lead-grade %s">%s</span>',
			esc_attr( $class ),
			esc_html( $grade )
		);
	}

	/**
	 * Render the status column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_status( $item ) {
		$status = $item['status'] ?? 'new';

		$status_labels = array(
			'new'          => __( 'New', 'wp-ai-chatbot-leadgen-pro' ),
			'qualified'    => __( 'Qualified', 'wp-ai-chatbot-leadgen-pro' ),
			'contacted'    => __( 'Contacted', 'wp-ai-chatbot-leadgen-pro' ),
			'converted'    => __( 'Converted', 'wp-ai-chatbot-leadgen-pro' ),
			'disqualified' => __( 'Disqualified', 'wp-ai-chatbot-leadgen-pro' ),
		);

		$label = $status_labels[ $status ] ?? ucfirst( $status );

		return sprintf(
			'<span class="lead-status status-%s">%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Render the source column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_source( $item ) {
		$source = $item['source'] ?? 'chat';
		$utm = $item['utm_params'] ?? array();

		$source_icons = array(
			'chat'     => 'dashicons-format-chat',
			'form'     => 'dashicons-feedback',
			'popup'    => 'dashicons-welcome-widgets-menus',
			'api'      => 'dashicons-rest-api',
			'import'   => 'dashicons-upload',
		);

		$icon = $source_icons[ $source ] ?? 'dashicons-marker';
		$output = sprintf(
			'<span class="dashicons %s" title="%s"></span> %s',
			esc_attr( $icon ),
			esc_attr( ucfirst( $source ) ),
			esc_html( ucfirst( $source ) )
		);

		// Add UTM info if available
		if ( ! empty( $utm['utm_source'] ) || ! empty( $utm['utm_campaign'] ) ) {
			$utm_text = array();
			if ( ! empty( $utm['utm_source'] ) ) {
				$utm_text[] = $utm['utm_source'];
			}
			if ( ! empty( $utm['utm_campaign'] ) ) {
				$utm_text[] = $utm['utm_campaign'];
			}
			$output .= sprintf(
				'<br><small class="utm-info">%s</small>',
				esc_html( implode( ' / ', $utm_text ) )
			);
		}

		return $output;
	}

	/**
	 * Render the conversations column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_conversations( $item ) {
		$count = intval( $item['conversation_count'] ?? 0 );

		if ( $count === 0 ) {
			return '<span class="no-value">0</span>';
		}

		return sprintf(
			'<a href="%s">%d</a>',
			esc_url( add_query_arg( array(
				'page'    => 'wp-ai-chatbot-conversations',
				'lead_id' => $item['id'],
			), admin_url( 'admin.php' ) ) ),
			$count
		);
	}

	/**
	 * Render the created_at column.
	 *
	 * @since 1.0.0
	 * @param array $item Lead data.
	 * @return string Column HTML.
	 */
	public function column_created_at( $item ) {
		$timestamp = strtotime( $item['created_at'] );

		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
			esc_html( human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'wp-ai-chatbot-leadgen-pro' ) )
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @since 1.0.0
	 * @param array  $item        Lead data.
	 * @param string $column_name Column name.
	 * @return string Column HTML.
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
	}

	/**
	 * Render extra tablenav (filters).
	 *
	 * @since 1.0.0
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_status = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : '';
		$current_grade = isset( $_REQUEST['grade'] ) ? sanitize_text_field( $_REQUEST['grade'] ) : '';
		$current_source = isset( $_REQUEST['source'] ) ? sanitize_text_field( $_REQUEST['source'] ) : '';
		$date_from = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( $_REQUEST['date_from'] ) : '';
		$date_to = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( $_REQUEST['date_to'] ) : '';
		$score_min = isset( $_REQUEST['score_min'] ) ? intval( $_REQUEST['score_min'] ) : '';
		$score_max = isset( $_REQUEST['score_max'] ) ? intval( $_REQUEST['score_max'] ) : '';

		echo '<div class="alignleft actions">';

		// Status filter
		$statuses = array(
			''             => __( 'All Statuses', 'wp-ai-chatbot-leadgen-pro' ),
			'new'          => __( 'New', 'wp-ai-chatbot-leadgen-pro' ),
			'qualified'    => __( 'Qualified', 'wp-ai-chatbot-leadgen-pro' ),
			'contacted'    => __( 'Contacted', 'wp-ai-chatbot-leadgen-pro' ),
			'converted'    => __( 'Converted', 'wp-ai-chatbot-leadgen-pro' ),
			'disqualified' => __( 'Disqualified', 'wp-ai-chatbot-leadgen-pro' ),
		);

		echo '<select name="status" id="filter-by-status">';
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current_status, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		// Grade filter
		$grades = array(
			''   => __( 'All Grades', 'wp-ai-chatbot-leadgen-pro' ),
			'A+' => 'A+',
			'A'  => 'A',
			'B'  => 'B',
			'C'  => 'C',
			'D'  => 'D',
			'F'  => 'F',
			'DQ' => __( 'Disqualified', 'wp-ai-chatbot-leadgen-pro' ),
		);

		echo '<select name="grade" id="filter-by-grade">';
		foreach ( $grades as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current_grade, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		// Source filter
		$sources = array(
			''       => __( 'All Sources', 'wp-ai-chatbot-leadgen-pro' ),
			'chat'   => __( 'Chat', 'wp-ai-chatbot-leadgen-pro' ),
			'form'   => __( 'Form', 'wp-ai-chatbot-leadgen-pro' ),
			'popup'  => __( 'Popup', 'wp-ai-chatbot-leadgen-pro' ),
			'api'    => __( 'API', 'wp-ai-chatbot-leadgen-pro' ),
			'import' => __( 'Import', 'wp-ai-chatbot-leadgen-pro' ),
		);

		echo '<select name="source" id="filter-by-source">';
		foreach ( $sources as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current_source, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'wp-ai-chatbot-leadgen-pro' ), '', 'filter_action', false );

		// Advanced filters toggle
		echo ' <button type="button" class="button" id="toggle-advanced-filters">' 
			. esc_html__( 'Advanced', 'wp-ai-chatbot-leadgen-pro' ) . '</button>';

		echo '</div>';

		// Advanced filters (hidden by default)
		echo '<div class="alignleft actions advanced-filters" style="display:none;">';

		// Date range
		printf(
			'<label>%s</label> <input type="date" name="date_from" value="%s" />',
			esc_html__( 'From:', 'wp-ai-chatbot-leadgen-pro' ),
			esc_attr( $date_from )
		);

		printf(
			'<label>%s</label> <input type="date" name="date_to" value="%s" />',
			esc_html__( 'To:', 'wp-ai-chatbot-leadgen-pro' ),
			esc_attr( $date_to )
		);

		// Score range
		printf(
			'<label>%s</label> <input type="number" name="score_min" value="%s" min="0" max="100" placeholder="0" style="width:60px" />',
			esc_html__( 'Score:', 'wp-ai-chatbot-leadgen-pro' ),
			esc_attr( $score_min )
		);

		printf(
			'- <input type="number" name="score_max" value="%s" min="0" max="100" placeholder="100" style="width:60px" />',
			esc_attr( $score_max )
		);

		echo '</div>';
	}

	/**
	 * Display when no items found.
	 *
	 * @since 1.0.0
	 */
	public function no_items() {
		esc_html_e( 'No leads found.', 'wp-ai-chatbot-leadgen-pro' );
	}

	/**
	 * Get views (status tabs).
	 *
	 * @since 1.0.0
	 * @return array Views.
	 */
	protected function get_views() {
		$views = array();
		$current = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : '';
		$base_url = remove_query_arg( array( 'status', 'paged' ) );

		// Get counts
		$counts = $this->get_status_counts();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $base_url ),
			empty( $current ) ? ' class="current"' : '',
			__( 'All', 'wp-ai-chatbot-leadgen-pro' ),
			$counts['total']
		);

		$statuses = array(
			'new'          => __( 'New', 'wp-ai-chatbot-leadgen-pro' ),
			'qualified'    => __( 'Qualified', 'wp-ai-chatbot-leadgen-pro' ),
			'contacted'    => __( 'Contacted', 'wp-ai-chatbot-leadgen-pro' ),
			'converted'    => __( 'Converted', 'wp-ai-chatbot-leadgen-pro' ),
			'disqualified' => __( 'Disqualified', 'wp-ai-chatbot-leadgen-pro' ),
		);

		foreach ( $statuses as $status => $label ) {
			$count = $counts[ $status ] ?? 0;
			if ( $count > 0 || $status === $current ) {
				$views[ $status ] = sprintf(
					'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
					esc_url( add_query_arg( 'status', $status, $base_url ) ),
					$current === $status ? ' class="current"' : '',
					$label,
					$count
				);
			}
		}

		return $views;
	}

	/**
	 * Get status counts.
	 *
	 * @since 1.0.0
	 * @return array Counts by status.
	 */
	private function get_status_counts() {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_leads';
		$counts = array( 'total' => 0 );

		$results = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
			ARRAY_A
		);

		foreach ( $results as $row ) {
			$counts[ $row['status'] ] = intval( $row['count'] );
			$counts['total'] += intval( $row['count'] );
		}

		return $counts;
	}
}






