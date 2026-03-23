<?php

namespace Yith\Auctions\Repository;

use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\RepositoryTrait;

/**
 * AuctionStateRepository - Data access layer for auction state tracking.
 *
 * Responsible for:
 * - Persisting auction state transitions
 * - Querying current auction state
 * - Maintaining immutable state history
 * - Recording state change metadata
 * - Tracking state transition initiators
 *
 * Database Schema:
 * wp_wc_auction_states
 * ├─ id (PK) - Auto-increment
 * ├─ state_id (UUID, UNIQUE) - External reference
 * ├─ auction_id (FK) - Product/auction ID
 * ├─ auction_state - Current state (UPCOMING, ACTIVE_OPEN_BID, ACTIVE_SEALED, ENDED_REVEAL, COMPLETED)
 * ├─ transition_from - Previous state (nullable)
 * ├─ transition_at - When transition occurred
 * ├─ initiated_by - WordPress user ID (nullable for system transitions)
 * ├─ metadata - JSON context about transition
 * └─ created_at - Record creation timestamp
 *
 * Usage Pattern:
 * 1. Initialize with WPDB: new AuctionStateRepository($wpdb)
 * 2. Update state: updateState($auction_id, $new_state, $from_state, $user_id, $metadata)
 * 3. Query current: getCurrentState($auction_id)
 * 4. Get history: getStateHistory($auction_id)
 *
 * Design Principles:
 * - All operations use prepared statements (SQL injection prevention)
 * - State history is append-only (immutable audit trail)
 * - Current state stored for efficient queries
 * - FK constraints with cascade delete
 * - Timestamps for audit trail
 * - Transaction support for consistency
 *
 * @package Yith\Auctions\Repository
 * @requirement REQ-SEALED-BID-STATE-MACHINE-001: State storage and retrieval
 * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Immutable state history
 *
 * Class Relationships:
 * AuctionStateRepository
 *     ├─ uses: WPDB (database queries)
 *     ├─ uses: LoggerTrait (operation logging)
 *     ├─ uses: RepositoryTrait (common repo operations)
 *     └─ returns: array[] (state records)
 */
class AuctionStateRepository
{
    use LoggerTrait;
    use RepositoryTrait;

    /**
     * @var \wpdb WordPress database abstraction
     */
    private \wpdb $wpdb;

    /**
     * @var string Table name for auction states
     */
    private string $table;

    /**
     * Initialize auction state repository.
     *
     * @param \wpdb $wpdb WordPress database
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
        $this->table = $this->wpdb->prefix . 'wc_auction_states';
    }

    /**
     * Get current state of auction.
     *
     * Retrieves the most recent auction_state value. This is the current state.
     *
     * @param int $auction_id Auction post ID
     *
     * @return string|null Current state or null if auction not found
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Query current state
     */
    public function getCurrentState(int $auction_id): ?string
    {
        if ($auction_id <= 0) {
            return null;
        }

        $query = $this->wpdb->prepare(
            "SELECT auction_state FROM {$this->table} WHERE auction_id = %d ORDER BY id DESC LIMIT 1",
            $auction_id
        );

        $state = $this->wpdb->get_var($query);

        if (null === $state) {
            $this->logDebug('No state found for auction', [
                'auction_id' => $auction_id,
            ]);
        }

        return $state;
    }

    /**
     * Update auction state with transaction support.
     *
     * Creates a new state record with transition information. This is the primary
     * method for recording state changes. It maintains history for audit trails.
     *
     * @param int        $auction_id  Auction post ID
     * @param string     $new_state   Target state
     * @param string     $from_state  Previous state
     * @param int        $user_id     WordPress user ID of initiator (0 = system)
     * @param array|null $metadata    Optional context (e.g., ['reason' => 'Sealed bidding started'])
     *
     * @return bool True on success, false on failure
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Persist state transitions
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Record transition history
     */
    public function updateState(
        int $auction_id,
        string $new_state,
        string $from_state,
        int $user_id = 0,
        ?array $metadata = null
    ): bool {
        if ($auction_id <= 0 || empty($new_state)) {
            return false;
        }

        $state_id = $this->generateUUID();
        $now = current_time('mysql');
        $metadata_json = $metadata ? wp_json_encode($metadata) : null;
        $initiated_by = $user_id > 0 ? $user_id : null;

        $inserted = $this->wpdb->insert(
            $this->table,
            [
                'state_id' => $state_id,
                'auction_id' => $auction_id,
                'auction_state' => $new_state,
                'transition_from' => $from_state,
                'transition_at' => $now,
                'initiated_by' => $initiated_by,
                'metadata' => $metadata_json,
                'created_at' => $now,
            ],
            [
                '%s', // state_id
                '%d', // auction_id
                '%s', // auction_state
                '%s', // transition_from
                '%s', // transition_at
                '%d', // initiated_by
                '%s', // metadata
                '%s', // created_at
            ]
        );

        if (false === $inserted) {
            $this->logError('Failed to insert state transition', [
                'auction_id' => $auction_id,
                'new_state' => $new_state,
                'error' => $this->wpdb->last_error,
            ]);

            return false;
        }

        $this->logDebug('State transition recorded', [
            'auction_id' => $auction_id,
            'from_state' => $from_state,
            'new_state' => $new_state,
            'state_id' => $state_id,
            'user_id' => $user_id,
        ]);

        return true;
    }

    /**
     * Get full state history for audit trail.
     *
     * Returns all state transitions in chronological order, from initial creation
     * to present state. Useful for understanding auction lifecycle and debugging.
     *
     * @param int $auction_id Auction post ID
     *
     * @return array[] Array of state transition records:
     *     [
     *         [
     *             'state_id' => string,
     *             'state' => 'UPCOMING',
     *             'from_state' => null,
     *             'transitioned_at' => '2026-03-23 10:15:00',
     *             'initiated_by' => 1,
     *             'metadata' => ['reason' => 'Initial creation'],
     *         ],
     *         ...
     *     ]
     *
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Retrieve state change history
     */
    public function getStateHistory(int $auction_id): array
    {
        if ($auction_id <= 0) {
            return [];
        }

        $query = $this->wpdb->prepare(
            "SELECT 
                state_id,
                auction_state as state,
                transition_from as from_state,
                transition_at as transitioned_at,
                initiated_by,
                metadata
            FROM {$this->table}
            WHERE auction_id = %d
            ORDER BY id ASC",
            $auction_id
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);

        if (null === $results) {
            $this->logError('Failed to retrieve state history', [
                'auction_id' => $auction_id,
                'error' => $this->wpdb->last_error,
            ]);

            return [];
        }

        // Parse JSON metadata for each record
        foreach ($results as &$record) {
            if (!empty($record['metadata'])) {
                $metadata = json_decode($record['metadata'], true);
                $record['metadata'] = $metadata ?: [];
            } else {
                $record['metadata'] = [];
            }

            // Convert initiated_by to int or null
            $record['initiated_by'] = $record['initiated_by'] ? 
                (int) $record['initiated_by'] : null;
        }

        $this->logDebug('Retrieved state history', [
            'auction_id' => $auction_id,
            'count' => count($results),
        ]);

        return $results;
    }

    /**
     * Get auctions in specific state.
     *
     * Useful for finding auctions that need state transitions (e.g., all
     * auctions in ENDED_REVEAL state waiting for manual reveal).
     *
     * @param string $state   State to filter by
     * @param int    $limit   Maximum results (0 = unlimited)
     * @param int    $offset  Result offset for pagination
     *
     * @return int[] Array of auction IDs in the specified state
     *
     * @requirement REQ-SEALED-BID-WORKFLOW-001: Find auctions needing action
     */
    public function getAuctionsByState(string $state, int $limit = 0, int $offset = 0): array
    {
        if (empty($state)) {
            return [];
        }

        $query = $this->wpdb->prepare(
            "SELECT DISTINCT auction_id FROM {$this->table}
             WHERE auction_state = %s
             ORDER BY id DESC",
            $state
        );

        if ($limit > 0) {
            $query .= $this->wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
        }

        $results = $this->wpdb->get_col($query);

        if (null === $results) {
            $this->logError('Failed to retrieve auctions by state', [
                'state' => $state,
                'error' => $this->wpdb->last_error,
            ]);

            return [];
        }

        return array_map('intval', $results);
    }

    /**
     * Get state change count for auction.
     *
     * Useful for debugging or monitoring auction state churn. High numbers
     * might indicate state machine issues.
     *
     * @param int $auction_id Auction post ID
     *
     * @return int Number of state transitions
     *
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: State tracking metrics
     */
    public function getStateChangeCount(int $auction_id): int
    {
        if ($auction_id <= 0) {
            return 0;
        }

        $query = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE auction_id = %d",
            $auction_id
        );

        $count = (int) $this->wpdb->get_var($query);

        return max(0, $count);
    }

    /**
     * Get time spent in current state.
     *
     * Returns minutes since last state transition, useful for identifying
     * auctions stuck in a state.
     *
     * @param int $auction_id Auction post ID
     *
     * @return int|null Minutes in current state, or null if not found
     *
     * @requirement REQ-SEALED-BID-WORKFLOW-001: State duration tracking
     */
    public function getTimeInCurrentState(int $auction_id): ?int
    {
        if ($auction_id <= 0) {
            return null;
        }

        $query = $this->wpdb->prepare(
            "SELECT TIMESTAMPDIFF(MINUTE, transition_at, NOW()) as minutes_elapsed
             FROM {$this->table}
             WHERE auction_id = %d
             ORDER BY id DESC
             LIMIT 1",
            $auction_id
        );

        $minutes = $this->wpdb->get_var($query);

        return null === $minutes ? null : (int) $minutes;
    }

    /**
     * Get latest transition metadata.
     *
     * Returns the context/metadata from the most recent state transition.
     * Useful for understanding why an auction is in a particular state.
     *
     * @param int $auction_id Auction post ID
     *
     * @return array|null Metadata from latest transition, or null if not found
     *
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Transition context
     */
    public function getLatestTransitionMetadata(int $auction_id): ?array
    {
        if ($auction_id <= 0) {
            return null;
        }

        $query = $this->wpdb->prepare(
            "SELECT metadata FROM {$this->table}
             WHERE auction_id = %d
             ORDER BY id DESC
             LIMIT 1",
            $auction_id
        );

        $metadata = $this->wpdb->get_var($query);

        if (null === $metadata || empty($metadata)) {
            return null;
        }

        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Initialize auction state.
     *
     * Called when auction is first created. Sets initial state to UPCOMING.
     * This should be called during auction creation/migration.
     *
     * @param int $auction_id Auction post ID
     *
     * @return bool True on success
     *
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001: Initial state setup
     */
    public function initializeState(int $auction_id): bool
    {
        if ($auction_id <= 0) {
            return false;
        }

        // Check if already initialized
        $existing = $this->getCurrentState($auction_id);
        if (null !== $existing) {
            $this->logDebug('Auction state already initialized', [
                'auction_id' => $auction_id,
                'state' => $existing,
            ]);

            return true; // Idempotent
        }

        return $this->updateState(
            $auction_id,
            'UPCOMING',
            null,
            0,
            ['reason' => 'Initial state on auction creation']
        );
    }

    /**
     * Generate UUID for state transition tracking.
     *
     * @return string UUID v4
     *
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Unique transition IDs
     */
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
