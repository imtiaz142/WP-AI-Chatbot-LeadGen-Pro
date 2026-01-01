<?php
/**
 * Content Manager Admin UI.
 *
 * Admin interface for content management, citation frequency, and content gaps.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes/admin
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Content_Manager_Admin {

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
	 * Citation tracker instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker
	 */
	private $citation_tracker;

	/**
	 * Content gap analyzer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Gap_Analyzer
	 */
	private $gap_analyzer;

	/**
	 * Content indexer instance.
	 *
	 * @since 1.0.0
	 * @var WP_AI_Chatbot_LeadGen_Pro_Content_Indexer
	 */
	private $indexer;

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
		$this->citation_tracker = new WP_AI_Chatbot_LeadGen_Pro_Citation_Tracker();
		$this->gap_analyzer = new WP_AI_Chatbot_LeadGen_Pro_Content_Gap_Analyzer();
		$this->indexer = new WP_AI_Chatbot_LeadGen_Pro_Content_Indexer();
		$this->freshness_tracker = new WP_AI_Chatbot_LeadGen_Pro_Content_Freshness_Tracker();
	}

	/**
	 * Register admin hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_reindex_url', array( $this, 'ajax_reindex_url' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_bulk_reindex', array( $this, 'ajax_bulk_reindex' ) );
	}

	/**
	 * Add admin menu items.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'wp-ai-chatbot-leadgen-pro',
			__( 'Content Manager', 'wp-ai-chatbot-leadgen-pro' ),
			__( 'Content Manager', 'wp-ai-chatbot-leadgen-pro' ),
			'manage_options',
			'wp-ai-chatbot-content-manager',
			array( $this, 'render_content_manager_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( strpos( $hook, 'wp-ai-chatbot-content-manager' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wp-ai-chatbot-content-manager-admin',
			WP_AI_CHATBOT_LEADGEN_PRO_URL . 'assets/css/admin-content-manager.css',
			array(),
			WP_AI_CHATBOT_LEADGEN_PRO_VERSION
		);

		wp_enqueue_script(
			'wp-ai-chatbot-content-manager-admin',
			WP_AI_CHATBOT_LEADGEN_PRO_URL . 'assets/js/admin-content-manager.js',
			array( 'jquery' ),
			WP_AI_CHATBOT_LEADGEN_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-ai-chatbot-content-manager-admin',
			'wpAiChatbotContentManager',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_ai_chatbot_content_manager' ),
			)
		);
	}

	/**
	 * Handle admin actions (re-index, refresh, etc.).
	 *
	 * @since 1.0.0
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wp-ai-chatbot-content-manager' ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle re-index action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'reindex' && isset( $_GET['url'] ) ) {
			check_admin_referer( 'reindex_' . $_GET['url'] );

			$url = urldecode( sanitize_text_field( $_GET['url'] ) );
			$result = $this->indexer->reindex_url( $url );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'wp_ai_chatbot_content_manager',
					'reindex_error',
					sprintf( __( 'Failed to re-index URL: %s', 'wp-ai-chatbot-leadgen-pro' ), $result->get_error_message() ),
					'error'
				);
			} else {
				add_settings_error(
					'wp_ai_chatbot_content_manager',
					'reindex_success',
					sprintf( __( 'Successfully queued URL for re-indexing: %s', 'wp-ai-chatbot-leadgen-pro' ), $url ),
					'success'
				);
			}

			// Redirect to remove action from URL
			wp_safe_redirect( remove_query_arg( array( 'action', 'url', '_wpnonce' ) ) );
			exit;
		}

		// Handle bulk re-index action
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'bulk_reindex' && isset( $_POST['urls'] ) ) {
			check_admin_referer( 'bulk_reindex' );

			$urls = array_map( 'sanitize_text_field', $_POST['urls'] );
			$queued = 0;
			$errors = 0;

			foreach ( $urls as $url ) {
				$result = $this->indexer->reindex_url( $url );
				if ( is_wp_error( $result ) ) {
					$errors++;
				} else {
					$queued++;
				}
			}

			if ( $queued > 0 ) {
				add_settings_error(
					'wp_ai_chatbot_content_manager',
					'bulk_reindex_success',
					sprintf( __( 'Successfully queued %d URL(s) for re-indexing.', 'wp-ai-chatbot-leadgen-pro' ), $queued ),
					'success'
				);
			}

			if ( $errors > 0 ) {
				add_settings_error(
					'wp_ai_chatbot_content_manager',
					'bulk_reindex_errors',
					sprintf( __( 'Failed to queue %d URL(s) for re-indexing.', 'wp-ai-chatbot-leadgen-pro' ), $errors ),
					'error'
				);
			}

			// Redirect to remove action from URL
			wp_safe_redirect( remove_query_arg( array( 'action', 'urls', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Render content manager admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_content_manager_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'wp-ai-chatbot-leadgen-pro' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'indexed';

		?>
		<div class="wrap wp-ai-chatbot-content-manager-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( 'wp_ai_chatbot_content_manager' ); ?>

			<nav class="nav-tab-wrapper">
				<a href="?page=wp-ai-chatbot-content-manager&tab=indexed" class="nav-tab <?php echo $tab === 'indexed' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Indexed Pages', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</a>
				<a href="?page=wp-ai-chatbot-content-manager&tab=citations" class="nav-tab <?php echo $tab === 'citations' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Citation Frequency', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</a>
				<a href="?page=wp-ai-chatbot-content-manager&tab=gaps" class="nav-tab <?php echo $tab === 'gaps' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Content Gaps', 'wp-ai-chatbot-leadgen-pro' ); ?>
				</a>
			</nav>

			<div class="tab-content">
				<?php
				switch ( $tab ) {
					case 'indexed':
						$this->render_indexed_pages_tab();
						break;

					case 'citations':
						$this->render_citations_tab();
						break;

					case 'gaps':
						$this->render_content_gaps_tab();
						break;

					default:
						$this->render_indexed_pages_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render indexed pages tab.
	 *
	 * @since 1.0.0
	 */
	private function render_indexed_pages_tab() {
		global $wpdb;

		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		// Get pagination
		$per_page = 20;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$offset = ( $current_page - 1 ) * $per_page;

		// Get indexed pages
		$query = "SELECT 
			source_url,
			source_type,
			source_id,
			COUNT(*) as chunk_count,
			SUM(word_count) as total_words,
			MAX(last_updated) as last_updated,
			MAX(indexed_at) as last_indexed
		FROM {$chunks_table}
		WHERE source_url != ''
		GROUP BY source_url, source_type, source_id
		ORDER BY last_indexed DESC
		LIMIT %d OFFSET %d";

		$pages = $wpdb->get_results(
			$wpdb->prepare( $query, $per_page, $offset ),
			ARRAY_A
		);

		// Get total count
		$total_pages = intval(
			$wpdb->get_var(
				"SELECT COUNT(DISTINCT source_url) FROM {$chunks_table} WHERE source_url != ''"
			)
		);

		$total_pages_paginated = ceil( $total_pages / $per_page );

		?>
		<div class="wp-ai-chatbot-indexed-pages">
			<div class="page-actions">
				<h2><?php esc_html_e( 'Indexed Pages', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
				<div class="action-buttons">
					<button type="button" class="button" id="select-all-pages"><?php esc_html_e( 'Select All', 'wp-ai-chatbot-leadgen-pro' ); ?></button>
					<button type="button" class="button" id="deselect-all-pages"><?php esc_html_e( 'Deselect All', 'wp-ai-chatbot-leadgen-pro' ); ?></button>
					<button type="button" class="button button-primary" id="bulk-reindex-pages"><?php esc_html_e( 'Re-index Selected', 'wp-ai-chatbot-leadgen-pro' ); ?></button>
					<button type="button" class="button" id="refresh-stale-pages"><?php esc_html_e( 'Re-index Stale Content', 'wp-ai-chatbot-leadgen-pro' ); ?></button>
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'All pages and content sources that have been indexed in the knowledge base.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>

			<form method="post" id="bulk-reindex-form">
				<?php wp_nonce_field( 'bulk_reindex' ); ?>
				<input type="hidden" name="action" value="bulk_reindex">
				<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="select-all-checkbox"></th>
						<th><?php esc_html_e( 'Source URL', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Chunks', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Words', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Last Updated', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Last Indexed', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $pages ) ) : ?>
						<?php foreach ( $pages as $page ) : ?>
							<?php
							$freshness = $this->freshness_tracker->get_freshness( $page['source_url'] );
							$is_stale = $freshness && ! $freshness['is_fresh'];
							?>
							<tr class="<?php echo $is_stale ? 'stale-content' : ''; ?>">
								<th class="check-column">
									<input type="checkbox" name="urls[]" value="<?php echo esc_attr( $page['source_url'] ); ?>" class="page-checkbox">
								</th>
								<td>
									<strong>
										<a href="<?php echo esc_url( $page['source_url'] ); ?>" target="_blank">
											<?php echo esc_html( $page['source_url'] ); ?>
										</a>
									</strong>
									<?php if ( $is_stale ) : ?>
										<span class="stale-badge"><?php esc_html_e( 'Stale', 'wp-ai-chatbot-leadgen-pro' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( ucfirst( $page['source_type'] ) ); ?></td>
								<td><?php echo esc_html( number_format( $page['chunk_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format( $page['total_words'] ) ); ?></td>
								<td>
									<?php
									if ( $page['last_updated'] ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $page['last_updated'] ) ) );
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<?php
									if ( $page['last_indexed'] ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $page['last_indexed'] ) ) );
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'reindex', 'url' => urlencode( $page['source_url'] ), 'nonce' => wp_create_nonce( 'reindex_' . $page['source_url'] ) ) ) ); ?>" class="button button-small">
										<?php esc_html_e( 'Re-index', 'wp-ai-chatbot-leadgen-pro' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No indexed pages found.', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			</form>

			<?php if ( $total_pages_paginated > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $current_page,
							'total'   => $total_pages_paginated,
						) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render citations tab.
	 *
	 * @since 1.0.0
	 */
	private function render_citations_tab() {
		global $wpdb;

		$messages_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_messages_table();
		$chunks_table = WP_AI_Chatbot_LeadGen_Pro_Database::get_content_chunks_table();

		// Get pagination
		$per_page = 20;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$offset = ( $current_page - 1 ) * $per_page;

		// Get all messages with citations
		$messages_with_citations = $wpdb->get_results(
			"SELECT id, citations, created_at FROM {$messages_table} 
			WHERE role = 'assistant' 
			AND citations IS NOT NULL 
			AND citations != ''",
			ARRAY_A
		);

		// Process citations to count by source URL
		$citation_counts = array();
		foreach ( $messages_with_citations as $message ) {
			$citations_data = json_decode( $message['citations'], true );
			if ( ! $citations_data || ! isset( $citations_data['chunks'] ) ) {
				continue;
			}

			foreach ( $citations_data['chunks'] as $citation ) {
				if ( ! isset( $citation['source_url'] ) ) {
					continue;
				}

				$source_url = $citation['source_url'];
				if ( ! isset( $citation_counts[ $source_url ] ) ) {
					$citation_counts[ $source_url ] = array(
						'source_url'      => $source_url,
						'source_type'     => isset( $citation['source_type'] ) ? $citation['source_type'] : 'unknown',
						'citation_count'  => 0,
						'total_citations' => 0,
						'last_cited'      => $message['created_at'],
					);
				}

				$citation_counts[ $source_url ]['citation_count']++;
				$citation_counts[ $source_url ]['total_citations']++;
				if ( strtotime( $message['created_at'] ) > strtotime( $citation_counts[ $source_url ]['last_cited'] ) ) {
					$citation_counts[ $source_url ]['last_cited'] = $message['created_at'];
				}
			}
		}

		// Sort by citation count
		usort( $citation_counts, function( $a, $b ) {
			if ( $a['citation_count'] === $b['citation_count'] ) {
				return $b['total_citations'] <=> $a['total_citations'];
			}
			return $b['citation_count'] <=> $a['citation_count'];
		} );

		// Paginate
		$total_sources = count( $citation_counts );
		$citations = array_slice( $citation_counts, $offset, $per_page );
		$total_pages_paginated = ceil( $total_sources / $per_page );

		?>
		<div class="wp-ai-chatbot-citations">
			<h2><?php esc_html_e( 'Citation Frequency', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Pages and content sources ranked by how frequently they are cited in AI responses.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Rank', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Source URL', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Messages Cited', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Total Citations', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						<th><?php esc_html_e( 'Last Cited', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $citations ) ) : ?>
						<?php foreach ( $citations as $index => $citation ) : ?>
							<tr>
								<td><?php echo esc_html( $offset + $index + 1 ); ?></td>
								<td>
									<strong>
										<a href="<?php echo esc_url( $citation['source_url'] ); ?>" target="_blank">
											<?php echo esc_html( $citation['source_url'] ); ?>
										</a>
									</strong>
								</td>
								<td><?php echo esc_html( ucfirst( $citation['source_type'] ) ); ?></td>
								<td><?php echo esc_html( number_format( $citation['citation_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format( $citation['total_citations'] ) ); ?></td>
								<td>
									<?php
									if ( $citation['last_cited'] ) {
										echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $citation['last_cited'] ) ) );
									} else {
										echo '—';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No citations found.', 'wp-ai-chatbot-leadgen-pro' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages_paginated > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $current_page,
							'total'   => $total_pages_paginated,
						) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render content gaps tab.
	 *
	 * @since 1.0.0
	 */
	private function render_content_gaps_tab() {
		$gap_stats = $this->gap_analyzer->get_gap_statistics();
		$unanswered = $this->gap_analyzer->get_unanswered_questions( array( 'limit' => 50 ) );
		$low_quality = $this->gap_analyzer->get_low_quality_answers( array( 'limit' => 50 ) );

		?>
		<div class="wp-ai-chatbot-content-gaps">
			<h2><?php esc_html_e( 'Content Gap Analysis', 'wp-ai-chatbot-leadgen-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Identify questions that are frequently asked but not well-answered by your knowledge base.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>

			<div class="gap-statistics">
				<div class="stat-card">
					<h3><?php esc_html_e( 'Total Questions', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value"><?php echo esc_html( number_format( $gap_stats['total_questions'] ) ); ?></div>
					<p class="stat-period"><?php esc_html_e( 'Last 30 days', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
				</div>

				<div class="stat-card">
					<h3><?php esc_html_e( 'Unanswered', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value stat-warning"><?php echo esc_html( number_format( $gap_stats['unanswered_questions'] ) ); ?></div>
					<p class="stat-period"><?php esc_html_e( 'No citations found', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
				</div>

				<div class="stat-card">
					<h3><?php esc_html_e( 'Low Quality', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value stat-warning"><?php echo esc_html( number_format( $gap_stats['low_quality_answers'] ) ); ?></div>
					<p class="stat-period"><?php esc_html_e( 'Negative feedback or low similarity', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
				</div>

				<div class="stat-card">
					<h3><?php esc_html_e( 'Quality Score', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
					<div class="stat-value stat-<?php echo $gap_stats['answer_quality_score'] >= 80 ? 'success' : ( $gap_stats['answer_quality_score'] >= 60 ? 'warning' : 'error' ); ?>">
						<?php echo esc_html( number_format( $gap_stats['answer_quality_score'], 1 ) ); ?>%
					</div>
					<p class="stat-period"><?php esc_html_e( 'Overall answer quality', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Unanswered Questions', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Questions that were asked multiple times but had no citations (indicating no good answer was found).', 'wp-ai-chatbot-leadgen-pro' ); ?></p>

			<?php if ( ! empty( $unanswered ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Question', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Times Asked', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'First Asked', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Last Asked', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $unanswered as $question ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $question['question'] ); ?></strong></td>
								<td><?php echo esc_html( number_format( $question['occurrence_count'] ) ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $question['first_asked'] ) ) ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $question['last_asked'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No unanswered questions found. Great job!', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Low Quality Answers', 'wp-ai-chatbot-leadgen-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Questions that received negative feedback or had low similarity scores, indicating the answers were not helpful.', 'wp-ai-chatbot-leadgen-pro' ); ?></p>

			<?php if ( ! empty( $low_quality ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Question', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Times Asked', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Avg Similarity', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Negative Feedback', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
							<th><?php esc_html_e( 'Last Asked', 'wp-ai-chatbot-leadgen-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $low_quality as $question ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $question['question'] ); ?></strong></td>
								<td><?php echo esc_html( number_format( $question['occurrence_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format( $question['avg_similarity'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format( $question['negative_feedback'] ) ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $question['last_asked'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No low quality answers found. Great job!', 'wp-ai-chatbot-leadgen-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Re-index single URL.
	 *
	 * @since 1.0.0
	 */
	public function ajax_reindex_url() {
		check_ajax_referer( 'wp_ai_chatbot_content_manager', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-chatbot-leadgen-pro' ) ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'URL is required.', 'wp-ai-chatbot-leadgen-pro' ) ) );
		}

		$result = $this->indexer->reindex_url( $url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => sprintf( __( 'URL queued for re-indexing: %s', 'wp-ai-chatbot-leadgen-pro' ), $url ),
		) );
	}

	/**
	 * AJAX handler: Bulk re-index URLs.
	 *
	 * @since 1.0.0
	 */
	public function ajax_bulk_reindex() {
		check_ajax_referer( 'wp_ai_chatbot_content_manager', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-chatbot-leadgen-pro' ) ) );
		}

		$urls = isset( $_POST['urls'] ) ? array_map( 'esc_url_raw', $_POST['urls'] ) : array();

		if ( empty( $urls ) ) {
			wp_send_json_error( array( 'message' => __( 'No URLs provided.', 'wp-ai-chatbot-leadgen-pro' ) ) );
		}

		$queued = 0;
		$errors = 0;
		$error_messages = array();

		foreach ( $urls as $url ) {
			$result = $this->indexer->reindex_url( $url );
			if ( is_wp_error( $result ) ) {
				$errors++;
				$error_messages[] = $url . ': ' . $result->get_error_message();
			} else {
				$queued++;
			}
		}

		$response = array(
			'queued' => $queued,
			'errors' => $errors,
		);

		if ( $queued > 0 ) {
			$response['message'] = sprintf( __( 'Successfully queued %d URL(s) for re-indexing.', 'wp-ai-chatbot-leadgen-pro' ), $queued );
		}

		if ( $errors > 0 ) {
			$response['error_message'] = sprintf( __( 'Failed to queue %d URL(s).', 'wp-ai-chatbot-leadgen-pro' ), $errors );
			$response['error_details'] = $error_messages;
		}

		wp_send_json_success( $response );
	}
}

