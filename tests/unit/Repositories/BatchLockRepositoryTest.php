<?php
/**
 * BatchLockRepository Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Repositories
 * @version    1.0.0
 * @requirement REQ-4D-038: Persist and query batch locks
 */

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\BatchLock;
use WC\Auction\Repositories\BatchLockRepository;

/**
 * Test BatchLockRepository DAO
 *
 * @covers \WC\Auction\Repositories\BatchLockRepository
 */
class BatchLockRepositoryTest extends TestCase {

	/**
	 * Repository instance
	 *
	 * @var BatchLockRepository
	 */
	private $repository;

	/**
	 * Set up test fixtures
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->repository = new BatchLockRepository();
		// Clean table before each test
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_auction_batch_locks" );
	}

	/**
	 * Tear down after each test
	 */
	protected function tearDown(): void {
		// Clean table after each test
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wc_auction_batch_locks" );
		parent::tearDown();
	}

	/**
	 * Test acquire lock creates new lock
	 */
	public function testAcquireLockCreatesNewLock() {
		$acquired = $this->repository->acquireLock( 'payout-batch', 300 );

		$this->assertNotNull( $acquired );
		$this->assertInstanceOf( BatchLock::class, $acquired );
		$this->assertEquals( 'payout-batch', $acquired->getBatchId() );
		$this->assertEquals( 300, $acquired->getTimeoutSeconds() );
	}

	/**
	 * Test is locked returns true when lock exists
	 */
	public function testIsLockedReturnsTrueWhenLockExists() {
		$lock = $this->repository->acquireLock( 'payout-batch', 600 );

		$is_locked = $this->repository->isLocked( 'payout-batch' );

		$this->assertTrue( $is_locked );
	}

	/**
	 * Test is locked returns false when no lock
	 */
	public function testIsLockedReturnsFalseWhenNoLock() {
		$is_locked = $this->repository->isLocked( 'payout-batch' );

		$this->assertFalse( $is_locked );
	}

	/**
	 * Test is locked returns false when lock expired
	 */
	public function testIsLockedReturnsFalseWhenLockExpired() {
		// Create lock with 5 second timeout 10 seconds ago
		$lock = BatchLock::create( 'payout-batch', 5 );
		$old_time = new \DateTime( '-10 seconds', new \DateTimeZone( 'UTC' ) );
		$lock->setLockedAt( $old_time );

		$this->repository->save( $lock );

		$is_locked = $this->repository->isLocked( 'payout-batch' );

		$this->assertFalse( $is_locked );
	}

	/**
	 * Test release lock removes lock from database
	 */
	public function testReleaseLockRemovesLock() {
		$lock = $this->repository->acquireLock( 'payout-batch', 300 );

		$released = $this->repository->releaseLock( 'payout-batch' );

		$this->assertTrue( $released );
		$this->assertFalse( $this->repository->isLocked( 'payout-batch' ) );
	}

	/**
	 * Test cleanup stale locks removes expired locks
	 */
	public function testCleanupStaleLocks() {
		// Create expired lock
		$lock1 = BatchLock::create( 'batch-1', 5 );
		$old_time = new \DateTime( '-10 seconds', new \DateTimeZone( 'UTC' ) );
		$lock1->setLockedAt( $old_time );
		$this->repository->save( $lock1 );

		// Create valid lock
		$lock2 = $this->repository->acquireLock( 'batch-2', 600 );

		$cleaned = $this->repository->cleanupStaleLocks();

		$this->assertEquals( 1, $cleaned );
		$this->assertFalse( $this->repository->isLocked( 'batch-1' ) );
		$this->assertTrue( $this->repository->isLocked( 'batch-2' ) );
	}

	/**
	 * Test refresh extends lock timeout
	 */
	public function testRefreshExtendsLockTimeout() {
		$lock = $this->repository->acquireLock( 'payout-batch', 300 );

		$refreshed = $this->repository->refresh( 'payout-batch' );

		$this->assertNotNull( $refreshed );
		$this->assertTrue( $refreshed->isLocked() );
		$this->assertEquals( 'payout-batch', $refreshed->getBatchId() );
	}
}
