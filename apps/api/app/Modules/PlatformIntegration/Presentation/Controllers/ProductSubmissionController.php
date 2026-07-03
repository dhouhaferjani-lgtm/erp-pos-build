<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\PlatformIntegration\Domain\Services\BarcodeNormalizer;
use App\Shared\Contracts\EnrichmentSubmissionCorrelatorInterface;
use App\Shared\DTOs\TrackingIdHolderDTO;
use App\Shared\Exceptions\EnrichmentAlreadyPendingException;
use App\Shared\Exceptions\EnrichmentCorrelationConflictException;
use App\Shared\Exceptions\ProductNotFoundForEnrichmentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ProductSubmissionController extends Controller
{
    public function __construct(
        private readonly ProductSubmissionService $submissionService,
        private readonly CompanyContext $companyContext,
        private readonly EnrichmentSubmissionCorrelatorInterface $correlator,
        private readonly BarcodeNormalizer $barcodeNormalizer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('enrichment.submit')) {
            abort(403);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'photo_ids' => ['sometimes', 'array', 'max:5'],
            'photo_ids.*' => ['string', 'max:255'],
            'attributes' => ['sometimes', 'nullable', 'array'],
            'attributes.*' => ['string', 'max:255'],
        ]);

        $barcode = $validated['barcode'] ?? null;
        if (is_string($barcode)) {
            $barcode = $this->barcodeNormalizer->normalize($barcode);

            if ($barcode === null) {
                return response()->json([
                    'error' => ['code' => 'invalid_barcode', 'message' => 'The barcode could not be normalized.'],
                ], 422);
            }
        }

        $company = $this->companyContext->requireCompany();
        $vertical = $company->tenant->vertical->platformVertical();

        if ($vertical === null) {
            return response()->json([
                'error' => ['code' => 'vertical_not_supported', 'message' => 'This vertical does not support enrichment.'],
            ], 422);
        }

        // Fail fast BEFORE the outbound platform call so a not-found /
        // already-pending product never mints an orphan platform submission.
        try {
            $this->correlator->assertSubmittable($validated['product_id'], $company->id);
        } catch (ProductNotFoundForEnrichmentException) {
            return response()->json([
                'error' => ['code' => 'product_not_found', 'message' => 'Product not found.'],
            ], 404);
        } catch (EnrichmentAlreadyPendingException) {
            return response()->json([
                'error' => ['code' => 'enrichment_already_pending', 'message' => 'This product already has a pending enrichment submission.'],
            ], 409);
        }

        $result = $this->submissionService->submit(new ProductSubmissionData(
            barcode: $barcode,
            vertical: $vertical,
            name: $validated['name'],
            brand: $validated['brand'] ?? null,
            category: $validated['category'] ?? null,
            description: $validated['description'] ?? null,
            attributes: $validated['attributes'] ?? null,
            photoIds: $validated['photo_ids'] ?? [],
            autoEnrich: true,
        ));

        if ($result === null) {
            return response()->json([
                'error' => ['code' => 'platform_unavailable', 'message' => 'Platform is currently unavailable.'],
            ], 502);
        }

        $holder = $this->correlator->findTrackingIdHolder($result->trackingId, $company->id);
        if ($holder !== null && ! hash_equals(Str::lower($holder->productId), Str::lower($validated['product_id']))) {
            return $this->trackingConflictResponse($holder);
        }

        // Persist the correlation so inbound webhooks / polling can route the
        // enriched result back to this product.
        try {
            $this->correlator->correlateSubmission($validated['product_id'], $company->id, $result->trackingId);
        } catch (ProductNotFoundForEnrichmentException) {
            return response()->json([
                'error' => ['code' => 'product_not_found', 'message' => 'Product not found.'],
            ], 404);
        } catch (EnrichmentAlreadyPendingException) {
            return response()->json([
                'error' => ['code' => 'enrichment_already_pending', 'message' => 'This product already has a pending enrichment submission.'],
            ], 409);
        } catch (EnrichmentCorrelationConflictException) {
            return $this->trackingConflictResponse(
                $this->correlator->findTrackingIdHolder($result->trackingId, $company->id),
            );
        }

        return response()->json([
            'data' => $result->toArray(),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
            ],
        ]);
    }

    private function trackingConflictResponse(?TrackingIdHolderDTO $holder): JsonResponse
    {
        $error = [
            'code' => 'enrichment_tracking_conflict',
            'message' => 'This enrichment submission is already correlated to another product.',
        ];

        if ($holder !== null) {
            $error['details'] = [
                'holder_product_id' => $holder->productId,
                'holder_product_name' => $holder->productName,
            ];
        }

        return response()->json(['error' => $error], 409);
    }
}
