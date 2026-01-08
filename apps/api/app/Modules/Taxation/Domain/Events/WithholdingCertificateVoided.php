<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Events;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Withholding Certificate Voided Event
 *
 * Dispatched when a certificate is voided/cancelled.
 */
class WithholdingCertificateVoided
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly WithholdingCertificate $certificate,
        public readonly string $reason,
        public readonly string $voidedBy
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
            'event' => 'withholding_certificate_voided',
            'certificate_id' => $this->certificate->id,
            'certificate_number' => $this->certificate->certificate_number,
            'year' => $this->certificate->year,
            'direction' => $this->certificate->direction->value,
            'reason' => $this->reason,
            'voided_by' => $this->voidedBy,
            'voided_at' => now()->toIso8601String(),
        ];
    }
}
