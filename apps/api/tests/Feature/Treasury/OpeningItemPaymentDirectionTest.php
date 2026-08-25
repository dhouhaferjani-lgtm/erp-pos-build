<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\ArApOpeningService;
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
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * W4-3 (P0), the money end — paying an AP opening item.
 *
 * On the first tenant, `POST /payments` for the 500.000 TND owed to a supplier
 * against its opening item returned 201 and recorded, in order: a
 * `customer_payment` journal entry `Dr 512 / Cr 411`, a repository movement of
 * direction **IN** (bank −1289.950 → −789.950), `receivable_balance = −500.000`,
 * `GL 401` untouched at 500.000 Cr, and the document marked `paid`. The operator
 * sent money to a supplier; the system recorded money arriving.
 *
 * Two links produced it, and this class pins both:
 *  1. the opening item was minted as a CUSTOMER invoice (fixed in
 *     `ArApOpeningService` — asserted end-to-end here through the real endpoint);
 *  2. nothing checked that a document's SIDE agrees with its partner's ROLE, so a
 *     customer-typed document owned by a supplier took the AR arm in silence. That
 *     shape still exists on every tenant migrated before the fix, so the guard is
 *     not hypothetical — it is what protects the already-minted rows.
 *
 * docs/handoff/PLAYWRIGHT-first-tenant-campaign-wave4-critical-path-2026-08-24.md §W4-3 / §B.8
 */
final class OpeningItemPaymentDirectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $supplier;

    private Account $bankAccount;

    private PaymentRepository $repository;

    private PaymentMethod $bankMethod;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));

        $this->tenant = Tenant::create([
            'name' => 'W43 Payment Tenant',
            'slug' => 'w43-pay-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parabio Tunisie',
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

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Treasury Admin',
            'email' => 'w43-treasury@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.view', 'payments.create', 'payments.allocate']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SUPP-001',
            'name' => 'Laboratoires Méditerranée SA',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->bankMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-W43',
            'name' => 'Virement',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->repository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'REPO-BANK-W43',
            'name' => 'Compte bancaire',
            'type' => RepositoryType::BankAccount,
            'balance' => '5000.000',
            'gl_account_id' => $this->bankAccount->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paying_an_ap_opening_item_debits_the_payable_and_moves_the_cash_out(): void
    {
        $openingItem = $this->postApOpening('500.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $this->bankMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '500.000',
            'currency' => 'TND',
            'payment_date' => '2026-08-25',
            'allocations' => [
                ['document_id' => $openingItem->id, 'amount' => '500.000'],
            ],
        ]);

        $response->assertStatus(201);

        $entry = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'supplier_payment')
            ->with('lines')
            ->first();

        self::assertNotNull(
            $entry,
            'Paying a supplier opening item must take the supplier arm — a customer_payment entry means the cash moved the wrong way.'
        );

        $payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);

        $payableLine = $entry->lines->firstWhere('account_id', $payableAccount->id);
        self::assertNotNull($payableLine);
        self::assertSame('500.000', $payableLine->debit, 'The payable must be cleared, not the receivable.');
        self::assertSame($this->supplier->id, $payableLine->partner_id);

        $bankLine = $entry->lines->firstWhere('account_id', $this->bankAccount->id);
        self::assertNotNull($bankLine);
        self::assertSame('500.000', $bankLine->credit, 'Cash leaves the bank.');

        self::assertSame('0.000', $this->supplier->refresh()->payable_balance, 'The debt is settled.');

        // `amount` comes back as a float on SQLite and a numeric string on
        // PostgreSQL, so bcmath decides rather than assertDatabaseHas.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $this->repository->id)
            ->get();

        self::assertCount(1, $movements);
        self::assertSame(
            MovementDirection::Out->value,
            $movements[0]->direction,
            'The campaign recorded direction IN for money leaving the bank.'
        );
        self::assertSame(0, bccomp((string) $movements[0]->amount, '500.000', 3));

        // `payment_repositories.balance` is port-managed (not fillable; a pgsql
        // trigger forbids direct writes), so this repository starts at 0.000 and a
        // bank repository is allowed to go overdrawn — exactly the campaign's shape.
        // What matters is the SIGN of the movement: the campaign saw the bank go UP
        // by 500 for money it had just sent out.
        self::assertSame(
            0,
            bccomp((string) $this->repository->refresh()->balance, '-500.000', 3),
            'The repository must go DOWN by the payment — the campaign saw it go up.'
        );

        $this->assertDatabaseMissing('journal_entries', [
            'company_id' => $this->company->id,
            'source_type' => 'customer_payment',
        ]);
    }

    public function test_a_customer_typed_document_owned_by_a_supplier_cannot_be_paid(): void
    {
        $legacyMisTypedOpening = $this->legacyMisTypedOpening();

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $this->bankMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '500.000',
            'currency' => 'TND',
            'payment_date' => '2026-08-25',
            'allocations' => [
                ['document_id' => $legacyMisTypedOpening->id, 'amount' => '500.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_DIRECTION_MISMATCH');

        // Fails closed: no cash moved, no ledger entry, no allocation.
        $this->assertDatabaseMissing('payments', ['partner_id' => $this->supplier->id]);
        $this->assertDatabaseMissing('payment_allocations', ['document_id' => $legacyMisTypedOpening->id]);
        $this->assertDatabaseMissing('repository_movements', ['payment_repository_id' => $this->repository->id]);
        self::assertSame(0, bccomp((string) $this->repository->refresh()->balance, '0.000', 3));
    }

    public function test_a_supplier_typed_document_owned_by_a_customer_cannot_be_paid(): void
    {
        $customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CUST-W43',
            'name' => 'Nadia Chaabane',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $misTyped = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $customer->id,
            'type' => DocumentType::SupplierInvoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'SI-2026-09002',
            'document_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'currency' => 'TND',
            'subtotal' => '80.000',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '80.000',
            'balance_due' => '80.000',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $customer->id,
            'payment_method_id' => $this->bankMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '80.000',
            'currency' => 'TND',
            'payment_date' => '2026-08-25',
            'allocations' => [
                ['document_id' => $misTyped->id, 'amount' => '80.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_DIRECTION_MISMATCH');
    }

    // ------------------------------ gate r1 I-1: the OTHER settlement routes ---
    //
    // The guard was on POST /payments single-payment alone, and the legacy
    // mis-typed row stayed reachable through three more routes. `storeMultiple`'s
    // only type guard was `rejectSupplierInvoiceInMultiline()`, a pure
    // `=== SupplierInvoice` test that a customer-typed document passes;
    // `MultiPaymentController` and the smart-payment path have the same shape. One
    // test per route, mirroring `PaymentAllocationDocumentStateTest` (W-7 F-6),
    // because that is what proved a one-site guard is not a guard.

    public function test_the_multi_payment_path_refuses_a_direction_mismatch(): void
    {
        $document = $this->legacyMisTypedOpening();

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->supplier->id,
            'document_id' => $document->id,
            'payment_date' => '2026-08-25',
            'payments' => [
                [
                    'payment_method_id' => $this->bankMethod->id,
                    'repository_id' => $this->repository->id,
                    'amount' => '500.000',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_DIRECTION_MISMATCH');
        $this->assertDatabaseMissing('payment_allocations', ['document_id' => $document->id]);
        self::assertSame(0, Payment::query()->count());
    }

    public function test_the_split_payment_path_refuses_a_direction_mismatch(): void
    {
        $document = $this->legacyMisTypedOpening();

        $response = $this->actingAs($this->user)->postJson("/api/v1/documents/{$document->id}/split-payment", [
            'splits' => [
                ['payment_method_id' => $this->bankMethod->id, 'repository_id' => $this->repository->id, 'amount' => '250.000'],
                ['payment_method_id' => $this->bankMethod->id, 'repository_id' => $this->repository->id, 'amount' => '250.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_DIRECTION_MISMATCH');
        $this->assertDatabaseMissing('payment_allocations', ['document_id' => $document->id]);
        self::assertSame(0, Payment::query()->count());
    }

    public function test_the_apply_deposit_path_refuses_a_direction_mismatch(): void
    {
        $document = $this->legacyMisTypedOpening();
        $deposit = $this->unallocatedDeposit('500.000');

        $response = $this->actingAs($this->user)->postJson("/api/v1/payments/{$deposit->id}/apply-deposit", [
            'document_id' => $document->id,
            'amount' => '500.000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_DIRECTION_MISMATCH');
        $this->assertDatabaseMissing('payment_allocations', ['document_id' => $document->id]);
    }

    public function test_the_smart_payment_path_refuses_a_direction_mismatch(): void
    {
        $document = $this->legacyMisTypedOpening();
        $deposit = $this->unallocatedDeposit('500.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $deposit->id,
            'allocation_method' => 'fifo',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PAYMENT_DIRECTION_MISMATCH');
        $this->assertDatabaseMissing('payment_allocations', ['document_id' => $document->id]);
    }

    /**
     * The exact row every tenant migrated before W4-3 already carries, and the one
     * the campaign tenant holds as `HIST-INV-2026-00002`: a customer-typed invoice
     * whose partner is a SUPPLIER. Nothing in the payment path used to notice, so
     * it took the AR arm — `Dr bank / Cr 411`, movement IN — for money going out.
     */
    private function legacyMisTypedOpening(): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'HIST-INV-2026-09001',
            'document_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'currency' => 'TND',
            'subtotal' => '500.000',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '500.000',
            'balance_due' => '500.000',
            'is_historical' => true,
        ]);
    }

    private function unallocatedDeposit(string $amount): Payment
    {
        return Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'payment_method_id' => $this->bankMethod->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => '2026-08-25',
            'status' => PaymentStatus::Completed,
            'origin' => PaymentOrigin::WebAdmin,
            'reference' => 'Deposit for direction-guard test',
            'notes' => 'Advance payment/deposit [UNALLOCATED]',
        ]);
    }

    private function postApOpening(string $amount): Document
    {
        $batchService = app(OpeningBalanceBatchService::class);

        $batch = $batchService->createBatch(
            $this->company,
            OpeningBatchType::ApOpenItems,
            Carbon::parse('2026-08-25'),
            'AP-'.uniqid(),
            $this->user->id,
            'phpunit',
        );

        $batchService->addImportRows($batch, [[
            'partner_code' => 'SUPP-001',
            'external_invoice_number' => 'F2024-100',
            'document_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'total' => $amount,
            'open_amount' => $amount,
            'document_type' => 'invoice',
            'currency' => 'TND',
            'notes' => null,
        ]]);

        $service = app(ArApOpeningService::class);
        $service->validateBatch($batch->refresh());
        $service->postBatch($batch->refresh(), $this->user->id);

        return Document::query()
            ->where('company_id', $this->company->id)
            ->where('partner_id', $this->supplier->id)
            ->firstOrFail();
    }
}
