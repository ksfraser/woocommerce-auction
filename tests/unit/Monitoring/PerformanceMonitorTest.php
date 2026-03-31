<?php
/**
 * Tests for PerformanceMonitor class.
 *
 * @package ksfraser\Tests\Monitoring
 * @covers \ksfraser\Monitoring\PerformanceMonitor
 */

namespace ksfraser\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use ksfraser\Monitoring\PerformanceMonitor;

/**
 * PerformanceMonitorTest class.
 *
 * @covers \ksfraser\Monitoring\PerformanceMonitor
 */
class PerformanceMonitorTest extends TestCase {

	/**
	 * Performance monitor instance.
	 *
	 * @var PerformanceMonitor
	 */
	private $monitor;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->monitor = new PerformanceMonitor();
	}

	/**
	 * Test monitor instantiation.
	 *
	 * @test
	 */
	public function test_instantiation() {
		$this->assertInstanceOf( PerformanceMonitor::class, $this->monitor );
	}

	/**
	 * Test record API call.
	 *
	 * @test
	 */
	public function test_record_api_call() {
		$this->monitor->record_api_call( '/api/settlements', 150.5, true );

		$metrics = $this->monitor->get_performance_metrics();

		$this->assertEquals( 1, $metrics['api_calls'] );
		$this->assertGreaterThan( 0, $metrics['api_average_time_ms'] );
	}

	/**
	 * Test record database query.
	 *
	 * @test
	 */
	public function test_record_database_query() {
		$this->monitor->record_database_query( 'SELECT * FROM wp_users', 25.3 );

		$metrics = $this->monitor->get_performance_metrics();

		$this->assertEquals( 1, $metrics['database_queries'] );
		$this->assertGreaterThan( 0, $metrics['database_average_time_ms'] );
	}

	/**
	 * Test record batch job.
	 *
	 * @test
	 */
	public function test_record_batch_job() {
		$this->monitor->record_batch_job( 123, 100, 5.2 );

		$metrics = $this->monitor->get_performance_metrics();

		$this->assertEquals( 1, $metrics['batch_jobs'] );
		$this->assertGreaterThan( 0, $metrics['batch_average_time_sec'] );
	}

	/**
	 * Test record error.
	 *
	 * @test
	 */
	public function test_record_error() {
		$this->monitor->record_error( 'Database connection failed', 'initialization' );

		$metrics = $this->monitor->get_performance_metrics();

		$this->assertEquals( 1, $metrics['total_errors'] );
	}

	/**
	 * Test get health status.
	 *
	 * @test
	 */
	public function test_get_health_status() {
		$health = $this->monitor->get_health_status();

		$this->assertIsArray( $health );
		$this->assertArrayHasKey( 'status', $health );
		$this->assertArrayHasKey( 'checks', $health );
		$this->assertArrayHasKey( 'alerts', $health );
		$this->assertArrayHasKey( 'metrics', $health );
		$this->assertArrayHasKey( 'timestamp', $health );
	}

	/**
	 * Test health status is healthy by default.
	 *
	 * @test
	 */
	public function test_health_status_healthy_by_default() {
		$health = $this->monitor->get_health_status();

		$this->assertEquals( 'healthy', $health['status'] );
		$this->assertIsArray( $health['alerts'] );
	}

	/**
	 * Test performance metrics structure.
	 *
	 * @test
	 */
	public function test_performance_metrics_structure() {
		$metrics = $this->monitor->get_performance_metrics();

		$this->assertArrayHasKey( 'api_calls', $metrics );
		$this->assertArrayHasKey( 'api_average_time_ms', $metrics );
		$this->assertArrayHasKey( 'database_queries', $metrics );
		$this->assertArrayHasKey( 'database_average_time_ms', $metrics );
		$this->assertArrayHasKey( 'batch_jobs', $metrics );
		$this->assertArrayHasKey( 'batch_average_time_sec', $metrics );
		$this->assertArrayHasKey( 'total_errors', $metrics );
		$this->assertArrayHasKey( 'memory_usage_mb', $metrics );
	}

	/**
	 * Test set threshold.
	 *
	 * @test
	 */
	public function test_set_threshold() {
		$this->monitor->set_threshold( 'api_response_time_ms', 1000 );
		$thresholds = $this->monitor->get_thresholds();

		$this->assertEquals( 1000, $thresholds['api_response_time_ms'] );
	}

	/**
	 * Test get thresholds.
	 *
	 * @test
	 */
	public function test_get_thresholds() {
		$thresholds = $this->monitor->get_thresholds();

		$this->assertIsArray( $thresholds );
		$this->assertArrayHasKey( 'api_response_time_ms', $thresholds );
		$this->assertArrayHasKey( 'database_query_time_ms', $thresholds );
		$this->assertArrayHasKey( 'batch_job_time_seconds', $thresholds );
		$this->assertArrayHasKey( 'memory_usage_pct', $thresholds );
		$this->assertArrayHasKey( 'disk_usage_pct', $thresholds );
		$this->assertArrayHasKey( 'cache_hit_rate_pct', $thresholds );
		$this->assertArrayHasKey( 'error_rate_pct', $thresholds );
	}

	/**
	 * Test reset metrics.
	 *
	 * @test
	 */
	public function test_reset_metrics() {
		$this->monitor->record_api_call( '/api/test', 50, true );
		$this->monitor->reset_metrics();

		$metrics = $this->monitor->get_performance_metrics();

		$this->assertEquals( 0, $metrics['api_calls'] );
	}

	/**
	 * Test export metrics.
	 *
	 * @test
	 */
	public function test_export_metrics() {
		$this->monitor->record_api_call( '/api/test', 50, true );

		$export = $this->monitor->export_metrics();

		$this->assertIsArray( $export );
		$this->assertArrayHasKey( 'metrics', $export );
		$this->assertArrayHasKey( 'thresholds', $export );
		$this->assertArrayHasKey( 'exported_at', $export );
		$this->assertArrayHasKey( 'health_status', $export );
	}

	/**
	 * Test slow API alert detection.
	 *
	 * @test
	 */
	public function test_slow_api_detection() {
		$this->monitor->set_threshold( 'api_response_time_ms', 100 );
		$this->monitor->record_api_call( '/api/slow', 500, true );

		$health = $this->monitor->get_health_status();

		$this->assertNotEmpty( $health['alerts'] );
	}

	/**
	 * Test slow query alert detection.
	 *
	 * @test
	 */
	public function test_slow_query_detection() {
		$this->monitor->set_threshold( 'database_query_time_ms', 50 );
		$this->monitor->record_database_query( 'SELECT * FROM massive_table', 200 );

		$health = $this->monitor->get_health_status();

		$this->assertNotEmpty( $health['alerts'] );
	}

	/**
	 * Test error rate calculation.
	 *
	 * @test
	 */
	public function test_error_rate_calculation() {
		$this->monitor->set_threshold( 'error_rate_pct', 1 );

		// Record some failed API calls
		for ( $i = 0; $i < 10; $i++ ) {
			$this->monitor->record_api_call( '/api/test', 50, false );
		}

		$health = $this->monitor->get_health_status();

		// Should detect high error rate
		$this->assertGreaterThan( 0, count( $health['alerts'] ) );
	}

	/**
	 * Test metrics are numeric.
	 *
	 * @test
	 */
	public function test_metrics_are_numeric() {
		$this->monitor->record_api_call( '/api/test', 100, true );
		$this->monitor->record_database_query( 'SELECT 1', 25 );

		$metrics = $this->monitor->get_performance_metrics();

		$this->assertIsNumeric( $metrics['api_calls'] );
		$this->assertIsNumeric( $metrics['api_average_time_ms'] );
		$this->assertIsNumeric( $metrics['database_queries'] );
		$this->assertIsNumeric( $metrics['memory_usage_mb'] );
	}

	/**
	 * Test multiple operations tracking.
	 *
	 * @test
	 */
	public function test_multiple_operations_tracking() {
		$this->monitor->record_api_call( '/api/1', 50, true );
		$this->monitor->record_api_call( '/api/2', 75, true );
		$this->monitor->record_api_call( '/api/3', 100, true );

		$metrics = $this->monitor->get_performance_metrics();

		$this->assertEquals( 3, $metrics['api_calls'] );
		$this->assertGreaterThan( 50, $metrics['api_average_time_ms'] );
		$this->assertLessThan( 100, $metrics['api_average_time_ms'] );
	}
}
