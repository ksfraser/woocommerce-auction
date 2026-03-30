<?php
/**
 * Tests for PluginActivationHooks class.
 *
 * @package ksfraser\Tests\Plugin
 * @covers \ksfraser\Plugin\Hooks\PluginActivationHooks
 */

namespace ksfraser\Tests\Plugin\Hooks;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ksfraser\Plugin\Hooks\PluginActivationHooks;
use ksfraser\Database\Schema\DatabaseSchemaInitializer;

/**
 * PluginActivationHooksTest class.
 *
 * @covers \ksfraser\Plugin\Hooks\PluginActivationHooks
 */
class PluginActivationHooksTest extends TestCase {

	/**
	 * Schema initializer mock.
	 *
	 * @var DatabaseSchemaInitializer|MockObject
	 */
	private $schema_initializer_mock;

	/**
	 * Plugin activation hooks instance.
	 *
	 * @var PluginActivationHooks
	 */
	private $hooks;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema_initializer_mock = $this->createMock( DatabaseSchemaInitializer::class );
		$this->hooks                   = new PluginActivationHooks( $this->schema_initializer_mock, false );
	}

	/**
	 * Test plugin activation hooks instantiation.
	 *
	 * @test
	 */
	public function test_instantiation() {
		$this->assertInstanceOf( PluginActivationHooks::class, $this->hooks );
	}

	/**
	 * Test registration of hooks.
	 *
	 * @test
	 */
	public function test_register_method_exists() {
		$this->assertTrue( method_exists( $this->hooks, 'register' ) );
	}

	/**
	 * Test on_activation method exists.
	 *
	 * @test
	 */
	public function test_on_activation_method_exists() {
		$this->assertTrue( method_exists( $this->hooks, 'on_activation' ) );
	}

	/**
	 * Test on_deactivation method exists.
	 *
	 * @test
	 */
	public function test_on_deactivation_method_exists() {
		$this->assertTrue( method_exists( $this->hooks, 'on_deactivation' ) );
	}

	/**
	 * Test get_activation_status method.
	 *
	 * @test
	 */
	public function test_get_activation_status() {
		$status = $this->hooks->get_activation_status();

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'initialized', $status );
		$this->assertArrayHasKey( 'last_init', $status );
		$this->assertArrayHasKey( 'status', $status );
		$this->assertArrayHasKey( 'message', $status );
		$this->assertArrayHasKey( 'stats', $status );
	}

	/**
	 * Test get_deactivation_status method.
	 *
	 * @test
	 */
	public function test_get_deactivation_status() {
		$status = $this->hooks->get_deactivation_status();

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'last_cleanup', $status );
		$this->assertArrayHasKey( 'status', $status );
		$this->assertArrayHasKey( 'message', $status );
	}

	/**
	 * Test activation status initialized flag is boolean.
	 *
	 * @test
	 */
	public function test_activation_status_initialized_is_boolean() {
		$status = $this->hooks->get_activation_status();

		$this->assertIsBool( $status['initialized'] );
	}

	/**
	 * Test deactivation hook with delete flag false.
	 *
	 * @test
	 */
	public function test_deactivation_with_delete_false() {
		$hooks = new PluginActivationHooks( $this->schema_initializer_mock, false );

		$this->schema_initializer_mock->expects( $this->once() )
			->method( 'cleanup' )
			->with( false )
			->willReturn( true );

		$hooks->on_deactivation();
	}

	/**
	 * Test deactivation hook with delete flag true.
	 *
	 * @test
	 */
	public function test_deactivation_with_delete_true() {
		$hooks = new PluginActivationHooks( $this->schema_initializer_mock, true );

		$this->schema_initializer_mock->expects( $this->once() )
			->method( 'cleanup' )
			->with( true )
			->willReturn( true );

		$hooks->on_deactivation();
	}

	/**
	 * Test display_activation_success_notice method exists.
	 *
	 * @test
	 */
	public function test_display_activation_success_notice_method_exists() {
		$this->assertTrue( method_exists( $this->hooks, 'display_activation_success_notice' ) );
	}

	/**
	 * Test display_activation_error_notice method exists.
	 *
	 * @test
	 */
	public function test_display_activation_error_notice_method_exists() {
		$this->assertTrue( method_exists( $this->hooks, 'display_activation_error_notice' ) );
	}

	/**
	 * Test hooks are based on DatabaseSchemaInitializer.
	 *
	 * @test
	 */
	public function test_uses_schema_initializer() {
		$hooks = new PluginActivationHooks( $this->schema_initializer_mock );

		$this->assertInstanceOf( PluginActivationHooks::class, $hooks );
	}

	/**
	 * Test activation hooks can be configured with delete flag.
	 *
	 * @test
	 */
	public function test_delete_on_deactivation_configuration() {
		$hooks_with_delete    = new PluginActivationHooks( $this->schema_initializer_mock, true );
		$hooks_without_delete = new PluginActivationHooks( $this->schema_initializer_mock, false );

		$this->assertInstanceOf( PluginActivationHooks::class, $hooks_with_delete );
		$this->assertInstanceOf( PluginActivationHooks::class, $hooks_without_delete );
	}
}
