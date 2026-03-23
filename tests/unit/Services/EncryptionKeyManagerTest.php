<?php

namespace Tests\Unit\Services;

use Tests\BaseUnitTest;
use Yith\Auctions\Services\Encryption\EncryptionKeyManager;

/**
 * EncryptionKeyManagerTest - Unit tests for encryption key lifecycle.
 *
 * @package Tests\Unit\Services
 * @requirement REQ-SEALED-BID-KEY-ROTATION-001: Key rotation and management
 *
 * Test Strategy:
 * - Mock WPDB for database operations
 * - Verify key creation and rotation
 * - Test expiration logic
 * - Verify backward compatibility with old keys
 * - Test cleanup of expired keys
 */
class EncryptionKeyManagerTest extends BaseUnitTest
{
    /**
     * @var EncryptionKeyManager Manager under test
     */
    private EncryptionKeyManager $manager;

    /**
     * @var \wpdb Mock database
     */
    private \wpdb $wpdb_mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb_mock = $this->createMock(\wpdb::class);
        $this->wpdb_mock->prefix = 'wp_';
        $this->manager = new EncryptionKeyManager($this->wpdb_mock);
    }

    /**
     * Test: Create new encryption key.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function testCreateNewKey_Success(): void
    {
        $key_id = 'uuid-1234';

        // Mock insert
        $this->wpdb_mock->expects($this->once())
            ->method('insert')
            ->willReturn(true);

        // Mock retrieve newly created key
        $this->wpdb_mock->expects($this->once())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->any())
            ->method('get_row')
            ->willReturn([
                'key_id' => $key_id,
                'algorithm' => 'AES-256-GCM',
                'rotation_status' => 'ACTIVE',
            ]);

        $result = $this->manager->createNewKey();

        $this->assertNotEmpty($result);
        $this->assertEquals('AES-256-GCM', $result['algorithm']);
    }

    /**
     * Test: Get active key.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function testGetActiveKey_Success(): void
    {
        $active_key = [
            'key_id' => 'active-uuid',
            'rotation_status' => 'ACTIVE',
            'algorithm' => 'AES-256-GCM',
        ];

        $this->wpdb_mock->expects($this->once())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->once())
            ->method('get_row')
            ->willReturn($active_key);

        $result = $this->manager->getActiveKey();

        $this->assertEquals('active-uuid', $result['key_id']);
        $this->assertEquals('ACTIVE', $result['rotation_status']);
    }

    /**
     * Test: Get active key throws when none found.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function testGetActiveKey_ThrowsWhenNotFound(): void
    {
        $this->wpdb_mock->expects($this->once())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->once())
            ->method('get_row')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active encryption key');

        $this->manager->getActiveKey();
    }

    /**
     * Test: Get decryption keys for backward compatibility.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-VERSIONING-001
     */
    public function testGetDecryptionKeys_Success(): void
    {
        $keys = [
            ['key_id' => 'active-uuid', 'rotation_status' => 'ACTIVE'],
            ['key_id' => 'rotated-uuid', 'rotation_status' => 'ROTATED'],
        ];

        $this->wpdb_mock->expects($this->once())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->once())
            ->method('get_results')
            ->willReturn($keys);

        $result = $this->manager->getDecryptionKeys();

        $this->assertCount(2, $result);
        $this->assertEquals('ACTIVE', $result[0]['rotation_status']);
    }

    /**
     * Test: Rotate current key.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function testRotateCurrentKey_Success(): void
    {
        $active_key = [
            'key_id' => 'old-key-uuid',
            'rotation_status' => 'ACTIVE',
        ];

        // Mock get active key
        $this->wpdb_mock->expects($this->any())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->any())
            ->method('get_row')
            ->willReturn($active_key);

        // Mock update to ROTATED
        $this->wpdb_mock->expects($this->once())
            ->method('update')
            ->willReturn(1);

        $result = $this->manager->rotateCurrentKey();

        $this->assertTrue($result);
    }

    /**
     * Test: Check if rotation is due.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function testIsRotationDue_NotDue(): void
    {
        $future_date = date('Y-m-d H:i:s', strtotime('+30 days'));
        $active_key = [
            'key_id' => 'uuid',
            'expires_at' => $future_date,
        ];

        $this->wpdb_mock->expects($this->once())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->once())
            ->method('get_row')
            ->willReturn($active_key);

        $result = $this->manager->isRotationDue();

        $this->assertFalse($result);
    }

    /**
     * Test: Check if rotation is due soon.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function testIsRotationDue_WithinThreshold(): void
    {
        $soon_date = date('Y-m-d H:i:s', strtotime('+5 days'));
        $active_key = [
            'key_id' => 'uuid',
            'expires_at' => $soon_date,
        ];

        $this->wpdb_mock->expects($this->once())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->once())
            ->method('get_row')
            ->willReturn($active_key);

        $result = $this->manager->isRotationDue();

        $this->assertTrue($result);
    }

    /**
     * Test: Cleanup expired keys.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function testCleanupExpiredKeys_Success(): void
    {
        $this->wpdb_mock->expects($this->once())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->once())
            ->method('query')
            ->willReturn(3);

        $result = $this->manager->cleanupExpiredKeys();

        $this->assertEquals(3, $result);
    }

    /**
     * Test: Get key statistics.
     *
     * @test
     * @requirement REQ-SEALED-BID-KEY-ROTATION-001
     */
    public function testGetKeyStatistics_Success(): void
    {
        $active_key = [
            'key_id' => 'uuid',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+45 days')),
        ];

        $this->wpdb_mock->expects($this->any())
            ->method('prepare')
            ->willReturnSelf();

        $this->wpdb_mock->expects($this->any())
            ->method('get_var')
            ->willReturnOnConsecutiveCalls(5, 1); // total, active

        $this->wpdb_mock->expects($this->any())
            ->method('get_row')
            ->willReturn($active_key);

        $result = $this->manager->getKeyStatistics();

        $this->assertEquals(5, $result['total_keys']);
        $this->assertEquals(1, $result['active_keys']);
        $this->assertFalse($result['rotation_due']);
        $this->assertGreaterThan(0, $result['days_until_rotation']);
    }
}
