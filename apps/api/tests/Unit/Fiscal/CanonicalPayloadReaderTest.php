<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentView;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use InvalidArgumentException;
use Tests\TestCase;

final class CanonicalPayloadReaderTest extends TestCase
{
    public function test_for_account_payment_returns_typed_view(): void
    {
        $event = new FiscalEvent;
        $event->id = '44444444-4444-4444-8444-444444444444';
        $event->event_type = FiscalEventType::ACCOUNT_PAYMENT;
        $event->payload = $this->accountPaymentPayload();

        $view = (new CanonicalPayloadReader)->forAccountPayment($event);

        self::assertInstanceOf(AccountPaymentView::class, $view);
        self::assertSame('ACCOUNT_PAYMENT', $view->payload->receiptTypeCode);
        self::assertSame('Mariam Ben Ali', $view->customer->name);
        self::assertSame('100.000', $view->payment->amount);
        self::assertSame('300.000', $view->localBalanceSnapshot->receivableBalanceBefore);
        self::assertFalse($view->staleness->balanceSnapshotStale);
    }

    public function test_for_account_charge_returns_typed_view(): void
    {
        $event = new FiscalEvent;
        $event->id = '66666666-6666-4666-8666-666666666666';
        $event->event_type = FiscalEventType::ACCOUNT_CHARGE;
        $event->payload = $this->accountChargePayload();

        $view = (new CanonicalPayloadReader)->forAccountCharge($event);

        self::assertInstanceOf(AccountChargeView::class, $view);
        self::assertSame('ACCOUNT_CHARGE', $view->payload->receiptTypeCode);
        self::assertSame('Mariam Ben Ali', $view->customer->name);
        self::assertSame('119.000', $view->totals->amountChargedToAccount);
        self::assertSame('419.000', $view->localBalanceSnapshot->projectedReceivableBalanceAfter);
        self::assertSame('Net 30', $view->chargeTerms->termsLabel);
        self::assertSame('phase3-default-v1', $view->creditDecision->policyVersion);
        self::assertSame('Default Seller', $view->seller->name);
    }

    public function test_for_account_payment_rejects_wrong_event_type(): void
    {
        $event = new FiscalEvent;
        $event->id = '11111111-1111-4111-8111-111111111111';
        $event->event_type = FiscalEventType::SALE_RECEIPT;
        $event->payload = $this->accountPaymentPayload();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/forAccountPayment called with event_type=SALE_RECEIPT/');
        (new CanonicalPayloadReader)->forAccountPayment($event);
    }

    public function test_for_account_payment_rejects_null_payload(): void
    {
        $event = new FiscalEvent;
        $event->id = '22222222-2222-4222-8222-222222222222';
        $event->event_type = FiscalEventType::ACCOUNT_PAYMENT;
        $event->payload = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/forAccountPayment called on fiscal_event_id=/');
        (new CanonicalPayloadReader)->forAccountPayment($event);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountChargePayload(): array
    {
        return [
            'account_charge_uuid' => '66666666-6666-4666-8666-666666666666',
            'business_date' => '2026-05-21',
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'charge_terms' => ['due_date' => '2026-06-20', 'payment_terms_days' => 30, 'terms_label' => 'Net 30'],
            'credit_decision' => [
                'credit_available_after' => '81.000',
                'credit_available_before' => '200.000',
                'credit_limit' => '500.000',
                'decision' => 'approved',
                'limit_exceeded' => false,
                'mirror_stale_at_authoring' => false,
                'policy_version' => 'phase3-default-v1',
                'stale_policy_action' => 'allow',
                'warnings' => [],
            ],
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'account_identifier' => 'CUST-0001',
                'address' => null,
                'customer_category' => 'individual',
                'customer_id' => '55555555-5555-4555-8555-555555555555',
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'invoice_classification' => 'b2c_charge_receipt',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '100.000',
                'line_uuid' => '77777777-7777-4777-8777-777777777777',
                'line_vat' => '19.000',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '100.000',
                'vat_rate' => '19.00',
            ]],
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'charge_amount' => '119.000',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '419.000',
                'projected_receivable_balance_after' => '419.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'print_profile' => 'ACCOUNT_CHARGE_RECEIPT',
            'receipt_type_code' => 'ACCOUNT_CHARGE',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
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
            'totals' => [
                'amount_charged_to_account' => '119.000',
                'grand_total_before_charge' => '119.000',
                'subtotal' => '100.000',
                'total' => '119.000',
                'vat_total' => '19.000',
            ],
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '119.000',
                'net_amount' => '100.000',
                'rate' => '19.00',
                'tax_category_code' => '',
                'vat_amount' => '19.000',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPaymentPayload(): array
    {
        return [
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
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
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
    }
}
