<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 41: Nf525DataProvider populates the H3 contract extension points.
 *
 * Tests:
 * - exchange_group_id is propagated from pos_receipts to Nf525ReceiptData.
 * - Audit fields (authorized_by_user_id, override_reason, out_of_window, policy_trigger)
 *   are populated for return receipts with an override.
 * - voucher_ledger_entries are populated for credit notes that issued vouchers.
 * - voucher_ledger_entries are populated for sale receipts that redeemed vouchers.
 * - instrument_type / instrument_serial are populated on voucher payments.
 */
class Nf525DataProviderRefundExtensionTest extends TestCase
{
    use RefreshDatabase;

    private Nf525DataProvider $provider;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->app->make(Nf525DataProvider::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = $this->createTerminal();
    }

    public function test_nf525_data_provider_populates_exchange_group_id_for_exchange_receipts(): void
    {
        $exchangeGroupId = (string) Str::uuid();

        $receipt = $this->createSaleReceipt([
            'exchange_group_id' => $exchangeGroupId,
        ]);

        $snapshot = $this->provider->buildExportSnapshot(
            $this->company->id,
            Carbon::yesterday(),
            Carbon::tomorrow(),
        );

        $this->assertCount(1, $snapshot->sales);
        $mapped = $snapshot->sales[0];

        $this->assertSame($exchangeGroupId, $mapped->exchangeGroupId);
    }

    public function test_nf525_data_provider_populates_audit_fields_for_return_receipts_with_override(): void
    {
        $managerId = (string) User::factory()->create(['tenant_id' => $this->tenant->id])->id;
        $originalReceipt = $this->createSaleReceipt();

        $this->createReturnReceipt($originalReceipt, [
            'authorized_by_user_id' => $managerId,
            'override_reason' => 'Customer exception',
            'out_of_window' => true,
            'policy_trigger' => 'over_threshold',
        ]);

        $snapshot = $this->provider->buildExportSnapshot(
            $this->company->id,
            Carbon::yesterday(),
            Carbon::tomorrow(),
        );

        $this->assertCount(1, $snapshot->returnReceipts);
        $mapped = $snapshot->returnReceipts[0];

        $this->assertSame($managerId, $mapped->authorizedByUserId);
        $this->assertSame('Customer exception', $mapped->overrideReason);
        $this->assertTrue($mapped->outOfWindow);
    }

    public function test_nf525_data_provider_populates_voucher_ledger_entries_for_credit_notes_that_issued_vouchers(): void
    {
        $originalReceipt = $this->createSaleReceipt();
        $returnReceipt = $this->createReturnReceipt($originalReceipt);

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Issuance ledger entry on the return receipt
        VoucherLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'receipt_id' => $returnReceipt->id,
            'terminal_id' => $this->terminal->id,
            'event' => VoucherEvent::Issued,
            'amount' => '50.00000',
            'currency' => 'EUR',
            'user_id' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        $snapshot = $this->provider->buildExportSnapshot(
            $this->company->id,
            Carbon::yesterday(),
            Carbon::tomorrow(),
        );

        $this->assertCount(1, $snapshot->returnReceipts);
        $mapped = $snapshot->returnReceipts[0];

        $this->assertCount(1, $mapped->voucherLedgerEntries);
        $entry = $mapped->voucherLedgerEntries[0];

        $this->assertSame((string) $voucher->id, $entry->voucherId);
        $this->assertSame('Issued', $entry->event);
        // Amount stored as decimal:5, provider passes it through as string
        $this->assertStringContainsString('50', $entry->amount);
    }

    public function test_nf525_data_provider_populates_voucher_ledger_entries_for_sale_receipts_that_redeemed_vouchers(): void
    {
        $saleReceipt = $this->createSaleReceipt();

        $voucher = Voucher::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Redemption ledger entry on the sale receipt
        VoucherLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'voucher_id' => $voucher->id,
            'receipt_id' => $saleReceipt->id,
            'terminal_id' => $this->terminal->id,
            'event' => VoucherEvent::Redeemed,
            'amount' => '-20.00000',
            'currency' => 'EUR',
            'user_id' => $this->cashier->id,
            'occurred_at' => now(),
        ]);

        $snapshot = $this->provider->buildExportSnapshot(
            $this->company->id,
            Carbon::yesterday(),
            Carbon::tomorrow(),
        );

        $this->assertCount(1, $snapshot->sales);
        $mapped = $snapshot->sales[0];

        $this->assertCount(1, $mapped->voucherLedgerEntries);
        $entry = $mapped->voucherLedgerEntries[0];

        $this->assertSame('Redeemed', $entry->event);
    }

    public function test_nf525_data_provider_populates_instrument_type_serial_on_voucher_payments(): void
    {
        $saleReceipt = $this->createSaleReceipt();

        $paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ReceiptPayment::create([
            'receipt_id' => $saleReceipt->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_type' => 'STORE_VOUCHER',
            'amount' => '30.000',
            'instrument_type' => PaymentInstrumentKind::StoreVoucher,
            'instrument_serial' => 'VOUCHER-ABC123',
        ]);

        $snapshot = $this->provider->buildExportSnapshot(
            $this->company->id,
            Carbon::yesterday(),
            Carbon::tomorrow(),
        );

        $this->assertCount(1, $snapshot->sales);
        $mappedReceipt = $snapshot->sales[0];
        $this->assertCount(1, $mappedReceipt->payments);

        $payment = $mappedReceipt->payments[0];
        $this->assertSame('store_voucher', $payment->instrumentType);
        $this->assertSame('VOUCHER-ABC123', $payment->instrumentSerial);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private int $seq = 0;

    private function createTerminal(): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => 2026,
            'is_active' => true,
            'max_discount_percent' => 20.0,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSaleReceipt(array $overrides = []): Receipt
    {
        $this->seq++;

        return Receipt::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('POS01-2026-%08d', $this->seq),
            'receipt_type' => ReceiptType::Sale,
            'chain_sequence' => $this->seq,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "sale-{$this->seq}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'pay'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '100.000',
            'tax_amount' => '20.000',
            'discount_amount' => '0.000',
            'total' => '120.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_voided' => false,
            'is_training' => false,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReturnReceipt(Receipt $original, array $overrides = []): Receipt
    {
        $this->seq++;

        return Receipt::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('POS01-2026-%08d', $this->seq),
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $original->id,
            'return_reason' => ReturnReason::CustomerChangedMind,
            'chain_sequence' => $this->seq,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "return-{$this->seq}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'pay'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => '100.000',
            'tax_amount' => '20.000',
            'discount_amount' => '0.000',
            'total' => '120.000',
            'currency' => 'EUR',
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_voided' => false,
            'is_training' => false,
        ], $overrides));
    }
}
