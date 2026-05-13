# web.tanstack-keys Batch 44 — Opus Review

Commit reviewed: 1538d2b2
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: workshop work order hooks — callsites web.tanstack-keys.826-838 (13)
Scanner delta: 406 → 393 (-13)

## Summary

`useWorkOrders.ts` reads wrapped with `tenantScopedKey`; lists use `workOrderListsPredicate` (namespace + 'list' + suffix), detail uses exact `tenantScopedKey([...detail(id)])`. All mutations awaited via `Promise.all`. Per-call counters distinguish listCalls and detailCalls advancing independently after each mutation. Tenant-B markers preserved.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
