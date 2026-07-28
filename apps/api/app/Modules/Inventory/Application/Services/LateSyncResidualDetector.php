<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryScale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read-only attribution of POS sales that arrived after a physical count's
 * stock correction applied even though their device event time precedes it.
 *
 * Such a sale was already absent from the shelf quantity the counter entered,
 * but its later stock-movement insert subtracts it from the corrected level a
 * second time. Detection deliberately does not mutate stock: an operator may
 * already have repaired the variance, so automatic reversal is unsafe.
 */
final class LateSyncResidualDetector
{
    /**
     * @return list<array{
     *     movement_id: string,
     *     counting_id: string,
     *     counting_item_id: string,
     *     product_id: string,
     *     variant_id: string|null,
     *     location_id: string,
     *     product: array{id: string, name: string, sku: string, quantity_decimals: int},
     *     location: array{id: string, name: string, code: string|null},
     *     occurred_at: string,
     *     arrived_at: string,
     *     quantity: numeric-string
     * }>
     */
    public function forCounting(InventoryCounting $counting): array
    {
        if ($counting->status !== CountingStatus::Finalized || $counting->finalized_at === null) {
            return [];
        }

        /** @var list<stdClass> $candidates */
        $candidates = DB::table('stock_movements as movement')
            ->join('inventory_counting_items as item', function ($join): void {
                $join->on('item.product_id', '=', 'movement.product_id')
                    ->on('item.location_id', '=', 'movement.location_id')
                    ->where(function ($variant): void {
                        $variant->whereColumn('item.variant_id', 'movement.variant_id')
                            ->orWhere(function ($baseProduct): void {
                                $baseProduct->whereNull('item.variant_id')
                                    ->whereNull('movement.variant_id');
                            });
                    });
            })
            ->join('inventory_countings as counting', 'counting.id', '=', 'item.counting_id')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->leftJoin('units as unit', 'unit.id', '=', 'product.unit_id')
            ->join('locations as location', 'location.id', '=', 'movement.location_id')
            ->where('counting.company_id', $counting->company_id)
            ->where('movement.company_id', $counting->company_id)
            ->where('counting.status', CountingStatus::Finalized->value)
            ->whereNotNull('counting.finalized_at')
            // Legacy delta-path items have neither a trustworthy count
            // boundary nor a successful apply marker, so they are explicitly
            // outside this detector's analyzable replay-era population.
            ->whereNotNull('item.final_qty_as_of')
            ->whereNotNull('item.replay_audit')
            ->where('movement.reason', MovementReason::POSSale->value)
            ->whereRaw('COALESCE(movement.occurred_at, movement.created_at) <= item.final_qty_as_of')
            ->whereExists(function ($query) use ($counting): void {
                $query->selectRaw('1')
                    ->from('inventory_counting_items as target_item')
                    ->where('target_item.counting_id', $counting->id)
                    ->whereColumn('target_item.product_id', 'movement.product_id')
                    ->whereColumn('target_item.location_id', 'movement.location_id')
                    ->where(function ($variant): void {
                        $variant->whereColumn('target_item.variant_id', 'movement.variant_id')
                            ->orWhere(function ($baseProduct): void {
                                $baseProduct->whereNull('target_item.variant_id')
                                    ->whereNull('movement.variant_id');
                            });
                    });
            })
            ->select([
                'movement.id as movement_id',
                'movement.product_id',
                'movement.variant_id',
                'movement.location_id',
                DB::raw('CAST(movement.quantity AS TEXT) as quantity'),
                'movement.occurred_at',
                'movement.created_at as arrived_at',
                'item.id as counting_item_id',
                'item.replay_audit',
                'item.flag_reasons',
                'counting.id as counting_id',
                'counting.finalized_at',
                'product.name as product_name',
                'product.sku as product_sku',
                'unit.decimal_places as quantity_decimals',
                'location.name as location_name',
                'location.code as location_code',
            ])
            ->orderBy('movement.id')
            ->orderByDesc('counting.finalized_at')
            ->orderByDesc('counting.id')
            ->get()
            ->all();

        $attributedMovementIds = [];
        $residuals = [];

        foreach ($candidates as $candidate) {
            $movementId = (string) $candidate->movement_id;
            if (isset($attributedMovementIds[$movementId])) {
                continue;
            }

            if (! is_string($candidate->replay_audit)) {
                continue;
            }
            if (is_string($candidate->flag_reasons) && $this->hasApplyBlockingReason($candidate->flag_reasons)) {
                continue;
            }
            $replayAudit = json_decode($candidate->replay_audit, true);
            if (! is_array($replayAudit) || ! is_string($replayAudit['windowTo'] ?? null)) {
                continue;
            }

            // The newest successfully-applied count supersedes every older
            // count for this movement. Consume it before testing windowTo: if
            // that newest apply absorbed the movement, it must not fall
            // through and appear as a residual on an older count.
            $attributedMovementIds[$movementId] = true;
            if (Carbon::parse((string) $candidate->arrived_at)->lte(Carbon::parse($replayAudit['windowTo']))) {
                continue;
            }

            // The most recently finalized qualifying count is the stock level
            // the delayed sale actually reduced. Older qualifying counts have
            // since been superseded and must not also claim the same movement.
            if ((string) $candidate->counting_id !== $counting->id) {
                continue;
            }

            if (! is_string($candidate->quantity) && ! is_int($candidate->quantity)) {
                throw new \UnexpectedValueException('Late-sync residual quantity must be numeric.');
            }
            $movementQuantity = $this->numericString($candidate->quantity, 'quantity');
            /** @var numeric-string $quantity */
            $quantity = bccomp($movementQuantity, '0', InventoryScale::QUANTITY_SCALE) < 0
                ? bcmul($movementQuantity, '-1', InventoryScale::QUANTITY_SCALE)
                : bcadd($movementQuantity, '0', InventoryScale::QUANTITY_SCALE);

            $eventTime = $candidate->occurred_at ?? $candidate->arrived_at;

            $residuals[] = [
                'movement_id' => $movementId,
                'counting_id' => (string) $candidate->counting_id,
                'counting_item_id' => (string) $candidate->counting_item_id,
                'product_id' => (string) $candidate->product_id,
                'variant_id' => $candidate->variant_id !== null ? (string) $candidate->variant_id : null,
                'location_id' => (string) $candidate->location_id,
                'product' => [
                    'id' => (string) $candidate->product_id,
                    'name' => (string) $candidate->product_name,
                    'sku' => (string) $candidate->product_sku,
                    'quantity_decimals' => $candidate->quantity_decimals !== null
                        ? (int) $candidate->quantity_decimals
                        : InventoryScale::QUANTITY_SCALE,
                ],
                'location' => [
                    'id' => (string) $candidate->location_id,
                    'name' => (string) $candidate->location_name,
                    'code' => $candidate->location_code !== null ? (string) $candidate->location_code : null,
                ],
                'occurred_at' => Carbon::parse((string) $eventTime)->toIso8601String(),
                'arrived_at' => Carbon::parse((string) $candidate->arrived_at)->toIso8601String(),
                'quantity' => $quantity,
            ];
        }

        return $residuals;
    }

    /** @return numeric-string */
    private function numericString(string|int $value, string $field): string
    {
        if (! is_numeric($value)) {
            throw new \UnexpectedValueException("Late-sync residual {$field} must be numeric.");
        }

        return (string) $value;
    }

    private function hasApplyBlockingReason(string $rawFlagReasons): bool
    {
        $flagReasons = json_decode($rawFlagReasons, true);
        if (! is_array($flagReasons)) {
            return false;
        }

        foreach ($flagReasons as $flagReason) {
            if (! is_string($flagReason)) {
                continue;
            }

            if (CountingItemFlagReason::tryFrom($flagReason)?->blocksStockApplication() === true) {
                return true;
            }
        }

        return false;
    }
}
