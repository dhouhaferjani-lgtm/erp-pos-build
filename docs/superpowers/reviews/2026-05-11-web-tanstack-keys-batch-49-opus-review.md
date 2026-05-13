# web.tanstack-keys Batch 49 — Opus Review

Commit reviewed: cd730fd9
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: smart payment hooks — callsites web.tanstack-keys.735-739 (5)
Scanner delta: 331 → 326 (-5)

## Summary

`useToleranceSettings` read wrapped; gated. `useApplyAllocation` invalidates `payments` (predicate), exact `payment`, exact `invoice` per allocation (mapped through `Promise.all`), `partner-balance` (predicate) — matches spec. 5 tenant-B markers preserved across 2 allocated invoices.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
