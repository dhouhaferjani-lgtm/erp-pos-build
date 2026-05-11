# web.tanstack-keys Batch 74 — Opus Review

Commit reviewed: fe160a38
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `fe160a38` — fix(tenant-isolation): wrap web.tanstack-keys batch 74 (record payment modal)
Scope: 10 callsites in `RecordPaymentModal.tsx`.
- .008/.009/.010 reads: payment-methods, payment-repositories, open-invoices
- .011-.016 invalidate on payment save: payments-predicate, invoice-exact, invoices-predicate, documents-predicate, open-invoices-predicate, document-exact
- .017 invalidate payment-repositories-predicate inside AddRepositoryModal onSuccess

Test: `apps/web/src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` (new, 243 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 10 sites use `tenantScopedKey([...])` or `scopedNamespacePredicate(...)`. The save flow invalidates 6 keys via `Promise.all([…])` covering payments, invoice, invoices, documents, open-invoices, and the specific document.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: All 3 reads AND-combine `tenantId !== null && companyId !== null` with `isOpen` (and `!!partner_id` for open-invoices).
4. **Async invalidate**: Save onSuccess `async/await` with `Promise.all`. AddRepositoryModal nested onSuccess callback also `async/await` (.017).

## Non-blocking findings

1. `scopedNamespacePredicate` inlined. Tracked.
2. Save flow has 6 parallel invalidations — appropriate for the cross-table effects of recording a payment.

## Locks applied

10 callsites locked at fix commit `fe160a38`:
web.tanstack-keys.008-.017.
