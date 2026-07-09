# Adversarial Review — M1 Implementation Plan vs. Rev 3 Spec

## GATE VERDICT: ❌ CHANGES REQUIRED — DO NOT DISPATCH

The plan is well-structured and follows most Rev 3 overrides (drops `margin_floor_buffer_percent`, drops `pricing.override_discount_floor`, Advisory default, DTO-only Pricing, cap cascade product→category→company, `sale_price` HT). But it has **three blocking defects** (a core-guarantee correctness risk, an undefined cross-wave contract, and a permission-catalog gap) plus several scope and coverage gaps. Not clean.

---

## BLOCKER

**B1 — Floor formula is markup, not margin; may silently violate the "never lose" guarantee.**
Wave 3 (plan `598–599`) computes `floor = WAC × (1 + minMargin/100)`, and the test (`537`) bakes in `WAC=100, minMargin=10 → floor=110.000`. That is a **markup-on-cost** formula. If the existing `MarginService`/`default_minimum_margin` (spec `R3-1`, lines `14–15`; `R3-6`, line `35`) defines margin as **margin-on-selling-price** — the retail convention — the correct floor is `WAC / (1 − minMargin/100)` (= 111.11 for the same inputs). The plan's floor would sit *below* the true minimum-margin price, so a sale at 110 would pass the gate while actually breaching minimum margin — defeating the entire feature. The plan never pins its definition to `MarginService`'s actual margin math, and Wave 3 has no parity test against `MarginService`. **Must** verify against `MarginService::getMarginLevel`/`updateSalePrice` and add a parity assertion before any code is written.

**B2 — `DiscountPolicyInterface::resolveMany` is required by Wave 4 but never defined or implemented in Wave 3.**
Wave 4 (plan `690`, and the spy at `740–750`) resolves all document lines "in one batch via `DiscountPolicyInterface::resolveMany`" — mandatory per `R3-4` (line `28`: "Resolve ALL document lines in ONE batch pass … N+1"). But Wave 3's `DiscountPolicyService` implements only single-line `resolve(DiscountPolicyContext): DiscountPolicyVerdict` (`573–590`), and `DiscountPolicyContext` (`522–532`) is single-product. There is no `resolveMany` method, no multi-line context type, and no batch verdict shape anywhere in Waves 2–3. Wave 4 depends on a contract that the plan never builds. Either Wave 3 must add `resolveMany` (and the batch context/verdict shapes), or Wave 4 cannot satisfy the N+1 requirement.

**B3 — Plan grants `pricing.sell_below_minimum_margin` only as a role mapping, but never guarantees the permission exists in the catalog.**
`R3-2` (line `18`) says: *"if `pricing.sell_below_minimum_margin` is missing from the catalog/roles, ADD it and grant to manager+admin with a DENY-path test."* The plan's file structure (line `64`) mentions `PermissionSeeder.php`, but Wave 1's Files list (`109`) omits it and Step 3 states "Grant manager permissions in `RolesAndPermissionsSeeder` **only**" (`256`), with Global Constraints reinforcing "not new floor permissions" (`64`). Granting a role a permission that isn't registered in the catalog will fail or no-op. The test at `170` asserts the manager *has* it, but nothing in the plan creates it if absent. Add a verify-and-register step in `PermissionSeeder` (or make it conditional), per `R3-2`.

---

## MAJOR

**M1 — Regulatory rule engine + TN pharma seed is out-of-scope Phase-3 work.**
Wave 1 builds `country_pricing_regulations` (plan `94`, `99–100`), `RegulatoryRuleType`/`RegulatoryEnforcement` enums (`97–98`), and Wave 3 adds `RegulatoryPricingRuleInterface`, `BelowCostRegulatoryRule`, and an `iterable $regulatoryRules` injected into the service (`490–491`, `570–580`). Rev 3 Phase-1 scope (§1, lines `85–87`) does **not** list a regulation table or rule engine. The TN `PharmaMarginSchedule` row asserted in the Wave 1 test (`150–154`) is explicitly **Phase 3** (§1, line `91`). Decision 3 (line `50`) authorizes *seeding FR/TN below-cost rules as advisory* — not a full pluggable rule engine or the pharma schedule. This violates CLAUDE.md Rule 4 (no scope creep). Trim to what Rev 3 mandates, or get explicit owner sign-off to pull Phase-3 scaffolding forward.

**M2 — `companies.price_entry_mode` column + `PriceEntryMode` enum are not in Rev 3.**
Wave 1 adds `companies.price_entry_mode` (`34`, `96`, `136`, `203–207`). Rev 3 `R3-6` (line `35`) asks for an HT/TTC **display** toggle on the panel — not a persisted company entry-mode preference. No Rev 3 section mandates this column. Either cite the requirement or drop it (scope creep).

**M3 — Document validator is a parallel gate, not an extension of `canSellAtPrice`.**
`R3-4` (line `28`) is explicit: *"It EXTENDS the existing `canSellAtPrice` verdict, not a parallel gate."* Wave 4 builds a fresh `DiscountPolicyDocumentValidator` calling `DiscountPolicyInterface` (`680`, `802`) and never references `canSellAtPrice` anywhere. As written this is a parallel enforcement path. The plan must show how the new check extends/hooks the existing `canSellAtPrice` verdict rather than duplicating it.

**M4 — Tax basis conversion uses `tax_rate` only, contradicting `R3-6`.**
`R3-6` (line `35`): panel/traffic-light and the net-basis math must use "the SAME tax resolution as the backend contract — `default_tax_configuration_id`, fallback `tax_rate` — **not `tax_rate`-only**." Wave 3's `netUnitPrice` (`611`) does `$rate = $context->taxRate ?? $subject->taxRate ?? '0.00'` — pure `tax_rate`, no `tax_configuration_id` resolution. The DTO carries `taxConfigurationId` (`402`) but nothing resolves it to a rate. Fix the provider/service to resolve the configured rate first.

**M5 — No handling or test for cost ≤ 0 (floor must be SKIPPED, not zeroed).**
`R3-7` (line `39`): "the min-margin contribution is SKIPPED (not zero) when cost ≤ 0"; `R3-6` (line `35`): no-cost case shows "—"/floor-disabled. Wave 3 floor math (`596–599`) has no `wacNet` null/≤0 guard — `bcmul(null, …)`/`bcmul('0', …)` yields an error or a bogus `0.000` floor that would pass everything. There is no backend test for the no-cost path (the only cost-less test is frontend, `891–894`). Add the guard and a Wave 3 test.

**M6 — Missing the merge-blocker permission-cache test.**
`R3-2` (line `20`) makes the "fails before `permission:cache-reset`, passes after" test a **merge blocker** (tenant-blind cache key). The plan lists cache-reset in deploy-owes (`1081`) but includes no such test in any wave. Add it as specified.

**M7 — Incomplete document-mode test coverage.**
Wave 4 tests only Advisory-warns (`695`) and Block-denies-without-permission (`711`). It does **not** test: (a) `WarnRequiresPermission` mode behavior at all (a distinct Rev 3 enum value, `R3-4` line `27`), nor (b) Block/Warn mode **passing** when the user holds `pricing.sell_below_minimum_margin` (the ALLOW path). `R3-2` explicitly calls for a DENY-path *and* the permission-holder path. Add both.

---

## MINOR

**m1 — Duplicated cap-cascade logic / unguarded array indexing.** The cascade lives in both `DiscountPolicySubject::effectiveMaxDiscountPercent()` (`408–414`) and `DiscountCapResolver` (Wave 3, `489`, `508–516`) — two sources of truth that can diverge. Also `categoryMaxDiscountPercents[0]` (`411`) assumes the array is non-null and ordered nearest-first; there is no test for child-cap-null/parent-cap-set (the Wave 2 test at `332–352` only sets the child cap). Consolidate and add the null-child case.

**m2 — `policyVersion` / policy-input hash promised but absent from the DTO.** Interfaces (`323`) and `R3-7` (line `41`) require a "stable policy input hash" (`policyVersion = short hash of policy inputs`), but the `DiscountPolicySubject` constructor (`388–406`) has only `policyAsOf`, no version/hash field.

**m3 — Provider `resolve()` singular is used but not in the interface.** Wave 2 test calls `$this->provider->resolve(...)` (`366`), but `DiscountPolicySubjectProviderInterface` declares only `resolveMany` (`321`). Declare `resolve()` or change the test to `resolveMany`.

**m4 — Misleading method name.** Wave 4 reuses `isPaymentDueDocumentRoute` (`786`) for discount-policy route gating — nothing to do with payment-due. Rename to reflect discount-policy gating.

**m5 — Endpoint money regex is not currency-scale-aware.** `effective_unit_price` uses `/^\d+(\.\d{1,3})?$/` (`633`), a hardcoded 3dp ceiling that accepts 3dp for EUR (2dp). Harmless at rest (`bcformatStrict` truncates) but inconsistent with the currency-driven precision contract; note it or resolve scale per `currency`.

**m6 — Branch base.** The worktree is 3 commits behind `dev` with a stale-branch warning; the plan (Wave 6, `1093`) pushes `feat/pricing-discount-panel` without a step to rebase/base on current local `dev` first (per memory's "worktree bases on LOCAL dev" and dev-sync discipline). Add an explicit base-on-dev step before Wave 1.

---

## What's correct (for the record)
Drops `margin_floor_buffer_percent` (`13`, `137`) and `pricing.override_discount_floor` (`13`, `172`); Advisory default (`15`, `697`); DTO-only Pricing with `rg` boundary check (`653`); cap cascade limited to product→category→company (`16`, `508–516`); `sale_price` pinned HT with TTC derived (`17`, `960–962`); percent validation `numeric|min:0|max:100|2dp` (`18`, `253`); bcmath/string money math with explicit currency (`19`, `596`); `CACHE_STORE=array` for transform (`444`); vitest-zombie cleanup (`994`); path-only tests, no full suite (`23`). These all track Rev 3.

**Bottom line:** resolve B1–B3 (correctness, cross-wave contract, permission catalog) and M1–M7 before dispatching Codex. The MINORs can be folded into the same revision pass.

---

## Reconciliation — 2026-07-08

The M1 implementation plan was updated before dispatch. BLOCKER and MAJOR findings are reconciled as follows:

- B1: Resolved. Wave 3 now requires a parity test against the existing `MarginService` and explicitly follows its markup-on-cost semantics rather than introducing a separate floor formula.
- B2: Resolved. Wave 2/Wave 3 now define `DiscountPolicyInterface::resolve()` and `resolveMany()`, plus line contexts and batch tests, before any document validator work consumes the batch contract.
- B3: Resolved. Wave 1 now includes both `RolesAndPermissionsSeeder` and the legacy `PermissionSeeder`, with tests that verify catalog registration and manager/admin grants for the existing floor permissions.
- M1: Partially rejected, with scope clarified. The governing task and Rev 3 section 3.2 require `country_pricing_regulations` and a Tunisia pharma seed row in Phase 1. The plan keeps schema/seed coverage but removes the regulatory rule engine/provider from Phase 1.
- M2: Rejected, with scope clarified. The governing task and Rev 3 section 3.1 require `companies.price_entry_mode` in Phase 1; the plan keeps it and confines behavior to display/entry preference.
- M3: Resolved. Wave 3 adds parity coverage to the existing minimum-margin verdict behavior, and Wave 4 consumes the same policy verdict fields instead of creating an independent permission decision.
- M4: Resolved. Wave 2/Wave 3 now resolve the product's configured tax rate through `default_tax_configuration_id` first, with `tax_rate` fallback.
- M5: Resolved. Wave 3 now includes a no-cost/zero-cost skip guard and backend test.
- M6: Resolved. Wave 1 now includes the permission cache reset regression test.
- M7: Resolved. Wave 4 now covers `WarnRequiresPermission`, Block deny/allow, and warning behavior with and without the existing floor permission.

MINOR findings were folded into the plan: cap cascade is centralized, `policyVersion` is present on the subject DTO, singular provider resolution is declared, route helper naming is corrected, endpoint precision is currency-aware, and the worktree base step is called out before implementation.
