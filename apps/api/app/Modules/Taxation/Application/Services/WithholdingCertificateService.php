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
use App\Modules\Taxation\Domain\ValueObjects\WithholdingCalculation;
use App\Modules\Treasury\Domain\Payment;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
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
        private readonly CurrencyScaleResolverInterface $scaleResolver,
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
                // Precision contract (rule 19): manualRatePercentage is already a
                // numeric-string (bcmath domain) — never float-cast it.
                $calculation = $this->calculationService->calculateWithOverride(
                    $data->grossAmount,
                    $data->currency,
                    $data->manualRatePercentage ?? '0',
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

            $this->assertNonZeroWithholding($calculation);

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
     * MTP-TRE-15 fix (precision contract rule 19): $overrideRate is a
     * numeric-string, NOT a float. PaymentController::store() passes the
     * FormRequest-validated `withholding_rate` (normalised to a canonical
     * string at the HTTP boundary — see
     * `PaymentController::normalizeWithholdingRate()`, review finding C4)
     * straight through — under strict_types=1 a `?float` parameter here
     * threw an uncaught TypeError (bare 500) on every payment carrying a
     * withholding override. Callers must NOT float-cast before calling this
     * — bcmath domain end to end, since the rate feeds a money computation
     * (gross_amount * rate).
     *
     * UNITS (review finding C5): `$overrideRate` is a FRACTION (0–1, e.g.
     * `"0.015"` for 1.5%) — the SAME domain `PaymentController.php`'s
     * `withholding_rate` FormRequest rule enforces
     * (`min:0`, `max:1`, 4dp regex — `max:1` makes a percentage
     * literally impossible to submit). It therefore calculates the
     * certificate DIRECTLY via `WithholdingCalculation::calculate()`
     * (the same fraction-domain entry point `calculateForPayment()` uses
     * for a matched rule's `->rate`), NOT via
     * `WithholdingCalculationService::calculateWithOverride()` — that
     * method is for the OTHER caller of this class, `create()`'s
     * `manual_rate_percentage` (0–100 PERCENTAGE, validated separately at
     * `CreateWithholdingCertificateRequest.php`), and divides by 100
     * internally. Routing a 0–1 fraction through that percentage-scaled
     * conversion silently produced a certificate ~100x too small (a 1.5%
     * rate on a 1190.000 invoice must withhold 17.850, not 0.119).
     *
     * @param  numeric-string|null  $overrideRate  Fraction (0–1), e.g. "0.015" for 1.5%.
     * @return WithholdingCertificateData The created certificate data
     */
    public function createFromPayment(
        Payment $payment,
        Document $document,
        ?string $overrideRate = null,
        ?string $overrideReason = null
    ): WithholdingCertificateData {
        return DB::transaction(function () use ($payment, $document, $overrideRate, $overrideReason) {
            $partner = $document->partner;

            // Calculate withholding using calculation service
            if ($overrideRate !== null) {
                $calculation = WithholdingCalculation::calculate(
                    $document->total ?? '0.00',
                    $overrideRate,
                    $document->currency,
                    null
                )->withOverride($overrideReason ?? 'Manual override');
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

            $this->assertNonZeroWithholding($calculation);

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
     * P1 fiscal guard (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md
     * #1, §20-69): a zero EFFECTIVE withholding amount — a zero rate, a zero
     * gross amount, or any combination that rounds to `0.000` at currency
     * scale 3 — must never manufacture a withholding certificate. Pre-fix,
     * `WithholdingCalculation::calculate()`/`calculateWithOverride()` had no
     * zero-rate guard, so `bcmul($gross, '0', 3)` silently produced a `0.000`
     * DRAFT row that was TEJ-exportable, PDF-printable, and — on `issue()` —
     * written into the withholding fiscal hash chain under the acting user's
     * identity. A stream of `0.000` certificates is a stream of fictitious
     * tax documents, and it inflates `generateCertificateNumber()`'s per-year
     * sequence so real certificates carry non-contiguous numbers.
     *
     * MUST be called AFTER the calculation is finalised but BEFORE
     * `certificateRepository->generateCertificateNumber()` (no sequence
     * number is derived for a phantom row) and BEFORE
     * `certificateRepository->create()` (no row is written at all) — the
     * guard is preventive, not corrective, because a payment-linked
     * certificate cannot be deleted through the API afterwards (only voided).
     *
     * Both callers of this guard (`create()`'s manual-override percentage
     * path and `createFromPayment()`'s fraction-rate path) route through it,
     * so neither entry point can slip a zero-amount certificate past it.
     *
     * @throws \DomainException Translated to an HTTP 422
     *                          (`WithholdingCertificateController::store()`'s existing
     *                          `catch (\DomainException $e)` -> `CREATION_FAILED`) for the direct-
     *                          create endpoint. `PaymentController::store()`'s existing
     *                          `try/catch (\DomainException)` around `createFromPayment()` swallows
     *                          this exception instead — the payment still settles at full gross
     *                          with no certificate (MTP-WHT-04), matching the pre-existing "no
     *                          applicable withholding rule found" \DomainException handling one
     *                          branch above.
     */
    private function assertNonZeroWithholding(WithholdingCalculation $calculation): void
    {
        // Precision contract (rule 19): currency-resolved scale, not a
        // hardcoded literal — the certificate's currency is always known
        // here (never null), so getScale() (not the *Safe variant) is the
        // correct call; a bound CompanyContext is not required.
        $scale = $this->scaleResolver->getScale($calculation->currency);

        if (bccomp($calculation->withholdingAmount, '0', $scale) === 0) {
            throw new \DomainException('Withholding amount is zero; no certificate is created.');
        }
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
