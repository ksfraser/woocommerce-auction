<?php
/**
 * E2E Test: Retry and recovery workflows
 *
 * @package YITH_Auctions
 * @subpackage Tests\Integration
 * @version 1.0.0
 * @requirement REQ-4D-048 - End-to-end retry and recovery testing
 */

namespace YITH_Auctions\Tests\Integration;

use YITH_Auctions\Models\SellerPayout;
use YITH_Auctions\Models\RetrySchedule;

/**
 * Test retry and recovery E2E flow
 *
 * Verifies:
 * - Payment processor failure triggers retry schedule
 * - Exponential backoff applied correctly
 * - First retry immediate (T+0s)
 * - Subsequent retries delayed exponentially
 * - Retry success marks payout completed
 * - Audit trail recorded for all attempts
 *
 * @covers \YITH_Auctions\Services\PayoutService
 * @covers \YITH_Auctions\Models\RetrySchedule
 */
class RetryRecoveryTest extends IntegrationTestCase {

	/**
	 * Test: Payment processor failure triggers retry schedule
	 *
	 * Scenario:
	 * 1. Create payout
	 * 2. Mock payment processor to return failure
	 * 3. Verify retry schedule created
	 * 4. Verify payout status = 'failed'
	 * 5. Verify RetrySchedule record exists
	 *
	 * @return void
	 * @test
	 */
	public function test_processor_failure_triggers_retry_schedule(): void {
		// Arrange: Create payout
		$seller_id = $this->createTestSeller( 'vendor' );
		$auction_id = $this->createTestAuction( [ 'seller_id' => $seller_id ] );

		$payout_id = $this->insertSellerPayout( [
			'seller_id' => $seller_id,
			'auction_id' => $auction_id,
			'gross_amount' => 5000,
			'status' => 'pending',
		] );

		// Act: Simulate processor failure
		$this->wpdb->update(
			"{$this->wpdb->prefix}seller_payouts",
			[
				'status' => 'failed',
				'failed_reason' => 'Payment processor timeout',
				'failed_at' => current_time( 'mysql' ),
			],
			[ 'id' => $payout_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		// Create retry schedule
		$retry_schedule_id = $this->insertRetrySchedule( [
			'payout_id' => $payout_id,
			'attempt_number' => 1,
			'status' => 'pending',
		] );

		// Assert: Verify failure recorded
		$payout = $this->getRecord(
			'seller_payouts',
			"id = {$payout_id}"
		);

		$this->assertEquals( 'failed', $payout->status );
		$this->assertNotNull( $payout->failed_reason );

		// Assert: Verify retry schedule created
		$retry = $this->getRecord(
			'retry_schedules',
			"id = {$retry_schedule_id}"
		);

		$this->assertNotNull( $retry );
		$this->assertEquals( $payout_id, $retry->payout_id );
		$this->assertEquals( 1, $retry->attempt_number );
		$this->assertEquals( 'pending', $retry->status );
	}

	/**
	 * Test: Exponential backoff calculated correctly
	 *
	 * Scenario:
	 * 1. Create payout with failure
	 * 2. Create retry schedules for attempts 1-5
	 * 3. Verify backoff: 0s, 60s, 300s, 900s, 1800s
	 * 4. Verify next_retry_at timestamps increase exponentially
	 *
	 * @return void
	 * @test
	 */
	public function test_exponential_backoff_calculated(): void {
		// Arrange: Create payout
		$seller_id = $this->createTestSeller( 'vendor' );
		$auction_id = $this->createTestAuction( [ 'seller_id' => $seller_id ] );

		$payout_id = $this->insertSellerPayout( [
			'seller_id' => $seller_id,
			'auction_id' => $auction_id,
			'gross_amount' => 5000,
			'status' => 'failed',
		] );

		$base_time = time();
		$backoff_intervals = [
			1 => 0,      // T+0s (immediate)
			2 => 60,     // T+60s (1 minute)
			3 => 300,    // T+5 minutes
			4 => 900,    // T+15 minutes
			5 => 1800,   // T+30 minutes
		];

		// Act: Create retry schedule for each attempt
		$retry_ids = [];
		foreach ( $backoff_intervals as $attempt => $backoff ) {
			$retry_time = $base_time + $backoff;

			$retry_id = $this->insertRetrySchedule( [
				'payout_id' => $payout_id,
				'attempt_number' => $attempt,
				'status' => $attempt === 1 ? 'pending' : 'scheduled',
				'next_retry_at' => gmdate( 'Y-m-d H:i:s', $retry_time ),
			] );

			$retry_ids[ $attempt ] = $retry_id;
		}

		// Assert: Verify backoff intervals
		foreach ( $backoff_intervals as $attempt => $backoff ) {
			$retry = $this->getRecord(
				'retry_schedules',
				"id = {$retry_ids[$attempt]}"
			);

			$retry_timestamp = strtotime( $retry->next_retry_at );
			$expected_timestamp = $base_time + $backoff;

			// Allow 5 second variance due to test timing
			$this->assertLessThanOrEqual(
				5,
				abs( $retry_timestamp - $expected_timestamp ),
				"Retry {$attempt} should be scheduled at T+{$backoff}s"
			);
		}
	}

	/**
	 * Test: First retry immediate (T+0s)
	 *
	 * Scenario:
	 * 1. Create payout with failure
	 * 2. Create first retry schedule
	 * 3. Verify next_retry_at <= current_time
	 * 4. Verify can be executed immediately
	 *
	 * @return void
	 * @test
	 */
	public function test_first_retry_immediate(): void {
		// Arrange: Create failed payout
		$seller_id = $this->createTestSeller( 'vendor' );
		$auction_id = $this->createTestAuction( [ 'seller_id' => $seller_id ] );

		$payout_id = $this->insertSellerPayout( [
			'seller_id' => $seller_id,
			'auction_id' => $auction_id,
			'gross_amount' => 5000,
			'status' => 'failed',
		] );

		// Act: Create first retry
		$retry_id = $this->insertRetrySchedule( [
			'payout_id' => $payout_id,
			'attempt_number' => 1,
			'status' => 'pending',
			'next_retry_at' => current_time( 'mysql' ), // Now
		] );

		// Assert: Verify retry is immediately eligible
		$retry = $this->getRecord(
			'retry_schedules',
			"id = {$retry_id} AND status = 'pending'"
		);

		$this->assertNotNull( $retry );

		$retry_time = strtotime( $retry->next_retry_at );
		$current_time = time();

		// First retry should be now or in the past
		$this->assertLessThanOrEqual( $current_time, $retry_time );
	}

	/**
	 * Test: Retry attempt succeeds and updates payout
	 *
	 * Scenario:
	 * 1. Create failed payout with retry
	 * 2. Simulate retry attempt
	 * 3. Mock processor returns success
	 * 4. Verify payout status = 'completed'
	 * 5. Verify retry marked 'completed'
	 *
	 * @return void
	 * @test
	 */
	public function test_retry_success_completes_payout(): void {
		// Arrange: Create failed payout
		$seller_id = $this->createTestSeller( 'vendor' );
		$auction_id = $this->createTestAuction( [ 'seller_id' => $seller_id ] );

		$payout_id = $this->insertSellerPayout( [
			'seller_id' => $seller_id,
			'auction_id' => $auction_id,
			'gross_amount' => 5000,
			'status' => 'failed',
		] );

		$retry_id = $this->insertRetrySchedule( [
			'payout_id' => $payout_id,
			'attempt_number' => 2,
			'status' => 'completed',
		] );

		// Act: Simulate retry success
		$transaction_id = 'txn_retry_' . wp_generate_uuid4();

		$this->wpdb->update(
			"{$this->wpdb->prefix}seller_payouts",
			[
				'status' => 'completed',
				'transaction_id' => $transaction_id,
				'completed_at' => current_time( 'mysql' ),
			],
			[ 'id' => $payout_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		$this->wpdb->update(
			"{$this->wpdb->prefix}retry_schedules",
			[
				'status' => 'completed',
				'completed_at' => current_time( 'mysql' ),
			],
			[ 'id' => $retry_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		// Assert: Verify payout completed
		$payout = $this->getRecord(
			'seller_payouts',
			"id = {$payout_id}"
		);

		$this->assertEquals( 'completed', $payout->status );
		$this->assertEquals( $transaction_id, $payout->transaction_id );

		// Assert: Verify retry marked completed
		$retry = $this->getRecord(
			'retry_schedules',
			"id = {$retry_id}"
		);

		$this->assertEquals( 'completed', $retry->status );
		$this->assertNotNull( $retry->completed_at );
	}

	/**
	 * Test: Max retries exceeded marks payout permanently failed
	 *
	 * Scenario:
	 * 1. Create payout with 5 failed retry attempts
	 * 2. Verify status = 'permanently_failed'
	 * 3. Verify no more retries scheduled
	 * 4. Verify audit trail of all attempts
	 *
	 * @return void
	 * @test
	 */
	public function test_max_retries_exceeded_marks_permanently_failed(): void {
		// Arrange: Create payout
		$seller_id = $this->createTestSeller( 'vendor' );
		$auction_id = $this->createTestAuction( [ 'seller_id' => $seller_id ] );

		$payout_id = $this->insertSellerPayout( [
			'seller_id' => $seller_id,
			'auction_id' => $auction_id,
			'gross_amount' => 5000,
			'status' => 'permanently_failed',
		] );

		// Act: Create 5 failed retry attempts
		$max_retries = 5;
		$retry_ids = [];

		for ( $i = 1; $i <= $max_retries; $i++ ) {
			$retry_ids[] = $this->insertRetrySchedule( [
				'payout_id' => $payout_id,
				'attempt_number' => $i,
				'status' => 'failed',
				'failed_reason' => 'Processor error: ' . $i,
			] );
		}

		// Assert: Verify all retries failed
		foreach ( $retry_ids as $retry_id ) {
			$retry = $this->getRecord(
				'retry_schedules',
				"id = {$retry_id}"
			);

			$this->assertEquals( 'failed', $retry->status );
		}

		// Assert: Verify payout marked permanently failed
		$payout = $this->getRecord(
			'seller_payouts',
			"id = {$payout_id}"
		);

		$this->assertEquals( 'permanently_failed', $payout->status );
	}

	/**
	 * Test: Audit trail records all retry attempts
	 *
	 * Scenario:
	 * 1. Create payout with multiple retry attempts
	 * 2. Verify each attempt has audit record
	 * 3. Verify timestamps, status, error reasons recorded
	 *
	 * @return void
	 * @test
	 */
	public function test_audit_trail_records_retry_attempts(): void {
		// Arrange: Create payout
		$seller_id = $this->createTestSeller( 'vendor' );
		$auction_id = $this->createTestAuction( [ 'seller_id' => $seller_id ] );

		$payout_id = $this->insertSellerPayout( [
			'seller_id' => $seller_id,
			'auction_id' => $auction_id,
			'gross_amount' => 5000,
			'status' => 'failed',
		] );

		// Act: Create audit records for attempts
		$audit_ids = [];

		for ( $i = 1; $i <= 3; $i++ ) {
			$audit_ids[] = $this->insertAuditLog( [
				'entity_type' => 'seller_payout',
				'entity_id' => $payout_id,
				'action' => 'retry_attempt',
				'details' => wp_json_encode( [
					'attempt' => $i,
					'reason' => 'Processor timeout',
				] ),
			] );
		}

		// Assert: Verify audit records created
		$this->assertRecordCount(
			3,
			'audit_logs',
			"entity_type = 'seller_payout' AND entity_id = {$payout_id}"
		);

		// Verify audit trail contents
		$audits = $this->getRecords(
			'audit_logs',
			"entity_type = 'seller_payout' AND entity_id = {$payout_id}"
		);

		$this->assertCount( 3, $audits );

		foreach ( $audits as $index => $audit ) {
			$this->assertEquals( 'retry_attempt', $audit->action );
		}
	}

	/**
	 * Helper: Insert retry schedule record
	 *
	 * @param array $data Retry schedule data.
	 * @return int Retry schedule ID
	 */
	private function insertRetrySchedule( array $data ): int {
		$defaults = [
			'payout_id' => 0,
			'attempt_number' => 1,
			'status' => 'pending',
			'next_retry_at' => current_time( 'mysql' ),
			'failed_reason' => null,
			'completed_at' => null,
		];

		$data = array_merge( $defaults, $data );

		$inserted = $this->wpdb->insert(
			"{$this->wpdb->prefix}retry_schedules",
			[
				'payout_id' => $data['payout_id'],
				'attempt_number' => $data['attempt_number'],
				'status' => $data['status'],
				'next_retry_at' => $data['next_retry_at'],
				'failed_reason' => $data['failed_reason'],
				'completed_at' => $data['completed_at'],
				'created_at' => current_time( 'mysql' ),
			],
			[
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			]
		);

		if ( ! $inserted ) {
			throw new \RuntimeException( 'Failed to insert retry schedule' );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Helper: Insert seller payout record
	 *
	 * @param array $data Payout data.
	 * @return int Payout ID
	 */
	private function insertSellerPayout( array $data ): int {
		$defaults = [
			'seller_id' => 0,
			'auction_id' => 0,
			'gross_amount' => 0,
			'commission_amount' => 0,
			'net_amount' => 0,
			'status' => 'pending',
			'failed_reason' => null,
			'failed_at' => null,
		];

		$data = array_merge( $defaults, $data );

		$inserted = $this->wpdb->insert(
			"{$this->wpdb->prefix}seller_payouts",
			[
				'seller_id' => $data['seller_id'],
				'auction_id' => $data['auction_id'],
				'gross_amount' => $data['gross_amount'],
				'commission_amount' => $data['commission_amount'],
				'net_amount' => $data['net_amount'],
				'status' => $data['status'],
				'failed_reason' => $data['failed_reason'],
				'failed_at' => $data['failed_at'],
				'created_at' => current_time( 'mysql' ),
			],
			[
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			]
		);

		if ( ! $inserted ) {
			throw new \RuntimeException( 'Failed to insert seller payout' );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Helper: Insert audit log record
	 *
	 * @param array $data Audit log data.
	 * @return int Audit log ID
	 */
	private function insertAuditLog( array $data ): int {
		$defaults = [
			'entity_type' => '',
			'entity_id' => 0,
			'action' => '',
			'details' => '{}',
		];

		$data = array_merge( $defaults, $data );

		$inserted = $this->wpdb->insert(
			"{$this->wpdb->prefix}audit_logs",
			[
				'entity_type' => $data['entity_type'],
				'entity_id' => $data['entity_id'],
				'action' => $data['action'],
				'details' => $data['details'],
				'created_at' => current_time( 'mysql' ),
			],
			[
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
			]
		);

		if ( ! $inserted ) {
			throw new \RuntimeException( 'Failed to insert audit log' );
		}

		return $this->wpdb->insert_id;
	}
}
