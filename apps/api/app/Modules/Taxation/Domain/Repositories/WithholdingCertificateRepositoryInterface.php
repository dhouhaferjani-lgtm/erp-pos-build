<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Repositories;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Collection;

interface WithholdingCertificateRepositoryInterface
{
    /**
     * Find a certificate by ID.
     */
    public function findById(string $id): ?WithholdingCertificate;

    /**
     * Find all certificates for a company.
     *
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, WithholdingCertificate>
     */
    public function findByCompany(string $companyId, array $filters = []): CursorPaginator;

    /**
     * Find certificates for a partner.
     *
     * @return Collection<int, WithholdingCertificate>
     */
    public function findByPartner(string $partnerId): Collection;

    /**
     * Find certificate for a payment.
     */
    public function findByPayment(string $paymentId): ?WithholdingCertificate;

    /**
     * Find certificates by direction.
     *
     * @return Collection<int, WithholdingCertificate>
     */
    public function findByDirection(string $companyId, WithholdingDirection $direction): Collection;

    /**
     * Find certificates by status.
     *
     * @return Collection<int, WithholdingCertificate>
     */
    public function findByStatus(string $companyId, CertificateStatus $status): Collection;

    /**
     * Find certificates for a year.
     *
     * @return Collection<int, WithholdingCertificate>
     */
    public function findByYear(string $companyId, int $year): Collection;

    /**
     * Get the last certificate in chain for hash calculation.
     */
    public function getLastInChain(string $companyId, WithholdingDirection $direction): ?WithholdingCertificate;

    /**
     * Generate next certificate number.
     */
    public function generateCertificateNumber(string $companyId, int $year): string;

    /**
     * Create a new certificate.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WithholdingCertificate;

    /**
     * Update a certificate.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): WithholdingCertificate;

    /**
     * Delete a certificate (only drafts).
     */
    public function delete(string $id): bool;
}
