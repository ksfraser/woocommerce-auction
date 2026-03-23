<?php

namespace Tests\Unit\Services\SealedBidding;

use PHPUnit\Framework\TestCase;
use Mockery;
use Yith\Auctions\Services\SealedBidding\AuctionStateManager;
use Yith\Auctions\Repository\AuctionStateRepository;
use Yith\Auctions\Exceptions\InvalidStateException;
use Yith\Auctions\Exceptions\AuctionStateException;

/**
 * AuctionStateManager Unit Tests
 *
 * Test suite for auction state transitions and validation.
 *
 * @package Tests\Unit\Services\SealedBidding
 * @requirement REQ-SEALED-BID-STATE-MACHINE-001
 * @covers \Yith\Auctions\Services\SealedBidding\AuctionStateManager
 * @group unit
 * @group state-machine
 */
class AuctionStateManagerTest extends TestCase
{
    /**
     * @var AuctionStateManager Auction state manager under test
     */
    private AuctionStateManager $manager;

    /**
     * @var \Mockery\Mock Mock repository
     */
    private \Mockery\Mock $repository;

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

        $this->repository = Mockery::mock(AuctionStateRepository::class);
        $this->wpdb = Mockery::mock('wpdb');
        $this->manager = new AuctionStateManager($this->repository, $this->wpdb);
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
     * Test getting current state.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Query current state
     */
    public function test_get_current_state_success(): void
    {
        $auction_id = 123;
        $expected_state = 'ACTIVE_SEALED';

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn($expected_state);

        $state = $this->manager->getCurrentState($auction_id);

        $this->assertSame($expected_state, $state);
    }

    /**
     * Test get current state with invalid ID.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Validate inputs
     */
    public function test_get_current_state_invalid_id(): void
    {
        $this->expectException(AuctionStateException::class);
        $this->expectExceptionMessage('Invalid auction ID');

        $this->manager->getCurrentState(-1);
    }

    /**
     * Test get current state when not found.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Handle missing auctions
     */
    public function test_get_current_state_not_found(): void
    {
        $auction_id = 999;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn(null);

        $this->expectException(AuctionStateException::class);
        $this->manager->getCurrentState($auction_id);
    }

    /**
     * Test transition from UPCOMING to ACTIVE_BID.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Valid transition
     */
    public function test_transition_upcoming_to_active_bid(): void
    {
        $auction_id = 123;
        $user_id = 1;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('UPCOMING');

        $this->repository
            ->shouldReceive('updateState')
            ->with(
                $auction_id,
                'ACTIVE_OPEN_BID',
                'UPCOMING',
                $user_id,
                Mockery::on(function ($arg) {
                    return is_array($arg) && isset($arg['reason']);
                })
            )
            ->once()
            ->andReturn(true);

        $this->wpdb
            ->shouldReceive('query')
            ->with('BEGIN')
            ->once();

        $this->wpdb
            ->shouldReceive('query')
            ->with('COMMIT')
            ->once();

        $result = $this->manager->transitionToActiveBid($auction_id, $user_id);

        $this->assertTrue($result);
    }

    /**
     * Test transition from ACTIVE_OPEN_BID to ACTIVE_SEALED.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Valid transition
     */
    public function test_transition_active_bid_to_sealed(): void
    {
        $auction_id = 123;
        $user_id = 1;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('ACTIVE_OPEN_BID');

        $this->repository
            ->shouldReceive('updateState')
            ->once()
            ->andReturn(true);

        $this->wpdb
            ->shouldReceive('query')
            ->with('BEGIN')
            ->once();

        $this->wpdb
            ->shouldReceive('query')
            ->with('COMMIT')
            ->once();

        $result = $this->manager->transitionToActiveSealed($auction_id, $user_id);

        $this->assertTrue($result);
    }

    /**
     * Test transition from ACTIVE_SEALED to ENDED_REVEAL.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Valid transition
     */
    public function test_transition_active_sealed_to_ended_reveal(): void
    {
        $auction_id = 123;
        $user_id = 1;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('ACTIVE_SEALED');

        $this->repository
            ->shouldReceive('updateState')
            ->once()
            ->andReturn(true);

        $this->wpdb
            ->shouldReceive('query')
            ->with('BEGIN')
            ->once();

        $this->wpdb
            ->shouldReceive('query')
            ->with('COMMIT')
            ->once();

        $result = $this->manager->transitionToEndedReveal($auction_id, $user_id);

        $this->assertTrue($result);
    }

    /**
     * Test transition from ENDED_REVEAL to COMPLETED.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Valid transition
     */
    public function test_transition_ended_reveal_to_completed(): void
    {
        $auction_id = 123;
        $user_id = 1;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('ENDED_REVEAL');

        $this->repository
            ->shouldReceive('updateState')
            ->once()
            ->andReturn(true);

        $this->wpdb
            ->shouldReceive('query')
            ->with('BEGIN')
            ->once();

        $this->wpdb
            ->shouldReceive('query')
            ->with('COMMIT')
            ->once();

        $result = $this->manager->transitionToCompleted($auction_id, $user_id);

        $this->assertTrue($result);
    }

    /**
     * Test invalid transition (backward).
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Prevent invalid transitions
     */
    public function test_invalid_transition_backward(): void
    {
        $auction_id = 123;
        $user_id = 1;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('COMPLETED');

        $this->expectException(InvalidStateException::class);
        $this->expectExceptionMessage('Cannot transition from COMPLETED');

        $this->manager->transitionToEndedReveal($auction_id, $user_id);
    }

    /**
     * Test invalid transition (skip state).
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Prevent invalid transitions
     */
    public function test_invalid_transition_skip_state(): void
    {
        $auction_id = 123;
        $user_id = 1;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('UPCOMING');

        $this->expectException(InvalidStateException::class);

        $this->manager->transitionToEndedReveal($auction_id, $user_id);
    }

    /**
     * Test idempotent transition (already in state).
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Handle idempotent transitions
     */
    public function test_idempotent_transition(): void
    {
        $auction_id = 123;
        $user_id = 1;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('ACTIVE_SEALED');

        // Should NOT call updateState if already in state
        $this->repository
            ->shouldNotReceive('updateState');

        $result = $this->manager->transitionToActiveSealed($auction_id, $user_id);

        $this->assertTrue($result);
    }

    /**
     * Test transition with database rollback on failure.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Handle database errors
     */
    public function test_transition_database_failure_rollback(): void
    {
        $auction_id = 123;
        $user_id = 1;

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('UPCOMING');

        $this->repository
            ->shouldReceive('updateState')
            ->once()
            ->andReturn(false);

        $this->wpdb
            ->shouldReceive('query')
            ->with('BEGIN')
            ->once();

        $this->wpdb
            ->shouldReceive('query')
            ->with('ROLLBACK')
            ->once();

        $this->expectException(AuctionStateException::class);
        $this->manager->transitionToActiveBid($auction_id, $user_id);
    }

    /**
     * Test is valid transition check.
     *
     * @test
     * @dataProvider validTransitions
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Validate transitions
     */
    public function test_is_valid_transition(string $from, string $to, bool $expected): void
    {
        $result = $this->manager->isValidTransition($from, $to);
        $this->assertSame($expected, $result);
    }

    /**
     * Data provider for valid transition tests.
     *
     * @return array[] Valid transition test cases
     */
    public static function validTransitions(): array
    {
        return [
            'upcoming to active bid' => ['UPCOMING', 'ACTIVE_OPEN_BID', true],
            'upcoming to active sealed' => ['UPCOMING', 'ACTIVE_SEALED', true],
            'active bid to sealed' => ['ACTIVE_OPEN_BID', 'ACTIVE_SEALED', true],
            'active bid to ended reveal' => ['ACTIVE_OPEN_BID', 'ENDED_REVEAL', true],
            'active sealed to ended reveal' => ['ACTIVE_SEALED', 'ENDED_REVEAL', true],
            'ended reveal to completed' => ['ENDED_REVEAL', 'COMPLETED', true],
            'completed to any (terminal)' => ['COMPLETED', 'UPCOMING', false],
            'upcoming to completed (skip)' => ['UPCOMING', 'COMPLETED', false],
            'ended reveal to active sealed (backward)' => ['ENDED_REVEAL', 'ACTIVE_SEALED', false],
            'invalid from state' => ['INVALID_STATE', 'ACTIVE_SEALED', false],
            'invalid to state' => ['UPCOMING', 'INVALID_STATE', false],
        ];
    }

    /**
     * Test get valid states.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: State enumeration
     */
    public function test_get_valid_states(): void
    {
        $states = AuctionStateManager::getValidStates();

        $this->assertIsArray($states);
        $this->assertContains('UPCOMING', $states);
        $this->assertContains('ACTIVE_OPEN_BID', $states);
        $this->assertContains('ACTIVE_SEALED', $states);
        $this->assertContains('ENDED_REVEAL', $states);
        $this->assertContains('COMPLETED', $states);
    }

    /**
     * Test is terminal state.
     *
     * @test
     * @dataProvider terminalStates
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Terminal state detection
     */
    public function test_is_terminal_state(string $state, bool $expected): void
    {
        $result = AuctionStateManager::isTerminalState($state);
        $this->assertSame($expected, $result);
    }

    /**
     * Data provider for terminal state tests.
     *
     * @return array[] Terminal state test cases
     */
    public static function terminalStates(): array
    {
        return [
            'completed is terminal' => ['COMPLETED', true],
            'upcoming is not terminal' => ['UPCOMING', false],
            'active sealed is not terminal' => ['ACTIVE_SEALED', false],
            'ended reveal is not terminal' => ['ENDED_REVEAL', false],
        ];
    }

    /**
     * Test allows bid submission.
     *
     * @test
     * @dataProvider bidSubmissionStates
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Bid submission validation
     */
    public function test_allows_bid_submission(string $state, bool $expected): void
    {
        $result = AuctionStateManager::allowsBidSubmission($state);
        $this->assertSame($expected, $result);
    }

    /**
     * Data provider for bid submission tests.
     *
     * @return array[] States that allow/disallow bid submission
     */
    public static function bidSubmissionStates(): array
    {
        return [
            'active open bid allows' => ['ACTIVE_OPEN_BID', true],
            'active sealed allows' => ['ACTIVE_SEALED', true],
            'upcoming disallows' => ['UPCOMING', false],
            'ended reveal disallows' => ['ENDED_REVEAL', false],
            'completed disallows' => ['COMPLETED', false],
        ];
    }

    /**
     * Test requires reveal.
     *
     * @test
     * @dataProvider revealStates
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Reveal requirement check
     */
    public function test_requires_reveal(string $state, bool $expected): void
    {
        $result = AuctionStateManager::requiresReveal($state);
        $this->assertSame($expected, $result);
    }

    /**
     * Data provider for require reveal tests.
     *
     * @return array[] States that require/don't require reveal
     */
    public static function revealStates(): array
    {
        return [
            'ended reveal requires' => ['ENDED_REVEAL', true],
            'upcoming does not' => ['UPCOMING', false],
            'active sealed does not' => ['ACTIVE_SEALED', false],
            'completed does not' => ['COMPLETED', false],
        ];
    }

    /**
     * Test get state history.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Retrieve state history
     */
    public function test_get_state_history(): void
    {
        $auction_id = 123;
        $expected_history = [
            ['state' => 'UPCOMING', 'from_state' => null],
            ['state' => 'ACTIVE_SEALED', 'from_state' => 'UPCOMING'],
        ];

        $this->repository
            ->shouldReceive('getStateHistory')
            ->with($auction_id)
            ->once()
            ->andReturn($expected_history);

        $history = $this->manager->getStateHistory($auction_id);

        $this->assertSame($expected_history, $history);
    }

    /**
     * Test get state history with invalid ID.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Validate inputs
     */
    public function test_get_state_history_invalid_id(): void
    {
        $this->expectException(AuctionStateException::class);
        $this->manager->getStateHistory(0);
    }

    /**
     * Test transition with custom metadata.
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Metadata tracking
     */
    public function test_transition_with_metadata(): void
    {
        $auction_id = 123;
        $user_id = 1;
        $metadata = ['reason' => 'Manual override', 'comment' => 'Testing'];

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('UPCOMING');

        $this->repository
            ->shouldReceive('updateState')
            ->with(
                $auction_id,
                'ACTIVE_OPEN_BID',
                'UPCOMING',
                $user_id,
                $metadata
            )
            ->once()
            ->andReturn(true);

        $this->wpdb
            ->shouldReceive('query')
            ->with('BEGIN')
            ->once();

        $this->wpdb
            ->shouldReceive('query')
            ->with('COMMIT')
            ->once();

        $result = $this->manager->transitionToActiveBid($auction_id, $user_id, $metadata);

        $this->assertTrue($result);
    }

    /**
     * Test transition with system initiator (user_id = 0).
     *
     * @test
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: System transitions
     */
    public function test_transition_system_initiated(): void
    {
        $auction_id = 123;
        $user_id = 0; // System transition

        $this->repository
            ->shouldReceive('getCurrentState')
            ->with($auction_id)
            ->once()
            ->andReturn('UPCOMING');

        $this->repository
            ->shouldReceive('updateState')
            ->with($auction_id, 'ACTIVE_OPEN_BID', 'UPCOMING', 0, Mockery::any())
            ->once()
            ->andReturn(true);

        $this->wpdb
            ->shouldReceive('query')
            ->with('BEGIN')
            ->once();

        $this->wpdb
            ->shouldReceive('query')
            ->with('COMMIT')
            ->once();

        $result = $this->manager->transitionToActiveBid($auction_id, $user_id);

        $this->assertTrue($result);
    }

    /**
     * Test multiple transitions in sequence.
     *
     * @test
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Full auction lifecycle
     */
    public function test_full_auction_lifecycle(): void
    {
        $auction_id = 123;
        $states = [
            'UPCOMING',
            'ACTIVE_OPEN_BID',
            'ACTIVE_SEALED',
            'ENDED_REVEAL',
            'COMPLETED',
        ];

        // Setup repository to return updated state for each call
        $call_count = 0;
        $this->repository
            ->shouldReceive('getCurrentState')
            ->times(4) // Called for each transition
            ->andReturnUsing(function () use (&$call_count, $states) {
                return $states[$call_count++];
            });

        $this->repository
            ->shouldReceive('updateState')
            ->times(4)
            ->andReturn(true);

        $this->wpdb
            ->shouldReceive('query')
            ->withArgs(['BEGIN'])
            ->times(4);

        $this->wpdb
            ->shouldReceive('query')
            ->withArgs(['COMMIT'])
            ->times(4);

        // Execute full lifecycle
        $this->assertTrue($this->manager->transitionToActiveBid($auction_id, 1));
        $this->assertTrue($this->manager->transitionToActiveSealed($auction_id, 1));
        $this->assertTrue($this->manager->transitionToEndedReveal($auction_id, 1));
        $this->assertTrue($this->manager->transitionToCompleted($auction_id, 1));
    }
}
