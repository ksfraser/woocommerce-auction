<?php

namespace Yith\Auctions\Services\AutoBidding;

use Yith\Auctions\Repository\AutoBidRepository;
use Yith\Auctions\Services\BidQueue;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;
use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\ValueObjects\AutoBidStatus;

/**
 * AutoBidService - Manages auto-bid lifecycle.
 *
 * Main service for auto-bidding feature. Handles:
 * - Creating and managing auto-bid configurations
 * - Processing outbids and placing counter-bids via proxy bidding
 * - Coordinating with BidQueue for async bid processing
 * - Tracking history and audit trail
 *
 * @package Yith\Auctions\Services\AutoBidding
 * @requirement REQ-AUTO-BID-SERVICE-001: Auto-bid service orchestration
 * @requirement REQ-AUTO-BID-QUEUE-001: Integration with BidQueue for async processing
 *
 * Architecture:
 *
 * ```
 * AutoBidService ──┬──> ProxyBiddingEngine (stateless calculations)
 *                  ├──> AutoBidRepository (data access)
 *                  ├──> BidQueue (async bid placement)
 *                  └──> History tracking
 * ```
 */
class AutoBidService
{
    use LoggerTrait;
    use ValidationTrait;

    /**
     * @var AutoBidRepository Auto-bid repository
     */
    private AutoBidRepository $repository;

    /**
     * @var BidQueue Bid queue service
     */
    private BidQueue $bid_queue;

    /**
     * @var ProxyBiddingEngine Proxy bidding algorithm
     */
    private ProxyBiddingEngine $engine;

    /**
     * @var callable Increment calculator function
     */
    private $increment_calculator;

    /**
     * Initialize service.
     *
     * @param AutoBidRepository $repository Auto-bid repository
     * @param BidQueue $bid_queue Bid queue service
     * @param ProxyBiddingEngine $engine Proxy bidding engine
     * @param callable $increment_calculator Callable to calculate bid increment
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function __construct(
        AutoBidRepository $repository,
        BidQueue $bid_queue,
        ProxyBiddingEngine $engine,
        callable $increment_calculator
    ) {
        $this->repository = $repository;
        $this->bid_queue = $bid_queue;
        $this->engine = $engine;
        $this->increment_calculator = $increment_calculator;
    }

    /**
     * Set up auto-bid for user.
     *
     * Creates a new auto-bid configuration. A user can only have one
     * active auto-bid per auction.
     *
     * @param int $auction_id Auction ID
     * @param int $user_id User ID
     * @param float $maximum_bid Maximum amount user will bid
     * @return string Auto-bid ID
     * @throws \InvalidArgumentException If inputs invalid
     * @throws \RuntimeException If creation fails
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function setAutoBid(int $auction_id, int $user_id, float $maximum_bid): string
    {
        // Validate inputs
        $this->validateRequired($auction_id, 'auction_id');
        $this->validateRequired($user_id, 'user_id');
        $this->validateRequired($maximum_bid, 'maximum_bid');
        $this->validateRange($maximum_bid, 0.01, 999999.99, 'maximum_bid');

        // Check no existing active auto-bid
        $existing = $this->repository->getActiveForAuctionUser($auction_id, $user_id);
        if ($existing) {
            throw new \InvalidArgumentException(
                'User already has an active auto-bid on this auction'
            );
        }

        // Create auto-bid
        $auto_bid_id = $this->repository->create([
            'auction_id'   => $auction_id,
            'user_id'      => $user_id,
            'maximum_bid'  => $maximum_bid,
            'status'       => AutoBidStatus::ACTIVE,
        ]);

        // Record history event
        $this->repository->recordHistory([
            'auto_bid_id'  => $auto_bid_id,
            'auction_id'   => $auction_id,
            'user_id'      => $user_id,
            'event_type'   => 'AUTO_BID_CREATED',
            'event_data'   => ['maximum_bid' => $maximum_bid],
            'bid_amount'   => $maximum_bid,
        ]);

        $this->logInfo(
            'Auto-bid created',
            [
                'auto_bid_id'   => $auto_bid_id,
                'auction_id'    => $auction_id,
                'user_id'       => $user_id,
                'maximum_bid'   => $maximum_bid,
            ]
        );

        return $auto_bid_id;
    }

    /**
     * Cancel auto-bid.
     *
     * @param string $auto_bid_id Auto-bid ID
     * @return bool Success
     * @throws \InvalidArgumentException If auto-bid not found
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function cancelAutoBid(string $auto_bid_id): bool
    {
        $auto_bid = $this->repository->getById($auto_bid_id);
        if (!$auto_bid) {
            throw new \InvalidArgumentException("Auto-bid not found: {$auto_bid_id}");
        }

        if (AutoBidStatus::isTerminal($auto_bid['status'])) {
            throw new \InvalidArgumentException(
                "Cannot cancel auto-bid with status: {$auto_bid['status']}"
            );
        }

        // Update status
        $this->repository->update($auto_bid_id, ['status' => AutoBidStatus::CANCELLED]);

        // Record history
        $this->repository->recordHistory([
            'auto_bid_id'  => $auto_bid_id,
            'auction_id'   => $auto_bid['auction_id'],
            'user_id'      => $auto_bid['user_id'],
            'event_type'   => 'AUTO_BID_CANCELLED',
        ]);

        $this->logInfo('Auto-bid cancelled', ['auto_bid_id' => $auto_bid_id]);

        return true;
    }

    /**
     * Update auto-bid maximum.
     *
     * Allows user to increase their maximum bid while auto-bid is active.
     *
     * @param string $auto_bid_id Auto-bid ID
     * @param float $new_maximum New maximum bid
     * @return bool Success
     * @throws \InvalidArgumentException If inputs invalid
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function updateMaximum(string $auto_bid_id, float $new_maximum): bool
    {
        $auto_bid = $this->repository->getById($auto_bid_id);
        if (!$auto_bid) {
            throw new \InvalidArgumentException("Auto-bid not found: {$auto_bid_id}");
        }

        if ($new_maximum <= (float)$auto_bid['maximum_bid']) {
            throw new \InvalidArgumentException(
                'New maximum must be greater than current maximum'
            );
        }

        $this->repository->update($auto_bid_id, ['maximum_bid' => $new_maximum]);

        $this->repository->recordHistory([
            'auto_bid_id'  => $auto_bid_id,
            'auction_id'   => $auto_bid['auction_id'],
            'user_id'      => $auto_bid['user_id'],
            'event_type'   => 'AUTO_BID_UPDATED',
            'event_data'   => [
                'old_maximum' => $auto_bid['maximum_bid'],
                'new_maximum' => $new_maximum,
            ],
        ]);

        $this->logInfo(
            'Auto-bid updated',
            [
                'auto_bid_id'   => $auto_bid_id,
                'old_maximum'   => $auto_bid['maximum_bid'],
                'new_maximum'   => $new_maximum,
            ]
        );

        return true;
    }

    /**
     * Process outbid - attempt proxy bid.
     *
     * Called when a new bid comes in on an auction with active auto-bids.
     * Checks if auto-bid should counter-bid, and if so, places async bid via queue.
     *
     * This is the main driver of the proxy bidding algorithm.
     *
     * @param int $auction_id Auction ID
     * @param array $new_bid New incoming bid data
     * @param string $auction_status Current auction status
     * @return bool True if counter-bid was queued
     * @requirement REQ-AUTO-BID-SERVICE-001
     * @requirement REQ-AUTO-BID-QUEUE-001
     */
    public function processOutbid(int $auction_id, array $new_bid, string $auction_status): bool
    {
        $auto_bids = $this->repository->getActiveForAuction($auction_id);
        
        if (empty($auto_bids)) {
            return false;
        }

        $bid_placed = false;
        $new_bid_money = Money::fromFloat((float)$new_bid['amount']);

        foreach ($auto_bids as $auto_bid) {
            // Check if should place counter-bid
            if (!$this->engine->shouldPlaceCounterBid($auto_bid, $new_bid, $auction_status)) {
                continue;
            }

            $auto_bid_max = Money::fromFloat((float)$auto_bid['maximum_bid']);

            // Calculate proxy bid
            $proxy_bid = $this->engine->calculateProxyBid(
                $auto_bid_max,
                $new_bid_money,
                $this->increment_calculator
            );

            if (!$proxy_bid) {
                // Auto-bidder lost (bid >= maximum)
                $this->repository->update(
                    $auto_bid['auto_bid_id'],
                    ['status' => AutoBidStatus::LOST]
                );

                $this->repository->recordHistory([
                    'auto_bid_id'    => $auto_bid['auto_bid_id'],
                    'auction_id'     => $auction_id,
                    'user_id'        => $auto_bid['user_id'],
                    'event_type'     => 'AUTO_BID_LOST',
                    'bid_amount'     => $new_bid['amount'],
                    'outbidden_by_user' => $new_bid['user_id'],
                    'outbidden_by_bid'  => $new_bid['amount'],
                ]);

                $this->logInfo(
                    'Auto-bid lost due to outbid at maximum',
                    [
                        'auto_bid_id' => $auto_bid['auto_bid_id'],
                        'outbidden_by' => $new_bid['amount'],
                    ]
                );
                continue;
            }

            // Queue proxy bid for async processing
            try {
                $job_id = $this->bid_queue->enqueue([
                    'type'              => 'AUTO_BID_PROXY',
                    'auction_id'        => $auction_id,
                    'user_id'           => $auto_bid['user_id'],
                    'auto_bid_id'       => $auto_bid['auto_bid_id'],
                    'bid_amount'        => $proxy_bid->value(),
                    'proxy_for_bid_id'  => $new_bid['id'] ?? null,
                ]);

                // Update auto-bid with proxy bid info
                $this->repository->update(
                    $auto_bid['auto_bid_id'],
                    [
                        'proxy_bid_amount' => $proxy_bid->value(),
                        'current_bid'      => $proxy_bid->value(),
                    ]
                );

                // Record history
                $this->repository->recordHistory([
                    'auto_bid_id'  => $auto_bid['auto_bid_id'],
                    'auction_id'   => $auction_id,
                    'user_id'      => $auto_bid['user_id'],
                    'event_type'   => 'PROXY_BID_QUEUED',
                    'bid_amount'   => $proxy_bid->value(),
                    'proxy_action' => 'COUNTER_BID',
                    'event_data'   => ['job_id' => $job_id],
                ]);

                $bid_placed = true;

                $this->logDebug(
                    'Proxy bid queued',
                    [
                        'auto_bid_id'  => $auto_bid['auto_bid_id'],
                        'job_id'       => $job_id,
                        'proxy_bid'    => $proxy_bid->value(),
                    ]
                );
            } catch (\Exception $e) {
                $this->logError(
                    'Failed to queue proxy bid',
                    ['error' => $e->getMessage(), 'auto_bid_id' => $auto_bid['auto_bid_id']]
                );
            }
        }

        return $bid_placed;
    }

    /**
     * Get auto-bid details.
     *
     * @param string $auto_bid_id Auto-bid ID
     * @return array|null Auto-bid data
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function getAutoBid(string $auto_bid_id): ?array
    {
        return $this->repository->getById($auto_bid_id);
    }

    /**
     * Get user's auto-bids.
     *
     * @param int $user_id User ID
     * @param array $statuses Statuses to filter (default: all)
     * @return array Array of user's auto-bids
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function getUserAutoBids(int $user_id, array $statuses = []): array
    {
        return $this->repository->getForUser($user_id, $statuses);
    }

    /**
     * Get auto-bid history.
     *
     * @param string $auto_bid_id Auto-bid ID
     * @param int $limit Number of events to return
     * @return array History events
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function getHistory(string $auto_bid_id, int $limit = 50): array
    {
        return $this->repository->getHistory($auto_bid_id, $limit);
    }
}
