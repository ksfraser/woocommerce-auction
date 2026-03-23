<?php

namespace Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Mockery;
use Yith\Auctions\Repository\AuctionStateRepository;

/**
 * AuctionStateRepository Unit Tests
 *
 * Test suite for auction state persistence layer.
 *
 * @package Tests\Unit\Repository
 * @requirement REQ-SEALED-BID-STATE-MACHINE-001
 * @covers \Yith\Auctions\Repository\AuctionStateRepository
 * @group unit
 * @group repository
 * @group state-machine
 */
class AuctionStateRepositoryTest extends TestCase
{
    /**
     * @var AuctionStateRepository Repository under test
     */
    private AuctionStateRepository $repository;

    /**
     * @var \Mockery\Mock Mock WPDB
     */
    private \Mockery\Mock $wpdb;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $this->repository = new AuctionStateRepository($this->wpdb);
    }

    /**
     * Tear down test fixtures.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test getting current state successfully.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Query current state
     */
    public function test_get_current_state_success(): void
    {
        $auction_id = 123;
        $expected_state = 'ACTIVE_SEALED';

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->with('mocked_query')
            ->andReturn($expected_state);

        $state = $this->repository->getCurrentState($auction_id);

        $this->assertSame($expected_state, $state);
    }

    /**
     * Test getting current state with invalid ID.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Input validation
     */
    public function test_get_current_state_invalid_id(): void
    {
        $state = $this->repository->getCurrentState(-1);

        $this->assertNull($state);
    }

    /**
     * Test getting current state when not found.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Handle missing states
     */
    public function test_get_current_state_not_found(): void
    {
        $auction_id = 999;

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->with('mocked_query')
            ->andReturn(null);

        $state = $this->repository->getCurrentState($auction_id);

        $this->assertNull($state);
    }

    /**
     * Test updating state successfully.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Persist state transitions
     */
    public function test_update_state_success(): void
    {
        $auction_id = 123;
        $new_state = 'ACTIVE_SEALED';
        $from_state = 'UPCOMING';
        $user_id = 1;
        $metadata = ['reason' => 'Test transition'];

        $this->wpdb
            ->shouldReceive('insert')
            ->with(
                'wp_wc_auction_states',
                Mockery::on(function ($data) use ($new_state, $from_state, $auction_id) {
                    return $data['auction_state'] === $new_state &&
                           $data['transition_from'] === $from_state &&
                           $data['auction_id'] === $auction_id &&
                           isset($data['state_id']) &&
                           isset($data['transition_at']) &&
                           isset($data['created_at']);
                }),
                [
                    '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s',
                ]
            )
            ->once()
            ->andReturn(1);

        $result = $this->repository->updateState(
            $auction_id,
            $new_state,
            $from_state,
            $user_id,
            $metadata
        );

        $this->assertTrue($result);
    }

    /**
     * Test updating state with invalid auction ID.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Input validation
     */
    public function test_update_state_invalid_auction_id(): void
    {
        $result = $this->repository->updateState(-1, 'ACTIVE_SEALED', 'UPCOMING', 1);

        $this->assertFalse($result);
    }

    /**
     * Test updating state with empty state.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Input validation
     */
    public function test_update_state_empty_state(): void
    {
        $result = $this->repository->updateState(123, '', 'UPCOMING', 1);

        $this->assertFalse($result);
    }

    /**
     * Test updating state with database error.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Error handling
     */
    public function test_update_state_database_error(): void
    {
        $this->wpdb
            ->shouldReceive('insert')
            ->andReturn(false);

        $this->wpdb->last_error = 'Database error';

        $result = $this->repository->updateState(123, 'ACTIVE_SEALED', 'UPCOMING', 1);

        $this->assertFalse($result);
    }

    /**
     * Test updating state without user ID (system transition).
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: System transitions
     */
    public function test_update_state_system_initiated(): void
    {
        $this->wpdb
            ->shouldReceive('insert')
            ->with(
                'wp_wc_auction_states',
                Mockery::on(function ($data) {
                    return null === $data['initiated_by']; // No user for system transition
                }),
                Mockery::any()
            )
            ->once()
            ->andReturn(1);

        $result = $this->repository->updateState(123, 'ACTIVE_SEALED', 'UPCOMING', 0);

        $this->assertTrue($result);
    }

    /**
     * Test getting state history.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Retrieve state history
     */
    public function test_get_state_history_success(): void
    {
        $auction_id = 123;
        $expected_results = [
            [
                'state_id' => 'state-001',
                'state' => 'UPCOMING',
                'from_state' => null,
                'transitioned_at' => '2026-03-23 10:00:00',
                'initiated_by' => null,
                'metadata' => null,
            ],
            [
                'state_id' => 'state-002',
                'state' => 'ACTIVE_SEALED',
                'from_state' => 'UPCOMING',
                'transitioned_at' => '2026-03-23 11:00:00',
                'initiated_by' => '1',
                'metadata' => '{"reason":"Sealed bid mode enabled"}',
            ],
        ];

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_results')
            ->with('mocked_query', ARRAY_A)
            ->andReturn($expected_results);

        $history = $this->repository->getStateHistory($auction_id);

        $this->assertCount(2, $history);
        $this->assertSame('UPCOMING', $history[0]['state']);
        $this->assertSame('ACTIVE_SEALED', $history[1]['state']);
        $this->assertNull($history[0]['metadata']);
        $this->assertIsArray($history[1]['metadata']);
        $this->assertSame(1, $history[1]['initiated_by']);
    }

    /**
     * Test getting state history with invalid ID.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Input validation
     */
    public function test_get_state_history_invalid_id(): void
    {
        $history = $this->repository->getStateHistory(-1);

        $this->assertSame([], $history);
    }

    /**
     * Test getting state history with database error.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Error handling
     */
    public function test_get_state_history_database_error(): void
    {
        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_results')
            ->andReturn(null);

        $this->wpdb->last_error = 'Query error';

        $history = $this->repository->getStateHistory(123);

        $this->assertSame([], $history);
    }

    /**
     * Test getting auctions by state.
     *
     * @test
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Find auctions by state
     */
    public function test_get_auctions_by_state(): void
    {
        $state = 'ENDED_REVEAL';
        $expected_auction_ids = ['123', '456', '789'];

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_col')
            ->andReturn($expected_auction_ids);

        $results = $this->repository->getAuctionsByState($state);

        $this->assertCount(3, $results);
        $this->assertSame([123, 456, 789], $results);
        $this->assertContainsOnly('int', $results);
    }

    /**
     * Test getting auctions by state with limit and offset.
     *
     * @test
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Pagination support
     */
    public function test_get_auctions_by_state_with_pagination(): void
    {
        $state = 'ENDED_REVEAL';
        $limit = 10;
        $offset = 0;

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_col')
            ->andReturn(['123']);

        $results = $this->repository->getAuctionsByState($state, $limit, $offset);

        $this->assertCount(1, $results);
    }

    /**
     * Test getting state change count.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: State tracking metrics
     */
    public function test_get_state_change_count(): void
    {
        $auction_id = 123;
        $expected_count = 5;

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->andReturn($expected_count);

        $count = $this->repository->getStateChangeCount($auction_id);

        $this->assertSame($expected_count, $count);
    }

    /**
     * Test getting state change count with invalid ID.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Input validation
     */
    public function test_get_state_change_count_invalid_id(): void
    {
        $count = $this->repository->getStateChangeCount(-1);

        $this->assertSame(0, $count);
    }

    /**
     * Test getting time in current state.
     *
     * @test
     * @requirement REQ-SEALED-BID-WORKFLOW-001: State duration tracking
     */
    public function test_get_time_in_current_state(): void
    {
        $auction_id = 123;
        $expected_minutes = 45;

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->andReturn($expected_minutes);

        $minutes = $this->repository->getTimeInCurrentState($auction_id);

        $this->assertSame($expected_minutes, $minutes);
    }

    /**
     * Test getting time in current state with invalid ID.
     *
     * @test
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Input validation
     */
    public function test_get_time_in_current_state_invalid_id(): void
    {
        $minutes = $this->repository->getTimeInCurrentState(-1);

        $this->assertNull($minutes);
    }

    /**
     * Test getting time in current state when not found.
     *
     * @test
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Handle missing states
     */
    public function test_get_time_in_current_state_not_found(): void
    {
        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->andReturn(null);

        $minutes = $this->repository->getTimeInCurrentState(123);

        $this->assertNull($minutes);
    }

    /**
     * Test getting latest transition metadata.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Transition context
     */
    public function test_get_latest_transition_metadata(): void
    {
        $auction_id = 123;
        $metadata_json = '{"reason":"Bid revealed","count":5}';

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->andReturn($metadata_json);

        $metadata = $this->repository->getLatestTransitionMetadata($auction_id);

        $this->assertIsArray($metadata);
        $this->assertSame('Bid revealed', $metadata['reason']);
        $this->assertSame(5, $metadata['count']);
    }

    /**
     * Test getting latest transition metadata with no metadata.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Handle missing context
     */
    public function test_get_latest_transition_metadata_empty(): void
    {
        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->andReturn(null);

        $metadata = $this->repository->getLatestTransitionMetadata(123);

        $this->assertNull($metadata);
    }

    /**
     * Test getting latest transition metadata with invalid JSON.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Error handling
     */
    public function test_get_latest_transition_metadata_invalid_json(): void
    {
        $invalid_json = 'not valid json';

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->andReturn($invalid_json);

        $metadata = $this->repository->getLatestTransitionMetadata(123);

        $this->assertNull($metadata);
    }

    /**
     * Test initializing auction state.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Initial state setup
     */
    public function test_initialize_state_success(): void
    {
        $auction_id = 123;

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->once()
            ->andReturn(null);

        $this->wpdb
            ->shouldReceive('insert')
            ->andReturn(1);

        $result = $this->repository->initializeState($auction_id);

        $this->assertTrue($result);
    }

    /**
     * Test initializing state when already initialized.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Idempotent initialization
     */
    public function test_initialize_state_already_initialized(): void
    {
        $auction_id = 123;

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_var')
            ->once()
            ->andReturn('UPCOMING');

        // Should not call insert for already initialized state
        $this->wpdb
            ->shouldNotReceive('insert');

        $result = $this->repository->initializeState($auction_id);

        $this->assertTrue($result);
    }

    /**
     * Test initializing state with invalid ID.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Input validation
     */
    public function test_initialize_state_invalid_id(): void
    {
        $result = $this->repository->initializeState(-1);

        $this->assertFalse($result);
    }

    /**
     * Test metadata JSON parsing in state history.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Metadata handling
     */
    public function test_get_state_history_metadata_parsing(): void
    {
        $auction_id = 123;
        $results = [
            [
                'state_id' => 'state-001',
                'state' => 'ACTIVE_SEALED',
                'from_state' => 'UPCOMING',
                'transitioned_at' => '2026-03-23 10:00:00',
                'initiated_by' => '2',
                'metadata' => '{"reason":"Manual admin trigger","comment":"Test"}',
            ],
        ];

        $this->wpdb
            ->shouldReceive('prepare')
            ->andReturn('mocked_query');

        $this->wpdb
            ->shouldReceive('get_results')
            ->andReturn($results);

        $history = $this->repository->getStateHistory($auction_id);

        $this->assertCount(1, $history);
        $this->assertIsArray($history[0]['metadata']);
        $this->assertSame('Manual admin trigger', $history[0]['metadata']['reason']);
        $this->assertSame('Test', $history[0]['metadata']['comment']);
        $this->assertSame(2, $history[0]['initiated_by']);
    }
}
