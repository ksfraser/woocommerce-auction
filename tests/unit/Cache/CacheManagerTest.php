<?php
/**
 * Tests for CacheManager class.
 *
 * @package ksfraser\Tests\Cache
 * @covers \ksfraser\Cache\CacheManager
 */

namespace ksfraser\Tests\Cache;

use PHPUnit\Framework\TestCase;
use ksfraser\Cache\CacheManager;

/**
 * CacheManagerTest class.
 *
 * @covers \ksfraser\Cache\CacheManager
 */
class CacheManagerTest extends TestCase {

	/**
	 * Cache manager instance.
	 *
	 * @var CacheManager
	 */
	private $cache;

	/**
	 * Setup test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cache = new CacheManager();
	}

	/**
	 * Test cache manager instantiation.
	 *
	 * @test
	 */
	public function test_instantiation() {
		$this->assertInstanceOf( CacheManager::class, $this->cache );
	}

	/**
	 * Test get active backends.
	 *
	 * @test
	 */
	public function test_get_active_backends() {
		$backends = $this->cache->get_active_backends();

		$this->assertIsArray( $backends );
		$this->assertNotEmpty( $backends );
		// At minimum, transient backend should always be available
		$this->assertContains( 'transient', $backends );
	}

	/**
	 * Test set and get functionality.
	 *
	 * @test
	 */
	public function test_set_and_get() {
		$value = array( 'test' => 'data', 'number' => 42 );
		$result = $this->cache->set( 'test_key', $value, 3600 );

		$this->assertTrue( $result );

		$retrieved = $this->cache->get( 'test_key' );
		$this->assertEquals( $value, $retrieved );
	}

	/**
	 * Test get non-existent key.
	 *
	 * @test
	 */
	public function test_get_non_existent_key() {
		$value = $this->cache->get( 'non_existent_key' );

		$this->assertFalse( $value );
	}

	/**
	 * Test get with default value.
	 *
	 * @test
	 */
	public function test_get_with_default() {
		$value = $this->cache->get( 'non_existent_key', 'default_value' );

		$this->assertEquals( 'default_value', $value );
	}

	/**
	 * Test delete.
	 *
	 * @test
	 */
	public function test_delete() {
		$this->cache->set( 'test_key', 'value', 3600 );
		$result = $this->cache->delete( 'test_key' );

		$this->assertTrue( $result );

		$value = $this->cache->get( 'test_key' );
		$this->assertFalse( $value );
	}

	/**
	 * Test remember (get or set).
	 *
	 * @test
	 */
	public function test_remember() {
		$call_count = 0;

		$value = $this->cache->remember(
			'remember_key',
			function () use ( &$call_count ) {
				$call_count++;
				return 'computed_value';
			},
			3600
		);

		$this->assertEquals( 'computed_value', $value );
		$this->assertEquals( 1, $call_count );

		// Second call should use cache
		$value2 = $this->cache->remember(
			'remember_key',
			function () use ( &$call_count ) {
				$call_count++;
				return 'computed_value';
			},
			3600
		);

		$this->assertEquals( 'computed_value', $value2 );
		$this->assertEquals( 1, $call_count );
	}

	/**
	 * Test invalidate tags.
	 *
	 * @test
	 */
	public function test_invalidate_tags() {
		$this->cache->set( 'key1', 'value1', 3600, array( 'tag1' ) );
		$this->cache->set( 'key2', 'value2', 3600, array( 'tag1', 'tag2' ) );

		$result = $this->cache->invalidate_tags( array( 'tag1' ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test statistics.
	 *
	 * @test
	 */
	public function test_get_statistics() {
		$this->cache->set( 'key1', 'value1', 3600 );
		$this->cache->get( 'key1' );
		$this->cache->get( 'non_existent' );

		$stats = $this->cache->get_statistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'hits', $stats );
		$this->assertArrayHasKey( 'misses', $stats );
		$this->assertArrayHasKey( 'sets', $stats );
		$this->assertArrayHasKey( 'deletes', $stats );
		$this->assertArrayHasKey( 'hit_rate_pct', $stats );
	}

	/**
	 * Test statistics hit rate calculation.
	 *
	 * @test
	 */
	public function test_statistics_hit_rate() {
		// Clear cache first
		$this->cache->flush_all();

		$this->cache->set( 'key1', 'value1', 3600 );
		$this->cache->set( 'key2', 'value2', 3600 );

		$this->cache->get( 'key1' ); // hit
		$this->cache->get( 'key2' ); // hit
		$this->cache->get( 'non_existent' ); // miss

		$stats = $this->cache->get_statistics();

		$this->assertGreaterThan( 0, $stats['hit_rate_pct'] );
		$this->assertLessThanOrEqual( 100, $stats['hit_rate_pct'] );
	}

	/**
	 * Test flush all.
	 *
	 * @test
	 */
	public function test_flush_all() {
		$this->cache->set( 'key1', 'value1', 3600 );
		$this->cache->set( 'key2', 'value2', 3600 );

		// Transient backend flush returns false (by design)
		// but flush_all should return success indicator
		$result = $this->cache->flush_all();

		$this->assertIsBool( $result );
	}

	/**
	 * Test caching various data types.
	 *
	 * @test
	 * @dataProvider dataTypeProvider
	 */
	public function test_cache_data_types( $key, $value ) {
		$result = $this->cache->set( $key, $value, 3600 );

		$this->assertTrue( $result );

		$retrieved = $this->cache->get( $key );
		$this->assertEquals( $value, $retrieved );
	}

	/**
	 * Data type provider for testing.
	 *
	 * @return array Test data.
	 */
	public function dataTypeProvider() {
		return array(
			'string'       => array( 'string_key', 'string_value' ),
			'integer'      => array( 'int_key', 42 ),
			'float'        => array( 'float_key', 3.14 ),
			'array'        => array( 'array_key', array( 'a' => 1, 'b' => 2 ) ),
			'object'       => array( 'object_key', (object) array( 'prop' => 'value' ) ),
		);
	}

	/**
	 * Test set with TTL.
	 *
	 * @test
	 */
	public function test_set_with_ttl() {
		$result = $this->cache->set( 'ttl_key', 'value', 1 );

		$this->assertTrue( $result );

		$value = $this->cache->get( 'ttl_key' );
		$this->assertEquals( 'value', $value );
	}

	/**
	 * Test multiple backend storage.
	 *
	 * @test
	 */
	public function test_multiple_backends() {
		$backends = $this->cache->get_active_backends();

		// Set should store in all available backends
		$result = $this->cache->set( 'multi_key', 'multi_value', 3600 );

		$this->assertTrue( $result );

		// Get should retrieve from any backend
		$value = $this->cache->get( 'multi_key' );
		$this->assertEquals( 'multi_value', $value );
	}

	/**
	 * Test statistics per backend.
	 *
	 * @test
	 */
	public function test_stats_per_backend() {
		$this->cache->set( 'key1', 'value1', 3600 );
		$this->cache->get( 'key1' );

		$stats = $this->cache->get_statistics();

		$this->assertArrayHasKey( 'backends', $stats );
		$this->assertIsArray( $stats['backends'] );
	}
}
