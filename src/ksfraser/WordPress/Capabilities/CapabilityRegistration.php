<?php
/**
 * WordPress Capability Registration
 *
 * @package YITH_Auctions\WordPress
 * @subpackage Capabilities
 * @version 1.0.0
 * @requirement REQ-WORDPRESS-CAPS-001-003 - Capability system
 * @covers-requirement REQ-WORDPRESS-CAPS-001 - Define custom capabilities
 * @covers-requirement REQ-WORDPRESS-CAPS-002 - Map capabilities to roles
 * @covers-requirement REQ-WORDPRESS-CAPS-003 - Verify capabilities on access
 */

namespace YITH_Auctions\WordPress\Capabilities;

/**
 * Handles registration and mapping of custom capabilities
 *
 * Defines custom capabilities for auction dashboard access and maps them
 * to WordPress roles (Administrator, Shop Manager, Seller).
 *
 * Capabilities:
 * - manage_auction_settlements: View/manage settlement operations
 * - manage_auction_admin_reports: Access admin reporting dashboard
 * - manage_auction_seller_payouts: Manage and view seller payouts
 * - manage_batch_operations: Monitor and manage batch jobs
 * - view_seller_payouts: Seller views own payouts
 *
 * @since 1.0.0
 */
class CapabilityRegistration {
	/**
	 * List of custom capabilities
	 *
	 * @var array<string, array<string>>
	 */
	private static array $capability_map = [
		'manage_auction_settlements' => [ 'administrator', 'shop_manager' ],
		'manage_auction_admin_reports' => [ 'administrator', 'shop_manager' ],
		'manage_auction_seller_payouts' => [ 'administrator', 'shop_manager' ],
		'manage_batch_operations' => [ 'administrator' ],
		'view_seller_payouts' => [ 'administrator', 'shop_manager', 'seller' ],
	];

	/**
	 * Constructor - registers hooks
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'registerCapabilities' ], 10 );
		add_filter( 'map_meta_cap', [ $this, 'mapMetaCapabilities' ], 10, 4 );
	}

	/**
	 * Register capabilities during admin_init
	 *
	 * Assigns custom capabilities to appropriate WordPress roles.
	 * Creates 'seller' role if it doesn't exist.
	 *
	 * @return void
	 * @covers-requirement REQ-WORDPRESS-CAPS-002
	 * @since 1.0.0
	 */
	public function registerCapabilities(): void {
		// Ensure seller role exists
		$this->ensureSellerRoleExists();

		// Assign capabilities to roles
		foreach ( self::$capability_map as $capability => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );
				if ( $role instanceof \WP_Role ) {
					$role->add_cap( $capability );
				}
			}
		}
	}

	/**
	 * Map meta capabilities to base capabilities
	 *
	 * Hook for map_meta_cap filter. Allows additional capability
	 * mapping logic if needed for complex scenarios.
	 *
	 * @param array<string>|bool $caps Capabilities required.
	 * @param string $cap Capability name.
	 * @param int $user_id User ID.
	 * @param array $args Additional arguments.
	 * @return array<string>|bool Mapped capabilities.
	 * @since 1.0.0
	 */
	public function mapMetaCapabilities( $caps, string $cap, int $user_id, array $args ) {
		// Custom mapping can be added here if needed
		return $caps;
	}

	/**
	 * Ensure 'seller' role exists
	 *
	 * Creates seller role if it doesn't exist. Used for sellers
	 * to access their own payout dashboard.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function ensureSellerRoleExists(): void {
		if ( null === get_role( 'seller' ) ) {
			add_role(
				'seller',
				esc_html__( 'Seller', 'yith-auctions-for-woocommerce' ),
				[
					'read' => true,
					'view_seller_payouts' => true,
				]
			);
		}
	}

	/**
	 * Get capability map
	 *
	 * Returns map of capabilities to roles.
	 *
	 * @return array<string, array<string>> Capability map.
	 * @since 1.0.0
	 */
	public static function getCapabilityMap(): array {
		return self::$capability_map;
	}

	/**
	 * Get capabilities for a role
	 *
	 * @param string $role_name WordPress role name.
	 * @return string[] Array of capabilities for the role.
	 * @since 1.0.0
	 */
	public static function getCapabilitiesForRole( string $role_name ): array {
		$capabilities = [];

		foreach ( self::$capability_map as $capability => $roles ) {
			if ( in_array( $role_name, $roles, true ) ) {
				$capabilities[] = $capability;
			}
		}

		return $capabilities;
	}

	/**
	 * Revoke all custom capabilities
	 *
	 * Called on plugin deactivation to clean up capabilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function revokeCapabilities(): void {
		// Get all registered roles
		$roles = wp_roles();

		if ( ! $roles instanceof \WP_Roles ) {
			return;
		}

		// Remove capabilities from all roles
		foreach ( self::$capability_map as $capability => $role_names ) {
			foreach ( $roles->role_names as $role_name ) {
				$role = get_role( $role_name );
				if ( $role instanceof \WP_Role ) {
					$role->remove_cap( $capability );
				}
			}
		}
	}

	/**
	 * Check if user has capability
	 *
	 * Wrapper around current_user_can() for convenience.
	 *
	 * @param string $capability Capability name.
	 * @return bool True if current user has capability.
	 * @since 1.0.0
	 */
	public static function userHasCapability( string $capability ): bool {
		return current_user_can( $capability );
	}

	/**
	 * Check if capability is defined in system
	 *
	 * @param string $capability Capability name.
	 * @return bool True if capability is in the map.
	 * @since 1.0.0
	 */
	public static function isCustomCapability( string $capability ): bool {
		return isset( self::$capability_map[ $capability ] );
	}
}
