<?php

namespace Yith\Auctions\ValueObjects;

/**
 * AutoBidStatus - Enumeration of auto-bid lifecycle states.
 *
 * Defines all valid states an auto-bid configuration can have.
 *
 * @package Yith\Auctions\ValueObjects
 * @requirement REQ-AUTO-BID-STATUS-001: Auto-bid status enumeration
 */
final class AutoBidStatus
{
    public const ACTIVE = 'ACTIVE';
    public const PAUSED = 'PAUSED';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';
    public const EXPIRED = 'EXPIRED';
    public const LOST = 'LOST';

    /**
     * All valid statuses.
     *
     * @var string[]
     */
    public const ALL = [
        self::ACTIVE,
        self::PAUSED,
        self::COMPLETED,
        self::CANCELLED,
        self::EXPIRED,
        self::LOST,
    ];

    /**
     * Terminal statuses (no further transitions allowed).
     *
     * @var string[]
     */
    public const TERMINAL = [
        self::COMPLETED,
        self::CANCELLED,
        self::EXPIRED,
        self::LOST,
    ];

    /**
     * Verify a status is valid.
     *
     * @param string $status Status to verify
     * @return bool True if valid
     * @requirement REQ-AUTO-BID-STATUS-001
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
     * @requirement REQ-AUTO-BID-STATUS-001
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * Check if auto-bidding is active.
     *
     * @param string $status Status to check
     * @return bool True if bids can be placed
     * @requirement REQ-AUTO-BID-STATUS-001
     */
    public static function isBiddingActive(string $status): bool
    {
        return in_array($status, [self::ACTIVE, self::PAUSED], true);
    }
}
