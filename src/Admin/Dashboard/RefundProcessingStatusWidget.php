<?php
/**
 * Admin Dashboard Widget: Refund Processing Status
 *
 * Displays cron status, refund queue information, and manual trigger controls
 * for WordPress admin dashboard.
 *
 * @package YITHEA\Admin\Dashboard
 */

namespace YITHEA\Admin\Dashboard;

use YITHEA\Integration\RefundSchedulerCronIntegration;
use YITHEA\Traits\LoggerTrait;

/**
 * Class: RefundProcessingStatusWidget
 *
 * Admin dashboard widget showing refund cron status, queue state,
 * and manual intervention controls.
 *
 * **UML Structure:**
 * ```
 * RefundProcessingStatusWidget
 * ├─ Properties
 * │  ├─ cron_integration: RefundSchedulerCronIntegration
 * │  └─ current_user: WP_User
 * ├─ Public Methods
 * │  ├─ register(): void
 * │  ├─ render(): void
 * │  └─ handleManualTrigger(): void
 * └─ Private Methods
 *    ├─ renderCronStatus(): string
 *    ├─ renderQueueStatus(): string
 *    ├─ renderStatistics(): string
 *    ├─ renderFailedRefunds(): string
 *    ├─ renderActionButtons(): string
 *    ├─ getStatusBadge(): string
 *    ├─ formatTimestamp(): string
 *    └─ canManageCron(): bool
 * ```
 *
 * **Responsibility:** Provide admin visibility into refund processing system
 * including current status, queue metrics, and manual controls.
 *
 * **Dependencies:** RefundSchedulerCronIntegration, current user capabilities
 *
 * @covers requirement REQ-4C-S3-009: Admin refund processing dashboard
 */
class RefundProcessingStatusWidget {

    use LoggerTrait;

    /**
     * Cron integration service
     *
     * @var RefundSchedulerCronIntegration
     */
    private RefundSchedulerCronIntegration $cron_integration;

    /**
     * Current admin user
     *
     * @var \WP_User
     */
    private \WP_User $current_user;

    /**
     * Widget ID
     *
     * @var string
     */
    private string $widget_id = 'yith-auction-refund-processing';

    /**
     * Constructor
     *
     * @param RefundSchedulerCronIntegration $cron_integration Cron integration service.
     */
    public function __construct(RefundSchedulerCronIntegration $cron_integration) {
        $this->cron_integration = $cron_integration;
        $this->current_user = wp_get_current_user();
    }

    /**
     * Register dashboard widget
     *
     * Hooks into WordPress dashboard initialization.
     *
     * @return void
     *
     * @requirement REQ-4C-S3-009
     */
    public function register(): void {
        add_action('wp_dashboard_setup', [$this, 'addDashboardWidget']);
        add_action('admin_post_yith_auction_trigger_refund_processing', [$this, 'handleManualTrigger']);
    }

    /**
     * Add dashboard widget
     *
     * WordPress callback for wp_dashboard_setup.
     *
     * @return void
     */
    public function addDashboardWidget(): void {
        if (!$this->canManageCron()) {
            return;
        }

        wp_add_dashboard_widget(
            $this->widget_id,
            'Auction Refund Processing Status',
            [$this, 'render']
        );
    }

    /**
     * Render widget HTML
     *
     * Main widget display with all sections.
     *
     * @return void
     */
    public function render(): void {
        ?>
        <div class="yith-auction-refund-widget">
            <?php echo $this->renderCronStatus(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->renderQueueStatus(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->renderStatistics(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->renderFailedRefunds(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->renderActionButtons(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
    }

    /**
     * Render cron status section
     *
     * Displays current cron scheduling state.
     *
     * @return string HTML output.
     */
    private function renderCronStatus(): string {
        $status = $this->cron_integration->getStatus();

        $status_badge = $this->getStatusBadge($status['status'] ?? 'unknown');

        $next_run = isset($status['next_run']) && $status['next_run']
            ? $this->formatTimestamp((int) $status['next_run'])
            : 'Not scheduled';

        ob_start();
        ?>
        <div class="yith-auction-refund-section">
            <h3>Cron Status</h3>
            <table class="widefat">
                <tr>
                    <td>Status:</td>
                    <td><?php echo $status_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                </tr>
                <tr>
                    <td>Hook:</td>
                    <td><code><?php echo esc_html($status['hook'] ?? 'wc_auction_process_refunds'); ?></code></td>
                </tr>
                <tr>
                    <td>Interval:</td>
                    <td><?php echo esc_html($status['interval'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td>Next Run:</td>
                    <td><?php echo esc_html($next_run); ?></td>
                </tr>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render queue status section
     *
     * Displays refund queue metrics.
     *
     * @return string HTML output.
     */
    private function renderQueueStatus(): string {
        $queue = $this->cron_integration->getQueueStatus();

        $oldest_age = isset($queue['oldest_refund_age_hours'])
            ? sprintf('%d hours ago', (int) $queue['oldest_refund_age_hours'])
            : 'N/A';

        ob_start();
        ?>
        <div class="yith-auction-refund-section">
            <h3>Refund Queue Status</h3>
            <div class="yith-auction-queue-grid">
                <div class="queue-stat">
                    <div class="stat-value"><?php echo esc_html($queue['scheduled'] ?? 0); ?></div>
                    <div class="stat-label">Scheduled</div>
                </div>
                <div class="queue-stat">
                    <div class="stat-value"><?php echo esc_html($queue['processing'] ?? 0); ?></div>
                    <div class="stat-label">Processing</div>
                </div>
                <div class="queue-stat">
                    <div class="stat-value"><?php echo esc_html($queue['completed'] ?? 0); ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="queue-stat">
                    <div class="stat-value alert"><?php echo esc_html($queue['failed'] ?? 0); ?></div>
                    <div class="stat-label">Failed</div>
                </div>
            </div>
            <p class="queue-note">Oldest refund: <?php echo esc_html($oldest_age); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render statistics section
     *
     * Displays processing statistics and metrics.
     *
     * @return string HTML output.
     */
    private function renderStatistics(): string {
        $stats = $this->cron_integration->getStatistics();

        $total_pending = $stats['total_pending'] ?? 0;
        $total_processed = $stats['total_processed'] ?? 0;
        $total_failed = $stats['total_failed'] ?? 0;
        $success_rate = $stats['success_rate'] ?? 0;
        $avg_time = $stats['avg_processing_time_ms'] ?? 0;

        $success_color = $success_rate >= 95 ? '#28a745' : ($success_rate >= 80 ? '#ffc107' : '#dc3545');

        ob_start();
        ?>
        <div class="yith-auction-refund-section">
            <h3>Processing Statistics</h3>
            <table class="widefat">
                <tr>
                    <td>Total Pending:</td>
                    <td><strong><?php echo esc_html($total_pending); ?></strong></td>
                </tr>
                <tr>
                    <td>Total Processed:</td>
                    <td><strong><?php echo esc_html($total_processed); ?></strong></td>
                </tr>
                <tr>
                    <td>Total Failed:</td>
                    <td><strong style="color: #dc3545;"><?php echo esc_html($total_failed); ?></strong></td>
                </tr>
                <tr>
                    <td>Success Rate:</td>
                    <td>
                        <strong style="color: <?php echo esc_attr($success_color); ?>;">
                            <?php echo esc_html(sprintf('%.1f%%', $success_rate)); ?>
                        </strong>
                    </td>
                </tr>
                <tr>
                    <td>Avg Processing Time:</td>
                    <td><?php echo esc_html(sprintf('%d ms', (int) $avg_time)); ?></td>
                </tr>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render failed refunds section
     *
     * Displays recent failed refunds with retry options.
     *
     * @return string HTML output.
     */
    private function renderFailedRefunds(): string {
        $failed = $this->cron_integration->getQueueStatus()['failed'] ?? 0;

        if ($failed === 0) {
            return '';
        }

        ob_start();
        ?>
        <div class="yith-auction-refund-section alert">
            <h3>Failed Refunds</h3>
            <p>There are <strong><?php echo esc_html($failed); ?></strong> failed refunds requiring attention.</p>
            <p class="description">
                Failed refunds can be retried manually or will be automatically retried during the next cron cycle.
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=yith-auction-refunds&filter=failed')); ?>" 
                   class="button">
                    View Failed Refunds
                </a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render action buttons section
     *
     * Provides manual intervention controls.
     *
     * @return string HTML output.
     */
    private function renderActionButtons(): string {
        ob_start();
        ?>
        <div class="yith-auction-refund-section actions">
            <h3>Actions</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="yith_auction_trigger_refund_processing">
                <?php wp_nonce_field('yith_auction_trigger_refund_processing_nonce', 'nonce'); ?>

                <p>
                    <button type="submit" class="button button-primary">
                        Force Refund Processing Now
                    </button>
                </p>
                <p class="description">
                    Manually trigger refund processing immediately. Useful when you need refunds processed before the next hourly cron cycle.
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle manual trigger request
     *
     * AJAX POST handler for manual refund processing trigger.
     *
     * @return void
     */
    public function handleManualTrigger(): void {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_key($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'yith_auction_trigger_refund_processing_nonce')) {
            wp_die('Security check failed');
        }

        // Check capabilities
        if (!$this->canManageCron()) {
            wp_die('Insufficient permissions');
        }

        // Trigger processing
        $result = $this->cron_integration->manuallyTriggerProcessing();

        // Log result
        $this->logger->info(
            'Manual refund processing triggered',
            [
                'status' => $result['status'],
                'processed' => $result['stats']['processed_count'] ?? 0,
                'failed' => $result['stats']['failed_count'] ?? 0,
                'admin_id' => $this->current_user->ID,
            ]
        );

        // Redirect with status
        $redirect_url = admin_url('index.php');
        if ($result['status'] === 'SUCCESS') {
            $redirect_url = add_query_arg('yith_auction_refund_result', 'success', $redirect_url);
        } else {
            $redirect_url = add_query_arg(
                ['yith_auction_refund_result' => 'error', 'error_msg' => urlencode($result['error'] ?? '')],
                $redirect_url
            );
        }

        wp_safe_remote_post($redirect_url);
        exit;
    }

    /**
     * Get status badge HTML
     *
     * Returns colored badge for status display.
     *
     * @param string $status Status value.
     *
     * @return string HTML badge.
     */
    private function getStatusBadge(string $status): string {
        $colors = [
            'active' => '#28a745',
            'inactive' => '#6c757d',
            'overdue' => '#dc3545',
            'unknown' => '#6c757d',
        ];

        $color = $colors[$status] ?? '#6c757d';
        $label = ucfirst($status);

        return sprintf(
            '<span style="display: inline-block; padding: 4px 8px; background-color: %s; color: white; border-radius: 3px;">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    /**
     * Format timestamp for display
     *
     * Converts Unix timestamp to human-readable format.
     *
     * @param int $timestamp Unix timestamp.
     *
     * @return string Formatted time.
     */
    private function formatTimestamp(int $timestamp): string {
        return wp_date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Check if current user can manage cron
     *
     * Validates current user has permissions to view/manage cron.
     *
     * @return bool True if user can manage, false otherwise.
     */
    private function canManageCron(): bool {
        return current_user_can('manage_options');
    }
}
