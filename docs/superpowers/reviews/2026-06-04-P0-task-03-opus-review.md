# Opus Adversarial Review — Branch Tax-ID P0, Task 03

**Task:** Shared `CountryTaxNumberRules` + fiscal-parity test
**Commit reviewed:** `7bd9a9348` (`feat(branch-tax-id): shared CountryTaxNumberRules with fiscal-parity test`)
**Reviewer:** Opus (adversarial)
**Date:** 2026-06-05
**Scope:** Diff only — `app/Shared/Domain/Validation/CountryTaxNumberRules.php` (new) + `tests/Unit/Shared/CountryTaxNumberRulesTest.php` (new), checked against the P0 plan, the branch-tax-id design spec, and `apps/erp/CLAUDE.md`.

> Note: the supplied diff path `/tmp/branch-tax-id-task-03.diff` is outside the session's allowed working directory and could not be read. I reviewed the equivalent content via `git show 7bd9a9348`, which the plan/commit log confirms is Task 03. The two-file, +100-line shape matches the plan's Task 3 exactly.

---

## Verdict drivers

The implementation is small, strict-typed, TDD-first, and free of `app()`/`mixed`/`any`. Patterns are **byte-identical** to the live fiscal const, and — notably — the implementer made the *correct* judgment call to deviate from the plan's pseudocode. **However**, the normalization in `matches()` diverges from the fiscal normalizer in the *permissive* direction, and the parity test (the safety mechanism this whole high-risk feature leans on) does not cover normalization or the reverse key-set direction. That is the central finding.

---

## Findings

### MAJOR — `matches()` normalization is more permissive than the fiscal normalizer, and the parity test does not catch it
**File:** `apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php:39-46`

The shared normalizer uppercases and trims for **all** countries:
```php
$normalized = strtoupper(trim($value));
if ($countryCode === 'TN') {
    return str_replace('/', '', $normalized);
}
return $normalized;
```

The fiscal normalizer (the authority this class promises parity with) does **neither** uppercase **nor** trim — `FiscalPayloadConstraintValidator::normalizeTaxNumberForCountry()` (`:2317-2324`) only strips `/` for TN and returns the value unchanged otherwise, and `assertTaxNumberBaseline()` (`:2368-2374`) *rejects* untrimmed values rather than trimming them.

Consequences (entry validation accepts a value fiscal will later reject):
- `de123456789` (lowercase) → shared uppercases → `DE123456789` → matches DE pattern → **accepted at entry**. Fiscal does not uppercase → `/^DE[0-9]{9}$/D` fails on lowercase `de` → **rejected at signing**.
- `1234567am000` (lowercase TN matricule) → shared accepts; fiscal `[A-Z]{2}` rejects.
- `' 732829320 '` (surrounding whitespace) → shared trims → accepts; fiscal baseline rejects untrimmed outright.

Why this matters here specifically: the entry path stores the value **verbatim** (plan Task 7 `store()`: `'tax_id' => $validated['tax_id'] ?? null`), and the resolver (Task 9) feeds it unchanged into the **signed** SALE_RECEIPT / FacturX seller block. So a value that passes branch-entry validation but is non-canonical in case/whitespace can be persisted and then **break fiscal signing downstream** — the exact high-risk seam the spec calls out (seller `tax_number` lives in signed canonical bytes).

The class docstring and the review gate claim "TN normalization matches the fiscal normalizer." The TN `str_replace('/', '')` does match — good — but the blanket `strtoupper(trim())` does **not**, and `test_patterns_stay_in_parity_with_fiscal_validator` only compares `PATTERNS` arrays, never normalization. So the guard gives false confidence.

**Recommended edit (pick one):**
1. Mirror the fiscal normalizer exactly so `matches()` predicts fiscal's verdict — drop the general `strtoupper(trim())`, keep TN slash-strip only; or
2. Canonicalize-on-store (uppercase/trim the value before persisting in Task 5/7) so the validated form == the stored form == the signed form. Option 2 is the cleaner UX (accept lowercase, store canonical) but belongs to the entry/store tasks and must be tracked there.

Either way, the divergence should be made explicit and test-covered, not silent.

### MINOR — parity test is one-directional (fiscal ⊆ shared only)
**File:** `apps/api/tests/Unit/Shared/CountryTaxNumberRulesTest.php:276-289`

The test iterates the **fiscal** patterns and asserts each exists+matches in `PATTERNS`. It never asserts the reverse. A future dev adding a country to `CountryTaxNumberRules::PATTERNS` that fiscal does **not** have (e.g. `'BE' => …`) would pass this test, yet entry validation would then **reject** a Belgian tax number that fiscal (permissive — no BE pattern → returns `true`) would happily sign. That is precisely the "Phase 1 can never reject a value the device/fiscal path must author" guarantee from the class docblock, broken silently. Assert key-set equality (e.g. `assertSame` on sorted keys, or `assertEqualsCanonicalizing(array_keys($fiscal), array_keys(PATTERNS))`) to make parity bidirectional.

### NIT — docblock overclaims enforcement scope
**File:** `apps/api/app/Shared/Domain/Validation/CountryTaxNumberRules.php:7-12`

"This table must stay in parity … enforced by CountryTaxNumberRulesTest." Only the **patterns** are enforced; normalization and the reverse key direction are not. Tighten the wording or (better) add the coverage so the claim becomes true.

---

## Things checked and found correct (anti-confirmation pass)

- **Pattern byte-identity:** `FR/TN/SA/DE/IT` in `CountryTaxNumberRules::PATTERNS` are character-for-character equal to `FiscalPayloadConstraintValidator::TAX_NUMBER_PATTERNS:150-156`. Verified directly.
- **Correct deviation from plan pseudocode:** the plan (Task 3 Step 3) specified TN normalization `preg_replace('/[\s\/\-]/', '', …)` (strips whitespace **and dashes** too). The implementer instead used `str_replace('/', '')`, which **exactly** matches fiscal `normalizeTaxNumberForCountry()`. Following the plan literally would have *created* a dash/whitespace divergence from fiscal; the implementer chose fiscal parity over plan literalness. Correct call — commend.
- **TDD:** test file added in the same commit; four methods cover FR SIREN/SIRET + reject, TN compact/slash-form/single-letter-reject, unknown-country permissive, and reflection parity. Assertions trace correctly against the real regexes.
- **Reflection reads private const:** `getConstant('TAX_NUMBER_PATTERNS')` works despite the const being `private` — `ReflectionClass::getConstant` ignores visibility. Test guards with `assertIsArray` before the typed loop (PHPStan-safe on the `mixed` return).
- **Conventions:** `declare(strict_types=1)`, `final class`, no `app()`, no `mixed` in signatures, no TS/`any`, `preg_match(...) === 1` strict check. Backend-only → no i18n `t()` / Tailwind-token surface.
- **Fiscal inertness:** no fiscal payload, schema, or version touched; `FiscalPayloadConstraintValidator` is read-only via reflection. Phase 1 stays fiscally inert as the plan requires — no payload version bump.
- **Placement:** `app/Shared/Domain/Validation/` is appropriate for a cross-module canonical rule set consumed by the Company request layer.

---

## Conclusion

No blockers; the code is clean and the patterns are in genuine parity. But the normalization divergence is a real latent fiscal-signing risk in a feature whose whole premise is "never let entry accept a seller tax_number that the signed path will reject," and the parity test does not protect against either the normalization gap or a reverse-direction key drift. These should be addressed (or, for the store-side canonicalization option, explicitly tracked into Task 5/7) before the resolver starts feeding these values into signed bytes.

VERDICT: APPROVE-WITH-EDITS
