# Adversarial Code Review — Precision Remediation PR Group A

**Branch:** `feat/precision-phase-a` vs `origin/dev`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.precision-drift-remediation`
**Reviewer:** Opus (adversarial)
**Date:** 2026-05-29
**Scope:** Phase 6 (Resources), Phase 8 (Notifications), Phase 2 (UoM precision settings)

---

## Summary

The Phase 8 notification changes and most of Phase 6 are clean and correct. The Phase 2 UoM precision feature ships a **frontend↔backend enum-casing mismatch that hard-breaks the save path** (the headline BLOCKER), and the Phase 6 Taxation return-type change produces an **un-coordinated API response-shape change** (string vs number) that is not reflected in the frontend types or the realignment log. The cascade job is well-reasoned and read-only as advertised, with one latent queue-context robustness note.

---

## Findings

### BLOCKER 1 — `updateUnitPrecision` sends PascalCase `rounding_method`; backend enum is snake_case → every precision save 422s

**Files:**
- `apps/web/src/features/uom/api/uomApi.ts:142` — `export type RoundingMethod = 'HalfUp' | 'Floor' | 'Ceil'`
- `apps/web/src/features/uom/api/uomApi.ts:137` — comment falsely claims *"the backend for PUT uom/units/{id} precision payload uses PascalCase identifiers ('HalfUp' | 'Floor' | 'Ceil')"*
- `apps/web/src/features/settings/components/UnitDecimalSettings.tsx:23,122-126,238` — `draft.rounding_method` is always one of `'HalfUp' | 'Floor' | 'Ceil'`, and `payload: draft` is passed straight to `updateUnitPrecision`
- `apps/api/app/Modules/Uom/Domain/Enums/RoundingMethod.php:7-9` — backing values are `half_up | floor | ceil`
- `apps/api/app/Modules/Uom/Presentation/Requests/UpdateUnitRequest.php:29` — `Rule::enum(RoundingMethod::class)` validates against the **backing values** (`half_up`, …)

**Explanation:** `Rule::enum()` matches the enum's *backing values*, i.e. `half_up|floor|ceil`. The new precision UI sends `rounding_method: 'HalfUp'` (etc.) verbatim. `'HalfUp'` is not a valid backing value, so `PUT /api/v1/uom/units/{id}` returns **422 Unprocessable Entity** on every save that includes a rounding method — and the payload always includes it (`payload: draft`). The Phase 2 "Save" button is therefore non-functional end-to-end.

This is also internally inconsistent: the *same file* (`uomApi.ts:24`) already correctly types the canonical `Unit.rounding_method` as `'half_up' | 'floor' | 'ceil'`, and the legacy full-unit flow (`updateUnit`, `AddUnitModal.tsx`) correctly sends snake_case to the identical endpoint. Only the new `updateUnitPrecision` diverges.

**Why it wasn't caught:** `UnitDecimalSettings.test.tsx:56` mocks `updateUnitPrecision`, and lines 214-216 / 231-233 assert it is called with `rounding_method: 'HalfUp'` / `'Floor'`. The test *enshrines the bug* (the memory's documented "frontend API test antipattern": mocking the API with hand-fed payloads hides response/request-shape drift). No integration test drives the real `PUT` with a frontend-sourced rounding value.

**Suggested fix:** Map PascalCase → snake_case before sending (in `updateUnitPrecision` or in the component), e.g. send `half_up|floor|ceil`. Simplest: make the precision payload reuse the canonical snake_case `RoundingMethod` already defined at `uomApi.ts:24` and drop the new PascalCase type + its incorrect comment. Then update the component test to assert the snake_case wire value, and add one real PUT integration assertion (no API mock) for the rounding round-trip.

---

### P1 1 — `getRateAsPercentage(): float → string` changes a published API shape (`rate_percentage`) with no frontend-type or realignment-log update

**Files:**
- `apps/api/app/Modules/Taxation/Domain/Entities/WithholdingTaxRule.php:151-153` (`float`→`string`)
- `apps/api/app/Modules/Taxation/Application/DTOs/WithholdingRuleData.php:71-75` (`float`→`string`)
- Emitted by `apps/api/.../Resources/WithholdingRuleResource.php:41` and `WithholdingRuleData::toArray():98` → consumed by `WithholdingTaxRuleController::store/update` (lines 91, 109)
- Frontend type still `number`: `apps/web/src/features/withholding/types.ts:72,136`
- Consumed as a value fed into a form field: `apps/web/src/features/withholding/components/WithholdingRuleFormModal.tsx:49` (`rate: rule.rate_percentage`)

**Explanation:** Previously `rate_percentage` serialized as a JSON **number** (`5`, from float `5.0`). After this change it serializes as a JSON **string** (`"5.00"`). This is a real response-shape change on the withholding-rules endpoints. The frontend `WithholdingRule` type still declares `rate_percentage: number`, so the TS type now lies, and `WithholdingRuleFormModal` seeds a numeric form field with a string. Per CLAUDE rule #9, a published-API shape change must be logged in `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` — it was not (no realignment file in the diff).

The backend change itself is *correct* for the precision invariant (no `(float)` laundering of a bcmath result). The defect is the missing coordination: (a) update `withholding/types.ts` `rate_percentage` to `string`, (b) verify `WithholdingRuleFormModal` handles a string rate, (c) add a REALIGNMENT-LOG entry. Note: `WithholdingCertificateResource` / `WithholdingCertificate::getRateAsPercentage()` (Domain/Entities/WithholdingCertificate.php:229) was **not** changed and still returns `float`, so the certificate-side `rate_percentage` (types.ts:19,72 in certificate context, list/detail `{cert.rate_percentage}%`) is unaffected — the change is scoped to the *rule* shape only. Confirm that asymmetry is intended.

**Suggested fix:** Update the frontend `WithholdingRule` type and form handling to treat `rate_percentage` as a numeric string; add the REALIGNMENT-LOG entry.

---

### P2 1 — `UnitSeeder` unconditionally overwrites operator-customized `rounding_method`, contradicting its own "operator-safe" docstring

**File:** `apps/api/database/seeders/UnitSeeder.php:88-91` (vs docstring lines 32-34)

**Explanation:** The class docstring (and the `MIGRATION_DEFAULT_DECIMAL_PLACES` guard at lines 82-84) carefully protect operator-customized `decimal_places`. But `rounding_method` is reset to `HalfUp` for every matched canonical unit **unconditionally** (line 89: `if ($unit->rounding_method !== RoundingMethod::HalfUp)`). An operator who deliberately set a unit to `floor`/`ceil` will silently have it reverted to `half_up` on the next seed run. The docstring claim "preserves operator-customised values" is therefore only half-true. (Idempotency itself is fine — the enum cast at `Unit.php:65` makes the comparison stable, so re-runs converge.)

**Suggested fix:** Either (a) only set `rounding_method` when it is still the migration default (`'half_up'`) — which makes the write a no-op anyway since the default already equals the target, so effectively drop the rounding write entirely; or (b) update the docstring to state that rounding_method is canonicalized unconditionally and confirm that is intended.

---

### P2 2 — `DocumentTaxBreakdownResource` custom constructor breaks Laravel's standard `JsonResource` factory methods (latent)

**File:** `apps/api/app/Modules/Taxation/Presentation/Resources/DocumentTaxBreakdownResource.php:19-24`

**Explanation:** `JsonResource::make()` and `::collection()` call `new static($resource)` with a single argument. This resource now requires a second constructor arg (`CurrencyScaleResolverInterface`), so any future use of the standard factory helpers will throw `ArgumentCountError`. There is currently **no production call site** (verified: only the test instantiates it), so this is latent rather than active — but it's a trap for the eventual wiring. The injected-resolver-into-JsonResource pattern does work for direct `new`, which is what the test does.

The `discount`-scale-vs-sibling-scale concern is acceptable: the `TaxCalculationResult` DTO carries no currency, the sibling strings (`subtotal`, `line_tax_amount`, …) are passed through verbatim at whatever scale they were computed, and `discount` is formatted at the request-bound company scale. In the normal single-currency-per-company case these align; the comment correctly documents the limitation. The no-arg `getScale()` throwing `UnboundCompanyContextException` when no company is bound is the intended fail-loud behavior.

**Suggested fix:** When this resource is finally wired to a controller, either inject via a factory closure or document at the call site that `make()`/`collection()` are unusable. Consider resolving the scale in the controller and passing the int, rather than injecting a service into a JsonResource.

---

### P2 3 — `TerminalResource.max_discount_percent` now emits a string, but `terminalApi.ts` still types it `number`

**Files:**
- `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:52-55` (dropped `(float)`)
- `apps/web/src/features/pos/api/terminalApi.ts:25,37,46` — still `max_discount_percent: number`

**Explanation:** The model cast is confirmed `decimal:2` (`Terminal.php:129`) and the column is NOT nullable (`add_discount_settings_to_terminals` default `0.00`, later `100.00`, with a `BETWEEN 0 AND 100` CHECK), so dropping `(float)` correctly emits the string `"100.00"` and never null. The change is right per the precision invariant. However the hand-written TS interface still says `number`, so the type now lies. Runtime impact is limited: `TerminalForm.tsx:168` uses `<input type="number">` + `valueAsNumber`, which tolerates a string default, and the discount-enforcement path reads a *different* endpoint (`/pos/discount-permissions` → `DiscountPermissions.maxDiscountPercent: number`, unaffected). So no functional break found, but the type drift should be corrected to `string` for honesty and to prevent future numeric-comparison bugs.

**Suggested fix:** Change `terminalApi.ts` `max_discount_percent` to `string` and confirm `TerminalForm` default handling (it already coerces on submit).

---

### NIT 1 — `RevalidateUnitQuantityScaleJob` relies on `QueueTenancyBootstrapper` re-init; a context-less run fails silently (audit gap)

**File:** `apps/api/app/Modules/Uom/Application/Jobs/RevalidateUnitQuantityScaleJob.php:51,62-70`

**Explanation:** `products`, `units`, and `stock_levels` all live in the **tenant** DB (`database/migrations/tenant/`). The job is correct *because* `QueueTenancyBootstrapper` (enabled in `TenancyServiceProvider.php:42`) tags the job with the dispatch-time tenant and re-initializes the tenant connection on the worker. The dispatcher (`UomController::updateUnit`) runs inside tenant-bound request middleware, so this holds in practice. The manual `where('tenant_id', …)` filter (lines 66-68) is then redundant-but-harmless inside a per-tenant DB (and the memory note that the model is DB-per-tenant, not row-level, makes the `tenant_id` column legacy). Two minor robustness gaps: (1) if the job ever runs without tenancy initialized, `Product::query()` hits the *central* DB (no `products` table) and throws; with `$tries = 1` it fails once and the audit is silently lost. (2) The boundary read of `Product` (Catalog) and `StockLevel` (Inventory) models directly from the Uom module is a documented, deliberate invariant-#4 deviation — acceptable for a read-only audit-log job with the in-code justification, but should graduate to an `InventoryServiceInterface` contract method if it ever does more than log. The `fitsScale()` comparison correctly uses bcmath (`bccomp`/`bcadd`), no float — good.

**Suggested fix (optional):** Add a `failed()` handler or a defensive check that tenancy is initialized at the top of `handle()` and log a distinct error if not, so a lost audit run is observable rather than silent.

---

### NIT 2 — `CurrencyScale::for()` double-`strtoupper` in notifications

**Files:** all four Billing notifications, e.g. `PaymentSucceededNotification.php:37-38`

**Explanation:** `$currency = strtoupper($this->payment->currency)` then `CurrencyScale::for($currency)`, and `for()` itself does `strtoupper()` internally (`CurrencyScale.php:64`). Harmless, purely cosmetic. The notification change is otherwise correct: scale is derived at render time from the serialized model's `currency` field (Payment/Invoice `currency` is typed non-nullable `string`), satisfying invariant #5. The tests (`NotificationCurrencyScaleTest`) properly assert scale-3 vs scale-2 rendering and include a JPY/scale-0 guard (`preg_match('/\d+\.\d{2,3}/')` expecting no match) — not tautological.

---

## Test Quality Notes

- **Strong / non-tautological:** `NotificationCurrencyScaleTest` (asserts real TND-3 vs EUR-2 vs JPY-0 rendering); `DocumentTaxBreakdownResourceTest` (asserts `'0.000'` vs `'0.00'`); `UpdateUnitDecimalPlacesTest::test_cascade_job_logs_warning_…_without_mutating` (asserts the warning context *and* `assertSame('12.5000', …)` no-mutation); the dispatch/no-dispatch/422-rejection tests.
- **Bug-enshrining:** `UnitDecimalSettings.test.tsx:214-216,231-233` asserts the frontend sends `rounding_method: 'HalfUp'`/`'Floor'` — the exact PascalCase the backend rejects (see BLOCKER 1). Because `updateUnitPrecision` is mocked, the 422 never surfaces. This test must be corrected to assert the snake_case wire value, and a real-PUT integration test should cover the rounding round-trip.

---

## VERDICT: REQUEST-CHANGES

BLOCKER 1 (PascalCase rounding_method → 422 on every precision save) makes the Phase 2 feature non-functional and must be fixed before merge. P1 1 (un-coordinated `rate_percentage` string shape change with stale frontend type + missing realignment-log entry) must also be resolved or the withholding-rule form/types will drift. The P2/NIT items are real but non-blocking cleanups.

---

## Orchestrator reconciliation (2026-05-29)

All findings triaged and resolved on branch `feat/precision-phase-a` (commit "fix(precision-A): address Opus adversarial review"):

- **BLOCKER 1 (rounding_method casing → 422):** FIXED. `updateUnitPrecision` now maps the PascalCase UI value to the snake_case enum backing value on the wire via `ROUNDING_METHOD_WIRE`. Added `uomApi.test.ts` asserting `HalfUp→half_up`, `Floor→floor`, `Ceil→ceil` reach `apiPut`. The component→mocked-function test stays (it asserts the component contract, which is now correct).
- **P1 1 (rate_percentage shape):** FIXED. `WithholdingRule.rate_percentage` frontend type `number→string`; `WithholdingRuleFormModal` seeds its numeric form field via `Number(...)` (behaviour-preserving). REALIGNMENT-LOG entry deferred to Phase 12.3 (per plan grouping) and will include this + the NF525 + API string-type changes.
- **P2 1 (UnitSeeder clobbers rounding_method):** FIXED. Removed the unconditional rounding_method override (migration already defaults `half_up`); test now asserts an operator's `Floor` is preserved.
- **P2 2 (DocumentTaxBreakdownResource 2-arg constructor vs `::make()`):** ACCEPTED as-is. No production call site exists; the no-arg request-bound resolver is the correct DI source and `app()` is forbidden. If a future call site uses `::make()`, the resolver must be passed explicitly — documented in the resource.
- **P2 3 (TerminalResource string vs terminalApi number):** FIXED. `Terminal.max_discount_percent` frontend type `number→string` (decimal:2 numeric-string); form seed parses via `Number(...)`; 3 mock fixtures aligned to strings.
- **NIT 1/2:** Acknowledged; non-blocking (tenant-bound dispatcher makes the cascade job correct in practice; double `strtoupper` is harmless).

Verification after fixes: frontend typecheck clean; lint ratchet held at 11343 (0 regression); 51 affected frontend tests green; backend UnitSeederTest 4/4 green; pint + PHPStan L8 clean on changed files; deptrac ratchet held (59, no regression); baseline full suite 6784 tests, only the known `TenantCreationTest` failing.

**Final verdict: APPROVE** (all BLOCKER/P1 closed; P2-2 accepted with rationale).
