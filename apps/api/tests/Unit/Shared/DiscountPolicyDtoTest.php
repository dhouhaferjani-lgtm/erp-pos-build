<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\DTOs\DiscountPolicySubject;
use PHPUnit\Framework\TestCase;

final class DiscountPolicyDtoTest extends TestCase
{
    public function test_subject_resolves_effective_discount_cap_product_category_company(): void
    {
        $subject = $this->subject(
            productMaxDiscountPercent: '5.00',
            categoryMaxDiscountPercents: ['10.00'],
            companyMaxDiscountPercent: '20.00',
        );

        self::assertSame('5.00', $subject->effectiveMaxDiscountPercent());

        $subject = $this->subject(
            productMaxDiscountPercent: null,
            categoryMaxDiscountPercents: [null, '15.00'],
            companyMaxDiscountPercent: '20.00',
        );

        self::assertSame('15.00', $subject->effectiveMaxDiscountPercent());

        $subject = $this->subject(
            productMaxDiscountPercent: null,
            categoryMaxDiscountPercents: [],
            companyMaxDiscountPercent: '20.00',
        );

        self::assertSame('20.00', $subject->effectiveMaxDiscountPercent());
    }

    public function test_product_discount_cap_has_priority_even_when_less_restrictive(): void
    {
        $subject = $this->subject(
            productMaxDiscountPercent: '50.00',
            categoryMaxDiscountPercents: ['10.00'],
            companyMaxDiscountPercent: '5.00',
        );

        self::assertSame('50.00', $subject->effectiveMaxDiscountPercent());
    }

    public function test_subject_policy_version_changes_when_policy_inputs_change(): void
    {
        $first = $this->subject(productMaxDiscountPercent: '5.00');
        $second = $this->subject(productMaxDiscountPercent: '6.00');

        self::assertNotSame('', $first->policyVersion);
        self::assertNotSame($first->policyVersion, $second->policyVersion);
    }

    /**
     * @param  array<int, string|null>  $categoryMaxDiscountPercents
     */
    private function subject(
        ?string $productMaxDiscountPercent = null,
        array $categoryMaxDiscountPercents = [],
        ?string $companyMaxDiscountPercent = null,
    ): DiscountPolicySubject {
        return DiscountPolicySubject::make(
            companyId: 'company-1',
            productId: 'product-1',
            variantId: null,
            productMaxDiscountPercent: $productMaxDiscountPercent,
            categoryMaxDiscountPercents: $categoryMaxDiscountPercents,
            companyMaxDiscountPercent: $companyMaxDiscountPercent,
            salePriceNet: '150.00',
            wacNet: '100.000000',
            lastPurchaseCost: '98.000000',
            minimumMarginPercent: '12.00',
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
