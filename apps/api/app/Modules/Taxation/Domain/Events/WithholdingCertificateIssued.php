<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Events;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Withholding Certificate Issued Event
 *
 * Dispatched when a certificate is finalized and issued.
 * This is a fiscal event and should be part of the hash chain.
 */
class WithholdingCertificateIssued
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly WithholdingCertificate $certificate,
        public readonly string $issuedBy
    ) {}

    /**
     * Get the certificate ID for event logging.
     */
    public function getCertificateId(): string
    {
        return $this->certificate->id;
    }

    /**
     * Get event payload for audit log.
     *
     * @return array<string, mixed>
     */
    public function toAuditLog(): array
    {
        return [
            'event' => 'withholding_certificate_issued',
            'certificate_id' => $this->certificate->id,
            'certificate_number' => $this->certificate->certificate_number,
            'year' => $this->certificate->year,
            'direction' => $this->certificate->direction->value,
            'partner_id' => $this->certificate->partner_id,
            'gross_amount' => $this->certificate->gross_amount,
            'withholding_amount' => $this->certificate->withholding_amount,
            'currency' => $this->certificate->currency,
            'issued_at' => $this->certificate->issued_at?->toIso8601String(),
            'issued_by' => $this->issuedBy,
            'hash' => $this->certificate->hash,
            'chain_sequence' => $this->certificate->chain_sequence,
        ];
    }
}
