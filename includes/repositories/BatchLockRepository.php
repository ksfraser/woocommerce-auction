<?php
/**
 * BatchLockRepository DAO
 *
 * @package    WooCommerce Auction
 * @subpackage Repositories
 * @version    1.0.0
 * @requirement REQ-4D-038: Persist and query batch locks to prevent concurrent execution
 */

namespace WC\Auction\Repositories;

use WC\Auction\Models\BatchLock;

/**
 * Batch Lock Data Access Object
 *
 * Manages persistence and retrieval of batch locks from the database.
 *
 * Database table: wc_auction_batch_locks
 * - id (INT, PRIMARY KEY, AUTO_INCREMENT)
 * - batch_id (VARCHAR(255), UNIQUE NOT NULL)
 * - locked_at (DATETIME NOT NULL)
 * - timeout_seconds (INT NOT NULL)
 *
 * Class Design:
 * <pre>
 * ┌─────────────────────────────────┐
 * │  BatchLockRepository            │
 * ├─────────────────────────────────┤
 * │ - wpdb: wpdb global             │
 * ├─────────────────────────────────┤
 * │ + acquireLock()                 │
 * │ + releaseLock()                 │
 * │ + isLocked()                    │
 * │ + refresh()                     │
 * │ + cleanupStaleLocks()           │
 * │ + save()                        │
 * │ + findByBatchId()               │
 * │ + delete()                      │
 * └─────────────────────────────────┘
 * </pre>
 *
 * @covers REQ-4D-038: Batch lock persistence
 */
class BatchLockRepository {

	const TABLE_NAME = 'wc_auction_batch_locks';

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
	 * Acquire lock for batch
	 *
	 * Creates a new lock or returns existing valid lock. Only returns
	 * a lock if batch_id is not currently locked.
	 *
	 * @param string $batch_id Batch identifier
	 * @param int    $timeout_seconds Lock timeout
	 *
	 * @return BatchLock New batch lock
	 */
	public function acquireLock( string $batch_id, int $timeout_seconds ): BatchLock {
		// Check if lock already exists and is valid
		$existing = $this->findByBatchId( $batch_id );
		if ( $existing && ! $existing->isExpired() ) {
			return $existing;
		}

		// Create new lock
		$lock = BatchLock::create( $batch_id, $timeout_seconds );
		$this->save( $lock );

		return $lock;
	}

	/**
	 * Release lock by batch ID
	 *
	 * Removes lock from database.
	 *
	 * @param string $batch_id Batch identifier
	 *
	 * @return bool True if lock was released
	 */
	public function releaseLock( string $batch_id ): bool {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$deleted    = $this->wpdb->delete(
			$table_name,
			array( 'batch_id' => $batch_id ),
			array( '%s' )
		);

		return $deleted > 0;
	}

	/**
	 * Check if batch is currently locked
	 *
	 * Returns true only if an active (not expired) lock exists.
	 *
	 * @param string $batch_id Batch identifier
	 *
	 * @return bool True if locked
	 */
	public function isLocked( string $batch_id ): bool {
		$lock = $this->findByBatchId( $batch_id );

		if ( ! $lock ) {
			return false;
		}

		return $lock->isLocked();
	}

	/**
	 * Refresh lock to extend timeout
	 *
	 * Updates the lock's acquired timestamp to current time,
	 * effectively extending the timeout.
	 *
	 * @param string $batch_id Batch identifier
	 *
	 * @return ?BatchLock Refreshed lock or null if not found
	 */
	public function refresh( string $batch_id ): ?BatchLock {
		$lock = $this->findByBatchId( $batch_id );

		if ( ! $lock ) {
			return null;
		}

		$lock->refresh();
		$this->save( $lock );

		return $lock;
	}

	/**
	 * Clean up expired locks
	 *
	 * Removes all locks that have passed their timeout.
	 *
	 * @return int Number of stale locks removed
	 */
	public function cleanupStaleLocks(): int {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$all_locks  = $this->findAll();
		$deleted    = 0;

		foreach ( $all_locks as $lock ) {
			if ( $lock->isExpired() ) {
				$this->delete( $lock->getId() );
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Save batch lock to database
	 *
	 * Inserts or updates lock in database. Sets ID on model after insert.
	 *
	 * @param BatchLock $lock Lock to save
	 *
	 * @return int Lock ID
	 */
	public function save( BatchLock $lock ): int {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$data       = $lock->toArray();

		if ( $lock->getId() ) {
			// Update existing
			$this->wpdb->update(
				$table_name,
				$data,
				array( 'id' => $lock->getId() ),
				array( '%s', '%s', '%d' ),
				array( '%d' )
			);
			return $lock->getId();
		}

		// Insert new
		$result = $this->wpdb->insert(
			$table_name,
			$data,
			array( '%s', '%s', '%d' )
		);

		if ( $result === false ) {
			throw new \Exception( 'Failed to insert batch lock: ' . $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find lock by batch ID
	 *
	 * Returns lock or null if not found.
	 *
	 * @param string $batch_id Batch identifier
	 *
	 * @return ?BatchLock Lock or null
	 */
	public function findByBatchId( string $batch_id ): ?BatchLock {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$query      = $this->wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE batch_id = %s LIMIT 1",
			$batch_id
		);

		$row = $this->wpdb->get_row( $query, ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		return BatchLock::fromDatabase( $row );
	}

	/**
	 * Find all locks
	 *
	 * @return BatchLock[] All locks
	 */
	public function findAll(): array {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$query      = "SELECT * FROM {$table_name} ORDER BY locked_at DESC";

		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		$locks = array();
		foreach ( $rows as $row ) {
			$locks[] = BatchLock::fromDatabase( $row );
		}

		return $locks;
	}

	/**
	 * Delete lock by ID
	 *
	 * @param int $id Lock ID
	 *
	 * @return bool True if deleted
	 */
	public function delete( int $id ): bool {
		$table_name = $this->wpdb->prefix . self::TABLE_NAME;
		$deleted    = $this->wpdb->delete(
			$table_name,
			array( 'id' => $id ),
			array( '%d' )
		);

		return $deleted > 0;
	}
}
