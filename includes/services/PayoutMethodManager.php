<?php
/**
 * Payout Method Manager - Manage seller payout methods with encryption
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-045: Provide secure payout method storage and retrieval
 */

namespace WC\Auction\Services;

use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Repositories\PayoutMethodRepository;
use WC\Auction\Validators\PayoutMethodValidator;
use WC\Auction\Exceptions\ValidationException;
use WC\Auction\Exceptions\EntityNotFoundException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PayoutMethodManager - Manages seller payout methods with encryption
 *
 * Handles secure storage/retrieval of payment processing details.
 * All sensitive data is encrypted with AES-256-CBC before storage.
 *
 * @requirement REQ-4D-045: Encrypt payout methods before storage
 * @requirement SEC-001: Use AES-256-CBC encryption
 */
class PayoutMethodManager {

    /**
     * Payout method repository
     *
     * @var PayoutMethodRepository
     */
    private $repository;

    /**
     * Encryption service
     *
     * @var EncryptionService
     */
    private $encryption;

    /**
     * Payout method validator
     *
     * @var PayoutMethodValidator
     */
    private $validator;

    /**
     * Event publisher
     *
     * @var EventPublisher
     */
    private $events;

    /**
     * Logger service
     *
     * @var \WC\Auction\Services\LoggerService
     */
    private $logger;

    /**
     * Constructor
     *
     * @param PayoutMethodRepository  $repository Method repository (DAL)
     * @param EncryptionService       $encryption Encryption service
     * @param PayoutMethodValidator   $validator  Method validator
     * @param EventPublisher          $events     Event publisher
     * @param \WC\Auction\Services\LoggerService $logger     Logger service
     */
    public function __construct(
        PayoutMethodRepository $repository,
        EncryptionService $encryption,
        PayoutMethodValidator $validator,
        EventPublisher $events,
        $logger
    ) {
        $this->repository = $repository;
        $this->encryption = $encryption;
        $this->validator  = $validator;
        $this->events     = $events;
        $this->logger     = $logger;
    }

    /**
     * Add a new payout method for seller
     *
     * Validates the method, encrypts sensitive fields, and stores.
     * Sets as primary if no other active methods exist.
     *
     * @param int    $seller_id Seller ID
     * @param string $processor Processor type (square, paypal, stripe)
     * @param array  $method_data Raw method data with sensitive fields
     * @return SellerPayoutMethod Created method (decrypted for return)
     * @throws ValidationException If validation fails
     *
     * @requirement REQ-4D-045: Encrypt and store payout methods
     */
    public function addPayoutMethod(
        int $seller_id,
        string $processor,
        array $method_data
    ): SellerPayoutMethod {
        // Create method object
        $method = new SellerPayoutMethod(
            null, // No ID yet
            $seller_id,
            $processor,
            $method_data,
            true, // Active by default
            false, // Not deleted
            new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ), // created_at
            null  // updated_at
        );

        // Validate method
        $this->validator->validate( $method, $processor );

        // Check if this should be primary
        $existing = $this->repository->findBySeller( $seller_id );
        $is_primary = empty( $existing );

        // Encrypt sensitive data
        $encrypted_data = $this->encryptMethodData( $method_data, $processor );
        $method->setMethodData( $encrypted_data );

        // Store in repository
        $stored = $this->repository->create( $method, $is_primary );

        // Log action
        $this->logger->info(
            sprintf(
                'Payout method added for seller %d (processor: %s, primary: %s)',
                $seller_id,
                $processor,
                $is_primary ? 'yes' : 'no'
            ),
            [ 'seller_id' => $seller_id, 'processor' => $processor ]
        );

        // Publish event
        $this->events->publish( 'payout_method.added', [
            'seller_id'   => $seller_id,
            'method_id'   => $stored->getId(),
            'processor'   => $processor,
            'is_primary'  => $is_primary,
            'timestamp'   => current_time( 'mysql', true ),
        ] );

        // Return decrypted version
        $stored->setMethodData( $method_data );
        return $stored;
    }

    /**
     * Update existing payout method
     *
     * Re-validates, re-encrypts, and stores updated data.
     *
     * @param int   $method_id Method ID to update
     * @param array $method_data Updated method data
     * @return SellerPayoutMethod Updated method (decrypted)
     * @throws EntityNotFoundException If method not found
     * @throws ValidationException If validation fails
     *
     * @requirement REQ-4D-045: Re-encrypt updated method data
     */
    public function updatePayoutMethod(
        int $method_id,
        array $method_data
    ): SellerPayoutMethod {
        // Load existing method
        $method = $this->repository->find( $method_id );
        if ( ! $method ) {
            throw new EntityNotFoundException( "Payout method #{$method_id} not found" );
        }

        // Create updated version
        $method->setMethodData( $method_data );
        $method->setUpdatedAt( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );

        // Validate
        $this->validator->validate( $method, $method->getProcessor() );

        // Re-encrypt
        $encrypted_data = $this->encryptMethodData( $method_data, $method->getProcessor() );
        $method->setMethodData( $encrypted_data );

        // Update in repository
        $updated = $this->repository->update( $method );

        // Log action
        $this->logger->info(
            sprintf( 'Payout method %d updated for seller %d', $method_id, $method->getSellerId() ),
            [ 'method_id' => $method_id, 'seller_id' => $method->getSellerId() ]
        );

        // Publish event
        $this->events->publish( 'payout_method.updated', [
            'method_id'  => $method_id,
            'seller_id'  => $method->getSellerId(),
            'processor'  => $method->getProcessor(),
            'timestamp'  => current_time( 'mysql', true ),
        ] );

        // Return decrypted version
        $updated->setMethodData( $method_data );
        return $updated;
    }

    /**
     * Delete payout method
     *
     * Soft-deletes (marks deleted) rather than removing from DB.
     *
     * @param int $method_id Method to delete
     * @throws EntityNotFoundException If method not found
     *
     * @requirement SEC-003: Keep audit trail (soft delete)
     */
    public function deletePayoutMethod( int $method_id ): void {
        $method = $this->repository->find( $method_id );
        if ( ! $method ) {
            throw new EntityNotFoundException( "Payout method #{$method_id} not found" );
        }

        // Mark as deleted
        $method->markDeleted();
        $this->repository->update( $method );

        // Log action
        $this->logger->info(
            sprintf( 'Payout method %d deleted by seller %d', $method_id, $method->getSellerId() ),
            [ 'method_id' => $method_id, 'seller_id' => $method->getSellerId() ]
        );

        // Publish event
        $this->events->publish( 'payout_method.deleted', [
            'method_id'  => $method_id,
            'seller_id'  => $method->getSellerId(),
            'timestamp'  => current_time( 'mysql', true ),
        ] );
    }

    /**
     * Retrieve and decrypt payout method
     *
     * @param int $method_id Method to retrieve
     * @return SellerPayoutMethod Method with decrypted data
     * @throws EntityNotFoundException If method not found
     *
     * @requirement REQ-4D-045: Decrypt method data on retrieval
     */
    public function getPayoutMethod( int $method_id ): SellerPayoutMethod {
        $method = $this->repository->find( $method_id );
        if ( ! $method ) {
            throw new EntityNotFoundException( "Payout method #{$method_id} not found" );
        }

        // Decrypt method data
        $encrypted = $method->getMethodData();
        if ( is_string( $encrypted ) && EncryptionService::isEncrypted( $encrypted ) ) {
            try {
                $decrypted = json_decode( $this->encryption->decrypt( $encrypted ), true ) ?: [];
                $method->setMethodData( $decrypted );
            } catch ( \Exception $e ) {
                // Log decryption failure
                $this->logger->error(
                    sprintf( 'Failed to decrypt payout method %d: %s', $method_id, $e->getMessage() ),
                    [ 'method_id' => $method_id ]
                );
                throw new \RuntimeException( 'Failed to decrypt payout method data' );
            }
        }

        return $method;
    }

    /**
     * Get primary payout method for seller
     *
     * @param int $seller_id Seller ID
     * @return SellerPayoutMethod Primary method (decrypted)
     * @throws EntityNotFoundException If no primary method found
     *
     * @requirement REQ-4D-045: Return primary active method
     */
    public function getPrimaryMethodForSeller( int $seller_id ): SellerPayoutMethod {
        $method = $this->repository->findPrimaryBySeller( $seller_id );
        if ( ! $method ) {
            throw new EntityNotFoundException( "No primary payout method found for seller #{$seller_id}" );
        }

        return $this->decryptMethod( $method );
    }

    /**
     * List all active payout methods for seller
     *
     * @param int $seller_id Seller ID
     * @return SellerPayoutMethod[] Active methods (decrypted)
     *
     * @requirement REQ-4D-045: Return all active methods for seller
     */
    public function listPayoutMethods( int $seller_id ): array {
        $methods = $this->repository->findBySeller( $seller_id );
        return array_map( [ $this, 'decryptMethod' ], $methods );
    }

    /**
     * Set method as primary for seller
     *
     * @param int $method_id Method to set as primary
     * @throws EntityNotFoundException If method not found
     */
    public function setPrimaryMethod( int $method_id ): void {
        $method = $this->repository->find( $method_id );
        if ( ! $method ) {
            throw new EntityNotFoundException( "Payout method #{$method_id} not found" );
        }

        $this->repository->setPrimary( $method_id, $method->getSellerId() );

        $this->logger->info(
            sprintf( 'Payout method %d set as primary for seller %d', $method_id, $method->getSellerId() ),
            [ 'method_id' => $method_id, 'seller_id' => $method->getSellerId() ]
        );

        $this->events->publish( 'payout_method.primary_changed', [
            'method_id'  => $method_id,
            'seller_id'  => $method->getSellerId(),
            'timestamp'  => current_time( 'mysql', true ),
        ] );
    }

    /**
     * Encrypt method data for storage
     *
     * Sensitive fields are encrypted as JSON.
     *
     * @param array  $method_data Raw method data
     * @param string $processor Processor type
     * @return string Encrypted JSON
     *
     * @requirement SEC-001: Encrypt sensitive fields
     */
    private function encryptMethodData( array $method_data, string $processor ): string {
        $json = json_encode( $method_data );
        return $this->encryption->encrypt( $json );
    }

    /**
     * Decrypt method data
     *
     * @param SellerPayoutMethod $method Method with encrypted data
     * @return SellerPayoutMethod Method with decrypted data
     */
    private function decryptMethod( SellerPayoutMethod $method ): SellerPayoutMethod {
        $encrypted = $method->getMethodData();

        if ( is_string( $encrypted ) && EncryptionService::isEncrypted( $encrypted ) ) {
            try {
                $decrypted = json_decode( $this->encryption->decrypt( $encrypted ), true ) ?: [];
                $method->setMethodData( $decrypted );
            } catch ( \Exception $e ) {
                $this->logger->error(
                    sprintf( 'Decryption failed for method %d: %s', $method->getId(), $e->getMessage() )
                );
            }
        }

        return $method;
    }
}
