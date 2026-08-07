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
}
