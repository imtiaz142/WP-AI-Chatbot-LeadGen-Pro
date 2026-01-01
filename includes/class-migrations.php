<?php
/**
 * Database migrations handler.
 *
 * Manages database schema updates across plugin versions.
 *
 * @package    WP_AI_Chatbot_LeadGen_Pro
 * @subpackage WP_AI_Chatbot_LeadGen_Pro/includes
 * @since      1.0.0
 */
class WP_AI_Chatbot_LeadGen_Pro_Migrations {

	/**
	 * Current database version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $current_db_version = '1.0.0';

	/**
	 * Plugin version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->plugin_version = WP_AI_CHATBOT_LEADGEN_PRO_VERSION;
		$this->current_db_version = WP_AI_Chatbot_LeadGen_Pro_Database::get_db_version();
	}

	/**
	 * Run migrations if needed.
	 *
	 * @since 1.0.0
	 */
	public function maybe_run_migrations() {
		// Compare versions
		if ( version_compare( $this->current_db_version, $this->plugin_version, '<' ) ) {
			$this->run_migrations();
		}
	}

	/**
	 * Run all pending migrations.
	 *
	 * @since 1.0.0
	 */
	private function run_migrations() {
		global $wpdb;

		// Start transaction if supported
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Get list of migration methods
			$migrations = $this->get_migrations();

			foreach ( $migrations as $version => $method ) {
				// Skip if already migrated
				if ( version_compare( $this->current_db_version, $version, '>=' ) ) {
					continue;
				}

				// Run migration
				if ( method_exists( $this, $method ) ) {
					call_user_func( array( $this, $method ) );
					$this->current_db_version = $version;
					WP_AI_Chatbot_LeadGen_Pro_Database::update_db_version( $version );
				}
			}

			// Update to current plugin version if all migrations passed
			WP_AI_Chatbot_LeadGen_Pro_Database::update_db_version( $this->plugin_version );

			// Commit transaction
			$wpdb->query( 'COMMIT' );

		} catch ( Exception $e ) {
			// Rollback on error
			$wpdb->query( 'ROLLBACK' );
			error_log( 'WP AI Chatbot LeadGen Pro Migration Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Get list of migrations.
	 *
	 * Maps version numbers to migration method names.
	 *
	 * @since 1.0.0
	 * @return array Array of version => method_name pairs.
	 */
	private function get_migrations() {
		return array(
			'1.0.0' => 'migration_1_0_0', // Initial schema (already done in activator)
			// Future migrations will be added here:
			// '1.1.0' => 'migration_1_1_0',
			// '1.2.0' => 'migration_1_2_0',
		);
	}

	/**
	 * Migration 1.0.0 - Initial schema.
	 *
	 * This is handled by the activator, but included for completeness.
	 *
	 * @since 1.0.0
	 */
	private function migration_1_0_0() {
		// Initial schema is created in the activator
		// This method exists for consistency and future reference
	}

	/**
	 * Example migration method template.
	 *
	 * This is a template for future migrations. Uncomment and modify as needed.
	 *
	 * @since 1.0.0
	 */
	/*
	private function migration_1_1_0() {
		global $wpdb;

		$table = WP_AI_Chatbot_LeadGen_Pro_Database::get_conversations_table();

		// Example: Add a new column
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				'new_column_name'
			)
		);

		if ( empty( $column_exists ) ) {
			$wpdb->query(
				"ALTER TABLE {$table} ADD COLUMN new_column_name VARCHAR(255) DEFAULT NULL"
			);
		}

		// Example: Add a new index
		$index_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table} WHERE Key_name = %s",
				'new_index_name'
			)
		);

		if ( empty( $index_exists ) ) {
			$wpdb->query(
				"ALTER TABLE {$table} ADD INDEX new_index_name (column_name)"
			);
		}

		// Example: Modify column
		// $wpdb->query(
		// 	"ALTER TABLE {$table} MODIFY COLUMN column_name TEXT"
		// );

		// Example: Create new table
		// $new_table = $wpdb->prefix . 'ai_chatbot_new_table';
		// $charset_collate = $wpdb->get_charset_collate();
		// $sql = "CREATE TABLE IF NOT EXISTS {$new_table} (
		// 	id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		// 	PRIMARY KEY (id)
		// ) $charset_collate;";
		// require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// dbDelta( $sql );
	}
	*/

	/**
	 * Check if a column exists in a table.
	 *
	 * @since 1.0.0
	 * @param string $table_name  Table name.
	 * @param string $column_name Column name.
	 * @return bool True if column exists, false otherwise.
	 */
	private function column_exists( $table_name, $column_name ) {
		global $wpdb;

		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table_name} LIKE %s",
				$column_name
			)
		);

		return ! empty( $result );
	}

	/**
	 * Check if an index exists in a table.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name.
	 * @param string $index_name Index name.
	 * @return bool True if index exists, false otherwise.
	 */
	private function index_exists( $table_name, $index_name ) {
		global $wpdb;

		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table_name} WHERE Key_name = %s",
				$index_name
			)
		);

		return ! empty( $result );
	}

	/**
	 * Check if a table exists.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name.
	 * @return bool True if table exists, false otherwise.
	 */
	private function table_exists( $table_name ) {
		global $wpdb;

		$table = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		return $table === $table_name;
	}

	/**
	 * Add a column to a table safely.
	 *
	 * @since 1.0.0
	 * @param string $table_name  Table name.
	 * @param string $column_name Column name.
	 * @param string $definition  Column definition (e.g., "VARCHAR(255) DEFAULT NULL").
	 * @return bool True on success, false on failure.
	 */
	private function add_column( $table_name, $column_name, $definition ) {
		global $wpdb;

		if ( $this->column_exists( $table_name, $column_name ) ) {
			return true; // Column already exists
		}

		$result = $wpdb->query(
			"ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$definition}"
		);

		return false !== $result;
	}

	/**
	 * Add an index to a table safely.
	 *
	 * @since 1.0.0
	 * @param string $table_name  Table name.
	 * @param string $index_name  Index name.
	 * @param string $column_name Column name(s) for the index.
	 * @param string $type        Index type (INDEX, UNIQUE, FULLTEXT).
	 * @return bool True on success, false on failure.
	 */
	private function add_index( $table_name, $index_name, $column_name, $type = 'INDEX' ) {
		global $wpdb;

		if ( $this->index_exists( $table_name, $index_name ) ) {
			return true; // Index already exists
		}

		$result = $wpdb->query(
			"ALTER TABLE {$table_name} ADD {$type} {$index_name} ({$column_name})"
		);

		return false !== $result;
	}

	/**
	 * Drop a column from a table safely.
	 *
	 * @since 1.0.0
	 * @param string $table_name  Table name.
	 * @param string $column_name Column name.
	 * @return bool True on success, false on failure.
	 */
	private function drop_column( $table_name, $column_name ) {
		global $wpdb;

		if ( ! $this->column_exists( $table_name, $column_name ) ) {
			return true; // Column doesn't exist
		}

		$result = $wpdb->query(
			"ALTER TABLE {$table_name} DROP COLUMN {$column_name}"
		);

		return false !== $result;
	}

	/**
	 * Drop an index from a table safely.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name.
	 * @param string $index_name Index name.
	 * @return bool True on success, false on failure.
	 */
	private function drop_index( $table_name, $index_name ) {
		global $wpdb;

		if ( ! $this->index_exists( $table_name, $index_name ) ) {
			return true; // Index doesn't exist
		}

		$result = $wpdb->query(
			"ALTER TABLE {$table_name} DROP INDEX {$index_name}"
		);

		return false !== $result;
	}
}

