<?php
/**
 * RetrySchedule Event Classes
 *
 * @package    WooCommerce Auction
 * @subpackage Events
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing for retry scheduling
 */

namespace WC\Auction\Events;

use WC\Auction\Models\RetrySchedule;

/**
 * Retry Schedule Created Event
 *
 * Fired when a retry schedule is first created.
 *
 * @covers REQ-4D-041: Retry scheduling events
 */
class RetryScheduleCreatedEvent extends Event {

	/**
	 * Retry schedule model
	 *
	 * @var RetrySchedule
	 */
	private $retry_schedule;

	/**
	 * Constructor
	 *
	 * @param RetrySchedule $retry_schedule The retry schedule
	 */
	public function __construct( RetrySchedule $retry_schedule ) {
		parent::__construct();
		$this->retry_schedule = $retry_schedule;
	}

	/**
	 * Get event name
	 *
	 * @return string Event name
	 */
	public function getName(): string {
		return 'retry_schedule.created';
	}

	/**
	 * Get retry schedule
	 *
	 * @return RetrySchedule The retry schedule
	 */
	public function getRetrySchedule(): RetrySchedule {
		return $this->retry_schedule;
	}

	/**
	 * Serialize to array
	 *
	 * @return array Event data
	 */
	public function toArray(): array {
		return array(
			'event_name' => $this->getName(),
			'timestamp'  => $this->timestamp,
			'data'       => array(
				'payout_id'      => $this->retry_schedule->getPayoutId(),
				'failure_count'  => $this->retry_schedule->getFailureCount(),
				'next_retry_time' => $this->retry_schedule->getNextRetryTime()->format( 'Y-m-d H:i:s' ),
			),
		);
	}
}

/**
 * Retry Schedule Failed Event
 *
 * Fired when a retry attempt fails.
 *
 * @covers REQ-4D-041: Retry scheduling events
 */
class RetryScheduleFailedEvent extends Event {

	/**
	 * Retry schedule model
	 *
	 * @var RetrySchedule
	 */
	private $retry_schedule;

	/**
	 * Error message
	 *
	 * @var string
	 */
	private $error_message;

	/**
	 * Constructor
	 *
	 * @param RetrySchedule $retry_schedule The retry schedule
	 * @param string        $error_message Error message
	 */
	public function __construct( RetrySchedule $retry_schedule, string $error_message ) {
		parent::__construct();
		$this->retry_schedule = $retry_schedule;
		$this->error_message  = $error_message;
	}

	/**
	 * Get event name
	 *
	 * @return string Event name
	 */
	public function getName(): string {
		return 'retry_schedule.failed';
	}

	/**
	 * Get retry schedule
	 *
	 * @return RetrySchedule The retry schedule
	 */
	public function getRetrySchedule(): RetrySchedule {
		return $this->retry_schedule;
	}

	/**
	 * Get error message
	 *
	 * @return string Error description
	 */
	public function getErrorMessage(): string {
		return $this->error_message;
	}

	/**
	 * Serialize to array
	 *
	 * @return array Event data
	 */
	public function toArray(): array {
		return array(
			'event_name' => $this->getName(),
			'timestamp'  => $this->timestamp,
			'data'       => array(
				'payout_id'      => $this->retry_schedule->getPayoutId(),
				'failure_count'  => $this->retry_schedule->getFailureCount(),
				'error_message'  => $this->error_message,
				'next_retry_time' => $this->retry_schedule->getNextRetryTime()->format( 'Y-m-d H:i:s' ),
			),
		);
	}
}

/**
 * Retry Schedule Succeeded Event
 *
 * Fired when a retry attempt succeeds.
 *
 * @covers REQ-4D-041: Retry scheduling events
 */
class RetryScheduleSucceededEvent extends Event {

	/**
	 * Retry schedule model
	 *
	 * @var RetrySchedule
	 */
	private $retry_schedule;

	/**
	 * Constructor
	 *
	 * @param RetrySchedule $retry_schedule The retry schedule
	 */
	public function __construct( RetrySchedule $retry_schedule ) {
		parent::__construct();
		$this->retry_schedule = $retry_schedule;
	}

	/**
	 * Get event name
	 *
	 * @return string Event name
	 */
	public function getName(): string {
		return 'retry_schedule.succeeded';
	}

	/**
	 * Get retry schedule
	 *
	 * @return RetrySchedule The retry schedule
	 */
	public function getRetrySchedule(): RetrySchedule {
		return $this->retry_schedule;
	}

	/**
	 * Serialize to array
	 *
	 * @return array Event data
	 */
	public function toArray(): array {
		return array(
			'event_name' => $this->getName(),
			'timestamp'  => $this->timestamp,
			'data'       => array(
				'payout_id'      => $this->retry_schedule->getPayoutId(),
				'failure_count'  => $this->retry_schedule->getFailureCount(),
				'succeeded_at'   => date( 'Y-m-d H:i:s' ),
			),
		);
	}
}
