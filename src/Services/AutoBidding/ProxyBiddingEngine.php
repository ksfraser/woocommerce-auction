<?php

namespace Yith\Auctions\Services\AutoBidding;

use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;
use Yith\Auctions\ValueObjects\Money;

/**
 * ProxyBiddingEngine - Core proxy bidding algorithm.
 *
 * Implements the proxy bidding algorithm (eBay-style). When a bid is placed
 * on an auction with an auto-bid:
 *
 * 1. Determine the current auto-bid maximum
 * 2. Determine the increment for the current bid level
 * 3. Place a proxy bid at (current_bid + increment)
 * 4. If proxy bid < max, user wins; if not, auto-bidder wins at (max + increment)
 *
 * This engine is stateless - it calculates bids but does not store state.
 *
 * @package Yith\Auctions\Services\AutoBidding
 * @requirement REQ-AUTO-BID-PROXY-001: Proxy bidding algorithm implementation
 * @requirement REQ-AUTO-BID-CONCURRENCY-001: Handle concurrent bid situations
 *
 * Algorithm Overview:
 *
 * ```
 * When a new bid B arrives on an auction with auto-bid A:
 *
 * if B < A.max_bid:
 *     proxy_bid = min(B + increment(B), A.max_bid)
 *     if proxy_bid > B:
 *         Place proxy bid at proxy_bid
 *         A wins with new current_bid = proxy_bid
 *         B loses
 *     else:
 *         B wins (shouldn't happen in normal case)
 *
 * else if B >= A.max_bid:
 *     A loses
 *     auto_bid.status = LOST
 * ```
 */
class ProxyBiddingEngine
{
    use LoggerTrait;
    use ValidationTrait;

    /**
     * Calculate proxy bid for auto-bidder when outbid.
     *
     * Places a counter-bid using the proxy bidding algorithm.
     * The result is the maximum the auto-bidder is willing to get
     * ("sniped" at), rounded to the next increment.
     *
     * @param Money $auto_bid_maximum User's maximum auto-bid
     * @param Money $new_incoming_bid The bid that outbid us
     * @param callable $increment_calculator Callable to get increment for bid level
     * @return Money|null Proxy bid amount, or null if auto-bidder lost
     * @throws \InvalidArgumentException If inputs invalid
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function calculateProxyBid(
        Money $auto_bid_maximum,
        Money $new_incoming_bid,
        callable $increment_calculator
    ): ?Money {
        // Validate inputs
        $this->validateRequired($auto_bid_maximum, 'auto_bid_maximum');
        $this->validateRequired($new_incoming_bid, 'new_incoming_bid');

        $incoming_float = $new_incoming_bid->asFloat();
        $maximum_float = $auto_bid_maximum->asFloat();

        // Auto-bidder lost if outbid at or above their maximum
        if ($incoming_float >= $maximum_float) {
            $this->logInfo(
                'Auto-bidder lost: incoming bid >= maximum',
                [
                    'incoming_bid' => $incoming_float,
                    'auto_bid_max'  => $maximum_float,
                ]
            );
            return null;
        }

        // Calculate proxy bid: incoming bid + next increment
        $increment = $increment_calculator($incoming_float);
        $proxy_bid_float = $incoming_float + $increment;

        // Cap proxy bid at auto-bidder's maximum
        $proxy_bid_float = min($proxy_bid_float, $maximum_float);

        // If proxy bid still less than incoming, something is wrong with increment
        if ($proxy_bid_float <= $incoming_float) {
            $this->logWarning(
                'Proxy bid calculation resulted in non-increment',
                [
                    'incoming'     => $incoming_float,
                    'increment'    => $increment,
                    'calculated'   => $proxy_bid_float,
                ]
            );
            // Fallback: just add minimum increment
            $proxy_bid_float = $incoming_float + 0.01;
            $proxy_bid_float = min($proxy_bid_float, $maximum_float);
        }

        $proxy_bid = Money::fromFloat($proxy_bid_float);

        $this->logDebug(
            'Calculated proxy bid',
            [
                'incoming_bid'      => $incoming_float,
                'auto_bid_maximum'  => $maximum_float,
                'increment'         => $increment,
                'proxy_bid'         => $proxy_bid_float,
            ]
        );

        return $proxy_bid;
    }

    /**
     * Determine if proxy bid wins the auction.
     *
     * @param Money $proxy_bid The proxy bid amount
     * @param Money $competing_bid The competing bid amount
     * @return bool True if proxy bid wins
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function doesProxyBidWin(Money $proxy_bid, Money $competing_bid): bool
    {
        return $proxy_bid->greaterThan($competing_bid);
    }

    /**
     * Determine winning bid amount after proxy bidding.
     *
     * If auto-bidder wins, winning bid is the second-highest proxy bid plus increment.
     * If competitor wins, winning bid is their bid (but auto-bidder could have gone higher).
     *
     * @param Money $auto_bid_maximum Auto-bidder's maximum
     * @param Money $competing_bid Competing bid
     * @param Money $proxy_bid The proxy bid calculated
     * @param callable $increment_calculator Callable to get increment
     * @return Money The actual winning bid amount
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function determineWinningBid(
        Money $auto_bid_maximum,
        Money $competing_bid,
        Money $proxy_bid,
        callable $increment_calculator
    ): Money {
        // If proxy bid wins, winning amount is competing bid + increment
        if ($this->doesProxyBidWin($proxy_bid, $competing_bid)) {
            $increment = $increment_calculator($competing_bid->asFloat());
            return $competing_bid->add(Money::fromFloat($increment));
        }

        // Competing bid wins
        return $competing_bid;
    }

    /**
     * Check if auto-bid should place counter-bid.
     *
     * Considers various factors:
     * - Auto-bid is active
     * - Auction is still open
     * - Maximum hasn't been reached
     * - Bid is from different user than auto-bidder
     *
     * @param array $auto_bid Auto-bid config
     * @param array $new_bid New incoming bid
     * @param string $auction_status Current auction status
     * @return bool True if should place proxy bid
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function shouldPlaceCounterBid(
        array $auto_bid,
        array $new_bid,
        string $auction_status
    ): bool {
        // Check auto-bid is active
        if ($auto_bid['status'] !== 'ACTIVE') {
            return false;
        }

        // Check auction is still open for bidding
        if (!in_array($auction_status, ['ACTIVE', 'ENDING_SOON', 'EXTENDED'])) {
            return false;
        }

        // Check bid is from different user (not auto-bidder themselves)
        if ((int)$new_bid['user_id'] === (int)$auto_bid['user_id']) {
            // User is bidding against themselves - normally shouldn't happen
            // but if it does, don't place counter bid
            return false;
        }

        // Check auto-bidder hasn't been outbid beyond their maximum
        $new_bid_amount = (float)$new_bid['amount'];
        $auto_bid_max = (float)$auto_bid['maximum_bid'];

        return $new_bid_amount < $auto_bid_max;
    }

    /**
     * Simulate a bidding scenario for testing/analysis.
     *
     * Returns what would happen with given bids and auto-bid configuration.
     *
     * @param Money $auto_bid_maximum Auto-bidder's maximum
     * @param Money[] $bids Sequence of bids (in order)
     * @param callable $increment_calculator Callable to get increment
     * @return array Simulation result with winner and winning bid
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function simulateBidding(
        Money $auto_bid_maximum,
        array $bids,
        callable $increment_calculator
    ): array {
        $current_winning_bid = Money::fromFloat(0.00);
        $current_winner = null;
        $is_auto_bidder_winning = false;

        foreach ($bids as $bid) {
            if ($bid->lessThan($auto_bid_maximum) && $bid->greaterThan($current_winning_bid)) {
                $proxy = $this->calculateProxyBid($auto_bid_maximum, $bid, $increment_calculator);
                
                if ($proxy && $this->doesProxyBidWin($proxy, $bid)) {
                    $current_winning_bid = $this->determineWinningBid(
                        $auto_bid_maximum,
                        $bid,
                        $proxy,
                        $increment_calculator
                    );
                    $is_auto_bidder_winning = true;
                    $current_winner = 'auto_bidder';
                } else {
                    $current_winning_bid = $bid;
                    $is_auto_bidder_winning = false;
                    $current_winner = 'manual_bidder';
                }
            }
        }

        return [
            'winner'          => $current_winner,
            'winning_bid'     => $current_winning_bid->value(),
            'auto_bid_wins'   => $is_auto_bidder_winning,
            'maximum_reached' => $current_winning_bid->equals($auto_bid_maximum)
                                 || $current_winning_bid->greaterThan($auto_bid_maximum),
        ];
    }
}
