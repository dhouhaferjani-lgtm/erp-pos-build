Commit reviewed: 6148ebfa

# Opus review — web.tanstack-keys batch 33 (POS terminals)

Independent second-pair-of-eyes review of the Codex implementation
at `6148ebfa`. All 7 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 13 (503 → 490): PASS.** Single file
useTerminals.ts: 2 useQuery hooks (list + detail) + 7 mutations
(create + archive + delete + update + activate + deactivate + toggle)
with various invalidation arities = 13.

**Gate 2 — Terminal list + detail query keys wrapped: PASS.**
- list: `tenantScopedKey([...terminalKeys.lists()])`
- detail: `tenantScopedKey([...terminalKeys.detail(id!)])`

**Gate 3 — Hooks subscribe via `usePosTenantScope()`: PASS.** Helper
reused from B30.

**Gate 4 — Terminal list + detail require tenant/company scope: PASS.**
`enabled: hasTenantScope` for list; `enabled: !!id && hasTenantScope`
for detail.

**Gate 5 — Create/archive/delete target only current-tenant terminal
list caches: PASS.** Single-arm invalidations on the lists predicate
(via async onSuccess + await).

**Gate 6 — Update/activate/deactivate/toggle await `Promise.all`
cascade: PASS.** Each uses async onSuccess + `await Promise.all([
list-predicate, exact-detail wrap])`. Verified via diff grep showing
6× `Promise.all` + 4× `terminalKeys.detail(...)` exact-match wraps.

**Gate 7 — Tests cover scoped key shapes + no-fetch + list/detail
per-call counters + tenant-B preservation: PASS.** 3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/pos/hooks/__tests__/useTerminals.tenantScope.test.tsx`:
  **3/3 pass**.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
