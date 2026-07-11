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
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 16b (spine Wave D, HIGH-7): request-level idempotency for payment
 * creation. Task 16 made PaymentController::store() write the treasury
 * movement through the write port keyed on $payment->id — but store() mints a
 * fresh Payment UUID on every request, so a lost-response retry creates a
 * SECOND payment (and therefore a second movement, since the movement key
 * derives from the new payment id). An `Idempotency-Key` header lets the
 * controller detect the retry BEFORE creating anything and return the
 * original payment instead.
 */
class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $receivableAccount;

    private Account $revenueAccount;

    private PaymentMethod $paymentMethod;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Idempotency Tenant',
            'slug' => 'idempotency-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Idempotency Company',
            'legal_name' => 'Idempotency Company LLC',
            'tax_id' => 'TAX-IDEMP',
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
            'name' => 'Idempotency User',
            'email' => 'idempotency@example.com',
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

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Idempotency Customer',
            'type' => PartnerType::Customer,
        ]);
    }

    /** @test */
    public function retry_with_same_idempotency_key_creates_exactly_one_payment_and_one_movement(): void
    {
        $this->postOpeningReceivable('300.000');

        $invoice = $this->makeInvoice('INV-IDEMP-001', '300.000');
        $repository = $this->makeLedgeredRepository();

        $payload = [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '120.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-IDEMP-001',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '120.000'],
            ],
        ];

        $first = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'idem-key-001')
            ->postJson('/api/v1/payments', $payload);
        $first->assertCreated();
        $firstPaymentId = $first->json('data.id');

        $second = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'idem-key-001')
            ->postJson('/api/v1/payments', $payload);
        $second->assertOk();
        $secondPaymentId = $second->json('data.id');

        // Both responses reference the SAME payment.
        $this->assertSame($firstPaymentId, $secondPaymentId);

        // Exactly one payments row for this idempotency key.
        $this->assertSame(
            1,
            DB::table('payments')->where('company_id', $this->company->id)->where('idempotency_key', 'idem-key-001')->count(),
        );

        // Exactly one movement row.
        $this->assertSame(
            1,
            DB::table('repository_movements')
                ->where('payment_repository_id', $repository->id)
                ->where('source_type', 'payment')
                ->where('source_id', $firstPaymentId)
                ->count(),
        );

        // Balance moved ONCE.
        $repository->refresh();
        $this->assertSame(0, bccomp((string) $repository->balance, '120.000', 3));
    }

    /** @test */
    public function retry_with_different_idempotency_keys_creates_two_payments(): void
    {
        $this->postOpeningReceivable('300.000');

        $invoice = $this->makeInvoice('INV-IDEMP-002', '300.000');
        $repository = $this->makeLedgeredRepository();

        $payload = [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '50.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-IDEMP-002',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '50.000'],
            ],
        ];

        $first = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'idem-key-002-a')
            ->postJson('/api/v1/payments', $payload);
        $first->assertCreated();

        $second = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'idem-key-002-b')
            ->postJson('/api/v1/payments', $payload);
        $second->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));

        $this->assertSame(
            2,
            DB::table('payments')->where('company_id', $this->company->id)->where('reference', 'PAY-IDEMP-002')->count(),
        );

        $repository->refresh();
        $this->assertSame(0, bccomp((string) $repository->balance, '100.000', 3));
    }

    /** @test */
    public function no_idempotency_key_behaves_exactly_as_before_each_request_creates_a_payment(): void
    {
        $this->postOpeningReceivable('300.000');

        $invoice = $this->makeInvoice('INV-IDEMP-003', '300.000');
        $repository = $this->makeLedgeredRepository();

        $payload = [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => '30.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-IDEMP-003',
            'allocations' => [
                ['document_id' => $invoice->id, 'amount' => '30.000'],
            ],
        ];

        $first = $this->actingAs($this->user)->postJson('/api/v1/payments', $payload);
        $first->assertCreated();

        $second = $this->actingAs($this->user)->postJson('/api/v1/payments', $payload);
        $second->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));

        $this->assertSame(
            2,
            DB::table('payments')->where('company_id', $this->company->id)->where('reference', 'PAY-IDEMP-003')->count(),
        );

        // Backward compat: idempotency_key stays NULL when the caller doesn't supply one.
        $this->assertNull(DB::table('payments')->where('id', $first->json('data.id'))->value('idempotency_key'));
    }

    /** @test */
    public function database_rejects_a_direct_duplicate_idempotency_key_insert_for_the_same_company(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            $this->markTestSkipped('Partial unique index test only meaningful on pgsql/sqlite.');
        }

        DB::table('payments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '10.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => 'completed',
            'payment_type' => 'advance',
            'origin' => 'web_admin',
            'idempotency_key' => 'db-level-dup-key',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '20.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => 'completed',
            'payment_type' => 'advance',
            'origin' => 'web_admin',
            'idempotency_key' => 'db-level-dup-key',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function retry_of_a_storemultiple_batch_with_the_same_idempotency_key_creates_no_duplicate_rows(): void
    {
        $invoice = $this->makeInvoice('INV-IDEMP-MULTI-001', '150.000');
        $repositoryA = $this->makeLedgeredRepository();
        $repositoryB = $this->makeLedgeredRepository();

        $payload = [
            'partner_id' => $this->partner->id,
            'document_id' => $invoice->id,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'payments' => [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'repository_id' => $repositoryA->id,
                    'amount' => '100.000',
                    'reference' => 'PAY-IDEMP-MULTI-A',
                ],
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'repository_id' => $repositoryB->id,
                    'amount' => '50.000',
                    'reference' => 'PAY-IDEMP-MULTI-B',
                ],
            ],
        ];

        $first = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'idem-multi-001')
            ->postJson('/api/v1/payments', $payload);
        $first->assertCreated();
        $firstPaymentIds = collect($first->json('data.payments'))->pluck('id')->sort()->values()->all();

        $second = $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'idem-multi-001')
            ->postJson('/api/v1/payments', $payload);
        $second->assertOk();
        $secondPaymentIds = collect($second->json('data.payments'))->pluck('id')->sort()->values()->all();

        $this->assertSame($firstPaymentIds, $secondPaymentIds);
        $this->assertCount(2, $firstPaymentIds);

        // Exactly two payment rows total for this batch (one per line), not four.
        $this->assertSame(
            2,
            DB::table('payments')
                ->where('company_id', $this->company->id)
                ->where('idempotency_key', 'like', 'idem-multi-001:multi:%')
                ->count(),
        );

        // Exactly one movement per repository leg — no duplicate movements on replay.
        $this->assertSame(
            1,
            DB::table('repository_movements')->where('payment_repository_id', $repositoryA->id)->count(),
        );
        $this->assertSame(
            1,
            DB::table('repository_movements')->where('payment_repository_id', $repositoryB->id)->count(),
        );

        $repositoryA->refresh();
        $repositoryB->refresh();
        $this->assertSame(0, bccomp((string) $repositoryA->balance, '100.000', 3));
        $this->assertSame(0, bccomp((string) $repositoryB->balance, '50.000', 3));
    }

    private function makeInvoice(string $number, string $total): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'TND',
        ]);
    }

    private function makeLedgeredRepository(string $openingBalance = '0.000'): PaymentRepository
    {
        return PaymentRepository::create([
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

    private function postOpeningReceivable(string $amount): void
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-OPEN-IDEMP-'.substr((string) Str::uuid(), 0, 8),
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
            'partner_id' => $this->partner->id,
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
            ->refreshPartnerBalance($this->company->id, $this->partner->id);
    }
}
