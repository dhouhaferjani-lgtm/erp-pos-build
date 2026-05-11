# Codex round-3 second-layer review — api.compliance cluster

Review date: 2026-05-04
Branch tip reviewed: 009bd760
Reviewer: codex (round-3 second-layer review post-Opus APPROVE)

Verdict: REQUEST-CHANGES
Commit reviewed: 009bd760

## Round-2 Finding A closure
- Confirmed via: `git show 009bd760`, direct reads of `AuditService.php` and `AuditController.php`, the compliance feature suite, PHPStan, hostile grep, and regression-test inspection. `AuditService::getEventsForAggregate(string $aggregateType, string $aggregateId, string $companyId)` now leads with `AuditEvent::where('company_id', $companyId)`, then applies `aggregate_type` and `aggregate_id`. `AuditController::index` obtains `$companyId` from `CompanyContext::requireCompanyId()` once and passes that value into the aggregate branch. This closes the code-level leak Opus found in round 2.

## Opus round-3 claim verification
- `AuditService::getEventsForAggregate` signature and query shape: confirmed. The method requires the caller company id and has no fallback to request input.
- `AuditController::index` company source: confirmed. All four branches use the same `CompanyContext`-resolved company id: aggregate, date range, event type, and default company listing.
- `AuditTrailTest::test_can_query_audit_events_by_aggregate`: confirmed updated to pass `$this->company->id`.
- `ComplianceCrossTenantHardeningTest::test_audit_events_aggregate_branch_scopes_by_company`: honest. It seeds tenant A and tenant B with the exact same `aggregate_id`, then asserts exactly one returned event and asserts the payload is `['ref' => 'doc-A']`, so it excludes the foreign row rather than merely counting a coincidental singleton.
- Other `AuditService` reads: confirmed. `getEventsByUser`, `getEventsByType`, `getEventsInRange`, `getEventsForCompany`, and `countEventsByType` all take `$companyId` as the first argument and lead with `where('company_id', $companyId)`.
- Reachable callers: `AuditController::index` and `AuditController::anomalies` both resolve company id from `CompanyContext`; `AnomalyDetectionService` receives caller-supplied company id and its HTTP caller is `AuditController::anomalies`; `DetectFraudPatterns` iterates `Company` records and passes `$company->id`; no queue job path was found.
- Opus's code-level APPROVE is correct, but its inventory finding is under-severed for this sweep workflow.

## New findings (round 3)
- REQUEST-CHANGES: scanner-invisible compliance surfaces fixed during rounds 1-3 are not represented in the sweep inventory or manual-callsite stub. `rg` finds no inventory/manual rows for `Nf525ExportController`, `AuditController`, `/audit/events`, `/audit/anomalies`, `getEventsForAggregate`, or the NF525 endpoints. The live inventory still has only `api.compliance.001` through `.005`, all tied to `FraudAlertController`. `tenant-isolation-sweep-manual-callsites.yml` says cluster owners populate manual rows for non-mechanical tenant-isolation gaps, and the sweep plan calls the YAML inventory the single source of truth. Since this cluster scope now explicitly includes the sibling NF525/audit controllers and the service-tier aggregate method, allowing approval without rows would let the cluster hard gate certify only the original five FraudAlert rows while losing the evidence for the discovered fiscal/audit surfaces. Add manual inventory rows and submit/review them through the normal sweep commands.

## Audit exhaustiveness
- Hostile-grep result count: 20
- Tests: `vendor/bin/phpunit tests/Feature/Compliance` passed: 111 tests, 475 assertions, 4 PHPUnit deprecations, 5 skipped.
- PHPStan: `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Compliance/Services/AuditService.php app/Modules/Compliance/Presentation/Controllers/AuditController.php tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php` passed with no errors.
- Pint: `./vendor/bin/pint --test` on the four changed files passed.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` passed: verified 579 events across 262 callsites; 0 problems.
- POS surface: `git diff --name-only dev..HEAD -- apps/api/app/Modules/POS apps/api/tests/Feature/POS apps/web/src/features/pos apps/web/e2e packages/shared` returned no files. `git show --name-only 009bd760` touched only Compliance code/tests.

## Confidence
High on the runtime tenant-isolation fix: the aggregate audit branch is now company-scoped, the regression uses the worst-case shared aggregate id, and all reachable audit-event HTTP/job paths resolve or derive company id from trusted context rather than request input. The only blocking issue is workflow/accounting, not a newly observed data leak: the expanded compliance surfaces must be added to the canonical inventory so the sweep status, history, and review gate match what was actually fixed.
