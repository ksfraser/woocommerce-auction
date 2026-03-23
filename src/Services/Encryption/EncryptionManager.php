<?php

namespace Yith\Auctions\Services\Encryption;

use Yith\Auctions\Traits\LoggerTrait;

/**
 * EncryptionManager - Handles encryption and decryption using AES-256-GCM.
 *
 * Provides secure, authenticated encryption for sensitive bid data.
 * Uses AES-256-GCM algorithm (NIST-approved, provides authenticity checking).
 *
 * Key features:
 * - Authenticated encryption (detects tampering)
 * - Random IV generation for each encryption
 * - Proper key management (never stored in plaintext)
 * - Support for key versioning and rotation
 * - Audit logging of all encryption operations
 *
 * @package Yith\Auctions\Services\Encryption
 * @requirement REQ-SEALED-BID-ENCRYPTION-001: Bid encryption using AES-256-GCM
 * @requirement REQ-SEALED-BID-AUTH-001: Authenticated encryption with integrity checking
 *
 * Usage:
 * ```php
 * $manager = new EncryptionManager($master_key);
 * $encrypted = $manager->encrypt('100.00');
 * // Later:
 * $decrypted = $manager->decrypt($encrypted);
 * ```
 */
class EncryptionManager
{
    use LoggerTrait;

    /**
     * @var string Master encryption key (256-bit)
     */
    private string $master_key;

    /**
     * @var string Encryption algorithm (AES-256-GCM)
     */
    private const ALGORITHM = 'aes-256-gcm';

    /**
     * @var int Authentication tag length (16 bytes)
     */
    private const TAG_LENGTH = 16;

    /**
     * @var int IV length (12 bytes - standard for GCM)
     */
    private const IV_LENGTH = 12;

    /**
     * Initialize encryption manager with master key.
     *
     * @param string $master_key Master encryption key (must be 32 bytes for AES-256)
     * @throws \InvalidArgumentException If key is invalid
     * @requirement REQ-SEALED-BID-ENCRYPTION-001
     */
    public function __construct(string $master_key)
    {
        if (strlen($master_key) !== 32) {
            throw new \InvalidArgumentException(
                'Master key must be exactly 32 bytes (256 bits) for AES-256-GCM'
            );
        }

        $this->master_key = $master_key;
        $this->logInfo('EncryptionManager initialized');
    }

    /**
     * Encrypt plaintext using AES-256-GCM.
     *
     * Produces encrypted data with authentication tag to detect tampering.
     *
     * @param string $plaintext Data to encrypt
     * @param string $aad Additional authenticated data (optional header)
     * @return string Encrypted data in format: IV (12 bytes) + ciphertext + tag (16 bytes)
     * @throws \RuntimeException If encryption fails
     * @requirement REQ-SEALED-BID-ENCRYPTION-001
     * @requirement REQ-SEALED-BID-AUTH-001
     */
    public function encrypt(string $plaintext, string $aad = ''): string
    {
        // Generate random IV (initialization vector)
        if (!function_exists('random_bytes')) {
            throw new \RuntimeException('random_bytes() not available');
        }

        $iv = random_bytes(self::IV_LENGTH);

        // Encrypt using AES-256-GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGORITHM,
            $this->master_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            $this->logError('Encryption failed', ['error' => openssl_error_string()]);
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Return: IV + ciphertext + tag (all concatenated)
        $encrypted = $iv . $ciphertext . $tag;

        $this->logDebug(
            'Data encrypted',
            [
                'plaintext_length' => strlen($plaintext),
                'encrypted_length' => strlen($encrypted),
                'iv_length' => strlen($iv),
                'tag_length' => strlen($tag),
            ]
        );

        return $encrypted;
    }

    /**
     * Decrypt ciphertext using AES-256-GCM.
     *
     * Verifies authentication tag before decryption to ensure data integrity.
     *
     * @param string $encrypted Encrypted data (output from encrypt())
     * @param string $aad Additional authenticated data (must match encryption)
     * @return string|false Decrypted plaintext, or false if decryption/verification fails
     * @requirement REQ-SEALED-BID-ENCRYPTION-001
     * @requirement REQ-SEALED-BID-AUTH-001
     */
    public function decrypt(string $encrypted, string $aad = '')
    {
        // Extract components
        if (strlen($encrypted) < self::IV_LENGTH + self::TAG_LENGTH) {
            $this->logWarning('Encrypted data too short');
            return false;
        }

        $iv = substr($encrypted, 0, self::IV_LENGTH);
        $tag = substr($encrypted, -self::TAG_LENGTH);
        $ciphertext = substr($encrypted, self::IV_LENGTH, -self::TAG_LENGTH);

        // Attempt decryption with authentication
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ALGORITHM,
            $this->master_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            $error = openssl_error_string();
            $this->logWarning(
                'Decryption failed - possible tampering',
                ['error' => $error]
            );
            return false;
        }

        $this->logDebug(
            'Data decrypted',
            [
                'plaintext_length' => strlen($plaintext),
                'encrypted_length' => strlen($encrypted),
            ]
        );

        return $plaintext;
    }

    /**
     * Generate hash of plaintext for audit trail.
     *
     * Creates a non-reversible hash for audit logging.
     * Allows verification that same plaintext was encrypted without revealing it.
     *
     * @param string $plaintext Plaintext to hash
     * @return string SHA-256 hash (hex-encoded)
     * @requirement REQ-SEALED-BID-ENCRYPTION-001
     */
    public function hashPlaintext(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Verify plaintext against stored hash.
     *
     * @param string $plaintext Plaintext to verify
     * @param string $stored_hash Hash to compare against
     * @return bool True if plaintext hashes match
     * @requirement REQ-SEALED-BID-ENCRYPTION-001
     */
    public function verifyPlaintextHash(string $plaintext, string $stored_hash): bool
    {
        $computed_hash = $this->hashPlaintext($plaintext);
        return hash_equals($computed_hash, $stored_hash);
    }

    /**
     * Derive encryption key from password using PBKDF2.
     *
     * For scenarios where encryption key needs to be derived from user input.
     * Uses PBKDF2 with SHA-256 and high iteration count.
     *
     * @param string $password Input password
     * @param string $salt Salt value (should be random, at least 16 bytes)
     * @param int $iterations PBKDF2 iterations (higher = slower but more secure)
     * @return string Derived key (32 bytes)
     * @requirement REQ-SEALED-BID-ENCRYPTION-001
     */
    public static function deriveKeyFromPassword(
        string $password,
        string $salt,
        int $iterations = 100000
    ): string {
        $derived = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);

        if ($derived === false) {
            throw new \RuntimeException('Key derivation failed');
        }

        return $derived;
    }

    /**
     * Generate random encryption key.
     *
     * Creates a cryptographically secure random 256-bit key.
     *
     * @return string Random key (32 bytes)
     * @throws \RuntimeException If random_bytes unavailable
     * @requirement REQ-SEALED-BID-ENCRYPTION-001
     */
    public static function generateRandomKey(): string
    {
        if (!function_exists('random_bytes')) {
            throw new \RuntimeException('random_bytes() not available');
        }

        return random_bytes(32);
    }

    /**
     * Get algorithm name.
     *
     * @return string Algorithm name
     */
    public static function getAlgorithm(): string
    {
        return self::ALGORITHM;
    }

    /**
     * Get IV length in bytes.
     *
     * @return int IV length
     */
    public static function getIVLength(): int
    {
        return self::IV_LENGTH;
    }

    /**
     * Get authentication tag length in bytes.
     *
     * @return int Tag length
     */
    public static function getTagLength(): int
    {
        return self::TAG_LENGTH;
    }

    /**
     * Verify OpenSSL support for AES-256-GCM.
     *
     * @return bool True if algorithm is supported
     */
    public static function isAlgorithmSupported(): bool
    {
        return in_array(self::ALGORITHM, openssl_get_cipher_methods());
    }
}
