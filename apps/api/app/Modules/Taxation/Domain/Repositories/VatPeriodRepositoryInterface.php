<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Repositories;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use Illuminate\Database\Eloquent\Collection;

interface VatPeriodRepositoryInterface
{
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
}
