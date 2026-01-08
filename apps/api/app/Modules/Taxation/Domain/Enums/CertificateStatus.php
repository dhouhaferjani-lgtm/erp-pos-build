<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

/**
 * Withholding certificate lifecycle status.
 *
 * - DRAFT: Certificate created but not finalized
 * - ISSUED: Certificate finalized and ready for submission
 * - SUBMITTED: Certificate submitted to tax authority (TEJ in Tunisia)
 * - VOIDED: Certificate cancelled/voided
 */
enum CertificateStatus: string
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case SUBMITTED = 'submitted';
    case VOIDED = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::ISSUED => 'Issued',
            self::SUBMITTED => 'Submitted',
            self::VOIDED => 'Voided',
        };
    }

    public function canBeModified(): bool
    {
        return $this === self::DRAFT;
    }

    public function canBeIssued(): bool
    {
        return $this === self::DRAFT;
    }

    public function canBeSubmitted(): bool
    {
        return $this === self::ISSUED;
    }

    public function canBeVoided(): bool
    {
        return match ($this) {
            self::DRAFT, self::ISSUED => true,
            self::SUBMITTED, self::VOIDED => false,
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::SUBMITTED, self::VOIDED => true,
            self::DRAFT, self::ISSUED => false,
        };
    }
}
