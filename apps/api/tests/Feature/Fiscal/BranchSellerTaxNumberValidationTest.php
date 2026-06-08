<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use Tests\Helpers\Fiscal\GoldenFixtureBuilder;
use Tests\TestCase;

final class BranchSellerTaxNumberValidationTest extends TestCase
{
    private FiscalPayloadConstraintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new FiscalPayloadConstraintValidator;
    }

    public function test_sale_receipt_validator_accepts_branch_fr_seller_tax_number(): void
    {
        $payload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $payload['seller']['address']['country_code'] = 'FR';
        $payload['seller']['tax_jurisdiction_country_code'] = 'FR';
        $payload['seller']['tax_number'] = '73282932000074';

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::SALE_RECEIPT, $payload));
        $this->validator->validatePerEventConstraints(FiscalEventType::SALE_RECEIPT, $payload);

        $this->addToAssertionCount(1);
    }

    public function test_account_payment_validator_accepts_branch_tn_seller_tax_number(): void
    {
        $payload = $this->accountPaymentPayload([
            'seller' => [
                'address' => [
                    'country_code' => 'TN',
                ],
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567/A/M/000',
            ],
        ]);

        self::assertNull($this->validator->validatePayloadKeySet(FiscalEventType::ACCOUNT_PAYMENT, $payload));
        $this->validator->validatePerEventConstraints(FiscalEventType::ACCOUNT_PAYMENT, $payload);

        $this->addToAssertionCount(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountPaymentPayload(array $overrides = []): array
    {
        $payload = [
            'account_payment_uuid' => '44444444-4444-4444-8444-444444444444',
            'business_date' => '2026-05-21',
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'customer_category' => 'retail',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'payment_amount' => '100.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '200.000',
                'projected_receivable_balance_after' => '200.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => '100.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
                'repository_id' => null,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => [
                    'city' => 'Tunis',
                    'country_code' => 'TN',
                    'postal_code' => '1000',
                    'street' => '1 rue Test',
                ],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => '2026-05-21T10:10:00.000Z',
                'staleness_reason' => null,
            ],
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];

        return array_replace_recursive($payload, $overrides);
    }
}
