<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DiscountPolicySubject extends Data
{
    /**
     * @param  array<int, string|null>  $categoryMaxDiscountPercents
     */
    public function __construct(
        public string $companyId,
        public string $productId,
        public ?string $variantId,
        public ?string $productMaxDiscountPercent,
        public array $categoryMaxDiscountPercents,
        public ?string $companyMaxDiscountPercent,
        public ?string $salePriceNet,
        public ?string $wacNet,
        public ?string $lastPurchaseCost,
        public ?string $minimumMarginPercent,
        public string $currency,
        public ?string $taxConfigurationId,
        public ?string $taxRate,
        public string $resolvedTaxRate,
        public string $discountFloorMode,
        public string $priceEntryMode,
        public string $policyAsOf,
        public string $policyVersion,
        public ?string $countryCode = null,
    ) {}

    /**
     * @param  array<int, string|null>  $categoryMaxDiscountPercents
     */
    public static function make(
        string $companyId,
        string $productId,
        ?string $variantId,
        ?string $productMaxDiscountPercent,
        array $categoryMaxDiscountPercents,
        ?string $companyMaxDiscountPercent,
        ?string $salePriceNet,
        ?string $wacNet,
        ?string $lastPurchaseCost,
        ?string $minimumMarginPercent,
        string $currency,
        ?string $taxConfigurationId,
        ?string $taxRate,
        string $resolvedTaxRate,
        string $discountFloorMode,
        string $priceEntryMode,
        string $policyAsOf,
        ?string $countryCode = null,
    ): self {
        return new self(
            companyId: $companyId,
            productId: $productId,
            variantId: $variantId,
            productMaxDiscountPercent: $productMaxDiscountPercent,
            categoryMaxDiscountPercents: $categoryMaxDiscountPercents,
            companyMaxDiscountPercent: $companyMaxDiscountPercent,
            salePriceNet: $salePriceNet,
            wacNet: $wacNet,
            lastPurchaseCost: $lastPurchaseCost,
            minimumMarginPercent: $minimumMarginPercent,
            currency: $currency,
            taxConfigurationId: $taxConfigurationId,
            taxRate: $taxRate,
            resolvedTaxRate: $resolvedTaxRate,
            discountFloorMode: $discountFloorMode,
            priceEntryMode: $priceEntryMode,
            policyAsOf: $policyAsOf,
            policyVersion: self::hashPolicyInputs([
                $companyId,
                $productId,
                $variantId,
                $productMaxDiscountPercent,
                $categoryMaxDiscountPercents,
                $companyMaxDiscountPercent,
                $salePriceNet,
                $wacNet,
                $lastPurchaseCost,
                $minimumMarginPercent,
                $currency,
                $taxConfigurationId,
                $taxRate,
                $resolvedTaxRate,
                $discountFloorMode,
                $priceEntryMode,
                $countryCode,
            ]),
            countryCode: $countryCode,
        );
    }

    public function effectiveMaxDiscountPercent(): ?string
    {
        if ($this->productMaxDiscountPercent !== null) {
            return $this->productMaxDiscountPercent;
        }

        foreach ($this->categoryMaxDiscountPercents as $categoryCap) {
            if ($categoryCap !== null) {
                return $categoryCap;
            }
        }

        return $this->companyMaxDiscountPercent;
    }

    /**
     * @param  array<int, mixed>  $inputs
     */
    private static function hashPolicyInputs(array $inputs): string
    {
        return substr(hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
