<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\Services;

use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffData;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffMovementResult;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffResult;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Entities\GroupedWriteOff;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockLevel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes off MULTIPLE lots in ONE atomic, all-or-nothing, idempotent operation.
 *
 * Deadlock-freedom (scoped to the WRITE-OFF FAMILY) rests on a SINGLE canonical
 * cross-domain lock order that is COMPATIBLE with the single-lot
 * {@see BatchWriteOffService} path:
 *
 *   1. stock_levels rows FIRST, ordered by (product_id, variant_id, location_id)
 *      ascending.
 *   2. inventory_batch_stock rows THEN, ordered by batch_id ascending.
 *
 * The single-lot path locks in exactly this domain order — its issue() locks the
 * aggregate stock_levels row, then issueBatchStock() locks the lot row — so a
 * grouped call and a concurrent single-lot call acquire the two domains in the
 * same global order and cannot form a cross-domain cycle.
 *
 * SCOPE OF THE GUARANTEE: this canonical order is global only ACROSS THE WRITE-OFF
 * FAMILY (grouped + single-lot). It is NOT yet globally deadlock-free against every
 * stock_levels writer: a concurrent multi-line POS sale (ReceiptCreationService)
 * locks stock_levels rows in UNSORTED line order and could form a cross-cycle with
 * a grouped write-off. Closing that requires the POS path to adopt the SAME
 * (product_id, variant_id, location_id) sort — see TODO(deadlock) in acquireLocks().
 *
 * CRITICAL: ALL locks are acquired up front (acquireLocks), BEFORE ANY mutation.
 * The per-line work then reuses BatchWriteOffService::writeOff(); the row re-locks
 * inside issue()/issueBatchStock() are no-ops because this transaction already
 * holds every lock, so they cannot reorder acquisition or deadlock.
 *
 * Aggregate-lock variant note: the reused single-lot primitive decrements the
 * PRODUCT-LEVEL aggregate row (StockAdjustmentService::issue() is invoked WITHOUT
 * a variantId by BatchWriteOffService), so the canonical stock_levels lock targets
 * the variant_id IS NULL rows keyed by product_id. The (…, variant_id, …) sort key
 * is therefore honoured with a NULL variant component; lot-level (variant-aware)
 * granularity lives in inventory_batch_stock, keyed by batch_id.
 */
final class GroupedWriteOffService
{
    private const SCALE = 4;

    public function __construct(
        private readonly BatchWriteOffService $batchWriteOffService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Atomically write off every lot in the group, or none of them.
     *
     * @throws InsufficientBatchStockException When any lot is short (rolls back all lines).
     * @throws \DomainException On empty input or an unknown/cross-company batch.
     * @throws \InvalidArgumentException When the reason is not a write-off reason.
     */
    public function writeOffGroup(GroupedWriteOffData $data, string $userId): GroupedWriteOffResult
    {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        try {
            return DB::transaction(
                fn (): GroupedWriteOffResult => $this->apply($data, $userId, $tenantId, $companyId),
            );
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent FIRST-TIME request carrying the same idempotency_key won
            // the unique-index race. Postgres only surfaces the violation once the
            // winner has COMMITTED, so its result row is now visible: our own stock
            // mutations rolled back with the aborted transaction, and we replay the
            // winner's persisted result instead of double-applying.
            $existing = $this->findExisting($tenantId, $data->idempotencyKey);
            if ($existing !== null) {
                return GroupedWriteOffResult::fromArray($existing->result);
            }

            throw $e;
        }
    }

    /**
     * The canonical lock-acquisition plan for a group. Exposed so the ordering is
     * deterministically testable without a live multi-connection database (sqlite
     * does not exhibit real row-lock contention).
     *
     * @param  Collection<int, Batch>  $batches  Resolved batches keyed by id.
     * @return array{stock_levels: list<string>, batch_stock: list<int>}
     *                                                                   stock_levels: product_ids ascending (the variant_id-NULL aggregate rows);
     *                                                                   batch_stock: batch_ids ascending.
     */
    public function planLockOrder(GroupedWriteOffData $data, Collection $batches): array
    {
        /** @var list<string> $productIds */
        $productIds = $batches
            ->map(static fn (Batch $b): string => (string) $b->product_id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        /** @var list<int> $batchIds */
        $batchIds = collect($data->lines)
            ->map(static fn ($line): int => $line->batchId)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'stock_levels' => $productIds,
            'batch_stock' => $batchIds,
        ];
    }

    private function apply(GroupedWriteOffData $data, string $userId, string $tenantId, string $companyId): GroupedWriteOffResult
    {
        // 1. Idempotency FIRST — a persisted record means the work already ran;
        //    return its result without touching any stock.
        $existing = $this->findExisting($tenantId, $data->idempotencyKey);
        if ($existing !== null) {
            return GroupedWriteOffResult::fromArray($existing->result);
        }

        if ($data->lines === []) {
            throw new \DomainException('A grouped write-off requires at least one line.');
        }

        // Map the enum reason to the single-lot service's reason string up front so
        // an invalid reason fails before any lock is taken.
        $writeOffReason = $this->mapReason($data->reason);

        $batches = $this->resolveBatches($data, $companyId);

        // 2. CANONICAL LOCK ORDER — acquire ALL locks before ANY mutation.
        $this->acquireLocks($data, $batches, $companyId);

        // 2b. IDEMPOTENCY double-check UNDER the locks (TOCTOU guard). A concurrent
        //     first-time request O carrying the same key may still have been
        //     uncommitted — and therefore invisible to the top-of-apply
        //     findExisting() under READ COMMITTED. We then BLOCKED in acquireLocks()
        //     on O's row locks; once O COMMITS and releases them, its
        //     grouped_write_offs record becomes visible to us. Re-read it here and
        //     REPLAY (no stock mutation) instead of falling through to
        //     assertSufficientStock(), which would otherwise throw a SPURIOUS
        //     InsufficientBatchStockException for a write-off that already succeeded
        //     — violating the "replay returns the same result" contract. The
        //     post-rollback UniqueConstraintViolationException catch in
        //     writeOffGroup() remains the backstop for the residual window where O
        //     commits between this re-check and our own GroupedWriteOff::create().
        $existing = $this->findExisting($tenantId, $data->idempotencyKey);
        if ($existing !== null) {
            return GroupedWriteOffResult::fromArray($existing->result);
        }

        // 3. Validate every lot under the locks (all-or-nothing).
        $this->assertSufficientStock($data, $companyId);

        // 4. Apply each line. We reuse the single-lot write-off primitive verbatim;
        //    its internal stock_levels/inventory_batch_stock re-locks are no-ops
        //    because acquireLocks() already holds every row in canonical order.
        $movements = [];
        foreach ($data->lines as $line) {
            /** @var Batch $batch */
            $batch = $batches->get($line->batchId);

            $movement = $this->batchWriteOffService->writeOff(
                batch: $batch,
                locationId: $data->locationId,
                quantity: $line->quantity,
                reason: $writeOffReason,
                userId: $userId,
            );

            // unit_cost/total_cost are decimal:6 casts on StockMovement (NOT an
            // explicit string cast), so reading them THROUGH the cast risks a float
            // round-trip that sheds scale ("2.000000" -> "2"/"2.0"), violating the
            // precision contract. getRawOriginal() bypasses the cast and returns the
            // raw full-scale DB string StockAdjustmentService persisted (bcadd/bcmul
            // at COST_SCALE=6).
            /** @var numeric-string $unitCost */
            $unitCost = (string) ($movement->getRawOriginal('unit_cost') ?? '0.000000');
            /** @var numeric-string $totalCost */
            $totalCost = (string) ($movement->getRawOriginal('total_cost') ?? '0.000000');

            $movements[] = new GroupedWriteOffMovementResult(
                batchId: $line->batchId,
                movementId: (string) $movement->id,
                quantity: $line->quantity,
                unitCost: $unitCost,
                totalCost: $totalCost,
            );
        }

        $result = new GroupedWriteOffResult(
            idempotencyKey: $data->idempotencyKey,
            replayed: false,
            movements: $movements,
        );

        // 5. Persist the idempotency record. The UNIQUE (tenant_id, idempotency_key)
        //    index is the race backstop: a concurrent first-time insert with the same
        //    key throws UniqueConstraintViolationException, handled in writeOffGroup().
        GroupedWriteOff::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'location_id' => $data->locationId,
            'idempotency_key' => $data->idempotencyKey,
            'reason' => $data->reason->value,
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    /**
     * Acquire every lock the group needs, in the canonical order, BEFORE any
     * mutation. Locking the whole set up front (rather than interleaving lock/mutate
     * per line) is what makes concurrent grouped/single-lot write-offs deadlock-free.
     *
     * Deadlock-freedom here is guaranteed only ACROSS THE WRITE-OFF FAMILY (grouped +
     * single-lot), which share this (product_id, variant_id, location_id) → batch_id
     * acquisition order.
     *
     * TODO(deadlock): this order is NOT yet shared by every stock_levels writer. The
     * multi-line POS sale (App\Modules\Pos\...\ReceiptCreationService) locks
     * stock_levels rows in UNSORTED cart-line order and can therefore form a
     * cross-cycle with a concurrent grouped write-off. To be globally deadlock-free,
     * the POS path must sort its stock_levels lock acquisition by the SAME
     * (product_id, variant_id, location_id) key. That fix belongs in the POS module,
     * NOT here.
     *
     * @param  Collection<int, Batch>  $batches
     */
    private function acquireLocks(GroupedWriteOffData $data, Collection $batches, string $companyId): void
    {
        $plan = $this->planLockOrder($data, $batches);

        // FIRST: stock_levels (product-level aggregate rows, variant_id NULL),
        // product_ids ascending.
        foreach ($plan['stock_levels'] as $productId) {
            StockLevel::query()
                ->where('company_id', $companyId)
                ->where('product_id', $productId)
                ->where('location_id', $data->locationId)
                ->whereNull('variant_id')
                ->lockForUpdate()
                ->first();
        }

        // THEN: inventory_batch_stock rows, batch_ids ascending.
        foreach ($plan['batch_stock'] as $batchId) {
            BatchStock::query()
                ->where('batch_id', $batchId)
                ->where('location_id', $data->locationId)
                ->lockForUpdate()
                ->first();
        }
    }

    /**
     * Lot-level all-or-nothing guard. Sums the requested quantity per batch (a group
     * may list the same lot twice) and rejects the WHOLE group if any lot is short —
     * so no partial write-off is ever applied.
     *
     * @throws InsufficientBatchStockException
     */
    private function assertSufficientStock(GroupedWriteOffData $data, string $companyId): void
    {
        /** @var array<int, numeric-string> $requestedByBatch */
        $requestedByBatch = [];
        foreach ($data->lines as $line) {
            /** @var numeric-string $prev */
            $prev = $requestedByBatch[$line->batchId] ?? '0.0000';
            $requestedByBatch[$line->batchId] = bcadd($prev, $line->quantity, self::SCALE);
        }

        foreach ($requestedByBatch as $batchId => $requested) {
            $stock = BatchStock::query()
                ->where('batch_id', $batchId)
                ->where('location_id', $data->locationId)
                ->first();

            /** @var numeric-string $available */
            $available = $stock !== null
                ? bcsub((string) $stock->quantity, (string) $stock->reserved_quantity, self::SCALE)
                : '0.0000';

            if (bccomp($available, $requested, self::SCALE) < 0) {
                /** @var numeric-string $shortfall */
                $shortfall = bcsub($requested, $available, self::SCALE);
                throw new InsufficientBatchStockException(
                    shortfall: $shortfall,
                    message: "Insufficient batch stock for grouped write-off. Batch ID: {$batchId}, Available: {$available}, Requested: {$requested}",
                );
            }
        }
    }

    /**
     * Resolve and company-scope every referenced batch. A batch id that does not
     * belong to the current company is rejected (defense-in-depth against a forged
     * cross-company lot id reaching the service directly).
     *
     * @return Collection<int, Batch> Keyed by integer batch id.
     *
     * @throws \DomainException
     */
    private function resolveBatches(GroupedWriteOffData $data, string $companyId): Collection
    {
        /** @var list<int> $batchIds */
        $batchIds = collect($data->lines)
            ->map(static fn ($line): int => $line->batchId)
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, Batch> $batches */
        $batches = Batch::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $batchIds)
            ->get()
            ->keyBy(static fn (Batch $b): int => (int) $b->id);

        foreach ($batchIds as $batchId) {
            if (! $batches->has($batchId)) {
                throw new \DomainException("Batch {$batchId} not found for the current company.");
            }
        }

        return $batches;
    }

    private function findExisting(string $tenantId, string $idempotencyKey): ?GroupedWriteOff
    {
        return GroupedWriteOff::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Map a write-off MovementReason to the single-lot service's reason string.
     *
     * @throws \InvalidArgumentException When the reason is not a write-off reason.
     */
    private function mapReason(MovementReason $reason): string
    {
        return match ($reason) {
            MovementReason::Expiry => 'expiry',
            MovementReason::Damage => 'damage',
            MovementReason::WriteOff => 'other',
            default => throw new \InvalidArgumentException(
                "Reason '{$reason->value}' is not a valid write-off reason (expected expiry, damage or write_off)."
            ),
        };
    }
}
