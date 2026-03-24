<?php
/**
 * RetryScheduleRepository - Data Access Object
 *
 * @package    WooCommerce Auction
 * @subpackage Repositories
 * @version    1.0.0
 * @requirement REQ-4D-039: Persist and query retry schedules
 */

namespace WC\Auction\Repositories;

use WC\Auction\Models\RetrySchedule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RetryScheduleRepository - DAO for retry schedule persistence
 *
 * UML Class Diagram:
 * ```
 * RetryScheduleRepository (DAO)
 * ├── Methods:
 * │   ├── save(schedule): int
 * │   ├── find(id): RetrySchedule|null
 * │   ├── findByPayoutId(payout_id): RetrySchedule|null
 * │   ├── findDueRetries(): RetrySchedule[]
 * │   ├── findAll(): RetrySchedule[]
 * │   ├── update(schedule): bool
 * │   ├── delete(id): bool
 * │   └── count(): int
 * └── Dependencies:
 *     └── WordPress $wpdb
 * ```
 *
 * Design Pattern: Data Access Object (DAO)
 * - Encapsulates all CRUD operations
 * - Returns domain objects (RetrySchedule)
 * - Parameterized queries for security
 * - Support for filtering and querying
 *
 * Database Table:
 * - Table: wc_auction_retry_schedules
 * - Columns: id (PK), payout_id (UNIQUE), failure_count, next_retry_time, last_error_message, created_at
 *
 * @requirement REQ-4D-039: Persist and query retry schedules
 */
class RetryScheduleRepository {

	const TABLE_NAME = 'wc_auction_retry_schedules';

	/**
	 * WordPress database instance
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
	 * Save new retry schedule
	 *
	 * @param RetrySchedule $schedule Schedule to save
	 * @return int Newly assigned ID
	 * @throws \Exception If save fails
	 * @requirement REQ-4D-039: Persist retry schedule
	 */
	public function save( RetrySchedule $schedule ): int {
		$data = $schedule->toArray();
		unset( $data['id'] );

		$inserted = $this->wpdb->insert(
			$this->wpdb->prefix . self::TABLE_NAME,
			$data,
			[
				'%d', // payout_id
				'%d', // failure_count
				'%s', // next_retry_time
				'%s', // last_error_message
				'%s', // created_at
			]
		);

		if ( false === $inserted ) {
			throw new \Exception( 'Failed to save retry schedule: ' . $this->wpdb->last_error );
		}

		$id = (int) $this->wpdb->insert_id;
		$schedule->setId( $id );

		return $id;
	}

	/**
	 * Find retry schedule by ID
	 *
	 * @param int $id Schedule ID
	 * @return RetrySchedule|null Found schedule or null
	 */
	public function find( int $id ): ?RetrySchedule {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$query      = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$id
		);
		$row        = $this->wpdb->get_row( $query, ARRAY_A );

		return $row ? RetrySchedule::fromDatabase( $row ) : null;
	}

	/**
	 * Find retry schedule by payout ID
	 *
	 * @param int $payout_id Payout ID
	 * @return RetrySchedule|null Found schedule or null
	 */
	public function findByPayoutId( int $payout_id ): ?RetrySchedule {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$query      = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE payout_id = %d",
			$payout_id
		);
		$row        = $this->wpdb->get_row( $query, ARRAY_A );

		return $row ? RetrySchedule::fromDatabase( $row ) : null;
	}

	/**
	 * Find all schedules that are due for retry
	 *
	 * @return RetrySchedule[] Array of past-due schedules
	 */
	public function findDueRetries(): array {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$now        = gmdate( 'Y-m-d H:i:s' );
		$query      = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} 
             WHERE next_retry_time IS NOT NULL 
             AND next_retry_time <= %s 
             ORDER BY next_retry_time ASC",
			$now
		);
		$rows       = $this->wpdb->get_results( $query, ARRAY_A );

		return array_map(
			function( $row ) {
				return RetrySchedule::fromDatabase( $row );
			},
			$rows ?? []
		);
	}

	/**
	 * Find all retry schedules
	 *
	 * @return RetrySchedule[] All schedules
	 */
	public function findAll(): array {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$query      = "SELECT * FROM {$table_name} ORDER BY created_at DESC";
		$rows       = $this->wpdb->get_results( $query, ARRAY_A );

		return array_map(
			function( $row ) {
				return RetrySchedule::fromDatabase( $row );
			},
			$rows ?? []
		);
	}

	/**
	 * Update existing retry schedule
	 *
	 * @param RetrySchedule $schedule Schedule to update
	 * @return bool Success status
	 * @throws \Exception If schedule has no ID
	 */
	public function update( RetrySchedule $schedule ): bool {
		if ( null === $schedule->getId() ) {
			throw new \Exception( 'Cannot update schedule without ID' );
		}

		$data = $schedule->toArray();
		unset( $data['id'] );
		unset( $data['created_at'] );

		$updated = $this->wpdb->update(
			$this->wpdb->prefix . self::TABLE_NAME,
			$data,
			[ 'id' => $schedule->getId() ],
			[
				'%d', // payout_id
				'%d', // failure_count
				'%s', // next_retry_time
				'%s', // last_error_message
			],
			[ '%d' ]
		);

		return false !== $updated;
	}

	/**
	 * Delete retry schedule
	 *
	 * @param int $id Schedule ID
	 * @return bool Success status
	 */
	public function delete( int $id ): bool {
		$deleted = $this->wpdb->delete(
			$this->wpdb->prefix . self::TABLE_NAME,
			[ 'id' => $id ],
			[ '%d' ]
		);

		return false !== $deleted;
	}

	/**
	 * Count total retry schedules
	 *
	 * @return int Total count
	 */
	public function count(): int {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$count      = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		return $count;
	}

	/**
	 * Count by failure count
	 *
	 * Get number of schedules with specific failure count
	 *
	 * @param int $failure_count Failure count to match
	 * @return int Count of schedules
	 */
	public function countByFailureCount( int $failure_count ): int {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$count      = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE failure_count = %d",
				$failure_count
			)
		);

		return $count;
	}

	/**
	 * Find by failure count threshold
	 *
	 * Get all schedules with failure_count >= N
	 *
	 * @param int $min_failures Minimum failure count
	 * @return RetrySchedule[] Matching schedules
	 */
	public function findByFailureCountAtLeast( int $min_failures ): array {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$query      = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE failure_count >= %d ORDER BY failure_count DESC",
			$min_failures
		);
		$rows       = $this->wpdb->get_results( $query, ARRAY_A );

		return array_map(
			function( $row ) {
				return RetrySchedule::fromDatabase( $row );
			},
			$rows ?? []
		);
	}
}
