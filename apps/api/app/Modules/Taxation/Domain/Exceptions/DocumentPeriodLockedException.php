<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Exceptions;

use App\Modules\Taxation\Domain\Enums\PeriodLockRefusalCode;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use DomainException;

/**
 * Thrown when a document is withdrawn (cancelled) while the VAT period covering
 * its own `document_date` is no longer OPEN.
 *
 * R2-F1, from the L2 GL gate's ruling 6a. That ruling fixed the reversal's
 * `entry_date` at `now()` — deliberately, so a cancellation never retroactively
 * rewrites a period that may already be CLOSED or FILED — and attached a SECOND
 * condition that was ticketed rather than implemented: once the original
 * document's period is locked, the cancel itself must be refused. In FR/TN an
 * invoice whose period is already declared is not cancelled at all; it is
 * credited (avoir).
 *
 * Refusing makes the `now()` dating decision unambiguously safe: a cancel can
 * then only ever reverse VAT that is still in an OPEN period.
 *
 * Extending `DomainException` means the generic `bootstrap/app.php` handler would
 * already render it as a 422; a dedicated handler is registered BEFORE that one
 * so the response carries the typed {@see PeriodLockRefusalCode} instead of the
 * catch-all `BUSINESS_ERROR`. Because the guard runs INSIDE the cancel
 * transaction, the refusal rolls the whole cancel back with it — no document is
 * left half-withdrawn and no reversal entry is sealed.
 *
 * docs/superpowers/tickets/2026-08-06-l2-gl-vat-declaration-desync.md
 * docs/superpowers/tickets/2026-08-07-round2-rulings-record.md (R-c c2)
 */
final class DocumentPeriodLockedException extends DomainException
{
    private function __construct(
        string $message,
        public readonly PeriodLockRefusalCode $refusalCode,
        public readonly string $documentNumber,
        public readonly string $periodLabel,
        public readonly VatPeriodStatus $periodStatus,
    ) {
        parent::__construct($message);
    }

    public static function forDocument(
        string $documentNumber,
        string $periodLabel,
        VatPeriodStatus $periodStatus,
    ): self {
        $refusalCode = PeriodLockRefusalCode::fromPeriodStatus($periodStatus);

        return new self(
            (string) __($refusalCode->translationKey(), [
                'document' => $documentNumber,
                'period' => $periodLabel,
            ]),
            $refusalCode,
            $documentNumber,
            $periodLabel,
            $periodStatus,
        );
    }
}
