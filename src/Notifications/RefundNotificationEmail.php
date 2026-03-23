<?php
/**
 * Refund Notification Email Class
 *
 * Generates and sends email notifications for refund completion.
 * Includes HTML templates for bidders and admin alerts.
 *
 * @package YITHEA\Notifications
 */

namespace YITHEA\Notifications;

use YITHEA\Traits\LoggerTrait;

/**
 * Class: RefundNotificationEmail
 *
 * Handles email notifications for:
 * - Bidder refund completion
 * - Admin refund failure alerts
 * - Processing status updates
 *
 * **UML Structure:**
 * ```
 * RefundNotificationEmail
 * ├─ Properties
 * │  ├─ mailer_settings: array
 * │  └─ site_info: array
 * ├─ Public Methods
 * │  ├─ notifyBidderRefundComplete(): bool
 * │  ├─ notifyAdminRefundFailure(): bool
 * │  └─ notifyAdminProcessingSummary(): bool
 * └─ Private Methods
 *    ├─ renderBidderTemplate(): string
 *    ├─ renderAdminFailureTemplate(): string
 *    ├─ renderProcessingSummaryTemplate(): string
 *    ├─ sendMail(): bool
 *    ├─ getMailHeaders(): array
 *    └─ getAdminEmails(): array
 * ```
 *
 * **Responsibility:** Compose and send email notifications for refund events.
 *
 * **Dependencies:** WP_Mail, site configuration
 *
 * @covers requirement REQ-4C-S3-010: Email notification for refunds
 */
class RefundNotificationEmail {

    use LoggerTrait;

    /**
     * Mailer configuration
     *
     * @var array
     */
    private array $mailer_settings;

    /**
     * Site information
     *
     * @var array
     */
    private array $site_info;

    /**
     * Constructor
     */
    public function __construct() {
        $this->mailer_settings = [
            'from_name' => get_option('blogname'),
            'from_email' => get_option('admin_email'),
            'wordpress_notification' => get_option('admin_email'),
        ];

        $this->site_info = [
            'site_url' => get_site_url(),
            'site_name' => get_option('blogname'),
            'site_description' => get_option('blogdescription'),
        ];
    }

    /**
     * Notify bidder of refund completion
     *
     * Sends email to bidder confirming their entry fee refund.
     *
     * @param int    $user_id       Bidder user ID.
     * @param int    $amount_cents  Refund amount in cents.
     * @param string $refund_id     Payment gateway refund ID.
     *
     * @return bool True if email sent, false otherwise.
     *
     * @requirement REQ-4C-S3-010
     */
    public function notifyBidderRefundComplete(int $user_id, int $amount_cents, string $refund_id): bool {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            $this->logger->warning('Refund notification: User not found', ['user_id' => $user_id]);
            return false;
        }

        $to = $user->user_email;
        $subject = sprintf(
            '[%s] Entry Fee Refund Processed - %s',
            esc_html($this->site_info['site_name']),
            $this->formatCurrency($amount_cents)
        );

        $message = $this->renderBidderTemplate($user, $amount_cents, $refund_id);

        return $this->sendMail($to, $subject, $message);
    }

    /**
     * Notify admin of refund failure
     *
     * Sends alert to admin when refund processing fails.
     *
     * @param int    $bid_id        Bid ID.
     * @param int    $amount_cents  Refund amount in cents.
     * @param string $error_message Error description.
     *
     * @return bool True if email sent, false otherwise.
     *
     * @requirement REQ-4C-S3-010
     */
    public function notifyAdminRefundFailure(int $bid_id, int $amount_cents, string $error_message): bool {
        $to = implode(',', $this->getAdminEmails());
        $subject = sprintf(
            '[%s] ACTION REQUIRED: Auction Refund Failed - Bid #%d (%s)',
            esc_html($this->site_info['site_name']),
            $bid_id,
            $this->formatCurrency($amount_cents)
        );

        $message = $this->renderAdminFailureTemplate($bid_id, $amount_cents, $error_message);

        return $this->sendMail($to, $subject, $message);
    }

    /**
     * Notify admin of processing summary
     *
     * Sends daily summary of refund processing results.
     *
     * @param array $summary Processing statistics.
     *
     * @return bool True if email sent, false otherwise.
     */
    public function notifyAdminProcessingSummary(array $summary): bool {
        $to = implode(',', $this->getAdminEmails());
        $subject = sprintf(
            '[%s] Daily Refund Processing Summary',
            esc_html($this->site_info['site_name'])
        );

        $message = $this->renderProcessingSummaryTemplate($summary);

        return $this->sendMail($to, $subject, $message);
    }

    /**
     * Render bidder refund template
     *
     * Generates HTML email template for bidder notification.
     *
     * @param \WP_User $user         User receiving refund.
     * @param int      $amount_cents Refund amount in cents.
     * @param string   $refund_id    Payment gateway refund ID.
     *
     * @return string HTML email body.
     */
    private function renderBidderTemplate(\WP_User $user, int $amount_cents, string $refund_id): string {
        $amount = $this->formatCurrency($amount_cents);
        $site_name = esc_html($this->site_info['site_name']);
        $site_url = esc_url($this->site_info['site_url']);
        $account_url = esc_url($site_url . '/my-account/');

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background-color: #f8f9fa; padding: 20px; border-bottom: 3px solid #007bff; }
                .content { padding: 20px; }
                .amount { font-size: 24px; color: #28a745; font-weight: bold; }
                .details { background-color: #f8f9fa; padding: 15px; margin: 20px 0; border-left: 4px solid #007bff; }
                .footer { background-color: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
                a { color: #007bff; text-decoration: none; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1><?php echo $site_name; ?></h1>
                    <p>Entry Fee Refund Notification</p>
                </div>

                <div class="content">
                    <p>Hi <?php echo esc_html($user->first_name ?: $user->user_login); ?>,</p>

                    <p>Your entry fee has been refunded to your original payment method.</p>

                    <div class="details">
                        <p><strong>Refund Amount:</strong></p>
                        <p class="amount"><?php echo esc_html($amount); ?></p>

                        <p><strong>Refund ID:</strong></p>
                        <p><code><?php echo esc_html($refund_id); ?></code></p>

                        <p><strong>Processing Time:</strong></p>
                        <p>Refunds typically appear in your account within 1-3 business days, depending on your payment method and bank.</p>
                    </div>

                    <p>If you have any questions about your refund, please <a href="<?php echo esc_url($site_url); ?>/contact/">contact our support team</a>.</p>

                    <p><a href="<?php echo $account_url; ?>" class="button">View My Account</a></p>
                </div>

                <div class="footer">
                    <p>&copy; <?php echo esc_html($site_name); ?>. All rights reserved.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Render admin failure template
     *
     * Generates HTML email template for admin failure alert.
     *
     * @param int    $bid_id        Bid ID.
     * @param int    $amount_cents  Refund amount in cents.
     * @param string $error_message Error description.
     *
     * @return string HTML email body.
     */
    private function renderAdminFailureTemplate(int $bid_id, int $amount_cents, string $error_message): string {
        $amount = $this->formatCurrency($amount_cents);
        $site_name = esc_html($this->site_info['site_name']);
        $site_url = esc_url($this->site_info['site_url']);
        $admin_url = esc_url($site_url . '/wp-admin/');

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background-color: #f8d7da; padding: 20px; border-bottom: 3px solid #dc3545; }
                .content { padding: 20px; }
                .alert { background-color: #f8d7da; padding: 15px; margin: 20px 0; border-left: 4px solid #dc3545; color: #721c24; }
                .details { background-color: #f8f9fa; padding: 15px; margin: 20px 0; }
                .code { background-color: #f8f9fa; padding: 10px; font-family: monospace; overflow-x: auto; }
                .button { display: inline-block; padding: 10px 20px; background-color: #dc3545; color: white; text-decoration: none; border-radius: 3px; margin-top: 10px; }
                .footer { background-color: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>⚠️ Refund Processing Failed</h1>
                </div>

                <div class="content">
                    <p><strong>ACTION REQUIRED:</strong> A refund processing error has occurred and requires manual attention.</p>

                    <div class="alert">
                        <p><strong>Error Details:</strong></p>
                        <p><?php echo esc_html($error_message); ?></p>
                    </div>

                    <div class="details">
                        <p><strong>Bid ID:</strong> <?php echo esc_html($bid_id); ?></p>
                        <p><strong>Refund Amount:</strong> <?php echo esc_html($amount); ?></p>
                        <p><strong>Timestamp:</strong> <?php echo esc_html(wp_date('Y-m-d H:i:s')); ?></p>
                    </div>

                    <p>Please review the refund status in the admin dashboard and take appropriate action:</p>
                    <ul>
                        <li>Review the error and correct the underlying issue</li>
                        <li>Manually retry the refund</li>
                        <li>Contact the payment processor if needed</li>
                    </ul>

                    <p>
                        <a href="<?php echo $admin_url; ?>index.php" class="button">View Dashboard</a>
                    </p>
                </div>

                <div class="footer">
                    <p>&copy; <?php echo esc_html($site_name); ?>. All rights reserved.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Render processing summary template
     *
     * Generates HTML email template for daily summary.
     *
     * @param array $summary Processing statistics.
     *
     * @return string HTML email body.
     */
    private function renderProcessingSummaryTemplate(array $summary): string {
        $site_name = esc_html($this->site_info['site_name']);
        $site_url = esc_url($this->site_info['site_url']);
        $admin_url = esc_url($site_url . '/wp-admin/');

        $total_processed = $summary['processed_count'] ?? 0;
        $total_failed = $summary['failed_count'] ?? 0;
        $total_refunded = $this->formatCurrency($summary['total_refunded_cents'] ?? 0);
        $success_rate = $summary['success_rate'] ?? 100;

        $status_color = $success_rate >= 95 ? '#28a745' : ($success_rate >= 80 ? '#ffc107' : '#dc3545');

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background-color: #f8f9fa; padding: 20px; border-bottom: 3px solid #007bff; }
                .content { padding: 20px; }
                .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; }
                .stat-box { background-color: #f8f9fa; padding: 15px; border-radius: 3px; }
                .stat-value { font-size: 28px; font-weight: bold; }
                .stat-label { color: #666; font-size: 12px; margin-top: 5px; }
                .footer { background-color: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1><?php echo $site_name; ?></h1>
                    <p>Daily Refund Processing Summary</p>
                </div>

                <div class="content">
                    <p>Here's your daily summary of refund processing:</p>

                    <div class="stat-grid">
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($total_processed); ?></div>
                            <div class="stat-label">Processed</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value" style="color: #dc3545;"><?php echo esc_html($total_failed); ?></div>
                            <div class="stat-label">Failed</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value" style="color: #28a745;"><?php echo esc_html($total_refunded); ?></div>
                            <div class="stat-label">Total Refunded</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value" style="color: <?php echo esc_attr($status_color); ?>;">
                                <?php echo esc_html(sprintf('%.1f%%', $success_rate)); ?>
                            </div>
                            <div class="stat-label">Success Rate</div>
                        </div>
                    </div>

                    <?php if ($total_failed > 0): ?>
                        <p style="color: #dc3545;"><strong>⚠️ Note:</strong> <?php echo esc_html($total_failed); ?> refund(s) failed. Please review and retry if needed.</p>
                    <?php endif; ?>

                    <p>
                        <a href="<?php echo $admin_url; ?>index.php" style="color: #007bff;">View Dashboard</a>
                    </p>
                </div>

                <div class="footer">
                    <p>&copy; <?php echo esc_html($site_name); ?>. All rights reserved.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Send mail
     *
     * Wrapper for wp_mail with error logging.
     *
     * @param string $to      Recipient email address(es).
     * @param string $subject Email subject.
     * @param string $message Email body.
     *
     * @return bool True if sent, false otherwise.
     */
    private function sendMail(string $to, string $subject, string $message): bool {
        $headers = $this->getMailHeaders();

        $result = wp_mail($to, $subject, $message, $headers);

        if (!$result) {
            $this->logger->error(
                'Failed to send email',
                ['to' => $to, 'subject' => $subject]
            );
        }

        return $result;
    }

    /**
     * Get mail headers
     *
     * Returns standard email headers with proper format.
     *
     * @return array Mail headers.
     */
    private function getMailHeaders(): array {
        return [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $this->mailer_settings['from_name'], $this->mailer_settings['from_email']),
        ];
    }

    /**
     * Get admin emails
     *
     * Retrieves list of admin email addresses for notifications.
     *
     * @return array List of admin email addresses.
     */
    private function getAdminEmails(): array {
        $admin_email = get_option('admin_email');

        /**
         * Filter admin notification emails
         *
         * @param array $emails Default admin emails.
         */
        return apply_filters('yith_auction_admin_notification_emails', [$admin_email]);
    }

    /**
     * Format currency
     *
     * Converts cents to currency string.
     *
     * @param int $cents Amount in cents.
     *
     * @return string Formatted currency (e.g., "$100.00").
     */
    private function formatCurrency(int $cents): string {
        $amount = $cents / 100;
        return wc_price($amount);
    }
}
