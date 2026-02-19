<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Taxation\Application\DTOs\CreateSalesWithholdingTrackingData;
use App\Modules\Taxation\Application\DTOs\SalesWithholdingTrackingData;
use App\Modules\Taxation\Domain\Repositories\SalesWithholdingTrackingRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales Withholding Tracking Service
 *
 * Manages tracking of withholding tax applied by customers on sales invoices.
 */
class SalesWithholdingTrackingService
{
    public function __construct(
        private readonly SalesWithholdingTrackingRepositoryInterface $repository,
    ) {}

    /**
     * Record withholding applied by customer on a sales document.
     */
    public function recordWithholding(
        Document $document,
        CreateSalesWithholdingTrackingData $data,
        string $companyId,
        string $tenantId
    ): SalesWithholdingTrackingData {
        return DB::transaction(function () use ($document, $data, $companyId, $tenantId) {
            // Check if withholding already recorded for this document
            $existing = $this->repository->findByDocument($document->id);

            if ($existing) {
                throw new \DomainException('Withholding already recorded for this document');
            }

            // Create tracking record
            $trackingData = array_merge($data->toArray(), [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
            ]);

            $tracking = $this->repository->create($trackingData);

            return SalesWithholdingTrackingData::fromEntity($tracking);
        });
    }

    /**
     * Get all tracking records for a company.
     *
     * @return Collection<int, SalesWithholdingTrackingData>
     */
    public function getAllForCompany(string $companyId): Collection
    {
        return $this->repository->getAllForCompany($companyId)
            ->map(fn ($tracking) => SalesWithholdingTrackingData::fromEntity($tracking));
    }

    /**
     * Get pending (certificate not received) tracking records for a company.
     *
     * @return Collection<int, SalesWithholdingTrackingData>
     */
    public function getPendingForCompany(string $companyId): Collection
    {
        return $this->repository->getPendingForCompany($companyId)
            ->map(fn ($tracking) => SalesWithholdingTrackingData::fromEntity($tracking));
    }

    /**
     * Mark certificate as received.
     */
    public function markCertificateReceived(
        string $trackingId,
        string $certificateNumber
    ): SalesWithholdingTrackingData {
        return DB::transaction(function () use ($trackingId, $certificateNumber) {
            $tracking = $this->repository->findById($trackingId);

            if (! $tracking) {
                throw new \DomainException('Sales withholding tracking record not found');
            }

            if ($tracking->certificate_received) {
                throw new \DomainException('Certificate already marked as received');
            }

            $tracking->markCertificateReceived($certificateNumber);

            return SalesWithholdingTrackingData::fromEntity($tracking->fresh(['document', 'customer', 'payment']));
        });
    }

    /**
     * Get tracking record by ID.
     */
    public function findById(string $id): ?SalesWithholdingTrackingData
    {
        $tracking = $this->repository->findById($id);

        return $tracking ? SalesWithholdingTrackingData::fromEntity($tracking) : null;
    }

    /**
     * Get tracking record by document ID.
     */
    public function findByDocument(string $documentId): ?SalesWithholdingTrackingData
    {
        $tracking = $this->repository->findByDocument($documentId);

        return $tracking ? SalesWithholdingTrackingData::fromEntity($tracking) : null;
    }
}
