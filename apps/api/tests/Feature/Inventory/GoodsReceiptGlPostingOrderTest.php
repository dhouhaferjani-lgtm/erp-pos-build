<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\Events\GoodsReceived;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptInput;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptLineInput;
use App\Modules\Procurement\Application\StandaloneReceiptService;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * DPA Wave 3 · sub-wave 3A · **T5b — normalise `GoodsReceiptService`'s GL
 * posting order** (BLOCKING for 3C, D-9.4 / inv F-1 / fiscal C-1's residue).
 *
 * ## The hazard
 *
 * `GoodsReceived` was dispatched with a SYNCHRONOUS `event()` INSIDE the
 * per-line receipt loop, and its only listener (`PostGrIrOnGoodsReceipt`,
 * `EventServiceProvider.php:145-147`) is **not** `ShouldQueue`. It posts GR-IR →
 * `generateEntryNumber` → `pg_advisory_xact_lock(hashtextextended(company_id,0))`
 * (`GeneralLedgerService.php:4609-4612`). `_xact_` advisory locks are released at
 * the OUTERMOST commit, so from receipt **line 2** onward the service held the
 * per-company GL advisory while still requesting `stock_levels` / `products` row
 * locks for the remaining lines.
 *
 * Today that is harmless only because NO other inventory writer takes the
 * advisory at all. Wave 3 creates four. At that point it is a live ABBA pair
 * under BOTH candidate orderings, so no choice on the Wave-3 side can fix it —
 * which is why T5b is a blocking 3A task rather than a tidy-up.
 *
 * ## The second site is LIVE, not dead
 *
 * `postFailClosedGrirIfRequested` (`:392-410` pre-fix) calls
 * `createGoodsReceiptGrIrEntry` DIRECTLY, bypassing the event. Plan §0b.2 and
 * both round-1 gate reviews called it dead on the strength of
 * `grep -rn failClosedGrir`, which finds nothing — because
 * `StandaloneReceiptService.php:134` passes `true` **POSITIONALLY**:
 * `post($draft, $input->actorId, null, true)`. Corrected in plan §0q.5 /
 * D-9.2′'s GR row (Revision 4). This suite therefore exercises the
 * standalone-receipt path explicitly.
 *
 * ## What is proved here
 *
 * The contention half is a real two-connection PostgreSQL probe of `pg_locks`,
 * taken from a SEPARATE connection at the moment the receipt loop creates the
 * SECOND line's stock movement — i.e. after that line's row locks were taken.
 * Pre-T5b the advisory is held at that instant; post-T5b it is not. This is the
 * seed of T11c's contention pairs 4-5 (counting x GR, POS x GR); the harness is
 * deliberately written to be handed to that task.
 */
final class GoodsReceiptGlPostingOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE_CONNECTION = 'w3ab_lock_probe';

    /**
     * The tables `GoodsReceiptService::post()` takes ROW LOCKS on. None of these may
     * be written after the GL phase opens (fix round 2, NEW-2).
     *
     * @var list<string>
     */
    private const RECEIPT_ROW_LOCK_TABLES = [
        'documents',
        'document_lines',
        'document_sequences',
        'goods_receipts',
        'goods_receipt_lines',
        'stock_levels',
        'stock_movements',
        'products',
    ];

    /** @var list<string> */
    private const GL_TABLES = ['journal_entries', 'journal_lines'];

    private Tenant $tenant;

    private Company $company;

    private Location $warehouse;

    private Partner $supplier;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'T5b Company',
            'legal_name' => 'T5b Company SARL',
            'tax_id' => 'TAX-T5B-'.random_int(1000, 9999),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-T5B',
            'name' => 'T5b Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'T5b Supplier',
            'type' => PartnerType::Supplier,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    // =================================================================
    // CHARACTERISATION — the ledger must be byte-identical
    // =================================================================

    public function test_a_multi_line_receipt_posts_one_grir_entry_per_movement(): void
    {
        $purchaseOrder = $this->confirmedPurchaseOrder([
            ['qty' => '10.0000', 'price' => '5.000'],
            ['qty' => '4.0000', 'price' => '2.500'],
            ['qty' => '7.0000', 'price' => '1.000'],
        ]);

        $this->receive($purchaseOrder);

        $movementIds = StockMovement::query()
            ->where('company_id', $this->company->id)
            ->pluck('id')
            ->all();
        self::assertCount(3, $movementIds);

        $entries = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'goods_receipt')
            ->get();

        self::assertCount(3, $entries, 'one GR-IR entry per receipt movement, buffered or not');
        self::assertEqualsCanonicalizing(
            $movementIds,
            $entries->pluck('source_id')->all(),
            'each entry stays keyed on ITS movement — buffering must not re-key anything',
        );

        // The amounts: 10x5 + 4x2.5 + 7x1 = 67.000, debited to Inventory.
        $total = '0';
        foreach ($entries->flatMap->lines as $line) {
            $total = bcadd($total, $this->numeric($line->debit), 3);
        }
        self::assertSame(0, bccomp($total, '67.000', 3));
    }

    public function test_the_relative_order_of_the_grir_entries_is_preserved(): void
    {
        // Entry numbers and chain sequences are allocated in posting order, so a
        // reordered flush would renumber the ledger even though the amounts
        // matched. Line order in, entry order out.
        $purchaseOrder = $this->confirmedPurchaseOrder([
            ['qty' => '1.0000', 'price' => '11.000'],
            ['qty' => '1.0000', 'price' => '22.000'],
            ['qty' => '1.0000', 'price' => '33.000'],
        ]);

        $this->receive($purchaseOrder);

        $amountsInEntryOrder = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'goods_receipt')
            ->orderBy('entry_number')
            ->get()
            ->map(fn (JournalEntry $entry): string => $this->numeric($entry->lines->firstWhere(
                fn ($line): bool => bccomp($this->numeric($line->debit), '0', 3) > 0,
            )?->debit))
            ->all();

        self::assertSame(['11.000', '22.000', '33.000'], $amountsInEntryOrder);
    }

    public function test_a_failing_gl_post_still_cannot_fail_the_receipt(): void
    {
        // PostGrIrOnGoodsReceipt swallows whatever createGoodsReceiptGrIrEntry
        // throws (`PostGrIrOnGoodsReceipt.php:41-55`) — the stock movement has
        // already been written and a GL outage may never roll goods back out of
        // the warehouse. Buffering moves WHEN that call happens; it must not
        // change WHETHER a failure escapes.
        //
        // The failure is induced the way production would produce it: the 408
        // purpose is unmapped, so `Account::findByPurposeOrFail` raises.
        Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::GoodsReceivedNotInvoiced->value)
            ->update(['system_purpose' => null]);

        $purchaseOrder = $this->confirmedPurchaseOrder([
            ['qty' => '2.0000', 'price' => '5.000'],
            ['qty' => '3.0000', 'price' => '5.000'],
        ]);

        $this->receive($purchaseOrder);

        self::assertSame(
            2,
            StockMovement::query()->where('company_id', $this->company->id)->count(),
            'the goods were received even though every GR-IR post failed',
        );
        self::assertSame(
            0,
            JournalEntry::query()
                ->where('company_id', $this->company->id)
                ->where('source_type', 'goods_receipt')
                ->count(),
        );
    }

    /**
     * Fix round 1 · fiscal gate P3-6(b) — the fail-closed leg driven to an ACTUAL
     * failure, not merely to its happy path.
     *
     * `postFailClosedGrirIfRequested` calls `createGoodsReceiptGrIrEntry` DIRECTLY
     * rather than through `PostGrIrOnGoodsReceipt`, which is the entire point of
     * the flag: the listener swallows GL failures (a warehouse may not un-receive
     * goods because the ledger is down), the direct call does not. Buffering the
     * call to after the loop moves WHEN it runs; this pins that it still takes the
     * receipt down with it, and that the rollback is complete.
     */
    public function test_the_fail_closed_leg_takes_the_receipt_down_with_it(): void
    {
        // Same induced failure as the swallowing test above: the 408 purpose is
        // unmapped, so `Account::findByPurposeOrFail` raises.
        Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::GoodsReceivedNotInvoiced->value)
            ->update(['system_purpose' => null]);

        $purchaseOrder = $this->confirmedPurchaseOrder([
            ['qty' => '2.0000', 'price' => '5.000'],
            ['qty' => '3.0000', 'price' => '5.000'],
        ]);

        $raised = null;

        try {
            $this->receive($purchaseOrder, failClosedGrir: true);
        } catch (\Throwable $exception) {
            $raised = $exception;
        }

        self::assertNotNull($raised, 'the fail-closed GR-IR leg swallowed a GL failure — it must not');

        self::assertSame(
            0,
            StockMovement::query()->where('company_id', $this->company->id)->count(),
            'fail-closed means the goods are NOT received when the ledger refuses',
        );
        self::assertSame(
            0,
            JournalEntry::query()
                ->where('company_id', $this->company->id)
                ->where('source_type', 'goods_receipt')
                ->count(),
        );
        self::assertSame(
            DocumentStatus::Confirmed,
            $purchaseOrder->refresh()->status,
            'the purchase-order write that now precedes the GL phase must roll back too',
        );
    }

    public function test_the_listener_set_for_goods_received_is_still_exactly_one(): void
    {
        // T5b moves an event() call. The risk it carries is a changed listener
        // set, not changed arithmetic — so pin the set.
        /** @var array<string, list<mixed>> $raw */
        $raw = Event::getRawListeners();

        self::assertSame(
            ['App\Modules\Accounting\Listeners\PostGrIrOnGoodsReceipt'],
            array_map(
                static fn (mixed $listener): string => is_string($listener) ? $listener : get_debug_type($listener),
                $raw[GoodsReceived::class] ?? [],
            ),
        );
    }

    // =================================================================
    // THE CONTENTION PROOF — seed of T11c pairs 4-5
    // =================================================================

    public function test_the_company_gl_advisory_is_not_held_while_later_lines_take_row_locks(): void
    {
        $this->requirePostgres();

        $purchaseOrder = $this->confirmedPurchaseOrder([
            ['qty' => '10.0000', 'price' => '5.000'],
            ['qty' => '10.0000', 'price' => '5.000'],
            ['qty' => '10.0000', 'price' => '5.000'],
        ]);

        $observations = $this->observeAdvisoryDuringReceiptLoop($purchaseOrder);

        self::assertNotEmpty($observations, 'the probe never fired — the instrument is broken');
        self::assertTrue(
            $observations[0]['locks_visible'],
            'the probe connection must be able to see the receipt transaction\'s locks at all',
        );

        foreach ($observations as $index => $observation) {
            self::assertFalse(
                $observation['advisory_held'],
                sprintf(
                    'the per-company GL advisory was already held while line %d was taking row locks — '
                    .'that is the ABBA pair T5b exists to remove',
                    $index + 2,
                ),
            );
        }
    }

    /**
     * Fix round 1 · fiscal gate P2-2 — the advisory is TERMINAL, and that is now
     * an observed property rather than a docblock claim.
     *
     * The pre-fix comment at `GoodsReceiptService.php:723-729` asserted "every row
     * lock this receipt needs has been taken" before the GL phase. It was FALSE:
     * `$purchaseOrder->update()` — the `documents` row write — happened AFTER the
     * flush. D-9's architecture, and the four 3C writers designed against it, rest
     * on that invariant, so the fix moves the flush past the PO update and this
     * test pins it: at the instant a GR-IR entry is created (advisory held), the
     * receipt transaction must ALREADY hold the write lock on `documents`.
     *
     * The instrument is a WRITE-ORDER trace, not a `pg_locks` sample: under
     * `RefreshDatabase` the whole test runs inside one wrapping transaction on one
     * backend, so the fixture's own `documents` writes leave a relation-level
     * `RowExclusiveLock` standing for the entire test and no lock sample can
     * discriminate. The order in which the writes are ISSUED is the property that
     * decides the lock order anyway.
     *
     * ── TERMINAL, NOT MERELY ORDERED (fix round 2, fiscal gate NEW-2) ──
     * Round 1 traced exactly two writes — the PO update and the GR-IR entry — and
     * asserted the first preceded the second. That is an ALLOW-LIST: a third writer
     * added after the flush would take a row lock under a held advisory and the test
     * would not notice. This now watches the WHOLE statement stream via
     * `DB::listen(QueryExecuted)` and asserts the real invariant: **no write to any
     * table this receipt row-locks occurs after the GL phase opens.**
     *
     * `stored_events` / `audit_events` writes legitimately follow the first journal
     * insert — they are the GL post's own append-only artefacts, not receipt row
     * locks — which is why the assertion is "no ROW-LOCK write after the GL phase
     * opens" rather than the literal "the last statement is a GL statement" (the
     * last statement is in fact a `stored_events` update).
     */
    public function test_the_gl_phase_runs_after_every_row_lock_including_the_purchase_order(): void
    {
        $purchaseOrder = $this->confirmedPurchaseOrder([
            ['qty' => '10.0000', 'price' => '5.000'],
            ['qty' => '4.0000', 'price' => '2.500'],
        ]);

        $writes = $this->traceWriteOrderDuringReceipt($purchaseOrder);

        $glOpensAt = $this->firstIndexTouching($writes, self::GL_TABLES);
        self::assertNotNull($glOpensAt, 'no GR-IR entry was written — the instrument is broken');

        $lastRowLockAt = $this->lastIndexTouching($writes, self::RECEIPT_ROW_LOCK_TABLES);
        self::assertNotNull($lastRowLockAt, 'the receipt wrote no row-locked table at all — the instrument is broken');

        // …and specifically the purchase-order header, the write P2-2 was about.
        self::assertNotNull(
            $this->lastIndexTouching($writes, ['documents']),
            'the PO header write never fired — the instrument is broken',
        );

        self::assertLessThan(
            $glOpensAt,
            $lastRowLockAt,
            sprintf(
                'a row lock was taken AFTER the GL phase opened, so the per-company GL advisory is '
                .'held while further row locks are acquired — the invariant D-9 rests on, and that 3C '
                ."designs four writers against, is false.\nGL opens at #%d: %s\nOffending write #%d: %s",
                $glOpensAt,
                $writes[$glOpensAt],
                $lastRowLockAt,
                $writes[$lastRowLockAt],
            ),
        );
    }

    /**
     * The fail-closed GR-IR leg — `post($draft, $actor, null, true)` — is a SECOND
     * in-loop advisory acquisition that bypasses the event entirely, and it is
     * buffered by the same edit.
     *
     * HONEST NAME (fix round 1, inv F-3): this drives `GoodsReceiptService::post()`
     * DIRECTLY with the positional 4th argument. That covers the fail-closed FLAG;
     * it does NOT cover the standalone caller's outer transaction, which is what
     * makes the advisory non-terminal. That case is
     * `test_the_standalone_receipt_service_defers_its_gl_posting` /
     * `..._keeps_the_advisory_after_post_returns` below.
     */
    public function test_the_fail_closed_grir_leg_is_deferred_when_post_is_called_directly(): void
    {
        $this->requirePostgres();

        $purchaseOrder = $this->confirmedPurchaseOrder([
            ['qty' => '10.0000', 'price' => '5.000'],
            ['qty' => '10.0000', 'price' => '5.000'],
            ['qty' => '10.0000', 'price' => '5.000'],
        ]);

        $observations = $this->observeAdvisoryDuringReceiptLoop($purchaseOrder, failClosedGrir: true);

        self::assertNotEmpty($observations);
        foreach ($observations as $index => $observation) {
            self::assertFalse(
                $observation['advisory_held'],
                sprintf('fail-closed GR-IR still posted inside the loop at line %d', $index + 2),
            );
        }

        // …and the ledger is still complete on that path.
        self::assertSame(
            3,
            JournalEntry::query()
                ->where('company_id', $this->company->id)
                ->where('source_type', 'goods_receipt')
                ->count(),
        );
    }

    /**
     * …and the REAL standalone path, driven through the service that owns it
     * (fix round 1, inv F-3).
     *
     * `StandaloneReceiptService::execute()` opens its own `DB::transaction` (`:117`)
     * and calls `post($draft, $actor, null, true)` inside it (`:134`). That is the
     * only production caller of the fail-closed leg and the only one that NESTS
     * `post()`, so it is the shape the D-9 invariant has to survive.
     */
    public function test_the_standalone_receipt_service_defers_its_gl_posting(): void
    {
        $this->requirePostgres();
        $this->allowReceiptFirst();

        $product = $this->standaloneProduct();

        $observations = $this->observeAdvisoryDuringStandaloneReceipt($product, 'w3ab-fix-standalone-1');

        self::assertNotEmpty($observations, 'the probe never fired — the instrument is broken');
        self::assertTrue($observations[0]['locks_visible']);

        foreach ($observations as $index => $observation) {
            self::assertFalse(
                $observation['advisory_held'],
                sprintf(
                    'the standalone path held the per-company GL advisory while line %d took row locks',
                    $index + 2,
                ),
            );
        }

        self::assertSame(
            3,
            JournalEntry::query()
                ->where('company_id', $this->company->id)
                ->where('source_type', 'goods_receipt')
                ->count(),
        );
    }

    /**
     * CHARACTERISATION of the honest limit, pinned as a FACT rather than prose
     * (fix round 1, inv F-2 / fiscal P2-2; 3C's N-3).
     *
     * Moving the flush past the purchase-order write makes the advisory terminal
     * for `post()`. It cannot make it terminal for a CALLER that keeps working:
     * `pg_advisory_xact_lock` releases at the OUTERMOST commit, so inside
     * `StandaloneReceiptService::execute()`'s transaction the advisory is still
     * held when that caller writes `procurement_idempotency_keys` at `:137-143`.
     *
     * This test asserts that it IS still held there. When 3C's N-3 changes that,
     * this test must fail and be updated deliberately — which is the point.
     */
    public function test_the_standalone_caller_still_holds_the_advisory_after_post_returns(): void
    {
        $this->requirePostgres();
        $this->allowReceiptFirst();

        $product = $this->standaloneProduct();

        $this->bootProbeConnection();

        /** @var object{pid: int} $backend */
        $backend = DB::selectOne('select pg_backend_pid() as pid');
        $callerPid = $backend->pid;

        $heldAtIdempotencyWrite = [];
        $companyId = $this->company->id;

        DB::listen(function (QueryExecuted $query) use (&$heldAtIdempotencyWrite, $callerPid, $companyId): void {
            if (! str_contains($query->sql, 'procurement_idempotency_keys') || ! str_starts_with(strtolower(trim($query->sql)), 'update')) {
                return;
            }

            $heldAtIdempotencyWrite[] = $this->advisoryHeldBy($callerPid, $companyId);
        });

        try {
            app(StandaloneReceiptService::class)->execute(
                $this->standaloneInput($product, 'w3ab-fix-standalone-2'),
            );
        } finally {
            DB::purge(self::PROBE_CONNECTION);
        }

        self::assertNotEmpty($heldAtIdempotencyWrite, 'the idempotency-key write never fired — the instrument is broken');

        // Only the LAST sample matters: `execute()` also stamps the key in its
        // FIRST transaction, before any GL has posted, and that one is legitimately
        // false. The last is the write at `:137-143`, after `post()` returned.
        self::assertTrue(
            end($heldAtIdempotencyWrite),
            'the advisory was NOT held at the caller\'s post-post write — N-3 has changed and the '
            .'GoodsReceiptService docblock caveat must be revisited',
        );
    }

    // =================================================================
    // Harness — handed to T11c (contention pairs 4-5)
    // =================================================================

    /**
     * Narrow a decimal-column read to `numeric-string` for bcmath (rule 19).
     *
     * @return numeric-string
     */
    private function numeric(mixed $value): string
    {
        $string = (string) $value; // @phpstan-ignore-line cast.string

        if (! is_numeric($string)) {
            throw new \RuntimeException('expected a numeric decimal, got: '.$string);
        }

        /** @var numeric-string $string */
        return $string;
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Advisory-lock contention is observable only on PostgreSQL.');
        }
    }

    /**
     * Drive a receipt and, from a SEPARATE connection, sample `pg_locks` for the
     * receipt transaction at the moment each line AFTER THE FIRST creates its
     * stock movement — i.e. immediately after that line's `stock_levels` and
     * `products` row locks were taken.
     *
     * Line 1 is excluded on purpose: nothing has posted GL yet at that point
     * under either implementation, so it cannot discriminate.
     *
     * @return list<array{advisory_held: bool, locks_visible: bool}>
     */
    private function observeAdvisoryDuringReceiptLoop(Document $purchaseOrder, bool $failClosedGrir = false): array
    {
        $this->bootProbeConnection();

        /** @var object{pid: int} $backend */
        $backend = DB::selectOne('select pg_backend_pid() as pid');
        $receiptPid = $backend->pid;

        $observations = [];
        $seen = 0;

        $companyId = $this->company->id;

        $restoreDispatcher = $this->isolateModelEventDispatcher();

        StockMovement::created(function () use (&$observations, &$seen, $receiptPid, $companyId): void {
            $seen++;

            if ($seen < 2) {
                return; // line 1 cannot discriminate — see the docblock
            }

            // Match the COMPANY GL advisory specifically. The receipt already
            // holds unrelated advisory locks for the whole loop — ProductCostLock
            // takes one per product up front (`ProductCostLock.php:47`) — so a
            // bare `locktype = 'advisory'` count would be true under BOTH
            // implementations and prove nothing.
            //
            $observations[] = $this->sampleAdvisory($receiptPid, $companyId);
        });

        try {
            $this->receive($purchaseOrder, $failClosedGrir);
        } finally {
            $restoreDispatcher();
            DB::purge(self::PROBE_CONNECTION);
        }

        return $observations;
    }

    /**
     * The same probe, driven through `StandaloneReceiptService::execute()` — the
     * only production caller that NESTS `post()` inside its own transaction.
     *
     * @return list<array{advisory_held: bool, locks_visible: bool}>
     */
    private function observeAdvisoryDuringStandaloneReceipt(Product $product, string $idempotencyKey): array
    {
        $this->bootProbeConnection();

        /** @var object{pid: int} $backend */
        $backend = DB::selectOne('select pg_backend_pid() as pid');
        $receiptPid = $backend->pid;

        $observations = [];
        $seen = 0;
        $companyId = $this->company->id;

        $restoreDispatcher = $this->isolateModelEventDispatcher();

        StockMovement::created(function () use (&$observations, &$seen, $receiptPid, $companyId): void {
            $seen++;

            if ($seen < 2) {
                return; // line 1 cannot discriminate — see the docblock above
            }

            $observations[] = $this->sampleAdvisory($receiptPid, $companyId);
        });

        try {
            app(StandaloneReceiptService::class)->execute(
                $this->standaloneInput($product, $idempotencyKey),
            );
        } finally {
            $restoreDispatcher();
            DB::purge(self::PROBE_CONNECTION);
        }

        return $observations;
    }

    /**
     * @return array{advisory_held: bool, locks_visible: bool}
     */
    private function sampleAdvisory(int $pid, string $companyId): array
    {
        // Match the COMPANY GL advisory specifically. The receipt already holds
        // unrelated advisory locks for the whole loop — ProductCostLock takes one
        // per product up front (`ProductCostLock.php:47`) — so a bare
        // `locktype = 'advisory'` count would be true under BOTH implementations
        // and prove nothing.
        //
        // `pg_advisory_xact_lock(bigint)` stores the key split across
        // pg_locks.classid (high 32 bits) and .objid (low 32 bits); the GL key is
        // `hashtextextended(company_id, 0)` (`GeneralLedgerService.php:4610`, `:3257`).
        /** @var object{advisory: int, total: int} $row */
        $row = DB::connection(self::PROBE_CONNECTION)->selectOne(
            'select
                count(*) filter (
                    where locktype = \'advisory\'
                      and classid::bigint = ((hashtextextended(?, 0) >> 32) & 4294967295)
                      and objid::bigint = (hashtextextended(?, 0) & 4294967295)
                ) as advisory,
                count(*) as total
             from pg_locks where pid = ?',
            [$companyId, $companyId, $pid],
        );

        return [
            'advisory_held' => (int) $row->advisory > 0,
            'locks_visible' => (int) $row->total > 0,
        ];
    }

    private function advisoryHeldBy(int $pid, string $companyId): bool
    {
        return $this->sampleAdvisory($pid, $companyId)['advisory_held'];
    }

    /**
     * Every WRITE statement the receipt issues, in order (fix round 2, NEW-2).
     *
     * Whole stream, not an allow-list of two: the invariant is about what happens
     * after the GL phase opens, so a writer nobody thought to name is exactly what
     * this has to catch.
     *
     * @return list<string>
     */
    private function traceWriteOrderDuringReceipt(Document $purchaseOrder): array
    {
        /** @var list<string> $writes */
        $writes = [];

        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            $sql = strtolower(trim($query->sql));

            if (preg_match('/^(insert|update|delete)\b/', $sql) === 1) {
                $writes[] = $sql;
            }
        });

        $this->receive($purchaseOrder);

        return $writes;
    }

    /**
     * @param  list<string>  $writes
     * @param  list<string>  $tables
     */
    private function firstIndexTouching(array $writes, array $tables): ?int
    {
        foreach ($writes as $index => $sql) {
            if ($this->touchesAnyTable($sql, $tables)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $writes
     * @param  list<string>  $tables
     */
    private function lastIndexTouching(array $writes, array $tables): ?int
    {
        $found = null;

        foreach ($writes as $index => $sql) {
            if ($this->touchesAnyTable($sql, $tables)) {
                $found = $index;
            }
        }

        return $found;
    }

    /**
     * Match the QUOTED table identifier so `documents` cannot match
     * `document_lines` / `document_sequences`, and `products` cannot match
     * `goods_receipt_lines`'s columns.
     *
     * @param  list<string>  $tables
     */
    private function touchesAnyTable(string $sql, array $tables): bool
    {
        foreach ($tables as $table) {
            if (preg_match('/^(insert into|update|delete from)\s+"'.preg_quote($table, '/').'"/', $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swap the SHARED Eloquent event dispatcher for a clone for the duration of a
     * probe, and return the restorer (fix round 1, inv F-9).
     *
     * The suite previously ended with `StockMovement::flushEventListeners()`,
     * which is harmless only for as long as `StockMovement` has no observer: the
     * moment one is registered, the probe silently deletes it for every later test
     * in the process. Registering on a CLONE of the dispatcher leaves the real
     * listener set untouched — arrays are values in PHP, so the clone's listener
     * table is a separate array — and restoring the original drops the temporary
     * listener and nothing else.
     *
     * @return \Closure(): void
     */
    private function isolateModelEventDispatcher(): \Closure
    {
        /** @var Dispatcher $original */
        $original = Model::getEventDispatcher();

        Model::setEventDispatcher(clone $original);

        return static function () use ($original): void {
            Model::setEventDispatcher($original);
        };
    }

    private function bootProbeConnection(): void
    {
        /** @var array<string, mixed> $default */
        $default = config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::PROBE_CONNECTION => $default]);
        DB::purge(self::PROBE_CONNECTION);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function receive(Document $purchaseOrder, bool $failClosedGrir = false): void
    {
        $service = app(GoodsReceiptService::class);

        /** @var Document $fresh */
        $fresh = $purchaseOrder->fresh(['lines']);

        $receivedQuantities = [];
        foreach ($fresh->lines as $line) {
            $receivedQuantities[$line->id] = (string) $line->quantity;
        }

        $draft = $service->createDraft(
            $fresh,
            $receivedQuantities,
            [],
            [],
            [],
            null,
            $this->user->id,
        );

        // Positional 4th argument — exactly how StandaloneReceiptService:134
        // reaches the fail-closed GR-IR site.
        $service->post($draft, $this->user->id, null, $failClosedGrir);
    }

    private function allowReceiptFirst(): void
    {
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
            'allow_receipt_first' => true,
            'allow_invoice_first' => false,
            'invoice_first_requires_approval' => true,
        ]);
    }

    private function standaloneProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'T5B-SR-'.random_int(1000, 9999),
            'name' => 'T5b Standalone Part',
            'type' => ProductType::Part,
            'cost_price' => '5.000',
            'is_active' => true,
            'is_physical' => true,
        ]);
    }

    /**
     * Three lines on ONE product-less-of-a-problem receipt: the probe skips line 1,
     * so a single-line receipt cannot discriminate.
     */
    private function standaloneInput(Product $product, string $idempotencyKey): StandaloneReceiptInput
    {
        $line = static fn (Product $product): StandaloneReceiptLineInput => new StandaloneReceiptLineInput(
            productId: $product->id,
            variantId: null,
            quantity: '10.0000',
            freeQuantity: '0.0000',
            unitPrice: '5.000',
            batch: null,
        );

        return new StandaloneReceiptInput(
            companyId: $this->company->id,
            supplierId: $this->supplier->id,
            locationId: $this->warehouse->id,
            actorId: $this->user->id,
            idempotencyKey: $idempotencyKey,
            source: 'standalone_receipt',
            externalReference: null,
            externalDate: null,
            postImmediately: true,
            lines: [$line($product), $line($product), $line($product)],
        );
    }

    /**
     * @param  list<array{qty: string, price: string}>  $lines
     */
    private function confirmedPurchaseOrder(array $lines): Document
    {
        $purchaseOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-T5B-'.random_int(100000, 999999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        $lineNumber = 1;
        $subtotal = '0.000';

        foreach ($lines as $index => $line) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'sku' => 'T5B-'.$index.'-'.random_int(1000, 9999),
                'name' => 'T5b Part '.$index,
                'type' => ProductType::Part,
                'cost_price' => $line['price'],
                'is_active' => true,
                'is_physical' => true,
            ]);

            $lineTotal = bcmul($this->numeric($line['qty']), $this->numeric($line['price']), 3);
            $subtotal = bcadd($subtotal, $lineTotal, 3);

            DocumentLine::create([
                'document_id' => $purchaseOrder->id,
                'product_id' => $product->id,
                'product_code' => $product->sku,
                'line_number' => $lineNumber++,
                'description' => $product->name,
                'quantity' => $line['qty'],
                'quantity_received' => '0.0000',
                'unit_price' => $line['price'],
                'line_total' => $lineTotal,
                'allocated_costs' => '0.0000',
            ]);
        }

        $purchaseOrder->update(['subtotal' => $subtotal, 'total' => $subtotal]);

        /** @var Document $fresh */
        $fresh = $purchaseOrder->fresh(['lines']);

        return $fresh;
    }
}
