# web.tanstack-keys Batch 52 — Opus Review

Commit reviewed: d1181b91
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: return note hooks — callsites web.tanstack-keys.198-203 (6)
Scanner delta: 313 → 307 (-6)

## Summary

`useReturnNotes` list + detail wrapped. `useCreateReturnNote` invalidates `'return-notes'` (predicate) + conditional exact `['invoice', source_invoice_id]` + conditional exact `['delivery-note', source_delivery_note_id]` + `'documents'` (predicate). Fixture sets both source IDs so both branches fire; `Promise.resolve()` placeholders keep `Promise.all` shape. 4 tenant-B markers preserved.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
