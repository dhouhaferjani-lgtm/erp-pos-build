<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ImmutableJournalEntryException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * N-6 — no orphan DRAFT clearing entry may survive a failed synchronous post
 * (fiscal gate R2-F5), and the ledger must reach that outcome by ROLLBACK, not
 * by DELETE (DPA consolidation r1).
 *
 * THE DEFECT (R2-F5). `clearCustomerAdvanceToReceivable()` created its entry in
 * its own `DB::transaction` and posted AFTER that returned. Under an enclosing
 * transaction that inner one is a SAVEPOINT which has already been RELEASED, so
 * when `postEntryNow()` threw and the CALLER caught it —
 * `SalesOrderToInvoiceConverter` downgrades a non-balance failure to a payload
 * note — the DRAFT entry stayed durable: a journal entry that discharges
 * nothing, that no reconcile consumes, and that nothing links to (the caller
 * returns before recording `advance_journal_entry_id`).
 *
 * THE FIRST FIX WAS RIGHT ABOUT THE SYMPTOM AND WRONG ABOUT THE MECHANISM. It
 * DELETEd the orphan as compensation. `journal_entries` and `journal_lines` are
 * APPEND-ONLY under the document-per-action contract — a correction is a
 * reversing document, never a row delete — and the `backend-dpa-guard` ratchet
 * said so with two new keys.
 *
 * THE SHIPPED FIX IS CONTAINMENT. The post now happens INSIDE the creating
 * savepoint, so a refusal unwinds it and the rows never existed. Same outcome,
 * no DELETE, and no re-read-and-assert-Draft belt needed to make a delete safe.
 * Both facts are asserted below: zero rows AND zero DELETE statements.
 */
final class ClearCustomerAdvanceOrphanDraftTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $executedStatements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures('TN');

        // Every statement this test causes, so the assertions can be about what
        // the DATABASE was asked to do rather than about what the source says.
        DB::listen(function (QueryExecuted $query): void {
            $this->executedStatements[] = $query->sql;
        });
    }

    public function test_a_failed_synchronous_post_leaves_no_draft_entry_behind_and_rethrows(): void
    {
        $this->seedAdvance('100.000');
        $bank = Account::findByPurposeOrFail($this->dpCompany->id, SystemAccountPurpose::Bank);

        $before = JournalEntry::query()->where('company_id', $this->dpCompany->id)->count();

        // Make the post FAIL after the entry is already durable in its own
        // savepoint. Unbalancing the entry between line creation and the post is
        // the repo's established technique for this
        // (`DocumentConversionScenarioTest::it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud`)
        // and it raises from `sealAndPersistEntry()` — the same frame every other
        // post failure raises from, so the cleanup being proved here is the same
        // cleanup for all of them.
        $injecting = false;
        JournalLine::created(function (JournalLine $line) use (&$injecting, $bank): void {
            if ($injecting) {
                return;
            }

            $entry = JournalEntry::find($line->journal_entry_id);
            if ($entry === null
                || $entry->source_type !== 'prepayment_application'
                || $entry->status !== JournalEntryStatus::Draft) {
                return;
            }

            $injecting = true;
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $bank->id,
                'debit' => '7.000',
                'credit' => '0',
                'description' => 'Injected unbalancing leg',
                'line_order' => 99,
            ]);
            $injecting = false;
        });

        // THE PRODUCTION SHAPE, and getting it wrong hides the defect: the
        // enclosing transaction must COMMIT. `SalesOrderToInvoiceConverter`
        // CATCHES a non-balance failure and downgrades it to a payload note, so
        // the conversion transaction commits and the draft entry — created in an
        // inner savepoint that was already released — stays durable. A test that
        // lets the throw escape its own `DB::transaction` rolls the entry back
        // for free and passes with or without the fix.
        $threw = null;

        DB::transaction(function () use (&$threw): void {
            try {
                app(GeneralLedgerService::class)->clearCustomerAdvanceToReceivable(
                    $this->dpCompany->id,
                    $this->dpPartner->id,
                    (string) Str::uuid(),
                    '100.000',
                    now(),
                    'R2-F5 probe',
                    null,
                    'TND',
                    PostingMode::SynchronousInTransaction,
                );
            } catch (\Throwable $e) {
                $threw = $e;
            }
        });

        $this->assertNotNull($threw, 'the post failure must be re-thrown, never swallowed by the cleanup');

        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('company_id', $this->dpCompany->id)
                ->where('source_type', 'prepayment_application')
                ->count(),
            'no orphan DRAFT clearing entry may survive a failed post',
        );
        $this->assertSame(
            $before,
            JournalEntry::query()->where('company_id', $this->dpCompany->id)->count(),
        );

        // R3 — and its LINES with it. The r2 cleanup removed them with a builder
        // mass-delete, which is what the gate found; they must go through the
        // models now, and they must still actually go.
        $this->assertSame(
            0,
            JournalLine::query()
                ->whereIn('journal_entry_id', JournalEntry::query()
                    ->where('company_id', $this->dpCompany->id)
                    ->where('source_type', 'prepayment_application')
                    ->pluck('id'))
                ->count(),
            'no orphan clearing LINES may survive either',
        );

        // DPA consolidation r1 — HOW the rows are gone is part of the contract,
        // not an implementation detail. `journal_entries` and `journal_lines`
        // are append-only; the rows must be undone by the transaction, never
        // removed by a compensating DELETE. Measured on the query log rather
        // than asserted about the source, so a future re-introduction of a
        // delete on this path fails here even if it is written differently.
        $deletes = array_values(array_filter(
            $this->executedStatements,
            static fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'delete')
                && (str_contains(strtolower($sql), 'journal_entries')
                    || str_contains(strtolower($sql), 'journal_lines')),
        ));

        $this->assertSame(
            [],
            $deletes,
            'the draft must be rolled back, never deleted — journal rows are append-only',
        );
    }

    /**
     * R3 (treasury gate r3) — the cleanup must delete through the MODELS, so the
     * guard that protects a chained entry's lines actually runs.
     *
     * `$entry->lines()->delete()` is a builder mass-delete: one
     * `DELETE ... WHERE journal_entry_id = ?`, no model events, so
     * `JournalLineObserver::deleting()` never fires. The gate probed it against a
     * POSTED, hash-chained entry and it SUCCEEDED — while `$entry->delete()`, a
     * model delete, was correctly refused by its own observer. The header was
     * protected and its lines were not.
     *
     * This asserts the guard from both sides on a real chained entry.
     */
    public function test_a_chained_entrys_lines_cannot_be_removed_through_a_model_delete(): void
    {
        $this->seedAdvance('100.000');

        $entry = JournalEntry::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('source_type', 'advance')
            ->sole();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status, 'precondition: the entry is posted');
        $this->assertTrue($entry->isChained(), 'precondition: and hash-chained');

        $line = $entry->lines()->firstOrFail();

        try {
            $line->delete();
            $this->fail('a chained entry\'s line must not be deletable');
        } catch (ImmutableJournalEntryException $e) {
            $this->assertStringContainsString((string) $entry->entry_number, $e->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('a chained entry must not be deletable');
        } catch (ImmutableJournalEntryException $e) {
            $this->assertStringContainsString((string) $entry->entry_number, $e->getMessage());
        }

        $this->assertSame(2, $entry->lines()->count(), 'nothing was removed');
    }

    private function seedAdvance(string $amount): void
    {
        $bank = Account::findByPurposeOrFail($this->dpCompany->id, SystemAccountPurpose::Bank);

        app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->dpCompany->id,
            partnerId: $this->dpPartner->id,
            advanceId: (string) Str::uuid(),
            amount: $amount,
            paymentMethodAccountId: $bank->id,
            date: now(),
            user: $this->dpUser,
            description: 'Advance for the R2-F5 probe',
            currencyCode: 'TND',
        );
    }
}
