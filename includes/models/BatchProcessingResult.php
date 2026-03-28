<?php
/**
 * Batch Processing Result - Immutable result value object for batch processing outcomes
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    4.0.0
 * @requirement REQ-4D-044: Provide batch processing result encapsulation
 */

namespace WC\Auction\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BatchProcessingResult - Immutable result from batch processing
 *
 * Encapsulates the outcome of processing a settlement batch.
 *
 * @requirement REQ-4D-044: Encapsulate batch processing outcomes
 */
class BatchProcessingResult {

    /**
     * Result status: success
     */
    const STATUS_SUCCESS = 'SUCCESS';

    /**
     * Result status: partial (some failures)
     */
    const STATUS_PARTIAL = 'PARTIAL';

    /**
     * Result status: failed
     */
    const STATUS_FAILED = 'FAILED';

    /**
     * Result status: skipped (already processing or other guard condition)
     */
    const STATUS_SKIPPED = 'SKIPPED';

    /**
     * Batch ID
     *
     * @var int
     */
    private $batch_id;

    /**
     * Number of payouts processed successfully
     *
     * @var int
     */
    private $processed;

    /**
     * Number of payouts that failed
     *
     * @var int
     */
    private $failed;

    /**
     * Total payouts in batch
     *
     * @var int
     */
    private $total;

    /**
     * Processing duration in seconds
     *
     * @var float
     */
    private $duration_seconds;

    /**
     * Result status
     *
     * @var string
     */
    private $status;

    /**
     * Error message (if any)
     *
     * @var string|null
     */
    private $error_message;

    /**
     * Constructor (private - use factory methods)
     *
     * @param int    $batch_id Batch ID
     * @param int    $processed Payouts processed
     * @param int    $failed Payouts failed
     * @param int    $total Total payouts
     * @param float  $duration_seconds Processing time
     * @param string $status Result status
     * @param string|null $error_message Error message
     */
    private function __construct(
        int $batch_id,
        int $processed,
        int $failed,
        int $total,
        float $duration_seconds,
        string $status,
        ?string $error_message = null
    ) {
        $this->batch_id           = $batch_id;
        $this->processed          = $processed;
        $this->failed             = $failed;
        $this->total              = $total;
        $this->duration_seconds   = $duration_seconds;
        $this->status             = $status;
        $this->error_message      = $error_message;
    }

    /**
     * Create success result
     *
     * @param int   $batch_id Batch ID
     * @param int   $processed Processed count
     * @param int   $failed Failed count
     * @param int   $total Total count
     * @param float $duration_seconds Duration
     * @return BatchProcessingResult
     */
    public static function createSuccess(
        int $batch_id,
        int $processed,
        int $failed,
        int $total,
        float $duration_seconds
    ): self {
        return new self( $batch_id, $processed, $failed, $total, $duration_seconds, self::STATUS_SUCCESS );
    }

    /**
     * Create partial result (some failures)
     *
     * @param int   $batch_id Batch ID
     * @param int   $processed Processed count
     * @param int   $failed Failed count
     * @param int   $total Total count
     * @param float $duration_seconds Duration
     * @return BatchProcessingResult
     */
    public static function createPartial(
        int $batch_id,
        int $processed,
        int $failed,
        int $total,
        float $duration_seconds
    ): self {
        return new self( $batch_id, $processed, $failed, $total, $duration_seconds, self::STATUS_PARTIAL );
    }

    /**
     * Create failed result
     *
     * @param int    $batch_id Batch ID
     * @param int    $processed Processed count
     * @param int    $failed Failed count
     * @param int    $total Total count
     * @param string $error_message Error message
     * @return BatchProcessingResult
     */
    public static function createFailed(
        int $batch_id,
        int $processed,
        int $failed,
        int $total,
        string $error_message = ''
    ): self {
        return new self( $batch_id, $processed, $failed, $total, 0.0, self::STATUS_FAILED, $error_message );
    }

    /**
     * Create skipped result
     *
     * @param int    $batch_id Batch ID
     * @param int    $processed Processed count
     * @param int    $failed Failed count
     * @param int    $total Total count
     * @param string $reason Reason for skipping
     * @return BatchProcessingResult
     */
    public static function createSkipped(
        int $batch_id,
        int $processed,
        int $failed,
        int $total,
        string $reason = ''
    ): self {
        return new self( $batch_id, $processed, $failed, $total, 0.0, self::STATUS_SKIPPED, $reason );
    }

    /**
     * Check if result is success
     *
     * @return bool
     */
    public function isSuccess(): bool {
        return self::STATUS_SUCCESS === $this->status;
    }

    /**
     * Check if result is partial (some failures)
     *
     * @return bool
     */
    public function isPartial(): bool {
        return self::STATUS_PARTIAL === $this->status;
    }

    /**
     * Check if result is failed
     *
     * @return bool
     */
    public function isFailed(): bool {
        return self::STATUS_FAILED === $this->status;
    }

    /**
     * Check if result is skipped
     *
     * @return bool
     */
    public function isSkipped(): bool {
        return self::STATUS_SKIPPED === $this->status;
    }

    // ===== Getters =====

    /**
     * Get batch ID
     *
     * @return int
     */
    public function getBatchId(): int {
        return $this->batch_id;
    }

    /**
     * Get processed count
     *
     * @return int
     */
    public function getProcessed(): int {
        return $this->processed;
    }

    /**
     * Get failed count
     *
     * @return int
     */
    public function getFailed(): int {
        return $this->failed;
    }

    /**
     * Get total count
     *
     * @return int
     */
    public function getTotal(): int {
        return $this->total;
    }

    /**
     * Get duration in seconds
     *
     * @return float
     */
    public function getDurationSeconds(): float {
        return $this->duration_seconds;
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->status;
    }

    /**
     * Get error message
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string {
        return $this->error_message;
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'batch_id'    => $this->batch_id,
            'processed'   => $this->processed,
            'failed'      => $this->failed,
            'total'       => $this->total,
            'duration'    => round( $this->duration_seconds, 2 ),
            'status'      => $this->status,
            'error'       => $this->error_message,
        ];
    }
}
