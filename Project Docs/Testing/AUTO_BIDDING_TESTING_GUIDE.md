## Auto-Bidding System Unit Testing Guide

**Requirement**: REQ-AUTO-BID-SERVICE-001, REQ-AUTO-BID-ENGINE-001, REQ-AUTO-BID-REPO-001

This guide documents the comprehensive unit test suite for the proxy bidding engine, auto-bid service, and repository.

---

## Test Structure Overview

```
tests/
├── Unit/
│   └── Services/
│       └── AutoBidding/
│           ├── ProxyBiddingEngineTest.php    (Algorithm tests)
│           ├── AutoBidRepositoryTest.php     (Data access tests)
│           └── AutoBidServiceTest.php        (Orchestration tests)
└── Integration/
    └── AutoBidding/
        └── AutoBiddingWorkflowTest.php       (End-to-end tests)
```

---

## Unit Tests Overview

### 1. ProxyBiddingEngineTest (Algorithm Layer)

**File**: `tests/Unit/Services/AutoBidding/ProxyBiddingEngineTest.php`

Tests the core bidding algorithm logic without dependencies.

#### Key Test Cases:

**Bid Validation**
- `testValidateBid_Success`: Valid bids pass validation
- `testValidateBid_InvalidAmount`: Negative amounts rejected
- `testValidateBid_BelowMinimum`: Amounts below minimum increment rejected
- `testValidateBid_MaximumExceeded`: Amounts above maximum rejected

**Increment Calculation**
- `testCalculateMinimumIncrement_Small`: Correct increment for small amounts
- `testCalculateMinimumIncrement_Medium`: Correct increment for medium amounts
- `testCalculateMinimumIncrement_Large`: Correct increment for large amounts

**Proxy Bid Calculation**
- `testCalculateProxyBid_Simple`: Current bid < max = proxy bid calculated
- `testCalculateProxyBid_AtMaximum`: Current bid >= max = return current
- `testCalculateProxyBid_NoWinner`: No current bid = return minimum proxy

**Counter-Bid Logic**
- `testShouldPlaceCounterBid_Yes`: Valid conditions place counter bid
- `testShouldPlaceCounterBid_No_OutbidderHigher`: Outbidder already above max
- `testShouldPlaceCounterBid_No_AlreadyHighest`: Auto-bidder already highest
- `testShouldPlaceCounterBid_No_MaximumReached`: Auto-bid maximum reached

**Edge Cases**
- `testBidWithPrecisionIssues`: Handles floating-point precision
- `testBidWithZeroAmount`: Zero amounts handled correctly
- `testBidWithVeryLargeAmount`: Large amounts within system limits
- `testBidWithIncrementBoundaries`: Exactly at increment boundaries

### 2. AutoBidRepositoryTest (Data Access Layer)

**File**: `tests/Unit/Repository/AutoBidRepositoryTest.php`

Tests database operations with mocked database.

#### Key Test Cases:

**Create Operations**
- `testCreate_Success`: Auto-bid created with all defaults
- `testCreate_WithCustomTime`: Custom timestamps preserved
- `testCreate_GeneratesValidUUID`: UUID generated correctly

**Read Operations**
- `testGetById_Success`: Record retrieved by ID
- `testGetById_NotFound`: Returns null for missing records
- `testGetActiveForAuctionUser_Success`: Correct filtering by auction/user
- `testGetActiveForAuctionUser_OnlyActive`: Doesn't return inactive records
- `testGetActiveForAuction_MultipleActive`: All active records returned
- `testGetForUser_Filters`: Status filters applied correctly

**Update Operations**
- `testUpdate_Success`: Record updated correctly
- `testUpdate_PreservesOtherFields`: Other fields unchanged
- `testUpdate_CannotUndo`: Status changes cannot be undone to higher priority
- `testUpdate_NonExistent`: False returned for non-existent records

**Delete Operations**
- `testSoftDelete_Success`: Record marked deleted
- `testSoftDelete_CleanupLinkedData`: Related records cleaned
- `testSoftDelete_DoesNotExceedAuction`: Doesn't delete related auction

**History Operations**
- `testRecordHistory_Success`: Event recorded
- `testRecordHistory_WithContext`: Custom context preserved
- `testGetHistory_Success`: Events retrieved in order
- `testGetHistory_WithLimit`: Pagination works correctly

**Migration Verification**
- `testMigration_TablesExist`: All required tables created
- `testMigration_IndexesExist`: Indexes created for performance
- `testMigration_ForeignKeys`: Referential integrity maintained

### 3. AutoBidServiceTest (Orchestration Layer)

**File**: `tests/Unit/Services/AutoBidServiceTest.php`

Tests high-level service operations with all dependencies mocked.

#### Key Test Cases:

**Set Auto-Bid**
- `testSetAutoBid_Success`: Auto-bid created and history recorded
- `testSetAutoBid_DuplicateFails`: Cannot create duplicate for auction+user
- `testSetAutoBid_InvalidAmount`: Negative or zero amounts rejected
- `testSetAutoBid_CallsRepository`: Repository create called with correct data

**Cancel Auto-Bid**
- `testCancelAutoBid_Success`: Auto-bid marked CANCELLED
- `testCancelAutoBid_CannotCancelTerminal`: COMPLETED/FAILED cannot cancel
- `testCancelAutoBid_CannotCancelCancelled`: Already CANCELLED rejected
- `testCancelAutoBid_RecordsHistory`: History event created

**Update Maximum**
- `testUpdateMaximum_Success`: Maximum updated and history recorded
- `testUpdateMaximum_CannotDecrease`: Cannot lower maximum
- `testUpdateMaximum_AllowsEquality`: Can set equal to current
- `testUpdateMaximum_RejectedForComposed`: COMPOSED statuses rejected

**Retrieval**
- `testGetAutoBid`: Returns correct auto-bid data
- `testGetAutoBid_NotFound`: Returns null for missing
- `testGetUserAutoBids`: Returns all user auto-bids
- `testGetUserAutoBids_Filters`: Status filters applied

**History**
- `testGetHistory_Success`: History events returned
- `testGetHistory_Ordered`: Events in correct order
- `testGetHistory_WithLimit`: Pagination works

**Process Outbid**
- `testProcessOutbid_PlacesCounterBid`: Counter bid queued when appropriate
- `testProcessOutbid_NoActiveBids`: False when no active bids
- `testProcessOutbid_SkipsOutbidderBid`: Doesn't counter own bid
- `testProcessOutbid_CallsBidQueue`: Bid queue enqueue called

---

## Running the Tests

### Run All Auto-Bidding Tests
```bash
./vendor/bin/phpunit tests/Unit/Services/AutoBidding/
```

### Run Specific Test Class
```bash
./vendor/bin/phpunit tests/Unit/Services/AutoBidding/ProxyBiddingEngineTest.php
```

### Run Single Test Method
```bash
./vendor/bin/phpunit tests/Unit/Services/AutoBidding/ProxyBiddingEngineTest.php::ProxyBiddingEngineTest::testCalculateProxyBid_Simple
```

### Generate Coverage Report
```bash
./vendor/bin/phpunit --coverage-html=coverage tests/Unit/Services/AutoBidding/
```

### Run with Strict Type Checking
```bash
./vendor/bin/phpunit --configuration phpunit-strict.xml tests/Unit/Services/AutoBidding/
```

---

## Testing Patterns Used

### 1. Arrange-Act-Assert (AAA)
```php
// Arrange
$auto_bid = new AutoBid(123, 456, 100.00);

// Act
$result = $auto_bid->calculateProxyBid(50.00);

// Assert
$this->assertEquals(50.75, $result);
```

### 2. Mock Dependencies
```php
$repository = $this->mock(AutoBidRepository::class);
$repository->shouldReceive('create')->andReturn('uuid-123');
```

### 3. Test Data Builders
```php
$auto_bid = $this->createAutoBid()
    ->withAuction(123)
    ->withUser(456)
    ->withMaximum(100.00)
    ->build();
```

### 4. Parameterized Tests
```php
/**
 * @dataProvider incrementProvider
 */
public function testCalculateIncrement($bid, $expected) {
    // Test with multiple data sets
}
```

---

## Mocking Strategy

### Repository Mocks
```php
$repository = $this->mock(AutoBidRepository::class);

// Mock read operations
$repository->shouldReceive('getById')
    ->with('uuid-123')
    ->andReturn($auto_bid_data);

// Mock write operations
$repository->shouldReceive('create')
    ->withArgs(function($data) {
        return $data['auction_id'] === 123;
    })
    ->andReturn('generated-uuid');

// Mock history recording
$repository->shouldReceive('recordHistory')
    ->once();
```

### Engine Mocks
```php
$engine = $this->mock(ProxyBiddingEngine::class);

// Mock algorithm results
$engine->shouldReceive('calculateProxyBid')
    ->andReturn(ProxyBidResult::fromAmount(75.50));

// Mock validation
$engine->shouldReceive('shouldPlaceCounterBid')
    ->andReturn(true);
```

### Queue Mocks
```php
$bid_queue = $this->mock(BidQueue::class);

// Mock job enqueueing
$bid_queue->shouldReceive('enqueue')
    ->andReturn('job-id-12345');
```

---

## Coverage Requirements

### Target Coverage: 100% for Auto-Bidding

- **ProxyBiddingEngine**: 100% line and branch coverage
- **AutoBidRepository**: 100% line coverage (branches vary by DB layer)
- **AutoBidService**: 100% line coverage

### Coverage Report Sections

1. **Algorithm Logic** (`ProxyBiddingEngine`)
   - All calculation paths tested
   - All validation conditions tested
   - All edge cases tested

2. **Data Persistence** (`AutoBidRepository`)
   - All CRUD operations tested
   - All query conditions tested
   - All error conditions handled

3. **Service Orchestration** (`AutoBidService`)
   - All business rule validations tested
   - All state transitions tested
   - All external service calls tested

---

## Debugging Failed Tests

### Common Issues

**1. Mock not called**
```php
// Problem: Expected call didn't happen
$this->repository->shouldReceive('update')->once();
// Verify: Is the method actually being called?
```

**Solution**: Add debugging output or trace execution.

**2. Floating-point precision**
```php
// Problem: 100.1 + 0.2 ≠ 100.3
$this->assertEquals(100.3, 100.1 + 0.2); // FAILS

// Solution: Use almost-equal assertion
$this->assertEqualsWithDelta(100.3, 100.1 + 0.2, 0.01);
```

**3. DateTime comparison**
```php
// Problem: Timestamps differ by milliseconds
$this->assertEquals($expected_time, $actual_time);

// Solution: Compare date strings
$this->assertEquals(
    $expected_time->format('Y-m-d H:i:s'),
    $actual_time->format('Y-m-d H:i:s')
);
```

---

## Test Data Scenarios

### Valid Auto-Bid Scenario
```php
Auction: created, active, ends in 2 hours
User: registered, in good standing
Auto-Bid: maximum=100.00, active
Current Bid: 50.00 by competitor
Expected: Counter-bid of 50.75 placed automatically
```

### Maximum Reached Scenario
```php
Auto-Bid: maximum=50.00, active
Current Bid: 49.00
New Bid: 50.00 by competitor
Expected: No counter-bid (already at maximum)
```

### Cancellation Scenario
```php
Auto-Bid: maximum=100.00, status=ACTIVE
User Action: Cancel auto-bid
Expected: Status changed to CANCELLED, history recorded
```

---

## Integration Testing

### End-to-End Scenarios

**Scenario 1: Normal Auction with Auto-Bidding**
1. Create auction (ends in 1 hour)
2. Bidder A sets auto-bid of $100
3. Bidder B places bid of $50 → Bidder A counter-bids $50.75
4. Bidder B places bid of $60 → Bidder A counter-bids $60.75
5. Auction ends → Bidder A wins with $60.75

**Scenario 2: Auto-Bid Maximum Exceeded**
1. Create auction
2. Bidder A sets auto-bid of $100
3. Bidder B places bid of $101
4. Expected: No counter-bid, Bidder B wins

**Scenario 3: Multiple Auto-Bidders**
1. Bidder A: auto-bid $100
2. Bidder B: auto-bid $120
3. Bidder C: normal bid $50
4. Expected: Automatic escalation between A and B until max reached

See `tests/Integration/AutoBiddingWorkflowTest.php` for implementation.

---

## Test Maintenance

### When to Update Tests

1. **Algorithm Changes**: Recalculate expected values
2. **Validation Rules**: Add new test cases
3. **Status Transitions**: Update state machine tests
4. **Database Schema**: Update migration tests

### Documentation Requirements

- Each test must reference the requirement it covers
- Complex tests need inline comments explaining logic
- Data setup should be documented

### CI/CD Integration

- Tests run on every commit
- Coverage reports generated automatically
- Failures block merge requests
- Performance tests run nightly

---

## Best Practices

✅ **DO:**
- Test one logical concept per test
- Use descriptive test names (test content is clear from name)
- Keep tests independent (no shared state)
- Mock external dependencies
- Test edge cases and error conditions
- Keep tests fast (< 1 second per test)

❌ **DON'T:**
- Test framework code (PHPUnit internals)
- Test external libraries (trust they work)
- Create test interdependencies
- Make real database calls in unit tests
- Use time-dependent assertions (sleep, now())
- Skip tests instead of fixing them
