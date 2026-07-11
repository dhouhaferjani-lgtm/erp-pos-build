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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury spine (Task 19): `MultiPaymentService`'s cash-bearing flows must move
 * repository cash through the single movement write port + post GL synchronously
 * in-transaction — NOT stay movement-free as before.
 *
 * Cash-line rule (mirrors PaymentController, migration-safe): a split/deposit line
 * MOVES CASH (posts one GL entry + records one movement) ONLY when it carries a
 * repository_id whose repository is ledgered (gl_account_id set). A line with a
 * null repository_id — or a non-ledgered repository — is SKIPPED (Payment row +
 * allocation still created, but no movement, no GL). `recordPaymentOnAccount`
 * (partner credit, no repository_id) is always movement-free.
 */
class MultiPaymentSpineTest extends TestCase
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
            'name' => 'Spine MP Tenant',
            'slug' => 'spine-mp-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Spine MP Company',
            'legal_name' => 'Spine MP Company LLC',
            'tax_id' => 'TAX-SPINE-MP',
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
            'name' => 'Spine MP User',
            'email' => 'spine-mp@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.create', 'payments.view', 'payments.allocate']);

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
    public function split_payment_with_two_ledgered_lines_posts_gl_and_records_two_movements(): void
    {
        $partner = $this->makeCustomer('Split Customer');
        $this->postOpeningReceivable($partner, '200.000');

        $invoice = $this->makeInvoice($partner, 'INV-MP-001', '200.000');

        $repoA = $this->makeLedgeredRepository();
        $repoB = $this->makeLedgeredRepository();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/documents/{$invoice->id}/split-payment",
            [
                'splits' => [
                    ['payment_method_id' => $this->paymentMethod->id, 'amount' => '120.000', 'repository_id' => $repoA->id],
                    ['payment_method_id' => $this->paymentMethod->id, 'amount' => '80.000', 'repository_id' => $repoB->id],
                ],
            ]
        );

        $response->assertCreated();

        // Two payments for the document.
        $this->assertSame(2, DB::table('payment_allocations')->where('document_id', $invoice->id)->count());

        $paymentIds = DB::table('payments')
            ->where('company_id', $this->company->id)
            ->orderBy('amount')
            ->pluck('id', 'amount');

        // Two movements, one per repository, each linked to a posted customer_payment JE.
        foreach ([[$repoA, '120.000'], [$repoB, '80.000']] as [$repo, $amount]) {
            $movements = DB::table('repository_movements')
                ->where('payment_repository_id', $repo->id)
                ->where('source_type', 'payment')
                ->get();

            $this->assertCount(1, $movements, "Exactly one movement for repo {$repo->id}.");
            $movement = $movements->first();
            $this->assertSame('in', $movement->direction);
            $this->assertSame(0, bccomp((string) $movement->amount, $amount, 3));

            $entry = JournalEntry::query()
                ->where('source_type', 'customer_payment')
                ->where('source_id', $movement->source_id)
                ->first();
            $this->assertNotNull($entry, 'Each cash line must post a customer_payment JE.');
            $this->assertSame(JournalEntryStatus::Posted, $entry->status);
            $this->assertSame($entry->id, $movement->journal_entry_id, 'Movement links to the posted JE.');

            $repo->refresh();
            $this->assertSame(0, bccomp((string) $repo->balance, $amount, 3), 'Balance moves once by the line amount.');
        }

        // Exactly two movements total (no double count).
        $this->assertSame(
            2,
            DB::table('repository_movements')->where('source_type', 'payment')->count(),
            'Exactly two movements — one per cash line, none doubled.'
        );
    }

    /** @test */
    public function payment_on_account_records_no_repository_movement(): void
    {
        $partner = $this->makeCustomer('On Account Customer');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments/on-account', [
            'partner_id' => $partner->id,
            'amount' => '150.000',
            'currency' => 'TND',
        ]);

        $response->assertCreated();

        // The partner-credit payment row exists...
        $this->assertSame(
            1,
            DB::table('payments')->where('partner_id', $partner->id)->count(),
            'Payment on account creates exactly one payment.'
        );

        // ...but NO repository movement (it carries no repository_id — partner credit only).
        $this->assertSame(
            0,
            DB::table('repository_movements')->count(),
            'Payment on account must not move any repository cash.'
        );
    }

    /** @test */
    public function split_line_with_null_repository_id_is_skipped_and_moves_no_cash(): void
    {
        $partner = $this->makeCustomer('Null Repo Customer');
        $this->postOpeningReceivable($partner, '200.000');

        $invoice = $this->makeInvoice($partner, 'INV-MP-002', '200.000');

        $repoA = $this->makeLedgeredRepository();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/documents/{$invoice->id}/split-payment",
            [
                'splits' => [
                    ['payment_method_id' => $this->paymentMethod->id, 'amount' => '120.000', 'repository_id' => $repoA->id],
                    // No repository_id — a non-cash line: Payment + allocation created, but no movement/GL.
                    ['payment_method_id' => $this->paymentMethod->id, 'amount' => '80.000'],
                ],
            ]
        );

        $response->assertCreated();

        // Both payment rows + allocations created.
        $this->assertSame(2, DB::table('payment_allocations')->where('document_id', $invoice->id)->count());

        // Only the ledgered line moved cash: exactly one movement, on repo A.
        $this->assertSame(
            1,
            DB::table('repository_movements')->where('source_type', 'payment')->count(),
            'Only the repository-bearing line moves cash; the null-repository line is skipped.'
        );

        $movement = DB::table('repository_movements')->where('payment_repository_id', $repoA->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame(0, bccomp((string) $movement->amount, '120.000', 3));

        $repoA->refresh();
        $this->assertSame(0, bccomp((string) $repoA->balance, '120.000', 3));
    }

    /** @test */
    public function repeated_split_payment_with_same_idempotency_key_creates_payments_and_movements_once(): void
    {
        $partner = $this->makeCustomer('Idem Customer');
        $this->postOpeningReceivable($partner, '200.000');

        $invoice = $this->makeInvoice($partner, 'INV-MP-003', '200.000');

        $repoA = $this->makeLedgeredRepository();
        $repoB = $this->makeLedgeredRepository();

        $payload = [
            'splits' => [
                ['payment_method_id' => $this->paymentMethod->id, 'amount' => '120.000', 'repository_id' => $repoA->id],
                ['payment_method_id' => $this->paymentMethod->id, 'amount' => '80.000', 'repository_id' => $repoB->id],
            ],
        ];

        $key = (string) Str::uuid();

        $first = $this->actingAs($this->user)->postJson(
            "/api/v1/documents/{$invoice->id}/split-payment",
            $payload,
            ['Idempotency-Key' => $key]
        );
        $first->assertCreated();

        // Balance is now 0 — a naive replay would fail split validation (total != balance),
        // proving the idempotency short-circuit runs BEFORE validation.
        $second = $this->actingAs($this->user)->postJson(
            "/api/v1/documents/{$invoice->id}/split-payment",
            $payload,
            ['Idempotency-Key' => $key]
        );
        $second->assertOk();

        // Payments created ONCE (2, not 4).
        $this->assertSame(
            2,
            DB::table('payment_allocations')->where('document_id', $invoice->id)->count(),
            'Replay must not create a second batch of payments.'
        );

        // Movements created ONCE (2, not 4).
        $this->assertSame(
            2,
            DB::table('repository_movements')->where('source_type', 'payment')->count(),
            'Replay must not record a second set of movements.'
        );

        // Balances moved once.
        $repoA->refresh();
        $repoB->refresh();
        $this->assertSame(0, bccomp((string) $repoA->balance, '120.000', 3));
        $this->assertSame(0, bccomp((string) $repoB->balance, '80.000', 3));
    }

    /** @test */
    public function recorded_deposit_into_ledgered_repository_posts_advance_gl_and_one_movement(): void
    {
        $partner = $this->makeCustomer('Deposit Customer');
        $repo = $this->makeLedgeredRepository();

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments/deposit', [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '90.000',
            'currency' => 'TND',
            'repository_id' => $repo->id,
        ]);

        $response->assertCreated();
        $paymentId = $response->json('data.id');

        // One customer advance JE, posted, linked to the payment.
        $entry = JournalEntry::query()
            ->where('source_type', 'advance')
            ->where('source_id', $paymentId)
            ->first();
        $this->assertNotNull($entry, 'A ledgered deposit must post a customer_advance JE.');
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // Exactly one IN movement, linked to the advance JE.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'payment')
            ->where('source_id', $paymentId)
            ->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertSame('in', $movement->direction);
        $this->assertSame(0, bccomp((string) $movement->amount, '90.000', 3));
        $this->assertSame($entry->id, $movement->journal_entry_id);

        $repo->refresh();
        $this->assertSame(0, bccomp((string) $repo->balance, '90.000', 3));
    }

    /** @test */
    public function repeated_deposit_with_same_idempotency_key_records_once(): void
    {
        $partner = $this->makeCustomer('Deposit Idem Customer');
        $repo = $this->makeLedgeredRepository();

        $payload = [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '90.000',
            'currency' => 'TND',
            'repository_id' => $repo->id,
        ];

        $key = (string) Str::uuid();

        $this->actingAs($this->user)->postJson('/api/v1/payments/deposit', $payload, ['Idempotency-Key' => $key])
            ->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/payments/deposit', $payload, ['Idempotency-Key' => $key])
            ->assertOk();

        $this->assertSame(
            1,
            DB::table('payments')->where('partner_id', $partner->id)->count(),
            'Replayed deposit must not create a second payment.'
        );
        $this->assertSame(
            1,
            DB::table('repository_movements')->where('source_type', 'payment')->count(),
            'Replayed deposit must not record a second movement.'
        );

        $repo->refresh();
        $this->assertSame(0, bccomp((string) $repo->balance, '90.000', 3), 'Deposit cash moves once.');
    }

    private function makeCustomer(string $name): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'type' => PartnerType::Customer,
        ]);
    }

    private function makeInvoice(Partner $partner, string $number, string $total): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
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
