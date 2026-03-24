<?php
/**
 * SchedulerService - Core Orchestration Service
 *
 * Coordinates retry scheduling, processing, and batch management.
 * Implements publish-subscribe pattern for event publishing.
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    1.0.0
 * @requirement REQ-4D-041: Scheduler service orchestration
 */

namespace WC\Auction\Services;

use WC\Auction\Models\RetrySchedule;
use WC\Auction\Models\BatchLock;
use WC\Auction\Models\SchedulerConfig;
use WC\Auction\Repositories\RetryScheduleRepository;
use WC\Auction\Repositories\BatchLockRepository;
use WC\Auction\Repositories\SchedulerConfigRepository;
use WC\Auction\Events\RetryScheduleCreatedEvent;
use WC\Auction\Events\RetryScheduleFailedEvent;
use WC\Auction\Events\RetryScheduleSucceededEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SchedulerService - Main orchestration service
 *
 * Coordinates:
 * - Scheduling new retries for failed payouts
 * - Processing due retries in batches
 * - Updating retry states (failed/succeeded)
 * - Managing scheduler configuration
 * - Publishing domain events
 *
 * UML Class Diagram:
 * ```
 * SchedulerService (Orchestration Service)
 * ├── Properties:
 * │   ├── retry_repo: RetryScheduleRepository
 * │   ├── batch_lock_repo: BatchLockRepository
 * │   ├── config_repo: SchedulerConfigRepository
 * │   └── event_publisher: EventPublisher
 * ├── Public Methods:
 * │   ├── scheduleRetry(payout_id): RetrySchedule
 * │   ├── processDueRetries(): RetrySchedule[]
 * │   ├── markRetryFailed(schedule, error): RetrySchedule
 * │   ├── markRetrySucceeded(schedule): bool
 * │   ├── getRetrySchedule(payout_id): RetrySchedule|null
 * │   ├── hasPendingRetries(): bool
 * │   ├── updateConfig(name, value): SchedulerConfig
 * │   └── getConfig(name): string|null
 * └── Dependencies:
 *     ├── RetryScheduleRepository (save, update, delete, find, findDueRetries)
 *     ├── BatchLockRepository (acquireLock, releaseLock)
 *     ├── SchedulerConfigRepository (get, set, getAll)
 *     └── EventPublisher (subscribe, publish)
 * ```
 *
 * Design Patterns:
 * - Dependency Injection: All dependencies injected via constructor
 * - Publisher-Subscriber: Events published via EventPublisher service
 * - Repository Pattern: Data access abstracted
 * - Orchestration: Coordinates multiple services
 *
 * @requirement REQ-4D-041: Scheduler service orchestration
 */
class SchedulerService {

	/**
	 * Retry schedule repository
	 *
	 * @var RetryScheduleRepository
	 */
	private $retry_repo;

	/**
	 * Batch lock repository
	 *
	 * @var BatchLockRepository
	 */
	private $batch_lock_repo;

	/**
	 * Scheduler config repository
	 *
	 * @var SchedulerConfigRepository
	 */
	private $config_repo;

	/**
	 * Event publisher service
	 *
	 * @var EventPublisher
	 */
	private $event_publisher;

	/**
	 * Constructor
	 *
	 * @param RetryScheduleRepository   $retry_repo      Retry schedule data access
	 * @param BatchLockRepository       $batch_lock_repo Batch lock data access
	 * @param SchedulerConfigRepository $config_repo     Configuration data access
	 * @param EventPublisher            $event_publisher Event publishing service
	 * @requirement REQ-4D-041: Dependency injection for orchestration
	 */
	public function __construct(
		RetryScheduleRepository $retry_repo,
		BatchLockRepository $batch_lock_repo,
		SchedulerConfigRepository $config_repo,
		EventPublisher $event_publisher
	) {
		$this->retry_repo       = $retry_repo;
		$this->batch_lock_repo  = $batch_lock_repo;
		$this->config_repo      = $config_repo;
		$this->event_publisher  = $event_publisher;
	}

	/**
	 * Schedule a new retry for a failed payout
	 *
	 * Creates a new RetrySchedule model, calculates next retry time,
	 * persists to database, and publishes creation event.
	 *
	 * @param int $payout_id Payout ID to schedule retry for
	 * @return RetrySchedule The created retry schedule
	 * @throws \Exception If schedule creation fails
	 * @requirement REQ-4D-041: Schedule new retries with event publishing
	 */
	public function scheduleRetry( int $payout_id ): RetrySchedule {
		// Create new retry schedule
		$schedule = RetrySchedule::create( $payout_id );

		// Calculate initial next retry time (1 minute from now)
		$next_retry_time = new \DateTime( '+1 minute', new \DateTimeZone( 'UTC' ) );
		$schedule->setNextRetryTime( $next_retry_time );

		// Persist to database
		$this->retry_repo->save( $schedule );

		// Publish creation event
		$event = new RetryScheduleCreatedEvent( $schedule );
		$this->event_publisher->publish( $event );

		return $schedule;
	}

	/**
	 * Process all due retries in a batch
	 *
	 * Acquires batch lock, finds all due retries, and returns them.
	 * Lock is automatically released after processing.
	 *
	 * @return RetrySchedule[] Array of due retry schedules
	 * @throws \Exception If batch processing fails
	 * @requirement REQ-4D-041: Process due retries with batch locking
	 */
	public function processDueRetries(): array {
		$batch_id = 'retry_processing_batch';

		try {
			// Acquire batch lock to prevent concurrent execution
			$lock = $this->batch_lock_repo->acquireLock( $batch_id, 300 );

			// Find all retries that are due for processing
			$due_retries = $this->retry_repo->findDueRetries();

			return $due_retries;

		} finally {
			// Always release lock, even on error
			$this->batch_lock_repo->releaseLock( $batch_id );
		}
	}

	/**
	 * Mark a retry as failed
	 *
	 * Increments failure count, sets error message, persists update,
	 * and publishes failed event.
	 *
	 * @param RetrySchedule $schedule    Retry schedule to update
	 * @param string        $error_msg   Error message explaining failure
	 * @return RetrySchedule Updated retry schedule
	 * @throws \Exception If update fails
	 * @requirement REQ-4D-041: Mark retries as failed with event publishing
	 */
	public function markRetryFailed( RetrySchedule $schedule, string $error_msg ): RetrySchedule {
		// Increment failure count
		$schedule->incrementFailureCount();

		// Set error message
		$schedule->setErrorMessage( $error_msg );

		// Persist update
		$this->retry_repo->update( $schedule );

		// Publish failed event
		$event = new RetryScheduleFailedEvent( $schedule, $error_msg );
		$this->event_publisher->publish( $event );

		return $schedule;
	}

	/**
	 * Mark a retry as succeeded and remove from queue
	 *
	 * Deletes the retry schedule (no more retries needed) and
	 * publishes succeeded event.
	 *
	 * @param RetrySchedule $schedule Retry schedule that succeeded
	 * @return bool True if deletion successful
	 * @throws \Exception If deletion fails
	 * @requirement REQ-4D-041: Mark retries as succeeded with event publishing
	 */
	public function markRetrySucceeded( RetrySchedule $schedule ): bool {
		// Delete the retry schedule (payout succeeded, no more retries needed)
		$result = $this->retry_repo->delete( $schedule->getId() );

		if ( $result ) {
			// Publish succeeded event
			$event = new RetryScheduleSucceededEvent( $schedule );
			$this->event_publisher->publish( $event );
		}

		return $result;
	}

	/**
	 * Get retry schedule for a specific payout
	 *
	 * @param int $payout_id Payout ID
	 * @return RetrySchedule|null Retry schedule if exists, null otherwise
	 * @requirement REQ-4D-041: Query retry schedules by payout ID
	 */
	public function getRetrySchedule( int $payout_id ): ?RetrySchedule {
		return $this->retry_repo->findByPayoutId( $payout_id );
	}

	/**
	 * Check if there are pending retries due for processing
	 *
	 * @return bool True if there are pending retries, false otherwise
	 * @requirement REQ-4D-041: Check pending retry count
	 */
	public function hasPendingRetries(): bool {
		$due_retries = $this->retry_repo->findDueRetries();
		return count( $due_retries ) > 0;
	}

	/**
	 * Update scheduler configuration value
	 *
	 * @param string $option_name Configuration option name
	 * @param string $value       Configuration value
	 * @return SchedulerConfig Updated configuration object
	 * @throws \Exception If update fails
	 * @requirement REQ-4D-040: Update scheduler configuration
	 */
	public function updateConfig( string $option_name, string $value ): SchedulerConfig {
		return $this->config_repo->set( $option_name, $value );
	}

	/**
	 * Get scheduler configuration value
	 *
	 * @param string $option_name Configuration option name
	 * @return string|null Configuration value or null if not set
	 * @requirement REQ-4D-040: Retrieve scheduler configuration
	 */
	public function getConfig( string $option_name ): ?string {
		return $this->config_repo->get( $option_name );
	}

	/**
	 * Get retry count for a specific payout
	 *
	 * @param int $payout_id Payout ID
	 * @return int Number of retries attempted (0-6)
	 * @requirement REQ-4D-041: Get retry attempt count
	 */
	public function getRetryCount( int $payout_id ): int {
		$schedule = $this->getRetrySchedule( $payout_id );
		return $schedule ? $schedule->getFailureCount() : 0;
	}

	/**
	 * Get all pending retry schedules
	 *
	 * @return RetrySchedule[] All pending retry schedules
	 * @requirement REQ-4D-041: Query all pending retries
	 */
	public function getAllPendingRetries(): array {
		return $this->retry_repo->findDueRetries();
	}
}
