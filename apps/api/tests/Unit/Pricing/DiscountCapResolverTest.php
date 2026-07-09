<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Modules\Pricing\Domain\Services\DiscountCapResolver;
use App\Shared\DTOs\DiscountPolicySubject;
use PHPUnit\Framework\TestCase;

final class DiscountCapResolverTest extends TestCase
{
    public function test_cap_resolver_uses_product_category_company_priority(): void
    {
        $resolver = new DiscountCapResolver;

        self::assertSame('5.00', $resolver->resolve($this->subject(product: '5.00', categories: ['10.00'], company: '20.00')));
        self::assertSame('10.00', $resolver->resolve($this->subject(product: null, categories: ['10.00', '15.00'], company: '20.00')));
        self::assertSame('20.00', $resolver->resolve($this->subject(product: null, categories: [], company: '20.00')));
        self::assertSame('100.00', $resolver->resolve($this->subject(product: null, categories: [], company: null)));
    }

    /**
     * @param  array<int, string|null>  $categories
     */
    private function subject(?string $product, array $categories, ?string $company): DiscountPolicySubject
    {
        return DiscountPolicySubject::make(
            companyId: 'company-1',
            productId: 'product-1',
            variantId: null,
            productMaxDiscountPercent: $product,
            categoryMaxDiscountPercents: $categories,
            companyMaxDiscountPercent: $company,
            salePriceNet: '200.00',
            wacNet: '100.000000',
            lastPurchaseCost: '90.000000',
            minimumMarginPercent: '10.00',
            currency: 'EUR',
            taxConfigurationId: null,
            taxRate: '20.00',
            resolvedTaxRate: '20.00',
            discountFloorMode: 'Advisory',
            priceEntryMode: 'Ht',
            policyAsOf: '2026-07-08T00:00:00+00:00',
        );
    }
}
