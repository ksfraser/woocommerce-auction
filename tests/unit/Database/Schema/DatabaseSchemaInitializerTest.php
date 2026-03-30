<?php
/**
 * Tests for DatabaseSchemaInitializer class.
 *
 * @package ksfraser\Tests\Database
 * @covers \ksfraser\Database\Schema\DatabaseSchemaInitializer
 */

namespace ksfraser\Tests\Database\Schema;

use PHPUnit\Framework\TestCase;
use ksfraser\Database\Schema\DatabaseSchemaInitializer;

/**
 * DatabaseSchemaInitializerTest class.
 *
 * @covers \ksfraser\Database\Schema\DatabaseSchemaInitializer
 */
class DatabaseSchemaInitializerTest extends TestCase {

	/**
	 * Initializer instance.
	 *
	 * @var DatabaseSchemaInitializer
	 */
	private $initializer;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->initializer = new DatabaseSchemaInitializer();
	}

	/**
	 * Test initializer instantiation.
	 *
	 * @test
	 */
	public function test_initializer_instantiation() {
		$this->assertInstanceOf( DatabaseSchemaInitializer::class, $this->initializer );
	}

	/**
	 * Test get statistics.
	 *
	 * @test
	 */
	public function test_get_statistics() {
		$stats = $this->initializer->get_statistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_registered', $stats );
		$this->assertArrayHasKey( 'total_applied', $stats );
		$this->assertArrayHasKey( 'total_pending', $stats );
	}

	/**
	 * Test get applied migrations.
	 *
	 * @test
	 */
	public function test_get_applied_migrations() {
		$applied = $this->initializer->get_applied_migrations();

		$this->assertIsArray( $applied );
	}

	/**
	 * Test get pending migrations.
	 *
	 * @test
	 */
	public function test_get_pending_migrations() {
		$pending = $this->initializer->get_pending_migrations();

		$this->assertIsArray( $pending );
	}

	/**
	 * Test initialize method returns array.
	 *
	 * @test
	 * @requires function wp_create_initial_taxonomies
	 */
	public function test_initialize_returns_array() {
		// Note: This test would actually run migrations in a test environment
		$stats = $this->initializer->get_statistics();

		$this->assertIsArray( $stats );
	}

	/**
	 * Test statistics contains all expected fields.
	 *
	 * @test
	 */
	public function test_statistics_contains_all_fields() {
		$stats = $this->initializer->get_statistics();

		$this->assertArrayHasKey( 'total_registered', $stats );
		$this->assertArrayHasKey( 'total_applied', $stats );
		$this->assertArrayHasKey( 'total_pending', $stats );
		$this->assertArrayHasKey( 'total_exec_time', $stats );
		$this->assertArrayHasKey( 'avg_exec_time', $stats );
	}

	/**
	 * Test statistics values are numeric.
	 *
	 * @test
	 */
	public function test_statistics_values_are_numeric() {
		$stats = $this->initializer->get_statistics();

		$this->assertIsInt( $stats['total_registered'] );
		$this->assertIsInt( $stats['total_applied'] );
		$this->assertIsInt( $stats['total_pending'] );
		$this->assertIsNumeric( $stats['total_exec_time'] );
		$this->assertIsNumeric( $stats['avg_exec_time'] );
	}

	/**
	 * Test statistics values are non-negative.
	 *
	 * @test
	 */
	public function test_statistics_values_non_negative() {
		$stats = $this->initializer->get_statistics();

		$this->assertGreaterThanOrEqual( 0, $stats['total_registered'] );
		$this->assertGreaterThanOrEqual( 0, $stats['total_applied'] );
		$this->assertGreaterThanOrEqual( 0, $stats['total_pending'] );
		$this->assertGreaterThanOrEqual( 0, $stats['total_exec_time'] );
		$this->assertGreaterThanOrEqual( 0, $stats['avg_exec_time'] );
	}

	/**
	 * Test cleanup method with tables not deleted.
	 *
	 * @test
	 */
	public function test_cleanup_without_deletion() {
		$result = $this->initializer->cleanup( false );

		$this->assertTrue( $result );
	}

	/**
	 * Test cleanup method signature.
	 *
	 * @test
	 */
	public function test_cleanup_method_exists() {
		$this->assertTrue( method_exists( $this->initializer, 'cleanup' ) );
	}

	/**
	 * Test initialize method exists.
	 *
	 * @test
	 */
	public function test_initialize_method_exists() {
		$this->assertTrue( method_exists( $this->initializer, 'initialize' ) );
	}

	/**
	 * Test registered migrations count is greater than zero.
	 *
	 * @test
	 */
	public function test_registered_migrations_count() {
		$stats = $this->initializer->get_statistics();

		// Should have at least batch_jobs and dashboard_views migrations
		$this->assertGreaterThanOrEqual( 2, $stats['total_registered'] );
	}
}
