# Tenant Key Guard Wiring Report

## Files Changed

- `.github/workflows/ci.yml`
- `CLAUDE.md`
- `apps/web/package.json`
- `apps/web/tools/audit-tanstack-keys.mjs`
- `apps/web/tools/__tests__/audit-tanstack-keys.test.mjs`
- `docs/conventions/05-REACT-QUERY.md`
- `scripts/preflight.sh`

## Gate Behavior

- Web package: `pnpm --filter @autoerp/web lint` now runs `pnpm lint:eslint && pnpm audit:keys`.
- Web package: `pnpm --filter @autoerp/web audit:keys` runs `node tools/audit-tanstack-keys.mjs`; `test:arch` delegates to the same command.
- Preflight: `./scripts/preflight.sh` now runs ESLint via `pnpm lint:eslint`, then runs `pnpm audit:keys` as a separate frontend check.
- CI: the existing `frontend-lint` job in `.github/workflows/ci.yml` now runs `pnpm audit:keys` before the ESLint warning ratchet.
- Scanner: default gate mode exits `1` for new unscoped query-key violations or stale baseline entries. `--json` remains raw inventory output and preserves exit `0` for existing scanner consumers.

## Baseline

The current tree has 14 existing violations. They are explicitly baselined in `apps/web/tools/audit-tanstack-keys.mjs` so the gate is green today but fails on any new violation or stale baseline entry.

- `src/features/catalog/hooks/useVariants.ts` `invalidateQueries` `attributeKeys.all@2039`
- `src/features/catalog/hooks/useVariants.ts` `invalidateQueries` `attributeKeys.all@2330`
- `src/features/catalog/hooks/useVariants.ts` `invalidateQueries` `attributeKeys.all@2673`
- `src/features/catalog/hooks/useVariants.ts` `invalidateQueries` `variantKeys.forProduct(productId)@3357`
- `src/features/catalog/hooks/useVariants.ts` `invalidateQueries` `variantKeys.forProduct(productId)@3788`
- `src/features/catalog/hooks/useVariants.ts` `invalidateQueries` `variantKeys.forProduct(productId)@4119`
- `src/features/inventory/useLoyaltyEarnRate.ts` `useQuery` `['loyalty', 'earn-rate']@585`
- `src/features/purchases/supplier-invoices/api.ts` `useQuery` `supplierInvoiceKeys.list(params)@1862`
- `src/features/purchases/supplier-invoices/api.ts` `useQuery` `supplierInvoiceKeys.detail(id)@3443`
- `src/features/purchases/supplier-invoices/api.ts` `invalidateQueries` `supplierInvoiceKeys.detail(id)@4700`
- `src/features/purchases/supplier-invoices/api.ts` `useQuery` `supplierInvoiceKeys.attachments(documentId)@5788`
- `src/features/purchases/supplier-invoices/api.ts` `invalidateQueries` `supplierInvoiceKeys.attachments(documentId)@6428`
- `src/features/purchases/supplier-invoices/api.ts` `invalidateQueries` `supplierInvoiceKeys.attachments(documentId)@6855`
- `src/features/purchases/supplier-invoices/api.ts` `invalidateQueries` `supplierInvoiceKeys.detail(invoiceId)@7880`

## Verification Commands

Run these from the repo root:

```bash
pnpm --filter @autoerp/web exec vitest run tools/__tests__/audit-tanstack-keys.test.mjs
pnpm --filter @autoerp/web audit:keys
pnpm --filter @autoerp/web lint
bash -n scripts/preflight.sh
```

Observed in this worktree:

- `pnpm --filter @autoerp/web exec vitest run tools/__tests__/audit-tanstack-keys.test.mjs` -> exit 0, 26 tests passed.
- `pnpm --filter @autoerp/web audit:keys` -> exit 0, 14 baseline acknowledged, 0 new, 0 stale.
- `pnpm --filter @autoerp/web lint` -> exit 0, ESLint reported 8433 existing warnings and the key audit passed.
- `bash -n scripts/preflight.sh` -> exit 0.
