Commit reviewed: 090ec64c

# Opus review — web.tanstack-keys batch 27 (partners)

Independent second-pair-of-eyes review of the Codex implementation
at `090ec64c`. All 6 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 15 (580 → 565): PASS.** Per-batch delta
verified by file scope: PartnerDetailPage (4 queries + delete invalidate
cascade), PartnerForm (2 queries + create + update invalidates),
PartnerListPage (1 query), usePartnerBalanceRealtime (invalidations on
realtime events), usePartnerContacts (queries). Total = 15.

**Gate 2 — Query keys wrapped with state-value selectors: PASS.** Every
touched component subscribes to:
- `useAuthStore((state) => state.user?.tenant_id ?? null)`
- `useCompanyStore((state) => state.currentCompanyId ?? null)`

PartnerDetailPage wraps `tenantScopedKey(['partner', id])`,
`tenantScopedKey(['partner-documents', id, isSupplierContext])`,
`tenantScopedKey(['partner-payments', id])`, and
`tenantScopedKey(['partner-account-balance', id])`. PartnerForm wraps
`tenantScopedKey(['countries', 'active'])` and
`tenantScopedKey(['partner', id])`. PartnerListPage wraps
`tenantScopedKey(['partners', queryParams])`.

**Gate 3 — List-like predicates don't accidentally match details:
PASS.** `_invalidation.ts` exports three narrow predicates:
- `partnersInvalidationPredicate(t, c)` — `k[0]==='partners'` (plural),
  rejects `['partner', id, ...]` singular naturally.
- `partnerVehiclesInvalidationPredicate(partnerId, t, c)` — gates on
  `k[0]==='partner-vehicles' && k[1]===partnerId` (length>=4).
- `partnerAccountBalanceInvalidationPredicate(t, c)` —
  `k[0]==='partner-account-balance'` namespace.

**Gate 4 — Form mutation cascades await before navigation: PASS.**
PartnerForm's create + update mutations use `async onSuccess` with
`await Promise.all([...invalidations])`. Update also invalidates exact
`tenantScopedKey(['partner', id])` for the singular detail.

**Gate 5 — Realtime invalidation tenant-scoped: PASS.**
usePartnerBalanceRealtime now uses
`partnerAccountBalanceInvalidationPredicate(tenantId, companyId)` to
scope realtime cache invalidation to the current tenant/company.

**Gate 6 — Tests cover scoped key shapes + predicate gates + per-call
refetch counters + tenant-B cache preservation: PASS.** Two test
files: `__tests__/tenantScope.test.tsx` (new) + `partners.test.tsx`
(updated). Combined 50 tests pass.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/partners/__tests__/tenantScope.test.tsx
  src/features/partners/partners.test.tsx`: **50/50 pass**.
- `audit-tanstack-keys`: per-batch delta 15 verified by file scope;
  live cumulative count consistent with downstream batches.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems (pre-lock).

Verdict: APPROVE
