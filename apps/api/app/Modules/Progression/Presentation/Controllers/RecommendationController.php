<?php

declare(strict_types=1);

namespace App\Modules\Progression\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Progression\Application\Services\ProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * api.module-gating cluster: companyId resolves exclusively from
 * CompanyContext::requireCompanyId(). See ModuleReadinessController
 * docblock for full rationale.
 */
final class RecommendationController extends Controller
{
    public function __construct(
        private readonly ProgressionService $progressionService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $recommendations = $this->progressionService->getRecommendations($companyId);

        return response()->json(['data' => array_map(static fn ($r) => [
            'id' => $r->id,
            'title' => $r->title,
            'description' => $r->description,
            'priority' => $r->priority->value,
            'action_label' => $r->actionLabel,
            'action_route' => $r->actionRoute,
            'status' => $r->status,
        ], $recommendations)]);
    }

    public function accept(Request $request, string $recommendationId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $recommendation = $this->progressionService->acceptRecommendation($companyId, $recommendationId);

        if ($recommendation === null) {
            return response()->json(['message' => 'Failed to accept recommendation'], 503);
        }

        return response()->json(['data' => [
            'id' => $recommendation->id,
            'title' => $recommendation->title,
            'description' => $recommendation->description,
            'priority' => $recommendation->priority->value,
            'action_label' => $recommendation->actionLabel,
            'action_route' => $recommendation->actionRoute,
            'status' => $recommendation->status,
        ]]);
    }

    public function dismiss(Request $request, string $recommendationId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $recommendation = $this->progressionService->dismissRecommendation($companyId, $recommendationId);

        if ($recommendation === null) {
            return response()->json(['message' => 'Failed to dismiss recommendation'], 503);
        }

        return response()->json(['data' => [
            'id' => $recommendation->id,
            'title' => $recommendation->title,
            'description' => $recommendation->description,
            'priority' => $recommendation->priority->value,
            'action_label' => $recommendation->actionLabel,
            'action_route' => $recommendation->actionRoute,
            'status' => $recommendation->status,
        ]]);
    }
}
