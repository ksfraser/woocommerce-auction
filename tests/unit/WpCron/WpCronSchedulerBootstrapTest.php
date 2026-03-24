<?php
/**
 * WP-Cron Scheduler Bootstrap Unit Tests
 *
 * Tests scheduler initialization and WordPress integration
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    1.0.0
 * @requirement REQ-4D-041: WP-Cron bootstrap and WordPress integration
 */

namespace Tests\Unit\WpCron;

use PHPUnit\Framework\TestCase;
use WC\Auction\WpCron\WpCronSchedulerBootstrap;
use WC\Auction\Services\SchedulerService;
use WC\Auction\Services\EventPublisher;

/**
 * @covers \WC\Auction\WpCron\WpCronSchedulerBootstrap
 */
class WpCronSchedulerBootstrapTest extends TestCase {

	private $bootstrap;
	private $scheduler;

	protected function setUp(): void {
		$retry_repo      = $this->getMockBuilder( \WC\Auction\Repositories\RetryScheduleRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$batch_lock_repo = $this->getMockBuilder( \WC\Auction\Repositories\BatchLockRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$config_repo     = $this->getMockBuilder( \WC\Auction\Repositories\SchedulerConfigRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$event_publisher = new EventPublisher();

		$this->scheduler = new SchedulerService(
			$retry_repo,
			$batch_lock_repo,
			$config_repo,
			$event_publisher
		);

		$this->bootstrap = new WpCronSchedulerBootstrap( $this->scheduler );
	}

	/**
	 * Test bootstrap construction
	 */
	public function testBootstrapConstruction() {
		$this->assertInstanceOf( WpCronSchedulerBootstrap::class, $this->bootstrap );
	}

	/**
	 * Test initialize bootstrap
	 */
	public function testInitializeBootstrap() {
		$result = $this->bootstrap->initialize();

		$this->assertTrue( $result );
	}

	/**
	 * Test shutdown bootstrap
	 */
	public function testShutdownBootstrap() {
		$this->bootstrap->initialize();

		$result = $this->bootstrap->shutdown();

		$this->assertTrue( $result );
	}

	/**
	 * Test get status returns initialization status
	 */
	public function testGetStatusReturnsArray() {
		$this->bootstrap->initialize();

		$status = $this->bootstrap->getStatus();

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'initialized', $status );
		$this->assertArrayHasKey( 'cron_registered', $status );
		$this->assertArrayHasKey( 'last_init_time', $status );
	}

	/**
	 * Test is initialized returns bool
	 */
	public function testIsInitializedReturnsBool() {
		$is_initialized = $this->bootstrap->isInitialized();

		$this->assertIsBool( $is_initialized );
	}

	/**
	 * Test register cron hook
	 */
	public function testRegisterCronHook() {
		$result = $this->bootstrap->registerCronHook();

		$this->assertTrue( $result );
	}

	/**
	 * Test unregister cron hook
	 */
	public function testUnregisterCronHook() {
		$this->bootstrap->registerCronHook();

		$result = $this->bootstrap->unregisterCronHook();

		$this->assertTrue( $result );
	}

	/**
	 * Test get cron event name
	 */
	public function testGetCronEventName() {
		$name = $this->bootstrap->getCronEventName();

		$this->assertIsString( $name );
		$this->assertNotEmpty( $name );
	}

	/**
	 * Test get cron recurrence
	 */
	public function testGetCronRecurrence() {
		$recurrence = $this->bootstrap->getCronRecurrence();

		$this->assertIsString( $recurrence );
		$this->assertNotEmpty( $recurrence );
	}

	/**
	 * Test is cron registered returns bool
	 */
	public function testIsCronRegisteredReturnsBool() {
		$is_registered = $this->bootstrap->isCronRegistered();

		$this->assertIsBool( $is_registered );
	}

	/**
	 * Test get next scheduled time
	 */
	public function testGetNextScheduledTime() {
		$this->bootstrap->registerCronHook();

		$next_time = $this->bootstrap->getNextScheduledTime();

		// Can be false if not scheduled, or int if scheduled
		$this->assertTrue( is_int( $next_time ) || false === $next_time );
	}
}
