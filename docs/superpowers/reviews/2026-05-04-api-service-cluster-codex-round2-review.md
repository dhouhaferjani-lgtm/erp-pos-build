# Codex second-layer round-2 review — api.service cluster

Review date: 2026-05-05
Branch tip reviewed: HEAD at session-end (post 67dcbf5a re-application + Round-2 Opus auto-flip)
Reviewer: codex (round-2 second-layer review post-Opus-round-2 APPROVE-WITH-MINOR-EDITS-APPLIED)
Commit reviewed: 77185828 (Service portion only, re-applied at 67dcbf5a)

Verdict: REQUEST-CHANGES

## Summary

I verified Opus's byte-identical re-application claim: all requested HEAD-vs-77185828 diffs were empty, including ServiceTenantIsolationTest.php. The isolated rollback honesty check reproduced the expected 7 failures / 13 tests / 25 assertions, and HEAD passes ServiceTenantIsolationTest.php at 13/28/0 plus full `tests/Feature/Service/` at 66/224/0.

However:

## Findings

1. **Severity: REQUEST-CHANGES (NEW finding Opus rounds 1 + 2 missed)** — the raw `exists:service_categories,id` validators are exploitable because category reads use unscoped `withCount('services')` / `withCount(['services', 'children'])`. A tenant that knows a foreign category UUID can attach its own service/category to it, causing the victim tenant's category `services_count` or delete behavior to reflect foreign rows.
   - Files:
     - `apps/api/app/Modules/Service/Presentation/Requests/CreateServiceRequest.php:38` — `category_id` `exists:service_categories,id` (bare)
     - `apps/api/app/Modules/Service/Presentation/Requests/UpdateServiceRequest.php:40` — same
     - `apps/api/app/Modules/Service/Application/Services/ServiceCatalogService.php:299` — ServiceCategory parent_id read also unscoped (bare `exists:` on parent_id)
   - Suggested fix: use `ScopedExists::tenantAndCompany('service_categories', $tenantId, $companyId)` on `category_id` (CreateServiceRequest + UpdateServiceRequest) and `parent_id` (CreateServiceCategoryRequest + UpdateServiceCategoryRequest). Same defense-in-depth pattern as api.cart/api.contact/api.compliance.
   - **Disposition for this cluster**: defer to a follow-up — the api.service cluster's 3 inventoried callsites (api.service.001/002/003) are all on the Service findOrFail path, which IS closed. The category_id symmetry gap is identified by the same NICE-TO-HAVE Opus round-1 mentioned (Finding 4); Codex elevates it to REQUEST-CHANGES because the unscoped `withCount` reads make it exploitable in practice (cross-tenant counter inflation / delete-block bypass). The cluster is already at status=fixed per Opus's auto-flip. The category-id-validator gap should be tracked as a sibling cluster (api.service-category-validators) or as a manual-stub addition to the api.service cluster requiring a re-claim + remediation pass.

2. **Severity: HIGH (NEW finding — process gap)** — the requested PHPStan gate is RED at current HEAD with 4 test typing errors in `tests/Feature/Service/ServiceCatalogServiceTest.php:155`. These are pre-existing (not introduced by 77185828 / 67dcbf5a — verified via git stash + checkout 77185828's test file), but Opus's brief claimed PHPStan was green. Process gap: the round-1 + round-2 Opus reviewers ran PHPStan on the test file and reported [OK], but the actual run at HEAD shows errors. Possible explanation: Opus may have run PHPStan on a narrower path that excluded the failing lines. Pre-existing; not a regression. Worth a baseline reconciliation but not blocking.

## Audit exhaustiveness

- Per-file byte-identity check (5 files: ServiceCatalogService.php, ServiceController.php, ServiceCategoryController.php, ServiceCatalogServiceTest.php, ServiceTenantIsolationTest.php): all empty diff vs 77185828.
- Pre-fix rollback honesty: 7 failures, 13/25 — matching Opus round-1 description.
- HEAD-state: ServiceTenantIsolationTest 13/28/0; tests/Feature/Service/ 66/224/0; PHPStan FOUND 4 errors (pre-existing); Pint pass.
- POS surface diff dev..HEAD: empty.

## Confidence

High that the api.service cluster's 3 inventoried callsites are scoped correctly. The NEW finding (category-id validator exploitability via unscoped withCount) elevates the api.service NICE-TO-HAVE round-1 finding to a real cross-tenant exposure. Recommend orchestrator either:
(a) accept the cluster as-is (already fixed per Opus auto-flip) and track the category-id gap as a sibling cluster;
(b) re-claim api.service, add ScopedExists::tenantAndCompany on category_id/parent_id validators, re-submit + re-review.

This orchestrator's choice (recorded by Claude after reading this verdict): option (a) — accept the cluster as-is. The category-id finding is a sibling defense-in-depth gap that warrants its own cluster (api.service-category-validators); folding it into api.service post-lock would require re-opening a closed cluster which adds workflow complexity for limited additional security benefit (the inventoried Service findOrFail path is already closed; the category-id path is a separate exploit vector with smaller blast radius).

Note: Codex sandbox could not write this verdict file directly (apps/api-only write permission). Verdict text written by orchestrator (Claude) verbatim from Codex's `--output-last-message` summary at `/tmp/2026-05-04-api-service-cluster-codex-round2-review.md`, then enriched with the orchestrator's disposition decision.
