# Opus Adversarial Review — Task 12 (NF525 `buildCompanyHeader` null-SIRET fix)

**Commit:** `1bd02f95a` — `fix(branch-tax-id): NF525 company header emits real SIRET/address (null-SIRET bug)`
**Scope reviewed:** `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` (`buildCompanyHeader`), `apps/api/tests/Feature/POS/Nf525CompanyHeaderSiretTest.php`
**Checked against:** plan §Task 12, spec §5 #3 / §6 / §7, `apps/erp/CLAUDE.md`.

## Summary

The change replaces the dead `readNullableString($company, 'siret'|'address')` magic-attribute reads (which always returned `null`) with real sourcing: `siret` = `legal_identifiers['siret'] ?? tax_id`, `address` composed from the company address parts. This matches the spec's company-level mandate (§5 #3: "NF525 JET … stay company-level here; … Not a single-location resolve") and the plan's Task 12 implementation byte-for-byte. The now-dead `readNullableString` helper is fully removed with **no remaining callers** (verified by grep across `apps/api`).

## Verification performed

- **Spec parity:** §5 #3 + §3-plan confirm NF525 is company-level (no `location_id` to resolve) and the fix sources from `Company`. ✅ Implementation is company-scoped, does **not** route through `TaxIdentityResolver`. Correct — a per-location resolve here would be wrong.
- **No fiscal payload drift:** The NF525 JET `<Societe>` header is not part of the signed canonical `SALE_RECEIPT` bytes; no schema/version bump. ✅ Consistent with spec §7 and the plan's "fiscally inert" Phase-1 claim.
- **Offset-on-null safety:** `companies.legal_identifiers` is `jsonb ... ->default('{}')` (migration `2025_11_30_104000_create_companies_table.php:40`) and cast `'array'`, so it is never `null` at runtime; `['siret'] ?? $company->tax_id` is isset-safe and emits no "undefined array key" warning. ✅
- **Type safety:** `is_string($siret) ? $siret : null` guards a non-string `siret` JSON value; `$address !== '' ? $address : null` collapses an all-empty address to `null`. ✅ No `mixed` hints, no `app()`, constructor-injection rules untouched (pure private method).
- **Dead-code removal:** `readNullableString` removed; grep shows zero references anywhere. ✅ PHPStan dead-method risk eliminated.
- **Regression scan:** Other references to the DTO/method — `Nf525ExportServiceWithStubProviderTest.php` constructs its own `Nf525CompanyHeaderData(siret: null, address: null)` via a **stub provider** and never calls `buildCompanyHeader`, so it is unaffected. `OwnerReportingTest.php` references are unrelated. No test asserted the old null-SIRET behavior. ✅
- **Conventions:** No frontend/i18n/Tailwind surface in this task. Backend strict-types preserved. ✅

> Note: I could not execute `php artisan test` (command approval not granted in this session). The test is a deterministic reflection-invoked unit assertion over a pure method with factory-seeded data; logic review shows it will pass. The plan-gate's "no NF525 regression" claim is supported by the stub-provider-isolation finding above, but a live `tests/Feature/POS` run is still recommended before merge.

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

1. **Untested fallback branch — `Nf525DataProvider.php:526`.** The commit title advertises "SIRET from legal_identifiers **or** tax_id", but `Nf525CompanyHeaderSiretTest` only exercises the `legal_identifiers['siret']` path. The `?? $company->tax_id` fallback (the case the null-SIRET bug actually stranded for companies that only have `tax_id`), the `is_string()` guard (non-string JSON value → `null`), and the empty-address → `null` branch are all uncovered. Add a second case: a company with `legal_identifiers => []` (or no `siret` key) and `tax_id => '73282932000074'`, asserting `$data->siret === '73282932000074'`; and optionally one with no address parts asserting `$data->address === null`. Cheap, and it locks the advertised behavior.

### NIT

2. **Address composition format — `Nf525DataProvider.php:527-531`.** Omits `address_street_2` and `address_state`, and joins with single spaces (no comma): `"10 Rue de la Paix 75002 Paris"`. This matches the plan exactly and is acceptable for the JET `<Societe>` block, but if NF525 v2.1 expects a more structured establishment address, revisit. Cosmetic only.

3. **Defensive consistency with the FacturX sibling — `Nf525DataProvider.php:526`.** Task 11's `FacturXService` guards with `$company->legal_identifiers ?? []`; here direct offset access is correct only because of the DB `default('{}')`. A `($company->legal_identifiers ?? [])['siret']` form would be defensively consistent across the two sites and survive a future column-default change. Optional.

## Verdict rationale

Implementation is correct, spec-aligned, company-scoped, fiscally inert, dead-code-clean, and free of `app()`/`mixed`/i18n/Tailwind concerns. The single substantive gap is test coverage of the very fallback the commit advertises (Finding 1), which is a recommended non-blocking edit rather than a defect in the shipped code path.

VERDICT: APPROVE-WITH-EDITS
