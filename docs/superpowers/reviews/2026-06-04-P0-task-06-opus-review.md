# Opus Adversarial Review — Task 06: `UpdateLocationRequest` tax-field validation

**Branch:** `feat/branch-tax-id-spec`
**Commit reviewed:** `ebdefbe05` (`feat(branch-tax-id): UpdateLocationRequest tax-field validation`)
**Diff:** `/tmp/branch-tax-id-task-06.diff` (= `git show ebdefbe05`, 2 files / +132)
**Reviewer:** Opus (adversarial)
**Date:** 2026-06-05
**Verdict:** APPROVE-WITH-EDITS

---

## Scope of diff

- `apps/api/app/Modules/Company/Presentation/Requests/UpdateLocationRequest.php` — adds `tax_id` (with a per-country format closure), `vat_number`, `legal_identifiers` rules.
- `apps/api/tests/Feature/Location/UpdateLocationTaxValidationTest.php` — new, 2 tests.

Task 06 deliverable per plan §Task 6: add the same 3 fields + format closure as Create, **without** the conditional-required `withValidator()` (explicitly deferred). Confirm symmetry with create; clearing to null allowed.

## What is correct

- **Symmetry with `CreateLocationRequest` is exact** — the `tax_id` closure is byte-identical to the approved Task 05 version; `vat_number`/`legal_identifiers` mirror create with `sometimes` prepended. Update correctly omits the conditional-required gate (plan-sanctioned).
- **Clearing-to-null works.** `nullable` short-circuits the closure via Laravel's `isNotNullIfMarkedAsNullable()` (verified in `Validator.php:884`), so `PATCH {tax_id: null}` skips the format check → 200. The `test_accepts_clearing_tax_id_to_inherit` test is valid.
- **Format logic is sound.** `CountryTaxNumberRules::matches('FR','73282932000074')` → true (14-digit SIRET), `matches('FR','BAD')` → false. The closure guards with `is_string($value)` before calling the `string`-typed `matches()`, so an array `tax_id` can't fault it.
- **Uses the shared fiscal-aligned rules** (`App\Shared\Domain\Validation\CountryTaxNumberRules`), not the divergent Partner `TaxIdValidationService` — per spec §6.
- **Test harness is correct** — uses the project's `assertApiValidationErrors()` (reads `error.errors`), *not* the stock `assertJsonValidationErrors('tax_id')` the plan snippet suggested (which would silently pass on the wrong envelope). This is an improvement over the plan.
- **No `app()` in production code** (controller injects `companyContext`; request uses none). `app(PermissionRegistrar::class)` appears only in test `setUp()` — the established seeding pattern, acceptable.
- `declare(strict_types=1)`, `array<string,mixed>` rules docblock, no `mixed`/`any` in the closure, no frontend / no Tailwind / no fiscal-payload bytes touched in this diff.

---

## Findings

### MAJOR-1 — Non-string `tax_id` throws a `TypeError` → HTTP 500 instead of 422
`UpdateLocationRequest.php:42` (and inherited identically in `CreateLocationRequest.php:42`)

```php
function (string $_attribute, string|array|null $value, Closure $fail): void {
```

The plan specified `mixed $value`; the implementation narrowed it to `string|array|null` to honor the no-`mixed` rule. But Laravel passes the **raw** input to closure rules and does **not** bail a closure when an earlier rule (`string`) fails:

- `Validator::hasNotFailedPreviousRuleIfPresenceRule()` (`Validator.php:902`) only bails for `Unique`/`Exists`. No `bail` rule is present, so every rule runs.
- `ClosureValidationRule::passes()` (`ClosureValidationRule.php:62`) invokes `$this->callback->__invoke($attribute, $value, …)` with `$value` **as-is**.

So a client sending `{"tax_id": 12345}` (a JSON *number*, not a string) — or `true` — reaches the closure with an `int`/`bool`, which violates the `string|array|null` parameter type → uncaught `TypeError` → **500**, when the intended response is a clean **422** (the `string` rule's error). Confirmed end-to-end against the vendored Laravel source.

This is latent in the already-approved Create request too (symmetry was the goal), so it is not a Task-06 regression — but it is a real robustness/typing bug and this diff reproduces it. **Recommend fixing in both requests:** drop the native type hint on `$value` (keep `@param mixed`) or wrap the format check in an invokable Rule whose `validate()` accepts `mixed`. The existing `is_string($value)` guard already handles the logic; only the *signature* faults.

### MAJOR-2 — Format check is bypassable on update by omitting `address_country`; a malformed `tax_id` then persists into a fiscally-signed field
`UpdateLocationRequest.php:43-44`

```php
$country = strtoupper((string) ($this->input('address_country') ?? ''));
if (is_string($value) && $value !== '' && $country !== '' && ! CountryTaxNumberRules::matches($country, $value)) {
```

The closure only validates when `address_country` is **in the request payload**. On update the country lives on the existing row, so `PATCH {tax_id: "BAD"}` (no `address_country`) sets `$country === ''` → format check skipped → `update()` mass-assigns `$validated` (`LocationController.php:152`) → `tax_id = "BAD"` is **persisted on an FR shop**. The test name (`…_when_country_is_submitted`) is honest that only the submitted-country path is covered.

Per the project's own risk register, the seller `tax_number` is carried into the **signed canonical `SALE_RECEIPT` seller block** (`location.tax_id ?? company.tax_id`). Letting a malformed value through entry validation pushes the failure to fiscal-authoring time (Phase 2 device / `FiscalPayloadConstraintValidator`) where it is far costlier. The plan §Task 6 note defers *conditional-required* re-enforcement, but it does **not** address this *format*-check hole.

**Recommend:** when `address_country` is absent from the payload, fall back to the persisted row's country (e.g. resolve `$this->route('location')` / the bound `Location` and read its `address_country`) before deciding to skip. This preserves the symmetry-of-intent the review gate asks for and closes the fiscal gap. At minimum, track it explicitly as a known limitation rather than leaving it implicit in a test name. (Acknowledged as a conscious deferral in the plan; flagged here for the fiscal-risk it carries.)

### MINOR-1 — Thin update test coverage
`UpdateLocationTaxValidationTest.php`

Two tests only: reject-malformed (country submitted) and clear-to-null. Missing:
- **accepts a valid SIRET on update** (positive path; create has its equivalent),
- a test pinning the MAJOR-2 country-omitted behavior (would become a regression guard once fixed).

Adequate for the literal plan ask, but light for a fiscally-sensitive field.

### NIT-1 — Hardcoded English validation message
`UpdateLocationRequest.php:45` — `'The branch tax ID format is invalid for '.$country.'.'` is not translated. CLAUDE.md rule 11 (`t()` keys) governs **frontend** text; backend `FormRequest` messages here (and in `CreateLocationRequest::messages()`) are conventionally plain English. Consistent with the codebase; noted only for completeness.

### NIT-2 — TDD red-step not verifiable from history
Test and implementation landed in the same commit (`ebdefbe05`), so the required failing-first step can't be confirmed from git. Consistent with every prior task on this branch; process note only.

---

## Decision

Task 06 meets its stated scope: fields added, format closure present, symmetric with Create, clearing-to-null works, shared fiscal-aligned rules used, correct test harness. Neither MAJOR breaks the task's narrow deliverable — MAJOR-1 is an inherited latent 500 on malformed non-string input, MAJOR-2 is a plan-acknowledged deferral with real fiscal exposure. Both warrant edits (ideally a small cross-cutting follow-up touching Create + Update together) before Phase 2 fiscal wiring, but do not require reworking this task.

VERDICT: APPROVE-WITH-EDITS
