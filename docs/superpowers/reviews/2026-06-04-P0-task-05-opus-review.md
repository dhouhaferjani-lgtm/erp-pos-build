# Opus Adversarial Review — Branch Tax-ID P0, Task 05

**Task:** `CreateLocationRequest` — add tax fields, format validation, conditional-required
**Diff:** `/tmp/branch-tax-id-task-05.diff`
**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` (Task 5, lines 488–616)
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (rev 2, §6 / D4)
**Reviewed:** 2026-06-05
**Commit under review:** `7dadf61bf feat(branch-tax-id): CreateLocationRequest tax-field validation + conditional-required`

---

## Scope of diff

Two files:
1. `app/Modules/Company/Presentation/Requests/CreateLocationRequest.php` — adds `tax_id` (with a closure format rule), `vat_number`, `legal_identifiers` to `rules()`; adds a `withValidator()` that hard-requires `tax_id` for a sellable `Shop` in a `branch_tax_id_required` country.
2. `tests/Feature/Location/CreateLocationTaxValidationTest.php` — new, 4 tests.

---

## Verification performed (claims checked against real code)

- **Route plumbing is real.** `/api/v1/locations` POST is registered in `app/Modules/Inventory/Presentation/routes.php:35` → `Company\…\LocationController::store` behind `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim,'module:Inventory']` + `can:inventory.adjust`. The new test's `setUp()` is **byte-for-byte equivalent** to the canonical `tests/Feature/Location/LocationTest.php::setUp()` (tenant + FR company + admin role + `UserCompanyMembership` + `actingAs(...,'sanctum')` + `X-Company-Id` header). `LocationTest` already exercises `postJson('/api/v1/locations') → assertCreated()` with this exact setup, so auth/permission/module-gating/tenant-claim all resolve. The new test will run against a live route, not a mock. ✔ (CLAUDE.md testing rule satisfied — `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`, no mocked HTTP.)
- **Validation envelope.** Test uses the project `AssertsApiValidation::assertApiValidationErrors()` helper (`tests/Traits/AssertsApiValidation.php`), which reads the wrapped `error.errors` shape — *correcting* the plan's draft test that used the stock `assertJsonValidationErrors('tax_id')` (which would silently pass on the wrong envelope). This is a real improvement over the plan. ✔
- **`CountryTaxNumberRules::matches()`** confirmed present (`app/Shared/Domain/Validation/CountryTaxNumberRules.php`); FR pattern `^([0-9]{9}|[0-9]{14})$` → `'123'` rejected, `'73282932000074'` accepted. ✔
- **`CountryTaxIdentityConfig::isBranchTaxIdRequired('FR')`** → true (config `tax_identity.php` FR structural+required). ✔
- **`(string) $this->input(...)`** cast pattern is established across 8+ FormRequests (Coupon, Accounting, POS, Inventory) → PHPStan-L8 clean under project config. ✔
- **Each test path traced:**
  - `test_rejects_malformed_fr_tax_id` (shop/FR/`123`): closure fires `$fail` → 422 `tax_id`. ✔
  - `test_accepts_valid_fr_siret` (shop/FR/valid SIRET): passes validation; `store()` returns 201. Note `store()` does **not** persist `tax_id` yet (Task 7) — test only asserts `assertCreated()`, correctly scoped to validation. ✔
  - `test_requires_tax_id_for_sellable_shop_in_fr` (shop/FR/no tax): `withValidator` adds `tax_id` error → 422. ✔
  - `test_does_not_require_tax_id_for_warehouse` (warehouse/FR): `withValidator` early-returns (type≠Shop), closure skipped (null) → 201. ✔

---

## Convention compliance

| Rule | Status |
|---|---|
| TDD (test-first, real models) | ✔ test file added, 4 cases |
| No `app()` helper | ✔ `withValidator` uses `new CountryTaxIdentityConfig` (not `app()`); explicitly sanctioned by the plan's review gate for a stateless config reader in a FormRequest |
| No `mixed` | ✔ closure types value as `string|array|null` (improves on the plan's `mixed`); the only `mixed` is the pre-existing `@return array<string,mixed>` on `rules()` (standard) |
| Enums, no magic strings | ✔ uses `LocationType::Shop->value`, not literal `'shop'` (improves on plan) |
| i18n (frontend only) | N/A — backend |
| Hardcoded Tailwind | N/A — backend |
| Fiscal payload schema/version drift | ✔ none — format check delegates to the shared `CountryTaxNumberRules` (parity-tested in Task 3); no canonical payload or version touched |
| Branch-vs-company fallback | ✔ for this layer — required-check uses the submitted `address_country` only, matching the plan's documented decision |

---

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

**M1 — Per-field format validation is `tax_id`-only; spec §6 asks for three fields.**
`CreateLocationRequest.php:49-50` — `vat_number` is `['nullable','string','max:50']` and `legal_identifiers` is `['nullable','array']`, with **no** format validation. Spec §6 (line 97) and D4 explicitly say: *"Validate per field — `tax_id`, `vat_number`, and `legal_identifiers['siret']` have different formats."*
This is consistent with the **Task 5 plan** (which only specifies a format rule for `tax_id`) and is also constrained by reality: the shared source-of-truth table `CountryTaxNumberRules::PATTERNS` holds exactly **one** pattern per country (the SIREN/SIRET tax-number form). It has no VAT pattern (`FR40303265045` would fail the SIRET regex) and no per-key SIRET pattern. So full per-field validation is not implementable with the current shared table. **Not a defect in this task** — it is a plan-sanctioned deferral — but the spec→plan gap should be tracked explicitly (either extend the shared table with VAT/SIRET sub-patterns in a follow-up, or amend the spec to scope validation to `tax_id`).

**M2 — Required-check can be bypassed by omitting `address_country` on a shop in a requiring-country company.**
`CreateLocationRequest.php:58-62` — `withValidator` resolves country **only** from the submitted `address_country` and early-returns when it is empty. Spec line 66 models jurisdiction as `location.address_country ?? company.country_code`. A sellable `Shop` POSTed with no `address_country` into an FR company therefore escapes the hard-required `tax_id` gate. The plan documents this deliberately (*"if absent we do NOT hard-require, since country is unknown"*) because the company isn't readily injectable into a FormRequest without `app()`. Real but plan-accepted; worth a follow-up note so the frontend always sends `address_country` for shops (and/or the controller re-checks against the resolved company country).

### NIT

**N1 — New error strings are inline rather than in `messages()`.**
`CreateLocationRequest.php:45,67-68` — `'The branch tax ID format is invalid for …'` / `'A branch tax ID is required for a sellable shop in …'` are inline in the closure/`withValidator`. The class keeps a `messages()` map for other keys. Inline `$fail()`/`errors()->add()` messages are idiomatic Laravel for closure/after rules, and backend messages in this codebase are plain English strings (rule #11 is frontend-only), so this is acceptable — noted only for stylistic consistency.

**N2 — No negative tests for `vat_number`/`legal_identifiers` rules.** The two new trivial rules are untested. Low value; mentioned for completeness.

---

## Notes (out of scope for Task 05, do not block)

- `CountryTaxNumberRules::normalize()` TN branch strips only `/` (not dashes/whitespace) and does not uppercase, diverging from the plan's Task 3 normalization wording. That belongs to **Task 03** (already committed/reviewed) and is not touched by this diff.
- `store()` whitelist persistence of the new fields is **Task 07**; correctly absent here.

---

## Conclusion

The diff implements Task 5 faithfully and correctly, with three concrete improvements over the plan (correct validation-assertion helper, `LocationType::Shop->value` over a magic string, `string|array|null` over `mixed`). TDD is followed against real models/routes; no `app()`, no `mixed`/`any`, no fiscal drift, no fallback bug at this layer. The two MINOR items (per-field validation coverage, country-fallback at create) are genuine spec→plan gaps but were **explicitly deferred by the approved plan**, so they are follow-ups rather than edits required of this task. Recommend logging M1/M2 as tracked follow-ups before Phase 1 closes.

VERDICT: APPROVE
