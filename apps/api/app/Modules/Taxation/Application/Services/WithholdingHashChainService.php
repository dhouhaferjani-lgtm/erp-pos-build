<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Repositories\WithholdingCertificateRepositoryInterface;

/**
 * Withholding Hash Chain Service
 *
 * Service for calculating and verifying fiscal hash chains for withholding certificates.
 * Each certificate links to the previous via SHA-256 hash for immutability.
 */
class WithholdingHashChainService
{
    public function __construct(
        private readonly WithholdingCertificateRepositoryInterface $certificateRepository
    ) {}

    /**
     * Calculate hash for a withholding certificate.
     *
     * Hash includes all fiscal-critical fields plus previous hash for chain integrity.
     */
    public function calculateHash(WithholdingCertificate $certificate, ?string $previousHash): string
    {
        $data = $this->serializeForHashing($certificate);
        $payload = ($previousHash ?? '') . '|' . $data;

        return hash('sha256', $payload);
    }

    /**
     * Verify hash chain integrity for a company and direction.
     *
     * Checks all certificates in sequence to ensure chain is unbroken.
     */
    public function verifyChain(string $companyId, string $direction): bool
    {
        $certificates = WithholdingCertificate::where('company_id', $companyId)
            ->where('direction', $direction)
            ->whereNotNull('hash')
            ->whereNotNull('chain_sequence')
            ->orderBy('chain_sequence')
            ->get();

        if ($certificates->isEmpty()) {
            return true; // No chain to verify
        }

        $previousHash = null;

        foreach ($certificates as $certificate) {
            $expectedHash = $this->calculateHash($certificate, $previousHash);

            if ($expectedHash !== $certificate->hash) {
                return false; // Chain broken!
            }

            // Verify previous_hash reference matches
            if ($previousHash !== $certificate->previous_hash) {
                return false; // Previous hash mismatch!
            }

            $previousHash = $certificate->hash;
        }

        return true;
    }

    /**
     * Serialize certificate data for hashing.
     *
     * Only includes fiscally-critical immutable fields.
     */
    private function serializeForHashing(WithholdingCertificate $certificate): string
    {
        $fields = [
            'certificate_number' => $certificate->certificate_number,
            'year' => $certificate->year,
            'direction' => $certificate->direction->value,
            'partner_id' => $certificate->partner_id,
            'payment_id' => $certificate->payment_id,
            'currency' => $certificate->currency,
            'gross_amount' => $certificate->gross_amount,
            'withholding_rate' => $certificate->withholding_rate,
            'withholding_amount' => $certificate->withholding_amount,
            'net_amount' => $certificate->net_amount,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'chain_sequence' => $certificate->chain_sequence,
        ];

        return json_encode($fields, JSON_THROW_ON_ERROR);
    }

    /**
     * Get hash chain details for a company.
     *
     * Returns statistics about the hash chain.
     *
     * @return array{total_certificates: int, last_sequence: int|null, last_hash: string|null, chain_valid: bool}
     */
    public function getChainDetails(string $companyId, string $direction): array
    {
        $lastCertificate = $this->certificateRepository->getLastInChain($companyId, $direction);

        $totalCertificates = WithholdingCertificate::where('company_id', $companyId)
            ->where('direction', $direction)
            ->whereNotNull('hash')
            ->count();

        return [
            'total_certificates' => $totalCertificates,
            'last_sequence' => $lastCertificate?->chain_sequence,
            'last_hash' => $lastCertificate?->hash,
            'chain_valid' => $this->verifyChain($companyId, $direction),
        ];
    }
}
