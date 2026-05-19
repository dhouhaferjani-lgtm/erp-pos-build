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
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tenant-isolation regression for POS payments.
 *
 * Reproduces the exploit chain documented in
 * `memory/project_payment_method_tenant_isolation.md`: tenant B authenticated
 * to its own POS endpoint can submit tenant A's `payment_method_id`,
 * `repository_id`, or `customer_id` because the validator's bare `exists:`
 * rules and the service-layer `findOrFail()` calls are unscoped.
 *
 * Each test must reject the cross-tenant submission with a 4xx response and
 * leave the receipt unfiscalized with no payment row written.
 */
final class StoreReceiptPaymentsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private static int $receiptCounter = 0;

    private Tenant $tenantA;

    private Company $companyA;

    private PaymentMethod $methodA;

    private PaymentRepository $repoA;

    private Partner $partnerA;

    private Tenant $tenantB;

    private Company $companyB;

    private Location $locationB;

    private Terminal $terminalB;

    private PaymentMethod $methodB;

    private PaymentRepository $repoB;

    private User $cashierB;

    /**
     * Per-method route-skip rationale (round-2 Codex T29-F4 P2):
     *
     * Round-1 used a class-level skip that ALSO disabled
     * test_service_rejects_cross_tenant_customer_id_when_form_request_is_bypassed,
     * a defense-in-depth assertion that calls ReceiptPaymentService directly
     * (no HTTP route). ReceiptPaymentService is still production code
     * reachable via ExchangeService::processExchange (§14.3 re-grep note),
     * so the service-bypass test is queued to stay live.
     *
     * Route-dependent methods (POST /pos/receipts/{id}/payments) remain
     * skipped under §14.2 disposition — their cross-tenant payment-method
     * defense moves to PosCoreReceiptProjection (Task 21) +
     * TreasuryReceiptBridge (Task 22) for the SALE_RECEIPT fiscal-event
     * path.
     */
    private const ROUTE_SKIP_REASON =
        'Obsolete per fiscal Phase 1 §14.2 disposition — '.
        'POST /api/v1/pos/receipts/{id}/payments retired (HTTP 410). '.
        'Cross-tenant payment rejection now lives on TreasuryReceiptBridge (Task 22) for '.
        'fiscal-event projected writes, and on PosCoreReceiptProjection (Task 21) for the '.
        'pos_receipt_payments mirror. Both pin the same cross-tenant defense end-to-end. '.
        'Pinned by NewSaleServerAuthoringDispositionTest.';

    protected function setUp(): void
    {
        parent::setUp();

        Country::create([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'currency_symbol' => '€',
        ]);

        // ---- Tenant A: the "victim" tenant whose IDs we must NOT accept ----
        $this->tenantA = Tenant::factory()->create();
        $this->companyA = Company::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        app(ChartOfAccountsService::class)->seedForCompany($this->companyA);
        $cashGlA = Account::query()
            ->where('company_id', $this->companyA->id)
            ->where('system_purpose', SystemAccountPurpose::Cash)
            ->firstOrFail();

        $this->methodA = PaymentMethod::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Cash A',
            'code' => 'cash',
        ]);
        $this->repoA = PaymentRepository::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Cash Drawer A',
            'code' => 'CASH-A',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashGlA->id,
        ]);
        $this->partnerA = Partner::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
        ]);

        // ---- Tenant B: the "attacker" tenant authenticated against the API ----
        $this->tenantB = Tenant::factory()->create();
        $this->companyB = Company::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        $this->locationB = Location::factory()->create(['company_id' => $this->companyB->id]);
        $this->terminalB = Terminal::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'location_id' => $this->locationB->id,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->companyB);
        $cashGlB = Account::query()
            ->where('company_id', $this->companyB->id)
            ->where('system_purpose', SystemAccountPurpose::Cash)
            ->firstOrFail();

        $this->methodB = PaymentMethod::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Cash B',
            'code' => 'cash',
        ]);
        $this->repoB = PaymentRepository::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Cash Drawer B',
            'code' => 'CASH-B',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashGlB->id,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');

        $this->cashierB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->cashierB->id,
            'company_id' => $this->companyB->id,
            'role' => 'cashier',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->cashierB->givePermissionTo('pos.operate_terminal');

        Shift::create([
            'terminal_id' => $this->terminalB->id,
            'cashier_id' => $this->cashierB->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.000',
        ]);
    }

    public function test_cross_tenant_payment_method_id_is_rejected(): void
    {
        $this->markTestSkipped(self::ROUTE_SKIP_REASON);

        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                // Tenant A's payment_method_id submitted by tenant B's cashier.
                'payment_method_id' => $this->methodA->id,
                'repository_id' => $this->repoB->id,
            ]],
        ]);

        // Codex review #8 (2026-05-01): pin the specific error key so the
        // rejection origin is unambiguous. A 422 from a different rule (e.g.,
        // exists falling through to company_id) would silently mask a hole in
        // the tenant_id predicate. The codebase emits a custom 422 envelope
        // that doesn't match assertJsonValidationErrors's default shape (see
        // StoreReceiptPaymentsInstrumentBindingTest comment), so we match on
        // the field name and the customized "does not exist" message text.
        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('payment_method_id', $body);
        $this->assertStringContainsString('Payment method does not exist', $body);

        $receipt->refresh();
        $this->assertSame(
            FiscalStatus::PendingSeal,
            $receipt->fiscal_status,
            'Receipt must NOT advance to fiscalized when a cross-tenant payment_method_id was submitted',
        );
        $this->assertSame(
            0,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
            'No ReceiptPayment row may be written for a cross-tenant submission',
        );
    }

    public function test_cross_tenant_repository_id_is_rejected(): void
    {
        $this->markTestSkipped(self::ROUTE_SKIP_REASON);

        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->methodB->id,
                // Tenant A's repository_id submitted by tenant B's cashier.
                'repository_id' => $this->repoA->id,
            ]],
        ]);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('repository_id', $body);
        $this->assertStringContainsString('Payment repository does not exist', $body);

        $receipt->refresh();
        $this->assertSame(FiscalStatus::PendingSeal, $receipt->fiscal_status);
        $this->assertSame(
            0,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
        );
    }

    public function test_cross_tenant_customer_id_is_rejected(): void
    {
        $this->markTestSkipped(self::ROUTE_SKIP_REASON);

        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->methodB->id,
                'repository_id' => $this->repoB->id,
            ]],
            // Tenant A's partner_id submitted as the customer for tenant B's sale.
            'customer_id' => $this->partnerA->id,
        ]);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('customer_id', $body);
        $this->assertStringContainsString('Customer does not exist', $body);

        $receipt->refresh();
        $this->assertSame(FiscalStatus::PendingSeal, $receipt->fiscal_status);
        $this->assertSame(
            0,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
        );
    }

    /**
     * Codex review #4 (2026-05-01): same-tenant/cross-company case.
     *
     * The original cross-tenant tests differ on BOTH tenant AND company, so a
     * 422 from the company predicate alone would silently mask a hole in the
     * tenant predicate (and vice versa). This test isolates the company axis:
     * tenant B is the same, but the payment_method_id and repository_id belong
     * to a SECOND company on the same tenant. The validator must still reject.
     */
    public function test_same_tenant_cross_company_is_rejected(): void
    {
        $this->markTestSkipped(self::ROUTE_SKIP_REASON);

        $companyB2 = Company::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        app(ChartOfAccountsService::class)->seedForCompany($companyB2);
        $cashGlB2 = Account::query()
            ->where('company_id', $companyB2->id)
            ->where('system_purpose', SystemAccountPurpose::Cash)
            ->firstOrFail();

        $methodB2 = PaymentMethod::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $companyB2->id,
            'name' => 'Cash B2',
            'code' => 'cash',
        ]);
        $repoB2 = PaymentRepository::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $companyB2->id,
            'name' => 'Cash Drawer B2',
            'code' => 'CASH-B2',
            'type' => RepositoryType::CashRegister->value,
            'gl_account_id' => $cashGlB2->id,
        ]);

        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $methodB2->id,
                'repository_id' => $repoB2->id,
            ]],
        ]);

        $response->assertStatus(422);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('payment_method_id', $body);
        $this->assertStringContainsString('repository_id', $body);
        $this->assertStringContainsString('Payment method does not exist', $body);
        $this->assertStringContainsString('Payment repository does not exist', $body);

        $receipt->refresh();
        $this->assertSame(FiscalStatus::PendingSeal, $receipt->fiscal_status);
        $this->assertSame(
            0,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
        );
    }

    /**
     * Codex review #5 (2026-05-01): service-layer bypass for programmatic
     * callers.
     *
     * StoreReceiptPaymentsRequest is the HTTP boundary; queue jobs, internal
     * flows, console commands, and future controllers can call
     * ReceiptPaymentService::processReceiptPayments() directly with a raw
     * customerId argument. Without explicit Partner scoping in the service,
     * a programmatic caller can persist tenant A's partner_id onto tenant B's
     * treasury_payment row — exactly the exploit class the HTTP fix closed at
     * the validator. The service must reject this with ModelNotFoundException
     * before any GL or payment write.
     */
    public function test_service_rejects_cross_tenant_customer_id_when_form_request_is_bypassed(): void
    {
        $receipt = $this->seedReceiptForTenantB('10.000');

        // The service reads CompanyContext for the resolved company; set it
        // to tenant B's company so the service path is reached as if a
        // legitimate internal caller were running in tenant B's context.
        app(CompanyContext::class)->setCompanyId($this->companyB->id);

        $this->expectException(ModelNotFoundException::class);

        try {
            app(ReceiptPaymentService::class)->processReceiptPayments(
                receiptId: $receipt->id,
                payments: [[
                    'amount' => '10.000',
                    'payment_method_id' => $this->methodB->id,
                    'repository_id' => $this->repoB->id,
                ]],
                // Tenant A's partner_id smuggled into tenant B's payment.
                customerId: $this->partnerA->id,
            );
        } finally {
            // Defense-in-depth: regardless of the throw, no payment row may
            // exist. If the test fails at expectException but a payment was
            // written, that is the real exploit and the assertion below must
            // surface it.
            $this->assertSame(
                0,
                ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
                'No ReceiptPayment row may be written when cross-tenant customer_id is submitted via service',
            );
        }
    }

    public function test_same_tenant_payment_succeeds_as_control(): void
    {
        $this->markTestSkipped(self::ROUTE_SKIP_REASON);

        // Positive control: the legitimate same-tenant shape MUST still succeed.
        // If this fails, the tenant-scoping fix is too aggressive.
        $receipt = $this->seedReceiptForTenantB('10.000');
        Sanctum::actingAs($this->cashierB);

        $response = $this->postJson("/api/v1/pos/receipts/{$receipt->id}/payments", [
            'payments' => [[
                'amount' => '10.000',
                'payment_method_id' => $this->methodB->id,
                'repository_id' => $this->repoB->id,
            ]],
        ]);

        $this->assertContains(
            $response->status(),
            [200, 201],
            'Same-tenant payment must succeed; got '.$response->status().': '.$response->getContent(),
        );

        $this->assertSame(
            1,
            ReceiptPayment::query()->where('receipt_id', $receipt->id)->count(),
            'Exactly one ReceiptPayment row expected for the legitimate single-tender path',
        );
    }

    private function seedReceiptForTenantB(string $total): Receipt
    {
        return Receipt::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'location_id' => $this->locationB->id,
            'terminal_id' => $this->terminalB->id,
            'receipt_number' => sprintf('T002-C001-L01-POS01-2026-%08d', ++self::$receiptCounter),
            'chain_sequence' => null,
            'receipt_year' => 2026,
            'fiscal_status' => FiscalStatus::PendingSeal,
            'fiscal_hash' => null,
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-'.uniqid()),
            'payment_methods_hash' => hash('sha256', 'pay-'.uniqid()),
            'posted_at' => Carbon::now(),
            'cashier_id' => $this->cashierB->id,
            'cashier_name' => $this->cashierB->name,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'currency' => 'EUR',
            'is_voided' => false,
            'is_training' => false,
        ]);
    }
}
