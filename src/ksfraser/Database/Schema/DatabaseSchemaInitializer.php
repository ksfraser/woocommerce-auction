<?php
/**
 * Database schema initialization manager.
 *
 * Manages registration and execution of all database schema migrations.
 * Integrates with WordPress plugin activation/deactivation hooks.
 *
 * @requirement REQ-DB-MIGRATIONS-001
 * @package ksfraser\Database\Schema
 * @version 1.0.0
 */

namespace ksfraser\Database\Schema;

use ksfraser\Database\Migration\DatabaseMigrator;
use ksfraser\Database\Migration\CreateBatchJobsTable;
use ksfraser\Database\Migration\CreateDashboardViewsTable;

/**
 * DatabaseSchemaInitializer class.
 *
 * Single entry point for all database schema operations.
 * Registers all migrations and provides methods for initialization and cleanup.
 */
class DatabaseSchemaInitializer {

	/**
	 * Database migrator instance.
	 *
	 * @var DatabaseMigrator
	 */
	private $migrator;

	/**
	 * Constructor.
	 *
	 * Initializes the migrator and registers all migrations.
	 */
	public function __construct() {
		$this->migrator = new DatabaseMigrator();

		// Initialize migrations table
		$this->migrator->init_migrations_table();

		// Register all migrations
		$this->register_migrations();
	}

	/**
	 * Register all database migrations.
	 *
	 * Called during initialization to register migration classes.
	 * New migrations should be registered here.
	 *
	 * @return void
	 */
	private function register_migrations() {
		$this->migrator->register_migrations( array(
			new CreateBatchJobsTable(),
			new CreateDashboardViewsTable(),
		) );
	}

	/**
	 * Initialize database schema.
	 *
	 * Runs all pending migrations. Called on plugin activation.
	 *
	 * @throws \Exception If any migration fails.
	 * @return array Execution result with status and statistics.
	 */
	public function initialize() {
		try {
			$result = $this->migrator->migrate();

			// Log successful initialization
			$this->log_initialization(
				'success',
				$result['message'],
				$this->migrator->get_statistics()
			);

			return $result;
		} catch ( \Exception $e ) {
			// Log initialization failure
			$this->log_initialization( 'error', $e->getMessage() );

			throw $e;
		}
	}

	/**
	 * Cleanup database schema.
	 *
	 * Optionally cleanup database tables on plugin deactivation/uninstall.
	 * Controlled by configuration setting.
	 *
	 * @param bool $delete_tables Whether to delete all YITH Auction tables.
	 * @return bool True if cleanup successful.
	 */
	public function cleanup( $delete_tables = false ) {
		if ( ! $delete_tables ) {
			$this->log_cleanup( 'skipped', 'Table deletion disabled in configuration' );
			return true;
		}

		global $wpdb;

		$tables = array(
			'batch_jobs',
			'dashboard_views',
			'migrations',
		);

		$prefix = $wpdb->prefix . 'yith_auction_';

		foreach ( $tables as $table ) {
			$table_name = $prefix . $table;
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$this->log_cleanup( 'success', 'All YITH Auction dashboard tables dropped' );

		return true;
	}

	/**
	 * Get schema statistics.
	 *
	 * Returns migration statistics for monitoring/debugging.
	 *
	 * @return array Statistics including total, applied, pending migrations and execution times.
	 */
	public function get_statistics() {
		return $this->migrator->get_statistics();
	}

	/**
	 * Get applied migrations.
	 *
	 * @return array Array of applied migration records.
	 */
	public function get_applied_migrations() {
		return $this->migrator->get_applied_migrations();
	}

	/**
	 * Get pending migrations.
	 *
	 * @return array Array of pending migration instances.
	 */
	public function get_pending_migrations() {
		return $this->migrator->get_pending_migrations();
	}

	/**
	 * Log initialization event.
	 *
	 * @param string $status    Status (success, error).
	 * @param string $message   Event message.
	 * @param array  $stats     Optional statistics array.
	 * @return void
	 */
	private function log_initialization( $status, $message, $stats = null ) {
		$log_level = 'success' === $status ? 'info' : 'error';

		do_action(
			'yith_auction_schema_initialized',
			array(
				'status'  => $status,
				'message' => $message,
				'stats'   => $stats,
			)
		);

		// Store in WordPress options for visibility
		update_option(
			'yith_auction_schema_last_init',
			array(
				'timestamp' => current_time( 'mysql' ),
				'status'    => $status,
				'message'   => $message,
				'stats'     => $stats,
			)
		);
	}

	/**
	 * Log cleanup event.
	 *
	 * @param string $status  Status (success, skipped, error).
	 * @param string $message Event message.
	 * @return void
	 */
	private function log_cleanup( $status, $message ) {
		do_action(
			'yith_auction_schema_cleanup',
			array(
				'status'  => $status,
				'message' => $message,
			)
		);

		// Store in WordPress options for visibility
		update_option(
			'yith_auction_schema_last_cleanup',
			array(
				'timestamp' => current_time( 'mysql' ),
				'status'    => $status,
				'message'   => $message,
			)
		);
	}
}
