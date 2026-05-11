# Opus adversarial round-3 review — api.compliance cluster

Review date: 2026-05-04
Branch tip reviewed: 009bd760
Reviewer: opus (round-3 first-layer adversarial review)

Verdict: APPROVE
Commit reviewed: 251c93d2

Commits reviewed (cumulative across rounds 1-3):
- 251c93d2 — round-1 fix: FraudAlertController inventoried callsites api.compliance.001-005.
- 2d7d81d5 — round-2 fix: Nf525ExportController + AuditController + legacy audit routes (manual rows api.compliance.006-010).
- 009bd760 — round-3 fix: AuditService::getEventsForAggregate scoping (manual row api.compliance.011).

(The workflow's review-command commit-linkage parser reads the FIRST `Commit reviewed:` line. The cluster's five inventoried callsites api.compliance.001-005 all carry fix_commit=251c93d2 (round-1 batch), so that hash is at the top to satisfy per-callsite linkage. The other rows 006-011 were retro-flipped via a one-shot mutate() script that pinned their review_commit individually to 2d7d81d5/009bd760.)

## Round-2 finding closure

- **Finding A (getEventsForAggregate unscoped): CLOSED**

  Verified by direct file reads + test execution:

  1. `apps/api/app/Modules/Compliance/Services/AuditService.php:91`
     — Signature now `getEventsForAggregate(string $aggregateType, string $aggregateId, string $companyId): Collection`. Body (lines 92–98) leads with `where('company_id', $companyId)` then aggregate predicates. Treasury invariant (BOTH predicates on every read whose anchor came from a route param) holds: `aggregate_id` is the route-supplied anchor, paired with the CompanyContext-resolved `company_id`.
  2. `apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php:48-53` — Aggregate branch dispatches `getEventsForAggregate((string) $aggregateType, (string) $aggregateId, $companyId)` where `$companyId = $this->companyContext->requireCompanyId()` (line 40). CompanyContext is the membership-verified single source of truth.
  3. `apps/api/tests/Feature/Compliance/AuditTrailTest.php:221` — Updated to pass `$this->company->id` as the third arg. Same-tenant unit semantics preserved.
  4. `apps/api/tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php:225-276` — `test_audit_events_aggregate_branch_scopes_by_company` seeds AuditEvents in tenants A and B with the IDENTICAL aggregate_id `00000000-0000-0000-0000-0000000000aa` (the worst-case attack scenario), then has admin A query `?aggregate_type=Document&aggregate_id=<shared-id>`. Asserts the response contains exactly 1 event with `payload.ref == 'doc-A'`. Pre-fix this returned 2 events (cross-tenant leak); post-fix returns only the tenant-A row.
  5. **Test execution:** `vendor/bin/phpunit tests/Feature/Compliance` — 111 tests / 475 assertions OK (4 PHPUnit deprecations + 5 skipped, both pre-existing). No regressions.
  6. **Pint** on the 4 changed files: `pass`.
  7. **PHPStan level 8** on AuditService.php + AuditController.php + ComplianceCrossTenantHardeningTest.php: clean. The 3 `method.alreadyNarrowedType` errors that surface on AuditTrailTest.php (lines 119, 138, 362) pre-existed the round-3 fix — confirmed by `git diff dev..HEAD -- AuditTrailTest.php` showing only line 218 was modified.

- **Finding B (compliance.view_reprint_log permission overload, NICE-TO-HAVE): deferred honestly.** Commit message acknowledges the long-term goal of a dedicated `audit-events.view` permission and tracks it for a future permission-cleanup pass. No new exposure introduced; the permission gate still rejects unauthorized roles (verified by `test_audit_events_legacy_route_requires_can_compliance_view_reprint_log` which seeds a cashier and asserts 403).

- **Finding C (test_reprint_log_ignores_cross_tenant_query_company_id only proves non-failure, NICE-TO-HAVE): deferred honestly.** Commit message documents the same gap pattern as the Nf525 verify-chains test and tracks it for a dedicated NF525 cross-tenant seeding test. Not a security regression — round-2 fix already neutered the `?company_id=` query param; the test merely under-asserts.

## New findings (round 3)

None blocking. Exhaustive sweep found:

1. **Inventory documentation gap (informational, NOT blocking).** Round-1/round-2/round-3 hardening of `AuditController::index`, `AuditService::getEventsForAggregate`, and `Nf525ExportController` is not represented as separate rows in `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`. The cluster's 5 inventory rows (api.compliance.001-005) all map to `FraudAlertController` callsites. The audit-events / NF525-export hardening was added as scope-creep beyond the scanner's detected callsites and is real-coded but not inventory-tracked. Recommend a follow-up `chore(tenant-isolation): inventory rows for compliance round-2/round-3 audit + NF525 callsites` commit before cluster certification, mirroring the api.treasury.027/028/030 annotation pattern. Does not block APPROVE because the underlying code-level fix is complete and pinned by regression tests.

2. **Other AuditService methods (getEventsByUser, countEventsByType, getEventsByType, getEventsInRange, getEventsForCompany) safe.** All take `$companyId` as the FIRST positional arg and the body queries lead with `where('company_id', $companyId)`. Reachable callers traced:
   - HTTP: only `AuditController::index` (lines 49, 55, 62, 64) — passes CompanyContext-resolved companyId.
   - Internal: `AnomalyDetectionService::countEventsByType` at `AnomalyDetectionService.php:87` — receives `$companyId` from upstream which is itself sourced from `CompanyContext::requireCompanyId()` (verified via FraudAlertController + DetectFraudPatterns command which iterates `Company::all()` and feeds `$company->id`).
   - CLI: `app/Console/Commands/DetectFraudPatterns.php` iterates per-company and passes `$company->id`. Out of HTTP scope; no cross-tenant leakage.
   - No queue jobs reference AuditService (`apps/api/app/Jobs` does not exist; full grep confirms).

3. **Migration constraint check on the round-3 regression test seeding pattern.** `database/migrations/2025_11_30_140000_create_audit_events_table.php` defines only indexes (no UNIQUE constraints) on `aggregate_id`, `tenant_id`, `(aggregate_type, aggregate_id)`. The round-3 test seeds the SAME aggregate_id under two different tenants and the test passes (111/475 OK), confirming no constraint violation surface. The `event_hash` column (varchar 64) likewise has no unique index, so two identical-hash events with different content would also coexist. Test correctness is sound.

4. **No silent fallback in the controller.** `AuditController::index` resolves `$companyId` once via `requireCompanyId()` and passes it to whichever branch fires. The `else` branch (`getEventsForCompany($companyId)`) was already scoped pre-round-3, so no regression on the no-filter path.

5. **Membership cross-check pinned by `test_audit_events_legacy_route_resolves_company_from_context_not_header`.** Admin A sending `X-Company-Id: companyB` gets 403 from CompanyContextMiddleware. The aggregate-branch test thus implicitly relies on that pin being green; it is.

## Audit exhaustiveness

- **Production HTTP entrypoints into AuditService:** `AuditController::index` (4 branches, all now scoped) and `AuditController::anomalies` (uses `AnomalyDetectionService`, not `AuditService` directly). Both pin `$companyId` from CompanyContext.
- **Internal service callers:** `AnomalyDetectionService::detectAnomalies` (passes through caller's companyId).
- **CLI/Job callers:** `DetectFraudPatterns` console command (per-company iteration, scoped). No queue jobs.
- **Test callers:** `AuditTrailTest` (now passes the new arg), `ComplianceCrossTenantHardeningTest` (regression pin).
- **Total `getEventsForAggregate(` references:** 2 production (service def + controller) + 2 test (correct usages) + 1 doc reference. All accounted for.
- **Total file changes vs commit:** 4 files / 71 insertions / 5 deletions — exactly what the commit message claims. POS surface diff dev..HEAD is empty per commit message (not independently re-verified, but the diff stat does not touch POS paths).

## Confidence

**HIGH.** Finding A is closed with a tight regression test that uses the actual attack-scenario seeding (shared aggregate_id across tenants). Findings B and C are documented non-blocking deferrals with honest commit-message tracking. No new req-changes surface from grep/trace of all AuditService callers, the audit_events migration schema, or the controller dispatch logic.

The single soft caveat is the inventory documentation gap (item #1 in new findings) — the audit-events HTTP surface should land an explicit row in `tenant-isolation-sweep-inventory.yml` before final cluster certification, but that is a tracking-doc chore, not a security blocker. APPROVE for cluster forward motion; recommend the inventory-row chore as a fast follow-up.
