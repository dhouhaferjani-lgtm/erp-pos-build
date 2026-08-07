<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Repositories;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

interface VatPeriodRepositoryInterface
{
    /**
     * Find a period for this company that COVERS $date and is no longer OPEN.
     *
     * R2-F1. This deliberately answers the *lock* question rather than "which
     * period covers this date": periods are unique per (company, start, end) but
     * NOT per country, so two country-scoped period sets can overlap the same
     * span. Returning any non-OPEN cover is the fail-closed reading — if any
     * declaration for that span is closed or filed, the span is locked.
     *
     * A `null` result means "no locked period covers this date", which includes
     * both "the covering period is OPEN" and "no period exists at all". A tenant
     * that has not started declaring VAT has no rows here and must stay fully
     * operational.
     */
    public function findLockedPeriodCoveringDate(string $companyId, CarbonInterface $date): ?VatPeriod;

    /**
     * Find a VAT period by ID.
     */
    public function findById(string $id): ?VatPeriod;

    /**
     * Find all VAT periods for a company in a given year.
     *
     * @return Collection<int, VatPeriod>
     */
    public function findByCompanyAndYear(string $companyId, int $year): Collection;

    /**
     * Create a new VAT period.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): VatPeriod;

    /**
     * Update a VAT period.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): VatPeriod;

    /**
     * Delete a VAT period.
     */
    public function delete(string $id): bool;

    /**
     * Find the previous period chronologically for a given period.
     */
    public function findPreviousPeriod(VatPeriod $period): ?VatPeriod;

    /**
     * Check whether a closed or filed successor period exists.
     * This prevents reopening a period when later ones have already been finalized.
     */
    public function hasClosedOrFiledSuccessor(VatPeriod $period): bool;

    /**
     * Create a breakdown record for a VAT period.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBreakdown(array $data): void;

    /**
     * Delete all breakdowns for a VAT period.
     */
    public function deleteBreakdowns(string $periodId): void;
}
