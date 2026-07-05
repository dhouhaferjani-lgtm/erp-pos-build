# Supplier Credit-Note Posting — Codex Adversarial Re-Review 2 (D1)
Date: 2026-06-26
Branch: feat/procurement-to-pay
Reviews the fix commit `80be437e0` against the prior `.codex-d1-rereview.md` findings.
(Verdict captured from the Codex session; the agent was sandbox-blocked from writing directly.)

## VERDICT: DO-NOT-SHIP
BLOCKERS: 0 · HIGH: 1

## Prior findings — status
- HIGH (cross-supplier `partner_id` mismatch) — **CLOSED** by `80be437e0` (`resolveAndLockLinkedInvoice()` now requires `supplierInvoice->partner_id === creditNote->partner_id`).
- HIGH (linked supplier invoice not required to be Posted) — **CLOSED** (now requires `DocumentStatus::Posted`).
- MED (input must be a Draft `DocumentType::SupplierCreditNote`) — **CLOSED** (type + Draft guards added).

## New HIGH (survives the fix)
**The credit-note document itself is not locked before guard evaluation.**
`SupplierCreditNotePostingService::post()` validates the caller-provided credit-note model (type, status, source, partner_id) **without first reloading and `lockForUpdate()`-locking the credit-note document row**. The linked supplier invoice and PO lines ARE locked, but the document whose type/status/source/partner are being guarded is not.

Because `journal_entries(source_type, source_id)` is **indexed but not unique-constrained**, a concurrent edit to the draft credit-note between the guard check and the GL write can:
- stale-state bypass the new guards, or
- double-post GL against the same credit-note document.

### Recommended fix
1. At the top of the transaction (in or before `resolveAndLockLinkedInvoice()`), **reload the credit-note with `->lockForUpdate()`** and re-run the type/status/Draft guards on the freshly-locked row.
2. Add a **unique index/constraint on `journal_entries(source_type, source_id)`** to make double-posting structurally impossible.

## Note on scope
Supplier credit-note API/UI is DEFERRED from tonight's minimal go-live cut. This HIGH does not block the minimal cut (no credit-note path is exposed tonight) but **must be fixed before the credit-note feature ships** and before AP-reconciliation phases that depend on it.

## Systemic follow-up
The "lock the source document before guard eval + unique `(source_type, source_id)`" concern is generic. Verify the **supplier-invoice posting** path (`SupplierInvoicePostingService::post`, commit `50ee63c0f` "under lock + idempotency") locks the invoice document row and is protected by the same unique constraint — relevant to tonight's minimal cut.
