# web.tanstack-keys Batch 72 — Opus Review

Commit reviewed: 28911a28
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `28911a28` — fix(tenant-isolation): wrap web.tanstack-keys batch 72 (invoice detail)
Scope: 14 callsites in `InvoiceDetailPage.tsx`.
- .204 read invoice
- .205/.206 invalidate on confirm
- .207/.208 invalidate on post
- .209/.210/.211 invalidate on confirm-deliveries-and-post (3 keys: document-exact, documents-predicate, delivery-notes-predicate)
- .212/.213/.214 invalidate on handleCreditNoteCreated (document-exact, documents-predicate, credit-notes-predicate)
- .215/.216/.217 invalidate on handlePaymentSuccess (document-exact, documents-predicate, payments-predicate)

Test: `InvoiceDetailPage.tenantScope.test.tsx` (new, 392 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 14 sites use `tenantScopedKey([...])` or `scopedNamespacePredicate(...)`.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gate**: Read AND-combines `id.length > 0 && tenantId !== null && companyId !== null`.
4. **Async invalidate**: All 5 invalidate flows (confirm, post, confirm-deliveries-and-post, handleCreditNoteCreated, handlePaymentSuccess) `async/await` with `Promise.all`.

## Locks applied

14 callsites locked at fix commit `28911a28`:
web.tanstack-keys.204-.217.
