<?php

namespace Yith\Auctions\Tests;

use PHPUnit\Framework\TestCase;

/**
 * BaseIntegrationTest - Base class for all integration tests.
 *
 * Provides database setup, WordPress hooks, and integration
 * test utilities. Use for end-to-end feature testing.
 *
 * @package Yith\Auctions\Tests
 * @requirement REQ-TESTING-INTEGRATION-001: Standardized integration test infrastructure
 */
abstract class BaseIntegrationTest extends TestCase
{
    /**
     * @var \wpdb WordPress database object
     */
    protected \wpdb $wpdb;

    /**
     * Test setup - initialize database connection.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        // Start transaction for test isolation
        $this->beginTestTransaction();
    }

    /**
     * Test teardown - rollback transaction.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->rollbackTestTransaction();
        parent::tearDown();
    }

    /**
     * Begin database transaction for test isolation.
     *
     * @return void
     * @requirement REQ-TESTING-INTEGRATION-001
     */
    protected function beginTestTransaction(): void
    {
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * Rollback database transaction after test.
     *
     * @return void
     * @requirement REQ-TESTING-INTEGRATION-001
     */
    protected function rollbackTestTransaction(): void
    {
        $this->wpdb->query('ROLLBACK');
    }

    /**
     * Get full table name with WordPress prefix.
     *
     * @param string $table Table name without prefix
     * @return string Table name with prefix
     * @requirement REQ-TESTING-INTEGRATION-001
     */
    protected function table(string $table): string
    {
        return $this->wpdb->prefix . $table;
    }

    /**
     * Insert test data and return ID.
     *
     * @param string $table  Table name
     * @param array  $data   Data to insert
     * @param array  $format Data format (%d, %s, %f)
     * @return int Inserted row ID
     * @requirement REQ-TESTING-INTEGRATION-001
     */
    protected function insertTestData(string $table, array $data, array $format): int
    {
        $this->wpdb->insert($this->table($table), $data, $format);

        if ($this->wpdb->last_error) {
            $this->fail("Failed to insert test data: {$this->wpdb->last_error}");
        }

        return (int)$this->wpdb->insert_id;
    }

    /**
     * Get test data from database.
     *
     * @param string $table Table name
     * @param int    $id    Row ID
     * @return array|object Row data
     * @requirement REQ-TESTING-INTEGRATION-001
     */
    protected function getTestData(string $table, int $id)
    {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table($table)} WHERE id = %d",
            $id
        );

        return $this->wpdb->get_row($query, ARRAY_A);
    }

    /**
     * Count test data rows.
     *
     * @param string $table   Table name
     * @param array  $where   WHERE conditions (key => value)
     * @return int Row count
     * @requirement REQ-TESTING-INTEGRATION-001
     */
    protected function countTestData(string $table, array $where = []): int
    {
        $query = "SELECT COUNT(*) FROM {$this->table($table)}";

        if (!empty($where)) {
            $conditions = [];
            $values = [];
            foreach ($where as $key => $value) {
                $conditions[] = "{$key} = %s";
                $values[] = $value;
            }
            $query .= ' WHERE ' . implode(' AND ', $conditions);
            $query = $this->wpdb->prepare($query, ...$values);
        }

        return (int)$this->wpdb->get_var($query);
    }

    /**
     * Fire a WordPress action for testing.
     *
     * @param string $hook Hook name
     * @param array  $args Arguments to pass
     * @return void
     * @requirement REQ-TESTING-INTEGRATION-001
     */
    protected function fireAction(string $hook, array $args = []): void
    {
        do_action($hook, ...$args);
    }

    /**
     * Apply a WordPress filter for testing.
     *
     * @param string $hook Hook name
     * @param mixed  $value Value to filter
     * @param array  $args Additional arguments
     * @return mixed Filtered value
     * @requirement REQ-TESTING-INTEGRATION-001
     */
    protected function applyFilter(string $hook, $value, array $args = [])
    {
        return apply_filters($hook, $value, ...$args);
    }
}
