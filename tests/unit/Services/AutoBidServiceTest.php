<?php

namespace Yith\Auctions\Tests\Unit\Services\AutoBidding;

use Yith\Auctions\Tests\BaseUnitTest;
use Yith\Auctions\Services\AutoBidding\AutoBidService;
use Yith\Auctions\Services\AutoBidding\ProxyBiddingEngine;
use Yith\Auctions\Repository\AutoBidRepository;
use Yith\Auctions\Services\BidQueue;
use Yith\Auctions\ValueObjects\AutoBidStatus;

/**
 * AutoBidServiceTest - Unit tests for AutoBidService.
 *
 * Tests the main auto-bidding service orchestration.
 * Uses mocks for dependencies (repository, bid queue, engine).
 *
 * @package Yith\Auctions\Tests\Unit\Services\AutoBidding
 * @requirement REQ-AUTO-BID-SERVICE-001: Auto-bid service
 */
class AutoBidServiceTest extends BaseUnitTest
{
    /**
     * @var AutoBidService Service instance
     */
    private AutoBidService $service;

    /**
     * @var AutoBidRepository Repository mock
     */
    private \Mockery\MockInterface $repository;

    /**
     * @var BidQueue Bid queue mock
     */
    private \Mockery\MockInterface $bid_queue;

    /**
     * @var ProxyBiddingEngine Engine mock
     */
    private \Mockery\MockInterface $engine;

    /**
     * Test setup.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->mock(AutoBidRepository::class);
        $this->bid_queue = $this->mock(BidQueue::class);
        $this->engine = $this->mock(ProxyBiddingEngine::class);

        $increment_calc = function($bid) {
            if ($bid < 1.00) return 0.05;
            if ($bid < 5.00) return 0.25;
            return 1.00;
        };

        $this->service = new AutoBidService(
            $this->repository->asUndeclared(),
            $this->bid_queue->asUndeclared(),
            $this->engine->asUndeclared(),
            $increment_calc
        );
    }

    /**
     * Test: Set auto-bid successfully.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testSetAutoBid_Success()
    {
        // Mock no existing auto-bid
        $this->repository
            ->shouldReceive('getActiveForAuctionUser')
            ->with(123, 456)
            ->andReturn(null);

        // Mock create
        $expected_id = 'uuid-12345';
        $this->repository
            ->shouldReceive('create')
            ->withArgs(function($data) {
                return $data['auction_id'] === 123
                    && $data['user_id'] === 456
                    && $data['maximum_bid'] === 100.00;
            })
            ->andReturn($expected_id);

        // Mock history recording
        $this->repository
            ->shouldReceive('recordHistory')
            ->once();

        $result = $this->service->setAutoBid(123, 456, 100.00);

        $this->assertEquals($expected_id, $result);
    }

    /**
     * Test: Cannot set duplicate auto-bid.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testSetAutoBid_DuplicateFails()
    {
        // Mock existing auto-bid
        $this->repository
            ->shouldReceive('getActiveForAuctionUser')
            ->with(123, 456)
            ->andReturn(['auto_bid_id' => 'existing']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->setAutoBid(123, 456, 100.00);
    }

    /**
     * Test: Cancel auto-bid successfully.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testCancelAutoBid_Success()
    {
        $auto_bid_id = 'uuid-12345';

        // Mock get
        $this->repository
            ->shouldReceive('getById')
            ->with($auto_bid_id)
            ->andReturn([
                'auto_bid_id'   => $auto_bid_id,
                'status'        => AutoBidStatus::ACTIVE,
                'auction_id'    => 123,
                'user_id'       => 456,
            ]);

        // Mock update
        $this->repository
            ->shouldReceive('update')
            ->withArgs(function($id, $data) {
                return $id === 'uuid-12345'
                    && $data['status'] === AutoBidStatus::CANCELLED;
            });

        // Mock history
        $this->repository
            ->shouldReceive('recordHistory')
            ->once();

        $result = $this->service->cancelAutoBid($auto_bid_id);

        $this->assertTrue($result);
    }

    /**
     * Test: Cannot cancel terminal auto-bid.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testCancelAutoBid_CannotCancelTerminal()
    {
        $auto_bid_id = 'uuid-12345';

        $this->repository
            ->shouldReceive('getById')
            ->with($auto_bid_id)
            ->andReturn([
                'status' => AutoBidStatus::COMPLETED, // Terminal
            ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->cancelAutoBid($auto_bid_id);
    }

    /**
     * Test: Update maximum successfully.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testUpdateMaximum_Success()
    {
        $auto_bid_id = 'uuid-12345';

        $this->repository
            ->shouldReceive('getById')
            ->with($auto_bid_id)
            ->andReturn([
                'auto_bid_id'   => $auto_bid_id,
                'maximum_bid'   => '100.00',
                'auction_id'    => 123,
                'user_id'       => 456,
            ]);

        $this->repository
            ->shouldReceive('update')
            ->withArgs(function($id, $data) {
                return $data['maximum_bid'] === 150.00;
            });

        $this->repository
            ->shouldReceive('recordHistory')
            ->once();

        $result = $this->service->updateMaximum($auto_bid_id, 150.00);

        $this->assertTrue($result);
    }

    /**
     * Test: Cannot decrease maximum.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testUpdateMaximum_CannotDecrease()
    {
        $auto_bid_id = 'uuid-12345';

        $this->repository
            ->shouldReceive('getById')
            ->with($auto_bid_id)
            ->andReturn([
                'maximum_bid' => '100.00',
            ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateMaximum($auto_bid_id, 50.00);
    }

    /**
     * Test: Get auto-bid.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testGetAutoBid()
    {
        $auto_bid_id = 'uuid-12345';
        $auto_bid_data = [
            'auto_bid_id'   => $auto_bid_id,
            'status'        => 'ACTIVE',
        ];

        $this->repository
            ->shouldReceive('getById')
            ->with($auto_bid_id)
            ->andReturn($auto_bid_data);

        $result = $this->service->getAutoBid($auto_bid_id);

        $this->assertEquals($auto_bid_data, $result);
    }

    /**
     * Test: Get user auto-bids.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testGetUserAutoBids()
    {
        $user_id = 456;
        $auto_bids = [
            ['auto_bid_id' => 'uuid-1', 'status' => 'ACTIVE'],
            ['auto_bid_id' => 'uuid-2', 'status' => 'COMPLETED'],
        ];

        $this->repository
            ->shouldReceive('getForUser')
            ->with($user_id, [])
            ->andReturn($auto_bids);

        $result = $this->service->getUserAutoBids($user_id);

        $this->assertCount(2, $result);
    }

    /**
     * Test: Get history.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testGetHistory()
    {
        $auto_bid_id = 'uuid-12345';
        $history_events = [
            ['event_type' => 'AUTO_BID_CREATED'],
            ['event_type' => 'PROXY_BID_QUEUED'],
        ];

        $this->repository
            ->shouldReceive('getHistory')
            ->with($auto_bid_id, 50)
            ->andReturn($history_events);

        $result = $this->service->getHistory($auto_bid_id);

        $this->assertCount(2, $result);
    }

    /**
     * Test: Process outbid - auto-bid should counter.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testProcessOutbid_PlacesCounterBid()
    {
        $auction_id = 123;
        $auto_bid = [
            'auto_bid_id'   => 'uuid-12345',
            'user_id'       => 1,
            'auction_id'    => 123,
            'maximum_bid'   => '100.00',
            'status'        => 'ACTIVE',
        ];
        $new_bid = [
            'user_id'       => 2,
            'amount'        => '50.00',
        ];

        // Mock get active auto-bids
        $this->repository
            ->shouldReceive('getActiveForAuction')
            ->with($auction_id)
            ->andReturn([$auto_bid]);

        // Mock engine should place bid check
        $this->engine
            ->shouldReceive('shouldPlaceCounterBid')
            ->andReturn(true);

        // Mock engine calculate proxy
        $this->engine
            ->shouldReceive('calculateProxyBid')
            ->andReturn(\Mockery::mock()->asUndeclared());

        // Mock bid queue
        $this->bid_queue
            ->shouldReceive('enqueue')
            ->andReturn('job-id-123');

        // Mock repository update and history
        $this->repository
            ->shouldReceive('update')
            ->twice();
        $this->repository
            ->shouldReceive('recordHistory')
            ->once();

        $result = $this->service->processOutbid($auction_id, $new_bid, 'ACTIVE');

        $this->assertTrue($result);
    }

    /**
     * Test: Process outbid - no active auto-bids.
     *
     * @test
     * @requirement REQ-AUTO-BID-SERVICE-001
     */
    public function testProcessOutbid_NoActiveBids()
    {
        $auction_id = 123;
        $new_bid = ['user_id' => 2, 'amount' => '50.00'];

        $this->repository
            ->shouldReceive('getActiveForAuction')
            ->with($auction_id)
            ->andReturn([]);

        $result = $this->service->processOutbid($auction_id, $new_bid, 'ACTIVE');

        $this->assertFalse($result);
    }
}
