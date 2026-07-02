<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\Services\ManualEnrichmentRefreshService;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class EnrichmentRefreshController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ManualEnrichmentRefreshService $refreshService,
    ) {}

    public function __invoke(string $productId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $product = Product::query()
            ->where('id', $productId)
            ->where('company_id', $companyId)
            ->first();

        if ($product === null) {
            return response()->json([
                'error' => ['code' => 'product_not_found', 'message' => 'Product not found.'],
            ], 404);
        }

        if ($product->platform_submission_id === null) {
            return response()->json([
                'error' => ['code' => 'no_pending_submission', 'message' => 'This product has no enrichment submission to refresh.'],
            ], 422);
        }

        $status = $this->refreshService->refresh($product);

        if ($status === null) {
            return response()->json([
                'error' => ['code' => 'platform_unavailable', 'message' => 'Platform is currently unavailable.'],
            ], 502);
        }

        return response()->json([
            'data' => ['enrichment_status' => $status->value],
        ]);
    }
}
