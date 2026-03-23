# Bid Queue System Architecture

## Design Principles

This implementation follows several key architectural principles:

### 1. SOLID Principles

**Single Responsibility Principle (SRP)**
- `BidQueue`: Manages job queueing operations
- `Migration`: Handles database schema
- `DatabaseSetup`: Orchestrates initialization
- `QueueServiceFactory`: Manages instantiation
- `Job`: Represents job data
- `JobStatus`: Defines job states

**Open/Closed Principle**
- New job types added via enqueue() without modifying queue logic
- New statuses added via JobStatus enum
- Future backends (Redis, RabbitMQ) via alternative implementations

**Liskov Substitution Principle**
- All exceptions extend `QueueException`
- Jobs are immutable value objects with consistent interface

**Interface Segregation Principle**
- Job provides only necessary methods
- Queue interface focuses on queue operations
- Migration handles only schema concerns

**Dependency Inversion Principle**
- Dependencies injected via constructor
- WPDB dependency abstracted
- Factory handles creation

### 2. Design Patterns Used

**Factory Pattern**
```
QueueServiceFactory → DatabaseSetup → BidQueue
Creates properly configured instances with dependencies
```

**Service Locator Pattern**
```
QueueServiceFactory::setup() → stores singleton instances
QueueServiceFactory::createBidQueue() → returns configured service
```

**Strategy Pattern**
```
Different prioritization strategies based on priority level
FIELD() in SQL optimizes without polymorphism
```

**Value Object Pattern**
```
Job class encapsulates immutable job data
JobStatus enumerates valid states
```

**Repository Pattern**
```
BidQueue acts as repository for accessing jobs
Encapsulates database access logic
```

### 3. No Conditionals Philosophy

Following Martin Fowler's "Replace Conditional with Polymorphism":

**Bad** (many if/else):
```php
switch($status) {
    case 'PENDING': return "Waiting";
    case 'COMPLETED': return "Done";
    case 'FAILED': return "Error";
}
```

**Good** (using JobStatus enum and direct mapping):
```php
$statusLabels = [
    JobStatus::PENDING => "Waiting",
    JobStatus::COMPLETED => "Done",
    JobStatus::FAILED => "Error",
];
```

**Better** (polymorphic classes):
```php
// Each status has its own handler class
$handler = StatusHandlerFactory::create($status);
$display = $handler->getDisplayLabel();
```

## Component Details

### QueueServiceFactory

**Purpose**: Singleton factory for creating queue instances

**Responsibilities**:
1. Store global WPDB and setup instances
2. Initialize database on first setup
3. Create BidQueue with dependencies
4. Provide access to DatabaseSetup

**Design Decisions**:
- Static methods for single-point access
- Lazy initialization of setup
- Reset method for testing

**Message Flow**:
```
Plugin Load
    ↓
QueueServiceFactory::setup($wpdb)
    ↓
Creates DatabaseSetup instance
    ↓
Calls DatabaseSetup::initialize()
    ↓
Creates table if needed
    ↓
Stores references for later use
```

### DatabaseSetup

**Purpose**: Orchestrate database initialization and migrations

**Responsibilities**:
1. Create queue table if needed
2. Run schema migrations
3. Track schema version
4. Provide diagnostics

**Design Decisions**:
- Separate from BidQueue to follow SRP
- Migration class isolated for testability
- Version tracking via WordPress options
- Idempotent operations (safe to call multiple times)

**Table Initialization Flow**:
```
initialize()
    ↓
hasTable() → table exists?
    ├─ Yes → return true
    └─ No → create table
            ↓
            setCurrentVersion()
            ↓
            return true
```

### Migration

**Purpose**: Manage database schema evolution

**Responsibilities**:
1. Define current schema
2. Execute CREATE TABLE statements
3. Track schema versions
4. Support future migrations

**Design Decisions**:
- Uses WordPress charset_collate
- information_schema for compatibility
- Version stored in WordPress options
- Separate migration methods for each version

**Future Migration Pattern**:
```php
// Each version gets its own method
private function migrateTo_1_1_0()
{
    // ALTER TABLE statements
}

// In migrate() method:
if (version_compare($current, '1.1.0', '<')) {
    $this->migrateTo_1_1_0();
}
```

### BidQueue

**Purpose**: Core queue service for job management

**Responsibilities**:
1. Enqueue jobs with priority
2. Dequeue jobs for processing
3. Update job status
4. Track job metadata
5. Retrieve queue statistics

**Design Decisions**:
- All database operations use prepared statements (security)
- JSON for flexible job data storage
- Composite indexes for performance
- Dead-letter queue for failed jobs

**Priority Ordering**:
```
FIELD in MySQL sorts without loading all rows:

SELECT * 
FROM queue
ORDER BY FIELD(priority, 'HIGH', 'NORMAL', 'LOW'), 
         created_at ASC

Results in:
1. All HIGH priority jobs (by creation order)
2. All NORMAL priority jobs (by creation order)
3. All LOW priority jobs (by creation order)
```

### Job

**Purpose**: Immutable value object representing queued job

**Responsibilities**:
1. Store job metadata
2. Serialize/deserialize to database
3. Provide type-safe access

**Design Decisions**:
- Immutable after creation
- No setters (only readonly properties)
- Constructor handles all initialization
- Easy to extend with new properties

**Usage**:
```php
$job = new Job(
    $id,
    $data,
    $priority,
    $status,
    $retryCount,
    $maxRetries,
    $errorMessage
);

// Only getters, no setters
$jobId = $job->getId();
$data = $job->getData();
```

### JobStatus

**Purpose**: Enumerate valid job statuses

**Values**:
- `PENDING` - Waiting to be processed
- `PROCESSING` - Currently being processed
- `COMPLETED` - Successfully completed
- `FAILED` - Failed but retries remaining
- `DEAD_LETTER` - Permanently failed

**Status Transitions**:
```
PENDING → PROCESSING ─→ COMPLETED
           ↓
           FAILED ──→ PENDING (retry) ─→ COMPLETED
                └────────────────┘
                      (up to maxRetries times)
           
           Or if max retries exceeded:
           ↓
           DEAD_LETTER (manual review)
```

## Database Design

### Table Structure

```sql
id                - Primary key, auto-increment
job_id            - Unique identifier (UUID)
data              - Serialized job payload (JSON)
status            - Current status (4 statuses possible)
priority          - Priority level (3 values)
retry_count       - Current retry attempt
max_retries       - Maximum allowed retries
error_message     - Last error description
created_at        - Timestamp
updated_at        - Timestamp
executed_at       - When processing started
expires_at        - When job should expire
```

### Indexing Strategy

```
idx_status              - Fast status filtering
idx_priority            - Priority sorting
idx_created_at          - Time-based ordering
idx_expires_at          - Expiration checking
idx_status_priority     - Combined filtering
```

**Query Optimization Examples**:

```sql
-- Uses idx_status_priority
SELECT * FROM queue 
WHERE status = 'PENDING' 
ORDER BY FIELD(priority, 'HIGH', 'NORMAL', 'LOW'), 
         created_at ASC
LIMIT 10

-- Uses idx_status
SELECT COUNT(*) FROM queue WHERE status = 'FAILED'

-- Uses idx_expires_at
SELECT * FROM queue WHERE expires_at < NOW()
```

## Data Flow

### Job Enqueuing

```
Client Code
    ↓
queue->enqueue($type, $data, $priority)
    ↓
Validate input
    ↓
Generate job_id (UUID)
    ↓
JSON encode data
    ↓
Calculate timestamps
    ↓
INSERT into database
    ↓
Return job_id
    ↓
Client has job_id for tracking
```

### Job Dequeuing

```
Client Code
    ↓
queue->dequeue($limit)
    ↓
SELECT PENDING jobs
ORDER BY priority
LIMIT $limit
    ↓
UPDATE status to PROCESSING
    ↓
Return Job objects
    ↓
Client processes jobs
```

### Job Completion

```
Process Success
    ↓
queue->markCompleted($jobId)
    ↓
UPDATE status = COMPLETED
    ↓
SUCCESS

Process Failure
    ↓
queue->markFailed($jobId, $reason)
    ↓
Increment retry_count
    ↓
Check retry_count vs max_retries
    ├─ < max_retries: UPDATE status = FAILED
    │                 (will be retried)
    └─ >= max_retries: UPDATE status = DEAD_LETTER
                       (manual intervention needed)
```

## Security Considerations

### SQL Injection Prevention

All database queries use prepared statements:
```php
$this->wpdb->prepare("SELECT * FROM {$table} WHERE job_id = %s", $jobId)
```

Benefits:
- Parameter escaping handled by WPDB
- Type safety (%s for strings, %d for integers)
- Consistent across all queries

### Input Validation

```php
// Enqueue validates data
if (empty($jobType) || !is_string($jobType)) {
    throw new ValidationException('Invalid job type');
}

// Job data validated as array
if (!is_array($jobData)) {
    throw new ValidationException('Job data must be array');
}

// Priority validated against enum
if (!in_array($priority, ['HIGH', 'NORMAL', 'LOW'])) {
    throw new ValidationException('Invalid priority');
}
```

### Error Message Handling

Error messages stored in database without exposing internal details:
```php
// Store user-friendly message
$queue->markFailed($jobId, 'Auction validation failed');

// NOT: 'Auction ID 123 not found in table wp_users'
```

## Performance Characteristics

### Time Complexity

- `enqueue()` - **O(1)** - Single INSERT
- `dequeue()` - **O(n)** - SELECT + UPDATE (n = batch size)
- `markCompleted()` - **O(1)** - Single UPDATE
- `markFailed()` - **O(1)** - Single UPDATE
- `getJob()` - **O(log n)** - Uses indexed lookup
- `getStats()` - **O(1)** - COUNT queries with indexes

### Space Complexity

- Each job: ~500 bytes (ID, data, status, metadata)
- Index overhead: ~30% of table size
- For 10,000 jobs: ~5 MB table + 1.5 MB indexes

### Database Tuning

**For high-volume queues (>100k jobs/day)**:
1. Increase MySQL buffer pool to 50-75% of RAM
2. Enable query caching for COUNT queries
3. Consider partitioning by date
4. Archive old completed jobs monthly

**Example partitioning**:
```sql
PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p_2024 VALUES LESS THAN (2025),
    PARTITION p_2025 VALUES LESS THAN (2026),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

## Testing Strategy

### Unit Tests

**Queue Operations**:
```php
- testEnqueueGeneratesUniqueJobId()
- testEnqueueValidatesInput()
- testEnqueueHighPriorityJobsFirst()
- testDequeueReturnsJobsByPriority()
- testMarkCompletedUpdatesStatus()
```

**Status Transitions**:
```php
- testMarkFailedMovesToDeadLetterAfterMaxRetries()
- testMarkFailedIncrementsRetryCount()
- testMoveToDeadLetterExplicitly()
```

**Job Management**:
```php
- testGetJobReturnsJobObject()
- testGetJobReturnsNullForNonexistent()
- testSetPriorityUpdatesQueue()
```

### Integration Tests

**Database Operations**:
```php
- testDatabaseInitialization()
- testMigrationRunsSuccessfully()
- testTablesCreatedWithCorrectSchema()
```

**End-to-End Workflow**:
```php
- testCompleteJobLifecycle()
- testRetryMechanism()
- testDeadLetterQueueHandling()
```

### Mock Strategy

```php
// Mock WPDB for unit tests
$mockWpdb = $this->createMock(\wpdb::class);
$mockWpdb->method('prepare')->willReturn($sql);
$mockWpdb->method('query')->willReturn(true);

// Use real WPDB for integration tests
global $wpdb;
$queue = new BidQueue($wpdb, $tableName);
```

## Future Enhancements

### 1. Redis Caching Layer

```php
// Optional Redis for performance
class BidQueueWithCache extends BidQueue
{
    private $redis;
    
    public function dequeue(int $limit = 1): array
    {
        // Try cache first
        $cached = $this->redis->get('queue:pending:0:' . $limit);
        if ($cached) return unserialize($cached);
        
        // Fall back to database
        return parent::dequeue($limit);
    }
}
```

### 2. Message Queue Integration

Support for external queues:
```php
// Interface for different backends
interface QueueBackend {
    public function enqueue($jobId, $data): void;
    public function dequeue($limit): array;
}

class RabbitMQBackend implements QueueBackend { }
class SQSBackend implements QueueBackend { }
```

### 3. Job Scheduling

Scheduled jobs for future execution:
```php
// Schedule job for later
$queue->scheduleJob($type, $data, $priority, 
                    new \DateTime('+1 hour'));
```

### 4. Webhook Integration

Notify external systems on job completion:
```php
do_action('wc_auction_queue_job_completed', [
    'job_id' => $jobId,
    'webhook_url' => $webhookUrl,
    'result' => $result,
]);
```

### 5. Job Chaining

Execute dependent jobs in sequence:
```php
$chain = new JobChain();
$chain->add('validate-bid', $bidData)
      ->add('process-payment', $paymentData)
      ->add('notify-winner', $winnerData)
      ->enqueue($queue);
```

## Compliance & Requirements

### From AGENTS.md

✅ **SOLID Principles**
- Single Responsibility: Each class has one reason to change
- Open/Closed: Extensible for new job types without modification
- Liskov Substitution: Job objects and exceptions substitutable
- Interface Segregation: Jobs and queue have focused interfaces
- Dependency Inversion: Dependencies injected, not hardcoded

✅ **Design Patterns**
- Factory Pattern: QueueServiceFactory
- Repository Pattern: BidQueue
- Value Object Pattern: Job
- Strategy Pattern: Priority-based ordering
- Minimal conditionals: Using FIELD() in SQL

✅ **Testing Strategy**
- Unit tests with mocks
- Integration tests with real database
- 100% code coverage target

✅ **Documentation**
- PHPDoc on all classes and methods
- Architecture diagrams (UML)
- README with usage examples
- Requirement traceability (@requirement tags)

✅ **Security**
- Prepared statements for SQL injection prevention
- Input validation
- No sensitive data in error messages

✅ **Performance**
- Optimized indexes (O(log n) lookups)
- Composite indexes for common queries
- Tuning guidelines for high volumes

## Requirement Traceability

| Requirement | Implementation | File |
|---|---|---|
| REQ-QUEUE-ARCH-001 | Priority queue system | BidQueue.php |
| REQ-QUEUE-ARCH-002 | Retry mechanism | BidQueue.php |
| REQ-QUEUE-ARCH-003 | Dead-letter handling | BidQueue.php |
| REQ-QUEUE-DB-001 | Database schema | Migration.php |
| REQ-QUEUE-FACTORY-001 | Service creation | QueueServiceFactory.php |
| REQ-QUEUE-EXCEPTIONS-001 | Error handling | Exceptions/ |
| REQ-QUEUE-JOB-001 | Job representation | Job.php |
| REQ-QUEUE-STATUS-001 | Status management | JobStatus.php |

