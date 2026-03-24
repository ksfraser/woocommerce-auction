<?php
/**
 * Seller Payout Method Value Object
 *
 * Immutable value object representing seller banking/payment method details.
 * Stores encrypted banking information with secure access controls.
 *
 * @package    YITH\Auctions\Models
 * @since      4.0.0
 * @author     YITH
 * @requirement REQ-4D-2-4: Secure payout method management
 *
 * UML Class Diagram:
 *
 *     ┌──────────────────────────────────────────────┐
 *     │      SellerPayoutMethod                      │
 *     │    (Immutable Value Object)                  │
 *     ├──────────────────────────────────────────────┤
 *     │ - id: int                                    │
 *     │ - seller_id: int                             │
 *     │ - method_type: string                        │
 *     │ - is_primary: bool                           │
 *     │ - account_holder_name: string                │
 *     │ - account_last_four: string                  │
 *     │ - banking_details_encrypted: string          │
 *     │ - verified: bool                             │
 *     │ - verification_date: ?DateTime               │
 *     │ - created_at: DateTime                       │
 *     │ - updated_at: DateTime                       │
 *     ├──────────────────────────────────────────────┤
 *     │ + create(...): SellerPayoutMethod            │
 *     │ + fromDatabase(...): SellerPayoutMethod      │
 *     │ + getters: all properties                    │
 *     │ + toArray(): array                           │
 *     └──────────────────────────────────────────────┘
 */

namespace WC\Auction\Models;

use DateTime;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SellerPayoutMethod
 *
 * Immutable value object for seller payout method details.
 * Encrypted banking details are not exposed directly.
 *
 * @since 4.0.0
 */
class SellerPayoutMethod
{
    // Method type constants
    public const METHOD_ACH = 'ACH';
    public const METHOD_PAYPAL = 'PAYPAL';
    public const METHOD_STRIPE = 'STRIPE';
    public const METHOD_WALLET = 'WALLET';

    /**
     * Database record ID
     *
     * @var int
     */
    private int $id;

    /**
     * WooCommerce seller user ID
     *
     * @var int
     */
    private int $seller_id;

    /**
     * Payment method type (ACH, PAYPAL, STRIPE, WALLET)
     *
     * @var string
     */
    private string $method_type;

    /**
     * Is this the primary payout method?
     *
     * @var bool
     */
    private bool $is_primary;

    /**
     * Account holder name for display
     *
     * @var string
     */
    private string $account_holder_name;

    /**
     * Last four digits for identification (not encrypted)
     *
     * @var string
     */
    private string $account_last_four;

    /**
     * Encrypted banking details (AES-256)
     *
     * Never expose directly - use PayoutMethodManager for encryption/decryption
     *
     * @var string
     */
    private string $banking_details_encrypted;

    /**
     * Has this method been verified with processor?
     *
     * @var bool
     */
    private bool $verified;

    /**
     * When verification occurred
     *
     * @var DateTime|null
     */
    private ?DateTime $verification_date;

    /**
     * When record was created
     *
     * @var DateTime
     */
    private DateTime $created_at;

    /**
     * When record was last updated
     *
     * @var DateTime
     */
    private DateTime $updated_at;

    /**
     * Constructor (private - use factory methods)
     *
     * @param int           $id                      Database ID
     * @param int           $seller_id               Seller user ID
     * @param string        $method_type             Payment method type
     * @param bool          $is_primary              Primary method flag
     * @param string        $account_holder_name     Account holder name
     * @param string        $account_last_four       Last 4 digits
     * @param string        $banking_details_encrypted Encrypted banking data
     * @param bool          $verified                Verification status
     * @param DateTime|null $verification_date       When verified
     * @param DateTime      $created_at              Creation timestamp
     * @param DateTime      $updated_at              Update timestamp
     *
     * @since 4.0.0
     */
    private function __construct(
        int $id,
        int $seller_id,
        string $method_type,
        bool $is_primary,
        string $account_holder_name,
        string $account_last_four,
        string $banking_details_encrypted,
        bool $verified,
        ?DateTime $verification_date,
        DateTime $created_at,
        DateTime $updated_at
    ) {
        $this->id = $id;
        $this->seller_id = $seller_id;
        $this->method_type = $method_type;
        $this->is_primary = $is_primary;
        $this->account_holder_name = $account_holder_name;
        $this->account_last_four = $account_last_four;
        $this->banking_details_encrypted = $banking_details_encrypted;
        $this->verified = $verified;
        $this->verification_date = $verification_date;
        $this->created_at = $created_at;
        $this->updated_at = $updated_at;
    }

    /**
     * Factory: Create new payout method record
     *
     * @param int    $seller_id               Seller user ID
     * @param string $method_type             Payment method type
     * @param string $account_holder_name     Account holder name
     * @param string $account_last_four       Last 4 digits
     * @param string $banking_details_encrypted Encrypted banking data
     * @param bool   $is_primary              Primary method flag (default: false)
     * @param bool   $verified                Verification status (default: false)
     *
     * @return SellerPayoutMethod
     *
     * @since 4.0.0
     */
    public static function create(
        int $seller_id,
        string $method_type,
        string $account_holder_name,
        string $account_last_four,
        string $banking_details_encrypted,
        bool $is_primary = false,
        bool $verified = false
    ): self {
        $now = new DateTime('now', new \DateTimeZone('UTC'));

        return new self(
            0, // ID will be assigned on insert
            $seller_id,
            $method_type,
            $is_primary,
            $account_holder_name,
            $account_last_four,
            $banking_details_encrypted,
            $verified,
            $verified ? $now : null,
            $now,
            $now
        );
    }

    /**
     * Factory: Hydrate from database row
     *
     * @param array $data Database row
     *
     * @return SellerPayoutMethod
     *
     * @since 4.0.0
     */
    public static function fromDatabase(array $data): self {
        $verification_date = null;
        if ( isset($data['verification_date']) && $data['verification_date'] ) {
            $verification_date = new DateTime($data['verification_date'], new \DateTimeZone('UTC'));
        }

        return new self(
            (int) ($data['id'] ?? 0),
            (int) ($data['seller_id'] ?? 0),
            $data['method_type'] ?? self::METHOD_ACH,
            (bool) ($data['is_primary'] ?? false),
            $data['account_holder_name'] ?? '',
            $data['account_last_four'] ?? '',
            $data['banking_details_encrypted'] ?? '',
            (bool) ($data['verified'] ?? false),
            $verification_date,
            new DateTime($data['created_at'] ?? 'now', new \DateTimeZone('UTC')),
            new DateTime($data['updated_at'] ?? 'now', new \DateTimeZone('UTC'))
        );
    }

    /**
     * Check if method is verified
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isVerified(): bool {
        return $this->verified;
    }

    /**
     * Check if method is primary
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isPrimary(): bool {
        return $this->is_primary;
    }

    /**
     * Check if method type is ACH
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isACH(): bool {
        return $this->method_type === self::METHOD_ACH;
    }

    /**
     * Check if method type is PayPal
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isPayPal(): bool {
        return $this->method_type === self::METHOD_PAYPAL;
    }

    /**
     * Check if method type is Stripe
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isStripe(): bool {
        return $this->method_type === self::METHOD_STRIPE;
    }

    /**
     * Check if method type is Wallet
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isWallet(): bool {
        return $this->method_type === self::METHOD_WALLET;
    }

    // ===== Getters =====

    public function getId(): int {
        return $this->id;
    }

    public function getSellerId(): int {
        return $this->seller_id;
    }

    public function getMethodType(): string {
        return $this->method_type;
    }

    public function getAccountHolderName(): string {
        return $this->account_holder_name;
    }

    public function getAccountLastFour(): string {
        return $this->account_last_four;
    }

    public function getBankingDetailsEncrypted(): string {
        return $this->banking_details_encrypted;
    }

    public function getVerificationDate(): ?DateTime {
        return $this->verification_date;
    }

    public function getCreatedAt(): DateTime {
        return $this->created_at;
    }

    public function getUpdatedAt(): DateTime {
        return $this->updated_at;
    }

    /**
     * Convert to array representation (excludes encrypted banking details)
     *
     * @return array
     *
     * @since 4.0.0
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'seller_id' => $this->seller_id,
            'method_type' => $this->method_type,
            'is_primary' => $this->is_primary,
            'account_holder_name' => $this->account_holder_name,
            'account_last_four' => $this->account_last_four,
            'verified' => $this->verified,
            'verification_date' => $this->verification_date ? $this->verification_date->format('Y-m-d H:i:s') : null,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
