# web.tanstack-keys Batch 51 — Opus Review

Commit reviewed: f5307e54
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: credit note hooks — callsites web.tanstack-keys.186-190 (5)
Scanner delta: 318 → 313 (-5)

## Summary

`useCreditNotes` list + detail wrapped. `useCreateCreditNote` invalidates `'credit-notes'` (predicate) + exact `['invoice', source_invoice_id]` + `'documents'` (predicate). 3 tenant-B markers preserved.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
