<?php

namespace Yith\Auctions\Services\Encryption;

use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;

/**
 * EncryptionKeyManager - Manages encryption key lifecycle and rotation.
 *
 * Handles:
 * - Key storage and retrieval (never stored in plaintext in database)
 * - Key versioning for rotation
 * - Automatic key expiration
 * - Backward compatibility with old keys (for decryption of old data)
 *
 * @package Yith\Auctions\Services\Encryption
 * @requirement REQ-SEALED-BID-KEY-ROTATION-001: Key rotation every 90 days
 * @requirement REQ-SEALED-BID-KEY-VERSIONING-001: Key versioning for backward compatibility
 *
 * Architecture:
 *
 * Current Active Key (used for NEW encryption)
 *      ↓
 * Key History (old keys kept for 1 year, used only for DECRYPTION)
 *      ↓
 * Rotated Keys (marked as inactive, kept for audit trail)
 *
 * When key expires:
 * 1. Generate new key
 * 2. Mark old key as inactive
 * 3. Move old key to history
 * 4. New key becomes active
 * 5. Old key kept for 1 year before deletion
 */
class EncryptionKeyManager
{
    use LoggerTrait;
    use ValidationTrait;

    /**
     * @var \wpdb WordPress database object
     */
    private \wpdb $wpdb;

    /**
     * @var string Table for storing key metadata
     */
    private string $keys_table;

    /**
     * @var int Key rotation interval in days
     */
    private const KEY_ROTATION_DAYS = 90;

    /**
     * @var int Key retention after rotation in days
     */
    private const KEY_RETENTION_DAYS = 365;

    /**
     * Initialize key manager.
     *
     * @param \wpdb $wpdb WordPress database object
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
        $this->keys_table = $wpdb->prefix . 'wc_auction_encryption_keys';
    }

    /**
     * Get current active encryption key.
     *
     * Retrieves the key currently marked as active for NEW encryption operations.
     *
     * @return array|null Active key metadata
     * @throws \RuntimeException If no active key found
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function getActiveKey(): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT id, key_id, algorithm, created_at, expires_at 
             FROM {$this->keys_table}
             WHERE rotation_status = %s AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 1",
            'ACTIVE'
        );

        $key = $this->wpdb->get_row($query, ARRAY_A);

        if (!$key) {
            $this->logError('No active encryption key found');
            throw new \RuntimeException('No active encryption key configured');
        }

        $this->logDebug('Retrieved active key', ['key_id' => $key['key_id']]);

        return $key;
    }

    /**
     * Get key by ID.
     *
     * @param string $key_id Key identifier
     * @return array|null Key metadata or null if not found
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function getKeyById(string $key_id): ?array
    {
        $query = $this->wpdb->prepare(
            "SELECT id, key_id, algorithm, rotation_status, created_at, expires_at
             FROM {$this->keys_table}
             WHERE key_id = %s",
            $key_id
        );

        return $this->wpdb->get_row($query, ARRAY_A);
    }

    /**
     * Get all active and recently-expired keys (for backward compatibility).
     *
     * Returns keys that can still be used for DECRYPTION of old data.
     *
     * @return array Array of usable keys
     * @requirement REQ-SEALED-BID-KEY-VERSIONING-001
     */
    public function getDecryptionKeys(): array
    {
        $cutoff_date = date(
            'Y-m-d H:i:s',
            strtotime('-' . self::KEY_RETENTION_DAYS . ' days')
        );

        $query = $this->wpdb->prepare(
            "SELECT id, key_id, algorithm, rotation_status, created_at, expires_at
             FROM {$this->keys_table}
             WHERE rotation_status IN (%s, %s) AND created_at > %s
             ORDER BY created_at DESC",
            'ACTIVE',
            'ROTATED',
            $cutoff_date
        );

        $keys = $this->wpdb->get_results($query, ARRAY_A);

        $this->logDebug(
            'Retrieved decryption keys',
            ['count' => count($keys), 'retention_days' => self::KEY_RETENTION_DAYS]
        );

        return $keys ?? [];
    }

    /**
     * Create new encryption key.
     *
     * Generates a new key and marks it as active.
     * Sets expiration date for automatic rotation.
     *
     * @return array New key metadata
     * @throws \RuntimeException If key creation fails
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function createNewKey(): array
    {
        // Mark old key as rotated
        $this->rotateCurrentKey();

        // Generate new key
        $key_id = wp_generate_uuid4();
        $created_at = current_time('mysql');
        $expires_at = date('Y-m-d H:i:s', strtotime('+' . self::KEY_ROTATION_DAYS . ' days'));

        $insert_data = [
            'key_id'            => $key_id,
            'algorithm'         => 'AES-256-GCM',
            'rotation_status'   => 'ACTIVE',
            'created_at'        => $created_at,
            'expires_at'        => $expires_at,
        ];

        $result = $this->wpdb->insert(
            $this->keys_table,
            $insert_data,
            ['%s', '%s', '%s', '%s', '%s']
        );

        if (!$result) {
            $this->logError('Failed to create key', ['error' => $this->wpdb->last_error]);
            throw new \RuntimeException('Failed to create encryption key');
        }

        $this->logInfo(
            'New encryption key created',
            [
                'key_id' => $key_id,
                'expires_at' => $expires_at,
                'rotation_days' => self::KEY_ROTATION_DAYS,
            ]
        );

        return $this->getKeyById($key_id);
    }

    /**
     * Rotate current key to inactive.
     *
     * Called when a new key is created. Marks the current active key
     * as rotated so it's only used for decryption of old data.
     *
     * @return bool Success
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function rotateCurrentKey(): bool
    {
        try {
            $active_key = $this->getActiveKey();
        } catch (\RuntimeException $e) {
            // No active key yet, this is first rotation
            return true;
        }

        $result = $this->wpdb->update(
            $this->keys_table,
            [
                'rotation_status' => 'ROTATED',
                'rotated_at'      => current_time('mysql'),
            ],
            ['key_id' => $active_key['key_id']],
            ['%s', '%s'],
            ['%s']
        );

        if ($result === false) {
            $this->logError('Failed to rotate key', ['error' => $this->wpdb->last_error]);
            return false;
        }

        $this->logInfo(
            'Key rotated',
            ['old_key_id' => $active_key['key_id']]
        );

        return true;
    }

    /**
     * Clean up expired keys.
     *
     * Removes keys older than retention period from database.
     * Called periodically (e.g., monthly) via scheduled job.
     *
     * @return int Number of keys deleted
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function cleanupExpiredKeys(): int
    {
        $cutoff_date = date(
            'Y-m-d H:i:s',
            strtotime('-' . self::KEY_RETENTION_DAYS . ' days')
        );

        $query = $this->wpdb->prepare(
            "DELETE FROM {$this->keys_table}
             WHERE rotation_status = %s AND created_at < %s",
            'ROTATED',
            $cutoff_date
        );

        $affected = $this->wpdb->query($query);

        if ($affected !== false && $affected > 0) {
            $this->logInfo(
                'Expired keys cleaned up',
                [
                    'deleted_count' => $affected,
                    'cutoff_date' => $cutoff_date,
                ]
            );
        }

        return $affected === false ? 0 : $affected;
    }

    /**
     * Check if key rotation is due.
     *
     * @return bool True if rotation needed
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function isRotationDue(): bool
    {
        try {
            $key = $this->getActiveKey();
        } catch (\RuntimeException $e) {
            return true; // No key = rotation needed
        }

        $expires_at = strtotime($key['expires_at']);
        $now = current_time_timestamp();

        // Consider rotation due if within 7 days of expiration
        $threshold = strtotime('+7 days', $now);

        return $now >= ($expires_at - (7 * 24 * 60 * 60));
    }

    /**
     * Get key statistics for monitoring.
     *
     * @return array Key statistics
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function getKeyStatistics(): array
    {
        $total_query = "SELECT COUNT(*) as total FROM {$this->keys_table}";
        $active_query = $this->wpdb->prepare(
            "SELECT COUNT(*) as active FROM {$this->keys_table} WHERE rotation_status = %s",
            'ACTIVE'
        );

        $total = $this->wpdb->get_var($total_query);
        $active = $this->wpdb->get_var($active_query);

        try {
            $active_key = $this->getActiveKey();
            $expiration_date = $active_key['expires_at'];
            $days_until_rotation = ceil(
                (strtotime($expiration_date) - current_time_timestamp()) / (24 * 60 * 60)
            );
        } catch (\RuntimeException $e) {
            $expiration_date = null;
            $days_until_rotation = 0;
        }

        return [
            'total_keys' => (int)$total,
            'active_keys' => (int)$active,
            'rotation_due' => $this->isRotationDue(),
            'active_key_expires' => $expiration_date,
            'days_until_rotation' => $days_until_rotation,
            'rotation_interval_days' => self::KEY_ROTATION_DAYS,
            'retention_days' => self::KEY_RETENTION_DAYS,
        ];
    }
}
