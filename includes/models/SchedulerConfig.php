<?php
/**
 * SchedulerConfig Model
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    1.0.0
 * @requirement REQ-4D-040: Persist scheduler configuration options
 */

namespace WC\Auction\Models;

use DateTime;
use DateTimeZone;

/**
 * Scheduler Configuration Value Object
 *
 * Represents scheduler configuration options stored in database.
 * Used to persist scheduler settings like polling interval, retry counts, etc.
 *
 * UML:
 * <pre>
 * ┌──────────────────────────────┐
 * │   SchedulerConfig            │
 * ├──────────────────────────────┤
 * │ - id: ?int                   │
 * │ - option_name: string        │
 * │ - option_value: string       │
 * │ - created_at: DateTime       │
 * │ - updated_at: DateTime       │
 * ├──────────────────────────────┤
 * │ + create()                   │
 * │ + fromDatabase()             │
 * │ + toArray()                  │
 * └──────────────────────────────┘
 * </pre>
 *
 * @covers REQ-4D-040: Scheduler configuration
 */
class SchedulerConfig {

	/**
	 * Config ID (nullable until database persistence)
	 *
	 * @var ?int
	 */
	private $id;

	/**
	 * Option name (e.g., 'polling_interval', 'max_retries')
	 *
	 * @var string
	 */
	private $option_name;

	/**
	 * Option value (stored as string)
	 *
	 * @var string
	 */
	private $option_value;

	/**
	 * When config was created (UTC)
	 *
	 * @var DateTime
	 */
	private $created_at;

	/**
	 * When config was last updated (UTC)
	 *
	 * @var DateTime
	 */
	private $updated_at;

	/**
	 * Create new scheduler config
	 *
	 * Factory method to create a configuration option at current time.
	 *
	 * @param string $option_name Configuration option name
	 * @param mixed  $option_value Configuration value (will be converted to string)
	 *
	 * @return self New scheduler config
	 */
	public static function create( string $option_name, $option_value ): self {
		$config = new self();
		$config->id            = null;
		$config->option_name   = $option_name;
		$config->option_value  = (string) $option_value;
		$config->created_at    = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$config->updated_at    = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		return $config;
	}

	/**
	 * Restore scheduler config from database row
	 *
	 * @param array $row Database row with id, option_name, option_value, created_at, updated_at
	 *
	 * @return self Restored scheduler config
	 *
	 * @throws \InvalidArgumentException If row missing required fields
	 */
	public static function fromDatabase( array $row ): self {
		if ( ! isset( $row['option_name'], $row['option_value'], $row['created_at'], $row['updated_at'] ) ) {
			throw new \InvalidArgumentException( 'Missing required fields: option_name, option_value, created_at, updated_at' );
		}

		$config = new self();
		$config->id           = $row['id'] ?? null;
		$config->option_name  = $row['option_name'];
		$config->option_value = $row['option_value'];
		$config->created_at   = new DateTime( $row['created_at'], new DateTimeZone( 'UTC' ) );
		$config->updated_at   = new DateTime( $row['updated_at'], new DateTimeZone( 'UTC' ) );

		return $config;
	}

	/**
	 * Get config ID
	 *
	 * @return ?int Config ID or null if not persisted
	 */
	public function getId(): ?int {
		return $this->id;
	}

	/**
	 * Get option name
	 *
	 * @return string Option name
	 */
	public function getOptionName(): string {
		return $this->option_name;
	}

	/**
	 * Get option value
	 *
	 * @return string Option value
	 */
	public function getOptionValue(): string {
		return $this->option_value;
	}

	/**
	 * Get created at time
	 *
	 * @return DateTime Creation time
	 */
	public function getCreatedAt(): DateTime {
		return $this->created_at;
	}

	/**
	 * Get updated at time
	 *
	 * @return DateTime Last update time
	 */
	public function getUpdatedAt(): DateTime {
		return $this->updated_at;
	}

	/**
	 * Set option value
	 *
	 * @param mixed $option_value New option value (will be converted to string)
	 *
	 * @return self Self for chaining
	 */
	public function setOptionValue( $option_value ): self {
		$this->option_value = (string) $option_value;
		return $this;
	}

	/**
	 * Set updated at time
	 *
	 * @param DateTime $updated_at Update time
	 *
	 * @return self Self for chaining
	 */
	public function setUpdatedAt( DateTime $updated_at ): self {
		$this->updated_at = $updated_at;
		return $this;
	}

	/**
	 * Set created at time
	 *
	 * @param DateTime $created_at Creation time
	 *
	 * @return self Self for chaining
	 */
	public function setCreatedAt( DateTime $created_at ): self {
		$this->created_at = $created_at;
		return $this;
	}

	/**
	 * Serialize to database array
	 *
	 * @return array Database-ready format with Y-m-d H:i:s datetime strings
	 */
	public function toArray(): array {
		return array(
			'option_name'  => $this->option_name,
			'option_value' => $this->option_value,
			'created_at'   => $this->created_at->format( 'Y-m-d H:i:s' ),
			'updated_at'   => $this->updated_at->format( 'Y-m-d H:i:s' ),
		);
	}
}
