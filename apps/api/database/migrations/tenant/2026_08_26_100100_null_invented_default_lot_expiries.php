<?php

declare(strict_types=1);

use App\Shared\Database\MigrationOutput;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign W4-1 — retire the invented `asOfDate + 365` expiries already in the
 * ledger, so FEFO stops ranking live stock on a date nobody supplied.
 *
 * ## The fiction, and how it is recognised
 *
 * Three paths used to mint a `DEFAULT` lot for stock whose expiry nobody gave —
 * `BatchStockService::ensureDefaultBatch()` (opening balances, the one that
 * matters: on day one ALL stock is an opening), `FEFOInventoryService::
 * defaultBatchId()` (return restore) and `StockAdjustmentService::
 * receiveIntoDefaultBatchByDelta()`. All three wrote the SAME two columns from
 * the same instant:
 *
 *     manufacturing_date = asOfDate
 *     expiry_date        = asOfDate + (product.default_shelf_life_days ?? 365)
 *
 * There is no provenance flag on `product_batches` — none was ever written — so
 * the predicate is the fingerprint those writes left, and it is a THREE-way
 * coincidence, not a guess:
 *
 *   1. `batch_number = 'DEFAULT'`         — only the auto-minted lot is at stake;
 *                                            a real, operator-supplied lot number
 *                                            keeps whatever expiry came with it.
 *   2. `products.default_shelf_life_days IS NULL`
 *                                          — the 365 fallback fired ONLY when the
 *                                            product had no configured shelf life.
 *                                            A product that DOES configure one
 *                                            (even 365) supplied its expiry rule,
 *                                            so that date is not invented.
 *   3. `expiry_date = manufacturing_date + 365 days`
 *                                          — the exact arithmetic of the fallback.
 *                                            Both columns come from the same mint,
 *                                            so this holds for the invented rows
 *                                            and is a coincidence for anything else.
 *
 * A lot an operator has since EDITED fails (3) and is left alone. So is every
 * dated lot from a goods receipt, which carries a real batch_number.
 *
 * ## Properties
 *
 * SELF-GUARDING — no-ops when `product_batches` is absent or its `expiry_date`
 * is still NOT NULL (the companion schema migration has not run), and when the
 * census is 0.
 * IDEMPOTENT — the matched rows end with `expiry_date IS NULL`, which fails
 * predicate (3), so a second run matches nothing.
 * CENSUS — the matched count is logged (and echoed) before and after the write.
 *
 * ## Deliberately NOT touched
 *
 * `pos_receipt_line_batch_allocations.expiry_date` keeps the date it snapshotted
 * at the time of sale. That row is a fiscal record of what the till believed when
 * it sold the unit; rewriting it would falsify a sealed receipt's trail. Only the
 * LIVE lot — the thing FEFO ranks — is corrected.
 */
return new class extends Migration
{
    /** The fallback shelf life this lane retired. */
    private const int INVENTED_SHELF_LIFE_DAYS = 365;

    /** How many residual lots to name individually before truncating the line. */
    private const int RESIDUAL_LIST_LIMIT = 25;

    private ?CarbonImmutable $cutoff = null;

    public function up(): void
    {
        if (! Schema::hasTable('product_batches') || ! Schema::hasTable('products')) {
            return;
        }

        // 🚨 Gate r1 — a SKIP must be as loud as a write. This migration runs on
        // every tenant database of a fleet auto-migrate, and the failure mode of a
        // silent skip is "that tenant's FEFO keeps ranking on fiction, forever,
        // with no signal". A Log::warning alone is not diagnostic on the channel
        // the deploy note tells the operator to watch.
        if (! $this->expiryDateIsNullable()) {
            $message = '[W4-1] SKIPPED: product_batches.expiry_date is still NOT NULL — the companion schema '
                .'migration (2026_08_26_100000) has not run on this tenant. Invented expiries are UNFIXED here.';
            $this->emit($message);

            return;
        }

        $ids = $this->inventedLotIds();
        $census = count($ids);

        // Echoed even at zero, so ABSENCE of a [W4-1] line means the migration did
        // not run at all — rather than being indistinguishable from "ran and found
        // nothing to fix".
        $this->emit(sprintf('[W4-1] invented DEFAULT-lot expiries found: %d', $census));

        if ($census > 0) {
            // MINOR-3 — count the subset the reset RESURRECTS before touching it.
            // Nulling the expiry clears `is_expired`, which is correct (the daily
            // check only ever flips false -> true, so a stuck `true` on a lot with
            // no expiry could never be cleared again, and `is_expired` is read as an
            // independent gate by the write-off path while every FEFO predicate
            // keys on `expiry_date` — preserving it would split the two brains).
            // But those units are exactly the stock an operator should physically
            // verify before it goes back on the shelf, so they are named.
            $resurrected = (int) DB::table('product_batches')
                ->whereIn('id', $ids)
                ->where('is_expired', true)
                ->count();

            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('product_batches')
                    ->whereIn('id', $chunk)
                    // `is_expired` is reset alongside: a nulled lot has no expiry, so
                    // a stale `true` left by the daily check would keep it out of
                    // every FEFO predicate. No lot can hold a PAST invented date
                    // today (product_batches was created 2026-01-05 and +365 has not
                    // elapsed), but on a tenant provisioned later this WILL resurrect
                    // previously-expired DEFAULT lots as sellable stock — correct in
                    // principle, and stated here rather than discovered.
                    ->update(['expiry_date' => null, 'is_expired' => false, 'updated_at' => now()]);
            }

            $remaining = count($this->inventedLotIds());

            $this->emit(sprintf('[W4-1] invented DEFAULT-lot expiries nulled: %d (remaining: %d)', $census, $remaining));

            if ($resurrected > 0) {
                $this->emit(sprintf(
                    '[W4-1] of those, %d were previously flagged expired and are now sellable again — '
                    .'their expiry was the fiction, not a real date, but VERIFY THE PHYSICAL STOCK before it goes '
                    .'back on the shelf.',
                    $resurrected,
                ));
            }
        }

        $this->reportResidual();
    }

    /**
     * NON-MUTATING census of lots this predicate deliberately DECLINED to touch.
     *
     * Predicate clause (2) tests the product's CURRENT `default_shelf_life_days`,
     * but the `?? 365` fallback fired against the value the product held WHEN THE
     * LOT WAS MINTED. So a product that had NULL at import and has since been given
     * a shelf life keeps its invented `mfg + 365` date, FEFO keeps ranking on the
     * fiction, and the main census above reports it as nothing to fix.
     *
     * That drift is invisible from the migrate output, which is the whole problem.
     * These rows are LISTED with their product codes and left alone: the evidence
     * is genuinely ambiguous — the same shape is what a correctly configured
     * 365-day shelf life produces — so an operator decides, not the migration.
     */
    private function reportResidual(): void
    {
        $rows = $this->residualQuery()
            ->orderBy('products.sku')
            ->limit(self::RESIDUAL_LIST_LIMIT + 1)
            ->get(['products.sku as sku', 'products.default_shelf_life_days as shelf_life']);

        if ($rows->isEmpty()) {
            return;
        }

        // MINOR-1 — the NAME LIST is capped, the COUNT is not. Formatting the
        // message with the capped count told an operator with 300 residual lots
        // that there were 25.
        $total = $this->residualQuery()->count();

        $listed = $rows->take(self::RESIDUAL_LIST_LIMIT);
        $codes = $listed
            ->map(static fn (object $row): string => sprintf('%s(shelf_life=%s)', (string) $row->sku, (string) $row->shelf_life))
            ->implode(', ');

        $shown = $total > $listed->count()
            ? sprintf(' (showing %d of %d)', $listed->count(), $total)
            : '';

        $message = sprintf(
            '[W4-1] RESIDUAL (not modified): %d DEFAULT lot(s) still carry manufacturing_date + %d but their product '
            .'now configures a DIFFERENT shelf life, so this migration cannot tell an invented date from a rule-derived '
            .'one. Review by hand%s: %s',
            $total,
            self::INVENTED_SHELF_LIFE_DAYS,
            $shown,
            $codes,
        );

        $this->emit($message);
    }

    /**
     * The residual predicate, in one place so the COUNT and the NAME LIST cannot
     * disagree with each other.
     */
    private function residualQuery(): Builder
    {
        return DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('product_batches.batch_number', 'DEFAULT')
            ->whereNotNull('products.default_shelf_life_days')
            ->where('products.default_shelf_life_days', '!=', self::INVENTED_SHELF_LIFE_DAYS)
            ->whereNotNull('product_batches.expiry_date')
            ->whereNotNull('product_batches.manufacturing_date')
            ->whereRaw($this->expiryMatchesFallbackSql())
            ->where('product_batches.created_at', '<', $this->cutoff());
    }

    /**
     * Write to the migrate output.
     *
     * `tenants:migrate` streams stdout per tenant, and that stream is what the
     * deploy note asks the operator to read.
     */
    private function emit(string $message): void
    {
        MigrationOutput::info($message);
    }

    /**
     * Deliberately irreversible.
     *
     * The invented dates carried no provenance, so after the NULL there is
     * nothing that distinguishes a lot this migration cleared from one minted
     * expiry-less afterwards. Re-deriving `manufacturing_date + 365` on the down
     * leg would therefore re-invent the fiction on BOTH sets — the exact defect
     * W4-1 exists to remove — so the down leg is a documented no-op. Roll the
     * companion schema migration back instead if the column must be NOT NULL
     * again; it refuses while any NULL remains, which is the honest failure.
     */
    public function down(): void
    {
        Log::info('[W4-1] invented-expiry backfill is not reversible by design — down() is a no-op.');
    }

    /**
     * The `expiry_date = manufacturing_date + 365` clause in the current driver's
     * dialect. Shared by the mutating census and the residual census so the two can
     * never drift apart — and so the PG branch is exercised by the same tests.
     */
    private function expiryMatchesFallbackSql(): string
    {
        $span = self::INVENTED_SHELF_LIFE_DAYS;

        return DB::getDriverName() === 'pgsql'
            ? "product_batches.expiry_date = product_batches.manufacturing_date + INTERVAL '{$span} days'"
            : "date(product_batches.expiry_date) = date(product_batches.manufacturing_date, '+{$span} days')";
    }

    /**
     * The instant this run started; every candidate lot must predate it.
     *
     * Captured ONCE so the mutating census, the re-count after the write and the
     * residual census all measure the same window.
     */
    private function cutoff(): CarbonImmutable
    {
        return $this->cutoff ??= CarbonImmutable::now();
    }

    /**
     * @return list<int>
     */
    private function inventedLotIds(): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('product_batches.batch_number', 'DEFAULT')
            ->whereNull('products.default_shelf_life_days')
            ->whereNotNull('product_batches.expiry_date')
            ->whereNotNull('product_batches.manufacturing_date')
            ->whereRaw($this->expiryMatchesFallbackSql())
            // 🚨 PROVENANCE UPPER BOUND (gate r1). The Products import can now
            // SUPPLY a 12-month expiry, and an opening posted the same day
            // (`manufacturing_date = asOfDate`) produces evidence byte-identical to
            // the fabricated date. Only a lot that existed BEFORE this migration ran
            // can possibly carry the fiction, so the window is closed explicitly
            // rather than left resting on "it runs before the column exists in
            // anyone's sheet" — which stops being true the moment this predicate is
            // lifted into a re-runnable repair command, or a restored DB replays it.
            ->where('product_batches.created_at', '<', $this->cutoff())
            ->orderBy('product_batches.id')
            ->pluck('product_batches.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $ids;
    }

    private function expiryDateIsNullable(): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            /** @var object{is_nullable: string}|null $row */
            // Scoped to the CURRENT schema: `selectOne` takes an arbitrary row, so
            // an unfiltered lookup would answer from a same-named table in another
            // schema if the tenant database ever holds one.
            $row = DB::selectOne(
                "SELECT is_nullable FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'product_batches'
                   AND column_name = 'expiry_date'"
            );

            return $row !== null && strtoupper($row->is_nullable) === 'YES';
        }

        foreach (DB::select('PRAGMA table_info(product_batches)') as $column) {
            /** @var object{name: string, notnull: int} $column */
            if ($column->name === 'expiry_date') {
                return (int) $column->notnull === 0;
            }
        }

        return false;
    }
};
