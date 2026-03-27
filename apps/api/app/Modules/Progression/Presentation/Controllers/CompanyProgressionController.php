<?php

declare(strict_types=1);

namespace App\Modules\Progression\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Progression\Application\Services\ProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyProgressionController extends Controller
{
    public function __construct(
        private readonly ProgressionService $progressionService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $request->header('X-Company-Id', '');
        $profile = $this->progressionService->getProfile($companyId);

        if ($profile === null) {
            if (! $this->progressionService->isAvailable()) {
                return response()->json(['message' => 'Growth Advisor is temporarily unavailable'], 503);
            }

            return response()->json(['message' => 'Company not registered with Growth Advisor'], 404);
        }

        return response()->json(['data' => [
            'id' => $profile->id,
            'tenant_id' => $profile->tenantId,
            'vertical' => $profile->vertical,
            'country' => $profile->country,
            'current_stage' => $profile->currentStage->value,
            'stage_progress_percent' => $profile->stageProgressPercent,
            'total_milestones' => $profile->totalMilestones,
            'completed_milestones' => $profile->completedMilestones,
        ]]);
    }

    public function register(Request $request): JsonResponse
    {
        $companyId = $request->header('X-Company-Id', '');
        $profile = $this->progressionService->registerCompany([
            'company_id' => $companyId,
            'tenant_id' => $request->header('X-Tenant-Id', ''),
        ]);

        if ($profile === null) {
            return response()->json(['message' => 'Failed to register with Growth Advisor'], 503);
        }

        return response()->json(['data' => [
            'id' => $profile->id,
            'tenant_id' => $profile->tenantId,
            'vertical' => $profile->vertical,
            'country' => $profile->country,
            'current_stage' => $profile->currentStage->value,
            'stage_progress_percent' => $profile->stageProgressPercent,
            'total_milestones' => $profile->totalMilestones,
            'completed_milestones' => $profile->completedMilestones,
        ]], 201);
    }

    public function milestones(Request $request): JsonResponse
    {
        $companyId = $request->header('X-Company-Id', '');
        $milestones = $this->progressionService->getMilestones($companyId);

        return response()->json(['data' => array_map(static fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'status' => $m->status->value,
            'progress_percent' => $m->progressPercent,
            'stage' => $m->stage,
        ], $milestones)]);
    }
}
