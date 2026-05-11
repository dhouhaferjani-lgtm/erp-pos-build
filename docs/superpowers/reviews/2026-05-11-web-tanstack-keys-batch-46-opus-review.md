# web.tanstack-keys Batch 46 — Opus Review

Commit reviewed: becd85fa
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: withholding hooks — callsites web.tanstack-keys.778-798 (21)
Scanner delta: 384 → 363 (-21)

## Summary

6 read hooks wrapped (certificates list/detail, rules list/detail, tracking list/record). Plural namespaces use `scopedNamespacePredicate`; singular detail uses exact invalidation. `sales-withholding-tracking` shared by list AND detail — predicate invalidates both, preserved pre-existing semantic. 10 mutations tested, 6 tenant-B markers preserved.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`.
