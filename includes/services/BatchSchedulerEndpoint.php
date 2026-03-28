<?php
/**
 * Batch Scheduler Endpoint - AJAX handler for batch processing triggers
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-045: Provide AJAX endpoint for manual batch processing
 */

namespace WC\Auction\Services;

use WC\Auction\Models\BatchProcessingResult;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BatchSchedulerEndpoint - AJAX endpoint for batch processing
 *
 * Provides AJAX action handler for manual and admin-triggered batch processing.
 * Validates nonce, capability, parameters, and delegates to BatchScheduler.
 *
 * @requirement REQ-4D-045: Handle AJAX batch processing requests
 */
class BatchSchedulerEndpoint {

    /**
     * AJAX action name
     */
    const AJAX_ACTION = 'wc_auction_process_batch';

    /**
     * Nonce action name
     */
    const NONCE_ACTION = 'wc_auction_process_batch_nonce';

    /**
     * Batch scheduler service
     *
     * @var BatchScheduler
     */
    private $scheduler;

    /**
     * Constructor
     *
     * @param BatchScheduler $scheduler Batch scheduler service
     */
    public function __construct( BatchScheduler $scheduler ) {
        $this->scheduler = $scheduler;
    }

    /**
     * Register AJAX actions
     *
     * Called during plugin initialization to register AJAX actions.
     */
    public function registerAjaxActions(): void {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handleProcessBatch' ] );
    }

    /**
     * Handle process batch AJAX request
     *
     * @requirement REQ-4D-045: Validate nonce and capability before processing
     */
    public function handleProcessBatch(): void {
        // Validate nonce
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_send_json_error( [
                'message' => __( 'Invalid security token.', 'yith-auctions-for-woocommerce' ),
            ], 403 );
        }

        // Validate user capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'message' => __( 'You do not have permission to perform this action.', 'yith-auctions-for-woocommerce' ),
            ], 403 );
        }

        // Get and validate batch ID
        if ( ! isset( $_REQUEST['batch_id'] ) ) {
            wp_send_json_error( [
                'message' => __( 'Batch ID is required.', 'yith-auctions-for-woocommerce' ),
            ], 400 );
        }

        $batch_id = (int) sanitize_text_field( wp_unslash( $_REQUEST['batch_id'] ) );
        if ( $batch_id <= 0 ) {
            wp_send_json_error( [
                'message' => __( 'Invalid batch ID.', 'yith-auctions-for-woocommerce' ),
            ], 400 );
        }

        try {
            // Process batch
            $result = $this->scheduler->processNow( $batch_id );

            // Return success response
            wp_send_json_success( $result->toArray() );
        } catch ( \Exception $e ) {
            wp_send_json_error( [
                'message' => __( 'Failed to process batch: ', 'yith-auctions-for-woocommerce' ) . $e->getMessage(),
                'batch_id' => $batch_id,
            ], 500 );
        }
    }

    /**
     * Get nonce for AJAX requests
     *
     * Used in frontend scripts to embed nonce for AJAX calls.
     *
     * @return string
     */
    public static function getNonce(): string {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    /**
     * Get AJAX URL with action
     *
     * @return string
     */
    public static function getAjaxUrl(): string {
        return add_query_arg( 'action', self::AJAX_ACTION, admin_ajax_url() );
    }
}
