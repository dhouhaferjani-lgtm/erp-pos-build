# Opus Adversarial Review — Task 10: Receipt PDF resolves seller tax id per branch

**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` — Task 10
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (rev 2, §5 #1)
**Commit reviewed:** `197d0ecaa feat(branch-tax-id): receipt PDF resolves seller tax id per branch`
**Reviewer:** Opus (adversarial)
**Date:** 2026-06-05

---

## Scope of diff

- `apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php` (+49/−20)
- `apps/api/resources/views/pos/receipt.blade.php` (±4)
- `apps/api/tests/Feature/POS/ReceiptPdfBranchTaxIdTest.php` (new, +71)

---

## Verification performed

- Read the full implementation file, not just the diff.
- Confirmed `TaxIdentityResolver::resolve(Location $location)` has a **non-nullable** `Location` parameter (`TaxIdentityResolver.php:12`).
- Confirmed `$receipt->location` cannot be null: `location_id` is a non-nullable FK (`database/migrations/tenant/2026_01_08_190637_create_pos_receipts_table.php:29-31`, `->constrained('locations')` with no `->nullable()`), and the model declares `@property-read Location $location` (`Receipt.php:90`) — non-nullable. `location` is eager-loaded in `viewDataFor()` before `prepareData()` runs.
- Confirmed every factory referenced by the test exists (`TenantFactory`, `CompanyFactory`, `LocationFactory`, `TerminalFactory`, `ReceiptFactory`, `UserFactory`) and `App\Modules\Identity\Domain\User` is present.
- Cross-checked spec §5 #1: resolve from the receipt's `location_id` inside `ReceiptPdfService` and pass the resolved value into the view (NOT in the controller). Implementation matches.
- Cross-checked spec §7/D6: PDF print is a non-signed output; no fiscal payload schema/version change is required. None made. Correct.

> Note: `php artisan test` could not be executed in this review environment (sandbox denied test execution). Verification is therefore static; the commit was authored after the plan's green step, and the test logic + fixtures are sound on inspection.

---

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

1. **Test only asserts the branch-override direction, not the company-fallback direction**
   `tests/Feature/POS/ReceiptPdfBranchTaxIdTest.php:18-70`
   Spec §11 (Output-site tests): "each asserts the branch value when set **and the company value when null**." The single test (`test_receipt_uses_branch_tax_id_when_set`) only covers `location.tax_id = 'BRANCH-TAX'`. There is no test for a location with `tax_id = null` rendering the company's `tax_id` (the `$taxIdentity->taxId ?? $company->tax_id` fallback branch in `ReceiptPdfService.php:156` and the blade fallback). The fallback path is the most regression-prone half of the feature and is currently uncovered for this site.
   **Edit:** add `test_receipt_falls_back_to_company_tax_id_when_branch_null()` — location with null `tax_id`, assert the rendered HTML contains the company tax id.

2. **Plan deviation: null-guard dropped (safe, but undocumented)**
   `ReceiptPdfService.php:119,156`
   The plan (Task 10, Step 3) wrote a guarded call:
   `$taxIdentity = $receipt->location !== null ? $this->...->resolve($receipt->location) : null;` with `'sellerTaxId' => $taxIdentity?->taxId ?? $company->tax_id`.
   The implementation calls `resolve($receipt->location)` unconditionally and uses `$taxIdentity->taxId ?? $company->tax_id`. This is **acceptable and arguably cleaner** because `location_id` is a non-nullable FK and the relation is declared non-null, so PHPStan L8 accepts passing `$receipt->location` (typed `Location`) to a `Location` param, and there is no runtime null risk. No change required, but worth a one-line code comment noting the FK guarantees non-null, so a future nullable-location change is caught at review rather than as a TypeError.

### NIT

3. **Redundant blade fallback**
   `resources/views/pos/receipt.blade.php:328-329`
   `$sellerTaxId` is always present in the view-data array and already equals `$taxIdentity->taxId ?? $company->tax_id`, so the blade's additional `?? $company->tax_id` (in both the `@if(!empty(...))` and the echo) is dead defensive code. Harmless and mildly future-proofs against the view being rendered without the key; can stay or be simplified to `$sellerTaxId`.

---

## Convention checklist

- **TDD:** Test-first per plan; new test added with the implementation. ✓ (see MINOR #1 for the coverage gap)
- **No `app()` in production code:** Resolver is constructor-injected (`ReceiptPdfService.php:34`); the `app()`/`$this->app->make`/`instance()` usages are test-only. ✓
- **No `mixed`/`any` introduced:** `prepareData(): array<string,mixed>` is pre-existing; nothing new typed as `mixed`. ✓
- **i18n:** `{{ __('pos.tax_id') }}` is the existing key, unchanged; no new hardcoded user-facing string. ✓
- **Hardcoded Tailwind colors:** N/A (blade/PHP only, no `.tsx`). ✓
- **Fiscal payload schema/version drift:** None — the receipt PDF is the human-readable, non-signed output; the signed canonical SALE_RECEIPT bytes are device-authored (Phase 2) and untouched. ✓ (spec §7/D6)
- **Branch-vs-company fallback correctness:** `sellerTaxId = branch.tax_id ?? company.tax_id` at `ReceiptPdfService.php:156`; per-field fallback lives in the resolver (Task 9). Logically correct. ✓
- **Constructor injection / module boundaries:** `TaxIdentityResolver` imported from `Company\Application\Services`; DTO consumed via `Shared\Contracts` — allowed cross-module seam. ✓

---

## Verdict rationale

The implementation is correct, safe, and matches spec §5 #1 with no fiscal risk. The plan deviation (dropped null-guard) is justified by the non-nullable `location_id` FK. The only actionable edit is the missing company-fallback test that spec §11 explicitly requires for output-site coverage — a real but non-blocking gap.

VERDICT: APPROVE-WITH-EDITS
