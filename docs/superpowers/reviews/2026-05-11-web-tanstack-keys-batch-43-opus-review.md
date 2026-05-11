# web.tanstack-keys Batch 43 — Opus Review

Commit reviewed: b8b51f87
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: workshop technician hooks — callsites web.tanstack-keys.811-825 (15)
Scanner delta: 421 → 406 (-15)

## Summary

Hook files (`useTechnicians.ts`, `useAuthoring.ts`) correctly wrap reads in `tenantScopedKey([...])`, gate `enabled` on tenant/company nullness AND-combined with pre-existing predicates, and use state-value selectors. Certifications use exact id-scoped detail invalidation; time-off / time-entries use `technicianAuthoringPredicate` scoping by ROOT + technicianId + segment + tenant/company suffix. All 9 mutations awaited; tenant-B markers (3) preserved through active-tenant cascades. Per-call counters confirm intended-tenant refetches with no overfire on tenant-B.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
