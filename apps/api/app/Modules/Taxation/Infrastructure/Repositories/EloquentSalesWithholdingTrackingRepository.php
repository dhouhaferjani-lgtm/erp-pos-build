<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Repositories;

use App\Modules\Taxation\Domain\Entities\SalesWithholdingTracking;
use App\Modules\Taxation\Domain\Repositories\SalesWithholdingTrackingRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Eloquent Sales Withholding Tracking Repository
 */
class EloquentSalesWithholdingTrackingRepository implements SalesWithholdingTrackingRepositoryInterface
{
    public function create(array $data): SalesWithholdingTracking
    {
        return SalesWithholdingTracking::create($data);
    }

    public function findById(string $id): ?SalesWithholdingTracking
    {
        return SalesWithholdingTracking::with(['document', 'customer', 'payment'])
            ->find($id);
    }

    public function findByDocument(string $documentId): ?SalesWithholdingTracking
    {
        return SalesWithholdingTracking::with(['document', 'customer', 'payment'])
            ->where('document_id', $documentId)
            ->first();
    }

    public function getAllForCompany(string $companyId): Collection
    {
        return SalesWithholdingTracking::with(['document', 'customer', 'payment'])
            ->where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getPendingForCompany(string $companyId): Collection
    {
        return SalesWithholdingTracking::with(['document', 'customer', 'payment'])
            ->where('company_id', $companyId)
            ->where('certificate_received', false)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function update(string $id, array $data): SalesWithholdingTracking
    {
        $tracking = $this->findById($id);

        if (! $tracking) {
            throw new \DomainException('Sales withholding tracking record not found');
        }

        $tracking->update($data);

        return $tracking->fresh(['document', 'customer', 'payment']);
    }
}
