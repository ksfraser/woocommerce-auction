<?php

namespace Yith\Auctions\ValueObjects;

/**
 * AuctionStatus - Enumeration of auction lifecycle states.
 *
 * Defines all valid states an auction can have. Used to enforce
 * valid state transitions and prevent invalid operations.
 *
 * @package Yith\Auctions\ValueObjects
 * @requirement REQ-DOMAIN-AUCTION-STATUS-001: Typed auction status enum
 */
final class AuctionStatus
{
    public const UPCOMING = 'UPCOMING';
    public const ACTIVE = 'ACTIVE';
    public const PAUSED = 'PAUSED';
    public const ENDING_SOON = 'ENDING_SOON';
    public const EXTENDED = 'EXTENDED';
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';

    /**
     * All valid statuses.
     *
     * @var string[]
     */
    public const ALL = [
        self::UPCOMING,
        self::ACTIVE,
        self::PAUSED,
        self::ENDING_SOON,
        self::EXTENDED,
        self::COMPLETED,
        self::FAILED,
        self::CANCELLED,
    ];

    /**
     * Terminal statuses (no further transitions allowed).
     *
     * @var string[]
     */
    public const TERMINAL = [
        self::COMPLETED,
        self::FAILED,
        self::CANCELLED,
    ];

    /**
     * Verify a status is valid.
     *
     * @param string $status Status to verify
     * @return bool True if valid
     * @requirement REQ-DOMAIN-AUCTION-STATUS-001
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /**
     * Check if status is terminal.
     *
     * @param string $status Status to check
     * @return bool True if terminal
     * @requirement REQ-DOMAIN-AUCTION-STATUS-001
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * Check if status is active (bidding allowed).
     *
     * @param string $status Status to check
     * @return bool True if bidding allowed
     * @requirement REQ-DOMAIN-AUCTION-STATUS-001
     */
    public static function isBiddingAllowed(string $status): bool
    {
        return in_array($status, [self::ACTIVE, self::ENDING_SOON, self::EXTENDED], true);
    }
}
