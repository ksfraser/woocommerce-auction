<?php

namespace Yith\Auctions\Repository;

use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Exceptions\RepositoryException;

/**
 * PaymentAuthorizationRepository - Manages payment authorization persistence.
 *
 * Tracks all payment holds, captures, and refunds for entry fees.
 * Maintains complete audit trail of payment lifecycle.
 *
 * Database Tables:
 *
 * wp_wc_auction_payment_methods
 *     - id: Primary key
 *     - user_id: Bidder's WordPress user ID
 *     - payment_token: Secure token (never expose card data)
 *     - card_brand: Detected card type (Visa, Mastercard, etc)
 *     - card_last_four: Last 4 digits (for display)
 *     - exp_month, exp_year: Expiration date
 *     - created_at, updated_at: Timestamps
 *
 * wp_wc_auction_payment_authorizations
 *     - id: Primary key
 *     - auction_id: Auction post ID (foreign key)
 *     - user_id: Bidder user ID
 *     - bid_id: Unique bid identifier (UUID)
 *     - authorization_id: Gateway auth/charge ID (Square, Stripe, etc)
 *     - payment_gateway: Which provider (square, stripe, paypal)
 *     - amount_cents: Amount in cents (entry fee)
 *     - status: AUTHORIZED|CAPTURED|REFUNDED|FAILED|CAPTURE_FAILED|REFUND_FAILED
 *     - created_at: When hold was created
 *     - expires_at: When hold expires (7 days default)
 *     - charged_at: When amount was captured
 *     - refunded_at: When refund processed
 *     - metadata: JSON blob with additional info
 *
 * wp_wc_auction_refund_schedule
 *     - id: Primary key
 *     - authorization_id: Payment authorization ID (foreign key)
 *     - refund_id: Unique refund identifier
 *     - user_id: Bidder user ID
 *     - scheduled_for: When refund should process (24h from bid end)
 *     - reason: Why refund is being issued
 *     - status: PENDING|PROCESSED|FAILED
 *     - created_at, processed_at: Timestamps
 *
 * @package Yith\Auctions\Repository
 * @requirement REQ-ENTRY-FEE-PAYMENT-001: Payment authorization tracking
 */
class PaymentAuthorizationRepository
{
    use LoggerTrait;

    /**
     * @var \wpdb WordPress database instance
     */
    private \wpdb $wpdb;

    /**
     * @var string Table prefix for payment methods
     */
    private string $table_methods;

    /**
     * @var string Table prefix for payment authorizations
     */
    private string $table_authorizations;

    /**
     * @var string Table prefix for refund schedule
     */
    private string $table_refunds;

    /**
     * Initialize repository with database connection.
     *
     * @param \wpdb $wpdb WordPress database object
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function __construct(\wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $prefix = $this->wpdb->prefix;
        $this->table_methods = $prefix . 'wc_auction_payment_methods';
        $this->table_authorizations = $prefix . 'wc_auction_payment_authorizations';
        $this->table_refunds = $prefix . 'wc_auction_refund_schedule';
    }

    /**
     * Store payment method (card token) for bidder.
     *
     * Never stores actual card data—only secure tokens from payment gateway.
     *
     * @param int    $user_id      WordPress user ID
     * @param string $token        Secure payment token
     * @param string $brand        Card brand (Visa, Mastercard, etc)
     * @param string $last_four    Last 4 digits for display
     * @param int    $exp_month    Expiration month
     * @param int    $exp_year     Expiration year
     *
     * @return int Payment method record ID
     *
     * @throws RepositoryException If storage fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function storePaymentMethod(
        int $user_id,
        string $token,
        string $brand,
        string $last_four,
        int $exp_month,
        int $exp_year
    ): int {
        try {
            $result = $this->wpdb->insert(
                $this->table_methods,
                [
                    'user_id' => $user_id,
                    'payment_token' => $token,
                    'card_brand' => $brand,
                    'card_last_four' => $last_four,
                    'exp_month' => $exp_month,
                    'exp_year' => $exp_year,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );

            if (false === $result) {
                throw new \Exception($this->wpdb->last_error);
            }

            return (int) $this->wpdb->insert_id;
        } catch (\Exception $e) {
            $this->logError('Failed to store payment method', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
            ]);

            throw new RepositoryException('Failed to store payment method: ' . $e->getMessage());
        }
    }

    /**
     * Get payment method token for user (for authorization).
     *
     * @param int $user_id WordPress user ID
     *
     * @return array|null Payment method data or null if not found
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getPaymentMethodForUser(int $user_id): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_methods} WHERE user_id = %d ORDER BY created_at DESC LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Record a payment authorization (hold/charge).
     *
     * Creates a record tracking each payment authorization, used for:
     * - Linking holds to bids
     * - Capturing charges later
     * - Scheduling refunds
     * - Audit trail
     *
     * @param int    $auction_id       Auction post ID
     * @param int    $user_id          Bidder user ID
     * @param string $bid_id           Bid identifier (UUID)
     * @param string $authorization_id Payment gateway auth/charge ID
     * @param string $payment_gateway  Gateway name (square, stripe, etc)
     * @param Money  $amount           Entry fee amount
     * @param string $status           Current status
     * @param array  $metadata         Additional data (JSON stored)
     *
     * @return int Authorization record ID
     *
     * @throws RepositoryException If storage fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function recordAuthorization(
        int $auction_id,
        int $user_id,
        string $bid_id,
        string $authorization_id,
        string $payment_gateway,
        Money $amount,
        string $status,
        array $metadata = []
    ): int {
        try {
            $result = $this->wpdb->insert(
                $this->table_authorizations,
                [
                    'auction_id' => $auction_id,
                    'user_id' => $user_id,
                    'bid_id' => $bid_id,
                    'authorization_id' => $authorization_id,
                    'payment_gateway' => $payment_gateway,
                    'amount_cents' => $amount->getAmount(),
                    'status' => $status,
                    'created_at' => current_time('mysql'),
                    'expires_at' => (new \DateTime())->modify('+7 days')->format('Y-m-d H:i:s'),
                    'metadata' => wp_json_encode($metadata),
                ],
                ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );

            if (false === $result) {
                throw new \Exception($this->wpdb->last_error);
            }

            return (int) $this->wpdb->insert_id;
        } catch (\Exception $e) {
            $this->logError('Failed to record authorization', [
                'auction_id' => $auction_id,
                'bid_id' => $bid_id,
                'error' => $e->getMessage(),
            ]);

            throw new RepositoryException('Failed to record authorization: ' . $e->getMessage());
        }
    }

    /**
     * Update authorization status (AUTHORIZED → CAPTURED or REFUNDED).
     *
     * @param string $authorization_id Payment gateway auth/charge ID
     * @param string $new_status       New status value
     * @param array  $additional_data  Additional fields to update
     *
     * @return bool True if updated
     *
     * @throws RepositoryException If update fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function updateAuthorizationStatus(
        string $authorization_id,
        string $new_status,
        array $additional_data = []
    ): bool {
        try {
            $update_data = ['status' => $new_status];
            $update_data = array_merge($update_data, $additional_data);

            $result = $this->wpdb->update(
                $this->table_authorizations,
                $update_data,
                ['authorization_id' => $authorization_id]
            );

            if (false === $result) {
                throw new \Exception($this->wpdb->last_error);
            }

            return true;
        } catch (\Exception $e) {
            $this->logError('Failed to update authorization status', [
                'authorization_id' => $authorization_id,
                'error' => $e->getMessage(),
            ]);

            throw new RepositoryException('Failed to update authorization: ' . $e->getMessage());
        }
    }

    /**
     * Get authorization by ID.
     *
     * @param string $authorization_id Payment gateway auth ID
     *
     * @return array|null Authorization record or null
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getAuthorizationById(string $authorization_id): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_authorizations} WHERE authorization_id = %s",
                $authorization_id
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Get authorization by bid ID.
     *
     * @param string $bid_id Unique bid identifier
     *
     * @return array|null Authorization record
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getAuthorizationByBid(string $bid_id): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_authorizations} WHERE bid_id = %s",
                $bid_id
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Get all authorizations for auction.
     *
     * @param int $auction_id Auction post ID
     *
     * @return array[] Array of authorization records
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getAuthorizationsByAuction(int $auction_id): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_authorizations} WHERE auction_id = %d ORDER BY created_at DESC",
                $auction_id
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Schedule a refund for 24 hours from now.
     *
     * Creates a record for a refund to be processed later
     * (after dispute window closes).
     *
     * @param string    $auth_id        Authorization ID to refund
     * @param int       $user_id        Bidder user ID
     * @param \DateTime $scheduled_for  When refund should process
     * @param string    $reason         Refund reason
     *
     * @return string Refund ID (UUID-like identifier)
     *
     * @throws RepositoryException If insertion fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function scheduleRefund(
        string $auth_id,
        int $user_id,
        \DateTime $scheduled_for,
        string $reason
    ): string {
        $refund_id = 'REFUND-' . time() . '-' . wp_generate_uuid4();

        try {
            $result = $this->wpdb->insert(
                $this->table_refunds,
                [
                    'authorization_id' => $auth_id,
                    'refund_id' => $refund_id,
                    'user_id' => $user_id,
                    'scheduled_for' => $scheduled_for->format('Y-m-d H:i:s'),
                    'reason' => $reason,
                    'status' => 'PENDING',
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );

            if (false === $result) {
                throw new \Exception($this->wpdb->last_error);
            }

            return $refund_id;
        } catch (\Exception $e) {
            $this->logError('Failed to schedule refund', [
                'auth_id' => $auth_id,
                'error' => $e->getMessage(),
            ]);

            throw new RepositoryException('Failed to schedule refund: ' . $e->getMessage());
        }
    }

    /**
     * Get refund by bid ID.
     *
     * @param string $bid_id Bid identifier
     *
     * @return array|null Refund record or null
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getRefundByBid(string $bid_id): ?array
    {
        $auth = $this->getAuthorizationByBid($bid_id);
        if (!$auth) {
            return null;
        }

        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_refunds} WHERE authorization_id = %s",
                $auth['authorization_id']
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Get pending refunds (ready to process now).
     *
     * Used by cron job to process refunds after 24h dispute window.
     *
     * @param int $limit Maximum number of refunds to retrieve
     *
     * @return array[] Array of refund records
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getPendingRefunds(int $limit = 50): array
    {
        $now = current_time('mysql');

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_refunds} 
                 WHERE status = 'PENDING' AND scheduled_for <= %s 
                 ORDER BY scheduled_for ASC 
                 LIMIT %d",
                $now,
                $limit
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Update refund status.
     *
     * @param string $refund_id    Refund ID
     * @param string $new_status   New status (PROCESSED, FAILED, etc)
     * @param array  $additional   Additional fields to update
     *
     * @return bool True if updated
     *
     * @throws RepositoryException If update fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function updateRefundStatus(
        string $refund_id,
        string $new_status,
        array $additional = []
    ): bool {
        try {
            $update_data = ['status' => $new_status];
            $update_data = array_merge($update_data, $additional);

            if ('PROCESSED' === $new_status && !isset($additional['processed_at'])) {
                $update_data['processed_at'] = current_time('mysql');
            }

            $result = $this->wpdb->update(
                $this->table_refunds,
                $update_data,
                ['refund_id' => $refund_id]
            );

            if (false === $result) {
                throw new \Exception($this->wpdb->last_error);
            }

            return true;
        } catch (\Exception $e) {
            $this->logError('Failed to update refund status', [
                'refund_id' => $refund_id,
                'error' => $e->getMessage(),
            ]);

            throw new RepositoryException('Failed to update refund: ' . $e->getMessage());
        }
    }

    /**
     * Get authorization history for user (for customer support/audit).
     *
     * @param int $user_id User ID
     * @param int $limit   Number of records
     *
     * @return array[] Authorization records
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getAuthorizationHistory(int $user_id, int $limit = 50): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_authorizations} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
                $user_id,
                $limit
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Get failed authorizations for audit trail.
     *
     * @param int $limit Number of records
     *
     * @return array[] Failed authorization records
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getFailedAuthorizations(int $limit = 100): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_authorizations} 
                 WHERE status IN ('FAILED', 'CAPTURE_FAILED', 'REFUND_FAILED')
                 ORDER BY created_at DESC 
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Get refund by ID.
     *
     * @param string $refund_id Refund identifier
     *
     * @return array|null Refund record or null
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getRefundById(string $refund_id): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_refunds} WHERE refund_id = %s",
                $refund_id
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Get failed refunds for investigation.
     *
     * @param int $limit Number of records
     *
     * @return array[] Failed refund records
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function getFailedRefunds(int $limit = 100): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_refunds} 
                 WHERE status = 'FAILED'
                 ORDER BY created_at DESC 
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Prune old authorization records (after sufficient audit period).
     *
     * Keeps authorization records for configured period (e.g., 90 days)
     * for compliance/dispute resolution.
     *
     * @param int $days_old Records older than this days get deleted
     *
     * @return int Number of records deleted
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function pruneOldRecords(int $days_old = 90): int
    {
        $cutoff_date = (new \DateTime())->modify("-{$days_old} days")->format('Y-m-d H:i:s');

        // Only prune fully resolved records (not PENDING, not FAILED)
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table_authorizations}
                 WHERE status IN ('CAPTURED', 'REFUNDED')
                 AND created_at < %s",
                $cutoff_date
            )
        );

        return (int) $result;
    }
}
