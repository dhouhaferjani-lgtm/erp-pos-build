<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichmentResultData;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Presentation\Requests\AcceptEnrichmentRequest;
use App\Modules\Product\Presentation\Requests\RejectEnrichmentRequest;
use App\Shared\Enums\EnrichmentFeedbackReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EnrichmentReviewController extends Controller
{
    public function __construct(
        private readonly EnrichmentReviewService $enrichmentReviewService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List enrichment results for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('enrichment.view')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to view enrichment results.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 403);
        }

        $company = $this->companyContext->requireCompany();

        $status = $request->has('status')
            ? EnrichmentReviewStatus::tryFrom($request->string('status')->toString())
            : null;

        $quality = $request->has('quality')
            ? $request->string('quality')->toString()
            : null;

        $validated = $request->validate([
            'product_id' => ['sometimes', 'uuid'],
        ]);

        $productId = isset($validated['product_id']) ? (string) $validated['product_id'] : null;

        $paginator = $this->enrichmentReviewService->listForReview(
            $company->tenant_id,
            $company->id,
            $status,
            $quality,
            $productId,
        );

        $items = collect($paginator->items())->map(function (EnrichmentResult $result): EnrichmentResultData {
            return new EnrichmentResultData(
                id: $result->id,
                product_id: $result->product_id,
                product_name: $result->product->name,
                product_barcode: $result->product->barcode,
                product_sku: $result->product->sku,
                tracking_id: $result->tracking_id,
                version: $result->version,
                origin: $result->origin,
                status: $result->status->value,
                enriched_data: $result->enriched_data,
                enrichment_quality: $result->enrichment_quality,
                assigned_barcode: $result->assigned_barcode,
                reviewed_at: $result->reviewed_at?->toIso8601String(),
                reviewed_by: $result->reviewed_by,
                accepted_fields: $result->accepted_fields,
                rejection_reason: $result->rejection_reason,
                rejection_notes: $result->rejection_notes,
                created_at: $result->created_at->toIso8601String(),
            );
        });

        return response()->json([
            'data' => $items->toArray(),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Show a single enrichment result.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('enrichment.view')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to view enrichment results.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 403);
        }

        $company = $this->companyContext->requireCompany();

        // api.catalog round-2 (Codex Finding 1): chained MethodCall + tenant scope
        // (enrichment_results carries both tenant_id + company_id).
        $result = EnrichmentResult::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->with('product')
            ->first();

        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'ENRICHMENT_RESULT_NOT_FOUND',
                    'message' => 'Enrichment result not found.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => new EnrichmentResultData(
                id: $result->id,
                product_id: $result->product_id,
                product_name: $result->product->name,
                product_barcode: $result->product->barcode,
                product_sku: $result->product->sku,
                tracking_id: $result->tracking_id,
                version: $result->version,
                origin: $result->origin,
                status: $result->status->value,
                enriched_data: $result->enriched_data,
                enrichment_quality: $result->enrichment_quality,
                assigned_barcode: $result->assigned_barcode,
                reviewed_at: $result->reviewed_at?->toIso8601String(),
                reviewed_by: $result->reviewed_by,
                accepted_fields: $result->accepted_fields,
                rejection_reason: $result->rejection_reason,
                rejection_notes: $result->rejection_notes,
                created_at: $result->created_at->toIso8601String(),
            ),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Accept enrichment results for a product.
     */
    public function accept(AcceptEnrichmentRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('enrichment.review')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to review enrichment results.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 403);
        }

        $company = $this->companyContext->requireCompany();

        // api.catalog round-2 (Codex Finding 1): chained MethodCall + tenant scope
        // (enrichment_results carries both tenant_id + company_id).
        $result = EnrichmentResult::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->with('product')
            ->first();

        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'ENRICHMENT_RESULT_NOT_FOUND',
                    'message' => 'Enrichment result not found.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        if ($result->status !== EnrichmentReviewStatus::PendingReview) {
            return response()->json([
                'error' => [
                    'code' => 'ENRICHMENT_ALREADY_REVIEWED',
                    'message' => 'This enrichment result has already been reviewed.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        /** @var array<int, string> $acceptedFields */
        $acceptedFields = $request->validated('accepted_fields');

        $this->enrichmentReviewService->accept($result, $acceptedFields, $user->id);

        return response()->json([
            'data' => [
                'message' => 'Enrichment result accepted successfully.',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Reject enrichment results for a product.
     */
    public function reject(RejectEnrichmentRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('enrichment.review')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to review enrichment results.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 403);
        }

        $company = $this->companyContext->requireCompany();

        // api.catalog round-2 (Codex Finding 1): chained MethodCall + tenant scope
        // (enrichment_results carries both tenant_id + company_id).
        $result = EnrichmentResult::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->with('product')
            ->first();

        if ($result === null) {
            return response()->json([
                'error' => [
                    'code' => 'ENRICHMENT_RESULT_NOT_FOUND',
                    'message' => 'Enrichment result not found.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        if ($result->status !== EnrichmentReviewStatus::PendingReview) {
            return response()->json([
                'error' => [
                    'code' => 'ENRICHMENT_ALREADY_REVIEWED',
                    'message' => 'This enrichment result has already been reviewed.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        $reason = EnrichmentFeedbackReason::from($request->string('reason')->toString());
        $notes = $request->validated('notes');

        $this->enrichmentReviewService->reject($result, $user->id, $reason, is_string($notes) ? $notes : null);

        return response()->json([
            'data' => [
                'message' => 'Enrichment result rejected.',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
