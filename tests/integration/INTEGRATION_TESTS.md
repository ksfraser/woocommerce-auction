# Integration Tests Documentation

## Overview

This document describes the integration test suite for Phase 4-D Settlement → Payout workflow. The integration tests verify end-to-end functionality across multiple services working together.

**Location**: `tests/integration/`

**Framework**: PHPUnit with WordPress integration

## Test Infrastructure

### IntegrationTestCase Base Class

All integration tests extend `IntegrationTestCase`, which provides:

- **Transaction Isolation**: Each test runs in isolated database transaction with automatic rollback
- **Service Initialization**: Dependency-injected service mocks and real instances
- **Fixture Management**: Helper methods for creating test sellers, auctions, payout methods
- **Database Assertions**: Helper methods for verifying database state

```php
class MyTestClass extends IntegrationTestCase {
    protected function setUp(): void {
        parent::setUp(); // Starts transaction
    }
    
    protected function tearDown(): void {
        // Automatic rollback cleaning up test data
        parent::tearDown();
    }
}
```

### Database Setup

**Transaction Isolation**:
- Each test starts with `START TRANSACTION`
- Test data created within transaction
- Automatic `ROLLBACK` on teardown
- No need for explicit database cleanup

**Requirements**:
- MySQL or PostgreSQL in REPEATABLE-READ or higher isolation level
- Transaction support required

### Service Fixtures

The `IntegrationTestCase` initializes:

1. **Repositories** (Mocked):
   - `SellerPayoutRepository`
   - `PaymentProcessorRepository`
   - `SettlementBatchRepository`

2. **Services** (Real):
   - `EventPublisher` - Event dispatch system
   - `EncryptionService` - AES-256 credential encryption
   - `PayoutMethodValidator` - Processor-specific validation

3. **Utilities** (Real):
   - `LoggerService` - Structured logging
   - `EventPublisher` - Event-driven updates

Access services via:
```php
$service = $this->getService('event_publisher');
```

## Test Suites

### 1. Settlement Batch Creation Tests
**File**: `SettlementBatchCreationTest.php`

**Purpose**: Verify settlement batch and seller payout creation workflow

**Test Cases**:

| Test Name | Scenario | Assertions |
|-----------|----------|-----------|
| `test_settlement_batch_created_with_winning_bid` | Single auction winning bid | Batch record exists, status=pending |
| `test_multiple_seller_payouts_created` | Two sellers, two auctions | Both payouts created independently |
| `test_commission_calculated_correctly` | Commission deduction | gross - commission = net amount |
| `test_payout_created_only_for_active_sellers` | Mix of active/inactive | Only active seller payout created |
| `test_payout_status_initialized_correctly` | New payout status | All payouts start as 'pending' |
| `test_duplicate_payout_prevented` | Same seller/auction twice | Only one payout created (unique constraint) |

**Database Tables Involved**:
- `settlement_batches` - Batch records
- `seller_payouts` - Individual seller amounts
- `yith_wc_auction_bids` - Winning bid references

**Running**:
```bash
phpunit tests/integration/SettlementBatchCreationTest.php
```

---

### 2. Payout Execution Tests
**File**: `PayoutExecutionTest.php`

**Purpose**: Verify payout processing through payment processors

**Test Cases**:

| Test Name | Scenario | Assertions |
|-----------|----------|-----------|
| `test_batch_scheduler_processes_pending_payouts` | Batch scheduler pickup | Status changes from pending |
| `test_payment_processor_adapter_called` | Adapter invocation | Processor called with credentials |
| `test_wp_cron_scheduled_for_polling` | Async polling setup | WP-Cron hook registered |
| `test_status_transitions_recorded` | Status: pending → processing → completed | Timestamps recorded for each |
| `test_payout_completed_after_success` | Success response | Transaction ID stored, status set |
| `test_multiple_payouts_processed_sequentially` | Batch of 3 | All transition from pending |
| `test_payout_completion_event_published` | Event dispatch | 'seller_payout.completed' fired |

**Database Tables Involved**:
- `seller_payouts` - Status transitions
- `options` - WP-Cron hooks

**Running**:
```bash
phpunit tests/integration/PayoutExecutionTest.php
```

---

### 3. Retry & Recovery Tests
**File**: `RetryRecoveryTest.php`

**Purpose**: Verify automatic retry and recovery from processor failures

**Test Cases**:

| Test Name | Scenario | Assertions |
|-----------|----------|-----------|
| `test_processor_failure_triggers_retry_schedule` | Processor failure | RetrySchedule created, status='failed' |
| `test_exponential_backoff_calculated` | 5 retry attempts | Backoff: 0s, 60s, 300s, 900s, 1800s |
| `test_first_retry_immediate` | Immediate retry | next_retry_at <= current_time |
| `test_retry_success_completes_payout` | Retry succeeds | Payout marked 'completed' |
| `test_max_retries_exceeded_marks_permanently_failed` | 5 failed retries | Status='permanently_failed' |
| `test_audit_trail_records_retry_attempts` | Audit logging | All attempts recorded with timestamps |

**Backoff Schedule**:
```
Attempt 1: 0 seconds (immediate)
Attempt 2: 60 seconds (1 minute)
Attempt 3: 300 seconds (5 minutes)
Attempt 4: 900 seconds (15 minutes)
Attempt 5: 1800 seconds (30 minutes)
Max Retries: 5 (then permanently_failed)
```

**Database Tables Involved**:
- `seller_payouts` - Status and retry tracking
- `retry_schedules` - Retry attempt records
- `audit_logs` - Attempt history

**Running**:
```bash
phpunit tests/integration/RetryRecoveryTest.php
```

---

### 4. Error Handling & Rollback Tests
**File**: `ErrorHandlingRollbackTest.php`

**Purpose**: Verify graceful error handling and data integrity

**Test Cases**:

| Test Name | Scenario | Assertions |
|-----------|----------|-----------|
| `test_processor_timeout_handled_gracefully` | Processor timeout | Marked 'failed', not 'completed', retried |
| `test_missing_encryption_key_handled` | Key not available | Status='skipped', logged, retryable |
| `test_database_connection_loss_rollback` | Connection lost | Transaction rolled back, no orphans |
| `test_batch_lock_expiration_allows_retry` | Lock timeout | New lock created, processing continues |
| `test_no_data_corruption_on_partial_failure` | Batch partial success | Successful payouts intact, failed isolated |
| `test_audit_trail_logs_error_scenarios` | Multi-error logging | All errors logged with context |

**Error Handling Strategies**:

| Scenario | Handling | Recovery |
|----------|----------|----------|
| Processor timeout | Mark failed, scheduled retry | Exponential backoff retry |
| Missing encryption key | Mark skipped, log alert | Retry when key available |
| DB connection loss | ROLLBACK transaction | Retry with new transaction |
| Batch lock timeout | Release expired lock | New lock acquired, resume |
| Partial batch failure | Complete successful, isolate failed | Retry failed independently |

**Database Tables Involved**:
- `seller_payouts` - Status tracking
- `batch_locks` - Lock management
- `audit_logs` - Error logging

**Running**:
```bash
phpunit tests/integration/ErrorHandlingRollbackTest.php
```

---

## Running Integration Tests

### Run All Integration Tests
```bash
phpunit tests/integration/
```

### Run Specific Test Suite
```bash
phpunit tests/integration/SettlementBatchCreationTest.php
```

### Run Specific Test Case
```bash
phpunit tests/integration/SettlementBatchCreationTest.php::test_settlement_batch_created_with_winning_bid
```

### Run with Coverage Report
```bash
phpunit --coverage-html=coverage/ tests/integration/
```

### Run with Verbose Output
```bash
phpunit -v tests/integration/
```

---

## Test Data Setup

### Creating Test Seller
```php
$seller_id = $this->createTestSeller('vendor');
```
Creates WordPress user with specified role.

### Creating Test Auction
```php
$auction_id = $this->createTestAuction([
    'seller_id' => $seller_id,
    'post_title' => 'My Test Auction'
]);
```
Creates auction product with specified seller.

### Creating Test Payout Method
```php
$method_id = $this->createTestPayoutMethod(
    $seller_id,
    'stripe',
    [
        'connected_account_id' => 'acct_1234567890',
        'access_token' => 'sk_test_abc123'
    ]
);
```
Creates encrypted payout method for processor.

### Helper Database Methods
```php
// Get single record
$record = $this->getRecord('seller_payouts', 'id = 42');

// Get multiple records
$records = $this->getRecords('seller_payouts', 'status = "pending"');

// Assert record exists
$this->assertRecordExists('seller_payouts', 'seller_id = 123');

// Assert record count
$this->assertRecordCount(5, 'seller_payouts', 'status = "completed"');
```

---

## Expected Test Output

### Successful Run
```
PHPUnit 9.5.x by Sebastian Bergmann

Testing tests/integration/SettlementBatchCreationTest.php

.......

 7 / 7 (100%)

OK (7 tests, 0 assertions)
```

### Full Integration Test Output
```
PHPUnit 9.5.x by Sebastian Bergmann

Testing tests/integration/SettlementBatchCreationTest.php
.......        7/26 tests passing

Testing tests/integration/PayoutExecutionTest.php
.......        14/26 tests passing

Testing tests/integration/RetryRecoveryTest.php
.......        21/26 tests passing

Testing tests/integration/ErrorHandlingRollbackTest.php
.......        26/26 tests passing

OK (26 tests, 150+ assertions)
```

---

## Verification Checklist

Before considering integration tests complete:

### Database State Verification
- [ ] Test sellers created and cleaned up
- [ ] Test auctions created and cleaned up
- [ ] Test payout methods encrypted and cleaned up
- [ ] No orphaned records after rollback
- [ ] Transaction isolation working properly

### Service Interaction Verification
- [ ] PayoutService called for each payout
- [ ] Encryption/decryption working transparently
- [ ] Events published on status changes
- [ ] Validators called before processing

### Error Handling Verification
- [ ] Timeouts handled without data loss
- [ ] Missing keys logged but don't crash
- [ ] Connection loss triggers rollback
- [ ] Lock expiration allows retry
- [ ] Partial failures don't corrupt other payouts

### Audit Trail Verification
- [ ] All status changes logged
- [ ] Timestamps accurate
- [ ] Error reasons captured
- [ ] Retry attempts recorded
- [ ] Queryable by entity and action

---

## Troubleshooting

### Tests Fail with "No tables found"
- Verify WordPress installation initialized
- Ensure test database has proper schema
- Run database migrations before tests

### Transaction Rollback Not Working
- Check database isolation level: `SHOW VARIABLES LIKE 'transaction_isolation'`
- Should be REPEATABLE-READ or higher
- MySQL default is REPEATABLE-READ

### Encryption Tests Failing
- Verify OpenSSL extension installed: `php -m | grep openssl`
- Check YITH_AUCTION_ENCRYPTION_KEY constant defined in test setup
- Verify 32-byte key generated correctly

### Timeout on Database Queries
- Check for missing indexes on status/seller_id columns
- Add indexes: `ALTER TABLE seller_payouts ADD INDEX idx_status_seller (status, seller_id);`
- Monitor slow query log for N+1 problems

---

## Maintenance & Extension

### Adding New Integration Tests
1. Create new class extending `IntegrationTestCase`
2. Implement `test_*` methods with clear scenario descriptions
3. Use helper methods for test data creation
4. Add database assertions using provided helper methods
5. Ensure automatic cleanup via transaction rollback

### Updating Test Infrastructure
- Modify `IntegrationTestCase` for common changes (new services, helper methods)
- Update `setUp()` for new required services
- Update `tearDown()` for new cleanup requirements
- Document any changes to transaction isolation strategy

---

## Integration Test Metrics

**Target Metrics**:
- ✅ 26+ test cases
- ✅ 150+ assertions
- ✅ 100% service method coverage in E2E scenarios
- ✅ All error paths tested
- ✅ Automatic data cleanup via transactions
- ✅ Sub-second test execution
- ✅ Repeatable and deterministic

**Coverage**:
- Settlement batch creation workflow
- Payout execution and status transitions
- Retry and recovery mechanisms
- Error handling and rollback scenarios
- Cross-service coordination
- Event publishing
- Encryption/decryption
- Audit trail recording

---

## Related Documentation

- [Service Architecture](../docs/architecture/services.md)
- [Database Schema](../docs/database/schema.md)
- [Phase 4-D Implementation Plan](../docs/plan/PHASE_4D_TASKS_2-2_TO_2-5_IMPLEMENTATION_PLAN.md)
- [Unit Testing Guide](unit-tests.md)
- [Error Handling Strategy](../docs/architecture/error-handling.md)
