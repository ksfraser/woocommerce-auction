<?php

namespace Yith\Auctions\Traits;

/**
 * LoggerTrait - Provides structured logging capability to any class.
 *
 * Implements standardized logging with configurable levels (error, warning, info, debug).
 * Logs are structured JSON format for machine parsing and analysis.
 *
 * @package Yith\Auctions\Traits
 * @requirement REQ-LOGGING-001: Structured logging with configurable levels
 */
trait LoggerTrait
{
    /**
     * @var string Log level configuration (ERROR, WARNING, INFO, DEBUG)
     */
    private string $logLevel = 'INFO';

    /**
     * @var array Allowed log levels with numeric severity
     */
    private const LOG_LEVELS = [
        'ERROR'   => 1,
        'WARNING' => 2,
        'INFO'    => 3,
        'DEBUG'   => 4,
    ];

    /**
     * Set the logging level.
     *
     * @param string $level One of: ERROR, WARNING, INFO, DEBUG
     * @throws \InvalidArgumentException If level is invalid
     * @requirement REQ-LOGGING-001
     */
    public function setLogLevel(string $level): void
    {
        $level = strtoupper($level);
        if (!isset(self::LOG_LEVELS[$level])) {
            throw new \InvalidArgumentException(
                "Invalid log level: {$level}. Allowed: " . implode(', ', array_keys(self::LOG_LEVELS))
            );
        }
        $this->logLevel = $level;
    }

    /**
     * Log an error message.
     *
     * @param string $message Error message
     * @param array  $context Additional context data
     * @requirement REQ-LOGGING-001
     */
    protected function logError(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message Warning message
     * @param array  $context Additional context data
     * @requirement REQ-LOGGING-001
     */
    protected function logWarning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Log an info message.
     *
     * @param string $message Info message
     * @param array  $context Additional context data
     * @requirement REQ-LOGGING-001
     */
    protected function logInfo(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * Log a debug message.
     *
     * @param string $message Debug message
     * @param array  $context Additional context data
     * @requirement REQ-LOGGING-001
     */
    protected function logDebug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    /**
     * Core logging method.
     *
     * Logs structured JSON to WordPress debug.log if level is enabled.
     *
     * @param string $level   Log level
     * @param string $message Log message
     * @param array  $context Additional context
     * @requirement REQ-LOGGING-001
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Check if this level should be logged
        if (self::LOG_LEVELS[$level] > self::LOG_LEVELS[$this->logLevel]) {
            return;
        }

        $log_entry = [
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'level'     => $level,
            'class'     => static::class,
            'message'   => $message,
            'context'   => $context,
        ];

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(json_encode($log_entry));
        }
    }
}
