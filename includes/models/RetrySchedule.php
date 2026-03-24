<?php
/**
 * RetrySchedule Model
 *
 * Immutable value object representing a scheduled retry for a failed payout.
 * Tracks failure count, next retry time, and error messages.
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    1.0.0
 * @requirement REQ-4D-039: Retry scheduling with exponential backoff
 */

namespace WC\Auction\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RetrySchedule - Immutable value object for payout retry scheduling
 *
 * UML Class Diagram:
 * ```
 * RetrySchedule (Value Object)
 * ├── Properties:
 * │   ├── id: int|null
 * │   ├── payout_id: int
 * │   ├── failure_count: int (0-6)
 * │   ├── next_retry_time: DateTime|null
 * │   ├── last_error_message: string|null
 * │   └── created_at: DateTime
 * ├── Factory Methods:
 * │   ├── create(payout_id): self
 * │   └── fromDatabase(row): self
 * ├── Getters:
 * │   ├── getId(): int|null
 * │   ├── getPayoutId(): int
 * │   ├── getFailureCount(): int
 * │   ├── getNextRetryTime(): DateTime|null
 * │   ├── getLastErrorMessage(): string|null
 * │   ├── getRemainingSeconds(): int
 * │   └── isRetryDue(): bool
 * ├── Setters:
 * │   ├── setNextRetryTime(DateTime): void
 * │   ├── setErrorMessage(string): void
 * │   └── incrementFailureCount(): void
 * ├── Static Methods:
 * │   ├── getBackoffSeconds(): int[]
 * │   └── MAX_RETRIES: 6
 * └── Serialization:
 *     └── toArray(): array
 * ```
 *
 * Design Pattern: Value Object
 * - Immutable after creation
 * - All setters are intentional and idempotent
 * - Created via factory methods
 * - Serializable to array for persistence
 *
 * Retry Strategy (Exponential Backoff):
 * - Attempt 1: Immediate (0 seconds)
 * - Attempt 2: 5 minutes (300 seconds)
 * - Attempt 3: 30 minutes (1800 seconds)
 * - Attempt 4: 2 hours (7200 seconds)
 * - Attempt 5: 8 hours (28800 seconds)
 * - Attempt 6: 24 hours (86400 seconds)
 * - After 6 attempts: Mark permanent failure
 *
 * @requirement REQ-4D-039: Failed payouts automatically retried with exponential backoff
 */
class RetrySchedule {

	/**
	 * Maximum retry attempts before permanent failure
	 */
	const MAX_RETRIES = 6;

	/**
	 * Exponential backoff seconds array
	 * Index = failure attempt number
	 */
	const BACKOFF_SECONDS = [ 0, 300, 1800, 7200, 28800, 86400 ];

	/**
	 * Retry schedule database ID
	 *
	 * @var int|null
	 */
	private $id;

	/**
	 * Payout ID this retry is for
	 *
	 * @var int
	 */
	private $payout_id;

	/**
	 * Number of failures/retry attempts
	 *
	 * @var int
	 */
	private $failure_count = 0;

	/**
	 * DateTime of next retry
	 *
	 * @var \DateTime|null
	 */
	private $next_retry_time;

	/**
	 * Last error message from adapter
	 *
	 * @var string|null
	 */
	private $last_error_message;

	/**
	 * When this retry schedule was created
	 *
	 * @var \DateTime
	 */
	private $created_at;

	/**
	 * Constructor
	 *
	 * @param int|null      $id                  Database ID or null for new
	 * @param int           $payout_id           Payout ID
	 * @param int           $failure_count       Failure count (0-6)
	 * @param \DateTime|null $next_retry_time    Next retry time
	 * @param string|null   $last_error_message Error message
	 * @param \DateTime|null $created_at         Creation timestamp
	 */
	private function __construct(
		?int $id,
		int $payout_id,
		int $failure_count = 0,
		?\DateTime $next_retry_time = null,
		?string $last_error_message = null,
		?\DateTime $created_at = null
	) {
		$this->id                   = $id;
		$this->payout_id            = $payout_id;
		$this->failure_count        = $failure_count;
		$this->next_retry_time      = $next_retry_time;
		$this->last_error_message   = $last_error_message;
		$this->created_at           = $created_at ?? new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Create new retry schedule for payout
	 *
	 * @param int $payout_id Payout ID
	 * @return self New retry schedule
	 */
	public static function create( int $payout_id ): self {
		return new self(
			null,
			$payout_id,
			0,
			null,
			null,
			new \DateTime( 'now', new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Restore retry schedule from database row
	 *
	 * @param array $row Database row
	 * @return self Restored retry schedule
	 */
	public static function fromDatabase( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['payout_id'],
			(int) $row['failure_count'],
			$row['next_retry_time'] ? new \DateTime( $row['next_retry_time'], new \DateTimeZone( 'UTC' ) ) : null,
			$row['last_error_message'] ?? null,
			new \DateTime( $row['created_at'], new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Get retry schedule ID
	 *
	 * @return int|null Database ID or null if not saved
	 */
	public function getId(): ?int {
		return $this->id;
	}

	/**
	 * Get payout ID
	 *
	 * @return int Payout ID
	 */
	public function getPayoutId(): int {
		return $this->payout_id;
	}

	/**
	 * Get failure/retry count
	 *
	 * @return int Failure count (0-6)
	 */
	public function getFailureCount(): int {
		return $this->failure_count;
	}

	/**
	 * Get next retry time
	 *
	 * @return \DateTime|null Next retry datetime or null
	 */
	public function getNextRetryTime(): ?\DateTime {
		return $this->next_retry_time;
	}

	/**
	 * Get last error message
	 *
	 * @return string|null Error message or null
	 */
	public function getLastErrorMessage(): ?string {
		return $this->last_error_message;
	}

	/**
	 * Get creation timestamp
	 *
	 * @return \DateTime Creation time
	 */
	public function getCreatedAt(): \DateTime {
		return $this->created_at;
	}

	/**
	 * Increment failure count
	 *
	 * @return void
	 */
	public function incrementFailureCount(): void {
		$this->failure_count ++;
	}

	/**
	 * Set next retry time
	 *
	 * @param \DateTime $datetime Retry datetime
	 * @return void
	 */
	public function setNextRetryTime( \DateTime $datetime ): void {
		$this->next_retry_time = $datetime;
	}

	/**
	 * Set error message
	 *
	 * @param string $message Error description
	 * @return void
	 */
	public function setErrorMessage( string $message ): void {
		$this->last_error_message = $message;
	}

	/**
	 * Set database ID after save
	 *
	 * @param int $id Database ID
	 * @return void
	 */
	public function setId( int $id ): void {
		if ( null === $this->id ) {
			$this->id = $id;
		}
	}

	/**
	 * Check if retry is due (next_retry_time has passed)
	 *
	 * @return bool True if retry is due
	 */
	public function isRetryDue(): bool {
		if ( null === $this->next_retry_time ) {
			return false;
		}
		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		return $now >= $this->next_retry_time;
	}

	/**
	 * Get remaining seconds until retry is due
	 *
	 * Returns negative if past due, 0 if no retry scheduled
	 *
	 * @return int Seconds until retry, or 0 if no retry
	 */
	public function getRemainingSeconds(): int {
		if ( null === $this->next_retry_time ) {
			return 0;
		}
		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$diff = $this->next_retry_time->getTimestamp() - $now->getTimestamp();
		return $diff;
	}

	/**
	 * Check if max retries exceeded
	 *
	 * @return bool True if failure_count >= MAX_RETRIES
	 */
	public function hasExceededMaxRetries(): bool {
		return $this->failure_count >= self::MAX_RETRIES;
	}

	/**
	 * Get exponential backoff array
	 *
	 * @return int[] Backoff seconds for each retry attempt
	 */
	public static function getBackoffSeconds(): array {
		return self::BACKOFF_SECONDS;
	}

	/**
	 * Calculate next retry time based on failure count
	 *
	 * Uses exponential backoff: [0, 5m, 30m, 2h, 8h, 24h]
	 *
	 * @return \DateTime Next retry time
	 */
	public function calculateNextRetryTime(): \DateTime {
		$backoff = self::BACKOFF_SECONDS[ min( $this->failure_count, self::MAX_RETRIES - 1 ) ];
		$next = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$next->modify( "+{$backoff} seconds" );
		return $next;
	}

	/**
	 * Serialize to array for database persistence
	 *
	 * @return array Database-ready array
	 */
	public function toArray(): array {
		return [
			'id'                   => $this->id,
			'payout_id'            => $this->payout_id,
			'failure_count'        => $this->failure_count,
			'next_retry_time'      => $this->next_retry_time ? $this->next_retry_time->format( 'Y-m-d H:i:s' ) : null,
			'last_error_message'   => $this->last_error_message,
			'created_at'           => $this->created_at->format( 'Y-m-d H:i:s' ),
		];
	}
}
