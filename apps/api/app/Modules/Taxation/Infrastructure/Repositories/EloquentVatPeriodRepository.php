<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Repositories;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Entities\VatPeriodBreakdown;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Repositories\VatPeriodRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentVatPeriodRepository implements VatPeriodRepositoryInterface
{
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
