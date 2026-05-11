# Codex review prompt — web.tanstack-keys batch 15 (import queries + ErrorViewer)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `b9811456` (production fix; verdict pins here)
**Files (2 + helper):**
- `apps/web/src/features/import/_invalidation.ts` (new)
- `apps/web/src/features/import/api/queries.ts`
- `apps/web/src/features/import/components/ErrorViewer.tsx`

**Test:** `apps/web/src/features/import/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.286`–`.299` (14)
**Cluster:** was 709; this batch closes 14 → 695 if APPROVE.

## Context (compressed — 4 keyed namespaces, 1 predicate, 5 mutation cascade boundaries)

The import feature's `importKeys` factory is intentionally rich:
- `imports.list` (plural) — predicate-based invalidate via `importsListInvalidationPredicate` (gates `k[0] === 'imports' && k[1] === 'list'`).
- `imports.detail(id)` / `imports.errors(id)` / `imports.preview(id)` — singular leaves; exact-match wrap suffices because invalidate shape matches leaf shape (B3 lesson 4).
- `migration-wizard.order` / `migration-wizard.status` / `migration-wizard.dependencies(type)` — the wizard sub-tree; only `wizardStatus` is invalidated by mutations (useExecuteImport), via exact-match.
- `import-error-summary` and `import-errors` (ErrorViewer ad-hoc bare arrays) — tenant-scoped at callsite; no mutation cascades into them. Kept on their existing namespaces (NOT folded into the factory) per "no scope creep" rule — only the wrap is in scope.

5 mutations:
- useCreateImport: predicate-only (list cascade).
- useExecuteImport: Promise.all of [list predicate + detail exact-match + wizardStatus exact-match] — the only triple-cascade in this batch.
- useDeleteImport: predicate-only (list cascade).
- useSuggestMapping: no cache invalidation.
- (no update mutation here — execute serves the role.)

L18 applied upfront: cross-tenant DATA isolation test pre-seeds tenant-B `imports.list` cache, renders useImportJobs under tenant-A, asserts tenant-A's slot equals the empty mock response (NOT seeded tenant-B payload).

## What to verify

1. Scanner delta = 14 (`audit-tanstack-keys.mjs` count drops from 709 to 695). **Confirmed live: 695.**
2. State-value selectors used in all 9 query hooks + 3 mutation hooks (createJob/execute/delete) + the ErrorViewer component.
3. `importsListInvalidationPredicate` correctly gates on `k[0] === 'imports' && k[1] === 'list'` AND tail t/c. Test asserts:
   - Positive: matches `[imports, list, ...]` shapes.
   - Negative (singular detail/errors/preview): predicate REJECTS `[imports, detail, id, ...]`, `[imports, errors, id, ...]`, `[imports, preview, id, ...]` — handled by exact-match instead, avoiding double-invalidate.
   - Negative (sibling namespaces): rejects `migration-wizard.*`, `import-error-summary`, `import-errors`.
4. Singular invalidates (useExecuteImport for detail + wizardStatus) use exact-match `tenantScopedKey([...factory.X(...)])` inside Promise.all alongside the list predicate.
5. Cross-tenant cache isolation AND cross-tenant DATA isolation (L18) — both asserted upfront.
6. Mutations' onSuccess async + awaited; sibling-namespace queries (wizardOrder, wizardStatus when not cascaded) untouched per cascade tests.
7. Cascade tests drive production mutations via `result.current.mutateAsync(...)` (B11 hook-level pattern).

## Quality gate evidence

- `pnpm vitest run src/features/import/__tests__/tenantScope.test.tsx`: 15/15 passing.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 0 problems.

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-15-codex-review.md
```

**Critical format:** First non-empty line MUST be `Commit reviewed: b9811456`. Verdict line MUST be a literal `Verdict: APPROVE` (or `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED` / `Verdict: REQUEST-CHANGES` / `Verdict: BLOCK`) on its own line — NOT `## VERDICT:` heading. Use the Write tool to save the file in this turn.
