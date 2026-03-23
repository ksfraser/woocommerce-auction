<?php
/**
 * Redis Interface
 * 
 * Mock interface for Redis client
 * Used for type hints and mocking in tests
 * 
 * @package Tests
 */

/**
 * Redis-like interface for mocking
 */
interface RedisInterface
{
    /**
     * Ping Redis
     * @return mixed
     */
    public function ping();

    /**
     * Add value to sorted set
     * @param string $key
     * @param mixed $score
     * @param string $member
     * @return mixed
     */
    public function zAdd($key, $score, $member);

    /**
     * Get range from sorted set
     * @param string $key
     * @param int $start
     * @param int $end
     * @return array
     */
    public function zRange($key, $start, $end);

    /**
     * Remove from sorted set
     * @param string $key
     * @param string $member
     * @return mixed
     */
    public function zRem($key, $member);

    /**
     * Get cardinality of sorted set
     * @param string $key
     * @return int
     */
    public function zCard($key);

    /**
     * Get score of member in sorted set
     * @param string $key
     * @param string $member
     * @return mixed
     */
    public function zScore($key, $member);

    /**
     * Set hash field
     * @param string $key
     * @param string $field
     * @param mixed $value
     * @return mixed
     */
    public function hSet($key, $field, $value);

    /**
     * Get hash field
     * @param string $key
     * @param string $field
     * @return mixed
     */
    public function hGet($key, $field);

    /**
     * Delete hash field
     * @param string $key
     * @param string $field
     * @return mixed
     */
    public function hDel($key, $field);

    /**
     * Push to list
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    public function lPush($key, $value);

    /**
     * Get range from list
     * @param string $key
     * @param int $start
     * @param int $end
     * @return array
     */
    public function lRange($key, $start, $end);

    /**
     * Delete key
     * @param mixed $keys
     * @return mixed
     */
    public function del(...$keys);

    /**
     * Set expiration
     * @param string $key
     * @param int $ttl
     * @return mixed
     */
    public function expire($key, $ttl);
}
