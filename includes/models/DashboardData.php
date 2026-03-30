<?php
/**
 * Data Models and DTOs for Dashboard Services
 *
 * @package YITH_Auctions\Models
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-4E-001 through REQ-4E-010
 */

namespace YITH_Auctions\Models;

/**
 * Immutable DTO for dashboard payout data
 *
 * @requirement REQ-4E-001 - Seller payout display
 */
class PayoutDashboardData {
	public readonly int $payout_id;
	public readonly int $seller_id;
	public readonly int $auction_id;
	public readonly int $gross_amount;
	public readonly int $commission_amount;
	public readonly int $net_amount;
	public readonly string $status;
	public readonly ?string $transaction_id;
	public readonly ?string $processor;
	public readonly \DateTime $created_at;
	public readonly ?\DateTime $completed_at;

	public function __construct(
		int $payout_id,
		int $seller_id,
		int $auction_id,
		int $gross_amount,
		int $commission_amount,
		int $net_amount,
		string $status,
		?string $transaction_id,
		?string $processor,
		\DateTime $created_at,
		?\DateTime $completed_at = null
	) {
		$this->payout_id = $payout_id;
		$this->seller_id = $seller_id;
		$this->auction_id = $auction_id;
		$this->gross_amount = $gross_amount;
		$this->commission_amount = $commission_amount;
		$this->net_amount = $net_amount;
		$this->status = $status;
		$this->transaction_id = $transaction_id;
		$this->processor = $processor;
		$this->created_at = $created_at;
		$this->completed_at = $completed_at;
	}

	public function getFormattedAmount(int $amount_cents): string {
		return '$' . number_format( $amount_cents / 100, 2 );
	}

	public function getStatusLabel(): string {
		$labels = [
			'pending' => __( 'Pending', 'yith-auctions' ),
			'processing' => __( 'Processing', 'yith-auctions' ),
			'completed' => __( 'Completed', 'yith-auctions' ),
			'failed' => __( 'Failed', 'yith-auctions' ),
			'skipped' => __( 'Skipped', 'yith-auctions' ),
			'permanently_failed' => __( 'Permanently Failed', 'yith-auctions' ),
		];

		return $labels[ $this->status ] ?? $this->status;
	}

	public function getStatusClass(): string {
		$classes = [
			'pending' => 'status-pending',
			'processing' => 'status-processing',
			'completed' => 'status-completed',
			'failed' => 'status-failed',
			'skipped' => 'status-skipped',
			'permanently_failed' => 'status-permanently-failed',
		];

		return $classes[ $this->status ] ?? 'status-unknown';
	}
}

/**
 * Immutable DTO for settlement batch status
 *
 * @requirement REQ-4E-002 - Batch monitoring
 */
class BatchStatusData {
	public readonly int $batch_id;
	public readonly int $seller_count;
	public readonly int $payout_count;
	public readonly int $completed_count;
	public readonly int $failed_count;
	public readonly int $pending_count;
	public readonly string $status;
	public readonly int $total_amount;
	public readonly \DateTime $created_at;
	public readonly ?\DateTime $completed_at;

	public function __construct(
		int $batch_id,
		int $seller_count,
		int $payout_count,
		int $completed_count,
		int $failed_count,
		int $pending_count,
		string $status,
		int $total_amount,
		\DateTime $created_at,
		?\DateTime $completed_at = null
	) {
		$this->batch_id = $batch_id;
		$this->seller_count = $seller_count;
		$this->payout_count = $payout_count;
		$this->completed_count = $completed_count;
		$this->failed_count = $failed_count;
		$this->pending_count = $pending_count;
		$this->status = $status;
		$this->total_amount = $total_amount;
		$this->created_at = $created_at;
		$this->completed_at = $completed_at;
	}

	public function getProgressPercentage(): int {
		if ( $this->payout_count === 0 ) {
			return 0;
		}

		return (int) round( ( $this->completed_count / $this->payout_count ) * 100 );
	}

	public function getEstimatedTimeRemaining(): ?string {
		if ( $this->payout_count === 0 || $this->completed_count === 0 ) {
			return null;
		}

		$elapsed = time() - $this->created_at->getTimestamp();
		$per_payout = $elapsed / $this->completed_count;
		$remaining_seconds = $per_payout * $this->pending_count;

		return $this->formatSeconds( (int) $remaining_seconds );
	}

	private function formatSeconds( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's';
		} elseif ( $seconds < 3600 ) {
			return ceil( $seconds / 60 ) . 'm';
		} else {
			return ceil( $seconds / 3600 ) . 'h';
		}
	}
}

/**
 * Immutable DTO for dashboard statistics
 *
 * @requirement REQ-4E-001 - Stats display
 */
class DashboardStats {
	public readonly int $total_payouts;
	public readonly int $total_amount;
	public readonly int $completed_amount;
	public readonly int $pending_amount;
	public readonly int $failed_count;
	public readonly float $success_rate;
	public readonly int $avg_amount;
	public readonly int $min_amount;
	public readonly int $max_amount;

	public function __construct(
		int $total_payouts,
		int $total_amount,
		int $completed_amount,
		int $pending_amount,
		int $failed_count,
		float $success_rate,
		int $avg_amount,
		int $min_amount,
		int $max_amount
	) {
		$this->total_payouts = $total_payouts;
		$this->total_amount = $total_amount;
		$this->completed_amount = $completed_amount;
		$this->pending_amount = $pending_amount;
		$this->failed_count = $failed_count;
		$this->success_rate = $success_rate;
		$this->avg_amount = $avg_amount;
		$this->min_amount = $min_amount;
		$this->max_amount = $max_amount;
	}

	public function getCompletionRate(): int {
		if ( $this->total_payouts === 0 ) {
			return 0;
		}

		$completed = $this->total_payouts - $this->failed_count;
		return (int) round( ( $completed / $this->total_payouts ) * 100 );
	}
}

/**
 * Immutable DTO for system health metrics
 *
 * @requirement REQ-4E-008 - Admin health monitoring
 */
class SystemHealthData {
	public readonly float $success_rate;
	public readonly float $error_rate;
	public readonly int $avg_processing_time_ms;
	public readonly int $total_payouts_24h;
	public readonly int $total_amount_24h;
	public readonly int $active_batches;
	public readonly int $pending_queue_size;
	public readonly \DateTime $last_batch_completed;

	public function __construct(
		float $success_rate,
		float $error_rate,
		int $avg_processing_time_ms,
		int $total_payouts_24h,
		int $total_amount_24h,
		int $active_batches,
		int $pending_queue_size,
		\DateTime $last_batch_completed
	) {
		$this->success_rate = $success_rate;
		$this->error_rate = $error_rate;
		$this->avg_processing_time_ms = $avg_processing_time_ms;
		$this->total_payouts_24h = $total_payouts_24h;
		$this->total_amount_24h = $total_amount_24h;
		$this->active_batches = $active_batches;
		$this->pending_queue_size = $pending_queue_size;
		$this->last_batch_completed = $last_batch_completed;
	}

	public function isHealthy(): bool {
		return $this->success_rate >= 95 && $this->avg_processing_time_ms < 5000;
	}

	public function getHealthStatus(): string {
		if ( $this->success_rate >= 98 && $this->error_rate < 1 ) {
			return 'excellent';
		} elseif ( $this->success_rate >= 95 && $this->error_rate < 3 ) {
			return 'good';
		} elseif ( $this->success_rate >= 90 && $this->error_rate < 5 ) {
			return 'warning';
		}

		return 'critical';
	}
}

/**
 * Immutable DTO for failed payout data
 *
 * @requirement REQ-4E-007 - Failed payout resolution
 */
class FailedPayoutData {
	public readonly int $payout_id;
	public readonly int $seller_id;
	public readonly int $amount;
	public readonly string $failed_reason;
	public readonly int $retry_count;
	public readonly int $max_retries;
	public readonly ?\DateTime $next_retry_at;
	public readonly \DateTime $failed_at;

	public function __construct(
		int $payout_id,
		int $seller_id,
		int $amount,
		string $failed_reason,
		int $retry_count,
		int $max_retries,
		?\DateTime $next_retry_at,
		\DateTime $failed_at
	) {
		$this->payout_id = $payout_id;
		$this->seller_id = $seller_id;
		$this->amount = $amount;
		$this->failed_reason = $failed_reason;
		$this->retry_count = $retry_count;
		$this->max_retries = $max_retries;
		$this->next_retry_at = $next_retry_at;
		$this->failed_at = $failed_at;
	}

	public function canRetry(): bool {
		return $this->retry_count < $this->max_retries;
	}

	public function isPermanentlyFailed(): bool {
		return $this->retry_count >= $this->max_retries;
	}

	public function isEligibleForRetry(): bool {
		if ( ! $this->canRetry() ) {
			return false;
		}

		if ( $this->next_retry_at === null ) {
			return false;
		}

		return $this->next_retry_at <= new \DateTime();
	}
}

/**
 * Immutable DTO for report data
 *
 * @requirement REQ-4E-005 - Report generation
 */
class ReportData {
	public readonly string $report_type;
	public readonly \DateTime $start_date;
	public readonly \DateTime $end_date;
	public readonly int $total_payouts;
	public readonly int $total_amount;
	public readonly int $total_commissions;
	public readonly int $successful_payouts;
	public readonly int $failed_payouts;
	public readonly float $success_rate;
	public readonly array $processor_breakdown;
	public readonly array $seller_breakdown;
	public readonly \DateTime $generated_at;

	public function __construct(
		string $report_type,
		\DateTime $start_date,
		\DateTime $end_date,
		int $total_payouts,
		int $total_amount,
		int $total_commissions,
		int $successful_payouts,
		int $failed_payouts,
		float $success_rate,
		array $processor_breakdown,
		array $seller_breakdown,
		\DateTime $generated_at
	) {
		$this->report_type = $report_type;
		$this->start_date = $start_date;
		$this->end_date = $end_date;
		$this->total_payouts = $total_payouts;
		$this->total_amount = $total_amount;
		$this->total_commissions = $total_commissions;
		$this->successful_payouts = $successful_payouts;
		$this->failed_payouts = $failed_payouts;
		$this->success_rate = $success_rate;
		$this->processor_breakdown = $processor_breakdown;
		$this->seller_breakdown = $seller_breakdown;
		$this->generated_at = $generated_at;
	}

	public function getDateRange(): string {
		return $this->start_date->format( 'Y-m-d' ) . ' to ' . $this->end_date->format( 'Y-m-d' );
	}
}

/**
 * Immutable DTO for metrics data
 *
 * @requirement REQ-4E-010 - Real-time metrics
 */
class MetricsData {
	public readonly string $metric_key;
	public readonly array $values;
	public readonly \DateTime $updated_at;

	public function __construct(
		string $metric_key,
		array $values,
		\DateTime $updated_at
	) {
		$this->metric_key = $metric_key;
		$this->values = $values;
		$this->updated_at = $updated_at;
	}
}

/**
 * Immutable DTO for anomaly detection
 *
 * @requirement REQ-4E-008 - Anomaly alerts
 */
class AnomalyAlert {
	public readonly string $alert_type;
	public readonly string $message;
	public readonly string $severity;
	public readonly array $details;
	public readonly \DateTime $detected_at;

	public function __construct(
		string $alert_type,
		string $message,
		string $severity,
		array $details,
		\DateTime $detected_at
	) {
		$this->alert_type = $alert_type;
		$this->message = $message;
		$this->severity = $severity;
		$this->details = $details;
		$this->detected_at = $detected_at;
	}

	public function getCSSClass(): string {
		$classes = [
			'warning' => 'alert-warning',
			'error' => 'alert-error',
			'critical' => 'alert-critical',
		];

		return $classes[ $this->severity ] ?? 'alert-info';
	}
}
