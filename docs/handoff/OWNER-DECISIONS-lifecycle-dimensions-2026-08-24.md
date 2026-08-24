# OWNER DECISIONS — document lifecycle dimensions program (Session C) — 2026-08-24

Each row: the question, the FAIL-SAFE default the spec/plan proceeds on until ruled, and what changes if you rule otherwise.
Spec/plan: `docs/sessions/session-C-lifecycle-2026-08-24/{SPEC-document-lifecycle-dimensions.md,PLAN-phase2-3-P-R.md}` (r1).
Rule by editing the "Ruling" cell; the session log records the date.

| # | Question | Fail-safe default in force | If ruled otherwise | Ruling |
|---|---|---|---|---|
| OQ-11 | `in_payment` (payment registered, pending bank reconciliation) — `payments.is_reconciled` EXISTS. Surface it in `payment_status` per the repo spec, or not? | Not a `payment_status` value; `has_unreconciled_payments` boolean on the read DTO | Add `in_payment` value + CHECK + FE colour; B2B default `require_bank_reconciliation=true` | |
| OQ-13 | Supplier goods return: does it reopen the PO for replacement receipt, reduce NET fulfilment, or leave gross receipt unchanged? | Reduces net fulfilment (PO may show `partially_fulfilled` again; receivable again) | "gross unchanged" ⇒ returns never touch PO verdict; "reopen" ⇒ explicit replacement-expected flag | |
| OQ-14 | Confirmed-unposted invoice printout: N-6 ruling says VAT shown normally + "not posted" line; adopted expert ruling (Art. 18) says no VAT mention before delivery (proforma). Which stands for TN? | PROFORMA / non-definitive label, NO VAT block, until ruled | Keep N-6 behaviour (VAT shown) — accepted Art. 18 exposure recorded | |
| OQ-15 | TN: "posted = sealed + Ministry QR". While the QR is pending: refuse `confirmed→posted` (synchronous QR) or post with `fiscal_authority_status=pending` and block print/dispatch until accepted? | Refuse (fail-closed) — posting waits for acceptance | Post-with-pending: printing/dispatch gated; a rejected QR path needs a ruling too | |
| OQ-16 | Fully applied/refunded credit note: keep emitting the existing `DocumentFullyPaid` event (semantics "fully settled") or version a settlement event? | Reuse existing event, schema unchanged | `CreditNoteSettledV1` event + listeners | |
| OQ-17 | FR purchase-posting + supplier-advance defaults: copy TN (`require_receipt_first`, `refuse`) or hold provisional pending FR expertise? | Seed FR rows PROVISIONAL — posting refused with a typed message on FR tenants until approved | Approve = flip `provisional=false` | |
| OQ-18 | `ReturnNoteMetadata` / `RefundMethod` (dead backend, live web fields): delete, or revive as first-class refund disposition? | Untouched (no deletion, no revival) until the CN cash-refund lane R-3 defines disposition | Delete ⇒ FE fields + table removal lane; revive ⇒ R-3 writes them | |
| R-OQ-9 | CN VAT declaration: today status-blind (a confirmed-never-posted or cancelled credit note already deducts output VAT). Fix changes declared figures on existing tenants. | Fix ships behind a per-tenant per-period delta report (`vat:declaration-delta --dry-run`) — merge only after you acknowledge the deltas | Keep status-blind (non-compliant, recorded) | |
| OQ-19 | Dashboard revenue is gross of credit notes (`DashboardController:50-58`). Net it in this program? | Unchanged | Net-of-CN lane (changes a daily-watched number) | |
| OQ-20 | `closed` meaning for Quote/RFQ (converted/expired), orders (fully fulfilled ∧ fully invoiced), DN (invoiced or returned), RN (credit posted or no-credit) — confirm the per-type table SPEC §1.1 | As tabled | Edit the table row | |
