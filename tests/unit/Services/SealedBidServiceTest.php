<?php

namespace Tests\Unit\Services;

use Tests\BaseUnitTest;
use Yith\Auctions\Services\SealedBidding\SealedBidService;
use Yith\Auctions\Repository\SealedBidRepository;
use Yith\Auctions\Services\Encryption\EncryptionManager;
use Yith\Auctions\Services\Encryption\EncryptionKeyManager;

/**
 * SealedBidServiceTest - Unit tests for sealed bid orchestration.
 *
 * @package Tests\Unit\Services
 * @requirement REQ-SEALED-BID-SERVICE-001: Core orchestration tests
 * @requirement REQ-SEALED-BID-ENCRYPTION-001: Encryption integration
 *
 * Test Strategy:
 * - All dependencies mocked (repository, encryption, key manager)
 * - Focus on business logic and workflow coordination
 * - Verify proper error handling and logging
 * - Test state transitions and edge cases
 */
class SealedBidServiceTest extends BaseUnitTest
{
    /**
     * @var SealedBidService Service under test
     */
    private SealedBidService $service;

    /**
     * @var SealedBidRepository Mock repository
     */
    private SealedBidRepository $repository_mock;

    /**
     * @var EncryptionManager Mock encryption
     */
    private EncryptionManager $encryption_mock;

    /**
     * @var EncryptionKeyManager Mock key manager
     */
    private EncryptionKeyManager $key_manager_mock;

    /**
     * @var \wpdb Mock database
     */
    private \wpdb $wpdb_mock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository_mock = $this->createMock(SealedBidRepository::class);
        $this->encryption_mock = $this->createMock(EncryptionManager::class);
        $this->key_manager_mock = $this->createMock(EncryptionKeyManager::class);
        $this->wpdb_mock = $this->createMock(\wpdb::class);

        $this->service = new SealedBidService(
            $this->repository_mock,
            $this->encryption_mock,
            $this->key_manager_mock,
            $this->wpdb_mock
        );
    }

    /**
     * Test: Submit sealed bid successfully.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testSubmitSealedBid_Success(): void
    {
        $auction_id = 123;
        $user_id = 456;
        $bid_amount = '100.50';
        $sealed_bid_id = 'uuid-1234';

        // Mock encryption key retrieval
        $this->key_manager_mock->expects($this->once())
            ->method('getActiveKey')
            ->willReturn(['key_id' => 'key-uuid-1']);

        // Mock encryption
        $encrypted = "IV+ciphertext+tag";
        $this->encryption_mock->expects($this->once())
            ->method('encrypt')
            ->with($bid_amount)
            ->willReturn($encrypted);

        // Mock hash
        $bid_hash = hash('sha256', $bid_amount);
        $this->encryption_mock->expects($this->once())
            ->method('hashPlaintext')
            ->with($bid_amount)
            ->willReturn($bid_hash);

        // Mock no existing duplicate bid
        $this->repository_mock->expects($this->once())
            ->method('getUserSealedBid')
            ->with($auction_id, $user_id)
            ->willReturn(null);

        // Mock bid creation
        $this->repository_mock->expects($this->once())
            ->method('createSealedBid')
            ->with($auction_id, $user_id, $encrypted, $bid_hash, 'key-uuid-1', 'SUBMITTED')
            ->willReturn($sealed_bid_id);

        // Submit bid
        $result = $this->service->submitSealedBid($auction_id, $user_id, $bid_amount);

        $this->assertEquals($sealed_bid_id, $result);
    }

    /**
     * Test: Submit sealed bid fails with duplicate.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testSubmitSealedBid_DuplicateBidExccepts(): void
    {
        $auction_id = 123;
        $user_id = 456;
        $bid_amount = '100.50';

        // Mock existing active bid
        $this->repository_mock->expects($this->once())
            ->method('getUserSealedBid')
            ->with($auction_id, $user_id)
            ->willReturn(['status' => 'SUBMITTED']);

        // Expect exception
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('User already has active sealed bid');

        $this->service->submitSealedBid($auction_id, $user_id, $bid_amount);
    }

    /**
     * Test: Submit sealed bid fails with negative amount.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testSubmitSealedBid_NegativeAmountThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->submitSealedBid(123, 456, '-50.00');
    }

    /**
     * Test: Submit sealed bid fails with invalid decimal places.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testSubmitSealedBid_InvalidDecimalPlacesThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->submitSealedBid(123, 456, '100.999');
    }

    /**
     * Test: Get sealed bid status.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testGetSealedBidStatus_Success(): void
    {
        $sealed_bid_id = 'uuid-1234';
        $bid_data = [
            'sealed_bid_id' => $sealed_bid_id,
            'auction_id' => 123,
            'user_id' => 456,
            'status' => 'SUBMITTED',
            'submitted_at' => '2024-01-01 10:00:00',
            'revealed_at' => null,
        ];

        $this->repository_mock->expects($this->once())
            ->method('getSealedBidById')
            ->with($sealed_bid_id)
            ->willReturn($bid_data);

        $result = $this->service->getSealedBidStatus($sealed_bid_id);

        $this->assertEquals($sealed_bid_id, $result['sealed_bid_id']);
        $this->assertEquals('SUBMITTED', $result['status']);
    }

    /**
     * Test: Get sealed bid status returns null for missing bid.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testGetSealedBidStatus_ReturnsNull(): void
    {
        $this->repository_mock->expects($this->once())
            ->method('getSealedBidById')
            ->willReturn(null);

        $result = $this->service->getSealedBidStatus('nonexistent-uuid');

        $this->assertNull($result);
    }

    /**
     * Test: Reveal all bids successfully.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testRevealAllBids_Success(): void
    {
        $auction_id = 123;
        $bid1_data = [
            'sealed_bid_id' => 'bid-1',
            'auction_id' => $auction_id,
            'user_id' => 456,
            'encrypted_bid' => 'encrypted-100',
            'bid_hash' => hash('sha256', '100.50'),
            'status' => 'SUBMITTED',
        ];

        // Mock get ready for reveal
        $this->repository_mock->expects($this->once())
            ->method('getReadyForReveal')
            ->with($auction_id)
            ->willReturn([$bid1_data]);

        // Mock transaction begin/commit
        $this->wpdb_mock->expects($this->any())
            ->method('query')
            ->willReturn(true);

        // Mock decryption
        $this->encryption_mock->expects($this->once())
            ->method('decrypt')
            ->with('encrypted-100')
            ->willReturn('100.50');

        // Mock hash comparison
        $plaintext_hash = hash('sha256', '100.50');
        $this->encryption_mock->expects($this->any())
            ->method('hashPlaintext')
            ->with('100.50')
            ->willReturn($plaintext_hash);

        // Mock bid status update
        $this->repository_mock->expects($this->once())
            ->method('updateBidStatus')
            ->with('bid-1', 'REVEALED', $plaintext_hash)
            ->willReturn(true);

        // Mock history record
        $this->repository_mock->expects($this->once())
            ->method('recordHistory')
            ->willReturn(true);

        // Mock statistics
        $this->repository_mock->expects($this->once())
            ->method('getAuctionStatistics')
            ->willReturn(['total' => 1, 'REVEALED' => 1]);

        $result = $this->service->revealAllBids($auction_id);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['revealed_bids']);
        $this->assertEmpty($result['failed_bids']);
    }

    /**
     * Test: Reveal all bids handles decryption failure.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testRevealAllBids_DecryptionFailure(): void
    {
        $auction_id = 123;
        $bid_data = [
            'sealed_bid_id' => 'bid-1',
            'auction_id' => $auction_id,
            'user_id' => 456,
            'encrypted_bid' => 'corrupted-data',
            'bid_hash' => hash('sha256', '100.50'),
        ];

        $this->repository_mock->expects($this->once())
            ->method('getReadyForReveal')
            ->willReturn([$bid_data]);

        // Mock transaction
        $this->wpdb_mock->expects($this->any())
            ->method('query')
            ->willReturn(true);

        // Mock failed decryption (tampering)
        $this->encryption_mock->expects($this->once())
            ->method('decrypt')
            ->willReturn(false);

        // Mock rejection recording
        $this->repository_mock->expects($this->once())
            ->method('updateBidStatus')
            ->with('bid-1', 'REJECTED')
            ->willReturn(true);

        $this->repository_mock->expects($this->once())
            ->method('recordHistory')
            ->willReturn(true);

        $this->repository_mock->expects($this->once())
            ->method('getAuctionStatistics')
            ->willReturn(['total' => 1, 'REJECTED' => 1]);

        $result = $this->service->revealAllBids($auction_id);

        $this->assertFalse($result['success']);
        $this->assertEmpty($result['revealed_bids']);
        $this->assertCount(1, $result['failed_bids']);
    }

    /**
     * Test: Get sealed bid history.
     *
     * @test
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001
     */
    public function testGetSealedBidHistory_Success(): void
    {
        $sealed_bid_id = 'bid-1';
        $history = [
            ['event_type' => 'SUBMITTED', 'description' => 'Bid submitted'],
            ['event_type' => 'REVEALED', 'description' => 'Bid revealed'],
        ];

        $this->repository_mock->expects($this->once())
            ->method('getHistory')
            ->with($sealed_bid_id)
            ->willReturn($history);

        $result = $this->service->getSealedBidHistory($sealed_bid_id);

        $this->assertCount(2, $result);
        $this->assertEquals('SUBMITTED', $result[0]['event_type']);
    }

    /**
     * Test: Check key rotation needed.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testCheckKeyRotation_RotationNeeded(): void
    {
        $this->key_manager_mock->expects($this->once())
            ->method('isRotationDue')
            ->willReturn(true);

        $new_key = ['key_id' => 'new-key-uuid'];
        $this->key_manager_mock->expects($this->once())
            ->method('createNewKey')
            ->willReturn($new_key);

        $result = $this->service->checkKeyRotation();

        $this->assertTrue($result);
    }

    /**
     * Test: Check key rotation not needed.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testCheckKeyRotation_NotNeeded(): void
    {
        $this->key_manager_mock->expects($this->once())
            ->method('isRotationDue')
            ->willReturn(false);

        $result = $this->service->checkKeyRotation();

        $this->assertFalse($result);
    }

    /**
     * Test: Get key statistics.
     *
     * @test
     * @requirement REQ-SEALED-BID-SERVICE-001
     */
    public function testGetKeyStatistics_Success(): void
    {
        $stats = [
            'total_keys' => 3,
            'active_keys' => 1,
            'rotation_due' => false,
        ];

        $this->key_manager_mock->expects($this->once())
            ->method('getKeyStatistics')
            ->willReturn($stats);

        $result = $this->service->getKeyStatistics();

        $this->assertEquals(3, $result['total_keys']);
        $this->assertFalse($result['rotation_due']);
    }
}
