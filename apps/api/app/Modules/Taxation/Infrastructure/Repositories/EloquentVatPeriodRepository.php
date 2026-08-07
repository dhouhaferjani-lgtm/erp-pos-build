<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Repositories;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Entities\VatPeriodBreakdown;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Repositories\VatPeriodRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentVatPeriodRepository implements VatPeriodRepositoryInterface
{
    public function findLockedPeriodCoveringDate(string $companyId, CarbonInterface $date): ?VatPeriod
    {
        return VatPeriod::query()
            ->where('company_id', $companyId)
            // Bind the Carbon INSTANCE, never `->toDateString()`. Passing a
            // 'Y-m-d' string made SQLite compare TEXT lexicographically against
            // its own 'Y-m-d H:i:s' storage, so `'2026-09-01 00:00:00' <=
            // '2026-09-01'` was FALSE and a document dated on `period_start`
            // escaped the lock (PostgreSQL, comparing real dates, was correct).
            // Binding the instance lets each driver's date grammar decide —
            // the same form `FiscalPeriodResolverService::isDateInClosedPeriod()`
            // has always used.
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->whereIn('status', [VatPeriodStatus::Filed, VatPeriodStatus::Closed])
            // FILED first: it is the stricter refusal (a filed declaration cannot
            // be reopened at all), so an overlap resolves to the stronger reason.
            ->orderByRaw('CASE status WHEN ? THEN 0 ELSE 1 END', [VatPeriodStatus::Filed->value])
            ->first();
    }

    public function findById(string $id): ?VatPeriod
    {
        return VatPeriod::find($id);
    }

    public function findByCompanyAndYear(string $companyId, int $year): Collection
    {
        return VatPeriod::forCompany($companyId)
            ->forYear($year)
            ->orderBy('period_start')
            ->get();
    }

    public function create(array $data): VatPeriod
    {
        return VatPeriod::create($data);
    }

    public function update(string $id, array $data): VatPeriod
    {
        $period = VatPeriod::findOrFail($id);
        $period->update($data);

        /** @var VatPeriod $freshPeriod */
        $freshPeriod = $period->fresh();

        return $freshPeriod;
    }

    public function delete(string $id): bool
    {
        $period = VatPeriod::findOrFail($id);

        return $period->delete() ?? false;
    }

    public function findPreviousPeriod(VatPeriod $period): ?VatPeriod
    {
        return VatPeriod::where('company_id', $period->company_id)
            ->where('period_end', '<', $period->period_start)
            ->orderByDesc('period_end')
            ->first();
    }

    public function hasClosedOrFiledSuccessor(VatPeriod $period): bool
    {
        return VatPeriod::where('company_id', $period->company_id)
            ->where('period_start', '>', $period->period_start)
            ->whereIn('status', [VatPeriodStatus::Closed, VatPeriodStatus::Filed])
            ->exists();
    }

    public function createBreakdown(array $data): void
    {
        VatPeriodBreakdown::create($data);
    }

    public function deleteBreakdowns(string $periodId): void
    {
        VatPeriodBreakdown::where('vat_period_id', $periodId)->delete();
    }
}
