# Opus adversarial review — api.accounting cluster (round 3)

Review date: 2026-05-04
Branch tip reviewed: HEAD at 9d4540bd (feat/tenant-isolation-sweep-execution)
Reviewer: opus (round-3 adversarial review post-Opus round-2 REQUEST-CHANGES; sweep:inventory:review --actor opus → claude)
Commit reviewed: 9d4540bd

Verdict: REQUEST-CHANGES

## Round-2 finding status

**Round-2 Opus Findings 1 + 2 are STRUCTURALLY CLOSED at the controller tier
by 9d4540bd. However, the new HTTP attack-shape tests for
`AccountPurposeController` are VACUOUS — they pass on the pre-fix controller
because they hit a non-existent URL (`/api/companies/...` is missing the
required `v1` prefix). The structural fix is real; the test coverage for two
of the five new tests is fake.** Verdict downgrades to REQUEST-CHANGES on the
test honesty failure.

### Round-2 Finding 1 (AccountPurposeController) — STRUCTURE CLOSED, TESTS VACUOUS

Structural remediation verified:
- `AccountPurposeController` `use`s the new
  `App\Modules\Accounting\Presentation\Concerns\RequiresCompanyAccess` trait.
- All 4 action methods (`index`, `validate`, `assignPurpose`, `removePurpose`)
  call `$this->assertCompanyAccess($request, $companyId)` as the first line
  before any service invocation
  (`AccountPurposeController.php:38,60,86,133`). Method signatures correctly
  gain `Request $request` as the first parameter for Laravel auto-injection.

Test coverage failure (NEW BLOCKER):
- The two new tests
  `test_account_purpose_index_route_refuses_foreign_company` and
  `test_account_purpose_validate_route_refuses_foreign_company` hit URLs of
  the form `"/api/companies/{$this->companyB->id}/accounts/purposes"` and
  `"/api/companies/{$this->companyB->id}/accounts/purposes/validate"`
  (`AccountingTenantIsolationTest.php:278,285`) — **missing the `v1`
  prefix**. The actual route definitions at
  `app/Modules/Accounting/Presentation/routes.php:60,64` are under
  `Route::prefix('api/v1')`, and `php artisan route:list --path="api/companies"`
  returns "no routes match" while `--path="api/v1/companies"` enumerates them.
- Honesty check (per brief Section 3): with the pre-fix
  `AccountPurposeController` restored
  (`git checkout 9d4540bd~1 -- apps/api/app/Modules/Accounting/Presentation/Controllers/AccountPurposeController.php`),
  the two AccountPurpose tests **pass** (testdox: "✔ Account purpose index
  route refuses foreign company", "✔ Account purpose validate route refuses
  foreign company") because Laravel returns 404 for the unmatched route, which
  satisfies `assertStatus(404)`. The tests do **not** exercise the
  `assertCompanyAccess` guard at all.
- Net result: the structural fix in `AccountPurposeController` is real and
  correct, but the regression tests for it provide ZERO assurance — they would
  pass even if the trait were never added or were removed in a future refactor.
  This is exactly the test-quality failure mode the round-2 brief enforces in
  the honesty-check step.

### Round-2 Finding 2 (OpeningBalanceBatchController) — STRUCTURE CLOSED, TESTS HONEST

Structural remediation verified:
- `OpeningBalanceBatchController` `use`s the `RequiresCompanyAccess` trait.
- All 11 action methods that take `{companyId}` from the URL call
  `$this->assertCompanyAccess($request, $companyId)` as their first line
  before any service invocation: `index` (line 48), `show` (line 68),
  `store` (line 113), `destroy` (line 173), `rows` (line 216), `lock`
  (line 252), `status` (line 309), `import` (line 374), `validateBatch`
  (line 454), `preview` (line 500), `post` (line 546).
- The 12th method `types()` (line 347) is correctly NOT guarded — its route
  `/api/v1/opening-batches/types` does not include `{companyId}` and returns
  pure enum metadata. This matches the brief's stated expectation.
- Pre-existing `$batch->company_id !== $companyId` checks remain after the
  trait call as defense-in-depth (now redundant but acceptable).

Honesty check confirmed for the 3 OpeningBalanceBatch tests:
- With pre-fix controller restored, all 3 OpeningBalanceBatch tests fail with
  the predicted shape:
  - `test_opening_balance_batches_index_route_refuses_foreign_company` →
    "Failed asserting that 200 is identical to 404."
  - `test_opening_balance_batches_status_route_refuses_foreign_company` →
    "Failed asserting that 200 is identical to 404."
  - `test_opening_balance_batches_post_route_refuses_foreign_company` →
    `RuntimeException: Opening balance batch not found: fake-batch-id` (the
    handler reaches the service-tier `getBatch()` call before hitting the
    nonexistent batch-id path → reflects 500 not 404; assertion fails as
    "Failed asserting that 500 is identical to 404"). The exploit is real:
    the controller IS being entered without a membership check on pre-fix
    code; the 500 simply demonstrates that absence of the guard.
- With HEAD restored, all 3 pass.
- Net: 3 of the 5 new tests are honest and gate the OpeningBalanceBatch
  remediation properly.

### PartnerBalanceController (round-2 finding) — UNCHANGED, TRAIT REFACTOR ONLY

`PartnerBalanceController` was correctly refactored from a private
`assertCompanyAccess` helper (round-2 fix) to the shared trait. All 7 action
methods retain the call. The 5 round-2 tests for this controller still hit
`/api/v1/companies/...` and remain honest (covered by round-2 honesty check).

### Trait

`App\Modules\Accounting\Presentation\Concerns\RequiresCompanyAccess` exists at
`apps/api/app/Modules/Accounting/Presentation/Concerns/RequiresCompanyAccess.php`.
- Single protected `assertCompanyAccess(Request $request, string $companyId): void`
  method.
- Queries `UserCompanyMembership::query()->where('user_id', $user->id)->where('company_id', $companyId)->exists()`.
- Throws `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`
  ("Company not found.") on miss (404 hides cross-tenant company existence).
- Comprehensive PHPDoc explains the attack shape and lists the 3 controllers
  that use it.
- Used by all 3 controllers (verified via `use` import).

The trait abstraction is sound. Refactoring from per-controller private
helper to shared trait is the correct DRY move and means future Accounting
controllers that take `{companyId}` from the URL can apply the same guard
consistently.

## Summary

Round-3 brings the controller-tier `UserCompanyMembership` check from 7
endpoints (PartnerBalanceController only) to 22 endpoints (all 3 sibling
controllers). The trait extraction
(`App\Modules\Accounting\Presentation\Concerns\RequiresCompanyAccess`) is
clean and consistent. PHPStan level 8 [OK], Pint pass, sweep verify-history
`1093 events / 268 callsites / 0 problems`, AccountingTenantIsolationTest
20/32/0, OpeningBalanceBatchTest 29/102/0 (no regression), POS surface diff
empty.

The verdict is **REQUEST-CHANGES** because two of the five new HTTP
attack-shape tests
(`test_account_purpose_index_route_refuses_foreign_company` and
`test_account_purpose_validate_route_refuses_foreign_company`) hit URLs that
do not exist in the application route table. Both URLs are missing the `v1`
prefix that all routes in `routes.php` require. Laravel returns 404 for
these unmatched routes, which satisfies `assertStatus(404)` regardless of
controller content. With the pre-fix `AccountPurposeController` restored
(no membership check), both tests still pass. They provide zero regression
coverage for the AccountPurposeController fix.

The structural fix in `AccountPurposeController` is correct — but the test
gate that's supposed to enforce it is fake. This is the exact failure mode
the round-2 brief Section 3 honesty check is designed to catch.

Severity rationale for REQUEST-CHANGES (not APPROVE-WITH-MINOR-EDITS-APPLIED):
the entire round-2 escalation hinged on Codex round-1 establishing that
route-driven cross-tenant exploits in this module count against the cluster.
The same bar must apply to verifying the fix: an honest HTTP test that
fails pre-fix and passes post-fix. Two of the five new tests fail that bar.
A future refactor that removes `RequiresCompanyAccess` from
`AccountPurposeController` would not be caught. Fixing the URL prefix is a
one-character change per test (insert `/v1`), so the cost of remediation
is trivial relative to the coverage value.

## Findings

1. **Severity: REQUEST-CHANGES (NEW BLOCKER — vacuous regression tests)** —
   Two of the five new HTTP attack-shape tests added in 9d4540bd are
   vacuous because they target URLs that have no matching route. Specifically:
   - `test_account_purpose_index_route_refuses_foreign_company` at
     `apps/api/tests/Feature/Accounting/AccountingTenantIsolationTest.php:278`
     hits `"/api/companies/{$this->companyB->id}/accounts/purposes"`. Should
     be `"/api/v1/companies/{$this->companyB->id}/accounts/purposes"`.
   - `test_account_purpose_validate_route_refuses_foreign_company` at
     `apps/api/tests/Feature/Accounting/AccountingTenantIsolationTest.php:285`
     hits `"/api/companies/{$this->companyB->id}/accounts/purposes/validate"`.
     Should be `"/api/v1/companies/{$this->companyB->id}/accounts/purposes/validate"`.
   - Honesty check (pre-fix re-run with controller reverted to 9d4540bd~1):
     both tests pass. They do not exercise `assertCompanyAccess` and would
     pass even if the trait were removed entirely.
   - The route table confirms the bug: `php artisan route:list --path="api/companies"`
     → "Your application doesn't have any routes matching the given criteria";
     `php artisan route:list --path="api/v1/companies"` → enumerates 29
     routes including the 4 AccountPurpose endpoints.
   - The same fix would be applied to the other AccountPurpose action
     methods if HTTP tests are added for `assignPurpose` and `removePurpose`
     (the brief's suggested fix list at round-2 Finding 1 included
     `test_account_purpose_assign_route_refuses_foreign_company` and
     `test_account_purpose_remove_route_refuses_foreign_company`, which are
     not present in 9d4540bd — see Finding 2).
   - Suggested remediation:
     - Update both URL strings to include `/v1` prefix.
     - Re-run pre-fix honesty check: both tests should now fail with
       "Failed asserting that 200 is identical to 404" against pre-fix
       controller.
     - Re-run on HEAD: both should pass.

2. **Severity: NICE — missing HTTP attack-shape test coverage for the
   2 mutation routes on AccountPurposeController** — The 5 new HTTP tests in
   9d4540bd cover index + validate on AccountPurposeController and index +
   status + post on OpeningBalanceBatchController. They do NOT cover
   `assignPurpose` (PUT) and `removePurpose` (DELETE) on
   AccountPurposeController. The round-2 review explicitly listed all 4
   AccountPurpose tests as needed. Mutation routes are higher-value
   regression targets than read-only routes (an attacker can mutate
   tenant-B's chart of accounts, not just read it). Recommend adding
   `test_account_purpose_assign_route_refuses_foreign_company` and
   `test_account_purpose_remove_route_refuses_foreign_company`. Both should
   target `/api/v1/companies/{companyB}/accounts/{accountId}/purpose` (with
   `/v1`) and use `putJson` / `deleteJson` respectively. Equally for
   OpeningBalanceBatchController: of the 11 newly-protected endpoints, only
   3 have HTTP tests (index, status, post). The other 8 (show, store,
   destroy, rows, lock, import, validateBatch, preview) are protected by
   the trait but not regression-gated. Severity is NICE because the trait
   is shared across all methods — if the trait import or call is ever
   removed, the existing 3 tests would catch it on each controller. But
   if a single method's `assertCompanyAccess` call is ever removed in a
   refactor (without removing the trait), the 8 untested methods would
   regress silently.

3. **Severity: OBSERVATION — `OpeningBalanceBatchController.show/destroy/etc`
   defense-in-depth `$batch->company_id !== $companyId` checks are now
   structurally redundant** — Once `assertCompanyAccess` proves the auth
   user owns `$companyId`, the secondary check that the batch's company_id
   matches `$companyId` is no longer guarding against the cross-tenant
   route-driven exploit. It still serves as an "unrelated batch ID"
   defense (e.g., if someone passes a tenant-A batch ID to a tenant-A
   companyB URL while user owns both companyA and companyB), so removing
   it isn't strictly necessary. Just a noting that the layered defense is
   now somewhat overlapping. No action required; flagging for the cluster
   owner's awareness.

4. **Severity: OBSERVATION (sibling-cluster confirmation)** — Verified that
   the other 3 Accounting controllers (`JournalEntryController`,
   `LedgerController`, `ReportsController`) do NOT take `{companyId}` from
   the URL route segment. All three use `CompanyContext::requireCompanyId()`
   inside their action methods, which sources the company from the
   `X-Company-Id` header (validated by `CompanyContextMiddleware`) or
   falls back to the user's first membership. Not exploitable via the
   route-driven attack shape. Same status as round-2 Finding 4.
   `AccountController.show/update` independently re-confirmed as the
   `Account::forTenant($tenantId)->find($id)` within-tenant cross-company
   sibling-cluster concern (`AccountController.php:79,151`), not the
   route-driven exploit shape (no `{companyId}` URL segment, scoping uses
   `tenant_id` not `company_id`). Status unchanged from round-2 Finding 3.

5. **Severity: NICE (test setup quality)** — The new tests reuse
   `$this->actingAsForCompany($this->userA, $this->companyA)` from the
   round-2 fixture pattern, which is good consistency. None of the new
   tests pre-create a target batch / account / purpose row in tenant-B
   (they rely on the membership check short-circuiting before service
   invocation). This is correct for the "no membership → 404" shape under
   test, but means the tests do not verify what would happen if both the
   membership check AND a real target row existed (would the service-tier
   scope catch a same-tenant cross-company case? — out of round-3 scope,
   noting for sibling cluster).

## Audit exhaustiveness

- Pulled `--ff-only` (already up to date at 9d4540bd).
- Verified commit diff for 9d4540bd:
  - `app/Modules/Accounting/Presentation/Concerns/RequiresCompanyAccess.php`
    is a new file, 50 LOC, contains a single trait with one protected method
    (`assertCompanyAccess`) that queries `UserCompanyMembership` and throws
    `NotFoundHttpException` on miss.
  - `PartnerBalanceController` refactored from inline private helper to
    `use RequiresCompanyAccess`. Trait import added. Private method removed.
    All 7 method calls unchanged (`assertCompanyAccess(...)` as first line).
  - `AccountPurposeController` adds trait import + `use` clause + signature
    Request injection on all 4 methods + `assertCompanyAccess` call as first
    line on all 4 methods. Verified line by line at lines 9, 25, 36, 38, 58,
    60, 84, 86, 131, 133.
  - `OpeningBalanceBatchController` adds trait import + `use` clause +
    signature Request injection on all 11 companyId-taking methods +
    `assertCompanyAccess` call as first line on all 11. `types()` correctly
    skipped (no companyId in route). Verified line by line at lines 12, 32,
    46-48, 66-68, 111-113, 171-173, 214-216, 250-252, 307-309, 372-374,
    452-454, 498-500, 544-546.
  - `tests/Feature/Accounting/AccountingTenantIsolationTest.php` adds 5 new
    test methods at lines 273-309. URL prefix bug isolated to lines 278 and
    285 (the AccountPurpose tests).
- AccountingTenantIsolationTest run on current HEAD: **OK (20 tests, 32
  assertions)**.
- OpeningBalanceBatchTest run on current HEAD: **OK (29 tests, 102 assertions)**
  — no regression on existing OpeningBalanceBatch coverage.
- Honesty check (per brief Section 3):
  - `cd apps/api && git stash --include-untracked` → clean stash (other
    untracked are pre-existing docs).
  - `git checkout 9d4540bd~1 -- apps/api/app/Modules/Accounting/Presentation/Controllers/AccountPurposeController.php apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php`
    → confirmed pre-fix controllers restored (no trait import, no
    assertCompanyAccess call, method signatures lack `Request $request` as
    first parameter).
  - Ran the 5 new test filter:
    `vendor/bin/phpunit --filter "test_account_purpose_index_route_refuses_foreign_company|test_account_purpose_validate_route_refuses_foreign_company|test_opening_balance_batches_index_route_refuses_foreign_company|test_opening_balance_batches_status_route_refuses_foreign_company|test_opening_balance_batches_post_route_refuses_foreign_company"
    tests/Feature/Accounting/AccountingTenantIsolationTest.php --testdox`
    → **5 tests, 5 assertions, 3 failures, 2 passes**.
  - Per-test outcome on pre-fix code:
    - `✔ Account purpose index route refuses foreign company` (PASS — vacuous)
    - `✔ Account purpose validate route refuses foreign company` (PASS — vacuous)
    - `✘ Opening balance batches index route refuses foreign company`
      ("Failed asserting that 200 is identical to 404." — honest exploit)
    - `✘ Opening balance batches status route refuses foreign company`
      ("Failed asserting that 200 is identical to 404." — honest exploit)
    - `✘ Opening balance batches post route refuses foreign company`
      ("Failed asserting that 500 is identical to 404." — honest exploit;
      pre-fix code reaches the service-tier `getBatch()` call which throws
      `RuntimeException` for the fake-batch-id, producing 500 instead of 404
      — still demonstrates the absence of the membership guard).
  - `git checkout HEAD -- apps/api/app/Modules/Accounting/Presentation/Controllers/AccountPurposeController.php apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php`
    → restored. Re-ran filtered tests → **5 / 5 / 0** — all pass.
  - `git stash pop` → clean.
- Quality gates (current HEAD):
  - `vendor/bin/phpunit tests/Feature/Accounting/AccountingTenantIsolationTest.php`
    → **OK (20 tests, 32 assertions)** in 19s.
  - `vendor/bin/phpunit tests/Feature/Accounting/OpeningBalanceBatchTest.php`
    → **OK (29 tests, 102 assertions)** in 16s. No regression.
  - `vendor/bin/phpstan analyse app/Modules/Accounting tests/Feature/Accounting/AccountingTenantIsolationTest.php
    --memory-limit=2G` → **[OK] No errors** (73 files analysed).
  - `vendor/bin/pint --test app/Modules/Accounting tests/Feature/Accounting/AccountingTenantIsolationTest.php`
    → **{"result":"pass"}**.
  - `php artisan sweep:inventory:verify-history` → **`verified 1093
    event(s) across 268 callsite(s); 0 problem(s).`**
  - `git diff --stat dev..HEAD -- 'apps/web/src/features/pos/' 'apps/web/src/components/pos/' 'apps/web/src/pages/pos/'`
    → **empty** (no POS surface impact).
- New finding hunt (per brief Section 5):
  - `php artisan route:list --path="api/v1/companies"` enumerates 29 routes
    under `/api/v1/companies/{companyId}/...`. Of these, 22 belong to the
    Accounting module's 3 controllers (Partner: 7, AccountPurpose: 4,
    OpeningBatches: 11) — all now protected by the trait. The other 7
    routes belong to non-Accounting modules (Company, Receipt settings,
    Reservation settings, etc.) and are out of scope for this cluster.
  - `php artisan route:list --path="api/companies"` (no v1) → no routes.
    This is what causes the AccountPurpose tests to vacuously pass.
  - `grep -rn "string \$companyId" app/Modules/Accounting/Presentation/Controllers/`
    enumerates exactly the same 22 method signatures across the 3 controllers.
    No additional `{companyId}` URL surface in Accounting.
  - `JournalEntryController`, `LedgerController`, `ReportsController`: all
    use `CompanyContext::requireCompanyId()` inside action methods; routes
    do NOT carry `{companyId}` segments. Not exploitable via route-driven
    attack shape.
  - `AccountController`: routes are `/accounts` and `/accounts/{account}`
    (no `{companyId}`). Uses `CompanyContext`. Same `forTenant`-only scope
    sibling-cluster concern as round-2 Finding 3 — not the route-driven
    exploit shape, separate cluster.
- Sibling-controller surface in the Accounting module that takes
  `{companyId}` route segment after 9d4540bd:
  - PartnerBalanceController: 7 routes — protected.
  - AccountPurposeController: 4 routes — protected (regression tests partial:
    2 of 4 are vacuous; 2 of 4 are missing entirely).
  - OpeningBalanceBatchController: 11 routes — protected (regression tests
    partial: 3 of 11 covered; 8 not gated).
  - Total: 22 / 22 protected at the controller tier; 7 of 22 gated by honest
    HTTP regression tests (5 round-2 partner tests + 3 round-3 batch tests
    with the post-test counted as honest despite the 500-vs-404 detail).

## Confidence

High that round-2 Findings 1 + 2 are STRUCTURALLY closed at the controller
tier by 9d4540bd. The trait is correctly defined, imported, and called as
the first line of every action method that takes `{companyId}` from the URL.
The 22-of-22 coverage matches the brief's stated expectation.

High that 2 of the 5 new tests are VACUOUS due to the missing `v1` URL
prefix. The honesty-check evidence is direct: those 2 tests pass on the
pre-fix controller. No interpretation needed — Laravel returns 404 for an
unmatched route, which satisfies `assertStatus(404)` regardless of what
the controller does. The route table independently confirms `/api/companies/`
has no matching route.

High that escalating to REQUEST-CHANGES is the right call. The structural
fix is complete, but accepting vacuous regression tests sets a bad
precedent for future cluster reviews. The remediation is a 6-character
edit per test (insert `/v1`), the test pattern is already established
(the 3 OpeningBalanceBatch tests use the correct prefix), and rejecting
vacuous tests now prevents a much harder remediation cost later if the
trait is ever removed from `AccountPurposeController` and the tests still
pass.

Medium-to-high that the NICE finding about missing test coverage for
`assignPurpose`, `removePurpose`, and the 8 unguarded OpeningBalanceBatch
methods is non-blocking. The trait pattern means a test that fails on
trait removal is sufficient for cluster integrity — but I'd recommend
covering the mutation routes (assignPurpose, removePurpose, store,
destroy, lock, import, validateBatch) in a follow-up because mutation is
the higher-impact attack vector.

What I could have missed:
- I did not inspect the `actingAsForCompany` helper to confirm it sets
  the `X-Company-Id` header to `companyA`. If it does NOT set the header,
  the AccountPurpose tests would NOT be vacuous because the middleware
  might fall back to the user's first membership (companyA) and make the
  exploit path moot before reaching the route. But that doesn't change
  the conclusion — the tests still don't exercise `assertCompanyAccess`,
  they exercise Laravel's 404-for-unmatched-route fallback. Verified
  via testdox + pre-fix run.
- I did not run the broader Accounting test suite (`tests/Feature/Accounting/`)
  in full — only the 2 most relevant files
  (`AccountingTenantIsolationTest`, `OpeningBalanceBatchTest`). The
  round-2 reviewer ran 257 tests across the directory and noted a
  timing-flaky test (`CompleteGLHashChainE2ETest`) — I did not re-run
  it and cannot speak to current flake status.
- I did not write proof-of-concept attack tests for `assignPurpose` /
  `removePurpose` / the 8 unguarded OpeningBalance methods. The brief
  scoped me to ratifying the existing fix and tests, not extending
  coverage. The trait is shared so the existing tests do prove the
  guard mechanism works; the missing tests are about regression
  surface area, which is a NICE not a blocker.
- The cluster status flag (`status=fixed`, auto-flipped at round-1) is
  unchanged regardless of this verdict, per the brief's note. If the
  cluster owner accepts REQUEST-CHANGES and lands a 6-character URL fix
  on the 2 vacuous tests, no sweep:inventory action is needed — the
  cluster remains at `fixed`.
