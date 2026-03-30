<?php
/**
 * EncryptionInstallerTest - Unit tests for EncryptionInstaller
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    1.0.0
 * @requirement REQ-4D-045: Test encryption installer
 */

namespace WC\Auction\Tests\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Services\EncryptionInstaller;
use WC\Auction\Services\EncryptionService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test suite for EncryptionInstaller
 *
 * @requirement REQ-4D-045: Test encryption setup and installation
 */
class EncryptionInstallerTest extends TestCase {

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();
        // Clear any existing encryption options
        delete_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY );
        delete_option( EncryptionInstaller::OPTION_ENCRYPTION_METHOD );
        delete_option( EncryptionInstaller::OPTION_KEY_GENERATED_AT );
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void {
        delete_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY );
        delete_option( EncryptionInstaller::OPTION_ENCRYPTION_METHOD );
        delete_option( EncryptionInstaller::OPTION_KEY_GENERATED_AT );
        parent::tearDown();
    }

    /**
     * Test install returns true
     *
     * @test
     */
    public function test_install_returns_true(): void {
        $result = EncryptionInstaller::install();
        $this->assertTrue( $result );
    }

    /**
     * Test install generates key on first run
     *
     * @test
     */
    public function test_install_generates_key(): void {
        EncryptionInstaller::install();

        $key = get_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY );
        $this->assertNotEmpty( $key );
        $this->assertIsString( $key );
    }

    /**
     * Test install sets method option
     *
     * @test
     */
    public function test_install_sets_encryption_method(): void {
        EncryptionInstaller::install();

        $method = get_option( EncryptionInstaller::OPTION_ENCRYPTION_METHOD );
        $this->assertIsString( $method );
        $this->assertTrue( $method === 'defuse' || $method === 'sodium' );
    }

    /**
     * Test install sets key generation timestamp
     *
     * @test
     */
    public function test_install_sets_generation_timestamp(): void {
        EncryptionInstaller::install();

        $generated_at = get_option( EncryptionInstaller::OPTION_KEY_GENERATED_AT );
        $this->assertNotEmpty( $generated_at );
        $this->assertIsString( $generated_at );
    }

    /**
     * Test install doesn't overwrite existing key
     *
     * @test
     */
    public function test_install_preserves_existing_key(): void {
        // Set up initial key
        update_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY, 'initial-key-12345' );

        // Run install
        EncryptionInstaller::install();

        // Key should be unchanged
        $key = get_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY );
        $this->assertEquals( 'initial-key-12345', $key );
    }

    /**
     * Test has configured key detects options storage
     *
     * @test
     */
    public function test_has_configured_key_options(): void {
        update_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY, 'test-key' );

        // Use reflection to call private method
        $reflection = new \ReflectionClass( EncryptionInstaller::class );
        $method = $reflection->getMethod( 'hasConfiguredKey' );
        $method->setAccessible( true );

        $has_key = $method->invoke( null );
        $this->assertTrue( $has_key );
    }

    /**
     * Test get encryption key from options
     *
     * @test
     */
    public function test_get_encryption_key_from_options(): void {
        $test_key = 'test-key-for-options';
        update_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY, $test_key );

        $key = EncryptionInstaller::getEncryptionKey();
        $this->assertEquals( $test_key, $key );
    }

    /**
     * Test get encryption key with empty storage
     *
     * @test
     */
    public function test_get_encryption_key_null_when_empty(): void {
        $key = EncryptionInstaller::getEncryptionKey();
        $this->assertNull( $key );
    }

    /**
     * Test get encryption service works
     *
     * @test
     */
    public function test_get_encryption_service(): void {
        // First install to generate key
        EncryptionInstaller::install();

        // Get service
        $service = EncryptionInstaller::getEncryptionService();

        $this->assertInstanceOf( EncryptionService::class, $service );
    }

    /**
     * Test get encryption service throws when no key
     *
     * @test
     */
    public function test_get_encryption_service_throws_when_no_key(): void {
        $this->expectException( \RuntimeException::class );
        EncryptionInstaller::getEncryptionService();
    }

    /**
     * Test get status returns valid structure
     *
     * @test
     */
    public function test_get_status_returns_array(): void {
        EncryptionInstaller::install();

        $status = EncryptionInstaller::getStatus();

        $this->assertIsArray( $status );
        $this->assertArrayHasKey( 'method', $status );
        $this->assertArrayHasKey( 'generated_at', $status );
        $this->assertArrayHasKey( 'location', $status );
    }

    /**
     * Test get status shows options location
     *
     * @test
     */
    public function test_get_status_shows_options_location(): void {
        update_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY, 'test-key' );
        update_option( EncryptionInstaller::OPTION_ENCRYPTION_METHOD, 'defuse' );

        $status = EncryptionInstaller::getStatus();

        $this->assertEquals( 'options', $status['location'] );
        $this->assertEquals( 'defuse', $status['method'] );
    }

    /**
     * Test verify encryption works
     *
     * @test
     */
    public function test_verify_encryption_works(): void {
        EncryptionInstaller::install();

        $result = EncryptionInstaller::verify();

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'valid', $result );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertTrue( $result['valid'] );
        $this->assertNull( $result['error'] );
    }

    /**
     * Test verify encryption fails with no key
     *
     * @test
     */
    public function test_verify_encryption_fails_without_key(): void {
        $result = EncryptionInstaller::verify();

        $this->assertIsArray( $result );
        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['error'] );
    }

    /**
     * Test multiple installs generate same key
     *
     * @test
     */
    public function test_install_idempotent(): void {
        EncryptionInstaller::install();
        $key1 = get_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY );

        EncryptionInstaller::install();
        $key2 = get_option( EncryptionInstaller::OPTION_ENCRYPTION_KEY );

        $this->assertEquals( $key1, $key2 );
    }

    /**
     * Test encryption service uses installed key
     *
     * @test
     */
    public function test_service_uses_installed_key(): void {
        EncryptionInstaller::install();

        $service = EncryptionInstaller::getEncryptionService();

        // Test encryption/decryption with service
        $plaintext = 'Secret data for testing';
        $encrypted = $service->encrypt( $plaintext );
        $decrypted = $service->decrypt( $encrypted );

        $this->assertEquals( $plaintext, $decrypted );
    }
}
