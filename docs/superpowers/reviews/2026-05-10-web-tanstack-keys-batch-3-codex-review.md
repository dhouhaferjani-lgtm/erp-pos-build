Commit reviewed: 65a6f325
Verdict: REQUEST-CHANGES

## Findings

[F1] apps/web/src/pages/POS/__tests__/tenantScope.test.tsx:242, :248, :265, :291: the cascade test does not prove the predicate invalidated/refetched both tenant-A shift queries. The query functions return constant values, the wait only checks cache entry existence, and the final assertions check the same constants — so an always-false predicate could still pass after the initial fetches. Add a fetch-count or changed-data assertion around the invalidation call to make the test prove the predicate fired.

## Verification Run

- Read the 89-line audit prompt end-to-end.
- Inspected `git show 65a6f325 --stat` and per-file diffs for all 5 touched files.
- Read all 3 POS page files and `_invalidation.ts` at commit `65a6f325` in full.
- Verified production predicate shape, `.848` exact wrapped key, selector-based tenant closure capture, and `enabled` gates — all correct.
- Scanner: `cd apps/web && node tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` → `816`; baseline from prompt was `827`; delta = 11. ✓
- `cd apps/web && pnpm test 2>&1 | tail -40` — Vitest startup reached but Vite could not write temp config (read-only sandbox EPERM); test suite did not complete. Hostile-grep of callsites in `apps/web/src/pages/POS` confirmed no bare `queryKey:` literals remain in production code.
