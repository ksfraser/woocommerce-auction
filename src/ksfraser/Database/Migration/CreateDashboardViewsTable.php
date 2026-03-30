<?php
/**
 * Migration to create dashboard_views materialized cache table.
 *
 * Creates the dashboard_views table for caching pre-computed dashboard metrics.
 * Stores aggregated dashboard data with TTL for performance optimization.
 *
 * @requirement REQ-DB-MIGRATIONS-001, REQ-DASHBOARD-ADMIN-001
 * @package ksfraser\Database\Migration
 * @version 1.0.0
 */

namespace ksfraser\Database\Migration;

/**
 * CreateDashboardViewsTable migration.
 *
 * Schema for dashboard_views materialized cache table:
 * - id: Auto-incrementing primary key
 * - view_name: Unique name of the cached view (e.g., 'settlement_metrics', 'revenue_summary')
 * - view_type: Type of view for categorization (settlement, revenue, performance, disputes, health, payouts)
 * - data: JSON-encoded cached view data
 * - cached_at: Timestamp when cache was created
 * - expires_at: Timestamp when cache expires
 *
 * Indexes optimized for cache invalidation and queries:
 * - view_name: Find specific cached view
 * - view_type: Find all views of a type
 * - expires_at: Identify expired cache entries for cleanup
 *
 * @see \ksfraser\Services\Dashboard\DashboardDataService
 */
class CreateDashboardViewsTable extends DatabaseMigration {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'2026_03_30_140100_create_dashboard_views_table',
			'Create dashboard_views materialized cache table'
		);
	}

	/**
	 * Execute migration - create dashboard_views table.
	 *
	 * @throws \Exception If table creation fails.
	 * @return bool True if successful.
	 */
	public function up() {
		if ( $this->table_exists( 'dashboard_views' ) ) {
			return true; // Idempotent - table already exists
		}

		$charset_collate = $this->wpdb->get_charset_collate();
		$table_name      = $this->table_prefix . 'dashboard_views';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT AUTO_INCREMENT PRIMARY KEY,
			view_name VARCHAR(100) NOT NULL UNIQUE,
			view_type VARCHAR(50),
			data LONGTEXT,
			cached_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			KEY idx_view_type (view_type),
			KEY idx_expires_at (expires_at),
			KEY idx_cached_at (cached_at)
		) {$charset_collate}";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Verify table was created
		if ( ! $this->table_exists( 'dashboard_views' ) ) {
			throw new \Exception( 'Failed to create dashboard_views table' );
		}

		return true;
	}

	/**
	 * Rollback migration - drop dashboard_views table.
	 *
	 * WARNING: This will delete all cached dashboard view data.
	 * Caches will be regenerated on next access.
	 *
	 * @throws \Exception If rollback fails.
	 * @return bool True if successful.
	 */
	public function down() {
		$table_name = $this->table_prefix . 'dashboard_views';

		$this->wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		if ( $this->table_exists( 'dashboard_views' ) ) {
			throw new \Exception( 'Failed to drop dashboard_views table' );
		}

		return true;
	}
}
