# Phase 1 TDD Workflow Guide

## Overview

Phase 1 uses **Test-Driven Development (TDD)** with the Red-Green-Refactor cycle. All tests are written first, then implementation code follows.

## Phase 1 Structure

```
📦 Phase 1: Performance Optimization & Async Processing
├── 7 Tasks (TASK-001 through TASK-007)
├── 97 Unit Tests across 7 test files
├── 25 Integration Tests
├── 5 Performance Benchmarks
└── Target: 100% test coverage, 1000+ bids/sec throughput
```

## Task Breakdown

| Task | Component | Tests | Status |
|------|-----------|-------|--------|
| TASK-001 | BidQueue (Redis) | 18 | 🔴 RED - Tests written, awaiting implementation |
| TASK-002 | AsyncWorker | 14 | ⚫ NOT STARTED |
| TASK-003 | CircuitBreaker | 16 | ⚫ NOT STARTED |
| TASK-004 | PerformanceMetrics | 12 | ⚫ NOT STARTED |
| TASK-005 | PerformanceDashboard | 10 | ⚫ NOT STARTED |
| TASK-006 | BatchProcessor | 15 | ⚫ NOT STARTED |
| TASK-007 | MonitoringAlerts | 12 | ⚫ NOT STARTED |

## Red-Green-Refactor Cycle

### 🔴 RED Phase (Current - TASK-001)

**Status**: Tests written, implementation not yet created

```bash
# 1. Run tests to see failures
vendor/bin/phpunit tests/unit/BidQueueTest.php

# Expected output:
# - Tests will FAIL because BidQueue class doesn't exist
# - This is normal and expected in TDD!
```

**Current Test Files Ready:**
- ✅ `tests/unit/BidQueueTest.php` - 18 failing tests
- Detailed test specifications in `plan/phase-1-tdd-test-specification.md`

### 🟢 GREEN Phase (Next - Write Implementation)

Once tests are in RED, create minimal implementation to pass tests:

**Step 1: Create BidQueue service structure**

Create file: `includes/services/BidQueue.php`

```php
<?php

namespace Yith\Services;

use Yith\Services\Queue\Job;
use Yith\Exceptions\Queue\ConnectionException;

class BidQueue
{
    private $redis;
    private $maxQueueSize = 10000;
    private $retryPolicy;

    public function __construct($redisClient)
    {
        $this->redis = $redisClient;
    }

    /**
     * Enqueue a bid job
     * 
     * @param array $jobData Job data containing auction_id, proxy_id, etc.
     * @param int $ttl Optional TTL in seconds
     * @param string $priority Priority level: 'LOW', 'NORMAL', 'HIGH'
     * @return string Job ID
     * @throws ValidationException
     * @throws OverflowException
     */
    public function enqueue(array $jobData, ?int $ttl = null, string $priority = 'NORMAL'): string
    {
        // TODO: Validate job data
        // TODO: Check queue size
        // TODO: Enqueue to Redis
        // TODO: Set TTL if provided
        return 'job-' . uniqid();
    }

    /**
     * Dequeue next job from queue
     * 
     * @return ?Job
     */
    public function dequeue(): ?Job
    {
        // TODO: Get job from Redis
        // TODO: Parse and return as Job object
        return null;
    }

    /**
     * Get queue size
     * 
     * @return int
     */
    public function getSize(): int
    {
        // TODO: Get queue size from Redis
        return 0;
    }

    /**
     * Clear all jobs from queue
     * 
     * @return void
     */
    public function clear(): void
    {
        // TODO: Clear Redis queue
    }

    /**
     * Flush queue and reset state
     * 
     * @return void
     */
    public function flush(): void
    {
        // TODO: Clear queue
        // TODO: Reset metrics and state
    }

    /**
     * Retry failed job
     * 
     * @param string $jobId
     * @param int $maxRetries
     * @return void
     * @throws MaxRetriesExceededException
     */
    public function retry(string $jobId, int $maxRetries = 3): void
    {
        // TODO: Implement retry logic
    }

    /**
     * Set retry policy
     * 
     * @param callable $policy
     * @return void
     */
    public function setRetryPolicy(callable $policy): void
    {
        $this->retryPolicy = $policy;
    }

    /**
     * Set job priority
     * 
     * @param string $jobId
     * @param string $priority
     * @return void
     */
    public function setPriority(string $jobId, string $priority): void
    {
        // TODO: Update job priority in queue
    }

    /**
     * Move job to dead-letter queue
     * 
     * @param string $jobId
     * @param array $jobData
     * @param string $reason
     * @return void
     */
    public function moveToDeadLetter(string $jobId, array $jobData, string $reason): void
    {
        // TODO: Move to dead-letter queue
    }

    /**
     * Get dead-letter queue jobs
     * 
     * @return array
     */
    public function getDeadLetterJobs(): array
    {
        // TODO: Retrieve dead-letter jobs
        return [];
    }

    /**
     * Set max queue size
     * 
     * @param int $size
     * @return void
     */
    public function setMaxQueueSize(int $size): void
    {
        $this->maxQueueSize = $size;
    }
}
```

**Step 2: Create required exception classes**

Create file: `includes/exceptions/Queue/ConnectionException.php`

```php
<?php

namespace Yith\Exceptions\Queue;

class ConnectionException extends \Exception {}
```

Create similar exception files:
- `includes/exceptions/Queue/ValidationException.php`
- `includes/exceptions/Queue/OverflowException.php`
- `includes/exceptions/Queue/MaxRetriesExceededException.php`

**Step 3: Create Job model class**

Create file: `includes/services/Queue/Job.php`

```php
<?php

namespace Yith\Services\Queue;

class Job
{
    private $id;
    private $data;
    private $auctionId;
    private $proxyId;

    public function __construct($id, array $data)
    {
        $this->id = $id;
        $this->data = $data;
        $this->auctionId = $data['auction_id'] ?? null;
        $this->proxyId = $data['proxy_id'] ?? null;
    }

    public function getAuctionId(): ?int
    {
        return $this->auctionId;
    }

    public function getProxyId(): ?int
    {
        return $this->proxyId;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
```

**Step 4: Run tests and implement to pass**

```bash
# Run tests - should see more failures now
vendor/bin/phpunit tests/unit/BidQueueTest.php --verbose

# Implement one method at a time until tests pass
# Focus on making each test pass with minimal code
```

### 🔵 REFACTOR Phase (After GREEN)

Once all tests pass, improve code quality:

- Extract duplicated logic into helper methods
- Improve variable naming
- Add performance optimizations
- Maintain 100% test coverage

## Workflow Commands

### View test specifications

```bash
# View full TDD test specification for all tasks
cat plan/phase-1-tdd-test-specification.md

# View test file structure
cat tests/unit/BidQueueTest.php
```

### Run tests

```bash
# Run TASK-001 tests (RED phase)
vendor/bin/phpunit tests/unit/BidQueueTest.php --verbose

# Watch test output as you implement
vendor/bin/phpunit tests/unit/BidQueueTest.php --verbose --testdox

# Show test failures with details
vendor/bin/phpunit tests/unit/BidQueueTest.php --verbose --debug

# Generate coverage report
vendor/bin/phpunit tests/unit/BidQueueTest.php --coverage-html=tests/coverage/
```

### Check progress

```bash
# Count passing vs failing tests
vendor/bin/phpunit tests/unit/BidQueueTest.php --report-json

# View test summary
vendor/bin/phpunit tests/unit/BidQueueTest.php --testdox
```

## Implementation Checklist for TASK-001

- [ ] Create exception classes (4 files)
- [ ] Create Job model class
- [ ] Create BidQueue service stub
- [ ] Implement `enqueue()` method
  - [ ] TEST-001-01: Basic enqueue
  - [ ] TEST-001-02: Enqueue returns job ID
  - [ ] TEST-001-14: Handle queue overflow
- [ ] Implement `dequeue()` method
  - [ ] TEST-001-02: Dequeue returns Job object
  - [ ] TEST-001-03: Dequeue from empty queue
- [ ] Implement `getSize()` method
  - [ ] TEST-001-04: Queue size accurate
- [ ] Implement `clear()` method
  - [ ] TEST-001-05: Clear removes all jobs
- [ ] Implement `flush()` method
  - [ ] TEST-001-06: Flush clears queue and state
- [ ] Implement retry mechanism (4 tests)
  - [ ] TEST-001-07: Retry failed jobs
  - [ ] TEST-001-08: Max retries exceeded
  - [ ] TEST-001-09: Exponential backoff
  - [ ] TEST-001-10: Custom retry policy
- [ ] Implement error handling (4 tests)
  - [ ] TEST-001-11: Redis connection error
  - [ ] TEST-001-12: Malformed job data
  - [ ] TEST-001-13: Job TTL expiration
  - [ ] TEST-001-14: Queue overflow
- [ ] Implement priority handling (4 tests)
  - [ ] TEST-001-15: Priority levels
  - [ ] TEST-001-16: Re-prioritize
  - [ ] TEST-001-17: FIFO within priority
  - [ ] TEST-001-18: Dead-letter queue

## Success Criteria for Each Task

### TASK-001 (BidQueue)
- ✅ All 18 tests passing
- ✅ 100% code coverage
- ✅ No test skips or incomplete tests
- ✅ Performance: Enqueue < 5ms, Dequeue < 5ms

### When Complete: Move to TASK-002

Once TASK-001 tests all pass:

1. Create `tests/unit/AsyncWorkerTest.php` with 14 failing tests
2. Implement `includes/workers/AsyncWorker.php`
3. Repeat Red-Green-Refactor cycle
4. Continue through all 7 tasks

## Key TDD Principles

1. **Write Tests First**: Always write failing tests before implementation
2. **Minimal Implementation**: Write just enough code to make tests pass
3. **Continuous Refactoring**: Improve code quality after tests pass
4. **100% Coverage Target**: Ensure all public methods are tested
5. **Fast Feedback**: Run tests frequently during development

## Common Pitfalls to Avoid

❌ Adding features not required by tests  
❌ Over-engineering before tests pass  
❌ Skipping the refactor phase  
❌ Writing vague assertions  
❌ Testing implementation details instead of behavior

✅ Focus on making tests pass  
✅ Keep implementation simple  
✅ Improve code quality in refactor phase  
✅ Write clear, specific assertions  
✅ Test observable behavior

## Documentation Files

- **Phase 1 TDD Specification**: `plan/phase-1-tdd-test-specification.md`
  - Complete test cases for all 7 tasks
  - Unit test groups and specific test descriptions
  - Integration tests and performance benchmarks
  - Running instructions and success criteria

- **This Guide**: `docs/PHASE-1-TDD-WORKFLOW.md`
  - How to execute the TDD cycle
  - Implementation checklist for TASK-001
  - Commands for running and monitoring tests

## Next Steps

1. ✅ Review this TDD workflow guide
2. ✅ Review full test specification (`plan/phase-1-tdd-test-specification.md`)
3. ⏳ Begin RED phase: Run `BidQueueTest.php` to see failures
4. ⏳ Begin GREEN phase: Implement `BidQueue.php` to pass tests
5. ⏳ Begin REFACTOR phase: Improve code quality
6. ⏳ Repeat for TASK-002 through TASK-007

---

**Ready to start TASK-001 TDD development!**

The test file is waiting at: `tests/unit/BidQueueTest.php`
The specification is at: `plan/phase-1-tdd-test-specification.md`

Execute first command:
```bash
vendor/bin/phpunit tests/unit/BidQueueTest.php --verbose
```

This will show 18 failing tests, marking the beginning of Phase 1 TDD implementation.
