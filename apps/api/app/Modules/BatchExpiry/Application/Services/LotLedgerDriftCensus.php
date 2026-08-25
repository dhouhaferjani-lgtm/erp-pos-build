<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\Services;

use App\Shared\Domain\QuantityScale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY census of the one invariant the lot ledger has to hold:
 * `Σ inventory_batch_stock` == `stock_levels.quantity`, per (product, variant,
 * location), for every batch-tracked tuple of a company.
 *
 * 🚨 **Why this exists as its own service (W4R-2 gate r1 F-4 / fiscal F-5).**
 * The query already existed, but only inside
 * `App\Console\Commands\RepairPhantomDefaultBatchesCommand` — a
 * MAINTENANCE-WINDOW repair tool named after the W2-7 phantom-`DEFAULT` defect,
 * which refuses to run without an explicit tenant AND exactly one of
 * `--dry-run` / `--execute`. Nobody reaches for that when they want to know
 * whether the lot ledger is telling the truth, so in practice the invariant had
 * no detector at all.
 *
 * That matters most for the drift this codebase creates DELIBERATELY. A POS
 * sale is projected from a SEALED fiscal event, and a projector may never
 * reject a signed event, so when the lot arm cannot fully draw
 * (`POS\Application\Projections\PosCoreReceiptProjection::consumeLotsForSaleLine()`)
 * the sale is committed with a lot shortfall and the tuple is left drifted. The
 * FEFO candidate predicate is narrower than the aggregate's in five independent
 * ways — expired, recalled and inactive lots are invisible,
 * `available_quantity` nets reservations, and `FOR UPDATE … SKIP LOCKED` means
 * even TRANSIENT lock contention converts into PERMANENT drift, because a
 * sealed sale is never retried. Each of those is the right call at the moment
 * it is made and none of them is discoverable from a `Log::warning`.
 *
 * The census is deliberately STATE-based, not event-based: it compares two sums
 * as they stand. So it reports a shortfall, a contained lot-arm failure, a
 * pre-lane sale that never wrote a lot leg and a hand-edited row identically,
 * without any of them having to remember to file a record.
 *
 * Sign convention, matching the repair command's: POSITIVE drift means the lot
 * ledger OVERSTATES on-hand (the W2-7 / W4-5 / POS-shortfall direction);
 * NEGATIVE means it understates (inbound stock that never got a lot leg).
 */
final class LotLedgerDriftCensus
{
    private const int SCALE = 4;

    /**
     * The `DEFAULT`-lot batch number, restated here rather than imported from
     * `BatchStockService` so this read-only census carries no dependency on the
     * write path it is auditing.
     */
    public const string DEFAULT_BATCH_NUMBER = 'DEFAULT';

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
    public function batchTrackedTuples(string $companyId): Collection
    {
        /** @var Collection<int, \stdClass> $rows */
        $rows = DB::table('stock_levels as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->leftJoin('product_batches as pb', function ($join): void {
                $join->on('pb.product_id', '=', 'sl.product_id')
                    ->whereRaw('pb.variant_id IS NOT DISTINCT FROM sl.variant_id');
            })
            ->leftJoin('inventory_batch_stock as ibs', function ($join): void {
                $join->on('ibs.batch_id', '=', 'pb.id')
                    ->on('ibs.location_id', '=', 'sl.location_id');
            })
            ->where('sl.company_id', $companyId)
            ->where('p.requires_batch_tracking', true)
            ->groupBy('sl.product_id', 'sl.variant_id', 'sl.location_id', 'sl.quantity')
            ->orderBy('sl.product_id')
            ->select([
                'sl.product_id as product_id',
                'sl.variant_id as variant_id',
                'sl.location_id as location_id',
                'sl.quantity as aggregate',
                DB::raw('COALESCE(SUM(ibs.quantity), 0) as lot_total'),
            ])
            ->get();

        return $rows;
    }

    /**
     * Only the tuples whose two sums disagree, each carrying its signed drift.
     *
     * @return list<array{product_id: string, variant_id: ?string, location_id: string, aggregate: numeric-string, lot_total: numeric-string, drift: numeric-string}>
     */
    public function driftedTuples(string $companyId): array
    {
        $drifted = [];

        foreach ($this->batchTrackedTuples($companyId) as $row) {
            $lotTotal = $this->decimal($row->lot_total);
            $aggregate = $this->decimal($row->aggregate);

            /** @var numeric-string $drift */
            $drift = bcsub($lotTotal, $aggregate, self::SCALE);

            if (bccomp($drift, '0', self::SCALE) === 0) {
                continue;
            }

            $drifted[] = [
                'product_id' => (string) $row->product_id,
                'variant_id' => is_string($row->variant_id) ? $row->variant_id : null,
                'location_id' => (string) $row->location_id,
                'aggregate' => $aggregate,
                'lot_total' => $lotTotal,
                'drift' => $drift,
            ];
        }

        return $drifted;
    }

    /**
     * Normalise a driver-returned quantity to a scale-4 decimal string.
     *
     * PostgreSQL hands back `numeric` as `10.0000` and SQLite the same stored
     * value as `10`, so the raw column value is not comparable across drivers.
     * A value that is not a plain decimal at all (null from a LEFT JOIN miss, or
     * anything a future driver invents) reads as zero rather than poisoning
     * `bcsub` — a census must never fatal on the data it is auditing.
     *
     * @return numeric-string
     */
    private function decimal(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return QuantityScale::round('0', self::SCALE, QuantityScale::FLOOR);
        }

        $raw = trim((string) $value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $raw) !== 1) {
            return QuantityScale::round('0', self::SCALE, QuantityScale::FLOOR);
        }

        return QuantityScale::round($raw, self::SCALE, QuantityScale::FLOOR);
    }
}
