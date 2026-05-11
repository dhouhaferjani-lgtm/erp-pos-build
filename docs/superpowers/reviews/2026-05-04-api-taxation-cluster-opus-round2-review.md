# Opus adversarial cluster review — api.taxation (round 2)

Review date: 2026-05-04
Branch tip reviewed: 02b38192
Reviewer: opus (round-2 post-round-1 BLOCK + round-2 remediation)
Owner: codex
Verdict: APPROVE
Commit reviewed: e7a2f543

## Verdict

APPROVE

The round-2 fix at `02b38192` honestly closes all 6 round-1 BLOCK findings. Each test-pin honesty check confirmed the new tests fail (with the expected 200-vs-404 / missing-body-pin diagnostic) when the relevant production change is reverted. The defer audit for Finding 5 (StampDutyRuleController) is appropriate per the kickoff brief. No new substantive findings surfaced during the fresh-eyes Taxation-module sweep.

## Round-1 BLOCK rationale verification

### Finding 1 (CRITICAL — fiscal hash chain integrity, issue/void/submitTEJ)

**Closed honestly.** Test pin: removed `$this->requireTenantScopedCertificate($id)` call from `WithholdingCertificateController::issue()`. Result: `test_issue_certificate_rejects_cross_tenant_id` failed with `Expected response status code [404] but received 200` at line 527. Restored. The post-condition assertion (cert.status remains DRAFT) is also load-bearing — without the helper, the foreign cert advances to ISSUED and the post-condition would also fail. Reviewed comments at lines 150-154, 181, 213-215 confirm same helper applied to issue/void/submitTEJ.

### Finding 2 (CRITICAL — show/downloadPDF/downloadTEJXML/destroy)

**Closed honestly.** Test pin (show): removed helper call from `show()`. Result: `test_show_certificate_rejects_cross_tenant_id` failed with `Expected response status code [404] but received 200` at line 499. Restored. Test pin (destroy): removed helper call from `destroy()`. Result: `test_destroy_certificate_rejects_cross_tenant_id` failed with `Expected response status code [404] but received 200` at line 560. Restored. The post-condition assertion (`assertNotNull($foreignCert->fresh())`) is load-bearing — without the helper, `certificateRepository->delete($id)` succeeds and the post-condition would also fail. Comments at lines 246-248, 265-266, 327-328 confirm helper applied to downloadPDF / downloadTEJXML as well; both share the same WithholdingCertificate route param + helper pattern, so the show/destroy test pins transitively cover them.

### Finding 3 (IMPORTANT — load-then-403 anti-pattern, show + markCertificateReceived)

**Closed at the controller tier; test gap acknowledged but acceptable.** Round-2 introduced the 404-on-foreign-or-missing pattern at `SalesWithholdingTrackingController::show` (line 110-114) and `markCertificateReceived` (line 131-135). However, no DIRECT regression test was added for either method against a foreign-tenant tracking record. The existing `test_record_withholding_rejects_cross_tenant_document_id` covers `recordWithholding` but not the sibling methods.

Caveat: the fix uses `if (! $tracking || $tracking->companyId !== $this->companyContext->getCompanyId())`. This means `$this->service->findById($id)` (which calls `EloquentSalesWithholdingTrackingRepository::findById` with eager-loaded document/customer/payment) DOES load the foreign tracking row + relations into memory before the 403-collapse-to-404 check. While the response is 404, the foreign data is still hydrated server-side — strictly weaker than the `recordWithholding` fix which pushes the predicate into the SQL. This is a NICE-TO-HAVE upgrade (push the company-scoping into the repository or service layer) but does NOT leak data to the response since the controller never serializes `$tracking` in the rejection path. Acceptable for round-2; flagging for future refinement.

### Finding 4 (IMPORTANT — withholding rule writes / cross-tenant fiscal-rule mutation)

**Closed honestly.** Test pin (show): replaced `$this->loadReadableRule($id)` with the original unscoped `$this->ruleRepository->findById($id)` + `abort(404)`. Result: `test_show_company_specific_withholding_rule_rejects_cross_tenant` failed with `Expected response status code [404] but received 200` at line 587. Restored. Test pin (update): removed `$this->requireCompanyScopedRule($id)` call from `update()`. Result: `test_update_company_specific_withholding_rule_rejects_cross_tenant` failed with `Expected response status code [404] but received 200` at line 607. Restored. The post-condition (`assertNotSame('0.9900', $foreignRule->fresh()?->rate)`) is load-bearing — without the helper, the rate IS mutated on the foreign rule.

The dual-helper design (`loadReadableRule` for global-OR-same-company reads vs. `requireCompanyScopedRule` for same-company-only writes) is correct: it preserves the by-design cross-tenant readability of global rules (`company_id IS NULL`) while preventing tenant-A admin from mutating tenant-B's company-specific rules.

### Finding 5 (NICE-TO-HAVE — StampDutyRuleController unscoped)

**Deferred appropriately.** Audit at `docs/superpowers/audits/2026-05-04-taxation-cross-cluster-blind-spots.md` documents the deferral with severity NICE-TO-HAVE, the schema rationale (`stamp_duty_rules` is country-scoped global reference, same shape as `tax_configurations`), and the recommended fix template. Per kickoff brief: "If you find a cross-cluster blind spot, document and defer." StampDutyRuleController is structurally distinct from the inventoried surfaces (different controller, different table, NOT in inventory) and the country-scoping invariant the cluster established is consistency-pressure rather than tenant-isolation per se. Deferral is correct.

### Finding 6 (IMPORTANT — test honesty, partner_id at service tier)

**Closed honestly.** Test pin: reverted `CreateWithholdingCertificateRequest::partner_id` rule from `ScopedExists::tenantAndCompany('partners', $tenantId, $companyId)` back to `'exists:partners,id'`. Result: `test_create_withholding_certificate_rejects_cross_tenant_partner_at_service_tier` failed with `Failed asserting that an array has the key 'partner_id'` at line 469 (Failures: 1, Assertions: 3 — meaning the 422 status assertion + error.code assertion both passed, but the body-pin caught the missing partner_id key in error.errors). Restored.

This is precisely the regression-detection scenario Finding 6 was created to catch: pre-body-pin, the test passed for unrelated 422 reasons (downstream business validation rejecting the request); post-body-pin, the test exclusively pins the validator-tier rejection of the cross-tenant partner_id. Reviewing the test code confirms the body-pin is duplicated with `test_create_withholding_certificate_rejects_cross_tenant_partner_id` (line 270-282) — both pin `error.errors.partner_id`, so two regression sentinels exist for the same surface (one from inventory api.taxation.001, one from defense-in-depth at the service tier).

## Defer audit assessment

The defer audit at `docs/superpowers/audits/2026-05-04-taxation-cross-cluster-blind-spots.md` is well-structured and appropriately scoped:

- Severity correctly graded NICE-TO-HAVE (no tenant-isolation breach in strict sense; same-country tenants can by-design read each other's stamp duty rules).
- Schema verification documented (no `tenant_id`, no `company_id`, FK to `countries.code`).
- Recommended fix template provided (mirrors api.taxation.008-010 pattern).
- Out-of-cluster justification cited from kickoff brief.

No objection to deferral.

## New round-2 findings

None substantive. Three observations worth noting for future cluster sweeps (none rise to BLOCK or REQUEST-CHANGES):

1. **SalesWithholdingTrackingController::show / markCertificateReceived use load-then-404 pattern, not read-tier scoping.** The controller calls `$this->service->findById($id)` (which executes an unscoped SELECT with eager-loaded relations) and then post-load checks `$tracking->companyId !== $companyContext->getCompanyId()`. Final response is 404 so no data leaks to client, but the foreign row + relations DO transit into application memory. The api.taxation.011 fix-commit explicitly forbade this anti-pattern for `recordWithholding`; sibling methods use a softer variant. NICE-TO-HAVE upgrade: push the company predicate into the repository (e.g., add `findByIdForCompany($id, $companyId)` to the repository interface) so the SELECT itself filters. Not a tenant-isolation breach in the strict sense — flagging for future hardening.

2. **VatPeriodController + VatReportController scope by `forCompany($company->id)` only (no `tenant_id` predicate).** Verified at `apps/api/app/Modules/Taxation/Domain/Entities/VatPeriod.php:116-119`: `scopeForCompany` adds `where('company_id', $companyId)` only. Verified migration `2026_03_23_200000_create_vat_periods_table.php`: `vat_periods` has NO `tenant_id` column, only `company_id`. Since each company belongs to exactly one tenant, `company_id` filtering structurally enforces tenant isolation. Acceptable per the cluster invariant, but worth noting the schema is structurally weaker than the partners/documents/payments shape — if a `tenant_id` migration ever happens for vat_periods, the scope must add the predicate.

3. **WithholdingTaxRuleController::store uses `$this->companyContext->requireCompanyId()` server-side authoritative for `company_id`.** Verified the validator at `CreateWithholdingRuleRequest.php` does NOT include `company_id` in its rules, so a tenant-A admin cannot inject a foreign company_id via the request body. Safe.

## Verification commands

- Test suite: `vendor/bin/phpunit tests/Feature/Taxation/TaxationTenantIsolationTest.php` — 20/20 OK, 40 assertions.
- Architecture gates: Gate A unscoped exists rules in Presentation tier: 63 (round-1 baseline); Gate B unscoped find/findOrFail on guarded models: 74 (round-1 baseline). Round-2 fixes are largely scanner-blind (sibling-controller findOrFails ARE in app/Modules/Taxation but new patterns use `where(...)->findOrFail()` + helpers, which Gate B treats as scoped). No regression in either count.
- PHPStan: `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Taxation tests/Feature/Taxation/TaxationTenantIsolationTest.php` — `[OK] No errors`.
- Pint: `./vendor/bin/pint --test app/Modules/Taxation tests/Feature/Taxation/TaxationTenantIsolationTest.php` — `pass`.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — `verified 916 event(s) across 268 callsite(s); 0 problem(s)`.
- POS surface diff: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` — empty.
- Cross-cluster regression: `vendor/bin/phpunit tests/Feature/Taxation tests/Feature/Pricing tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php` — 292 tests, 977 assertions, 24 skipped, 0 failures, 0 errors.

## Confidence

High. All 6 round-1 findings closed honestly (5 via direct test-pin reverts demonstrating 200→404 collapse + 1 via body-pin reveal that catches missing `partner_id` key in error.errors). The 5 new tests pin the previously-missing surfaces (show / issue / destroy on certificates; show / update on company-specific rules) with strong post-conditions (status remains DRAFT, fresh() not null, rate not mutated) that lock in fiscal-state invariants. Finding 3's lack of direct test for show/markCertificateReceived is a minor gap, but the fix is mechanically identical to recordWithholding (which is tested) and the 404 collapse is a single conditional, so regression risk is low. Finding 5 deferral is correct per kickoff brief. No new CRITICAL / IMPORTANT findings discovered during fresh-eyes module walk; the three observations noted are NICE-TO-HAVE refinements for future cluster sweeps.
