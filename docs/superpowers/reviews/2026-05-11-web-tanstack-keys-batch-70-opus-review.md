# web.tanstack-keys Batch 70 — Opus Review

Commit reviewed: b8e7ca54
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `b8e7ca54` — fix(tenant-isolation): wrap web.tanstack-keys batch 70 (payment form)
Scope: 15 callsites in `PaymentForm.tsx`.
- .689/.690/.691 reads: invoice, purchase-order, delivery-note (3 different source-document fetches)
- .692/.693/.694/.695 reads: payment-methods, partners, payment-repositories, open-invoices
- .696-.701 6 invalidate calls on save: payments-predicate, invoice-exact (conditional), purchase-order-exact (conditional), delivery-note-exact (conditional), invoices-predicate, open-invoices-predicate
- .702/.703 invalidate partners-predicate / payment-repositories-predicate in nested modal onSuccess

Test: `PaymentForm.tenantScope.test.tsx` (new, 245 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 15 sites use `tenantScopedKey([...])` or `scopedNamespacePredicate(...)`.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: All 7 reads AND-combine `tenantId !== null && companyId !== null` with their existing `!!invoiceId`/`!!purchaseOrderId`/`!!deliveryNoteId`/`!!selectedPartnerId && !invoiceId` predicates.
4. **Async invalidate**: Save `onSuccess` `async/await` with `Promise.all([…])` covering 6 invalidate paths. Conditional invalidates use `invoiceId ? queryClient.invalidate(...) : Promise.resolve()` to keep the array uniform.

## Non-blocking findings

1. `scopedNamespacePredicate` inlined. Tracked.
2. The conditional `Promise.resolve()` placeholders in `Promise.all` are tidy but a touch noisy — a small `.filter(Boolean)` pre-filter on a built array would be cleaner. Cosmetic only.

## Locks applied

15 callsites locked at fix commit `b8e7ca54`:
web.tanstack-keys.689-.703.
