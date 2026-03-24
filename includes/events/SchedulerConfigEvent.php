<?php
/**
 * SchedulerConfig Event Class
 *
 * @package    WooCommerce Auction
 * @subpackage Events
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing for configuration changes
 */

namespace WC\Auction\Events;

use WC\Auction\Models\SchedulerConfig;

/**
 * Scheduler Config Changed Event
 *
 * Fired when scheduler configuration is modified.
 *
 * @covers REQ-4D-041: Configuration change events
 */
class SchedulerConfigChangedEvent extends Event {

	/**
	 * Scheduler config model (optional if details provided separately)
	 *
	 * @var ?SchedulerConfig
	 */
	private $config;

	/**
	 * Option name
	 *
	 * @var ?string
	 */
	private $option_name;

	/**
	 * New option value
	 *
	 * @var ?string
	 */
	private $new_value;

	/**
	 * Old option value
	 *
	 * @var ?string
	 */
	private $old_value;

	/**
	 * Constructor with config model
	 *
	 * @param ?SchedulerConfig $config Config model or null
	 * @param ?string          $option_name Option name (required if config is null)
	 * @param ?string          $new_value New value (required if config is null)
	 * @param ?string          $old_value Old value
	 */
	public function __construct(
		?SchedulerConfig $config = null,
		?string $option_name = null,
		?string $new_value = null,
		?string $old_value = null
	) {
		parent::__construct();
		$this->config        = $config;
		$this->option_name   = $option_name ?? ( $config ? $config->getOptionName() : null );
		$this->new_value     = $new_value ?? ( $config ? $config->getOptionValue() : null );
		$this->old_value     = $old_value;
	}

	/**
	 * Get event name
	 *
	 * @return string Event name
	 */
	public function getName(): string {
		return 'scheduler_config.changed';
	}

	/**
	 * Get config model
	 *
	 * @return ?SchedulerConfig The config model or null
	 */
	public function getConfig(): ?SchedulerConfig {
		return $this->config;
	}

	/**
	 * Get option name
	 *
	 * @return ?string The option name
	 */
	public function getOptionName(): ?string {
		return $this->option_name;
	}

	/**
	 * Get new value
	 *
	 * @return ?string The new value
	 */
	public function getNewValue(): ?string {
		return $this->new_value;
	}

	/**
	 * Get old value
	 *
	 * @return ?string The old value
	 */
	public function getOldValue(): ?string {
		return $this->old_value;
	}

	/**
	 * Serialize to array
	 *
	 * @return array Event data
	 */
	public function toArray(): array {
		$data = array(
			'option_name' => $this->option_name,
			'option_value' => $this->new_value,
		);

		if ( null !== $this->new_value ) {
			$data['new_value'] = $this->new_value;
		}

		if ( null !== $this->old_value ) {
			$data['old_value'] = $this->old_value;
		}

		return array(
			'event_name' => $this->getName(),
			'timestamp'  => $this->timestamp,
			'data'       => $data,
		);
	}
}
