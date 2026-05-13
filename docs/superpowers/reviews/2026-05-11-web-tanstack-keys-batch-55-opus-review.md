# web.tanstack-keys Batch 55 — Opus Review

Commit reviewed: acd5def0
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: close-with-tolerance hook — callsites web.tanstack-keys.218-220 (3)
Scanner delta: 297 → 294 (-3)

## Summary

`useCloseWithTolerance` cascades: predicate invalidation for `documents`/`payments` suffix-scoped lists; exact key for invoice detail. Three tenant-B markers preserved post-close. State-value selectors capture tenantId/companyId for predicate use; mutations awaited via `Promise.all`.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-54-63-opus-review.md`.
