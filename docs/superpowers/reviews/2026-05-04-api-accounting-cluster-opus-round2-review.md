# Opus adversarial review — api.accounting cluster (round 2)

Review date: 2026-05-04
Branch tip reviewed: HEAD at 8b464720 (feat/tenant-isolation-sweep-execution)
Reviewer: opus (round-2 adversarial review post-Codex round-1 REQUEST-CHANGES; sweep:inventory:review --actor opus → claude)
Commit reviewed: 8b464720

Verdict: REQUEST-CHANGES

## Codex round-1 finding status

**The Codex round-1 second-layer finding for `PartnerBalanceController` is FULLY CLOSED by 8b464720.**

Round-1 finding (verbatim summary): tenant-A user hits
`/api/v1/companies/{tenant-B-companyId}/partners/{tenant-B-partnerId}/balance/refresh`
with `X-Company-Id: companyA` (middleware passes), and the company-only service
scope `Partner::query()->where('company_id', $companyB)->whereKey($partnerB)`
happily resolves tenant-B's partner.

Remediation verified at controller tier:
- Every public action method on `PartnerBalanceController` (`show`, `statement`,
  `receivables`, `payables`, `reconcile`, `refresh`, `refreshAll`) calls
  `$this->assertCompanyAccess($request, $companyId)` as the first line BEFORE
  any service invocation.
- `assertCompanyAccess` queries
  `UserCompanyMembership::query()->where('user_id', $user->id)->where('company_id', $companyId)->exists()`
  and throws `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`
  (HTTP 404) on miss. 404 (not 403) is the correct response to avoid disclosing
  that the companyId exists in some other tenant.
- The 5 new HTTP-tier route-attack tests
  (`test_partner_balance_*_route_refuses_foreign_company`) cover `show`,
  `statement`, `receivables`, `refresh`, `refreshAll` with `companyId =
  companyB->id` while the auth user is tenant-A. All 5 assert HTTP 404.
- Honesty check confirmed: with the pre-fix controller restored
  (`git checkout 8b464720~1 -- apps/api/app/Modules/Accounting/Presentation/Controllers/PartnerBalanceController.php`),
  all 5 new tests **fail** with "Expected 404 but received 200" (5/5
  failures, 5/5 assertions). With HEAD restored, all 5 tests **pass**.
- Service-tier scope (`PartnerBalanceService::refreshPartnerBalance` and
  `::getCachedOrCalculateBalance`) is unchanged at company_id-only. This is
  acceptable post-fix because the controller has now validated company
  ownership before invoking the service. Two original service-tier tests
  (`*_refuses_cross_tenant_partner`) still cover direct service callers
  (e.g., `AccountingService` / `GeneralLedgerService`) where the
  cross-tenant `(companyA, partnerB)` pair fails the company_id predicate.

The PartnerBalanceController route-driven exploit is **closed end-to-end** by
this commit.

**REQUEST-CHANGES verdict comes from a NEW finding, not from the Codex round-1
finding.** See Findings 1-2 below.

## Summary

The api.accounting cluster fix for the Codex round-1 second-layer finding is
correct and complete: `PartnerBalanceController` now performs a controller-tier
`UserCompanyMembership` check before every service invocation, returning HTTP
404 when the auth user is not a member of the URL `{companyId}`. The 5 new
HTTP-tier attack-shape tests fail against the pre-fix controller and pass
against HEAD — non-vacuous. PHPStan level 8 [OK], Pint pass, sweep
verify-history `1089 events / 268 callsites / 0 problems`, POS surface diff
empty, broader Accounting suite `257 tests / 901 assertions / 0 failures`
deterministically (no flake on the timing-dependent test this run). The
Codex round-1 second-layer finding is structurally and behaviorally closed
for `PartnerBalanceController`.

The verdict is **REQUEST-CHANGES** because the same exploit shape Codex
round-1 identified is **also reachable via two sibling controllers in the
same Accounting module**, and the cluster owner has now established the
controller-tier `assertCompanyAccess()` precedent that trivially closes
them. Filing as REQUEST-CHANGES (not deferred to sibling cluster) because
(a) Codex round-1 already established the same shape as a blocker, (b)
the remediation pattern is identical and one-line per route, (c) leaving
14+ unprotected route-driven endpoints in the same module after fixing 7
creates an inconsistent and exploitable security posture for the cluster's
visible surface area, and (d) Opus round-1 specifically called out
`OpeningBalanceBatchController.store` as "structurally protected by upstream
`requireCompanyId()`" which is **factually incorrect** — the controller
takes `$companyId` from the URL route segment, not from `CompanyContext`.

## Findings

1. **Severity: REQUEST-CHANGES (NEW BLOCKER — same exploit shape as Codex
   round-1, sibling controller in same module)** —
   `AccountPurposeController` accepts `{companyId}` from the URL route segment
   without any `UserCompanyMembership` validation. All 4 action methods
   (`index`, `validate`, `assignPurpose`, `removePurpose`) pass `$companyId`
   directly to `ChartOfAccountsService` methods that scope only by
   `where('company_id', $companyId)` (no tenant predicate, no membership
   check). Attack shape (identical to Codex round-1):
   - tenant-A user, `X-Company-Id: companyA` (middleware passes) →
     `GET /api/v1/companies/{tenant-B-companyId}/accounts/purposes` returns
     tenant-B's chart of accounts with system purposes (data exposure).
   - `PUT /api/v1/companies/{tenant-B-companyId}/accounts/{tenant-B-accountId}/purpose`
     mutates tenant-B's account purpose assignment (data mutation).
   - `DELETE /api/v1/companies/{tenant-B-companyId}/accounts/{tenant-B-accountId}/purpose`
     removes tenant-B's purpose (data mutation).
   - Files:
     - `apps/api/app/Modules/Accounting/Presentation/Controllers/AccountPurposeController.php:33,53,77,122`
       (4 action methods, zero membership validation)
     - `apps/api/app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:80,102,127`
       (`getAccountsWithPurposes`, `assignPurpose`, `removePurpose` —
       company_id-only scope)
   - Suggested fix (cluster-scoped, mirrors PartnerBalanceController):
     extract `assertCompanyAccess` to a small trait or shared base controller
     (or copy verbatim into AccountPurposeController), call as the first line
     of all 4 methods. Add HTTP-tier tests to AccountingTenantIsolationTest:
     `test_account_purpose_index_route_refuses_foreign_company`,
     `test_account_purpose_assign_route_refuses_foreign_company`,
     `test_account_purpose_remove_route_refuses_foreign_company`,
     `test_account_purpose_validate_route_refuses_foreign_company`.
   - Why this is a blocker, not a deferred sibling: the Codex round-1
     finding for PartnerBalanceController was treated as a blocker (rightly).
     This is the same shape, in the same module, with the same fix. Deferring
     it to a follow-up cluster while the precedent is wet ink would be
     internally inconsistent.

2. **Severity: REQUEST-CHANGES (NEW BLOCKER — same exploit shape, third
   sibling controller in same module)** — `OpeningBalanceBatchController`
   accepts `{companyId}` from the URL without `UserCompanyMembership`
   validation across 11 action methods. The controller has a partial defense
   for batch-bound methods (`show`, `destroy`, `rows`, `lock`, `import`,
   `validateBatch`, `preview`, `post` all check
   `$batch->company_id !== $companyId` after loading the batch by ID), but
   this defense is **structurally insufficient** for the route-driven attack
   shape:
   - The check verifies the batch belongs to the URL companyId, NOT that
     the auth user has access to the URL companyId. A tenant-A user hitting
     `/api/v1/companies/{tenant-B-companyId}/opening-batches/{tenant-B-batchId}/post`
     passes the equality check (both values are tenant-B) and **posts a
     batch on tenant-B's GL** (data mutation, fiscal-chain corruption).
   - `index($companyId)` and `status($companyId)` have ZERO companyId
     validation — return tenant-B's batches verbatim.
   - `store(Request, $companyId)` calls `Company::findOrFail($companyId)`
     and creates a batch under that company_id. Attacker creates a batch on
     tenant-B's books (data injection).
   - Opus round-1's classification of `Company::findOrFail($companyId)` at
     `OpeningBalanceBatchController.php:117` as "structurally protected by
     upstream `CompanyContext::requireCompanyId()`" is **factually
     incorrect**. The controller does NOT use `CompanyContext` — it reads
     `$companyId` directly from the URL route segment.
   - Files:
     - `apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:43,61,104,162,203,237,292,355,433,477,521`
       (11 action methods, zero membership validation; 8 of them have a
       batch->company_id == URL companyId check that does NOT defend against
       the cross-tenant attack shape)
     - `apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:74`
       (`getBatchesForCompany($companyId)` — company_id-only scope)
   - Suggested fix: same as Finding 1 — call `assertCompanyAccess` as the
     first line of all 11 action methods. The existing
     `batch->company_id == $companyId` checks become redundant (membership
     check already proves the user owns `$companyId`, and the batch
     resolution by ID combined with the equality check provides the
     batch-scoping) but can stay as defense-in-depth.
   - Severity rationale: this surface mutates posted journal entries
     (`post`), the fiscal hash chain anchor for the period. Cross-tenant
     mutation here is higher-impact than the read-only PartnerBalanceController
     methods that Codex round-1 already escalated.

3. **Severity: NICE / OUT-OF-SCOPE referral (not in api.accounting
   inventoried scope, sibling-cluster confirmation)** — `AccountController`
   `show($id)` and `update($id)` use `Account::forTenant($tenantId)->find($id)`
   with `$tenantId` sourced from `CompanyContext::requireCompany()->tenant_id`.
   This is a **within-tenant cross-company exposure** (different exploit
   shape from Findings 1-2): a user with access to companyA in tenantA can
   load/modify accounts belonging to companyB (also in tenantA, with
   shared tenant_id) by passing companyB's account UUID as the `{id}` route
   parameter. Confirmed as sibling-cluster concern, NOT the route-driven
   cross-tenant exploit Codex round-1 raised. Same status as Opus round-1
   classification (sibling cluster: `api.accounting-account-controller`).

4. **Severity: OUT-OF-SCOPE referral (sibling concern beyond Accounting
   module)** — `Account::findByPurposeOrFail($companyId, $purpose)` static
   helper retains the company_id-only scope concern from Opus round-1
   Finding 3. Constrained by `SystemAccountPurpose` enum so an attacker
   cannot smuggle a foreign accountId, but trusts unchecked `$companyId`.
   With the new `assertCompanyAccess` controller-tier guard now applied
   to PartnerBalanceController callers, the upstream `$companyId` for those
   callers is trustworthy. Other callers (e.g., `GeneralLedgerService:1401`)
   would also need their controllers' `$companyId` to be membership-validated;
   `GeneralLedgerService` is invoked from various controllers including the
   ReportsController and JournalEntryController which use `CompanyContext`
   (validator-tier guard via `requireCompanyId()` in CreateJournalEntryRequest).
   Surfacing for sibling-cluster review; not blocking this cluster.

## Audit exhaustiveness

- Pulled `--ff-only` (already up to date at 8b464720).
- Verified commit diff for `PartnerBalanceController.php` from 8b464720:
  every public action method calls `$this->assertCompanyAccess($request, $companyId)`
  before any service invocation. `assertCompanyAccess` queries
  `UserCompanyMembership` for `(user_id, company_id)` and throws
  `NotFoundHttpException` (HTTP 404) on miss.
- AccountingTenantIsolationTest run on current HEAD: **15 tests, 27
  assertions, 0 failures** (10 original + 5 new HTTP attack-shape).
- Honesty check (pre-fix re-run):
  - `git stash --include-untracked` (clean stash).
  - `git checkout 8b464720~1 -- apps/api/app/Modules/Accounting/Presentation/Controllers/PartnerBalanceController.php`
    confirmed pre-fix controller restored (no `assertCompanyAccess`,
    no `UserCompanyMembership` import).
  - Ran `vendor/bin/phpunit tests/Feature/Accounting/AccountingTenantIsolationTest.php
    --filter "_route_refuses_foreign_company"` → **5 tests, 5 assertions,
    5 failures** — exact predicted shape ("Expected 404 but received 200" on
    all 5 routes). Honest test, real exploit pre-fix.
  - `git checkout HEAD -- apps/api/app/Modules/Accounting/Presentation/Controllers/PartnerBalanceController.php`
    restored. Re-ran filtered tests → **OK (5 tests, 5 assertions)**.
  - `git stash pop` conflicted on `.claude/scheduled_tasks.lock`
    (pre-existing untracked file). Working tree clean. Stash 0 dropped
    (`git stash drop`); other stashes are pre-existing from prior sessions
    (not mine).
- Quality gates (current HEAD):
  - `vendor/bin/phpunit tests/Feature/Accounting/` → **OK (257 tests, 901
    assertions)** in 1m55s, no flake on the timing-dependent
    `CompleteGLHashChainE2ETest` this run.
  - `vendor/bin/phpstan analyse app/Modules/Accounting tests/Feature/Accounting/AccountingTenantIsolationTest.php
    --no-progress --memory-limit=2G` → **[OK] No errors**.
  - `vendor/bin/pint --test app/Modules/Accounting tests/Feature/Accounting`
    → **{"result":"pass"}**.
  - `php artisan sweep:inventory:verify-history` → **`verified 1089
    event(s) across 268 callsite(s); 0 problem(s).`**
  - `git diff --stat dev..HEAD -- apps/web/src/features/pos/ apps/web/src/pages/pos/`
    → **empty**.
- New finding hunt (per brief Section 5):
  - `AccountController::show/update`: independently re-verified Opus round-1
    classification — within-tenant cross-company gap, sibling-cluster
    concern, NOT exploitable via api.accounting inventoried surface. Status
    unchanged. (Finding 3 above.)
  - `AccountPurposeController`: **NEW BLOCKER**, same route-driven
    cross-tenant exploit shape as Codex round-1 raised for
    PartnerBalanceController. (Finding 1 above.)
  - `OpeningBalanceBatchController`: **NEW BLOCKER**, same route-driven
    cross-tenant exploit shape, with the additional severity multiplier of
    fiscal-chain mutation via `post`. Opus round-1's "structurally protected
    by `requireCompanyId()`" claim about this controller is factually
    incorrect (controller does not use `CompanyContext`). (Finding 2 above.)
  - `JournalEntryController`, `LedgerController`, `ReportsController`:
    spot-checked — none take `{companyId}` from the URL route segment;
    all use `CompanyContext::requireCompanyId()` (header-validated by
    `CompanyContextMiddleware`). Not exploitable via the route-driven
    attack shape.
- Sibling-controller surface in the Accounting module that takes
  `{companyId}` route segment (per `routes.php`):
  - PartnerBalanceController (7 routes) — **fixed** by 8b464720.
  - AccountPurposeController (4 routes) — **NEW BLOCKER** (Finding 1).
  - OpeningBalanceBatchController (11 routes) — **NEW BLOCKER** (Finding 2).
  - Total `{companyId}` route-segment endpoints in Accounting: 22. Fixed:
    7. Remaining exploitable: 15.

## Confidence

High that the Codex round-1 second-layer finding is fully closed for
`PartnerBalanceController` by 8b464720. The 5 new HTTP-tier tests are
honest (fail pre-fix, pass post-fix), the controller-tier guard is
correctly placed before service invocation, and the `UserCompanyMembership`
query semantically matches the round-1 attack shape.

High that Findings 1-2 are real, blocking, and identical in shape to the
Codex round-1 finding. I traced both controllers' routes, action methods,
and downstream service-tier scoping, and confirmed the same
tenant-A-user-hits-tenant-B-company URL flow that Codex round-1 used to
demonstrate the PartnerBalanceController exploit. The remediation pattern
is established (one-line `$this->assertCompanyAccess($request, $companyId)`
call) and trivially applicable.

Medium-to-high that escalating to REQUEST-CHANGES (rather than
APPROVE-WITH-MINOR-EDITS-APPLIED + sibling-cluster spawn) is the right
call. The argument for APPROVE-WITH-MINOR-EDITS-APPLIED would be: the 7
inventoried callsites are closed, the Codex round-1 finding is closed,
and Findings 1-2 are technically OUTSIDE the inventoried scope. The
argument for REQUEST-CHANGES (which I am making): Codex round-1 itself
moved a controller-tier route-trust gap into the cluster's blocking scope
even though it wasn't in the inventoried 7 callsites — the precedent is
already set that route-driven cross-tenant exploits in this module's
controllers count against this cluster. Applying that precedent
consistently means Findings 1-2 also count.

What I could have missed:
- I did not exhaustively re-trace `JournalEntryController` and
  `LedgerController` for `{companyId}` route segments — I relied on
  routes.php inspection which shows they do NOT have `{companyId}` route
  segments. If a hidden route binding overrides this in a service provider,
  there could be additional surface.
- I did not exhaustively run cross-cluster regression for Treasury,
  Document, or Compliance — the brief scoped me to Accounting. The
  inventory verify-history gate (1089/268/0) is the global consistency
  proxy.
- I did not write proof-of-concept attack tests for AccountPurposeController
  or OpeningBalanceBatchController — the brief restricted me to ratifying
  the existing remediation, not extending it. The remediation pattern is
  already proven (5 honest HTTP tests for PartnerBalanceController),
  so the Findings 1-2 fix is mechanical and the test pattern is copy-paste.
- The `assertCompanyAccess` helper is currently a private method on
  PartnerBalanceController. If the orchestrator chooses to apply Findings
  1-2 by extracting it to a trait or shared base controller, that's a
  refactor the cluster owner should consider — but the simpler "copy the
  6-line helper into each controller" path is also acceptable as long as
  the test coverage matches.
