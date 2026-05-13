# web.tanstack-keys Batch 89 — Opus Review

Commit reviewed: 936768fc
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `936768fc` — fix(tenant-isolation): wrap web.tanstack-keys batch 89 (finance reports)
Scope: 6 hook callsites (finance reports).
- `useAgedPayables.ts` (.273), `useAgedReceivables.ts` (.274), `useBalanceSheet.ts` (.275), `useFinanceSummary.ts` (.276), `useProfitLoss.ts` (.284), `useTrialBalance.ts` (.285).

Test: existing `apps/web/src/features/finance/hooks/__tests__/tenantScope.test.tsx` extended with 1 new it-block.

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 6 hooks use `tenantScopedKey([...])`. All pure reads — no invalidate sites.
2. **State-value selectors**: All select `tenantId` and `companyId` with `?? null`.
3. **Enabled gates**: All AND-combine `tenantId !== null && companyId !== null`.
4. **Cross-tenant isolation test**: New it-block asserts 6 distinct keys land at `[..., 'tenant-A', 'company-1']`, then resets tenant and asserts `useFinanceSummary` does not re-call the API (call count stays at 1).

## Non-blocking findings

1. Tenantless-gating check uses call count for only `useFinanceSummary`; the other 5 hooks are not directly asserted as gated. Pattern is mechanical and identical across all six, so the inference is safe.

## Locks applied

6 callsites locked at fix commit `936768fc`:
web.tanstack-keys.273, .274, .275, .276, .284, .285.
