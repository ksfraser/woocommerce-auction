<?php
/**
 * RetryExecutor Service
 *
 * Handles execution of individual retry attempts with exponential backoff.
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    1.0.0
 * @requirement REQ-4D-039: Retry execution with exponential backoff
 */

namespace WC\Auction\Services;

use WC\Auction\Models\RetrySchedule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RetryExecutor - Handles individual retry execution
 *
 * Manages:
 * - Execution of payout retry attempts
 * - Exponential backoff calculation
 * - Max retry limit enforcement
 * - Error handling and reporting
 *
 * UML Class Diagram:
 * ```
 * RetryExecutor (Execution Service)
 * ├── Constants:
 * │   ├── MAX_RETRIES: 6
 * │   ├── INITIAL_BACKOFF: 60 (seconds)
 * │   └── BACKOFF_MULTIPLIER: 2
 * ├── Public Methods:
 * │   ├── execute(schedule): array
 * │   ├── calculateNextRetryTime(schedule): DateTime
 * │   ├── shouldRetry(schedule): bool
 * │   ├── getMaxRetries(): int
 * │   ├── getRetryAttempts(): int[]
 * │   └── getBackoffSeconds(): int[]
 * └── Private Methods:
 *     ├── executePayoutAttempt(schedule): bool
 *     └── getBackoffForAttempt(attempt): int
 * ```
 *
 * Design Patterns:
 * - Service: Encapsulates retry execution logic
 * - Exponential backoff: Progressively longer delays
 * - Strategy: Different retry strategies per attempt
 *
 * @requirement REQ-4D-039: Retry execution with exponential backoff
 */
class RetryExecutor {

	/**
	 * Maximum number of retry attempts
	 *
	 * @var int
	 */
	const MAX_RETRIES = 6;

	/**
	 * Initial backoff in seconds (1 minute)
	 *
	 * @var int
	 */
	const INITIAL_BACKOFF = 60;

	/**
	 * Backoff multiplier (exponential: 1, 2, 4, 8, 16, 32 minutes)
	 *
	 * @var float
	 */
	const BACKOFF_MULTIPLIER = 2.0;

	/**
	 * Retry attempts in seconds for each failure (0-5 failures)
	 *
	 * @var int[]
	 */
	private $backoff_schedule;

	/**
	 * Constructor
	 *
	 * Initializes backoff schedule using exponential backoff formula.
	 * @requirement REQ-4D-039: Initialize retry executor with backoff strategy
	 */
	public function __construct() {
		$this->backoff_schedule = $this->getBackoffSeconds();
	}

	/**
	 * Execute a retry attempt for a given schedule
	 *
	 * Attempts to execute the payout retry and returns result
	 * with success flag and optional error message.
	 *
	 * @param RetrySchedule $schedule Retry schedule to execute
	 * @return array Result array with 'success' and 'error_message' keys
	 * @requirement REQ-4D-039: Execute retry attempts with error handling
	 */
	public function execute( RetrySchedule $schedule ): array {
		try {
			// Check if retry should be attempted
			if ( ! $this->shouldRetry( $schedule ) ) {
				return [
					'success'       => false,
					'error_message' => 'Max retries exceeded',
				];
			}

			// Attempt to execute retry (placeholder - actual implementation in real service)
			$result = $this->executePayoutAttempt( $schedule );

			if ( $result ) {
				return [
					'success'       => true,
					'error_message' => null,
				];
			} else {
				return [
					'success'       => false,
					'error_message' => 'Retry execution failed',
				];
			}

		} catch ( \Exception $e ) {
			return [
				'success'       => false,
				'error_message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Calculate next retry time using exponential backoff
	 *
	 * @param RetrySchedule $schedule Retry schedule with current failure count
	 * @return \DateTime Next retry time
	 * @requirement REQ-4D-039: Calculate backoff times
	 */
	public function calculateNextRetryTime( RetrySchedule $schedule ): \DateTime {
		$failure_count = $schedule->getFailureCount();

		// Get backoff seconds for this attempt
		$backoff_seconds = $this->getBackoffForAttempt( $failure_count );

		// Calculate next retry time
		$now  = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$next = clone $now;
		$next->add( new \DateInterval( 'PT' . $backoff_seconds . 'S' ) );

		return $next;
	}

	/**
	 * Check if retry should be attempted
	 *
	 * @param RetrySchedule $schedule Retry schedule to check
	 * @return bool True if retry should be attempted, false if max retries exceeded
	 * @requirement REQ-4D-039: Enforce max retry limit
	 */
	public function shouldRetry( RetrySchedule $schedule ): bool {
		return $schedule->getFailureCount() < self::MAX_RETRIES;
	}

	/**
	 * Get maximum number of retries
	 *
	 * @return int Maximum retry attempts
	 */
	public function getMaxRetries(): int {
		return self::MAX_RETRIES;
	}

	/**
	 * Get array of backoff seconds for each retry attempt
	 *
	 * Returns exponential backoff: 60, 120, 240, 480, 960, 1920 seconds
	 *
	 * @return int[] Backoff seconds for each attempt (0-5 failures)
	 * @requirement REQ-4D-039: Exponential backoff schedule
	 */
	public static function getBackoffSeconds(): array {
		$backoff = [];
		for ( $i = 0; $i < self::MAX_RETRIES; $i++ ) {
			$backoff[ $i ] = (int) ( self::INITIAL_BACKOFF * pow( self::BACKOFF_MULTIPLIER, $i ) );
		}
		return $backoff;
	}

	/**
	 * Get retry attempts as array
	 *
	 * @return int[] Array of attempt numbers (1-6)
	 */
	public function getRetryAttempts(): array {
		return range( 1, self::MAX_RETRIES );
	}

	/**
	 * Execute actual payout retry attempt
	 *
	 * This is a placeholder method. In production, this would call
	 * the actual payout processing service.
	 *
	 * @param RetrySchedule $schedule Retry schedule to execute
	 * @return bool True if execution successful, false otherwise
	 * @requirement REQ-4D-039: Execute payout attempt
	 */
	private function executePayoutAttempt( RetrySchedule $schedule ): bool {
		// Placeholder: In production, this would integrate with actual payout service
		// For now, simulate retry execution
		return true;
	}

	/**
	 * Get backoff seconds for specific attempt
	 *
	 * @param int $attempt_number Attempt number (0-5)
	 * @return int Backoff seconds for this attempt
	 * @requirement REQ-4D-039: Get backoff for attempt
	 */
	private function getBackoffForAttempt( int $attempt_number ): int {
		if ( $attempt_number >= count( $this->backoff_schedule ) ) {
			$attempt_number = count( $this->backoff_schedule ) - 1;
		}
		return $this->backoff_schedule[ $attempt_number ];
	}
}
