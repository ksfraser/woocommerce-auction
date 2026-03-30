<?php
/**
 * Encryption Service - Symmetric encryption with defuse library + sodium fallback
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.1.0
 * @requirement REQ-4D-045: Provide authenticated encryption for payout methods
 */

namespace WC\Auction\Services;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Defuse\Crypto\EncryptionException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * EncryptionService - Handles authenticated symmetric encryption/decryption
 *
 * Primary: defuse/php-encryption (authenticated, tamper-proof)
 * Fallback: libsodium/php (ChaCha20-Poly1305 if defuse unavailable)
 *
 * Defuse uses AES-256-CBC with HMAC-SHA256 for authentication.
 * Prevents tampering and key misuse through API design.
 *
 * @requirement REQ-4D-045: Encrypt payout method data before storage
 * @requirement SEC-001: Use authenticated encryption with tamper detection
 * @requirement SEC-002: Support key derivation and secure key management
 */
class EncryptionService {

    /**
     * Encryption method: defuse or sodium
     *
     * @var string
     */
    private $method = 'defuse';

    /**
     * Defuse encryption key
     *
     * @var Key|null
     */
    private $defuse_key;

    /**
     * Sodium encryption key (binary for ChaCha20-Poly1305)
     *
     * @var string|null
     */
    private $sodium_key;

    /**
     * Constructor
     *
     * Attempts to use defuse, falls back to sodium if available,
     * throws exception if neither is available.
     *
     * @param string|null $key_material Raw key material (if null, loads from config)
     * @throws \RuntimeException If no encryption method available
     */
    public function __construct( ?string $key_material = null ) {
        $key_material = $key_material ?? $this->loadKeyMaterial();

        if ( empty( $key_material ) ) {
            throw new \RuntimeException( 'Encryption key material cannot be empty' );
        }

        if ( $this->defuseAvailable() ) {
            try {
                // Defuse generates its own key from random bytes
                $this->defuse_key = Key::createNewRandomKey();
                $this->method      = 'defuse';
                return;
            } catch ( EncryptionException $e ) {
                // Fall through to sodium
            }
        }

        if ( $this->sodiumAvailable() ) {
            // Ensure key is correct length for sodium
            if ( strlen( $key_material ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
                // Hash the key material to get correct length
                $this->sodium_key = hash( 'sha256', $key_material, true );
                // If hash isn't 32 bytes, pad/truncate (sha256 is 32 bytes so this shouldn't happen)
                if ( strlen( $this->sodium_key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
                    $this->sodium_key = substr(
                        hash( 'sha512', $key_material, true ),
                        0,
                        SODIUM_CRYPTO_SECRETBOX_KEYBYTES
                    );
                }
            } else {
                $this->sodium_key = $key_material;
            }
            $this->method = 'sodium';
            return;
        }

        throw new \RuntimeException(
            'No encryption method available. Install defuse/php-encryption or enable sodium extension.'
        );
    }

    /**
     * Encrypt data with authenticated encryption
     *
     * @param string $data Plaintext data to encrypt
     * @return string Encrypted data (base64-encoded for storage)
     * @throws \RuntimeException If encryption fails
     *
     * @requirement SEC-001: Encrypt sensitive data with authentication
     */
    public function encrypt( string $data ): string {
        try {
            if ( 'defuse' === $this->method && $this->defuse_key ) {
                $ciphertext = Crypto::encrypt( $data, $this->defuse_key );
                return $ciphertext; // Defuse returns base64-encoded already
            }

            if ( 'sodium' === $this->method && $this->sodium_key ) {
                $nonce       = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $ciphertext  = sodium_crypto_secretbox( $data, $nonce, $this->sodium_key );
                $combined    = $nonce . $ciphertext;
                return base64_encode( $combined );
            }

            throw new \RuntimeException( 'No valid encryption method configured' );
        } catch ( EncryptionException $e ) {
            throw new \RuntimeException( 'Encryption failed: ' . $e->getMessage() );
        } catch ( \Exception $e ) {
            throw new \RuntimeException( 'Encryption failed: ' . $e->getMessage() );
        }
    }

    /**
     * Decrypt authenticated encrypted data
     *
     * @param string $ciphertext Encrypted data (base64-encoded)
     * @return string Plaintext data
     * @throws \RuntimeException If decryption fails or authentication fails
     *
     * @requirement SEC-001: Decrypt and verify authenticated data
     */
    public function decrypt( string $ciphertext ): string {
        try {
            if ( 'defuse' === $this->method && $this->defuse_key ) {
                return Crypto::decrypt( $ciphertext, $this->defuse_key );
            }

            if ( 'sodium' === $this->method && $this->sodium_key ) {
                $combined   = base64_decode( $ciphertext, true );
                $nonce_len  = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                $nonce      = substr( $combined, 0, $nonce_len );
                $ciphertext = substr( $combined, $nonce_len );

                $plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->sodium_key );
                if ( false === $plaintext ) {
                    throw new \RuntimeException( 'Decryption failed: authentication tag verification failed' );
                }
                return $plaintext;
            }

            throw new \RuntimeException( 'No valid decryption method configured' );
        } catch ( EncryptionException $e ) {
            throw new \RuntimeException( 'Decryption failed: ' . $e->getMessage() );
        } catch ( \Exception $e ) {
            throw new \RuntimeException( 'Decryption failed: ' . $e->getMessage() );
        }
    }

    /**
     * Get active encryption method
     *
     * @return string Either 'defuse' or 'sodium'
     */
    public function getMethod(): string {
        return $this->method;
    }

    /**
     * Check if defuse/php-encryption is available
     *
     * @return bool
     */
    private function defuseAvailable(): bool {
        return class_exists( Crypto::class );
    }

    /**
     * Check if sodium extension is available
     *
     * @return bool
     */
    private function sodiumAvailable(): bool {
        return extension_loaded( 'sodium' ) || extension_loaded( 'libsodium' );
    }

    /**
     * Generate a new encryption key
     *
     * Uses defuse's key generation if available, otherwise sodium.
     *
     * @return string Base64-encoded key for storage/configuration
     * @throws \RuntimeException If key generation fails
     *
     * @requirement SEC-002: Generate strong random keys
     */
    public static function generateKey(): string {
        if ( class_exists( Crypto::class ) ) {
            $key = Key::createNewRandomKey();
            return $key->saveToAsciiSafeString();
        }

        if ( extension_loaded( 'sodium' ) || extension_loaded( 'libsodium' ) ) {
            $key = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
            return base64_encode( $key );
        }

        throw new \RuntimeException(
            'Cannot generate key: install defuse/php-encryption or enable sodium extension.'
        );
    }

    /**
     * Load encryption key material from configuration
     *
     * Looks in order:
     * 1. wp-config.php constant: AUCTION_ENCRYPTION_KEY
     * 2. Environment variable: AUCTION_ENCRYPTION_KEY
     * 3. Falls back to WordPress auth keys (not recommended for production)
     *
     * @return string Key material (binary or base64-encoded)
     * @throws \RuntimeException If no key found
     *
     * @requirement SEC-002: Load encryption key from secure config
     */
    private function loadKeyMaterial(): string {
        // Try wp-config constant first
        if ( defined( 'AUCTION_ENCRYPTION_KEY' ) ) {
            $key = AUCTION_ENCRYPTION_KEY;
            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        // Try environment variable
        $env_key = getenv( 'AUCTION_ENCRYPTION_KEY' );
        if ( $env_key ) {
            return $env_key;
        }

        // Fallback: derive from WordPress auth keys (not ideal, but prevents errors)
        return AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY;
    }

    /**
     * Check if data appears to be encrypted
     *
     * Quick heuristic check for encryption markers.
     *
     * @param string $data Potentially encrypted data
     * @return bool
     */
    public static function isEncrypted( string $data ): bool {
        // Defuse format indicator
        if ( strpos( $data, 'DefuseCrypto' ) === 0 ) {
            return true;
        }

        // Base64 check (rough heuristic)
        $decoded = @base64_decode( $data, true );
        return false !== $decoded && strlen( $decoded ) >= 20;
    }
}
