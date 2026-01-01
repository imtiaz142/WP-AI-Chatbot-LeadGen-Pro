<?php
/**
 * Leads Admin Page.
 *
 * Main admin interface for lead management with list view,
 * single lead view, edit form, and statistics.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/admin
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Leads_Admin_Page {

	/**
	 * Lead storage instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Lead_Storage
	 */
	private $storage;

	/**
	 * Leads list table instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Leads_List_Table
	 */
	private $list_table;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 10, 3 );
	}

	/**
	 * Add menu page.
	 *
	 * @since 1.0.0
	 */
	public function add_menu_page() {
		$hook = add_submenu_page(
			'wp-ai-chatbot',
			__( 'Leads', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'Leads', 'wp-ai-chatbot-leadgen-pro' ),
			'manage_options',
			'wp-ai-chatbot-leads',
			array( $this, 'render_page' )
		);

		add_action( "load-{$hook}", array( $this, 'add_screen_options' ) );
	}

	/**
	 * Add screen options.
	 *
	 * @since 1.0.0
	 */
	public function add_screen_options() {
		$option = 'per_page';
		$args = array(
			'label'   => __( 'Leads per page', 'wp-ai-chatbot-leadgen-pro' ),
			'default' => 20,
			'option'  => 'leads_per_page',
		);

		add_screen_option( $option, $args );

		// Initialize list table
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-leads-list-table.php';
		$this->list_table = new WP_AI_Chatbot_LeadGen_Pro_Leads_List_Table();
	}

	/**
	 * Set screen option.
	 *
	 * @since 1.0.0
	 * @param mixed  $status Current value.
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 * @return mixed Value to save.
	 */
	public function set_screen_option( $status, $option, $value ) {
		if ( 'leads_per_page' === $option ) {
			return $value;
		}
		return $status;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 1.0.0
	 * @param string $hook Current page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'wp-ai-chatbot-leads' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wp-ai-chatbot-leads-admin',
			plugin_dir_url( dirname( dirname( __FILE__ ) ) ) . 'assets/css/leads-admin.css',
			array(),
			WP_AI_CHATBOT_LEADGEN_PRO_VERSION
		);

		wp_enqueue_script(
			'wp-ai-chatbot-leads-admin',
			plugin_dir_url( dirname( dirname( __FILE__ ) ) ) . 'assets/js/leads-admin.js',
			array( 'jquery', 'wp-util' ),
			WP_AI_CHATBOT_LEADGEN_PRO_VERSION,
			true
		);

		wp_localize_script( 'wp-ai-chatbot-leads-admin', 'wpAiChatbotLeadsAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wp_ai_chatbot_leads_admin' ),
			'strings' => array(
				'confirmDelete'   => __( 'Are you sure you want to delete this lead?', 'wp-ai-chatbot-leadgen-pro' ),
				'confirmBulkDelete' => __( 'Are you sure you want to delete the selected leads?', 'wp-ai-chatbot-leadgen-pro' ),
				'loading'         => __( 'Loading...', 'wp-ai-chatbot-leadgen-pro' ),
				'error'           => __( 'An error occurred. Please try again.', 'wp-ai-chatbot-leadgen-pro' ),
			),
		) );
	}

	/**
	 * Handle admin actions.
	 *
	 * @since 1.0.0
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wp-ai-chatbot-leads' ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';

		// Initialize storage
		if ( class_exists( 'WP_AI_Chatbot_LeadGen_Pro_Lead_Storage' ) ) {
			$this->storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
		}

		switch ( $action ) {
			case 'delete':
				$this->handle_delete();
				break;

			case 'download_csv':
				$this->handle_csv_download();
				break;

			case 'save':
				$this->handle_save();
				break;
		}
	}

	/**
	 * Handle lead deletion.
	 *
	 * @since 1.0.0
	 */
	private function handle_delete() {
		$lead_id = isset( $_GET['lead_id'] ) ? intval( $_GET['lead_id'] ) : 0;

		if ( ! $lead_id ) {
			return;
		}

		// Verify nonce
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'delete_lead_' . $lead_id ) ) {
			wp_die( __( 'Security check failed.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		if ( $this->storage && $this->storage->delete( $lead_id ) ) {
			wp_redirect( add_query_arg( array(
				'page'    => 'wp-ai-chatbot-leads',
				'deleted' => 1,
			), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Handle CSV download.
	 *
	 * @since 1.0.0
	 */
	private function handle_csv_download() {
		if ( ! wp_verify_nonce( $_GET['nonce'] ?? '', 'download_csv' ) ) {
			wp_die( __( 'Security check failed.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		$leads = get_transient( 'wp_ai_chatbot_export_leads' );
		delete_transient( 'wp_ai_chatbot_export_leads' );

		if ( empty( $leads ) ) {
			wp_redirect( add_query_arg( array(
				'page'  => 'wp-ai-chatbot-leads',
				'error' => 'no_export_data',
			), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Output CSV
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="leads-' . date( 'Y-m-d-His' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		// Headers
		fputcsv( $output, array(
			'ID',
			'Name',
			'Email',
			'Phone',
			'Company',
			'Score',
			'Grade',
			'Status',
			'Source',
			'UTM Source',
			'UTM Medium',
			'UTM Campaign',
			'Created',
			'Updated',
		) );

		// Data rows
		foreach ( $leads as $lead ) {
			$utm = $lead['utm_params'] ?? array();
			fputcsv( $output, array(
				$lead['id'],
				$lead['name'] ?? '',
				$lead['email'] ?? '',
				$lead['phone'] ?? '',
				$lead['company'] ?? '',
				$lead['score'] ?? 0,
				$lead['grade'] ?? '',
				$lead['status'] ?? '',
				$lead['source'] ?? '',
				$utm['utm_source'] ?? '',
				$utm['utm_medium'] ?? '',
				$utm['utm_campaign'] ?? '',
				$lead['created_at'] ?? '',
				$lead['updated_at'] ?? '',
			) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Handle lead save.
	 *
	 * @since 1.0.0
	 */
	private function handle_save() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'save_lead' ) ) {
			return;
		}

		$lead_id = isset( $_POST['lead_id'] ) ? intval( $_POST['lead_id'] ) : 0;

		$data = array(
			'name'    => sanitize_text_field( $_POST['name'] ?? '' ),
			'email'   => sanitize_email( $_POST['email'] ?? '' ),
			'phone'   => sanitize_text_field( $_POST['phone'] ?? '' ),
			'company' => sanitize_text_field( $_POST['company'] ?? '' ),
			'status'  => sanitize_text_field( $_POST['status'] ?? 'new' ),
		);

		// Custom fields
		if ( ! empty( $_POST['custom_fields'] ) && is_array( $_POST['custom_fields'] ) ) {
			$custom_fields = array();
			foreach ( $_POST['custom_fields'] as $key => $value ) {
				$custom_fields[ sanitize_key( $key ) ] = sanitize_text_field( $value );
			}
			$data['custom_fields'] = $custom_fields;
		}

		// Notes
		if ( isset( $_POST['notes'] ) ) {
			$data['notes'] = sanitize_textarea_field( $_POST['notes'] );
		}

		if ( $lead_id ) {
			$this->storage->update( $lead_id, $data );
			$message = 'updated';
		} else {
			$lead_id = $this->storage->create( $data );
			$message = 'created';
		}

		wp_redirect( add_query_arg( array(
			'page'    => 'wp-ai-chatbot-leads',
			'action'  => 'view',
			'lead_id' => $lead_id,
			'message' => $message,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
		$lead_id = isset( $_GET['lead_id'] ) ? intval( $_GET['lead_id'] ) : 0;

		// Show notices
		settings_errors( 'wp_ai_chatbot_leads' );

		// Show success messages
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' 
				. esc_html__( 'Lead deleted successfully.', 'wp-ai-chatbot-leadgen-pro' ) 
				. '</p></div>';
		}

		if ( isset( $_GET['message'] ) ) {
			$messages = array(
				'created' => __( 'Lead created successfully.', 'wp-ai-chatbot-leadgen-pro' ),
				'updated' => __( 'Lead updated successfully.', 'wp-ai-chatbot-leadgen-pro' ),
			);
			$msg = $messages[ $_GET['message'] ] ?? '';
			if ( $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}

		switch ( $action ) {
			case 'view':
				$this->render_single_view( $lead_id );
				break;

			case 'edit':
			case 'add':
				$this->render_edit_form( $lead_id );
				break;

			default:
				$this->render_list_view();
				break;
		}
	}

	/**
	 * Render the list view.
	 *
	 * @since 1.0.0
	 */
	private function render_list_view() {
		// Initialize list table if not already done
		if ( ! $this->list_table ) {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-leads-list-table.php';
			$this->list_table = new WP_AI_Chatbot_LeadGen_Pro_Leads_List_Table();
		}

		$this->list_table->prepare_items();

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Leads', 'wp-ai-chatbot-leadgen-pro' ); ?></h1>
			
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ai-chatbot-leads', 'action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'wp-ai-chatbot-leadgen-pro' ); ?>
			</a>

			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ai-chatbot-leads', 'action' => 'import' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Import', 'wp-ai-chatbot-leadgen-pro' ); ?>
			</a>

			<hr class="wp-header-end">

			<?php $this->render_summary_cards(); ?>

			<form id="leads-filter" method="get">
				<input type="hidden" name="page" value="wp-ai-chatbot-leads" />
				<?php
				$this->list_table->search_box( __( 'Search Leads', 'wp-ai-chatbot-leadgen-pro' ), 'lead' );
				$this->list_table->views();
				$this->list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render summary cards.
	 *
	 * @since 1.0.0
	 */
	private function render_summary_cards() {
		$stats = $this->get_summary_stats();

		?>
		<div class="leads-summary-cards">
			<div class="summary-card">
				<span class="card-value"><?php echo esc_html( number_format( $stats['total'] ) ); ?></span>
				<span class="card-label"><?php esc_html_e( 'Total Leads', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
			</div>

			<div class="summary-card card-new">
				<span class="card-value"><?php echo esc_html( number_format( $stats['new_today'] ) ); ?></span>
				<span class="card-label"><?php esc_html_e( 'New Today', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
			</div>

			<div class="summary-card card-qualified">
				<span class="card-value"><?php echo esc_html( number_format( $stats['qualified'] ) ); ?></span>
				<span class="card-label"><?php esc_html_e( 'Qualified', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
			</div>

			<div class="summary-card card-score">
				<span class="card-value"><?php echo esc_html( $stats['avg_score'] ); ?></span>
				<span class="card-label"><?php esc_html_e( 'Avg Score', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
			</div>

			<div class="summary-card card-converted">
				<span class="card-value"><?php echo esc_html( $stats['conversion_rate'] ); ?>%</span>
				<span class="card-label"><?php esc_html_e( 'Conversion Rate', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Get summary statistics.
	 *
	 * @since 1.0.0
	 * @return array Statistics.
	 */
	private function get_summary_stats() {
		global $wpdb;

		$table = $wpdb->prefix . 'ai_chatbot_leads';

		$total = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );

		$new_today = intval( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = %s",
				current_time( 'Y-m-d' )
			)
		) );

		$qualified = intval( $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'qualified'"
		) );

		$converted = intval( $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'converted'"
		) );

		$avg_score = floatval( $wpdb->get_var(
			"SELECT AVG(score) FROM {$table}"
		) );

		$conversion_rate = $total > 0 ? round( ( $converted / $total ) * 100, 1 ) : 0;

		return array(
			'total'           => $total,
			'new_today'       => $new_today,
			'qualified'       => $qualified,
			'converted'       => $converted,
			'avg_score'       => round( $avg_score ),
			'conversion_rate' => $conversion_rate,
		);
	}

	/**
	 * Render single lead view.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID.
	 */
	private function render_single_view( $lead_id ) {
		if ( ! $this->storage ) {
			$this->storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
		}

		$lead = $this->storage->get( $lead_id );

		if ( ! $lead ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' 
				. esc_html__( 'Lead not found.', 'wp-ai-chatbot-leadgen-pro' ) 
				. '</p></div></div>';
			return;
		}

		$custom_fields = $lead['custom_fields'] ?? array();
		$utm = $lead['utm_params'] ?? array();
		$scoring = $lead['scoring_breakdown'] ?? array();

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html( $lead['name'] ?: __( 'Lead Details', 'wp-ai-chatbot-leadgen-pro' ) ); ?>
				<span class="lead-grade grade-<?php echo esc_attr( strtolower( str_replace( '+', '-plus', $lead['grade'] ?? 'unknown' ) ) ); ?>">
					<?php echo esc_html( $lead['grade'] ?? 'N/A' ); ?>
				</span>
			</h1>

			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ai-chatbot-leads', 'action' => 'edit', 'lead_id' => $lead_id ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Edit', 'wp-ai-chatbot-leadgen-pro' ); ?>
			</a>

			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ai-chatbot-leads' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( '← Back to List', 'wp-ai-chatbot-leadgen-pro' ); ?>
			</a>

			<hr class="wp-header-end">

			<div class="lead-view-container">
				<!-- Main Info -->
				<div class="lead-section lead-main-info">
					<div class="lead-avatar-large">
						<?php echo get_avatar( $lead['email'] ?? '', 120 ); ?>
					</div>

					<div class="lead-contact-info">
						<h2><?php echo esc_html( $lead['name'] ?: __( '(No name)', 'wp-ai-chatbot-leadgen-pro' ) ); ?></h2>

						<?php if ( ! empty( $lead['email'] ) ) : ?>
							<p><span class="dashicons dashicons-email"></span> 
								<a href="mailto:<?php echo esc_attr( $lead['email'] ); ?>"><?php echo esc_html( $lead['email'] ); ?></a>
								<?php if ( ! empty( $custom_fields['email_verified'] ) ) : ?>
									<span class="verified-badge" title="<?php esc_attr_e( 'Verified', 'wp-ai-chatbot-leadgen-pro' ); ?>">✓</span>
								<?php endif; ?>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $lead['phone'] ) ) : ?>
							<p><span class="dashicons dashicons-phone"></span> 
								<a href="tel:<?php echo esc_attr( $lead['phone'] ); ?>"><?php echo esc_html( $lead['phone'] ); ?></a>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $lead['company'] ) ) : ?>
							<p><span class="dashicons dashicons-building"></span> <?php echo esc_html( $lead['company'] ); ?></p>
						<?php endif; ?>

						<p class="lead-status">
							<span class="status-badge status-<?php echo esc_attr( $lead['status'] ?? 'new' ); ?>">
								<?php echo esc_html( ucfirst( $lead['status'] ?? 'new' ) ); ?>
							</span>
						</p>
					</div>
				</div>

				<!-- Score Card -->
				<div class="lead-section lead-score-card">
					<h3><?php esc_html_e( 'Lead Score', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					
					<div class="score-display">
						<div class="score-circle">
							<span class="score-value"><?php echo esc_html( $lead['score'] ?? 0 ); ?></span>
							<span class="score-max">/100</span>
						</div>
					</div>

					<?php if ( ! empty( $scoring ) ) : ?>
						<div class="score-breakdown">
							<?php if ( isset( $scoring['behavioral'] ) ) : ?>
								<div class="score-item">
									<span class="score-label"><?php esc_html_e( 'Behavioral', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
									<span class="score-bar"><span style="width: <?php echo esc_attr( $scoring['behavioral'] ); ?>%"></span></span>
									<span class="score-num"><?php echo esc_html( $scoring['behavioral'] ); ?></span>
								</div>
							<?php endif; ?>

							<?php if ( isset( $scoring['intent'] ) ) : ?>
								<div class="score-item">
									<span class="score-label"><?php esc_html_e( 'Intent', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
									<span class="score-bar"><span style="width: <?php echo esc_attr( $scoring['intent'] ); ?>%"></span></span>
									<span class="score-num"><?php echo esc_html( $scoring['intent'] ); ?></span>
								</div>
							<?php endif; ?>

							<?php if ( isset( $scoring['qualification'] ) ) : ?>
								<div class="score-item">
									<span class="score-label"><?php esc_html_e( 'Qualification', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
									<span class="score-bar"><span style="width: <?php echo esc_attr( $scoring['qualification'] ); ?>%"></span></span>
									<span class="score-num"><?php echo esc_html( $scoring['qualification'] ); ?></span>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<!-- Enriched Data -->
				<?php if ( ! empty( $custom_fields ) ) : ?>
					<div class="lead-section lead-enriched-data">
						<h3><?php esc_html_e( 'Enriched Data', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
						
						<table class="widefat striped">
							<tbody>
								<?php foreach ( $custom_fields as $key => $value ) : 
									if ( empty( $value ) || in_array( $key, array( 'email_verified' ), true ) ) continue;
								?>
									<tr>
										<th><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></th>
										<td><?php echo esc_html( is_array( $value ) ? wp_json_encode( $value ) : $value ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<!-- UTM Data -->
				<?php if ( ! empty( $utm ) ) : ?>
					<div class="lead-section lead-utm-data">
						<h3><?php esc_html_e( 'Attribution', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
						
						<table class="widefat striped">
							<tbody>
								<?php if ( ! empty( $utm['utm_source'] ) ) : ?>
									<tr><th>Source</th><td><?php echo esc_html( $utm['utm_source'] ); ?></td></tr>
								<?php endif; ?>
								<?php if ( ! empty( $utm['utm_medium'] ) ) : ?>
									<tr><th>Medium</th><td><?php echo esc_html( $utm['utm_medium'] ); ?></td></tr>
								<?php endif; ?>
								<?php if ( ! empty( $utm['utm_campaign'] ) ) : ?>
									<tr><th>Campaign</th><td><?php echo esc_html( $utm['utm_campaign'] ); ?></td></tr>
								<?php endif; ?>
								<?php if ( ! empty( $utm['utm_term'] ) ) : ?>
									<tr><th>Term</th><td><?php echo esc_html( $utm['utm_term'] ); ?></td></tr>
								<?php endif; ?>
								<?php if ( ! empty( $utm['utm_content'] ) ) : ?>
									<tr><th>Content</th><td><?php echo esc_html( $utm['utm_content'] ); ?></td></tr>
								<?php endif; ?>
								<?php if ( ! empty( $lead['source'] ) ) : ?>
									<tr><th>Lead Source</th><td><?php echo esc_html( ucfirst( $lead['source'] ) ); ?></td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<!-- Timestamps -->
				<div class="lead-section lead-timestamps">
					<h3><?php esc_html_e( 'Timeline', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					
					<ul class="lead-timeline">
						<li>
							<span class="timeline-icon dashicons dashicons-plus-alt"></span>
							<span class="timeline-label"><?php esc_html_e( 'Created', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
							<span class="timeline-value"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $lead['created_at'] ) ) ); ?></span>
						</li>
						<li>
							<span class="timeline-icon dashicons dashicons-update"></span>
							<span class="timeline-label"><?php esc_html_e( 'Updated', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
							<span class="timeline-value"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $lead['updated_at'] ) ) ); ?></span>
						</li>
						<?php if ( ! empty( $custom_fields['enrichment_date'] ) ) : ?>
							<li>
								<span class="timeline-icon dashicons dashicons-admin-site-alt3"></span>
								<span class="timeline-label"><?php esc_html_e( 'Enriched', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
								<span class="timeline-value"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $custom_fields['enrichment_date'] ) ) ); ?></span>
							</li>
						<?php endif; ?>
					</ul>
				</div>

				<!-- Actions -->
				<div class="lead-section lead-actions">
					<h3><?php esc_html_e( 'Actions', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					
					<div class="action-buttons">
						<button type="button" class="button button-primary" id="enrich-lead" data-lead-id="<?php echo esc_attr( $lead_id ); ?>">
							<span class="dashicons dashicons-admin-site-alt3"></span>
							<?php esc_html_e( 'Enrich Data', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</button>

						<button type="button" class="button" id="rescore-lead" data-lead-id="<?php echo esc_attr( $lead_id ); ?>">
							<span class="dashicons dashicons-chart-line"></span>
							<?php esc_html_e( 'Recalculate Score', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</button>

						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ai-chatbot-conversations', 'lead_id' => $lead_id ), admin_url( 'admin.php' ) ) ); ?>" class="button">
							<span class="dashicons dashicons-format-chat"></span>
							<?php esc_html_e( 'View Conversations', 'wp-ai-chatbot-leadgen-pro' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render edit form.
	 *
	 * @since 1.0.0
	 * @param int $lead_id Lead ID (0 for new).
	 */
	private function render_edit_form( $lead_id ) {
		if ( ! $this->storage ) {
			$this->storage = new WP_AI_Chatbot_LeadGen_Pro_Lead_Storage();
		}

		$lead = $lead_id ? $this->storage->get( $lead_id ) : array();
		$is_new = empty( $lead );

		$lead = wp_parse_args( $lead, array(
			'name'    => '',
			'email'   => '',
			'phone'   => '',
			'company' => '',
			'status'  => 'new',
			'notes'   => '',
			'custom_fields' => array(),
		) );

		?>
		<div class="wrap">
			<h1>
				<?php echo $is_new 
					? esc_html__( 'Add New Lead', 'wp-ai-chatbot-leadgen-pro' ) 
					: esc_html__( 'Edit Lead', 'wp-ai-chatbot-leadgen-pro' ); 
				?>
			</h1>

			<form method="post" action="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ai-chatbot-leads', 'action' => 'save' ), admin_url( 'admin.php' ) ) ); ?>">
				<?php wp_nonce_field( 'save_lead' ); ?>
				<input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead_id ); ?>" />

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="name"><?php esc_html_e( 'Name', 'wp-ai-chatbot-leadgen-pro' ); ?></label>
						</th>
						<td>
							<input type="text" name="name" id="name" class="regular-text" value="<?php echo esc_attr( $lead['name'] ); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="email"><?php esc_html_e( 'Email', 'wp-ai-chatbot-leadgen-pro' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<input type="email" name="email" id="email" class="regular-text" value="<?php echo esc_attr( $lead['email'] ); ?>" required />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="phone"><?php esc_html_e( 'Phone', 'wp-ai-chatbot-leadgen-pro' ); ?></label>
						</th>
						<td>
							<input type="tel" name="phone" id="phone" class="regular-text" value="<?php echo esc_attr( $lead['phone'] ); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="company"><?php esc_html_e( 'Company', 'wp-ai-chatbot-leadgen-pro' ); ?></label>
						</th>
						<td>
							<input type="text" name="company" id="company" class="regular-text" value="<?php echo esc_attr( $lead['company'] ); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="status"><?php esc_html_e( 'Status', 'wp-ai-chatbot-leadgen-pro' ); ?></label>
						</th>
						<td>
							<select name="status" id="status">
								<option value="new" <?php selected( $lead['status'], 'new' ); ?>><?php esc_html_e( 'New', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
								<option value="qualified" <?php selected( $lead['status'], 'qualified' ); ?>><?php esc_html_e( 'Qualified', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
								<option value="contacted" <?php selected( $lead['status'], 'contacted' ); ?>><?php esc_html_e( 'Contacted', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
								<option value="converted" <?php selected( $lead['status'], 'converted' ); ?>><?php esc_html_e( 'Converted', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
								<option value="disqualified" <?php selected( $lead['status'], 'disqualified' ); ?>><?php esc_html_e( 'Disqualified', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="notes"><?php esc_html_e( 'Notes', 'wp-ai-chatbot-leadgen-pro' ); ?></label>
						</th>
						<td>
							<textarea name="notes" id="notes" rows="5" class="large-text"><?php echo esc_textarea( $lead['notes'] ); ?></textarea>
						</td>
					</tr>
				</table>

				<?php submit_button( $is_new ? __( 'Add Lead', 'wp-ai-chatbot-leadgen-pro' ) : __( 'Update Lead', 'wp-ai-chatbot-leadgen-pro' ) ); ?>

				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-ai-chatbot-leads' ), admin_url( 'admin.php' ) ) ); ?>" class="button">
					<?php esc_html_e( 'Cancel', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</a>
			</form>
		</div>
		<?php
	}
}






