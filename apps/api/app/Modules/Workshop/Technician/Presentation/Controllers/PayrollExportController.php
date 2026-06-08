<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use App\Modules\Workshop\Technician\Presentation\Requests\GeneratePayrollExportRequest;
use App\Shared\Domain\CurrencyScale;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll export controller (Phase A.4, v1 stateless).
 *
 * `index` returns an empty list — v1 does not persist exports anywhere.
 * `generate` aggregates time entries within the supplied pay period and
 * returns a CSV stream (one row per technician).
 *
 * Gross pay is computed as `hourly_cost_rate × hours_worked` using
 * CurrencyScale::bcformat to preserve decimal precision — no float math.
 */
final class PayrollExportController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(): JsonResponse
    {
        // v1: stateless — no payroll_exports table.
        return response()->json(['data' => []]);
    }

    public function generate(GeneratePayrollExportRequest $request): StreamedResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var array{pay_period_start: string, pay_period_end: string, technician_ids?: list<string>|null} $validated */
        $validated = $request->validated();

        $start = new DateTimeImmutable($validated['pay_period_start'].' 00:00:00');
        $end = new DateTimeImmutable($validated['pay_period_end'].' 23:59:59');
        $filterIds = $validated['technician_ids'] ?? null;

        $profileQuery = TechnicianProfile::query()
            ->where('company_id', $companyId)
            ->where('is_active', true);
        if (is_array($filterIds) && count($filterIds) > 0) {
            $profileQuery->whereIn('id', $filterIds);
        }
        /** @var list<TechnicianProfile> $profiles */
        $profiles = $profileQuery->orderBy('employee_code')->get()->all();

        $lines = [];
        foreach ($profiles as $profile) {
            $minutes = (int) TechnicianTimeEntry::query()
                ->where('technician_profile_id', $profile->id)
                ->where('started_at', '>=', $start)
                ->where('started_at', '<=', $end)
                ->sum('duration_minutes');

            $minutesStr = (string) $minutes;
            $costRate = $profile->hourly_cost_rate ?? '0';

            // Multiply-first pattern: avoids float division entirely.
            // gross = costRate × minutes ÷ 60  (all in bcmath, scale 6)
            $gross = CurrencyScale::bcformat(
                bcdiv(bcmul($costRate, $minutesStr, 6), '60', 6),
                3
            );

            // hours_worked for the CSV: bcmath division truncated to 2dp.
            $hoursWorked = bcdiv($minutesStr, '60', 2);

            $lines[] = [
                'employee_code' => (string) ($profile->employee_code ?? ''),
                'technician_id' => $profile->id,
                'hours_worked' => $hoursWorked,
                'gross_pay' => $gross,
                'currency' => $profile->currency,
            ];
        }

        $filename = sprintf(
            'payroll-%s-to-%s.csv',
            $validated['pay_period_start'],
            $validated['pay_period_end'],
        );

        /** @return resource */
        $callback = static function () use ($lines): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, ['employee_code', 'technician_id', 'hours_worked', 'gross_pay', 'currency']);
            foreach ($lines as $line) {
                fputcsv($handle, array_values($line));
            }
            fclose($handle);
        };

        return new StreamedResponse(
            $callback,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }
}
