<?php
/**
 * SchedulerConfigValidator Unit Tests
 *
 * Validates scheduler configuration values
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    1.0.0
 * @requirement REQ-4D-040: Configuration validation with default values
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Services\SchedulerConfigValidator;

/**
 * @covers \WC\Auction\Services\SchedulerConfigValidator
 */
class SchedulerConfigValidatorTest extends TestCase {

	private $validator;

	protected function setUp(): void {
		$this->validator = new SchedulerConfigValidator();
	}

	/**
	 * Test validator construction
	 */
	public function testValidatorConstruction() {
		$this->assertInstanceOf( SchedulerConfigValidator::class, $this->validator );
	}

	/**
	 * Test validate retry interval accepts valid values
	 */
	public function testValidateRetryIntervalAcceptsValidValue() {
		$result = $this->validator->validateRetryInterval( '60' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate retry interval rejects zero or negative
	 */
	public function testValidateRetryIntervalRejectsNonPositive() {
		$result_zero     = $this->validator->validateRetryInterval( '0' );
		$result_negative = $this->validator->validateRetryInterval( '-10' );

		$this->assertFalse( $result_zero );
		$this->assertFalse( $result_negative );
	}

	/**
	 * Test validate retry interval rejects non-numeric
	 */
	public function testValidateRetryIntervalRejectsNonNumeric() {
		$result = $this->validator->validateRetryInterval( 'invalid' );

		$this->assertFalse( $result );
	}

	/**
	 * Test validate max attempts accepts valid range
	 */
	public function testValidateMaxAttemptsAcceptsValidRange() {
		$result_min = $this->validator->validateMaxAttempts( '1' );
		$result_max = $this->validator->validateMaxAttempts( '10' );

		$this->assertTrue( $result_min );
		$this->assertTrue( $result_max );
	}

	/**
	 * Test validate max attempts rejects out of range
	 */
	public function testValidateMaxAttemptsRejectsOutOfRange() {
		$result_zero     = $this->validator->validateMaxAttempts( '0' );
		$result_too_high = $this->validator->validateMaxAttempts( '20' );

		$this->assertFalse( $result_zero );
		$this->assertFalse( $result_too_high );
	}

	/**
	 * Test validate batch size accepts valid values
	 */
	public function testValidateBatchSizeAcceptsValidValue() {
		$result = $this->validator->validateBatchSize( '100' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate batch size rejects too small
	 */
	public function testValidateBatchSizeRejectsTooSmall() {
		$result = $this->validator->validateBatchSize( '1' );

		$this->assertFalse( $result );
	}

	/**
	 * Test validate batch size rejects too large
	 */
	public function testValidateBatchSizeRejectsTooLarge() {
		$result = $this->validator->validateBatchSize( '10000' );

		$this->assertFalse( $result );
	}

	/**
	 * Test get default retry interval
	 */
	public function testGetDefaultRetryInterval() {
		$default = $this->validator->getDefaultRetryInterval();

		$this->assertIsInt( $default );
		$this->assertGreaterThan( 0, $default );
	}

	/**
	 * Test get default max attempts
	 */
	public function testGetDefaultMaxAttempts() {
		$default = $this->validator->getDefaultMaxAttempts();

		$this->assertIsInt( $default );
		$this->assertGreaterThan( 0, $default );
	}

	/**
	 * Test get default batch size
	 */
	public function testGetDefaultBatchSize() {
		$default = $this->validator->getDefaultBatchSize();

		$this->assertIsInt( $default );
		$this->assertGreaterThan( 0, $default );
	}

	/**
	 * Test sanitize config returns sanitized array
	 */
	public function testSanitizeConfigReturnsSanitizedArray() {
		$config = [
			'retry_interval' => '  60  ',
			'max_attempts'   => '5',
			'batch_size'     => '100',
		];

		$sanitized = $this->validator->sanitizeConfig( $config );

		$this->assertIsArray( $sanitized );
		$this->assertEquals( 60, (int) $sanitized['retry_interval'] );
		$this->assertEquals( 5, (int) $sanitized['max_attempts'] );
		$this->assertEquals( 100, (int) $sanitized['batch_size'] );
	}

	/**
	 * Test validate entire config returns true for valid config
	 */
	public function testValidateConfigReturnsTrueForValid() {
		$config = [
			'retry_interval' => '60',
			'max_attempts'   => '5',
			'batch_size'     => '100',
		];

		$result = $this->validator->validate( $config );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate entire config returns false for invalid
	 */
	public function testValidateConfigReturnsFalseForInvalid() {
		$config = [
			'retry_interval' => '0',
			'max_attempts'   => '20',
			'batch_size'     => '1',
		];

		$result = $this->validator->validate( $config );

		$this->assertFalse( $result );
	}

	/**
	 * Test get validation errors returns error list
	 */
	public function testGetValidationErrorsReturnsErrorList() {
		$config = [
			'retry_interval' => 'invalid',
			'max_attempts'   => '50',
		];

		$this->validator->validate( $config );
		$errors = $this->validator->getErrors();

		$this->assertIsArray( $errors );
		$this->assertGreaterThan( 0, count( $errors ) );
	}

	/**
	 * Test merge with defaults fills missing values
	 */
	public function testMergeWithDefaultsFillsMissingValues() {
		$partial_config = [
			'retry_interval' => '120',
		];

		$complete_config = $this->validator->mergeWithDefaults( $partial_config );

		$this->assertArrayHasKey( 'retry_interval', $complete_config );
		$this->assertArrayHasKey( 'max_attempts', $complete_config );
		$this->assertArrayHasKey( 'batch_size', $complete_config );
		$this->assertEquals( '120', $complete_config['retry_interval'] );
	}
}
