# Opus Review Prompt — web.tanstack-keys B69

Review the Codex implementation for B69 on `feat/tenant-isolation-sweep-execution`.

Fix commit: `8c002141`

Scope:
- B69: `apps/web/src/features/catalog/components/CompositeItemSearchSelect.tsx` — 2 callsites `.053-.054`

Review axes:
- Confirm both component `useQuery` keys are wrapped with `tenantScopedKey(...)`.
- Confirm both queries subscribe to tenant/company store values and are gated while tenant/company is missing.
- Confirm existing component tests still pass and now assert selected-item cache key suffix plus tenantless gating.
- Confirm scanner delta is exactly 2: `223 -> 221`; target file has zero remaining scanner violations.
- Confirm inventory status is `under_review` for `.053-.054` with fix commit `8c002141`.

Gates already run by Codex:
- `pnpm --filter @autoerp/web test -- src/features/catalog/components/__tests__/CompositeItemSearchSelect.test.tsx` — PASS (React Query act warnings only)
- `pnpm --filter @autoerp/web typecheck` — PASS
- `node apps/web/tools/audit-tanstack-keys.mjs --json` — 221 remaining
- `cd apps/api && php artisan sweep:inventory:verify-history` — 5,151 events / 1,205 callsites / 0 problems

Expected verdict if axes hold: APPROVE.
