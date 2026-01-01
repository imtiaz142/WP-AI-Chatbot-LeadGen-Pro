<?php
/**
 * Content Ingestion Admin UI.
 *
 * Admin interface for content ingestion configuration, sources, scheduling, and status.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/admin
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Content_Ingestion_Admin {

	/**
	 * Config instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Config
	 */
	private $config;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Logger
	 */
	private $logger;

	/**
	 * Content crawler instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Crawler
	 */
	private $crawler;

	/**
	 * Content indexer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Indexer
	 */
	private $indexer;

	/**
	 * Ingestion queue instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Ingestion_Queue
	 */
	private $queue;

	/**
	 * Freshness tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Freshness_Tracker
	 */
	private $freshness_tracker;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->config = WP_AI_Chatbot_LeadGen_Pro_Config::get_site_config();
		$this->logger = WP_AI_Chatbot_LeadGen_Pro_Logger::get_instance();
		$this->crawler = new WP_AI_Chatbot_LeadGen_Pro_Content_Crawler();
		$this->indexer = new WP_AI_Chatbot_LeadGen_Pro_Content_Indexer();
		$this->queue = new WP_AI_Chatbot_LeadGen_Pro_Ingestion_Queue();
		$this->freshness_tracker = new WP_AI_Chatbot_LeadGen_Pro_Content_Freshness_Tracker();
	}

	/**
	 * Register admin hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submissions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_start_indexing', array( $this, 'ajax_start_indexing' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_get_indexing_status', array( $this, 'ajax_get_indexing_status' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_reindex_stale', array( $this, 'ajax_reindex_stale' ) );
	}

	/**
	 * Add admin menu items.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		// Check if main menu exists, if not create it
		global $admin_page_hooks;
		if ( ! isset( $admin_page_hooks['wp-ai-chatbot-leadgen-pro'] ) ) {
			add_menu_page(
				__( 'WP AI Chatbot LeadGen Pro', 'wp-ai-chatbot-leadgen-pro' ),
				__( 'AI Chatbot', 'wp-ai-chatbot-leadgen-pro' ),
				'manage_options',
				'wp-ai-chatbot-leadgen-pro',
				array( $this, 'render_dashboard_page' ),
				'dashicons-format-chat',
				30
			);
		}

		// Add submenu for content ingestion
		add_submenu_page(
			'wp-ai-chatbot-leadgen-pro',
			__( 'Content Ingestion', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'Content Ingestion', 'wp-ai-chatbot-leadgen-pro' ),
			'manage_options',
			'wp-ai-chatbot-content-ingestion',
			array( $this, 'render_content_ingestion_page' )
		);
	}

	/**
	 * Render dashboard page (placeholder).
	 *
	 * @since 1.0.0
	 */
	public function render_dashboard_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP AI Chatbot LeadGen Pro', 'wp-ai-chatbot-leadgen-pro' ); ?></h1>
			<p><?php esc_html_e( 'Welcome to WP AI Chatbot LeadGen Pro. Use the menu on the left to navigate to different sections.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only load on our admin pages
		if ( strpos( $hook, 'wp-ai-chatbot' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'wp-ai-chatbot-content-ingestion-admin',
			WP_AI_CHATBOT_LEADGEN_PRO_URL . 'assets/js/admin-content-ingestion.js',
			array( 'jquery' ),
			WP_AI_CHATBOT_LEADGEN_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-ai-chatbot-content-ingestion-admin',
			'wpAiChatbotIngestion',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_ai_chatbot_ingestion' ),
			)
		);

		wp_enqueue_style(
			'wp-ai-chatbot-content-ingestion-admin',
			WP_AI_CHATBOT_LEADGEN_PRO_URL . 'assets/css/admin-content-ingestion.css',
			array(),
			WP_AI_CHATBOT_LEADGEN_PRO_VERSION
		);
	}

	/**
	 * Handle form submissions.
	 *
	 * @since 1.0.0
	 */
	public function handle_form_submissions() {
		if ( ! isset( $_POST['wp_ai_chatbot_ingestion_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['wp_ai_chatbot_ingestion_nonce'], 'wp_ai_chatbot_ingestion_save' ) ) {
			wp_die( __( 'Security check failed.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		// Handle settings save
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'save_ingestion_settings' ) {
			$this->save_ingestion_settings();
		}

		// Handle manual URL addition
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'add_manual_url' ) {
			$this->add_manual_url();
		}

		// Handle sitemap URL addition
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'add_sitemap_url' ) {
			$this->add_sitemap_url();
		}
	}

	/**
	 * Save ingestion settings.
	 *
	 * @since 1.0.0
	 */
	private function save_ingestion_settings() {
		$settings = array(
			'auto_index_enabled'     => isset( $_POST['auto_index_enabled'] ) ? 1 : 0,
			'index_sitemap'          => isset( $_POST['index_sitemap'] ) ? 1 : 0,
			'index_posts'            => isset( $_POST['index_posts'] ) ? 1 : 0,
			'index_pages'            => isset( $_POST['index_pages'] ) ? 1 : 0,
			'index_woocommerce'      => isset( $_POST['index_woocommerce'] ) ? 1 : 0,
			'reindex_interval'       => sanitize_text_field( $_POST['reindex_interval'] ?? 'weekly' ),
			'chunk_size'             => intval( $_POST['chunk_size'] ?? 1000 ),
			'chunk_overlap'          => intval( $_POST['chunk_overlap'] ?? 200 ),
			'content_freshness_threshold_days' => intval( $_POST['content_freshness_threshold_days'] ?? 30 ),
		);

		foreach ( $settings as $key => $value ) {
			$this->config->set( $key, $value );
		}

		// Update re-indexing schedule
		$scheduled_reindexer = new WP_AI_Chatbot_LeadGen_Pro_Scheduled_Reindexer();
		$scheduled_reindexer->schedule_reindex( $settings['reindex_interval'] );

		add_settings_error(
			'wp_ai_chatbot_ingestion',
			'settings_saved',
			__( 'Settings saved successfully.', 'wp-ai-chatbot-leadgen-pro' ),
			'success'
		);
	}

	/**
	 * Add manual URL.
	 *
	 * @since 1.0.0
	 */
	private function add_manual_url() {
		$url = isset( $_POST['manual_url'] ) ? esc_url_raw( $_POST['manual_url'] ) : '';

		if ( empty( $url ) ) {
			add_settings_error(
				'wp_ai_chatbot_ingestion',
				'invalid_url',
				__( 'Please provide a valid URL.', 'wp-ai-chatbot-leadgen-pro' ),
				'error'
			);
			return;
		}

		$manual_urls = $this->config->get( 'manual_urls', array() );
		if ( ! in_array( $url, $manual_urls, true ) ) {
			$manual_urls[] = $url;
			$this->config->set( 'manual_urls', $manual_urls );

			add_settings_error(
				'wp_ai_chatbot_ingestion',
				'url_added',
				sprintf( __( 'URL added: %s', 'wp-ai-chatbot-leadgen-pro' ), $url ),
				'success'
			);
		} else {
			add_settings_error(
				'wp_ai_chatbot_ingestion',
				'url_exists',
				__( 'URL already exists in the list.', 'wp-ai-chatbot-leadgen-pro' ),
				'error'
			);
		}
	}

	/**
	 * Add sitemap URL.
	 *
	 * @since 1.0.0
	 */
	private function add_sitemap_url() {
		$url = isset( $_POST['sitemap_url'] ) ? esc_url_raw( $_POST['sitemap_url'] ) : '';

		if ( empty( $url ) ) {
			add_settings_error(
				'wp_ai_chatbot_ingestion',
				'invalid_url',
				__( 'Please provide a valid sitemap URL.', 'wp-ai-chatbot-leadgen-pro' ),
				'error'
			);
			return;
		}

		$sitemap_urls = $this->config->get( 'sitemap_urls', array() );
		if ( ! in_array( $url, $sitemap_urls, true ) ) {
			$sitemap_urls[] = $url;
			$this->config->set( 'sitemap_urls', $sitemap_urls );

			add_settings_error(
				'wp_ai_chatbot_ingestion',
				'sitemap_added',
				sprintf( __( 'Sitemap URL added: %s', 'wp-ai-chatbot-leadgen-pro' ), $url ),
				'success'
			);
		} else {
			add_settings_error(
				'wp_ai_chatbot_ingestion',
				'sitemap_exists',
				__( 'Sitemap URL already exists in the list.', 'wp-ai-chatbot-leadgen-pro' ),
				'error'
			);
		}
	}

	/**
	 * Render content ingestion admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_content_ingestion_page() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		// Get current tab
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'overview';

		// Display settings errors
		settings_errors( 'wp_ai_chatbot_ingestion' );

		?>
		<div class="wrap wp-ai-chatbot-ingestion-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="?page=wp-ai-chatbot-content-ingestion&tab=overview" class="nav-tab <?php echo $tab === 'overview' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Overview', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</a>
				<a href="?page=wp-ai-chatbot-content-ingestion&tab=sources" class="nav-tab <?php echo $tab === 'sources' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Sources', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</a>
				<a href="?page=wp-ai-chatbot-content-ingestion&tab=scheduling" class="nav-tab <?php echo $tab === 'scheduling' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Scheduling', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</a>
				<a href="?page=wp-ai-chatbot-content-ingestion&tab=status" class="nav-tab <?php echo $tab === 'status' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Status', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</a>
			</nav>

			<div class="tab-content">
				<?php
				switch ( $tab ) {
					case 'overview':
						$this->render_overview_tab();
						break;

					case 'sources':
						$this->render_sources_tab();
						break;

					case 'scheduling':
						$this->render_scheduling_tab();
						break;

					case 'status':
						$this->render_status_tab();
						break;

					default:
						$this->render_overview_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render overview tab.
	 *
	 * @since 1.0.0
	 */
	private function render_overview_tab() {
		// Get statistics
		$indexing_stats = $this->indexer->get_indexing_stats();
		$queue_stats = $this->queue->get_queue_stats();
		$freshness_stats = $this->freshness_tracker->get_freshness_stats();

		?>
		<div class="wp-ai-chatbot-overview">
			<div class="stats-grid">
				<div class="stat-card">
					<h3><?php esc_html_e( 'Total Chunks', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value"><?php echo esc_html( number_format( $indexing_stats['total_chunks'] ) ); ?></div>
				</div>

				<div class="stat-card">
					<h3><?php esc_html_e( 'Total Embeddings', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value"><?php echo esc_html( number_format( $indexing_stats['total_embeddings'] ) ); ?></div>
				</div>

				<div class="stat-card">
					<h3><?php esc_html_e( 'Unique Sources', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value"><?php echo esc_html( number_format( $indexing_stats['unique_sources'] ) ); ?></div>
				</div>

				<div class="stat-card">
					<h3><?php esc_html_e( 'Pending Jobs', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value"><?php echo esc_html( number_format( $queue_stats['pending'] ) ); ?></div>
				</div>

				<div class="stat-card">
					<h3><?php esc_html_e( 'Fresh Sources', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value"><?php echo esc_html( number_format( $freshness_stats['fresh_sources'] ) ); ?></div>
				</div>

				<div class="stat-card">
					<h3><?php esc_html_e( 'Stale Sources', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value"><?php echo esc_html( number_format( $freshness_stats['stale_sources'] ) ); ?></div>
				</div>
			</div>

			<div class="quick-actions">
				<h2><?php esc_html_e( 'Quick Actions', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
				<p>
					<button type="button" class="button button-primary" id="start-full-indexing">
						<?php esc_html_e( 'Start Full Indexing', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</button>
					<button type="button" class="button" id="reindex-stale">
						<?php esc_html_e( 'Re-index Stale Content', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</button>
					<button type="button" class="button" id="refresh-stats">
						<?php esc_html_e( 'Refresh Statistics', 'wp-ai-chatbot-leadgen-pro' ); ?>
					</button>
				</p>
			</div>

			<div class="content-by-type">
				<h2><?php esc_html_e( 'Content by Source Type', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source Type', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Chunks', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $indexing_stats['by_source_type'] ) ) : ?>
							<?php foreach ( $indexing_stats['by_source_type'] as $type => $count ) : ?>
								<tr>
									<td><?php echo esc_html( ucfirst( $type ) ); ?></td>
									<td><?php echo esc_html( number_format( $count ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="2"><?php esc_html_e( 'No content indexed yet.', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sources tab.
	 *
	 * @since 1.0.0
	 */
	private function render_sources_tab() {
		$auto_index = $this->config->get( 'auto_index_enabled', true );
		$index_sitemap = $this->config->get( 'index_sitemap', true );
		$index_posts = $this->config->get( 'index_posts', true );
		$index_pages = $this->config->get( 'index_pages', true );
		$index_woocommerce = $this->config->get( 'index_woocommerce', false );
		$manual_urls = $this->config->get( 'manual_urls', array() );
		$sitemap_urls = $this->config->get( 'sitemap_urls', array() );

		?>
		<div class="wp-ai-chatbot-sources">
			<form method="post" action="">
				<?php wp_nonce_field( 'wp_ai_chatbot_ingestion_save', 'wp_ai_chatbot_ingestion_nonce' ); ?>
				<input type="hidden" name="action" value="save_ingestion_settings">

				<h2><?php esc_html_e( 'Content Sources', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto Indexing', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_index_enabled" value="1" <?php checked( $auto_index ); ?>>
								<?php esc_html_e( 'Enable automatic content indexing', 'wp-ai-chatbot-leadgen-pro' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Automatically index new content when it is published or updated.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'WordPress Posts', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="index_posts" value="1" <?php checked( $index_posts ); ?>>
								<?php esc_html_e( 'Index WordPress posts', 'wp-ai-chatbot-leadgen-pro' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'WordPress Pages', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="index_pages" value="1" <?php checked( $index_pages ); ?>>
								<?php esc_html_e( 'Index WordPress pages', 'wp-ai-chatbot-leadgen-pro' ); ?>
							</label>
						</td>
					</tr>

					<?php if ( class_exists( 'WooCommerce' ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'WooCommerce Products', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="index_woocommerce" value="1" <?php checked( $index_woocommerce ); ?>>
								<?php esc_html_e( 'Index WooCommerce products', 'wp-ai-chatbot-leadgen-pro' ); ?>
							</label>
						</td>
					</tr>
					<?php endif; ?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Sitemaps', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="index_sitemap" value="1" <?php checked( $index_sitemap ); ?>>
								<?php esc_html_e( 'Index URLs from sitemaps', 'wp-ai-chatbot-leadgen-pro' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'wp-ai-chatbot-leadgen-pro' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Sitemap URLs', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
			<form method="post" action="" class="inline-form">
				<?php wp_nonce_field( 'wp_ai_chatbot_ingestion_save', 'wp_ai_chatbot_ingestion_nonce' ); ?>
				<input type="hidden" name="action" value="add_sitemap_url">
				<p>
					<input type="url" name="sitemap_url" placeholder="<?php esc_attr_e( 'https://example.com/sitemap.xml', 'wp-ai-chatbot-leadgen-pro' ); ?>" class="regular-text" required>
					<?php submit_button( __( 'Add Sitemap', 'wp-ai-chatbot-leadgen-pro' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>

			<?php if ( ! empty( $sitemap_urls ) ) : ?>
				<ul class="url-list">
					<?php foreach ( $sitemap_urls as $url ) : ?>
						<li>
							<?php echo esc_html( $url ); ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'remove_sitemap', 'url' => urlencode( $url ), 'nonce' => wp_create_nonce( 'remove_sitemap_' . $url ) ) ) ); ?>" class="delete-url">
								<?php esc_html_e( 'Remove', 'wp-ai-chatbot-leadgen-pro' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Manual URLs', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
			<form method="post" action="" class="inline-form">
				<?php wp_nonce_field( 'wp_ai_chatbot_ingestion_save', 'wp_ai_chatbot_ingestion_nonce' ); ?>
				<input type="hidden" name="action" value="add_manual_url">
				<p>
					<input type="url" name="manual_url" placeholder="<?php esc_attr_e( 'https://example.com/page', 'wp-ai-chatbot-leadgen-pro' ); ?>" class="regular-text" required>
					<?php submit_button( __( 'Add URL', 'wp-ai-chatbot-leadgen-pro' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>

			<?php if ( ! empty( $manual_urls ) ) : ?>
				<ul class="url-list">
					<?php foreach ( $manual_urls as $url ) : ?>
						<li>
							<?php echo esc_html( $url ); ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'remove_manual', 'url' => urlencode( $url ), 'nonce' => wp_create_nonce( 'remove_manual_' . $url ) ) ) ); ?>" class="delete-url">
								<?php esc_html_e( 'Remove', 'wp-ai-chatbot-leadgen-pro' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render scheduling tab.
	 *
	 * @since 1.0.0
	 */
	private function render_scheduling_tab() {
		$reindex_interval = $this->config->get( 'reindex_interval', 'weekly' );
		$chunk_size = $this->config->get( 'chunk_size', 1000 );
		$chunk_overlap = $this->config->get( 'chunk_overlap', 200 );
		$freshness_threshold = $this->config->get( 'content_freshness_threshold_days', 30 );

		// Get next scheduled re-index time
		$scheduled_reindexer = new WP_AI_Chatbot_LeadGen_Pro_Scheduled_Reindexer();
		$next_reindex_time = $scheduled_reindexer->get_next_scheduled_time_formatted();

		?>
		<div class="wp-ai-chatbot-scheduling">
			<form method="post" action="">
				<?php wp_nonce_field( 'wp_ai_chatbot_ingestion_save', 'wp_ai_chatbot_ingestion_nonce' ); ?>
				<input type="hidden" name="action" value="save_ingestion_settings">

				<h2><?php esc_html_e( 'Re-indexing Schedule', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Re-index Interval', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<select name="reindex_interval">
								<option value="daily" <?php selected( $reindex_interval, 'daily' ); ?>><?php esc_html_e( 'Daily', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
								<option value="weekly" <?php selected( $reindex_interval, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
								<option value="monthly" <?php selected( $reindex_interval, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
								<option value="never" <?php selected( $reindex_interval, 'never' ); ?>><?php esc_html_e( 'Never', 'wp-ai-chatbot-leadgen-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How often to automatically re-index all content.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
							<p class="description">
								<strong><?php esc_html_e( 'Next scheduled re-index:', 'wp-ai-chatbot-leadgen-pro' ); ?></strong>
								<?php echo esc_html( $next_reindex_time ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Content Freshness Threshold', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<input type="number" name="content_freshness_threshold_days" value="<?php echo esc_attr( $freshness_threshold ); ?>" min="1" max="365" class="small-text">
							<?php esc_html_e( 'days', 'wp-ai-chatbot-leadgen-pro' ); ?>
							<p class="description"><?php esc_html_e( 'Content older than this threshold will be considered stale and flagged for re-indexing.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Chunking Settings', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Chunk Size', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<input type="number" name="chunk_size" value="<?php echo esc_attr( $chunk_size ); ?>" min="100" max="5000" class="small-text">
							<?php esc_html_e( 'characters', 'wp-ai-chatbot-leadgen-pro' ); ?>
							<p class="description"><?php esc_html_e( 'Maximum size of each content chunk in characters.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Chunk Overlap', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<td>
							<input type="number" name="chunk_overlap" value="<?php echo esc_attr( $chunk_overlap ); ?>" min="0" max="1000" class="small-text">
							<?php esc_html_e( 'characters', 'wp-ai-chatbot-leadgen-pro' ); ?>
							<p class="description"><?php esc_html_e( 'Number of characters to overlap between chunks to preserve context.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'wp-ai-chatbot-leadgen-pro' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render status tab.
	 *
	 * @since 1.0.0
	 */
	private function render_status_tab() {
		$queue_stats = $this->queue->get_queue_stats();
		$indexing_stats = $this->indexer->get_indexing_stats();
		$freshness_stats = $this->freshness_tracker->get_freshness_stats();
		$stale_content = $this->freshness_tracker->get_stale_content( array( 'limit' => 20 ) );

		?>
		<div class="wp-ai-chatbot-status">
			<h2><?php esc_html_e( 'Queue Status', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Status', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Count', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Pending', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $queue_stats['pending'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Processing', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $queue_stats['processing'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Completed', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $queue_stats['completed'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Failed', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $queue_stats['failed'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Retry', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $queue_stats['retry'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Content Freshness', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Metric', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Value', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Total Sources', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $freshness_stats['total_sources'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Fresh Sources', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $freshness_stats['fresh_sources'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Stale Sources', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $freshness_stats['stale_sources'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Average Age', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $freshness_stats['average_age_days'], 1 ) ); ?> <?php esc_html_e( 'days', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Oldest Content', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						<td><?php echo esc_html( number_format( $freshness_stats['oldest_content_days'] ) ); ?> <?php esc_html_e( 'days', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Stale Content', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
			<?php if ( ! empty( $stale_content ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source URL', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Chunks', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Age', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stale_content as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['source_url'] ); ?></td>
								<td><?php echo esc_html( ucfirst( $item['source_type'] ) ); ?></td>
								<td><?php echo esc_html( number_format( $item['chunk_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format( $item['age_days'] ) ); ?> <?php esc_html_e( 'days', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'reindex', 'url' => urlencode( $item['source_url'] ), 'nonce' => wp_create_nonce( 'reindex_' . $item['source_url'] ) ) ) ); ?>" class="button button-small">
										<?php esc_html_e( 'Re-index', 'wp-ai-chatbot-leadgen-pro' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No stale content found.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Start indexing.
	 *
	 * @since 1.0.0
	 */
	public function ajax_start_indexing() {
		check_ajax_referer( 'wp_ai_chatbot_ingestion', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-chatbot-leadgen-pro' ) ) );
		}

		// Start full indexing process
		$urls = $this->crawler->discover_urls();

		$queued = 0;
		foreach ( $urls as $url_data ) {
			$job_id = $this->queue->add_job( 'crawl_url', array( 'url' => $url_data['url'] ) );
			if ( ! is_wp_error( $job_id ) ) {
				$queued++;
			}
		}

		wp_send_json_success( array(
			'message' => sprintf( __( 'Queued %d URLs for indexing.', 'wp-ai-chatbot-leadgen-pro' ), $queued ),
			'queued'  => $queued,
		) );
	}

	/**
	 * AJAX handler: Get indexing status.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_indexing_status() {
		check_ajax_referer( 'wp_ai_chatbot_ingestion', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-chatbot-leadgen-pro' ) ) );
		}

		$queue_stats = $this->queue->get_queue_stats();
		$indexing_stats = $this->indexer->get_indexing_stats();

		wp_send_json_success( array(
			'queue'    => $queue_stats,
			'indexing' => $indexing_stats,
		) );
	}

	/**
	 * AJAX handler: Re-index stale content.
	 *
	 * @since 1.0.0
	 */
	public function ajax_reindex_stale() {
		check_ajax_referer( 'wp_ai_chatbot_ingestion', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-chatbot-leadgen-pro' ) ) );
		}

		$scheduled_reindexer = new WP_AI_Chatbot_LeadGen_Pro_Scheduled_Reindexer();
		$result = $scheduled_reindexer->trigger_manual_reindex( array( 'only_stale' => true ) );

		wp_send_json_success( $result );
	}
}

