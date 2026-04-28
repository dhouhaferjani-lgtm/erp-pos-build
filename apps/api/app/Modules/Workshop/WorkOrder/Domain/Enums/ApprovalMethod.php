<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Enums;

/**
 * Channel through which customer approval of a quote was captured. Persisted
 * alongside the approver name / signature / evidence for audit.
 */
enum ApprovalMethod: string
{
    case InPerson = 'in_person';
    case Phone = 'phone';
    case Email = 'email';
    case Sms = 'sms';
    case SignedDocument = 'signed_document';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
