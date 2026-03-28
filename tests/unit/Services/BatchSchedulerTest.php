<?php
/**
 * BatchSchedulerTest - Unit tests for BatchScheduler service
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    4.0.0
 * @requirement REQ-4D-046: Test batch scheduling orchestration logic
 */

namespace WC\Auction\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WC\Auction\Services\BatchScheduler;
use WC\Auction\Services\BatchSchedulerConfiguration;
use WC\Auction\Services\PayoutService;
use WC\Auction\Models\BatchProcessingResult;
use WC\Auction\Models\SettlementBatch;
use WC\Auction\Repositories\BatchLockRepository;
use WC\Auction\Repositories\SettlementBatchRepository;
use WC\Auction\Events\EventPublisher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test suite for BatchScheduler service
 *
 * @requirement REQ-4D-046: Test core batch scheduling functionality
 */
class BatchSchedulerTest extends TestCase {

    /**
     * PayoutService mock
     *
     * @var PayoutService|MockObject
     */
    private $payout_service_mock;

    /**
     * BatchLockRepository mock
     *
     * @var BatchLockRepository|MockObject
     */
    private $lock_repo_mock;

    /**
     * SettlementBatchRepository mock
     *
     * @var SettlementBatchRepository|MockObject
     */
    private $batch_repo_mock;

    /**
     * SchedulerService mock
     *
     * @var \WC\Auction\Services\SchedulerService|MockObject
     */
    private $scheduler_mock;

    /**
     * EventPublisher mock
     *
     * @var EventPublisher|MockObject
     */
    private $event_publisher_mock;

    /**
     * BatchSchedulerConfiguration mock
     *
     * @var BatchSchedulerConfiguration|MockObject
     */
    private $config_mock;

    /**
     * BatchScheduler instance
     *
     * @var BatchScheduler
     */
    private $batch_scheduler;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();

        $this->payout_service_mock    = $this->createMock( PayoutService::class );
        $this->lock_repo_mock         = $this->createMock( BatchLockRepository::class );
        $this->batch_repo_mock        = $this->createMock( SettlementBatchRepository::class );
        $this->scheduler_mock         = $this->createMock( \WC\Auction\Services\SchedulerService::class );
        $this->event_publisher_mock   = $this->createMock( EventPublisher::class );
        $this->config_mock            = $this->createMock( BatchSchedulerConfiguration::class );

        $this->batch_scheduler = new BatchScheduler(
            $this->payout_service_mock,
            $this->lock_repo_mock,
            $this->batch_repo_mock,
            $this->scheduler_mock,
            $this->event_publisher_mock,
            $this->config_mock
        );
    }

    /**
     * Test service can be instantiated
     *
     * @test
     */
    public function test_service_can_be_instantiated(): void {
        $this->assertInstanceOf( BatchScheduler::class, $this->batch_scheduler );
    }

    /**
     * Test daily schedule registration
     *
     * @test
     */
    public function test_schedule_daily_registers_cron_hook(): void {
        $this->scheduler_mock->expects( $this->once() )
            ->method( 'scheduleRecurring' )
            ->with(
                $this->stringContains( 'yith_wc_auction_batch_process_daily' ),
                $this->greaterThanOrEqual( 0 ),
                $this->equalTo( 'daily' ),
                $this->isType( 'array' )
            )
            ->willReturn( true );

        $result = $this->batch_scheduler->scheduleDaily( '02:00' );

        $this->assertTrue( $result );
    }

    /**
     * Test weekly schedule registration
     *
     * @test
     */
    public function test_schedule_weekly_registers_cron_hook(): void {
        $this->scheduler_mock->expects( $this->once() )
            ->method( 'scheduleRecurring' )
            ->with(
                $this->stringContains( 'yith_wc_auction_batch_process_weekly' ),
                $this->isType( 'int' ),
                $this->equalTo( 'weekly' ),
                $this->isType( 'array' )
            )
            ->willReturn( true );

        $result = $this->batch_scheduler->scheduleWeekly( 0, '14:30' );

        $this->assertTrue( $result );
    }

    /**
     * Test process scheduled batch with successful processing
     *
     * @test
     */
    public function test_process_scheduled_batch_success(): void {
        $batch_id = 123;
        $batch    = $this->createMockBatch( $batch_id );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'acquireLock' )
            ->with( $batch_id, $this->isType( 'int' ) )
            ->willReturn( true );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $batch_id )
            ->willReturn( $batch );

        $this->payout_service_mock->expects( $this->once() )
            ->method( 'processPayoutBatch' )
            ->with( $batch )
            ->willReturn( [ 'processed' => 5, 'failed' => 0 ] );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'update' )
            ->with( $batch );

        $this->event_publisher_mock->expects( $this->once() )
            ->method( 'publish' )
            ->with( 'batch.processing.completed', $this->isType( 'array' ) );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'releaseLock' )
            ->with( $batch_id );

        $result = $this->batch_scheduler->processScheduledBatch( $batch_id );

        $this->assertInstanceOf( BatchProcessingResult::class, $result );
        $this->assertTrue( $result->isSuccess() );
    }

    /**
     * Test process scheduled batch when already locked
     *
     * @test
     */
    public function test_process_scheduled_batch_skipped_when_locked(): void {
        $batch_id = 123;

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'acquireLock' )
            ->with( $batch_id, $this->isType( 'int' ) )
            ->willReturn( false );

        $this->batch_repo_mock->expects( $this->never() )
            ->method( 'find' );

        $result = $this->batch_scheduler->processScheduledBatch( $batch_id );

        $this->assertInstanceOf( BatchProcessingResult::class, $result );
        $this->assertTrue( $result->isSkipped() );
    }

    /**
     * Test process scheduled batch with batch not found
     *
     * @test
     */
    public function test_process_scheduled_batch_fails_when_batch_not_found(): void {
        $batch_id = 123;

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'acquireLock' )
            ->with( $batch_id, $this->isType( 'int' ) )
            ->willReturn( true );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $batch_id )
            ->willReturn( null );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'releaseLock' )
            ->with( $batch_id );

        $result = $this->batch_scheduler->processScheduledBatch( $batch_id );

        $this->assertInstanceOf( BatchProcessingResult::class, $result );
        $this->assertTrue( $result->isFailed() );
    }

    /**
     * Test process scheduled batch with partial failures
     *
     * @test
     */
    public function test_process_scheduled_batch_partial_failure(): void {
        $batch_id = 123;
        $batch    = $this->createMockBatch( $batch_id );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'acquireLock' )
            ->with( $batch_id, $this->isType( 'int' ) )
            ->willReturn( true );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $batch_id )
            ->willReturn( $batch );

        // Some payouts succeeded, some failed
        $this->payout_service_mock->expects( $this->once() )
            ->method( 'processPayoutBatch' )
            ->with( $batch )
            ->willReturn( [ 'processed' => 4, 'failed' => 1 ] );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'update' )
            ->with( $batch );

        $this->event_publisher_mock->expects( $this->once() )
            ->method( 'publish' )
            ->with( 'batch.processing.completed', $this->isType( 'array' ) );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'releaseLock' )
            ->with( $batch_id );

        $result = $this->batch_scheduler->processScheduledBatch( $batch_id );

        $this->assertInstanceOf( BatchProcessingResult::class, $result );
        $this->assertTrue( $result->isPartial() );
        $this->assertEquals( 1, $result->getFailed() );
    }

    /**
     * Test process scheduled batch with exception handling
     *
     * @test
     */
    public function test_process_scheduled_batch_exception_handling(): void {
        $batch_id = 123;
        $batch    = $this->createMockBatch( $batch_id );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'acquireLock' )
            ->with( $batch_id, $this->isType( 'int' ) )
            ->willReturn( true );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $batch_id )
            ->willReturn( $batch );

        // PayoutService throws exception
        $this->payout_service_mock->expects( $this->once() )
            ->method( 'processPayoutBatch' )
            ->with( $batch )
            ->willThrowException( new \Exception( 'Payment adapter error' ) );

        $this->event_publisher_mock->expects( $this->never() )
            ->method( 'publish' );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'releaseLock' )
            ->with( $batch_id );

        $result = $this->batch_scheduler->processScheduledBatch( $batch_id );

        $this->assertInstanceOf( BatchProcessingResult::class, $result );
        $this->assertTrue( $result->isFailed() );
        $this->assertNotEmpty( $result->getErrorMessage() );
    }

    /**
     * Test lock is released in finally block
     *
     * @test
     */
    public function test_lock_released_even_on_exception(): void {
        $batch_id = 123;
        $batch    = $this->createMockBatch( $batch_id );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'acquireLock' )
            ->willReturn( true );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( $batch );

        $this->payout_service_mock->expects( $this->once() )
            ->method( 'processPayoutBatch' )
            ->willThrowException( new \Exception( 'Test error' ) );

        // Verify lock is ALWAYS released even on exception
        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'releaseLock' )
            ->with( $batch_id );

        $result = $this->batch_scheduler->processScheduledBatch( $batch_id );
        $this->assertTrue( $result->isFailed() );
    }

    /**
     * Test process now delegates to process scheduled batch
     *
     * @test
     */
    public function test_process_now_triggers_processing(): void {
        $batch_id = 123;
        $batch    = $this->createMockBatch( $batch_id );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'acquireLock' )
            ->willReturn( true );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( $batch );

        $this->payout_service_mock->expects( $this->once() )
            ->method( 'processPayoutBatch' )
            ->willReturn( [ 'processed' => 3, 'failed' => 0 ] );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'update' );

        $this->event_publisher_mock->expects( $this->once() )
            ->method( 'publish' );

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'releaseLock' );

        $result = $this->batch_scheduler->processNow( $batch_id );

        $this->assertInstanceOf( BatchProcessingResult::class, $result );
    }

    /**
     * Test is batch locked check
     *
     * @test
     */
    public function test_is_batch_locked(): void {
        $batch_id = 123;

        $this->lock_repo_mock->expects( $this->once() )
            ->method( 'isLocked' )
            ->with( $batch_id )
            ->willReturn( true );

        $is_locked = $this->batch_scheduler->isBatchLocked( $batch_id );

        $this->assertTrue( $is_locked );
    }

    /**
     * Test get configuration returns config object
     *
     * @test
     */
    public function test_get_configuration(): void {
        $config = $this->batch_scheduler->getConfiguration();

        $this->assertSame( $this->config_mock, $config );
    }

    /**
     * Test result includes processing duration
     *
     * @test
     */
    public function test_result_includes_processing_duration(): void {
        $batch_id = 123;
        $batch    = $this->createMockBatch( $batch_id );

        $this->lock_repo_mock->method( 'acquireLock' )->willReturn( true );
        $this->batch_repo_mock->method( 'find' )->willReturn( $batch );
        $this->payout_service_mock->method( 'processPayoutBatch' )->willReturn( [ 'processed' => 1, 'failed' => 0 ] );
        $this->batch_repo_mock->method( 'update' );
        $this->event_publisher_mock->method( 'publish' );
        $this->lock_repo_mock->method( 'releaseLock' );

        $result = $this->batch_scheduler->processScheduledBatch( $batch_id );

        $this->assertGreaterThanOrEqual( 0, $result->getDurationSeconds() );
    }

    /**
     * Test batch status updated to PROCESSED
     *
     * @test
     */
    public function test_batch_status_updated_to_processed(): void {
        $batch_id = 123;
        $batch    = $this->createMockBatch( $batch_id );

        $this->lock_repo_mock->method( 'acquireLock' )->willReturn( true );
        $this->batch_repo_mock->method( 'find' )->willReturn( $batch );
        $this->payout_service_mock->method( 'processPayoutBatch' )->willReturn( [ 'processed' => 1, 'failed' => 0 ] );

        $this->batch_repo_mock->expects( $this->once() )
            ->method( 'update' )
            ->with( $this->callback( function ( SettlementBatch $b ) {
                return SettlementBatch::STATUS_PROCESSED === $b->getStatus();
            } ) );

        $this->event_publisher_mock->method( 'publish' );
        $this->lock_repo_mock->method( 'releaseLock' );

        $result = $this->batch_scheduler->processScheduledBatch( $batch_id );

        $this->assertTrue( $result->isSuccess() );
    }

    /**
     * Test daily schedule time parsing (HH:MM format)
     *
     * @test
     */
    public function test_daily_schedule_time_parsing(): void {
        $this->config_mock->expects( $this->once() )
            ->method( 'getDailyScheduleTime' )
            ->willReturn( '14:30' );

        $this->scheduler_mock->expects( $this->once() )
            ->method( 'scheduleRecurring' );

        $this->batch_scheduler->scheduleDaily( '14:30' );

        $this->assertTrue( true ); // Verify no parsing errors
    }

    /**
     * Create mock batch for testing
     *
     * @param int $batch_id Batch ID
     * @return SettlementBatch|MockObject
     */
    private function createMockBatch( int $batch_id ): \PHPUnit\Framework\MockObject\MockObject {
        $batch = $this->createMock( SettlementBatch::class );
        $batch->method( 'getId' )->willReturn( $batch_id );
        $batch->method( 'getStatus' )->willReturn( SettlementBatch::STATUS_PENDING );
        $batch->method( 'setStatus' )->willReturnSelf();
        $batch->method( 'setProcessedAt' )->willReturnSelf();

        return $batch;
    }
}
