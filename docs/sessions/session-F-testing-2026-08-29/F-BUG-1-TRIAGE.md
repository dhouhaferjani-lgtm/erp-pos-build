# F-BUG-1 — "location resets to default on refresh, breaking imports" — triage record

Session F · 2026-08-29 · orchestrator: Claude (Fable). Evidence gathered read-only on staging PG + local repro on `dev` = `a4ceeb0f5` (identical to the staging web bundle `index-DTzcxcul.js`, deploy `PiJfSQG0LSz7uUCi_wEYj` 2026-08-28 19:43).

## TL;DR

The reported symptom (header location reset on refresh) does **not** reproduce on local against the same bundle — the view-scope picker survives refresh (persisted under `autoerp-view-scope:<company>:<user>`). What the team actually hit today on staging is a **cluster of import defects that make the selected location irrelevant**, and every one of them is confirmed from code + staging data:

| # | Defect | Severity | Evidence |
|---|---|---|---|
| 1a | A company created **inside an existing tenant** gets a default "Main Location" with **no code** (`CompanyController::store` omits `code`; both registration paths set `MAIN`). An import can therefore never address that company's only location via `location_code`. | P1 | staging: 4 such locations — `019ee4d7` companies *jerbi* (09:21 today) + *Otospex* (09:53 today); `019fc714` *OTOSPEX LLC* + *synerivia* (08:57 today). Code: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:123-140` vs `TenantProvisioningService.php:161-166` / `AuthController.php:428-433`. |
| 1b | The products import wizard is **location-blind**: it never sends `options.location_code` (backend supports it: `ImportController.php:381,704`) and never reads the header selection. Opening stock is posted **only** when a `location_code` resolves (`ProductOpeningStockPhase.php:77-81, 243-253`); otherwise the row is `skipped: location_unresolved` — as a *warning*, and the completion screen shows only Imported/Failed (`ImportWizardPage.tsx:1040-1058`), so the operator sees "855 imported / 4 failed" and **zero stock**. | P1 | local: 2-row products import w/o `location_code` → `Imported 2 / Failed 0`, `warning_rows: 2`, **0 stock_levels rows** (job `01a04cf5-a6b0-…` on tenant `01a035ba`). staging: job `01a04cd3-146d-…` (019ee4d7, 09:21:36, *jerbi*): 855/859 imported, warnings `location_unresolved` **422** + `qty_without_cost` 3 = **all 425 rows with quantity>0**; `stock_movements` today = **0**. |
| 1c | The team's `location_code` cells hold values like `ZF1161`, `ZF625` (one per row → these are **shelf/bin positions**, i.e. `placement_path`, not location codes). FR "emplacement" means both. Column semantics are ambiguous in the template. | P2 (UX) | staging rows of job `01a04cd3` (`data->>'location_code'` distinct per row; `placement_path` empty). |
| 1d | Importing the same catalogue into a **second company of the same tenant** fails every row with a raw `SQLSTATE[23505] products_tenant_id_sku_unique` — SKU uniqueness is **tenant-wide** (`2025_11_30_052910_create_products_table.php:37`), and the wizard shows it as 856 failures with a DB error string. | P2 (ruling) | staging: `019fc714` jobs 09:00 / 09:07 / 09:27 (856 unique-violations + 3 rows `quantity = -1`); `019ee4d7` job 09:53 (same). |
| 1e | `AddCompanyModal` / `CompanyOnboardingPage` call `setCurrentCompany(newId)` right after create, but the store guard `companies.find(...)` rejects an id not yet in the (stale) list → **silent no-op**: the operator is *not* switched to the company they just created (selection + persisted key stay on the old company). | P2 | code: `AddCompanyModal.tsx:70-74`, `companyStore.ts:setCurrentCompany`. Not exercised today (products did land in *jerbi*, so they switched manually) but it is exactly the "I'm not where I think I am" trap in the create-company → import flow. |

Local vs staging inconsistency: **none** on the refresh behaviour (same bundle, same result). The inconsistency is *between what the UI says and what the DB did* (1b).

## What was reproduced

### Local (owner-equivalent admin, tenant `01a035ba`, 3 locations)
1. Login → header "All locations" → pick *Boutique Ariana* only → "1 locations". `localStorage['autoerp-view-scope:01a035ba-b7c7…:01a035ba-b7bf…'] = ["01a035bc-bb65…"]`.
2. Full reload → header still "1 locations"; key unchanged. **No reset.**
3. `autoerp-location` (zustand `locationStore`) = default location id — that store has **no UI to switch it** (`switchLocation` has zero callers outside `AddLocationModal`), so `ExpiryWriteOffPage`/`ToBillPage` always act on the default location. Latent, not today's bug.
4. `POST /imports` (products, `sku,name,type,sale_price,purchase_price,quantity`, no `location_code`) → `validated` → `POST …/execute` → `{status: completed, successful_rows: 2, failed_rows: 0, warning_rows: 2}`; `import_rows.warnings = [{code: location_unresolved}]` ×2; `stock_levels` for both SKUs: **none**.

### Staging (read-only PG 157.180.71.252:5434, today 2026-08-29)
- `tenant019ee4d7` (owner@pharmabio.tn): 09:20 `products_template.csv` 2/2 ok (`category_created`) → company *jerbi* created 09:21:00 (Main Location, code `''`) → 09:21:36 `model produits.xlsx` 859 rows: 855 ok / 4 failed, warnings `price_conflict` 543, `location_unresolved` 422, `qty_without_cost` 3 → 0 stock movements → company *Otospex* created 09:53:03 → 09:54 re-import: 856 × `products_tenant_id_sku_unique`, 3 × `quantity=-1`.
- `tenant019fc714` (bensaber.karim@gmail.com): company *synerivia* created 08:57:12 (Main Location, code `''`) → 09:00 / 09:07 / 09:27 `model produits.xlsx`: 856 × unique-violation (SKUs exist in *Paradeals.tn*), 3 × `quantity=-1`; every attempt `failed`.

## Company switching — the team's actual complaint (owner clarified 2026-08-29 ~12:10: "switching between companies specifically")

**Reproduced locally, root cause confirmed in code.** Flow (identical to the team's morning): create a second company via *Add Company* → app stays on the old company (row 1e) → pick the new company in the header (works; `autoerp-company-selection` = new id) → **reload → back on the primary company, and the key is overwritten with the primary id**. No 403, no console error.

Cause: `authStore.ts` persists `user` + `token` but deliberately not `isAuthenticated` (boots `false`); `AuthProvider` renders children immediately because a persisted `user` exists; `CompanyProvider.tsx:126-131`

```ts
useEffect(() => { if (!isAuthenticated) { reset(); queryClient.removeQueries(…) } }, [isAuthenticated, …])
```

therefore runs on **every page load** and `reset()` removes `autoerp-company-selection`. When `/auth/me` and `/user/companies` resolve, `resolveCompanySelection` has no persisted id → primary → re-persisted over the user's choice. Single-company users never notice (only one candidate), which is why it surfaced today, the first day the team had two companies. The 2026-06 "scope switches on its own" fix (`0732c7641`) guarded `LocationProvider` only; this effect dates from `a102ba647` (Phase 0).

Secondary, observed on the manual switch: one render of old-company location-scoped queries refetches with the previous company's `location_ids[]` → 403 `treasury/cash-position` → "Access denied…" error surfaced (stale-scope race in `CompanySelector.handleCompanyChange` blanket `invalidateQueries()`).

Dispatch: **Lane F2** now carries all three (boot reset = P1, post-create switch no-op = P2, stale-403 noise = P3) — `LANE-F2-company-switch-BRIEF.md`.

## Locations / branches — what was tested (owner asked whether anything there needs testing)
- Header *View scope* (locations) persists across refresh — verified locally, same bundle as staging. Nothing to test there beyond a smoke pass after F2 lands (switch company → scope resets to "All locations" for the new company, by design).
- Latent, not today's bug: `locationStore.currentLocationId` has no UI switcher, so `ExpiryWriteOffPage`/`ToBillPage` always act on the **default** location. Worth a ticket, not a Session F lane.
- Imports + locations: see rows 1a–1c (Lane F1) — this IS worth the team re-testing once F1 ships: import with quantities → stock must appear in the chosen location.

## Ruled out
- `LocationProvider` company-change reset (`previousCompanyIdRef` guard — only on a real change).
- `handleCompanyScopeRejection` / `clearScopeForNewSession` / `clearAllAppState` (403 / login / logout only).
- Shared-localhost POS `localStorage` conflict (POS never writes `autoerp-*` keys; irrelevant on staging anyway).
- Stale staging web build (deploy log + bundle grep for `autoerp-view-scope` / `_hydrate`).
- N-12 (branch drawers) and W2-* — neither touches location resolution in imports.

## Still open (needs the team)
- Exact steps/screenshot for "location resets to default on refresh": which page, which control (header *View scope* picker? company selector? a form field?). Hypothesis: they saw the import ignore their location and read the header's correct default ("All locations") as a reset.
- A staging login for a tester account (no creds in any session doc) so I can replay their exact flow.

## Dispatch
- **Lane F1 (P1)** — `fix/f-bug-1-import-location` → brief `LANE-F1-import-location-BRIEF.md`: 1a + 1b (+ 1c label clarification) with TDD; gates: imports-reviewer + tenancy-authz (or Codex read-only gate while Claude subagents are rate-limited).
- **Lane F2 (P2)** — 1e company-switch no-op, dispatched after F1 (≤2 concurrent agents on hotspot).
- **1d** → owner ruling: tenant-wide vs company-scoped SKU uniqueness + human-readable duplicate error → hand to Session G imports-hardening (newer-overrides duplicate policy already on its list).

## Local side-effects to know about
- Local tenant `01a035ba` user `hedi.bensalem@parawave4.tn` password reset to `password` (dev DB only).
- Two test products `FBUG1-001/002` + 2 import jobs left in that local tenant (harmless; delete if the demo must stay pristine).
