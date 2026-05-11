# web.tanstack-keys Batches 62-63 — Opus Review

Commit reviewed: 4fe26b41
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: VAT hooks (bundled commit)
- B62 VAT period actions — callsites .750-751 (2)
- B63 VAT report reads — callsites .753-754 (2)

Scanner delta: 279 → 275 (-4)

## Summary

`useVatPeriodActions.closeMutation` invalidates `vat-periods` (predicate) + exact `['vat-report', id]` — tighter than the prior broad `['vat-report']` invalidation. `useVatReport`, `useVatExportFormats` reads wrapped. Per-call counters PASS (periodsCalls / reportCalls), tenant-B isolation PASS (both markers preserved), production hooks exercised.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-54-63-opus-review.md`.
