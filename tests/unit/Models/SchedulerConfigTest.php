<?php
/**
 * SchedulerConfig Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Models
 * @version    1.0.0
 * @requirement REQ-4D-040: Persist scheduler configuration options
 */

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\SchedulerConfig;

/**
 * Test SchedulerConfig value object
 *
 * @covers \WC\Auction\Models\SchedulerConfig
 */
class SchedulerConfigTest extends TestCase {

	/**
	 * Test create scheduler config
	 */
	public function testCreateSchedulerConfig() {
		$config = SchedulerConfig::create( 'polling_interval', 300 );

		$this->assertInstanceOf( SchedulerConfig::class, $config );
		$this->assertNull( $config->getId() );
		$this->assertEquals( 'polling_interval', $config->getOptionName() );
		$this->assertEquals( '300', $config->getOptionValue() );
		$this->assertInstanceOf( \DateTime::class, $config->getCreatedAt() );
		$this->assertInstanceOf( \DateTime::class, $config->getUpdatedAt() );
	}

	/**
	 * Test from database restores all properties
	 */
	public function testFromDatabaseRestoresAllProperties() {
		$created_str = '2024-01-15 10:00:00';
		$updated_str = '2024-01-15 11:30:00';
		$row         = array(
			'id'          => 42,
			'option_name' => 'max_retries',
			'option_value' => '6',
			'created_at'  => $created_str,
			'updated_at'  => $updated_str,
		);

		$config = SchedulerConfig::fromDatabase( $row );

		$this->assertEquals( 42, $config->getId() );
		$this->assertEquals( 'max_retries', $config->getOptionName() );
		$this->assertEquals( '6', $config->getOptionValue() );

		$expected_created = new \DateTime( $created_str, new \DateTimeZone( 'UTC' ) );
		$this->assertEquals( $expected_created->getTimestamp(), $config->getCreatedAt()->getTimestamp() );

		$expected_updated = new \DateTime( $updated_str, new \DateTimeZone( 'UTC' ) );
		$this->assertEquals( $expected_updated->getTimestamp(), $config->getUpdatedAt()->getTimestamp() );
	}

	/**
	 * Test with value conversion
	 */
	public function testCreateWithIntegerValue() {
		$config = SchedulerConfig::create( 'batch_size', 50 );

		$this->assertEquals( 'batch_size', $config->getOptionName() );
		$this->assertEquals( '50', $config->getOptionValue() );
	}

	/**
	 * Test to array serializes for database
	 */
	public function testToArraySerializesForDatabase() {
		$created_str = '2024-01-15 10:00:00';
		$updated_str = '2024-01-15 10:00:00';

		$created = new \DateTime( $created_str, new \DateTimeZone( 'UTC' ) );
		$updated = new \DateTime( $updated_str, new \DateTimeZone( 'UTC' ) );

		$config = SchedulerConfig::create( 'test_option', 'test_value' );
		$config->setCreatedAt( $created );
		$config->setUpdatedAt( $updated );

		$array = $config->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 'test_option', $array['option_name'] );
		$this->assertEquals( 'test_value', $array['option_value'] );
		$this->assertEquals( $created_str, $array['created_at'] );
		$this->assertEquals( $updated_str, $array['updated_at'] );
		$this->assertArrayNotHasKey( 'id', $array );
	}

	/**
	 * Test setters work correctly
	 */
	public function testSettersWorkCorrectly() {
		$config = SchedulerConfig::create( 'option1', 'value1' );
		$new_time = new \DateTime( '2024-01-15 15:00:00', new \DateTimeZone( 'UTC' ) );

		$config->setOptionValue( 'new_value' );
		$config->setUpdatedAt( $new_time );

		$this->assertEquals( 'new_value', $config->getOptionValue() );
		$this->assertEquals( $new_time->getTimestamp(), $config->getUpdatedAt()->getTimestamp() );
	}
}
