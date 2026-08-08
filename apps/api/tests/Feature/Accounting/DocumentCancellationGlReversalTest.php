<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
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
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;
use Throwable;

/**
 * W-7 F-6, escalation (c) — cancelling a POSTED invoice left its revenue and
 * receivable legs standing in the ledger.
 *
 * `DocumentPostingService::cancel()` made NO GL call at all: the only listener on
 * `InvoiceCancelled` is the audit-chain one, and `GeneralLedgerService` carries no
 * sales-invoice reversal of any kind. So the document was withdrawn while its AR
 * debit, its revenue credits and its VAT credit stayed in the trial balance
 * forever.
 *
 * The reversal is a NEW hash-chained entry that MIRRORS the stored legs — read
 * back from the sealed entry, never recomputed from the document, so it can never
 * diverge from what was actually posted. The immutable ledger is preserved: the
 * original entry is not touched.
 *
 * docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md (F-6)
 */
#[UsesFrozenSeederFixture]
final class DocumentCancellationGlReversalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Product $product;

    private AccountingService $accountingService;

    private DocumentPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cancel Reversal Tenant',
            'slug' => 'cancel-reversal-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cancel Reversal Company',
            'legal_name' => 'Cancel Reversal Company SARL',
            'tax_id' => 'TAX-CANCEL-REV',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cancel Reversal User',
            'email' => 'cancel-reversal@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        (new TunisiaChartOfAccountsSeeder)->run($this->company->id, $this->tenant->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Clinique Al Amal',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-CANCEL-'.uniqid(),
            'name' => 'Doliprane 1000mg',
            'type' => ProductType::Part,
            'cost_price' => '5.000',
            'selling_price' => '10.000',
            'is_active' => true,
        ]);

        $this->accountingService = app(AccountingService::class);
        $this->postingService = app(DocumentPostingService::class);
    }

    public function test_cancelling_a_posted_invoice_mirrors_its_posted_legs_into_a_new_entry(): void
    {
        $invoice = $this->postedInvoiceWithGl();
        $original = $this->glEntryFor($invoice);
        $originalHash = $original->fiscal_hash;
        $originalLines = $original->lines
            ->map(static fn (JournalLine $line): string => sprintf(
                '%s|%s|%s|%s',
                $line->account_id,
                (string) ($line->partner_id ?? ''),
                (string) $line->debit,
                (string) $line->credit,
            ))
            ->sort()
            ->values()
            ->all();
        self::assertNotSame([], $originalLines, 'Precondition: the invoice really posted GL legs');

        $this->postingService->cancel($invoice, 'Customer withdrew the order', $this->user->id);

        $reversal = JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
            ->where('source_id', $invoice->id)
            ->with('lines')
            ->first();

        self::assertNotNull($reversal, 'Cancelling a posted invoice must write a reversing journal entry');
        self::assertSame(JournalEntryStatus::Posted, $reversal->status);
        self::assertNotNull($reversal->fiscal_hash, 'The reversal must be sealed into the GL hash chain');
        self::assertSame($original->chain_sequence + 1, $reversal->chain_sequence);
        self::assertSame($originalHash, $reversal->previous_hash, 'The reversal chains off the entry before it');

        // Mirror fidelity: every leg swapped, nothing invented, nothing dropped.
        $reversalLines = $reversal->lines
            ->map(static fn (JournalLine $line): string => sprintf(
                '%s|%s|%s|%s',
                $line->account_id,
                (string) ($line->partner_id ?? ''),
                (string) $line->credit,   // swapped back for comparison
                (string) $line->debit,
            ))
            ->sort()
            ->values()
            ->all();
        self::assertSame($originalLines, $reversalLines, 'Every reversal leg must mirror a posted leg exactly');

        // Balanced by construction, which is what the L1 lane's guard demands.
        self::assertSame(
            0,
            bccomp($this->sum($reversal, 'debit'), $this->sum($reversal, 'credit'), 3),
            'The reversal must balance',
        );
        self::assertSame(
            0,
            bccomp($this->sum($reversal, 'debit'), $this->sum($original, 'credit'), 3),
            'Reversal debits must equal the original credits',
        );

        // The sealed original is untouched — this is a forward correction, never a
        // mutation or a deletion of an immutable entry.
        $original->refresh();
        self::assertSame($originalHash, $original->fiscal_hash);
        self::assertCount($original->lines()->count(), $original->lines);
        self::assertSame(JournalEntryStatus::Posted, $original->status);

        // And the net effect on the ledger is zero for this document.
        $net = bcsub(
            bcadd($this->sum($original, 'debit'), $this->sum($reversal, 'debit'), 3),
            bcadd($this->sum($original, 'credit'), $this->sum($reversal, 'credit'), 3),
            3,
        );
        self::assertSame(0, bccomp($net, '0', 3), 'Original + reversal must net to zero');
    }

    public function test_cancelling_twice_cannot_double_reverse(): void
    {
        $invoice = $this->postedInvoiceWithGl();

        $this->postingService->cancel($invoice, 'first', $this->user->id);
        $this->postingService->cancel($invoice->fresh(['lines']), 'second', $this->user->id);
        // And directly, bypassing cancel()'s own already-cancelled short circuit.
        $this->accountingService->reverseDocumentGl($invoice->fresh(['lines']));

        self::assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
                ->where('source_id', $invoice->id)
                ->count(),
            'A document can only ever be reversed once',
        );
    }

    /**
     * GL gate I-1 / treasury gate finding 3 — the SERIAL double-cancel above
     * proves idempotence but cannot exercise the race: two ACTUALLY concurrent
     * cancels used to both pass `DocumentPostingService::cancel()`'s
     * pre-lock `$document->refresh()` re-check before either committed, so both
     * sealed a `REVCAN-…` reversal — the ledger's revenue/AR/VAT got
     * credited-then-debited TWICE. Two real PostgreSQL connections (forked
     * processes) are required to reproduce a genuine `SELECT ... FOR UPDATE`
     * race; SQLite has no row-level locking and no second connection to race
     * against, so this is skipped there like the codebase's other two-process
     * contention proofs (`OutboundInstrumentConcurrencyTest`).
     */
    public function test_concurrent_cancels_cannot_double_reverse_the_ledger(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Two-process concurrent-cancel contention requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the two-process concurrency proof.');
        }

        $invoiceId = $this->postedInvoiceWithGl()->id;
        $userId = $this->user->id;

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultFile = tempnam(sys_get_temp_dir(), 'doc-cancel-race-');
        self::assertIsString($resultFile);

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            DB::disconnect();
            fread($childSocket, 1);
            fclose($childSocket);

            try {
                /** @var Document $doc */
                $doc = Document::query()->findOrFail($invoiceId);
                app(DocumentPostingService::class)->cancel($doc, 'concurrent child', $userId);
                file_put_contents($resultFile, json_encode(['succeeded' => true], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($resultFile, json_encode([
                    'succeeded' => false,
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR));
                exit(0);
            }
        }

        fclose($childSocket);
        try {
            fwrite($parentSocket, '1');
            fclose($parentSocket);

            /** @var Document $doc */
            $doc = Document::query()->findOrFail($invoiceId);
            app(DocumentPostingService::class)->cancel($doc, 'concurrent parent', $userId);

            $status = 0;
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $childPayload = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($childPayload);
            self::assertTrue($childPayload['succeeded'] ?? false, 'the child cancel must not fail — it should serialize, not error');

            self::assertSame(
                1,
                JournalEntry::query()
                    ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
                    ->where('source_id', $invoiceId)
                    ->count(),
                'Two concurrent cancels must seal exactly ONE reversal entry, never two',
            );
        } finally {
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if (is_file($resultFile)) {
                unlink($resultFile);
            }
        }
    }

    /**
     * PostgreSQL fixtures must commit before forking so both independent PDOs
     * can see them. SQLite stays on the normal transaction-backed test path.
     *
     * @return list<string|null>
     */
    protected function connectionsToTransact(): array
    {
        return $this->usesCommittedPostgreSqlFixtures()
            ? []
            : [config('database.default')];
    }

    protected function tearDown(): void
    {
        try {
            if ($this->usesCommittedPostgreSqlFixtures()) {
                $this->artisan('migrate:fresh');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function usesCommittedPostgreSqlFixtures(): bool
    {
        return DB::getDriverName() === 'pgsql'
            && $this->name() === 'test_concurrent_cancels_cannot_double_reverse_the_ledger';
    }

    public function test_a_document_that_never_reached_the_gl_reverses_nothing(): void
    {
        $invoice = $this->invoice();
        self::assertSame(0, JournalEntry::query()->where('source_id', $invoice->id)->count());

        $this->postingService->cancel($invoice, 'never posted to GL', $this->user->id);

        self::assertSame(
            0,
            JournalEntry::query()->where('source_id', $invoice->id)->count(),
            'A document with no GL entry must not mint an empty reversal',
        );
        self::assertSame(DocumentStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_an_unbalanced_original_refuses_the_cancel_instead_of_minting_an_unbalanced_reversal(): void
    {
        $invoice = $this->postedInvoiceWithGl();
        $original = $this->glEntryFor($invoice);

        // Simulate the ledger's one legacy unbalanced entry (the campaign's own
        // DOC06 probe): mirroring it would produce an equally unbalanced entry,
        // which the L1 lane's Sum(dr) == Sum(cr) invariant forbids.
        //
        // `withoutEvents` is required because `JournalLineObserver` refuses to add
        // a line to a hash-chained entry — the ledger really is immutable, which is
        // also why the stranded 19.000 on the live tenant can only be corrected
        // forward. This fixture reproduces the ROW, not a reachable code path.
        JournalLine::withoutEvents(fn () => JournalLine::create([
            'journal_entry_id' => $original->id,
            'account_id' => $original->lines->first()->account_id,
            'debit' => '0',
            'credit' => '19.000',
            'description' => 'Legacy imbalance',
        ]));

        try {
            $this->postingService->cancel($invoice, 'should refuse', $this->user->id);
            self::fail('Cancelling must refuse rather than seal an unbalanced reversal');
        } catch (\DomainException) {
            // expected
        }

        self::assertSame(
            0,
            JournalEntry::query()
                ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
                ->where('source_id', $invoice->id)
                ->count(),
            'No reversal may be persisted when it could not balance',
        );
        $invoice->refresh();
        self::assertSame(DocumentStatus::Posted, $invoice->status, 'The refusal must roll the cancel back too');
        self::assertNull($invoice->cancelled_at);
    }

    /**
     * The refusal must name a remedy that ACTUALLY WORKS.
     *
     * History: the message originally said "Post a correcting entry first",
     * which was impossible advice — `reverseDocumentGl()` only counted entries
     * with `source_type = 'Document' AND source_id = $document->id`, and the only
     * manual-entry writer, `JournalEntryController::store()`, hard-codes
     * `source_type = 'manual'`, so no correcting entry reachable through the
     * product could enter that predicate. The GL gate replaced it with "contact
     * support" — honest, but a dead end.
     *
     * R2-F4 (owner ruling c4) built the remedy: a correcting-entry DOCUMENT
     * linked via `source_document_id`, whose legs the reversal now counts. The
     * message points at it again, and this time the loop really closes —
     * `CorrectingEntryUnblocksCancellationTest` drives it end to end.
     */
    public function test_the_refusal_message_names_the_correcting_entry_remedy(): void
    {
        $invoice = $this->postedInvoiceWithGl();
        $original = $this->glEntryFor($invoice);

        JournalLine::withoutEvents(fn () => JournalLine::create([
            'journal_entry_id' => $original->id,
            'account_id' => $original->lines->first()->account_id,
            'debit' => '0',
            'credit' => '19.000',
            'description' => 'Legacy imbalance',
        ]));

        try {
            $this->postingService->cancel($invoice, 'should refuse', $this->user->id);
            self::fail('Cancelling must refuse rather than seal an unbalanced reversal');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'CORRECTING ENTRY',
                $exception->getMessage(),
                'The remedy the message names must be the one the product actually offers',
            );
            self::assertStringNotContainsString(
                'no self-service way',
                $exception->getMessage(),
                'There IS a self-service way now — R2-F4 built it',
            );
            self::assertStringContainsString(
                '19.000',
                $exception->getMessage(),
                'The message must state the difference the correction has to cover',
            );
        }
    }

    /**
     * The L1 lane's lineless-document carve-out applies to the reversal too.
     *
     * A document with NO lines posts a lone AR leg with no revenue side on any
     * chart lacking a `SalesStampDutyPayable` account — a PRE-EXISTING broken
     * shape that `residualPlan()` deliberately leaves byte-identical
     * (`balanceAssertable = false`,
     * docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md).
     * Refusing to reverse it would make such a document impossible to cancel,
     * which is strictly worse than before this lane.
     */
    public function test_a_lineless_document_is_still_cancellable_and_its_lone_leg_is_mirrored(): void
    {
        // The one-legged variant needs a chart WITHOUT a sales stamp-duty account:
        // on the Tunisian chart the lineless branch sweeps the whole total into
        // 4375, which is nonsense but balanced. Every other chart gets the lone AR
        // leg — which is what `FiscalHardeningE2ETest` exercises.
        Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::SalesStampDutyPayable)
            ->update(['system_purpose' => null]);

        $invoice = $this->invoice(withLine: false);
        $this->accountingService->createInvoiceGLEntries($invoice->fresh(['lines']));
        $original = $this->glEntryFor($invoice);
        self::assertSame(
            1,
            $original->lines->count(),
            'Precondition: the lineless shape really does post a one-legged entry',
        );
        self::assertSame(
            0,
            bccomp($this->sum($original, 'credit'), '0', 3),
            'Precondition: …with no credit side at all',
        );

        $this->postingService->cancel($invoice->fresh(['lines']), 'lineless', $this->user->id);

        $reversal = JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
            ->where('source_id', $invoice->id)
            ->with('lines')
            ->first();

        self::assertNotNull($reversal, 'A lineless document must still be reversible');
        self::assertSame($this->sum($original, 'debit'), $this->sum($reversal, 'credit'));
        self::assertSame(DocumentStatus::Cancelled, $invoice->fresh()->status);
    }

    // ------------------------------------------------------------ helpers ---

    private function sum(JournalEntry $entry, string $column): string
    {
        $total = '0';
        foreach ($entry->lines as $line) {
            $total = bcadd($total, (string) $line->{$column}, 3);
        }

        return $total;
    }

    private function glEntryFor(Document $document): JournalEntry
    {
        $entry = JournalEntry::query()
            ->where('source_type', 'Document')
            ->where('source_id', $document->id)
            ->with('lines')
            ->first();
        self::assertNotNull($entry);

        return $entry;
    }

    private function postedInvoiceWithGl(): Document
    {
        $invoice = $this->invoice();
        $this->accountingService->createInvoiceGLEntries($invoice);

        return $invoice->fresh(['lines']);
    }

    private function invoice(bool $withLine = true): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-CANCEL-'.uniqid(),
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'balance_due' => '119.000',
            'currency' => 'TND',
        ]);

        if ($withLine) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $invoice->id,
                'product_id' => $this->product->id,
                'line_number' => 1,
                'description' => 'Doliprane 1000mg',
                'quantity' => '10',
                'unit_price' => '10.000',
                'tax_rate' => '19.00',
                'line_total' => '100.000',
            ]);
        }

        return $invoice->fresh(['lines']);
    }
}
