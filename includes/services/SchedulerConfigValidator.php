<?php
/**
 * SchedulerConfigValidator Service
 *
 * Validates scheduler configuration values with default fallbacks.
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    1.0.0
 * @requirement REQ-4D-040: Configuration validation with defaults
 */

namespace WC\Auction\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SchedulerConfigValidator - Configuration validation service
 *
 * Manages:
 * - Validation of scheduler configuration values
 * - Default value management
 * - Configuration sanitization
 * - Error tracking
 *
 * UML Class Diagram:
 * ```
 * SchedulerConfigValidator (Validation Service)
 * ├── Constants:
 * │   ├── DEFAULT_RETRY_INTERVAL: 60 (seconds)
 * │   ├── DEFAULT_MAX_ATTEMPTS: 6
 * │   ├── DEFAULT_BATCH_SIZE: 50
 * │   ├── MIN_RETRY_INTERVAL: 30 (seconds)
 * │   ├── MAX_RETRY_INTERVAL: 3600 (seconds)
 * │   ├── MIN_BATCH_SIZE: 10
 * │   └── MAX_BATCH_SIZE: 500
 * ├── Public Methods:
 * │   ├── validate(config): bool
 * │   ├── validateRetryInterval(value): bool
 * │   ├── validateMaxAttempts(value): bool
 * │   ├── validateBatchSize(value): bool
 * │   ├── sanitizeConfig(config): array
 * │   ├── mergeWithDefaults(config): array
 * │   ├── getErrors(): string[]
 * │   ├── getDefault...(): various
 * │   └── getDefaultConfig(): array
 * └── Private Methods:
 *     └── addError(message): void
 * ```
 *
 * Design Patterns:
 * - Validator: Validates configuration data
 * - Service: Encapsulates validation logic
 * - Template Method: Standard validation flow
 *
 * @requirement REQ-4D-040: Configuration validation with defaults
 */
class SchedulerConfigValidator {

	/**
	 * Default retry interval in seconds (1 minute)
	 */
	const DEFAULT_RETRY_INTERVAL = 60;

	/**
	 * Default maximum retry attempts
	 */
	const DEFAULT_MAX_ATTEMPTS = 6;

	/**
	 * Default batch size
	 */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Minimum retry interval in seconds (30 seconds)
	 */
	const MIN_RETRY_INTERVAL = 30;

	/**
	 * Maximum retry interval in seconds (1 hour)
	 */
	const MAX_RETRY_INTERVAL = 3600;

	/**
	 * Minimum batch size
	 */
	const MIN_BATCH_SIZE = 10;

	/**
	 * Maximum batch size
	 */
	const MAX_BATCH_SIZE = 500;

	/**
	 * Minimum max attempts
	 */
	const MIN_MAX_ATTEMPTS = 1;

	/**
	 * Maximum max attempts
	 */
	const MAX_MAX_ATTEMPTS = 15;

	/**
	 * Validation errors
	 *
	 * @var string[]
	 */
	private $errors = [];

	/**
	 * Constructor
	 *
	 * @requirement REQ-4D-040: Initialize validator
	 */
	public function __construct() {
		$this->errors = [];
	}

	/**
	 * Validate entire configuration array
	 *
	 * @param array $config Configuration array to validate
	 * @return bool True if all values valid, false otherwise
	 * @requirement REQ-4D-040: Validate complete config
	 */
	public function validate( array $config ): bool {
		$this->errors = [];
		$valid        = true;

		// Validate retry_interval if present
		if ( isset( $config['retry_interval'] ) ) {
			if ( ! $this->validateRetryInterval( (string) $config['retry_interval'] ) ) {
				$this->addError( 'Invalid retry_interval: must be between ' . self::MIN_RETRY_INTERVAL . ' and ' . self::MAX_RETRY_INTERVAL );
				$valid = false;
			}
		}

		// Validate max_attempts if present
		if ( isset( $config['max_attempts'] ) ) {
			if ( ! $this->validateMaxAttempts( (string) $config['max_attempts'] ) ) {
				$this->addError( 'Invalid max_attempts: must be between ' . self::MIN_MAX_ATTEMPTS . ' and ' . self::MAX_MAX_ATTEMPTS );
				$valid = false;
			}
		}

		// Validate batch_size if present
		if ( isset( $config['batch_size'] ) ) {
			if ( ! $this->validateBatchSize( (string) $config['batch_size'] ) ) {
				$this->addError( 'Invalid batch_size: must be between ' . self::MIN_BATCH_SIZE . ' and ' . self::MAX_BATCH_SIZE );
				$valid = false;
			}
		}

		return $valid;
	}

	/**
	 * Validate retry interval value
	 *
	 * Retry interval must be:
	 * - Numeric
	 * - Positive
	 * - Within configured range
	 *
	 * @param string $value Retry interval value to validate
	 * @return bool True if valid, false otherwise
	 * @requirement REQ-4D-040: Validate retry interval
	 */
	public function validateRetryInterval( string $value ): bool {
		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$interval = (int) $value;

		return $interval >= self::MIN_RETRY_INTERVAL && $interval <= self::MAX_RETRY_INTERVAL;
	}

	/**
	 * Validate maximum attempts value
	 *
	 * Max attempts must be:
	 * - Numeric
	 * - Positive
	 * - Within configured range
	 *
	 * @param string $value Max attempts value to validate
	 * @return bool True if valid, false otherwise
	 * @requirement REQ-4D-040: Validate max attempts
	 */
	public function validateMaxAttempts( string $value ): bool {
		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$max = (int) $value;

		return $max >= self::MIN_MAX_ATTEMPTS && $max <= self::MAX_MAX_ATTEMPTS;
	}

	/**
	 * Validate batch size value
	 *
	 * Batch size must be:
	 * - Numeric
	 * - Positive
	 * - Within configured range
	 *
	 * @param string $value Batch size value to validate
	 * @return bool True if valid, false otherwise
	 * @requirement REQ-4D-040: Validate batch size
	 */
	public function validateBatchSize( string $value ): bool {
		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$size = (int) $value;

		return $size >= self::MIN_BATCH_SIZE && $size <= self::MAX_BATCH_SIZE;
	}

	/**
	 * Sanitize configuration array
	 *
	 * Trims whitespace and converts to integers.
	 *
	 * @param array $config Configuration array to sanitize
	 * @return array Sanitized configuration
	 * @requirement REQ-4D-040: Sanitize configuration
	 */
	public function sanitizeConfig( array $config ): array {
		$sanitized = [];

		foreach ( $config as $key => $value ) {
			if ( is_string( $value ) ) {
				$sanitized[ $key ] = trim( $value );
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Merge partial config with defaults
	 *
	 * Fills in missing values from defaults.
	 *
	 * @param array $config Partial configuration
	 * @return array Complete configuration with defaults
	 * @requirement REQ-4D-040: Merge with defaults
	 */
	public function mergeWithDefaults( array $config ): array {
		$defaults = $this->getDefaultConfig();

		return array_merge( $defaults, $config );
	}

	/**
	 * Get default configuration
	 *
	 * @return array Default configuration values
	 * @requirement REQ-4D-040: Get default configuration
	 */
	public function getDefaultConfig(): array {
		return [
			'retry_interval' => self::DEFAULT_RETRY_INTERVAL,
			'max_attempts'   => self::DEFAULT_MAX_ATTEMPTS,
			'batch_size'     => self::DEFAULT_BATCH_SIZE,
		];
	}

	/**
	 * Get default retry interval
	 *
	 * @return int Default retry interval in seconds
	 */
	public function getDefaultRetryInterval(): int {
		return self::DEFAULT_RETRY_INTERVAL;
	}

	/**
	 * Get default max attempts
	 *
	 * @return int Default maximum attempts
	 */
	public function getDefaultMaxAttempts(): int {
		return self::DEFAULT_MAX_ATTEMPTS;
	}

	/**
	 * Get default batch size
	 *
	 * @return int Default batch size
	 */
	public function getDefaultBatchSize(): int {
		return self::DEFAULT_BATCH_SIZE;
	}

	/**
	 * Get validation errors
	 *
	 * @return string[] Array of error messages
	 */
	public function getErrors(): array {
		return $this->errors;
	}

	/**
	 * Check if there are validation errors
	 *
	 * @return bool True if errors exist, false otherwise
	 */
	public function hasErrors(): bool {
		return count( $this->errors ) > 0;
	}

	/**
	 * Clear validation errors
	 *
	 * @return void
	 */
	public function clearErrors(): void {
		$this->errors = [];
	}

	/**
	 * Add validation error
	 *
	 * @param string $message Error message
	 * @return void
	 */
	private function addError( string $message ): void {
		$this->errors[] = $message;
	}
}
