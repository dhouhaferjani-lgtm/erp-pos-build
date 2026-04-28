<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure\Adapters;

use App\Modules\Workshop\Bundle\Application\Services\BundleExpansionService;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ValueObjects\BundleExpansionLine;
use Illuminate\Support\Collection;

/**
 * Thin adapter wrapping BundleExpansionService. Keeps Application-layer code
 * inside Workshop\WorkOrder from depending directly on the sibling Bundle
 * module — it talks only to this adapter.
 *
 * No behavior added here; the adapter's job is just to pin the public contract
 * between the two submodules (signature + return type) so Workshop-WorkOrder
 * tests can mock one collaborator rather than the whole Bundle aggregate.
 */
final readonly class BundleExpansionAdapter
{
    public function __construct(
        private BundleExpansionService $expansion,
    ) {}

    /**
     * @return Collection<int, BundleExpansionLine>
     */
    public function expand(string $bundleId, string $quantity, ?string $vehicleId): Collection
    {
        return $this->expansion->expandForWorkOrder($bundleId, $quantity, $vehicleId);
    }

    /**
     * Look up whether a bundle is fixed-price. Used by WorkOrderBundleService
     * to decide header-line creation.
     */
    public function pricingModeOf(string $bundleId): ?BundlePricingMode
    {
        $bundle = ServiceBundle::query()->find($bundleId);

        return $bundle?->pricing_mode;
    }

    public function bundleName(string $bundleId): ?string
    {
        $bundle = ServiceBundle::query()->find($bundleId);

        return $bundle?->name;
    }
}
