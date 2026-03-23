<?php

namespace Yith\Auctions\Traits;

/**
 * RepositoryTrait - Provides common repository methods for data access.
 *
 * Implements standard CRUD operations, query building, and transaction support.
 * All database operations use prepared statements for SQL injection prevention.
 *
 * @package Yith\Auctions\Traits
 * @requirement REQ-REPOSITORY-001: Standardized data access patterns
 * @requirement REQ-SECURITY-SQL-001: SQL injection prevention via prepared statements
 */
trait RepositoryTrait
{
    /**
     * @var \wpdb WordPress database object
     */
    protected \wpdb $wpdb;

    /**
     * @var string Database table name (set by child class)
     */
    protected string $table;

    /**
     * Initialize repository with database connection.
     *
     * @param \wpdb $wpdb WordPress database object
     * @requirement REQ-REPOSITORY-001
     */
    protected function initRepository(\wpdb $wpdb): void
    {
        $this->wpdb = $wpdb;
        if (empty($this->table)) {
            throw new \RuntimeException('Table name must be defined in child class');
        }
    }

    /**
     * Get table name with WordPress prefix.
     *
     * @return string Full table name
     * @requirement REQ-REPOSITORY-001
     */
    protected function getTable(): string
    {
        return $this->wpdb->prefix . $this->table;
    }

    /**
     * Execute a prepared query and return results.
     *
     * @param string $query  SQL query with %s, %d, %f placeholders
     * @param array  $params Query parameters
     * @param string $output Return type (ARRAY_A, ARRAY_N, OBJECT)
     * @return array Query results
     * @requirement REQ-REPOSITORY-001
     * @requirement REQ-SECURITY-SQL-001
     */
    protected function query(string $query, array $params = [], string $output = ARRAY_A): array
    {
        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, ...$params);
        }

        $results = $this->wpdb->get_results($query, $output);

        if ($this->wpdb->last_error) {
            throw new \RuntimeException('Database error: ' . $this->wpdb->last_error);
        }

        return is_array($results) ? $results : [];
    }

    /**
     * Execute a query returning a single row.
     *
     * @param string $query  SQL query with %s, %d, %f placeholders
     * @param array  $params Query parameters
     * @param string $output Return type (ARRAY_A, OBJECT)
     * @return array|object|null Single row or null
     * @requirement REQ-REPOSITORY-001
     * @requirement REQ-SECURITY-SQL-001
     */
    protected function queryRow(string $query, array $params = [], string $output = ARRAY_A)
    {
        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, ...$params);
        }

        $result = $this->wpdb->get_row($query, $output);

        if ($this->wpdb->last_error) {
            throw new \RuntimeException('Database error: ' . $this->wpdb->last_error);
        }

        return $result;
    }

    /**
     * Execute a query returning a scalar value.
     *
     * @param string $query  SQL query with %s, %d, %f placeholders
     * @param array  $params Query parameters
     * @return mixed Scalar value or null
     * @requirement REQ-REPOSITORY-001
     * @requirement REQ-SECURITY-SQL-001
     */
    protected function queryVar(string $query, array $params = [])
    {
        if (!empty($params)) {
            $query = $this->wpdb->prepare($query, ...$params);
        }

        $result = $this->wpdb->get_var($query);

        if ($this->wpdb->last_error) {
            throw new \RuntimeException('Database error: ' . $this->wpdb->last_error);
        }

        return $result;
    }

    /**
     * Begin a database transaction.
     *
     * @return bool Success
     * @requirement REQ-REPOSITORY-001
     */
    protected function beginTransaction(): bool
    {
        return (bool)$this->wpdb->query('START TRANSACTION');
    }

    /**
     * Commit a database transaction.
     *
     * @return bool Success
     * @requirement REQ-REPOSITORY-001
     */
    protected function commit(): bool
    {
        return (bool)$this->wpdb->query('COMMIT');
    }

    /**
     * Rollback a database transaction.
     *
     * @return bool Success
     * @requirement REQ-REPOSITORY-001
     */
    protected function rollback(): bool
    {
        return (bool)$this->wpdb->query('ROLLBACK');
    }
}
