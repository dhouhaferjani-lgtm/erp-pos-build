<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

/**
 * The stable reasons a correcting-entry document can be refused (R2-F4).
 *
 * Same shape and purpose as Taxation's `PeriodLockRefusalCode`: the backing
 * values are what the 422 envelope carries and what the front end branches on,
 * so neither side has to parse a human message.
 */
enum CorrectingEntryRefusalCode: string
{
    /**
     * Owner ruling c4's core requirement — the link to the original is
     * MANDATORY. Without it this is the free-floating manual journal entry the
     * ruling forbids as a correction mechanism.
     */
    case MissingSourceDocument = 'CORRECTING_ENTRY_MISSING_SOURCE_DOCUMENT';

    /** The linked document does not exist in this tenant AND company. */
    case TargetNotFound = 'CORRECTING_ENTRY_TARGET_NOT_FOUND';

    /**
     * The target is not one of the types whose GL `AccountingService` writes
     * under `source_type = 'Document'` (Invoice / CreditNote) — the exact
     * population `reverseDocumentGl()` handles.
     */
    case UnsupportedTargetType = 'CORRECTING_ENTRY_UNSUPPORTED_TARGET_TYPE';

    /** The target never reached the ledger, so there is nothing to correct. */
    case TargetHasNoLedgerEntry = 'CORRECTING_ENTRY_TARGET_HAS_NO_LEDGER_ENTRY';

    /** A leg names an account that does not belong to this tenant and company. */
    case UnknownAccount = 'CORRECTING_ENTRY_UNKNOWN_ACCOUNT';

    /**
     * The invariant that makes a correction worth being a document: after it
     * posts, the target's whole ledger footprint must balance.
     */
    case LeavesTargetUnbalanced = 'CORRECTING_ENTRY_LEAVES_TARGET_UNBALANCED';

    /** This correcting document has already been posted to the ledger. */
    case AlreadyPosted = 'CORRECTING_ENTRY_ALREADY_POSTED';

    /** The document's `payload` is not a well-formed correcting-entry payload. */
    case MalformedPayload = 'CORRECTING_ENTRY_MALFORMED_PAYLOAD';

    /**
     * The target has already been WITHDRAWN — cancelled, or its GL already
     * reversed. Correcting it is a no-op that cannot be undone: `reverseDocumentGl()`
     * is idempotent and has already run, so the correction's legs would land in
     * the ledger with nothing left to mirror them out again (gate probe: a
     * 50.000 reclass on a cancelled invoice's AR + VAT, permanently unreachable).
     */
    case TargetAlreadyWithdrawn = 'CORRECTING_ENTRY_TARGET_ALREADY_WITHDRAWN';

    /**
     * A leg carries more decimals than the entry's CURRENCY admits.
     *
     * The FormRequest's ceiling is the column scale (3); the balance verdict and
     * the ledger both run at the currency scale, which for EUR is 2 — and
     * `bcadd` TRUNCATES. A leg of `0.005` on a EUR company therefore balanced as
     * `0.00` and posted a permanent 0.004 hole at column scale (fiscal gate
     * P1-3 probe). Refused rather than silently rounded: an accountant's stated
     * repair must be the repair that is made.
     */
    case LegAmountBeyondCurrencyScale = 'CORRECTING_ENTRY_LEG_AMOUNT_BEYOND_CURRENCY_SCALE';

    /**
     * A leg names a PARTNER CONTROL account but no partner can be resolved for it.
     *
     * Every sibling GL path stamps `journal_lines.partner_id` on control-account
     * legs; without it `PartnerBalanceService::reconcileSubledger()` reports the
     * control account and the subledger as divergent forever (gate probe:
     * control 129.000 vs subledger 119.000, broken by this lane's own canonical
     * 411 example). A control leg with no partner is refused, never guessed.
     */
    case ControlAccountLegWithoutPartner = 'CORRECTING_ENTRY_CONTROL_ACCOUNT_LEG_WITHOUT_PARTNER';

    /**
     * A leg names a partner that does not belong to this tenant AND company.
     *
     * `legs.*.partner_id` was format-checked (`uuid`) and never resolved, while
     * the ACCOUNT on the same leg was company-scoped three statements earlier.
     * A sibling-company partner therefore posted onto a control leg and diverged
     * the subledger invisibly — `getSubledgerTotal()` does not scope partners by
     * company, so the reconciler cannot even see the foreign row. A nonexistent
     * uuid reached the INSERT and surfaced as an FK-violation 500.
     * company_id is an authorization axis on BOTH axes, not just the chart.
     */
    case UnknownPartner = 'CORRECTING_ENTRY_UNKNOWN_PARTNER';

    /**
     * A leg names a VAT control account while the TARGET's VAT period is FILED.
     *
     * Moving 4457 / 4456 inside a period whose declaration is already with the
     * tax authority diverges the ledger from the filed return with no
     * reconciliation path. Refused for now; the recorded alternative is to gate
     * it behind an explicitly acknowledged flag, which needs an owner ruling
     * because it trades a silent divergence for a deliberate one.
     */
    case VatLegInFiledPeriod = 'CORRECTING_ENTRY_VAT_LEG_IN_FILED_PERIOD';
}
