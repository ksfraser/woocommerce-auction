<?php
/**
 * PayoutMethodManagerTest - Unit tests for PayoutMethodManager service
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    4.0.0
 * @requirement REQ-4D-045: Test payout method management with encryption
 */

namespace WC\Auction\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WC\Auction\Services\PayoutMethodManager;
use WC\Auction\Services\EncryptionService;
use WC\Auction\Validators\PayoutMethodValidator;
use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Repositories\PayoutMethodRepository;
use WC\Auction\Events\EventPublisher;
use WC\Auction\Exceptions\ValidationException;
use WC\Auction\Exceptions\EntityNotFoundException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test suite for PayoutMethodManager
 *
 * @requirement REQ-4D-045: Test encryption, CRUD, and validation
 */
class PayoutMethodManagerTest extends TestCase {

    /**
     * PayoutMethodRepository mock
     *
     * @var PayoutMethodRepository|MockObject
     */
    private $repo_mock;

    /**
     * EncryptionService mock
     *
     * @var EncryptionService|MockObject
     */
    private $encryption_mock;

    /**
     * PayoutMethodValidator mock
     *
     * @var PayoutMethodValidator|MockObject
     */
    private $validator_mock;

    /**
     * EventPublisher mock
     *
     * @var EventPublisher|MockObject
     */
    private $events_mock;

    /**
     * Logger mock
     *
     * @var MockObject
     */
    private $logger_mock;

    /**
     * PayoutMethodManager instance
     *
     * @var PayoutMethodManager
     */
    private $manager;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();

        $this->repo_mock       = $this->createMock( PayoutMethodRepository::class );
        $this->encryption_mock = $this->createMock( EncryptionService::class );
        $this->validator_mock  = $this->createMock( PayoutMethodValidator::class );
        $this->events_mock     = $this->createMock( EventPublisher::class );
        $this->logger_mock     = $this->createMock( \WC\Auction\Services\LoggerService::class );

        $this->manager = new PayoutMethodManager(
            $this->repo_mock,
            $this->encryption_mock,
            $this->validator_mock,
            $this->events_mock,
            $this->logger_mock
        );
    }

    /**
     * Test manager can be instantiated
     *
     * @test
     */
    public function test_manager_can_be_instantiated(): void {
        $this->assertInstanceOf( PayoutMethodManager::class, $this->manager );
    }

    /**
     * Test add payout method encrypts data
     *
     * @test
     */
    public function test_add_payout_method_encrypts_data(): void {
        $seller_id = 123;
        $processor = 'square';
        $method_data = [
            'location_id'  => 'sq0a123456789',
            'account_id'   => 'acct_12345',
            'access_token' => 'sq_token_xyz',
        ];
        $encrypted = 'base64_encrypted_data';

        $this->validator_mock->expects( $this->once() )
            ->method( 'validate' );

        $this->repo_mock->expects( $this->once() )
            ->method( 'findBySeller' )
            ->with( $seller_id )
            ->willReturn( [] ); // No existing methods

        $this->encryption_mock->expects( $this->once() )
            ->method( 'encrypt' )
            ->with( $this->stringContainsString( 'location_id' ) )
            ->willReturn( $encrypted );

        $created = $this->createMockPayoutMethod( 1, $seller_id, $processor, $encrypted );
        $this->repo_mock->expects( $this->once() )
            ->method( 'create' )
            ->willReturn( $created );

        $this->events_mock->expects( $this->once() )
            ->method( 'publish' )
            ->with( 'payout_method.added' );

        $result = $this->manager->addPayoutMethod( $seller_id, $processor, $method_data );

        $this->assertInstanceOf( SellerPayoutMethod::class, $result );
        $this->assertEquals( $seller_id, $result->getSellerId() );
    }

    /**
     * Test add payout method sets as primary if no existing methods
     *
     * @test
     */
    public function test_add_payout_method_sets_as_primary_when_no_existing(): void {
        $seller_id = 456;
        $processor = 'paypal';
        $method_data = [
            'account_email' => 'seller@example.com',
            'merchant_id'   => 'MERCHANT123456',
        ];

        $this->validator_mock->expects( $this->once() )->method( 'validate' );
        $this->repo_mock->expects( $this->once() )
            ->method( 'findBySeller' )
            ->willReturn( [] );

        $this->encryption_mock->expects( $this->once() )
            ->method( 'encrypt' )
            ->willReturn( 'encrypted' );

        $created = $this->createMockPayoutMethod( 2, $seller_id, $processor, 'encrypted' );
        $this->repo_mock->expects( $this->once() )
            ->method( 'create' )
            ->with( $this->anything(), true ); // Second param = is_primary

        $this->events_mock->expects( $this->once() )->method( 'publish' );

        $this->manager->addPayoutMethod( $seller_id, $processor, $method_data );

        $this->assertTrue( true );
    }

    /**
     * Test add payout method validation failure throws exception
     *
     * @test
     */
    public function test_add_payout_method_validation_failure(): void {
        $seller_id = 789;
        $processor = 'stripe';
        $method_data = [ 'connected_account_id' => 'invalid' ];

        $this->validator_mock->expects( $this->once() )
            ->method( 'validate' )
            ->willThrowException( new ValidationException( 'Invalid account ID' ) );

        $this->repo_mock->expects( $this->never() )->method( 'create' );

        $this->expectException( ValidationException::class );
        $this->manager->addPayoutMethod( $seller_id, $processor, $method_data );
    }

    /**
     * Test get payout method decrypts data
     *
     * @test
     */
    public function test_get_payout_method_decrypts_data(): void {
        $method_id = 5;
        $seller_id = 111;
        $processor = 'square';
        $encrypted = 'base64_encrypted_data';
        $decrypted_json = json_encode( [
            'location_id' => 'sq0a1234',
            'account_id'  => 'acct_99',
        ] );

        $method = $this->createMockPayoutMethod( $method_id, $seller_id, $processor, $encrypted );

        $this->repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $method_id )
            ->willReturn( $method );

        $this->encryption_mock->expects( $this->once() )
            ->method( 'decrypt' )
            ->with( $encrypted )
            ->willReturn( $decrypted_json );

        $result = $this->manager->getPayoutMethod( $method_id );

        $this->assertInstanceOf( SellerPayoutMethod::class, $result );
        $data = $result->getMethodData();
        $this->assertEquals( 'sq0a1234', $data['location_id'] );
    }

    /**
     * Test get payout method not found throws exception
     *
     * @test
     */
    public function test_get_payout_method_not_found(): void {
        $method_id = 999;

        $this->repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $method_id )
            ->willReturn( null );

        $this->expectException( EntityNotFoundException::class );
        $this->manager->getPayoutMethod( $method_id );
    }

    /**
     * Test update payout method re-encrypts data
     *
     * @test
     */
    public function test_update_payout_method_re_encrypts_data(): void {
        $method_id = 7;
        $seller_id = 222;
        $processor = 'paypal';
        $old_encrypted = 'old_base64_data';
        $new_data = [
            'account_email' => 'newemail@example.com',
            'merchant_id'   => 'NEWMERCHANT123',
        ];
        $new_encrypted = 'new_base64_data';

        $method = $this->createMockPayoutMethod( $method_id, $seller_id, $processor, $old_encrypted );

        $this->repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $method_id )
            ->willReturn( $method );

        $this->validator_mock->expects( $this->once() )->method( 'validate' );

        $this->encryption_mock->expects( $this->once() )
            ->method( 'encrypt' )
            ->willReturn( $new_encrypted );

        $updated = $this->createMockPayoutMethod( $method_id, $seller_id, $processor, $new_encrypted );
        $this->repo_mock->expects( $this->once() )
            ->method( 'update' )
            ->willReturn( $updated );

        $this->events_mock->expects( $this->once() )
            ->method( 'publish' )
            ->with( 'payout_method.updated' );

        $result = $this->manager->updatePayoutMethod( $method_id, $new_data );

        $this->assertInstanceOf( SellerPayoutMethod::class, $result );
    }

    /**
     * Test delete payout method soft-deletes
     *
     * @test
     */
    public function test_delete_payout_method_soft_deletes(): void {
        $method_id = 8;
        $seller_id = 333;
        $processor = 'stripe';

        $method = $this->createMockPayoutMethod( $method_id, $seller_id, $processor, 'encrypted' );
        $method->expects( $this->once() )->method( 'markDeleted' );

        $this->repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $method_id )
            ->willReturn( $method );

        $this->repo_mock->expects( $this->once() )
            ->method( 'update' )
            ->with( $method );

        $this->events_mock->expects( $this->once() )
            ->method( 'publish' )
            ->with( 'payout_method.deleted' );

        $this->manager->deletePayoutMethod( $method_id );

        $this->assertTrue( true );
    }

    /**
     * Test delete payout method not found throws exception
     *
     * @test
     */
    public function test_delete_payout_method_not_found(): void {
        $method_id = 999;

        $this->repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( null );

        $this->expectException( EntityNotFoundException::class );
        $this->manager->deletePayoutMethod( $method_id );
    }

    /**
     * Test get primary method for seller
     *
     * @test
     */
    public function test_get_primary_method_for_seller(): void {
        $seller_id = 444;
        $method_id = 10;
        $processor = 'square';
        $encrypted = 'encrypted_data';
        $decrypted_json = json_encode( [ 'location_id' => 'sq0a999' ] );

        $method = $this->createMockPayoutMethod( $method_id, $seller_id, $processor, $encrypted );

        $this->repo_mock->expects( $this->once() )
            ->method( 'findPrimaryBySeller' )
            ->with( $seller_id )
            ->willReturn( $method );

        $this->encryption_mock->expects( $this->once() )
            ->method( 'decrypt' )
            ->willReturn( $decrypted_json );

        $result = $this->manager->getPrimaryMethodForSeller( $seller_id );

        $this->assertInstanceOf( SellerPayoutMethod::class, $result );
        $this->assertEquals( $seller_id, $result->getSellerId() );
    }

    /**
     * Test get primary method not found throws exception
     *
     * @test
     */
    public function test_get_primary_method_not_found(): void {
        $seller_id = 555;

        $this->repo_mock->expects( $this->once() )
            ->method( 'findPrimaryBySeller' )
            ->with( $seller_id )
            ->willReturn( null );

        $this->expectException( EntityNotFoundException::class );
        $this->manager->getPrimaryMethodForSeller( $seller_id );
    }

    /**
     * Test list payout methods returns all decrypted methods
     *
     * @test
     */
    public function test_list_payout_methods_returns_all_decrypted(): void {
        $seller_id = 666;
        $method1 = $this->createMockPayoutMethod( 11, $seller_id, 'square', 'enc1' );
        $method2 = $this->createMockPayoutMethod( 12, $seller_id, 'paypal', 'enc2' );
        $methods = [ $method1, $method2 ];

        $this->repo_mock->expects( $this->once() )
            ->method( 'findBySeller' )
            ->with( $seller_id )
            ->willReturn( $methods );

        $this->encryption_mock->expects( $this->exactly( 2 ) )
            ->method( 'decrypt' )
            ->willReturnOnConsecutiveCalls(
                json_encode( [ 'location_id' => 'sq0a' ] ),
                json_encode( [ 'account_email' => 'test@test.com' ] )
            );

        $result = $this->manager->listPayoutMethods( $seller_id );

        $this->assertIsArray( $result );
        $this->assertCount( 2, $result );
        $this->assertInstanceOf( SellerPayoutMethod::class, $result[0] );
        $this->assertInstanceOf( SellerPayoutMethod::class, $result[1] );
    }

    /**
     * Test set primary method publishes event
     *
     * @test
     */
    public function test_set_primary_method_publishes_event(): void {
        $method_id = 13;
        $seller_id = 777;
        $processor = 'stripe';

        $method = $this->createMockPayoutMethod( $method_id, $seller_id, $processor, 'encrypted' );

        $this->repo_mock->expects( $this->once() )
            ->method( 'find' )
            ->with( $method_id )
            ->willReturn( $method );

        $this->repo_mock->expects( $this->once() )
            ->method( 'setPrimary' )
            ->with( $method_id, $seller_id );

        $this->events_mock->expects( $this->once() )
            ->method( 'publish' )
            ->with( 'payout_method.primary_changed' );

        $this->manager->setPrimaryMethod( $method_id );

        $this->assertTrue( true );
    }

    /**
     * Create mock payout method
     *
     * @param int    $id Method ID
     * @param int    $seller_id Seller ID
     * @param string $processor Processor type
     * @param string $encrypted_data Encrypted data
     * @return SellerPayoutMethod|MockObject
     */
    private function createMockPayoutMethod( $id, $seller_id, $processor, $encrypted_data ) {
        $method = $this->createMock( SellerPayoutMethod::class );
        $method->method( 'getId' )->willReturn( $id );
        $method->method( 'getSellerId' )->willReturn( $seller_id );
        $method->method( 'getProcessor' )->willReturn( $processor );
        $method->method( 'getMethodData' )->willReturn( $encrypted_data );
        $method->method( 'isActive' )->willReturn( true );
        $method->method( 'isDeleted' )->willReturn( false );
        $method->method( 'setMethodData' )->willReturnSelf();
        $method->method( 'setUpdatedAt' )->willReturnSelf();
        $method->method( 'markDeleted' )->willReturnSelf();

        return $method;
    }
}
