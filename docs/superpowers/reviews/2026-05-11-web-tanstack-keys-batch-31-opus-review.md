Commit reviewed: 62c4a6d5

# Opus review — web.tanstack-keys batch 31 (POS orders)

Independent second-pair-of-eyes review of the Codex implementation
at `62c4a6d5`. All 7 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 15 (529 → 514): PASS.** Single file
useOrders.ts: 2 useQuery hooks (list + detail) + 6 mutations each
with at least 2 invalidations (5 line/order state mutations × 2 + 1
create with 1) = 15 callsites.

**Gate 2 — Order list + detail query keys wrapped at callsite: PASS.**
- list: `tenantScopedKey([...orderKeys.list(params)])`
- detail: `tenantScopedKey([...orderKeys.detail(id!)])`

**Gate 3 — Hooks subscribe via `usePosTenantScope()`: PASS.** B30 POS
tenant/company scope helper reused.

**Gate 4 — List + detail queries require tenant/company scope: PASS.**
`enabled: hasTenantScope` for list; `enabled: !!id && hasTenantScope`
for detail.

**Gate 5 — Create-order invalidation targets only current-tenant
orders/list caches: PASS.** Uses `scopedKeyPredicate('orders', t, c)`
predicate (cluster-scoped to list namespace).

**Gate 6 — Line/order state mutations await `Promise.all` cascade:
PASS.** Each of the 5 line/order mutations uses async onSuccess +
`await Promise.all([exact-detail invalidate, predicate list
invalidate])`. Verified at useOrders.ts onSuccess blocks (visible
via diff grep — 6× `Promise.all` + 6× `orderKeys.detail(...)` exact-
match wraps).

**Gate 7 — Tests cover scoped key shapes + no-fetch + list/detail
per-call counters + tenant-B preservation: PASS.** 3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/pos/hooks/__tests__/useOrders.tenantScope.test.tsx`:
  **3/3 pass**.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
