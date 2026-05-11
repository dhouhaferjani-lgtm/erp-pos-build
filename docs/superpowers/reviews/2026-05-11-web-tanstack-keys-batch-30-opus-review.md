Commit reviewed: 19cc5ba7

# Opus review — web.tanstack-keys batch 30 (POS operations)

Independent second-pair-of-eyes review of the Codex implementation
at `19cc5ba7`. All 7 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 12 (541 → 529): PASS.** 5 hooks across
useDiscountPermissions, useDiscountPreview, useHeldOrders, useKitchen-
Channel, useKitchenOrders + the shared `usePosTenantScope` helper.

**Gate 2 — All query keys wrapped at callsite with tenantScopedKey:
PASS.** Verified:
- discount-permissions: `tenantScopedKey(['pos', 'discount-permissions',
  terminalCode])`
- discount-preview: `tenantScopedKey(['pos', 'discount-preview',
  debouncedRequest])`
- held-orders: `tenantScopedKey([...heldOrderKeys.list(terminalId, shiftId)])`
- kitchen orders: `tenantScopedKey([...kitchenKeys.orders()])`

**Gate 3 — Hooks subscribe via `usePosTenantScope()`: PASS.** Helper
at `usePosTenantScope.ts:9-10` reads state-value selectors for
tenant_id + currentCompanyId. Returns `{tenantId, companyId,
hasTenantScope}`. Also exports `scopedKeyPredicate(namespace, t, c)`
for reuse across POS batches.

**Gate 4 — Existing terminal/request enabled gates preserved + extended:
PASS.** Each hook preserves `!!terminalCode`, `!!debouncedRequest`,
`!!terminalId` and AND-combines with `hasTenantScope`.

**Gate 5 — Held-order invalidations use `scopedKeyPredicate('held-
orders', ...)`: PASS.** Tenant/company-aware predicate-based
invalidation.

**Gate 6 — Kitchen mutation cascades await exact + scoped: PASS.**
All 5 onSuccess handlers are async. Served-order cascade also
invalidates only tenant/company-matching `orders` list caches via
predicate.

**Gate 7 — Tests cover scoped key shapes + no-fetch + cross-tenant +
realtime invalidation + mutation cascades + per-call counters: PASS.**
4 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/pos/hooks/__tests__/posOperations.tenantScope.test.tsx`:
  **4/4 pass**.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
