# web.tanstack-keys Batch 53 — Opus Review

Commit reviewed: 005bd90c
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: delivery note hooks — callsites web.tanstack-keys.191-196 (6)
Scanner delta: 307 → 301 (-6)

## Summary

`useDeliveryNotes` list, invoiceable, detail wrapped. `useConsolidateDeliveryNotes` invalidates `'delivery-notes'` (predicate, matches both plain list and `['delivery-notes', 'invoiceable', partnerId]`), `'documents'` (predicate), `'invoices'` (predicate). 4 tenant-B markers preserved.

Pre-existing follow-up (not introduced here): consolidation does not invalidate the singular `['delivery-note', id]` detail. Out of scope.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
