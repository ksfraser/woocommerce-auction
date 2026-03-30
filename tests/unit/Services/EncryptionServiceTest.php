<?php
/**
 * EncryptionServiceTest - Unit tests for EncryptionService
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    4.0.0
 * @requirement REQ-4D-045: Test encryption/decryption functionality
 */

namespace WC\Auction\Tests\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Services\EncryptionService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test suite for EncryptionService
 *
 * @requirement REQ-4D-045: Test AES-256-CBC encryption
 */
class EncryptionServiceTest extends TestCase {

    /**
     * Encryption key for testing
     *
     * @var string
     */
    private $test_key;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();
        // Generate encryption key
        $this->test_key = EncryptionService::generateKey();
    }

    /**
     * Test encryption service can be instantiated
     *
     * @test
     */
    public function test_encryption_service_can_be_instantiated(): void {
        $service = new EncryptionService( $this->test_key );
        $this->assertInstanceOf( EncryptionService::class, $service );
    }

    /**
     * Test encrypt produces output
     *
     * @test
     */
    public function test_encrypt_produces_output(): void {
        $service = new EncryptionService( $this->test_key );
        $plaintext = 'This is secret data';

        $encrypted = $service->encrypt( $plaintext );

        $this->assertIsString( $encrypted );
        $this->assertNotEmpty( $encrypted );
        $this->assertNotEquals( $plaintext, $encrypted );
    }

    /**
     * Test encryption is deterministic (same input, same key = base64 only matches decoded)
     *
     * @test
     */
    public function test_encryption_with_random_iv(): void {
        $service = new EncryptionService( $this->test_key );
        $plaintext = 'Same secret';

        $encrypted1 = $service->encrypt( $plaintext );
        $encrypted2 = $service->encrypt( $plaintext );

        // Due to random IV, encrypted output should differ
        $this->assertNotEquals( $encrypted1, $encrypted2 );
    }

    /**
     * Test encryption-decryption roundtrip
     *
     * @test
     */
    public function test_encryption_decryption_roundtrip(): void {
        $service = new EncryptionService( $this->test_key );
        $plaintext = 'Banking details: account 123456789';

        $encrypted = $service->encrypt( $plaintext );
        $decrypted = $service->decrypt( $encrypted );

        $this->assertEquals( $plaintext, $decrypted );
    }

    /**
     * Test decrypt with wrong key fails
     *
     * @test
     */
    public function test_decrypt_with_wrong_key_fails(): void {
        $service1 = new EncryptionService( $this->test_key );
        $plaintext = 'Secret message';
        $encrypted = $service1->encrypt( $plaintext );

        // Create service with different key
        $wrong_key = EncryptionService::generateKey();
        $service2 = new EncryptionService( $wrong_key );

        $this->expectException( \RuntimeException::class );
        $service2->decrypt( $encrypted );
    }

    /**
     * Test decrypt invalid base64 throws exception
     *
     * @test
     */
    public function test_decrypt_invalid_base64_throws_exception(): void {
        $service = new EncryptionService( $this->test_key );

        $this->expectException( \RuntimeException::class );
        $service->decrypt( 'not-valid-base64!!!' );
    }

    /**
     * Test decrypt truncated data throws exception
     *
     * @test
     */
    public function test_decrypt_truncated_data_throws_exception(): void {
        $service = new EncryptionService( $this->test_key );

        // Create truncated encrypted data
        $short_base64 = base64_encode( 'short' );

        $this->expectException( \RuntimeException::class );
        $service->decrypt( $short_base64 );
    }

    /**
     * Test generate key produces 32-byte key
     *
     * @test
     */
    public function test_generate_key_produces_32_byte_key(): void {
        $key = EncryptionService::generateKey();

        $this->assertIsString( $key );
        $this->assertEquals( 32, strlen( $key ) );
    }

    /**
     * Test generated keys are cryptographically random
     *
     * @test
     */
    public function test_generated_keys_are_random(): void {
        $key1 = EncryptionService::generateKey();
        $key2 = EncryptionService::generateKey();
        $key3 = EncryptionService::generateKey();

        // All should be different
        $this->assertNotEquals( $key1, $key2 );
        $this->assertNotEquals( $key2, $key3 );
        $this->assertNotEquals( $key1, $key3 );
    }

    /**
     * Test invalid key length throws exception
     *
     * @test
     */
    public function test_invalid_key_length_throws_exception(): void {
        $short_key = substr( $this->test_key, 0, 16 ); // 16 bytes instead of 32

        $this->expectException( \InvalidArgumentException::class );
        new EncryptionService( $short_key );
    }

    /**
     * Test encrypt large data
     *
     * @test
     */
    public function test_encrypt_large_data(): void {
        $service = new EncryptionService( $this->test_key );
        $large_data = str_repeat( 'x', 10000 );

        $encrypted = $service->encrypt( $large_data );
        $decrypted = $service->decrypt( $encrypted );

        $this->assertEquals( $large_data, $decrypted );
    }

    /**
     * Test encrypt empty string
     *
     * @test
     */
    public function test_encrypt_empty_string(): void {
        $service = new EncryptionService( $this->test_key );
        $plaintext = '';

        $encrypted = $service->encrypt( $plaintext );
        $decrypted = $service->decrypt( $encrypted );

        $this->assertEquals( '', $decrypted );
    }

    /**
     * Test encrypt special characters and unicode
     *
     * @test
     */
    public function test_encrypt_special_and_unicode(): void {
        $service = new EncryptionService( $this->test_key );
        $plaintext = 'Special chars: !@#$%^&*() Unicode: 你好世界 🔐';

        $encrypted = $service->encrypt( $plaintext );
        $decrypted = $service->decrypt( $encrypted );

        $this->assertEquals( $plaintext, $decrypted );
    }

    /**
     * Test is encrypted detection
     *
     * @test
     */
    public function test_is_encrypted_detection(): void {
        $service = new EncryptionService( $this->test_key );
        $plaintext = 'Test data';
        $encrypted = $service->encrypt( $plaintext );

        $this->assertFalse( EncryptionService::isEncrypted( $plaintext ) );
        $this->assertTrue( EncryptionService::isEncrypted( $encrypted ) );
    }

    /**
     * Test JSON serialization of encrypted data
     *
     * @test
     */
    public function test_json_serialization_with_encryption(): void {
        $service = new EncryptionService( $this->test_key );
        $data = [
            'account_id'   => '123456',
            'access_token' => 'token_abc',
            'created_at'   => date( 'Y-m-d H:i:s' ),
        ];
        $json = json_encode( $data );

        $encrypted = $service->encrypt( $json );
        $decrypted = $service->decrypt( $encrypted );
        $restored = json_decode( $decrypted, true );

        $this->assertEquals( $data, $restored );
    }
}
