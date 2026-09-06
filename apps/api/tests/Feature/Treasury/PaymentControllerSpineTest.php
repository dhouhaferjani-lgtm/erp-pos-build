<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
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
 * Treasury spine (Task 16): PaymentController::store() must write the repository
 * balance EXACTLY ONCE through the movement write port — not via the old inline
 * `$repository->balance = bcadd/bcsub(...)` + hand-fired `RepositoryBalanceChanged`.
 *
 * Each accepted payment produces exactly one append-only `repository_movements`
 * row (source_type=payment; direction in for customer, out for supplier) linked
 * to the posted journal entry, and the cached balance moves by the amount ONCE
 * (never the old inline write AND the port — migration-safety invariant).
 */
class PaymentControllerSpineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $receivableAccount;

    private Account $revenueAccount;

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Spine Tenant',
            'slug' => 'spine-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Spine Company',
            'legal_name' => 'Spine Company LLC',
            'tax_id' => 'TAX-SPINE',
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
            'name' => 'Spine User',
            'email' => 'spine@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.create', 'payments.view', 'payments.pay-supplier']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);
        $this->receivableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerReceivable);
        $this->revenueAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::ProductRevenue);

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
    }

    /** @test */
    public function customer_payment_records_one_in_movement_linked_to_the_posted_journal_entry(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Spine Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->postOpeningReceivable($partner, '300.000');

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-SPINE-001',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '300.000',
            'tax_amount' => '0.000',
            'total' => '300.000',
            'balance_due' => '300.000',
            'currency' => 'TND',
        ]);

        $repository = $this->makeLedgeredRepository();

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '120.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-SPINE-AR-001',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '120.000'],
            ],
        ]);

        $response->assertCreated();
        $paymentId = $response->json('data.id');

        // Exactly one payment row.
        $this->assertSame(1, DB::table('payments')->where('id', $paymentId)->count());

        // The posted customer_payment journal entry.
        $entry = JournalEntry::query()
            ->where('source_type', 'customer_payment')
            ->where('source_id', $paymentId)
            ->first();
        $this->assertNotNull($entry, 'Customer payment must post a journal entry.');
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // payment.journal_entry_id is linked.
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'journal_entry_id' => $entry->id,
        ]);

        // EXACTLY ONE movement row: source=payment, direction=in, linked to the JE.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repository->id)
            ->where('source_type', 'payment')
            ->where('source_id', $paymentId)
            ->get();

        $this->assertCount(1, $movements, 'Exactly one repository movement per payment.');
        $movement = $movements->first();
        $this->assertSame('in', $movement->direction);
        $this->assertSame(0, bccomp((string) $movement->amount, '120.000', 3));
        $this->assertSame($entry->id, $movement->journal_entry_id, 'Movement must link to the posted JE.');

        // Balance moved UP by the amount exactly ONCE (not twice — no inline + port).
        $repository->refresh();
        $this->assertSame(0, bccomp((string) $repository->balance, '120.000', 3), 'Balance must move once by the amount.');
        $this->assertSame(0, bccomp((string) $movement->balance_after, '120.000', 3));
    }

    /** @test */
    public function supplier_payment_records_one_out_movement_and_moves_balance_down_once(): void
    {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Spine Supplier',
            'type' => PartnerType::Supplier,
        ]);

        $expenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseExpenses);

        $supplierInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'document_number' => 'SINV-SPINE-001',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '100.000',
            'currency' => 'TND',
        ]);

        app(GeneralLedgerService::class)->createSupplierInvoiceJournalEntry(
            companyId: $this->company->id,
            partnerId: $supplier->id,
            invoiceId: $supplierInvoice->id,
            totalAmount: '100.000',
            netAmount: '100.000',
            vatAmount: '0.000',
            expenseAccountId: $expenseAccount->id,
            date: now(),
            user: $this->user,
            currencyCode: 'TND',
        );

        // Fund the repository so an outflow has something to move.
        $repository = $this->makeLedgeredRepository('500.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $supplier->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '100.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-SPINE-AP-001',
            'allocations' => [
                ['document_id' => $supplierInvoice->id, 'amount' => '100.000'],
            ],
        ]);

        $response->assertCreated();
        $paymentId = $response->json('data.id');

        $entry = JournalEntry::query()
            ->where('source_type', 'supplier_payment')
            ->where('source_id', $paymentId)
            ->first();
        $this->assertNotNull($entry, 'Supplier payment must post a journal entry.');
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repository->id)
            ->where('source_type', 'payment')
            ->where('source_id', $paymentId)
            ->get();

        $this->assertCount(1, $movements, 'Exactly one repository movement per supplier payment.');
        $movement = $movements->first();
        $this->assertSame('out', $movement->direction);
        $this->assertSame(0, bccomp((string) $movement->amount, '100.000', 3));
        $this->assertSame($entry->id, $movement->journal_entry_id);

        // Balance moved DOWN by the amount exactly once: 500 - 100 = 400.
        $repository->refresh();
        $this->assertSame(0, bccomp((string) $repository->balance, '400.000', 3), 'Balance must move down once by the amount.');
        $this->assertSame(0, bccomp((string) $movement->balance_after, '400.000', 3));
    }

    /** @test */
    public function balance_moves_exactly_once_no_double_count(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Spine Customer 2',
            'type' => PartnerType::Customer,
        ]);

        $this->postOpeningReceivable($partner, '80.000');

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-SPINE-002',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '80.000',
            'tax_amount' => '0.000',
            'total' => '80.000',
            'balance_due' => '80.000',
            'currency' => 'TND',
        ]);

        $repository = $this->makeLedgeredRepository();

        $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '80.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-SPINE-AR-002',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '80.000'],
            ],
        ])->assertCreated();

        // If both the old inline write AND the port had run, balance would be 160.
        $repository->refresh();
        $this->assertSame(0, bccomp((string) $repository->balance, '80.000', 3), 'Balance must NOT be double-counted.');

        $this->assertSame(
            1,
            DB::table('repository_movements')->where('payment_repository_id', $repository->id)->count(),
            'Exactly one movement row — no double write.',
        );
    }

    /**
     * Reconciliation-readiness (Fix 2 / store): a PURE-ADVANCE customer payment (no
     * open invoices → the whole amount is booked as a customer advance) must record
     * a cash movement carrying the ADVANCE journal entry — never a null-JE movement.
     *
     * @test
     */
    public function pure_advance_customer_payment_records_movement_linked_to_the_advance_journal_entry(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Advance Customer',
            'type' => PartnerType::Customer,
        ]);

        $repository = $this->makeLedgeredRepository();

        // No allocations → the entire amount is a pure advance.
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '90.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-SPINE-ADV-001',
        ]);

        $response->assertCreated();
        $paymentId = $response->json('data.id');

        // The customer-advance journal entry (source_type 'advance').
        $advanceEntry = JournalEntry::query()
            ->where('source_type', 'advance')
            ->where('source_id', $paymentId)
            ->first();
        $this->assertNotNull($advanceEntry, 'Pure advance must post a customer-advance journal entry.');

        // The payment links the advance JE and is typed Advance.
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'journal_entry_id' => $advanceEntry->id,
            'payment_type' => PaymentType::Advance->value,
        ]);

        // EXACTLY ONE cash movement, carrying the advance JE (NOT null).
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repository->id)
            ->where('source_type', 'payment')
            ->where('source_id', $paymentId)
            ->get();

        $this->assertCount(1, $movements, 'Exactly one repository movement for the advance payment.');
        $movement = $movements->first();
        $this->assertSame('in', $movement->direction);
        $this->assertNotNull($movement->journal_entry_id, 'Movement must NOT carry a null journal entry.');
        $this->assertSame($advanceEntry->id, $movement->journal_entry_id, 'Movement must link to the advance JE.');
        $this->assertSame(0, bccomp((string) $movement->amount, '90.000', 3));

        // Balance moved UP once by the full amount.
        $repository->refresh();
        $this->assertSame(0, bccomp((string) $repository->balance, '90.000', 3), 'Balance must move once.');
    }

    /**
     * Reconciliation-readiness (Fix 1 / PaymentAllocationService): when a pure advance
     * is applied (no allocation JE), the advance JE must be linked+persisted onto
     * `payment.journal_entry_id` so the deposit/account bridges record the cash
     * movement with a non-null JE.
     *
     * @test
     */
    public function pure_advance_via_allocation_service_links_advance_journal_entry_to_payment(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Bridge Advance Customer',
            'type' => PartnerType::Customer,
        ]);

        $repository = $this->makeLedgeredRepository();

        // A completed deposit Payment with NO open invoices for the partner.
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '150.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::BackOffice,
            'created_by' => $this->user->id,
            'reference' => 'DEP-SPINE-ADV-001',
        ]);

        $result = app(PaymentAllocationService::class)->applyAllocationFromCommand(
            new ApplyPaymentAllocationCommand(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                paymentId: $payment->id,
                allocationMethod: AllocationMethod::FIFO,
                actorUserId: $this->user->id,
                source: 'test:deposit',
                manualAllocations: null,
            )
        );

        // No invoice allocation JE; the advance JE is the payment's only JE.
        $this->assertNull($result['journal_entry_id']);
        $this->assertNotNull($result['advance_journal_entry_id']);

        $payment->refresh();
        $this->assertSame(
            $result['advance_journal_entry_id'],
            $payment->journal_entry_id,
            'Pure advance must persist the advance JE onto payment.journal_entry_id.',
        );
        $this->assertSame(PaymentType::Advance, $payment->payment_type);
    }

    /**
     * Reconciliation-readiness (Fix 2 / storeMultiple): a multi-line OVERPAYMENT
     * whose trailing line is PURE EXCESS (allocates nothing to the primary document)
     * must still record that line's cash movement with a non-null JE — the excess
     * advance JE — not a null-JE movement.
     *
     * @test
     */
    public function multi_line_overpayment_pure_excess_line_movement_carries_a_journal_entry(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Multi Overpay Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->postOpeningReceivable($partner, '100.000');

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-SPINE-MULTI-001',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '100.000',
            'currency' => 'TND',
        ]);

        $repoA = $this->makeLedgeredRepository();
        $repoB = $this->makeLedgeredRepository();

        // Line A fully clears the invoice (100). Line B (50) is entirely excess.
        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $partner->id,
            'document_id' => $invoice->id,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'payments' => [
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $repoA->id, 'amount' => '100.000'],
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $repoB->id, 'amount' => '50.000'],
            ],
            'excess_allocation_method' => 'advance',
        ]);

        $response->assertCreated();
        $excessPaymentId = $response->json('data.payments.1.id');
        $this->assertIsString($excessPaymentId);

        // The pure-excess line's advance JE.
        $advanceEntry = JournalEntry::query()
            ->where('source_type', 'advance')
            ->where('source_id', $excessPaymentId)
            ->first();
        $this->assertNotNull($advanceEntry, 'The pure-excess line must post a customer-advance JE.');

        // The pure-excess line's cash movement carries that JE (NOT null).
        $movement = DB::table('repository_movements')
            ->where('payment_repository_id', $repoB->id)
            ->where('source_type', 'payment')
            ->where('source_id', $excessPaymentId)
            ->first();

        $this->assertNotNull($movement, 'The pure-excess line must record a cash movement.');
        $this->assertNotNull($movement->journal_entry_id, 'Pure-excess movement must NOT carry a null journal entry.');
        $this->assertSame($advanceEntry->id, $movement->journal_entry_id);
        $this->assertSame(0, bccomp((string) $movement->amount, '50.000', 3));
    }

    private function makeLedgeredRepository(string $openingBalance = '0.000'): PaymentRepository
    {
        // currency (port-managed, not fillable) is defaulted from the company by the
        // PaymentRepository::creating hook — no need to set it here. factory() is
        // unguarded, so it also seeds the port-managed (non-fillable) `balance` (Task 22).
        return PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Main Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => $openingBalance,
            'gl_account_id' => $this->cashAccount->id,
            'account_id' => null,
            'is_active' => true,
        ]);
    }

    private function postOpeningReceivable(Partner $partner, string $amount): void
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-OPEN-AR-'.substr((string) Str::uuid(), 0, 8),
            'entry_date' => now(),
            'description' => 'Opening receivable',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'test_receivable',
            'source_id' => (string) Str::uuid(),
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->receivableAccount->id,
            'partner_id' => $partner->id,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Opening receivable',
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Opening revenue',
            'line_order' => 1,
        ]);

        app(PartnerBalanceService::class)
            ->refreshPartnerBalance($this->company->id, $partner->id);
    }
}
