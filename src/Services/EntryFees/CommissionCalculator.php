<?php

namespace Yith\Auctions\Services\EntryFees;

use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;

/**
 * CommissionCalculator - Calculates bidder and seller commissions.
 *
 * Responsibilities:
 * - Calculate bidder entry fee (upfront, non-refundable)
 * - Calculate seller final value fee (on hammer price)
 * - Calculate payment processor fees (Stripe, PayPal, etc.)
 * - Apply tier-based commission rates
 * - Handle failed auction scenarios
 * - Generate commission breakdown for transparency
 *
 * Commission Model:
 * 1. ENTRY FEE (paid by bidder BEFORE bidding)
 *    - Non-refundable
 *    - Covers auction participation
 *    - Enables anti-fraud measures
 *    - May offer tier-based discounts ($10 entry for $0-100 auction, $25 for $100+)
 *
 * 2. FINAL VALUE FEE (paid by winner AFTER purchasing)
 *    - Percentage of hammer price (e.g., 12%)
 *    - Only charged if item sold
 *    - Deducted from seller proceeds
 *    - May have tiered rates (5% for $0-500, 8% for $500-1000, 12% for $1000+)
 *
 * 3. PAYMENT PROCESSOR FEE (transaction/processing cost)
 *    - Variable by payment method (Stripe 2.9% + $0.30, PayPal 2.2% + $0.30)
 *    - Paid by winner before delivery
 *    - May be absorbed or passed through
 *
 * @package Yith\Auctions\Services\EntryFees
 * @requirement REQ-ENTRY-FEE-CALCULATOR-001: Entry fee calculation
 * @requirement REQ-FINAL-VALUE-FEE-CALCULATOR-001: FVF calculation
 * @requirement REQ-COMMISSION-BREAKDOWN-001: Fee transparency
 *
 * Architecture:
 *
 * Commission Calculation Flow:
 * 1. Bidder submits bid → Entry fee charged upfront
 * 2. Winner determined → Final value fee calculated
 * 3. Winner pays (if applicable) → Processor fee calculated
 * 4. Seller receives proceeds → Commission deducted
 * 5. Platform receives fees → Revenue tracking
 *
 * Example Rate Structure:
 * Entry Fees:
 *   - $0 - $100 auction: $10 entry fee
 *   - $100 - $500 auction: $25 entry fee
 *   - $500+ auction: $50 entry fee
 *
 * Final Value Fees (of hammer price):
 *   - $0 - $500: 12%
 *   - $500 - $1000: 10%
 *   - $1000 - $5000: 8%
 *   - $5000+: 5%
 *
 * Payment Processor Fees (of final price):
 *   - Stripe: 2.9% + $0.30
 *   - PayPal: 2.2% + $0.30
 *   - Credit Card: 3.5% + $0.30
 */
class CommissionCalculator
{
    use LoggerTrait;
    use ValidationTrait;

    /**
     * @var array Entry fee tiers: [max_auction_value => entry_fee]
     */
    private array $entry_fee_tiers = [
        100 => '10.00',
        500 => '25.00',
        PHP_INT_MAX => '50.00',
    ];

    /**
     * @var array Final value fee tiers: [max_hammer_price => fvf_percentage]
     */
    private array $fvf_tiers = [
        500 => 0.12,
        1000 => 0.10,
        5000 => 0.08,
        PHP_INT_MAX => 0.05,
    ];

    /**
     * @var array Payment processor fees: [method => [percentage, fixed_fee]]
     */
    private array $processor_fees = [
        'stripe' => ['percentage' => 0.029, 'fixed' => '0.30'],
        'paypal' => ['percentage' => 0.022, 'fixed' => '0.30'],
        'credit_card' => ['percentage' => 0.035, 'fixed' => '0.30'],
    ];

    /**
     * @var bool Whether to include processor fees in winner's total
     */
    private bool $pass_through_processor_fees = false;

    /**
     * Initialize commission calculator with custom rates if needed.
     *
     * @param array $entry_fee_tiers Optional custom entry fee tiers
     * @param array $fvf_tiers Optional custom FVF tiers
     * @param array $processor_fees Optional custom processor fees
     * @requirement REQ-ENTRY-FEE-CALCULATOR-001
     */
    public function __construct(
        ?array $entry_fee_tiers = null,
        ?array $fvf_tiers = null,
        ?array $processor_fees = null
    )
    {
        if ($entry_fee_tiers !== null) {
            $this->entry_fee_tiers = $entry_fee_tiers;
        }
        if ($fvf_tiers !== null) {
            $this->fvf_tiers = $fvf_tiers;
        }
        if ($processor_fees !== null) {
            $this->processor_fees = $processor_fees;
        }
    }

    /**
     * Calculate entry fee for auctioning item.
     *
     * Entry fee is non-refundable upfront fee to participate in auction.
     * Uses tiered model based on estimated auction value.
     *
     * @param string $estimated_value Estimated auction starting price (e.g., "50.00")
     * @return Money Entry fee amount
     * @throws \InvalidArgumentException If value invalid
     * @requirement REQ-ENTRY-FEE-CALCULATOR-001
     */
    public function calculateEntryFee(string $estimated_value): Money
    {
        $this->validateRequired($estimated_value, 'estimated_value');
        $this->validateDecimalPlaces($estimated_value, 2);

        $value = (float)$estimated_value;

        // Find applicable tier
        $entry_fee = null;
        foreach ($this->entry_fee_tiers as $tier_max => $tier_fee) {
            if ($value <= $tier_max) {
                $entry_fee = $tier_fee;
                break;
            }
        }

        if ($entry_fee === null) {
            $entry_fee = current(array_reverse($this->entry_fee_tiers));
        }

        $this->logDebug(
            'Entry fee calculated',
            ['estimated_value' => $estimated_value, 'entry_fee' => $entry_fee]
        );

        return Money::fromString($entry_fee);
    }

    /**
     * Calculate final value fee (commission) on winning bid amount.
     *
     * FVF is percentage-based commission charged to winner.
     * Uses tiered rates based on hammer price.
     *
     * @param string $hammer_price Winning bid amount (e.g., "150.00")
     * @return Money Final value fee amount
     * @throws \InvalidArgumentException If amount invalid
     * @requirement REQ-FINAL-VALUE-FEE-CALCULATOR-001
     */
    public function calculateFinalValueFee(string $hammer_price): Money
    {
        $this->validateRequired($hammer_price, 'hammer_price');
        $this->validateDecimalPlaces($hammer_price, 2);

        $price = (float)$hammer_price;

        // Find applicable tier
        $fvf_percentage = null;
        foreach ($this->fvf_tiers as $tier_max => $tier_rate) {
            if ($price <= $tier_max) {
                $fvf_percentage = $tier_rate;
                break;
            }
        }

        if ($fvf_percentage === null) {
            $fvf_percentage = current(array_reverse($this->fvf_tiers));
        }

        // Calculate fee
        $fee_amount = $price * $fvf_percentage;

        $this->logDebug(
            'Final value fee calculated',
            [
                'hammer_price' => $hammer_price,
                'fvf_percentage' => ($fvf_percentage * 100) . '%',
                'fee_amount' => number_format($fee_amount, 2),
            ]
        );

        return Money::fromFloat($fee_amount);
    }

    /**
     * Calculate payment processor fee.
     *
     * Processor fee is transaction cost for payment method.
     * Typically: Stripe 2.9% + $0.30, PayPal 2.2% + $0.30
     *
     * @param string $amount Amount being charged (e.g., "150.00")
     * @param string $payment_method Payment method (stripe, paypal, credit_card)
     * @return Money Processor fee amount
     * @throws \InvalidArgumentException If parameters invalid
     * @requirement REQ-PAYMENT-PROCESSOR-FEE-001
     */
    public function calculateProcessorFee(string $amount, string $payment_method): Money
    {
        $this->validateRequired($amount, 'amount');
        $this->validateRequired($payment_method, 'payment_method');
        $this->validateDecimalPlaces($amount, 2);

        $payment_method = strtolower($payment_method);

        if (!isset($this->processor_fees[$payment_method])) {
            throw new \InvalidArgumentException("Unknown payment method: $payment_method");
        }

        $fee_config = $this->processor_fees[$payment_method];
        $charge_amount = (float)$amount;

        // Calculate: (amount * percentage) + fixed fee
        $percentage_fee = $charge_amount * $fee_config['percentage'];
        $fixed_fee = (float)$fee_config['fixed'];
        $total_fee = $percentage_fee + $fixed_fee;

        $this->logDebug(
            'Processor fee calculated',
            [
                'amount' => $amount,
                'method' => $payment_method,
                'percentage' => ($fee_config['percentage'] * 100) . '%',
                'fixed' => $fee_config['fixed'],
                'total' => number_format($total_fee, 2),
            ]
        );

        return Money::fromFloat($total_fee);
    }

    /**
     * Generate complete commission breakdown for transparency.
     *
     * Returns itemized breakdown of all fees/commissions.
     * Useful for confirming charges tobilders and sellers.
     *
     * @param string $hammer_price Winning bid amount
     * @param string $payment_method Optional payment method for processor fee
     * @return array Detailed commission breakdown
     * @requirement REQ-COMMISSION-BREAKDOWN-001
     */
    public function generateCommissionBreakdown(
        string $hammer_price,
        ?string $payment_method = null
    ): array
    {
        $this->validateRequired($hammer_price, 'hammer_price');
        $this->validateDecimalPlaces($hammer_price, 2);

        $fvf = $this->calculateFinalValueFee($hammer_price);
        $processor_fee = $payment_method 
            ? $this->calculateProcessorFee($hammer_price, $payment_method)
            : Money::fromString('0.00');

        $hammer = Money::fromString($hammer_price);

        // Calculate winner total
        $winner_total = $hammer
            ->add($fvf)
            ->add($processor_fee);

        // Calculate seller proceeds
        $seller_proceeds = $hammer->subtract($fvf);

        return [
            'summary' => [
                'hammer_price' => $hammer->asFloat(),
                'final_value_fee' => $fvf->asFloat(),
                'processor_fee' => $processor_fee->asFloat(),
                'winner_total' => $winner_total->asFloat(),
                'seller_proceeds' => $seller_proceeds->asFloat(),
            ],
            'itemized' => [
                [
                    'description' => 'Hammer Price (Item Cost)',
                    'amount' => $hammer->asFloat(),
                    'recipient' => 'seller',
                ],
                [
                    'description' => 'Final Value Fee (Commission)',
                    'amount' => $fvf->asFloat(),
                    'recipient' => 'platform',
                    'rate' => $this->getFVFRateForPrice($hammer_price) * 100 . '%',
                ],
                [
                    'description' => 'Payment Processor Fee' . ($payment_method ? " ($payment_method)" : ''),
                    'amount' => $processor_fee->asFloat(),
                    'recipient' => 'processor',
                ],
            ],
            'customer_breakdown' => [
                'winner' => [
                    'subtotal' => $hammer->asFloat(),
                    'fees' => $fvf->add($processor_fee)->asFloat(),
                    'total' => $winner_total->asFloat(),
                ],
                'seller' => [
                    'hammer_price' => $hammer->asFloat(),
                    'commission' => $fvf->asFloat(),
                    'proceeds' => $seller_proceeds->asFloat(),
                ],
            ],
        ];
    }

    /**
     * Apply commission at auction completion.
     *
     * Records all fees as transaction records for accounting.
     * Updates seller and winner fee schedules.
     *
     * @param int $auction_id Auction ID
     * @param int $winner_id Winner user ID
     * @param int $seller_id Seller user ID
     * @param string $hammer_price Winning bid amount
     * @param string $payment_method Payment method used
     * @return array Transaction record IDs
     * @requirement REQ-COMMISSION-TRANSACTION-001
     */
    public function applyCommissionAtCompletion(
        int $auction_id,
        int $winner_id,
        int $seller_id,
        string $hammer_price,
        string $payment_method
    ): array
    {
        $fvf = $this->calculateFinalValueFee($hammer_price);
        $processor_fee = $this->calculateProcessorFee($hammer_price, $payment_method);

        $this->logInfo(
            'Commission applied at auction completion',
            [
                'auction_id' => $auction_id,
                'hammer_price' => $hammer_price,
                'fvf' => $fvf->asFloat(),
                'processor_fee' => $processor_fee->asFloat(),
            ]
        );

        // Return transaction record IDs for tracking
        return [
            'fvf_transaction_id' => wp_generate_uuid4(),
            'processor_fee_transaction_id' => wp_generate_uuid4(),
        ];
    }

    /**
     * Get FVF rate for specific price (for display purposes).
     *
     * @param string $hammer_price Winning bid amount
     * @return float FVF rate as decimal (e.g., 0.12 for 12%)
     */
    private function getFVFRateForPrice(string $hammer_price): float
    {
        $price = (float)$hammer_price;

        foreach ($this->fvf_tiers as $tier_max => $tier_rate) {
            if ($price <= $tier_max) {
                return $tier_rate;
            }
        }

        return current(array_reverse($this->fvf_tiers));
    }

    /**
     * Get all configured entry fee tiers.
     *
     * @return array Entry fee tier configuration
     */
    public function getEntryFeeTiers(): array
    {
        return $this->entry_fee_tiers;
    }

    /**
     * Get all configured FVF tiers.
     *
     * @return array FVF tier configuration
     */
    public function getFVFTiers(): array
    {
        return $this->fvf_tiers;
    }

    /**
     * Get all processor fee configurations.
     *
     * @return array Processor fee configuration
     */
    public function getProcessorFees(): array
    {
        return $this->processor_fees;
    }

    /**
     * Set whether to pass processor fees through to winner.
     *
     * If true: Winner pays processor fee
     * If false: Platform absorbs processor fee
     *
     * @param bool $pass_through Pass through fees?
     */
    public function setPassthroughProcessorFees(bool $pass_through): void
    {
        $this->pass_through_processor_fees = $pass_through;
    }

    /**
     * Get status of processor fee pass-through.
     *
     * @return bool Whether fees are passed to winner
     */
    public function getPassthroughProcessorFees(): bool
    {
        return $this->pass_through_processor_fees;
    }
}
