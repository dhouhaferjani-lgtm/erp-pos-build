<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
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
use App\Modules\Voucher\Domain\Voucher;
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
        $this->markTestSkipped(
            'Obsolete per fiscal Phase 1 §14.2 disposition — '.
            'POST /api/v1/pos/receipts/{id}/payments retired (HTTP 410). '.
            'Instrument-binding defense-in-depth lives at '.
            'PosCoreReceiptProjection::writePayments (throws '.
            'InstrumentRequiredException on voucher-like method_code '.
            'without instrument_type/serial). Pinned by '.
            'PosCoreReceiptProjectionTest::'.
            'test_voucher_payment_line_without_instrument_type_is_rejected_by_projection + '.
            'test_voucher_payment_line_without_instrument_serial_is_rejected_by_projection '.
            '(round-2 Codex T29-F3 closure). Device-side payload validation '.
            'in FiscalPayloadConstraintValidator covers monetary shape; '.
            'instrument-kind enforcement lives in the projector. '.
            'Pinned by NewSaleServerAuthoringDispositionTest.',
        );

        // Unreachable after the class-level skip — kept as documentation.
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

        // Codex review B5 (2026-05-01): seed the FULL chart of accounts so
        // VoucherRedemptionService::redeem (called from ReceiptPaymentService
        // for store_voucher tenders) can resolve VoucherLiability,
        // PosTenderClearing, and RoundingLossExpense by purpose.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $voucherClearingAccount = Account::where([
            'company_id' => $this->company->id,
            'system_purpose' => SystemAccountPurpose::PosTenderClearing->value,
        ])->firstOrFail();

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
        // Codex review B5 (2026-05-01): the success path now calls
        // VoucherRedemptionService::redeem against the instrument_serial.
        // Seed a real voucher so the redemption can resolve and complete.
        $voucher = $this->seedVoucher('SV-2026-0042', '50.00');

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

        // Codex review B5 (2026-05-01): the redemption ran end-to-end.
        // Voucher balance moved + Redeemed ledger row + GL journal posted.
        // Internal precision = currency_scale + 2 (5 for EUR).
        $voucher->refresh();
        $this->assertSame('35.00000', $voucher->current_balance); // 50 - 15
        $this->assertDatabaseHas('voucher_ledger', [
            'voucher_id' => $voucher->id,
            'event' => 'redeemed',
            'receipt_id' => $receipt->id,
        ]);
    }

    private function seedVoucher(string $code, string $balance): Voucher
    {
        return Voucher::factory()
            ->forTerminal($this->terminal)
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $code,
                'currency' => 'EUR',
                'initial_balance' => $balance,
                'current_balance' => $balance,
                'issued_by_user_id' => $this->cashier->id,
            ]);
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

    /**
     * Codex review B4 (2026-04-30): the both-or-neither rule is not enough.
     * A `payment_methods.code = store_voucher` row with BOTH instrument fields
     * null still passes the both-or-neither check (both are absent), and the
     * v3 fiscal hash then binds `method_code = store_voucher` and
     * `instrument_serial = null` — same fiscal-integrity failure class as B2/B3.
     *
     * The validator must reject this: when the resolved PaymentMethod's code
     * is instrument-bearing (per PaymentInstrumentKind enum), both fields are
     * required. This test was the failing-first proof for the new rule and
     * MUST pass on HEAD.
     */
    public function test_store_voucher_method_with_null_instrument_fields_returns_422(): void
    {
        $receipt = $this->seedReceipt('10.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                // Both instrument fields deliberately omitted — this is the
                // exact stale-client / cooperative-bypass shape Codex flagged.
            ]],
        ]);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        // Both keys must surface so the error UI can guide the cashier.
        $this->assertStringContainsString('instrument_type', $body);
        $this->assertStringContainsString('instrument_serial', $body);
        // The error message names the payment method code so an integrator
        // can wire the cause back to the offending tender row.
        $this->assertStringContainsString('store_voucher', $body);
    }

    public function test_store_voucher_method_with_empty_string_instrument_fields_returns_422(): void
    {
        $receipt = $this->seedReceipt('10.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->voucherMethod->id,
                'repository_id' => $this->voucherRepo->id,
                // Empty strings must be treated the same as null — otherwise a
                // client could submit `""` and silently bypass enforcement.
                'instrument_type' => '',
                'instrument_serial' => '',
            ]],
        ]);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('instrument_type', $body);
        $this->assertStringContainsString('instrument_serial', $body);
    }

    public function test_restaurant_voucher_method_with_null_instrument_fields_returns_422(): void
    {
        // Seed a separate restaurant_voucher PaymentMethod for this tenant —
        // the rule is enum-derived, not store-voucher-only.
        $restaurantMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Restaurant Voucher',
            'code' => 'restaurant_voucher',
        ]);

        $receipt = $this->seedReceipt('10.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $restaurantMethod->id,
                'repository_id' => $this->voucherRepo->id,
            ]],
        ]);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('restaurant_voucher', $body);
    }

    public function test_gift_card_method_with_null_instrument_fields_returns_422(): void
    {
        $giftCardMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Gift Card',
            'code' => 'gift_card',
        ]);

        $receipt = $this->seedReceipt('10.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $giftCardMethod->id,
                'repository_id' => $this->voucherRepo->id,
            ]],
        ]);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('gift_card', $body);
    }

    /**
     * Positive control for B4: a non-instrument-bearing method (cash) with
     * null instrument fields must still pass. The B4 rule is value-conditional —
     * it only bites for store_voucher / restaurant_voucher / gift_card method
     * codes; cash/card rows continue to land with no instrument metadata.
     */
    public function test_cash_method_with_null_instrument_fields_still_succeeds(): void
    {
        // Reuse the Cash system_purpose account seeded in setUp() — only one
        // account per (company_id, system_purpose) is permitted by the unique
        // constraint, so creating a second `system_purpose = Cash` row here
        // would fail the integrity check.
        $cashGl = Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::Cash)
            ->firstOrFail();
        $cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'cash',
        ]);
        $cashRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash Drawer',
            'code' => 'CASH-01',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashGl->id,
        ]);

        $receipt = $this->seedReceipt('10.000');
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $cashMethod->id,
                'repository_id' => $cashRepo->id,
                // No instrument fields — this is the legitimate cash-only shape.
            ]],
        ]);

        $this->assertContains(
            $response->status(),
            [200, 201],
            'Cash payment with null instrument fields must succeed; got '.$response->status().': '.$response->getContent(),
        );

        $persistedPayment = ReceiptPayment::query()
            ->where('receipt_id', $receipt->id)
            ->firstOrFail();
        $this->assertNull($persistedPayment->instrument_type);
        $this->assertNull($persistedPayment->instrument_serial);
        $this->assertSame('cash', $persistedPayment->payment_method_code);
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
