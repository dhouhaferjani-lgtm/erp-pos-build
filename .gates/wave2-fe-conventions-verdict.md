# Wave 2 Frontend-Conventions Review — multi-location management

Branch: `feat/multi-location` (tip `4c29e831b`)
Scope: `git diff origin/dev...HEAD -- apps/web/src` (Wave 1 scope-picker/locations, Wave 2 inventory matrix/transfers/receiving/rebalancing, Wave 3 repository/user location assignment)
Guardrails run from `apps/web`: `pnpm lint`, `pnpm typecheck`.

## Guardrail results (re-run, not trusted from reports)
- `pnpm typecheck` — PASS (0 errors).
- ESLint — 0 errors, 6462 warnings (all warnings pre-existing; no new `precision/*` errors from this branch).
- `audit:keys` (tanstack scoping) — PASS: 0 new, 0 stale. Every location-consuming query uses `tenantScopedKey`/`locationScopedKey`.
- `audit:design-system` — **FAIL, exit 1**: 5 NEW violations + 2 stale baseline entries. Baseline file was NOT modified on this branch, so `pnpm lint` overall FAILS.

---

## BLOCKER

1. **Design-system CI gate is RED — `pnpm lint` fails (exit 1).** 5 new violations, none baselined; 2 stale baseline entries left behind. This is the authoritative canonical-component gate and must be green (0 errors) to merge.
   - `src/features/inventory/components/ProductStockLevels.tsx:176` — C5 raw `<table>`. The per-location breakdown was rewritten from a `<details>`/grid into a hand-rolled `<table>`. Fix: render via `DataTable` (or `LineItemsTable`); do not introduce a raw table in `features/`.
   - `src/features/inventory/pages/StockByLocationPage.tsx:28` — C2 raw `<input>` search box (`className={tokens.input.base}`). Fix: use the `Input` atom.
   - `src/features/settings/components/LocationAccessField.tsx:46` and `:62` — C2 raw radio `<input>` ×2; `:85` — C2 raw checkbox `<input>`. Radios are a known baseline exception "until a Radio atom exists," but these are NEW and are flagged as new debt. Fix: either build/use the atom, or honestly acknowledge them in `tools/audit-design-system-baseline.json` (do NOT `--write-baseline` to silently absorb the whole diff — add only these acknowledged entries).
   - Stale baseline entries (branch removed the baselined `OwnerDashboardFilters.tsx` checkbox + button but did not shrink the baseline): `C2 owner-dashboard/.../OwnerDashboardFilters.tsx` and `C3 …OwnerDashboardFilters.tsx`. Fix: remove both stale entries from the baseline.

2. **i18n missing-key defects — raw key strings rendered to users (CLAUDE.md rule 11).** No `fallbackNS` is configured and `inventory.json` / `stock-transfers.json` have no nested `common` block, so `t('common.*')` from those namespaces does NOT resolve to the `common` namespace (contrast: pickers work only because `pickers.json` carries its own `common` block). Confirmed the pattern is new — it does not exist in these namespaces on `origin/dev`. The mocked-identity `t` in the new tests cannot catch this.
   - `src/features/inventory/pages/StockByLocationPage.tsx` — `t('common.loading')` (ns `inventory`) renders literal `common.loading`.
   - `src/features/inventory/components/RebalancingView.tsx:15` — `t('common.loading')` (ns `inventory`) renders literal `common.loading`.
   - `src/features/stock-transfers/components/TransferSourceSuggestion.tsx` — `t('common.cancel')` and `t('common.confirm')` (ns `stock-transfers`) render literal `common.cancel` / `common.confirm` on the confirm modal's footer buttons.
   Fix: use namespace-prefixed keys (`t('common:loading')`, `t('common:cancel')`, `t('common:confirm')`) or add the keys to the feature namespaces (en + fr, and ar where present).

## MAJOR

3. **`src/features/inventory/components/RebalancingView.tsx:18`** — renders raw location UUIDs to the user: `{move.from.location_id} → {move.to.location_id}`. The rebalancing suggestion shows opaque UUIDs instead of location names. Fix: resolve ids to names via `useScopedLocations()` before display.

## MINOR

4. **`src/locales/ar/inventory.json`** — missing `stock.minQuantity` and `stock.maxQuantity` (used as `ThresholdEditCell` aria-labels), while the rest of the feature's `stock.*` keys WERE added to ar. en/fr parity is complete; this is an ar inconsistency for two keys the branch otherwise intended to cover.

5. **`src/features/stock-transfers/pages/CreateStockTransferPage.tsx:~483`** — source-location query key renamed to `tenantScopedKey(['locations','scoped'])` but `queryFn` is still `fetchLocations()` (hits `/locations`, i.e. ALL company locations, not the scoped set). Not a tenant/scoping-key bug (tenantScopedKey present), but the key label is misleading and the source dropdown is not actually view-scoped. Align the literal with the data source (or switch to the scoped endpoint if scoping was intended here).

6. **`src/features/inventory/components/ThresholdEditCell.tsx:15`** — `onSuccess` invalidates only `['inventory-stock-matrix']`. When this cell is embedded in `ProductStockLevels` (query key `['product-stock', productId]`, `ProductStockLevels.tsx:66`), a threshold edit from the product page does not refetch that page's own table. Also invalidate the `product-stock` prefix.

---

## What is clean (verified)
- Tenant/location query-key scoping: `audit:keys` 0 new; `tenantScopedKey`/`locationScopedKey` used on matrix, rebalance, suggestion, scoped/management/transaction location hooks.
- No double-unwrap: `getStockMatrix`/`getRebalance` correctly use `api.get` + `response.data` for `{data,meta}` payloads; other paths use `apiGet` single-unwrap.
- Money/quantity precision: `formatQuantity`/`MoneyInput`/`QuantityInput` used; no `parseFloat`/`Number` on money/qty in new code; no hardcoded `step` (QuantityInput `decimalPlaces`); `min="0"` is not a step.
- Design tokens: no new C1/C3/C4 color violations — only structural C2/C5 (see BLOCKER 1). Logical RTL properties used (`end-0`, `ps-7`, `ms-auto`).
- Permission gating present where required: `RequirePermission inventory.adjust` (threshold cell), `inventory.transfers.create` (transfer link), `users.manage_location_access` gating LocationAccessField + management-locations query, self-edit guard in UserEditModal.
- fr/en i18n parity is complete for every branch-added key across inventory/locations/sales/stock-transfers/treasury.

## Verdict rationale
Two BLOCKER classes: the design-system CI gate is red (`pnpm lint` exits 1 with 5 unbaselined new violations + 2 stale entries), and user-facing i18n keys render as literal strings on loading states and confirm-dialog buttons. Both are objectively verifiable and must be resolved before merge.

VERDICT: REJECT
