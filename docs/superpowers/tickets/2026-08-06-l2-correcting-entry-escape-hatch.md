# Ticket: give the unbalanced-original cancellation refusal an escape hatch

**Filed:** 2026-08-06, by the L2 AR-integrity fix lane's dual-gate round, from
`docs/superpowers/reviews/2026-08-06-l2-treasury-gate.md` (open question (i),
ruling: refuse is right, but escapable) and referenced from
`UnreversibleDocumentGlException`'s class docblock.
**Status:** OPEN.

## What happens today (post-round, working as designed but with no way out)

`AccountingService::reverseDocumentGl()` refuses to cancel a document whose
sealed journal entry is already out of balance
(`UnreversibleDocumentGlException::forUnbalancedOriginal()`,
`apps/api/app/Modules/Accounting/Domain/Exceptions/UnreversibleDocumentGlException.php`) —
mirroring an unbalanced original would seal a SECOND unbalanced entry into the
chain, which is exactly what the L1 lane's D1a guard exists to prevent. The
treasury gate's ruling (2026-08-06) confirmed **refuse is the right call**: the
alternative (plugging the gap to a suspense account) would be a posting the
accountant never authorised.

But as of this round the refusal is a **permanent dead end**. The exception
message no longer claims a correcting entry will help (that claim was the bug
this round fixed — see the exception's own docblock), but there is still **no
way at all** to correct the underlying imbalance and unblock the cancel:

- `AccountingService::reverseDocumentGl()`'s balance count matches only
  `source_type = 'Document' AND source_id = $document->id AND status = Posted`
  (`apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:730-737`).
- The only manual-entry writer, `JournalEntryController::store()`, hard-codes
  `'source_type' => 'manual'` and `source_id = $entry->id`
  (`apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:100-102`).

A correcting entry posted through the only available endpoint can **never**
enter the predicate — there is no `source_document_id` field the manual
endpoint accepts, and no `force`/override parameter on the cancel route. The
one document known to be in this state today (the campaign's own `MTP-DOC-06`
probe, W-6 D1b, 19.000 stranded on `4457`) is now **permanently uncancellable**
through the product, full stop.

## Suggested fix (per the gate's own ruling — pick (a) or (b))

- **(a)** Let a manual journal entry declare which document it corrects: add an
  optional `source_document_id` (or reuse `source_id` with
  `source_type = 'Document'` when explicitly flagged as a correction) to
  `JournalEntryController::store()`'s accepted payload, and extend
  `reverseDocumentGl()`'s balance count to
  `source_type IN ('Document', 'manual') ... AND (source_id = $document->id OR
  source_document_id = $document->id)`. This is the more "real accounting"
  shape — an accountant posts an actual correcting entry, sealed and visible in
  the ledger like any other.
- **(b)** Add an explicit `force` / `correcting_entry_id` parameter on the
  cancel route, gated on an `accounting.manage`-tier permission (stricter than
  the ordinary cancel permission), that lets a privileged user acknowledge the
  imbalance and proceed anyway. Simpler to build; weaker audit trail (the
  override itself needs its own log line, since it is knowingly sealing a
  second unbalanced entry).

Either requires a product/accounting ruling on which is preferred before
implementation — the treasury gate did not choose between them, only ruled
that SOME escape hatch is required.

## Related

- `docs/superpowers/reviews/2026-08-06-l2-treasury-gate.md` (open question (i))
- `docs/superpowers/reviews/2026-08-06-l2-gl-gate.md` (I-1/I-2 context on the
  same reversal path)
- `docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md` (D1a/D1b, the
  origin of the one known stranded entry)


**STATUS 2026-08-21: CLOSED** — r2f4 correcting-documents merged to local dev (session 0578e8d8); the escape hatch exists (admin-only, prospective-only, Invoice/CreditNote-only — LEDGER O-26/C-8 scope limits).
