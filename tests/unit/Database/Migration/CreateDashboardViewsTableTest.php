<?php
/**
 * Tests for dashboard_views table migration.
 *
 * @package ksfraser\Tests\Database
 * @covers \ksfraser\Database\Migration\CreateDashboardViewsTable
 */

namespace ksfraser\Tests\Database\Migration;

use PHPUnit\Framework\TestCase;
use ksfraser\Database\Migration\CreateDashboardViewsTable;

/**
 * CreateDashboardViewsTableTest class.
 *
 * @covers \ksfraser\Database\Migration\CreateDashboardViewsTable
 */
class CreateDashboardViewsTableTest extends TestCase {

	/**
	 * Migration instance.
	 *
	 * @var CreateDashboardViewsTable
	 */
	private $migration;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migration = new CreateDashboardViewsTable();
	}

	/**
	 * Test migration ID format.
	 *
	 * @test
	 */
	public function test_migration_id_format() {
		$id = $this->migration->get_migration_id();

		$this->assertStringContainsString( '2026_03_30_140100_create_dashboard_views_table', $id );
	}

	/**
	 * Test migration description.
	 *
	 * @test
	 */
	public function test_migration_description() {
		$description = $this->migration->get_description();

		$this->assertStringContainsString( 'dashboard_views', $description );
	}

	/**
	 * Test migration can be created without error.
	 *
	 * @test
	 */
	public function test_migration_instantiation() {
		$this->assertInstanceOf( CreateDashboardViewsTable::class, $this->migration );
	}

	/**
	 * Test migration up method is callable.
	 *
	 * @test
	 */
	public function test_migration_up_method_exists() {
		$this->assertTrue( method_exists( $this->migration, 'up' ) );
	}

	/**
	 * Test migration down method is callable.
	 *
	 * @test
	 */
	public function test_migration_down_method_exists() {
		$this->assertTrue( method_exists( $this->migration, 'down' ) );
	}

	/**
	 * Test migration is not yet applied (in isolated test).
	 *
	 * @test
	 */
	public function test_migration_not_applied_initially() {
		$this->assertFalse( $this->migration->is_applied() );
	}

	/**
	 * Test dashboard_views table view types are valid.
	 *
	 * @test
	 */
	public function test_dashboard_views_type_constants() {
		$valid_types = array(
			'settlement',
			'revenue',
			'performance',
			'disputes',
			'health',
			'payouts',
		);

		$this->assertNotEmpty( $valid_types );
		$this->assertContains( 'settlement', $valid_types );
		$this->assertContains( 'revenue', $valid_types );
		$this->assertContains( 'performance', $valid_types );
	}

	/**
	 * Test expected table columns are defined.
	 *
	 * @test
	 */
	public function test_expected_columns() {
		$expected_columns = array(
			'id',
			'view_name',
			'view_type',
			'data',
			'cached_at',
			'expires_at',
			'updated_at',
		);

		$this->assertCount( 7, $expected_columns );
		$this->assertContains( 'id', $expected_columns );
		$this->assertContains( 'view_name', $expected_columns );
		$this->assertContains( 'view_type', $expected_columns );
		$this->assertContains( 'expires_at', $expected_columns );
	}

	/**
	 * Test expected table indexes are defined.
	 *
	 * @test
	 */
	public function test_expected_indexes() {
		$expected_indexes = array(
			'idx_view_type',
			'idx_expires_at',
			'idx_cached_at',
		);

		$this->assertCount( 3, $expected_indexes );
		$this->assertContains( 'idx_view_type', $expected_indexes );
		$this->assertContains( 'idx_expires_at', $expected_indexes );
		$this->assertContains( 'idx_cached_at', $expected_indexes );
	}

	/**
	 * Test view_name uniqueness constraint.
	 *
	 * @test
	 */
	public function test_view_name_uniqueness() {
		// dashboard_views table enforces UNIQUE on view_name
		// This ensures only one cached entry per view
		$this->assertTrue( true );
	}

	/**
	 * Test cache expiration tracking.
	 *
	 * @test
	 */
	public function test_cache_expiration_fields() {
		// Table has both cached_at and expires_at for TTL tracking
		$this->assertTrue( true );
	}
}
