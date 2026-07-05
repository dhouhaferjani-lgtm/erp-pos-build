# Procurement Wave 9 — Presets + two_way wiring — Adversarial Treasury Review

Date: 2026-07-05 · Reviewer: treasury-reviewer (adversarial, code-grounded)
Worktree: `apps/erp.procurement-v2` (branch `feat/procurement-wave3`, uncommitted)
Scope reviewed: uncommitted working-tree diff only (Waves 1-8 committed).

**VERDICT: spec ⚠️ (one binding-criterion deviation) + quality CHANGES-REQUESTED → NEEDS-REVISION**

Core money-movement behavior (two_way price basis vs 408 clearing) is correct and
well-tested. One Important deviation gates: presets silently overwrite the two
variance-tolerance fields (a financial-control field) — contradicting the explicit
"writes ONLY the mapped fields, doesn't clobber tolerances" criterion, with no test
covering the hazard. Everything else is Minor.

---

## Attack-surface verification

### 1. two_way semantics — 408 clearing unchanged — PASS (strong)
- `MatchMode::TwoWay` is consumed ONLY inside `priceBasisForInvoiceLine()`
  (`SupplierInvoiceMatcher.php:544-546`), which feeds ONLY `computePriceStatus()`
  (`:498`) → returns a `SupplierInvoiceMatchStatus` enum (classification/preview).
  It never touches clearing amounts.
- `bcsub/bcmul` price math (`:503-527`) only produces a Matched/PriceVariance status.
  Posting/FIFO 408 clearing is a separate path, untouched.
- `grep match_mode|MatchMode::` across `apps/api/app` returns NO consumer outside the
  matcher/policy/preset files → the matcher is the first-and-only `match_mode`
  consumer. three_way (preset NULL) skips the two_way branch → byte-identical.
- Tests PROVE the required semantics (all ran green here):
  - `SupplierInvoiceReceiptClearingTest::test_two_way_match_uses_po_price_but_posting_clears_receipt_accrual_basis`
    — PO price 5.000, receipt accrual 5.400, invoice at 5.000 → Matched (vs PO price);
    posting Dr 408 **54.000** (= 10 × 5.400 accrual, NOT 50 = PO price), PPV income
    Cr 4.000, `net408 == 0.000`. Directly answers "does a different-priced receipt
    under two_way still clear 408 at its own accrual" → YES. (11 assertions PASS)
  - `SupplierInvoiceMatcherReceiptBasisTest::test_two_way_matcher_compares_price_against_po_unit_price_not_receipt_basis` PASS.
  - `..._two_way_still_rejects_invoiced_quantity_beyond_received_quantity` (recv 6,
    invoice 7 → QuantityVariance) PASS → invoiced-qty cap is mode-independent.
- No path where two_way changes clearing or bypasses the over-clear/qty guard. CLEAN.

### 2. Preset bundle integrity — PARTIAL (W9-1 Important, W9-4 Minor)
- `ProcurementPreset::values()` enum mapping is exact: Complet three_way/block,
  Standard three_way/warn, Léger two_way/warn (`ProcurementPreset.php:26-49`), asserted
  by `ProcurementPresetTest` (PASS).
- PG CHECK constraints (`2026_06_25_100000_create_procurement_policies_table.php:49-69`:
  bill_control_mode ∈ received/ordered, match_mode ∈ two_way/three_way,
  match_enforcement ∈ warn/block, tolerances ≥ 0) are satisfied by all three bundles.
- **W9-1 (Important):** each preset ALSO writes `variance_tolerance_percent='2.00'`
  and `variance_tolerance_max_amount='1.000'` (`ProcurementPreset.php:31-32,39-40,47-48`),
  and `applyPreset()` force-writes them (`ProcurementPolicy.php:120-126`). These are NOT
  in the spec preset table (Chain | match_mode | match_enforcement only) and are
  IDENTICAL across all three presets, so bundling them adds zero differentiation and
  silently RESETS a user's hand-tuned tolerance whenever ANY preset is picked.
  Tolerance governs whether a price variance BLOCKS an invoice under `block` — a real
  money-control side effect. No test catches it because default tolerance == preset
  tolerance (2.00/1.000). Contradicts the mandated criterion "applying a preset writes
  ONLY the mapped fields (doesn't clobber tolerances)".
- **W9-4 (Minor):** the new `preset varchar(20) NULL` column
  (`2026_07_05_090000_add_preset_to_procurement_policies.php:15`) has NO DB CHECK
  constraint, unlike its three sibling enum columns. Value integrity relies solely on
  the Eloquent cast + FormRequest. Add `CHECK (preset IN ('complet','standard','leger'))`
  in the pgsql block for parity.

### 3. Endpoints — PASS (W9-2 Minor)
- Permissions are REAL, not phantom: `settings.view` + `settings.update` exist in
  `RolesAndPermissionsSeeder.php:393-394`. Routes gate GET on `can:settings.view`,
  PUT on `can:settings.update` (`routes.php:32-38`); request re-checks
  `settings.update` in `authorize()` (`UpdateProcurementPolicyRequest.php:17`).
- Roles holding them: admin (`syncPermissions(Permission::all())`, :414) → both;
  manager (:473) → `settings.view` + `settings.manage` but **NOT** `settings.update`;
  viewer (:569) → `settings.view` only.
- **W9-2 (Minor):** manager holds every other procurement permission
  (purchase-orders.*, goods-receipt.edit-price, invoices.post) but CANNOT PUT this
  policy (403). Fail-closed and demo-safe (the demo owner is assigned `admin`, which
  has settings.update), so not a demo blocker — but the access asymmetry is likely
  unintended. Confirm whether manager should hold `settings.update` (or gate on
  `settings.manage`, which manager has).
- Company-scoped via `CompanyContext::requireCompany()` (`ProcurementPolicyController.php:24`);
  cross-company isolation asserted (`test_put_preset_applies_bundle_and_scopes_to_current_company`).
- Validation matrix (all PASS): preset+raw mixed → 422 on `preset`
  (`UpdateProcurementPolicyRequest::after()` :52-72); unknown enum values → 422;
  negative tolerances → 422; empty body → 422 via `required_without:preset` on all raw
  fields (also belt-and-suspenders in `after()`). `{error:{errors}}` envelope confirmed
  via `AssertsApiValidation`. `ProcurementPolicyApiTest` 6/6 PASS.

### 4. Matcher regression — PASS
- three_way behavior byte-identical for existing tenants (preset NULL / three_way):
  two_way branch skipped, snapshot/`receiptPlanner` path unchanged (`:548-563`).
- Snapshot semantics (Wave 5): `price_match_basis` is still stamped at CREATION with
  the receipt-accrual basis (Wave 9 did not touch creation). Under two_way it is simply
  IGNORED for classification (PO `unit_price` used instead, `:544-546`), so rematch of a
  three_way-created draft after a mode switch to two_way DOES honor the new mode (policy
  read at match time). It is NOT re-stamped with the PO price — the column becomes inert
  under two_way. Acceptable v1 behavior; no defect, noted for clarity.
- HT/HT basis: both invoice `unit_price` and PO `unit_price` are net/HT on B2B
  documents → apples-to-apples, no TTC/HT contamination.

### 5. Seeder — PASS
- `DemoPharmacySeeder` (`:398-400`) calls `firstOrCreateForCompany()->applyPreset(Standard)->save()`.
  Idempotent: `firstOrCreate` on `company_id` + deterministic `applyPreset`.
  `TenantProvisioningService` refactored to the same helper (`:159`), equivalent to the
  prior inline `firstOrCreate` (preset now stamped Standard).
- `DemoPharmacySeederTest` diff is PURELY ADDITIVE — one new method
  `test_procurement_policy_is_standard_preset_and_idempotent` (double-seed → count==1,
  preset Standard). The `test_seeds_gl_consistent_partner_balances` (SUPP-PAYABLE-01)
  test is UNTOUCHED by the diff, and the Wave 9 seeder change only inserts a
  `ProcurementPolicy` row — it is causally incapable of moving a partner
  `payable_balance`. So the claimed SUPP-PAYABLE-01 failure is NOT introduced by Wave 9
  (independently pre-existing; triage separately — a red test in the seeder suite should
  not be normalized).

### 6. Docblock + UI — PASS (W9-3, W9-5 Minor)
- `MatchMode.php:10-12` docblock corrected to the R3D-3 definition (two_way = PO-price
  basis; qty/GR-IR/FIFO 408 clearing still apply). No longer says "no GR requirement".
- Preset picker writes the bundle immediately (`handlePresetSelect` → PUT `{preset}`,
  `CompanyPage.tsx:261-263`); avancé Save sends all five raw fields → controller nulls
  preset (`ProcurementPolicyController.php:41-49`). FE tests assert both payloads
  (real behavior, not `assertTrue(true)`).
- i18n fr/en/ar: identical, complete key sets (22 procurement keys each, verified);
  `tabs.procurement` present in all three.
- No `parseFloat`/`Number()` on money: tolerance inputs are `<Input type="number">`
  emitting `e.target.value` strings; form state and payload stay string.
- **W9-3 (Minor):** new code adds hardcoded Tailwind colors — `border-blue-500`,
  `ring-blue-500`, `hover:border-blue-300`, `bg-white` (`CompanyPage.tsx:443-444`).
  ESLint flags them as WARNINGS (settings/ is legacy surface, not a new-feature
  error-override dir), so CI passes, but rule 18 wants tokens in touched/new code.
- **W9-5 (Nit):** two unnecessary type-assertions at `CompanyPage.tsx:273-274`
  (`no-unnecessary-type-assertion` warnings); `variance_tolerance_max_amount` uses a raw
  `<Input type="number">` rather than `<MoneyInput>` (no float leak, so contract-safe,
  but not the canonical money primitive).

---

## Findings (severity order)
- **[Important] W9-1** `ProcurementPreset.php:31-32,39-40,47-48` + `ProcurementPolicy.php:120-126`
  — preset bundles include + force-overwrite `variance_tolerance_percent`/`max_amount`
  (identical across all presets). Selecting any preset silently resets user-tuned
  tolerances (a variance-blocking financial control). Contradicts the mandated
  "writes ONLY the mapped fields, doesn't clobber tolerances" criterion; untested.
  Fix: either drop the two tolerance keys from `values()` so `applyPreset` preserves
  existing tolerances, OR (if full-bundle semantics are the owner's intent) add a
  test proving the intended overwrite and add the tolerance columns to the spec preset
  table. Resolve before merge.
- **[Minor] W9-2** `RolesAndPermissionsSeeder.php:473` — manager lacks `settings.update`;
  cannot PUT the policy despite holding all other procurement perms. Fail-closed,
  demo-safe (owner=admin). Confirm intended grant.
- **[Minor] W9-4** `2026_07_05_090000_add_preset_to_procurement_policies.php:15` — no DB
  CHECK on `preset` (siblings all have CHECKs). Add `CHECK (preset IN (...))` in pgsql.
- **[Minor] W9-3** `CompanyPage.tsx:443-444` — hardcoded blue/white Tailwind classes in
  new code (ESLint warnings). Migrate to design tokens.
- **[Nit] W9-5** `CompanyPage.tsx:273-274` unnecessary type assertions; amount field uses
  raw `<Input type=number>` instead of `<MoneyInput>` (contract-safe, string-preserving).

## What to fix before merge
Resolve W9-1 (stop clobbering tolerances, or confirm full-bundle intent + add a proving test); W9-2/W9-3/W9-4 are low-risk follow-ups.
