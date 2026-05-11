# web.tanstack-keys Batch 77 — Opus Review

Commit reviewed: f8b82780
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `f8b82780` — fix(tenant-isolation): wrap web.tanstack-keys batch 77 (quote detail)
Scope: 6 callsites in `QuoteDetailPage.tsx`.
- .230 read quote, .231/.232 invalidate quote-exact + documents-predicate on confirm
- .233/.234 invalidate documents-predicate + quote-exact on convert success
- .235 invalidate quote-exact on convert error (rollback)

Test: `QuoteDetailPage.tenantScope.test.tsx` (new, 268 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 6 sites use `tenantScopedKey([...])` or `scopedNamespacePredicate(...)`.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: Read AND-combines `id.length > 0 && tenantId !== null && companyId !== null`.
4. **Async invalidate**: All 3 mutation handlers (confirm onSuccess, convert onSuccess, convert onError) converted to `async/await` with `Promise.all([…])` for parallel.

## Non-blocking findings

1. `onError` handler now `async` (.235) — defensible since we're awaiting a single rollback invalidate, but if `onError` is called from sync code there's a slight risk of unhandled rejection if the await fails. React Query handles this internally; pattern is fine.
2. `scopedNamespacePredicate` inlined. Tracked.

## Locks applied

6 callsites locked at fix commit `f8b82780`:
web.tanstack-keys.230, .231, .232, .233, .234, .235.
