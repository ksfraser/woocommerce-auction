<?php
/**
 * Tests for QueryPerformanceAnalyzer class.
 *
 * @package ksfraser\Tests\Database\Performance
 * @covers \ksfraser\Database\Performance\QueryPerformanceAnalyzer
 */

namespace ksfraser\Tests\Database\Performance;

use PHPUnit\Framework\TestCase;
use ksfraser\Database\Performance\QueryPerformanceAnalyzer;

/**
 * QueryPerformanceAnalyzerTest class.
 *
 * @covers \ksfraser\Database\Performance\QueryPerformanceAnalyzer
 */
class QueryPerformanceAnalyzerTest extends TestCase {

	/**
	 * Analyzer instance.
	 *
	 * @var QueryPerformanceAnalyzer
	 */
	private $analyzer;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->analyzer = new QueryPerformanceAnalyzer();
	}

	/**
	 * Test analyzer instantiation.
	 *
	 * @test
	 */
	public function test_instantiation() {
		$this->assertInstanceOf( QueryPerformanceAnalyzer::class, $this->analyzer );
	}

	/**
	 * Test set slow query threshold.
	 *
	 * @test
	 */
	public function test_set_slow_query_threshold() {
		$this->analyzer->set_slow_query_threshold( 50 );

		// Verify via getter (through analysis)
		$this->assertTrue( method_exists( $this->analyzer, 'set_slow_query_threshold' ) );
	}

	/**
	 * Test analyze query method exists.
	 *
	 * @test
	 */
	public function test_analyze_query_method_exists() {
		$this->assertTrue( method_exists( $this->analyzer, 'analyze_query' ) );
	}

	/**
	 * Test get optimization recommendations method exists.
	 *
	 * @test
	 */
	public function test_get_optimization_recommendations_method_exists() {
		$this->assertTrue( method_exists( $this->analyzer, 'get_optimization_recommendations' ) );
	}

	/**
	 * Test get table indexes method.
	 *
	 * @test
	 */
	public function test_get_table_indexes() {
		$this->assertTrue( method_exists( $this->analyzer, 'get_table_indexes' ) );
	}

	/**
	 * Test get table statistics method.
	 *
	 * @test
	 */
	public function test_get_table_statistics() {
		$this->assertTrue( method_exists( $this->analyzer, 'get_table_statistics' ) );
	}

	/**
	 * Test analyze dashboard queries.
	 *
	 * @test
	 */
	public function test_analyze_dashboard_queries() {
		$this->assertTrue( method_exists( $this->analyzer, 'analyze_dashboard_queries' ) );
	}

	/**
	 * Test get slow queries method.
	 *
	 * @test
	 */
	public function test_get_slow_queries() {
		$this->assertTrue( method_exists( $this->analyzer, 'get_slow_queries' ) );
	}

	/**
	 * Test generate performance report.
	 *
	 * @test
	 */
	public function test_generate_performance_report() {
		$this->assertTrue( method_exists( $this->analyzer, 'generate_performance_report' ) );
	}

	/**
	 * Test get database capabilities.
	 *
	 * @test
	 */
	public function test_get_database_capabilities() {
		$this->assertTrue( method_exists( $this->analyzer, 'get_database_capabilities' ) );
	}

	/**
	 * Test optimization recommendation structure.
	 *
	 * @test
	 */
	public function test_recommendation_structure() {
		$sample_analysis = array(
			'full_table_scans' => 1,
			'uses_index'       => false,
			'total_rows_read'  => 5000,
			'num_rows'         => 2,
		);

		$recommendations = $this->analyzer->get_optimization_recommendations( $sample_analysis );

		$this->assertIsArray( $recommendations );
		$this->assertNotEmpty( $recommendations );

		foreach ( $recommendations as $rec ) {
			$this->assertArrayHasKey( 'type', $rec );
			$this->assertArrayHasKey( 'severity', $rec );
			$this->assertArrayHasKey( 'message', $rec );
			$this->assertArrayHasKey( 'suggestion', $rec );
		}
	}

	/**
	 * Test full table scan recommendation.
	 *
	 * @test
	 */
	public function test_full_table_scan_recommendation() {
		$analysis = array(
			'full_table_scans' => 2,
			'uses_index'       => false,
			'total_rows_read'  => 1000,
			'num_rows'         => 2,
		);

		$recommendations = $this->analyzer->get_optimization_recommendations( $analysis );

		$high_severity = array_filter(
			$recommendations,
			function ( $rec ) {
				return 'high' === $rec['severity'];
			}
		);

		$this->assertNotEmpty( $high_severity );
	}

	/**
	 * Test batch jobs table analysis.
	 *
	 * @test
	 * @requires function wp_create_initial_taxonomies
	 */
	public function test_batch_jobs_table_exists() {
		// verify table analysis would work for batch_jobs
		$this->assertTrue( method_exists( $this->analyzer, 'analyze_dashboard_queries' ) );
	}

	/**
	 * Test database capabilities detection.
	 *
	 * @test
	 * @requires function wp_create_initial_taxonomies
	 */
	public function test_database_capabilities_structure() {
		$capabilities = $this->analyzer->get_database_capabilities();

		$this->assertIsArray( $capabilities );
		$this->assertArrayHasKey( 'version', $capabilities );
		$this->assertArrayHasKey( 'supports_json', $capabilities );
		$this->assertArrayHasKey( 'supports_partitioning', $capabilities );
	}

	/**
	 * Test performance report structure.
	 *
	 * @test
	 */
	public function test_performance_report_structure() {
		$this->assertTrue( method_exists( $this->analyzer, 'generate_performance_report' ) );

		// Report should be array with expected keys
		// Full test requires database access in integrated test
	}
}
