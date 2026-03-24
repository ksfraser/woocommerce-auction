<?php
/**
 * Event Base Class
 *
 * @package    WooCommerce Auction
 * @subpackage Events
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing infrastructure
 */

namespace WC\Auction\Events;

/**
 * Base Event Class
 *
 * Abstract base class for all domain events.
 *
 * UML:
 * <pre>
 * ┌──────────────────────────┐
 * │   Event (abstract)       │
 * ├──────────────────────────┤
 * │ - timestamp: int         │
 * ├──────────────────────────┤
 * │ + getName(): string      │
 * │ + getTimestamp(): int    │
 * │ + toArray(): array       │
 * └──────────────────────────┘
 * </pre>
 *
 * @covers REQ-4D-041: Domain events
 */
abstract class Event {

	/**
	 * Event timestamp (Unix timestamp)
	 *
	 * @var int
	 */
	protected $timestamp;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->timestamp = time();
	}

	/**
	 * Get event name
	 *
	 * Event name in format: domain.action (e.g., 'retry_schedule.created')
	 *
	 * @return string Event name
	 */
	abstract public function getName(): string;

	/**
	 * Get event timestamp
	 *
	 * @return int Unix timestamp
	 */
	public function getTimestamp(): int {
		return $this->timestamp;
	}

	/**
	 * Serialize event to array
	 *
	 * @return array Event data including event_name, timestamp, and data
	 */
	abstract public function toArray(): array;
}
