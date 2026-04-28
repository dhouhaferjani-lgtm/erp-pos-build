<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Presentation\Controllers;

use App\Enums\Vertical;
use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\SmartPrompts\Application\DTOs\RecommendationRequestData;
use App\Modules\SmartPrompts\Application\Services\SmartPromptsService;
use App\Modules\SmartPrompts\Domain\Enums\RecommendationContext;
use App\Modules\SmartPrompts\Domain\Enums\SkinType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SmartPromptsController extends Controller
{
    public function __construct(
        private readonly SmartPromptsService $smartPromptsService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function recommendations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['required', 'uuid'],
            'context' => ['sometimes', 'string', 'in:cart,checkout,reorder'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'skin_type' => ['sometimes', 'nullable', 'string', 'in:normal,oily,dry,combination,sensitive'],
            'customer_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $company = $this->companyContext->requireCompany();

        /** @var Tenant $tenant */
        $tenant = $company->tenant;

        /** @var Vertical $vertical */
        $vertical = $tenant->vertical;

        if ($vertical !== Vertical::Parapharmacy) {
            return response()->json([
                'data' => [
                    'recommendations' => [],
                    'context' => $validated['context'] ?? RecommendationContext::Cart->value,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);
        }

        if (! (bool) $company->smart_prompts_enabled) {
            return response()->json([
                'data' => [
                    'recommendations' => [],
                    'context' => $validated['context'] ?? RecommendationContext::Cart->value,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);
        }

        $context = RecommendationContext::from($validated['context'] ?? RecommendationContext::Cart->value);
        $skinType = isset($validated['skin_type'])
            ? SkinType::from($validated['skin_type'])
            : null;

        $requestData = new RecommendationRequestData(
            productIds: $validated['product_ids'],
            context: $context,
            limit: (int) ($validated['limit'] ?? 5),
            skinType: $skinType,
            customerId: $validated['customer_id'] ?? null,
            vertical: $vertical->value,
            country: (string) ($company->country_code ?? 'FR'),
        );

        $response = $this->smartPromptsService->getRecommendations(
            request: $requestData,
            tenantId: (string) $tenant->id,
            companyId: (string) $company->id,
        );

        return response()->json([
            'data' => [
                'recommendations' => array_map(
                    static fn ($rec) => [
                        'product_id' => $rec->productId,
                        'product_name' => $rec->productName,
                        'score' => $rec->score,
                        'reason' => $rec->reason,
                        'strategy' => $rec->strategy,
                    ],
                    $response->recommendations,
                ),
                'context' => $response->context,
                'generated_at' => $response->generatedAt,
            ],
        ]);
    }
}
