<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Repositories;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Modules\Taxation\Domain\Repositories\WithholdingCertificateRepositoryInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentWithholdingCertificateRepository implements WithholdingCertificateRepositoryInterface
{
    public function findById(string $id): ?WithholdingCertificate
    {
        return WithholdingCertificate::with(['partner', 'document', 'rule', 'issuer'])
            ->find($id);
    }

    /** @return CursorPaginator<int, WithholdingCertificate> */
    public function findByCompany(string $companyId, array $filters = []): CursorPaginator
    {
        $query = WithholdingCertificate::where('company_id', $companyId)
            ->with(['partner', 'document', 'rule', 'issuer']);

        // Apply filters
        if (isset($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['year'])) {
            $query->where('year', $filters['year']);
        }

        if (isset($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('created_at')->cursorPaginate(15);
    }

    public function findByPartner(string $partnerId): Collection
    {
        return WithholdingCertificate::where('partner_id', $partnerId)
            ->with(['document', 'rule', 'issuer'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function findByPayment(string $paymentId): ?WithholdingCertificate
    {
        return WithholdingCertificate::where('payment_id', $paymentId)
            ->with(['partner', 'document', 'rule', 'issuer'])
            ->first();
    }

    public function findByDirection(string $companyId, WithholdingDirection $direction): Collection
    {
        return WithholdingCertificate::where('company_id', $companyId)
            ->where('direction', $direction)
            ->with(['partner', 'document', 'rule', 'issuer'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function findByStatus(string $companyId, CertificateStatus $status): Collection
    {
        return WithholdingCertificate::where('company_id', $companyId)
            ->where('status', $status)
            ->with(['partner', 'document', 'rule', 'issuer'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function findByYear(string $companyId, int $year): Collection
    {
        return WithholdingCertificate::where('company_id', $companyId)
            ->where('year', $year)
            ->with(['partner', 'document', 'rule', 'issuer'])
            ->orderBy('certificate_number')
            ->get();
    }

    public function getLastInChain(string $companyId, WithholdingDirection $direction): ?WithholdingCertificate
    {
        return WithholdingCertificate::where('company_id', $companyId)
            ->where('direction', $direction)
            ->whereNotNull('hash')
            ->whereNotNull('chain_sequence')
            ->orderByDesc('chain_sequence')
            ->first();
    }

    public function generateCertificateNumber(string $companyId, int $year): string
    {
        $lastCertificate = WithholdingCertificate::where('company_id', $companyId)
            ->where('year', $year)
            ->orderByDesc('certificate_number')
            ->first();

        if (! $lastCertificate) {
            return sprintf('WHT-%04d-0001', $year);
        }

        // Extract sequence number from last certificate
        // Format: WHT-YYYY-NNNN
        preg_match('/WHT-\d{4}-(\d{4})/', $lastCertificate->certificate_number, $matches);
        $lastSequence = isset($matches[1]) ? (int) $matches[1] : 0;

        return sprintf('WHT-%04d-%04d', $year, $lastSequence + 1);
    }

    public function create(array $data): WithholdingCertificate
    {
        return WithholdingCertificate::create($data);
    }

    public function update(string $id, array $data): WithholdingCertificate
    {
        $certificate = WithholdingCertificate::findOrFail($id);

        // Only allow updates if in draft status
        if (! $certificate->canBeModified()) {
            throw new \DomainException('Certificate cannot be modified in current status');
        }

        $certificate->update($data);

        /** @var WithholdingCertificate $freshCertificate */
        $freshCertificate = $certificate->fresh();

        return $freshCertificate;
    }

    public function delete(string $id): bool
    {
        $certificate = WithholdingCertificate::findOrFail($id);

        // Only allow deletion of drafts
        if ($certificate->status !== CertificateStatus::DRAFT) {
            throw new \DomainException('Only draft certificates can be deleted');
        }

        return $certificate->delete() ?? false;
    }
}
