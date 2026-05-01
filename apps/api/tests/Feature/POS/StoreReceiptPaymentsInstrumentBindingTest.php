<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Codex review B3 (2026-04-30) — online payment writer must persist
 * `instrument_type` and `instrument_serial` so a voucher-bearing tender
 * actually binds the voucher serial into the v3 fiscal hash.
 *
 * Without this test, a store-voucher payment posted via the online
 * `/pos/receipts/{id}/payments` endpoint would write a row with both
 * instrument fields set to NULL, and the v3 hash would be computed against
 * `instrument_serial = null` — defeating the chain's tamper-evidence for
 * voucher payments.
 *
 * Negative cases: an `instrument_type` without a serial (and the inverse)
 * are malformed inputs that the request validator must reject as 422 — never
 * reach the writer with half-configured instrument state.
 */
final class StoreReceiptPaymentsInstrumentBindingTest extends TestCase
{
    use RefreshDatabase;

    private static int $receiptCounter = 0;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private PaymentMethod $voucherMethod;

    private PaymentRepository $voucherRepo;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);

        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $voucherClearingAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '467',
            'name' => 'Voucher Clearing',
            'type' => 'liability',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Product Revenue',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        $this->voucherMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Store Voucher',
            'code' => 'store_voucher',
        ]);
        $this->voucherRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Voucher Clearing Repo',
            'code' => 'VOUCHER-01',
            'type' => RepositoryType::Virtual->value,
            'gl_account_id' => $voucherClearingAccount->id,
            'currency' => 'EUR',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->cashier->givePermissionTo('pos.operate_terminal');

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.000',
        ]);
    }

    public function test_voucher_payment_persists_instrument_type_and_serial(): void
    {
        $receipt = $this->seedReceipt('15.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '15.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                'instrument_type' => 'store_voucher',
                'instrument_serial' => 'SV-2026-0042',
            ]],
        ]);

        // 200 (legacy) or 201 (new ReceiptController contract) are both success
        // signals; the assertion below is the load-bearing one.
        $this->assertContains(
            $response->status(),
            [200, 201],
            'Expected successful response, got '.$response->status().': '.$response->getContent(),
        );

        $persistedPayment = ReceiptPayment::query()
            ->where('receipt_id', $receipt->id)
            ->firstOrFail();

        $this->assertSame(
            PaymentInstrumentKind::StoreVoucher,
            $persistedPayment->instrument_type,
            'instrument_type must persist as the StoreVoucher enum case',
        );
        $this->assertSame(
            'SV-2026-0042',
            $persistedPayment->instrument_serial,
            'instrument_serial must round-trip the voucher code passed by the client',
        );
        // Snapshot column was already proven by B2; assert it stayed correct here too.
        $this->assertSame('store_voucher', $persistedPayment->payment_method_code);
    }

    public function test_instrument_type_without_serial_returns_422(): void
    {
        $receipt = $this->seedReceipt('10.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                'instrument_type' => 'store_voucher',
                // instrument_serial deliberately omitted
            ]],
        ]);

        $response->assertStatus(422);
        // The exact JSON path for nested array errors differs between Laravel
        // versions / exception handlers; assert on the response body rather
        // than on assertJsonValidationErrors() which expects a specific
        // top-level "errors" key shape.
        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertStringContainsString('instrument_serial', (string) $body);
    }

    public function test_instrument_serial_without_type_returns_422(): void
    {
        $receipt = $this->seedReceipt('10.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                'instrument_serial' => 'SV-2026-0099',
                // instrument_type deliberately omitted
            ]],
        ]);

        $response->assertStatus(422);
        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertStringContainsString('instrument_type', (string) $body);
    }

    public function test_invalid_instrument_type_returns_422(): void
    {
        $receipt = $this->seedReceipt('10.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                'instrument_type' => 'crypto_token', // not in the enum
                'instrument_serial' => 'X-001',
            ]],
        ]);

        $response->assertStatus(422);
        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertStringContainsString('instrument_type', (string) $body);
    }

    private function seedReceipt(string $total): Receipt
    {
        return Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('T001-C001-L01-POS01-2026-%08d', ++self::$receiptCounter),
            'chain_sequence' => null,
            'receipt_year' => 2026,
            'fiscal_status' => FiscalStatus::PendingSeal,
            'fiscal_hash' => null,
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-'.uniqid()),
            'payment_methods_hash' => hash('sha256', 'pay-'.uniqid()),
            'posted_at' => Carbon::now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'currency' => 'EUR',
            'is_voided' => false,
            'is_training' => false,
        ]);
    }
}
