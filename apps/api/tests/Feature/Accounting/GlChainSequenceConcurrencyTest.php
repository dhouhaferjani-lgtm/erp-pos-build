<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Task 7 (Treasury spine, Wave B GL hardening): chain-sequence + entry-number
 * allocation must be serialized per company via a Postgres transaction-scoped
 * advisory lock (pg_advisory_xact_lock). This closes the concurrent-post race
 * where two posts to one company could allocate duplicate chain_sequence /
 * entry_number — an FEC-sequentiality break.
 *
 * Advisory locks are Postgres-only. True two-connection contention under
 * RefreshDatabase/pgsql is flaky (the test transaction holds uncommitted data
 * on one connection), so this uses a deterministic approach:
 *  - assert the advisory lock statement is issued on the sealing / allocation
 *    paths (proves the lock is acquired), and
 *  - assert sequential posts / allocations produce gapless, strictly-increasing
 *    chain_sequence and entry_number (proves allocation correctness).
 */
final class GlChainSequenceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        // GL sealing can run with NO CompanyContext (queued/console/projection).
        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Chain Poster',
            'email' => 'chain-poster@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '999999',
            'name' => 'Chain Test Suspense',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);
    }

    public function test_sealing_a_post_issues_the_company_advisory_lock(): void
    {
        $this->skipUnlessPostgres();

        $entry = $this->makeDraftBalancedEntry('CHAIN-LOCK-001');

        DB::enableQueryLog();
        DB::transaction(function () use ($entry): void {
            app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
        });
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue(
            $this->queryLogHasAdvisoryLock($log),
            'Expected pg_advisory_xact_lock to be issued during GL sealing.'
        );
    }

    public function test_sequential_posts_produce_gapless_strictly_increasing_chain_sequence(): void
    {
        $this->skipUnlessPostgres();

        $sequences = [];
        for ($i = 1; $i <= 5; $i++) {
            $entry = $this->makeDraftBalancedEntry(sprintf('CHAIN-SEQ-%03d', $i));
            DB::transaction(function () use ($entry): void {
                app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
            });
            $fresh = $entry->fresh();
            $this->assertNotNull($fresh);
            $sequences[] = $fresh->chain_sequence;
        }

        // Gapless and strictly increasing from 1.
        $this->assertSame([1, 2, 3, 4, 5], $sequences);
    }

    public function test_generate_entry_number_issues_the_company_advisory_lock(): void
    {
        $this->skipUnlessPostgres();

        DB::enableQueryLog();
        DB::transaction(function (): void {
            $this->invokeGenerateEntryNumber($this->company->id);
        });
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue(
            $this->queryLogHasAdvisoryLock($log),
            'Expected pg_advisory_xact_lock to be issued during entry-number allocation.'
        );
    }

    public function test_sequential_entry_number_allocation_is_gapless(): void
    {
        $this->skipUnlessPostgres();

        $year = date('Y');
        $numbers = [];
        for ($i = 1; $i <= 4; $i++) {
            $number = DB::transaction(fn (): string => $this->invokeGenerateEntryNumber($this->company->id));
            $numbers[] = $number;

            // Persist an entry claiming the number so the next allocation advances.
            JournalEntry::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'entry_number' => $number,
                'entry_date' => now(),
                'description' => 'Entry-number allocation test',
                'status' => JournalEntryStatus::Draft,
            ]);
        }

        $this->assertSame([
            sprintf('JE-%s-000001', $year),
            sprintf('JE-%s-000002', $year),
            sprintf('JE-%s-000003', $year),
            sprintf('JE-%s-000004', $year),
        ], $numbers);
    }

    /**
     * @param  array<int, array{query: string, bindings: array<int, mixed>}>  $log
     */
    private function queryLogHasAdvisoryLock(array $log): bool
    {
        foreach ($log as $entry) {
            if (str_contains($entry['query'], 'pg_advisory_xact_lock')) {
                return true;
            }
        }

        return false;
    }

    private function invokeGenerateEntryNumber(string $companyId): string
    {
        $method = new ReflectionMethod(GeneralLedgerService::class, 'generateEntryNumber');
        $method->setAccessible(true);

        /** @var string $number */
        $number = $method->invoke(app(GeneralLedgerService::class), $companyId);

        return $number;
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Advisory locks are Postgres-only; skipping on '.DB::connection()->getDriverName().'.');
        }
    }

    private function makeDraftBalancedEntry(string $entryNumber): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => $entryNumber,
            'entry_date' => now(),
            'description' => 'Chain sequence concurrency test entry',
            'status' => JournalEntryStatus::Draft,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->account->id,
            'debit' => '100.000',
            'credit' => '0',
            'description' => 'Debit leg',
            'line_order' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->account->id,
            'debit' => '0',
            'credit' => '100.000',
            'description' => 'Credit leg',
            'line_order' => 1,
        ]);

        return $entry->load('lines');
    }
}
