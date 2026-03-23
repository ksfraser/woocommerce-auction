<?php

namespace Yith\Auctions\Repository;

use Yith\Auctions\Traits\RepositoryTrait;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;

/**
 * EntryFeeRepository - Data access layer for entry fees and commissions.
 *
 * Responsible for:
 * - Recording entry fees paid by bidders
 * - Recording final value fees (commissions)
 * - Tracking fee payment status and refunds
 * - Generating accounting reports
 * - Audit trail for fee calculations
 *
 * Database Tables:
 * - wp_wc_auction_entry_fees: Entry fee records
 * - wp_wc_auction_fvf_commissions: Final Value Fee records
 * - wp_wc_auction_fee_transactions: Accounting ledger
 *
 * @package Yith\Auctions\Repository
 * @requirement REQ-ENTRY-FEE-STORAGE-001: Entry fee storage
 * @requirement REQ-COMMISSION-STORAGE-001: Commission storage
 * @requirement REQ-FEE-AUDIT-TRAIL-001: Fee audit trail
 *
 * Architecture:
 *
 * Entry Fee Flow:
 * 1. Bidder joins auction → recordEntryFee()
 * 2. Fee charged via payment processor
 * 3. Entry recorded with PENDING status
 * 4. Payment confirmed → updateFeeStatus(COMPLETED)
 * 5. Refund if auction cancelled → updateFeeStatus(REFUNDED)
 *
 * FVF Commission Flow:
 * 1. Auction ends → recordCommission()
 * 2. Commission calculated and recorded
 * 3. Status PENDING until settlement
 * 4. Seller paid → recordSettlement()
 * 5. Status updated to PAID
 *
 * Transaction Ledger:
 * - Double-entry accounting for platform financials
 * - Tracks: ENTRY_FEE_RECEIVED, COMMISSION_RECEIVED, REFUND_PAID, etc.
 * - Enables reconciliation and reporting
 */
class EntryFeeRepository
{
    use RepositoryTrait;
    use LoggerTrait;
    use ValidationTrait;

    /**
     * @var \wpdb WordPress database object
     */
    protected \wpdb $wpdb;

    /**
     * @var string Table for entry fees
     */
    private string $entry_fees_table;

    /**
     * @var string Table for FVF commissions
     */
    private string $fvf_table;

    /**
     * @var string Table for transaction ledger
     */
    private string $transactions_table;

    /**
     * Initialize entry fee repository.
     *
     * @param \wpdb $wpdb WordPress database object
     * @requirement REQ-ENTRY-FEE-STORAGE-001
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
        $this->entry_fees_table = $wpdb->prefix . 'wc_auction_entry_fees';
        $this->fvf_table = $wpdb->prefix . 'wc_auction_fvf_commissions';
        $this->transactions_table = $wpdb->prefix . 'wc_auction_fee_transactions';
    }

    /**
     * Record entry fee payment.
     *
     * @param int    $auction_id Auction ID
     * @param int    $bidder_id Bidder user ID
     * @param string $amount Entry fee amount (e.g., "10.00")
     * @param string $status Payment status (PENDING, COMPLETED, FAILED, REFUNDED)
     * @return string|false Entry fee record ID (UUID) or false
     * @requirement REQ-ENTRY-FEE-STORAGE-001
     */
    public function recordEntryFee(
        int $auction_id,
        int $bidder_id,
        string $amount,
        string $status = 'PENDING'
    )
    {
        $this->validateRequired($auction_id, 'auction_id');
        $this->validateRequired($bidder_id, 'bidder_id');
        $this->validateRequired($amount, 'amount');
        $this->validateEnum($status, ['PENDING', 'COMPLETED', 'FAILED', 'REFUNDED']);

        $entry_fee_id = wp_generate_uuid4();
        $recorded_at = current_time('mysql');

        $result = $this->wpdb->insert(
            $this->entry_fees_table,
            [
                'entry_fee_id' => $entry_fee_id,
                'auction_id' => $auction_id,
                'bidder_id' => $bidder_id,
                'amount' => $amount,
                'status' => $status,
                'recorded_at' => $recorded_at,
                'completed_at' => null,
                'refunded_at' => null,
                'created_at' => $recorded_at,
                'updated_at' => $recorded_at,
            ],
            ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$result) {
            $this->logError(
                'Failed to record entry fee',
                ['auction_id' => $auction_id, 'bidder_id' => $bidder_id, 'error' => $this->wpdb->last_error]
            );
            return false;
        }

        $this->logInfo(
            'Entry fee recorded',
            ['entry_fee_id' => $entry_fee_id, 'amount' => $amount, 'status' => $status]
        );

        return $entry_fee_id;
    }

    /**
     * Update entry fee status.
     *
     * @param string $entry_fee_id Entry fee UUID
     * @param string $new_status New status
     * @return bool Success
     * @requirement REQ-ENTRY-FEE-STORAGE-001
     */
    public function updateEntryFeeStatus(string $entry_fee_id, string $new_status): bool
    {
        $this->validateEnum($new_status, ['PENDING', 'COMPLETED', 'FAILED', 'REFUNDED']);

        $update_data = [
            'status' => $new_status,
            'updated_at' => current_time('mysql'),
        ];

        if ($new_status === 'COMPLETED') {
            $update_data['completed_at'] = current_time('mysql');
        } elseif ($new_status === 'REFUNDED') {
            $update_data['refunded_at'] = current_time('mysql');
        }

        $result = $this->wpdb->update(
            $this->entry_fees_table,
            $update_data,
            ['entry_fee_id' => $entry_fee_id],
            array_fill(0, count($update_data), '%s'),
            ['%s']
        );

        if ($result === false) {
            $this->logError(
                'Failed to update entry fee status',
                ['entry_fee_id' => $entry_fee_id, 'status' => $new_status, 'error' => $this->wpdb->last_error]
            );
            return false;
        }

        return true;
    }

    /**
     * Get entry fee record.
     *
     * @param string $entry_fee_id Entry fee UUID
     * @return array|null Record or null if not found
     * @requirement REQ-ENTRY-FEE-STORAGE-001
     */
    public function getEntryFee(string $entry_fee_id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->entry_fees_table} WHERE entry_fee_id = %s LIMIT 1",
            $entry_fee_id
        );

        return $this->wpdb->get_row($query, ARRAY_A) ?? null;
    }

    /**
     * Get bidder's entry fees for auction.
     *
     * @param int $auction_id Auction ID
     * @param int $bidder_id Bidder ID
     * @return array|null Entry fee record or null
     * @requirement REQ-ENTRY-FEE-STORAGE-001
     */
    public function getBidderEntryFee(int $auction_id, int $bidder_id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->entry_fees_table} 
             WHERE auction_id = %d AND bidder_id = %d 
             ORDER BY created_at DESC 
             LIMIT 1",
            $auction_id,
            $bidder_id
        );

        return $this->wpdb->get_row($query, ARRAY_A) ?? null;
    }

    /**
     * Record FVF commission.
     *
     * @param int    $auction_id Auction ID
     * @param int    $winner_id Winner user ID
     * @param int    $seller_id Seller user ID
     * @param string $hammer_price Winning bid amount
     * @param string $commission_amount Commission calculation result
     * @param string $status Commission status
     * @return string|false Commission record ID (UUID) or false
     * @requirement REQ-COMMISSION-STORAGE-001
     */
    public function recordCommission(
        int $auction_id,
        int $winner_id,
        int $seller_id,
        string $hammer_price,
        string $commission_amount,
        string $status = 'PENDING'
    )
    {
        $this->validateRequired($auction_id, 'auction_id');
        $this->validateRequired($winner_id, 'winner_id');
        $this->validateRequired($seller_id, 'seller_id');
        $this->validateRequired($hammer_price, 'hammer_price');
        $this->validateRequired($commission_amount, 'commission_amount');
        $this->validateEnum($status, ['PENDING', 'PAID', 'DISPUTED']);

        $commission_id = wp_generate_uuid4();
        $recorded_at = current_time('mysql');

        $result = $this->wpdb->insert(
            $this->fvf_table,
            [
                'commission_id' => $commission_id,
                'auction_id' => $auction_id,
                'winner_id' => $winner_id,
                'seller_id' => $seller_id,
                'hammer_price' => $hammer_price,
                'commission_amount' => $commission_amount,
                'status' => $status,
                'recorded_at' => $recorded_at,
                'paid_at' => null,
                'created_at' => $recorded_at,
            ],
            ['%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$result) {
            $this->logError(
                'Failed to record commission',
                ['auction_id' => $auction_id, 'error' => $this->wpdb->last_error]
            );
            return false;
        }

        $this->logInfo(
            'Commission recorded',
            ['commission_id' => $commission_id, 'amount' => $commission_amount]
        );

        return $commission_id;
    }

    /**
     * Update commission status.
     *
     * @param string $commission_id Commission UUID
     * @param string $new_status New status
     * @return bool Success
     * @requirement REQ-COMMISSION-STORAGE-001
     */
    public function updateCommissionStatus(string $commission_id, string $new_status): bool
    {
        $this->validateEnum($new_status, ['PENDING', 'PAID', 'DISPUTED']);

        $update_data = [
            'status' => $new_status,
        ];

        if ($new_status === 'PAID') {
            $update_data['paid_at'] = current_time('mysql');
        }

        $result = $this->wpdb->update(
            $this->fvf_table,
            $update_data,
            ['commission_id' => $commission_id],
            array_fill(0, count($update_data), '%s'),
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Get commission record.
     *
     * @param string $commission_id Commission UUID
     * @return array|null Record or null if not found
     * @requirement REQ-COMMISSION-STORAGE-001
     */
    public function getCommission(string $commission_id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->fvf_table} WHERE commission_id = %s LIMIT 1",
            $commission_id
        );

        return $this->wpdb->get_row($query, ARRAY_A) ?? null;
    }

    /**
     * Get auction commissions.
     *
     * @param int    $auction_id Auction ID
     * @param string|null $status Filter by status
     * @return array Array of commission records
     * @requirement REQ-COMMISSION-STORAGE-001
     */
    public function getAuctionCommissions(int $auction_id, ?string $status = null): array
    {
        if ($status) {
            $this->validateEnum($status, ['PENDING', 'PAID', 'DISPUTED']);
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->fvf_table} 
                 WHERE auction_id = %d AND status = %s 
                 ORDER BY recorded_at DESC",
                $auction_id,
                $status
            );
        } else {
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->fvf_table} 
                 WHERE auction_id = %d 
                 ORDER BY recorded_at DESC",
                $auction_id
            );
        }

        return $this->wpdb->get_results($query, ARRAY_A) ?? [];
    }

    /**
     * Record transaction in ledger for accounting.
     *
     * @param string $type Transaction type
     * @param string $amount Amount
     * @param int    $auction_id Related auction
     * @param int    $user_id Related user
     * @param string $description Human-readable description
     * @return bool Success
     * @requirement REQ-FEE-AUDIT-TRAIL-001
     */
    public function recordTransaction(
        string $type,
        string $amount,
        int $auction_id,
        int $user_id,
        string $description
    ): bool
    {
        $transaction_id = wp_generate_uuid4();

        $result = $this->wpdb->insert(
            $this->transactions_table,
            [
                'transaction_id' => $transaction_id,
                'type' => $type,
                'amount' => $amount,
                'auction_id' => $auction_id,
                'user_id' => $user_id,
                'description' => $description,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Get entry fee statistics for auction.
     *
     * @param int $auction_id Auction ID
     * @return array Statistics by status
     * @requirement REQ-ENTRY-FEE-STORAGE-001
     */
    public function getEntryFeeStatistics(int $auction_id): array
    {
        $query = $this->wpdb->prepare(
            "SELECT status, COUNT(*) as count, SUM(CAST(amount AS DECIMAL(10,2))) as total 
             FROM {$this->entry_fees_table}
             WHERE auction_id = %d
             GROUP BY status",
            $auction_id
        );

        $results = $this->wpdb->get_results($query, ARRAY_A);

        $stats = [
            'PENDING' => ['count' => 0, 'total' => '0.00'],
            'COMPLETED' => ['count' => 0, 'total' => '0.00'],
            'FAILED' => ['count' => 0, 'total' => '0.00'],
            'REFUNDED' => ['count' => 0, 'total' => '0.00'],
        ];

        foreach ($results as $row) {
            if (isset($stats[$row['status']])) {
                $stats[$row['status']] = [
                    'count' => (int)$row['count'],
                    'total' => $row['total'] ?? '0.00',
                ];
            }
        }

        return $stats;
    }
}
