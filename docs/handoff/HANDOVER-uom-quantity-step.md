# HANDOVER — Per-unit quantity step/precision + onboarding-safe 403s

> Paste this into a fresh session. Read-then-brainstorm-then-TDD. Two related workstreams: **A (priority) = per-unit quantity step**, **B = descriptive, remediation-bearing errors so onboarding tenants don't hit bare 403s.** A is the bug the owner felt; B is the principle behind the `uom.view` 403 we saw.

Repo: `/Users/houssamr/Projects/syneriva/apps/erp` · branch off `origin/dev` (this is mostly main-repo work, NOT the izipos editor worktree). Work in a `git worktree` off `origin/dev` (rule 21). Suggested branch: `fix/per-unit-quantity-step`.

---

## The problem (owner, verbatim intent)
When you click the +/- arrows on a quantity field in invoices / purchase orders / sales orders / quotes / credit & delivery notes, it increments by **0.0001** even when the product's unit is **pieces** (which should step by **1**). A unit should carry its own precision, and every quantity selector should step by that unit's precision — pieces → 1, kg/L → fractional.

## What ALREADY exists (do NOT rebuild — verified)
The whole mechanism is in place; it's just not threaded into the document line editor.

- **Unit precision lives on the unit as `decimal_places`** (not a separate "step"): `apps/api/app/Modules/Uom/Domain/Entities/Unit.php:18-29`; migration `database/migrations/tenant/2026_01_09_095045_create_units_table.php:14-40` (`decimal_places` int, `rounding_method`). There is intentionally **no** `step`/`is_integer` column — `decimal_places` is the source of truth.
- **`QuantityInput` already derives step from decimal places:** `apps/web/src/components/atoms/QuantityInput/QuantityInput.tsx:56` → `step = String(1 / 10 ** decimalPlaces)`. So `decimalPlaces={0}` → step `1`; `{4}` → `0.0001`.
- **The product carries its unit's precision to the FE:** `ProductData` exposes `quantity_decimals` (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:39,77,101-108`) derived from `unitOfMeasure->decimal_places` (fallback 4). FE util `apps/web/src/lib/quantityScale.ts:7-14` `getQuantityDecimals(product)`.
- **Reference implementation that does it RIGHT:** `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:552` → `decimalPlaces={getQuantityDecimals(line.product)}`.

## Root causes (the actual fix surface)
1. **`DocumentLineEditor.tsx:354` hardcodes `decimalPlaces={4}`** — this single line is why invoices/POs/SOs/quotes/credit & delivery notes all step by 0.0001 regardless of unit. (Stock-transfer *batches* table at `CreateStockTransferPage.tsx:178` also hardcodes `{4}` — fix it too; the *allocation* table next to it is already correct.)
2. **`unitOfMeasure` must be eager-loaded** wherever products feed a line editor, or `quantity_decimals` silently falls back to 4 (`ProductData` quantityDecimals returns 4 unless `relationLoaded('unitOfMeasure')`). Audit the product list/search/picker endpoints these editors consume and add `->with('unitOfMeasure')`.
3. **Confirm the seeded "pieces" unit has `decimal_places = 0`.** Check `database/seeders/UomSeeder.php` (categories: pieces/weight/volume/length/time). If pieces is seeded with a nonzero `decimal_places`, step-by-1 is impossible no matter what the FE does. This is the keystone — verify FIRST.

## Workstream A — scope & method (priority)
1. **Verify the data**: pieces unit `decimal_places=0` in the seeder; a real parapharmacy product on a "pieces" unit returns `quantity_decimals: 0`. If not, fix the seed + add a test.
2. **Thread the unit precision** into every quantity selector. Audit EVERY `QuantityInput`/`QuantityCell` usage for a hardcoded `decimalPlaces` literal (the `no-hardcoded-step` ESLint rule at `apps/web/eslint-rules/no-hardcoded-step.js` flags hardcoded *steps* but NOT a hardcoded `decimalPlaces={4}` prop — so grep manually: `grep -rn "decimalPlaces={" apps/web/src`). Replace literals with `getQuantityDecimals(line.product)` (or the row's product).
   - Targets confirmed: `DocumentLineEditor.tsx:354`, `CreateStockTransferPage.tsx:178`. Also sweep POS cart qty, stock adjustment, goods receipt, and any `LineItemsTable` caller (`apps/web/src/components/molecules/line-items/LineItemsTable.tsx:178-200` passes `decimalPlaces` straight through — callers are the fix point).
3. **Eager-load `unitOfMeasure`** on the product-fetching endpoints those editors use; add a contract test asserting `quantity_decimals` is present and correct (0 for pieces).
4. **Tests** (TDD): FE — DocumentLineEditor with a pieces product renders a qty input that steps by 1; with a kg product steps by 0.0001. BE — ProductData contract test for `quantity_decimals` per unit. Run FE tests by path; backend tests BY PATH only (full suite fatals — see env note).
5. **Decide rounding on unit change**: if a line has qty `2.5` and the user switches the line's product/unit to pieces (dp 0), what happens to the entered value? Brainstorm the rule (clamp/round/block) and make it explicit — don't leave it implicit.

**Out of scope for A:** do not add a new `step` column (decimal_places is the SoT); do not touch storage scale (canonical `decimal(15,4)` is correct — `2026_05_29_100002_widen_quantity_columns_to_scale_4.php`). Storage stays 4dp; only the **UI increment** follows the unit.

## Workstream B — onboarding-safe, descriptive 403s (the broader principle)
The `uom.view` 403 we saw is the canonical "tenant forgot to set something up / role wasn't granted a permission" failure. Owner's principle: **any tenant who onboards must not silently hit bare 403s — if it happens, the error must be descriptive and tell them how to fix it.** (The specific 4034 "Cafe Tunis" demo user we do NOT care about — it's the systemic pattern that matters; we're launching with parapharmacy.)

Findings:
- `uom.view` is checked via `Gate::authorize('uom.view')` (`apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:36,68`).
- The permission is created/granted in the **older** `database/seeders/PermissionSeeder.php:150,178` (Administrator only) but is **missing from the newer `RolesAndPermissionsSeeder.php`** → roles provisioned via the newer path never get it. That's the real onboarding bug.
- 403s use Laravel's default (`bootstrap/app.php` has no custom `AuthorizationException` handler) → generic "This action is unauthorized." with no context.

Scope:
1. **Reconcile permission seeders** — ensure `uom.view` (and audit for other gaps) is granted by `RolesAndPermissionsSeeder` to the roles that need it (cashier/manager/etc. per vertical), so a freshly-provisioned tenant has it. Add a test asserting a newly-seeded non-admin role can hit `GET /api/v1/uom/units`.
2. **Make permission-denied responses descriptive** — a shared `AuthorizationException` render in `bootstrap/app.php` that returns the missing permission/ability and a remediation hint (e.g. "Your role lacks `uom.view`. An administrator can grant it under Settings → Roles."). Keep it i18n-friendly (rule 11) and don't leak internals beyond the ability name.
3. **(Brainstorm) onboarding completeness check** — a lightweight "tenant setup checklist / health check" that flags required-but-unset config (units, default tax, currency, roles missing key permissions) at onboarding, so 403s are prevented rather than explained. Scope this as a follow-up if it balloons; the seeder reconcile + descriptive errors are the must-ship.

**Coordinate:** permission-seeder edits touch a shared file other sessions also touch — keep the change minimal and additive.

---

## Worktree env facts (apply to ANY backend work here) — see memory `project_izipos_worktree_backend_env_gotchas`
- Run backend tests **BY PATH**, never the full suite / bare `--filter` (collection fatals on a pre-existing broken `OwnerSalesSummaryServiceTest`): `php artisan test tests/Feature/Modules/Uom/...Test.php`.
- **NEVER run the full PHPUnit suite** (crashes the laptop — feedback rule).
- Validation errors use the custom envelope `{error:{errors:{field:[…]}}}` → use `Tests\Traits\AssertsApiValidation`.
- `php artisan typescript:transform` needs array cache: `CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array BROADCAST_CONNECTION=null php artisan typescript:transform`.
- Money/qty: rule 19 — strings, `QuantityInput`/`MoneyInput`, no `parseFloat`.
- Preflight before commit; reconcile with `origin/dev` before finishing (rule 21).

## Acceptance bar
- A parapharmacy product on a **pieces** unit: qty arrows step by **1** in invoice, PO, SO, quote, credit note, delivery note, stock transfer (both tables), POS cart, stock adjustment, goods receipt.
- A product on a **kg/L** unit still steps fractionally (0.0001 or its unit's dp).
- A freshly-provisioned non-admin role can read `/api/v1/uom/units`; if a permission IS missing anywhere, the 403 names the ability and how to fix it.
- Tests cover the pieces-vs-fractional step and the permission grant. Preflight green.
