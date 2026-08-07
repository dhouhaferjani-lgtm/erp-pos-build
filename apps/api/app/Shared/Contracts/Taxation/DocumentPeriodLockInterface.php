<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Taxation;

use App\Modules\Document\Domain\Document;
use App\Modules\Taxation\Application\Services\VatPeriodCancellationGuard;
use App\Modules\Taxation\Domain\Enums\PeriodLockRefusalCode;
use App\Modules\Taxation\Domain\Exceptions\DocumentPeriodLockedException;

/**
 * Ask Taxation whether a document may still be withdrawn, given the state of the
 * VAT period that covers its own accounting date.
 *
 * R2-F1 / GL gate ruling 6a's second condition. `DocumentPostingService::cancel()`
 * now mirrors a document's SEALED GL legs into a reversing entry dated `now()`.
 * That dating is correct — back-dating would rewrite a period that may already be
 * CLOSED or FILED — but it is only *safe* while the original document's own
 * period is still OPEN. Once the period is locked, the cancellation is refused
 * and the accountant is pointed at a credit note (avoir) instead.
 *
 * Called from INSIDE the cancel transaction and BEFORE the GL reversal, so the
 * refusal rolls the cancel back with it and no reversal is ever sealed.
 *
 * Module boundaries stay intact: Document depends on this Shared contract, never
 * on the `VatPeriod` entity or on any Taxation service.
 *
 * @see VatPeriodCancellationGuard::assertCancellationPeriodIsOpen()
 */
interface DocumentPeriodLockInterface
{
    /**
     * @throws DocumentPeriodLockedException When a VAT period covering the
     *                                       document's accounting date exists and
     *                                       is CLOSED or FILED. A no-op when no
     *                                       period covers that date (nothing has
     *                                       been declared for it) or when the
     *                                       covering period is still OPEN.
     */
    public function assertCancellationPeriodIsOpen(Document $document): void;

    /**
     * The NON-throwing counterpart, for read models that must render a Cancel
     * button's availability without provoking an exception.
     *
     * Exists so the `can-cancel` endpoint can report the same verdict the cancel
     * itself would reach WITHOUT Document having to catch a Taxation exception —
     * the module boundary allows Document to depend on this contract, not on
     * `DocumentPeriodLockedException`. Returns the same stable codes the 422
     * carries ({@see PeriodLockRefusalCode}),
     * so front end and API agree by construction.
     *
     * @return string|null The refusal code, or NULL when the period permits the
     *                     cancellation (open period, absent period, or a document
     *                     type outside the lock).
     */
    public function cancellationRefusalCode(Document $document): ?string;
}
