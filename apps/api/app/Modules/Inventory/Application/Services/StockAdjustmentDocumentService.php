<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Application\DTOs\CreateStockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentLineInput;
use App\Modules\Inventory\Application\DTOs\UpdateStockAdjustmentData;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Domain\Exceptions\AdjustmentAlreadyCorrectedException;
use App\Modules\Inventory\Domain\Exceptions\BatchNotApplicableException;
use App\Modules\Inventory\Domain\Exceptions\BatchRequiredForLineException;
use App\Modules\Inventory\Domain\Exceptions\CannotCorrectACorrectionException;
use App\Modules\Inventory\Domain\Exceptions\LineTenantMismatchException;
use App\Modules\Inventory\Domain\Exceptions\StockAdjustmentStateException;
use App\Modules\Inventory\Domain\Exceptions\UseBatchWriteOffException;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Inventory\Domain\StockAdjustmentLine;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The `stock_adjustments` document lifecycle (DPA V7 / D12).
 *
 * Lives in `Inventory\Application\Services` next to GoodsReceiptService and
 * StockTransferService; the low-level writer StockAdjustmentService stays in
 * `Inventory\Domain\Services` and is NOT renamed or moved.
 *
 * `post()` is the interesting one. It mirrors GoodsReceiptService::post()'s
 * skeleton with StockTransferService::complete()'s retry and typed exception:
 *
 *   DB::transaction(attempts: 3)
 *     -> lockForUpdate()->firstOrFail() the header
 *     -> typed state assert
 *     -> load lines
 *     -> resolve the LOCK TUPLE through the same source as the writer (D14a)
 *     -> collect + sort productIds -> ONE costLock->acquire (D14)
 *     -> stamp adjustment_number + status + audit columns
 *     -> per-line adjustByDelta(...)  [nested acquires are re-entrant]
 *     -> write movement_id / quantity_before / quantity_after ON THE LINE
 */
final class StockAdjustmentDocumentService
{
    private const SCALE = 4;

    /**
     * `DocumentSequence.type` key for the ADJ series (D13). Deliberately a raw
     * sequence key, not a DocumentType — the adjustment is NOT a row in the
     * unified `documents` table (D4).
     */
    private const SEQUENCE_KEY = 'stock_adjustment';

    private const SEQUENCE_PREFIX = 'ADJ';

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly DocumentNumberingService $numberingService,
        private readonly ProductCostLock $costLock,
    ) {}

    /**
     * Create a DRAFT. Writes no movement and stamps no number.
     *
     * ASSUMES AN OPEN TRANSACTION (the plan-cf T2 convention), so
     * `post_immediately` is one transaction and an immediate-post refusal rolls
     * the draft back with it — which is exactly why the refusal payload's
     * `line_id` is null on that flow (D15a).
     */
    public function createDraft(CreateStockAdjustmentData $data): StockAdjustment
    {
        if ($data->lines === []) {
            throw new InvalidArgumentException('A stock adjustment needs at least one line.');
        }

        if ($data->idempotencyKey !== null) {
            // Read-then-insert, not an upsert: on a genuine race the loser's
            // INSERT is refused by `stock_adjustments_idempotency_unique`, which
            // is the authoritative guard. The window here is narrow and the
            // failure mode is benign-but-confusing rather than corrupting — a
            // concurrent duplicate can return an already-POSTED document, and a
            // `post_immediately` retry on it then refuses with
            // INVALID_ADJUSTMENT_STATE instead of replaying 200 (gate M-6). The
            // controller's own pre-check short-circuits the common case.
            $existing = StockAdjustment::query()
                ->where('tenant_id', $data->tenantId)
                ->where('company_id', $data->companyId)
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $adjustment = StockAdjustment::create([
            'tenant_id' => $data->tenantId,
            'company_id' => $data->companyId,
            'adjustment_number' => null,
            'status' => StockAdjustmentStatus::Draft,
            'note' => $data->note,
            'location_id' => $data->locationId,
            // v1 FORBIDS backdating (D11): stamped server-side, and the
            // FormRequest prohibits a client value, so no window exists in which
            // a backdated adjustment can be authored before a period guard exists.
            'occurred_at' => now(),
            'idempotency_key' => $data->idempotencyKey,
            'created_by_user_id' => $data->createdByUserId,
        ]);

        $this->writeLines($adjustment, $data->lines);

        return $adjustment->refresh();
    }

    /**
     * Draft-only edit; the re-anchor target of the persisted-draft staleness
     * branch (plan §2's PATCH contract).
     */
    public function updateDraft(string $adjustmentId, UpdateStockAdjustmentData $data): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $data): StockAdjustment {
            $adjustment = $this->lockHeader($adjustmentId);
            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'update');

            if ($data->noteProvided) {
                $adjustment->note = $data->note;
                $adjustment->save();
            }

            if ($data->lines !== null) {
                if ($data->lines === []) {
                    throw new InvalidArgumentException('A stock adjustment needs at least one line.');
                }

                // Full replace. Safe because a DRAFT line always has
                // movement_id IS NULL by construction (D5/D10), so
                // delete-and-recreate inside this transaction loses no back-link
                // and cannot orphan a movement.
                $adjustment->lines()->delete();
                $this->writeLines($adjustment, $data->lines);
            }

            return $adjustment->refresh();
        }, attempts: 3);
    }

    public function post(
        string $adjustmentId,
        string $actorId,
        bool $acknowledgeStale = false,
        bool $ignoreReservations = false,
    ): StockAdjustment {
        return DB::transaction(function () use ($adjustmentId, $actorId, $acknowledgeStale, $ignoreReservations): StockAdjustment {
            $adjustment = $this->lockHeader($adjustmentId);
            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'post');

            $adjustment->load('lines');
            $lines = $adjustment->lines->all();

            if ($lines === []) {
                throw new InvalidArgumentException('A stock adjustment needs at least one line.');
            }

            /** @var list<string> $productIds */
            $productIds = $adjustment->lines
                ->pluck('product_id')
                ->filter()
                ->unique()
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            // D14a — the up-front advisory key MUST be built from the SAME tuple
            // as the nested per-line acquire, or the deadlock defence evaporates
            // while the architecture test stays green.
            $this->assertLineTenantsMatch($adjustment, $productIds);

            return $this->costLock->acquire(
                $adjustment->tenant_id,
                $adjustment->company_id,
                $productIds,
                function () use ($adjustment, $lines, $actorId, $acknowledgeStale, $ignoreReservations): StockAdjustment {
                    $adjustment->forceFill([
                        'adjustment_number' => $this->numberingService->generateForKey(
                            $adjustment->tenant_id,
                            $adjustment->company_id,
                            self::SEQUENCE_KEY,
                            self::SEQUENCE_PREFIX,
                        ),
                        'status' => StockAdjustmentStatus::Posted,
                        'posted_by_user_id' => $actorId,
                        'posted_at' => now(),
                    ])->save();

                    // Deltas already applied WITHIN THIS DOCUMENT, per
                    // (product, variant). See rebasedObservedBefore().
                    /** @var array<string, numeric-string> $appliedSoFar */
                    $appliedSoFar = [];

                    // Whether each override was actually RELIED ON. The audit
                    // columns exist to make an override visible; stamping them
                    // because a flag was merely SENT dilutes them to noise (gate
                    // code-review M-5), and a header that claims an operator
                    // overrode the reservation guard when they never met it is a
                    // false record, not a conservative one.
                    $staleDiverged = false;
                    $reservationsBreached = false;

                    foreach ($lines as $line) {
                        // RE-ASSERT the lot predicates HERE, under the lock —
                        // never trust the draft-time check (gate code-review I-1).
                        // A draft persists indefinitely, so a lot minted between
                        // authoring and posting (goods receipt, transfer in,
                        // another adjustment) leaves a lot-less negative line
                        // legal-at-draft and desyncing-at-post. The staleness
                        // guard usually intercepts because the aggregate moved —
                        // but `acknowledge_stale`, the "Apply anyway" affordance
                        // the UI ships, bypasses exactly that. The
                        // `requires_batch_tracking` flag is user-toggleable in the
                        // same window, so USE_BATCH_WRITE_OFF is re-asserted too.
                        $this->assertLinePredicatesAtPost($adjustment, $line);

                        /** @var numeric-string $delta */
                        $delta = (string) $line->delta_quantity;
                        $bucket = $line->product_id.'|'.($line->variant_id ?? '');
                        $observedBefore = $this->rebasedObservedBefore($line, $appliedSoFar[$bucket] ?? '0');
                        $appliedSoFar[$bucket] = bcadd($appliedSoFar[$bucket] ?? '0', $delta, self::SCALE);

                        $movement = $this->stockAdjustmentService->adjustByDelta(
                            productId: $line->product_id,
                            locationId: $adjustment->location_id,
                            deltaQuantity: $delta,
                            reference: (string) $adjustment->adjustment_number,
                            userId: $actorId,
                            expectedCompanyId: $adjustment->company_id,
                            variantId: $line->variant_id,
                            reasonCode: $line->reason_code,
                            referenceType: StockMovementReferenceType::StockAdjustment,
                            referenceId: $adjustment->id,
                            observedBefore: $observedBefore,
                            acknowledgeStale: $acknowledgeStale,
                            ignoreReservations: $ignoreReservations,
                            batchId: $line->batch_id,
                            reversesMovementId: $line->movement_id === null ? $this->reversesMovementIdFor($adjustment, $line) : null,
                        );

                        // Back-link on the LINE (the GoodsReceiptService pattern),
                        // never a post-hoc UPDATE on the movement.
                        $line->forceFill([
                            'movement_id' => $movement->id,
                            'quantity_before' => $movement->quantity_before,
                            'quantity_after' => $movement->quantity_after,
                        ])->save();

                        // Did the staleness guard actually have something to
                        // refuse? Compared against the REBASED anchor, so this
                        // document's own arithmetic never counts as divergence.
                        if (bccomp($observedBefore, (string) $movement->quantity_before, self::SCALE) !== 0) {
                            $staleDiverged = true;
                        }

                        // Did this line actually rely on the reservation
                        // override? Only a NEGATIVE delta can, and only when the
                        // resulting availability is below zero.
                        if (bccomp($delta, '0', self::SCALE) < 0
                            && $this->availabilityWentNegative($line, $adjustment->location_id)) {
                            $reservationsBreached = true;
                        }
                    }

                    if ($acknowledgeStale && $staleDiverged) {
                        $adjustment->forceFill([
                            'stale_acknowledged_at' => now(),
                            'stale_acknowledged_by_user_id' => $actorId,
                        ])->save();
                    }

                    if ($ignoreReservations && $reservationsBreached) {
                        $adjustment->forceFill([
                            'reservations_ignored_at' => now(),
                            'reservations_ignored_by_user_id' => $actorId,
                        ])->save();
                    }

                    return $adjustment->refresh();
                }
            );
        }, attempts: 3);
    }

    public function cancel(string $adjustmentId, string $actorId, ?string $reason = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $actorId, $reason): StockAdjustment {
            $adjustment = $this->lockHeader($adjustmentId);
            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'cancel');

            $adjustment->forceFill([
                'status' => StockAdjustmentStatus::Cancelled,
                'cancelled_by_user_id' => $actorId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            return $adjustment->refresh();
        }, attempts: 3);
    }

    /**
     * Build the contra DRAFT that corrects a POSTED document (D8).
     *
     * All-or-nothing: every line is negated, with the reason remapped so the
     * sign CHECK stays satisfiable. The remap is INFORMATION-LOSING — `damage`
     * and `write_off` both invert to `adjustment_positive`, and `affectsCOGS()`
     * differs across them — so G1 must read the ORIGINAL reason through
     * `reverses_movement_id`, never the contra's own reason.
     */
    public function correct(string $adjustmentId, string $actorId): StockAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $actorId): StockAdjustment {
            $adjustment = $this->lockHeader($adjustmentId);

            if ($adjustment->status !== StockAdjustmentStatus::Posted) {
                throw StockAdjustmentStateException::for($adjustment->id, $adjustment->status, 'correct');
            }

            if ($adjustment->corrects_adjustment_id !== null) {
                throw new CannotCorrectACorrectionException($adjustment->id, $adjustment->corrects_adjustment_id);
            }

            $existingCorrection = StockAdjustment::query()
                ->where('corrects_adjustment_id', $adjustment->id)
                ->first();

            if ($existingCorrection !== null) {
                throw new AdjustmentAlreadyCorrectedException($adjustment->id, $existingCorrection->id);
            }

            $adjustment->load('lines');

            $contra = StockAdjustment::create([
                'tenant_id' => $adjustment->tenant_id,
                'company_id' => $adjustment->company_id,
                'adjustment_number' => null,
                'status' => StockAdjustmentStatus::Draft,
                'note' => $adjustment->note,
                'location_id' => $adjustment->location_id,
                'occurred_at' => now(),
                'created_by_user_id' => $actorId,
                'corrects_adjustment_id' => $adjustment->id,
            ]);

            // Contra lines go through the SAME resolution as authored lines
            // (gate code-review C-1). Copying `batch_id` verbatim was the defect:
            // for the door-1 onboarding case the original posts lot-LESS and the
            // writer mints the DEFAULT lot, so a verbatim copy made the contra a
            // NEGATIVE lot-less line that moved the aggregate and no lot at all —
            // Sigma lots > aggregate, i.e. the exact FEFO corruption D1b exists to
            // prevent, reachable from a first-class permissioned action.
            $contraInputs = [];

            foreach ($adjustment->lines as $line) {
                /** @var numeric-string $delta */
                $delta = (string) $line->delta_quantity;

                $contraInputs[] = new StockAdjustmentLineInput(
                    productId: $line->product_id,
                    variantId: $line->variant_id,
                    // Which lot the ORIGINAL actually moved, not which lot it
                    // named: for a lot-less positive on a batch-tracked product
                    // that is the DEFAULT lot the writer minted.
                    batchUuid: $this->lotActuallyMovedBy($line),
                    reasonCode: self::contraReason($line->reason_code),
                    deltaQuantity: bcmul($delta, '-1', self::SCALE),
                    // The contra is authored against what the ORIGINAL actually
                    // posted, so its own authoring snapshot is that line's result.
                    observedBefore: (string) ($line->quantity_after ?? $line->observed_before),
                    lineNote: $line->line_note,
                );
            }

            $this->writeLines($contra, $contraInputs);

            return $contra->refresh();
        }, attempts: 3);
    }

    /**
     * The lot the ORIGINAL line actually moved, as its public uuid.
     *
     * `stock_adjustment_lines.batch_id` records what the operator NAMED, which is
     * NULL for the door-1 onboarding case — but the writer still landed that
     * stock in the DEFAULT lot it minted. The truth of what moved lives in the
     * `inventory_batch_movements` row keyed on the posted movement, so the contra
     * reads it from there rather than trusting the named column.
     */
    private function lotActuallyMovedBy(StockAdjustmentLine $line): ?string
    {
        $batchId = $line->batch_id;

        if ($batchId === null && $line->movement_id !== null) {
            $moved = BatchMovement::query()
                ->where('movement_id', $line->movement_id)
                ->value('batch_id');

            $batchId = $moved !== null ? (int) $moved : null;
        }

        if ($batchId === null) {
            return null;
        }

        $uuid = Batch::query()->whereKey($batchId)->value('uuid');

        return $uuid !== null ? (string) $uuid : null;
    }

    /**
     * The D8 contra remap. A positive `damage` is unrepresentable under the sign
     * CHECK, so the mapping is FORCED, not chosen.
     */
    public static function contraReason(MovementReason $original): MovementReason
    {
        return match ($original) {
            MovementReason::AdjustmentPositive => MovementReason::AdjustmentNegative,
            default => MovementReason::AdjustmentPositive,
        };
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param  list<StockAdjustmentLineInput>  $lines
     */
    private function writeLines(StockAdjustment $adjustment, array $lines): void
    {
        foreach ($lines as $input) {
            $product = $this->resolveProduct($adjustment, $input->productId);

            $this->assertReasonSign($input);
            $this->assertReasonAllowedForProduct($product, $input);

            $batchId = $this->resolveBatchId($adjustment, $product, $input);

            StockAdjustmentLine::create([
                'adjustment_id' => $adjustment->id,
                'tenant_id' => $adjustment->tenant_id,
                'company_id' => $adjustment->company_id,
                'product_id' => $input->productId,
                'variant_id' => $input->variantId,
                'batch_id' => $batchId,
                'reason_code' => $input->reasonCode,
                'delta_quantity' => $input->deltaQuantity,
                'observed_before' => $input->observedBefore,
                'line_note' => $input->lineNote,
            ]);
        }
    }

    private function resolveProduct(StockAdjustment $adjustment, string $productId): Product
    {
        return Product::query()
            ->where('tenant_id', $adjustment->tenant_id)
            ->where('company_id', $adjustment->company_id)
            ->findOrFail($productId);
    }

    /**
     * DERIVED from MovementReason::getMovementType() — zero literal reason lists
     * in runtime code (D6a). The migration's CHECK freezes the same partition as
     * a point-in-time snapshot; T17 asserts the two agree.
     */
    private function assertReasonSign(StockAdjustmentLineInput $input): void
    {
        $sign = bccomp($input->deltaQuantity, '0', self::SCALE);

        if ($sign === 0) {
            throw new InvalidArgumentException('A stock adjustment delta must not be zero.');
        }

        $expected = $input->reasonCode->getMovementType() === 'in' ? 1 : -1;

        if ($sign !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'Reason %s requires a %s delta_quantity.',
                $input->reasonCode->value,
                $expected === 1 ? 'positive' : 'negative',
            ));
        }
    }

    /**
     * D7a — `damage` / `write_off` are refused on a batch-tracked product,
     * keyed on `products.requires_batch_tracking` ALONE.
     */
    private function assertReasonAllowedForProduct(Product $product, StockAdjustmentLineInput $input): void
    {
        $destructive = in_array($input->reasonCode, [MovementReason::Damage, MovementReason::WriteOff], true);

        if ($destructive && $product->requires_batch_tracking) {
            throw new UseBatchWriteOffException($product->id, $input->reasonCode);
        }
    }

    /**
     * The two lot predicates of D1b part 2, both deliberately FLAG-INDEPENDENT:
     *
     *  - a NEGATIVE line must name a lot whenever the product holds at least one
     *    lot with stock at the header's location (that is what corrupts FEFO);
     *  - a named lot must belong to THIS product and have a BatchStock row at
     *    THIS location.
     *
     * A POSITIVE line never requires a lot: `postAdjustmentWithinLock` lands it
     * in the DEFAULT lot by the delta, which is what keeps the pharmacy /
     * parapharmacy onboarding flow (flag true, zero lots) authorable.
     */
    private function resolveBatchId(StockAdjustment $adjustment, Product $product, StockAdjustmentLineInput $input): ?int
    {
        $isNegative = bccomp($input->deltaQuantity, '0', self::SCALE) < 0;

        if ($input->batchUuid !== null) {
            $batch = Batch::query()
                ->where('tenant_id', $adjustment->tenant_id)
                ->where('company_id', $adjustment->company_id)
                ->where('product_id', $product->id)
                ->where('uuid', $input->batchUuid)
                ->first();

            if ($batch === null) {
                throw new BatchNotApplicableException($product->id, $input->batchUuid);
            }

            // "Holds a stock row HERE" is a requirement of DRAWING FROM a lot, not
            // of adding to one: receiveBatchStock() creates the row. Applying it
            // to positive lines refused a contra that was putting stock back into
            // a lot whose now-empty row had been cleaned up (gate N-1).
            if ($isNegative && ! $this->lotHasStockRowAt($batch->id, $adjustment->location_id)) {
                throw new BatchNotApplicableException($product->id, $input->batchUuid);
            }

            return (int) $batch->id;
        }

        // A CONTRA line is exempt from lot-required (gate N-1).
        //
        // It is not a new correction: it is the exact inverse of a specific prior
        // movement whose lot disposition is already settled, and it INHERITS that
        // disposition from `lotActuallyMovedBy()`. When the original genuinely
        // moved no lot — a lot-less positive on a product that was not lot-tracked
        // at the time — the contra must be able to move no lot either. Demanding
        // one dead-ends `correct()`, a first-class permissioned action on a POSTED
        // document, behind an authoring-time refusal the correction UI cannot
        // satisfy: the contra's lot is machine-chosen and there is no picker.
        //
        // The exemption is narrow by construction — it is keyed on the header's
        // `corrects_adjustment_id`, so a hand-authored line on the same product in
        // the same configuration is still refused.
        if ($isNegative
            && $adjustment->corrects_adjustment_id === null
            && $this->hasLotsWithStockAtLocation($product->id, $adjustment->location_id)) {
            throw new BatchRequiredForLineException($product->id);
        }

        return null;
    }

    private function lotHasStockRowAt(int $batchId, string $locationId): bool
    {
        return BatchStock::query()
            ->where('batch_id', $batchId)
            ->where('location_id', $locationId)
            ->exists();
    }

    /**
     * Whether this line's post left `available` (= quantity − reserved) below
     * zero — i.e. whether the reserved-aware guard was actually overridden
     * rather than merely waived in the request body (gate M-5).
     */
    private function availabilityWentNegative(StockAdjustmentLine $line, string $locationId): bool
    {
        $stockLevel = StockLevel::query()
            ->where('product_id', $line->product_id)
            ->where('location_id', $locationId)
            ->when(
                $line->variant_id === null,
                static fn ($query) => $query->whereNull('variant_id'),
                static fn ($query) => $query->where('variant_id', $line->variant_id),
            )
            ->first();

        if ($stockLevel === null) {
            return false;
        }

        return bccomp($stockLevel->getAvailableQuantity(), '0', self::SCALE) < 0;
    }

    /**
     * The post-time re-assertion of the three lot predicates (gate I-1).
     *
     * Deliberately expressed against the PERSISTED line rather than an input DTO:
     * at this point the operator's intent is already stored, and what has to be
     * re-checked is whether the WORLD still agrees with it.
     */
    private function assertLinePredicatesAtPost(StockAdjustment $adjustment, StockAdjustmentLine $line): void
    {
        $product = $this->resolveProduct($adjustment, $line->product_id);

        $destructive = in_array($line->reason_code, [MovementReason::Damage, MovementReason::WriteOff], true);
        if ($destructive && $product->requires_batch_tracking) {
            throw new UseBatchWriteOffException($product->id, $line->reason_code);
        }

        /** @var numeric-string $delta */
        $delta = (string) $line->delta_quantity;
        $isNegative = bccomp($delta, '0', self::SCALE) < 0;

        if ($line->batch_id === null) {
            // A positive line never requires a lot — the writer lands it in the
            // DEFAULT lot by the delta, which is what keeps the pharmacy /
            // parapharmacy onboarding case authorable (door 1). A CONTRA line is
            // exempt too; see resolveBatchId() for why.
            if ($isNegative
                && $adjustment->corrects_adjustment_id === null
                && $this->hasLotsWithStockAtLocation($product->id, $adjustment->location_id)) {
                throw new BatchRequiredForLineException($product->id);
            }

            return;
        }

        // A named lot must STILL belong to this product; and, for a NEGATIVE line,
        // must STILL hold a stock row at this location (a positive line may create
        // one — see resolveBatchId()).
        $batch = Batch::query()
            ->where('tenant_id', $adjustment->tenant_id)
            ->where('company_id', $adjustment->company_id)
            ->where('product_id', $product->id)
            ->whereKey($line->batch_id)
            ->first();

        $stillApplies = $batch !== null
            && (! $isNegative || $this->lotHasStockRowAt($batch->id, $adjustment->location_id));

        if (! $stillApplies) {
            throw new BatchNotApplicableException(
                $product->id,
                $batch !== null ? (string) $batch->uuid : (string) $line->batch_id,
            );
        }
    }

    private function hasLotsWithStockAtLocation(string $productId, string $locationId): bool
    {
        return BatchStock::query()
            ->where('location_id', $locationId)
            ->where('quantity', '>', 0)
            ->whereIn('batch_id', Batch::query()->where('product_id', $productId)->select('id'))
            ->exists();
    }

    /**
     * @param  list<string>  $productIds
     */
    private function assertLineTenantsMatch(StockAdjustment $adjustment, array $productIds): void
    {
        /** @var array<string, string> $tenantsById */
        $tenantsById = Product::query()
            ->where('company_id', $adjustment->company_id)
            ->whereIn('id', $productIds)
            ->pluck('tenant_id', 'id')
            ->all();

        foreach ($productIds as $productId) {
            $found = $tenantsById[$productId] ?? null;

            if ($found === null || $found !== $adjustment->tenant_id) {
                throw new LineTenantMismatchException(
                    productId: $productId,
                    expectedTenantId: $adjustment->tenant_id,
                    foundTenantId: (string) ($found ?? ''),
                );
            }
        }
    }

    /**
     * The staleness anchor for a line, rebased by what THIS DOCUMENT has already
     * applied to the same (product, variant).
     *
     * Why this exists: `observed_before` is a (product, variant, location)
     * AGGREGATE snapshot, but D1b part 5 deliberately allows several lines on one
     * product when they name different lots ("one line = one lot"), and the four
     * partial uniques key on `batch_id` precisely to permit it. Comparing every
     * line's raw snapshot against the locked row would make the SECOND line of any
     * multi-lot document trip STOCK_MOVED_SINCE_AUTHORING against the FIRST line's
     * own effect — the document would refuse itself, and T16's required
     * "Sigma BatchStock equals stock_levels.quantity after a mixed multi-lot post"
     * would be unreachable. The guard exists to catch movement by SOMEONE ELSE
     * between authoring and posting; a sibling line in the same transaction is not
     * that.
     *
     * The seam contract is untouched: adjustByDelta() still compares one value
     * against the locked row. Only the expectation is rebased, here, where the
     * document's own arithmetic is known. Flagged as Collision C-2 in the task
     * report; a ruling may prefer a different placement.
     *
     * @param  numeric-string  $appliedSoFar
     * @return numeric-string
     */
    private function rebasedObservedBefore(StockAdjustmentLine $line, string $appliedSoFar): string
    {
        /** @var numeric-string $observed */
        $observed = (string) $line->observed_before;

        if (bccomp($appliedSoFar, '0', self::SCALE) === 0) {
            return $observed;
        }

        return bcadd($observed, $appliedSoFar, self::SCALE);
    }

    /**
     * A contra document's line points at the ORIGINAL line's movement (D8).
     * Threaded through the seam parameter, never set by a post-hoc UPDATE.
     */
    private function reversesMovementIdFor(StockAdjustment $adjustment, StockAdjustmentLine $line): ?string
    {
        if ($adjustment->corrects_adjustment_id === null) {
            return null;
        }

        $original = StockAdjustmentLine::query()
            ->where('adjustment_id', $adjustment->corrects_adjustment_id)
            ->where('product_id', $line->product_id)
            ->when(
                $line->variant_id === null,
                static fn ($query) => $query->whereNull('variant_id'),
                static fn ($query) => $query->where('variant_id', $line->variant_id),
            )
            ->when(
                $line->batch_id === null,
                static fn ($query) => $query->whereNull('batch_id'),
                static fn ($query) => $query->where('batch_id', $line->batch_id),
            )
            ->first();

        return $original?->movement_id;
    }

    private function lockHeader(string $adjustmentId): StockAdjustment
    {
        /** @var StockAdjustment $adjustment */
        $adjustment = StockAdjustment::query()
            ->whereKey($adjustmentId)
            ->lockForUpdate()
            ->firstOrFail();

        return $adjustment;
    }

    private function assertStatus(StockAdjustment $adjustment, StockAdjustmentStatus $required, string $action): void
    {
        if ($adjustment->status !== $required) {
            throw StockAdjustmentStateException::for($adjustment->id, $adjustment->status, $action);
        }
    }
}
