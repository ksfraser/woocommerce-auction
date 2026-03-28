<?php
/**
 * Batch Scheduler Configuration - Configuration management for batch scheduling
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-043: Configuration management for batch scheduling
 */

namespace WC\Auction\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BatchSchedulerConfiguration - Manages configuration for batch processing
 *
 * Allows configuration via constants, wp-config.php, or database settings table.
 * Priority: Constants > wp-config defines > Database table > Defaults
 *
 * @requirement REQ-4D-043: Provide configurable batch scheduling
 */
class BatchSchedulerConfiguration {

    /**
     * Default daily schedule time (UTC)
     */
    const DEFAULT_DAILY_TIME = '02:00';

    /**
     * Default weekly schedule day (0=Sunday)
     */
    const DEFAULT_WEEKLY_DAY = 0;

    /**
     * Database access for configuration
     *
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Configuration cache
     *
     * @var array
     */
    private $cache = [];

    /**
     * Constructor
     *
     * @param \wpdb|null $wpdb WordPress database (injected for testing)
     */
    public function __construct( ?\wpdb $wpdb = null ) {
        global $wpdb as $global_wpdb;
        $this->wpdb = $wpdb ?? $global_wpdb;
    }

    /**
     * Get daily schedule time
     *
     * @return string Time in HH:MM format (UTC)
     */
    public function getDailyScheduleTime(): string {
        return $this->getSetting( 'daily_time', self::DEFAULT_DAILY_TIME );
    }

    /**
     * Set daily schedule time
     *
     * @param string $time_string Time in HH:MM format
     * @return bool Success
     */
    public function setDailyScheduleTime( string $time_string ): bool {
        return $this->setSetting( 'daily_time', $time_string );
    }

    /**
     * Get weekly schedule day
     *
     * @return int Day of week (0=Sunday, 6=Saturday)
     */
    public function getWeeklyScheduleDay(): int {
        return (int) $this->getSetting( 'weekly_day', self::DEFAULT_WEEKLY_DAY );
    }

    /**
     * Set weekly schedule day
     *
     * @param int $day_of_week Day of week (0=Sunday, 6=Saturday)
     * @return bool Success
     */
    public function setWeeklyScheduleDay( int $day_of_week ): bool {
        return $this->setSetting( 'weekly_day', (string) $day_of_week );
    }

    /**
     * Get batch chunk size (payouts per batch)
     *
     * @return int Chunk size
     */
    public function getBatchChunkSize(): int {
        return (int) $this->getSetting( 'chunk_size', '100' );
    }

    /**
     * Set batch chunk size
     *
     * @param int $size Chunk size
     * @return bool Success
     */
    public function setBatchChunkSize( int $size ): bool {
        return $this->setSetting( 'chunk_size', (string) $size );
    }

    /**
     * Get setting value with fallback chain
     *
     * @param string $key Setting key
     * @param mixed  $default Default value
     * @return mixed Setting value
     */
    private function getSetting( string $key, $default = null ) {
        // Check cache first
        if ( isset( $this->cache[ $key ] ) ) {
            return $this->cache[ $key ];
        }

        // Check wp-config constant
        $constant = 'WC_AUCTION_' . strtoupper( $key );
        if ( defined( $constant ) ) {
            $value = constant( $constant );
            $this->cache[ $key ] = $value;
            return $value;
        }

        // Check database settings table
        $value = get_option( "wc_auction_batch_scheduler_{$key}" );
        if ( false !== $value ) {
            $this->cache[ $key ] = $value;
            return $value;
        }

        // Return default
        $this->cache[ $key ] = $default;
        return $default;
    }

    /**
     * Set setting value in database
     *
     * @param string $key Setting key
     * @param mixed  $value Value to set
     * @return bool Success
     */
    private function setSetting( string $key, $value ): bool {
        // Invalidate cache
        unset( $this->cache[ $key ] );

        // Store in options
        return (bool) update_option( "wc_auction_batch_scheduler_{$key}", $value );
    }
}
