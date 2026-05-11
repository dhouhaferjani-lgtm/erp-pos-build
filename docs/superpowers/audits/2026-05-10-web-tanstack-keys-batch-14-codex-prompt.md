# Codex review prompt — web.tanstack-keys batch 14 (expenses hooks)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `33edc7a0` (production fix; verdict pins here)
**Files (2 + helper):**
- `apps/web/src/features/expenses/_invalidation.ts` (new)
- `apps/web/src/features/expenses/hooks/useExpenseCategories.ts`
- `apps/web/src/features/expenses/hooks/useExpenses.ts`

**Test:** `apps/web/src/features/expenses/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.255`–`.268` (14)
**Cluster:** was 723; this batch closes 14 → 709 if APPROVE.

## Context (compressed — hook-level B5/B6/B8 mirror, 2 namespaces with k[1]==='list' predicate gate)

2 namespaces, both factory-keyed:
- `expenses` factory (.list / .detail) — predicate-based invalidate for plural list cascade; exact-match wrap for singular detail
- `expense-categories` factory (same shape) — same pattern

5 mutations across 2 hooks:
- useCreateExpense / useDeleteExpense / useCreateExpenseCategory / useDeleteExpenseCategory: predicate-only (list cascade)
- useUpdateExpense / usePostExpense / useUpdateExpenseCategory: Promise.all of [list predicate + singular detail exact-match]

**Critical predicate design choice:** each predicate gates on `k[0] === <namespace> && k[1] === 'list'` (NOT just k[0]). Without the `k[1]` gate, the predicate would match BOTH list and detail keys (since the factory uses the same root namespace for both). Update + post mutations would then invalidate the singular detail TWICE — once via the predicate, once via the exact-match invalidate — causing double refetch. Test asserts predicate REJECTS detail keys (handled by exact-match exclusively).

L18 applied upfront: cross-tenant DATA isolation test pre-seeds tenant-B cache with non-empty payloads, renders useExpenses + useExpenseCategories under tenant-A, asserts tenant-A's slot equals the empty mock response (NOT seeded tenant-B payload).

## What to verify

1. Scanner delta = 14 (`audit-tanstack-keys.mjs` count drops from 723 to 709). **Confirmed live: 709.**
2. State-value selectors used in all 9 hooks (4 query hooks + 5 mutation hooks).
3. 2 predicates correctly gate on `k[0] === <namespace> && k[1] === 'list'` AND tail t/c. Each rejects the other namespace's list AND the singular detail.
4. Singular `detail(id)` invalidates (update + post for expenses; update for categories) use exact-match `tenantScopedKey([...factory.detail(id)])` — no predicate; same shape as the leaf useQuery.
5. Cross-tenant cache isolation (tenant-B cache survives tenant-A cascade) AND cross-tenant DATA isolation (tenant-A query results don't contain tenant-B entries) — both asserted upfront, not added after a BLOCK.
6. Mutations' onSuccess async + awaited; toast.success runs after invalidate; sibling-namespace queries (expenses ↔ expense-categories) untouched per cascade test.
7. Cascade tests drive production mutations via `result.current.mutateAsync(...)` — no predicate-direct invalidate (B11 pattern, B12 round-2 caveat closed at scaffold time).

## Quality gate evidence

- `pnpm vitest run src/features/expenses/__tests__/tenantScope.test.tsx`: 22/22 passing.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 0 problems.

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-14-codex-review.md
```

**Critical format:** First non-empty line MUST be `Commit reviewed: 33edc7a0`. Verdict line MUST be a literal `Verdict: APPROVE` (or `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED` / `Verdict: REQUEST-CHANGES` / `Verdict: BLOCK`) on its own line — NOT `## VERDICT:` heading. Use the Write tool to save the file in this turn.
