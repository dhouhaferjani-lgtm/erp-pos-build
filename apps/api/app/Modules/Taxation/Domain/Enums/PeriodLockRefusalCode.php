<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

/**
 * Stable API error codes for a refusal raised by the VAT-period lock.
 *
 * CLOSED and FILED get DISTINCT codes on purpose: the remedies differ in kind.
 * A CLOSED period can still be reopened by an accountant
 * (`VatPeriodManagementService::reopenPeriod()`, allowed while no successor
 * period is itself closed or filed), so the front end can offer "ask your
 * accountant to reopen <label>". A FILED period cannot be undone
 * — the declaration is with the tax authority — so the only lawful remedy is a
 * credit note (avoir). Collapsing both into one code would force the UI to guess.
 */
enum PeriodLockRefusalCode: string
{
    case PeriodClosed = 'DOCUMENT_PERIOD_CLOSED';
    case PeriodFiled = 'DOCUMENT_PERIOD_FILED';

    /**
     * @throws \LogicException When called with OPEN — an open period is not a refusal.
     */
    public static function fromPeriodStatus(VatPeriodStatus $status): self
    {
        return match ($status) {
            VatPeriodStatus::Closed => self::PeriodClosed,
            VatPeriodStatus::Filed => self::PeriodFiled,
            VatPeriodStatus::Open => throw new \LogicException(
                'An OPEN VAT period is not a refusal reason.'
            ),
        };
    }

    /**
     * Translation key for the user-facing message (lang/{locale}/messages.php).
     */
    public function translationKey(): string
    {
        return match ($this) {
            self::PeriodClosed => 'messages.taxation.cancel_refused_period_closed',
            self::PeriodFiled => 'messages.taxation.cancel_refused_period_filed',
        };
    }
}
