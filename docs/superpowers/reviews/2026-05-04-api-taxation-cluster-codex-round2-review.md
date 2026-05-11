# Codex second-layer review — api.taxation cluster (round 2)

Review date: 2026-05-04
Branch tip reviewed: 02b38192
Reviewer: codex (round-2 second-layer review post-Opus round-2 APPROVE; round-1 Opus BLOCK remediated)

Verdict: APPROVE
Commit reviewed: e7a2f543

## Round-1 BLOCK rationale verification

Finding 1 (WithholdingCertificateController::issue / void / submitTEJ): closed honestly. `issue`, `void`, and `submitTEJ` all call `requireTenantScopedCertificate($id)` before service delegation. Negative controls:

- Removing the `issue` helper made `test_issue_certificate_rejects_cross_tenant_id` fail with `Expected response status code [404] but received 200`.
- Temporary route probes for `void` and `submit-tej` returned 404 in current code; removing only the relevant helper in each method made the probe fail with `Expected response status code [404] but received 200`.

Finding 2 (show / downloadPDF / downloadTEJXML / destroy): closed honestly. `show`, both download methods, and `destroy` all pre-load through `requireTenantScopedCertificate($id)`. Negative controls on the committed tests:

- Removing the `show` helper made `test_show_certificate_rejects_cross_tenant_id` fail with 200 instead of 404.
- Removing the `destroy` helper made `test_destroy_certificate_rejects_cross_tenant_id` fail with 200 instead of 404.

Finding 3 (SalesWithholdingTrackingController::show + markCertificateReceived): closed for the promised soft semantic. Both methods still hydrate through `service->findById($id)`, but foreign-company records now abort 404 instead of the old load-then-403 response. A temporary route probe for foreign `GET /sales-withholding/{id}` and `PATCH /certificate-received` passed at 404; changing only those aborts back to 403 made the probe fail on expected 404 vs received 403. This is response-tier closure, not SQL-tier scoping.

Finding 4 (WithholdingTaxRuleController reads/writes): closed honestly. `show` uses `loadReadableRule($id)` to allow global rules while 404ing foreign company-specific rules. `update`, `deactivate`, and `destroy` use `requireCompanyScopedRule($id)` before repository writes. Negative controls:

- Replacing `loadReadableRule` with unscoped `ruleRepository->findById` made `test_show_company_specific_withholding_rule_rejects_cross_tenant` fail with 200 instead of 404.
- Removing `requireCompanyScopedRule` from `update` made `test_update_company_specific_withholding_rule_rejects_cross_tenant` fail with 200 instead of 404.

Finding 6 (api.taxation.013 test pin): closed honestly. Reverting only `CreateWithholdingCertificateRequest::partner_id` from `ScopedExists::tenantAndCompany(...)` to `exists:partners,id` made `test_create_withholding_certificate_rejects_cross_tenant_partner_at_service_tier` fail with `Failed asserting that an array has the key 'partner_id'`, proving the new body-pin catches the previously masked downstream 422.

## Defer audit assessment

Finding 5 (StampDutyRuleController) is appropriately deferred. `stamp_duty_rules` is a country-scoped global reference table with no tenant/company columns, and the defer audit documents the consistency gap against TaxConfiguration plus a follow-up fix template. This is not an in-cluster tenant-owned route-param surface.

## Module-walk hunt

WithholdingCertificateController: no route-param method bypasses `requireTenantScopedCertificate`. `downloadBatchTEJXML` is anchored on `companyContext->requireCompanyId()` and repository `findByCompany($companyId, ...)`, so it does not accept a foreign certificate id.

WithholdingTaxRuleController: request-body `company_id` forging is not viable. `CreateWithholdingRuleRequest` has no `company_id` rule, so `validated()` drops a forged body value; when `is_company_specific` is true the controller overwrites `company_id` from `CompanyContext`. If false, no forged `company_id` is persisted.

SalesWithholdingTrackingController: `index` calls `getPendingForCompany($companyId)` / `getAllForCompany($companyId)`, and the repository applies `where('company_id', $companyId)`. `show` and `markCertificateReceived` are 404-collapsed but still load foreign rows before the post-load check; that is worth future hardening, but it does not leak response data and matches the round-2 acceptance criterion.

VatPeriodController + VatReportController: all route-anchored VAT period reads use `VatPeriod::query()->forCompany($company->id)->findOrFail(...)`. `vat_periods` has `company_id` but no `tenant_id`; company scoping is the available structural tenant boundary.

Hostile grep found only infra repository `::find(` hits:

- `EloquentWithholdingTaxRuleRepository::findById`
- `EloquentVatPeriodRepository::findById`

The remaining controller `findOrFail` blind spot is the documented deferred StampDutyRuleController country-scope issue.

## Audit exhaustiveness

- Hostile-grep delta: no new in-cluster controller bypass found since round 1; `grep -rn "::find(" apps/api/app/Modules/Taxation/ | grep -v '/tests/'` reports only the two repository hits above.
- Tests: `vendor/bin/phpunit tests/Feature/Taxation/TaxationTenantIsolationTest.php` — OK, 20 tests / 40 assertions. Cross-cluster regression — OK, 292 tests / 977 assertions / 24 skipped / 47 PHPUnit deprecations.
- PHPStan: normal run hit sandbox `EPERM` on PHPStan's TCP listener; `vendor/bin/phpstan --debug --no-progress --memory-limit=2G` completed full analysis with `[OK] No errors`. Taxation-scoped PHPStan also passed.
- Pint: `vendor/bin/pint --test app/Modules/Taxation tests/Feature/Taxation/TaxationTenantIsolationTest.php` passed. Full `vendor/bin/pint --test` failed on unrelated `tests/Feature/Identity/UserManagement/SetPosPinTest.php`.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — verified 916 events across 268 callsites; 0 problems.
- Inventory integrity: all 13 `api.taxation.001` through `.013` callsites remain `status: under_review` with `fix_commit: e7a2f543`.
- POS surface diff: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` — empty.

## Confidence

High. The five in-cluster remediation findings fail in targeted ways when their guards or body-pin are removed, and the deferred StampDutyRuleController item is correctly tracked outside this cluster. I found no additional substantive api.taxation tenant-isolation blocker; the only residual hardening note is that SalesWithholdingTracking `show` / `markCertificateReceived` are response-tier 404 collapses rather than SQL-tier scoped reads.
