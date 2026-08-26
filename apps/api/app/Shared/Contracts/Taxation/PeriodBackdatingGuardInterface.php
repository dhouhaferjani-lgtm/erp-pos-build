<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Taxation;

use App\Shared\Domain\Enums\ReturnPeriodRefusalCode;
use App\Shared\Exceptions\ReturnPeriodLockedException;
use Carbon\CarbonInterface;

/**
 * Ask Taxation whether a document may be DATED into a given period.
 *
 * Plan CF CF-D3. The owner ruling makes option 2 of the guided cancel flow
 * ("products were already returned, on date X") a backdating action, and requires
 * it to follow "the same period rules as a manually-dated return note". This is
 * that rule, expressed once and shared, so the manual
 * `POST /return-notes/{id}/confirm` and the composite `POST /invoices/{id}/cancel`
 * cannot drift apart.
 *
 * DISTINCT FROM {@see DocumentPeriodLockInterface}, deliberately. That contract
 * asks whether a ledger-bearing document may be WITHDRAWN, keyed on the
 * document's own `document_date`, and its population is narrowed to the types that
 * reach the ledger or the declaration — which excludes ReturnNote. This contract
 * asks a different question about a different date (the date the user typed) and
 * carries its own refusal codes, so `DocumentPeriodLockInterface` is NOT widened.
 *
 * BOTH PERIOD TABLES (fiscal gate I-1, orchestrator-ratified):
 *  - `vat_periods` CLOSED/FILED — honours the owner's literal "R-c/c2 model"
 *    wording;
 *  - `fiscal_periods` Closed/Locked — the semantically correct protection, since a
 *    return note reaches neither the declaration nor the ledger today and the
 *    c1-bis lane will date its future GL entry on `document_date`.
 *
 * Both are ABSENT-PERMITS, so no launch tenant regresses.
 *
 * Keyed on PRIMITIVES (company id, date, document number) rather than a Document,
 * so this contract adds no dependency on a module tier.
 */
interface PeriodBackdatingGuardInterface
{
    /**
     * @param  string  $documentNumber  Carried only so the refusal can name the
     *                                  document in its user-facing message.
     *
     * @throws ReturnPeriodLockedException When a `vat_periods` row covering the
     *                                     date is CLOSED or FILED, or a
     *                                     `fiscal_periods` row covering it is
     *                                     Closed or Locked. A no-op when no row
     *                                     covers the date in either table, or when
     *                                     every covering row is still open.
     */
    public function assertBackdatingPeriodIsOpen(string $companyId, CarbonInterface $date, string $documentNumber): void;

    /**
     * The NON-throwing counterpart, for read models that must render a date
     * field's availability without provoking an exception.
     *
     * Returns the same stable codes the 422 carries — the backing values of
     * {@see ReturnPeriodRefusalCode} — so front end and
     * API agree by construction, the property `RefundService::cancellationBlockReason()`
     * exists to guarantee for the cancel button.
     *
     * @return string|null The refusal code, or NULL when the date is permitted.
     */
    public function backdatingRefusalCode(string $companyId, CarbonInterface $date): ?string;

    /**
     * The refusal WITH the period it names and whether that period can actually
     * be reopened — everything a caller needs to render an ACTIONABLE refusal.
     *
     * B-19 fix round 2 (N2, both r2 gates). The return-note path can afford a
     * bare refusal because its own docblock's premise holds there: the remedy is
     * a single `PATCH` of the draft's `document_date`. That premise does NOT
     * transfer to a supplier invoice — `Procurement/Presentation/routes.php`
     * exposes no update, no PATCH and no delete — so a bare refusal strands the
     * document with no visible way forward, and the refusal blocks the whole
     * `Dr 408 / Dr 4456 / Cr 401` recognition, a bookkeeping obligation that
     * exists independently of the declaration. The refusal is still correct (a
     * closed or filed declaration must never be rewritten); it just has to say
     * what the operator can do about it.
     *
     * `reopenable` is the honest answer, not an optimistic one:
     * `VatPeriodManagementService::reopenPeriod()` refuses a FILED period
     * outright AND refuses a CLOSED period that has any closed/filed successor,
     * so offering "ask your accountant to reopen it" in either case would be
     * offering a remedy the system will reject.
     *
     * Keyed on primitives and returning primitives, like the rest of this
     * contract, so no caller gains a dependency on a module tier.
     *
     * @return array{
     *     code: string,
     *     period_id: string|null,
     *     period_label: string|null,
     *     period_start: string|null,
     *     period_end: string|null,
     *     reopenable: bool
     * }|null NULL when the date is permitted. `period_*` are NULL when the
     *        refusal came from `fiscal_periods` rather than `vat_periods` —
     *        that table is not reopenable through this route at all, so
     *        `reopenable` is false.
     */
    public function backdatingRefusalDetail(string $companyId, CarbonInterface $date): ?array;
}
