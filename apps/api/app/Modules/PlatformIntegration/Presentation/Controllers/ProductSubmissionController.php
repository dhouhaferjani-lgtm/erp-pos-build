<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ProductSubmissionController extends Controller
{
    public function __construct(
        private readonly ProductSubmissionService $submissionService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('enrichment.submit')) {
            abort(403);
        }

        $validated = $request->validate([
            'barcode' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $this->companyContext->requireCompany();
        $vertical = $company->tenant->vertical->platformVertical();

        if ($vertical === null) {
            return response()->json([
                'error' => ['code' => 'vertical_not_supported', 'message' => 'This vertical does not support enrichment.'],
            ], 422);
        }

        $result = $this->submissionService->submit(new ProductSubmissionData(
            barcode: $validated['barcode'] ?? null,
            vertical: $vertical,
            name: $validated['name'],
            brand: $validated['brand'],
            category: $validated['category'] ?? null,
            description: $validated['description'] ?? null,
            attributes: null,
            photoIds: [],
            autoEnrich: true,
        ));

        if ($result === null) {
            return response()->json([
                'error' => ['code' => 'platform_unavailable', 'message' => 'Platform is currently unavailable.'],
            ], 502);
        }

        return response()->json([
            'data' => $result->toArray(),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
            ],
        ]);
    }
}
