# Opus adversarial cluster review — api.taxation

Review date: 2026-05-04
Branch tip reviewed: e7a2f543 (fix); 501c4b37 (submit-state)
Reviewer: opus
Owner: codex
Verdict: BLOCK
Commit reviewed: e7a2f543

## Verdict

BLOCK

## Summary

The 13 inventoried callsites are correctly fixed at the tier they target, the
13/14 of 15 regression tests honestly pin those fixes (one test pin is dishonest),
and gate deltas match the kickoff-brief-authorized scanner-blind pattern. However,
walking every route-mapped method in the Taxation module surfaces multiple
sibling-controller methods on the SAME controller (`WithholdingCertificateController`,
`SalesWithholdingTrackingController`) that carry the IDENTICAL exploitable
vulnerability the inventory only partially closes — including a fiscal-chain
integrity gap where any tenant-A admin can issue / void / submit-to-TEJ ANY
foreign-tenant withholding certificate by UUID. Per the BLOCK threshold the
kickoff brief explicitly enumerates ("a sibling-controller method with same
vulnerability still unscoped"), this cluster cannot flip to fixed without
round-2 remediation matching the api.loyalty round-2/3 precedent.

## Findings

### Finding 1 — CRITICAL — `WithholdingCertificateController::issue / void / submitTEJ` are fiscal-chain-cross-tenant exploitable

Location:
- `apps/api/app/Modules/Taxation/Presentation/Controllers/WithholdingCertificateController.php:141-219`
  (routes: `POST /api/v1/withholding/certificates/{id}/issue`,
  `/{id}/void`, `/{id}/submit-tej`)
- Service: `apps/api/app/Modules/Taxation/Application/Services/WithholdingCertificateService.php:187-302`
- Repo: `apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentWithholdingCertificateRepository.php:16-20`

Issue: All three controller actions delegate to
`$this->certificateService->issue($id, $userId)` /
`->void($id, $reason, $userId)` / `->submitToTEJ($id, ...)`. Each service
method begins with `$certificate = $this->certificateRepository->findById($certificateId)`
which resolves to `WithholdingCertificate::with([...])->find($id)` — NO
`tenant_id` predicate, NO `company_id` predicate. A tenant-A admin
authenticated to companyA, knowing companyB's certificate UUID, can:
1. ISSUE a foreign tenant's draft certificate, baking tenant-B's hash chain
   sequence into the row (via `$this->certificateRepository->getLastInChain($certificate->company_id, ...)` on line 201 — note this re-reads using the *foreign* company_id stored on the row, so the chain hash references the *foreign* tenant's chain even though the action originated from tenant-A).
2. VOID a foreign tenant's certificate, breaking compliance audit trail.
3. SUBMIT a foreign tenant's certificate to TEJ, fabricating a tax-authority
   submission against the foreign tenant's name.

Withholding certificates carry `tenant_id` AND `company_id` columns
(`2026_01_08_172147_create_withholding_certificates_table.php:16-17`), so this
is plain unscoped lookup, not a structural-protection ambiguity.

Severity: CRITICAL. This is fiscal-chain integrity territory. The two-tier
hash chain (per AutoERP CLAUDE.md, NF525-equivalent for Tunisia/France) is
broken at the controller-tier read.

Fix: Repository `findById($id)` must accept tenant/company context, OR the
controller must pre-load the certificate via a tenant-scoped chain before
calling the service. Pattern matches api.loyalty round-2 enroll:
```php
$company = $this->companyContext->requireCompany();
$certificate = WithholdingCertificate::where('tenant_id', $company->tenant_id)
    ->where('company_id', $company->id)
    ->findOrFail($id);
$this->certificateService->issue($certificate->id, $userId);
```

### Finding 2 — CRITICAL — `WithholdingCertificateController::show / downloadPDF / downloadTEJXML / destroy` leak / mutate foreign-tenant certificate data

Location: `apps/api/app/Modules/Taxation/Presentation/Controllers/WithholdingCertificateController.php`:
- `show($id)` lines 78-96
- `downloadPDF($id)` lines 224-233
- `downloadTEJXML($id)` lines 238-254
- `destroy($id)` lines 295-311

All four call into `$this->certificateRepository->findById($id)` or
`$this->certificateService->findById($id)` (which itself calls the repo's
unscoped `findById`). `destroy` calls `$this->certificateRepository->delete($id)`
which internally does `WithholdingCertificate::findOrFail($id)` (line 151 of
the repo). All unscoped.

Direct exploitation:
- `show`: tenant-A admin can read any tenant-B certificate with full
  `partner` / `document` / `rule` / `issuer` eager-loads — leaks customer
  identity, transaction amounts, hash, hash chain sequence.
- `downloadPDF` / `downloadTEJXML`: tenant-A admin can download tenant-B's
  fiscal PDF/TEJ submission XML, including all transactional and partner data.
- `destroy`: tenant-A admin can hard-delete tenant-B's draft withholding
  certificates.

Severity: CRITICAL. Same fiscal-data territory as Finding 1.

Fix: Same scoping pattern. The `findByCompany($companyId, ...)` repo method
already exists (line 23) and is correctly scoped — extend to a
`findByCompanyAndId($companyId, $id)` or scope at the controller tier.

### Finding 3 — IMPORTANT — `SalesWithholdingTrackingController::show / markCertificateReceived` violate the cluster invariant the same controller now codifies

Location: `apps/api/app/Modules/Taxation/Presentation/Controllers/SalesWithholdingTrackingController.php`:
- `show($id)` lines 104-120
- `markCertificateReceived($id, ...)` lines 127-155

Issue: Both methods use the load-then-403 anti-pattern that this cluster's
api.taxation.011 fix EXPLICITLY codified as forbidden. The api.taxation.011
fix-commit message reads: "every read whose anchor came from a route param
MUST carry tenant_id + company_id predicates so a foreign document 404s at
the read tier rather than leaking its existence (and content via eager
loads) before a post-load check." Yet sibling methods in the SAME controller
do exactly that — `$this->service->findById($id)` (which calls the unscoped
repo `findById`), then `if ($tracking->companyId !== $this->companyContext->getCompanyId()) abort(403, ...)`.

`sales_withholding_tracking` carries `tenant_id` AND `company_id`
(`2026_01_09_111456_create_sales_withholding_tracking_table.php`), so this is
the same pattern as the inventoried `recordWithholding` fix — it just wasn't
inventoried.

Exploit difference vs Finding 2: this is a SOFT leak — the 403 returns no
body data, but the existence of the foreign tracking row is still leaked
(403 vs 404 distinguishes "exists but forbidden" from "doesn't exist"), and
the eager-load includes `document` / `customer` / `payment` so the loaded
domain model carries cross-tenant data into memory before the check.

Severity: IMPORTANT (not CRITICAL: payload is not returned). Still violates
the cluster invariant.

Fix: Mirror the api.taxation.011 fix:
```php
$company = $this->companyContext->requireCompany();
$tracking = $this->service->findByIdScoped($id, $company->tenant_id, $company->id);
abort_if($tracking === null, 404);
```
Or scope at the repo tier and pass tenant/company through the service.

### Finding 4 — IMPORTANT — `WithholdingTaxRuleController::show / update / deactivate / destroy` are unscoped

Location: `apps/api/app/Modules/Taxation/Presentation/Controllers/WithholdingTaxRuleController.php` lines 60-133, delegating to `EloquentWithholdingTaxRuleRepository::findById / findOrFail` (lines 18, 89, 100, 108).

Schema: `withholding_tax_rules` has `country_code` + nullable `company_id`
(`2026_01_08_172123_create_withholding_tax_rules_table.php`). Pure-global
rules (`company_id IS NULL`) are intended cross-tenant shareable. But
COMPANY-SPECIFIC rules (`company_id IS NOT NULL`) carry tenant-private rate
configurations. The four controller methods make NO distinction — a
tenant-A admin can read/update/deactivate/delete a tenant-B company-specific
withholding rule by UUID.

Severity: IMPORTANT — exploitable cross-tenant mutation of fiscal rate
configuration; could be used to defraud tenant-B's withholding calculations.
Less severe than Findings 1-2 because (a) the repo pattern conflates global
vs company-specific so most call paths land on global rows, and (b) write
side-effects are less audit-chain-binding than certificate issuance.

Fix: Either (a) repo `findById` must accept `?string $companyId` and scope
when non-null, OR (b) controller must short-circuit company-specific writes
through `$this->companyContext->requireCompanyId()`.

### Finding 5 — NICE-TO-HAVE — `StampDutyRuleController::show / update / destroy` are unscoped

Location: `apps/api/app/Modules/Taxation/Presentation/Controllers/StampDutyRuleController.php` lines 49, 82, 101.

Schema: `stamp_duty_rules` is country-scoped global reference (FK to
`countries.code`, NO `tenant_id`, NO `company_id` —
`2025_12_30_101000_create_stamp_duty_rules_table.php`). Same shape as
`tax_configurations`.

Issue: Symmetry with the inventoried api.taxation.008/009/010 fix. Those
applied `country_code` scoping to TaxConfiguration sister methods. Stamp
duty has the same shape and should mirror — show/update/destroy should
scope by `company->country_code`. Without it, a French tenant admin can
update a Tunisian stamp-duty rate, producing cross-country fiscal
mis-configuration.

Severity: NICE-TO-HAVE because (a) shape is identical to the global-reference
pattern the kickoff brief authorized as `structurally_protected_by_country_scoped_reference`, but (b) the inventoried sibling chose to apply country_code
scoping anyway (api.taxation.008-010), so consistency demands the same here.
Defer to a follow-up cluster (`api.taxation.stampduty`) acceptable.

### Finding 6 — IMPORTANT — Test pin for api.taxation.013 is not honest

Location: `apps/api/tests/Feature/Taxation/TaxationTenantIsolationTest.php:441-455`
(`test_create_withholding_certificate_rejects_cross_tenant_partner_at_service_tier`).

Issue: Test-pin honesty check (executed): I reverted the production diff for
all 7 fix-touched files and re-ran the suite. 14 of 15 tests failed with
diagnostics targeting the right fix surfaces; ONE test passed pre-fix:
`test_create_withholding_certificate_rejects_cross_tenant_partner_at_service_tier`.
Trace: with both validator-tier (api.taxation.001) AND controller-tier
(api.taxation.013) reverted, the request still 422s — but for an UNRELATED
reason (the certificate service's downstream business validation, e.g. no
matching withholding rule for a foreign-tenant partner). The test only
asserts `assertStatus(422)` with no body-pin, so it cannot distinguish
"422 because validator scoped partner_id" from "422 because service threw
DomainException for unrelated reason."

Severity: IMPORTANT. The fix at api.taxation.013 is real (controller-tier
defense-in-depth), but the test does not honestly pin it. Compounding: this
is the ONLY service-tier defense-in-depth pin in the cluster — without an
honest test, regressions to that line silently survive CI.

Fix: Add a body-pin (e.g. `assertJsonPath('error.errors.partner_id.0', '...')`
or `$cross->assertJsonValidationErrors(['partner_id'])`), AND/OR wrap with a
structural-SQL-log assertion that captures the controller-tier
`partners`-with-tenant_id-and-company_id-predicates query. Note:
`test_create_certificate_validator_query_includes_tenant_and_company_predicates`
(line 461) already pins the validator-tier scoping by SQL — adding a
second SQL-log pin for the controller-tier read makes the defense-in-depth
honest.

## Test honesty assessment

A.1 (every test exercises the actual route → controller path): VERIFIED.
Each test uses `actingAsForTenant` which authenticates via Sanctum with
`X-Company-Id` header and posts/patches/deletes against the real Laravel
HTTP kernel. No service-direct calls.

A.2 (same-tenant 200 controls): VERIFIED for TaxConfiguration tests
(`test_show_tax_configuration_rejects_cross_country_id` line 362-364
includes a same-FR control that asserts 200). FormRequest tests do NOT
include same-tenant 200 controls — they only assert 422 cross. This is
acceptable for validator-tier pins because a 200 on same-tenant would
require seeding a valid withholding-rule row matched by transaction_type;
the validator-tier pin is honest if the SQL-log query carries the right
predicates (see A.4).

A.3 (real two-tenant fixtures with permissions correctly registered):
VERIFIED. Lines 139-158 register all six permissions
(`taxation.view`, `.manage`, `withholding.view`, `.manage`,
`tax-config.view`, `.manage`) on BOTH tenants' permission scopes via
`Permission::findOrCreate` — necessary because RolesAndPermissionsSeeder
doesn't include them. Routes that use `can:invoices.view` /
`can:invoices.update` (sales-withholding routes line 67, 71, 76 in
routes.php) — the canonical seeder DOES include `invoices.view` and
`invoices.update` per the loyalty review precedent. UserA.assignRole('admin')
on line 168 grants those.

A.4 (test-pin honesty check, REQUIRED): EXECUTED. See Finding 6 — 1 of 15
tests passed pre-fix, indicating dishonest pin for api.taxation.013. The
remaining 14 fail with surface-targeted diagnostics:
- 6 FormRequest tests (api.taxation.001-006): 422→200 or 422 on validator
  field error — pin the validator scoping.
- 4 TaxConfig tests (api.taxation.007-010): 422/404 → 422/200/204 — pin the
  country-scoping.
- 2 per-tenant findOrFail tests (api.taxation.011-012): 404→403 (the pre-fix
  load-then-403 path), 422→200 (preview validator) — pin the controller fix.
- 2 SQL-log tests: assertions fail with raw pre-fix SQL — pin both the
  validator AND tax-config-controller-tier scoping at the SQL level.

The 14 failures all target exactly the fix surfaces. After restore, 15/15 pass.

A.5 (service-tier defense-in-depth documentation): VERIFIED in test
docblocks (lines 429-431, 444-446) — but not honestly pinned, see Finding 6.

## Hostile grep results

Run from `apps/api/`:

```
$ grep -rnE "->where\(['\"](id|partner_id|document_id|payment_id|certificate_id|rule_id|period_id)['\"]" app/Modules/Taxation/ --include='*.php'
app/Modules/Taxation/Infrastructure/Repositories/EloquentSalesWithholdingTrackingRepository.php:30:            ->where('document_id', $documentId)
app/Modules/Taxation/Infrastructure/Repositories/EloquentWithholdingCertificateRepository.php:42:            $query->where('partner_id', $filters['partner_id']);
app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php:184:                ->where('id', $item['id'])
```
Classification:
- Repo line 30 (`document_id`): list query inside `findByDocument`, returns
  `first()`. Anchor is the document_id which is a route-param leaf, not the
  identity column. Cross-tenant scoping must come from upstream; repo treats
  document_id as already-validated. Acceptable in current cluster scope but
  flagged by Finding 2-style audit if reachable from controller without
  upstream tenant scope. NOT in this cluster.
- Repo line 42 (`partner_id`): filter inside `findByCompany`. Outer query
  IS scoped by `company_id`. Safe.
- TaxConfigurationController line 184: `where('country_code', ...)->where('id', ...)` chain inside reorder fix — country-scoped. Safe (api.taxation.007 fix).

```
$ grep -rnE "exists:(partners|documents|payments|tax_configurations|withholding_certificates|withholding_tax_rules|stamp_duty_rules|vat_periods)" app/Modules/Taxation/ --include='*.php'
(no matches)

$ grep -rn "exists:" app/Modules/Taxation/ --include='*.php' | grep -v "/tests/"
app/Modules/Taxation/Presentation/Controllers/StampDutyRuleController.php:62:'country_code' => ['required', 'string', 'size:2', 'exists:countries,code'],
```
Classification: `countries` is a global reference. Allowed.

```
$ grep -rnE "::find(OrFail)?\(" app/Modules/Taxation/ --include='*.php' | grep -v "/tests/"
app/Modules/Taxation/Infrastructure/Repositories/EloquentWithholdingCertificateRepository.php:134:        $certificate = WithholdingCertificate::findOrFail($id);
app/Modules/Taxation/Infrastructure/Repositories/EloquentWithholdingCertificateRepository.php:151:        $certificate = WithholdingCertificate::findOrFail($id);
app/Modules/Taxation/Infrastructure/Repositories/EloquentWithholdingTaxRuleRepository.php:18:        return WithholdingTaxRule::find($id);
app/Modules/Taxation/Infrastructure/Repositories/EloquentWithholdingTaxRuleRepository.php:89:        $rule = WithholdingTaxRule::findOrFail($id);
app/Modules/Taxation/Infrastructure/Repositories/EloquentWithholdingTaxRuleRepository.php:100:       $rule = WithholdingTaxRule::findOrFail($id);
app/Modules/Taxation/Infrastructure/Repositories/EloquentWithholdingTaxRuleRepository.php:108:       $rule = WithholdingTaxRule::findOrFail($id);
app/Modules/Taxation/Infrastructure/Repositories/EloquentVatPeriodRepository.php:17:        return VatPeriod::find($id);
app/Modules/Taxation/Infrastructure/Repositories/EloquentVatPeriodRepository.php:35:        $period = VatPeriod::findOrFail($id);
app/Modules/Taxation/Infrastructure/Repositories/EloquentVatPeriodRepository.php:46:        $period = VatPeriod::findOrFail($id);
app/Modules/Taxation/Presentation/Controllers/StampDutyRuleController.php:51:        $rule = StampDutyRule::findOrFail($id);
app/Modules/Taxation/Presentation/Controllers/StampDutyRuleController.php:84:        $rule = StampDutyRule::findOrFail($id);
app/Modules/Taxation/Presentation/Controllers/StampDutyRuleController.php:103:       $rule = StampDutyRule::findOrFail($id);
```
Classification:
- WithholdingCertificateRepository:134, :151 — UNSCOPED (Finding 2).
- WithholdingTaxRuleRepository:18, :89, :100, :108 — UNSCOPED (Finding 4).
- VatPeriodRepository:17, :35, :46 — These are repo-level. ALL controller call
  paths into `VatPeriodController` go through `VatPeriod::query()->forCompany($company->id)->findOrFail($id)` (verified at controller lines 54, 94, 119, 140; VatReportController lines 55, 126, 150). The unscoped repo methods are not reached by HTTP. NICE-TO-HAVE: scope the repo as defense-in-depth. NOT a current security gap.
- StampDutyRuleController:51, :84, :103 — UNSCOPED (Finding 5,
  global-reference table).

```
$ grep -rnE '\$request->(input|header|query)\(.{0,30}(company_id|tenant_id|X-Company-Id)' app/Modules/Taxation/ --include='*.php' | grep -v "/tests/"
(no matches)

$ grep -rn "app(" app/Modules/Taxation/Presentation/ | grep -v "/tests/"
(no matches)

$ grep -rn "phpstan-ignore" app/Modules/Taxation/ | grep -v "/tests/"
(no matches)
```

Service-locator usage: clean. PHPStan suppressions: clean. Request-injected
tenant/company id: clean.

## Architecture gate drops

Pre-cluster baseline (commit `adfd587c`):
- Gate A: 69
- Gate B: 77

Post-fix (commit `e7a2f543`):
- Gate A: 63 (delta -6)
- Gate B: 74 (delta -3)

Expected drops:
- Gate A: 6 inline `exists:` rules across 3 FormRequests = -6.
  TaxConfigurationController::reorder validator (api.taxation.007) is
  invisible to Gate A because `tax_configurations` is NOT in Gate A's
  GUARDED_TABLES (per kickoff brief). MATCH: -6.
- Gate B: Of 7 controller-tier findOrFail fixes:
  - api.taxation.011 (`Document::where('tenant_id')->...->findOrFail`) — Gate B's
    `chainIsScoped` (`tests/Architecture/TenantScopedFindCallsTest.php` →
    `app/Application/Sweep/Visitors/FindCallVisitor.php:264`) recognizes
    `where('tenant_id')` predicates, so this DROPS (contradicts the kickoff
    brief's claim that it's invisible — verified by reading the visitor source).
  - api.taxation.012, .013 (`Partner::where('tenant_id')->...->findOrFail`):
    same reasoning, DROPS.
  - api.taxation.008, .009, .010 (`TaxConfiguration::where('country_code')->findOrFail`):
    INVISIBLE. `chainIsScoped` only recognizes `tenant_id` / `company_id`
    where-clauses, not `country_code`. So these stay in Gate B's count even
    though they're correctly fixed (structurally_protected_by_country_scoped_reference).
  - Net expected drop: -3. MATCH: -3.

Both deltas match expectation. No suspicious counts.

## Sibling-controller / cross-cluster survey

Walked every route in `apps/api/app/Modules/Taxation/routes.php`. Per-route classification:

`taxation/configurations`:
- `index` (line 19): no anchor; lists by `country_code` filter — country-scoped at controller. (e)→(d) by query filter.
- `documentTypes` (line 20): no anchor; static-list response.
- `reorder` (line 21): inline validator — FIXED (api.taxation.007).
- `show` (line 22): findOrFail — FIXED (api.taxation.008).
- `store` (line 23): no anchor (creates new row).
- `update` (line 24): findOrFail — FIXED (api.taxation.009).
- `destroy` (line 25): findOrFail — FIXED (api.taxation.010).

`taxation/stamp-duties` (StampDutyRuleController):
- `index`: list with country/active/type filters — country-anchored (a).
- `show / update / destroy`: unscoped findOrFail — Finding 5 (NICE-TO-HAVE).
- `store`: no anchor.

`withholding/preview` (WithholdingPreviewController):
- `preview` POST (line 38): partner_id route param → FIXED (api.taxation.012).

`withholding/rules` (WithholdingTaxRuleController):
- `index`: country/company filters — partial scoping (a/c).
- `show / update / deactivate / destroy`: unscoped repo lookups — Finding 4 (IMPORTANT).
- `store`: no anchor.

`withholding/certificates` (WithholdingCertificateController):
- `index`: company-scoped filter via `findByCompany` — (c).
- `export-tej-batch`: company-scoped filter via `findByCompany` — (c).
- `show`: unscoped — Finding 2 (CRITICAL).
- `store`: FIXED (api.taxation.013) at controller; validator at .001/.002/.003.
- `issue / void / submit-tej`: unscoped service-tier — Finding 1 (CRITICAL).
- `download-pdf / download-tej-xml`: unscoped — Finding 2 (CRITICAL).
- `destroy`: unscoped repo — Finding 2 (CRITICAL).

`sales-withholding` (SalesWithholdingTrackingController):
- `index`: company-scoped filter — (c).
- `show`: load-then-403 anti-pattern — Finding 3 (IMPORTANT).
- `markCertificateReceived`: load-then-403 anti-pattern — Finding 3 (IMPORTANT).

`/documents/{documentId}/record-withholding`:
- `recordWithholding`: FIXED (api.taxation.011).

`vat/periods` (VatPeriodController):
- All five methods (`index`, `generate`, `show`, `close`, `reopen`, `file`):
  use `VatPeriod::query()->forCompany($company->id)->findOrFail($id)` chain
  with `forCompany` recognized by Gate B as a SCOPE_METHOD. (a)+(d). Clean.

`vat/reports` (VatReportController):
- `summary`: companyContext-anchored (c).
- `periodSummary / exportFormats / export`: forCompany-scoped findOrFail (a)+(d). Clean.

NICE-TO-HAVE outside the security surface:
- `EloquentVatPeriodRepository::find / findOrFail` (lines 17, 35, 46) is
  unscoped at the repo tier but unreachable via HTTP because every
  controller path scopes upstream via `forCompany`. Defense-in-depth
  improvement, not a security gap.

## What looks good

- The 13 inventoried callsites are correctly fixed with the right pattern
  per resource: `ScopedExists::tenantAndCompany` for tenant_and_company
  resources, `country_code` scoping for the global-reference
  `tax_configurations` (matching the kickoff brief's
  `structurally_protected_by_country_scoped_reference` annotation).
- Constructor injection is honest in all three FormRequests — the
  `CompanyContext` is added as `private readonly` with `parent::__construct()`
  delegation. No `app()` helper, no service-locator drift, no PHPStan
  suppression, no @phpstan-ignore lines.
- The two structural-SQL-log tests (`test_create_certificate_validator_query_includes_tenant_and_company_predicates`,
  `test_show_tax_configuration_query_includes_country_predicate`) are
  bar-raising. Both target the fix-anchor SQL directly and survive
  rephrasing of the fix (e.g. switching to a different scope method would
  still satisfy them).
- The api.taxation.011 fix pre-emptively removed a redundant 403 post-load
  check, which is the right cleanup — it makes the cluster invariant
  enforceable by the read tier alone.
- POS surface diff `dev..HEAD` empty as required.
- `php artisan sweep:inventory:verify-history` clean (916 events, 268
  callsites, 0 problems).

## Verification commands

- Test suite (`vendor/bin/phpunit tests/Feature/Taxation/TaxationTenantIsolationTest.php`):
  PASS, 15 tests / 30 assertions OK.
- Test-pin honesty check (revert all 7 production diff files):
  14 / 15 fail with surface-targeted diagnostics; 1 dishonestly passes
  pre-fix (Finding 6).
- PHPStan (`./vendor/bin/phpstan analyse app/Modules/Taxation tests/Feature/Taxation/TaxationTenantIsolationTest.php`):
  [OK] No errors (102 files analysed).
- Pint (`./vendor/bin/pint --test app/Modules/Taxation tests/Feature/Taxation/TaxationTenantIsolationTest.php`):
  pass.
- verify-history (`php artisan sweep:inventory:verify-history`):
  916 events / 268 callsites / 0 problems.
- POS surface diff (`git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`):
  empty.
- Cross-cluster regression (`vendor/bin/phpunit tests/Feature/Taxation tests/Feature/Pricing tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php`):
  287 tests / 967 assertions, OK (with 47 PHPUnit deprecations, 24 skipped).
- Architecture gate deltas (Gate A 69→63, Gate B 77→74): MATCH expected
  cluster-fix surface modulo the kickoff-brief-authorized scanner-blind
  patterns.

## Round-2 remediation roadmap

To re-attain APPROVE the next round must:

1. **Block-resolution (Findings 1-2)**: scope
   `EloquentWithholdingCertificateRepository::findById / delete` (lines 16-20,
   149-159) and/or pre-load tenant-scoped at every controller method
   (`show`, `issue`, `void`, `submitTEJ`, `downloadPDF`, `downloadTEJXML`,
   `destroy`). Prefer repo-tier with optional `?string $companyId, ?string
   $tenantId` parameters so the service layer can stay unaware. Add tests
   pinning each route as 404 cross-tenant.

2. **Important-resolution (Finding 3)**: mirror the api.taxation.011 fix on
   `SalesWithholdingTrackingController::show / markCertificateReceived` —
   replace load-then-403 with read-tier scoping; remove redundant 403
   guard.

3. **Important-resolution (Finding 4)**: scope
   `EloquentWithholdingTaxRuleRepository` write methods, ensuring
   company-specific rule writes carry the requesting tenant's company_id.
   Global rules (company_id IS NULL) remain read-only-shareable.

4. **Important-resolution (Finding 6)**: strengthen the
   `test_create_withholding_certificate_rejects_cross_tenant_partner_at_service_tier`
   pin so it fails when controller-tier `Partner::where(...)->findOrFail`
   is reverted. SQL-log assertion pinning the controller-tier read is the
   recommended pattern.

5. **Nice-to-have (Finding 5)**: defer to a follow-up cluster
   `api.taxation.stampduty`. Acceptable to flip api.taxation.001-013 to
   fixed once 1-4 are remediated.

The api.loyalty round-2/3 precedent (single-controller surgical fixes
mirroring the inventoried pattern, with same-controller test additions) is
the right shape for this remediation.
