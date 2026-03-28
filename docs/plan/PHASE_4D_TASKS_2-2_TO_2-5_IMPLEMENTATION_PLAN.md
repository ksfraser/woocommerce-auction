---
goal: Implement Payout Processing Pipeline (Tasks 2-2 through 2-5)
version: 1.0
date_created: 2026-03-28
last_updated: 2026-03-28
owner: Development Team
status: 'Planned'
tags: [phase-4d, payouts, orchestration, testing, infrastructure]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

This implementation plan covers Phase 4-D Tasks 2-2 through 2-5, which complete the payout processing pipeline. Starting with the core PayoutService orchestrator, then adding batch scheduling, secure payout method storage, and comprehensive integration tests. All work builds upon Phase 2-3 (Scheduler Service with 172 passing tests) and Phase 2-1 (Payment Processor Adapters).

**Total Estimated Effort**: 10-14 days | **LOC**: ~2,500+ production | **Tests**: 50+ unit tests

---

## 1. Requirements & Constraints

### Functional Requirements

- **REQ-4D-043**: PayoutService orchestrates payout execution via PaymentProcessorFactory
- **REQ-4D-044**: PayoutOrchestrator manages batch processing with transactional safety
- **REQ-4D-045**: PayoutMethodManager provides secure storage and retrieval of payout methods
- **REQ-4D-046**: Integration tests verify complete settlement→payout flow
- **REQ-4D-037**: Scheduler service retry logic (from Phase 2-3) integrated into PayoutService
- **REQ-4D-042**: WP-Cron integration triggers batch processing automatically

### Non-Functional Requirements

- **PERF-001**: Payout initiation < 500ms per payout
- **PERF-002**: Batch processing 1000+ payouts < 60 seconds
- **PERF-003**: Status polling 100 payouts < 10 seconds
- **PERF-004**: PayoutMethod encryption/decryption < 100ms per method

### Technical Constraints

- **CON-001**: Must use PaymentProcessorFactory (Phase 2-1) for adapter routing
- **CON-002**: Must integrate SchedulerService retry logic (Phase 2-3) for failed payouts
- **CON-003**: Must use WP-Cron hooks for automated batch scheduling
- **CON-004**: All SQL queries must use prepared statements (security SOP)
- **CON-005**: Must maintain PSR-4 autoloading compliance
- **CON-006**: All code must be 100% type-hinted and documented with PHPDoc

### Security Requirements

- **SEC-001**: Banking details encrypted with AES-256-CBC before storage
- **SEC-002**: Encryption keys stored in wp-config.php or environment variables
- **SEC-003**: No sensitive data logged (PII filtering)
- **SEC-004**: All API calls to payment processors use SDK/HTTPS
- **SEC-005**: Database access via $wpdb prepared statements only

### Architectural Constraints

- **ARC-001**: Use Repository pattern for data access (DAO layer)
- **ARC-002**: Use Dependency Injection in all service classes
- **ARC-003**: Use SRP (Single Responsibility Principle) - no god classes
- **ARC-004**: Use Domain Events for all state changes (EventPublisher from Phase 2-3B)
- **ARC-005**: Use immutable Value Objects for data transfers (PaymentProcessorAdapter returns standardized responses)

---

## 2. Implementation Steps

### Phase 4-D Task 2-2: PayoutService (3-4 days)

**GOAL-001**: Implement core PayoutService orchestrator for executing individual seller payouts with retry logic and status tracking

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-2-2-1 | Create PayoutService class with dependency injection | | |
| TASK-2-2-2 | Implement `initiateSellerPayout()` method with adapter routing | | |
| TASK-2-2-3 | Implement status polling loop for PROCESSING payouts | | |
| TASK-2-2-4 | Integrate exponential backoff retry logic from Phase 2-3 | | |
| TASK-2-2-5 | Create PayoutRepository for CRUD operations | | |
| TASK-2-2-6 | Implement PayoutStatusService for state transitions | | |
| TASK-2-2-7 | Write unit tests (25+ test cases) for all methods | | |
| TASK-2-2-8 | Generate context map and architecture documentation | | |

**TASK-2-2-1: Create PayoutService Class**
- Location: `includes/services/PayoutService.php`
- Constructor parameters: `PaymentProcessorFactory, PayoutRepository, SchedulerService, EventPublisher, Logger`
- Core methods:
  - `initiateSellerPayout(PayoutDTO): PayoutDTO` - Entry point for payout execution
  - `processPayoutBatch(SettlementBatch): BatchResultDTO` - Process batch with locking
  - `pollPayoutStatus(PayoutDTO): PayoutDTO` - Query processor for status updates
  - `handleRetry(PayoutDTO, PayoutException): void` - Retry failed payouts
- Expected LOC: 250-300

**TASK-2-2-2: Implement initiateSellerPayout() Method**
- Accept PayoutDTO with: payout_id, seller_id, amount, method_id, processor_type
- Route to PaymentProcessorFactory based on method_type
- Call adapter's `processPaymentRequest()` method
- Update PayoutStatus from PENDING → INITIATED
- Publish PayoutInitiatedEvent
- Return updated PayoutDTO with transaction_id
- Error handling: Catch PaymentProcessorException, schedule retry

**TASK-2-2-3: Implement Status Polling Loop**
- Method: `pollPayoutStatus(PayoutDTO): PayoutDTO`
- Use SchedulerService.scheduleRetry() for failed payouts
- Update payout status from processor response: PROCESSING → COMPLETED | FAILED
- Publish PayoutStatusUpdatedEvent
- Transaction safety: Use database transactions for atomic updates

**TASK-2-2-4: Integrate Retry Logic**
- Use RetrySchedule model/repository from Phase 2-3A
- Exponential backoff: [0s, 5m, 30m, 2h, 8h, 24h] (max 6 attempts)
- On RetrySchedule.isRetryDue(): Call `handleRetry()`
- Max retries enforcement
- Publish events and update audit trail

**TASK-2-2-5: Create PayoutRepository**
- Location: `includes/repositories/PayoutRepository.php`
- Database table: `wc_auction_seller_payouts`
- Methods:
  - `save(PayoutDTO): PayoutDTO` - Persist payout
  - `getById(int): PayoutDTO` - Retrieve single payout
  - `getByStatus(string): PayoutDTO[]` - Find payouts by status
  - `updateStatus(int, string, array): void` - Atomic status update
  - `getAll(int $limit, int $offset): PayoutDTO[]` - Pagination
- SQL prepared statements for all queries

**TASK-2-2-6: Implement PayoutStatusService**
- Location: `includes/services/PayoutStatusService.php`
- Valid status transitions: PENDING → INITIATED → PROCESSING → COMPLETED | FAILED | CANCELLED
- Method: `transitionStatus(PayoutDTO, string $newStatus): void`
- Publish events for each transition
- Update audit trail with timestamp and metadata

**TASK-2-2-7: Write Unit Tests**
- Location: `tests/unit/Services/PayoutServiceTest.php`
- Test cases (25+):
  - testInitiatePayoutSuccess - Happy path execution
  - testInitiatePayoutWithInvalidMethod - Adapter error handling
  - testInitiatePayoutPublishesEvent - Event verification
  - testPollStatusUpdatesDatabase - Status update verification
  - testRetryLogicRespectExponentialBackoff - Retry delay calculation
  - testMaxRetriesEnforced - Permanent failure after max attempts
  - testConcurrentPayoutProcessing - Thread safety (if applicable)
  - testStatusTransitions - All valid/invalid transitions
- Mock: PaymentProcessorFactory, PayoutRepository, SchedulerService, EventPublisher
- Assertion: 100% code coverage

**TASK-2-2-8: Generate Documentation**
- Context map showing integration points
- Architecture diagrams (PlantUML)
- API documentation with PHPDoc
- Sequence diagram: Bid won → Settlement → Payout execution

---

### Phase 4-D Task 2-3: Batch Scheduler (2-3 days)

**GOAL-002**: Implement batch processing scheduler leveraging WP-Cron and Phase 2-3 SchedulerService

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-2-3-1 | Create BatchScheduler class for WP-Cron integration | | |
| TASK-2-3-2 | Implement lock acquisition before batch processing | | |
| TASK-2-3-3 | Implement batch chunking for large payload sets | | |
| TASK-2-3-4 | Implement manual trigger endpoint for admin UI | | |
| TASK-2-3-5 | Write unit tests (15+ test cases) | | |
| TASK-2-3-6 | Generate WP-Cron integration documentation | | |

**TASK-2-3-1: Create BatchScheduler Class**
- Location: `includes/services/BatchScheduler.php`
- Constructor: `WpCronSchedulerBootstrap, PayoutService, BatchLockRepository, Logger, EventPublisher`
- Methods:
  - `scheduleDaily(string $time): void` - Register daily WP-Cron hook
  - `scheduleWeekly(string $day, string $time): void` - Register weekly hook
  - `processScheduledBatch(): void` - Main execution handler (WP-Cron callback)
  - `processNow(int $batch_id): BatchResultDTO` - Manual trigger
  - `isBatchLocked(int $batch_id): bool` - Check lock status
- Expected LOC: 180-220

**TASK-2-3-2: Implement Batch Locking**
- Use BatchLock model/repository from Phase 2-3A
- Lock acquisition before processing: `batchLockRepository->acquireLock(batch_id, timeout=3600)`
- If lock fails: LockedException thrown → log and retry on next schedule
- Release lock after processing (finally block)
- Publish BatchProcessingStarted/Completed events

**TASK-2-3-3: Implement Batch Chunking**
- For batches > 1000 payouts: Split into chunks (size configurable, default: 100)
- Process chunks sequentially within same lock
- Update progress in wc_auction_batch_locks.metadata JSON
- Checkpoint: Save progress to database after each chunk (transactional)

**TASK-2-3-4: Manual Trigger Endpoint**
- Location: `includes/endpoints/BatchSchedulerEndpoint.php`
- AJAX action: `wp_ajax_nopriv_wc_auction_process_batch` (admin only)
- Parameters: batch_id (required), action_type (optional: immediate|schedule)
- Return: BatchResultDTO as JSON
- Security: Nonce verification + admin capability check

**TASK-2-3-5: Unit Tests**
- Location: `tests/unit/Services/BatchSchedulerTest.php`
- Test cases (15+):
  - testScheduleDailyRegistersWpCronHook
  - testProcessScheduledBatchAcquiresLock
  - testProcessScheduledBatchProcessesAllPayouts
  - testProcessScheduledBatchHandlesLockFailure
  - testProcessScheduledBatchChunksBatchProperly
  - testManualTriggerBypassesSchedule
  - testExponentialBackoffRetryOnFailure
- Mock: WpCronSchedulerBootstrap, PayoutService, BatchLockRepository

**TASK-2-3-6: Documentation**
- WP-Cron hook structure and callbacks
- Batch processing flow diagram
- Lock timeout management
- Integration with SchedulerService

---

### Phase 4-D Task 2-4: PayoutMethodManager (2 days)

**GOAL-003**: Implement secure storage and retrieval of seller payout methods with AES-256 encryption

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-2-4-1 | Create EncryptionService for AES-256-CBC encryption | | |
| TASK-2-4-2 | Create PayoutMethodManager class with CRUD operations | | |
| TASK-2-4-3 | Implement PayoutMethodValidator for method verification | | |
| TASK-2-4-4 | Write unit tests (12+ test cases) | | |
| TASK-2-4-5 | Generate encryption key management documentation | | |

**TASK-2-4-1: Create EncryptionService**
- Location: `includes/services/EncryptionService.php`
- Use OpenSSL extension (builtin to PHP)
- Cipher: aes-256-cbc
- Key source: `getEncryptionKey()` from wp-config.php or environment `AUCTION_ENCRYPTION_KEY`
- Methods:
  - `encrypt(string $data, string $key): string` - Encrypt data, return base64
  - `decrypt(string $encrypted, string $key): string` - Decrypt base64 to plaintext
  - `generateKey(): string` - Generate random 32-byte key
- Expected LOC: 80-100

**TASK-2-4-2: Create PayoutMethodManager**
- Location: `includes/services/PayoutMethodManager.php`
- Database table: `wc_auction_seller_payout_methods`
- Constructor: `PayoutMethodRepository, EncryptionService, ValidatorService, Logger`
- Methods:
  - `addPayoutMethod(SellerPayoutMethodDTO): SellerPayoutMethodDTO` - Store new method (encrypted)
  - `updatePayoutMethod(int $method_id, DTO): SellerPayoutMethodDTO` - Update with re-encryption
  - `deletePayoutMethod(int $method_id): void` - Remove method
  - `getPayoutMethod(int $method_id): SellerPayoutMethodDTO` - Retrieve and decrypt
  - `getPrimaryMethodForSeller(int $seller_id): SellerPayoutMethodDTO` - Get active method
  - `listPayoutMethods(int $seller_id): SellerPayoutMethodDTO[]` - All methods for seller
- Expected LOC: 150-200

**TASK-2-4-3: Implement PayoutMethodValidator**
- Location: `includes/validators/PayoutMethodValidator.php`
- Validate payout method data based on processor type:
  - **Square**: account_id, access_token validation
  - **PayPal**: account_email, verification status
  - **Stripe**: connected_account_id validation
- Methods:
  - `validate(SellerPayoutMethodDTO, string $processor): void` - Throws ValidationException if invalid
  - `isMethodActive(SellerPayoutMethodDTO): bool` - Check if verified/active
- Expected LOC: 100-150

**TASK-2-4-4: Unit Tests**
- Location: `tests/unit/Services/PayoutMethodManagerTest.php`
- Test cases (12+):
  - testAddPayoutMethodEncryptsData
  - testGetPayoutMethodDecryptsData
  - testEncryptionDecryptionRoundtrip
  - testDeletePayoutMethodRemovesRecord
  - testUpdatePayoutMethodReEncryptsData
  - testGetPrimaryMethodForSeller
  - testValidatePayoutMethodSquare
  - testValidatePayoutMethodPayPal
  - testValidatePayoutMethodStripe
- Mock: PayoutMethodRepository, EncryptionService

**TASK-2-4-5: Documentation**
- Encryption key management and rotation
- Database schema for payout methods
- PayPal/Square/Stripe account setup instructions

---

### Phase 4-D Task 2-5: Integration Tests (2-3 days)

**GOAL-004**: Implement end-to-end integration tests verifying complete settlement→payout flow with all components

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-2-5-1 | Create integration test suite structure | | |
| TASK-2-5-2 | Implement E2E test: Settlement batch creation | | |
| TASK-2-5-3 | Implement E2E test: Payout initiation and execution | | |
| TASK-2-5-4 | Implement E2E test: Retry and recovery scenarios | | |
| TASK-2-5-5 | Implement E2E test: Error handling and rollback | | |
| TASK-2-5-6 | Generate integration test documentation | | |

**TASK-2-5-1: Create Integration Test Structure**
- Location: `tests/integration/Services/PayoutIntegrationTest.php`
- Base class: IntegrationTestCase (handles database setup/teardown)
- Setup: Create test seller, test auction, test payout methods
- Teardown: Clean test data, verify no orphaned records
- Expected LOC: 100-150

**TASK-2-5-2: E2E Test - Settlement Batch Creation**
- Flow:
  1. Create auction with winning bid
  2. Calculate commission via CommissionCalculator (Phase 4-D Phase 1)
  3. Create settlement batch via SettlementBatchService
  4. Verify batch record in database
  5. Verify seller_payouts created for all eligible sellers
- Assertions: Record counts, status values, amount calculations
- Expected LOC: 60-80

**TASK-2-5-3: E2E Test - Payout Execution**
- Flow:
  1. Create settlement batch with payouts
  2. Call BatchScheduler.processNow()
  3. Verify PayoutService.initiateSellerPayout() called for each
  4. Verify PaymentProcessorFactory adapter called
  5. Verify WP-Cron hook scheduled for polling
  6. Simulate timeout: handleRetry() with exponential backoff
  7. Verify payout marked COMPLETED after success
- Assertions: Status transitions, event publishing, adapter calls
- Expected LOC: 100-150

**TASK-2-5-4: E2E Test - Retry & Recovery**
- Flow:
  1. Create payout, simulate payment processor failure
  2. Verify RetrySchedule created with exponential backoff
  3. Verify first retry at T+0s (immediate)
  4. Simulate retry success on second attempt
  5. Verify payout marked COMPLETED
- Assertions: Retry schedule, backoff timing, final status
- Expected LOC: 80-120

**TASK-2-5-5: E2E Test - Error Handling & Rollback**
- Scenarios:
  1. Payment processor timeout → HandleFail gracefully
  2. Encryption key missing → Payout skipped, logged
  3. Database connection lost → Transaction rolled back
  4. Batch lock expired → New processing attempt allowed
- Assertions: Error handling, no data corruption, audit trail logged
- Expected LOC: 100-150

**TASK-2-5-6: Documentation**
- Integration test execution instructions
- Test data setup/teardown procedures
- Expected test output and verification

---

## 3. Alternatives

- **ALT-001**: Use Laravel Queue instead of WP-Cron
  - Rejected: Not available in WordPress; WP-Cron is standard WordPress pattern
  
- **ALT-002**: Store payment credentials in Redis instead of database
  - Rejected: WordPress projects lack Redis; database + encryption is more portable
  
- **ALT-003**: Implement custom encryption instead of OpenSSL
  - Rejected: OpenSSL is standard, battle-tested, no need for custom implementation
  
- **ALT-004**: Synchronous payout processing instead of batched
  - Rejected: Would cause performance bottlenecks; async batching is better for scale

---

## 4. Dependencies

- **DEP-001**: Phase 4-D Phase 1 (Settlement Calculation Engine) ✅ COMPLETE
- **DEP-002**: Phase 4-D Task 2-1 (Payment Processor Adapters) ✅ COMPLETE
- **DEP-003**: Phase 2-3 (Scheduler Service) ✅ COMPLETE
- **DEP-004**: WordPress $wpdb global
- **DEP-005**: PHP OpenSSL extension (standard in PHP 7.3+)
- **DEP-006**: PHPUnit 9.6+ for testing

---

## 5. Files

**New Files to Create** (16 total):

| File | Purpose | LOC | Type |
|------|---------|-----|------|
| `includes/services/PayoutService.php` | Core orchestrator | 250-300 | Service |
| `includes/services/PayoutStatusService.php` | Status transitions | 100-120 | Service |
| `includes/repositories/PayoutRepository.php` | Data access | 200-250 | Repository |
| `includes/services/BatchScheduler.php` | WP-Cron integration | 180-220 | Service |
| `includes/endpoints/BatchSchedulerEndpoint.php` | Manual trigger | 80-100 | Endpoint |
| `includes/services/EncryptionService.php` | AES-256 encryption | 80-100 | Service |
| `includes/services/PayoutMethodManager.php` | Secure method storage | 150-200 | Service |
| `includes/validators/PayoutMethodValidator.php` | Method validation | 100-150 | Validator |
| `includes/repositories/PayoutMethodRepository.php` | Method persistence | 120-150 | Repository |
| `tests/unit/Services/PayoutServiceTest.php` | Unit tests | 600-750 | Test |
| `tests/unit/Services/BatchSchedulerTest.php` | Unit tests | 400-500 | Test |
| `tests/unit/Services/PayoutMethodManagerTest.php` | Unit tests | 350-450 | Test |
| `tests/integration/Services/PayoutIntegrationTest.php` | Integration tests | 400-500 | Test |
| `docs/PHASE_4D_TASK_2-2_PAYOUTSERVICE.md` | Architecture doc | 1,000+ | Documentation |
| `docs/PHASE_4D_TASK_2-3_BATCH_SCHEDULER.md` | Architecture doc | 800+ | Documentation |
| `docs/PHASE_4D_TASK_2-4_PAYOUT_METHODS.md` | Encryption guide | 600+ | Documentation |

**Modified Files** (4 total):

| File | Changes |
|------|---------|
| `includes/models/PayoutDTO.php` | Add status constants, validation methods |
| `includes/bootstrap.php` | Register WP-Cron hooks, service initialization |
| `tests/bootstrap.php` | Add integration test database setup |
| `composer.json` | Update dev dependencies if needed |

---

## 6. Testing

**Unit Tests** (52 test cases, ~2,000 LOC):
- **PayoutServiceTest.php**: 25 test cases
- **BatchSchedulerTest.php**: 15 test cases
- **PayoutMethodManagerTest.php**: 12 test cases

**Integration Tests** (12 E2E scenarios, ~500 LOC):
- Settlement→Payout complete flow
- Retry and recovery scenarios
- Error handling and rollback

**Coverage Goals**:
- 100% code coverage for production code
- 100% line coverage for all methods
- 95%+ branch coverage

**Testing Commands**:
```bash
# Unit tests
vendor/bin/phpunit tests/unit/Services/PayoutServiceTest.php --testdox
vendor/bin/phpunit tests/unit/Services/BatchSchedulerTest.php --testdox
vendor/bin/phpunit tests/unit/Services/PayoutMethodManagerTest.php --testdox

# Integration tests
vendor/bin/phpunit tests/integration/Services/PayoutIntegrationTest.php --testdox

# All tests with coverage
vendor/bin/phpunit tests/ --coverage-html=coverage/ --coverage-clover=coverage.xml
```

---

## 7. Risks & Assumptions

### Risks

- **RISK-001**: Payment processor API downtime
  - Mitigation: Exponential backoff retry logic, max 6 attempts, fallback to manual processing
  
- **RISK-002**: Encryption key compromise
  - Mitigation: Store in wp-config.php (not in git), implement key rotation procedure
  
- **RISK-003**: Race conditions in concurrent batch processing
  - Mitigation: Database locks (wc_auction_batch_locks), atomic transactions
  
- **RISK-004**: Large batch memory exhaustion (10,000+ payouts)
  - Mitigation: Chunking strategy (process in 100-payout groups), checkpoint to DB
  
- **RISK-005**: WP-Cron reliability (depends on site traffic)
  - Mitigation: Manual trigger endpoint + admin notifications of missed schedules

### Assumptions

- **ASSUMPTION-001**: All payment processors have documented APIs and SDK support
- **ASSUMPTION-002**: WordPress $wpdb will handle concurrent requests properly
- **ASSUMPTION-003**: Sellers have verified payout methods before batch processing
- **ASSUMPTION-004**: OpenSSL PHP extension available (standard in PHP 7.3+)
- **ASSUMPTION-005**: Database supports transactions (InnoDB)

---

## 8. Success Criteria

✅ **Code Quality**:
- All production code 100% type-hinted
- All methods have comprehensive PHPDoc with UML diagrams
- PSR-12 compliance via php-cs-fixer

✅ **Testing**:
- 52+ unit tests passing (100% coverage)
- 12+ integration tests passing
- All edge cases covered (timeouts, failures, retries)

✅ **Performance**:
- Payout initiation ≤ 500ms per payout ✅
- Batch processing 1,000 payouts ≤ 60 seconds ✅
- Status polling 100 payouts ≤ 10 seconds ✅

✅ **Security**:
- Banking details encrypted with AES-256 ✅
- No sensitive data in logs ✅
- All SQL via prepared statements ✅

✅ **Integration**:
- Seamless integration with Phase 2-1 adapters ✅
- Seamless integration with Phase 2-3 SchedulerService ✅
- Full E2E flow: Settlement→Batch→Payout complete ✅

✅ **Documentation**:
- Architecture diagrams for each task ✅
- API documentation with usage examples ✅
- Encryption key management guide ✅

---

## 9. Timeline & Effort Estimate

| Phase | Task | Duration | LOC | Effort |
|-------|------|----------|-----|--------|
| 2-2 | PayoutService Orchestrator | 3-4 days | 600-750 | 24-32 hours |
| 2-3 | Batch Scheduler | 2-3 days | 400-500 | 16-24 hours |
| 2-4 | PayoutMethodManager | 2 days | 350-450 | 16-20 hours |
| 2-5 | Integration Tests | 2-3 days | 400-500 | 16-24 hours |
| **TOTAL** | **All Tasks 2-2 to 2-5** | **10-14 days** | **~2,500+** | **72-100 hours** |

**Calendar Estimate** (assuming 8-hour workdays):
- Start: March 28, 2026
- Estimated Completion: April 10-14, 2026
- Buffer: +2 days for code review and refinement

---

**Author**: Development Team  
**Last Updated**: 2026-03-28  
**Status**: Ready for Implementation
