<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * api.module-gating cluster: companyId resolves exclusively from
 * CompanyContext::requireCompanyId(). The earlier explicit
 * `if ($companyId === null) → 400` guard was dead code because
 * CompanyContextMiddleware (registered in the global `api` group)
 * already returns 403 NO_COMPANY_ACCESS upstream, and
 * requireCompanyId() throws on missing context. Pinning here keeps
 * controller behavior correct even if future middleware re-ordering
 * disturbs the validator.
 */
final class OnboardingController
{
    public function __construct(
        private readonly OnboardingChecklistService $checklistService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        return response()->json([
            'data' => $this->checklistService->getStatus($companyId),
        ]);
    }
}
