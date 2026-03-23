# WC Auction Bid Queue System

## Overview

The Bid Queue System is a sophisticated job queue implementation for managing automatic bidding operations in the YITH Auctions for WooCommerce plugin. It provides:

- **Priority-based job queuing** (HIGH, NORMAL, LOW)
- **Automatic retry mechanism** with exponential backoff
- **Dead-letter queue** for permanently failed jobs
- **Time-to-live (TTL) support** for job expiration
- **WordPress database backend** (MySQL/PostgreSQL compatible)
- **Persistent storage** without external dependencies

## Architecture

### Component Overview

```
┌─────────────────────────────────────────────────────────────┐
│                   Application Layer                         │
│                  (Auction Management)                       │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ↓
┌─────────────────────────────────────────────────────────────┐
│              QueueServiceFactory                            │
│     (Service Locator & Dependency Injection)                │
└────────────────────┬────────────────────────────────────────┘
                     │
          ┌──────────┴──────────┐
          ↓                     ↓
┌──────────────────┐    ┌──────────────────────┐
│ DatabaseSetup    │    │ BidQueue Service     │
│ - initialize()   │    │ - enqueue()          │
│ - migrate()      │    │ - dequeue()          │
│ - isReady()      │    │ - markCompleted()    │
└──────────────────┘    │ - markFailed()       │
         │              │ - getJob()           │
         ↓              └──────────┬───────────┘
┌──────────────────┐              │
│ Migration        │              │
│ - createTable()  │              │
│ - migrate()      │              │
│ - getSchema()    │              │
└──────────────────┘              │
         │                        │
         └───────────┬────────────┘
                     ↓
           ┌──────────────────────┐
           │  WordPress Database  │
           │  (with Queue Table)  │
           └──────────────────────┘
```

### Database Schema

```sql
CREATE TABLE wc_auction_bid_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(36) UNIQUE,
    data LONGTEXT,
    status VARCHAR(20),
    priority VARCHAR(10),
    retry_count INT UNSIGNED,
    max_retries INT UNSIGNED,
    error_message TEXT,
    created_at DATETIME,
    updated_at DATETIME,
    executed_at DATETIME,
    expires_at DATETIME,
    KEY idx_status (status),
    KEY idx_priority (priority),
    KEY idx_created_at (created_at),
    KEY idx_status_priority (status, priority)
);
```

### Job Status Flow

```
PENDING → PROCESSING → COMPLETED
  ├─→ FAILED → PENDING (retry) → ...
  └─→ DEAD_LETTER (max retries exceeded)
```

## Usage

### Basic Setup

```php
global $wpdb;

// Initialize factory during plugin activation
QueueServiceFactory::setup($wpdb);

// Check if database is ready
$setup = QueueServiceFactory::getSetup();
if ($setup->isReady()) {
    // Queue is ready for use
}
```

### Enqueuing Jobs

```php
// Get queue instance
$queue = QueueServiceFactory::createBidQueue();

// Enqueue a bid job
$jobId = $queue->enqueue(
    'auction-bid',
    [
        'auction_id' => 123,
        'product_id' => 456,
        'bid_amount' => 99.99,
        'bidder_id' => 789,
    ],
    'HIGH' // Priority: HIGH, NORMAL, LOW
);
```

### Processing Jobs

```php
// Get next job to process
$job = $queue->dequeue();

if ($job) {
    try {
        // Process the job
        $bidService = new BidService();
        $result = $bidService->processBid($job->getData());
        
        // Mark as completed
        $queue->markCompleted($job->getId());
    } catch (\Exception $e) {
        // Mark as failed (will retry or dead-letter)
        $queue->markFailed($job->getId(), $e->getMessage());
    }
}
```

### Handling Dead-Letter Queue

```php
// Get failed jobs
$deadLetterJobs = $queue->getDeadLetterJobs();

foreach ($deadLetterJobs as $job) {
    echo "Failed job: " . $job->getId();
    echo "Error: " . $job->getErrorMessage();
    // Manual intervention needed
}

// Manually retry a dead-letter job
$queue->setPriority('job-id-123', 'HIGH');
$queue->markFailed('job-id-123', ''); // Reset status to FAILED
```

### Advanced Usage

```php
// Get specific job
$job = $queue->getJob('job-id-123');

// Change job priority
$queue->setPriority('job-id-123', 'HIGH');

// Move to dead-letter explicitly
$queue->moveToDeadLetter('job-id-123');

// Get pending jobs count
$stats = $queue->getStats();
echo "Pending: " . $stats['pending'];
echo "Processing: " . $stats['processing'];
```

## API Reference

### QueueServiceFactory

Static factory for creating and managing queue services.

#### Methods

- `setup(\wpdb $wpdb): DatabaseSetup` - Initialize factory
- `createBidQueue(): BidQueue` - Create queue instance
- `getSetup(): DatabaseSetup` - Get database setup
- `isInitialized(): bool` - Check factory status
- `reset(): void` - Reset factory (testing only)

### DatabaseSetup

Handles database initialization and schema management.

#### Methods

- `initialize(): bool` - Create tables if needed
- `migrate(): bool` - Apply schema migrations
- `getTableName(): string` - Get qualified table name
- `isReady(): bool` - Check database readiness
- `dropTable(): bool` - Remove queue table
- `getDiagnostics(): array` - Get debug information

### BidQueue

Main queue service for job management.

#### Methods

- `enqueue(string $type, array $data, string $priority): string` - Add job
- `dequeue(int $limit): array` - Get next jobs
- `markCompleted(string $jobId): void` - Mark job successful
- `markFailed(string $jobId, string $reason): void` - Mark job failed
- `moveToDeadLetter(string $jobId): void` - Move to dead-letter
- `getDeadLetterJobs(int $limit): array` - Get failed jobs
- `setPriority(string $jobId, string $priority): void` - Update priority
- `getJob(string $jobId): ?Job` - Get job by ID
- `getStats(): array` - Queue statistics

### Job Value Object

Represents a queued job.

#### Methods

- `getId(): string` - Get job ID
- `getType(): string` - Get job type
- `getData(): array` - Get job data
- `getStatus(): string` - Get job status
- `getPriority(): string` - Get job priority
- `getRetryCount(): int` - Get retry count
- `getErrorMessage(): string` - Get error message

### Exceptions

- `ConnectionException` - Database connection error
- `ValidationException` - Invalid input data
- `OverflowException` - Queue capacity exceeded
- `MaxRetriesExceededException` - Retries exhausted
- `JobNotFoundException` - Job not found

## Error Handling

### Retry Mechanism

Jobs are automatically retried up to `max_retries` (default: 3) times.

Retry behavior:
1. Job fails → status = FAILED
2. After 1st failure: automatically dequeued for retry
3. After 2nd failure: automatically dequeued for retry
4. After 3rd failure: moved to DEAD_LETTER queue

### Dead-Letter Queue

Jobs that exceed max retries are moved to dead-letter queue for:
- Manual review
- Debugging
- Administrative action
- Potential recovery

### Exception Types

```php
try {
    $queue->enqueue(...);
} catch (ValidationException $e) {
    // Invalid input data
} catch (OverflowException $e) {
    // Queue is full
} catch (ConnectionException $e) {
    // Database error
}
```

## Performance Optimization

### Indexing Strategy

The queue table includes optimized indexes:
- `idx_status` - Fast status-based queries
- `idx_priority` - Priority sorting
- `idx_created_at` - Time-based queries
- `idx_status_priority` - Combined filtering

### Query Optimization

Bad query (full table scan):
```php
$queue->getStats(); // Without indexes
```

Good query (uses composite index):
```php
$queue->dequeue(10); // Uses idx_status_priority
```

### Database Tuning

For high-volume queues:
1. Increase `sort_buffer_size` in MySQL
2. Adjust `innodb_buffer_pool_size`
3. Monitor table fragmentation
4. Consider table partitioning for very large tables

## Monitoring and Diagnostics

### Get Queue Statistics

```php
$stats = $queue->getStats();
// Returns: ['pending' => 5, 'processing' => 2, 'completed' => 100, 'failed' => 3]
```

### Database Diagnostics

```php
$setup = QueueServiceFactory::getSetup();
$diagnostics = $setup->getDiagnostics();

echo "Table exists: " . ($diagnostics['table_exists'] ? 'Yes' : 'No');
echo "Current version: " . $diagnostics['current_version'];
echo "Schema: " . json_encode($diagnostics['schema']);
```

### Logging

Queue operations are logged via:
```php
do_action('wc_auction_queue_job_enqueued', $jobId, $data);
do_action('wc_auction_queue_job_completed', $jobId);
do_action('wc_auction_queue_job_failed', $jobId, $error);
```

## Best Practices

### 1. Job Design

```php
// Good: Idempotent, atomic operation
$data = ['auction_id' => 123, 'bidder_id' => 456];

// Bad: Depends on previous state
$data = ['increment_bid_by' => 5];
```

### 2. Error Handling

```php
// Good: Specific error handling
try {
    $queue->markFailed($jobId, 'Auction ended');
} catch (JobNotFoundException $e) {
    // Job already processed
}

// Bad: Generic catch-all
try {
    $queue->markFailed($jobId, $e);
} catch (\Exception $e) {
    // Swallows real errors
}
```

### 3. Job Priority

```php
// Use HIGH for time-sensitive operations
$queue->enqueue('urgent-bid', $data, 'HIGH');

// Use NORMAL for regular operations
$queue->enqueue('regular-bid', $data, 'NORMAL');

// Use LOW for batch operations
$queue->enqueue('batch-cleanup', $data, 'LOW');
```

### 4. Job Expiration

```php
// Add expires_at to automatically expire old jobs
$jobs = $queue->getExpiredJobs();
foreach ($jobs as $job) {
    $queue->moveToDeadLetter($job->getId());
}
```

## Testing

### Unit Tests

```php
public function testEnqueueJob()
{
    $queue = QueueServiceFactory::createBidQueue();
    $jobId = $queue->enqueue('test', ['key' => 'value']);
    
    $this->assertNotEmpty($jobId);
    $job = $queue->getJob($jobId);
    $this->assertEquals('PENDING', $job->getStatus());
}
```

### Integration Tests

```php
public function testJobProcessing()
{
    $queue = QueueServiceFactory::createBidQueue();
    
    // Enqueue
    $jobId = $queue->enqueue('bid', $bidData);
    
    // Dequeue and process
    $job = $queue->dequeue()[0];
    $queue->markCompleted($job->getId());
    
    // Verify
    $completed = $queue->getJob($jobId);
    $this->assertEquals('COMPLETED', $completed->getStatus());
}
```

## Migration Guide

### From No Queue to Bid Queue

1. **Install**: Add `src/Services` and `src/Database` to your project
2. **Initialize**: Call `QueueServiceFactory::setup($wpdb)` during plugin activation
3. **Migrate**: Run `$setup->migrate()` to create tables
4. **Use**: Start enqueueing jobs via `$queue->enqueue()`

### Enabling in Plugin

```php
// In plugin activation hook
register_activation_hook(__FILE__, function() {
    global $wpdb;
    QueueServiceFactory::setup($wpdb);
    
    $setup = QueueServiceFactory::getSetup();
    if (!$setup->initialize()) {
        wp_die('Failed to initialize bid queue');
    }
});
```

## Requirements

- PHP 7.3+
- WordPress
- WooCommerce
- MySQL 5.7+ or PostgreSQL 9.5+
- No external Redis dependency (optional)

## License

As per YITH plugin license

## Support

For issues or questions, refer to the technical requirements document (AGENTS.md) for development practices.
