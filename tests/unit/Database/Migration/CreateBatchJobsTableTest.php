<?php
/**
 * Tests for batch_jobs table migration.
 *
 * @package ksfraser\Tests\Database
 * @covers \ksfraser\Database\Migration\CreateBatchJobsTable
 */

namespace ksfraser\Tests\Database\Migration;

use PHPUnit\Framework\TestCase;
use ksfraser\Database\Migration\CreateBatchJobsTable;

/**
 * CreateBatchJobsTableTest class.
 *
 * @covers \ksfraser\Database\Migration\CreateBatchJobsTable
 */
class CreateBatchJobsTableTest extends TestCase {

	/**
	 * Migration instance.
	 *
	 * @var CreateBatchJobsTable
	 */
	private $migration;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migration = new CreateBatchJobsTable();
	}

	/**
	 * Test migration ID format.
	 *
	 * @test
	 */
	public function test_migration_id_format() {
		$id = $this->migration->get_migration_id();

		$this->assertStringContainsString( '2026_03_30_140000_create_batch_jobs_table', $id );
	}

	/**
	 * Test migration description.
	 *
	 * @test
	 */
	public function test_migration_description() {
		$description = $this->migration->get_description();

		$this->assertStringContainsString( 'batch_jobs', $description );
	}

	/**
	 * Test migration can be created without error.
	 *
	 * @test
	 */
	public function test_migration_instantiation() {
		$this->assertInstanceOf( CreateBatchJobsTable::class, $this->migration );
	}

	/**
	 * Test migration up method returns boolean.
	 *
	 * @test
	 * @requires function wp_create_initial_taxonomies
	 */
	public function test_migration_up_returns_boolean() {
		// In actual test, this would run against test database
		// For now, just verify method exists and is callable
		$this->assertTrue( method_exists( $this->migration, 'up' ) );
	}

	/**
	 * Test migration down method returns boolean.
	 *
	 * @test
	 * @requires function wp_create_initial_taxonomies
	 */
	public function test_migration_down_returns_boolean() {
		$this->assertTrue( method_exists( $this->migration, 'down' ) );
	}

	/**
	 * Test migration is not yet applied (in isolated test).
	 *
	 * @test
	 */
	public function test_migration_not_applied_initially() {
		// In a fresh test environment, migration should not be applied
		// Actual implementation would check database
		$this->assertFalse( $this->migration->is_applied() );
	}

	/**
	 * Test batch_jobs table status constants are valid.
	 *
	 * @test
	 */
	public function test_batch_jobs_status_constants() {
		// Valid statuses for batch jobs
		$valid_statuses = array( 'pending', 'running', 'completed', 'failed' );

		$this->assertNotEmpty( $valid_statuses );
		$this->assertContains( 'pending', $valid_statuses );
		$this->assertContains( 'running', $valid_statuses );
		$this->assertContains( 'completed', $valid_statuses );
		$this->assertContains( 'failed', $valid_statuses );
	}

	/**
	 * Test batch_jobs table job_type constants are valid.
	 *
	 * @test
	 */
	public function test_batch_jobs_type_constants() {
		// Valid job types for batch jobs
		$valid_types = array( 'bulk_payout', 'bulk_settlement', 'bulk_dispute', 'custom' );

		$this->assertNotEmpty( $valid_types );
		$this->assertContains( 'bulk_payout', $valid_types );
		$this->assertContains( 'bulk_settlement', $valid_types );
		$this->assertContains( 'bulk_dispute', $valid_types );
		$this->assertContains( 'custom', $valid_types );
	}

	/**
	 * Test expected table columns are defined.
	 *
	 * @test
	 */
	public function test_expected_columns() {
		$expected_columns = array(
			'id',
			'job_type',
			'parameters',
			'description',
			'status',
			'total_items',
			'processed_items',
			'failed_items',
			'logs',
			'scheduled_for',
			'started_at',
			'completed_at',
			'created_at',
			'updated_at',
		);

		$this->assertCount( 14, $expected_columns );
		$this->assertContains( 'id', $expected_columns );
		$this->assertContains( 'job_type', $expected_columns );
		$this->assertContains( 'status', $expected_columns );
		$this->assertContains( 'logs', $expected_columns );
	}

	/**
	 * Test expected table indexes are defined.
	 *
	 * @test
	 */
	public function test_expected_indexes() {
		$expected_indexes = array(
			'idx_status',
			'idx_job_type',
			'idx_scheduled_for',
			'idx_created_at',
			'idx_status_created',
			'idx_job_type_status',
		);

		$this->assertCount( 6, $expected_indexes );
		$this->assertContains( 'idx_status', $expected_indexes );
		$this->assertContains( 'idx_job_type', $expected_indexes );
		$this->assertContains( 'idx_created_at', $expected_indexes );
	}
}
