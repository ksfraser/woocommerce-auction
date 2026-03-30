<?php
/**
 * Abstract base class for database migrations.
 *
 * Provides a standard interface for creating, updating, and rolling back database schema changes.
 * All migrations must extend this class and implement the required methods.
 *
 * @requirement REQ-DB-MIGRATIONS-001
 * @package ksfraser\Database\Migration
 * @version 1.0.0
 */

namespace ksfraser\Database\Migration;

use wpdb;

/**
 * Abstract DatabaseMigration.
 *
 * Base class for versioned database schema migrations. Supports both forward and rollback operations.
 *
 * @see DatabaseMigrator for orchestration
 */
abstract class DatabaseMigration {

	/**
	 * Unique migration identifier.
	 *
	 * Format: YYYY_MM_DD_HHMMSS_description
	 * Example: 2026_03_30_140000_create_batch_jobs_table
	 *
	 * @var string
	 */
	protected $migration_id;

	/**
	 * Human-readable migration description.
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	protected $wpdb;

	/**
	 * Database table prefix (e.g., 'wp_').
	 *
	 * @var string
	 */
	protected $table_prefix;

	/**
	 * Constructor.
	 *
	 * @param string $migration_id Unique migration identifier.
	 * @param string $description  Human-readable description.
	 */
	public function __construct( $migration_id, $description ) {
		global $wpdb;

		$this->migration_id  = $migration_id;
		$this->description   = $description;
		$this->wpdb          = $wpdb;
		$this->table_prefix  = $wpdb->prefix . 'yith_auction_';
	}

	/**
	 * Get the migration ID.
	 *
	 * @return string Migration identifier.
	 */
	public function get_migration_id() {
		return $this->migration_id;
	}

	/**
	 * Get the migration description.
	 *
	 * @return string Human-readable description.
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Execute the migration (forward operation).
	 *
	 * Subclasses must implement this to perform schema changes (create tables, add columns, etc.).
	 * Should be idempotent - safe to run multiple times.
	 *
	 * @throws \Exception If migration fails.
	 * @return bool True if successful, false otherwise.
	 */
	abstract public function up();

	/**
	 * Rollback the migration (reverse operation).
	 *
	 * Subclasses must implement this to undo schema changes. Optional if rollback not supported.
	 * By default, throws an exception indicating rollback is not supported.
	 *
	 * @throws \Exception If rollback is not supported.
	 * @return bool True if successful, false otherwise.
	 */
	public function down() {
		throw new \Exception(
			"Rollback not supported for migration: {$this->migration_id}. " .
			"Manual database cleanup may be required."
		);
	}

	/**
	 * Check if migration has already been applied.
	 *
	 * Queries the migrations table to verify execution status.
	 *
	 * @return bool True if migration has been applied, false otherwise.
	 */
	public function is_applied() {
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->wpdb->prefix}yith_auction_migrations WHERE migration_id = %s",
				$this->migration_id
			)
		);

		return (int) $result > 0;
	}

	/**
	 * Record migration as applied.
	 *
	 * Inserts migration record into tracking table with timestamp.
	 *
	 * @return bool True if recorded successfully, false otherwise.
	 */
	public function mark_as_applied() {
		$inserted = $this->wpdb->insert(
			"{$this->wpdb->prefix}yith_auction_migrations",
			array(
				'migration_id'   => $this->migration_id,
				'description'    => $this->description,
				'executed_at'    => current_time( 'mysql' ),
				'execution_time' => 0,
			),
			array( '%s', '%s', '%s', '%f' )
		);

		return false !== $inserted;
	}

	/**
	 * Helper to check if a table exists.
	 *
	 * @param string $table_name Table name (without prefix).
	 * @return bool True if table exists, false otherwise.
	 */
	protected function table_exists( $table_name ) {
		$full_name = $this->table_prefix . $table_name;

		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
				DB_NAME,
				$full_name
			)
		);

		return (int) $result > 0;
	}

	/**
	 * Helper to check if a column exists.
	 *
	 * @param string $table_name  Table name (without prefix).
	 * @param string $column_name Column name.
	 * @return bool True if column exists, false otherwise.
	 */
	protected function column_exists( $table_name, $column_name ) {
		$full_table = $this->table_prefix . $table_name;

		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				DB_NAME,
				$full_table,
				$column_name
			)
		);

		return (int) $result > 0;
	}

	/**
	 * Helper to check if an index exists.
	 *
	 * @param string $table_name Table name (without prefix).
	 * @param string $index_name Index name.
	 * @return bool True if index exists, false otherwise.
	 */
	protected function index_exists( $table_name, $index_name ) {
		$full_table = $this->table_prefix . $table_name;

		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
				DB_NAME,
				$full_table,
				$index_name
			)
		);

		return (int) $result > 0;
	}

	/**
	 * Update migration execution time.
	 *
	 * Records how long the migration took to execute.
	 *
	 * @param float $execution_time Time in milliseconds.
	 * @return bool True if updated successfully, false otherwise.
	 */
	public function record_execution_time( $execution_time ) {
		$updated = $this->wpdb->update(
			"{$this->wpdb->prefix}yith_auction_migrations",
			array( 'execution_time' => $execution_time ),
			array( 'migration_id' => $this->migration_id ),
			array( '%f' ),
			array( '%s' )
		);

		return false !== $updated;
	}
}
