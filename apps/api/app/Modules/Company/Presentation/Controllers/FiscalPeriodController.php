<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Controllers;

use App\Modules\Company\Application\Services\FiscalPeriodAutoLockService;
use App\Modules\Company\Application\Services\FiscalPeriodReopenService;
use App\Modules\Company\Domain\Exceptions\FiscalPeriodReopenRefusedException;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Presentation\Requests\ReopenFiscalPeriodRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Fiscal-period lifecycle endpoints (Session B lane Q-10 (c)).
 *
 * Only the reopen edge lives here: closing and locking are the scheduler's job
 * ({@see FiscalPeriodAutoLockService}), and the
 * full PeriodStatus machine is program scope.
 *
 * Shape copied from `VatPeriodController::reopen()`
 * (app/Modules/Taxation/Presentation/Controllers/VatPeriodController.php:116):
 * resolve the company from context, scope the lookup to it (a period belonging to another
 * company 404s rather than 403s — it is not addressable), turn the service's typed
 * {@see FiscalPeriodReopenRefusedException} into a 422 carrying its refusal code.
 */
final class FiscalPeriodController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly FiscalPeriodReopenService $reopenService,
    ) {}

    /**
     * Reopen a closed fiscal period (Closed → Open).
     */
    public function reopen(ReopenFiscalPeriodRequest $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        /** @var FiscalPeriod $period */
        $period = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->findOrFail($id);

        try {
            $reopened = $this->reopenService->reopen(
                $period,
                (string) $request->user()?->id,
                (string) $request->validated('reason'),
            );
        } catch (FiscalPeriodReopenRefusedException $e) {
            // House `{error: {code, message, ...}}` envelope with the TYPED code
            // (gate r1, M-1): a front end branches on `error.code`, never on the
            // prose. Caught here rather than in `bootstrap/app.php` because the
            // exception is raised on exactly one route.
            return response()->json([
                'error' => [
                    'code' => $e->refusalCode->value,
                    'message' => $e->getMessage(),
                    'fiscal_period_id' => $e->fiscalPeriodId,
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'id' => $reopened->id,
                'fiscal_year_id' => $reopened->fiscal_year_id,
                'company_id' => $reopened->company_id,
                'name' => $reopened->name,
                'period_number' => $reopened->period_number,
                'start_date' => $reopened->start_date->toDateString(),
                'end_date' => $reopened->end_date->toDateString(),
                'status' => $reopened->status->value,
                'closed_at' => $reopened->closed_at?->toIso8601String(),
                'closed_by' => $reopened->closed_by,
                'reopened_at' => $reopened->reopened_at?->toIso8601String(),
                'reopened_by' => $reopened->reopened_by,
                'reopen_reason' => $reopened->reopen_reason,
                'status_actor' => $reopened->status_actor,
                'status_changed_from' => $reopened->status_changed_from,
            ],
        ]);
    }
}
