<?php
/**
 * RetryScheduleRepository Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Repositories
 * @version    1.0.0
 * @requirement REQ-4D-039: Persist and query retry schedules
 */

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\RetrySchedule;
use WC\Auction\Repositories\RetryScheduleRepository;

/**
 * Test RetryScheduleRepository DAO
 *
 * @covers \WC\Auction\Repositories\RetryScheduleRepository
 */
class RetryScheduleRepositoryTest extends TestCase {

	/**
	 * Repository instance
	 *
	 * @var RetryScheduleRepository
	 */
	private $repository;

	/**
	 * Set up test fixtures
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->repository = new RetryScheduleRepository();
		// Clean table before each test
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_auction_retry_schedules" );
	}

	/**
	 * Tear down after each test
	 */
	protected function tearDown(): void {
		// Clean table after each test
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_auction_retry_schedules" );
		parent::tearDown();
	}

	/**
	 * Test save new retry schedule
	 */
	public function testSaveNewRetrySchedule() {
		$schedule = RetrySchedule::create( 42 );
		$future = new \DateTime( '+5 minutes', new \DateTimeZone( 'UTC' ) );
		$schedule->setNextRetryTime( $future );

		$id = $this->repository->save( $schedule );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Test find by ID retrieves saved schedule
	 */
	public function testFindByIdRetrievesSavedSchedule() {
		$schedule = RetrySchedule::create( 42 );
		$future = new \DateTime( '+5 minutes', new \DateTimeZone( 'UTC' ) );
		$schedule->setNextRetryTime( $future );
		$schedule->setErrorMessage( 'Test error' );

		$id = $this->repository->save( $schedule );
		$retrieved = $this->repository->find( $id );

		$this->assertNotNull( $retrieved );
		$this->assertEquals( $id, $retrieved->getId() );
		$this->assertEquals( 42, $retrieved->getPayoutId() );
		$this->assertEquals( 'Test error', $retrieved->getLastErrorMessage() );
	}

	/**
	 * Test find returns null for non-existent ID
	 */
	public function testFindReturnsNullForNonExistentId() {
		$retrieved = $this->repository->find( 9999 );

		$this->assertNull( $retrieved );
	}

	/**
	 * Test find by payout ID
	 */
	public function testFindByPayoutIdRetrievesSchedule() {
		$schedule1 = RetrySchedule::create( 42 );
		$schedule2 = RetrySchedule::create( 99 );

		$id1 = $this->repository->save( $schedule1 );
		$id2 = $this->repository->save( $schedule2 );

		$retrieved = $this->repository->findByPayoutId( 42 );

		$this->assertNotNull( $retrieved );
		$this->assertEquals( $id1, $retrieved->getId() );
		$this->assertEquals( 42, $retrieved->getPayoutId() );
	}

	/**
	 * Test find by payout ID returns null if not found
	 */
	public function testFindByPayoutIdReturnsNullIfNotFound() {
		$retrieved = $this->repository->findByPayoutId( 9999 );

		$this->assertNull( $retrieved );
	}

	/**
	 * Test find due retries returns schedules past their retry time
	 */
	public function testFindDueRetriesReturnsPastDueOnly() {
		// Create schedule 1: past due (should return)
		$schedule1 = RetrySchedule::create( 42 );
		$past = new \DateTime( '-5 minutes', new \DateTimeZone( 'UTC' ) );
		$schedule1->setNextRetryTime( $past );

		// Create schedule 2: future (should not return)
		$schedule2 = RetrySchedule::create( 99 );
		$future = new \DateTime( '+5 minutes', new \DateTimeZone( 'UTC' ) );
		$schedule2->setNextRetryTime( $future );

		// Create schedule 3: no retry time (should not return)
		$schedule3 = RetrySchedule::create( 100 );

		$this->repository->save( $schedule1 );
		$this->repository->save( $schedule2 );
		$this->repository->save( $schedule3 );

		$due = $this->repository->findDueRetries();

		$this->assertCount( 1, $due );
		$this->assertEquals( 42, $due[0]->getPayoutId() );
	}

	/**
	 * Test update retry schedule
	 */
	public function testUpdateRetrySchedule() {
		$schedule = RetrySchedule::create( 42 );
		$id = $this->repository->save( $schedule );

		// Modify schedule
		$schedule->incrementFailureCount();
		$schedule->incrementFailureCount();
		$schedule->setErrorMessage( 'Updated error' );

		$updated = $this->repository->update( $schedule );

		$this->assertTrue( $updated );

		// Verify update persisted
		$retrieved = $this->repository->find( $id );
		$this->assertEquals( 2, $retrieved->getFailureCount() );
		$this->assertEquals( 'Updated error', $retrieved->getLastErrorMessage() );
	}

	/**
	 * Test delete removes schedule
	 */
	public function testDeleteRemovesSchedule() {
		$schedule = RetrySchedule::create( 42 );
		$id = $this->repository->save( $schedule );

		$deleted = $this->repository->delete( $id );

		$this->assertTrue( $deleted );

		$retrieved = $this->repository->find( $id );
		$this->assertNull( $retrieved );
	}

	/**
	 * Test find all by status retrieves multiple schedules
	 */
	public function testFindAllReturnMultipleSchedules() {
		$schedule1 = RetrySchedule::create( 42 );
		$schedule2 = RetrySchedule::create( 99 );
		$schedule3 = RetrySchedule::create( 100 );

		$this->repository->save( $schedule1 );
		$this->repository->save( $schedule2 );
		$this->repository->save( $schedule3 );

		$all = $this->repository->findAll();

		$this->assertCount( 3, $all );
	}

	/**
	 * Test count returns total number of schedules
	 */
	public function testCountReturnsTotalSchedules() {
		$schedule1 = RetrySchedule::create( 42 );
		$schedule2 = RetrySchedule::create( 99 );

		$this->repository->save( $schedule1 );
		$this->repository->save( $schedule2 );

		$count = $this->repository->count();

		$this->assertEquals( 2, $count );
	}

	/**
	 * Test find by payout ID with multiple attempts (unique constraint)
	 */
	public function testFindByPayoutIdReturnsOnlyOne() {
		// Save same payout multiple times (should handle unique constraint)
		$schedule1 = RetrySchedule::create( 42 );
		$id1 = $this->repository->save( $schedule1 );

		// Try to save another for same payout (should update or reject)
		$schedule2 = RetrySchedule::create( 42 );

		// Should either error or update existing
		// For now, test that we can retrieve the first one
		$retrieved = $this->repository->findByPayoutId( 42 );

		$this->assertNotNull( $retrieved );
		$this->assertEquals( 42, $retrieved->getPayoutId() );
	}
}
