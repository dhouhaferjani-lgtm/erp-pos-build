<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Events;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Withholding Submitted to TEJ Event
 *
 * Dispatched when a certificate is submitted to Tunisia's TEJ platform.
 */
class WithholdingSubmittedToTEJ
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly WithholdingCertificate $certificate,
        public readonly string $tejReference,
        public readonly string $submittedBy
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
            'event' => 'withholding_submitted_to_tej',
            'certificate_id' => $this->certificate->id,
            'certificate_number' => $this->certificate->certificate_number,
            'year' => $this->certificate->year,
            'tej_reference' => $this->tejReference,
            'submitted_at' => $this->certificate->tej_submitted_at?->toIso8601String(),
            'submitted_by' => $this->submittedBy,
        ];
    }
}
