<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
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

    public function up(): void
    {
        if (! Schema::hasTable('product_batches') || ! Schema::hasTable('products')) {
            return;
        }

        // Self-guard: refuse to write NULLs into a column that is still NOT NULL.
        // Running out of order would otherwise abort the whole tenant migrate with
        // a raw SQLSTATE instead of a skip.
        if (! $this->expiryDateIsNullable()) {
            Log::warning('[W4-1] product_batches.expiry_date is still NOT NULL — skipping the invented-expiry backfill.');

            return;
        }

        $ids = $this->inventedLotIds();
        $census = count($ids);

        Log::info('[W4-1] invented DEFAULT-lot expiry census', ['lots' => $census]);

        if ($census === 0) {
            // Silent on the no-op path: this migration runs on EVERY tenant
            // database and on every RefreshDatabase in the test suite.
            return;
        }

        echo sprintf('[W4-1] invented DEFAULT-lot expiries found: %d%s', $census, PHP_EOL);

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('product_batches')
                ->whereIn('id', $chunk)
                ->update(['expiry_date' => null, 'is_expired' => false, 'updated_at' => now()]);
        }

        $remaining = count($this->inventedLotIds());

        Log::info('[W4-1] invented DEFAULT-lot expiries nulled', [
            'nulled' => $census,
            'remaining' => $remaining,
        ]);
        echo sprintf('[W4-1] invented DEFAULT-lot expiries nulled: %d (remaining: %d)%s', $census, $remaining, PHP_EOL);
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
     * @return list<int>
     */
    private function inventedLotIds(): array
    {
        $span = self::INVENTED_SHELF_LIFE_DAYS;

        $expiryMatchesFallback = DB::getDriverName() === 'pgsql'
            ? "product_batches.expiry_date = product_batches.manufacturing_date + INTERVAL '{$span} days'"
            : "date(product_batches.expiry_date) = date(product_batches.manufacturing_date, '+{$span} days')";

        /** @var list<int> $ids */
        $ids = DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('product_batches.batch_number', 'DEFAULT')
            ->whereNull('products.default_shelf_life_days')
            ->whereNotNull('product_batches.expiry_date')
            ->whereNotNull('product_batches.manufacturing_date')
            ->whereRaw($expiryMatchesFallback)
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
            $row = DB::selectOne(
                "SELECT is_nullable FROM information_schema.columns
                 WHERE table_name = 'product_batches' AND column_name = 'expiry_date'"
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
