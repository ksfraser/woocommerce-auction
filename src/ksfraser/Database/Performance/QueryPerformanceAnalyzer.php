<?php
/**
 * Query performance analyzer and optimization recommendations.
 *
 * Analyzes database query performance, identifies slow queries, and provides
 * optimization recommendations including query plans and index suggestions.
 *
 * @requirement REQ-PERFORMANCE-MONITORING-001
 * @package ksfraser\Database\Performance
 * @version 1.0.0
 */

namespace ksfraser\Database\Performance;

use wpdb;

/**
 * QueryPerformanceAnalyzer class.
 *
 * Monitors and analyzes database query performance for optimization opportunities.
 * Provides detailed query analysis with EXPLAIN output and index recommendations.
 */
class QueryPerformanceAnalyzer {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Query execution threshold (milliseconds).
	 *
	 * Queries exceeding this threshold are flagged as slow queries.
	 *
	 * @var float
	 */
	private $slow_query_threshold = 100; // 100ms

	/**
	 * Collected query statistics.
	 *
	 * @var array
	 */
	private $query_stats = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Set slow query threshold.
	 *
	 * @param float $threshold_ms Threshold in milliseconds.
	 * @return void
	 */
	public function set_slow_query_threshold( $threshold_ms ) {
		$this->slow_query_threshold = floatval( $threshold_ms );
	}

	/**
	 * Analyze a query using EXPLAIN.
	 *
	 * @param string $query SQL query to analyze.
	 * @return array Query analysis results including rows examined, keys used, type.
	 */
	public function analyze_query( $query ) {
		// Remove query limits and pagination for analysis
		$analysis_query = preg_replace( '/LIMIT\s+\d+(\s*,\s*\d+)?/i', '', $query );

		// Get EXPLAIN results
		$results = $this->wpdb->get_results( "EXPLAIN {$analysis_query}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $results ) ) {
			return array(
				'error' => 'Failed to analyze query',
				'query' => $query,
			);
		}

		$analysis = array(
			'query'           => $query,
			'num_rows'        => count( $results ),
			'total_rows_read' => 0,
			'uses_index'      => false,
			'full_table_scans' => 0,
			'rows_per_table'  => array(),
			'indexes_used'    => array(),
		);

		foreach ( $results as $row ) {
			$analysis['total_rows_read'] += $row->rows;

			if ( 'ALL' === $row->type ) {
				$analysis['full_table_scans']++;
			} elseif ( ! empty( $row->key ) ) {
				$analysis['uses_index'] = true;
				if ( ! isset( $analysis['indexes_used'][ $row->table ] ) ) {
					$analysis['indexes_used'][ $row->table ] = array();
				}
				$analysis['indexes_used'][ $row->table ][] = $row->key;
			}

			$analysis['rows_per_table'][ $row->table ] = $row->rows;
		}

		return $analysis;
	}

	/**
	 * Get recommendations for optimizing a query.
	 *
	 * @param array $analysis Query analysis results from analyze_query().
	 * @return array Array of optimization recommendations.
	 */
	public function get_optimization_recommendations( array $analysis ) {
		$recommendations = array();

		// Check for full table scans
		if ( $analysis['full_table_scans'] > 0 ) {
			$recommendations[] = array(
				'type'       => 'index',
				'severity'   => 'high',
				'message'    => sprintf(
					'Query performs %d full table scan(s). Consider adding indexes on WHERE clause columns.',
					$analysis['full_table_scans']
				),
				'suggestion' => 'Analyze WHERE clause and add composite indexes for filtered columns.',
			);
		}

		// Check for high rows read vs rows used ratio
		if ( $analysis['total_rows_read'] > 1000 ) {
			$recommendations[] = array(
				'type'       => 'query',
				'severity'   => 'medium',
				'message'    => sprintf(
					'Query examines %d rows. Consider adding filtering or indexes to reduce this.',
					$analysis['total_rows_read']
				),
				'suggestion' => 'Add specific WHERE clause filters or create indexes on frequently filtered columns.',
			);
		}

		// Check for no indexes used
		if ( ! $analysis['uses_index'] && $analysis['num_rows'] > 1 ) {
			$recommendations[] = array(
				'type'       => 'index',
				'severity'   => 'high',
				'message'    => 'Query does not use any indexes. Table structure optimization needed.',
				'suggestion' => 'Create appropriate indexes for JOIN and WHERE clause conditions.',
			);
		}

		return $recommendations;
	}

	/**
	 * Get existing indexes for a table.
	 *
	 * @param string $table_name Table name.
	 * @return array Array of index information.
	 */
	public function get_table_indexes( $table_name ) {
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM information_schema.STATISTICS 
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
				 ORDER BY SEQ_IN_INDEX ASC",
				DB_NAME,
				$table_name
			)
		);

		$indexes = array();

		foreach ( $results as $row ) {
			if ( ! isset( $indexes[ $row->INDEX_NAME ] ) ) {
				$indexes[ $row->INDEX_NAME ] = array(
					'name'      => $row->INDEX_NAME,
					'unique'    => 'PRIMARY' === $row->INDEX_NAME || 0 === $row->NON_UNIQUE ? false : true,
					'columns'   => array(),
					'cardinality' => $row->CARDINALITY,
				);
			}

			$indexes[ $row->INDEX_NAME ]['columns'][] = $row->COLUMN_NAME;
		}

		return $indexes;
	}

	/**
	 * Calculate table statistics.
	 *
	 * @param string $table_name Table name.
	 * @return array Table statistics including row count, size, fragmentation.
	 */
	public function get_table_statistics( $table_name ) {
		$stats = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT 
					table_name, 
					table_rows, 
					data_length, 
					index_length,
					data_free 
				 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
				DB_NAME,
				$table_name
			)
		);

		if ( ! $stats ) {
			return array( 'error' => "Table {$table_name} not found" );
		}

		$total_size = $stats->data_length + $stats->index_length;
		$fragmentation = $total_size > 0 ? ( $stats->data_free / $total_size ) * 100 : 0;

		return array(
			'table_name'      => $stats->table_name,
			'row_count'       => $stats->table_rows,
			'data_size_mb'    => round( $stats->data_length / 1024 / 1024, 2 ),
			'index_size_mb'   => round( $stats->index_length / 1024 / 1024, 2 ),
			'total_size_mb'   => round( $total_size / 1024 / 1024, 2 ),
			'fragmentation_pct' => round( $fragmentation, 2 ),
			'optimization_needed' => $fragmentation > 10,
		);
	}

	/**
	 * Get query recommendations for YITH Auction dashboard tables.
	 *
	 * Analyzes common dashboard queries and recommends indexes.
	 *
	 * @return array Query analysis and recommendations grouped by table.
	 */
	public function analyze_dashboard_queries() {
		$tables = array(
			'batch_jobs'     => $this->wpdb->prefix . 'yith_auction_batch_jobs',
			'dashboard_views' => $this->wpdb->prefix . 'yith_auction_dashboard_views',
			'settlements'    => $this->wpdb->prefix . 'yith_auction_settlements',
			'disputes'       => $this->wpdb->prefix . 'yith_auction_disputes',
		);

		$analysis = array();

		foreach ( $tables as $name => $full_table ) {
			$analysis[ $name ] = array(
				'table'        => $full_table,
				'statistics'   => $this->get_table_statistics( $full_table ),
				'indexes'      => $this->get_table_indexes( $full_table ),
			);
		}

		return $analysis;
	}

	/**
	 * Get slow queries from WordPress query log.
	 *
	 * Analyzes recently executed queries and identifies those exceeding threshold.
	 *
	 * @param int $limit Maximum number of slow queries to return.
	 * @return array Array of slow query details.
	 */
	public function get_slow_queries( $limit = 10 ) {
		$slow_queries = array();

		// WordPress stores queries in $wpdb->queries if SAVEQUERIES is enabled
		if ( ! isset( $GLOBALS['wpdb']->queries ) ) {
			return array( 'message' => 'Query logging not enabled. Enable SAVEQUERIES in wp-config.php.' );
		}

		$queries = $GLOBALS['wpdb']->queries;

		// Sort by execution time
		usort(
			$queries,
			function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);

		$count = 0;
		foreach ( $queries as $query_data ) {
			if ( $count >= $limit ) {
				break;
			}

			// query_data format: array( SQL, execution_time, call_stack )
			$execution_time = floatval( $query_data[1] * 1000 ); // Convert to ms

			if ( $execution_time >= $this->slow_query_threshold ) {
				$slow_queries[] = array(
					'query'            => $query_data[0],
					'execution_time_ms' => round( $execution_time, 2 ),
					'location'         => $query_data[2],
				);

				$count++;
			}
		}

		return array(
			'threshold_ms'   => $this->slow_query_threshold,
			'slow_queries'   => $slow_queries,
			'total_slow'     => $count,
			'total_queries'  => count( $queries ),
		);
	}

	/**
	 * Generate performance report.
	 *
	 * Comprehensive performance analysis report for dashboard tables.
	 *
	 * @return array Performance report with recommendations.
	 */
	public function generate_performance_report() {
		$report = array(
			'generated_at'   => current_time( 'mysql' ),
			'database_name'  => DB_NAME,
			'table_analysis' => $this->analyze_dashboard_queries(),
			'slow_queries'   => $this->get_slow_queries( 20 ),
		);

		// Add recommendations
		$high_severity = array();
		$medium_severity = array();

		foreach ( $report['table_analysis'] as $table_name => $data ) {
			if ( isset( $data['statistics']['optimization_needed'] ) && $data['statistics']['optimization_needed'] ) {
				$high_severity[] = array(
					'table'         => $table_name,
					'issue'         => 'Table fragmentation > 10%',
					'action'        => 'Run OPTIMIZE TABLE ' . $data['table'],
					'fragmentation' => $data['statistics']['fragmentation_pct'],
				);
			}
		}

		$report['recommendations'] = array(
			'high_severity'   => $high_severity,
			'medium_severity' => $medium_severity,
			'summary'         => array(
				'total_recommendations' => count( $high_severity ) + count( $medium_severity ),
				'urgent_actions'        => count( $high_severity ),
			),
		);

		return $report;
	}

	/**
	 * Get database version and capabilities.
	 *
	 * @return array Database server information and feature support.
	 */
	public function get_database_capabilities() {
		$version = $this->wpdb->db_version();
		$result  = $this->wpdb->get_row( 'SHOW STATUS' );

		return array(
			'version'              => $version,
			'supports_json'        => version_compare( $version, '5.7.0', '>=' ),
			'supports_partitioning' => true, // MySQL 5.1+
			'supports_generated_columns' => version_compare( $version, '5.7.0', '>=' ),
			'supports_window_functions' => version_compare( $version, '8.0.0', '>=' ),
		);
	}
}
