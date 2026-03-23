<?php
/**
 * Job Model Class
 * 
 * Represents a single job in the bidding queue
 * 
 * @package WC\Auction\Services\Queue
 */

namespace WC\Auction\Services\Queue;

/**
 * Job entity for queue processing
 * 
 * Immutable data class representing a job
 * Status flows: PENDING -> PROCESSING -> COMPLETED or FAILED
 */
class Job
{
    /**
     * Unique job ID
     * 
     * @var string
     */
    private $id;

    /**
     * Job status
     * 
     * @var string
     */
    private $status;

    /**
     * Job data (auction_id, proxy_id, bid_amount, etc.)
     * 
     * @var array
     */
    private $data;

    /**
     * Job retry count
     * 
     * @var int
     */
    private $retryCount;

    /**
     * Job priority level (LOW, NORMAL, HIGH)
     * 
     * @var string
     */
    private $priority;

    /**
     * Timestamp when job was created
     * 
     * @var int
     */
    private $createdAt;

    /**
     * Initialize job
     * 
     * @param string $id Unique job ID
     * @param array $data Job data
     * @param string $priority Priority level
     */
    public function __construct(string $id, array $data, string $priority = 'NORMAL')
    {
        $this->id = $id;
        $this->data = $data;
        $this->priority = $priority;
        $this->status = JobStatus::PENDING;
        $this->retryCount = 0;
        $this->createdAt = time();
    }

    /**
     * Get job ID
     * 
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get job status
     * 
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Set job status
     * 
     * @param string $status
     * @return self
     */
    public function setStatus(string $status): self
    {
        if (!JobStatus::isValid($status)) {
            throw new \InvalidArgumentException("Invalid job status: {$status}");
        }
        $this->status = $status;
        return $this;
    }

    /**
     * Get job data
     * 
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get specific data field
     * 
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Get auction ID from job data
     * 
     * @return int|null
     */
    public function getAuctionId(): ?int
    {
        $id = $this->get('auction_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Get proxy ID from job data
     * 
     * @return int|null
     */
    public function getProxyId(): ?int
    {
        $id = $this->get('proxy_id');
        return $id !== null ? (int) $id : null;
    }

    /**
     * Get bid amount from job data
     * 
     * @return float|null
     */
    public function getBidAmount(): ?float
    {
        $amount = $this->get('bid_amount');
        return $amount !== null ? (float) $amount : null;
    }

    /**
     * Get retry count
     * 
     * @return int
     */
    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    /**
     * Increment retry count
     * 
     * @return self
     */
    public function incrementRetryCount(): self
    {
        $this->retryCount++;
        return $this;
    }

    /**
     * Get priority level
     * 
     * @return string
     */
    public function getPriority(): string
    {
        return $this->priority;
    }

    /**
     * Set priority level
     * 
     * @param string $priority
     * @return self
     */
    public function setPriority(string $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * Get creation timestamp
     * 
     * @return int
     */
    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    /**
     * Get job age in seconds
     * 
     * @return int
     */
    public function getAge(): int
    {
        return time() - $this->createdAt;
    }

    /**
     * Convert job to array for serialization
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'data' => $this->data,
            'retry_count' => $this->retryCount,
            'priority' => $this->priority,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Create job from array
     * 
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $job = new self(
            $data['id'],
            $data['data'],
            $data['priority'] ?? 'NORMAL'
        );
        $job->status = $data['status'] ?? JobStatus::PENDING;
        $job->retryCount = $data['retry_count'] ?? 0;
        $job->createdAt = $data['created_at'] ?? time();
        return $job;
    }
}
