<?php
/**
 * SchedulerConfigRepository Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Repositories
 * @version    1.0.0
 * @requirement REQ-4D-040: Persist and query scheduler configuration
 */

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\SchedulerConfig;
use WC\Auction\Repositories\SchedulerConfigRepository;

/**
 * Test SchedulerConfigRepository DAO
 *
 * @covers \WC\Auction\Repositories\SchedulerConfigRepository
 */
class SchedulerConfigRepositoryTest extends TestCase {

	/**
	 * Repository instance
	 *
	 * @var SchedulerConfigRepository
	 */
	private $repository;

	/**
	 * Set up test fixtures
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->repository = new SchedulerConfigRepository();
		// Clean table before each test
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_auction_scheduler_config" );
	}

	/**
	 * Tear down after each test
	 */
	protected function tearDown(): void {
		// Clean table after each test
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_auction_scheduler_config" );
		parent::tearDown();
	}

	/**
	 * Test get returns config value
	 */
	public function testGetReturnsConfigValue() {
		$config = SchedulerConfig::create( 'polling_interval', 300 );
		$this->repository->save( $config );

		$value = $this->repository->get( 'polling_interval' );

		$this->assertEquals( '300', $value );
	}

	/**
	 * Test get returns null for missing option
	 */
	public function testGetReturnsNullForMissingOption() {
		$value = $this->repository->get( 'non_existent_option' );

		$this->assertNull( $value );
	}

	/**
	 * Test set saves or updates config
	 */
	public function testSetSavesOrUpdatesConfig() {
		$this->repository->set( 'batch_size', '50' );

		$value = $this->repository->get( 'batch_size' );

		$this->assertEquals( '50', $value );
	}

	/**
	 * Test set updates existing option
	 */
	public function testSetUpdatesExistingOption() {
		$this->repository->set( 'max_retries', '6' );
		$this->repository->set( 'max_retries', '10' );

		$value = $this->repository->get( 'max_retries' );

		$this->assertEquals( '10', $value );
	}

	/**
	 * Test get all returns all configs
	 */
	public function testGetAllReturnsAllConfigs() {
		$this->repository->set( 'option1', 'value1' );
		$this->repository->set( 'option2', 'value2' );
		$this->repository->set( 'option3', 'value3' );

		$all = $this->repository->getAll();

		$this->assertCount( 3, $all );
		$this->assertArrayHasKey( 'option1', $all );
		$this->assertArrayHasKey( 'option2', $all );
		$this->assertArrayHasKey( 'option3', $all );
		$this->assertEquals( 'value1', $all['option1'] );
		$this->assertEquals( 'value2', $all['option2'] );
		$this->assertEquals( 'value3', $all['option3'] );
	}

	/**
	 * Test get all returns empty array when no configs
	 */
	public function testGetAllReturnsEmptyArrayWhenNoConfigs() {
		$all = $this->repository->getAll();

		$this->assertIsArray( $all );
		$this->assertEmpty( $all );
	}

	/**
	 * Test delete removes config
	 */
	public function testDeleteRemovesConfig() {
		$this->repository->set( 'test_option', 'test_value' );

		$deleted = $this->repository->delete( 'test_option' );

		$this->assertTrue( $deleted );
		$this->assertNull( $this->repository->get( 'test_option' ) );
	}

	/**
	 * Test delete returns false when option not found
	 */
	public function testDeleteReturnsFalseWhenOptionNotFound() {
		$deleted = $this->repository->delete( 'non_existent' );

		$this->assertFalse( $deleted );
	}
}
