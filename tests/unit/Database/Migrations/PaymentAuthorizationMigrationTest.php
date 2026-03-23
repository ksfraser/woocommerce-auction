<?php

namespace Yith\Auctions\Tests\Unit\Database\Migrations;

use PHPUnit\Framework\TestCase;
use Yith\Auctions\Database\Migrations\PaymentAuthorizationMigration;

/**
 * PaymentAuthorizationMigrationTest - Unit tests for database migration.
 *
 * Verifies:
 * - All three tables created successfully
 * - Table structure (columns, types, constraints)
 * - Indices created for performance
 * - Idempotent execution (safe re-run)
 * - Down migration (cleanup)
 * - Status queries work
 *
 * @package Yith\Auctions\Tests\Unit\Database\Migrations
 * @requirement REQ-ENTRY-FEE-PAYMENT-001
 */
class PaymentAuthorizationMigrationTest extends TestCase
{
    private object $migration;

    /**
     * Mock WordPress WPDB.
     */
    private object $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = $this->createMockWpdb();
        $this->migration = new PaymentAuthorizationMigration($this->wpdb);
    }

    /**
     * Test: Migration creates all three tables successfully.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_migration_creates_tables(): void
    {
        $result = $this->migration->up();

        $this->assertTrue($result);
    }

    /**
     * Test: Payment methods table is created with correct columns.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_payment_methods_table_structure(): void
    {
        $this->migration->up();

        $columns = $this->wpdb->get_results("DESCRIBE {$this->wpdb->prefix}wc_auction_payment_methods");

        $column_names = wp_list_pluck($columns, 'Field');

        $this->assertContains('id', $column_names);
        $this->assertContains('user_id', $column_names);
        $this->assertContains('payment_token', $column_names);
        $this->assertContains('card_brand', $column_names);
        $this->assertContains('card_last_four', $column_names);
        $this->assertContains('exp_month', $column_names);
        $this->assertContains('exp_year', $column_names);
        $this->assertContains('created_at', $column_names);
        $this->assertContains('updated_at', $column_names);
    }

    /**
     * Test: Payment authorizations table is created with correct columns.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_payment_authorizations_table_structure(): void
    {
        $this->migration->up();

        $columns = $this->wpdb->get_results("DESCRIBE {$this->wpdb->prefix}wc_auction_payment_authorizations");

        $column_names = wp_list_pluck($columns, 'Field');

        $this->assertContains('id', $column_names);
        $this->assertContains('auction_id', $column_names);
        $this->assertContains('user_id', $column_names);
        $this->assertContains('bid_id', $column_names);
        $this->assertContains('authorization_id', $column_names);
        $this->assertContains('payment_gateway', $column_names);
        $this->assertContains('amount_cents', $column_names);
        $this->assertContains('status', $column_names);
        $this->assertContains('created_at', $column_names);
        $this->assertContains('expires_at', $column_names);
        $this->assertContains('charged_at', $column_names);
        $this->assertContains('refunded_at', $column_names);
        $this->assertContains('metadata', $column_names);
    }

    /**
     * Test: Refund schedule table is created with correct columns.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_refund_schedule_table_structure(): void
    {
        $this->migration->up();

        $columns = $this->wpdb->get_results("DESCRIBE {$this->wpdb->prefix}wc_auction_refund_schedule");

        $column_names = wp_list_pluck($columns, 'Field');

        $this->assertContains('id', $column_names);
        $this->assertContains('authorization_id', $column_names);
        $this->assertContains('refund_id', $column_names);
        $this->assertContains('user_id', $column_names);
        $this->assertContains('scheduled_for', $column_names);
        $this->assertContains('reason', $column_names);
        $this->assertContains('status', $column_names);
        $this->assertContains('created_at', $column_names);
        $this->assertContains('processed_at', $column_names);
    }

    /**
     * Test: Payment methods table has correct unque key on user_id + payment_token.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_payment_methods_unique_constraint(): void
    {
        $this->migration->up();

        $result = $this->wpdb->get_results(
            "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
             WHERE TABLE_NAME = '{$this->wpdb->prefix}wc_auction_payment_methods' 
             AND COLUMN_NAME IN ('user_id', 'payment_token')"
        );

        $this->assertNotEmpty($result);
    }

    /**
     * Test: Payment authorizations table has indices for performance.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_payment_authorizations_indices(): void
    {
        $this->migration->up();

        $indices = $this->wpdb->get_results(
            "SHOW INDEXES FROM {$this->wpdb->prefix}wc_auction_payment_authorizations"
        );

        $index_columns = wp_list_pluck($indices, 'Column_name');

        // Key indices for queries
        $this->assertContains('auction_id', $index_columns);
        $this->assertContains('user_id', $index_columns);
        $this->assertContains('status', $index_columns);
        $this->assertContains('created_at', $index_columns);
    }

    /**
     * Test: Migration is idempotent (can run multiple times safely).
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_migration_is_idempotent(): void
    {
        // First run
        $result1 = $this->migration->up();
        $this->assertTrue($result1);

        // Second run (should not fail)
        $result2 = $this->migration->up();
        $this->assertTrue($result2);
    }

    /**
     * Test: Down migration drops all tables.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_down_migration_drops_tables(): void
    {
        $this->migration->up();
        $this->assertTrue($this->migration->isMigrated());

        $result = $this->migration->down();
        $this->assertTrue($result);

        $this->assertFalse($this->migration->isMigrated());
    }

    /**
     * Test: isMigrated() returns correct status.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_is_migrated_returns_status(): void
    {
        $this->assertFalse($this->migration->isMigrated());

        $this->migration->up();

        $this->assertTrue($this->migration->isMigrated());

        $this->migration->down();

        $this->assertFalse($this->migration->isMigrated());
    }

    /**
     * Test: getStatus() returns migration information.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_status_returns_info(): void
    {
        $this->migration->up();

        $status = $this->migration->getStatus();

        $this->assertIsArray($status);
        $this->assertTrue($status['migrated']);
        $this->assertEquals(0, $status['payment_methods_count']);
        $this->assertEquals(0, $status['authorizations_count']);
        $this->assertEquals(0, $status['refunds_count']);
    }

    /**
     * Test: getStatus() returns accurate counts after inserts.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_status_counts_inserts(): void
    {
        $this->migration->up();

        // Insert test data
        $this->wpdb->insert(
            $this->wpdb->prefix . 'wc_auction_payment_methods',
            [
                'user_id' => 1,
                'payment_token' => 'tok_visa_123',
                'card_brand' => 'Visa',
                'card_last_four' => '4242',
            ]
        );

        $this->wpdb->insert(
            $this->wpdb->prefix . 'wc_auction_payment_authorizations',
            [
                'auction_id' => 1,
                'user_id' => 1,
                'bid_id' => 'bid_123',
                'authorization_id' => 'auth_123',
                'payment_gateway' => 'square',
                'amount_cents' => 5000,
                'status' => 'AUTHORIZED',
            ]
        );

        $status = $this->migration->getStatus();

        $this->assertEquals(1, $status['payment_methods_count']);
        $this->assertEquals(1, $status['authorizations_count']);
    }

    /**
     * Test: Payment methods table enforces data types correctly.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_payment_methods_data_types(): void
    {
        $this->migration->up();

        $columns = $this->wpdb->get_results("DESCRIBE {$this->wpdb->prefix}wc_auction_payment_methods");

        $column_map = [];
        foreach ($columns as $col) {
            $column_map[$col->Field] = $col->Type;
        }

        $this->assertStringContainsString('BIGINT', $column_map['id']);
        $this->assertStringContainsString('BIGINT', $column_map['user_id']);
        $this->assertStringContainsString('VARCHAR', $column_map['payment_token']);
        $this->assertStringContainsString('VARCHAR', $column_map['card_brand']);
        $this->assertStringContainsString('INT', $column_map['exp_month']);
        $this->assertStringContainsString('DATETIME', $column_map['created_at']);
    }

    /**
     * Test: Payment authorizations table enforces data types.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_payment_authorizations_data_types(): void
    {
        $this->migration->up();

        $columns = $this->wpdb->get_results("DESCRIBE {$this->wpdb->prefix}wc_auction_payment_authorizations");

        $column_map = [];
        foreach ($columns as $col) {
            $column_map[$col->Field] = $col->Type;
        }

        $this->assertStringContainsString('BIGINT', $column_map['id']);
        $this->assertStringContainsString('BIGINT', $column_map['auction_id']);
        $this->assertStringContainsString('BIGINT', $column_map['amount_cents']);
        $this->assertStringContainsString('VARCHAR', $column_map['authorization_id']);
        $this->assertStringContainsString('DATETIME', $column_map['created_at']);
        $this->assertStringContainsString('LONGTEXT', $column_map['metadata']);
    }

    /**
     * Test: Refund schedule table has index on scheduled_for for query performance.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_refund_schedule_scheduled_for_index(): void
    {
        $this->migration->up();

        $indices = $this->wpdb->get_results(
            "SHOW INDEXES FROM {$this->wpdb->prefix}wc_auction_refund_schedule 
             WHERE Column_name = 'scheduled_for'"
        );

        $this->assertNotEmpty($indices);
    }

    /**
     * Helper: Create mock WPDB.
     *
     * @return object Mock WPDB
     */
    private function createMockWpdb(): object
    {
        return $this->createMock(
            '\stdClass'  // Simplified mock for tests
        );
    }
}
