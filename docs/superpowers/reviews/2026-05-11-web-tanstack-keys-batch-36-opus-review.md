# web.tanstack-keys Batch 36 — Opus Review

Commit reviewed: 03ffd55e
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `03ffd55e` — fix(tenant-isolation): wrap web.tanstack-keys batch 36 (pos smart prompts)
Scope: 3 callsites in pos/smart-prompts hooks.
- `useCartRecommendations.ts` (.541 read smart-prompts/recommendations)
- `useContactProfile.ts` (.542 read contact-profile, .543 invalidate contact-profile)

Test: `smartPrompts.tenantScope.test.tsx` (new, 217 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 3 sites use `tenantScopedKey([...])`.
2. **Shared scope hook**: Uses `usePosTenantScope()` from `apps/web/src/features/pos/hooks/usePosTenantScope.ts` — the canonical shared helper for POS callsites (and the namesake of the `scopedKeyPredicate` export that subsequent batches inlined).
3. **Enabled gates**: AND-combine `hasTenantScope` with existing predicates (`debouncedIds.length > 0`, `!!contactId`).
4. **Async invalidate**: `onSuccess` for contact-profile mutation `async/await`.

## Non-blocking findings

1. The existence of the shared `usePosTenantScope` + `scopedKeyPredicate` helper makes the inlined `scopedNamespacePredicate` in later batches (B70-B82, B86-B95) clearly redundant — promoting that helper to `apps/web/src/lib/tenantScopedKey.ts` and adopting it everywhere is a clean follow-up.

## Locks applied

3 callsites locked at fix commit `03ffd55e`:
web.tanstack-keys.541, .542, .543.
