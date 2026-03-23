<?php
/**
 * Database Queue Service
 * 
 * WordPress database-backed queue for managing automatic bidding operations
 * Provides priority-based job queuing with retry mechanism and dead-letter handling
 * Optional Redis backend for caching and performance optimization
 * 
 * @package WC\Auction\Services
 */

namespace WC\Auction\Services;

use WC\Auction\Exceptions\Queue\ConnectionException;
use WC\Auction\Exceptions\Queue\ValidationException;
use WC\Auction\Exceptions\Queue\OverflowException;
use WC\Auction\Exceptions\Queue\MaxRetriesExceededException;
use WC\Auction\Exceptions\Queue\JobNotFoundException;
use WC\Auction\Services\Queue\Job;
use WC\Auction\Services\Queue\JobStatus;

/**
 * Database-backed bid queue implementation
 * 
 * Uses WordPress database for persistent storage
 * Optional Redis integration for performance
 * 
 * Features:
 * - Priority-based job ordering (HIGH, NORMAL, LOW)
 * - Exponential backoff retry mechanism
 * - Dead-letter handling for permanently failed jobs
 * - Job TTL (time-to-live) support
 * - MySQL/PostgreSQL compatible
 * - Works on any WordPress install
 */
class BidQueue
{
    /**
     * WordPress database object
     * 
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Queue table name
     * 
     * @var string
     */
    private $tableName;

    /**
     * Maximum queue size
     * 
     * @var int
     */
    private $maxQueueSize;

    /**
     * Maximum retry attempts
     * 
     * @var int
     */
    private $maxRetries;

    /**
     * Retry delay strategy (base delay in seconds)
     * 
     * @var array
     */
    private $retryDelays;

    /**
     * Optional Redis client for caching
     * 
     * @var \Redis|null
     */
    private $redis;

    /**
     * Initialize BidQueue service
     * 
     * @param array $options Configuration options
     * @throws ConnectionException
     */
    public function __construct(array $options = [])
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableName = $wpdb->prefix . 'bid_queue';
        $this->maxQueueSize = $options['max_queue_size'] ?? 10000;
        $this->maxRetries = $options['max_retries'] ?? 3;
        $this->retryDelays = $options['retry_delays'] ?? [1, 2, 4, 8, 16];
        
        // Optional Redis connection
        if (!empty($options['redis'])) {
            $this->redis = $options['redis'];
            if (!$this->redis->ping()) {
                // Redis unavailable but optional - continue without it
                $this->redis = null;
            }
        }
    }

    /**
     * Add job to queue
     * 
     * Enqueues a new job with given priority and TTL
     * 
     * @param array $jobData Job data (auction_id, proxy_id, bid_amount, etc.)
     * @param int $ttl Time to live in seconds (0 = no expiry)
     * @param string $priority Job priority (HIGH, NORMAL, LOW)
     * @return string Job ID
     * @throws ValidationException
     * @throws OverflowException
     * @throws ConnectionException
     */
    public function enqueue(array $jobData, int $ttl = 0, string $priority = 'NORMAL'): string
    {
        try {
            // Validate job data
            if (empty($jobData['auction_id'])) {
                throw new ValidationException('Job data must contain auction_id');
            }

            // Check queue size
            if ($this->getSize() >= $this->maxQueueSize) {
                throw new OverflowException('Queue is full');
            }

            // Generate job ID
            $jobId = uniqid('job_', true);

            // Create job
            $job = new Job($jobId, $jobData, $priority);

            // Calculate expiry
            $expiresAt = null;
            if ($ttl > 0) {
                $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
            }

            // Insert into database
            $result = $this->wpdb->insert(
                $this->tableName,
                [
                    'job_id' => $jobId,
                    'status' => JobStatus::PENDING,
                    'priority' => $priority,
                    'data' => json_encode($job->getData()),
                    'retry_count' => 0,
                    'max_retries' => $this->maxRetries,
                    'expires_at' => $expiresAt,
                ],
                [
                    '%s', // job_id
                    '%s', // status
                    '%s', // priority
                    '%s', // data
                    '%d', // retry_count
                    '%d', // max_retries
                    '%s', // expires_at
                ]
            );

            if (!$result) {
                throw new ConnectionException('Failed to insert job: ' . $this->wpdb->last_error);
            }

            return $jobId;
        } catch (ValidationException $e) {
            throw $e;
        } catch (OverflowException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ConnectionException('Queue operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get next job from queue
     * 
     * Returns highest priority job and marks it as processing
     * 
     * @return Job|null Next job or null if queue empty
     * @throws ConnectionException
     */
    public function dequeue(): ?Job
    {
        try {
            // Get highest priority job (ORDER BY priority, created_at)
            $query = $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName} 
                 WHERE status = %s AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY FIELD(priority, 'HIGH', 'NORMAL', 'LOW'), created_at ASC
                 LIMIT 1",
                JobStatus::PENDING
            );

            $row = $this->wpdb->get_row($query);
            if (!$row) {
                return null;
            }

            // Update status to PROCESSING
            $this->wpdb->update(
                $this->tableName,
                ['status' => JobStatus::PROCESSING],
                ['job_id' => $row->job_id],
                ['%s'],
                ['%s']
            );

            // Build Job object
            $job = new Job($row->job_id, json_decode($row->data, true), $row->priority);
            $job->setStatus(JobStatus::PROCESSING);

            return $job;
        } catch (\Exception $e) {
            throw new ConnectionException('Dequeue operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get current queue size
     * 
     * @return int Number of jobs in queue
     * @throws ConnectionException
     */
    public function getSize(): int
    {
        try {
            $query = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tableName} WHERE status = %s",
                JobStatus::PENDING
            );
            return (int) $this->wpdb->get_var($query);
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot get queue size: ' . $e->getMessage());
        }
    }

    /**
     * Clear all jobs from queue
     * 
     * @return int Number of jobs removed
     * @throws ConnectionException
     */
    public function clear(): int
    {
        try {
            $result = $this->wpdb->delete($this->tableName, [], ['%s']);
            return $result;
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot clear queue: ' . $e->getMessage());
        }
    }

    /**
     * Flush queue and all metadata
     * 
     * @return void
     * @throws ConnectionException
     */
    public function flush(): void
    {
        try {
            $this->wpdb->query("TRUNCATE TABLE {$this->tableName}");
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot flush queue: ' . $e->getMessage());
        }
    }

    /**
     * Retry a failed job
     * 
     * @param string $jobId Job ID to retry
     * @param int $maxRetries Maximum retry attempts
     * @return void
     * @throws JobNotFoundException
     * @throws MaxRetriesExceededException
     * @throws ConnectionException
     */
    public function retry(string $jobId, int $maxRetries = 0): void
    {
        try {
            $maxRetries = $maxRetries > 0 ? $maxRetries : $this->maxRetries;

            // Get job
            $job_row = $this->wpdb->get_row(
                $this->wpdb->prepare("SELECT * FROM {$this->tableName} WHERE job_id = %s", $jobId)
            );

            if (!$job_row) {
                throw new JobNotFoundException("Job not found: {$jobId}");
            }

            // Check retry limit
            if ($job_row->retry_count >= $maxRetries) {
                throw new MaxRetriesExceededException(
                    "Job {$jobId} exceeded maximum retries ({$maxRetries})"
                );
            }

            // Update job for retry
            $this->wpdb->update(
                $this->tableName,
                [
                    'status' => JobStatus::PENDING,
                    'retry_count' => (int)$job_row->retry_count + 1,
                    'updated_at' => current_time('mysql'),
                ],
                ['job_id' => $jobId],
                ['%s', '%d', '%s'],
                ['%s']
            );
        } catch (MaxRetriesExceededException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ConnectionException('Retry operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Set custom retry policy
     * 
     * @param array $delays Array of retry delays in seconds [1, 2, 4, 8, 16]
     * @param int $maxRetries Maximum retry attempts
     * @return void
     */
    public function setRetryPolicy(array $delays, int $maxRetries): void
    {
        $this->retryDelays = $delays;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Get retry policy
     * 
     * @return array [delays, maxRetries]
     */
    public function getRetryPolicy(): array
    {
        return [
            'delays' => $this->retryDelays,
            'max_retries' => $this->maxRetries,
        ];
    }

    /**
     * Mark job as completed
     * 
     * @param string $jobId Job ID
     * @return void
     * @throws JobNotFoundException
     * @throws ConnectionException
     */
    public function markCompleted(string $jobId): void
    {
        try {
            $result = $this->wpdb->update(
                $this->tableName,
                ['status' => JobStatus::COMPLETED],
                ['job_id' => $jobId],
                ['%s'],
                ['%s']
            );

            if ($result === false) {
                throw new JobNotFoundException("Job not found: {$jobId}");
            }
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot mark job completed: ' . $e->getMessage());
        }
    }

    /**
     * Mark job as failed
     * 
     * @param string $jobId Job ID
     * @param string $reason Failure reason
     * @return void
     * @throws JobNotFoundException
     * @throws ConnectionException
     */
    public function markFailed(string $jobId, string $reason = ''): void
    {
        try {
            $job_row = $this->wpdb->get_row(
                $this->wpdb->prepare("SELECT * FROM {$this->tableName} WHERE job_id = %s", $jobId)
            );

            if (!$job_row) {
                throw new JobNotFoundException("Job not found: {$jobId}");
            }

            // If exceeded max retries, move to dead-letter
            if ($job_row->retry_count >= $job_row->max_retries) {
                $this->wpdb->update(
                    $this->tableName,
                    [
                        'status' => JobStatus::DEAD_LETTER,
                        'error_message' => $reason,
                    ],
                    ['job_id' => $jobId],
                    ['%s', '%s'],
                    ['%s']
                );
            } else {
                $this->wpdb->update(
                    $this->tableName,
                    [
                        'status' => JobStatus::FAILED,
                        'error_message' => $reason,
                    ],
                    ['job_id' => $jobId],
                    ['%s', '%s'],
                    ['%s']
                );
            }
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot mark job failed: ' . $e->getMessage());
        }
    }

    /**
     * Move job to dead-letter queue
     * 
     * @param string $jobId Job ID
     * @return void
     * @throws JobNotFoundException
     * @throws ConnectionException
     */
    public function moveToDeadLetter(string $jobId): void
    {
        try {
            $result = $this->wpdb->update(
                $this->tableName,
                ['status' => JobStatus::DEAD_LETTER],
                ['job_id' => $jobId],
                ['%s'],
                ['%s']
            );

            if ($result === false) {
                throw new JobNotFoundException("Job not found: {$jobId}");
            }
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot move job to dead-letter: ' . $e->getMessage());
        }
    }

    /**
     * Get dead-letter queue jobs
     * 
     * @param int $limit Number of jobs to retrieve (0 = all)
     * @return array Array of dead-letter jobs
     * @throws ConnectionException
     */
    public function getDeadLetterJobs(int $limit = 0): array
    {
        try {
            $sql = "SELECT * FROM {$this->tableName} WHERE status = %s";
            $params = [JobStatus::DEAD_LETTER];

            if ($limit > 0) {
                $sql .= " LIMIT %d";
                $params[] = $limit;
            }

            $query = $this->wpdb->prepare($sql, $params);
            $rows = $this->wpdb->get_results($query);

            return array_map(function ($row) {
                return new Job($row->job_id, json_decode($row->data, true), $row->priority);
            }, $rows ?: []);
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot get dead-letter jobs: ' . $e->getMessage());
        }
    }

    /**
     * Set job priority
     * 
     * @param string $jobId Job ID
     * @param string $priority New priority
     * @return void
     * @throws JobNotFoundException
     * @throws ConnectionException
     */
    public function setPriority(string $jobId, string $priority): void
    {
        try {
            $result = $this->wpdb->update(
                $this->tableName,
                ['priority' => $priority],
                ['job_id' => $jobId],
                ['%s'],
                ['%s']
            );

            if ($result === false) {
                throw new JobNotFoundException("Job not found: {$jobId}");
            }
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot set job priority: ' . $e->getMessage());
        }
    }

    /**
     * Get job by ID
     * 
     * @param string $jobId Job ID
     * @return Job|null Job or null if not found
     * @throws ConnectionException
     */
    public function getJob(string $jobId): ?Job
    {
        try {
            $row = $this->wpdb->get_row(
                $this->wpdb->prepare("SELECT * FROM {$this->tableName} WHERE job_id = %s", $jobId)
            );

            if (!$row) {
                return null;
            }

            return new Job($row->job_id, json_decode($row->data, true), $row->priority);
        } catch (\Exception $e) {
            throw new ConnectionException('Cannot get job: ' . $e->getMessage());
        }
    }
}
