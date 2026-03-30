<?php
/**
 * Encryption Service - AES-256-CBC encryption/decryption for sensitive data
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-045: Provide AES-256-CBC encryption for payout methods
 */

namespace WC\Auction\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * EncryptionService - Handles symmetric encryption/decryption
 *
 * Uses OpenSSL AES-256-CBC cipher with random IVs for each encryption.
 * Supports key generation and retrieval from environment/config.
 *
 * @requirement REQ-4D-045: Encrypt payout method data before storage
 * @requirement SEC-001: Use AES-256-CBC encryption with secure key management
 */
class EncryptionService {

    /**
     * Cipher algorithm
     */
    const CIPHER = 'aes-256-cbc';

    /**
     * Key byte length (256-bit = 32 bytes)
     */
    const KEY_LENGTH = 32;

    /**
     * IV byte length (128-bit = 16 bytes for CBC mode)
     */
    const IV_LENGTH = 16;

    /**
     * Encryption key (binary)
     *
     * @var string
     */
    private $key;

    /**
     * Constructor
     *
     * @param string|null $key Encryption key (if null, uses getEncryptionKey())
     */
    public function __construct( ?string $key = null ) {
        $this->key = $key ?? $this->getEncryptionKey();

        if ( strlen( $this->key ) !== self::KEY_LENGTH ) {
            throw new \InvalidArgumentException(
                sprintf( 'Encryption key must be %d bytes, got %d', self::KEY_LENGTH, strlen( $this->key ) )
            );
        }
    }

    /**
     * Encrypt data with AES-256-CBC
     *
     * Generates a random IV, encrypts the data, and returns base64-encoded
     * concatenation of IV + ciphertext.
     *
     * @param string $data Plaintext data to encrypt
     * @return string Base64-encoded IV + ciphertext
     * @throws \RuntimeException If encryption fails
     *
     * @requirement SEC-001: Encrypt sensitive data with random IV
     */
    public function encrypt( string $data ): string {
        // Generate random IV
        $iv = openssl_random_pseudo_bytes( self::IV_LENGTH, $strong );
        if ( ! $strong ) {
            throw new \RuntimeException( 'Failed to generate cryptographically strong IV' );
        }

        // Encrypt the data
        $encrypted = openssl_encrypt( $data, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv );
        if ( false === $encrypted ) {
            throw new \RuntimeException( 'Encryption failed: ' . openssl_error_string() );
        }

        // Combine IV + ciphertext and encode
        $combined = $iv . $encrypted;
        return base64_encode( $combined );
    }

    /**
     * Decrypt data encrypted with encrypt()
     *
     * Extracts IV from base64-encoded data, then decrypts the ciphertext.
     *
     * @param string $encrypted Base64-encoded IV + ciphertext
     * @return string Plaintext data
     * @throws \RuntimeException If decryption fails
     *
     * @requirement SEC-001: Decrypt stored data safely
     */
    public function decrypt( string $encrypted ): string {
        // Decode from base64
        $combined = base64_decode( $encrypted, true );
        if ( false === $combined ) {
            throw new \RuntimeException( 'Failed to decode base64 encrypted data' );
        }

        // Extract IV and ciphertext
        if ( strlen( $combined ) < self::IV_LENGTH ) {
            throw new \RuntimeException( 'Invalid encrypted data: too short' );
        }

        $iv        = substr( $combined, 0, self::IV_LENGTH );
        $ciphertext = substr( $combined, self::IV_LENGTH );

        // Decrypt
        $decrypted = openssl_decrypt( $ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv );
        if ( false === $decrypted ) {
            throw new \RuntimeException( 'Decryption failed: invalid key or corrupted data' );
        }

        return $decrypted;
    }

    /**
     * Generate a random encryption key
     *
     * Returns a cryptographically strong random 32-byte key suitable for AES-256.
     *
     * @return string 32-byte binary key
     * @throws \RuntimeException If generation fails
     *
     * @requirement SEC-001: Generate strong random keys
     */
    public static function generateKey(): string {
        $key = openssl_random_pseudo_bytes( self::KEY_LENGTH, $strong );
        if ( ! $strong ) {
            throw new \RuntimeException( 'Failed to generate cryptographically strong key' );
        }
        return $key;
    }

    /**
     * Get encryption key from configuration
     *
     * Looks in order:
     * 1. wp-config.php constant: AUCTION_ENCRYPTION_KEY
     * 2. Environment variable: AUCTION_ENCRYPTION_KEY
     * 3. Falls back to WordPress auth keys (concatenated and hashed)
     *
     * @return string 32-byte binary key
     * @throws \RuntimeException If no key found or invalid
     *
     * @requirement SEC-002: Load encryption key from secure config
     */
    private function getEncryptionKey(): string {
        // Try wp-config constant first
        if ( defined( 'AUCTION_ENCRYPTION_KEY' ) ) {
            $key = AUCTION_ENCRYPTION_KEY;
            // If it's hex string, convert to binary
            if ( ctype_xdigit( $key ) && strlen( $key ) === 64 ) {
                return hex2bin( $key );
            }
            // Raw binary key
            if ( strlen( $key ) === self::KEY_LENGTH ) {
                return $key;
            }
        }

        // Try environment variable
        $env_key = getenv( 'AUCTION_ENCRYPTION_KEY' );
        if ( $env_key ) {
            if ( ctype_xdigit( $env_key ) && strlen( $env_key ) === 64 ) {
                return hex2bin( $env_key );
            }
            if ( strlen( $env_key ) === self::KEY_LENGTH ) {
                return $env_key;
            }
        }

        // Fallback: derive from WordPress auth keys (not ideal for production)
        $fallback = hash(
            'sha256',
            AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY,
            true
        );
        if ( strlen( $fallback ) === self::KEY_LENGTH ) {
            return $fallback;
        }

        throw new \RuntimeException(
            'No encryption key configured. Set AUCTION_ENCRYPTION_KEY in wp-config.php or environment.'
        );
    }

    /**
     * Check if data appears to be encrypted
     *
     * Attempts to base64-decode and check for valid format.
     * Not bulletproof, but useful for validation.
     *
     * @param string $data Potentially encrypted data
     * @return bool
     */
    public static function isEncrypted( string $data ): bool {
        $decoded = @base64_decode( $data, true );
        return false !== $decoded && strlen( $decoded ) >= self::IV_LENGTH;
    }
}
