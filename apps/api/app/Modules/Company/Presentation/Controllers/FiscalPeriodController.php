<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Controllers;

use App\Modules\Company\Application\Services\FiscalPeriodAutoLockService;
use App\Modules\Company\Application\Services\FiscalPeriodCloseService;
use App\Modules\Company\Application\Services\FiscalPeriodReopenService;
use App\Modules\Company\Domain\Exceptions\FiscalPeriodCloseRefusedException;
use App\Modules\Company\Domain\Exceptions\FiscalPeriodReopenRefusedException;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Presentation\Requests\CloseFiscalPeriodRequest;
use App\Modules\Company\Presentation\Requests\ReopenFiscalPeriodRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Fiscal-period lifecycle endpoints.
 *
 * TWO human edges live here — the reopen (Session B lane Q-10 (c)) and the manual close
 * (Session B2 lane C-24 (i)) — and they are a PAIR: Q-10 made the nightly
 * {@see FiscalPeriodAutoLockService} step around any period a human reopened, which left
 * that period (and its fiscal year) open forever until C-24 supplied the close. Locking
 * remains the scheduler's alone, and the full PeriodStatus machine is program scope.
 *
 * Shape copied from `VatPeriodController::reopen()`
 * (app/Modules/Taxation/Presentation/Controllers/VatPeriodController.php:116):
 * resolve the company from context, scope the lookup to it (a period belonging to another
 * company 404s rather than 403s — it is not addressable), turn the service's typed
 * {@see FiscalPeriodReopenRefusedException} / {@see FiscalPeriodCloseRefusedException}
 * into a 422 carrying its refusal code.
 */
final class FiscalPeriodController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly FiscalPeriodReopenService $reopenService,
        private readonly FiscalPeriodCloseService $closeService,
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

        return response()->json(['data' => $this->payload($reopened)]);
    }

    /**
     * Manually close an open fiscal period (Open -> Closed) — Session B2 lane C-24 (i).
     *
     * The counterpart of {@see reopen()}: without it a reopened period stays Open
     * forever, because the nightly {@see FiscalPeriodAutoLockService} deliberately skips
     * it AND holds the close of its fiscal year. Nothing in the scheduler changes — the
     * `Open -> Closed` write is all it was waiting for.
     */
    public function close(CloseFiscalPeriodRequest $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        /** @var FiscalPeriod $period */
        $period = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->findOrFail($id);

        $reason = $request->validated('reason');

        try {
            $closed = $this->closeService->close(
                $period,
                (string) $request->user()?->id,
                is_string($reason) ? $reason : null,
            );
        } catch (FiscalPeriodCloseRefusedException $e) {
            // Same house `{error: {code, message, ...}}` envelope as reopen(), with the
            // TYPED code: a front end branches on `error.code`, never on the prose.
            return response()->json([
                'error' => [
                    'code' => $e->refusalCode->value,
                    'message' => $e->getMessage(),
                    'fiscal_period_id' => $e->fiscalPeriodId,
                ],
            ], 422);
        }

        return response()->json(['data' => $this->payload($closed)]);
    }

    /**
     * The one fiscal-period representation both lifecycle edges answer with.
     *
     * Deliberately hand-built rather than a Resource: it exposes the transition audit
     * columns (`status_actor` / `status_changed_from` / the close and reopen stamps) that
     * the lifecycle screens read, and both edges must answer with the SAME shape so a
     * caller can treat them interchangeably.
     *
     * @return array<string, mixed>
     */
    private function payload(FiscalPeriod $period): array
    {
        return [
            'id' => $period->id,
            'fiscal_year_id' => $period->fiscal_year_id,
            'company_id' => $period->company_id,
            'name' => $period->name,
            'period_number' => $period->period_number,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'status' => $period->status->value,
            'closed_at' => $period->closed_at?->toIso8601String(),
            'closed_by' => $period->closed_by,
            'reopened_at' => $period->reopened_at?->toIso8601String(),
            'reopened_by' => $period->reopened_by,
            'reopen_reason' => $period->reopen_reason,
            'status_actor' => $period->status_actor,
            'status_changed_from' => $period->status_changed_from,
        ];
    }
}
