<?php
/**
 * Integration tests for monitoring system.
 *
 * @package ksfraser\Tests\Monitoring
 * @covers \ksfraser\Monitoring
 */

namespace ksfraser\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use ksfraser\Monitoring\PerformanceMonitor;
use ksfraser\Monitoring\AlertManager;
use ksfraser\Monitoring\MetricsCollector;

/**
 * MonitoringIntegrationTest class.
 *
 * @covers \ksfraser\Monitoring\PerformanceMonitor
 * @covers \ksfraser\Monitoring\AlertManager
 * @covers \ksfraser\Monitoring\MetricsCollector
 */
class MonitoringIntegrationTest extends TestCase {

	/**
	 * Test monitoring system initialization.
	 *
	 * @test
	 */
	public function test_monitoring_system_integration() {
		$monitor = new PerformanceMonitor();
		$alert_manager = new AlertManager();
		$metrics_collector = new MetricsCollector();

		$this->assertInstanceOf( PerformanceMonitor::class, $monitor );
		$this->assertInstanceOf( AlertManager::class, $alert_manager );
		$this->assertInstanceOf( MetricsCollector::class, $metrics_collector );
	}

	/**
	 * Test complete monitoring workflow.
	 *
	 * @test
	 */
	public function test_complete_monitoring_workflow() {
		$monitor = new PerformanceMonitor();
		$alert_manager = new AlertManager();
		$metrics_collector = new MetricsCollector();

		// Record API call
		$monitor->record_api_call( '/api/settlements', 150, true );
		$metrics_collector->record( 'api_response_time', 150, [ 'endpoint' => '/api/settlements' ] );

		// Record database query
		$monitor->record_database_query( 'SELECT * FROM wp_posts', 25 );
		$metrics_collector->record( 'db_query_time', 25, [ 'query_type' => 'SELECT' ] );

		// Record batch job
		$monitor->record_batch_job( 1, 1000, 5.2 );
		$metrics_collector->record( 'batch_job_duration', 5.2, [ 'job_id' => 1 ] );

		// Check metrics
		$perf_metrics = $monitor->get_performance_metrics();
		$this->assertEquals( 1, $perf_metrics['api_calls'] );
		$this->assertEquals( 1, $perf_metrics['database_queries'] );
		$this->assertEquals( 1, $perf_metrics['batch_jobs'] );

		// Check metrics collector
		$all_metrics = $metrics_collector->get_metrics();
		$this->assertGreaterThanOrEqual( 3, count( $all_metrics ) );

		// Get health status
		$health = $monitor->get_health_status();
		$this->assertArrayHasKey( 'status', $health );
		$this->assertArrayHasKey( 'metrics', $health );
	}

	/**
	 * Test alert generation on threshold breach.
	 *
	 * @test
	 */
	public function test_alert_on_threshold_breach() {
		$monitor = new PerformanceMonitor();
		$alert_manager = new AlertManager();

		// Set low threshold
		$monitor->set_threshold( 'api_response_time_ms', 100 );

		// Record slow API call
		$monitor->record_api_call( '/api/slow', 500, true );

		// Add alert
		$alert_manager->add( 'Slow API response', 'warning', 'api' );

		// Verify alert
		$alerts = $alert_manager->get_by_source( 'api' );
		$this->assertGreaterThan( 0, count( $alerts ) );
	}

	/**
	 * Test metrics export workflow.
	 *
	 * @test
	 */
	public function test_metrics_export_workflow() {
		$monitor = new PerformanceMonitor();
		$metrics_collector = new MetricsCollector();

		// Record some metrics
		$monitor->record_api_call( '/api/test', 100, true );
		$metrics_collector->record( 'test_metric', 100 );

		// Export
		$export = $monitor->export_metrics();
		$collector_export = $metrics_collector->export();

		$this->assertIsArray( $export );
		$this->assertArrayHasKey( 'metrics', $export );
		$this->assertIsArray( $collector_export );
	}

	/**
	 * Test health check aggregation.
	 *
	 * @test
	 */
	public function test_health_check_aggregation() {
		$monitor = new PerformanceMonitor();

		// Record various metrics
		$monitor->record_api_call( '/api/test', 50, true );
		$monitor->record_database_query( 'SELECT 1', 10 );

		$health = $monitor->get_health_status();

		// Verify aggregated health status
		$this->assertArrayHasKey( 'checks', $health );
		$this->assertArrayHasKey( 'alerts', $health );
		$this->assertArrayHasKey( 'status', $health );
	}

	/**
	 * Test metrics aggregation.
	 *
	 * @test
	 */
	public function test_metrics_aggregation() {
		$monitor = new PerformanceMonitor();

		// Record multiple API calls
		for ( $i = 0; $i < 5; $i++ ) {
			$monitor->record_api_call( '/api/test', 100 + $i * 10, true );
		}

		$metrics = $monitor->get_performance_metrics();

		$this->assertEquals( 5, $metrics['api_calls'] );
		$this->assertGreaterThan( 100, $metrics['api_average_time_ms'] );
	}

	/**
	 * Test error tracking.
	 *
	 * @test
	 */
	public function test_error_tracking() {
		$monitor = new PerformanceMonitor();
		$alert_manager = new AlertManager();

		// Record errors
		$monitor->record_error( 'Database connection failed', 'database' );
		$monitor->record_error( 'API timeout', 'api' );

		$alert_manager->add( 'System error 1', 'error', 'system' );
		$alert_manager->add( 'System error 2', 'error', 'system' );

		$metrics = $monitor->get_performance_metrics();
		$alerts = $alert_manager->get_by_level( 'error' );

		$this->assertEquals( 2, $metrics['total_errors'] );
		$this->assertEquals( 2, count( $alerts ) );
	}

	/**
	 * Test performance monitoring over time.
	 *
	 * @test
	 */
	public function test_performance_monitoring_timeline() {
		$monitor = new PerformanceMonitor();
		$metrics_collector = new MetricsCollector();

		// Simulate API calls over time
		for ( $i = 0; $i < 10; $i++ ) {
			$response_time = 100 + $i * 5;
			$monitor->record_api_call( '/api/test', $response_time, true );
			$metrics_collector->record( 'api_response_time', $response_time );
		}

		// Verify trend
		$metrics = $monitor->get_performance_metrics();
		$this->assertEquals( 10, $metrics['api_calls'] );

		// Verify average calculation
		$average = $metrics_collector->get_average( 'api_response_time' );
		$this->assertGreaterThan( 100, $average );

		// Verify min/max
		$min = $metrics_collector->get_min( 'api_response_time' );
		$max = $metrics_collector->get_max( 'api_response_time' );

		$this->assertEquals( 100, $min );
		$this->assertGreaterThan( $min, $max );
	}

	/**
	 * Test monitoring system state consistency.
	 *
	 * @test
	 */
	public function test_monitoring_state_consistency() {
		$monitor = new PerformanceMonitor();
		$alert_manager = new AlertManager();

		// Record operations
		$monitor->record_api_call( '/api/test', 100, true );
		$alert_manager->add( 'Test alert', 'info', 'test' );

		// Verify state
		$metrics = $monitor->get_performance_metrics();
		$alerts = $alert_manager->get_recent( 10 );

		$this->assertEquals( 1, $metrics['api_calls'] );
		$this->assertEquals( 1, count( $alerts ) );

		// Reset and verify
		$monitor->reset_metrics();
		$alert_manager->clear();

		$metrics = $monitor->get_performance_metrics();
		$alerts = $alert_manager->get_recent( 10 );

		$this->assertEquals( 0, $metrics['api_calls'] );
		$this->assertEquals( 0, count( $alerts ) );
	}
}
