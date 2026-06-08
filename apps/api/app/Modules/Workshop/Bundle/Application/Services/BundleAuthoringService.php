<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Services;

use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use App\Modules\Workshop\Bundle\Application\Commands\AddComponentCommand;
use App\Modules\Workshop\Bundle\Application\Commands\CreateBundleCommand;
use App\Modules\Workshop\Bundle\Application\Commands\DeactivateBundleCommand;
use App\Modules\Workshop\Bundle\Application\Commands\RemoveComponentCommand;
use App\Modules\Workshop\Bundle\Application\Commands\SetVehicleApplicabilitiesCommand;
use App\Modules\Workshop\Bundle\Application\Commands\UpdateBundleCommand;
use App\Modules\Workshop\Bundle\Application\Commands\UpdateComponentCommand;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\Exceptions\BundleCycleException;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates bundle authoring: create / update / deactivate, plus
 * component add/remove and full applicability replacement.
 *
 * All monetary inputs are scaled via CurrencyScale::bcformat to avoid
 * float precision loss. Cycle detection is enforced at the application
 * layer before insertion via iterative BFS over `nested_bundle_id`.
 */
final readonly class BundleAuthoringService
{
    private const MAX_NESTING_DEPTH = 5;

    public function __construct(
        private BundleRepositoryInterface $bundles,
    ) {}

    public function create(CreateBundleCommand $command): ServiceBundle
    {
        $this->assertValidCurrency($command->currency);
        $this->assertPricingModeInvariant($command->pricing_mode, $command->base_price);

        $scale = CurrencyScale::for($command->currency);

        $bundle = new ServiceBundle;
        $bundle->tenant_id = $command->tenant_id;
        $bundle->company_id = $command->company_id;
        $bundle->code = $command->code;
        $bundle->name = $command->name;
        $bundle->description = $command->description;
        $bundle->pricing_mode = $command->pricing_mode;
        $bundle->base_price = $command->base_price !== null
            ? CurrencyScale::bcformat($command->base_price, $scale)
            : null;
        $bundle->currency = strtoupper($command->currency);
        $bundle->tax_rate = $command->tax_rate !== null
            ? CurrencyScale::bcformat($command->tax_rate, 3)
            : null;
        $bundle->estimated_labor_hours = $command->estimated_labor_hours !== null
            ? CurrencyScale::bcformat($command->estimated_labor_hours, 2)
            : null;
        $bundle->service_interval_km = $command->service_interval_km;
        $bundle->service_interval_months = $command->service_interval_months;
        $bundle->is_active = true;

        return $this->bundles->save($bundle);
    }

    public function update(UpdateBundleCommand $command): ServiceBundle
    {
        $bundle = $this->bundles->findByIdForScope($command->tenant_id, $command->company_id, $command->bundle_id);
        if ($bundle === null) {
            throw new InvalidArgumentException('Bundle not found: '.$command->bundle_id);
        }

        if ($command->currency !== null) {
            $this->assertValidCurrency($command->currency);
            $bundle->currency = strtoupper($command->currency);
        }

        $pricingMode = $command->pricing_mode ?? $bundle->pricing_mode;
        $basePrice = $command->base_price ?? $bundle->base_price;
        $this->assertPricingModeInvariant($pricingMode, $basePrice);

        if ($command->name !== null) {
            $bundle->name = $command->name;
        }
        if ($command->description !== null) {
            $bundle->description = $command->description;
        }
        if ($command->pricing_mode !== null) {
            $bundle->pricing_mode = $command->pricing_mode;
        }

        $scale = CurrencyScale::for($bundle->currency);

        if ($command->base_price !== null) {
            $bundle->base_price = CurrencyScale::bcformat($command->base_price, $scale);
        }
        if ($command->tax_rate !== null) {
            $bundle->tax_rate = CurrencyScale::bcformat($command->tax_rate, 3);
        }
        if ($command->estimated_labor_hours !== null) {
            $bundle->estimated_labor_hours = CurrencyScale::bcformat($command->estimated_labor_hours, 2);
        }
        if ($command->service_interval_km !== null) {
            $bundle->service_interval_km = $command->service_interval_km;
        }
        if ($command->service_interval_months !== null) {
            $bundle->service_interval_months = $command->service_interval_months;
        }
        if ($command->is_active !== null) {
            $bundle->is_active = $command->is_active;
        }

        return $this->bundles->save($bundle);
    }

    public function deactivate(DeactivateBundleCommand $command): ServiceBundle
    {
        $bundle = $this->bundles->findByIdForScope($command->tenant_id, $command->company_id, $command->bundle_id);
        if ($bundle === null) {
            throw new InvalidArgumentException('Bundle not found: '.$command->bundle_id);
        }

        $bundle->is_active = false;

        return $this->bundles->save($bundle);
    }

    public function addComponent(AddComponentCommand $command): ServiceBundleComponent
    {
        $bundle = $this->bundles->findByIdForScope($command->tenant_id, $command->company_id, $command->bundle_id);
        if ($bundle === null) {
            throw new InvalidArgumentException('Bundle not found: '.$command->bundle_id);
        }

        $this->assertQuantityPositive($command->quantity);

        if ($command->component_type === BundleComponentType::NestedBundle) {
            $this->assertNoCycle($command->tenant_id, $command->company_id, $command->bundle_id, $command->component_id);
        }

        $scale = CurrencyScale::for($bundle->currency);

        $component = new ServiceBundleComponent;
        $component->tenant_id = $bundle->tenant_id;
        $component->bundle_id = $bundle->id;
        $component->component_type = $command->component_type;

        $component->product_id = $command->component_type === BundleComponentType::Part
            ? $command->component_id : null;
        $component->service_id = $command->component_type === BundleComponentType::Labor
            ? $command->component_id : null;
        $component->nested_bundle_id = $command->component_type === BundleComponentType::NestedBundle
            ? $command->component_id : null;

        $component->quantity = CurrencyScale::bcformat($command->quantity, 4);
        $component->unit_id = $command->unit_id;
        $component->override_unit_price = $command->override_unit_price !== null
            ? CurrencyScale::bcformat($command->override_unit_price, $scale)
            : null;
        $component->is_optional = $command->is_optional;
        $component->display_order = $command->display_order;
        $component->notes = $command->notes;

        $component->save();

        return $component;
    }

    /**
     * Patch-style partial update of an existing component. Only fields
     * explicitly supplied are touched. When `component_type` is supplied,
     * `new_component_reference_id` is required and the FK triplet is
     * rewritten; cycle detection runs for `NestedBundle`.
     */
    public function updateComponent(UpdateComponentCommand $command): ServiceBundleComponent
    {
        $bundle = $this->bundles->findByIdForScope($command->tenant_id, $command->company_id, $command->bundle_id);
        if ($bundle === null) {
            throw new InvalidArgumentException('Bundle not found: '.$command->bundle_id);
        }

        $component = ServiceBundleComponent::query()
            ->where('bundle_id', $command->bundle_id)
            ->where('id', $command->component_id)
            ->first();

        if ($component === null) {
            throw new InvalidArgumentException('Component not found: '.$command->component_id);
        }

        if ($command->component_type !== null) {
            if ($command->new_component_reference_id === null) {
                throw new InvalidArgumentException(
                    'When component_type is updated, the matching reference id must be supplied.',
                );
            }

            if ($command->component_type === BundleComponentType::NestedBundle) {
                $this->assertNoCycle($command->tenant_id, $command->company_id, $command->bundle_id, $command->new_component_reference_id);
            }

            $component->component_type = $command->component_type;
            $component->product_id = $command->component_type === BundleComponentType::Part
                ? $command->new_component_reference_id : null;
            $component->service_id = $command->component_type === BundleComponentType::Labor
                ? $command->new_component_reference_id : null;
            $component->nested_bundle_id = $command->component_type === BundleComponentType::NestedBundle
                ? $command->new_component_reference_id : null;
        }

        if ($command->quantity !== null) {
            $this->assertQuantityPositive($command->quantity);
            $component->quantity = CurrencyScale::bcformat($command->quantity, 4);
        }

        if ($command->unit_id !== null) {
            $component->unit_id = $command->unit_id;
        }

        if ($command->override_unit_price_provided) {
            $component->override_unit_price = $command->override_unit_price === null
                ? null
                : CurrencyScale::bcformat($command->override_unit_price, CurrencyScale::for($bundle->currency));
        }

        if ($command->is_optional !== null) {
            $component->is_optional = $command->is_optional;
        }

        if ($command->display_order !== null) {
            $component->display_order = $command->display_order;
        }

        if ($command->notes_provided) {
            $component->notes = $command->notes;
        }

        $component->save();

        return $component;
    }

    public function removeComponent(RemoveComponentCommand $command): void
    {
        $bundle = $this->bundles->findByIdForScope($command->tenant_id, $command->company_id, $command->bundle_id);
        if ($bundle === null) {
            throw new InvalidArgumentException('Bundle not found: '.$command->bundle_id);
        }

        $component = ServiceBundleComponent::query()
            ->where('tenant_id', $command->tenant_id)
            ->where('bundle_id', $bundle->id)
            ->where('id', $command->component_id)
            ->first();

        if ($component === null) {
            throw new InvalidArgumentException('Component not found: '.$command->component_id);
        }

        $component->delete();
    }

    /**
     * Replace the full set of vehicle applicabilities for a bundle.
     */
    public function setVehicleApplicabilities(SetVehicleApplicabilitiesCommand $command): void
    {
        $bundle = $this->bundles->findByIdForScope($command->tenant_id, $command->company_id, $command->bundle_id);
        if ($bundle === null) {
            throw new InvalidArgumentException('Bundle not found: '.$command->bundle_id);
        }

        foreach ($command->applicabilities as $row) {
            $this->assertApplicabilityShape($row);
        }

        DB::transaction(function () use ($bundle, $command): void {
            ServiceBundleVehicleApplicability::query()->where('bundle_id', $bundle->id)->delete();

            foreach ($command->applicabilities as $row) {
                $a = new ServiceBundleVehicleApplicability;
                $a->tenant_id = $bundle->tenant_id;
                $a->bundle_id = $bundle->id;
                $a->platform_vehicle_id = $row['platform_vehicle_id'];
                $a->vehicle_type = $row['vehicle_type'] !== null
                    ? VehicleTypeRef::from($row['vehicle_type'])
                    : null;
                $a->vehicle_display = $row['vehicle_display'];
                $a->year_from = $row['year_from'];
                $a->year_to = $row['year_to'];
                $a->save();
            }
        });
    }

    private function assertValidCurrency(string $currency): void
    {
        if (strlen($currency) !== 3 || ! ctype_alpha($currency)) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO 4217 code: got '.$currency);
        }
    }

    private function assertPricingModeInvariant(BundlePricingMode $mode, ?string $basePrice): void
    {
        if ($mode === BundlePricingMode::FixedBundle && ($basePrice === null || $basePrice === '')) {
            throw new InvalidArgumentException('fixed_bundle pricing requires a base_price.');
        }
    }

    private function assertQuantityPositive(string $quantity): void
    {
        $normalized = CurrencyScale::bcformat($quantity, 6);
        if (bccomp($normalized, '0', 6) <= 0) {
            throw new InvalidArgumentException('Component quantity must be > 0, got '.$quantity);
        }
    }

    /**
     * BFS over `nested_bundle_id` starting from `$candidateChild`. If
     * `$bundleId` is reachable, adding the child would create a cycle.
     */
    private function assertNoCycle(string $tenantId, string $companyId, string $bundleId, string $candidateChild): void
    {
        if ($bundleId === $candidateChild) {
            throw BundleCycleException::between($bundleId, $candidateChild);
        }

        $visited = [];
        $queue = [$candidateChild];
        $depth = 0;

        while ($queue !== []) {
            $depth++;
            if ($depth > self::MAX_NESTING_DEPTH) {
                throw new InvalidArgumentException(
                    sprintf('Bundle nesting exceeds max depth of %d.', self::MAX_NESTING_DEPTH),
                );
            }

            $next = [];
            foreach ($queue as $currentId) {
                if (isset($visited[$currentId])) {
                    continue;
                }
                $visited[$currentId] = true;

                if ($currentId === $bundleId) {
                    throw BundleCycleException::between($bundleId, $candidateChild);
                }

                $childBundle = $this->bundles->findByIdForScope($tenantId, $companyId, $currentId);
                if ($childBundle === null) {
                    throw new InvalidArgumentException('Bundle not found: '.$currentId);
                }

                $childIds = ServiceBundleComponent::query()
                    ->where('tenant_id', $tenantId)
                    ->where('bundle_id', $childBundle->id)
                    ->whereNotNull('nested_bundle_id')
                    ->pluck('nested_bundle_id')
                    ->all();

                foreach ($childIds as $childId) {
                    if (! isset($visited[(string) $childId])) {
                        $next[] = (string) $childId;
                    }
                }
            }
            $queue = $next;
        }
    }

    /**
     * @param  array{platform_vehicle_id: ?string, vehicle_type: ?string, vehicle_display: ?string, year_from: ?int, year_to: ?int}  $row
     */
    private function assertApplicabilityShape(array $row): void
    {
        $platformVehicleId = $row['platform_vehicle_id'];
        $vehicleType = $row['vehicle_type'];

        if (($platformVehicleId === null) !== ($vehicleType === null)) {
            throw new InvalidArgumentException(
                'Vehicle applicability must be fully universal (both null) or fully scoped (both non-null).',
            );
        }
    }
}
