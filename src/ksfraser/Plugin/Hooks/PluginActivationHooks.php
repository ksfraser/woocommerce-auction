<?php
/**
 * Plugin activation and deactivation hooks.
 *
 * Integrates database schema initialization with WordPress plugin lifecycle.
 * Handles both activation (schema creation) and deactivation (cleanup).
 *
 * @requirement REQ-DB-MIGRATIONS-001
 * @package ksfraser\Plugin\Hooks
 * @version 1.0.0
 */

namespace ksfraser\Plugin\Hooks;

use ksfraser\Database\Schema\DatabaseSchemaInitializer;

/**
 * PluginActivationHooks class.
 *
 * Registers WordPress hooks for plugin activation and deactivation.
 * Responsible for initializing database schema on activation and cleaning up on deactivation.
 */
class PluginActivationHooks {

	/**
	 * Database schema initializer.
	 *
	 * @var DatabaseSchemaInitializer
	 */
	private $schema_initializer;

	/**
	 * Whether to delete tables on deactivation.
	 *
	 * @var bool
	 */
	private $delete_on_deactivation;

	/**
	 * Constructor.
	 *
	 * @param DatabaseSchemaInitializer $schema_initializer Schema initializer instance.
	 * @param bool                      $delete_on_deactivation Whether to delete tables on deactivation.
	 */
	public function __construct( DatabaseSchemaInitializer $schema_initializer, $delete_on_deactivation = false ) {
		$this->schema_initializer      = $schema_initializer;
		$this->delete_on_deactivation  = $delete_on_deactivation;
	}

	/**
	 * Register hooks with WordPress.
	 *
	 * Should be called during plugin initialization to register activation/deactivation hooks.
	 *
	 * @param string $plugin_basename Plugin basename for hook registration.
	 * @return void
	 */
	public function register( $plugin_basename ) {
		register_activation_hook( $plugin_basename, array( $this, 'on_activation' ) );
		register_deactivation_hook( $plugin_basename, array( $this, 'on_deactivation' ) );
	}

	/**
	 * Handle plugin activation.
	 *
	 * Called when plugin is activated. Initializes database schema.
	 * This is a static method to work with register_activation_hook.
	 *
	 * @static
	 * @return void
	 *
	 * @throws \Exception If schema initialization fails.
	 */
	public function on_activation() {
		try {
			// Initialize the schema
			$result = $this->schema_initializer->initialize();

			// Log activation
			do_action(
				'yith_auction_plugin_activated',
				array(
					'timestamp' => current_time( 'mysql' ),
					'result'    => $result,
				)
			);

			if ( 'error' !== $result['status'] ) {
				// Display admin notice on activation
				add_action( 'admin_notices', array( $this, 'display_activation_success_notice' ) );
			}
		} catch ( \Exception $e ) {
			// Log activation error
			do_action(
				'yith_auction_plugin_activation_error',
				array(
					'timestamp' => current_time( 'mysql' ),
					'error'     => $e->getMessage(),
				)
			);

			// Display admin error notice
			add_action( 'admin_notices', array( $this, 'display_activation_error_notice' ) );
		}
	}

	/**
	 * Handle plugin deactivation.
	 *
	 * Called when plugin is deactivated. Optionally cleans up database.
	 * This is a static method to work with register_deactivation_hook.
	 *
	 * @static
	 * @return void
	 */
	public function on_deactivation() {
		try {
			// Cleanup database if configured
			$this->schema_initializer->cleanup( $this->delete_on_deactivation );

			// Log deactivation
			do_action(
				'yith_auction_plugin_deactivated',
				array(
					'timestamp' => current_time( 'mysql' ),
					'tables_deleted' => $this->delete_on_deactivation,
				)
			);
		} catch ( \Exception $e ) {
			// Log deactivation error
			do_action(
				'yith_auction_plugin_deactivation_error',
				array(
					'timestamp' => current_time( 'mysql' ),
					'error'     => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Display activation success notice.
	 *
	 * @return void
	 */
	public function display_activation_success_notice() {
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong>YITH Auctions Dashboard:</strong>
				<?php esc_html_e( 'Database schema initialized successfully. Dashboard features are ready to use.', 'yith-auctions-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Display activation error notice.
	 *
	 * @return void
	 */
	public function display_activation_error_notice() {
		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong>YITH Auctions Dashboard Error:</strong>
				<?php esc_html_e( 'Failed to initialize database schema. Please contact support.', 'yith-auctions-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get activation status.
	 *
	 * Returns whether the plugin has been activated and schema initialized.
	 *
	 * @return array Activation status with timestamp and statistics.
	 */
	public function get_activation_status() {
		$status = get_option( 'yith_auction_schema_last_init', array() );

		return array(
			'initialized' => ! empty( $status ),
			'last_init'   => $status['timestamp'] ?? null,
			'status'      => $status['status'] ?? null,
			'message'     => $status['message'] ?? null,
			'stats'       => $status['stats'] ?? null,
		);
	}

	/**
	 * Get deactivation status.
	 *
	 * Returns information about the last deactivation.
	 *
	 * @return array Deactivation status with timestamp and details.
	 */
	public function get_deactivation_status() {
		$status = get_option( 'yith_auction_schema_last_cleanup', array() );

		return array(
			'last_cleanup' => $status['timestamp'] ?? null,
			'status'       => $status['status'] ?? null,
			'message'      => $status['message'] ?? null,
		);
	}
}
