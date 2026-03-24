<?php
/**
 * Retry Schedule Created Event
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
		$next_retry_time = $this->retry_schedule->getNextRetryTime();
		return array(
			'event_name' => $this->getName(),
			'timestamp'  => $this->timestamp,
			'data'       => array(
				'payout_id'       => $this->retry_schedule->getPayoutId(),
				'failure_count'   => $this->retry_schedule->getFailureCount(),
				'next_retry_time' => $next_retry_time ? $next_retry_time->format( 'Y-m-d H:i:s' ) : null,
			),
		);
	}
}
