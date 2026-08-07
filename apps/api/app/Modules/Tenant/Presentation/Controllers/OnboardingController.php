<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * api.module-gating cluster: companyId resolves exclusively from
 * CompanyContext.
 *
 * CompanyContextMiddleware (registered in the global `api` group) normally
 * returns 403 NO_COMPANY_ACCESS upstream — but it deliberately SKIPS
 * principals that are not App\Modules\Identity\Domain\User (super-admin
 * guard), so the controller can still be reached with an empty context.
 * requireCompanyId() then threw a bare \RuntimeException that matched no
 * render callback → an unhandled 500 (BUG-005 / RCA B2).
 *
 * The guard below makes that path answer the SAME typed 403 envelope the
 * middleware emits, so the contract holds regardless of middleware ordering
 * or principal type.
 */
final class OnboardingController
{
    public function __construct(
        private readonly OnboardingChecklistService $checklistService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if ($companyId === null) {
            return response()->json([
                'error' => [
                    'code' => 'NO_COMPANY_ACCESS',
                    'message' => 'User is not a member of any company.',
                ],
            ], 403);
        }

        return response()->json([
            'data' => $this->checklistService->getStatus($companyId),
        ]);
    }
}
