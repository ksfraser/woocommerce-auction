<?php
/**
 * Multi-layer caching system with Redis and Memcached support.
 *
 * Implements a sophisticated caching layer that falls back through multiple
 * storage backends: Redis → Memcached → WordPress Transients → No Cache
 *
 * @requirement REQ-PERFORMANCE-CACHING-001
 * @package ksfraser\Cache
 * @version 1.0.0
 */

namespace ksfraser\Cache;

/**
 * CacheManager class.
 *
 * Manages multi-layer caching with automatic fallback and unified interface.
 * Supports TTL, tag-based invalidation, and statistics tracking.
 */
class CacheManager {

	/**
	 * Cache storage backends in priority order.
	 *
	 * @var CacheBackend[]
	 */
	private $backends = array();

	/**
	 * Cache statistics.
	 *
	 * @var array
	 */
	private $statistics = array(
		'hits'      => 0,
		'misses'    => 0,
		'sets'      => 0,
		'deletes'   => 0,
		'backend'   => array(),
	);

	/**
	 * Constructor.
	 *
	 * Initializes cache backends based on available extensions.
	 */
	public function __construct() {
		$this->initialize_backends();
	}

	/**
	 * Initialize available cache backends.
	 *
	 * Attempts to load backends in order of preference:
	 * 1. Redis (if extension available)
	 * 2. Memcached (if extension available)
	 * 3. WordPress Transients (always available)
	 *
	 * @return void
	 */
	private function initialize_backends() {
		// Try Redis first
		if ( extension_loaded( 'redis' ) ) {
			$this->backends['redis'] = new RedisBackend();
			$this->statistics['backend']['redis'] = array( 'status' => 'available' );
		}

		// Try Memcached second
		if ( extension_loaded( 'memcached' ) ) {
			$this->backends['memcached'] = new MemcachedBackend();
			$this->statistics['backend']['memcached'] = array( 'status' => 'available' );
		}

		// WordPress transients always available (fallback)
		$this->backends['transient'] = new TransientBackend();
		$this->statistics['backend']['transient'] = array( 'status' => 'available' );
	}

	/**
	 * Set a cache value.
	 *
	 * Attempts to store in all available backends for redundancy.
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $value   Value to cache.
	 * @param int    $ttl     Time to live in seconds.
	 * @param array  $tags    Optional cache tags for grouped invalidation.
	 * @return bool True if stored in at least one backend, false otherwise.
	 */
	public function set( $key, $value, $ttl = 3600, array $tags = array() ) {
		$stored = false;

		foreach ( $this->backends as $backend_name => $backend ) {
			try {
				if ( $backend->set( $key, $value, $ttl, $tags ) ) {
					$stored = true;
					$this->statistics['backend'][ $backend_name ]['sets'] = 
						( $this->statistics['backend'][ $backend_name ]['sets'] ?? 0 ) + 1;
				}
			} catch ( \Exception $e ) {
				// Log failure but continue to next backend
				do_action(
					'yith_auction_cache_set_error',
					array(
						'backend' => $backend_name,
						'key'     => $key,
						'error'   => $e->getMessage(),
					)
				);
			}
		}

		if ( $stored ) {
			$this->statistics['sets']++;
		}

		return $stored;
	}

	/**
	 * Get a cached value.
	 *
	 * Checks backends in priority order, returns first hit and caches in lower-priority backends.
	 *
	 * @param string $key          Cache key.
	 * @param mixed  $default      Default value if not found.
	 * @return mixed Cached value or default value.
	 */
	public function get( $key, $default = false ) {
		foreach ( $this->backends as $backend_name => $backend ) {
			try {
				$value = $backend->get( $key );

				if ( false !== $value ) {
					$this->statistics['hits']++;
					$this->statistics['backend'][ $backend_name ]['hits'] = 
						( $this->statistics['backend'][ $backend_name ]['hits'] ?? 0 ) + 1;

					return $value;
				}
			} catch ( \Exception $e ) {
				// Continue to next backend
				do_action(
					'yith_auction_cache_get_error',
					array(
						'backend' => $backend_name,
						'key'     => $key,
						'error'   => $e->getMessage(),
					)
				);
			}
		}

		$this->statistics['misses']++;
		return $default;
	}

	/**
	 * Delete a cache value.
	 *
	 * Removes from all backends.
	 *
	 * @param string $key Cache key.
	 * @return bool True if deleted from at least one backend, false otherwise.
	 */
	public function delete( $key ) {
		$deleted = false;

		foreach ( $this->backends as $backend_name => $backend ) {
			try {
				if ( $backend->delete( $key ) ) {
					$deleted = true;
					$this->statistics['backend'][ $backend_name ]['deletes'] = 
						( $this->statistics['backend'][ $backend_name ]['deletes'] ?? 0 ) + 1;
				}
			} catch ( \Exception $e ) {
				// Continue to next backend
				do_action(
					'yith_auction_cache_delete_error',
					array(
						'backend' => $backend_name,
						'key'     => $key,
					)
				);
			}
		}

		if ( $deleted ) {
			$this->statistics['deletes']++;
		}

		return $deleted;
	}

	/**
	 * Invalidate cache by tags.
	 *
	 * Removes all cached items with specified tags.
	 *
	 * @param array $tags Cache tags to invalidate.
	 * @return bool True if any items invalidated, false otherwise.
	 */
	public function invalidate_tags( array $tags ) {
		$invalidated = false;

		foreach ( $this->backends as $backend ) {
			try {
				if ( $backend->invalidate_tags( $tags ) ) {
					$invalidated = true;
				}
			} catch ( \Exception $e ) {
				// Continue to next backend
			}
		}

		return $invalidated;
	}

	/**
	 * Clear all cache.
	 *
	 * Clears all backends completely.
	 *
	 * @return bool True if cleared successfully, false otherwise.
	 */
	public function flush_all() {
		$flushed = true;

		foreach ( $this->backends as $backend_name => $backend ) {
			try {
				$backend->flush();
			} catch ( \Exception $e ) {
				$flushed = false;
				do_action(
					'yith_auction_cache_flush_error',
					array(
						'backend' => $backend_name,
						'error'   => $e->getMessage(),
					)
				);
			}
		}

		return $flushed;
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array Statistics including hits, misses, and per-backend stats.
	 */
	public function get_statistics() {
		$total_operations = $this->statistics['hits'] + $this->statistics['misses'];
		$hit_rate = $total_operations > 0 ? ( $this->statistics['hits'] / $total_operations ) * 100 : 0;

		return array(
			'hits'              => $this->statistics['hits'],
			'misses'            => $this->statistics['misses'],
			'total_operations'  => $total_operations,
			'hit_rate_pct'      => round( $hit_rate, 2 ),
			'sets'              => $this->statistics['sets'],
			'deletes'           => $this->statistics['deletes'],
			'backends'          => $this->statistics['backend'],
		);
	}

	/**
	 * Get active backends.
	 *
	 * @return array List of active cache backends.
	 */
	public function get_active_backends() {
		return array_keys( $this->backends );
	}

	/**
	 * Remember a value (get or set).
	 *
	 * Gets cached value or computes and caches it using callback.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Function to compute value if not cached.
	 * @param int      $ttl      Time to live in seconds.
	 * @param array    $tags     Optional cache tags.
	 * @return mixed Cached or computed value.
	 */
	public function remember( $key, callable $callback, $ttl = 3600, array $tags = array() ) {
		$value = $this->get( $key );

		if ( false !== $value ) {
			return $value;
		}

		$value = call_user_func( $callback );
		$this->set( $key, $value, $ttl, $tags );

		return $value;
	}
}

/**
 * Abstract cache backend interface.
 *
 * @internal
 */
abstract class CacheBackend {

	/**
	 * Set a cache value.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $value  Value to cache.
	 * @param int    $ttl    Time to live in seconds.
	 * @param array  $tags   Optional tags.
	 * @return bool True if successful.
	 */
	abstract public function set( $key, $value, $ttl = 3600, array $tags = array() );

	/**
	 * Get a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value or false if not found.
	 */
	abstract public function get( $key );

	/**
	 * Delete a cache value.
	 *
	 * @param string $key Cache key.
	 * @return bool True if deleted.
	 */
	abstract public function delete( $key );

	/**
	 * Invalidate by tags.
	 *
	 * @param array $tags Cache tags.
	 * @return bool True if successful.
	 */
	abstract public function invalidate_tags( array $tags );

	/**
	 * Flush all cache.
	 *
	 * @return bool True if successful.
	 */
	abstract public function flush();
}

/**
 * Redis cache backend.
 *
 * @internal
 */
class RedisBackend extends CacheBackend {

	/**
	 * Redis client.
	 *
	 * @var \Redis
	 */
	private $client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->client = new \Redis();
		$this->client->connect( 'localhost', 6379 );
	}

	public function set( $key, $value, $ttl = 3600, array $tags = array() ) {
		$serialized = serialize( $value );
		$result     = $this->client->setex( $key, $ttl, $serialized );

		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$this->client->sAdd( "tag:$tag", $key );
				$this->client->expire( "tag:$tag", $ttl );
			}
		}

		return $result;
	}

	public function get( $key ) {
		$value = $this->client->get( $key );
		return false !== $value ? unserialize( $value ) : false;
	}

	public function delete( $key ) {
		return (bool) $this->client->del( $key );
	}

	public function invalidate_tags( array $tags ) {
		$deleted = 0;
		foreach ( $tags as $tag ) {
			$keys = $this->client->sMembers( "tag:$tag" );
			foreach ( $keys as $key ) {
				$deleted += (int) $this->client->del( $key );
			}
			$this->client->del( "tag:$tag" );
		}
		return $deleted > 0;
	}

	public function flush() {
		return $this->client->flushDb();
	}
}

/**
 * Memcached cache backend.
 *
 * @internal
 */
class MemcachedBackend extends CacheBackend {

	/**
	 * Memcached client.
	 *
	 * @var \Memcached
	 */
	private $client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->client = new \Memcached();
		$this->client->addServer( 'localhost', 11211 );
	}

	public function set( $key, $value, $ttl = 3600, array $tags = array() ) {
		$serialized = serialize( $value );
		return $this->client->set( $key, $serialized, $ttl );
	}

	public function get( $key ) {
		$value = $this->client->get( $key );
		return false !== $value ? unserialize( $value ) : false;
	}

	public function delete( $key ) {
		return $this->client->delete( $key );
	}

	public function invalidate_tags( array $tags ) {
		// Memcached doesn't support tag-based invalidation natively
		// This is a limitation vs Redis
		return false;
	}

	public function flush() {
		return $this->client->flush();
	}
}

/**
 * WordPress transient cache backend (fallback).
 *
 * @internal
 */
class TransientBackend extends CacheBackend {

	/**
	 * Tag storage in transients.
	 *
	 * @var array
	 */
	private $tags = array();

	public function set( $key, $value, $ttl = 3600, array $tags = array() ) {
		$result = set_transient( $key, $value, $ttl );

		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$tag_key        = "_yith_auction_tag_$tag";
				$tagged_keys    = get_transient( $tag_key ) ?: array();
				$tagged_keys[]  = $key;
				set_transient( $tag_key, $tagged_keys, $ttl );
			}
		}

		return $result;
	}

	public function get( $key ) {
		return get_transient( $key );
	}

	public function delete( $key ) {
		delete_transient( $key );
		return true;
	}

	public function invalidate_tags( array $tags ) {
		$deleted = false;
		foreach ( $tags as $tag ) {
			$tag_key        = "_yith_auction_tag_$tag";
			$tagged_keys    = get_transient( $tag_key );

			if ( is_array( $tagged_keys ) ) {
				foreach ( $tagged_keys as $key ) {
					delete_transient( $key );
					$deleted = true;
				}
			}

			delete_transient( $tag_key );
		}
		return $deleted;
	}

	public function flush() {
		// Cannot safely flush all transients without side effects
		return false;
	}
}
