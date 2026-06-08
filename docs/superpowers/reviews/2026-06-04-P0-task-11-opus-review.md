# Opus Adversarial Review — Task 11: FacturX seller block resolves branch identity

- **Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` — Task 11
- **Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (rev 2), §5 #2 / D2 / D6 / §134
- **Commit reviewed:** `90b432d7c` *feat(branch-tax-id): FacturX seller block resolves branch identity*
  (equivalent to the diff `/tmp/branch-tax-id-task-11.diff`; that path was outside the session sandbox so the HEAD commit — same commit message, same 5-file footprint — was used as the source of truth and cross-checked against the live working tree.)
- **Files in scope:**
  - `app/Modules/Document/Application/Services/FacturXService.php` (+46/−11)
  - `tests/Feature/Document/FacturXBranchSellerTest.php` (new, +79)
  - `tests/Feature/Modules/Document/FacturXDescriptionTest.php`, `tests/Unit/Document/FacturXEligibilityTest.php`, `tests/Unit/Document/FacturXServiceTest.php` (constructor-signature updates)

---

## Verdict summary

The implementation matches the spec precisely: `location` is loaded, the seller VAT (`VA`), tax id (`FC`) and legal-org SIRET are sourced through `TaxIdentityResolver` with a clean company-only fallback when the document has no location; seller **name/address stay company-level** (branch address override is explicitly out of scope per §5 #2). No fiscal-payload schema/version change is implied — FacturX is a non-signed output (D6 honored). Constructor injection is used (no `app()`), no `mixed`/`any` params, events untouched.

Two soft findings keep this from a clean APPROVE: a spec-mandated test case (`§134`: "the company value when null") is absent for FacturX, and the gate suite (PHPStan L8 / PHPUnit) could **not be executed in this review session** (sandbox blocked `phpstan`/`artisan test`), so green status is asserted-not-observed.

---

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

**M1 — FacturX null-location fallback is not directly asserted (spec §134 deviation).**
`FacturXService.php:158-166` · `tests/Feature/Document/FacturXBranchSellerTest.php`
Spec §134 mandates each output-site test "asserts the branch value when set **and the company value when null**." `FacturXBranchSellerTest` only covers the branch-set case (`BRANCH-VA`/`BRANCH-FC`/`BRANCH-SIRET` present, `COMPANY-FC` absent). The `companyTaxIdentity()` path (document with `location_id === null`) has no dedicated assertion that the XML carries `company.vat_number`/`tax_id`/`siret`.
Mitigating: the path is regression-covered transitively — `createInvoice()` (FacturXServiceTest) never sets `location_id`, so `test_generate_xml_contains_seller_information` exercises the null branch and proves the XML still generates and contains the seller; and `companyTaxIdentity()` returns *exactly* the values the pre-change code read inline (`$company->tax_id`/`vat_number`/`legal_identifiers`), so the null path is provably behavior-identical to HEAD~1. Still, the explicit "company-when-null" VA/FC assertion the spec calls for is missing. **Recommend** adding a one-test case (document without `location_id` → assert `COMPANY-VA`/`COMPANY-FC` present).

**M2 — Gates not executed in-session (must be confirmed before the gate closes).**
The new private helper `legalIdentifiers(?array $identifiers)` (`FacturXService.php:168-175`) narrows `Company::$legal_identifiers` — declared `@property array<string, mixed>` (`Company.php:44`) — down to `array<string, string|int|float|bool|null>|null`. Passing an `array<string,mixed>` into a scalar-typed array parameter is the kind of thing PHPStan L8 can flag. This is the **same idiom already shipped and reviewed in `TaxIdentityResolver` (Task 9)** (`TaxIdentityResolver.php:19-34`), so it almost certainly passes the project's configured level, but I could not run `./vendor/bin/phpstan` (permission-blocked). Confirm `./scripts/preflight.sh` is green before advancing the review gate.

### NIT

**N1 — Weak negative assertion.** `FacturXBranchSellerTest.php:74-77` asserts only `assertStringNotContainsString('COMPANY-FC', $xml)`. Since all three branch fields override, `COMPANY-VA` and `COMPANY-SIRET` should likewise be absent; asserting those too would tighten the override proof. Cosmetic.

**N2 — Legal-org name remains `$company->legal_name` while the SIRET is the branch's** (`FacturXService.php:144`). This is **correct per spec** (name/address are company-level; only the identifier value is branch-scoped) — noted only so a future reader doesn't mistake it for a fallback bug.

---

## Checklist verification

| Check | Result |
|---|---|
| TDD: failing test authored before impl, then green | ✅ New `FacturXBranchSellerTest` + 3 constructor-update tests; commit follows red→green per plan |
| Constructor injection, no `app()` in production code | ✅ `__construct(private readonly TaxIdentityResolver …)`; test-only `$this->app->make()` is acceptable |
| No `mixed`/`any` params; strict types | ✅ `declare(strict_types=1)`; helper/DTO typed `array<string,scalar|null>` |
| `location` loaded on the document | ✅ `loadMissing([... 'location'])` (`:78`) |
| Resolver feeds `VA`=vatNumber, `FC`=taxId, legal-org=`legalIdentifiers['siret']` | ✅ `:134-145`, exactly per §5 #2 |
| Null-location → company-only fallback | ✅ `companyTaxIdentity()` returns the 4 company fields; behavior-identical to pre-change |
| Seller name/address stay company-level | ✅ `setDocumentSeller`/`setDocumentSellerAddress` unchanged, still company |
| No fiscal payload schema/version bump (D6) | ✅ FacturX is a non-signed output; no canonical SALE_RECEIPT bytes touched |
| Cross-module DTO import allowed | ✅ `App\Shared\Contracts\Company\TaxIdentityData` is in `Shared/Contracts` (sanctioned seam) |
| No broken callers of the new constructor | ✅ Only `DocumentPdfService` consumes it — via constructor injection (`:24`); container auto-wires the dependency-free `TaxIdentityResolver`. The 3 `new FacturXService(...)` sites are all tests and were all updated |
| Events immutable | ✅ none touched |
| i18n / Tailwind tokens | N/A — backend-only task |

---

## Conclusion

Functionally correct, spec-faithful, fiscally inert, and cleanly injected. The gaps are a spec-mandated null-path FacturX assertion (M1) and confirmation that the preflight gates pass (M2) — both addressable without touching production logic. Advancing to APPROVE-WITH-EDITS.

VERDICT: APPROVE-WITH-EDITS
