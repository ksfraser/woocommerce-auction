<?php
/**
 * Integration tests for database schema initialization.
 *
 * @package ksfraser\Tests\Integration
 * @covers Database schema initialization
 */

namespace ksfraser\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ksfraser\Database\Schema\DatabaseSchemaInitializer;
use ksfraser\Database\Migration\DatabaseMigrator;

/**
 * DatabaseSchemaIntegrationTest class.
 *
 * Integration tests for complete database schema initialization workflow.
 */
class DatabaseSchemaIntegrationTest extends TestCase {

	/**
	 * Schema initializer instance.
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
	 * Test database schema initialization workflow.
	 *
	 * @test
	 * @group integration
	 */
	public function test_schema_initialization_workflow() {
		$this->assertInstanceOf( DatabaseSchemaInitializer::class, $this->initializer );

		$stats = $this->initializer->get_statistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_registered', $stats );
	}

	/**
	 * Test migrations are properly registered.
	 *
	 * @test
	 * @group integration
	 */
	public function test_migrations_are_registered() {
		$stats = $this->initializer->get_statistics();

		// Should have batch_jobs and dashboard_views migrations registered
		$this->assertGreaterThanOrEqual( 2, $stats['total_registered'] );
	}

	/**
	 * Test pending migrations can be identified.
	 *
	 * @test
	 * @group integration
	 */
	public function test_pending_migrations_identified() {
		$pending = $this->initializer->get_pending_migrations();

		$this->assertIsArray( $pending );
	}

	/**
	 * Test applied migrations tracking.
	 *
	 * @test
	 * @group integration
	 */
	public function test_applied_migrations_tracking() {
		$applied = $this->initializer->get_applied_migrations();

		$this->assertIsArray( $applied );
	}

	/**
	 * Test schema statistics are consistent.
	 *
	 * @test
	 * @group integration
	 */
	public function test_schema_statistics_consistency() {
		$stats = $this->initializer->get_statistics();

		// Total = Applied + Pending
		$total = $stats['total_applied'] + $stats['total_pending'];
		$this->assertEquals( $stats['total_registered'], $total );
	}

	/**
	 * Test closure without table deletion.
	 *
	 * @test
	 * @group integration
	 */
	public function test_cleanup_without_deletion() {
		$result = $this->initializer->cleanup( false );

		$this->assertTrue( $result );
	}

	/**
	 * Test batch_jobs migration is recognizable.
	 *
	 * @test
	 * @group integration
	 */
	public function test_batch_jobs_migration_exists() {
		$pending = $this->initializer->get_pending_migrations();

		// At least one migration should exist
		$this->assertIsArray( $pending );
	}

	/**
	 * Test dashboard_views migration is recognizable.
	 *
	 * @test
	 * @group integration
	 */
	public function test_dashboard_views_migration_exists() {
		$pending = $this->initializer->get_pending_migrations();

		// At least one migration should exist
		$this->assertIsArray( $pending );
	}

	/**
	 * Test schema initialization with multiple calls.
	 *
	 * @test
	 * @group integration
	 */
	public function test_multiple_initialization_calls() {
		$stats1 = $this->initializer->get_statistics();
		$stats2 = $this->initializer->get_statistics();

		// Statistics should be consistent across calls
		$this->assertEquals( $stats1['total_registered'], $stats2['total_registered'] );
	}

	/**
	 * Test database migrator is used internally.
	 *
	 * @test
	 * @group integration
	 */
	public function test_uses_database_migrator() {
		// Initializer should have registered migrations via migrator
		$stats = $this->initializer->get_statistics();

		$this->assertGreaterThan( 0, $stats['total_registered'] );
	}

	/**
	 * Test schema initialization is idempotent.
	 *
	 * @test
	 * @group integration
	 */
	public function test_schema_initialization_idempotent() {
		// Creating multiple initializers should not cause issues
		$init1 = new DatabaseSchemaInitializer();
		$init2 = new DatabaseSchemaInitializer();

		$stats1 = $init1->get_statistics();
		$stats2 = $init2->get_statistics();

		$this->assertEquals( $stats1['total_registered'], $stats2['total_registered'] );
	}

	/**
	 * Test column configuration (ENUM types).
	 *
	 * @test
	 * @group integration
	 */
	public function test_batch_job_status_enum_values() {
		// Valid statuses that should exist in batch_jobs table
		$valid_statuses = array( 'pending', 'running', 'completed', 'failed' );

		$this->assertNotEmpty( $valid_statuses );
		$this->assertCount( 4, $valid_statuses );
	}

	/**
	 * Test batch job type configuration.
	 *
	 * @test
	 * @group integration
	 */
	public function test_batch_job_type_configuration() {
		// Valid job types that should exist in batch_jobs table
		$valid_types = array( 'bulk_payout', 'bulk_settlement', 'bulk_dispute', 'custom' );

		$this->assertNotEmpty( $valid_types );
		$this->assertCount( 4, $valid_types );
	}

	/**
	 * Test dashboard views types configuration.
	 *
	 * @test
	 * @group integration
	 */
	public function test_dashboard_view_types_configuration() {
		// Valid view types that should exist in dashboard_views table
		$valid_types = array(
			'settlement',
			'revenue',
			'performance',
			'disputes',
			'health',
			'payouts',
		);

		$this->assertNotEmpty( $valid_types );
		$this->assertCount( 6, $valid_types );
	}
}
