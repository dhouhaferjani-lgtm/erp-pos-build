<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Application\Services\LotLedgerDriftCensus;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use App\Shared\Domain\QuantityScale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repair the phantom `DEFAULT` lots left behind by campaign defect W2-7.
 *
 * Until the fix in
 * `StockReservationService::resolveDefaultBatchIdForImplicitReservation()`,
 * confirming a sales order on a batch-tracked product minted a `DEFAULT` lot
 * seeded with the tuple's ENTIRE `stock_levels.quantity`, without subtracting
 * what the real dated lots already held. Stock received through a goods receipt
 * with explicit lots was therefore counted TWICE in the batch ledger (30 real
 * units → 60), and the reservation was pinned to an expiry-less lot.
 *
 * This command recomputes each `DEFAULT` lot down to its true share —
 * `stock_levels.quantity − Σ(real lot quantities)`, clamped at zero — and
 * re-points the reservations that were parked on it onto real FEFO lots.
 *
 * ## Why a command and not a migration
 *
 * `git push origin dev` auto-deploys and runs `tenants:migrate`, so a migration
 * would rewrite every tenant's batch ledger UNATTENDED, with no census read
 * first and no operator looking at the numbers. The correction also needs a
 * judgement call per reservation (below), and a tenant whose real lots cannot
 * absorb a parked reservation must be REPORTED, not silently mangled. Hence an
 * explicitly scoped, two-phase command: `--dry-run` reads and reports, and
 * nothing is written until an operator passes `--execute`.
 *
 * ## Document-per-action
 *
 * Reducing a lot's quantity is a stock quantity change, so every correction
 * writes its own justifying pair inside one transaction:
 *
 *   - a `stock_movements` row (`adjustment` / `count_correction`,
 *     `reference_type = StockMovementReferenceType::BatchLedgerRepair`) — the
 *     justifying document. Its `quantity` is **0** and
 *     `quantity_before === quantity_after`, because the
 *     AGGREGATE on-hand quantity is not changing and never was wrong: only the
 *     lot ledger was inflated. Recording a non-zero aggregate delta here would
 *     invent a stock movement that never happened and would corrupt WAC.
 *   - an `inventory_batch_movements` row carrying that `movement_id` and the
 *     NEGATIVE lot delta — the batch-level leg of the same correction.
 *
 * Reservation re-pointing moves only `reserved_quantity` / `batch_id`, which is
 * a soft hold: no on-hand quantity moves, so it carries no movement of its own.
 *
 * ## Concurrency (gate r1 finding 13)
 *
 * `--execute` is a MAINTENANCE-WINDOW operation. `reserve()` locks
 * `stock_levels` → `inventory_batch_stock`; `repairLot()` locks the DEFAULT lot's
 * batch-stock row, then the reservations, then the target lot's batch-stock row.
 * No cycle exists against `reserve()` (it locks exactly one batch-stock row), but
 * two concurrent runs of THIS command on one tenant can deadlock on the two
 * batch-stock rows. Run it once, on a quiet system. The excess is also recomputed
 * under the lock and the lot is SKIPPED if it moved, so a concurrent write makes
 * the run report a skip rather than write a stale correction.
 *
 * ## Reservation policy
 *
 * An active reservation parked on a `DEFAULT` lot is re-pointed to the
 * EARLIEST-EXPIRY real lot whose available quantity covers it whole — exactly
 * the predicate the fixed reservation path applies, so repaired data matches
 * what the fixed code would have produced. A reservation no single real lot can
 * cover is LEFT on the `DEFAULT` lot and reported: the lot's quantity is then
 * floored at its remaining `reserved_quantity` so the reservation stays
 * honoured, and the residual excess is reported for operator follow-up.
 */
final class RepairPhantomDefaultBatchesCommand extends TenantScopedCommand
{
    private const int SCALE = 4;

    /**
     * Why a tuple stays drifted after the phantom is removed. Emitted as its own
     * short line so it survives console wrapping and can be asserted verbatim.
     */
    private const string DRIFT_CAUSE_HINT =
        '    cause: an outbound sale without batch_id moved stock_levels, not the lot ledger';

    /**
     * The mirror cause: the lot ledger UNDERSTATES on-hand. Inbound stock that
     * never got a lot leg — the pre-r3 customer-return path is the known one.
     */
    private const string DRIFT_CAUSE_HINT_NEGATIVE =
        '    cause: inbound stock (a return or receipt) credited stock_levels, not the lot ledger';

    protected $signature = 'inventory:repair-phantom-default-batches
        {--tenant= : Tenant UUID to repair (required unless --all-tenants)}
        {--all-tenants : Deliberate fleet-wide run over every reachable tenant}
        {--company= : Narrow the run to one company UUID inside the selected tenant(s)}
        {--dry-run : Report what WOULD change and write nothing}
        {--execute : Apply the corrections}';

    protected $description = 'MAINTENANCE WINDOW: recompute phantom DEFAULT batch quantities (campaign W2-7) and re-point their reservations onto real FEFO lots';

    public function __construct(
        CompanyContext $companyContext,
        private readonly BatchStockService $batchStockService,
        // W4R-2 gate r1 F-4 — the tuple census moved to a shared read-only
        // service so `inventory:lot-drift-census` can run the SAME query
        // without this command's maintenance-window scope guards. The repair
        // arm's own reporting is unchanged; it just no longer owns the query.
        private readonly LotLedgerDriftCensus $driftCensus,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $dryRun = $this->option('dry-run') === true;
        $execute = $this->option('execute') === true;

        if ($dryRun === $execute) {
            $this->error(
                'Pass exactly one of --dry-run (report only) or --execute (apply). '
                .'Refusing to guess which one you meant; nothing was processed.',
            );

            return self::INVALID;
        }

        $companyFilter = $this->stringOption('company');

        // One id for the whole run: every movement this invocation writes carries
        // it as `reference_id`, so the corrections it made are one auditable batch
        // an operator can pull back out of `stock_movements`. Gate r1 finding 11 —
        // it is printed by the FIRST tenant that is actually visited, never before
        // the scope check, so a refused run leaves no run id in the transcript to
        // be mistaken for an audit breadcrumb.
        $repairRunId = Str::uuid()->toString();
        $runIdAnnounced = false;

        $lotsInspected = 0;
        $lotsPhantom = 0;
        $reservationsRepointed = 0;
        $reservationsLeftOnDefault = 0;
        $lotsPartiallyReduced = 0;
        $lotsSkippedStale = 0;
        $tuplesStillDrifted = 0;
        $excessTotal = $this->zero();

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use (
                $execute,
                $companyFilter,
                $repairRunId,
                &$runIdAnnounced,
                &$lotsInspected,
                &$lotsPhantom,
                &$reservationsRepointed,
                &$reservationsLeftOnDefault,
                &$lotsPartiallyReduced,
                &$lotsSkippedStale,
                &$tuplesStillDrifted,
                &$excessTotal,
            ): int {
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when($companyFilter !== null, fn ($query) => $query->where('id', $companyFilter))
                    ->orderBy('id')
                    ->get();

                if (! $runIdAnnounced) {
                    $this->info("Repair run id: {$repairRunId}");
                    $runIdAnnounced = true;
                }

                foreach ($companies as $company) {
                    /** @var array<string, numeric-string> $plannedRemovals */
                    $plannedRemovals = [];

                    foreach ($this->defaultLotRows((string) $company->id) as $row) {
                        $lotsInspected++;

                        $excess = $this->phantomExcessFor($company, $row);

                        if (bccomp($excess, '0', self::SCALE) <= 0) {
                            continue;
                        }

                        $lotsPhantom++;
                        $excessTotal = bcadd($excessTotal, $excess, self::SCALE);

                        $this->line(sprintf(
                            '  %s / %s @ %s — DEFAULT lot %d holds %s, should hold %s (excess %s)',
                            $company->name,
                            $row->product_id,
                            $row->location_id,
                            $row->batch_stock_id,
                            $this->decimal($row->current_quantity),
                            bcsub($this->decimal($row->current_quantity), $excess, self::SCALE),
                            $excess,
                        ));

                        // Gate r3 R3-7 — drift is NOT reported from inside this
                        // loop any more. This loop iterates tuples that HAVE a
                        // DEFAULT lot and are phantom-drifted, so reporting from
                        // here could only ever see drift that is BOTH; a tuple with
                        // a healthy or absent DEFAULT lot — precisely where the
                        // return-side drift lives — was invisible and the run
                        // printed "Tuples still drifted: 0" on a tenant that was
                        // bleeding lot quantity. The census now runs over EVERY
                        // batch-tracked tuple, after this loop.
                        //
                        // What this loop contributes is the planned removal per
                        // tuple, so the dry-run census can predict the post-repair
                        // drift. Clamped later by what `repairLot()` would actually
                        // remove is not possible without the lock, so the prediction
                        // is documented as optimistic on a lot floored by its own
                        // reservations (r3 R3-7 secondary).
                        $plannedRemovals[$this->tupleKey($row)] = bcadd(
                            $plannedRemovals[$this->tupleKey($row)] ?? $this->zero(),
                            $excess,
                            self::SCALE,
                        );

                        if (! $execute) {
                            continue;
                        }

                        [$repointed, $left, $reduced, $stale] = $this->repairLot($company, $row, $excess, $repairRunId);
                        $reservationsRepointed += $repointed;
                        $reservationsLeftOnDefault += $left;

                        if ($stale) {
                            $lotsSkippedStale++;
                            $this->warn(sprintf(
                                '    SKIPPED: the excess changed between the census and the lock (censused %s). '
                                .'Stock moved under the run — re-run the census.',
                                $excess,
                            ));

                            continue;
                        }

                        if (bccomp($reduced, $excess, self::SCALE) < 0) {
                            $lotsPartiallyReduced++;
                            $this->warn(sprintf(
                                '    reduced by %s of %s — the remainder is still held by reservations no single real lot can cover',
                                $reduced,
                                $excess,
                            ));
                        }

                    }

                    // Gate r1 finding 9 + r3 R3-7 — "repaired" is not "reconciled",
                    // and the tuples that are NOT reconciled are mostly ones this
                    // command never touches. One census over every batch-tracked
                    // tuple of the company, in BOTH arms.
                    $tuplesStillDrifted += $this->reportLedgerDrift($company, $plannedRemovals, $execute);
                }

                return self::SUCCESS;
            },
        );

        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        $this->info("DEFAULT lots inspected: {$lotsInspected}");
        $this->info("Phantom DEFAULT lots: {$lotsPhantom}");
        $this->info("Total phantom quantity: {$excessTotal}");
        $this->info("Reservations re-pointed to real lots: {$reservationsRepointed}");
        $this->info("Reservations left on the DEFAULT lot: {$reservationsLeftOnDefault}");
        $this->info("Lots only partially reduced: {$lotsPartiallyReduced}");
        $this->info("Lots skipped (excess changed under the lock): {$lotsSkippedStale}");
        $this->info("Tuples still drifted: {$tuplesStillDrifted}");

        if (! $execute) {
            $this->warn('Dry run: nothing was written. Re-run with --execute to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Every `DEFAULT` lot / location pair a company holds, with the columns the
     * census needs. Read-only projection — the repair re-reads under a lock.
     *
     * @return Collection<int, \stdClass>
     */
    private function defaultLotRows(string $companyId): Collection
    {
        /** @var Collection<int, \stdClass> $rows */
        $rows = DB::table('inventory_batch_stock')
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.company_id', $companyId)
            ->where('product_batches.batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->orderBy('inventory_batch_stock.id')
            ->select([
                'inventory_batch_stock.id as batch_stock_id',
                'inventory_batch_stock.batch_id as batch_id',
                'inventory_batch_stock.location_id as location_id',
                'inventory_batch_stock.quantity as current_quantity',
                'inventory_batch_stock.reserved_quantity as reserved_quantity',
                'product_batches.product_id as product_id',
                'product_batches.variant_id as variant_id',
            ])
            ->get();

        return $rows;
    }

    /**
     * `current DEFAULT quantity − (stock_levels.quantity − Σ real lots)`,
     * clamped at zero: the quantity this lot double-books.
     *
     * @return numeric-string
     */
    private function phantomExcessFor(Company $company, \stdClass $row): string
    {
        $variantId = is_string($row->variant_id) ? $row->variant_id : null;

        $aggregate = $this->aggregateQuantityFor($company, $row);

        $expected = $this->batchStockService->untrackedRemainderAt(
            companyId: (string) $company->id,
            productId: (string) $row->product_id,
            locationId: (string) $row->location_id,
            aggregateQuantity: $aggregate,
            variantId: $variantId,
        );

        /** @var numeric-string $excess */
        $excess = bcsub($this->decimal($row->current_quantity), $expected, self::SCALE);

        return bccomp($excess, '0', self::SCALE) > 0 ? $excess : $this->zero();
    }

    /**
     * Apply one lot's correction inside a single transaction.
     *
     * @param  numeric-string  $excess
     * @param  string  $repairRunId  Groups every movement written by this invocation.
     * @return array{0: int, 1: int, 2: numeric-string, 3: bool} re-pointed, left on DEFAULT, quantity removed, censused-excess-was-stale
     */
    private function repairLot(Company $company, \stdClass $row, string $excess, string $repairRunId): array
    {
        return DB::transaction(function () use ($company, $row, $excess, $repairRunId): array {
            $batchStock = BatchStock::query()
                ->whereKey($row->batch_stock_id)
                ->lockForUpdate()
                ->first();

            if ($batchStock === null) {
                return [0, 0, $this->zero(), false];
            }

            // Gate r1 finding 4 — the census at :151 is LOCK-FREE, so a concurrent
            // receipt or adjustment between it and this lock would make the write
            // wrong in one direction or the other. Recompute the excess from the
            // LOCKED state and refuse the lot outright if it moved: for a command
            // whose entire justification is "an operator looked at these numbers
            // first", a silently different write is worse than no write.
            $row->current_quantity = (string) $batchStock->quantity;
            $lockedExcess = $this->phantomExcessFor($company, $row);

            if (bccomp($lockedExcess, $excess, self::SCALE) !== 0) {
                return [0, 0, $this->zero(), true];
            }

            [$repointed, $left] = $this->repointReservations($company, $row, $batchStock);

            $batchStock->refresh();

            /** @var numeric-string $current */
            $current = (string) $batchStock->quantity;
            /** @var numeric-string $reserved */
            $reserved = (string) $batchStock->reserved_quantity;

            // Floor at the reservations that could not be moved: dropping below
            // them would leave a reservation the lot cannot honour.
            $target = bcsub($current, $excess, self::SCALE);
            if (bccomp($target, $reserved, self::SCALE) < 0) {
                $target = $reserved;
            }

            /** @var numeric-string $removed */
            $removed = bcsub($current, $target, self::SCALE);

            if (bccomp($removed, '0', self::SCALE) <= 0) {
                return [$repointed, $left, $this->zero(), false];
            }

            // Document-per-action: the justifying movement is written FIRST, and
            // the lot write below is its batch-level leg. `quantity` is 0 because
            // the AGGREGATE on-hand quantity is unchanged — only the lot ledger
            // was inflated (see the class docblock).
            // Gate r1 finding 3 — the SAME tuple the excess was computed from,
            // variant predicate included. Without it a variant-bearing product
            // stamps an arbitrary sibling row's quantity (or 0.0000 when the
            // product-level row is absent) onto the audit document for the
            // correction.
            $aggregateQuantity = $this->aggregateQuantityFor($company, $row);

            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'product_id' => (string) $row->product_id,
                'variant_id' => is_string($row->variant_id) ? $row->variant_id : null,
                'location_id' => (string) $row->location_id,
                'movement_type' => MovementType::Adjustment,
                'reason' => MovementReason::CountCorrection,
                'quantity' => $this->zero(),
                'quantity_before' => $aggregateQuantity,
                'quantity_after' => $aggregateQuantity,
                'reference' => 'W2-7 phantom DEFAULT batch repair',
                'reference_type' => StockMovementReferenceType::BatchLedgerRepair->value,
                'reference_id' => $repairRunId,
                'notes' => sprintf(
                    'Campaign W2-7: DEFAULT lot %d at location %s reduced by %s to its untracked remainder. '
                    .'The aggregate on-hand quantity is unchanged; only the batch ledger was double-booked.',
                    (int) $row->batch_id,
                    (string) $row->location_id,
                    $removed,
                ),
                'occurred_at' => now(),
            ]);

            BatchMovement::create([
                'tenant_id' => $company->tenant_id,
                'batch_id' => (int) $row->batch_id,
                'movement_id' => $movement->id,
                'quantity' => bcsub($this->zero(), $removed, self::SCALE),
            ]);

            $batchStock->update(['quantity' => $target]);

            return [$repointed, $left, $removed, false];
        });
    }

    /**
     * Stable key for a (product, variant, location) tuple.
     */
    private function tupleKey(\stdClass $row): string
    {
        return implode('|', [
            (string) $row->product_id,
            is_string($row->variant_id) ? $row->variant_id : '',
            (string) $row->location_id,
        ]);
    }

    /**
     * Census EVERY batch-tracked tuple of the company where the lot ledger and
     * `stock_levels` disagree, and report each one. Returns how many drifted.
     *
     * 🚨 Gate r3 R3-7. The correction loop iterates tuples that HAVE a `DEFAULT`
     * lot, so drift reported from inside it could only ever be drift that is BOTH
     * phantom AND residual. The drift class that matters most lives elsewhere:
     * a tuple whose DEFAULT lot is healthy or absent and whose REAL lots
     * disagree with the aggregate — which is exactly what an outbound sale
     * without a lot leg (before W4-5) or a return without one (before r3 R3-1)
     * produces. Those tuples were invisible, and the run printed
     * "Tuples still drifted: 0" on a tenant that was bleeding lot quantity on
     * every return.
     *
     * This is the same query the handback publishes for the owner, run by the
     * command instead of by hand. It is a pure read of two sums, so it runs in
     * the DRY-RUN arm as well; there the planned phantom removal for the tuple is
     * subtracted so the number is the drift that would REMAIN.
     *
     * Sign convention: POSITIVE means the lot ledger overstates on-hand (the
     * W2-7 / W4-5 direction), NEGATIVE means it understates (the return
     * direction, gate r3 R3-1).
     *
     * @param  array<string, numeric-string>  $plannedRemovals  keyed by {@see self::tupleKey()}
     */
    private function reportLedgerDrift(Company $company, array $plannedRemovals, bool $execute): int
    {
        $drifted = 0;

        foreach ($this->batchTrackedTuples((string) $company->id) as $row) {
            /** @var numeric-string $lotTotal */
            $lotTotal = $this->decimal($row->lot_total);
            /** @var numeric-string $aggregate */
            $aggregate = $this->decimal($row->aggregate);

            /** @var numeric-string $drift */
            $drift = bcsub($lotTotal, $aggregate, self::SCALE);

            if (! $execute) {
                $drift = bcsub($drift, $plannedRemovals[$this->tupleKey($row)] ?? $this->zero(), self::SCALE);
            }

            if (bccomp($drift, '0', self::SCALE) === 0) {
                continue;
            }

            $drifted++;

            $this->warn(sprintf(
                '    %s %s / %s @ %s (lot ledger %s vs stock_levels %s)',
                $execute ? 'RESIDUAL DRIFT' : 'WOULD REMAIN DRIFTED',
                $drift,
                (string) $row->product_id,
                (string) $row->location_id,
                $lotTotal,
                $aggregate,
            ));
            $this->warn(bccomp($drift, '0', self::SCALE) > 0
                ? self::DRIFT_CAUSE_HINT
                : self::DRIFT_CAUSE_HINT_NEGATIVE);
        }

        return $drifted;
    }

    /**
     * Every batch-tracked (product, variant, location) tuple of the company with
     * its aggregate quantity and its lot total.
     *
     * A LEFT JOIN, so a tuple with aggregate stock and NO lots at all is
     * included — that shape reads as drift of `−quantity` and is exactly the one
     * a delivery now refuses.
     *
     * @return Collection<int, \stdClass>
     */
    private function batchTrackedTuples(string $companyId): Collection
    {
        return $this->driftCensus->batchTrackedTuples($companyId);
    }

    /**
     * The tuple's `stock_levels.quantity`, resolved with the SAME variant
     * predicate everywhere it is read (gate r1 finding 3).
     *
     * @return numeric-string
     */
    private function aggregateQuantityFor(Company $company, \stdClass $row): string
    {
        $variantId = is_string($row->variant_id) ? $row->variant_id : null;

        return $this->decimal(
            StockLevel::query()
                ->where('company_id', $company->id)
                ->where('product_id', (string) $row->product_id)
                ->where('location_id', (string) $row->location_id)
                ->when($variantId === null,
                    fn ($query) => $query->whereNull('variant_id'),
                    fn ($query) => $query->where('variant_id', $variantId),
                )
                ->value('quantity'),
        );
    }

    /**
     * Move every active reservation off the DEFAULT lot onto the earliest-expiry
     * real lot that can cover it whole.
     *
     * @return array{0: int, 1: int} re-pointed, left in place
     */
    private function repointReservations(Company $company, \stdClass $row, BatchStock $defaultStock): array
    {
        $reservations = StockReservation::query()
            ->where('company_id', $company->id)
            ->where('batch_id', (int) $row->batch_id)
            ->where('location_id', (string) $row->location_id)
            ->active()
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        $repointed = 0;
        $left = 0;

        foreach ($reservations as $reservation) {
            /** @var numeric-string $quantity */
            $quantity = (string) $reservation->quantity;

            $target = $this->earliestRealLotCovering(
                companyId: (string) $company->id,
                productId: (string) $row->product_id,
                locationId: (string) $row->location_id,
                variantId: is_string($row->variant_id) ? $row->variant_id : null,
                quantity: $quantity,
            );

            if ($target === null) {
                $left++;

                continue;
            }

            // Soft hold only: `reserved_quantity` and `batch_id` move, no on-hand
            // quantity does, so this carries no movement of its own.
            $target->update([
                'reserved_quantity' => bcadd((string) $target->reserved_quantity, $quantity, self::SCALE),
            ]);

            $defaultStock->refresh();
            $defaultStock->update([
                'reserved_quantity' => bcsub((string) $defaultStock->reserved_quantity, $quantity, self::SCALE),
            ]);

            $reservation->update(['batch_id' => $target->batch_id]);

            $repointed++;
        }

        return [$repointed, $left];
    }

    /**
     * The earliest-expiry REAL (non-DEFAULT) lot at the location whose available
     * quantity covers `$quantity` whole. Same predicate the fixed reservation
     * path applies, so repaired rows match what the fixed code would have written.
     *
     * @param  numeric-string  $quantity
     */
    private function earliestRealLotCovering(
        string $companyId,
        string $productId,
        string $locationId,
        ?string $variantId,
        string $quantity,
    ): ?BatchStock {
        // The GENERATED `available_quantity` column is read through a normal
        // `where`, never `whereRaw`: a raw expression has no column affinity, so
        // SQLite compares the numeric left side against a TEXT bound parameter
        // and every candidate lot silently fails the predicate.
        $query = BatchStock::query()
            ->select('inventory_batch_stock.id')
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.company_id', $companyId)
            ->where('product_batches.product_id', $productId)
            ->where('product_batches.batch_number', '!=', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->where('product_batches.is_active', true)
            ->where('product_batches.is_recalled', false)
            // W4-1: "not expired" admits a lot with NO recorded expiry, exactly
            // as the live FEFO predicate does. Keeping the bare `>=` here would
            // make an undated real lot an invalid repair target while the fixed
            // reservation path happily draws from it — and this method exists to
            // write what that path would have written.
            ->where(function ($q): void {
                $q->whereNull('product_batches.expiry_date')
                    ->orWhere('product_batches.expiry_date', '>=', now()->startOfDay());
            })
            ->where('inventory_batch_stock.location_id', $locationId)
            ->where('inventory_batch_stock.available_quantity', '>=', $quantity)
            ->orderByRaw('(product_batches.expiry_date IS NULL) ASC, product_batches.expiry_date ASC');

        if ($variantId === null) {
            $query->whereNull('product_batches.variant_id');
        } else {
            $query->where('product_batches.variant_id', $variantId);
        }

        // Discovery is lock-free (a `FOR UPDATE` over the join would lock
        // `product_batches` rows too); the chosen row is then locked by key and
        // its availability RE-CHECKED under that lock, so a concurrent reserver
        // between the two reads cannot make us over-commit the lot.
        $candidateId = $query->value('inventory_batch_stock.id');

        if ($candidateId === null) {
            return null;
        }

        $locked = BatchStock::query()
            ->whereKey($candidateId)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            return null;
        }

        $available = bcsub(
            (string) $locked->quantity,
            (string) $locked->reserved_quantity,
            self::SCALE,
        );

        return bccomp($available, $quantity, self::SCALE) >= 0 ? $locked : null;
    }

    /**
     * Normalise a driver-supplied decimal (int, float or string) to a scale-4
     * decimal string without ever routing it through a float literal.
     *
     * @return numeric-string
     */
    private function decimal(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return $this->zero();
        }

        $raw = trim((string) $value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $raw) !== 1) {
            return $this->zero();
        }

        // FLOOR, not HALF_UP: these values come straight out of decimal(15,4)
        // columns, so the scale is already canonical and flooring merely states
        // that on-hand quantities are never rounded UP.
        return QuantityScale::round($raw, self::SCALE, QuantityScale::FLOOR);
    }

    /** @return numeric-string */
    private function zero(): string
    {
        return QuantityScale::round('0', self::SCALE, QuantityScale::FLOOR);
    }
}
