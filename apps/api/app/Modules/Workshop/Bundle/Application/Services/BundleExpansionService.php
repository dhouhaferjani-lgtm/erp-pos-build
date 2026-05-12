<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Services;

use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\Contracts\ProductResolverInterface;
use App\Modules\Workshop\Bundle\Domain\Contracts\ServiceResolverInterface;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\Exceptions\BundleExpansionException;
use App\Modules\Workshop\Bundle\Domain\Exceptions\MixedVatInFixedBundleException;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Modules\Workshop\Bundle\Domain\ValueObjects\BundleExpansionLine;
use App\Modules\Workshop\Bundle\Domain\ValueObjects\ComponentServiceRef;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Collection;

/**
 * Snapshot-at-use materialization of a bundle into a flat list of
 * expansion lines. Pure projection — no DB writes, no events, no side
 * effects.
 *
 * Pricing modes:
 *   - Standard:    one priced line per component (qty * unit_price)
 *   - FixedBundle: one synthetic header line at base_price, plus
 *                  informational component lines for inventory +
 *                  labor attribution (is_optional=true,
 *                  is_from_fixed_bundle=true, line_total='0.xxx').
 *                  Rejects with {@see MixedVatInFixedBundleException}
 *                  when components span multiple VAT rates.
 *
 * Nested bundles are recursively flattened up to MAX_NESTING_DEPTH.
 */
final readonly class BundleExpansionService
{
    private const MAX_NESTING_DEPTH = 5;

    public function __construct(
        private BundleRepositoryInterface $bundles,
        private ProductResolverInterface $products,
        private ServiceResolverInterface $services,
    ) {}

    /**
     * Materialize a bundle for a WorkOrder at a given quantity.
     *
     * @param  string  $quantity  scaled decimal string; typically '1' at the
     *                            WO line level. Scales every child line.
     * @param  string|null  $vehicleId  Reserved for vehicle-specific expansion
     *                                  paths. Currently unused because Plan A.5
     *                                  only ships universal / vehicle-scoped
     *                                  applicability at the bundle header, not
     *                                  per-component. Must remain in the public
     *                                  signature so downstream callers
     *                                  (Work-Order module, POS flows) can begin
     *                                  passing the selected vehicle before the
     *                                  implementation lands.
     *
     * @todo Plan A.7 / Spec B: honor $vehicleId to filter applicability sets
     *                          and apply vehicle-specific override prices
     *                          (labor rate tiers, EV surcharges, …).
     *
     * @return Collection<int, BundleExpansionLine>
     */
    public function expandForWorkOrder(string $tenantId, string $companyId, string $bundleId, string $quantity, ?string $vehicleId): Collection
    {
        // Discard: see @todo on docblock. Referenced for static-analysis parity.
        unset($vehicleId);

        $bundle = $this->bundles->findWithComponentsAndApplicabilitiesForScope($tenantId, $companyId, $bundleId);
        if ($bundle === null) {
            throw BundleExpansionException::bundleNotFound($bundleId);
        }

        $scale = CurrencyScale::for($bundle->currency);
        $normalizedQty = CurrencyScale::bcformat($quantity, $scale);

        /** @var list<BundleExpansionLine> $lines */
        $lines = $this->expandBundle($tenantId, $companyId, $bundle, $normalizedQty, $scale, depth: 0);

        return new Collection($lines);
    }

    /**
     * Multiply two scaled decimal strings and truncate to `$scale` decimals.
     * Both operands are already numeric-strings from CurrencyScale.
     */
    private function multiplyScaled(string $a, string $b, int $scale): string
    {
        /** @var numeric-string $a */
        /** @var numeric-string $b */
        return CurrencyScale::bcformat(bcmul($a, $b, $scale + 3), $scale);
    }

    /**
     * @return list<BundleExpansionLine>
     */
    private function expandBundle(string $tenantId, string $companyId, ServiceBundle $bundle, string $quantity, int $scale, int $depth): array
    {
        if ($depth >= self::MAX_NESTING_DEPTH) {
            throw BundleExpansionException::maxDepthExceeded(self::MAX_NESTING_DEPTH);
        }

        if ($bundle->pricing_mode === BundlePricingMode::FixedBundle) {
            return $this->expandFixedBundle($tenantId, $companyId, $bundle, $quantity, $scale, $depth);
        }

        return $this->expandStandard($tenantId, $companyId, $bundle, $quantity, $scale, $depth);
    }

    /**
     * @return list<BundleExpansionLine>
     */
    private function expandStandard(string $tenantId, string $companyId, ServiceBundle $bundle, string $quantity, int $scale, int $depth): array
    {
        $lines = [];
        foreach ($bundle->components as $component) {
            foreach ($this->expandComponent($tenantId, $companyId, $component, $quantity, $scale, $depth) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<BundleExpansionLine>
     */
    private function expandFixedBundle(string $tenantId, string $companyId, ServiceBundle $bundle, string $quantity, int $scale, int $depth): array
    {
        $this->assertSingleVatRate($bundle);

        if ($bundle->base_price === null) {
            throw BundleExpansionException::missingComponent($bundle->id);
        }

        $basePrice = CurrencyScale::bcformat($bundle->base_price, $scale);
        $headerLineTotal = $this->multiplyScaled($basePrice, $quantity, $scale);

        $lines = [];
        $lines[] = new BundleExpansionLine(
            component_type: BundleComponentType::NestedBundle,
            component_id: null,
            display_name: $bundle->name,
            quantity: $quantity,
            unit: 'bundle',
            unit_price: $basePrice,
            line_total: $headerLineTotal,
            is_optional: false,
            is_from_fixed_bundle: false,
        );

        foreach ($bundle->components as $component) {
            foreach ($this->expandComponentInformational($tenantId, $companyId, $component, $quantity, $scale, $depth) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Emit priced lines for a standard-mode component. Nested bundles
     * recurse; parts and labor are one-shot lines.
     *
     * @return list<BundleExpansionLine>
     */
    private function expandComponent(string $tenantId, string $companyId, ServiceBundleComponent $component, string $quantity, int $scale, int $depth): array
    {
        $lineQty = $this->multiplyScaled(CurrencyScale::bcformat($component->quantity, $scale), $quantity, $scale);

        if ($component->component_type === BundleComponentType::NestedBundle && $component->nested_bundle_id !== null) {
            $nested = $this->bundles->findWithComponentsAndApplicabilitiesForScope($tenantId, $companyId, $component->nested_bundle_id);
            if ($nested === null) {
                throw BundleExpansionException::missingComponent($component->nested_bundle_id);
            }

            return $this->expandBundle($tenantId, $companyId, $nested, $lineQty, $scale, $depth + 1);
        }

        if ($component->component_type === BundleComponentType::Part && $component->product_id !== null) {
            $productRef = $this->products->findForBundleComponent($component->product_id);
            if ($productRef === null) {
                throw BundleExpansionException::missingComponent($component->product_id);
            }
            $unitPrice = $component->override_unit_price
                ?? ($productRef->sale_price ?? '0');
            $unitPrice = CurrencyScale::bcformat($unitPrice, $scale);
            $lineTotal = $this->multiplyScaled($unitPrice, $lineQty, $scale);

            return [new BundleExpansionLine(
                component_type: BundleComponentType::Part,
                component_id: $component->product_id,
                display_name: $productRef->display_name,
                quantity: $lineQty,
                unit: $productRef->unit,
                unit_price: $unitPrice,
                line_total: $lineTotal,
                is_optional: $component->is_optional,
                is_from_fixed_bundle: false,
            )];
        }

        if ($component->component_type === BundleComponentType::Labor && $component->service_id !== null) {
            $serviceRef = $this->services->findForBundleComponent($component->service_id);
            if ($serviceRef === null) {
                throw BundleExpansionException::missingComponent($component->service_id);
            }
            $unitPrice = $component->override_unit_price
                ?? $this->laborPrice($serviceRef);
            $unitPrice = CurrencyScale::bcformat($unitPrice, $scale);
            $lineTotal = $this->multiplyScaled($unitPrice, $lineQty, $scale);

            return [new BundleExpansionLine(
                component_type: BundleComponentType::Labor,
                component_id: $component->service_id,
                display_name: $serviceRef->display_name,
                quantity: $lineQty,
                unit: 'hour',
                unit_price: $unitPrice,
                line_total: $lineTotal,
                is_optional: $component->is_optional,
                is_from_fixed_bundle: false,
            )];
        }

        throw BundleExpansionException::missingComponent($component->id);
    }

    /**
     * Informational lines emitted for fixed_bundle children — no price
     * contribution but preserved for inventory allocation + labor hours.
     *
     * @return list<BundleExpansionLine>
     */
    private function expandComponentInformational(string $tenantId, string $companyId, ServiceBundleComponent $component, string $quantity, int $scale, int $depth): array
    {
        $lineQty = $this->multiplyScaled(CurrencyScale::bcformat($component->quantity, $scale), $quantity, $scale);
        $zero = CurrencyScale::bcformat('0', $scale);

        if ($component->component_type === BundleComponentType::NestedBundle && $component->nested_bundle_id !== null) {
            $nested = $this->bundles->findWithComponentsAndApplicabilitiesForScope($tenantId, $companyId, $component->nested_bundle_id);
            if ($nested === null) {
                throw BundleExpansionException::missingComponent($component->nested_bundle_id);
            }
            // Recursively collect informational lines from the nested
            // bundle's components at the next depth.
            $lines = [];
            foreach ($nested->components as $inner) {
                foreach ($this->expandComponentInformational($tenantId, $companyId, $inner, $lineQty, $scale, $depth + 1) as $l) {
                    $lines[] = $l;
                }
            }

            return $lines;
        }

        if ($component->component_type === BundleComponentType::Part && $component->product_id !== null) {
            $productRef = $this->products->findForBundleComponent($component->product_id);
            if ($productRef === null) {
                throw BundleExpansionException::missingComponent($component->product_id);
            }

            return [new BundleExpansionLine(
                component_type: BundleComponentType::Part,
                component_id: $component->product_id,
                display_name: $productRef->display_name,
                quantity: $lineQty,
                unit: $productRef->unit,
                unit_price: $zero,
                line_total: $zero,
                is_optional: true,
                is_from_fixed_bundle: true,
            )];
        }

        if ($component->component_type === BundleComponentType::Labor && $component->service_id !== null) {
            $serviceRef = $this->services->findForBundleComponent($component->service_id);
            if ($serviceRef === null) {
                throw BundleExpansionException::missingComponent($component->service_id);
            }

            return [new BundleExpansionLine(
                component_type: BundleComponentType::Labor,
                component_id: $component->service_id,
                display_name: $serviceRef->display_name,
                quantity: $lineQty,
                unit: 'hour',
                unit_price: $zero,
                line_total: $zero,
                is_optional: true,
                is_from_fixed_bundle: true,
            )];
        }

        throw BundleExpansionException::missingComponent($component->id);
    }

    private function laborPrice(ComponentServiceRef $ref): string
    {
        return $ref->pricing_type === PricingType::Hourly && $ref->hourly_rate !== null
            ? $ref->hourly_rate
            : $ref->base_price;
    }

    private function assertSingleVatRate(ServiceBundle $bundle): void
    {
        $rates = [];
        foreach ($bundle->components as $component) {
            $rate = $this->componentTaxRate($component);
            if ($rate !== null) {
                $rates[$rate] = true;
            }
        }

        if (count($rates) > 1) {
            throw MixedVatInFixedBundleException::forRates($bundle->id, array_keys($rates));
        }
    }

    private function componentTaxRate(ServiceBundleComponent $component): ?string
    {
        if ($component->component_type === BundleComponentType::Part && $component->product_id !== null) {
            $ref = $this->products->findForBundleComponent($component->product_id);

            return $ref?->tax_rate;
        }

        if ($component->component_type === BundleComponentType::Labor && $component->service_id !== null) {
            $ref = $this->services->findForBundleComponent($component->service_id);

            return $ref?->tax_rate;
        }

        // Nested bundles don't expose a single flat rate at this level;
        // their children contribute individually. Skip for this check.
        return null;
    }
}
