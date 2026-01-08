<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Events;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Withholding Certificate Created Event
 *
 * Dispatched when a new withholding certificate is created.
 */
class WithholdingCertificateCreated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly WithholdingCertificate $certificate
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
            'event' => 'withholding_certificate_created',
            'certificate_id' => $this->certificate->id,
            'certificate_number' => $this->certificate->certificate_number,
            'year' => $this->certificate->year,
            'direction' => $this->certificate->direction->value,
            'partner_id' => $this->certificate->partner_id,
            'payment_id' => $this->certificate->payment_id,
            'gross_amount' => $this->certificate->gross_amount,
            'withholding_amount' => $this->certificate->withholding_amount,
            'net_amount' => $this->certificate->net_amount,
            'currency' => $this->certificate->currency,
            'status' => $this->certificate->status->value,
        ];
    }
}
