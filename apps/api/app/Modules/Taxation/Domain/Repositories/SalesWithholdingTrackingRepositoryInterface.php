<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Repositories;

use App\Modules\Taxation\Domain\Entities\SalesWithholdingTracking;
use Illuminate\Support\Collection;

/**
 * Sales Withholding Tracking Repository Interface
 */
interface SalesWithholdingTrackingRepositoryInterface
{
    /**
     * Create a new sales withholding tracking record.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SalesWithholdingTracking;

    /**
     * Find tracking record by ID.
     */
    public function findById(string $id): ?SalesWithholdingTracking;

    /**
     * Find tracking record by document ID.
     */
    public function findByDocument(string $documentId): ?SalesWithholdingTracking;

    /**
     * Get all tracking records for a company.
     *
     * @return Collection<int, SalesWithholdingTracking>
     */
    public function getAllForCompany(string $companyId): Collection;

    /**
     * Get pending (certificate not received) tracking records for a company.
     *
     * @return Collection<int, SalesWithholdingTracking>
     */
    public function getPendingForCompany(string $companyId): Collection;

    /**
     * Update tracking record.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): SalesWithholdingTracking;
}
