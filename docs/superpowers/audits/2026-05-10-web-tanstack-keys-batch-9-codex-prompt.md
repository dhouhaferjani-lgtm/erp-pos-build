# Codex review prompt — web.tanstack-keys batch 9 (pricing pages)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `b751cefe`
**Files under review (3 pages + 1 helper):**
- `apps/web/src/features/pricing/PriceListDetailPage.tsx`
- `apps/web/src/features/pricing/PriceListForm.tsx`
- `apps/web/src/features/pricing/PriceListListPage.tsx`
- `apps/web/src/features/pricing/_invalidation.ts` (new helper)

**Test:** `apps/web/src/features/pricing/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.544`–`.552` (9)
**Cluster:** `web.tanstack-keys` (was 778; this batch closes 9 → 769 remaining if APPROVE)

## Context (compressed — first PAGE-level batch in this session)

NEW shape vs B5-B8 (which were all hook-level): the wrap is applied directly inside page components instead of in a hook file. Same mechanic (tenantScopedKey + state-value selectors + enabled gate), different surrounding context.

Two-namespace pattern within the same feature:
- **`price-lists`** (plural): list useQuery + cross-page invalidates carry positional segments (e.g., statusFilter), so wrap doesn't prefix-match. Predicate `priceListsInvalidationPredicate` exported from `_invalidation.ts`.
- **`price-list`** (singular): detail useQuery + detail invalidates use the same shape after wrap (`[price-list, id, t, c]`), so exact-match wrap suffices. No singular predicate needed (would be equivalent to exact match — see B3 lesson 4).

The `useReservationSettings` rewrite from B7 is the closest analogue but was a hook; this is the first batch where state-value selectors are subscribed inside page components. The hook still re-renders on tenant/company switch the same way.

## What to verify

1. Scanner delta = 9 (`audit-tanstack-keys.mjs` count drops from 778 to 769). **Confirmed live: 769.**
2. State-value selectors used in all 3 pages.
3. `enabled` gate combines pre-existing condition (Boolean(id) or isEditing) with `!!tenantId && !!companyId`.
4. Predicate `priceListsInvalidationPredicate` matches `[price-lists, ...]` and rejects `[price-list, ...]` (singular) and wrong-t/c/degenerate.
5. Singular invalidates use exact-match `tenantScopedKey(['price-list', id])` — same shape as the leaf useQuery, no predicate.
6. Mutations' `onSuccess` made async + awaited; existing `navigate()` calls run after invalidate completes.
7. Cross-tenant isolation test seeds tenant-B `price-lists` entry, runs predicate-based invalidate against tenant-A, asserts tenant-B `state.isInvalidated === false` + unchanged data.

## Quality gate evidence (run by main session at fix SHA)

- `pnpm vitest run src/features/pricing/__tests__/tenantScope.test.tsx`: 9/9 passing.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3185 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-9-codex-review.md
```

**Critical format:** First non-empty line MUST be `Commit reviewed: b751cefe`. Verdict line MUST be a literal `Verdict: APPROVE` (or `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED` / `Verdict: REQUEST-CHANGES`) on its own line — **NOT** `## VERDICT:` heading. Strict CLI regex requires the bare line.

**Important:** Use the Write tool to save the review file to disk. Last batch (B8) returned content inline without writing the file.

Page-level wrap is the new shape; if the wrap mechanic is sound and the singular/plural namespace separation is correct, expected verdict is APPROVE.
