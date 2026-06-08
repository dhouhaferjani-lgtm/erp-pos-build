# Branch Tax-ID P0 — Adversarial Post-Implementation Review
Date: 2026-06-05
Reviewer: Codex (adversarial post-implementation)
Diff base: d35da55bd48f459f1191ffa7b0caaaf25a449f54
Branch: feat/branch-tax-id-spec

## Executive Summary
Verdict: NEEDS-REWORK
Findings: BLOCKER: 0 | MAJOR: 3 | MINOR: 0 | NIT: 0

Fiscal path looks structurally safe: I found no changed golden fixture paths in the feature diff, no fiscal payload key-set/schema-version/event-version change, SALE_RECEIPT and ACCOUNT_PAYMENT source `seller.tax_number` from branch first, and ACCOUNT_CHARGE remains untouched. The rework is in server/web completeness: validation is not per-field as specified, the settings UI cannot capture the `legal_identifiers.siret` value FacturX needs, and the UI cannot clear branch overrides back to the required `null = inherit company` state.

## Findings

### [MAJOR] Per-field tax identity validation is incomplete
**File:** /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php:49
**Finding:** Entry validation only applies fiscal-aligned country rules to `tax_id`; `vat_number` and `legal_identifiers['siret']` are accepted as shape-only fields. The same gap exists in `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/apps/api/app/Modules/Company/Presentation/Requests/UpdateLocationRequest.php:50`.
**Evidence:**
```php
'tax_id' => [
    'nullable',
    'string',
    'max:50',
    function (string $_attribute, string|int|float|bool|array|null $value, Closure $fail): void {
        $country = strtoupper((string) ($this->input('address_country') ?? ''));
        if (is_string($value) && $value !== '' && $country !== '' && ! CountryTaxNumberRules::matches($country, $value)) {
            $fail('The branch tax ID format is invalid for '.$country.'.');
        }
    },
],
'vat_number' => ['nullable', 'string', 'max:50'],
'legal_identifiers' => ['nullable', 'array'],
```
**Risk:** The API can persist malformed branch VAT numbers or malformed `siret` legal identifiers. FacturX then emits these values into seller `VA` and legal-organisation fields, so P0 can produce invalid e-invoice metadata even though the spec explicitly required per-field validation.
**Fix:** Add nested validation for `legal_identifiers.siret` using the fiscal-aligned country rule where applicable, add VAT-specific validation for `vat_number`, and mirror both checks in create and update tests.

### [MAJOR] Location settings UI cannot capture branch legal identifiers
**File:** /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/apps/web/src/features/settings/LocationsPage.tsx:31
**Finding:** The settings form only models and submits `taxId` and `vatNumber`; it never captures `legalIdentifiers` / `legal_identifiers.siret`, even though FacturX reads the resolved `siret` key for seller legal organisation.
**Evidence:**
```ts
interface LocationFormData {
  name: string
  type: LocationType
  code: string
  phone: string
  email: string
  addressStreet: string
  addressCity: string
  addressPostalCode: string
  addressCountry: string
  taxId: string
  vatNumber: string
  posEnabled: boolean
}
```
FacturX consumes the missing field at `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/apps/api/app/Modules/Document/Application/Services/FacturXService.php:142`:
```php
$siret = $identity->legalIdentifiers['siret'] ?? null;
if (is_string($siret)) {
    $builder->setDocumentSellerLegalOrganisation($siret, '0002', $company->legal_name ?? $company->name);
}
```
**Risk:** A user can enter a branch `tax_id` and `vat_number` from the UI, but cannot enter the branch `siret` key that FacturX uses. The resulting FacturX seller block can mix branch `FC`/`VA` with the company legal organisation identifier via resolver fallback, violating the full FacturX parity goal.
**Fix:** Add a UI field for the relevant legal identifier key, at minimum `legalIdentifiers.siret` for FR, submit it through `CreateLocationInput`/`UpdateLocationInput`, and test that the settings form can persist it.

### [MAJOR] Edit form cannot clear overrides back to company inheritance
**File:** /Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/apps/web/src/features/settings/LocationsPage.tsx:180
**Finding:** On edit, empty `taxId` and `vatNumber` values are omitted from the update payload instead of being sent as `null`, so an existing branch override cannot be cleared from the UI.
**Evidence:**
```ts
if (formData.addressCountry) updateData.addressCountry = formData.addressCountry
if (formData.taxId) updateData.taxId = formData.taxId
if (formData.vatNumber) updateData.vatNumber = formData.vatNumber
```
The API mapper also types update fields as string-only at `/Users/houssamr/Projects/syneriva/apps/erp.branch-tax-id/apps/web/src/features/location/api.ts:51`:
```ts
export interface UpdateLocationInput {
  name?: string
  type?: LocationType
  code?: string
  phone?: string
  email?: string
  addressStreet?: string
  addressCity?: string
  addressPostalCode?: string
  addressCountry?: string
  taxId?: string
  vatNumber?: string
  legalIdentifiers?: Record<string, unknown>
```
**Risk:** `null = inherit company` is a core data contract, and backend update supports nullable fields, but the primary UI cannot return a branch to inherited company tax identity. Users are stuck with stale branch overrides unless they use the API directly.
**Fix:** Allow nullable update inputs, and on edit send `taxId: null`, `vatNumber: null`, and `legalIdentifiers: null` or targeted cleared keys when the user empties those fields.

## Plan Completeness Checklist
- Task 1 [DONE]: Migration adds nullable `tax_id`, `vat_number`, and `legal_identifiers` to `locations` with no backfill.
- Task 2 [DONE]: `Location` fillable, cast, and docblock include the three tax identity fields.
- Task 3 [DONE]: `CountryTaxNumberRules` exists and the parity test compares both fiscal pattern key sets and pattern values.
- Task 4 [DONE]: Country tax identity config and typed reader exist with FR/TN/MA required and default not-required.
- Task 5 [PARTIAL]: `CreateLocationRequest` validates and conditionally requires `tax_id`, but does not validate `vat_number` or `legal_identifiers.siret`.
- Task 6 [PARTIAL]: `UpdateLocationRequest` validates `tax_id`, including existing location country lookup, but does not validate `vat_number` or `legal_identifiers.siret`.
- Task 7 [DONE]: `LocationController::store()` persists the new fields.
- Task 8 [DONE]: `LocationResource` exposes the new fields.
- Task 9 [DONE]: `TaxIdentityResolver` and DTO implement per-field fallback, key-merge with location override, country fallback, and soft-deleted company fallback.
- Task 10 [DONE]: Receipt PDF resolves seller tax id from receipt location and falls back to company.
- Task 11 [DONE]: FacturX loads document location and uses resolved VA/FC/legal-org values, but UI cannot supply branch legal-org per Finding 2.
- Task 12 [DONE]: NF525 company header now sources SIRET from company `legal_identifiers['siret']` or `tax_id` and composes address parts.
- Task 13 [DONE]: Web types/API/transform include tax identity fields and mapping.
- Task 14 [PARTIAL]: Location form captures `taxId` and `vatNumber`, but cannot capture `legalIdentifiers.siret` and cannot clear overrides back to inherit.
- Task 15 [DONE]: `TerminalResource` includes tax fields and inspected terminal endpoints load `location`.
- Task 16 [DONE]: Device `Terminal.location` type carries tax fields and persistence test covers rehydrate.
- Task 17 [DONE]: Device seller sourcing prefers branch tax id for SALE_RECEIPT and ACCOUNT_PAYMENT; ACCOUNT_CHARGE is untouched.
- Task 18 [PARTIAL]: Source-path tests exist for POS seller sourcing and PHP fiscal validator acceptance; fixture parity script was run but UNVERIFIED because Vitest hit sandbox EPERM writing `apps/pos/node_modules/.vite-temp` in the branch worktree.

## Conclusion
NEEDS-REWORK. Fiscal safety is acceptable for the intended value-source-only cutover: no fixture/schema/version churn was found, and the two live fiscal seller paths use branch-over-company fallback. The branch tax identity feature is not complete enough to merge because the API does not enforce per-field validation, the UI cannot set the legal identifier needed by FacturX, and the UI cannot clear overrides to restore company fallback.
