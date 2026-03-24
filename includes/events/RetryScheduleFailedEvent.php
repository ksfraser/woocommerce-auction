<?php
namespace WC\Auction\Events;
use WC\Auction\Models\RetrySchedule;
class RetryScheduleFailedEvent extends Event {
	private $retry_schedule;
	private $error_message;
	public function __construct( RetrySchedule $retry_schedule, string $error_message ) {
		parent::__construct();
		$this->retry_schedule = $retry_schedule;
		$this->error_message  = $error_message;
	}
	public function getName(): string {
		return 'retry_schedule.failed';
	}
	public function getRetrySchedule(): RetrySchedule {
		return $this->retry_schedule;
	}
	public function getErrorMessage(): string {
		return $this->error_message;
	}
	public function toArray(): array {
		$next_retry_time = $this->retry_schedule->getNextRetryTime();
		return array(
			'event_name' => $this->getName(),
			'timestamp'  => $this->timestamp,
			'data'       => array(
				'payout_id'       => $this->retry_schedule->getPayoutId(),
				'failure_count'   => $this->retry_schedule->getFailureCount(),
				'error_message'   => $this->error_message,
				'next_retry_time' => $next_retry_time ? $next_retry_time->format( 'Y-m-d H:i:s' ) : null,
			),
		);
	}
}
