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
 * CompanyContext::requireCompanyId() — the middleware-validated source.
 * Earlier reads of $request->header('X-Company-Id') trusted the raw
 * header; CompanyContextMiddleware (registered in the global `api`
 * group) verifies the header against UserCompanyMembership before
 * binding context, so requireCompanyId() is the safe single source of
 * truth. Pinning here keeps the controller behavior correct even if
 * future middleware re-ordering disturbs the validator.
 */
final class ModuleReadinessController extends Controller
{
    public function __construct(
        private readonly ProgressionService $progressionService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $modules = $this->progressionService->getModules($companyId);

        return response()->json(['data' => array_map(static fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'icon' => $m->icon,
            'status' => $m->status->value,
            'readiness_percent' => $m->readinessPercent,
            'stage' => $m->stage,
            'discount_percent' => $m->discountPercent,
            'requirements' => $m->requirements,
        ], $modules)]);
    }

    public function activate(Request $request, string $moduleId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $module = $this->progressionService->activateModule($companyId, $moduleId);

        if ($module === null) {
            return response()->json(['message' => 'Failed to activate module'], 503);
        }

        return response()->json(['data' => [
            'id' => $module->id,
            'name' => $module->name,
            'description' => $module->description,
            'icon' => $module->icon,
            'status' => $module->status->value,
            'readiness_percent' => $module->readinessPercent,
            'stage' => $module->stage,
            'discount_percent' => $module->discountPercent,
            'requirements' => $module->requirements,
        ]]);
    }
}
