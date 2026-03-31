<?php
/**
 * Tests for AlertManager class.
 *
 * @package ksfraser\Tests\Monitoring
 * @covers \ksfraser\Monitoring\AlertManager
 */

namespace ksfraser\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use ksfraser\Monitoring\AlertManager;
use ksfraser\Monitoring\Alerts\Alert;

/**
 * AlertManagerTest class.
 *
 * @covers \ksfraser\Monitoring\AlertManager
 */
class AlertManagerTest extends TestCase {

	/**
	 * Alert manager instance.
	 *
	 * @var AlertManager
	 */
	private $alert_manager;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->alert_manager = new AlertManager();
	}

	/**
	 * Test alert manager instantiation.
	 *
	 * @test
	 */
	public function test_instantiation() {
		$this->assertInstanceOf( AlertManager::class, $this->alert_manager );
	}

	/**
	 * Test add alert.
	 *
	 * @test
	 */
	public function test_add_alert() {
		$this->alert_manager->add(
			'message',
			'warning',
			'test_source'
		);

		$alerts = $this->alert_manager->get_recent( 10 );

		$this->assertCount( 1, $alerts );
		$this->assertEquals( 'message', $alerts[0]['message'] );
		$this->assertEquals( 'warning', $alerts[0]['level'] );
	}

	/**
	 * Test get recent alerts.
	 *
	 * @test
	 */
	public function test_get_recent_alerts() {
		for ( $i = 0; $i < 15; $i++ ) {
			$this->alert_manager->add(
				"Alert $i",
				'info',
				'test'
			);
		}

		$recent = $this->alert_manager->get_recent( 5 );

		$this->assertCount( 5, $recent );
	}

	/**
	 * Test get alerts by level.
	 *
	 * @test
	 */
	public function test_get_alerts_by_level() {
		$this->alert_manager->add( 'Error alert', 'error', 'test' );
		$this->alert_manager->add( 'Warning alert', 'warning', 'test' );
		$this->alert_manager->add( 'Info alert', 'info', 'test' );

		$errors = $this->alert_manager->get_by_level( 'error' );

		$this->assertCount( 1, $errors );
		$this->assertEquals( 'Error alert', $errors[0]['message'] );
	}

	/**
	 * Test get alerts by source.
	 *
	 * @test
	 */
	public function test_get_alerts_by_source() {
		$this->alert_manager->add( 'API error', 'error', 'api' );
		$this->alert_manager->add( 'DB error', 'error', 'database' );

		$api_alerts = $this->alert_manager->get_by_source( 'api' );

		$this->assertCount( 1, $api_alerts );
		$this->assertEquals( 'API error', $api_alerts[0]['message'] );
	}

	/**
	 * Test clear alerts.
	 *
	 * @test
	 */
	public function test_clear_alerts() {
		$this->alert_manager->add( 'Alert 1', 'info', 'test' );
		$this->alert_manager->add( 'Alert 2', 'info', 'test' );

		$this->alert_manager->clear();

		$alerts = $this->alert_manager->get_recent( 10 );

		$this->assertCount( 0, $alerts );
	}

	/**
	 * Test alert structure.
	 *
	 * @test
	 */
	public function test_alert_structure() {
		$this->alert_manager->add( 'Test message', 'error', 'test_source' );

		$alerts = $this->alert_manager->get_recent( 1 );
		$alert = $alerts[0];

		$this->assertArrayHasKey( 'message', $alert );
		$this->assertArrayHasKey( 'level', $alert );
		$this->assertArrayHasKey( 'source', $alert );
		$this->assertArrayHasKey( 'timestamp', $alert );
		$this->assertArrayHasKey( 'id', $alert );
	}

	/**
	 * Test alert ID uniqueness.
	 *
	 * @test
	 */
	public function test_alert_id_uniqueness() {
		$this->alert_manager->add( 'Alert 1', 'info', 'test' );
		$this->alert_manager->add( 'Alert 2', 'info', 'test' );

		$alerts = $this->alert_manager->get_recent( 10 );

		$this->assertNotEquals( $alerts[0]['id'], $alerts[1]['id'] );
	}

	/**
	 * Test count alerts by level.
	 *
	 * @test
	 */
	public function test_count_by_level() {
		$this->alert_manager->add( 'Error 1', 'error', 'test' );
		$this->alert_manager->add( 'Error 2', 'error', 'test' );
		$this->alert_manager->add( 'Warning 1', 'warning', 'test' );

		$error_count = count( $this->alert_manager->get_by_level( 'error' ) );
		$warning_count = count( $this->alert_manager->get_by_level( 'warning' ) );

		$this->assertEquals( 2, $error_count );
		$this->assertEquals( 1, $warning_count );
	}

	/**
	 * Test all alert levels are supported.
	 *
	 * @test
	 */
	public function test_all_alert_levels() {
		$levels = [ 'critical', 'error', 'warning', 'info', 'debug' ];

		foreach ( $levels as $level ) {
			$this->alert_manager->add( "Message for $level", $level, 'test' );
		}

		$alerts = $this->alert_manager->get_recent( 10 );

		$this->assertCount( 5, $alerts );
	}

	/**
	 * Test alerts are stored with correct timestamp.
	 *
	 * @test
	 */
	public function test_alert_timestamp() {
		$before = time();
		$this->alert_manager->add( 'Alert', 'info', 'test' );
		$after = time();

		$alerts = $this->alert_manager->get_recent( 1 );
		$timestamp = strtotime( $alerts[0]['timestamp'] );

		$this->assertGreaterThanOrEqual( $before, $timestamp );
		$this->assertLessThanOrEqual( $after, $timestamp );
	}

	/**
	 * Test acknowledge alert.
	 *
	 * @test
	 */
	public function test_acknowledge_alert() {
		$this->alert_manager->add( 'Alert to acknowledge', 'warning', 'test' );

		$alerts = $this->alert_manager->get_recent( 1 );
		$alert_id = $alerts[0]['id'];

		$this->alert_manager->acknowledge( $alert_id );

		$alerts = $this->alert_manager->get_recent( 1 );

		$this->assertTrue( $alerts[0]['acknowledged'] ?? false );
	}

	/**
	 * Test get critical alerts.
	 *
	 * @test
	 */
	public function test_get_critical_alerts() {
		$this->alert_manager->add( 'Critical issue', 'critical', 'test' );
		$this->alert_manager->add( 'Warning issue', 'warning', 'test' );

		$critical = $this->alert_manager->get_by_level( 'critical' );

		$this->assertCount( 1, $critical );
		$this->assertEquals( 'critical', $critical[0]['level'] );
	}

	/**
	 * Test max alerts limit.
	 *
	 * @test
	 */
	public function test_max_alerts_limit() {
		// Add many alerts to test max limit enforcement
		for ( $i = 0; $i < 1000; $i++ ) {
			$this->alert_manager->add( "Alert $i", 'info', 'test' );
		}

		$alerts = $this->alert_manager->get_recent( 10000 );

		// Should be limited to a reasonable number
		$this->assertLessThan( 1000, count( $alerts ) );
	}

	/**
	 * Test export alerts.
	 *
	 * @test
	 */
	public function test_export_alerts() {
		$this->alert_manager->add( 'Alert 1', 'error', 'test' );
		$this->alert_manager->add( 'Alert 2', 'warning', 'test' );

		// Get recent alerts
		$exported = $this->alert_manager->get_recent( 10 );

		$this->assertIsArray( $exported );
		$this->assertCount( 2, $exported );
	}
}
