<?php
/**
 * SchedulerConfigRepository DAO
 *
 * @package    WooCommerce Auction
 * @subpackage Repositories
 * @version    1.0.0
 * @requirement REQ-4D-040: Persist and query scheduler configuration options
 */

namespace WC\Auction\Repositories;

use WC\Auction\Models\SchedulerConfig;

/**
 * Scheduler Configuration Data Access Object
 *
 * Manages persistence and retrieval of scheduler configuration from database.
 *
 * Database table: wc_auction_scheduler_config
 * - id (INT, PRIMARY KEY, AUTO_INCREMENT)
 * - option_name (VARCHAR(255), UNIQUE NOT NULL)
 * - option_value (LONGTEXT NOT NULL)
 * - created_at (DATETIME NOT NULL)
 * - updated_at (DATETIME NOT NULL)
 *
 * Class Design:
 * <pre>
 * ┌─────────────────────────────────┐
 * │ SchedulerConfigRepository       │
 * ├─────────────────────────────────┤
 * │ - wpdb: wpdb global             │
 * ├─────────────────────────────────┤
 * │ + get()                         │
 * │ + set()                         │
 * │ + getAll()                      │
 * │ + delete()                      │
 * │ + findByOptionName()            │
 * │ + save()                        │
 * └─────────────────────────────────┘
 * </pre>
 *
 * @covers REQ-4D-040: Scheduler configuration persistence
 */
class SchedulerConfigRepository {

	const TABLE_NAME = 'wc_auction_scheduler_config';

	/**
	 * WordPress database object
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Get config value by option name
	 *
	 * Returns option value or null if not found.
	 *
	 * @param string $option_name Option name
	 *
	 * @return ?string Option value or null
	 */
	public function get( string $option_name ): ?string {
		$config = $this->findByOptionName( $option_name );

		if ( ! $config ) {
			return null;
		}

		return $config->getOptionValue();
	}

	/**
	 * Set config value
	 *
	 * Creates new option or updates existing.
	 *
	 * @param string $option_name Option name
	 * @param mixed  $option_value Option value (will be converted to string)
	 *
	 * @return SchedulerConfig Saved config
	 */
	public function set( string $option_name, $option_value ): SchedulerConfig {
		$existing = $this->findByOptionName( $option_name );

		if ( $existing ) {
			// Update existing
			$existing->setOptionValue( $option_value );
			$existing->setUpdatedAt( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );
			$this->save( $existing );
			return $existing;
		}

		// Create new
		$config = SchedulerConfig::create( $option_name, $option_value );
		$this->save( $config );

		return $config;
	}

	/**
	 * Get all config options
	 *
	 * Returns associative array of option_name => option_value.
	 *
	 * @return array Associative array of all options
	 */
	public function getAll(): array {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$query      = "SELECT * FROM {$table_name} ORDER BY option_name ASC";

		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		$result = array();
		foreach ( $rows as $row ) {
			$result[ $row['option_name'] ] = $row['option_value'];
		}

		return $result;
	}

	/**
	 * Delete config option
	 *
	 * Removes option from database.
	 *
	 * @param string $option_name Option name
	 *
	 * @return bool True if deleted
	 */
	public function delete( string $option_name ): bool {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$deleted    = $this->wpdb->delete(
			$table_name,
			array( 'option_name' => $option_name ),
			array( '%s' )
		);

		return $deleted > 0;
	}

	/**
	 * Find config by option name
	 *
	 * @param string $option_name Option name
	 *
	 * @return ?SchedulerConfig Config or null
	 */
	public function findByOptionName( string $option_name ): ?SchedulerConfig {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$query      = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE option_name = %s LIMIT 1",
			$option_name
		);

		$row = $this->wpdb->get_row( $query, ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		return SchedulerConfig::fromDatabase( $row );
	}

	/**
	 * Save scheduler config to database
	 *
	 * Inserts or updates config in database. Sets ID on model after insert.
	 *
	 * @param SchedulerConfig $config Config to save
	 *
	 * @return int Config ID
	 */
	public function save( SchedulerConfig $config ): int {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$data       = $config->toArray();

		if ( $config->getId() ) {
			// Update existing
			$this->wpdb->update(
				$table_name,
				$data,
				array( 'id' => $config->getId() ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return $config->getId();
		}

		// Insert new
		$result = $this->wpdb->insert(
			$table_name,
			$data,
			array( '%s', '%s', '%s', '%s' )
		);

		if ( $result === false ) {
			throw new \Exception( 'Failed to insert scheduler config: ' . $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}
}
