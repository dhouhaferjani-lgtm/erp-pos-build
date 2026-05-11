# web.tanstack-keys Batch 50 — Opus Review

Commit reviewed: c0448856
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: additional cost hooks — callsites web.tanstack-keys.174-181 (8)
Scanner delta: 326 → 318 (-8)

## Summary

`useAdditionalCosts` and `useLandedCostBreakdown` reads wrapped. All three mutations (create/update/delete) invalidate exact `['additional-costs', documentId]`, exact `['document', 'purchase_order', documentId]`, AND exact `['landed-cost-breakdown', documentId]` — matches spec including landed-cost addition. 3 counters + 3 tenant-B markers preserved.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
