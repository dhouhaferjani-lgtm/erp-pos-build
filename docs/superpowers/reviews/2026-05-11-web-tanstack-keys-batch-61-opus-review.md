# web.tanstack-keys Batch 61 — Opus Review

Commit reviewed: 85ed3918
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: user hooks — callsites web.tanstack-keys.748-749 (2)
Scanner delta: 281 → 279 (-2)

## Summary

`userKeys.list(params)` and `userKeys.detail(id)` wrapped with `tenantScopedKey`. State-value selectors used. Enabled gates extended with tenant/company nullness. Read-only batch in this file (mutation surface tracked separately).

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-54-63-opus-review.md`.
