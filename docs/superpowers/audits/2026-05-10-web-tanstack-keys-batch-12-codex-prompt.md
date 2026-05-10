# Codex review prompt — web.tanstack-keys batch 12 (compliance)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `5780f96d`
**Files (3 + helper):**
- `apps/web/src/features/compliance/_invalidation.ts` (new)
- `apps/web/src/features/compliance/components/FraudAlertActionModals.tsx`
- `apps/web/src/features/compliance/pages/FraudAlertsPage.tsx`
- `apps/web/src/features/compliance/pages/FraudSettingsPage.tsx`

**Test:** `apps/web/src/features/compliance/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.108`–`.119` (12)
**Cluster:** was 748; this batch closes 12 → 736 if APPROVE.

## Context (compressed — 4-namespace cascade with shared modal cascade boundary)

3 invalidatable namespaces + 1 sibling-fetch namespace:
- `fraud-alerts`: list useQuery (.115) + 3 modal mutation invalidates
- `fraud-alert-statistics`: stats useQuery (.116) + 3 modal mutation invalidates (paired with fraud-alerts via Promise.all)
- `fraud-settings`: settings useQuery (.117) + 2 mutation invalidates (.118, .119; update + reset)
- `users` sibling: admin-role useQuery (.108) — wrapped for tenant scoping but no mutation cascades into it

Each of the 3 modals (Assign/Dismiss/Resolve, .109-.114) cascades BOTH `fraud-alerts` + `fraud-alert-statistics` predicates via Promise.all. FraudSettingsPage's 2 mutations cascade only fraud-settings predicate.

## What to verify

1. Scanner delta = 12 (`audit-tanstack-keys.mjs` count drops from 748 to 736). **Confirmed live: 736.**
2. State-value selectors used in all 3 modals + 2 pages.
3. 3 predicates correctly gate on `k[0] === <namespace>` AND tail t/c. Each rejects the other 2 invalidatable namespaces + the `users` sibling (positive + negative cases asserted).
4. 3 modals each wrap mutation onSuccess in async + Promise.all of 2 predicate invalidates. FraudSettingsPage's 2 mutations each await a single fraud-settings predicate invalidate.
5. `users` namespace useQuery is wrapped (`tenantScopedKey(['users', 'admin-role'])`) but NOT cascaded into by any modal mutation — explicit predicate-rejects-users assertion in test.
6. Cross-tenant isolation test seeds tenant-B fraud-alerts entry, runs tenant-A predicate cascade, asserts state.isInvalidated === false.

## Quality gate evidence

- `pnpm vitest run src/features/compliance/__tests__/tenantScope.test.tsx`: 12/12 passing.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3314 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-12-codex-review.md
```

**Critical format:** First non-empty line MUST be `Commit reviewed: 5780f96d`. Verdict line MUST be a literal `Verdict: APPROVE` (or `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED` / `Verdict: REQUEST-CHANGES`) on its own line — NOT `## VERDICT:` heading. Use the Write tool to save the file in this turn.
