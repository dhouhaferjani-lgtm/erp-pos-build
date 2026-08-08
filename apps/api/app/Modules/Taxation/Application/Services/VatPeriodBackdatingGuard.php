<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Repositories\VatPeriodRepositoryInterface;
use App\Shared\Contracts\Accounting\FiscalPeriodLockReaderInterface;
use App\Shared\Contracts\Taxation\PeriodBackdatingGuardInterface;
use App\Shared\Domain\Enums\ReturnPeriodRefusalCode;
use App\Shared\Exceptions\ReturnPeriodLockedException;
use Carbon\CarbonInterface;

/**
 * Refuses to DATE a document into a period that is no longer open.
 *
 * Plan CF T3 / CF-D3. The owner ruling requires option 2 of the guided cancel
 * flow ("products were already returned, on date X") to follow "the same period
 * rules as a manually-dated return note". This guard is that rule, and it is
 * placed behind a Shared contract so `ReturnNoteService::confirm()` — the ONE
 * confirm path both the manual route and the composite go through — can call it
 * without Document depending on Taxation internals.
 *
 * WHY THIS IS A NEW GUARD AND NOT A WIDENING OF `VatPeriodCancellationGuard`.
 * That guard answers "may this ledger-bearing document be WITHDRAWN", keyed on the
 * document's own `document_date`, and its `refusalAppliesTo()` seam deliberately
 * EXCLUDES ReturnNote with a documented reason: a return note posts no journal
 * entry, so locking its cancellation would be "a fiscal refusal with no fiscal
 * justification", and — the part that matters here — it would make a return note
 * PERMANENTLY uncancellable. Widening that seam would inherit both the wrong
 * question and the wrong remedy. This guard asks about the date the USER typed,
 * and its refusal is recoverable by a single `PATCH` of the draft's
 * `document_date` (`ReturnNoteController::update()`, gated on `isDraft()`).
 *
 * ORDER OF THE TWO CHECKS. `vat_periods` first, because its two states carry
 * different remedies the UI must distinguish (CLOSED is reopenable, FILED never
 * is), and a caller shown "the books are locked" when the real obstacle is a filed
 * declaration would be sent to the wrong person. The `fiscal_periods` check is the
 * fallback, and its code is deliberately a third one.
 *
 * BOTH TABLES ARE ABSENT-PERMITS. See {@see ReturnPeriodRefusalCode} for the
 * reasoning and the house precedent.
 *
 * NOTE — do not repeat the premise that return notes "write no
 * `document_tax_details` row" (fiscal gate M-3: that is false;
 * `ReturnNoteService::confirmWithFiscalChain()` calls `snapshotTaxDetails()`). The
 * correct premise is that the VAT declaration never READS return-note rows —
 * `EloquentVatDataRepository::aggregateByRateAndDirection()` restricts to
 * invoice / credit_note / expense — which is why this is document and ledger
 * integrity rather than declaration integrity.
 */
final class VatPeriodBackdatingGuard implements PeriodBackdatingGuardInterface
{
    public function __construct(
        private readonly VatPeriodRepositoryInterface $periodRepository,
        private readonly FiscalPeriodLockReaderInterface $fiscalPeriodLock,
    ) {}

    public function assertBackdatingPeriodIsOpen(string $companyId, CarbonInterface $date, string $documentNumber): void
    {
        $vatPeriod = $this->periodRepository->findLockedPeriodCoveringDate($companyId, $date);

        if ($vatPeriod !== null) {
            throw ReturnPeriodLockedException::forDate(
                $this->vatRefusalCode($vatPeriod->status),
                $documentNumber,
                $date->toDateString(),
                $vatPeriod->label,
            );
        }

        if ($this->fiscalPeriodLock->isDateInClosedFiscalPeriod($companyId, $date)) {
            throw ReturnPeriodLockedException::forDate(
                ReturnPeriodRefusalCode::PeriodLocked,
                $documentNumber,
                $date->toDateString(),
            );
        }
    }

    public function backdatingRefusalCode(string $companyId, CarbonInterface $date): ?string
    {
        $vatPeriod = $this->periodRepository->findLockedPeriodCoveringDate($companyId, $date);

        if ($vatPeriod !== null) {
            return $this->vatRefusalCode($vatPeriod->status)->value;
        }

        if ($this->fiscalPeriodLock->isDateInClosedFiscalPeriod($companyId, $date)) {
            return ReturnPeriodRefusalCode::PeriodLocked->value;
        }

        return null;
    }

    /**
     * `findLockedPeriodCoveringDate()` only ever returns a CLOSED or FILED row, so
     * OPEN here is unreachable — and `LogicException` rather than a silent fallback
     * is the right response if that repository contract ever changes, because
     * silently reporting "closed" for an open period is the class of error this
     * guard exists to prevent.
     */
    private function vatRefusalCode(VatPeriodStatus $status): ReturnPeriodRefusalCode
    {
        return match ($status) {
            VatPeriodStatus::Closed => ReturnPeriodRefusalCode::PeriodClosed,
            VatPeriodStatus::Filed => ReturnPeriodRefusalCode::PeriodFiled,
            VatPeriodStatus::Open => throw new \LogicException(
                'An OPEN VAT period is not a refusal reason; findLockedPeriodCoveringDate() must never return one.'
            ),
        };
    }
}
