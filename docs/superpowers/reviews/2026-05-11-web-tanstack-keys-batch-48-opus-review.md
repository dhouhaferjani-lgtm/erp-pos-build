# web.tanstack-keys Batch 48 — Opus Review

Commit reviewed: af17652e
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: batch hooks — callsites web.tanstack-keys.030-044 (15)
Scanner delta: 346 → 331 (-15)

## Summary

`useBatches.ts` reads wrapped. FEFO hook retains full pre-existing gate (productId, locationId, quantity > 0, enabled). `scopedBatchCollectionsPredicate` excludes `k[1] === 'detail'` from the collections sweep — detail handled by explicit exact invalidation. Cross-namespace exact `tenantScopedKey(['products', 'detail', productId])` invalidated on create/update only (delete does NOT bump productDetailCalls — test asserts).

7 counters tracked independently across 4 mutations; 7 tenant-B markers preserved.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
