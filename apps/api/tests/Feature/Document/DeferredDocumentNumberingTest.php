<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentSequence;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\SalesOrderCancelled;
use App\Modules\Document\Domain\Events\SalesOrderCancelledV2;
use App\Modules\Document\Domain\Exceptions\DocumentRenumberingException;
use App\Modules\Document\Domain\Exceptions\DocumentTransitionException;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Document\Domain\Services\SalesOrderService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

/**
 * R-2 / LEDGER D-T9-1 — DEFERRED DOCUMENT NUMBERING.
 *
 * N-14 closed the LINELESS class: an auto-save with no line authors nothing and
 * spends nothing. It did NOT close the one-line-then-abandoned class — the draft
 * reached one line, `createNewDraft()` allocated out of `document_sequences`, the
 * operator walked away, and the number was gone forever. That is the wave-4
 * `PO-2026-0001 … PO-2026-0009` orphan shape
 * (PLAYWRIGHT-first-tenant-campaign-wave2-imports-2026-08-24 §N-14).
 *
 * THE RULED CONTRACT, pinned here:
 *   1. A DRAFT carries NO `document_number`. The UI shows a placeholder.
 *   2. The number is allocated EXACTLY ONCE, at the first transition out of
 *      `Draft` that produces a real document — never on the way to `Cancelled`.
 *   3. Allocation happens INSIDE the confirming transaction, so a rollback
 *      returns the number to the sequence.
 *   4. A legacy draft that already holds a number keeps it.
 *   5. No fiscal seal ever hashes a NULL number.
 */
final class DeferredDocumentNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Deferred Numbering Tenant',
            'slug' => 'deferred-numbering',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deferred Co',
            'legal_name' => 'Deferred Co LLC',
            'tax_id' => 'TAX-DEFERRED',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
            'fiscal_chain_seed' => hash('sha256', 'deferred-seed'),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        if ($this->name() !== 'test_two_postgresql_sessions_confirming_the_same_stale_draft_keep_the_first_number') {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Deferred Customer',
            'type' => 'customer',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Deferred Product',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Deferred Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => false,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. A draft is born WITHOUT a number
    // ──────────────────────────────────────────────────────────────────

    public function test_an_auto_saved_draft_with_one_line_carries_no_number_and_burns_none(): void
    {
        $response = $this->actingAsUser($this->authorizedUser())
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10'],
                ],
            ]);

        $response->assertStatus(200);
        $draftId = $response->json('draft_id');
        $this->assertIsString($draftId);

        $draft = Document::query()->findOrFail($draftId);

        $this->assertNull(
            $draft->document_number,
            'A draft must be born unnumbered — the number belongs to the confirm.'
        );
        $this->assertNull(
            DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'and the quote sequence must not have been touched at all.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. Abandoning it burns nothing — the next document gets 0001
    // ──────────────────────────────────────────────────────────────────

    public function test_an_abandoned_draft_leaves_the_first_number_for_the_next_document(): void
    {
        $author = $this->authorizedUser();

        // Nine abandoned drafts — the wave-4 shape, one line each.
        for ($i = 0; $i < 9; $i++) {
            $this->actingAsUser($author)
                ->postJson('/api/v1/documents/auto-save', [
                    'type' => DocumentType::Quote->value,
                    'partner_id' => $this->customer->id,
                    'lines' => [
                        ['product_id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10'],
                    ],
                ])
                ->assertStatus(200);
        }

        $realQuote = $this->draftQuoteWithOneLine();

        $this->actingAsUser($author)
            ->postJson("/api/v1/quotes/{$realQuote->id}/confirm")
            ->assertStatus(200);

        $this->assertSame(
            sprintf('QT-%d-0001', (int) date('Y')),
            (string) $realQuote->refresh()->document_number,
            'Nine abandoned drafts must not have pushed the real quote to 0010.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. Confirm allocates — and two confirms are sequential
    // ──────────────────────────────────────────────────────────────────

    public function test_confirming_allocates_the_number_and_successive_confirms_are_sequential(): void
    {
        $author = $this->authorizedUser();
        $year = (int) date('Y');

        $first = $this->draftQuoteWithOneLine();
        $second = $this->draftQuoteWithOneLine();

        $this->actingAsUser($author)->postJson("/api/v1/quotes/{$first->id}/confirm")->assertStatus(200);
        $this->actingAsUser($author)->postJson("/api/v1/quotes/{$second->id}/confirm")->assertStatus(200);

        $this->assertSame(sprintf('QT-%d-0001', $year), (string) $first->refresh()->document_number);
        $this->assertSame(sprintf('QT-%d-0002', $year), (string) $second->refresh()->document_number);
        $this->assertSame(DocumentStatus::Confirmed, $first->status);
        $this->assertSame(DocumentStatus::Confirmed, $second->status);
    }

    public function test_a_second_confirm_of_the_same_document_does_not_reallocate(): void
    {
        $author = $this->authorizedUser();
        $quote = $this->draftQuoteWithOneLine();

        $this->actingAsUser($author)->postJson("/api/v1/quotes/{$quote->id}/confirm")->assertStatus(200);
        $allocated = (string) $quote->refresh()->document_number;

        $this->actingAsUser($author)->postJson("/api/v1/quotes/{$quote->id}/confirm")->assertStatus(200);

        $this->assertSame($allocated, (string) $quote->refresh()->document_number);
        $this->assertSame(
            1,
            (int) DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'The idempotent re-confirm must not spend a second number.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. A rollback mid-confirm returns the number
    // ──────────────────────────────────────────────────────────────────

    public function test_a_rolled_back_confirm_does_not_burn_the_number(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);

        $doomed = $this->draftQuoteWithOneLine();

        DB::beginTransaction();

        try {
            $statusService->transition($doomed, DocumentStatus::Confirmed);
            $this->assertNotNull(
                $doomed->document_number,
                'Sanity: the number is allocated inside the transaction.'
            );
        } finally {
            // `finally`, not a bare call: a failing assertion above must not leave
            // the transaction open and take every later test in this file with it.
            DB::rollBack();
        }

        $this->assertNull(
            $doomed->refresh()->document_number,
            'The rolled-back confirm must leave the draft unnumbered.'
        );

        $survivor = $this->draftQuoteWithOneLine();
        $statusService->transition($survivor, DocumentStatus::Confirmed);

        $this->assertSame(
            sprintf('QT-%d-0001', (int) date('Y')),
            (string) $survivor->refresh()->document_number,
            'The number the rolled-back confirm reserved must be handed to the next one.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. A legacy numbered draft keeps its number
    // ──────────────────────────────────────────────────────────────────

    public function test_a_legacy_numbered_draft_keeps_its_number_on_confirm(): void
    {
        $legacy = $this->draftQuoteWithOneLine();
        $legacy->update(['document_number' => 'QT-2026-0007']);

        DocumentSequence::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Quote->value,
            'year' => (int) date('Y'),
            'last_number' => 7,
        ]);

        $this->actingAsUser($this->authorizedUser())
            ->postJson("/api/v1/quotes/{$legacy->id}/confirm")
            ->assertStatus(200);

        $this->assertSame(
            'QT-2026-0007',
            (string) $legacy->refresh()->document_number,
            'A draft that already holds a number must never be renumbered.'
        );
        $this->assertSame(
            7,
            (int) DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'and confirming it must not advance the sequence either.'
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. Cancelling an abandoned draft allocates nothing
    // ──────────────────────────────────────────────────────────────────

    public function test_cancelling_an_abandoned_draft_allocates_nothing(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);

        $abandoned = $this->draftQuoteWithOneLine();

        $statusService->transition($abandoned, DocumentStatus::Cancelled);

        $this->assertNull(
            $abandoned->refresh()->document_number,
            'A draft that dies on the way to Cancelled never became a document — it must spend nothing.'
        );
        $this->assertNull(
            DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number')
        );
    }

    public function test_cancelling_an_unnumbered_sales_order_uses_a_nullable_versioned_audit_event(): void
    {
        Event::fake([SalesOrderCancelled::class, SalesOrderCancelledV2::class]);
        $actor = $this->authorizedUser();

        $salesOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => null,
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        app(DocumentPostingService::class)->cancel($salesOrder, 'Abandoned', $actor->id);

        Event::assertNotDispatched(SalesOrderCancelled::class);
        Event::assertDispatched(
            SalesOrderCancelledV2::class,
            static fn (SalesOrderCancelledV2 $event): bool => $event->documentNumber === null
                && $event->draftReference === 'DRAFT-'.$salesOrder->id,
        );
        self::assertNull($salesOrder->refresh()->document_number);
    }

    // ──────────────────────────────────────────────────────────────────
    // 6b. A caller may NAME an unnumbered document; it may never RENUMBER one
    // ──────────────────────────────────────────────────────────────────

    /**
     * `ExpenseService::post()` and `IncomeService::post()` number their document
     * from `EXP-`/`INC-` sequences this service does not own, and hand the result
     * to `transition()` in `$extraAttributes`. That predates R-2 and stays legal:
     * the caller's number wins and nothing is allocated behind it.
     */
    public function test_a_caller_supplied_number_wins_and_nothing_is_allocated_behind_it(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);

        $expense = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => null,
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
        ]);

        // `Draft -> Posted` is legal for this type (postsDirectlyFromDraft).
        $statusService->transition($expense, DocumentStatus::Posted, [
            'document_number' => 'EXP-2026-0042',
        ]);

        $this->assertSame('EXP-2026-0042', (string) $expense->refresh()->document_number);
        $this->assertNull(
            DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Expense->value)
                ->value('last_number'),
            'The caller owns this sequence — DocumentStatusService must not touch its own.'
        );
    }

    public function test_a_null_caller_value_does_not_suppress_allocation_for_an_unnumbered_draft(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);
        $quote = $this->draftQuoteWithOneLine();

        $statusService->transition($quote, DocumentStatus::Confirmed, [
            'document_number' => null,
        ]);

        self::assertSame(
            sprintf('QT-%d-0001', (int) date('Y')),
            $quote->refresh()->document_number,
            'A null value does not name the document; the single allocator must still run.',
        );
    }

    public function test_a_confirmed_document_cannot_be_renumbered_through_the_status_service(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);

        $salesOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'SO-2026-0042',
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
        ]);

        try {
            $statusService->transition($salesOrder, DocumentStatus::Cancelled, [
                'document_number' => 'SO-2026-9999',
            ]);
            self::fail('Expected a typed refusal before a Confirmed document could be renumbered.');
        } catch (DocumentRenumberingException $exception) {
            self::assertSame($salesOrder->id, $exception->documentId);
            self::assertSame('SO-2026-0042', $exception->existingNumber);
            self::assertSame('SO-2026-9999', $exception->attemptedNumber);
        }

        self::assertSame('SO-2026-0042', $salesOrder->refresh()->document_number);
        self::assertSame(DocumentStatus::Confirmed, $salesOrder->status);
    }

    public function test_a_dirty_confirmed_model_cannot_bypass_the_typed_renumber_refusal(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);

        $salesOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'SO-2026-0042',
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
        ]);
        $salesOrder->setAttribute('document_number', 'SO-2026-9999');

        $this->expectException(DocumentRenumberingException::class);

        try {
            $statusService->transition($salesOrder, DocumentStatus::Cancelled);
        } finally {
            $salesOrder->refresh();
            self::assertSame('SO-2026-0042', $salesOrder->document_number);
            self::assertSame(DocumentStatus::Confirmed, $salesOrder->status);
        }
    }

    public function test_a_stale_draft_model_cannot_overwrite_the_number_allocated_by_the_first_confirm(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);

        $firstInstance = $this->draftQuoteWithOneLine();
        $staleInstance = Document::query()->findOrFail($firstInstance->id);

        $statusService->transition($firstInstance, DocumentStatus::Confirmed);
        $firstNumber = (string) $firstInstance->document_number;

        self::assertNull($staleInstance->document_number, 'Sanity: the second instance is still Draft/null in memory.');
        $statusService->transition($staleInstance, DocumentStatus::Confirmed);

        self::assertSame($firstNumber, $staleInstance->document_number);
        self::assertSame(
            $firstNumber,
            Document::query()->findOrFail($firstInstance->id)->document_number,
            'A stale Draft/null model must never overwrite the number already established in the database.',
        );
        self::assertSame(
            1,
            (int) DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'The losing stale confirm must return its unused allocation to the sequence.',
        );

        $nextQuote = $this->draftQuoteWithOneLine();
        $statusService->transition($nextQuote, DocumentStatus::Confirmed);
        self::assertSame(sprintf('QT-%d-0002', (int) date('Y')), $nextQuote->document_number);
    }

    public function test_an_inconsistent_zero_row_claim_refreshes_the_model_before_refusing(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);
        $staleDraft = $this->draftQuoteWithOneLine();

        Document::query()->whereKey($staleDraft->id)->update([
            'status' => DocumentStatus::Cancelled->value,
        ]);

        try {
            $statusService->transition($staleDraft, DocumentStatus::Confirmed);
            self::fail('Expected an inconsistent concurrent state to refuse the transition.');
        } catch (DocumentTransitionException) {
            self::assertSame(DocumentStatus::Cancelled, $staleDraft->status);
            self::assertNull($staleDraft->document_number);
            self::assertFalse($staleDraft->isDirty());
        }

        self::assertNull(
            DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'A refused conditional claim must roll its sequence allocation back.',
        );
    }

    public function test_a_losing_staged_number_is_returned_to_the_sequence(): void
    {
        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);
        $firstInstance = $this->draftQuoteWithOneLine();
        $staleInstance = Document::query()->findOrFail($firstInstance->id);

        DB::transaction(function () use ($statusService, $firstInstance): void {
            $statusService->assignNumberIfMissing($firstInstance);
            $statusService->transition($firstInstance, DocumentStatus::Confirmed);
        });
        $firstNumber = (string) $firstInstance->document_number;

        DB::transaction(function () use ($statusService, $staleInstance): void {
            $statusService->assignNumberIfMissing($staleInstance);
            $staleInstance->lines()->update([
                'description' => 'Loser side effect '.$staleInstance->document_number,
            ]);
            $statusService->transition($staleInstance, DocumentStatus::Confirmed);
        });

        self::assertSame($firstNumber, $staleInstance->document_number);
        self::assertSame(
            1,
            (int) DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number'),
            'A staged allocation that loses the conditional claim must be returned while its sequence lock is held.',
        );
        self::assertSame(
            'Deferred Product',
            $firstInstance->lines()->firstOrFail()->description,
            'Losing the staged claim must roll back side effects written after the number was staged.',
        );
    }

    public function test_two_postgresql_sessions_confirming_the_same_stale_draft_keep_the_first_number(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The two-session row-lock race proof requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the two-session row-lock race proof.');
        }

        /** @var DocumentStatusService $statusService */
        $statusService = app(DocumentStatusService::class);
        $firstInstance = $this->draftQuoteWithOneLine();
        $staleInstance = Document::query()->findOrFail($firstInstance->id);

        DocumentSequence::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Quote->value,
            'year' => (int) date('Y'),
            'last_number' => 0,
        ]);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultFile = tempnam(sys_get_temp_dir(), 'deferred-number-race-');
        self::assertIsString($resultFile);

        // RefreshDatabase normally hides fixtures in the test transaction. Commit
        // this test's setup so the independent child session can see the same draft;
        // the finally block removes it and restores the transaction expected by the
        // trait's teardown callback.
        DB::commit();

        $childPid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $childPid);

        if ($childPid === 0) {
            fclose($parentSocket);
            DB::disconnect();

            try {
                DB::reconnect();
                DB::statement("SET lock_timeout = '8s'");
                DB::statement("SET statement_timeout = '20s'");
                $backendPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                fwrite($childSocket, $backendPid."\n");
                stream_set_timeout($childSocket, 8);

                if (fread($childSocket, 1) !== '1') {
                    throw new \RuntimeException('The child did not receive the race start signal.');
                }

                /** @var DocumentStatusService $childStatusService */
                $childStatusService = app(DocumentStatusService::class);
                $confirmed = $childStatusService->transition($staleInstance, DocumentStatus::Confirmed);
                $payload = [
                    'outcome' => 'success',
                    'returned_number' => $confirmed->document_number,
                    'database_number' => Document::query()->findOrFail($confirmed->id)->document_number,
                    'status' => $confirmed->status->value,
                ];
            } catch (Throwable $exception) {
                $payload = [
                    'outcome' => 'error',
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            file_put_contents($resultFile, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($childSocket);
            DB::disconnect();
            exit(0);
        }

        fclose($childSocket);
        $childReaped = false;
        $payload = [];
        $firstNumber = '';
        $databaseNumber = '';
        $sequenceNumber = 0;

        try {
            stream_set_timeout($parentSocket, 8);
            $backendPid = (int) trim((string) fgets($parentSocket));
            self::assertGreaterThan(0, $backendPid);

            DB::beginTransaction();
            $statusService->transition($firstInstance, DocumentStatus::Confirmed);
            $firstNumber = (string) $firstInstance->document_number;

            fwrite($parentSocket, '1');
            self::assertTrue(
                $this->waitUntilPostgresBackendWaitsForLock($backendPid),
                'Session B must reach a real PostgreSQL lock wait before session A commits.',
            );
            DB::commit();

            pcntl_waitpid($childPid, $childStatus);
            $childReaped = true;
            self::assertTrue(pcntl_wifexited($childStatus));
            self::assertSame(0, pcntl_wexitstatus($childStatus));

            $payload = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            $databaseNumber = (string) Document::query()->findOrFail($firstInstance->id)->document_number;
            $sequenceNumber = (int) DocumentSequence::query()
                ->where('company_id', $this->company->id)
                ->where('type', DocumentType::Quote->value)
                ->value('last_number');
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (! $childReaped) {
                posix_kill($childPid, SIGTERM);
                pcntl_waitpid($childPid, $childStatus);
            }
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if (is_file($resultFile)) {
                unlink($resultFile);
            }

            $this->deleteCommittedRaceFixtures();
            DB::beginTransaction();
        }

        self::assertSame('success', $payload['outcome'] ?? null, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame(DocumentStatus::Confirmed->value, $payload['status'] ?? null);
        self::assertSame($firstNumber, $payload['returned_number'] ?? null);
        self::assertSame($firstNumber, $payload['database_number'] ?? null);
        self::assertSame($firstNumber, $databaseNumber);
        self::assertSame(1, $sequenceNumber, 'The losing PostgreSQL session must not burn a second number.');
    }

    // ──────────────────────────────────────────────────────────────────
    // 7. FISCAL INVARIANT — no seal ever hashes a NULL number
    // ──────────────────────────────────────────────────────────────────

    public function test_a_delivery_note_is_numbered_before_its_fiscal_hash_is_sealed(): void
    {
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $deliveryNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::DeliveryNote,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => null,
            'document_date' => now()->format('Y-m-d'),
            'location_id' => $this->location->id,
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        // A non-physical line: the seal is the subject here, not stock movement.
        $deliveryNote->lines()->create([
            'line_number' => 1,
            'description' => 'Consulting hours',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => 0,
            'line_total' => '100.000',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $confirmed = app(DeliveryNoteService::class)->confirm($deliveryNote->refresh());
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $this->assertNotNull($confirmed->fiscal_hash, 'Sanity: the delivery note was sealed.');
        $this->assertSame(
            sprintf('DN-%d-0001', (int) date('Y')),
            (string) $confirmed->document_number,
            'The number must exist BEFORE the hash input is serialized — a NULL number in a fiscal hash is unrecoverable.'
        );
        $this->assertConditionalNumberAndStatusUpdate(
            $queries,
            $deliveryNote->id,
            'Delivery-note allocation and Draft → Confirmed must share one document UPDATE.',
        );
    }

    public function test_a_sales_order_number_and_status_are_persisted_in_the_same_update(): void
    {
        $salesOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => null,
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            app(SalesOrderService::class)->confirm($salesOrder);
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $this->assertConditionalNumberAndStatusUpdate(
            $queries,
            $salesOrder->id,
            'Sales-order allocation and Draft → Confirmed must share one document UPDATE.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function draftQuoteWithOneLine(): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Quote),
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => null,
            'document_date' => now()->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $document->lines()->create([
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Deferred Product',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => 0,
            'line_total' => '100.000',
        ]);

        return $document->refresh();
    }

    private function authorizedUser(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deferred User',
            'email' => 'deferred-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $user->givePermissionTo([
            'documents.view',
            'documents.update',
            'quotes.create',
            'quotes.update',
            'orders.create',
            'invoices.create',
            'credit-notes.create',
            'purchase-orders.create',
            'deliveries.create',
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
        ]);

        return $user;
    }

    private function waitUntilPostgresBackendWaitsForLock(int $backendPid): bool
    {
        $deadline = microtime(true) + 8.0;

        do {
            $activity = DB::selectOne(
                'SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?',
                [$backendPid],
            );

            if ($activity?->wait_event_type === 'Lock') {
                return true;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function deleteCommittedRaceFixtures(): void
    {
        $documentIds = DB::table('documents')->where('company_id', $this->company->id)->pluck('id');

        DB::table('document_lines')->whereIn('document_id', $documentIds)->delete();
        DB::table('documents')->whereIn('id', $documentIds)->delete();
        DB::table('document_sequences')->where('company_id', $this->company->id)->delete();
        DB::table('locations')->where('company_id', $this->company->id)->delete();
        DB::table('products')->where('company_id', $this->company->id)->delete();
        DB::table('partners')->where('company_id', $this->company->id)->delete();
        DB::table('companies')->where('id', $this->company->id)->delete();
        DB::table('tenants')->where('id', $this->tenant->id)->delete();
        app(CompanyContext::class)->clear();
    }

    /**
     * @param  array<array-key, array{query: string, bindings: array<array-key, mixed>, time: float|null}>  $queries
     */
    private function assertConditionalNumberAndStatusUpdate(array $queries, string $documentId, string $message): void
    {
        $update = collect($queries)->first(static function (array $query) use ($documentId): bool {
            $sql = strtolower($query['query']);

            return str_starts_with($sql, 'update "documents"')
                && str_contains($sql, '"document_number"')
                && str_contains($sql, '"status"')
                && in_array($documentId, $query['bindings'], true);
        });

        self::assertIsArray($update, $message);
        self::assertMatchesRegularExpression(
            '/where "id" = \? and "status" = \? and "document_number" is null$/',
            strtolower($update['query']),
            $message.' The write must be guarded by id, expected status, and a NULL number.',
        );
    }

    private function actingAsUser(User $user): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id);
    }
}
