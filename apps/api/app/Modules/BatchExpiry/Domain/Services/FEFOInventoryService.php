<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Services;

use App\Modules\BatchExpiry\Application\DTOs\BatchSuggestionDTO;
use App\Modules\BatchExpiry\Application\DTOs\BatchSuggestionResultDTO;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\DTOs\BatchConsumptionResultDTO;
use App\Modules\BatchExpiry\Domain\DTOs\ConsumedBatchDTO;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Events\BatchStockConsumed;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\Domain\Exceptions\MissingVariantException;
use App\Shared\Domain\QuantityScale;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 🚨 W4-1 — there is deliberately no `DEFAULT_SHELF_LIFE_DAYS` mirror in this
 * class any more. It used to mint a `today + 365` expiry for the DEFAULT lot,
 * and {@see BatchStockService} carried the same constant for the opening path.
 * Both are gone: a lot whose expiry nobody supplied now records
 * `expiry_date IS NULL`.
 *
 * Every ordering below therefore spells the ranking out as
 * `(expiry_date IS NULL) ASC, expiry_date ASC` — undated lots LAST. PostgreSQL
 * sorts NULLs last on a bare ASC, SQLite sorts them FIRST, so the flag column is
 * stated rather than inherited from whichever driver is underneath. Every
 * "is it expired?" predicate admits NULL for the same reason: an undated lot is
 * not expired, and filtering it out would make the whole opening catalogue
 * invisible to FEFO.
 */
class FEFOInventoryService
{
    /**
     * Mirrors {@see BatchStockService::DEFAULT_BATCH_NUMBER}.
     * Duplicated rather than imported because Domain must not depend on
     * Application (deptrac ModuleDomain → ModuleApplication).
     */
    private const string DEFAULT_BATCH_NUMBER = 'DEFAULT';

    public function __construct(
        private readonly ProductVariantLookup $variantLookup,
    ) {}

    /**
     * Get batches to fulfill a quantity, ordered by expiry (soonest first).
     * Implements FEFO (First-Expired-First-Out) logic.
     *
     * Quantities stay in bcmath decimal strings end-to-end (precision contract):
     * a native-float pass reported false shortfalls of ~1e-16 for requests the
     * lots exactly covered (e.g. 1.1 against [0.7, 0.4]).
     *
     * @param  numeric-string  $quantity  Positive quantity to fulfil (decimal string, 4dp).
     * @param  ?string  $variantId  When set, only batches belonging to that variant
     *                              are considered (product_batches.variant_id = $variantId).
     *                              When null, only product-level batches are considered
     *                              (product_batches.variant_id IS NULL).
     *                              This is a READ-ONLY suggestion; atomic consume is handled
     *                              separately (Task 16b).
     */
    public function suggestBatchesForSale(
        string $productId,
        string $locationId,
        string $quantity,
        bool $includeExpired = false,
        ?string $variantId = null,
    ): BatchSuggestionResultDTO {
        $query = BatchStock::query()
            // Explicit projection: the join means a bare `select *` lets
            // product_batches columns clobber same-named batch-stock ones, and a
            // future ->select() elsewhere could silently drop the GENERATED
            // available_quantity column this method reads raw.
            ->select('inventory_batch_stock.*')
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.product_id', $productId)
            ->where('inventory_batch_stock.location_id', $locationId)
            ->where('inventory_batch_stock.available_quantity', '>', 0)
            ->where('product_batches.is_active', true)
            ->where('product_batches.is_recalled', false)
            // FEFO: earliest expiry first, undated lots last (W4-1), then the
            // order the lots were received so a tie is deterministic.
            ->orderByRaw('(product_batches.expiry_date IS NULL) ASC, product_batches.expiry_date ASC, product_batches.created_at ASC');

        // Variant predicate: null → product-level batches only; set → variant batches only.
        if ($variantId === null) {
            $query->whereNull('product_batches.variant_id');
        } else {
            $query->where('product_batches.variant_id', $variantId);
        }

        if (! $includeExpired) {
            // W4-1: an undated lot is not expired, so excluding it here would make
            // the whole opening catalogue invisible to every FEFO consumer the
            // moment we stopped inventing expiries.
            $query->where(function (Builder $q): void {
                $q->whereNull('product_batches.expiry_date')
                    ->orWhere('product_batches.expiry_date', '>=', now()->startOfDay());
            });
        }

        $batchStocks = $query->with('batch')->get();

        /** @var array<int, BatchSuggestionDTO> $suggestions */
        $suggestions = [];

        // Normalize the request to the canonical quantity scale up front, so every
        // emitted quantity carries the same scale regardless of caller input.
        // Round (HALF_UP) rather than bcadd-truncate: the route validator caps
        // input at 4dp, but in-process callers such as
        // StockReservationService::reserveWithFEFO() are not regex-gated, and
        // silently truncating their request would under-fulfil it.
        /** @var numeric-string $remaining */
        $remaining = QuantityScale::round($quantity, QuantityScale::SCALE, QuantityScale::HALF_UP);

        foreach ($batchStocks as $stock) {
            if (bccomp($remaining, '0', QuantityScale::SCALE) <= 0) {
                break;
            }

            $batch = $stock->batch;
            if ($batch === null) {
                continue;
            }

            // Read the GENERATED available_quantity column RAW, bypassing the
            // model: BatchStock::getAvailableQuantityAttribute() returns a float
            // (quantity - reserved_quantity), which would reintroduce exactly the
            // representation error this method exists to remove. getRawOriginal()
            // hands back the untouched DB value instead.
            $rawAvailableValue = $stock->getRawOriginal('available_quantity');
            $rawAvailable = is_scalar($rawAvailableValue) ? trim((string) $rawAvailableValue) : '';

            // Fail loudly rather than silently. Two ways this can bite:
            //  - bcmath coerces "" to 0, so a missing/blank column would make every
            //    lot report zero availability and the endpoint would report a total
            //    shortfall with no error at all;
            //  - bcmath throws a bare ValueError on scientific notation, which
            //    SQLite can produce for sub-1e-4 generated decimals (it returns them
            //    as floats). Postgres never emits it for decimal(15,4), so this is a
            //    test-environment guard — but a named error beats a raw ValueError.
            // Defence in depth, deliberately UNTESTED: available_quantity is a
            // GENERATED column (so it cannot be blanked by any UPDATE) and the
            // explicit ->select() above guarantees it is projected. There is no
            // reachable trigger today; this exists so a future ->select() change
            // fails loudly instead of silently zeroing every lot's availability.
            if (preg_match('/^-?\d+(\.\d+)?$/', $rawAvailable) !== 1) {
                throw new \RuntimeException(sprintf(
                    'inventory_batch_stock.available_quantity for batch %d is not a plain decimal ("%s") — '
                    .'refusing to compute FEFO suggestions from an unknown availability.',
                    $batch->id,
                    $rawAvailable,
                ));
            }

            // FLOOR, not HALF_UP, on the availability side: this is stock ON HAND,
            // and rounding it up would suggest a draw larger than the lot holds.
            // inventory_batch_stock.available_quantity is decimal(15,4), so today
            // this is a no-op either way — FLOOR just states the safe intent.
            /** @var numeric-string $available */
            $available = QuantityScale::round($rawAvailable, QuantityScale::SCALE, QuantityScale::FLOOR);

            /** @var numeric-string $takeQuantity */
            $takeQuantity = bccomp($available, $remaining, QuantityScale::SCALE) < 0
                ? $available
                : $remaining;

            $suggestions[] = new BatchSuggestionDTO(
                batch: $batch,
                quantity: $takeQuantity,
                expiryDate: $batch->expiry_date,
                expiryStatus: $batch->expiryStatus()
            );

            $remaining = bcsub($remaining, $takeQuantity, QuantityScale::SCALE);
        }

        // A take never exceeds the remainder, so $remaining cannot go negative;
        // clamp defensively and emit a canonical scale-4 string either way.
        /** @var numeric-string $shortfall */
        $shortfall = bccomp($remaining, '0', QuantityScale::SCALE) > 0
            ? $remaining
            : bcadd('0', '0', QuantityScale::SCALE);

        return new BatchSuggestionResultDTO(
            suggestions: $suggestions,
            fullyFulfilled: bccomp($shortfall, '0', QuantityScale::SCALE) <= 0,
            shortfall: $shortfall,
        );
    }

    /**
     * Atomically consume batch stock for a quantity, FEFO order, with row locks.
     *
     * Unlike {@see suggestBatchesForSale()} (read-only, lock-free), this method
     * runs inside a transaction and locks each candidate inventory_batch_stock
     * row with `FOR UPDATE ... SKIP LOCKED`. Two concurrent consumers therefore
     * never draw from the same locked row — the second skips it and either finds
     * other stock or reports a shortfall. This closes the concurrency hole where
     * two cashiers received identical suggestions and a sale could shortfall yet
     * still commit.
     *
     * Each consumed batch decrements `quantity` (the GENERATED `available_quantity`
     * recomputes itself) and writes one inventory_batch_movements row keyed to the
     * supplied stock_movements id.
     *
     * @param  string  $tenantId  Owning tenant.
     * @param  string  $productId  Product whose batches are consumed.
     * @param  string  $locationId  Location to consume from.
     * @param  numeric-string  $quantity  Positive quantity to consume (decimal string, 4dp).
     * @param  string  $movementId  FK → stock_movements.id. The caller MUST create
     *                              the stock_movements row first and pass its id.
     * @param  ?string  $variantId  When set, only that variant's batches are consumed;
     *                              when null, only product-level batches (variant_id IS NULL).
     * @param  bool  $strictFulfillment  When true (default), a shortfall throws
     *                                   InsufficientBatchStockException and rolls back the
     *                                   whole pass. When false, partial consumption commits
     *                                   and the remaining quantity is returned as the shortfall.
     *
     * @throws InsufficientBatchStockException When strict and the quantity cannot be fully met.
     */
    public function consumeBatchesAtomically(
        string $tenantId,
        string $productId,
        string $locationId,
        string $quantity,
        string $movementId,
        ?string $variantId = null,
        bool $strictFulfillment = true,
    ): BatchConsumptionResultDTO {
        return DB::transaction(function () use (
            $tenantId,
            $productId,
            $locationId,
            $quantity,
            $movementId,
            $variantId,
            $strictFulfillment,
        ): BatchConsumptionResultDTO {
            $variantPredicate = $variantId !== null ? 'b.variant_id = ?' : 'b.variant_id IS NULL';

            $bindings = [$productId];
            if ($variantId !== null) {
                $bindings[] = $variantId;
            }
            $bindings = array_merge($bindings, [$locationId, now()->toDateString()]);

            // FOR UPDATE OF ibs SKIP LOCKED: lock only the batch-stock rows we are
            // about to draw down, and skip any row another transaction already holds.
            $rows = DB::select("
                SELECT ibs.id AS batch_stock_id, ibs.batch_id, ibs.quantity AS stored_quantity,
                       ibs.available_quantity, b.expiry_date
                FROM inventory_batch_stock AS ibs
                JOIN product_batches AS b ON ibs.batch_id = b.id
                WHERE b.product_id = ? AND {$variantPredicate}
                  AND ibs.location_id = ? AND ibs.available_quantity > 0
                  AND b.is_active = TRUE AND b.is_recalled = FALSE
                  AND (b.expiry_date IS NULL OR b.expiry_date >= ?)
                ORDER BY (b.expiry_date IS NULL) ASC, b.expiry_date ASC, b.created_at ASC
                FOR UPDATE OF ibs SKIP LOCKED
            ", $bindings);

            /** @var numeric-string $remaining */
            $remaining = $quantity;
            /** @var array<int, ConsumedBatchDTO> $consumed */
            $consumed = [];

            foreach ($rows as $row) {
                if (bccomp($remaining, '0', 4) <= 0) { // precision-ok: batch quantity is decimal(15,4), canonical scale 4
                    break;
                }

                /** @var numeric-string $available */
                $available = (string) $row->available_quantity;

                // Normalize to a 4dp decimal string so every consumed quantity and
                // movement row carries a consistent scale, regardless of caller input.
                // precision-ok: batch quantity is decimal(15,4), canonical scale 4
                $takeSource = bccomp($available, $remaining, 4) < 0 ? $available : $remaining;
                /** @var numeric-string $take */
                $take = bcadd($takeSource, '0', 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

                // Decrement quantity at full decimal precision; the GENERATED
                // available_quantity recomputes. We hold a FOR UPDATE lock on this
                // exact row, so the read-modify-write is safe from concurrent writers.
                // bcsub avoids the float cast that decrement() would force.
                /** @var numeric-string $storedQuantity */
                $storedQuantity = (string) $row->stored_quantity;

                /** @var numeric-string $newQuantity */
                $newQuantity = bcsub($storedQuantity, $take, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

                DB::table('inventory_batch_stock')
                    ->where('id', $row->batch_stock_id)
                    ->update(['quantity' => $newQuantity]);

                // Signed ledger: a consume is an ISSUE, so the movement row stores
                // the NEGATIVE magnitude — matching BatchStockService::issueBatchStock()
                // (bcmul($quantity, '-1', 4)). Receipts are positive, issues negative.
                /** @var numeric-string $movementQuantity */
                $movementQuantity = bcmul($take, '-1', 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

                DB::table('inventory_batch_movements')->insert([
                    'tenant_id' => $tenantId,
                    'batch_id' => (int) $row->batch_id,
                    'movement_id' => $movementId,
                    'quantity' => $movementQuantity,
                    'created_at' => now(),
                ]);

                // The result DTO reports the POSITIVE magnitude consumed; only the
                // ledger ROW carries the negative sign.
                $consumed[] = new ConsumedBatchDTO(
                    batchId: (int) $row->batch_id,
                    batchStockId: (int) $row->batch_stock_id,
                    quantityConsumed: $take,
                    expiryDate: $row->expiry_date === null ? null : Carbon::parse($row->expiry_date),
                );

                $remaining = bcsub($remaining, $take, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4
            }

            // Normalize the shortfall to a consistent 4dp decimal string.
            /** @var numeric-string $shortfall */
            $shortfall = bcadd($remaining, '0', 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

            // precision-ok: batch quantity is decimal(15,4), canonical scale 4
            if ($strictFulfillment && bccomp($shortfall, '0', 4) > 0) {
                throw new InsufficientBatchStockException($shortfall);
            }

            DB::afterCommit(function () use ($productId, $variantId, $locationId, $consumed): void {
                event(new BatchStockConsumed(
                    productId: $productId,
                    variantId: $variantId,
                    locationId: $locationId,
                    consumed: $consumed,
                ));
            });

            return new BatchConsumptionResultDTO(
                consumed: $consumed,
                shortfall: $shortfall,
            );
        });
    }

    /**
     * Put returned units back on the lot(s) they LEFT — the inbound mirror of
     * {@see self::consumeBatchesAtomically()} (campaign gate r3 R3-1).
     *
     * Until this existed, the document return path credited `stock_levels` and no
     * lot, so a sale followed by a return left `Σ lots` BELOW the aggregate: every
     * returned unit permanently shaved the lot ledger, deliveries were eventually
     * refused with a 422 on stock the stock screen showed on hand, and
     * `untrackedRemainderAt()` reported a phantom remainder that the sibling seams
     * would re-mint as a `DEFAULT` lot — re-labelling short-dated returned goods as
     * untracked stock ranked `today + 365` by FEFO. In a parapharmacy that is a
     * product-safety defect, not just a ledger one.
     *
     * Origin resolution, in order (gate r5 R5-1 — PROVENANCE FIRST):
     *
     *  1. **Per-line provenance**, when the caller has it. `$preferredLots` maps
     *     batch id → quantity that THIS line is known to have taken from that lot.
     *     The POS channel passes the sale line's own
     *     `pos_receipt_line_batch_allocations` rows; the document channel passes
     *     the issue legs of the originating delivery. Each hint is still capped by
     *     the lot's outstanding outbound (below), so a hint can never over-credit.
     *
     *     This exists because the heuristic in step 2 cannot tell WHICH sale
     *     shipped a leg — it aggregates over the whole (product, location, variant)
     *     tuple — so it is only right when the returned sale happened to be the
     *     last one out. Gate r5 measured the consequence: two dated lots, receipt 1
     *     ships the short-dated lot A and receipt 2 ships the long-dated lot B;
     *     returning receipt 1 credited **B**, leaving A at zero with 5 of its units
     *     physically back on the shelf. Two lot balances wrong at rest,
     *     `BatchTraceabilityController`'s recall trail broken in both directions,
     *     and short-dated units re-labelled long-dated so FEFO ships them LAST —
     *     the same product-safety failure mode as the `DEFAULT@today+365` phantom
     *     this lane exists to kill, one notch less visible. `Σ lots ==
     *     stock_levels` reconciles either way, which is exactly why nothing else
     *     catches it.
     *
     *  2. **Shipment history** (the heuristic), for whatever provenance does not
     *     cover — pre-lane sales that have no snapshot, and unattributed returns.
     *     Lots whose OUTSTANDING OUTBOUND is positive, most-recently-SHIPPED first.
     *     See {@see self::outstandingShippedLots()} for why the ceiling is
     *     outstanding-outbound and not the lot's total net (gate r4 R4-1).
     *
     *  3. **DEFAULT lot.** Anything still left — goods the ERP never saw leave a
     *     lot — lands there. `Σ lots` reconciles with `stock_levels` and the units
     *     are VISIBLY untracked rather than silently missing.
     *
     * Every credit writes an `inventory_batch_movements` row keyed to the caller's
     * inbound movement (document-per-action), positive by the signed-ledger
     * convention this class already uses for consumption.
     *
     * 🚨 **W4R-2 gate r1 F-2 — a provenance hint may name the `DEFAULT` lot, and
     * until this fix that hint was silently DROPPED.** Step 1 caps each hint by
     * the lot's outstanding outbound, read from
     * {@see self::outstandingShippedLots()} — which excluded `DEFAULT` lots by
     * construction. A hint naming a `DEFAULT` lot therefore found no ceiling and
     * `continue`d, and the credit fell through to the step-2 heuristic: exactly
     * the "credits whichever lot shipped last" behaviour the provenance arm was
     * built to prevent. On the first tenant that is the DOMINANT shape, not an
     * edge case — every batch-tracked product carries a `DEFAULT` lot, and on
     * every product that also has a dated lot the `DEFAULT` one has the EARLIER
     * expiry, so FEFO draws it first on essentially every POS sale. Measured:
     * `DEFAULT` at +10 days and `LOT-DATED` at +200 days, receipt 1 sells 4 from
     * `DEFAULT`, receipt 2 drains it and bites `LOT-DATED`; refunding receipt 1
     * credited 2 of its 4 units to `LOT-DATED`, a lot that never shipped them.
     * `Σ lots == stock_levels` still reconciled, which is why nothing else
     * caught it. The ceiling map is now built WITH `DEFAULT` lots for the
     * provenance pass and the heuristic still skips them (step 2's comment says
     * why), so `DEFAULT` is reachable by EVIDENCE but never by GUESS.
     *
     * 🚨 **W4R-2 gate r1 F-3 — `$mintDefaultLotForUnattributed`.** Step 3 exists
     * so `Σ lots` reconciles with `stock_levels` after a return the ERP cannot
     * attribute. That is right for a channel whose sales always wrote lot legs.
     * It is WRONG for a return of a sale that never wrote one: there the
     * aggregate was decremented and the lot ledger was not, so `Σ lots` already
     * SITS ABOVE the aggregate by exactly that quantity, and restoring the
     * aggregate alone brings the two back into line — drift returns to zero all
     * by itself. Minting a `DEFAULT` lot at `today + 365` on top of that turns a
     * self-healing case into a PERMANENT overstatement, and re-mints the very
     * phantom `inventory:repair-phantom-default-batches` (W2-7) exists to
     * remove, re-labelling short-dated goods as untracked stock that FEFO will
     * ship LAST. Pass `false` and the unattributed remainder simply stays in the
     * untracked remainder (`BatchStockService::untrackedRemainderAt()`), which is
     * W2-7's own semantics: a `DEFAULT` lot backs the untracked remainder, and
     * minting one is a deliberate, evidence-gated act — never a side effect of a
     * refund. Defaults to `true` so the document channel
     * (`Document\Domain\Services\ReturnNoteService`, which omits the flag) is
     * bit-for-bit unchanged.
     *
     * @param  numeric-string  $quantity  Positive quantity being returned.
     * @param  string  $movementId  FK → stock_movements.id (the caller's inbound movement).
     * @param  ?int  $defaultShelfLifeDays  Product default expiry period, for the fallback lot.
     * @param  array<int, numeric-string>  $preferredLots  batch id => quantity this line is
     *                                                     KNOWN to have taken from that lot.
     * @param  bool  $mintDefaultLotForUnattributed  When false, a remainder no lot can be
     *                                               evidenced for is LEFT in the untracked
     *                                               remainder instead of minting a DEFAULT lot.
     * @return numeric-string The quantity that could NOT be attributed to any lot. Zero on
     *                        every fully-attributed return, and (when minting is on) always
     *                        zero because step 3 absorbs it.
     */
    public function restoreBatchesForReturn(
        string $tenantId,
        string $companyId,
        string $productId,
        string $locationId,
        string $quantity,
        string $movementId,
        ?int $defaultShelfLifeDays = null,
        ?string $variantId = null,
        array $preferredLots = [],
        bool $mintDefaultLotForUnattributed = true,
    ): string {
        /** @var numeric-string $remaining */
        $remaining = QuantityScale::round($quantity, QuantityScale::SCALE, QuantityScale::HALF_UP);

        if (bccomp($remaining, '0', QuantityScale::SCALE) <= 0) {
            return QuantityScale::round('0', QuantityScale::SCALE, QuantityScale::FLOOR);
        }

        // ONE ceiling map, built WITH the DEFAULT lots (F-2). The heuristic pass
        // filters them back out; the provenance pass does not.
        $outstanding = $this->outstandingShippedLots($companyId, $productId, $locationId, $variantId, includeDefaultLots: true);
        $defaultLotIds = $this->defaultLotIds($productId, $variantId);

        // 1. Provenance first, each hint capped by the lot's outstanding outbound
        //    so a stale or over-stated hint can never over-credit a lot.
        foreach ($preferredLots as $batchId => $hinted) {
            if (bccomp($remaining, '0', QuantityScale::SCALE) <= 0) {
                break;
            }

            $ceiling = $outstanding[$batchId] ?? null;

            if ($ceiling === null) {
                continue;
            }

            /** @var numeric-string $credit */
            $credit = $hinted;
            foreach ([$ceiling, $remaining] as $cap) {
                if (bccomp($cap, $credit, QuantityScale::SCALE) < 0) {
                    $credit = $cap;
                }
            }

            if (bccomp($credit, '0', QuantityScale::SCALE) <= 0) {
                continue;
            }

            $this->creditLot($tenantId, $batchId, $locationId, $credit, $movementId);

            $remaining = bcsub($remaining, $credit, QuantityScale::SCALE);
            $outstanding[$batchId] = bcsub($ceiling, $credit, QuantityScale::SCALE);
        }

        // 2. Heuristic for whatever provenance did not cover.
        //
        // DEFAULT lots stay OUT of this pass (F-2 kept the exclusion here on
        // purpose): the heuristic is a GUESS ordered by "most recently shipped",
        // and letting it guess the DEFAULT lot would pre-empt step 3, whose
        // whole job is to decide deliberately whether unattributed goods become
        // untracked remainder. Step 1 above may still credit a DEFAULT lot,
        // because there the hint IS evidence.
        foreach ($outstanding as $batchId => $shipped) {
            if (bccomp($remaining, '0', QuantityScale::SCALE) <= 0) {
                break;
            }

            if (isset($defaultLotIds[$batchId])) {
                continue;
            }

            /** @var numeric-string $credit */
            $credit = bccomp($shipped, $remaining, QuantityScale::SCALE) < 0 ? $shipped : $remaining;

            $this->creditLot($tenantId, $batchId, $locationId, $credit, $movementId);

            $remaining = bcsub($remaining, $credit, QuantityScale::SCALE);
        }

        if (bccomp($remaining, '0', QuantityScale::SCALE) <= 0) {
            return QuantityScale::round('0', QuantityScale::SCALE, QuantityScale::FLOOR);
        }

        // 3. Unattributed remainder — goods the ERP never saw leave a lot.
        if (! $mintDefaultLotForUnattributed) {
            /** @var numeric-string $remaining */
            return $remaining;
        }

        $this->creditLot(
            $tenantId,
            $this->defaultBatchId($tenantId, $companyId, $productId, $defaultShelfLifeDays, $variantId),
            $locationId,
            $remaining,
            $movementId,
        );

        return QuantityScale::round('0', QuantityScale::SCALE, QuantityScale::FLOOR);
    }

    /**
     * The `DEFAULT` lot ids for this product grain, as a set keyed by batch id.
     *
     * Read as its own tiny query rather than carried out of
     * {@see self::outstandingShippedLots()} so that method keeps returning the
     * flat `batch id => outstanding` map every caller and test already expects.
     * There is at most one `DEFAULT` lot per (product, variant) by construction
     * ({@see BatchStockService}
     * looks one up before minting), so this is a single-row lookup in practice.
     *
     * @return array<int, true>
     */
    private function defaultLotIds(string $productId, ?string $variantId): array
    {
        $ids = DB::table('product_batches')
            ->where('product_id', $productId)
            ->where('batch_number', self::DEFAULT_BATCH_NUMBER)
            ->when(
                $variantId === null,
                fn ($query) => $query->whereNull('variant_id'),
                fn ($query) => $query->where('variant_id', $variantId),
            )
            ->pluck('id');

        $set = [];

        foreach ($ids as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }

    /**
     * Lots this product+location has shipped and NOT yet taken back, keyed by
     * batch id, most-recently-SHIPPED first. The value is the outstanding
     * OUTBOUND quantity as a positive decimal string, and it is the ceiling on
     * what a return may credit back to that lot.
     *
     * 🚨 Gate r4 R4-1 — this used to net EVERY leg of the lot and keep only lots
     * whose net was negative. But the inbound half of the ledger is not empty: a
     * lot that arrived through a goods receipt carries a POSITIVE leg for its
     * whole received quantity (`GoodsReceiptService` →
     * `BatchStockService::receiveBatchStock()` → `recordBatchMovement()`), and a
     * lot cannot ship more than it received — so `SUM(all legs) < 0` could NEVER
     * be true for a ledger-recorded lot. Every real lot was permanently invisible
     * to the restore arm and every customer return minted a fresh `DEFAULT` lot
     * ranked `today + 365`: the exact phantom this lane exists to eliminate,
     * re-minted on every return, with short-dated goods re-labelled untracked and
     * the recall trail pointing at the wrong lot. On the wave-4 first tenant every
     * real dated lot has exactly one leg and it is positive, so this fired on day
     * one. The lane's own tests passed only because their fixture seeded lots with
     * no ledger legs at all.
     *
     * The ceiling is therefore OUTSTANDING OUTBOUND, not the net of everything:
     *
     *     outstanding(lot) = −[ Σ(legs < 0)                       // shipped
     *                         + Σ(legs > 0 that are return credits) ]  // already given back
     *
     * The positive side is restricted to legs whose movement is itself a customer
     * or POS return, which is what preserves the anti-double-credit property: a
     * second return with no matching shipment finds the lot's outstanding already
     * consumed and falls through to the `DEFAULT` arm.
     *
     * `CASE WHEN` rather than `FILTER (WHERE …)`: the aggregate filter clause is
     * not portable to every driver this suite runs on, and the inbound arm is
     * deliberately driver-agnostic (gate r4 R4-5).
     *
     * @return array<int, numeric-string>
     */
    private function outstandingShippedLots(
        string $companyId,
        string $productId,
        string $locationId,
        ?string $variantId,
        bool $includeDefaultLots = false,
    ): array {
        $returnReasons = [
            MovementReason::CustomerReturn->value,
            MovementReason::POSReturn->value,
        ];

        $placeholders = implode(', ', array_fill(0, count($returnReasons), '?'));

        // Outbound magnitude still outstanding: everything the lot shipped, minus
        // everything a return has already put back.
        $outstandingExpr = '-SUM(CASE WHEN ibm.quantity < 0 THEN ibm.quantity '
            ."WHEN sm.reason IN ({$placeholders}) THEN ibm.quantity ELSE 0 END)";

        // r4 R4-8: "most recently shipped" is measured over OUTBOUND legs only —
        // a lot that RECEIVED stock yesterday must not sort ahead of one that
        // SHIPPED today.
        $lastShippedExpr = 'MAX(CASE WHEN ibm.quantity < 0 THEN sm.created_at END)';

        // r5 R5-9: one sale that spilled across several lots writes all its legs
        // under ONE movement_id, so those lots share a created_at and the ordering
        // above ties. Break the tie deterministically instead of letting the driver
        // decide — visible on a PARTIAL return, where only some tied lots are
        // credited.

        $query = DB::table('inventory_batch_movements as ibm')
            ->join('stock_movements as sm', 'sm.id', '=', 'ibm.movement_id')
            ->join('product_batches as pb', 'pb.id', '=', 'ibm.batch_id')
            // r4 R4-9: scoped like every other half of this comparison. Redundant
            // under database-per-tenant, load-bearing in single-schema mode.
            ->where('sm.company_id', $companyId)
            ->where('sm.product_id', $productId)
            ->where('sm.location_id', $locationId);

        // W4R-2 gate r1 F-2: the DEFAULT lot is excluded from the HEURISTIC pass
        // (a guess must never pre-empt step 3) but MUST be visible to the
        // PROVENANCE pass, which is reading a hint the sale itself recorded.
        if (! $includeDefaultLots) {
            $query->where('pb.batch_number', '!=', self::DEFAULT_BATCH_NUMBER);
        }

        if ($variantId === null) {
            $query->whereNull('pb.variant_id');
        } else {
            $query->where('pb.variant_id', $variantId);
        }

        $rows = $query
            ->groupBy('ibm.batch_id')
            ->havingRaw("{$outstandingExpr} > 0", $returnReasons)
            ->orderByRaw("{$lastShippedExpr} DESC, ibm.batch_id DESC")
            ->select([
                'ibm.batch_id as batch_id',
                DB::raw("{$outstandingExpr} as outstanding"),
            ])
            ->addBinding($returnReasons, 'select')
            ->get();

        $outstanding = [];

        foreach ($rows as $row) {
            // FLOOR: this is a CEILING on what may be credited back, never round up.
            $outstanding[(int) $row->batch_id] = QuantityScale::round(
                trim((string) $row->outstanding),
                QuantityScale::SCALE,
                QuantityScale::FLOOR,
            );
        }

        return $outstanding;
    }

    /**
     * Credit a lot and write its positive ledger leg. Mirrors the decrement half
     * of {@see self::consumeBatchesAtomically()}, including the row lock.
     *
     * @param  numeric-string  $quantity
     */
    private function creditLot(
        string $tenantId,
        int $batchId,
        string $locationId,
        string $quantity,
        string $movementId,
    ): void {
        /** @var object{id: int, quantity: mixed}|null $row */
        $row = DB::table('inventory_batch_stock')
            ->where('batch_id', $batchId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            DB::table('inventory_batch_stock')->insert([
                'tenant_id' => $tenantId,
                'batch_id' => $batchId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'reserved_quantity' => '0.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $current = QuantityScale::round(
                trim((string) $row->quantity),
                QuantityScale::SCALE,
                QuantityScale::FLOOR,
            );

            DB::table('inventory_batch_stock')
                ->where('id', $row->id)
                ->update([
                    'quantity' => bcadd($current, $quantity, QuantityScale::SCALE),
                    'updated_at' => now(),
                ]);
        }

        // The justifying leg, written through the MODEL rather than the query
        // builder: `movement_id` is a NOT-NULL FK to `stock_movements`, and the
        // document-per-action scanner recognises the pairing only in this shape
        // (`BatchMovement::create([... 'movement_id' => ...])`), which is what
        // makes the two batch-stock writes above LINKED rather than violations.
        BatchMovement::create([
            'tenant_id' => $tenantId,
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => $quantity,
        ]);
    }

    /**
     * The product's `DEFAULT` lot id, minting the lot row if it does not exist.
     * Deliberately does NOT create batch stock — {@see self::creditLot()} does that
     * with the ledger leg attached.
     */
    private function defaultBatchId(
        string $tenantId,
        string $companyId,
        string $productId,
        ?int $defaultShelfLifeDays,
        ?string $variantId,
    ): int {
        $existing = DB::table('product_batches')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('batch_number', self::DEFAULT_BATCH_NUMBER)
            ->when($variantId === null,
                fn ($q) => $q->whereNull('variant_id'),
                fn ($q) => $q->where('variant_id', $variantId),
            )
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        // 🚨 Gate r4 R4-4 — the same guard `BatchStockService::findOrCreateBatch()`
        // applies: a variant-bearing product must NEVER receive a product-level
        // batch. This mint is a raw insert (the model layer would pull Application
        // into Domain), so the guard has to be restated here rather than inherited.
        // Refusing is the right answer: silently minting a forbidden product-level
        // DEFAULT lot would hide the real problem, which is that
        // `WeightedAverageCostService::recordReturn()` has no `variantId` parameter
        // and credits the aggregate product-level — the variant asymmetry above the
        // lot layer is pre-existing and is a named residual.
        if ($variantId === null) {
            $activeVariants = $this->variantLookup->listForProduct($productId, true);

            if ($activeVariants->isNotEmpty()) {
                throw MissingVariantException::forProduct($productId);
            }
        }

        $today = CarbonImmutable::now()->toDateString();

        return (int) DB::table('product_batches')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'batch_number' => self::DEFAULT_BATCH_NUMBER,
            'manufacturing_date' => $today,
            // W4-1: only a CONFIGURED shelf life may date this lot. With none,
            // the returned units genuinely have no known expiry — record that,
            // and let FEFO rank the lot last, rather than minting a `today + 365`
            // date that would jump the whole queue.
            'expiry_date' => $defaultShelfLifeDays === null
                ? null
                : CarbonImmutable::now()->addDays($defaultShelfLifeDays)->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Get products expiring within threshold.
     *
     * @return Collection<int, Batch>
     */
    public function getExpiringProducts(
        string $companyId,
        int $daysThreshold = 30,
        ?string $locationId = null
    ): Collection {
        $query = Batch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->whereBetween('expiry_date', [
                now()->startOfDay(),
                now()->addDays($daysThreshold)->endOfDay(),
            ])
            ->whereHas('batchStock', function (Builder $q) use ($locationId): void {
                $q->whereRaw('available_quantity > 0');
                if ($locationId) {
                    $q->whereRaw('location_id = ?', [$locationId]);
                }
            })
            ->with(['product.unitOfMeasure', 'batchStock'])
            ->orderBy('expiry_date', 'asc');

        return $query->get();
    }

    /**
     * Get all expired batches with remaining available (un-reserved) stock.
     *
     * Only lots where `available_quantity > 0` are returned — lots whose
     * entire on-hand quantity is reserved are excluded, as there is nothing
     * free to write off.  Both `quantity` (on-hand total) and
     * `reserved_quantity` are present on each loaded BatchStock row so the
     * caller can display the full picture to the operator.
     *
     * @param  string|list<string>|null  $locationId  A legacy scalar location id,
     *                                                a resolved location-id set, or null for an unscoped service call.
     * @return Collection<int, Batch>
     */
    public function getExpiredBatchesWithStock(string $companyId, string|array|null $locationId = null): Collection
    {
        $locationIds = is_array($locationId) ? $locationId : null;

        $query = Batch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->where('expiry_date', '<', now()->startOfDay())
            ->whereHas('batchStock', function (Builder $q) use ($locationId, $locationIds): void {
                $q->whereRaw('available_quantity > 0');
                if (is_array($locationIds)) {
                    if ($locationIds === []) {
                        $q->whereRaw('1 = 0');
                    } else {
                        $q->whereIn('location_id', $locationIds);
                    }
                } elseif ($locationId !== null) {
                    $q->whereRaw('location_id = ?', [$locationId]);
                }
            })
            ->with(['product.unitOfMeasure', 'batchStock' => function (Relation $q) use ($locationId, $locationIds): void {
                if (is_array($locationIds)) {
                    if ($locationIds === []) {
                        $q->whereRaw('1 = 0');
                    } else {
                        $q->whereIn('location_id', $locationIds);
                    }
                } elseif ($locationId !== null) {
                    $q->whereRaw('location_id = ?', [$locationId]);
                }
            }])
            ->orderBy('expiry_date', 'asc');

        return $query->get();
    }

    /**
     * Get batch stock allocation for a specific batch.
     *
     * @return Collection<int, BatchStock>
     */
    public function getBatchStockByLocation(string $batchId): Collection
    {
        return BatchStock::query()
            ->where('batch_id', $batchId)
            ->with('location')
            ->get();
    }

    /**
     * Check if a product requires batch tracking.
     */
    public function productRequiresBatchTracking(string $productId): bool
    {
        return (bool) (Product::query()
            ->where('id', $productId)
            ->value('requires_batch_tracking') ?? false);
    }

    /**
     * Get total available quantity for a product across all batches.
     */
    public function getTotalAvailableQuantity(string $productId, ?string $locationId = null): float
    {
        $query = BatchStock::query()
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.product_id', $productId)
            ->where('product_batches.is_active', true)
            ->where('product_batches.is_recalled', false)
            // W4-1: an undated lot is sellable stock, not expired stock.
            ->where(function (Builder $q): void {
                $q->whereNull('product_batches.expiry_date')
                    ->orWhere('product_batches.expiry_date', '>=', now()->startOfDay());
            });

        if ($locationId) {
            $query->where('inventory_batch_stock.location_id', $locationId);
        }

        return (float) $query->sum('inventory_batch_stock.available_quantity');
    }
}
