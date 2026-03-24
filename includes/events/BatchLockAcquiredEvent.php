<?php
namespace WC\Auction\Events;
use WC\Auction\Models\BatchLock;
class BatchLockAcquiredEvent extends Event {
	private $batch_lock;
	public function __construct( BatchLock $batch_lock ) {
		parent::__construct();
		$this->batch_lock = $batch_lock;
	}
	public function getName(): string {
		return 'batch_lock.acquired';
	}
	public function getBatchLock(): BatchLock {
		return $this->batch_lock;
	}
	public function toArray(): array {
		return array(
			'event_name' => $this->getName(),
			'timestamp'  => $this->timestamp,
			'data'       => array(
				'batch_id'        => $this->batch_lock->getBatchId(),
				'locked_at'       => $this->batch_lock->getLockedAt()->format( 'Y-m-d H:i:s' ),
				'timeout_seconds' => $this->batch_lock->getTimeoutSeconds(),
			),
		);
	}
}
