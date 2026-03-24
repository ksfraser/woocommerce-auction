<?php
namespace WC\Auction\Events;
use WC\Auction\Models\SchedulerConfig;
class SchedulerConfigChangedEvent extends Event {
	private $config;
	private $option_name;
	private $new_value;
	private $old_value;
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
	public function getName(): string {
		return 'scheduler_config.changed';
	}
	public function getConfig(): ?SchedulerConfig {
		return $this->config;
	}
	public function getOptionName(): ?string {
		return $this->option_name;
	}
	public function getNewValue(): ?string {
		return $this->new_value;
	}
	public function getOldValue(): ?string {
		return $this->old_value;
	}
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
