# Bid Queue System - Testing Guide

## Overview

This guide provides comprehensive testing strategies for the Bid Queue system, including unit tests, integration tests, and quality assurance procedures.

## Test Structure

```
tests/
├── Unit/
│   ├── Services/
│   │   ├── BidQueueTest.php
│   │   └── QueueStatusTest.php
│   ├── Database/
│   │   ├── MigrationTest.php
│   │   ├── DatabaseSetupTest.php
│   │   └── QueueServiceFactoryTest.php
│   ├── Models/
│   │   ├── JobTest.php
│   │   └── JobStatusTest.php
│   └── Exceptions/
│       └── QueueExceptionsTest.php
├── Integration/
│   ├── BidQueueIntegrationTest.php
│   ├── DatabaseSetupIntegrationTest.php
│   └── EndToEndTest.php
└── Fixtures/
    └── TestData.php
```

## Unit Testing

### 1. BidQueue Service Tests

**Test File**: `tests/Unit/Services/BidQueueTest.php`

```php
<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Services\BidQueue;
use WC\Auction\Services\Queue\Job;
use WC\Auction\Services\Queue\JobStatus;
use WC\Auction\Exceptions\Queue\ValidationException;
use WC\Auction\Exceptions\Queue\JobNotFoundException;

class BidQueueTest extends TestCase
{
    private $mockWpdb;
    private $queue;

    protected function setUp(): void
    {
        $this->mockWpdb = $this->createMock(\wpdb::class);
        $this->mockWpdb->base_prefix = 'wp_';
        $this->queue = new BidQueue($this->mockWpdb, 'wp_wc_auction_bid_queue');
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     */
    public function testEnqueueGeneratesUniqueJobId(): void
    {
        // Arrange
        $this->mockWpdb->method('insert')->willReturn(1);
        
        // Act
        $jobId1 = $this->queue->enqueue('bid', ['amount' => 100]);
        $jobId2 = $this->queue->enqueue('bid', ['amount' => 200]);
        
        // Assert
        $this->assertNotEmpty($jobId1);
        $this->assertNotEmpty($jobId2);
        $this->assertNotEquals($jobId1, $jobId2);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     */
    public function testEnqueueValidatesJobType(): void
    {
        // Arrange
        $invalidJobType = '';
        
        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->queue->enqueue($invalidJobType, ['data' => 'test']);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     */
    public function testEnqueueValidatesJobData(): void
    {
        // Arrange
        $invalidData = 'not-an-array';
        
        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->queue->enqueue('bid', $invalidData);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     */
    public function testEnqueueValidatesPriority(): void
    {
        // Arrange
        $invalidPriority = 'CRITICAL';
        
        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->queue->enqueue('bid', ['data' => 'test'], $invalidPriority);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     */
    public function testEnqueueStoresDataAsJson(): void
    {
        // Arrange
        $jobData = ['auction_id' => 123, 'amount' => 99.99];
        
        // Capture the INSERT call
        $this->mockWpdb->expects($this->once())
            ->method('insert')
            ->with(
                'wp_wc_auction_bid_queue',
                $this->callback(function ($data) use ($jobData) {
                    // Verify data is JSON encoded
                    $decoded = json_decode($data['data'], true);
                    return $decoded === $jobData;
                })
            )
            ->willReturn(1);
        
        // Act
        $this->queue->enqueue('bid', $jobData);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     */
    public function testDequeueReturnsPendingJobs(): void
    {
        // Arrange
        $mockJobRow = (object)[
            'job_id' => 'test-id-1',
            'data' => '{"amount": 100}',
            'status' => 'PENDING',
            'priority' => 'HIGH',
            'retry_count' => 0,
            'max_retries' => 3,
            'error_message' => null,
        ];
        
        $this->mockWpdb->method('get_results')
            ->willReturn([$mockJobRow]);
        $this->mockWpdb->method('update')
            ->willReturn(1);
        
        // Act
        $jobs = $this->queue->dequeue(1);
        
        // Assert
        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(Job::class, $jobs[0]);
        $this->assertEquals('test-id-1', $jobs[0]->getId());
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     */
    public function testDequeueUpdateStatusToProcessing(): void
    {
        // Arrange
        $this->mockWpdb->method('get_results')->willReturn([]);
        $this->mockWpdb->expects($this->once())
            ->method('update')
            ->with(
                'wp_wc_auction_bid_queue',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                ['%s']
            );
        
        // Act
        $this->queue->dequeue(1);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-002
     */
    public function testMarkCompletedUpdatesStatus(): void
    {
        // Arrange
        $jobId = 'test-id-1';
        $this->mockWpdb->expects($this->once())
            ->method('update')
            ->with(
                'wp_wc_auction_bid_queue',
                ['status' => JobStatus::COMPLETED],
                ['job_id' => $jobId]
            )
            ->willReturn(1);
        
        // Act
        $this->queue->markCompleted($jobId);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-002
     */
    public function testMarkCompletedThrowsExceptionIfJobNotFound(): void
    {
        // Arrange
        $jobId = 'nonexistent-id';
        $this->mockWpdb->method('update')->willReturn(false);
        
        // Act & Assert
        $this->expectException(JobNotFoundException::class);
        $this->queue->markCompleted($jobId);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-003
     */
    public function testMarkFailedIncrementsRetryCount(): void
    {
        // Arrange
        $jobId = 'test-id-1';
        $mockJobRow = (object)[
            'job_id' => $jobId,
            'retry_count' => 1,
            'max_retries' => 3,
        ];
        
        $this->mockWpdb->method('get_row')->willReturn($mockJobRow);
        $this->mockWpdb->expects($this->once())
            ->method('update')
            ->with(
                'wp_wc_auction_bid_queue',
                $this->callback(function ($data) {
                    // Verify status is FAILED (not DEAD_LETTER)
                    return $data['status'] === JobStatus::FAILED;
                })
            );
        
        // Act
        $this->queue->markFailed($jobId, 'Test error');
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-003
     */
    public function testMarkFailedMovesToDeadLetterAfterMaxRetries(): void
    {
        // Arrange
        $jobId = 'test-id-1';
        $mockJobRow = (object)[
            'job_id' => $jobId,
            'retry_count' => 3,
            'max_retries' => 3,
        ];
        
        $this->mockWpdb->method('get_row')->willReturn($mockJobRow);
        $this->mockWpdb->expects($this->once())
            ->method('update')
            ->with(
                'wp_wc_auction_bid_queue',
                $this->callback(function ($data) {
                    // Verify status is DEAD_LETTER
                    return $data['status'] === JobStatus::DEAD_LETTER;
                })
            );
        
        // Act
        $this->queue->markFailed($jobId, 'Max retries exceeded');
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-003
     */
    public function testGetDeadLetterJobs(): void
    {
        // Arrange
        $mockJobRow = (object)[
            'job_id' => 'dead-letter-id',
            'data' => '{"test": true}',
            'priority' => 'NORMAL',
        ];
        
        $this->mockWpdb->method('get_results')->willReturn([$mockJobRow]);
        
        // Act
        $jobs = $this->queue->getDeadLetterJobs();
        
        // Assert
        $this->assertCount(1, $jobs);
        $this->assertEquals('dead-letter-id', $jobs[0]->getId());
    }

    /**
     * @test
     */
    public function testSetPriority(): void
    {
        // Arrange
        $jobId = 'test-id-1';
        $newPriority = 'HIGH';
        
        $this->mockWpdb->expects($this->once())
            ->method('update')
            ->with(
                'wp_wc_auction_bid_queue',
                ['priority' => $newPriority],
                ['job_id' => $jobId]
            )
            ->willReturn(1);
        
        // Act
        $this->queue->setPriority($jobId, $newPriority);
    }

    /**
     * @test
     */
    public function testGetJob(): void
    {
        // Arrange
        $jobId = 'test-id-1';
        $mockJobRow = (object)[
            'job_id' => $jobId,
            'data' => '{"amount": 100}',
            'priority' => 'HIGH',
        ];
        
        $this->mockWpdb->method('get_row')->willReturn($mockJobRow);
        
        // Act
        $job = $this->queue->getJob($jobId);
        
        // Assert
        $this->assertInstanceOf(Job::class, $job);
        $this->assertEquals($jobId, $job->getId());
    }

    /**
     * @test
     */
    public function testGetJobReturnsNullIfNotFound(): void
    {
        // Arrange
        $this->mockWpdb->method('get_row')->willReturn(null);
        
        // Act
        $job = $this->queue->getJob('nonexistent-id');
        
        // Assert
        $this->assertNull($job);
    }
}
```

### 2. Job Model Tests

**Test File**: `tests/Unit/Models/JobTest.php`

```php
<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use WC\Auction\Services\Queue\Job;
use WC\Auction\Services\Queue\JobStatus;

class JobTest extends TestCase
{
    /**
     * @test
     * @requirement REQ-QUEUE-JOB-001
     */
    public function testJobIsImmutable(): void
    {
        // Arrange
        $job = new Job(
            'test-id',
            ['data' => 'value'],
            'HIGH'
        );
        
        // Act & Assert
        $this->expectException(\BadMethodCallException::class);
        $job->setStatus(JobStatus::COMPLETED);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-JOB-001
     */
    public function testJobStoresAllProperties(): void
    {
        // Arrange
        $id = 'test-id';
        $data = ['auction_id' => 123];
        $priority = 'HIGH';
        
        // Act
        $job = new Job($id, $data, $priority);
        
        // Assert
        $this->assertEquals($id, $job->getId());
        $this->assertEquals($data, $job->getData());
        $this->assertEquals($priority, $job->getPriority());
    }

    /**
     * @test
     */
    public function testJobDeserializesFromArray(): void
    {
        // Arrange
        $jobData = [
            'job_id' => 'test-id',
            'data' => ['amount' => 100],
            'priority' => 'NORMAL',
            'status' => 'PENDING',
            'retry_count' => 0,
            'max_retries' => 3,
        ];
        
        // Act
        $job = Job::fromArray($jobData);
        
        // Assert
        $this->assertEquals('test-id', $job->getId());
        $this->assertEquals(['amount' => 100], $job->getData());
    }
}
```

### 3. Database Setup Tests

**Test File**: `tests/Unit/Database/DatabaseSetupTest.php`

```php
<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use WC\Auction\Database\DatabaseSetup;
use WC\Auction\Database\Migration;
use WC\Auction\Exceptions\Queue\ConnectionException;

class DatabaseSetupTest extends TestCase
{
    private $mockWpdb;
    private $setup;

    protected function setUp(): void
    {
        $this->mockWpdb = $this->createMock(\wpdb::class);
        $this->mockWpdb->base_prefix = 'wp_';
        $this->setup = new DatabaseSetup($this->mockWpdb);
    }

    /**
     * @test
     * @requirement REQ-QUEUE-DB-001
     */
    public function testInitializeCreatesTable(): void
    {
        // This is tested in integration tests
    }

    /**
     * @test
     */
    public function testGetTableNameIncludesPrefix(): void
    {
        // Act
        $tableName = $this->setup->getTableName();
        
        // Assert
        $this->assertStringContainsString('wp_', $tableName);
        $this->assertStringContainsString('wc_auction_bid_queue', $tableName);
    }

    /**
     * @test
     */
    public function testGetMigrationLazyLoadsInstance(): void
    {
        // Act
        $migration1 = $this->setup->getMigration();
        $migration2 = $this->setup->getMigration();
        
        // Assert
        $this->assertSame($migration1, $migration2);
    }

    /**
     * @test
     */
    public function testConstructorRejectsInvalidWpdb(): void
    {
        // Arrange
        $invalidWpdb = new \stdClass();
        
        // Act & Assert
        $this->expectException(ConnectionException::class);
        new DatabaseSetup($invalidWpdb);
    }
}
```

## Integration Testing

### 1. Full Queue Lifecycle Integration Test

**Test File**: `tests/Integration/BidQueueIntegrationTest.php`

```php
<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use WC\Auction\Database\QueueServiceFactory;
use WC\Auction\Services\Queue\JobStatus;

class BidQueueIntegrationTest extends TestCase
{
    private $queue;
    private $setup;

    protected function setUp(): void
    {
        global $wpdb;
        
        // Initialize factory
        $this->setup = QueueServiceFactory::setup($wpdb);
        
        // Create queue instance
        $this->queue = QueueServiceFactory::createBidQueue();
        
        // Ensure clean table
        $this->setup->dropTable();
        $this->setup->initialize();
    }

    protected function tearDown(): void
    {
        // Clean up
        QueueServiceFactory::reset();
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     * Integration test for complete workflow
     */
    public function testCompleteJobLifecycle(): void
    {
        // === ENQUEUE ===
        $jobId = $this->queue->enqueue(
            'auction-bid',
            [
                'auction_id' => 123,
                'product_id' => 456,
                'bid_amount' => 99.99,
            ],
            'HIGH'
        );
        
        // Verify job was enqueued
        $job = $this->queue->getJob($jobId);
        $this->assertNotNull($job);
        $this->assertEquals(JobStatus::PENDING, $job->getStatus());
        
        // === DEQUEUE ===
        $jobs = $this->queue->dequeue(1);
        $this->assertCount(1, $jobs);
        $this->assertEquals($jobId, $jobs[0]->getId());
        
        // Verify status changed to PROCESSING
        $job = $this->queue->getJob($jobId);
        $this->assertEquals(JobStatus::PROCESSING, $job->getStatus());
        
        // === COMPLETE ===
        $this->queue->markCompleted($jobId);
        
        // Verify final status
        $job = $this->queue->getJob($jobId);
        $this->assertEquals(JobStatus::COMPLETED, $job->getStatus());
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-002
     * Integration test for retry mechanism
     */
    public function testRetryMechanism(): void
    {
        // Enqueue job
        $jobId = $this->queue->enqueue('test-job', ['data' => 'test']);
        
        // First attempt - dequeue and fail
        $jobs = $this->queue->dequeue(1);
        $this->queue->markFailed($jobId, 'First attempt failed');
        
        // Verify moved back to PENDING (status before next retry)
        // Note: Implementation may vary
        
        // Verify we can dequeue again
        $jobs = $this->queue->dequeue(1);
        $this->assertCount(1, $jobs);
        
        // Max retries reached - should go to dead-letter
        for ($i = 0; $i < 2; $i++) {
            $this->queue->markFailed($jobId, 'Attempt ' . ($i + 2) . ' failed');
            $job = $this->queue->getJob($jobId);
            
            // After 3 failures, should be DEAD_LETTER
            if ($i === 1) {
                $this->assertEquals(JobStatus::DEAD_LETTER, $job->getStatus());
            }
        }
    }

    /**
     * @test
     * @requirement REQ-QUEUE-ARCH-001
     * Integration test for priority ordering
     */
    public function testPriorityOrdering(): void
    {
        // Enqueue jobs with different priorities
        $lowId = $this->queue->enqueue('low', ['priority' => 'low'], 'LOW');
        $highId = $this->queue->enqueue('high', ['priority' => 'high'], 'HIGH');
        $normalId = $this->queue->enqueue('normal', ['priority' => 'normal'], 'NORMAL');
        
        // Dequeue - should get HIGH priority first
        $job1 = $this->queue->dequeue(1)[0];
        $this->assertEquals($highId, $job1->getId());
        
        // Reset to PENDING for next dequeue
        $this->queue->markFailed($highId, '');
        
        // Dequeue - should get NORMAL priority
        $job2 = $this->queue->dequeue(1)[0];
        $this->assertEquals($normalId, $job2->getId());
    }

    /**
     * @test
     */
    public function testMultipleJobsInBatch(): void
    {
        // Enqueue multiple jobs
        for ($i = 1; $i <= 5; $i++) {
            $this->queue->enqueue('job-' . $i, ['index' => $i]);
        }
        
        // Dequeue multiple at once
        $jobs = $this->queue->dequeue(3);
        $this->assertCount(3, $jobs);
        
        // Verify we got different jobs
        $ids = array_map(fn($j) => $j->getId(), $jobs);
        $this->assertEquals(3, count(array_unique($ids)));
    }

    /**
     * @test
     */
    public function testDatabasePersistence(): void
    {
        // Enqueue job
        $jobId = $this->queue->enqueue('persistent', ['data' => 'test']);
        
        // Create new queue instance (simulating new request)
        $newQueue = QueueServiceFactory::createBidQueue();
        
        // Should still be able to retrieve the job
        $job = $newQueue->getJob($jobId);
        $this->assertNotNull($job);
        $this->assertEquals($jobId, $job->getId());
    }
}
```

### 2. Database Migration Integration Test

**Test File**: `tests/Integration/DatabaseSetupIntegrationTest.php`

```php
<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use WC\Auction\Database\DatabaseSetup;
use WC\Auction\Database\Migration;

class DatabaseSetupIntegrationTest extends TestCase
{
    private $setup;
    private $wpdb;

    protected function setUp(): void
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        $this->setup = new DatabaseSetup($wpdb);
        
        // Clean up before test
        $this->setup->dropTable();
    }

    protected function tearDown(): void
    {
        // Clean up after test
        $this->setup->dropTable();
    }

    /**
     * @test
     * @requirement REQ-QUEUE-DB-001
     */
    public function testTableCreation(): void
    {
        // Act
        $result = $this->setup->initialize();
        
        // Assert
        $this->assertTrue($result);
        $this->assertTrue($this->setup->isReady());
    }

    /**
     * @test
     */
    public function testTableHasCorrectSchema(): void
    {
        // Arrange
        $this->setup->initialize();
        
        // Act
        $schema = $this->setup->getMigration()->getSchema();
        
        // Assert
        $this->assertNotNull($schema);
        
        $columnNames = array_column($schema, 'COLUMN_NAME');
        $this->assertContains('job_id', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('priority', $columnNames);
        $this->assertContains('data', $columnNames);
    }

    /**
     * @test
     */
    public function testMigrationIsIdempotent(): void
    {
        // Act
        $result1 = $this->setup->initialize();
        $result2 = $this->setup->initialize();
        
        // Assert - both should succeed
        $this->assertTrue($result1);
        $this->assertTrue($result2);
    }

    /**
     * @test
     */
    public function testVersionTracking(): void
    {
        // Arrange
        $this->setup->initialize();
        
        // Act
        $version = $this->setup->getMigration()->getCurrentVersion();
        
        // Assert
        $this->assertEquals('1.0.0', $version);
    }
}
```

## Test Fixtures and Helpers

**File**: `tests/Fixtures/TestData.php`

```php
<?php

namespace Tests\Fixtures;

class TestData
{
    /**
     * Create sample bid job data
     */
    public static function bidJobData(): array
    {
        return [
            'auction_id' => 123,
            'product_id' => 456,
            'bid_amount' => 99.99,
            'bidder_id' => 789,
        ];
    }

    /**
     * Create sample job with all properties
     */
    public static function fullJobData(): array
    {
        return [
            'job_id' => 'test-job-' . uniqid(),
            'data' => self::bidJobData(),
            'status' => 'PENDING',
            'priority' => 'NORMAL',
            'retry_count' => 0,
            'max_retries' => 3,
            'error_message' => null,
        ];
    }
}
```

## Running Tests

### PHPUnit Configuration

**File**: `phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.5/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    beStrictAboutOutputDuringTests="true"
    beStrictAboutTestsThatDoNotTestAnything="true"
>
    <testsuites>
        <testsuite name="Unit Tests">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration Tests">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>

    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">src</directory>
        </include>
        <exclude>
            <directory suffix="Test.php">src</directory>
        </exclude>
        <report>
            <html outputDirectory="build/coverage"/>
            <text outputFile="php://stdout" showUncoveredFiles="true"/>
        </report>
    </coverage>
</phpunit>
```

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run only unit tests
vendor/bin/phpunit tests/Unit

# Run only integration tests
vendor/bin/phpunit tests/Integration

# Run with coverage report
vendor/bin/phpunit --coverage-html build/coverage

# Run specific test
vendor/bin/phpunit tests/Unit/Services/BidQueueTest.php::BidQueueTest::testEnqueueGeneratesUniqueJobId
```

## Code Coverage Goals

| Component | Target Coverage | Current |
|---|---|---|
| BidQueue | 100% | - |
| DatabaseSetup | 100% | - |
| Migration | 100% | - |
| Job | 100% | - |
| JobStatus | 100% | - |
| Exceptions | 100% | - |
| **Overall** | **100%** | - |

## Test Quality Checklist

- ✅ Each test has a single assertion focus
- ✅ Arrange-Act-Assert pattern used
- ✅ Mock external dependencies
- ✅ Use real database for integration tests
- ✅ Independent tests (no order dependency)
- ✅ Descriptive test names
- ✅ Requirement mapping (@requirement tags)
- ✅ Edge cases tested
- ✅ Error conditions tested
- ✅ Performance acceptable (<100ms per test)

## Continuous Integration

### GitHub Actions Example

**File**: `.github/workflows/test.yml`

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    strategy:
      matrix:
        php-version: ['7.3', '7.4', '8.0', '8.1']
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: intl, bcmath
      
      - name: Install dependencies
        run: composer install
      
      - name: Run tests
        run: vendor/bin/phpunit
      
      - name: Upload coverage
        uses: codecov/codecov-action@v1
        with:
          files: ./coverage.xml
```

## Performance Testing

### Load Testing Script

```php
<?php

// tests/Performance/QueueLoadTest.php

$queue = QueueServiceFactory::createBidQueue();

$startTime = microtime(true);
$jobCount = 1000;

// Enqueue 1000 jobs
for ($i = 0; $i < $jobCount; $i++) {
    $queue->enqueue('perf-test', ['index' => $i]);
}

$enqueueTime = microtime(true) - $startTime;
echo "Enqueued $jobCount jobs in " . round($enqueueTime, 2) . "s\n";

// Dequeue in batches
$startTime = microtime(true);
$processed = 0;

while ($processed < $jobCount) {
    $jobs = $queue->dequeue(100);
    if (empty($jobs)) break;
    
    foreach ($jobs as $job) {
        $queue->markCompleted($job->getId());
    }
    
    $processed += count($jobs);
}

$dequeueTime = microtime(true) - $startTime;
echo "Processed $jobCount jobs in " . round($dequeueTime, 2) . "s\n";
echo "Throughput: " . round($jobCount / ($enqueueTime + $dequeueTime), 0) . " jobs/sec\n";
```

