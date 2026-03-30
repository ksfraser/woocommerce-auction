<?php
/**
 * Database migration orchestrator.
 *
 * Manages discovery, execution, and tracking of database migrations.
 * Maintains a migrations table to prevent duplicate execution.
 *
 * @requirement REQ-DB-MIGRATIONS-001
 * @package ksfraser\Database\Migration
 * @version 1.0.0
 */

namespace ksfraser\Database\Migration;

use wpdb;

/**
 * DatabaseMigrator class.
 *
 * Orchestrates the discovery and execution of database migrations in a versioned manner.
 * Supports pending migration identification and batch execution.
 */
class DatabaseMigrator {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Array of registered migrations.
	 *
	 * @var DatabaseMigration[]
	 */
	private $migrations = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Register a migration.
	 *
	 * @param DatabaseMigration $migration Migration instance.
	 * @return void
	 */
	public function register_migration( DatabaseMigration $migration ) {
		$this->migrations[ $migration->get_migration_id() ] = $migration;
	}

	/**
	 * Register multiple migrations.
	 *
	 * @param DatabaseMigration[] $migrations Array of migration instances.
	 * @return void
	 */
	public function register_migrations( array $migrations ) {
		foreach ( $migrations as $migration ) {
			$this->register_migration( $migration );
		}
	}

	/**
	 * Get all registered migrations.
	 *
	 * @return DatabaseMigration[] Array of migration instances keyed by migration ID.
	 */
	public function get_migrations() {
		return $this->migrations;
	}

	/**
	 * Get pending migrations (not yet applied).
	 *
	 * @return DatabaseMigration[] Array of unapplied migrations.
	 */
	public function get_pending_migrations() {
		$pending = array();

		foreach ( $this->migrations as $migration ) {
			if ( ! $migration->is_applied() ) {
				$pending[ $migration->get_migration_id() ] = $migration;
			}
		}

		return $pending;
	}

	/**
	 * Get applied migrations.
	 *
	 * @return array Array of applied migration records from database.
	 */
	public function get_applied_migrations() {
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->wpdb->prefix}yith_auction_migrations ORDER BY executed_at ASC"
		);
	}

	/**
	 * Execute all pending migrations.
	 *
	 * Runs all registered migrations that haven't been applied yet.
	 *
	 * @throws \Exception If any migration fails.
	 * @return array Execution result with counts and timing.
	 */
	public function migrate() {
		$pending = $this->get_pending_migrations();

		if ( empty( $pending ) ) {
			return array(
				'status'           => 'success',
				'migrations_run'   => 0,
				'migrations_total' => count( $this->migrations ),
				'message'          => 'No pending migrations to run.',
			);
		}

		$executed = 0;
		$failed   = 0;
		$errors   = array();

		foreach ( $pending as $migration ) {
			try {
				$start_time = microtime( true );
				$result     = $migration->up();

				if ( $result ) {
					$end_time       = microtime( true );
					$execution_time = ( $end_time - $start_time ) * 1000; // Convert to ms

					$migration->mark_as_applied();
					$migration->record_execution_time( $execution_time );

					$executed++;
				} else {
					$failed++;
					$errors[] = "Migration {$migration->get_migration_id()} returned false.";
				}
			} catch ( \Exception $e ) {
				$failed++;
				$errors[] = "Migration {$migration->get_migration_id()} failed: " . $e->getMessage();
			}
		}

		if ( $failed > 0 ) {
			throw new \Exception(
				'One or more migrations failed: ' . implode( '; ', $errors )
			);
		}

		return array(
			'status'           => 'success',
			'migrations_run'   => $executed,
			'migrations_total' => count( $this->migrations ),
			'message'          => "Successfully applied {$executed} migration(s).",
		);
	}

	/**
	 * Initialize migrations table if it doesn't exist.
	 *
	 * Creates the yith_auction_migrations tracking table.
	 *
	 * @return bool True if table created or already exists, false otherwise.
	 */
	public function init_migrations_table() {
		$migrations_table = "{$this->wpdb->prefix}yith_auction_migrations";

		// Check if table already exists
		if ( $this->wpdb->get_var( "SHOW TABLES LIKE '$migrations_table'" ) === $migrations_table ) {
			return true;
		}

		// Create migrations table
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$migrations_table} (
			id INT AUTO_INCREMENT PRIMARY KEY,
			migration_id VARCHAR(255) NOT NULL UNIQUE,
			description TEXT,
			executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			execution_time FLOAT DEFAULT 0,
			KEY (executed_at),
			KEY (migration_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return true;
	}

	/**
	 * Get migration statistics.
	 *
	 * @return array Statistics including total, applied, pending, and execution times.
	 */
	public function get_statistics() {
		$applied = $this->get_applied_migrations();

		$total_execution_time = 0;
		foreach ( $applied as $record ) {
			$total_execution_time += $record->execution_time;
		}

		return array(
			'total_registered' => count( $this->migrations ),
			'total_applied'    => count( $applied ),
			'total_pending'    => count( $this->get_pending_migrations() ),
			'total_exec_time'  => $total_execution_time,
			'avg_exec_time'    => count( $applied ) > 0 ? $total_execution_time / count( $applied ) : 0,
		);
	}
}
