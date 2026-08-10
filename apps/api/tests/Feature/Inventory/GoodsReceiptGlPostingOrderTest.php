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
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
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

    public function test_the_standalone_receipt_path_also_defers_its_gl_posting(): void
    {
        // The fail-closed site is LIVE: StandaloneReceiptService.php:134 passes
        // `true` POSITIONALLY (plan §0q.5). It bypasses the event entirely and
        // calls createGoodsReceiptGrIrEntry directly, so it is a SECOND in-loop
        // advisory acquisition and must be buffered by the same edit.
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
            // `pg_advisory_xact_lock(bigint)` stores the key split across
            // pg_locks.classid (high 32 bits) and .objid (low 32 bits); the GL
            // key is `hashtextextended(company_id, 0)`
            // (`GeneralLedgerService.php:4610`, `:3257`).
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
                [$companyId, $companyId, $receiptPid],
            );

            $observations[] = [
                'advisory_held' => (int) $row->advisory > 0,
                'locks_visible' => (int) $row->total > 0,
            ];
        });

        try {
            $this->receive($purchaseOrder, $failClosedGrir);
        } finally {
            StockMovement::flushEventListeners();
            DB::purge(self::PROBE_CONNECTION);
        }

        return $observations;
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
