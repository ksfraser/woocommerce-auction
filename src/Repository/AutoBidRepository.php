<?php

namespace Yith\Auctions\Repository;

use Yith\Auctions\Traits\RepositoryTrait;
use Yith\Auctions\Traits\LoggerTrait;

/**
 * AutoBidRepository - Data access for auto-bid configurations.
 *
 * Handles persistence of auto-bid setups and history.
 * Uses prepared statements and transaction support.
 *
 * @package Yith\Auctions\Repository
 * @requirement REQ-AUTO-BID-REPOSITORY-001: Auto-bid data persistence
 * @requirement REQ-AUTO-BID-QUERY-001: Optimized queries for auto-bid lookups
 */
class AutoBidRepository
{
    use RepositoryTrait;
    use LoggerTrait;

    /**
     * @var string Table name
     */
    protected string $table = 'wc_auction_auto_bids';

    /**
     * Initialize repository.
     *
     * @param \wpdb $wpdb WordPress database object
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->initRepository($wpdb);
    }

    /**
     * Get auto-bid by ID.
     *
     * @param string $auto_bid_id UUID of auto-bid
     * @return array|null Auto-bid data or null if not found
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function getById(string $auto_bid_id): ?array
    {
        return $this->queryRow(
            "SELECT * FROM {$this->getTable()} WHERE auto_bid_id = %s",
            [$auto_bid_id]
        );
    }

    /**
     * Get active auto-bid for user on auction.
     *
     * @param int $auction_id Auction ID
     * @param int $user_id User ID
     * @return array|null Auto-bid data or null
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function getActiveForAuctionUser(int $auction_id, int $user_id): ?array
    {
        return $this->queryRow(
            "SELECT * FROM {$this->getTable()} 
             WHERE auction_id = %d AND user_id = %d AND status = %s",
            [$auction_id, $user_id, 'ACTIVE']
        );
    }

    /**
     * Get all active auto-bids for auction.
     *
     * Used when a bid comes in on an auction to check all active auto-bids.
     *
     * @param int $auction_id Auction ID
     * @return array Array of auto-bids
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     * @requirement REQ-AUTO-BID-QUERY-001
     */
    public function getActiveForAuction(int $auction_id): array
    {
        return $this->query(
            "SELECT * FROM {$this->getTable()} 
             WHERE auction_id = %d AND status = %s
             ORDER BY created_at ASC",
            [$auction_id, 'ACTIVE']
        );
    }

    /**
     * Get all auto-bids for user.
     *
     * @param int $user_id User ID
     * @param array $statuses Statuses to filter by
     * @return array Array of auto-bids
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function getForUser(int $user_id, array $statuses = []): array
    {
        $query = "SELECT * FROM {$this->getTable()} WHERE user_id = %d";
        $params = [$user_id];

        if (!empty($statuses)) {
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $query .= " AND status IN ({$placeholders})";
            $params = array_merge([$user_id], $statuses);
        }

        $query .= " ORDER BY created_at DESC";

        return $this->query($query, $params);
    }

    /**
     * Create new auto-bid.
     *
     * @param array $data Auto-bid data
     * @return string Auto-bid ID on success
     * @throws \RuntimeException On failure
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function create(array $data): string
    {
        $auto_bid_id = $data['auto_bid_id'] ?? wp_generate_uuid4();

        $insert_data = [
            'auto_bid_id'   => $auto_bid_id,
            'auction_id'    => $data['auction_id'],
            'user_id'       => $data['user_id'],
            'maximum_bid'   => $data['maximum_bid'],
            'status'        => $data['status'] ?? 'ACTIVE',
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ];

        $format = ['%s', '%d', '%d', '%f', '%s', '%s', '%s'];

        $result = $this->wpdb->insert(
            $this->getTable(),
            $insert_data,
            $format
        );

        if (!$result) {
            $this->logError('Failed to create auto-bid', ['error' => $this->wpdb->last_error]);
            throw new \RuntimeException('Failed to create auto-bid: ' . $this->wpdb->last_error);
        }

        return $auto_bid_id;
    }

    /**
     * Update auto-bid.
     *
     * @param string $auto_bid_id Auto-bid ID
     * @param array  $data Data to update
     * @return bool Success
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function update(string $auto_bid_id, array $data): bool
    {
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->update(
            $this->getTable(),
            $data,
            ['auto_bid_id' => $auto_bid_id],
            array_values(array_map(function($val) {
                return is_numeric($val) ? '%f' : '%s';
            }, $data)),
            ['%s']
        );

        if ($result === false) {
            $this->logError('Failed to update auto-bid', ['auto_bid_id' => $auto_bid_id]);
            return false;
        }

        return true;
    }

    /**
     * Delete auto-bid.
     *
     * @param string $auto_bid_id Auto-bid ID
     * @return bool Success
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function delete(string $auto_bid_id): bool
    {
        $result = $this->wpdb->delete(
            $this->getTable(),
            ['auto_bid_id' => $auto_bid_id],
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Record auto-bid history event.
     *
     * @param array $history_data History event data
     * @return string History event ID
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function recordHistory(array $history_data): string
    {
        $history_id = $history_data['history_id'] ?? wp_generate_uuid4();
        $table = $this->wpdb->prefix . 'wc_auction_auto_bid_history';

        $insert_data = [
            'history_id'         => $history_id,
            'auto_bid_id'        => $history_data['auto_bid_id'],
            'auction_id'         => $history_data['auction_id'],
            'user_id'            => $history_data['user_id'],
            'event_type'         => $history_data['event_type'],
            'event_data'         => isset($history_data['event_data']) ? json_encode($history_data['event_data']) : null,
            'bid_amount'         => $history_data['bid_amount'] ?? null,
            'outbidden_by_user'  => $history_data['outbidden_by_user'] ?? null,
            'outbidden_by_bid'   => $history_data['outbidden_by_bid'] ?? null,
            'proxy_action'       => $history_data['proxy_action'] ?? null,
            'created_at'         => current_time('mysql'),
        ];

        $result = $this->wpdb->insert($table, $insert_data);

        if (!$result) {
            $this->logError('Failed to record history', ['error' => $this->wpdb->last_error]);
            throw new \RuntimeException('Failed to record history');
        }

        return $history_id;
    }

    /**
     * Get history for auto-bid.
     *
     * @param string $auto_bid_id Auto-bid ID
     * @param int $limit Number of records to return
     * @return array History events
     * @requirement REQ-AUTO-BID-REPOSITORY-001
     */
    public function getHistory(string $auto_bid_id, int $limit = 50): array
    {
        $table = $this->wpdb->prefix . 'wc_auction_auto_bid_history';

        return $this->query(
            "SELECT * FROM {$table} 
             WHERE auto_bid_id = %s
             ORDER BY created_at DESC
             LIMIT %d",
            [$auto_bid_id, $limit]
        );
    }
}
