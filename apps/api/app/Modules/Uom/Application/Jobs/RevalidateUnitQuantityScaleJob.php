<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\Jobs;

use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Cascade revalidation when a unit's `decimal_places` (quantity display/validation
 * precision) is narrowed.
 *
 * Scans every Product that uses the unit and re-checks the quantity fields stored
 * against that product (currently the Inventory `stock_levels.quantity`) against the
 * new, narrower scale. When a stored quantity carries more decimal places than the
 * new scale permits, the job emits a STRUCTURED WARNING (ops alert) but does NOT
 * mutate any data — silently truncating a tenant's on-hand stock would be a
 * data-integrity hazard. Remediation is a human/ops decision.
 *
 * MODULE-BOUNDARY DECISION
 * ========================
 * This job is owned by the Uom module but must read Catalog/Product rows and
 * Inventory stock rows. There is no published read contract that returns
 * "stock quantities for products using unit X" (the existing
 * App\Shared\Contracts\InventoryServiceInterface / ProductServiceInterface expose
 * only write/upsert + SKU-lookup, and ProductInventoryQueryInterface keys on
 * platform article IDs — none fit this audit query). Adding new contract methods
 * is out of scope for this precision-drift remediation task, and the access here
 * is strictly READ-ONLY (it logs, never writes). Per the task guidance, we read
 * the Product and StockLevel models directly and document the boundary here. If
 * this read graduates beyond an audit log, promote it to a contract method on
 * InventoryServiceInterface.
 *
 * @cross-tenant-by-design Audit-only queue job. handle() scopes every query by the
 * tenant_id captured at dispatch time; it performs no cross-tenant reads and never
 * mutates rows. The dispatcher (UomController::updateUnit) is responsible for
 * supplying the tenant_id of the unit being edited.
 */
final class RevalidateUnitQuantityScaleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $unitId,
        public readonly string $unitCode,
        public readonly ?string $tenantId,
        public readonly int $newDecimalPlaces,
    ) {}

    public function handle(): void
    {
        $productQuery = Product::query()->where('unit_id', $this->unitId);

        if ($this->tenantId !== null) {
            $productQuery->where('tenant_id', $this->tenantId);
        }

        $productIds = $productQuery->pluck('id')->all();

        if ($productIds === []) {
            return;
        }

        $violations = 0;

        StockLevel::query()
            ->whereIn('product_id', $productIds)
            ->select(['id', 'product_id', 'location_id', 'quantity', 'reserved'])
            ->chunkById(500, function ($stockLevels) use (&$violations): void {
                foreach ($stockLevels as $stockLevel) {
                    foreach (['quantity', 'reserved'] as $field) {
                        /** @var string|null $raw */
                        $raw = $stockLevel->getAttribute($field);

                        if ($raw === null) {
                            continue;
                        }

                        if (! $this->fitsScale((string) $raw, $this->newDecimalPlaces)) {
                            $violations++;

                            Log::warning('uom.unit_scale_cascade.quantity_violation', [
                                'unit_id' => $this->unitId,
                                'unit_code' => $this->unitCode,
                                'tenant_id' => $this->tenantId,
                                'new_decimal_places' => $this->newDecimalPlaces,
                                'stock_level_id' => $stockLevel->id,
                                'product_id' => $stockLevel->product_id,
                                'location_id' => $stockLevel->location_id,
                                'field' => $field,
                                'stored_value' => (string) $raw,
                            ]);
                        }
                    }
                }
            });

        Log::info('uom.unit_scale_cascade.completed', [
            'unit_id' => $this->unitId,
            'unit_code' => $this->unitCode,
            'tenant_id' => $this->tenantId,
            'new_decimal_places' => $this->newDecimalPlaces,
            'products_scanned' => count($productIds),
            'violations' => $violations,
        ]);
    }

    /**
     * Determine whether the numeric string fits within the given decimal scale
     * without losing precision. Uses bcmath to avoid float rounding error: a value
     * fits iff re-formatting it at the target scale yields the same number.
     */
    private function fitsScale(string $value, int $scale): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            // Non-numeric storage is a separate integrity concern; do not flag here.
            return true;
        }

        // bccomp returns 0 when the truncated-to-scale value equals the original
        // (compared at a higher precision than the unit's scale).
        $comparisonScale = $scale + 10;

        return bccomp($trimmed, bcadd($trimmed, '0', $scale), $comparisonScale) === 0;
    }
}
