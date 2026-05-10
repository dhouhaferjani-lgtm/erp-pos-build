# Codex review prompt — web.tanstack-keys batch 11 (menu hooks + manager)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `c10a8a97`
**Files (2):**
- `apps/web/src/features/menu/hooks/useMenus.ts`
- `apps/web/src/features/menu/components/MenuCategoryItemManager.tsx`

**New test:** `apps/web/src/features/menu/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.376`–`.387` (12)
**Cluster:** `web.tanstack-keys` (was 760; this batch closes 12 → 748 remaining if APPROVE)

## Context (compressed — first runtime-computed namespace shape)

Single-predicate cascade for menus + a sibling component on a runtime-computed namespace:

- `useMenus.ts` (.377-.387): factory + 2 useQuery + 9 mutation invalidates. Same shape as B11 if it had been B7-style without the cross-file wrinkle.
- `MenuCategoryItemManager.tsx` (.376): `tenantScopedKey([searchTab + '-search', searchQuery])` — namespace literal computed at runtime (`'composite_item-search'` or `'product-search'`). The wrap is still valid because the audit-tanstack-keys scanner only requires the OUTER call expression to be a bare-Identifier `tenantScopedKey()`; argument shape can be any array literal.

The predicate's namespace gate `k[0] === 'menus'` deliberately rejects both runtime computed search namespaces — sibling-namespace negative assertion in the test confirms this.

## What to verify

1. Scanner delta = 12 (`audit-tanstack-keys.mjs` count drops from 760 to 748). **Confirmed live: 748.**
2. State-value selectors used in all 9 mutations + 2 useQuery hooks + the manager component.
3. Predicate matches `[menus, ...]`; rejects `[composite_item-search, ...]`, `[product-search, ...]`, and wrong-t/c.
4. 9 cascade tests via `it.each` parameterization — each mutation increments list + detail counters to 2 (per-call counters; vacuous predicate would leave them at 1).
5. Cross-tenant isolation test seeds tenant-B detail entry, asserts `state.isInvalidated === false` post-mutation.
6. MenuCategoryItemManager imports include `tenantScopedKey`, `useAuthStore`, `useCompanyStore`; the queryKey wrap and enabled gate are correct.

## Quality gate evidence (run by main session at fix SHA)

- `pnpm vitest run src/features/menu/__tests__/tenantScope.test.tsx`: 16/16 passing.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3266 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-11-codex-review.md
```

**Critical format:** First non-empty line MUST be `Commit reviewed: c10a8a97`. Verdict line MUST be a literal `Verdict: APPROVE` (or `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED` / `Verdict: REQUEST-CHANGES`) on its own line — NOT `## VERDICT:` heading.

**Important:** Use the Write tool to save the review file to disk in this turn.

Runtime-computed namespace is the new wrinkle; if the scanner accepts the outer `tenantScopedKey()` call regardless of argument shape and the predicate-based cascade isolates the menu namespace, expected verdict is APPROVE.
