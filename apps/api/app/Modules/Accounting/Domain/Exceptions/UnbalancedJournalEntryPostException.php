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
 * render **422** (`DeliveryNoteController:587`, `DocumentConversionController:471`,
 * `POS/ReceiptController:453`) — the exact downgrade that contract forbids. The
 * split removes the trade-off instead of picking a side.
 *
 * NOTE this class is an `\InvalidArgumentException`, so it is matched by any
 * `catch (\InvalidArgumentException)` on a path the chokepoint can reach. M1
 * reported the resulting downgrades as R-10
 * (`docs/handoff/reviews/enforcement-p3/M1-census.md` §6) and could not fix them —
 * only per-site narrowing can, which was outside 3(a).
 *
 * SUPERSEDED IN PART (R-10 lane, 2026-08-23): the two NARROW sites the census
 * named are now CLOSED at source — `DocumentConversionController:419` and
 * `POS/ReceiptController:379` each declare
 * `catch (UnbalancedJournalEntryPostException) { throw $e; }` ABOVE their broad
 * arm, so the refusal escapes to the global renderer's 500 instead of a 4xx.
 * R-10's remaining tail is still open and still downgrades: the broad
 * `catch (\Exception)` / `catch (\Throwable)` sites at `RefundController:154,254`,
 * `MultiPaymentController:214,338`, `PaymentRefundController:89,128,177` and
 * `TenantScopedCommand:333`.
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
