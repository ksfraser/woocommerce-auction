<?php

namespace Yith\Auctions\Services\SealedBidding;

use Yith\Auctions\Repository\SealedBidRepository;
use Yith\Auctions\Services\Encryption\EncryptionManager;
use Yith\Auctions\Services\Encryption\EncryptionKeyManager;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;

/**
 * SealedBidService - Orchestrates sealed bid workflow.
 *
 * Responsible for:
 * - Submitting sealed bids (encryption + storage)
 * - Retrieving sealed bid status
 * - Revealing sealed bids at auction end
 * - Validating bid amounts
 * - Managing bid workflow state transitions
 *
 * Sealed Bid Workflow:
 * 1. User submits bid → Encrypted + Stored
 * 2. Bidding period continues (no one sees bids)
 * 3. Auction ends → Admin/System reveals all bids
 * 4. Highest bid wins (auction system processes normally)
 * 5. History kept for audit trail
 *
 * Security Considerations:
 * - Bids encrypted with AES-256-GCM (authenticated encryption)
 * - Hash stored for comparing without revealing bid
 * - Encryption key versioned for rotation
 * - No plaintext bids ever stored
 * - Tamper detection via authentication tag
 *
 * @package Yith\Auctions\Services\SeaedBidding
 * @requirement REQ-SEALED-BID-SERVICE-001: Core orchestration layer
 * @requirement REQ-SEALED-BID-ENCRYPTION-001: Bid encryption/decryption
 * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001: Immutable history
 *
 * Architecture:
 *
 * Submit Sealed Bid Flow:
 * submitSealedBid()
 *     ↓
 * validateBidAmount()
 *     ↓
 * checkDuplicateBid()
 *     ↓
 * EncryptionManager.encrypt()
 *     ↓
 * SealedBidRepository.createSealedBid()
 *     ↓
 * recordHistory(SUBMITTED)
 *     ↓
 * return sealed_bid_id
 *
 * Reveal Bids Flow:
 * revealAllBids()
 *     ↓
 * SealedBidRepository.getReadyForReveal()
 *     ↓
 * foreach bid:
 *   ├─ EncryptionManager.decrypt()
 *   ├─ verify authentication tag
 *   ├─ verify plaintext hash
 *   ├─ updateBidStatus(REVEALED)
 *   └─ recordHistory(REVEALED)
 *     ↓
 * return revelation_results
 */
class SealedBidService
{
    use LoggerTrait;
    use ValidationTrait;

    /**
     * @var SealedBidRepository Data access layer
     */
    private SealedBidRepository $repository;

    /**
     * @var EncryptionManager Encryption service
     */
    private EncryptionManager $encryption;

    /**
     * @var EncryptionKeyManager Key management service
     */
    private EncryptionKeyManager $key_manager;

    /**
     * @var \wpdb WordPress database
     */
    private \wpdb $wpdb;

    /**
     * Initialize sealed bid service.
     *
     * @param SealedBidRepository    $repository Sealed bid repository
     * @param EncryptionManager      $encryption Encryption service
     * @param EncryptionKeyManager   $key_manager Key management
     * @param \wpdb                  $wpdb WordPress database
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function __construct(
        SealedBidRepository $repository,
        EncryptionManager $encryption,
        EncryptionKeyManager $key_manager,
        \wpdb $wpdb
    )
    {
        $this->repository = $repository;
        $this->encryption = $encryption;
        $this->key_manager = $key_manager;
        $this->wpdb = $wpdb;
    }

    /**
     * Submit a sealed bid.
     *
     * Encrypts bid amount and stores sealed record.
     * User cannot modify bid once submitted (security).
     *
     * @param int    $auction_id Auction ID
     * @param int    $user_id User ID (WordPress user)
     * @param string $bid_amount Bid amount (e.g., "100.50")
     * @return string|false Sealed bid ID (UUID) or false on failure
     * @throws \InvalidArgumentException If inputs invalid
     * @throws \RuntimeException If encryption fails
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function submitSealedBid(int $auction_id, int $user_id, string $bid_amount)
    {
        // Validate inputs
        $this->validateRequired($auction_id, 'auction_id');
        $this->validateRequired($user_id, 'user_id');
        $this->validateRequired($bid_amount, 'bid_amount');
        $this->validateDecimalPlaces($bid_amount, 2);

        // Check bid amount is positive
        if ((float)$bid_amount <= 0) {
            throw new \InvalidArgumentException('Bid amount must be greater than zero');
        }

        // Check for duplicate bid
        if ($existing = $this->repository->getUserSealedBid($auction_id, $user_id)) {
            if ($existing['status'] === 'SUBMITTED') {
                $this->logWarning(
                    'Attempt to submit duplicate sealed bid',
                    ['auction_id' => $auction_id, 'user_id' => $user_id]
                );
                throw new \LogicException('User already has active sealed bid for this auction');
            }
        }

        try {
            // Get active encryption key
            $active_key = $this->key_manager->getActiveKey();
            $key_id = $active_key['key_id'];

            // Encrypt bid amount
            $encrypted_bid = $this->encryption->encrypt($bid_amount);
            if (!$encrypted_bid) {
                throw new \RuntimeException('Failed to encrypt bid');
            }

            // Generate hash for comparison/verification
            $bid_hash = $this->encryption->hashPlaintext($bid_amount);

            // Create sealed bid record
            $sealed_bid_id = $this->repository->createSealedBid(
                $auction_id,
                $user_id,
                $encrypted_bid,
                $bid_hash,
                $key_id,
                'SUBMITTED'
            );

            if (!$sealed_bid_id) {
                throw new \RuntimeException('Failed to store sealed bid');
            }

            $this->logInfo(
                'Sealed bid submitted successfully',
                [
                    'sealed_bid_id' => $sealed_bid_id,
                    'auction_id' => $auction_id,
                    'user_id' => $user_id,
                ]
            );

            return $sealed_bid_id;

        } catch (\Exception $e) {
            $this->logError(
                'Error submitting sealed bid',
                [
                    'auction_id' => $auction_id,
                    'user_id' => $user_id,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }
    }

    /**
     * Get sealed bid status.
     *
     * @param string $sealed_bid_id Sealed bid UUID
     * @return array|null Bid status info or null if not found
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function getSealedBidStatus(string $sealed_bid_id): ?array
    {
        $bid = $this->repository->getSealedBidById($sealed_bid_id);

        if (!$bid) {
            return null;
        }

        return [
            'sealed_bid_id'  => $bid['sealed_bid_id'],
            'auction_id'     => $bid['auction_id'],
            'user_id'        => $bid['user_id'],
            'status'         => $bid['status'],
            'submitted_at'   => $bid['submitted_at'],
            'revealed_at'    => $bid['revealed_at'],
        ];
    }

    /**
     * Reveal all sealed bids for an auction.
     *
     * Called when auction bidding period ends.
     * Decrypts all SUBMITTED bids and marks as REVEALED.
     *
     * @param int $auction_id Auction ID
     * @return array Revelation results with decrypted bids
     * @throws \RuntimeException If revelation fails
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function revealAllBids(int $auction_id): array
    {
        $this->logInfo('Starting sealed bid revelation', ['auction_id' => $auction_id]);

        // Get all submitted bids ready for revelation
        $bids = $this->repository->getReadyForReveal($auction_id);

        $revealed_bids = [];
        $failed_bids = [];

        // Begin transaction for all-or-nothing revelation
        $this->wpdb->query('START TRANSACTION');

        try {
            foreach ($bids as $bid) {
                try {
                    // Decrypt bid amount
                    $plaintext_bid = $this->encryption->decrypt($bid['encrypted_bid']);

                    if ($plaintext_bid === false) {
                        // Decryption failed - tampering detected or wrong key
                        $this->logError(
                            'Failed to decrypt sealed bid - possible tampering',
                            ['sealed_bid_id' => $bid['sealed_bid_id'], 'key_id' => $bid['key_id']]
                        );

                        $failed_bids[] = [
                            'sealed_bid_id' => $bid['sealed_bid_id'],
                            'reason' => 'DECRYPTION_FAILED',
                            'status' => 'REJECTED',
                        ];

                        // Mark as rejected
                        $this->repository->updateBidStatus(
                            $bid['sealed_bid_id'],
                            'REJECTED'
                        );

                        $this->repository->recordHistory(
                            $bid['sealed_bid_id'],
                            $auction_id,
                            $bid['user_id'],
                            'REJECTED',
                            'Bid decryption failed - possible tampering'
                        );

                        continue;
                    }

                    // Verify hash matches
                    $expected_hash = $this->encryption->hashPlaintext($plaintext_bid);
                    if (!hash_equals($bid['bid_hash'], $expected_hash)) {
                        $this->logError(
                            'Bid hash mismatch - tampering detected',
                            ['sealed_bid_id' => $bid['sealed_bid_id']]
                        );

                        $failed_bids[] = [
                            'sealed_bid_id' => $bid['sealed_bid_id'],
                            'reason' => 'HASH_MISMATCH',
                            'status' => 'REJECTED',
                        ];

                        $this->repository->updateBidStatus(
                            $bid['sealed_bid_id'],
                            'REJECTED'
                        );

                        $this->repository->recordHistory(
                            $bid['sealed_bid_id'],
                            $auction_id,
                            $bid['user_id'],
                            'REJECTED',
                            'Bid hash verification failed - tampering detected'
                        );

                        continue;
                    }

                    // Bid is valid, mark as revealed
                    $revealed_hash = $this->encryption->hashPlaintext($plaintext_bid);
                    $this->repository->updateBidStatus(
                        $bid['sealed_bid_id'],
                        'REVEALED',
                        $revealed_hash
                    );

                    $this->repository->recordHistory(
                        $bid['sealed_bid_id'],
                        $auction_id,
                        $bid['user_id'],
                        'REVEALED',
                        'Sealed bid revealed and decrypted successfully',
                        ['plaintext_hash' => $revealed_hash]
                    );

                    $revealed_bids[] = [
                        'sealed_bid_id' => $bid['sealed_bid_id'],
                        'user_id'       => $bid['user_id'],
                        'bid_amount'    => $plaintext_bid,
                        'status'        => 'REVEALED',
                    ];

                } catch (\Exception $e) {
                    $this->logError(
                        'Error revealing individual bid',
                        ['sealed_bid_id' => $bid['sealed_bid_id'], 'error' => $e->getMessage()]
                    );

                    $failed_bids[] = [
                        'sealed_bid_id' => $bid['sealed_bid_id'],
                        'reason' => 'PROCESSING_ERROR',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Commit transaction
            $this->wpdb->query('COMMIT');

            $this->logInfo(
                'Sealed bid revelation complete',
                [
                    'auction_id' => $auction_id,
                    'revealed_count' => count($revealed_bids),
                    'failed_count' => count($failed_bids),
                ]
            );

            return [
                'success' => count($failed_bids) === 0,
                'revealed_bids' => $revealed_bids,
                'failed_bids' => $failed_bids,
                'statistics' => $this->repository->getAuctionStatistics($auction_id),
            ];

        } catch (\Exception $e) {
            $this->wpdb->query('ROLLBACK');
            $this->logError('Sealed bid revelation transaction failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get sealed bid history.
     *
     * @param string $sealed_bid_id Sealed bid UUID
     * @return array Audit trail events
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001
     */
    public function getSealedBidHistory(string $sealed_bid_id): array
    {
        return $this->repository->getHistory($sealed_bid_id);
    }

    /**
     * Get auction bid statistics.
     *
     * @param int $auction_id Auction ID
     * @return array Statistics including count by status
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function getAuctionBidStatistics(int $auction_id): array
    {
        return $this->repository->getAuctionStatistics($auction_id);
    }

    /**
     * Check if key rotation is needed.
     *
     * Called periodically (e.g., daily) to ensure keys are rotated.
     * Automatically creates new key if needed.
     *
     * @return bool True if rotation was performed
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function checkKeyRotation(): bool
    {
        if (!$this->key_manager->isRotationDue()) {
            return false;
        }

        try {
            $new_key = $this->key_manager->createNewKey();
            $this->logInfo('Encryption key rotated', ['new_key_id' => $new_key['key_id']]);
            return true;
        } catch (\Exception $e) {
            $this->logError('Failed to rotate encryption key', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Clean up old expired keys.
     *
     * Called periodically (e.g., monthly) to remove old keys from database.
     *
     * @return int Number of keys deleted
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function cleanupExpiredKeys(): int
    {
        return $this->key_manager->cleanupExpiredKeys();
    }

    /**
     * Get key statistics for monitoring.
     *
     * @return array Key stats including rotation status
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function getKeyStatistics(): array
    {
        return $this->key_manager->getKeyStatistics();
    }
}
