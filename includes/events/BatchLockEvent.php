<?php
/**
 * BatchLock Event Classes
 *
 * @package    WooCommerce Auction
 * @subpackage Events
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing for batch locking
 */

namespace WC\Auction\Events;

use WC\Auction\Models\BatchLock;

/**
 * Batch Lock Acquired Event
 *
 * Fired when a lock is successfully acquired.
 *
 * @covers REQ-4D-041: Batch locking events
 */
class BatchLockAcquiredEvent extends Event {

	/**
	 * Batch lock model
	 *
	 * @var BatchLock
	 */
	private $batch_lock;

	/**
	 * Constructor
	 *
	 * @param BatchLock $batch_lock The batch lock
	 */
	public function __construct( BatchLock $batch_lock ) {
		parent::__construct();
		$this->batch_lock = $batch_lock;
	}

	/**
	 * Get event name
	 *
	 * @return string Event name
	 */
	public function getName(): string {
		return 'batch_lock.acquired';
	}

	/**
	 * Get batch lock
	 *
	 * @return BatchLock The batch lock
	 */
	public function getBatchLock(): BatchLock {
		return $this->batch_lock;
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
				'batch_id'        => $this->batch_lock->getBatchId(),
				'locked_at'       => $this->batch_lock->getLockedAt()->format( 'Y-m-d H:i:s' ),
				'timeout_seconds' => $this->batch_lock->getTimeoutSeconds(),
			),
		);
	}
}

/**
 * Batch Lock Released Event
 *
 * Fired when a lock is released.
 *
 * @covers REQ-4D-041: Batch locking events
 */
class BatchLockReleasedEvent extends Event {

	/**
	 * Batch ID
	 *
	 * @var string
	 */
	private $batch_id;

	/**
	 * Constructor
	 *
	 * @param string $batch_id The batch ID
	 */
	public function __construct( string $batch_id ) {
		parent::__construct();
		$this->batch_id = $batch_id;
	}

	/**
	 * Get event name
	 *
	 * @return string Event name
	 */
	public function getName(): string {
		return 'batch_lock.released';
	}

	/**
	 * Get batch ID
	 *
	 * @return string The batch ID
	 */
	public function getBatchId(): string {
		return $this->batch_id;
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
				'batch_id'    => $this->batch_id,
				'released_at' => date( 'Y-m-d H:i:s' ),
			),
		);
	}
}
