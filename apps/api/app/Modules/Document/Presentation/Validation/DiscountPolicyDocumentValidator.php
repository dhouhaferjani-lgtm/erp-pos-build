<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Validation;

use App\Modules\Company\Domain\Enums\DiscountFloorMode;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Identity\Domain\User;
use App\Modules\Pricing\Domain\Enums\FloorBasis;
use App\Modules\Pricing\Domain\Enums\PriceBasis;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\DiscountPolicyInterface;
use App\Shared\DTOs\DiscountPolicyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class DiscountPolicyDocumentValidator
{
    private const POLICY_ROUTES = [
        'invoices.store',
        'invoices.update',
        'orders.store',
        'orders.update',
    ];

    public function __construct(
        private readonly DiscountPolicyInterface $policy,
        private readonly CompanyContext $companyContext,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function validate(FormRequest $request, Validator $validator): void
    {
        if (! $this->isDiscountPolicyDocumentRoute($request) || $validator->errors()->isNotEmpty()) {
            return;
        }

        $lines = $request->input('lines');
        if (! is_array($lines) || $lines === []) {
            return;
        }

        /** @var User|null $user */
        $user = $request->user();
        if (! $user instanceof User) {
            return;
        }

        $company = $this->companyContext->requireCompany();
        $scale = $this->scaleResolver->getScale($company->currency);

        // Privilege claim — resolved server-side from the authenticated user, never
        // from the request payload. Mirrors the override rights enforced by shouldReject().
        $callerHasFloorOverride = $user->can('pricing.sell_below_minimum_margin')
            || $user->can('pricing.sell_below_cost');

        $contexts = [];
        $lineIndexesByKey = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line) || ! isset($line['product_id'])) {
                continue;
            }

            $productId = (string) $line['product_id'];
            $quantity = $this->numericString((string) ($line['quantity'] ?? '0'));
            if (bccomp($quantity, '0', 4) <= 0) {
                continue;
            }

            $lineKey = "line-{$index}";
            $lineTotal = DocumentLine::computeLineTotal(
                $quantity,
                $this->numericString((string) ($line['unit_price'] ?? '0')),
                isset($line['discount_percent']) ? $this->numericString((string) $line['discount_percent']) : null,
                isset($line['discount_amount']) ? $this->numericString((string) $line['discount_amount']) : null,
                $scale,
            );
            $effectiveUnitPrice = bcdiv($lineTotal, $quantity, $scale);

            $contexts[$lineKey] = new DiscountPolicyContext(
                companyId: $company->id,
                productId: $productId,
                variantId: isset($line['variant_id']) ? (string) $line['variant_id'] : null,
                effectiveUnitPrice: $effectiveUnitPrice,
                currency: $company->currency,
                quantity: $quantity,
                taxRate: isset($line['tax_rate']) ? (string) $line['tax_rate'] : null,
                taxConfigurationId: isset($line['tax_configuration_id']) ? (string) $line['tax_configuration_id'] : null,
                priceBasis: PriceBasis::Ht->value,
                userId: $user->id,
                callerHasFloorOverride: $callerHasFloorOverride,
            );
            $lineIndexesByKey[$lineKey] = (int) $index;
        }

        if ($contexts === []) {
            return;
        }

        $warnings = [];
        foreach ($this->policy->resolveMany($contexts) as $lineKey => $verdict) {
            if (! array_key_exists($lineKey, $lineIndexesByKey)) {
                continue;
            }

            $lineIndex = $lineIndexesByKey[$lineKey];

            if ($verdict->requiresPermission === null) {
                continue;
            }

            $hasPermission = $user->can($verdict->requiresPermission);
            $warning = [
                'line' => $lineIndex,
                'field' => "lines.{$lineIndex}.unit_price",
                'requires_permission' => $verdict->requiresPermission,
                'floor_price_net' => $verdict->floorPriceNet,
                'floor_basis' => $verdict->floorBasis,
                'max_discount_percent' => $verdict->maxDiscountPercent,
                'discount_percent' => $verdict->discountPercent,
                'policy_version' => $verdict->policyVersion,
                'policy_as_of' => $verdict->policyAsOf,
                'reasons' => $verdict->reasons,
            ];

            $shouldReject = $this->shouldReject($verdict->mode, $hasPermission);
            if ($shouldReject) {
                $validator->errors()->add(
                    "lines.{$lineIndex}.unit_price",
                    $this->messageFor($verdict->floorBasis),
                );
            }

            if (! $shouldReject) {
                $warnings[] = $warning;
            }
        }

        if ($warnings !== []) {
            $request->attributes->set('discount_policy_warnings', $warnings);
        }
    }

    private function isDiscountPolicyDocumentRoute(FormRequest $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && in_array($route->getName(), self::POLICY_ROUTES, true);
    }

    private function shouldReject(string $mode, bool $hasPermission): bool
    {
        if ($mode === DiscountFloorMode::Advisory->value) {
            return false;
        }

        return ! $hasPermission;
    }

    private function messageFor(string $floorBasis): string
    {
        if ($floorBasis === FloorBasis::DiscountCap->value) {
            return 'Line price exceeds the configured discount policy.';
        }

        return 'Line price is below the configured discount floor.';
    }

    /**
     * @return numeric-string
     */
    private function numericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Document discount policy numeric value '{$value}' is invalid.");
        }

        return $value;
    }
}
