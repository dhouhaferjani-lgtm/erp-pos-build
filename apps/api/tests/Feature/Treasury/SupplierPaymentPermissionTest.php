<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * F-W2-14 residual (a) — `POST /api/v1/payments` is TWO acts behind one route
 * gate (`can:payments.create`, which a cashier holds so a till can take a
 * customer payment).
 *
 * The wave-2 browser run measured a `cashier` paying a SUPPLIER: 201, with a
 * cash movement OUT of the repository (`docs/superpowers/reviews/2026-09-01-wave2-po-evidence.md:137`).
 * `SupplierPaymentAuthorizer` splits the two by the shape of the REQUEST — never
 * by role — and requires the dedicated `payments.pay-supplier` on the AP side.
 *
 * These tests pin all four corners: the AP deny, the AP allow, the AR
 * regression (a cashier's customer payment is untouched), and grantability (a
 * user granted the permission directly may pay a supplier even though their
 * role's default does not include it — owner ruling 2026-09-07).
 */
final class SupplierPaymentPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private PaymentMethod $bankMethod;

    private Partner $supplier;

    private Partner $customer;

    private Account $bankAccount;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'F-W2-14 Payment Perms Tenant',
            'slug' => 'fw214-pay-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'F-W2-14 Payment Perms Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->bankMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-PERM',
            'name' => 'Bank',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'F-W2-14 Supplier',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'F-W2-14 Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'REPO-PERM',
            'name' => 'Perm Bank Repo',
            'type' => RepositoryType::BankAccount,
            'balance' => '5000.000',
            'gl_account_id' => $this->bankAccount->id,
            'is_active' => true,
        ]);
    }

    public function test_cashier_cannot_pay_a_supplier_invoice_and_no_cash_leaves_the_repository(): void
    {
        $cashier = $this->makeUser('cashier', 'cashier-pay@example.com');
        $invoice = $this->makePayableSupplierInvoice('300.000');
        // The repository balance is trigger-maintained (direct writes are
        // refused), so the baseline is read back rather than assumed.
        $balanceBefore = (string) $this->repository->fresh()?->balance;

        $response = $this->actingAs($cashier)->postJson('/api/v1/payments', $this->supplierPayload($invoice, '300.00'));

        $response->assertForbidden();

        // No payment, no allocation, no journal entry, and — the measured harm —
        // the repository balance is untouched.
        $this->assertDatabaseMissing('payments', ['partner_id' => $this->supplier->id]);
        $this->assertDatabaseMissing('payment_allocations', ['document_id' => $invoice->id]);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'supplier_payment']);
        $this->assertSame($balanceBefore, (string) $this->repository->fresh()?->balance);
        $this->assertDatabaseMissing('repository_movements', ['payment_repository_id' => $this->repository->id]);
    }

    public function test_cashier_cannot_make_an_on_account_payment_to_a_supplier(): void
    {
        $cashier = $this->makeUser('cashier', 'cashier-advance@example.com');

        $response = $this->actingAs($cashier)->postJson('/api/v1/payments', [
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $this->bankMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '50.00',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('payments', ['partner_id' => $this->supplier->id]);
    }

    public function test_manager_can_pay_a_supplier_invoice(): void
    {
        $manager = $this->makeUser('manager', 'manager-pay@example.com');
        $invoice = $this->makePayableSupplierInvoice('300.000');

        $response = $this->actingAs($manager)->postJson('/api/v1/payments', $this->supplierPayload($invoice, '300.00'));

        $response->assertCreated();
        $this->assertDatabaseHas('payments', ['partner_id' => $this->supplier->id]);
    }

    /**
     * Grantability (owner ruling 2026-09-07): the secure default is a DEFAULT.
     * `operator` does not get `payments.pay-supplier` from the seeder, but an
     * administrator may grant it to that user (or to a custom role) through the
     * permissions surface, and the gate must then let them through. Nothing in
     * the check reads a role name.
     */
    public function test_operator_granted_the_permission_directly_can_pay_a_supplier_invoice(): void
    {
        $operator = $this->makeUser('operator', 'operator-pay@example.com');
        $this->assertFalse($operator->can('payments.pay-supplier'));

        $operator->givePermissionTo('payments.pay-supplier');
        $operator->unsetRelation('permissions')->unsetRelation('roles');
        $this->assertTrue($operator->fresh()?->can('payments.pay-supplier'));

        $invoice = $this->makePayableSupplierInvoice('300.000');

        $response = $this->actingAs($operator->fresh())
            ->postJson('/api/v1/payments', $this->supplierPayload($invoice, '300.00'));

        $response->assertCreated();
    }

    /**
     * Gate r2 finding 2 — PIN the deliberate `PartnerType::Both` exclusion.
     *
     * A `both`-typed partner with NO supplier document named is an inbound
     * receipt, not a supplier payment: `PaymentController::store()` derives
     * `$isSupplierPayment` solely from a `SupplierInvoice` allocation, and the
     * movement direction keys on that flag. The authorizer therefore lets a
     * cashier through — which is only correct WHILE that coupling holds. This
     * test asserts the coupling itself (direction `in`, money ARRIVING), so if
     * direction ever becomes partner-derived the `Both` arm stops being a silent
     * hole and fails here instead.
     */
    public function test_cashier_may_take_an_inbound_receipt_from_a_both_typed_partner(): void
    {
        $cashier = $this->makeUser('cashier', 'cashier-both@example.com');
        $both = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'F-W2-14 Both Partner',
            'type' => PartnerType::Both,
            'is_active' => true,
        ]);

        $response = $this->actingAs($cashier)->postJson('/api/v1/payments', [
            'partner_id' => $both->id,
            'payment_method_id' => $this->bankMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '300.00',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', ['partner_id' => $both->id]);
        // The load-bearing half: money came IN. A supplier payment would be 'out'.
        $this->assertDatabaseHas('repository_movements', [
            'payment_repository_id' => $this->repository->id,
            'direction' => 'in',
        ]);
        $this->assertDatabaseMissing('repository_movements', [
            'payment_repository_id' => $this->repository->id,
            'direction' => 'out',
        ]);
    }

    /**
     * Gate r2 finding 4 — the idempotency replay must not be the one path around
     * the gate. A cashier who learns a manager's Idempotency-Key used to get 200
     * with the full supplier-payment payload; the replay arm now re-takes the
     * verdict against the PERSISTED payment.
     */
    public function test_cashier_cannot_replay_a_managers_supplier_payment_idempotency_key(): void
    {
        $manager = $this->makeUser('manager', 'manager-idem@example.com');
        $cashier = $this->makeUser('cashier', 'cashier-idem@example.com');
        $invoice = $this->makePayableSupplierInvoice('300.000');

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'F-W2-14-REPLAY-1')
            ->postJson('/api/v1/payments', $this->supplierPayload($invoice, '300.00'))
            ->assertCreated();

        $this->actingAs($cashier)
            ->withHeader('Idempotency-Key', 'F-W2-14-REPLAY-1')
            ->postJson('/api/v1/payments', $this->supplierPayload($invoice, '300.00'))
            ->assertForbidden();

        // The manager's own retry still replays cleanly — no second payment row.
        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'F-W2-14-REPLAY-1')
            ->postJson('/api/v1/payments', $this->supplierPayload($invoice, '300.00'))
            ->assertOk();

        $this->assertSame(1, Payment::query()->where('partner_id', $this->supplier->id)->count());
    }

    /**
     * The AR regression: the cashier flow this permission must not touch.
     */
    public function test_cashier_can_still_take_a_customer_payment(): void
    {
        $cashier = $this->makeUser('cashier', 'cashier-customer@example.com');

        $customerInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-PERM-'.Str::upper(Str::random(4)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '120.000',
            'tax_amount' => '0.000',
            'total' => '120.000',
            'balance_due' => '120.000',
        ]);

        $response = $this->actingAs($cashier)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->bankMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '120.00',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $customerInvoice->id, 'amount' => '120.00'],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payments', ['partner_id' => $this->customer->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierPayload(Document $invoice, string $amount): array
    {
        return [
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $this->bankMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => $amount],
            ],
        ];
    }

    /**
     * A Posted supplier invoice carrying a real posted Cr-401 journal entry —
     * the only shape `PaymentController::store()` will settle (B1 guard).
     */
    private function makePayableSupplierInvoice(string $total): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,
            'document_number' => 'SI-PERM-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $total,
        ]);

        $poster = $this->makeUser('admin', 'poster-'.Str::lower(Str::random(6)).'@example.com');
        $expenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseExpenses);

        DB::transaction(function () use ($invoice, $expenseAccount, $poster, $total): void {
            app(GeneralLedgerService::class)->createSupplierInvoiceJournalEntry(
                companyId: $this->company->id,
                partnerId: $this->supplier->id,
                invoiceId: $invoice->id,
                totalAmount: $total,
                netAmount: $total,
                vatAmount: '0.000',
                expenseAccountId: $expenseAccount->id,
                date: new \DateTimeImmutable('now'),
                user: $poster,
                description: 'F-W2-14 permission fixture',
                currencyCode: 'TND',
            );
        });

        return $invoice;
    }

    private function makeUser(string $role, string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'F-W2-14 '.$role,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $user->assignRole($role);

        return $user;
    }
}
