<?php

namespace Yith\Auctions\Services\SealedBidding;

use Yith\Auctions\Repository\AuctionStateRepository;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;
use Yith\Auctions\Exceptions\InvalidStateException;
use Yith\Auctions\Exceptions\AuctionStateException;

/**
 * AuctionStateManager - Orchestrates auction state transitions.
 *
 * Responsible for:
 * - Managing state transitions for auctions
 * - Validating state transition rules
 * - Recording state change history
 * - Enforcing business rule constraints
 * - Tracking state transition initiators
 * - Supporting metadata for context
 *
 * Auction State Lifecycle:
 * UPCOMING
 *     ↓ (on auction start time)
 * ACTIVE_OPEN_BID
 *     ↓ (on sealed bid mode activation)
 * ACTIVE_SEALED
 *     ↓ (on auction end time)
 * ENDED_REVEAL
 *     ↓ (admin reveals bids or automatic)
 * COMPLETED
 *
 * State Rules:
 * - UPCOMING: Auction created, not yet started
 * - ACTIVE_OPEN_BID: Auction accepting open bids (optional phase)
 * - ACTIVE_SEALED: Auction accepting sealed bids (main phase)
 * - ENDED_REVEAL: Auction ended, bids awaiting reveal
 * - COMPLETED: Auction fully complete, can proceed to checkout
 *
 * Invariants:
 * - Only forward transitions allowed (no stepping back)
 * - Certain transitions require specific conditions
 * - All transitions recorded immutably
 * - State changes track who initiated them
 * - Metadata captures transition context
 *
 * @package Yith\Auctions\Services\SealedBidding
 * @requirement REQ-SEALED-BID-STATE-MACHINE-001: State transition management
 * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Immutable state history
 * @requirement REQ-SEALED-BID-WORKFLOW-001: Auction workflow coordination
 *
 * State Machine Diagram:
 *
 *  ┌─────────────┐
 *  │  UPCOMING   │
 *  └──────┬──────┘
 *         │ transitionToActiveBid()
 *         │ [requires start_time <= now]
 *         ↓
 *  ┌──────────────────┐
 *  │ ACTIVE_OPEN_BID  │ (optional)
 *  └──────┬───────────┘
 *         │ transitionToActiveSealed()
 *         │ [requires sealed_mode enabled]
 *         ↓
 *  ┌──────────────────┐
 *  │  ACTIVE_SEALED   │
 *  └──────┬───────────┘
 *         │ transitionToEndedReveal()
 *         │ [requires end_time <= now]
 *         ↓
 *  ┌──────────────────┐
 *  │  ENDED_REVEAL    │
 *  └──────┬───────────┘
 *         │ transitionToCompleted()
 *         │ [requires winner determined]
 *         ↓
 *  ┌──────────────────┐
 *  │   COMPLETED      │ (terminal)
 *  └──────────────────┘
 *
 * Class Relationships:
 * AuctionStateManager
 *     ├─ depends on: AuctionStateRepository (persistence)
 *     ├─ depends on: WPDB (database access)
 *     ├─ uses: LoggerTrait (operation logging)
 *     ├─ uses: ValidationTrait (input validation)
 *     ├─ throws: InvalidStateException (bad transitions)
 *     └─ throws: AuctionStateException (state violations)
 */
class AuctionStateManager
{
    use LoggerTrait;
    use ValidationTrait;

    /**
     * Valid auction states
     */
    private const STATE_UPCOMING = 'UPCOMING';
    private const STATE_ACTIVE_OPEN_BID = 'ACTIVE_OPEN_BID';
    private const STATE_ACTIVE_SEALED = 'ACTIVE_SEALED';
    private const STATE_ENDED_REVEAL = 'ENDED_REVEAL';
    private const STATE_COMPLETED = 'COMPLETED';

    /**
     * All valid states as array
     */
    private const VALID_STATES = [
        self::STATE_UPCOMING,
        self::STATE_ACTIVE_OPEN_BID,
        self::STATE_ACTIVE_SEALED,
        self::STATE_ENDED_REVEAL,
        self::STATE_COMPLETED,
    ];

    /**
     * Valid state transitions (from_state => [allowed_to_states])
     */
    private const VALID_TRANSITIONS = [
        self::STATE_UPCOMING => [
            self::STATE_ACTIVE_OPEN_BID,
            self::STATE_ACTIVE_SEALED,
        ],
        self::STATE_ACTIVE_OPEN_BID => [
            self::STATE_ACTIVE_SEALED,
            self::STATE_ENDED_REVEAL,
        ],
        self::STATE_ACTIVE_SEALED => [
            self::STATE_ENDED_REVEAL,
        ],
        self::STATE_ENDED_REVEAL => [
            self::STATE_COMPLETED,
        ],
        self::STATE_COMPLETED => [], // Terminal state
    ];

    /**
     * @var AuctionStateRepository Repository for state persistence
     */
    private AuctionStateRepository $repository;

    /**
     * @var \wpdb WordPress database
     */
    private \wpdb $wpdb;

    /**
     * Initialize auction state manager.
     *
     * @param AuctionStateRepository $repository State repository
     * @param \wpdb                  $wpdb       WordPress database
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001
     */
    public function __construct(
        AuctionStateRepository $repository,
        \wpdb $wpdb
    ) {
        $this->repository = $repository;
        $this->wpdb = $wpdb;
    }

    /**
     * Get current auction state.
     *
     * @param int $auction_id Auction post ID
     *
     * @return string Current state (one of VALID_STATES)
     *
     * @throws AuctionStateException If auction not found or state invalid
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Query current state
     */
    public function getCurrentState(int $auction_id): string
    {
        if ($auction_id <= 0) {
            throw new AuctionStateException('Invalid auction ID');
        }

        $state = $this->repository->getCurrentState($auction_id);

        if (null === $state) {
            $this->logWarning('Auction not found in state tracking', [
                'auction_id' => $auction_id,
            ]);

            throw new AuctionStateException(
                'Auction not found in state tracking'
            );
        }

        if (!in_array($state, self::VALID_STATES, true)) {
            throw new AuctionStateException(
                "Invalid state retrieved from database: {$state}"
            );
        }

        return $state;
    }

    /**
     * Transition auction to active open bid state.
     *
     * Requires:
     * - Current state: UPCOMING
     * - Auction start time has passed or has been reached
     *
     * @param int        $auction_id Auction post ID
     * @param int        $user_id    WordPress user ID (transition initiator)
     * @param array|null $metadata   Optional transition context (e.g., reason)
     *
     * @return bool True on success
     *
     * @throws InvalidStateException If current state doesn't allow this transition
     * @throws AuctionStateException If validation fails or transition fails
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: First state transition
     */
    public function transitionToActiveBid(
        int $auction_id,
        int $user_id = 0,
        ?array $metadata = null
    ): bool {
        return $this->transitionState(
            $auction_id,
            self::STATE_ACTIVE_OPEN_BID,
            $user_id,
            $metadata ?? ['reason' => 'Auction start time reached']
        );
    }

    /**
     * Transition auction to active sealed state.
     *
     * Requires:
     * - Current state: UPCOMING or ACTIVE_OPEN_BID
     * - Sealed bidding mode enabled on auction
     *
     * @param int        $auction_id Auction post ID
     * @param int        $user_id    WordPress user ID (transition initiator)
     * @param array|null $metadata   Optional transition context
     *
     * @return bool True on success
     *
     * @throws InvalidStateException If current state doesn't allow this transition
     * @throws AuctionStateException If validation fails or transition fails
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Sealed bid phase commencement
     */
    public function transitionToActiveSealed(
        int $auction_id,
        int $user_id = 0,
        ?array $metadata = null
    ): bool {
        return $this->transitionState(
            $auction_id,
            self::STATE_ACTIVE_SEALED,
            $user_id,
            $metadata ?? ['reason' => 'Sealed bid mode activated']
        );
    }

    /**
     * Transition auction to ended reveal state.
     *
     * Requires:
     * - Current state: ACTIVE_SEALED or ACTIVE_OPEN_BID
     * - Auction end time has passed
     *
     * @param int        $auction_id Auction post ID
     * @param int        $user_id    WordPress user ID (transition initiator)
     * @param array|null $metadata   Optional transition context
     *
     * @return bool True on success
     *
     * @throws InvalidStateException If current state doesn't allow this transition
     * @throws AuctionStateException If validation fails or transition fails
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Auction end state
     */
    public function transitionToEndedReveal(
        int $auction_id,
        int $user_id = 0,
        ?array $metadata = null
    ): bool {
        return $this->transitionState(
            $auction_id,
            self::STATE_ENDED_REVEAL,
            $user_id,
            $metadata ?? ['reason' => 'Auction end time reached']
        );
    }

    /**
     * Transition auction to completed state.
     *
     * Requires:
     * - Current state: ENDED_REVEAL
     * - Winner has been determined
     * - Bids have been revealed (if sealed)
     *
     * @param int        $auction_id Auction post ID
     * @param int        $user_id    WordPress user ID (transition initiator, usually admin)
     * @param array|null $metadata   Optional transition context (winner info, etc)
     *
     * @return bool True on success
     *
     * @throws InvalidStateException If current state doesn't allow this transition
     * @throws AuctionStateException If validation fails or transition fails
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Auction completion
     */
    public function transitionToCompleted(
        int $auction_id,
        int $user_id = 0,
        ?array $metadata = null
    ): bool {
        return $this->transitionState(
            $auction_id,
            self::STATE_COMPLETED,
            $user_id,
            $metadata ?? ['reason' => 'Auction completed']
        );
    }

    /**
     * Check if state transition is valid.
     *
     * @param string $from_state Current state
     * @param string $to_state   Target state
     *
     * @return bool True if transition is allowed
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Validate transitions
     */
    public function isValidTransition(string $from_state, string $to_state): bool
    {
        if (!in_array($from_state, self::VALID_STATES, true)) {
            return false;
        }

        if (!in_array($to_state, self::VALID_STATES, true)) {
            return false;
        }

        if (!isset(self::VALID_TRANSITIONS[$from_state])) {
            return false;
        }

        return in_array($to_state, self::VALID_TRANSITIONS[$from_state], true);
    }

    /**
     * Get all valid states.
     *
     * @return string[] Array of valid state constants
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: State enumeration
     */
    public static function getValidStates(): array
    {
        return self::VALID_STATES;
    }

    /**
     * Get state history for auction.
     *
     * Returns chronological list of all state transitions for audit trail.
     *
     * @param int $auction_id Auction post ID
     *
     * @return array[] Array of state history records with keys:
     *     - state: The state at this point
     *     - from_state: Previous state (null if initial)
     *     - transitioned_at: When transition occurred
     *     - initiated_by: User ID of transition initiator (null if system)
     *     - metadata: Transition context
     *
     * @throws AuctionStateException If auction not found
     *
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: State change history
     */
    public function getStateHistory(int $auction_id): array
    {
        if ($auction_id <= 0) {
            throw new AuctionStateException('Invalid auction ID');
        }

        return $this->repository->getStateHistory($auction_id);
    }

    /**
     * Check if auction is in terminal state.
     *
     * Terminal states cannot transition further.
     *
     * @param string $state State to check
     *
     * @return bool True if state is terminal
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Terminal state check
     */
    public static function isTerminalState(string $state): bool
    {
        return self::STATE_COMPLETED === $state;
    }

    /**
     * Check if auction state allows bid submission.
     *
     * @param string $state Auction state
     *
     * @return bool True if bids can be submitted in this state
     *
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Bid submission validation
     */
    public static function allowsBidSubmission(string $state): bool
    {
        return in_array($state, [
            self::STATE_ACTIVE_OPEN_BID,
            self::STATE_ACTIVE_SEALED,
        ], true);
    }

    /**
     * Check if auction state requires bid revelation.
     *
     * @param string $state Auction state
     *
     * @return bool True if bids should be revealed in this state
     *
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Reveal requirement check
     */
    public static function requiresReveal(string $state): bool
    {
        return in_array($state, [
            self::STATE_ENDED_REVEAL,
        ], true);
    }

    /**
     * Internal: Execute state transition with validation.
     *
     * @param int        $auction_id Auction post ID
     * @param string     $to_state   Target state
     * @param int        $user_id    User initiating transition (0 = system)
     * @param array|null $metadata   Transition context
     *
     * @return bool True on success
     *
     * @throws InvalidStateException If transition is invalid
     * @throws AuctionStateException If transition fails
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: State transition core logic
     */
    private function transitionState(
        int $auction_id,
        string $to_state,
        int $user_id = 0,
        ?array $metadata = null
    ): bool {
        // Validate inputs
        if ($auction_id <= 0) {
            throw new AuctionStateException('Invalid auction ID');
        }

        if (!in_array($to_state, self::VALID_STATES, true)) {
            throw new AuctionStateException("Invalid target state: {$to_state}");
        }

        // Get current state
        $from_state = $this->getCurrentState($auction_id);

        // Check if transition is valid
        if (!$this->isValidTransition($from_state, $to_state)) {
            throw new InvalidStateException(
                "Cannot transition from {$from_state} to {$to_state}",
                context: [
                    'auction_id' => $auction_id,
                    'from_state' => $from_state,
                    'to_state' => $to_state,
                ]
            );
        }

        // Check if already in target state
        if ($from_state === $to_state) {
            $this->logDebug("Auction already in state {$to_state}", [
                'auction_id' => $auction_id,
            ]);
            return true; // Idempotent
        }

        // Execute transition with database constraint for atomicity
        try {
            $this->wpdb->query('BEGIN');

            $updated = $this->repository->updateState(
                $auction_id,
                $to_state,
                $from_state,
                $user_id,
                $metadata
            );

            if (!$updated) {
                $this->wpdb->query('ROLLBACK');
                throw new AuctionStateException(
                    'Failed to update auction state in database'
                );
            }

            $this->wpdb->query('COMMIT');

            $this->logInfo("Auction state transitioned", [
                'auction_id' => $auction_id,
                'from_state' => $from_state,
                'to_state' => $to_state,
                'user_id' => $user_id,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->wpdb->query('ROLLBACK');

            throw new AuctionStateException(
                "State transition failed: {$e->getMessage()}",
                context: [
                    'auction_id' => $auction_id,
                    'from_state' => $from_state,
                    'to_state' => $to_state,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }
}
