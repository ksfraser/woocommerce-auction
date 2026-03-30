<?php
/**
 * Base class for integration tests
 *
 * @package YITH_Auctions
 * @subpackage Tests\Integration
 * @version 1.0.0
 * @requirement REQ-4D-048 - Integration testing framework
 */

namespace YITH_Auctions\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\Services\PayoutService;
use YITH_Auctions\Services\SettlementBatchService;
use YITH_Auctions\Services\PayoutMethodManager;
use YITH_Auctions\Services\EncryptionService;
use YITH_Auctions\Batch\BatchScheduler;
use YITH_Auctions\Repositories\SellerPayoutRepository;
use YITH_Auctions\Repositories\PaymentProcessorRepository;
use YITH_Auctions\Repositories\SettlementBatchRepository;
use YITH_Auctions\Events\EventPublisher;
use YITH_Auctions\Logging\LoggerService;
use YITH_Auctions\Validators\PayoutMethodValidator;

/**
 * IntegrationTestCase base class for end-to-end testing
 *
 * Handles:
 * - Database setup and teardown
 * - Test data creation (sellers, auctions, payout methods)
 * - Service fixture initialization
 * - Transaction rollback for isolation
 *
 * @covers \YITH_Auctions\Services\PayoutService
 * @covers \YITH_Auctions\Services\SettlementBatchService
 * @covers \YITH_Auctions\Batch\BatchScheduler
 */
class IntegrationTestCase extends TestCase {

	/**
	 * Services under test
	 *
	 * @var array
	 */
	protected $services = [];

	/**
	 * Test database connection
	 *
	 * @var object
	 */
	protected $wpdb;

	/**
	 * Test seller IDs
	 *
	 * @var array
	 */
	protected $test_seller_ids = [];

	/**
	 * Test auction data
	 *
	 * @var array
	 */
	protected $test_auctions = [];

	/**
	 * Test payout method IDs
	 *
	 * @var array
	 */
	protected $test_payout_method_ids = [];

	/**
	 * Setup: Initialize test database and services
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Get WordPress global database
		global $wpdb;
		$this->wpdb = $wpdb;

		// Start transaction for test isolation
		$this->wpdb->query( 'START TRANSACTION' );

		// Initialize services
		$this->initializeServices();
	}

	/**
	 * Teardown: Rollback database and clean fixtures
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Rollback transaction to clean up test data
		$this->wpdb->query( 'ROLLBACK' );

		// Clean test arrays
		$this->test_seller_ids = [];
		$this->test_auctions = [];
		$this->test_payout_method_ids = [];
		$this->services = [];

		parent::tearDown();
	}

	/**
	 * Initialize service instances for testing
	 *
	 * @return void
	 * @requirement REQ-4D-048 - Service dependency injection
	 */
	protected function initializeServices(): void {
		// Initialize repositories
		$seller_payout_repo = $this->createMock( SellerPayoutRepository::class );
		$payment_processor_repo = $this->createMock( PaymentProcessorRepository::class );
		$settlement_batch_repo = $this->createMock( SettlementBatchRepository::class );

		// Initialize services
		$event_publisher = new EventPublisher();
		$logger = new LoggerService( 'integration-tests' );
		$encryption = new EncryptionService();
		$validator = new PayoutMethodValidator();

		$this->services = [
			'payout_repository' => $seller_payout_repo,
			'payment_processor_repository' => $payment_processor_repo,
			'settlement_batch_repository' => $settlement_batch_repo,
			'event_publisher' => $event_publisher,
			'logger' => $logger,
			'encryption' => $encryption,
			'validator' => $validator,
		];
	}

	/**
	 * Create test seller with specified role
	 *
	 * @param string $role User role.
	 * @return int Seller (user) ID
	 */
	protected function createTestSeller( string $role = 'vendor' ): int {
		// Create WordPress user
		$user_id = wp_create_user(
			'test_seller_' . count( $this->test_seller_ids ),
			wp_generate_password(),
			'seller_' . count( $this->test_seller_ids ) . '@example.com'
		);

		if ( is_wp_error( $user_id ) ) {
			throw new \RuntimeException( 'Failed to create test seller: ' . $user_id->get_error_message() );
		}

		// Set user role
		$user = new \WP_User( $user_id );
		$user->set_role( $role );

		// Store seller ID for cleanup
		$this->test_seller_ids[] = $user_id;

		return $user_id;
	}

	/**
	 * Create test auction with winning bid
	 *
	 * @param array $args Auction arguments.
	 * @return int Auction (product) ID
	 */
	protected function createTestAuction( array $args = [] ): int {
		$defaults = [
			'post_title' => 'Test Auction ' . count( $this->test_auctions ),
			'post_status' => 'publish',
			'post_type' => 'product',
			'post_author' => $args['seller_id'] ?? $this->createTestSeller(),
		];

		$post_id = wp_insert_post( array_merge( $defaults, $args ) );

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Failed to create test auction: ' . $post_id->get_error_message() );
		}

		// Set as auction product
		update_post_meta( $post_id, '_auction_enabled', 'yes' );
		update_post_meta( $post_id, '_auction_start_price', wp_unslash( $_POST['_auction_start_price'] ?? '10.00' ) );

		// Store auction for cleanup
		$this->test_auctions[] = $post_id;

		return $post_id;
	}

	/**
	 * Create test payout method for seller
	 *
	 * @param int    $seller_id Seller user ID.
	 * @param string $processor Payment processor name.
	 * @param array  $method_data Processor-specific method data.
	 * @return int Payout method ID
	 */
	protected function createTestPayoutMethod(
		int $seller_id,
		string $processor,
		array $method_data
	): int {
		$encryption = $this->services['encryption'];
		$validator = $this->services['validator'];

		// Validate method data
		$method_data['processor'] = $processor;
		$method_data['seller_id'] = $seller_id;
		$method_data['active'] = true;
		$method_data['deleted'] = false;

		// Insert into database
		$inserted = $this->wpdb->insert(
			"{$this->wpdb->prefix}seller_payout_methods",
			[
				'seller_id' => $seller_id,
				'processor' => $processor,
				'method_data' => $encryption->encrypt( wp_json_encode( $method_data ) ),
				'active' => 1,
				'primary' => 0,
				'deleted' => 0,
				'created_at' => current_time( 'mysql' ),
			],
			[
				'%d',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%s',
			]
		);

		if ( ! $inserted ) {
			throw new \RuntimeException( 'Failed to create test payout method' );
		}

		$method_id = $this->wpdb->insert_id;
		$this->test_payout_method_ids[] = $method_id;

		return $method_id;
	}

	/**
	 * Get service by name
	 *
	 * @param string $name Service name.
	 * @return mixed Service instance
	 * @throws \RuntimeException When service not found.
	 */
	protected function getService( string $name ) {
		if ( ! isset( $this->services[ $name ] ) ) {
			throw new \RuntimeException( "Service '$name' not initialized" );
		}

		return $this->services[ $name ];
	}

	/**
	 * Assert database record count matches expected
	 *
	 * @param int    $expected Expected count.
	 * @param string $table Table name (without prefix).
	 * @param string $where WHERE clause.
	 * @return void
	 */
	protected function assertRecordCount( int $expected, string $table, string $where = '' ): void {
		$prefix = $this->wpdb->prefix;
		$table_name = esc_sql( "{$prefix}{$table}" );
		$where_clause = $where ? " WHERE {$where}" : '';

		$query = "SELECT COUNT(*) FROM {$table_name}{$where_clause}";
		$actual = (int) $this->wpdb->get_var( $query );

		$this->assertEquals(
			$expected,
			$actual,
			"Expected {$expected} records in {$table}, got {$actual}"
		);
	}

	/**
	 * Assert database record with WHERE clause exists
	 *
	 * @param string $table Table name (without prefix).
	 * @param string $where WHERE clause.
	 * @return void
	 */
	protected function assertRecordExists( string $table, string $where ): void {
		$prefix = $this->wpdb->prefix;
		$table_name = esc_sql( "{$prefix}{$table}" );
		$where_clause = esc_sql( $where );

		$query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
		$exists = (int) $this->wpdb->get_var( $query ) > 0;

		$this->assertTrue( $exists, "Expected record in {$table} with condition: {$where}" );
	}

	/**
	 * Get database record by WHERE clause
	 *
	 * @param string $table Table name (without prefix).
	 * @param string $where WHERE clause.
	 * @return object|null Database record or null
	 */
	protected function getRecord( string $table, string $where ) {
		$prefix = $this->wpdb->prefix;
		$table_name = esc_sql( "{$prefix}{$table}" );
		$where_clause = esc_sql( $where );

		$query = "SELECT * FROM {$table_name} WHERE {$where_clause} LIMIT 1";
		return $this->wpdb->get_row( $query );
	}

	/**
	 * Get all database records matching WHERE clause
	 *
	 * @param string $table Table name (without prefix).
	 * @param string $where WHERE clause.
	 * @return array Array of records
	 */
	protected function getRecords( string $table, string $where = '' ): array {
		$prefix = $this->wpdb->prefix;
		$table_name = esc_sql( "{$prefix}{$table}" );
		$where_clause = $where ? "WHERE " . esc_sql( $where ) : '';

		$query = "SELECT * FROM {$table_name} {$where_clause}";
		$results = $this->wpdb->get_results( $query );

		return $results ? $results : [];
	}
}
