<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Application\DTOs\CreateWithholdingCertificateData;
use App\Modules\Taxation\Application\DTOs\WithholdingCertificateData;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Modules\Taxation\Domain\Events\WithholdingCertificateCreated;
use App\Modules\Taxation\Domain\Events\WithholdingCertificateIssued;
use App\Modules\Taxation\Domain\Events\WithholdingCertificateVoided;
use App\Modules\Taxation\Domain\Events\WithholdingSubmittedToTEJ;
use App\Modules\Taxation\Domain\Repositories\WithholdingCertificateRepositoryInterface;
use App\Modules\Taxation\Domain\Services\WithholdingCalculationService;
use App\Modules\Treasury\Domain\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Withholding Certificate Service
 *
 * Application service for orchestrating withholding certificate operations.
 */
class WithholdingCertificateService
{
    public function __construct(
        private readonly WithholdingCertificateRepositoryInterface $certificateRepository,
        private readonly WithholdingCalculationService $calculationService,
        private readonly WithholdingHashChainService $hashChainService,
    ) {}

    /**
     * Create a new withholding certificate.
     *
     * Calculates withholding amount and creates certificate in draft status.
     */
    public function create(
        CreateWithholdingCertificateData $data,
        Partner $partner,
        string $tenantId
    ): WithholdingCertificateData {
        return DB::transaction(function () use ($data, $partner, $tenantId) {
            // Calculate withholding
            if ($data->isManualOverride()) {
                $calculation = $this->calculationService->calculateWithOverride(
                    $data->grossAmount,
                    $data->currency,
                    $data->manualRatePercentage ?? 0.0,
                    $data->overrideReason ?? 'Manual override',
                    $data->transactionType
                );
            } else {
                $calculation = $this->calculationService->calculateForPayment(
                    $partner,
                    $data->grossAmount,
                    $data->currency,
                    $partner->country_code ?? 'TN',
                    $data->companyId,
                    $data->transactionType
                );

                if (! $calculation) {
                    throw new \DomainException('No applicable withholding rule found');
                }
            }

            // Generate certificate number
            $year = now()->year;
            $certificateNumber = $this->certificateRepository->generateCertificateNumber(
                $data->companyId,
                $year
            );

            // Create certificate
            $certificate = $this->certificateRepository->create([
                'tenant_id' => $tenantId,
                'company_id' => $data->companyId,
                'certificate_number' => $certificateNumber,
                'year' => $year,
                'direction' => $data->direction,
                'partner_id' => $data->partnerId,
                'document_id' => $data->documentId,
                'payment_id' => $data->paymentId,
                'currency' => $calculation->currency,
                'gross_amount' => $calculation->grossAmount,
                'withholding_rate' => $calculation->withholdingRate,
                'withholding_amount' => $calculation->withholdingAmount,
                'net_amount' => $calculation->netAmount,
                'withholding_rule_id' => $calculation->ruleId,
                'override_reason' => $calculation->overrideReason,
                'status' => CertificateStatus::DRAFT,
            ]);

            // Dispatch event
            DB::afterCommit(function () use ($certificate): void {
                event(new WithholdingCertificateCreated($certificate));
            });

            return WithholdingCertificateData::fromEntity($certificate);
        });
    }

    /**
     * Create a withholding certificate from a payment.
     *
     * Simplified method for payment integration - creates a draft certificate
     * linked to a payment and document.
     *
     * @return WithholdingCertificateData The created certificate data
     */
    public function createFromPayment(
        Payment $payment,
        Document $document,
        ?float $overrideRate = null,
        ?string $overrideReason = null
    ): WithholdingCertificateData {
        return DB::transaction(function () use ($payment, $document, $overrideRate, $overrideReason) {
            $partner = $document->partner;

            // Calculate withholding using calculation service
            if ($overrideRate !== null) {
                $calculation = $this->calculationService->calculateWithOverride(
                    $document->total ?? '0.00',
                    $document->currency,
                    $overrideRate,
                    $overrideReason ?? 'Manual override',
                    null
                );
            } else {
                $calculation = $this->calculationService->calculateForPayment(
                    $partner,
                    $document->total ?? '0.00',
                    $document->currency,
                    $partner->country_code ?? 'TN',
                    $document->company_id,
                    null
                );

                // If no rule matched, return without creating certificate
                if (! $calculation) {
                    throw new \DomainException('No applicable withholding rule found for this payment');
                }
            }

            // Generate certificate number
            $year = now()->year;
            $certificateNumber = $this->certificateRepository->generateCertificateNumber(
                $payment->company_id,
                $year
            );

            // Create draft certificate
            $certificate = $this->certificateRepository->create([
                'tenant_id' => $payment->tenant_id,
                'company_id' => $payment->company_id,
                'certificate_number' => $certificateNumber,
                'year' => $year,
                'direction' => WithholdingDirection::PURCHASE,
                'partner_id' => $document->partner_id,
                'document_id' => $document->id,
                'payment_id' => $payment->id,
                'currency' => $payment->currency,
                'gross_amount' => $document->total ?? '0.00',
                'withholding_rate' => $calculation->withholdingRate,
                'withholding_amount' => $calculation->withholdingAmount,
                'net_amount' => $calculation->netAmount,
                'withholding_rule_id' => $calculation->ruleId,
                'override_reason' => $overrideReason,
                'status' => CertificateStatus::DRAFT,
            ]);

            // Dispatch event
            DB::afterCommit(function () use ($certificate): void {
                event(new WithholdingCertificateCreated($certificate));
            });

            return WithholdingCertificateData::fromEntity($certificate);
        });
    }

    /**
     * Issue a certificate (finalize it with hash chain).
     */
    public function issue(string $certificateId, string $userId): WithholdingCertificateData
    {
        return DB::transaction(function () use ($certificateId, $userId) {
            $certificate = $this->certificateRepository->findById($certificateId);

            if (! $certificate) {
                throw new \DomainException('Certificate not found');
            }

            if (! $certificate->canBeIssued()) {
                throw new \DomainException('Certificate cannot be issued in current status');
            }

            // Calculate hash chain
            $lastCertificate = $this->certificateRepository->getLastInChain(
                $certificate->company_id,
                $certificate->direction
            );

            $chainSequence = ($lastCertificate !== null ? $lastCertificate->chain_sequence : 0) + 1;
            $issuedAt = now();

            // Temporarily set issued_at for hash calculation
            $certificate->issued_at = $issuedAt;
            $certificate->chain_sequence = $chainSequence;

            $hash = $this->hashChainService->calculateHash(
                $certificate,
                $lastCertificate?->hash
            );

            // Update certificate directly (bypass repository's draft-only check)
            $certificate->update([
                'status' => CertificateStatus::ISSUED,
                'hash' => $hash,
                'previous_hash' => $lastCertificate?->hash,
                'chain_sequence' => $chainSequence,
                'issued_at' => $issuedAt,
                'issued_by' => $userId,
            ]);

            $certificate->refresh();

            // Dispatch event
            DB::afterCommit(function () use ($certificate, $userId): void {
                event(new WithholdingCertificateIssued($certificate, $userId));
            });

            return WithholdingCertificateData::fromEntity($certificate);
        });
    }

    /**
     * Void a certificate.
     */
    public function void(string $certificateId, string $reason, string $userId): WithholdingCertificateData
    {
        return DB::transaction(function () use ($certificateId, $reason, $userId) {
            $certificate = $this->certificateRepository->findById($certificateId);

            if (! $certificate) {
                throw new \DomainException('Certificate not found');
            }

            if (! $certificate->canBeVoided()) {
                throw new \DomainException('Certificate cannot be voided in current status');
            }

            // Update certificate directly (bypass repository's draft-only check)
            $certificate->update([
                'status' => CertificateStatus::VOIDED,
            ]);

            $certificate->refresh();

            // Dispatch event
            DB::afterCommit(function () use ($certificate, $reason, $userId): void {
                event(new WithholdingCertificateVoided($certificate, $reason, $userId));
            });

            return WithholdingCertificateData::fromEntity($certificate);
        });
    }

    /**
     * Mark certificate as submitted to TEJ.
     */
    public function submitToTEJ(string $certificateId, string $tejReference, string $userId): WithholdingCertificateData
    {
        return DB::transaction(function () use ($certificateId, $tejReference, $userId) {
            $certificate = $this->certificateRepository->findById($certificateId);

            if (! $certificate) {
                throw new \DomainException('Certificate not found');
            }

            if (! $certificate->canBeSubmitted()) {
                throw new \DomainException('Certificate cannot be submitted in current status');
            }

            // Update certificate directly (bypass repository's draft-only check)
            $certificate->update([
                'status' => CertificateStatus::SUBMITTED,
                'tej_reference' => $tejReference,
                'tej_submitted_at' => now(),
            ]);

            $certificate->refresh();

            // Dispatch event
            DB::afterCommit(function () use ($certificate, $tejReference, $userId): void {
                event(new WithholdingSubmittedToTEJ($certificate, $tejReference, $userId));
            });

            return WithholdingCertificateData::fromEntity($certificate);
        });
    }

    /**
     * Get certificate by ID.
     */
    public function findById(string $certificateId): ?WithholdingCertificateData
    {
        $certificate = $this->certificateRepository->findById($certificateId);

        return $certificate ? WithholdingCertificateData::fromEntity($certificate) : null;
    }

    /**
     * Get certificate for a payment.
     */
    public function findByPayment(string $paymentId): ?WithholdingCertificateData
    {
        $certificate = $this->certificateRepository->findByPayment($paymentId);

        return $certificate ? WithholdingCertificateData::fromEntity($certificate) : null;
    }

    /**
     * Verify hash chain integrity for a company.
     */
    public function verifyHashChain(string $companyId, string $direction): bool
    {
        return $this->hashChainService->verifyChain($companyId, $direction);
    }
}
