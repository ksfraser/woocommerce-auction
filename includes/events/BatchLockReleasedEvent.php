<?php
namespace WC\Auction\Events;
class BatchLockReleasedEvent extends Event {
	private $batch_id;
	public function __construct( string $batch_id ) {
		parent::__construct();
		$this->batch_id = $batch_id;
	}
	public function getName(): string {
		return 'batch_lock.released';
	}
	public function getBatchId(): string {
		return $this->batch_id;
	}
	public function toArray(): array {
		return array(
			'event_name' => $this->getName(),
			'timestamp'  => $this->timestamp,
			'data'       => array(
				'batch_id'    => $this->batch_id,
				'released_at' => date( 'Y-m-d H:i:s' ),
			),
		);
	}
}
