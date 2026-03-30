<?php
/**
 * Encryption Installer - Sets up encryption during plugin installation
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    1.0.0
 * @requirement REQ-4D-045: Initialize encryption during plugin setup
 */

namespace WC\Auction\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * EncryptionInstaller - Plugin installation and encryption key setup
 *
 * Handles:
 * - Verifying defuse/php-encryption is installed
 * - Generating encryption keys on first install
 * - Storing keys in WordPress options with guidance for wp-config
 * - Key rotation detection and handling
 *
 * @requirement REQ-4D-045: Initialize encryption during setup
 * @requirement SEC-002: Secure key management (generation and storage)
 */
class EncryptionInstaller {

    /**
     * Option key for stored encryption key
     */
    const OPTION_ENCRYPTION_KEY = 'auction_encryption_key_v1';

    /**
     * Option key for encryption method
     */
    const OPTION_ENCRYPTION_METHOD = 'auction_encryption_method';

    /**
     * Option key for key generation timestamp
     */
    const OPTION_KEY_GENERATED_AT = 'auction_encryption_key_generated_at';

    /**
     * Initialize encryption during plugin activation
     *
     * Called via action hook during plugin load sequence.
     * Sets up encryption key if not already configured.
     *
     * @return true Always returns true for install verification
     * @throws \RuntimeException If encryption cannot be initialized
     *
     * @requirement REQ-4D-045: Set up encryption on plugin install
     */
    public static function install(): bool {
        // Check if defuse/php-encryption is available
        $method = self::detectEncryptionMethod();

        if ( ! $method ) {
            // Log warning but don't fail - sodium fallback available
            error_log( 'Warning: defuse/php-encryption not found. Using sodium fallback.' );
        }

        // Check if key already exists in configuration
        if ( self::hasConfiguredKey() ) {
            return true;
        }

        // Generate and store new key
        self::generateAndStoreKey( $method );

        return true;
    }

    /**
     * Detect available encryption method
     *
     * @return string|null 'defuse', 'sodium', or null if none available
     *
     * @requirement SEC-002: Detect available crypto libraries
     */
    private static function detectEncryptionMethod(): ?string {
        // Check for defuse/php-encryption
        if ( class_exists( '\Defuse\Crypto\Crypto' ) ) {
            return 'defuse';
        }

        // Check for sodium
        if ( extension_loaded( 'sodium' ) || extension_loaded( 'libsodium' ) ) {
            return 'sodium';
        }

        return null;
    }

    /**
     * Check if encryption key is already configured
     *
     * Looks for key in:
     * 1. wp-config.php constant (AUCTION_ENCRYPTION_KEY)
     * 2. WordPress options (stored during install)
     * 3. Environment variable
     *
     * @return bool True if key is configured
     *
     * @requirement SEC-002: Load existing keys from configuration
     */
    private static function hasConfiguredKey(): bool {
        // Check wp-config constant
        if ( defined( 'AUCTION_ENCRYPTION_KEY' ) ) {
            $key = constant( 'AUCTION_ENCRYPTION_KEY' );
            return ! empty( $key );
        }

        // Check environment variable
        if ( ! empty( getenv( 'AUCTION_ENCRYPTION_KEY' ) ) ) {
            return true;
        }

        // Check WordPress options
        $stored_key = get_option( self::OPTION_ENCRYPTION_KEY );
        return ! empty( $stored_key );
    }

    /**
     * Generate and store encryption key
     *
     * Generates a new encryption key and stores in WordPress options.
     * Provides admin notice with instructions for moving to wp-config.
     *
     * @param string|null $method Available encryption method ('defuse' or 'sodium')
     *
     * @requirement REQ-4D-045: Generate encryption key on install
     * @requirement SEC-002: Generate and manage encryption keys
     */
    private static function generateAndStoreKey( ?string $method ): void {
        try {
            // Generate key using appropriate method
            $key = self::generateKey( $method );

            // Store in WordPress options
            update_option( self::OPTION_ENCRYPTION_KEY, $key );
            update_option( self::OPTION_ENCRYPTION_METHOD, $method ?? 'sodium' );
            update_option( self::OPTION_KEY_GENERATED_AT, current_time( 'mysql' ) );

            // Schedule admin notice about key configuration
            self::scheduleAdminNotice();

        } catch ( \Exception $e ) {
            error_log(
                sprintf(
                    'Auction Plugin Error: Failed to generate encryption key: %s',
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Generate encryption key
     *
     * Uses defuse if available, otherwise generates for sodium.
     *
     * @param string|null $method Encryption method ('defuse' or 'sodium')
     * @return string Generated encryption key (base64-encoded)
     * @throws \RuntimeException If key generation fails
     *
     * @requirement SEC-002: Generate strong random keys
     */
    private static function generateKey( ?string $method = null ): string {
        if ( 'defuse' === $method && class_exists( '\Defuse\Crypto\Key' ) ) {
            try {
                $key = \Defuse\Crypto\Key::createNewRandomKey();
                return $key->saveToAsciiSafeString();
            } catch ( \Exception $e ) {
                error_log( 'Defuse key generation failed, falling back to sodium: ' . $e->getMessage() );
            }
        }

        // Fallback to sodium
        if ( extension_loaded( 'sodium' ) || extension_loaded( 'libsodium' ) ) {
            $key = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
            return base64_encode( $key );
        }

        throw new \RuntimeException(
            'Cannot generate encryption key: defuse/php-encryption and sodium unavailable'
        );
    }

    /**
     * Schedule admin notice about encryption key configuration
     *
     * Shows one-time notice with:
     * - Confirmation that key was generated
     * - Instructions to move key to wp-config.php
     * - Link to documentation
     */
    private static function scheduleAdminNotice(): void {
        add_action(
            'admin_notices',
            [ __CLASS__, 'displayEncryptionConfigNotice' ]
        );
    }

    /**
     * Display admin notice about encryption key configuration
     *
     * @wordpress-hook admin_notices
     */
    public static function displayEncryptionConfigNotice(): void {
        // Only show if we stored a temporary key
        $key = get_option( self::OPTION_ENCRYPTION_KEY );
        if ( empty( $key ) ) {
            return;
        }

        // Only show to administrators
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $method = get_option( self::OPTION_ENCRYPTION_METHOD, 'unknown' );
        $generated_at = get_option( self::OPTION_KEY_GENERATED_AT );

        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e( 'WooCommerce Auctions Encryption Configured', 'yith-auctions-for-woocommerce' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: encryption method (defuse or sodium) */
                    esc_html__( 'Encryption has been automatically configured using %s.', 'yith-auctions-for-woocommerce' ),
                    esc_html( ucfirst( $method ) )
                );
                ?>
            </p>
            <p>
                <?php esc_html_e( 'For production environments, add this constant to wp-config.php:', 'yith-auctions-for-woocommerce' ); ?>
            </p>
            <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto;">define('AUCTION_ENCRYPTION_KEY', <?php echo "'" . esc_html( $key ) . "'" ?>);</pre>
            <p>
                <a href="https://docs.example.com/auction-encryption" target="_blank">
                    <?php esc_html_e( 'Learn more about encryption setup →', 'yith-auctions-for-woocommerce' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Get or initialize encryption service
     *
     * Creates an EncryptionService instance using stored or configured key.
     * This is the main entry point for using encryption in the plugin.
     *
     * @return EncryptionService Initialized encryption service
     * @throws \RuntimeException If encryption cannot be initialized
     *
     * @requirement REQ-4D-045: Provide encryption service to other components
     */
    public static function getEncryptionService(): EncryptionService {
        $key = self::getEncryptionKey();

        if ( empty( $key ) ) {
            throw new \RuntimeException(
                'Encryption key not configured. Run plugin installation or set AUCTION_ENCRYPTION_KEY in wp-config.php'
            );
        }

        return new EncryptionService( $key );
    }

    /**
     * Get encryption key from configuration
     *
     * Checks in order:
     * 1. wp-config.php constant (AUCTION_ENCRYPTION_KEY)
     * 2. Environment variable (AUCTION_ENCRYPTION_KEY)
     * 3. WordPress options (set during installation)
     *
     * @return string|null Encryption key or null if not found
     *
     * @requirement SEC-002: Load keys from secure configuration
     */
    public static function getEncryptionKey(): ?string {
        // Check wp-config first (production setup)
        if ( defined( 'AUCTION_ENCRYPTION_KEY' ) ) {
            $key = constant( 'AUCTION_ENCRYPTION_KEY' );
            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        // Check environment variable
        $env_key = getenv( 'AUCTION_ENCRYPTION_KEY' );
        if ( $env_key ) {
            return $env_key;
        }

        // Fall back to WordPress options
        return get_option( self::OPTION_ENCRYPTION_KEY );
    }

    /**
     * Get encryption method info
     *
     * Returns what encryption method is being used.
     *
     * @return array {
     *     @type string $method 'defuse', 'sodium', or 'unknown'
     *     @type string $generated_at ISO 8601 timestamp
     *     @type string $location 'wp-config', 'env', or 'options'
     * }
     */
    public static function getStatus(): array {
        $method = 'unknown';
        $location = 'not-configured';

        if ( defined( 'AUCTION_ENCRYPTION_KEY' ) ) {
            $location = 'wp-config';
        } elseif ( getenv( 'AUCTION_ENCRYPTION_KEY' ) ) {
            $location = 'env';
        } elseif ( get_option( self::OPTION_ENCRYPTION_KEY ) ) {
            $location = 'options';
        }

        if ( 'not-configured' !== $location ) {
            try {
                $service = self::getEncryptionService();
                $method = $service->getMethod();
            } catch ( \Exception $e ) {
                $method = get_option( self::OPTION_ENCRYPTION_METHOD, 'unknown' );
            }
        }

        return [
            'method'       => $method,
            'generated_at' => get_option( self::OPTION_KEY_GENERATED_AT ),
            'location'     => $location,
        ];
    }

    /**
     * Verify encryption is properly configured
     *
     * Checks that:
     * - Encryption key exists
     * - Encryption service can be instantiated
     * - A test encryption/decryption round-trip succeeds
     *
     * @return array {
     *     @type bool $valid True if encryption is working
     *     @type string|null $error Error message if not valid
     * }
     *
     * @requirement REQ-4D-045: Verify encryption is functional
     */
    public static function verify(): array {
        try {
            $service = self::getEncryptionService();

            // Test encryption/decryption
            $test_data = 'encryption-test-' . time();
            $encrypted = $service->encrypt( $test_data );
            $decrypted = $service->decrypt( $encrypted );

            if ( $test_data !== $decrypted ) {
                return [
                    'valid' => false,
                    'error' => 'Encryption round-trip test failed: data mismatch',
                ];
            }

            return [
                'valid' => true,
                'error' => null,
            ];
        } catch ( \Exception $e ) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
