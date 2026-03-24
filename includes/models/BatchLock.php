<?php
/**
 * BatchLock Model
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    1.0.0
 * @requirement REQ-4D-038: Batch processing locks to prevent concurrent execution
 */

namespace WC\Auction\Models;

use DateTime;
use DateTimeZone;

/**
 * Batch Lock Value Object
 *
 * Represents a lock on a batch of payout processes to ensure only one process
 * works on a batch at a time. Locks automatically expire after a timeout period.
 *
 * UML:
 * <pre>
 * ┌─────────────────────────┐
 * │    BatchLock            │
 * ├─────────────────────────┤
 * │ - id: ?int              │
 * │ - batch_id: string      │
 * │ - locked_at: DateTime   │
 * │ - timeout_seconds: int  │
 * ├─────────────────────────┤
 * │ + create()              │
 * │ + fromDatabase()        │
 * │ + isExpired()           │
 * │ + isLocked()            │
 * │ + refresh()             │
 * │ + toArray()             │
 * └─────────────────────────┘
 * </pre>
 *
 * @covers REQ-4D-038: Batch processing locks
 */
class BatchLock {

	/**
	 * Lock ID (nullable until database persistence)
	 *
	 * @var ?int
	 */
	private $id;

	/**
	 * Batch identifier (e.g., 'payout-batch', 'settlement-batch')
	 *
	 * @var string
	 */
	private $batch_id;

	/**
	 * When lock was acquired (UTC)
	 *
	 * @var DateTime
	 */
	private $locked_at;

	/**
	 * Lock timeout in seconds
	 *
	 * @var int
	 */
	private $timeout_seconds;

	/**
	 * Create new batch lock
	 *
	 * Factory method to create a lock at current time.
	 *
	 * @param string $batch_id Batch identifier
	 * @param int    $timeout_seconds Lock timeout in seconds
	 *
	 * @return self New batch lock with current time
	 */
	public static function create( string $batch_id, int $timeout_seconds ): self {
		$lock = new self();
		$lock->batch_id         = $batch_id;
		$lock->timeout_seconds  = $timeout_seconds;
		$lock->locked_at        = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$lock->id               = null;
		return $lock;
	}

	/**
	 * Restore batch lock from database row
	 *
	 * @param array $row Database row with id, batch_id, locked_at, timeout_seconds
	 *
	 * @return self Restored batch lock
	 *
	 * @throws \InvalidArgumentException If row missing required fields
	 */
	public static function fromDatabase( array $row ): self {
		if ( ! isset( $row['batch_id'], $row['locked_at'], $row['timeout_seconds'] ) ) {
			throw new \InvalidArgumentException( 'Missing required fields: batch_id, locked_at, timeout_seconds' );
		}

		$lock = new self();
		$lock->id              = $row['id'] ?? null;
		$lock->batch_id        = $row['batch_id'];
		$lock->timeout_seconds = $row['timeout_seconds'];
		$lock->locked_at       = new DateTime( $row['locked_at'], new DateTimeZone( 'UTC' ) );

		return $lock;
	}

	/**
	 * Get lock ID
	 *
	 * @return ?int Lock ID or null if not persisted
	 */
	public function getId(): ?int {
		return $this->id;
	}

	/**
	 * Get batch ID
	 *
	 * @return string Batch identifier
	 */
	public function getBatchId(): string {
		return $this->batch_id;
	}

	/**
	 * Get locked at time
	 *
	 * @return DateTime When lock was acquired
	 */
	public function getLockedAt(): DateTime {
		return $this->locked_at;
	}

	/**
	 * Get timeout in seconds
	 *
	 * @return int Timeout duration
	 */
	public function getTimeoutSeconds(): int {
		return $this->timeout_seconds;
	}

	/**
	 * Check if lock has expired
	 *
	 * @return bool True if lock is past its timeout
	 */
	public function isExpired(): bool {
		$now           = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$expiration    = clone $this->locked_at;
		$expiration    = $expiration->modify( "+{$this->timeout_seconds} seconds" );
		return $now >= $expiration;
	}

	/**
	 * Check if lock is actively held
	 *
	 * Lock is held if not expired.
	 *
	 * @return bool True if lock is still valid
	 */
	public function isLocked(): bool {
		return ! $this->isExpired();
	}

	/**
	 * Refresh lock by resetting locked_at to current time
	 *
	 * Extends the lock timeout by resetting the acquisition time.
	 *
	 * @return self Self for chaining
	 */
	public function refresh(): self {
		$this->locked_at = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		return $this;
	}

	/**
	 * Serialize to database array
	 *
	 * @return array Database-ready format with Y-m-d H:i:s datetime strings
	 */
	public function toArray(): array {
		return array(
			'batch_id'        => $this->batch_id,
			'locked_at'       => $this->locked_at->format( 'Y-m-d H:i:s' ),
			'timeout_seconds' => $this->timeout_seconds,
		);
	}

	/**
	 * Set locked at time
	 *
	 * @param DateTime $locked_at Lock acquisition time
	 *
	 * @return self Self for chaining
	 */
	public function setLockedAt( DateTime $locked_at ): self {
		$this->locked_at = $locked_at;
		return $this;
	}

	/**
	 * Set timeout in seconds
	 *
	 * @param int $timeout_seconds Timeout duration
	 *
	 * @return self Self for chaining
	 */
	public function setTimeoutSeconds( int $timeout_seconds ): self {
		$this->timeout_seconds = $timeout_seconds;
		return $this;
	}
}
