<?php
/**
 * Job Status Constants
 * 
 * Defines all possible states for a queued job throughout its lifecycle
 * 
 * @package WC\Auction\Services\Queue
 */

namespace WC\Auction\Services\Queue;

/**
 * Job status enumeration
 * 
 * States:
 *   PENDING -> PROCESSING -> COMPLETED
 *   PENDING -> PROCESSING -> FAILED -> PENDING (retry)
 *   PENDING -> PROCESSING -> FAILED -> DEAD_LETTER (max retries exceeded)
 */
class JobStatus
{
    /**
     * Job is queued and waiting to be processed
     */
    const PENDING = 'PENDING';

    /**
     * Job is currently being processed by a worker
     */
    const PROCESSING = 'PROCESSING';

    /**
     * Job completed successfully
     */
    const COMPLETED = 'COMPLETED';

    /**
     * Job failed during processing
     */
    const FAILED = 'FAILED';

    /**
     * Job failed and exceeded max retries - moved to dead letter
     */
    const DEAD_LETTER = 'DEAD_LETTER';

    /**
     * Validate job status
     * 
     * @param string $status
     * @return bool
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
            self::DEAD_LETTER,
        ]);
    }

    /**
     * Get all valid statuses
     * 
     * @return array
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
            self::DEAD_LETTER,
        ];
    }
}
