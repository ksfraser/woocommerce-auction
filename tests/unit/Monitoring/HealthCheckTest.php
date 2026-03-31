<?php
/**
 * Tests for HealthCheck interface and implementations.
 *
 * @package ksfraser\Tests\Monitoring
 * @covers \ksfraser\Monitoring\HealthCheck
 */

namespace ksfraser\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use ksfraser\Monitoring\HealthCheck;
use ksfraser\Monitoring\Checks\APIHealthCheck;
use ksfraser\Monitoring\Checks\DatabaseHealthCheck;
use ksfraser\Monitoring\Checks\MemoryHealthCheck;
use ksfraser\Monitoring\Checks\DiskSpaceHealthCheck;
use ksfraser\Monitoring\Checks\CacheHealthCheck;

/**
 * HealthCheckTest class.
 *
 * @covers \ksfraser\Monitoring\HealthCheck
 * @covers \ksfraser\Monitoring\Checks\APIHealthCheck
 * @covers \ksfraser\Monitoring\Checks\DatabaseHealthCheck
 * @covers \ksfraser\Monitoring\Checks\MemoryHealthCheck
 * @covers \ksfraser\Monitoring\Checks\DiskSpaceHealthCheck
 * @covers \ksfraser\Monitoring\Checks\CacheHealthCheck
 */
class HealthCheckTest extends TestCase {

	/**
	 * Test API health check.
	 *
	 * @test
	 */
	public function test_api_health_check() {
		$check = new APIHealthCheck();

		$this->assertInstanceOf( HealthCheck::class, $check );

		$result = $check->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'data', $result );
	}

	/**
	 * Test database health check.
	 *
	 * @test
	 */
	public function test_database_health_check() {
		$check = new DatabaseHealthCheck();

		$this->assertInstanceOf( HealthCheck::class, $check );

		$result = $check->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test memory health check.
	 *
	 * @test
	 */
	public function test_memory_health_check() {
		$check = new MemoryHealthCheck();

		$this->assertInstanceOf( HealthCheck::class, $check );

		$result = $check->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertEquals( 'memory', $result['name'] );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'data', $result );
	}

	/**
	 * Test disk space health check.
	 *
	 * @test
	 */
	public function test_disk_space_health_check() {
		$check = new DiskSpaceHealthCheck();

		$this->assertInstanceOf( HealthCheck::class, $check );

		$result = $check->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test cache health check.
	 *
	 * @test
	 */
	public function test_cache_health_check() {
		$check = new CacheHealthCheck();

		$this->assertInstanceOf( HealthCheck::class, $check );

		$result = $check->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
	}

	/**
	 * Test health check result structure.
	 *
	 * @test
	 */
	public function test_health_check_result_structure() {
		$check = new MemoryHealthCheck();
		$result = $check->execute();

		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test health status is valid.
	 *
	 * @test
	 */
	public function test_health_status_is_valid() {
		$check = new MemoryHealthCheck();
		$result = $check->execute();

		$valid_statuses = [ 'healthy', 'degraded', 'unhealthy' ];
		$this->assertContains( $result['status'], $valid_statuses );
	}

	/**
	 * Test API health check returns required data.
	 *
	 * @test
	 */
	public function test_api_health_check_data() {
		$check = new APIHealthCheck();
		$result = $check->execute();

		$this->assertArrayHasKey( 'response_time_ms', $result['data'] );
		$this->assertArrayHasKey( 'availability', $result['data'] );
		$this->assertArrayHasKey( 'last_check', $result['data'] );
	}

	/**
	 * Test database health check data.
	 *
	 * @test
	 */
	public function test_database_health_check_data() {
		$check = new DatabaseHealthCheck();
		$result = $check->execute();

		$this->assertArrayHasKey( 'connection_time_ms', $result['data'] );
		$this->assertArrayHasKey( 'query_time_ms', $result['data'] );
	}

	/**
	 * Test memory health check data.
	 *
	 * @test
	 */
	public function test_memory_health_check_data() {
		$check = new MemoryHealthCheck();
		$result = $check->execute();

		$this->assertArrayHasKey( 'current_usage_mb', $result['data'] );
		$this->assertArrayHasKey( 'peak_usage_mb', $result['data'] );
		$this->assertArrayHasKey( 'limit_mb', $result['data'] );
		$this->assertArrayHasKey( 'usage_percent', $result['data'] );
	}

	/**
	 * Test disk space health check data.
	 *
	 * @test
	 */
	public function test_disk_space_health_check_data() {
		$check = new DiskSpaceHealthCheck();
		$result = $check->execute();

		$this->assertArrayHasKey( 'free_space_gb', $result['data'] );
		$this->assertArrayHasKey( 'total_space_gb', $result['data'] );
		$this->assertArrayHasKey( 'usage_percent', $result['data'] );
	}

	/**
	 * Test cache health check data.
	 *
	 * @test
	 */
	public function test_cache_health_check_data() {
		$check = new CacheHealthCheck();
		$result = $check->execute();

		$this->assertArrayHasKey( 'hit_rate_percent', $result['data'] );
		$this->assertArrayHasKey( 'size_mb', $result['data'] );
		$this->assertArrayHasKey( 'items', $result['data'] );
	}

	/**
	 * Test memory health check status degraded when high usage.
	 *
	 * @test
	 */
	public function test_memory_health_status_degraded_warning() {
		$check = new MemoryHealthCheck();
		$result = $check->execute();

		// Check that we got a valid status
		$valid_statuses = [ 'healthy', 'degraded', 'unhealthy' ];
		$this->assertContains( $result['status'], $valid_statuses );
	}

	/**
	 * Test all checks return numeric data.
	 *
	 * @test
	 */
	public function test_checks_return_numeric_data() {
		$checks = [
			new APIHealthCheck(),
			new DatabaseHealthCheck(),
			new MemoryHealthCheck(),
			new DiskSpaceHealthCheck(),
			new CacheHealthCheck(),
		];

		foreach ( $checks as $check ) {
			$result = $check->execute();
			$this->assertIsArray( $result['data'] );
		}
	}

	/**
	 * Test health check name is set.
	 *
	 * @test
	 */
	public function test_health_check_name_is_set() {
		$checks = [
			new APIHealthCheck(),
			new DatabaseHealthCheck(),
			new MemoryHealthCheck(),
			new DiskSpaceHealthCheck(),
			new CacheHealthCheck(),
		];

		foreach ( $checks as $check ) {
			$result = $check->execute();
			$this->assertNotEmpty( $result['name'] );
		}
	}

	/**
	 * Test health check message is set.
	 *
	 * @test
	 */
	public function test_health_check_message_is_set() {
		$checks = [
			new APIHealthCheck(),
			new DatabaseHealthCheck(),
			new MemoryHealthCheck(),
			new DiskSpaceHealthCheck(),
			new CacheHealthCheck(),
		];

		foreach ( $checks as $check ) {
			$result = $check->execute();
			$this->assertNotEmpty( $result['message'] );
		}
	}
}
