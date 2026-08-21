<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;

/**
 * The GL POSTING CHOKEPOINT's balance refusal —
 * {@see GeneralLedgerService::sealAndPersistEntry()}
 * found Sigma(debits) != Sigma(credits) and refused before sealing.
 *
 * **Why this is a SEPARATE class from {@see UnbalancedJournalEntryException}
 * (enforcement-P3 M1, round 1).** The two refusals look alike but have opposite
 * catch semantics, and collapsing them onto one type broke a live API contract in
 * whichever direction the shared parent was chosen:
 *
 *  - `UnbalancedJournalEntryException` (a `\RuntimeException`) is thrown by
 *    `AccountingService::assertLegsBalance()` — POST-SEAL, document-sourced. Its
 *    `\RuntimeException` parent is load-bearing: `CreditNoteController::post()`
 *    catches it at `:406` and renders a structured `500 CONFIGURATION_ERROR`, and
 *    its docblock deliberately specifies "an unmapped `RuntimeException` (a 500 +
 *    alert), never a 422".
 *  - THIS class is thrown at POST time by the chokepoint, which historically threw
 *    a BARE `\InvalidArgumentException`. Extending that class keeps the blast
 *    radius **byte-identical to the pre-M1 behaviour** — every `catch` that
 *    matched the chokepoint's refusal before still matches it, and none that did
 *    not match starts matching — while finally giving callers a NAME they can
 *    single out. That is the whole of deliverable D.
 *
 * M1 first tried one shared type and measured the damage both ways: parenting it
 * under `\InvalidArgumentException` broke `CreditNoteController`'s envelope and
 * invalidated the "never a 422" contract; parenting it under `\RuntimeException`
 * newly exposed the chokepoint's refusal to `catch (\RuntimeException)` blocks that
 * render **422** (`DeliveryNoteController:587`, `DocumentConversionController:432`,
 * `POS/ReceiptController:378`) — the exact downgrade that contract forbids. The
 * split removes the trade-off instead of picking a side.
 *
 * NOTE this class is an `\InvalidArgumentException`, so the two PRE-EXISTING
 * `catch (\InvalidArgumentException)` sites that already downgraded the
 * chokepoint's bare refusal to 4xx still do so. That is unchanged from before M1
 * and is reported, not introduced — see
 * `docs/handoff/reviews/enforcement-p3/M1-census.md` §6 R-10. Fixing it requires
 * per-site narrowing at those catch sites, which is outside 3(a).
 */
final class UnbalancedJournalEntryPostException extends \InvalidArgumentException
{
    /**
     * The message is BYTE-IDENTICAL to the string the chokepoint raised before the
     * type was named — existing assertions on it stay valid.
     *
     * @param  numeric-string  $totalDebit
     * @param  numeric-string  $totalCredit
     */
    public static function forChokepoint(string $totalDebit, string $totalCredit): self
    {
        return new self(sprintf(
            'Cannot post unbalanced journal entry: total debit %s does not equal total credit %s.',
            $totalDebit,
            $totalCredit,
        ));
    }
}
