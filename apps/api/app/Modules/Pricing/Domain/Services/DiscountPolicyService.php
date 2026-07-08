<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Services;

use App\Modules\Pricing\Domain\Enums\FloorBasis;
use App\Modules\Pricing\Domain\Enums\PriceBasis;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\DiscountPolicyInterface;
use App\Shared\Contracts\DiscountPolicySubjectProviderInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\DTOs\DiscountPolicyContext;
use App\Shared\DTOs\DiscountPolicyLineContext;
use App\Shared\DTOs\DiscountPolicySubject;
use App\Shared\DTOs\DiscountPolicyVerdict;
use InvalidArgumentException;

final class DiscountPolicyService implements DiscountPolicyInterface
{
    private const PERMISSION_BELOW_COST = 'pricing.sell_below_cost';

    private const PERMISSION_BELOW_MINIMUM_MARGIN = 'pricing.sell_below_minimum_margin';

    private const PERCENT_SCALE = 2;

    public function __construct(
        private readonly DiscountPolicySubjectProviderInterface $subjects,
        private readonly DiscountCapResolver $capResolver,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function resolve(DiscountPolicyContext $context): DiscountPolicyVerdict
    {
        return $this->resolveMany(['line' => $context])['line'];
    }

    public function resolveMany(array $contexts): array
    {
        if ($contexts === []) {
            return [];
        }

        $companyId = $this->singleCompanyId($contexts);
        $lineContexts = [];
        foreach ($contexts as $key => $context) {
            $lineContexts[$key] = new DiscountPolicyLineContext(
                productId: $context->productId,
                variantId: $context->variantId,
            );
        }

        $subjects = $this->subjects->resolveMany($companyId, $lineContexts);

        $verdicts = [];
        foreach ($contexts as $key => $context) {
            $subject = $subjects[$key] ?? null;
            if (! $subject instanceof DiscountPolicySubject) {
                throw new InvalidArgumentException("No discount policy subject resolved for line {$key}.");
            }

            $verdicts[$key] = $this->resolveForSubject($context, $subject);
        }

        return $verdicts;
    }

    /**
     * @param  array<string, DiscountPolicyContext>  $contexts
     */
    private function singleCompanyId(array $contexts): string
    {
        $companyIds = [];
        foreach ($contexts as $context) {
            $companyIds[$context->companyId] = true;
        }

        if (count($companyIds) !== 1) {
            throw new InvalidArgumentException('Discount policy batch resolution requires a single company.');
        }

        return array_key_first($companyIds);
    }

    private function resolveForSubject(DiscountPolicyContext $context, DiscountPolicySubject $subject): DiscountPolicyVerdict
    {
        $scale = $this->scaleResolver->getScale($context->currency);
        $effectiveNet = $this->effectiveNetPrice($context, $subject, $scale);
        $maxDiscountPercent = $this->capResolver->resolve($subject);
        $discountPercent = $this->discountPercent($subject, $effectiveNet);
        $contributions = $this->floorContributions($subject, $maxDiscountPercent, $scale);
        $winner = $this->winningContribution($contributions, $scale);
        $floorPriceNet = $winner['floor'];
        $floorBasis = $winner['basis'];
        $reasons = [];
        $requiresPermission = null;

        $wacNet = $this->positiveNumericOrNull($subject->wacNet, $scale);
        $belowCost = $wacNet !== null
            && bccomp($effectiveNet, CurrencyScale::bcround($wacNet, $scale), $scale) < 0;
        $belowFloor = $floorPriceNet !== null && bccomp($effectiveNet, $floorPriceNet, $scale) < 0;

        if ($belowCost) {
            $reasons[] = 'below_cost';
            $requiresPermission = self::PERMISSION_BELOW_COST;
        }

        if ($belowFloor) {
            $reasons[] = $floorBasis === FloorBasis::DiscountCap->value ? 'discount_cap' : 'minimum_margin_floor';
            $requiresPermission ??= self::PERMISSION_BELOW_MINIMUM_MARGIN;
        }

        $blocksSale = $requiresPermission !== null && $subject->discountFloorMode === 'Block';
        $severity = $blocksSale ? 'block' : ($requiresPermission !== null ? 'warn' : 'ok');

        return new DiscountPolicyVerdict(
            allowed: ! $blocksSale,
            blocksSale: $blocksSale,
            severity: $severity,
            requiresPermission: $requiresPermission,
            maxDiscountPercent: $maxDiscountPercent,
            discountPercent: $discountPercent,
            floorPriceNet: $floorPriceNet,
            floorBasis: $floorBasis,
            floorEnforcement: $subject->discountFloorMode,
            mode: $subject->discountFloorMode,
            overridable: $requiresPermission !== null,
            requiresReason: false,
            policyVersion: $subject->policyVersion,
            policyAsOf: $subject->policyAsOf,
            reasons: array_values(array_unique($reasons)),
            meta: [
                'effectiveUnitPriceNet' => $effectiveNet,
                'priceBasis' => $context->priceBasis,
                'currency' => $context->currency,
            ],
        );
    }

    /**
     * @return array<int, array{basis: string, floor: numeric-string}>
     */
    private function floorContributions(DiscountPolicySubject $subject, string $maxDiscountPercent, int $scale): array
    {
        $contributions = [];
        $wacNet = $this->positiveNumericOrNull($subject->wacNet, $scale);

        if ($wacNet !== null) {
            $costFloor = CurrencyScale::bcround($wacNet, $scale);
            $contributions[] = ['basis' => FloorBasis::Cost->value, 'floor' => $costFloor];

            $minimumMarginPercent = $this->positiveNumericOrNull($subject->minimumMarginPercent, self::PERCENT_SCALE);
            if ($minimumMarginPercent !== null) {
                $intermediate = $scale + 4;
                $factor = bcadd('1', bcdiv($minimumMarginPercent, '100', $intermediate), $intermediate);
                $contributions[] = [
                    'basis' => FloorBasis::MinimumMargin->value,
                    'floor' => CurrencyScale::bcround(bcmul($wacNet, $factor, $intermediate), $scale),
                ];
            }
        }

        $salePriceNet = $this->positiveNumericOrNull($subject->salePriceNet, $scale);
        $maxDiscountPercent = $this->numericString($maxDiscountPercent);
        if (
            $salePriceNet !== null
            && bccomp($maxDiscountPercent, '100.00', self::PERCENT_SCALE) < 0
        ) {
            $intermediate = $scale + 4;
            $factor = bcsub('1', bcdiv($maxDiscountPercent, '100', $intermediate), $intermediate);
            $contributions[] = [
                'basis' => FloorBasis::DiscountCap->value,
                'floor' => CurrencyScale::bcround(bcmul($salePriceNet, $factor, $intermediate), $scale),
            ];
        }

        return $contributions;
    }

    /**
     * @param  array<int, array{basis: string, floor: numeric-string}>  $contributions
     * @return array{basis: string, floor: numeric-string|null}
     */
    private function winningContribution(array $contributions, int $scale): array
    {
        $winner = ['basis' => FloorBasis::None->value, 'floor' => null];

        foreach ($contributions as $contribution) {
            if (
                $winner['floor'] === null
                || bccomp($contribution['floor'], $winner['floor'], $scale) > 0
            ) {
                $winner = $contribution;
            }
        }

        return $winner;
    }

    /**
     * @return numeric-string
     */
    private function effectiveNetPrice(DiscountPolicyContext $context, DiscountPolicySubject $subject, int $scale): string
    {
        $price = CurrencyScale::bcformatStrict($context->effectiveUnitPrice, $scale + 4);

        if ($context->priceBasis !== PriceBasis::Ttc->value) {
            return CurrencyScale::bcround($price, $scale);
        }

        $taxRate = $context->taxRate ?? $subject->resolvedTaxRate;
        $taxRate = $this->numericString($taxRate);
        $factor = bcadd('1', bcdiv($taxRate, '100', $scale + 4), $scale + 4);

        return CurrencyScale::bcround(bcdiv($price, $factor, $scale + 4), $scale);
    }

    private function discountPercent(DiscountPolicySubject $subject, string $effectiveNet): ?string
    {
        $salePriceNet = $this->positiveNumericOrNull($subject->salePriceNet, self::PERCENT_SCALE);
        if ($salePriceNet === null) {
            return null;
        }

        $intermediate = 6;
        $effectiveNet = $this->numericString($effectiveNet);
        $discount = bcsub($salePriceNet, $effectiveNet, $intermediate);
        $percent = bcmul(bcdiv($discount, $salePriceNet, $intermediate), '100', $intermediate);

        return CurrencyScale::bcround($percent, self::PERCENT_SCALE);
    }

    /**
     * @return numeric-string|null
     */
    private function positiveNumericOrNull(?string $value, int $scale): ?string
    {
        $numeric = $this->numericOrNull($value);

        if ($numeric === null || bccomp($numeric, '0', $scale + 2) <= 0) {
            return null;
        }

        return $numeric;
    }

    /**
     * @return numeric-string|null
     */
    private function numericOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->numericString($value);
    }

    /**
     * @return numeric-string
     */
    private function numericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Discount policy numeric value '{$value}' is invalid.");
        }

        return $value;
    }
}
