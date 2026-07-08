<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
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
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Task 6 (Treasury spine BLOCKER-1): postEntryNow() must seal + persist the
 * journal entry SYNCHRONOUSLY inside the caller's transaction, so a money
 * movement and its GL posting are atomic. The event is still deferred to
 * DB::afterCommit, but the DB state change is durable-in-transaction.
 *
 * Contrast with the historical afterCommit path, which leaves the entry Draft
 * mid-transaction and only posts it after commit.
 */
final class PostEntryNowAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        // Queued/console/projection GL posting runs with NO CompanyContext.
        // postEntryNow must work with the currency passed explicitly (rule 20).
        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Spine Poster',
            'email' => 'spine-poster@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '999999',
            'name' => 'Spine Test Suspense',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);
    }

    public function test_post_entry_now_persists_posting_inside_caller_transaction_and_rolls_back(): void
    {
        app(CompanyContext::class)->clear();

        // Model the spine caller: the movement, the draft entry, and the GL post
        // all live in ONE transaction. When anything after the post throws, the
        // whole unit rolls back together — the money movement never commits
        // without its GL posting (atomicity is the point of postEntryNow).
        $capturedId = null;

        try {
            DB::transaction(function () use (&$capturedId): void {
                $entry = $this->makeDraftBalancedEntry('SPINE-ROLLBACK-001');
                $capturedId = $entry->id;

                app(GeneralLedgerService::class)->postEntryNow($entry, null, 'TND');

                // Posted state is visible INSIDE the transaction (synchronous,
                // not deferred to afterCommit).
                $this->assertSame(JournalEntryStatus::Posted, $entry->fresh()->status);

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotNull($capturedId);
        // The posting rolled back with the movement — atomic.
        $this->assertNull(JournalEntry::find($capturedId));
    }

    public function test_post_entry_now_commits_posted_status_and_chain_sequence(): void
    {
        app(CompanyContext::class)->clear();
        $entry = $this->makeDraftBalancedEntry('SPINE-COMMIT-001');

        DB::transaction(function () use ($entry): void {
            app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
        });

        $fresh = $entry->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(JournalEntryStatus::Posted, $fresh->status);
        $this->assertNotNull($fresh->chain_sequence);
        $this->assertNotNull($fresh->fiscal_hash);
        $this->assertSame($this->user->id, $fresh->posted_by);
    }

    public function test_post_entry_now_dispatches_journal_entry_posted_exactly_once_on_commit(): void
    {
        app(CompanyContext::class)->clear();
        Event::fake([JournalEntryPosted::class]);

        $entry = $this->makeDraftBalancedEntry('SPINE-EVENT-COMMIT-001');

        DB::transaction(function () use ($entry): void {
            app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');

            // Deferred to afterCommit — must not have fired yet mid-transaction.
            Event::assertNotDispatched(JournalEntryPosted::class);
        });

        Event::assertDispatched(JournalEntryPosted::class, 1);
    }

    public function test_post_entry_now_does_not_dispatch_journal_entry_posted_on_rollback(): void
    {
        app(CompanyContext::class)->clear();
        Event::fake([JournalEntryPosted::class]);

        try {
            DB::transaction(function (): void {
                $entry = $this->makeDraftBalancedEntry('SPINE-EVENT-ROLLBACK-001');

                app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Event::assertNotDispatched(JournalEntryPosted::class);
    }

    private function makeDraftBalancedEntry(string $entryNumber): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => $entryNumber,
            'entry_date' => now(),
            'description' => 'Spine atomicity test entry',
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
