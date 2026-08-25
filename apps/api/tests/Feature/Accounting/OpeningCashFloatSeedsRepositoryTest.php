<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\OpeningCashNotFullySeededException;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryAlreadySeededException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatIntent;
use App\Shared\Contracts\Treasury\RepositoryOpeningBalanceSeederInterface;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * W4-2 (campaign wave-4 report §W4-2, P0): there was no working path to an
 * opening cash float.
 *
 * The treasury guard sends the operator to Settings → Opening balances; the
 * wizard posted GL only; `MovementSourceType::OpeningBalance` was read by the
 * reconciler but never written by anything. A day-one drawer/safe/bank could
 * never receive its float, and GL cash exceeded treasury cash by the whole
 * float.
 *
 * These tests pin the ONE sanctioned path: an ACCOUNTING opening row that names
 * a payment repository posts BOTH the GL leg (Dr 53x/512 / Cr 119) AND the
 * repository opening movement, atomically, so the repository balance equals the
 * GL debit posted to that repository's own cash account.
 */
final class OpeningCashFloatSeedsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $bankAccount;

    private Account $openingEquityAccount;

    private PaymentRepository $drawer;

    private PaymentRepository $safe;

    private PaymentRepository $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Float Tenant',
            'slug' => 'float-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Float Co',
            'legal_name' => 'Float Co SARL',
            'tax_id' => 'TAX-FLOAT-1',
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
            'name' => 'Float Admin',
            'email' => 'float-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['accounts.view', 'accounts.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashAccount = $this->account('53', 'Caisse', AccountType::Asset, SystemAccountPurpose::Cash);
        $this->bankAccount = $this->account('512', 'Banque', AccountType::Asset, SystemAccountPurpose::Bank);
        $this->openingEquityAccount = $this->account(
            '119',
            "Solde d'ouverture",
            AccountType::Equity,
            SystemAccountPurpose::OpeningBalanceEquity,
        );

        // The seeded shape: a drawer and a safe SHARE the cash GL account
        // (PaymentRepositorySeeder.php:120), a bank account carries its own.
        $this->drawer = $this->repository('CASH-01', 'Caisse principale', RepositoryType::CashRegister, $this->cashAccount);
        $this->safe = $this->repository('SAFE-01', 'Coffre', RepositoryType::Safe, $this->cashAccount);
        $this->bank = $this->repository('BANK-01', 'Banque STB', RepositoryType::BankAccount, $this->bankAccount);
    }

    /**
     * The headline case: drawer 200 + safe 1000 + bank 5000 against Cr 119
     * 6200. Every repository ends at its float, every float is backed by a
     * movement, and each repository's balance equals the GL debit on its own
     * cash account.
     */
    public function test_posting_an_accounting_batch_seeds_every_named_repository_and_matches_gl(): void
    {
        $batch = $this->createBatch();
        $this->createRow($batch, 1, $this->cashAccount, '200.000', '0.000', 'CASH-01');
        $this->createRow($batch, 2, $this->cashAccount, '1000.000', '0.000', 'SAFE-01');
        $this->createRow($batch, 3, $this->bankAccount, '5000.000', '0.000', 'BANK-01');
        $this->createRow($batch, 4, $this->openingEquityAccount, '0.000', '6200.000', null);

        $entry = $this->service()->postBatch($batch, $this->user->id);

        $this->assertSame('200.000', $this->drawer->fresh()?->balance);
        $this->assertSame('1000.000', $this->safe->fresh()?->balance);
        $this->assertSame('5000.000', $this->bank->fresh()?->balance);

        $movements = RepositoryMovement::query()
            ->where('company_id', $this->company->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $movements);

        foreach ($movements as $movement) {
            $this->assertSame(MovementSourceType::OpeningBalance, $movement->source_type);
            $this->assertSame(MovementDirection::In, $movement->direction);
            $this->assertSame($batch->id, $movement->source_id);
            $this->assertSame($entry->id, $movement->journal_entry_id);
            $this->assertSame('TND', $movement->currency);
            $this->assertSame('2026-01-01', $movement->occurred_at?->toDateString());
        }

        // ReconcileTreasuryCommand check 2 treats the JE's line(s) on the
        // repository's OWN gl_account_id as AUTHORITATIVE: an In movement must be
        // justified by a debit there, per line or by their sum. Mirror that
        // predicate here so the equality is pinned the way the reconciler reads
        // it — a merged row (one Dr 53 1200 line for two tills) would fail this
        // and freeze the drawer in production, which is why one row seeds one
        // repository.
        foreach ($movements as $movement) {
            $repository = PaymentRepository::query()->findOrFail($movement->payment_repository_id);
            $matching = JournalLine::query()
                ->where('journal_entry_id', $entry->id)
                ->where('account_id', $repository->gl_account_id)
                ->get()
                ->filter(fn (JournalLine $line): bool => bccomp((string) $line->debit, $movement->amount, 3) === 0);

            $this->assertCount(
                1,
                $matching,
                "Movement {$movement->amount} on {$repository->code} must be justified by exactly one ".
                'debit line on its own GL account.'
            );
        }

        // Repository balance == GL debit on that repository's own cash account.
        $this->assertSame(
            '1200.000',
            $this->glDebit($entry->id, $this->cashAccount->id),
            'GL cash debit must equal drawer + safe floats.'
        );
        $this->assertSame(
            '5000.000',
            $this->glDebit($entry->id, $this->bankAccount->id),
            'GL bank debit must equal the bank float.'
        );
        $this->assertSame(
            '1200.000',
            bcadd($this->drawer->fresh()?->balance ?? '0', $this->safe->fresh()?->balance ?? '0', 3),
            'Treasury cash must equal GL cash — the whole point of W4-2.'
        );

        // gate r1 §(c) — pin the REAL reconciler, not only a re-implementation of
        // its predicate. `treasury:reconcile` FREEZES a repository on cash drift,
        // so a green run over the three freshly-seeded repositories is the
        // strongest available statement that the opening movements and their
        // journal entry agree. CompanyContext is cleared first: the command runs
        // as a console/queue-shaped caller with no bound company (rule 20).
        app(CompanyContext::class)->clear();

        $this->assertSame(
            0,
            Artisan::call('treasury:reconcile', ['--tenant' => $this->tenant->id]),
            'treasury:reconcile must stay green over the seeded repositories.'
        );
        $this->assertStringContainsString('froze 0', Artisan::output());
    }

    /**
     * Re-running the post for the same batch row must not double the float.
     * The movement port is keyed on `opening_balance:{batchId}:repository:{id}`.
     */
    public function test_re_posting_the_same_opening_row_is_idempotent(): void
    {
        $batch = $this->createBatch();
        $this->createRow($batch, 1, $this->cashAccount, '200.000', '0.000', 'CASH-01');
        $this->createRow($batch, 2, $this->openingEquityAccount, '0.000', '200.000', null);

        $this->service()->postBatch($batch, $this->user->id);

        // Replay the seeding leg through the same batch id/leg — the port must
        // return the existing movement instead of writing a second one.
        $seeder = app(RepositoryOpeningBalanceSeederInterface::class);
        $entryId = (string) RepositoryMovement::query()->firstOrFail()->journal_entry_id;

        DB::transaction(function () use ($seeder, $batch, $entryId): void {
            $seeder->seed(new OpeningFloatIntent(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                repositoryId: $this->drawer->id,
                amount: '200.000',
                currency: 'TND',
                batchId: $batch->id,
                occurredAt: CarbonImmutable::parse('2026-01-01'),
                journalEntryId: $entryId,
                createdBy: $this->user->id,
            ));
        });

        $this->assertSame('200.000', $this->drawer->fresh()?->balance);
        $this->assertSame(1, RepositoryMovement::query()->count());
    }

    public function test_a_repository_that_already_holds_money_is_refused_at_validation(): void
    {
        $seeded = $this->createBatch('Seeded first');
        $this->createRow($seeded, 1, $this->cashAccount, '200.000', '0.000', 'CASH-01');
        $this->createRow($seeded, 2, $this->openingEquityAccount, '0.000', '200.000', null);
        $this->service()->postBatch($seeded, $this->user->id);

        $second = $this->createBatch('Second attempt');
        $this->createRawRow($second, 1, ['account_code' => '53', 'debit' => '50.000', 'credit' => '0.000', 'repository_code' => 'CASH-01']);
        $this->createRawRow($second, 2, ['account_code' => '119', 'debit' => '0.000', 'credit' => '50.000']);

        $result = $this->service()->validateBatch($second);

        $this->assertFalse($result['valid']);
        $this->assertSame(1, $result['invalid_rows']);
        $this->assertStringContainsString(
            'already holds money',
            $this->firstError($result, 'repository_code'),
        );
    }

    public function test_an_unknown_repository_code_is_refused_at_validation(): void
    {
        $batch = $this->createBatch();
        $this->createRawRow($batch, 1, ['account_code' => '53', 'debit' => '200.000', 'credit' => '0.000', 'repository_code' => 'NOPE-99']);

        $result = $this->service()->validateBatch($batch);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not found', $this->firstError($result, 'repository_code'));
    }

    public function test_the_row_account_must_be_the_repositorys_own_gl_account(): void
    {
        $batch = $this->createBatch();
        // BANK-01 is linked to 512, but the row debits 53.
        $this->createRawRow($batch, 1, ['account_code' => '53', 'debit' => '5000.000', 'credit' => '0.000', 'repository_code' => 'BANK-01']);

        $result = $this->service()->validateBatch($batch);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('512', $this->firstError($result, 'repository_code'));
    }

    public function test_a_credit_row_cannot_name_a_repository(): void
    {
        $batch = $this->createBatch();
        $this->createRawRow($batch, 1, ['account_code' => '53', 'debit' => '0.000', 'credit' => '200.000', 'repository_code' => 'CASH-01']);

        $result = $this->service()->validateBatch($batch);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('debit', $this->firstError($result, 'repository_code'));
    }

    public function test_the_same_repository_cannot_be_named_twice_in_one_batch(): void
    {
        $batch = $this->createBatch();
        $this->createRawRow($batch, 1, ['account_code' => '53', 'debit' => '100.000', 'credit' => '0.000', 'repository_code' => 'CASH-01']);
        $this->createRawRow($batch, 2, ['account_code' => '53', 'debit' => '100.000', 'credit' => '0.000', 'repository_code' => 'CASH-01']);
        $this->createRawRow($batch, 3, ['account_code' => '119', 'debit' => '0.000', 'credit' => '200.000']);

        $result = $this->service()->validateBatch($batch);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('more than once', $this->firstError($result, 'repository_code'));
    }

    /**
     * A repository named on a row whose validation was bypassed must still be
     * refused at POST time — the post-time guard is authoritative, not advisory.
     */
    public function test_post_refuses_a_repository_that_already_holds_money(): void
    {
        $first = $this->createBatch('First');
        $this->createRow($first, 1, $this->cashAccount, '200.000', '0.000', 'CASH-01');
        $this->createRow($first, 2, $this->openingEquityAccount, '0.000', '200.000', null);
        $this->service()->postBatch($first, $this->user->id);

        $second = $this->createBatch('Second');
        $this->createRow($second, 1, $this->cashAccount, '50.000', '0.000', 'CASH-01');
        $this->createRow($second, 2, $this->openingEquityAccount, '0.000', '50.000', null);

        $this->expectException(RepositoryAlreadySeededException::class);

        $this->service()->postBatch($second, $this->user->id);
    }

    /**
     * gate r1 F-7 — the P0 re-opening itself. A four-column legacy sheet debits
     * the cash account and names no repository, so 1200.000 would exist in the
     * ledger and in no till, permanently (an opening batch locks at post). The
     * batch must refuse rather than reproduce W4-2.
     */
    public function test_a_cash_debit_that_reaches_no_repository_is_refused(): void
    {
        $batch = $this->createBatch();
        $this->createRawRow($batch, 1, ['account_code' => '53', 'debit' => '1200.000', 'credit' => '0.000']);
        $this->createRawRow($batch, 2, ['account_code' => '119', 'debit' => '0.000', 'credit' => '1200.000']);

        $result = $this->service()->validateBatch($batch);

        $this->assertFalse($result['valid'], 'A cash debit reaching no till must not validate.');
        /** @var array<string, mixed> $errors */
        $errors = $result['errors'];
        $this->assertArrayHasKey('_batch', $errors);
        // STRUCTURED, not prose (gate r2 G-1): the wizard renders this in the
        // operator's locale from the code and its parameters.
        $gap = ((array) $errors['_batch'])[0];
        $this->assertSame('OPENING_CASH_NOT_FULLY_SEEDED', $gap['code']);
        $this->assertSame('53', $gap['params']['account']);
        $this->assertSame('1200.000', $gap['params']['debited']);
        $this->assertSame('0.000', $gap['params']['attributed']);
        $this->assertSame('1200.000', $gap['params']['unattributed']);
        $this->assertStringContainsString('CASH-01', $gap['params']['repositories']);

        // And the post-time guard is the authoritative one — validation can be
        // stale by the time Post is pressed.
        $posted = $this->createBatch('Legacy sheet posted directly');
        $this->createRow($posted, 1, $this->cashAccount, '1200.000', '0.000', null);
        $this->createRow($posted, 2, $this->openingEquityAccount, '0.000', '1200.000', null);

        $this->expectException(OpeningCashNotFullySeededException::class);
        $this->service()->postBatch($posted, $this->user->id);
    }

    /**
     * The same refusal fires on a PARTIAL attribution: 1200.000 debited to `53`,
     * only 200.000 assigned to a till.
     */
    public function test_a_partially_attributed_cash_debit_is_refused(): void
    {
        $batch = $this->createBatch();
        $this->createRawRow($batch, 1, ['account_code' => '53', 'debit' => '200.000', 'credit' => '0.000', 'repository_code' => 'CASH-01']);
        $this->createRawRow($batch, 2, ['account_code' => '53', 'debit' => '1000.000', 'credit' => '0.000']);
        $this->createRawRow($batch, 3, ['account_code' => '119', 'debit' => '0.000', 'credit' => '1200.000']);

        $result = $this->service()->validateBatch($batch);

        $this->assertFalse($result['valid']);
        /** @var array<string, mixed> $errors */
        $errors = $result['errors'];
        $gap = ((array) $errors['_batch'])[0];
        $this->assertSame('OPENING_CASH_NOT_FULLY_SEEDED', $gap['code']);
        $this->assertSame('200.000', $gap['params']['attributed']);
        $this->assertSame('1000.000', $gap['params']['unattributed']);
    }

    /**
     * A debit on an account NO repository is linked to is not examined at all —
     * receivables, inventory, equity, or a petty-cash account modelled outside
     * Treasury must keep posting exactly as before.
     */
    public function test_a_debit_on_an_account_with_no_repository_is_not_examined(): void
    {
        $receivable = $this->account('411', 'Clients', AccountType::Asset, SystemAccountPurpose::CustomerReceivable);

        $batch = $this->createBatch();
        $this->createRow($batch, 1, $receivable, '900.000', '0.000', null);
        $this->createRow($batch, 2, $this->openingEquityAccount, '0.000', '900.000', null);

        $entry = $this->service()->postBatch($batch, $this->user->id);

        $this->assertSame('900.000', $this->glDebit($entry->id, $receivable->id));
        $this->assertSame(0, RepositoryMovement::query()->count());
    }

    /**
     * KNOWN AND DELIBERATE (gate r1 F-7 / PROBE B): a merged `Dr 53 1200.000`
     * row naming only the drawer POSTS, leaving the drawer at 1200.000 and the
     * safe at 0.000.
     *
     * It is not refused because it cannot be told apart from a shop whose safe
     * is genuinely empty on day one, and refusing that would block a correct
     * opening. The ledger and Treasury still agree — Σ tills on `53` == GL Dr
     * `53` — so it is a data-entry error about WHICH till holds the money, not a
     * money error, and `treasury:reconcile` stays green. This test exists so the
     * behaviour is a recorded decision rather than an accident: if a future lane
     * gives the operator a way to declare "this till opens at zero", it should
     * fail here and be rewritten.
     */
    public function test_a_merged_cash_row_over_seeds_one_till_and_is_deliberately_not_refused(): void
    {
        $batch = $this->createBatch();
        $this->createRow($batch, 1, $this->cashAccount, '1200.000', '0.000', 'CASH-01');
        $this->createRow($batch, 2, $this->openingEquityAccount, '0.000', '1200.000', null);

        $entry = $this->service()->postBatch($batch, $this->user->id);

        $this->assertSame('1200.000', $this->drawer->fresh()?->balance);
        $this->assertSame('0.000', $this->safe->fresh()?->balance);
        $this->assertSame('1200.000', $this->glDebit($entry->id, $this->cashAccount->id));

        app(CompanyContext::class)->clear();
        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $this->tenant->id]));
        $this->assertStringContainsString('froze 0', Artisan::output());
    }

    /**
     * gate r1 F-11 (narrowed) — a batch posts on STATUS alone, so a rejected row
     * silently degrades into "Batch posted successfully" with `119` absorbing the
     * difference. For a row that names a TILL that is unacceptable: the float
     * would vanish from the ledger AND from Treasury, on a write-once batch.
     */
    public function test_post_refuses_when_a_row_naming_a_repository_failed_validation(): void
    {
        $batch = $this->createBatch();
        // BANK-01 is linked to 512; this row debits 53 → the row is rejected.
        $this->createRawRow($batch, 1, ['account_code' => '53', 'debit' => '5000.000', 'credit' => '0.000', 'repository_code' => 'BANK-01']);
        $this->createRawRow($batch, 2, ['account_code' => '119', 'debit' => '0.000', 'credit' => '5000.000']);

        $this->service()->validateBatch($batch);

        $this->expectExceptionMessageMatches('/BANK-01/');
        $this->service()->postBatch($batch->fresh(), $this->user->id);
    }

    /**
     * gate r1 F-5 — the refusal must reach the operator as a TYPED code with a
     * translated message, the way the sibling W4-10 refusal does. It used to
     * fall through to the global handler as `BUSINESS_ERROR` plus raw server
     * English, and `ERROR_CODE` had zero consumers anywhere.
     */
    public function test_the_api_returns_a_typed_422_when_a_till_already_holds_money(): void
    {
        $first = $this->createBatch('First');
        $this->createRow($first, 1, $this->cashAccount, '200.000', '0.000', 'CASH-01');
        $this->createRow($first, 2, $this->openingEquityAccount, '0.000', '200.000', null);
        $this->service()->postBatch($first, $this->user->id);

        $second = $this->createBatch('Second');
        $this->createRow($second, 1, $this->cashAccount, '50.000', '0.000', 'CASH-01');
        $this->createRow($second, 2, $this->openingEquityAccount, '0.000', '50.000', null);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$second->id}/post"
        );

        $response->assertStatus(422);
        $this->assertSame('REPOSITORY_ALREADY_SEEDED', $response->json('error.code'));
        $this->assertStringContainsString('CASH-01', (string) $response->json('error.message'));
    }

    /**
     * The coverage refusal reaches the API as its own typed code, and carries the
     * per-account gap list so the wizard can point at the offending line.
     */
    public function test_the_api_returns_a_typed_422_with_gaps_when_cash_reaches_no_till(): void
    {
        $batch = $this->createBatch();
        $this->createRow($batch, 1, $this->cashAccount, '1200.000', '0.000', null);
        $this->createRow($batch, 2, $this->openingEquityAccount, '0.000', '1200.000', null);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/post"
        );

        $response->assertStatus(422);
        $this->assertSame('OPENING_CASH_NOT_FULLY_SEEDED', $response->json('error.code'));
        $this->assertSame('OPENING_CASH_NOT_FULLY_SEEDED', $response->json('error.gaps.0.code'));
        $this->assertSame('53', $response->json('error.gaps.0.params.account'));
        $this->assertSame('1200.000', $response->json('error.gaps.0.params.unattributed'));
    }

    private function service(): AccountingOpeningService
    {
        return app(AccountingOpeningService::class);
    }

    private function glDebit(string $entryId, string $accountId): string
    {
        $sum = '0';
        foreach (JournalLine::query()->where('journal_entry_id', $entryId)->where('account_id', $accountId)->get() as $line) {
            $sum = bcadd($sum, (string) $line->debit, 3);
        }

        return $sum;
    }

    /**
     * @param  array{valid: bool, total_rows: int, valid_rows: int, invalid_rows: int, total_debit: string, total_credit: string, is_balanced: bool, errors: array<string, mixed>}|array<string, mixed>  $result
     */
    private function firstError(array $result, string $field): string
    {
        /** @var array<string, mixed> $errors */
        $errors = $result['errors'];
        foreach ($errors as $key => $rowErrors) {
            if ($key === '_batch' || ! is_array($rowErrors)) {
                continue;
            }
            if (isset($rowErrors[$field]) && is_array($rowErrors[$field])) {
                return (string) $rowErrors[$field][0];
            }
        }

        return '';
    }

    private function account(string $code, string $name, AccountType $type, SystemAccountPurpose $purpose): Account
    {
        return Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => true,
        ]);
    }

    private function repository(string $code, string $name, RepositoryType $type, Account $glAccount): PaymentRepository
    {
        return PaymentRepository::forceCreate([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'account_id' => $glAccount->id,
            'gl_account_id' => $glAccount->id,
            'is_active' => true,
        ]);
    }

    private function createBatch(string $name = 'Opening 2026'): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Accounting,
            'name' => $name,
            'cutover_date' => '2026-01-01',
            'status' => OpeningBatchStatus::Draft,
            'created_by' => $this->user->id,
        ]);
    }

    private function createRow(
        OpeningBalanceBatch $batch,
        int $rowNumber,
        Account $account,
        string $debit,
        string $credit,
        ?string $repositoryCode,
    ): void {
        $mapped = [
            'account_id' => $account->id,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'debit' => $debit,
            'credit' => $credit,
            'description' => 'Opening balance',
        ];

        if ($repositoryCode !== null) {
            $mapped['repository_code'] = $repositoryCode;
            $mapped['repository_id'] = PaymentRepository::query()
                ->where('company_id', $this->company->id)
                ->where('code', $repositoryCode)
                ->value('id');
        }

        OpeningBalanceImportRow::create([
            'batch_id' => $batch->id,
            'row_type' => 'GL',
            'row_number' => $rowNumber,
            'status' => OpeningImportRowStatus::Valid,
            'raw_data' => [],
            'mapped_data' => $mapped,
        ]);
    }

    /**
     * @param  array<string, mixed>  $rawData
     */
    private function createRawRow(OpeningBalanceBatch $batch, int $rowNumber, array $rawData): void
    {
        OpeningBalanceImportRow::create([
            'batch_id' => $batch->id,
            'row_type' => 'GL',
            'row_number' => $rowNumber,
            'status' => OpeningImportRowStatus::Pending,
            'raw_data' => $rawData,
            'mapped_data' => [],
        ]);
    }
}
