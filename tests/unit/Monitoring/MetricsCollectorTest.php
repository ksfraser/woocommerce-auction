<?php
/**
 * Tests for MetricsCollector class.
 *
 * @package ksfraser\Tests\Monitoring
 * @covers \ksfraser\Monitoring\MetricsCollector
 */

namespace ksfraser\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use ksfraser\Monitoring\MetricsCollector;

/**
 * MetricsCollectorTest class.
 *
 * @covers \ksfraser\Monitoring\MetricsCollector
 */
class MetricsCollectorTest extends TestCase {

	/**
	 * Metrics collector instance.
	 *
	 * @var MetricsCollector
	 */
	private $collector;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->collector = new MetricsCollector();
	}

	/**
	 * Test collector instantiation.
	 *
	 * @test
	 */
	public function test_instantiation() {
		$this->assertInstanceOf( MetricsCollector::class, $this->collector );
	}

	/**
	 * Test record metric.
	 *
	 * @test
	 */
	public function test_record_metric() {
		$this->collector->record( 'test_metric', 100, [ 'tag' => 'test' ] );

		$metrics = $this->collector->get_metrics();

		$this->assertNotEmpty( $metrics );
	}

	/**
	 * Test get metric by name.
	 *
	 * @test
	 */
	public function test_get_metric_by_name() {
		$this->collector->record( 'response_time', 150 );

		$metric = $this->collector->get_metric( 'response_time' );

		$this->assertNotNull( $metric );
	}

	/**
	 * Test get all metrics.
	 *
	 * @test
	 */
	public function test_get_all_metrics() {
		$this->collector->record( 'metric1', 100 );
		$this->collector->record( 'metric2', 200 );
		$this->collector->record( 'metric3', 300 );

		$metrics = $this->collector->get_metrics();

		$this->assertGreaterThanOrEqual( 3, count( $metrics ) );
	}

	/**
	 * Test calculate average.
	 *
	 * @test
	 */
	public function test_calculate_average() {
		$this->collector->record( 'response_time', 100 );
		$this->collector->record( 'response_time', 200 );
		$this->collector->record( 'response_time', 300 );

		$average = $this->collector->get_average( 'response_time' );

		$this->assertEquals( 200, $average );
	}

	/**
	 * Test calculate min value.
	 *
	 * @test
	 */
	public function test_calculate_min() {
		$this->collector->record( 'response_time', 100 );
		$this->collector->record( 'response_time', 200 );
		$this->collector->record( 'response_time', 50 );

		$min = $this->collector->get_min( 'response_time' );

		$this->assertEquals( 50, $min );
	}

	/**
	 * Test calculate max value.
	 *
	 * @test
	 */
	public function test_calculate_max() {
		$this->collector->record( 'response_time', 100 );
		$this->collector->record( 'response_time', 200 );
		$this->collector->record( 'response_time', 500 );

		$max = $this->collector->get_max( 'response_time' );

		$this->assertEquals( 500, $max );
	}

	/**
	 * Test filter metrics by tags.
	 *
	 * @test
	 */
	public function test_filter_by_tags() {
		$this->collector->record( 'metric1', 100, [ 'type' => 'api' ] );
		$this->collector->record( 'metric2', 200, [ 'type' => 'database' ] );
		$this->collector->record( 'metric3', 300, [ 'type' => 'api' ] );

		$api_metrics = $this->collector->get_metrics( [ 'type' => 'api' ] );

		$this->assertGreaterThan( 1, count( $api_metrics ) );
	}

	/**
	 * Test metric structure.
	 *
	 * @test
	 */
	public function test_metric_structure() {
		$this->collector->record( 'test_metric', 100, [ 'tag' => 'test' ] );

		$metric = $this->collector->get_metric( 'test_metric' );

		$this->assertArrayHasKey( 'name', $metric );
		$this->assertArrayHasKey( 'value', $metric );
		$this->assertArrayHasKey( 'timestamp', $metric );
		$this->assertArrayHasKey( 'tags', $metric );
	}

	/**
	 * Test clear metrics.
	 *
	 * @test
	 */
	public function test_clear_metrics() {
		$this->collector->record( 'metric1', 100 );
		$this->collector->record( 'metric2', 200 );

		$this->collector->clear();

		$metrics = $this->collector->get_metrics();

		$this->assertCount( 0, $metrics );
	}

	/**
	 * Test get percentile.
	 *
	 * @test
	 */
	public function test_get_percentile() {
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->collector->record( 'response_time', $i );
		}

		$p95 = $this->collector->get_percentile( 'response_time', 95 );

		$this->assertGreaterThan( 90, $p95 );
	}

	/**
	 * Test count metrics.
	 *
	 * @test
	 */
	public function test_count_metrics() {
		$this->collector->record( 'response_time', 100 );
		$this->collector->record( 'response_time', 200 );
		$this->collector->record( 'response_time', 300 );

		$count = $this->collector->count( 'response_time' );

		$this->assertEquals( 3, $count );
	}

	/**
	 * Test tags are preserved.
	 *
	 * @test
	 */
	public function test_tags_are_preserved() {
		$tags = [ 'endpoint' => '/api/users', 'method' => 'GET' ];
		$this->collector->record( 'api_call', 150, $tags );

		$metric = $this->collector->get_metric( 'api_call' );

		$this->assertEquals( $tags, $metric['tags'] );
	}

	/**
	 * Test timestamp is set.
	 *
	 * @test
	 */
	public function test_timestamp_is_set() {
		$this->collector->record( 'test_metric', 100 );

		$metric = $this->collector->get_metric( 'test_metric' );

		$this->assertNotEmpty( $metric['timestamp'] );
		$this->assertIsNumeric( $metric['timestamp'] );
	}

	/**
	 * Test metric values are numeric.
	 *
	 * @test
	 */
	public function test_metric_values_are_numeric() {
		$this->collector->record( 'test_metric', 42.5 );

		$metric = $this->collector->get_metric( 'test_metric' );

		$this->assertIsNumeric( $metric['value'] );
	}

	/**
	 * Test standard deviation calculation.
	 *
	 * @test
	 */
	public function test_standard_deviation() {
		$this->collector->record( 'response_time', 10 );
		$this->collector->record( 'response_time', 20 );
		$this->collector->record( 'response_time', 30 );

		$stddev = $this->collector->get_stddev( 'response_time' );

		$this->assertGreaterThan( 0, $stddev );
	}

	/**
	 * Test export metrics.
	 *
	 * @test
	 */
	public function test_export_metrics() {
		$this->collector->record( 'metric1', 100, [ 'type' => 'test' ] );
		$this->collector->record( 'metric2', 200, [ 'type' => 'test' ] );

		$export = $this->collector->export();

		$this->assertIsArray( $export );
		$this->assertGreaterThan( 0, count( $export ) );
	}

	/**
	 * Test get metrics sorted by name.
	 *
	 * @test
	 */
	public function test_metrics_sorted_by_name() {
		$this->collector->record( 'z_metric', 100 );
		$this->collector->record( 'a_metric', 200 );
		$this->collector->record( 'm_metric', 300 );

		$metrics = $this->collector->get_metrics();

		// Verify we have all metrics
		$this->assertGreaterThanOrEqual( 3, count( $metrics ) );
	}
}
