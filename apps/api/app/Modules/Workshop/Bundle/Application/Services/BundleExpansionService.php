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
use App\Shared\Domain\QuantityScale;
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

        $moneyScale = CurrencyScale::for($bundle->currency);
        $normalizedQty = QuantityScale::round($quantity, QuantityScale::SCALE, QuantityScale::HALF_UP);

        /** @var list<BundleExpansionLine> $lines */
        $lines = $this->expandBundle($tenantId, $companyId, $bundle, $normalizedQty, $moneyScale, depth: 0);

        return new Collection($lines);
    }

    /**
     * Multiply a money amount by a quantity and truncate to currency precision.
     */
    private function multiplyMoney(string $money, string $quantity, int $moneyScale): string
    {
        /** @var numeric-string $money */
        /** @var numeric-string $quantity */
        return CurrencyScale::bcformat(
            bcmul($money, $quantity, $moneyScale + QuantityScale::SCALE + 2),
            $moneyScale,
        );
    }

    /**
     * Multiply quantities with full canonical headroom, then round once at the
     * destination unit boundary.
     */
    private function multiplyQuantity(
        string $componentQuantity,
        string $bundleQuantity,
        int $decimalPlaces,
        ?string $roundingMethod = null,
    ): string {
        /** @var numeric-string $componentQuantity */
        /** @var numeric-string $bundleQuantity */
        /** @var numeric-string $product */
        $product = bcmul($componentQuantity, $bundleQuantity, QuantityScale::SCALE * 2);

        return QuantityScale::formatForUnit($product, $decimalPlaces, $roundingMethod);
    }

    /**
     * @return list<BundleExpansionLine>
     */
    private function expandBundle(string $tenantId, string $companyId, ServiceBundle $bundle, string $quantity, int $moneyScale, int $depth): array
    {
        if ($depth >= self::MAX_NESTING_DEPTH) {
            throw BundleExpansionException::maxDepthExceeded(self::MAX_NESTING_DEPTH);
        }

        if ($bundle->pricing_mode === BundlePricingMode::FixedBundle) {
            return $this->expandFixedBundle($tenantId, $companyId, $bundle, $quantity, $moneyScale, $depth);
        }

        return $this->expandStandard($tenantId, $companyId, $bundle, $quantity, $moneyScale, $depth);
    }

    /**
     * @return list<BundleExpansionLine>
     */
    private function expandStandard(string $tenantId, string $companyId, ServiceBundle $bundle, string $quantity, int $moneyScale, int $depth): array
    {
        $lines = [];
        foreach ($bundle->components as $component) {
            foreach ($this->expandComponent($tenantId, $companyId, $component, $quantity, $moneyScale, $depth) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<BundleExpansionLine>
     */
    private function expandFixedBundle(string $tenantId, string $companyId, ServiceBundle $bundle, string $quantity, int $moneyScale, int $depth): array
    {
        $this->assertSingleVatRate($bundle);

        if ($bundle->base_price === null) {
            throw BundleExpansionException::missingComponent($bundle->id);
        }

        $basePrice = CurrencyScale::bcformat($bundle->base_price, $moneyScale);
        $headerLineTotal = $this->multiplyMoney($basePrice, $quantity, $moneyScale);

        $lines = [];
        $lines[] = new BundleExpansionLine(
            component_type: BundleComponentType::NestedBundle,
            component_id: null,
            display_name: $bundle->name,
            quantity: $quantity,
            quantity_decimals: QuantityScale::SCALE,
            unit: 'bundle',
            unit_price: $basePrice,
            line_total: $headerLineTotal,
            is_optional: false,
            is_from_fixed_bundle: false,
        );

        foreach ($bundle->components as $component) {
            foreach ($this->expandComponentInformational($tenantId, $companyId, $component, $quantity, $moneyScale, $depth) as $line) {
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
    private function expandComponent(string $tenantId, string $companyId, ServiceBundleComponent $component, string $quantity, int $moneyScale, int $depth): array
    {
        if ($component->component_type === BundleComponentType::NestedBundle && $component->nested_bundle_id !== null) {
            $nested = $this->bundles->findWithComponentsAndApplicabilitiesForScope($tenantId, $companyId, $component->nested_bundle_id);
            if ($nested === null) {
                throw BundleExpansionException::missingComponent($component->nested_bundle_id);
            }
            $lineQty = $this->multiplyQuantity(
                $component->quantity,
                $quantity,
                $this->componentQuantityDecimals($component),
                $this->componentQuantityRoundingMethod($component),
            );

            return $this->expandBundle($tenantId, $companyId, $nested, $lineQty, $moneyScale, $depth + 1);
        }

        if ($component->component_type === BundleComponentType::Part && $component->product_id !== null) {
            $productRef = $this->products->findForBundleComponent($component->product_id);
            if ($productRef === null) {
                throw BundleExpansionException::missingComponent($component->product_id);
            }
            $lineQty = $this->multiplyQuantity(
                $component->quantity,
                $quantity,
                $productRef->quantity_decimals,
                $productRef->quantity_rounding_method,
            );
            $unitPrice = $component->override_unit_price
                ?? ($productRef->sale_price ?? '0');
            $unitPrice = CurrencyScale::bcformat($unitPrice, $moneyScale);
            $lineTotal = $this->multiplyMoney($unitPrice, $lineQty, $moneyScale);

            return [new BundleExpansionLine(
                component_type: BundleComponentType::Part,
                component_id: $component->product_id,
                display_name: $productRef->display_name,
                quantity: $lineQty,
                quantity_decimals: $productRef->quantity_decimals,
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
            $quantityDecimals = $this->componentQuantityDecimals($component);
            $lineQty = $this->multiplyQuantity(
                $component->quantity,
                $quantity,
                $quantityDecimals,
                $this->componentQuantityRoundingMethod($component),
            );
            $unitPrice = $component->override_unit_price
                ?? $this->laborPrice($serviceRef);
            $unitPrice = CurrencyScale::bcformat($unitPrice, $moneyScale);
            $lineTotal = $this->multiplyMoney($unitPrice, $lineQty, $moneyScale);

            return [new BundleExpansionLine(
                component_type: BundleComponentType::Labor,
                component_id: $component->service_id,
                display_name: $serviceRef->display_name,
                quantity: $lineQty,
                quantity_decimals: $quantityDecimals,
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
    private function expandComponentInformational(string $tenantId, string $companyId, ServiceBundleComponent $component, string $quantity, int $moneyScale, int $depth): array
    {
        $zero = CurrencyScale::bcformat('0', $moneyScale);

        if ($component->component_type === BundleComponentType::NestedBundle && $component->nested_bundle_id !== null) {
            $nested = $this->bundles->findWithComponentsAndApplicabilitiesForScope($tenantId, $companyId, $component->nested_bundle_id);
            if ($nested === null) {
                throw BundleExpansionException::missingComponent($component->nested_bundle_id);
            }
            $lineQty = $this->multiplyQuantity(
                $component->quantity,
                $quantity,
                $this->componentQuantityDecimals($component),
                $this->componentQuantityRoundingMethod($component),
            );
            // Recursively collect informational lines from the nested
            // bundle's components at the next depth.
            $lines = [];
            foreach ($nested->components as $inner) {
                foreach ($this->expandComponentInformational($tenantId, $companyId, $inner, $lineQty, $moneyScale, $depth + 1) as $l) {
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
            $lineQty = $this->multiplyQuantity(
                $component->quantity,
                $quantity,
                $productRef->quantity_decimals,
                $productRef->quantity_rounding_method,
            );

            return [new BundleExpansionLine(
                component_type: BundleComponentType::Part,
                component_id: $component->product_id,
                display_name: $productRef->display_name,
                quantity: $lineQty,
                quantity_decimals: $productRef->quantity_decimals,
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
            $quantityDecimals = $this->componentQuantityDecimals($component);
            $lineQty = $this->multiplyQuantity(
                $component->quantity,
                $quantity,
                $quantityDecimals,
                $this->componentQuantityRoundingMethod($component),
            );

            return [new BundleExpansionLine(
                component_type: BundleComponentType::Labor,
                component_id: $component->service_id,
                display_name: $serviceRef->display_name,
                quantity: $lineQty,
                quantity_decimals: $quantityDecimals,
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

    private function componentQuantityDecimals(ServiceBundleComponent $component): int
    {
        if (! $component->relationLoaded('unit')) {
            return QuantityScale::SCALE;
        }

        return $component->unit->decimal_places;
    }

    private function componentQuantityRoundingMethod(ServiceBundleComponent $component): ?string
    {
        if (! $component->relationLoaded('unit')) {
            return null;
        }

        return $component->unit->rounding_method->value;
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
