# api.taxation Cluster — Claude Review

Cluster: `api.taxation`
Cluster aggregate status (inventory): `fixed`
Verdict: **CONDITIONAL APPROVE** (pending non-TanStack closure plan scanner fix for 4 needs_recheck callsites)

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Withholding certificate + sales-withholding tracking + tax-configuration reorder. Pre-sweep, withholding flows and `TaxConfigurationController::reorder` referenced shared tax tables without consistent tenant scope.

Inventory callsite total: **13 callsites — 9 fixed, 4 needs_recheck** (api.taxation.007/.008/.009/.010 — all `TaxConfigurationController` `tax_configurations` references).

## Implementation summary

Top fix commit: `e7a2f543` (all 13 callsites in one batch).

Top files: `CreateWithholdingCertificateRequest`, `RecordSalesWithholdingRequest`, `CalculateWithholdingRequest`, `TaxConfigurationController`, `SalesWithholdingTrackingController`.

The 4 `needs_recheck` rows reference `tax_configurations` — a country-scoped global reference table with NO tenant_id/company_id columns. The code IS country-scoped (`TaxConfigurationController.php:30, 60, 95, 99, 123, 152, 167, 176-177, 183` uses `where('country_code', $company->country_code)`). The scanner re-emits them only because `tax_configurations` is listed in `PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES`. Resolved by closure plan Task 3 Step 1.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Taxation/TaxationTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-04-api-taxation-cluster-codex-round2-review.md`
- `2026-05-04-api-taxation-cluster-opus-review.md`
- `2026-05-04-api-taxation-cluster-opus-round2-review.md`
- `2026-05-04-scanner-tax-configurations-false-positive.md` (audit anchor)

## Gates evaluated

1. **Withholding flow** (certificate create / record sales / calculate) carries tenant + company predicates.
2. **Tax configuration country coherence**: per the 2026-05-04 audit, country-scoping is the correct architectural choice — there is no tenant-scoped data inside the table; cross-tenant exfiltration is structurally impossible.
3. **Cross-country business validation** (e.g., FR company assigning TN tax config) is explicitly OUT of scope for the tenant-isolation sweep (master plan + 2026-05-04 audit line 23).

## Open work (handled by closure plan)

| Row | File:line | Code state | Plan task |
|---|---|---|---|
| api.taxation.007 | TaxConfigurationController.php:176 | Country-scoped (verified) | Task 3 Step 1: scanner fix |
| api.taxation.008 | TaxConfigurationController.php:60 | Country-scoped (verified) | Task 3 Step 1: scanner fix |
| api.taxation.009 | TaxConfigurationController.php:123 | Country-scoped (verified) | Task 3 Step 1: scanner fix |
| api.taxation.010 | TaxConfigurationController.php:152 | Country-scoped (verified) | Task 3 Step 1: scanner fix |

## Disposition

CONDITIONAL APPROVE. Upgrades to APPROVE once the closure plan executes Task 3 Step 1 (`tax_configurations` removed from guarded list + `tax_rates` verified).

## Cross-references

- Audit: `docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md`
- Closure plan: Task 3 Step 1
- Cluster-level test: `apps/api/tests/Feature/Taxation/TaxationTenantIsolationTest.php`
