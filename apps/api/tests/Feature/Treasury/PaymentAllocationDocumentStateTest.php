<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * What STATE a document is in, and how much of it is actually still open, decided
 * per allocation in `PaymentController::store()`.
 *
 * W-6 D2 (this file's first case): the per-allocation cap read
 * `$document->balance_due ?? $document->total`. `balance_due` is a PostgreSQL
 * trigger cache fired by allocation DML only, and it is NOT re-derived when an
 * allocation is written by anything other than that trigger (SQLite has no
 * trigger at all), so the `?? total` fallback offered the FULL total of a document
 * that was already partly settled.
 *
 * docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md (D2)
 * docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md (F-6)
 */
final class PaymentAllocationDocumentStateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Allocation State Tenant',
            'slug' => 'allocation-state-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Allocation State Company',
            'legal_name' => 'Allocation State Company SARL',
            'tax_id' => 'TAX-ALLOC-STATE',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Allocation State User',
            'email' => 'allocation-state@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.create', 'payments.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Clinique Al Amal',
            'type' => PartnerType::Customer,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
            'is_active' => true,
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $this->repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Main Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => '0.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'account_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_allocation_is_capped_at_the_computed_outstanding_not_the_full_total(): void
    {
        $invoice = $this->invoice('INV-PARTIAL-CACHE', '200.000');
        // The D2 shape: an allocation exists but the cache was never written, so
        // the old `balance_due ?? total` fallback offered the whole 200.000.
        PaymentAllocation::create(['document_id' => $invoice->id, 'amount' => '150.000']);
        $invoice->update(['balance_due' => null]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '200.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-CAP-001',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '200.000'],
            ],
        ]);

        $response->assertCreated();

        $allocated = PaymentAllocation::query()->where('document_id', $invoice->id)->sum('amount');
        self::assertSame(
            0,
            bccomp((string) $allocated, '200.000', 3),
            'The 200.000 invoice must never be allocated beyond its total: 150.000 already + 50.000 capped',
        );
    }

    /**
     * W-7 F-6 — the headline shape, reproduced exactly as the campaign found it:
     * session A cancels a posted invoice, session B (holding a stale page) records
     * the payment against it. It used to return 201 and rewrite the document to
     * `paid` with `cancelled_at` still populated — a cancelled sale reappearing as
     * collected revenue in every report that keys on `documents.status`.
     */
    public function test_a_payment_cannot_be_allocated_to_a_cancelled_invoice(): void
    {
        $invoice = $this->cancelledInvoice('INV-CANCELLED', '200.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '200.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-CANCELLED-001',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '200.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');

        $invoice->refresh();
        self::assertSame(DocumentStatus::Cancelled, $invoice->status, 'A terminal status must never be rewritten');
        self::assertNotNull($invoice->cancelled_at);
        self::assertSame(0, PaymentAllocation::query()->where('document_id', $invoice->id)->count());
        self::assertDatabaseMissing('payments', ['reference' => 'PAY-CANCELLED-001']);
    }

    /**
     * The guard must not depend on the `balance_due` cache being warm — W-7 F-6
     * escalation (a): a never-allocated cancelled invoice has a NULL cache, so the
     * amount path presented its FULL total as payable with no crafted input.
     */
    public function test_the_guard_holds_while_balance_due_is_null(): void
    {
        $invoice = $this->cancelledInvoice('INV-CANCELLED-NULL-CACHE', '200.000', balanceDue: null);
        self::assertNull($invoice->balance_due);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '200.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-CANCELLED-002',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '200.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        self::assertSame(DocumentStatus::Cancelled, $invoice->refresh()->status);
    }

    public function test_the_multi_payment_path_refuses_a_cancelled_document(): void
    {
        $invoice = $this->cancelledInvoice('INV-CANCELLED-MULTI', '200.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'document_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'repository_id' => $this->repository->id,
                    'amount' => '200.000',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        self::assertSame(DocumentStatus::Cancelled, $invoice->refresh()->status);
    }

    public function test_the_smart_payment_manual_preview_refuses_a_cancelled_invoice(): void
    {
        $invoice = $this->cancelledInvoice('INV-CANCELLED-PREVIEW', '200.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/preview-allocation', [
            'partner_id' => $this->customer->id,
            'payment_amount' => '200.000',
            'allocation_method' => 'manual',
            'manual_allocations' => [
                ['document_id' => $invoice->id, 'amount' => '200.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
    }

    private function cancelledInvoice(string $number, string $total, ?string $balanceDue = null): Document
    {
        $invoice = $this->invoice($number, $total, $balanceDue ?? $total);
        $invoice->update([
            'status' => DocumentStatus::Cancelled,
            'fiscal_status' => FiscalStatus::Voided,
            'cancelled_at' => now(),
            'balance_due' => $balanceDue,
        ]);

        return $invoice->refresh();
    }

    private function invoice(string $number, string $total, ?string $balanceDue = null): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $balanceDue,
            'currency' => 'TND',
        ]);
    }
}
