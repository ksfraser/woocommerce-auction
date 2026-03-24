<?php
namespace WC\Auction\Events;
use WC\Auction\Models\RetrySchedule;
class RetryScheduleSucceededEvent extends Event {
	private $retry_schedule;
	public function __construct( RetrySchedule $retry_schedule ) {
		parent::__construct();
		$this->retry_schedule = $retry_schedule;
	}
	public function getName(): string {
		return 'retry_schedule.succeeded';
	}
	public function getRetrySchedule(): RetrySchedule {
		return $this->retry_schedule;
	}
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
