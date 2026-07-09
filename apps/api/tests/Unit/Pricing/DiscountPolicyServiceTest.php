<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Modules\Pricing\Domain\Enums\FloorBasis;
use App\Modules\Pricing\Domain\Enums\PriceBasis;
use App\Modules\Pricing\Domain\Services\DiscountCapResolver;
use App\Modules\Pricing\Domain\Services\DiscountPolicyService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\DiscountPolicySubjectProviderInterface;
use App\Shared\Contracts\RegulatoryFloorResolverInterface;
use App\Shared\DTOs\DiscountPolicyContext;
use App\Shared\DTOs\DiscountPolicyLineContext;
use App\Shared\DTOs\DiscountPolicySubject;
use PHPUnit\Framework\TestCase;

final class DiscountPolicyServiceTest extends TestCase
{
    public function test_discount_policy_service_resolves_minimum_margin_floor_with_explicit_currency_scale(): void
    {
        $verdict = $this->serviceWithSubject($this->subject(wac: '100.000000', minimumMargin: '10.00'))
            ->resolve($this->context(price: '109.990', currency: 'TND'));

        self::assertSame('110.000', $verdict->floorPriceNet);
        self::assertSame(FloorBasis::MinimumMargin->value, $verdict->floorBasis);
        self::assertSame('pricing.sell_below_minimum_margin', $verdict->requiresPermission);
        self::assertFalse($verdict->blocksSale);
    }

    public function test_discount_policy_skips_floor_when_cost_is_missing_or_zero(): void
    {
        $verdict = $this->serviceWithSubject($this->subject(wac: '0.000000', minimumMargin: '10.00'))
            ->resolve($this->context(price: '1.000', currency: 'TND'));

        self::assertNull($verdict->floorPriceNet);
        self::assertSame(FloorBasis::None->value, $verdict->floorBasis);
        self::assertTrue($verdict->allowed);
        self::assertNull($verdict->requiresPermission);
    }

    public function test_no_defined_discount_cap_defaults_to_100_percent_without_cap_floor(): void
    {
        $verdict = $this->serviceWithSubject($this->subject(productCap: null, salePriceNet: '200.00', wac: '0.000000'))
            ->resolve($this->context(price: '1.00', currency: 'EUR'));

        self::assertSame('100.00', $verdict->maxDiscountPercent);
        self::assertNull($verdict->floorPriceNet);
        self::assertSame(FloorBasis::None->value, $verdict->floorBasis);
    }

    public function test_below_cost_requires_existing_below_cost_permission(): void
    {
        $verdict = $this->serviceWithSubject($this->subject(wac: '100.000000', minimumMargin: '10.00'))
            ->resolve($this->context(price: '99.990', currency: 'TND'));

        self::assertSame('110.000', $verdict->floorPriceNet);
        self::assertSame(FloorBasis::MinimumMargin->value, $verdict->floorBasis);
        self::assertSame('pricing.sell_below_cost', $verdict->requiresPermission);
    }

    public function test_discount_cap_computes_floor_from_ht_sale_price(): void
    {
        $verdict = $this->serviceWithSubject($this->subject(productCap: '10.00', salePriceNet: '200.00', wac: '0.000000'))
            ->resolve($this->context(price: '179.990', currency: 'EUR'));

        self::assertSame('180.00', $verdict->floorPriceNet);
        self::assertSame(FloorBasis::DiscountCap->value, $verdict->floorBasis);
        self::assertSame('10.01', $verdict->discountPercent);
        self::assertContains('discount_cap', $verdict->reasons);
    }

    public function test_gross_price_is_converted_to_net_before_comparison(): void
    {
        $verdict = $this->serviceWithSubject($this->subject(wac: '100.000000', minimumMargin: '10.00', taxRate: '20.00'))
            ->resolve($this->context(price: '131.988', currency: 'TND', priceBasis: PriceBasis::Ttc->value));

        self::assertSame('109.990', $verdict->meta['effectiveUnitPriceNet']);
        self::assertSame('pricing.sell_below_minimum_margin', $verdict->requiresPermission);
    }

    public function test_resolve_many_batches_subject_provider_calls(): void
    {
        $provider = new SpySubjectProvider($this->subject(wac: '100.000000'));
        $service = new DiscountPolicyService($provider, new DiscountCapResolver, new FixedScaleResolver, new StubRegulatoryFloorResolver(false));

        $service->resolveMany([
            'line-0' => $this->context(productId: 'product-1'),
            'line-1' => $this->context(productId: 'product-2'),
        ]);

        self::assertSame(1, $provider->resolveManyCalls);
        self::assertSame(['line-0', 'line-1'], array_keys($provider->lastContexts));
    }

    private function serviceWithSubject(DiscountPolicySubject $subject, bool $regulatoryActive = false): DiscountPolicyService
    {
        return new DiscountPolicyService(
            new SpySubjectProvider($subject),
            new DiscountCapResolver,
            new FixedScaleResolver,
            new StubRegulatoryFloorResolver($regulatoryActive),
        );
    }

    private function context(
        string $price = '110.00',
        string $currency = 'EUR',
        string $priceBasis = PriceBasis::Ht->value,
        string $productId = 'product-1',
    ): DiscountPolicyContext {
        return new DiscountPolicyContext(
            companyId: 'company-1',
            productId: $productId,
            variantId: null,
            effectiveUnitPrice: $price,
            currency: $currency,
            quantity: '1.0000',
            taxRate: null,
            taxConfigurationId: null,
            priceBasis: $priceBasis,
        );
    }

    public function test_regulatory_below_cost_floor_is_advisory_and_never_blocks(): void
    {
        // WAC 0 → no enforceable floor; only the FR/TN advisory floor from last purchase cost.
        // Mode is Block, yet a regulatory-only breach must NOT block (advisory until sign-off).
        $verdict = $this->serviceWithSubject(
            $this->subject(wac: '0.000000', countryCode: 'TN', lastPurchaseCost: '8.000', discountFloorMode: 'Block'),
            regulatoryActive: true,
        )->resolve($this->context(price: '5.000', currency: 'TND'));

        self::assertSame('8.000', $verdict->floorPriceNet);
        self::assertSame(FloorBasis::LegalBelowCost->value, $verdict->floorBasis);
        self::assertContains('legal_below_cost', $verdict->reasons);
        self::assertFalse($verdict->blocksSale);
        self::assertTrue($verdict->allowed);
        self::assertNull($verdict->requiresPermission);
        self::assertSame('warn', $verdict->severity);
    }

    public function test_regulatory_floor_absent_when_no_active_rule_for_country(): void
    {
        $verdict = $this->serviceWithSubject(
            $this->subject(wac: '0.000000', countryCode: 'TN', lastPurchaseCost: '8.000'),
            regulatoryActive: false,
        )->resolve($this->context(price: '5.000', currency: 'TND'));

        self::assertNull($verdict->floorPriceNet);
        self::assertSame(FloorBasis::None->value, $verdict->floorBasis);
        self::assertTrue($verdict->allowed);
    }

    private function subject(
        ?string $productCap = null,
        ?string $salePriceNet = '150.00',
        ?string $wac = '100.000000',
        ?string $minimumMargin = '10.00',
        string $taxRate = '20.00',
        ?string $countryCode = null,
        ?string $lastPurchaseCost = '90.000000',
        string $discountFloorMode = 'Advisory',
    ): DiscountPolicySubject {
        return DiscountPolicySubject::make(
            companyId: 'company-1',
            productId: 'product-1',
            variantId: null,
            productMaxDiscountPercent: $productCap,
            categoryMaxDiscountPercents: [],
            companyMaxDiscountPercent: null,
            salePriceNet: $salePriceNet,
            wacNet: $wac,
            lastPurchaseCost: $lastPurchaseCost,
            minimumMarginPercent: $minimumMargin,
            currency: 'EUR',
            taxConfigurationId: null,
            taxRate: $taxRate,
            resolvedTaxRate: $taxRate,
            discountFloorMode: $discountFloorMode,
            priceEntryMode: 'Ht',
            policyAsOf: '2026-07-08T00:00:00+00:00',
            countryCode: $countryCode,
        );
    }
}

final class SpySubjectProvider implements DiscountPolicySubjectProviderInterface
{
    public int $resolveManyCalls = 0;

    /** @var array<string, DiscountPolicyLineContext> */
    public array $lastContexts = [];

    public function __construct(private readonly DiscountPolicySubject $subject) {}

    public function resolveMany(string $companyId, array $contexts): array
    {
        $this->resolveManyCalls++;
        $this->lastContexts = $contexts;

        return array_fill_keys(array_keys($contexts), $this->subject);
    }

    public function resolve(string $companyId, string $productId, ?string $variantId = null): DiscountPolicySubject
    {
        return $this->subject;
    }
}

final class FixedScaleResolver implements CurrencyScaleResolverInterface
{
    public function getScale(?string $currencyCode = null): int
    {
        return $currencyCode === 'TND' ? 3 : 2;
    }

    public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int
    {
        return $this->getScale($currencyCode);
    }
}

final class StubRegulatoryFloorResolver implements RegulatoryFloorResolverInterface
{
    public function __construct(private readonly bool $active) {}

    public function belowCostFloorActive(string $countryCode): bool
    {
        return $this->active;
    }
}
