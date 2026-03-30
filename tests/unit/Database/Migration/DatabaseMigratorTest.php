<?php
/**
 * Tests for DatabaseMigrator class.
 *
 * @package ksfraser\Tests\Database
 * @covers \ksfraser\Database\Migration\DatabaseMigrator
 */

namespace ksfraser\Tests\Database\Migration;

use PHPUnit\Framework\TestCase;
use ksfraser\Database\Migration\DatabaseMigrator;
use ksfraser\Database\Migration\CreateBatchJobsTable;
use ksfraser\Database\Migration\CreateDashboardViewsTable;

/**
 * DatabaseMigratorTest class.
 *
 * @covers \ksfraser\Database\Migration\DatabaseMigrator
 */
class DatabaseMigratorTest extends TestCase {

	/**
	 * Migrator instance.
	 *
	 * @var DatabaseMigrator
	 */
	private $migrator;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migrator = new DatabaseMigrator();
	}

	/**
	 * Test migrator instantiation.
	 *
	 * @test
	 */
	public function test_migrator_instantiation() {
		$this->assertInstanceOf( DatabaseMigrator::class, $this->migrator );
	}

	/**
	 * Test register migration.
	 *
	 * @test
	 */
	public function test_register_migration() {
		$migration = new CreateBatchJobsTable();
		$this->migrator->register_migration( $migration );

		$migrations = $this->migrator->get_migrations();
		$this->assertNotEmpty( $migrations );
		$this->assertArrayHasKey( $migration->get_migration_id(), $migrations );
	}

	/**
	 * Test register multiple migrations.
	 *
	 * @test
	 */
	public function test_register_multiple_migrations() {
		$migrations = array(
			new CreateBatchJobsTable(),
			new CreateDashboardViewsTable(),
		);

		$this->migrator->register_migrations( $migrations );

		$registered = $this->migrator->get_migrations();
		$this->assertCount( 2, $registered );
	}

	/**
	 * Test get migrations.
	 *
	 * @test
	 */
	public function test_get_migrations() {
		$migration = new CreateBatchJobsTable();
		$this->migrator->register_migration( $migration );

		$migrations = $this->migrator->get_migrations();

		$this->assertIsArray( $migrations );
		$this->assertNotEmpty( $migrations );
	}

	/**
	 * Test get pending migrations.
	 *
	 * @test
	 */
	public function test_get_pending_migrations() {
		$migration = new CreateBatchJobsTable();
		$this->migrator->register_migration( $migration );

		$pending = $this->migrator->get_pending_migrations();

		// Initially, migrations should be pending (not applied)
		$this->assertIsArray( $pending );
	}

	/**
	 * Test get applied migrations.
	 *
	 * @test
	 */
	public function test_get_applied_migrations() {
		$applied = $this->migrator->get_applied_migrations();

		// Should return array
		$this->assertIsArray( $applied );
	}

	/**
	 * Test get statistics.
	 *
	 * @test
	 */
	public function test_get_statistics() {
		$this->migrator->register_migration( new CreateBatchJobsTable() );

		$stats = $this->migrator->get_statistics();

		$this->assertIsArray( $stats );
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
	public function test_statistics_numeric_values() {
		$this->migrator->register_migration( new CreateBatchJobsTable() );

		$stats = $this->migrator->get_statistics();

		$this->assertIsInt( $stats['total_registered'] );
		$this->assertIsInt( $stats['total_applied'] );
		$this->assertIsInt( $stats['total_pending'] );
		$this->assertIsNumeric( $stats['total_exec_time'] );
		$this->assertIsNumeric( $stats['avg_exec_time'] );
	}

	/**
	 * Test migrate returns array result.
	 *
	 * @test
	 */
	public function test_migrate_returns_result_array() {
		$result = $this->migrator->migrate();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'migrations_run', $result );
		$this->assertArrayHasKey( 'migrations_total', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test migrate with no pending migrations.
	 *
	 * @test
	 */
	public function test_migrate_no_pending() {
		$result = $this->migrator->migrate();

		// With no registered migrations, should return success with 0 migrations run
		$this->assertEquals( 'success', $result['status'] );
		$this->assertIsInt( $result['migrations_run'] );
		$this->assertIsInt( $result['migrations_total'] );
	}

	/**
	 * Test migrations table initialization.
	 *
	 * @test
	 */
	public function test_init_migrations_table() {
		$result = $this->migrator->init_migrations_table();

		$this->assertTrue( $result );
	}

	/**
	 * Test int migrations table is idempotent.
	 *
	 * @test
	 */
	public function test_init_migrations_table_idempotent() {
		$result1 = $this->migrator->init_migrations_table();
		$result2 = $this->migrator->init_migrations_table();

		$this->assertTrue( $result1 );
		$this->assertTrue( $result2 );
	}
}
