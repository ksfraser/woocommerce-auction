# Task 2-3: Batch Scheduler - Context Map

**Date**: 2026-03-28  
**Status**: PLANNING  
**Purpose**: Identify all files for WP-Cron batch scheduling implementation

---

## 1. Task Overview

**Task 2-3: Batch Scheduler** (2-3 days, ~400-500 LOC)  
Implement WP-Cron integrated batch processing scheduler leveraging Phase 2-3 SchedulerService and Phase 2-2 PayoutService.

**Files Required**: 6 new files + 2-3 modifications  
**Tests**: 15+ unit tests  
**Dependencies**: ✅ Phase 2-3 complete, ✅ Phase 2-2 (PayoutService)

---

## 2. Files to Create (NEW)

### Production Code (4 files)

#### 2.3.1: `includes/services/BatchScheduler.php` (PRIMARY)
**Purpose**: Main WP-Cron scheduler implementation  
**Size**: 180-220 LOC  
**DependsOn**: 
- SchedulerService (Phase 2-3)
- PayoutService (Phase 2-2)
- BatchLockRepository (Phase 2-3A)
- EventPublisher (Phase 2-3B)

**Key Methods**:
- `__construct(...)`: Dependency injection
- `scheduleDaily(string $time)`: Register daily WP-Cron hook
- `scheduleWeekly(string $day, string $time)`: Register weekly hook
- `processScheduledBatch()`: Main execution entry point (called by WP-Cron)
- `processNow(int $batch_id)`: Manual trigger for admin UI
- `isBatchLocked(int $batch_id)`: Check lock status

**Integration Points**:
```php
// Hook registration in wp-action
add_action('wc_auction_batch_scheduler_daily', [BatchScheduler, 'processScheduledBatch']);
add_action('wc_auction_batch_scheduler_weekly', [BatchScheduler, 'processScheduledBatch']);
```

**Database Interactions**:
- Read: wc_auction_settlement_batches (status = PROCESSING)
- Write: wc_auction_batch_locks (acquire/release)
- Read: wc_auction_scheduler_config (schedule settings)

---

#### 2.3.2: `includes/endpoints/BatchSchedulerEndpoint.php`
**Purpose**: AJAX endpoint for manual batch triggering  
**Size**: 80-100 LOC  
**EndpointName**: `wp_ajax_wc_auction_process_batch`  
**Security**: Nonce + admin capability check

**Key Methods**:
- `__construct(BatchScheduler $scheduler)`
- `handleProcessBatch()`: AJAX action handler
- `validateRequest()`: Security validation

**Request Params**:
```json
{
  "batch_id": 123,
  "action": "wc_auction_process_batch",
  "nonce": "wp_nonce_verification"
}
```

**Response**:
```json
{
  "success": true,
  "data": {
    "processed": 45,
    "failed": 2,
    "total": 47,
    "batch_id": 123
  }
}
```

---

#### 2.3.3: `includes/services/BatchSchedulerConfiguration.php`
**Purpose**: Configuration management for batch scheduling  
**Size**: 100-150 LOC  
**DependsOn**: SchedulerConfigRepository (Phase 2-3A)

**Key Methods**:
- `getDailyScheduleTime()`**: Get configured daily run time (format: HH:00, default: 02:00)
- `getWeeklyScheduleDay()`: Get day for weekly run (0-6, default: 1 = Monday)
- `getWeeklyScheduleTime()`: Get time for weekly run
- `setBatchChunkSize(int $size)`: Set size for batch chunking
- `getBatchChunkSize()`: Get current chunk size

**Configuration Keys** (stored in wc_auction_scheduler_config):
- `batch_schedule_daily_hour` (type: int, 0-23)
- `batch_schedule_daily_minute` (type: int, 0-59)
- `batch_schedule_weekly_day` (type: int, 0-6)
- `batch_schedule_weekly_time` (type: string, HH:MM)
- `batch_chunk_size` (type: int, default: 100)

---

#### 2.3.4: `includes/models/BatchProcessingResult.php`
**Purpose**: Value object for batch processing results  
**Size**: 60-80 LOC  
**Immutable**: Yes

**Properties**:
- `batch_id: int` - Batch identifier
- `processed: int` - Count of successfully processed payouts
- `failed: int` - Count of failed payouts
- `total: int` - Total payouts in batch
- `duration_seconds: float` - Execution time
- `completed_at: DateTime` - Completion timestamp
- `status: string` - Result status (SUCCESS, PARTIAL, FAILED)

**Methods**:
- `isSuccess(): bool` - All processed successfully
- `isPartial(): bool` - Some failed
- `isFailed(): bool` - All failed

---

### Test Code (2 files)

#### 2.3.5: `tests/unit/Services/BatchSchedulerTest.php`
**Purpose**: Unit tests for BatchScheduler  
**Size**: 400-500 LOC  
**Tests**: 15 test cases  
**Coverage**: 100% line coverage

**Test Cases**:
1. `test_service_can_be_instantiated` - Dependency injection
2. `test_schedule_daily_registers_wp_cron_hook` - WP-Cron registration
3. `test_schedule_weekly_registers_wp_cron_hook` - Weekly scheduling
4. `test_process_scheduled_batch_acquires_lock` - Lock coordination
5. `test_process_scheduled_batch_processes_all_payouts` - Batch execution
6. `test_process_scheduled_batch_handles_lock_failure` - Exception handling
7. `test_process_scheduled_batch_chunks_batch_properly` - Chunking strategy
8. `test_process_scheduled_batch_publishes_events` - Event publishing
9. `test_process_now_bypasses_schedule` - Manual trigger
10. `test_process_now_requires_batch_id` - Validation
11. `test_manual_trigger_via_ajax_endpoint` - AJAX action
12. `test_exponential_backoff_retry_on_failure` - Retry logic (delegation to SchedulerService)
13. `test_chunk_processing_checkpoints_to_database` - Progress tracking
14. `test_lock_timeout_allows_retry` - Stale lock recovery
15. `test_configuration_loads_from_database` - Config management

**Mocks**:
- WpCronSchedulerBootstrap
- PayoutService
- BatchLockRepository
- SchedulerService
- EventPublisher
- BatchSchedulerConfiguration

---

#### 2.3.6: `tests/unit/Endpoints/BatchSchedulerEndpointTest.php`
**Purpose**: Unit tests for AJAX endpoint  
**Size**: 200-300 LOC  
**Tests**: 8 test cases

**Test Cases**:
1. `test_endpoint_registered_with_ajax_action` - Hook registration
2. `test_process_batch_requires_nonce` - Security validation
3. `test_process_batch_requires_admin` - Capability check
4. `test_process_batch_requires_batch_id` - Input validation
5. `test_process_batch_returns_json_response` - Response format
6. `test_process_batch_calls_scheduler_service` - Service delegation
7. `test_error_response_on_exception` - Error handling
8. `test_concurrent_batch_processing_prevented` - Lock coordination

---

## 3. Files to Modify (EXISTING)

### 3.1: `includes/bootstrap.php` or `init.php`
**Changes**: WP-Cron hook registration

**Add**:
```php
// Register WP-Cron hooks
if ( ! wp_next_scheduled( 'wc_auction_batch_scheduler_daily' ) ) {
    wp_schedule_event( time(), 'daily', 'wc_auction_batch_scheduler_daily' );
}

if ( ! wp_next_scheduled( 'wc_auction_batch_scheduler_weekly' ) ) {
    wp_schedule_event( time(), 'weekly', 'wc_auction_batch_scheduler_weekly' );
}

// Register AJAX endpoint
add_action( 'wp_ajax_wc_auction_process_batch', [BatchSchedulerEndpoint::class, 'handleProcessBatch'] );
```

**Lines to Add**: 15-25 LOC

---

### 3.2: `tests/bootstrap.php`
**Changes**: Add mock WordPress wp_schedule_event function

**Add Mock**:
```php
if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook ) {
        global $wp_cron_mock;
        $wp_cron_mock[] = [
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'hook' => $hook,
        ];
        return true;
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook ) {
        global $wp_cron_mock;
        foreach ( $wp_cron_mock as $event ) {
            if ( $event['hook'] === $hook ) {
                return $event['timestamp'];
            }
        }
        return false;
    }
}
```

**Lines to Add**: 25-40 LOC

---

### 3.3: `docs/PHASE_4D_STATUS_SUMMARY.md`
**Changes**: Update Task 2-3 progress tracking

**From**:
```
| **Phase 2 Task 3: Batch Scheduler** | ⏳ Queued | 0% | — | — |
```

**To** (during implementation):
```
| **Phase 2 Task 3: Batch Scheduler** | 🔄 In Progress | 15% | 400-500 | TBD |
```

**Lines to Modify**: 1-2

---

## 4. Database Schema Review (EXISTING)

### Tables Used (Already Created Phase 2-3A):
- `wc_auction_batch_locks` - Lock management
  - `batch_id` (PK)
  - `locked_at`
  - `timeout_seconds`
  - `locked_by_process_id`

- `wc_auction_scheduler_config` - Configuration
  - `option_name` (PK)
  - `option_value`
  - `created_at`
  - `updated_at`

### Tables Used (from Phase 4-D Phase 1):
- `wc_auction_settlement_batches` - Batch definitions
  - `id` (PK)
  - `batch_number`
  - `status` (ENUM)
  - `settlement_date`

- `wc_auction_seller_payouts` - Payouts
  - `id` (PK)
  - `batch_id` (FK)
  - `status` (ENUM)

---

## 5. Integration Points Map

```
WordPress Site
├── WP-Cron Scheduler (External trigger)
│   ├── Daily @ 02:00 UTC → wc_auction_batch_scheduler_daily hook
│   └── Weekly @ Monday 02:00 UTC → wc_auction_batch_scheduler_weekly hook
│
├── BatchScheduler (Main Orchestrator)
│   ├── Acquires Lock via BatchLockRepository
│   ├── Queries Settlement Batches (status=PROCESSING)
│   ├── For each batch:
│   │   ├── Chunks payouts (size: 100-1000)
│   │   ├── Calls PayoutService.processPayoutBatch()
│   │   ├── Publishes BatchProcessingProgressEvent
│   │   └── Saves progress to database
│   ├── Publishes BatchProcessingCompletedEvent
│   └── Releases Lock
│
├── Admin AJAX Endpoint
│   ├── Receives manual trigger via wp_ajax_wc_auction_process_batch
│   ├── Validates security (nonce, capability)
│   ├── Calls BatchScheduler.processNow(batch_id)
│   └── Returns JSON response with result
│
└── Configuration Management
    ├── Loads schedule config from wc_auction_scheduler_config
    ├── Supports runtime updates
    └── Defaults: Daily @ 02:00 UTC, Weekly @ Monday 02:00 UTC
```

---

## 6. Dependency Graph

```
BatchScheduler
├── REQUIRES: SchedulerService (Phase 2-3) ✅
│   └── Uses: RetrySchedule, BatchLock models
│   └── Uses: acquireLock(), releaseLock(), scheduleRetry()
│
├── REQUIRES: PayoutService (Phase 2-2) 🔄 IN PROGRESS
│   └── Uses: processPayoutBatch(), getPayoutStatus()
│
├── REQUIRES: PaymentProcessorFactory (Phase 2-1) ✅
│   └── Delegated through PayoutService
│
├── REQUIRES: EventPublisher (Phase 2-3B) ✅
│   └── Uses: publish() for domain events
│
├── REQUIRES: SettlementBatchRepository (Phase 4-D Phase 1) ✅
│   └── Uses: findByStatus() for PROCESSING batches
│
├── REQUIRES: BatchSchedulerConfiguration (NEW in Task 2-3)
│   └── Uses: getDailyScheduleTime(), getWeeklyScheduleDay()
│
└── OPTIONAL: PayoutMethodManager (Phase 2-4) 
    └── Future enhancement for secure method retrieval
```

---

## 7. WP-Cron Hook Setup

**Hooks to Register**:
1. `wc_auction_batch_scheduler_daily` - Daily recurrence
2. `wc_auction_batch_scheduler_weekly` - Weekly recurrence
3. `wp_ajax_wc_auction_process_batch` - AJAX action

**Hook Names Follow Pattern**:
- Format: `wc_auction_*` (WooCommerce Auction plugin namespace)
- No underscores in verb (e.g., `batch_scheduler` not `batch_schedule`)
- Recurrence in name if multiple schedules

---

## 8. Configuration Examples

**WordPress wp-config.php**:
```php
// Override default batch schedule times
define( 'WC_AUCTION_BATCH_DAILY_HOUR', 2 );    // 2 AM UTC
define( 'WC_AUCTION_BATCH_DAILY_MINUTE', 0 );
define( 'WC_AUCTION_BATCH_WEEKLY_DAY', 1 );    // Monday
define( 'WC_AUCTION_BATCH_CHUNK_SIZE', 100 );
```

**Database (wc_auction_scheduler_config)**:
```sql
INSERT INTO wp_wc_auction_scheduler_config (option_name, option_value, created_at, updated_at) VALUES
  ('batch_schedule_daily_hour', '2', NOW(), NOW()),
  ('batch_schedule_daily_minute', '0', NOW(), NOW()),
  ('batch_schedule_weekly_day', '1', NOW(), NOW()),
  ('batch_schedule_weekly_time', '02:00', NOW(), NOW()),
  ('batch_chunk_size', '100', NOW(), NOW());
```

---

## 9. Testing Strategy

### Unit Tests (15+ cases)
- **WP-Cron Integration**: Hook registration, removal, triggering
- **Service Integration**: Verify SchedulerService & PayoutService calls
- **Lock Management**: Concurrent processing prevention
- **Configuration**: Load/save scheduling options
- **Error Handling**: Exceptions, retries, graceful degradation

### Integration Tests (Future - Phase 2-5)
- End-to-end: Settlement → Batch Created → Scheduled → Executed
- Multiple concurrent batches with lock coordination
- Admin manual trigger during automatic run
- Configuration changes during execution

---

## 10. Success Criteria

✅ **Core Functionality**:
- [ ] WP-Cron hooks register and unregister correctly
- [ ] Daily batch processing at configured time
- [ ] Weekly batch processing on configured day
- [ ] Manual AJAX trigger works
- [ ] Lock coordination prevents concurrent execution

✅ **Code Quality**:
- [ ] 15/15 unit tests passing
- [ ] 100% line coverage for BatchScheduler
- [ ] 95%+ coverage for BatchSchedulerEndpoint
- [ ] Comprehensive PHPDoc with UML diagrams
- [ ] PSR-12 compliance

✅ **Integration**:
- [ ] Cleanly delegates to PayoutService
- [ ] Seamless integration with SchedulerService
- [ ] Event publishing for monitoring/logging
- [ ] Configuration via database and wp-config.php

✅ **Performance**:
- [ ] Batch processing 1000 payouts < 60 seconds
- [ ] Lock acquire/release < 100ms
- [ ] Chunking strategy prevents memory exhaustion

---

## 11. File Count Summary

**Total Files**: 8 (6 new + 2-3 modified)

| Category | Count | Files |
|----------|-------|-------|
| **Production Code** | 2 | BatchScheduler.php, BatchSchedulerEndpoint.php |
| **Support Classes** | 2 | BatchSchedulerConfiguration.php, BatchProcessingResult.php |
| **Unit Tests** | 2 | BatchSchedulerTest.php, BatchSchedulerEndpointTest.php |
| **Modifications** | 3 | bootstrap.php, tests/bootstrap.php, PHASE_4D_STATUS_SUMMARY.md |
| **TOTAL** | **8** | **6 new + 2-3 modified** |

---

## 12. Implementation Sequence

**Phase 1**: Create models and configuration (Day 1)
- BatchProcessingResult.php
- BatchSchedulerConfiguration.php

**Phase 2**: Implement core scheduler (Day 2)
- BatchScheduler.php
- Write unit tests

**Phase 3**: Implement AJAX endpoint (Day 2)
- BatchSchedulerEndpoint.php
- Write endpoint tests

**Phase 4**: Bootstrap integration and docs (Day 3)
- Update bootstrap.php with WP-Cron hooks
- Update tests/bootstrap.php with mocks
- Update status documentation

---

**Prepared For**: Task 2-3 Implementation  
**Last Updated**: 2026-03-28  
**Status**: Ready for Development
