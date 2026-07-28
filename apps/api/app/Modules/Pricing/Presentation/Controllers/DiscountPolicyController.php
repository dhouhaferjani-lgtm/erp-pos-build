<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Pricing\Domain\Enums\PriceBasis;
use App\Shared\Contracts\DiscountPolicyInterface;
use App\Shared\Domain\QuantityScale;
use App\Shared\DTOs\DiscountPolicyContext;
use App\Shared\Exceptions\DiscountPolicySubjectNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class DiscountPolicyController extends Controller
{
    public function __construct(
        private readonly DiscountPolicyInterface $policy,
        private readonly CompanyContext $companyContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'string', 'uuid'],
            'variant_id' => ['nullable', 'string', 'uuid'],
            'effective_unit_price' => ['required', 'string', 'regex:/^\d+(\.\d{1,6})?$/'],
            'price_basis' => ['required', 'string', Rule::enum(PriceBasis::class)],
            'currency' => ['required', 'string', 'size:3'],
            'quantity' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_configuration_id' => ['nullable', 'string', 'uuid'],
            'tax_rate' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);

        $companyId = $this->companyContext->requireCompanyId();

        $user = $request->user();
        // Privilege claim — resolved server-side from the authenticated user, never from
        // the request payload — so the advisory verdict's blocksSale/allowed matches the
        // authoritative document-layer enforcement for this caller.
        $callerHasFloorOverride = $user !== null
            && ($user->can('pricing.sell_below_minimum_margin') || $user->can('pricing.sell_below_cost'));

        $context = new DiscountPolicyContext(
            companyId: $companyId,
            productId: $validated['product_id'],
            variantId: $validated['variant_id'] ?? null,
            effectiveUnitPrice: $validated['effective_unit_price'],
            currency: strtoupper($validated['currency']),
            quantity: $validated['quantity'] ?? QuantityScale::formatForUnit('1', null),
            taxRate: $validated['tax_rate'] ?? null,
            taxConfigurationId: $validated['tax_configuration_id'] ?? null,
            priceBasis: $validated['price_basis'],
            userId: $user?->getAuthIdentifier(),
            callerHasFloorOverride: $callerHasFloorOverride,
        );

        try {
            $verdict = $this->policy->resolve($context);
        } catch (DiscountPolicySubjectNotFoundException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Product was not found for the current company.',
                    'productId' => $exception->productId,
                ],
            ], 404);
        }

        return response()->json([
            'data' => $verdict->toArray(),
            'meta' => [
                'companyId' => $companyId,
                'productId' => $validated['product_id'],
                'currency' => strtoupper($validated['currency']),
            ],
        ]);
    }
}
