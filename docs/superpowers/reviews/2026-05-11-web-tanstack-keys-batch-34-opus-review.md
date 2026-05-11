Commit reviewed: bb05467b

# Opus review — web.tanstack-keys batch 34 (POS shift layout)

Independent second-pair-of-eyes review of the Codex implementation
at `bb05467b`. All 5 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 4 (490 → 486): PASS.** POSLayout (current
shift useQuery + shift-balance useQuery) + ShiftOperationsMenu
(X-report success cascade = 2 invalidates) = 4 callsites.

**Gate 2 — Current-shift + shift-balance query keys wrapped: PASS.**
- shift: `tenantScopedKey(['pos', 'shift', terminalCode])`
- shift-balance: `tenantScopedKey(['pos', 'shift-balance', shift?.id])`

**Gate 3 — POSLayout subscribes via `usePosTenantScope()` and gates
shift fetches: PASS.** `enabled: !!terminalCode && hasTenantScope` for
the shift useQuery; `enabled: !!shift?.id && hasTenantScope` for the
shift-balance useQuery.

**Gate 4 — X-report success awaits `Promise.all` cascade on exact
scoped shift + shift-balance keys: PASS.** ShiftOperationsMenu's
X-report mutation onSuccess is async and awaits both exact-match
invalidations.

**Gate 5 — Tests cover scoped key shapes + no-fetch + per-call counters
+ tenant-B preservation: PASS.** 3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/pos/layouts/POSLayout.tenantScope.test.tsx`:
  **3/3 pass**.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
