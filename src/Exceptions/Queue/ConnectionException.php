<?php
/**
 * Queue and Async Processing Exceptions
 * 
 * Exceptions for TASK-001: Redis-backed queue system
 * These exceptions handle errors specific to bid queuing and async job processing
 * 
 * @package WC\Auction\Exceptions\Queue
 * @requirement REQ-AB-001: Support async auto-bid processing
 */

namespace WC\Auction\Exceptions\Queue;

use WC\Auction\Exceptions\AuctionException;

/**
 * Connection exception for queue/Redis failures
 * 
 * Thrown when the queue system cannot connect to Redis or other backend services
 * 
 * Example:
 *   throw new ConnectionException(
 *       'Failed to connect to Redis: ' . $redisError,
 *       context: ['host' => 'localhost', 'port' => 6379]
 *   );
 */
class ConnectionException extends AuctionException
{
    protected $code = 1001;
}

/**
 * Job validation exception
 * 
 * Thrown when job data fails validation before enqueueing
 * 
 * Example:
 *   throw new ValidationException(
 *       'Job data invalid: missing auction_id',
 *       context: ['provided_data' => $jobData]
 *   );
 */
class ValidationException extends AuctionException
{
    protected $code = 1101;
}

/**
 * Queue overflow exception
 * 
 * Thrown when queue reaches maximum capacity and cannot accept new jobs
 * 
 * Example:
 *   throw new OverflowException(
 *       'Queue overflow: size 10000 exceeds maximum 10000',
 *       context: ['max_size' => 10000, 'current_size' => 10000]
 *   );
 */
class OverflowException extends AuctionException
{
    protected $code = 1102;
}

/**
 * Maximum retries exceeded exception
 * 
 * Thrown when a job has been retried beyond the maximum allowed attempts
 * Job is moved to dead-letter queue
 * 
 * Example:
 *   throw new MaxRetriesExceededException(
 *       'Job exceeded max retries (3)',
 *       context: ['job_id' => 'job-123', 'retry_count' => 3]
 *   );
 */
class MaxRetriesExceededException extends AuctionException
{
    protected $code = 1103;
}

/**
 * Job not found exception
 * 
 * Thrown when attempting to access or modify a job that doesn't exist
 * 
 * Example:
 *   throw new JobNotFoundException(
 *       'Job not found: job-xyz',
 *       context: ['job_id' => 'job-xyz']
 *   );
 */
class JobNotFoundException extends AuctionException
{
    protected $code = 1104;
}

/**
 * Task timeout exception
 * 
 * Thrown when a worker task exceeds its execution timeout
 * 
 * Example:
 *   throw new TaskTimeoutException(
 *       'Task execution exceeded 5 second timeout',
 *       context: ['job_id' => 'job-123', 'timeout' => 5000, 'elapsed' => 6500]
 *   );
 */
class TaskTimeoutException extends AuctionException
{
    protected $code = 1105;
}

/**
 * Worker error exception
 * 
 * Thrown when the async worker encounters an error during job processing
 * 
 * Example:
 *   throw new WorkerException(
 *       'Worker crashed during job processing',
 *       context: ['job_id' => 'job-123', 'reason' => 'OutOfMemory']
 *   );
 */
class WorkerException extends AuctionException
{
    protected $code = 1106;
}
