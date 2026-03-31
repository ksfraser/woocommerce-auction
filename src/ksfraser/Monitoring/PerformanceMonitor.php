<?php
/**
 * System performance monitoring and health check service.
 *
 * Monitors dashboard system health including API response times,
 * database performance, batch job processing, cache efficiency, and resource usage.
 *
 * @requirement REQ-PERFORMANCE-MONITORING-001
 * @package ksfraser\Monitoring
 * @version 1.0.0
 */

namespace ksfraser\Monitoring;

/**
 * PerformanceMonitor class.
 *
 * Tracks system performance metrics, health indicators, and resource usage.
 * Provides alerts for performance degradation.
 */
class PerformanceMonitor {

	/**
	 * Performance metrics storage.
	 *
	 * @var array
	 */
	private $metrics = array();

	/**
	 * Health check threshold values.
	 *
	 * @var array
	 */
	private $thresholds = array(
		'api_response_time_ms'      => 500,
		'database_query_time_ms'    => 100,
		'batch_job_time_seconds'    => 300,
		'memory_usage_pct'          => 80,
		'disk_usage_pct'            => 90,
		'cache_hit_rate_pct'        => 60,
		'error_rate_pct'            => 5,
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->initialize_metrics();
	}

	/**
	 * Initialize performance metrics.
	 *
	 * @return void
	 */
	private function initialize_metrics() {
		$this->metrics = array(
			'api_calls'          => 0,
			'api_total_time'     => 0,
			'database_queries'   => 0,
			'database_total_time' => 0,
			'batch_jobs'         => 0,
			'batch_total_time'   => 0,
			'errors'             => 0,
			'warnings'           => 0,
			'timestamps'         => array(),
		);
	}

	/**
	 * Record API call timing.
	 *
	 * @param string $endpoint     API endpoint called.
	 * @param float  $response_time Response time in milliseconds.
	 * @param bool   $success       Whether call succeeded.
	 * @return void
	 */
	public function record_api_call( $endpoint, $response_time, $success = true ) {
		$this->metrics['api_calls']++;
		$this->metrics['api_total_time'] += $response_time;

		if ( ! $success ) {
			$this->metrics['errors']++;
		}

		$this->metrics['timestamps'][] = array(
			'type'     => 'api_call',
			'endpoint' => $endpoint,
			'time_ms'  => $response_time,
			'success'  => $success,
			'timestamp' => microtime( true ),
		);

		// Alert if response time exceeds threshold
		if ( $response_time > $this->thresholds['api_response_time_ms'] ) {
			do_action(
				'yith_auction_performance_alert',
				array(
					'type'             => 'slow_api',
					'endpoint'         => $endpoint,
					'response_time_ms' => $response_time,
					'threshold_ms'     => $this->thresholds['api_response_time_ms'],
				)
			);
		}
	}

	/**
	 * Record database query timing.
	 *
	 * @param string $query   SQL query executed.
	 * @param float  $time_ms Query execution time in milliseconds.
	 * @return void
	 */
	public function record_database_query( $query, $time_ms ) {
		$this->metrics['database_queries']++;
		$this->metrics['database_total_time'] += $time_ms;

		$this->metrics['timestamps'][] = array(
			'type'     => 'database_query',
			'query'    => substr( $query, 0, 100 ), // Store first 100 chars
			'time_ms'  => $time_ms,
			'timestamp' => microtime( true ),
		);

		// Alert if query time exceeds threshold
		if ( $time_ms > $this->thresholds['database_query_time_ms'] ) {
			do_action(
				'yith_auction_performance_alert',
				array(
					'type'        => 'slow_query',
					'query'       => $query,
					'query_time_ms' => $time_ms,
					'threshold_ms' => $this->thresholds['database_query_time_ms'],
				)
			);
		}
	}

	/**
	 * Record batch job execution.
	 *
	 * @param int    $job_id      Job ID.
	 * @param int    $items_processed Items processed.
	 * @param float  $time_seconds Execution time in seconds.
	 * @return void
	 */
	public function record_batch_job( $job_id, $items_processed, $time_seconds ) {
		$this->metrics['batch_jobs']++;
		$this->metrics['batch_total_time'] += $time_seconds;

		$this->metrics['timestamps'][] = array(
			'type'            => 'batch_job',
			'job_id'          => $job_id,
			'items'           => $items_processed,
			'time_seconds'    => $time_seconds,
			'items_per_sec'   => $items_processed > 0 ? $items_processed / $time_seconds : 0,
			'timestamp'       => microtime( true ),
		);

		// Alert if job time exceeds threshold
		if ( $time_seconds > $this->thresholds['batch_job_time_seconds'] ) {
			do_action(
				'yith_auction_performance_alert',
				array(
					'type'             => 'slow_batch_job',
					'job_id'           => $job_id,
					'job_time_seconds' => $time_seconds,
					'threshold_seconds' => $this->thresholds['batch_job_time_seconds'],
				)
			);
		}
	}

	/**
	 * Record an error.
	 *
	 * @param string $error_message Error message.
	 * @param string $context       Error context.
	 * @return void
	 */
	public function record_error( $error_message, $context = '' ) {
		$this->metrics['errors']++;

		$this->metrics['timestamps'][] = array(
			'type'    => 'error',
			'message' => $error_message,
			'context' => $context,
			'timestamp' => microtime( true ),
		);
	}

	/**
	 * Get health status.
	 *
	 * Comprehensive system health assessment.
	 *
	 * @return array Health status with indicators and recommendations.
	 */
	public function get_health_status() {
		$health = array(
			'status'            => 'healthy',
			'checks'            => array(),
			'alerts'            => array(),
			'metrics'           => $this->get_performance_metrics(),
			'timestamp'         => current_time( 'mysql' ),
		);

		// Check API performance
		$api_avg_time = $this->metrics['api_calls'] > 0 
			? $this->metrics['api_total_time'] / $this->metrics['api_calls']
			: 0;

		if ( $api_avg_time > $this->thresholds['api_response_time_ms'] ) {
			$health['status'] = 'degraded';
			$health['alerts'][] = array(
				'type'              => 'slow_api',
				'average_time_ms'   => round( $api_avg_time, 2 ),
				'threshold_ms'      => $this->thresholds['api_response_time_ms'],
				'recommendation'    => 'Review API endpoints and optimize slow endpoints',
			);
		}

		// Check database performance
		$db_avg_time = $this->metrics['database_queries'] > 0
			? $this->metrics['database_total_time'] / $this->metrics['database_queries']
			: 0;

		if ( $db_avg_time > $this->thresholds['database_query_time_ms'] ) {
			$health['status'] = 'degraded';
			$health['alerts'][] = array(
				'type'              => 'slow_queries',
				'average_time_ms'   => round( $db_avg_time, 2 ),
				'threshold_ms'      => $this->thresholds['database_query_time_ms'],
				'recommendation'    => 'Review query optimization and index strategy',
			);
		}

		// Check error rate
		$total_operations = $this->metrics['api_calls'] + $this->metrics['database_queries'];
		$error_rate = $total_operations > 0 
			? ( $this->metrics['errors'] / $total_operations ) * 100
			: 0;

		if ( $error_rate > $this->thresholds['error_rate_pct'] ) {
			$health['status'] = 'error';
			$health['alerts'][] = array(
				'type'              => 'high_error_rate',
				'error_rate_pct'    => round( $error_rate, 2 ),
				'threshold_pct'     => $this->thresholds['error_rate_pct'],
				'recommendation'    => 'Investigate error logs for failure causes',
			);
		}

		// Check resource usage
		$memory = memory_get_usage( true ) / memory_get_limit() * 100;
		if ( $memory > $this->thresholds['memory_usage_pct'] ) {
			$health['status'] = 'degraded';
			$health['alerts'][] = array(
				'type'           => 'high_memory',
				'usage_pct'      => round( $memory, 2 ),
				'threshold_pct'  => $this->thresholds['memory_usage_pct'],
				'recommendation' => 'Increase PHP memory limit or optimize memory usage',
			);
		}

		return $health;
	}

	/**
	 * Get performance metrics summary.
	 *
	 * @return array Summary of performance metrics.
	 */
	public function get_performance_metrics() {
		$api_avg_time = $this->metrics['api_calls'] > 0
			? $this->metrics['api_total_time'] / $this->metrics['api_calls']
			: 0;

		$db_avg_time = $this->metrics['database_queries'] > 0
			? $this->metrics['database_total_time'] / $this->metrics['database_queries']
			: 0;

		$batch_avg_time = $this->metrics['batch_jobs'] > 0
			? $this->metrics['batch_total_time'] / $this->metrics['batch_jobs']
			: 0;

		return array(
			'api_calls'              => $this->metrics['api_calls'],
			'api_average_time_ms'    => round( $api_avg_time, 2 ),
			'api_total_time_ms'      => round( $this->metrics['api_total_time'], 2 ),
			'database_queries'       => $this->metrics['database_queries'],
			'database_average_time_ms' => round( $db_avg_time, 2 ),
			'database_total_time_ms' => round( $this->metrics['database_total_time'], 2 ),
			'batch_jobs'             => $this->metrics['batch_jobs'],
			'batch_average_time_sec' => round( $batch_avg_time, 2 ),
			'batch_total_time_sec'   => round( $this->metrics['batch_total_time'], 2 ),
			'total_errors'           => $this->metrics['errors'],
			'total_warnings'         => $this->metrics['warnings'],
			'memory_usage_mb'        => round( memory_get_usage( true ) / 1024 / 1024, 2 ),
			'peak_memory_mb'         => round( memory_get_peak_usage( true ) / 1024 / 1024, 2 ),
		);
	}

	/**
	 * Set performance threshold.
	 *
	 * @param string $metric    Metric name.
	 * @param float  $threshold Threshold value.
	 * @return void
	 */
	public function set_threshold( $metric, $threshold ) {
		if ( isset( $this->thresholds[ $metric ] ) ) {
			$this->thresholds[ $metric ] = $threshold;
		}
	}

	/**
	 * Get all performance thresholds.
	 *
	 * @return array Current threshold values.
	 */
	public function get_thresholds() {
		return $this->thresholds;
	}

	/**
	 * Reset metrics.
	 *
	 * Clears all collected metrics for fresh monitoring.
	 *
	 * @return void
	 */
	public function reset_metrics() {
		$this->initialize_metrics();
	}

	/**
	 * Export metrics for storage.
	 *
	 * Returns metrics in serializable format for database storage.
	 *
	 * @return array Export-ready metrics array.
	 */
	public function export_metrics() {
		return array(
			'metrics'       => $this->metrics,
			'thresholds'    => $this->thresholds,
			'exported_at'   => current_time( 'mysql' ),
			'health_status' => $this->get_health_status(),
		);
	}
}
