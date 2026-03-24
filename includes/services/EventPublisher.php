<?php
/**
 * EventPublisher Service
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing and dispatch
 */

namespace WC\Auction\Services;

use WC\Auction\Events\Event;

/**
 * Event Publisher Service
 *
 * Implements publish-subscribe pattern for domain events.
 * Allows listeners to register for specific event types and be notified when events occur.
 *
 * Class Design:
 * <pre>
 * ┌─────────────────────────────────┐
 * │  EventPublisher                 │
 * ├─────────────────────────────────┤
 * │ - listeners: array              │
 * ├─────────────────────────────────┤
 * │ + subscribe()                   │
 * │ + unsubscribe()                 │
 * │ + publish()                     │
 * │ + hasListeners()                │
 * └─────────────────────────────────┘
 * </pre>
 *
 * @covers REQ-4D-041: Event publishing infrastructure
 */
class EventPublisher {

	/**
	 * Registered event listeners
	 *
	 * Structure: ['event_name' => [callable, callable, ...], ...]
	 *
	 * @var array
	 */
	private $listeners = array();

	/**
	 * Subscribe to an event
	 *
	 * Registers a listener (callback) to be notified when an event is published.
	 *
	 * @param string   $event_name Event name (e.g., 'retry_schedule.created')
	 * @param callable $listener Callback function to invoke (e.g., [$obj, 'method'])
	 *
	 * @return self Self for chaining
	 */
	public function subscribe( string $event_name, callable $listener ): self {
		if ( ! isset( $this->listeners[ $event_name ] ) ) {
			$this->listeners[ $event_name ] = array();
		}

		$this->listeners[ $event_name ][] = $listener;

		return $this;
	}

	/**
	 * Unsubscribe from an event
	 *
	 * Removes a listener from event notifications.
	 *
	 * @param string   $event_name Event name
	 * @param callable $listener Callback to remove
	 *
	 * @return self Self for chaining
	 */
	public function unsubscribe( string $event_name, callable $listener ): self {
		if ( ! isset( $this->listeners[ $event_name ] ) ) {
			return $this;
		}

		$this->listeners[ $event_name ] = array_filter(
			$this->listeners[ $event_name ],
			function( $registered_listener ) use ( $listener ) {
				return $registered_listener !== $listener;
			}
		);

		// Remove event entry if no listeners remain
		if ( empty( $this->listeners[ $event_name ] ) ) {
			unset( $this->listeners[ $event_name ] );
		}

		return $this;
	}

	/**
	 * Publish event to all registered listeners
	 *
	 * Dispatches the event to all listeners subscribed to event type.
	 *
	 * @param Event $event Event to publish
	 *
	 * @return self Self for chaining
	 */
	public function publish( Event $event ): self {
		$event_name = $event->getName();

		if ( ! isset( $this->listeners[ $event_name ] ) ) {
			return $this;
		}

		foreach ( $this->listeners[ $event_name ] as $listener ) {
			call_user_func( $listener, $event );
		}

		return $this;
	}

	/**
	 * Check if event has registered listeners
	 *
	 * @param string $event_name Event name
	 *
	 * @return bool True if listeners registered for event
	 */
	public function hasListeners( string $event_name ): bool {
		return isset( $this->listeners[ $event_name ] ) && ! empty( $this->listeners[ $event_name ] );
	}
}
