# web.tanstack-keys Batches 56-59 — Opus Review

Commit reviewed: bb46f0b6
Verdict: APPROVE

Reviewer: claude (Opus 4.7)
Implementer: codex
Cluster: web.tanstack-keys
Scope: finance hooks (bundled commit)
- B56 finance account hooks — callsites .269-272 (4)
- B57 finance journal entry reads — callsites .277-278 (2)
- B58 finance journal entry mutations — callsites .279-281 (3)
- B59 finance ledger reads — callsites .282-283 (2)

Scanner delta: 294 → 283 (-11)

## Summary

`useAccounts`, `useAccount` wrapped with `accountsPredicate` (`k[0]==='accounts'` + suffix). Predicate matches both list and detail shapes — safe conservative over-invalidation, consistent with pattern. `useJournalEntries` / `useJournalEntry` wrapped. Mutation hooks (`useCreateJournalEntry`, `usePostJournalEntry`) use `scopedNamespacePredicate` for lists + exact `tenantScopedKey(['journal-entry', id])` for detail; counters bump independently. Ledger hooks wrapped read-only.

Per-call counters PASS (listCalls / detailCalls), tenant-B isolation PASS, production hooks exercised.

See combined narrative: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-54-63-opus-review.md`.
