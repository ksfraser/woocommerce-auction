<?php

namespace Yith\Auctions\Repository;

use Yith\Auctions\Traits\RepositoryTrait;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;
use Yith\Auctions\ValueObjects\Money;

/**
 * SealedBidRepository - Data access layer for sealed bids.
 *
 * Responsible for:
 * - Creating and storing sealed bid records
 * - Retrieving sealed bids by various criteria
 * - Recording sealed bid events and history
 * - Managing bid revelation workflow
 *
 * Database Tables:
 * - wp_wc_auction_sealed_bids: Main sealed bid records
 * - wp_wc_auction_sealed_bid_history: Immutable audit trail
 *
 * Security Patterns:
 * - All SQL queries use prepared statements
 * - Encrypted bids stored with encryption key version
 * - Hash verification to detect tampering
 * - No plaintext bids stored ever
 *
 * @package Yith\Auctions\Repository
 * @requirement REQ-SEALED-BID-STORAGE-001: Encrypted bid storage
 * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Immutable audit trail
 *
 * Architecture:
 *
 * Submit Sealed Bid Flow:
 * 1. submitSealedBid() creates encrypted record with SUBMITTED status
 * 2. recordHistory() logs immutable SUBMITTED event
 * 3. Returns bid_id for client to reference
 *
 * Reveal Bids Flow:
 * 1. getReadyForReveal() fetches all SUBMITTED bids
 * 2. revealBid() decrypts, verifies, moves to REVEALED status
 * 3. recordHistory() logs REVEALED event with plaintext hash
 *
 * Database Design:
 * - Indexes on (auction_id, status) for fast filtering
 * - Indexes on (user_id, auction_id) for user queries
 * - Foreign keys to proper tables
 * - Encryption key version tracked per bid for rotation
 */
class SealedBidRepository
{
    use RepositoryTrait;
    use LoggerTrait;
    use ValidationTrait;

    /**
     * @var \wpdb WordPress database object
     */
    protected \wpdb $wpdb;

    /**
     * @var string Main sealed bids table
     */
    private string $bids_table;

    /**
     * @var string History table for audit trail
     */
    private string $history_table;

    /**
     * Initialize sealed bid repository.
     *
     * @param \wpdb $wpdb WordPress database object
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
        $this->bids_table = $wpdb->prefix . 'wc_auction_sealed_bids';
        $this->history_table = $wpdb->prefix . 'wc_auction_sealed_bid_history';
    }

    /**
     * Create new sealed bid record.
     *
     * @param int    $auction_id Auction ID
     * @param int    $user_id User ID (WordPress user)
     * @param string $encrypted_bid Encrypted bid amount (IV+ciphertext+tag)
     * @param string $bid_hash SHA-256 hash of plaintext bid
     * @param string $key_id Encryption key ID used
     * @param string $status Bid status (SUBMITTED, REVEALED, ACCEPTED_FOR_COUNT)
     * @return string|false Sealed bid ID (UUID) or false on failure
     * @throws \InvalidArgumentException If inputs invalid
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function createSealedBid(
        int $auction_id,
        int $user_id,
        string $encrypted_bid,
        string $bid_hash,
        string $key_id,
        string $status = 'SUBMITTED'
    )
    {
        // Validate inputs
        $this->validateRequired($auction_id, 'auction_id');
        $this->validateRequired($user_id, 'user_id');
        $this->validateRequired($encrypted_bid, 'encrypted_bid');
        $this->validateRequired($bid_hash, 'bid_hash');
        $this->validateRequired($key_id, 'key_id');
        $this->validateEnum($status, ['SUBMITTED', 'REVEALED', 'ACCEPTED_FOR_COUNT', 'REJECTED']);

        // Generate UUID for sealed bid
        $sealed_bid_id = wp_generate_uuid4();
        $submitted_at = current_time('mysql');

        // Insert sealed bid record
        $insert_result = $this->wpdb->insert(
            $this->bids_table,
            [
                'sealed_bid_id'   => $sealed_bid_id,
                'auction_id'      => $auction_id,
                'user_id'         => $user_id,
                'encrypted_bid'   => $encrypted_bid,
                'bid_hash'        => $bid_hash,
                'key_id'          => $key_id,
                'status'          => $status,
                'submitted_at'    => $submitted_at,
                'revealed_at'     => null,
                'created_at'      => $submitted_at,
                'updated_at'      => $submitted_at,
            ],
            ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$insert_result) {
            $this->logError(
                'Failed to create sealed bid',
                ['auction_id' => $auction_id, 'user_id' => $user_id, 'error' => $this->wpdb->last_error]
            );
            return false;
        }

        // Record history
        $this->recordHistory($sealed_bid_id, $auction_id, $user_id, 'SUBMITTED', 'Sealed bid submitted');

        $this->logInfo(
            'Sealed bid created',
            ['sealed_bid_id' => $sealed_bid_id, 'auction_id' => $auction_id, 'user_id' => $user_id]
        );

        return $sealed_bid_id;
    }

    /**
     * Get sealed bid by ID.
     *
     * @param string $sealed_bid_id Sealed bid UUID
     * @return array|null Sealed bid record or null if not found
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function getSealedBidById(string $sealed_bid_id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->bids_table} WHERE sealed_bid_id = %s LIMIT 1",
            $sealed_bid_id
        );

        $bid = $this->wpdb->get_row($query, ARRAY_A);

        if (!$bid) {
            $this->logDebug('Sealed bid not found', ['sealed_bid_id' => $sealed_bid_id]);
            return null;
        }

        return $bid;
    }

    /**
     * Get all sealed bids for an auction.
     *
     * @param int    $auction_id Auction ID
     * @param string|null $status Filter by status (or null for all)
     * @return array Array of sealed bid records
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function getBidsForAuction(int $auction_id, ?string $status = null): array
    {
        if ($status) {
            $this->validateEnum($status, ['SUBMITTED', 'REVEALED', 'ACCEPTED_FOR_COUNT', 'REJECTED']);
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->bids_table} 
                 WHERE auction_id = %d AND status = %s
                 ORDER BY submitted_at ASC",
                $auction_id,
                $status
            );
        } else {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->bids_table}
                 WHERE auction_id = %d
                 ORDER BY submitted_at ASC",
                $auction_id
            );
        }

        $bids = $this->wpdb->get_results($query, ARRAY_A);

        $this->logDebug(
            'Retrieved sealed bids for auction',
            ['auction_id' => $auction_id, 'count' => count($bids ?? []), 'status' => $status]
        );

        return $bids ?? [];
    }

    /**
     * Get user's sealed bid for specific auction.
     *
     * @param int $auction_id Auction ID
     * @param int $user_id User ID
     * @return array|null User's sealed bid or null if not found
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function getUserSealedBid(int $auction_id, int $user_id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->bids_table}
             WHERE auction_id = %d AND user_id = %d
             ORDER BY submitted_at DESC
             LIMIT 1",
            $auction_id,
            $user_id
        );

        $bid = $this->wpdb->get_row($query, ARRAY_A);

        return $bid ?? null;
    }

    /**
     * Get all sealed bids ready for revelation.
     *
     * Returns SUBMITTED bids for auctions that have ended bidding period.
     *
     * @param int $auction_id Auction ID
     * @return array Array of SUBMITTED sealed bids
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function getReadyForReveal(int $auction_id): array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->bids_table}
             WHERE auction_id = %d AND status = %s
             ORDER BY submitted_at ASC",
            $auction_id,
            'SUBMITTED'
        );

        $bids = $this->wpdb->get_results($query, ARRAY_A);

        return $bids ?? [];
    }

    /**
     * Update sealed bid status after revelation.
     *
     * @param string $sealed_bid_id Sealed bid UUID
     * @param string $new_status New status
     * @param string|null $plaintext_hash SHA-256 hash of decrypted plaintext
     * @return bool Success
     * @throws \InvalidArgumentException If status invalid
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function updateBidStatus(
        string $sealed_bid_id,
        string $new_status,
        ?string $plaintext_hash = null
    ): bool
    {
        $this->validateEnum($new_status, ['SUBMITTED', 'REVEALED', 'ACCEPTED_FOR_COUNT', 'REJECTED']);

        $update_data = [
            'status'      => $new_status,
            'updated_at'  => current_time('mysql'),
        ];

        if ($new_status === 'REVEALED') {
            $update_data['revealed_at'] = current_time('mysql');
            $update_data['plaintext_hash'] = $plaintext_hash;
        }

        $result = $this->wpdb->update(
            $this->bids_table,
            $update_data,
            ['sealed_bid_id' => $sealed_bid_id],
            array_fill(0, count($update_data), '%s'),
            ['%s']
        );

        if ($result === false) {
            $this->logError(
                'Failed to update bid status',
                ['sealed_bid_id' => $sealed_bid_id, 'status' => $new_status, 'error' => $this->wpdb->last_error]
            );
            return false;
        }

        return true;
    }

    /**
     * Record sealed bid event in audit trail.
     *
     * @param string $sealed_bid_id Sealed bid UUID
     * @param int    $auction_id Auction ID
     * @param int    $user_id User ID
     * @param string $event_type Event type (SUBMITTED, REVEALED, REJECTED, etc.)
     * @param string $description Event description
     * @param array  $metadata Additional event metadata
     * @return bool Success
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001
     */
    public function recordHistory(
        string $sealed_bid_id,
        int $auction_id,
        int $user_id,
        string $event_type,
        string $description,
        array $metadata = []
    ): bool
    {
        $history_id = wp_generate_uuid4();

        $result = $this->wpdb->insert(
            $this->history_table,
            [
                'history_id'     => $history_id,
                'sealed_bid_id'  => $sealed_bid_id,
                'auction_id'     => $auction_id,
                'user_id'        => $user_id,
                'event_type'     => $event_type,
                'description'    => $description,
                'metadata'       => wp_json_encode($metadata),
                'created_at'     => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        if (!$result) {
            $this->logError(
                'Failed to record sealed bid history',
                ['sealed_bid_id' => $sealed_bid_id, 'event' => $event_type, 'error' => $this->wpdb->last_error]
            );
            return false;
        }

        $this->logDebug(
            'Sealed bid event recorded',
            ['sealed_bid_id' => $sealed_bid_id, 'event' => $event_type]
        );

        return true;
    }

    /**
     * Get audit trail for sealed bid.
     *
     * @param string $sealed_bid_id Sealed bid UUID
     * @return array Array of history events
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001
     */
    public function getHistory(string $sealed_bid_id): array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->history_table}
             WHERE sealed_bid_id = %s
             ORDER BY created_at ASC",
            $sealed_bid_id
        );

        $history = $this->wpdb->get_results($query, ARRAY_A);

        foreach ($history as &$event) {
            if ($event['metadata']) {
                $event['metadata'] = wp_json_decode($event['metadata'], true);
            }
        }

        return $history ?? [];
    }

    /**
     * Get bids ready for opening (auction bidding period ended).
     *
     * Retrieves SUBMITTED bids that haven't been revealed yet,
     * typically called at auction end time.
     *
     * @param int    $auction_id Auction ID
     * @return array Array of unrevealed bids
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function getUnrevealedBids(int $auction_id): array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->bids_table}
             WHERE auction_id = %d AND status = %s AND revealed_at IS NULL
             ORDER BY submitted_at ASC",
            $auction_id,
            'SUBMITTED'
        );

        $bids = $this->wpdb->get_results($query, ARRAY_A);

        return $bids ?? [];
    }

    /**
     * Delete sealed bid (cascade delete history).
     *
     * Only for administrative purposes (e.g., auction cancellation).
     * Normal workflow keeps records for audit trail.
     *
     * @param string $sealed_bid_id Sealed bid UUID
     * @return bool Success
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001
     */
    public function deleteSealedBid(string $sealed_bid_id): bool
    {
        // Delete history first
        $this->wpdb->delete(
            $this->history_table,
            ['sealed_bid_id' => $sealed_bid_id],
            ['%s']
        );

        // Delete sealed bid
        $result = $this->wpdb->delete(
            $this->bids_table,
            ['sealed_bid_id' => $sealed_bid_id],
            ['%s']
        );

        if (!$result) {
            $this->logError(
                'Failed to delete sealed bid',
                ['sealed_bid_id' => $sealed_bid_id, 'error' => $this->wpdb->last_error]
            );
            return false;
        }

        $this->logInfo('Sealed bid deleted', ['sealed_bid_id' => $sealed_bid_id]);

        return true;
    }

    /**
     * Get bid count statistics for auction.
     *
     * @param int $auction_id Auction ID
     * @return array Statistics by status
     * @requirement REQ-SEALED-BID-STORAGE-001
     */
    public function getAuctionStatistics(int $auction_id): array
    {
        $query = $this->wpdb->prepare(
            "SELECT status, COUNT(*) as count FROM {$this->bids_table}
             WHERE auction_id = %d
             GROUP BY status",
            $auction_id
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);

        $stats = [
            'SUBMITTED'           => 0,
            'REVEALED'            => 0,
            'ACCEPTED_FOR_COUNT'  => 0,
            'REJECTED'            => 0,
            'total'               => 0,
        ];

        foreach ($results as $row) {
            if (isset($stats[$row['status']])) {
                $stats[$row['status']] = (int)$row['count'];
            }
        }

        $stats['total'] = array_sum($stats) - ($stats['total'] ?? 0);

        return $stats;
    }
}
