# Opus adversarial review — api.accounting cluster (round 4 / final ratify)

Review date: 2026-05-04
Branch tip reviewed: HEAD at d91e6c17 (feat/tenant-isolation-sweep-execution)
Reviewer: opus (round-4 adversarial ratify post-Opus round-3 REQUEST-CHANGES; sweep:inventory:review --actor opus → claude)
Commit reviewed: d91e6c17
Cluster status: api.accounting at status=fixed (from round-1 auto-flip; unchanged through R2/R3/R4)

Verdict: APPROVE

## Round-3 BLOCKING finding status

**FULLY CLOSED by d91e6c17.**

Round-3 Opus blocked on two vacuous tests
(`test_account_purpose_index_route_refuses_foreign_company`,
`test_account_purpose_validate_route_refuses_foreign_company`) that hit
`/api/companies/{B}/accounts/purposes` instead of `/api/v1/companies/...`,
making them pass against the pre-fix controller because Laravel returns 404
for unmatched routes. d91e6c17 closes the finding cleanly:

1. **URL prefix corrected on the two flagged tests.** Diff shows both
   `getJson("/api/companies/...")` calls are now `getJson("/api/v1/companies/...")`
   at `apps/api/tests/Feature/Accounting/AccountingTenantIsolationTest.php:278`
   and `:285`. The `/v1` insertion is the entirety of the structural change for
   these two tests — minimal, surgical, no scope creep.

2. **Two new mutation-route tests added** (also flagged NICE in round-3
   Finding 2):
   - `test_account_purpose_assign_mutation_route_refuses_foreign_company`
     (`AccountingTenantIsolationTest.php:290-300`) — `putJson` against
     `/api/v1/companies/{B}/accounts/{accountB}/purpose` with `purpose=cash`
     payload. This is the higher-impact of the two mutation routes — an
     attacker that bypasses the controller-tier check could rewrite tenant-B's
     chart-of-accounts purpose mapping.
   - `test_account_purpose_remove_mutation_route_refuses_foreign_company`
     (`AccountingTenantIsolationTest.php:302-307`) — `deleteJson` against the
     same route, exercises `removePurpose`.
   Both correctly use `/api/v1/` prefix.

3. **Honesty check (NON-NEGOTIABLE per brief Section 3) — re-performed in this
   round:**
   - `git stash --include-untracked`
   - `git checkout 9d4540bd~1 -- apps/api/app/Modules/Accounting/Presentation/Controllers/AccountPurposeController.php`
     (note: d91e6c17~1 = 9d4540bd, which already contains the controller fix;
     went one step deeper to 9d4540bd~1 to get the genuinely pre-fix
     controller without the trait or `assertCompanyAccess` calls — diff
     confirmed: trait import removed, `assertCompanyAccess` calls removed
     from all 4 action methods, `Request $request` parameter dropped from
     `index`/`validate`/`removePurpose`).
   - `vendor/bin/phpunit --filter test_account_purpose tests/Feature/Accounting/AccountingTenantIsolationTest.php`
     against pre-fix controller → **4 tests / 4 assertions / 4 failures**, all
     four with the predicted shape "Expected response status code [404] but
     received 200." None of the four short-circuited on a route-not-found
     (which would also have produced 404 and been counted as pass under the
     old vacuous shape). Confirms all 4 AccountPurpose HTTP tests now
     genuinely exercise the controller-tier `assertCompanyAccess` guard.
   - `git checkout HEAD -- ...AccountPurposeController.php` to restore.
   - Re-run same filter against HEAD: **4 tests / 4 assertions / OK**. Stash
     popped; tree restored.
   - This is the textbook honesty-check signal: tests fail before the fix and
     pass after the fix, by exactly the amount of state the fix introduces.

The R3 blocker is structurally and behaviorally closed. No new exploit
identified.

## Gates — all green on current HEAD (d91e6c17)

| Gate | Result |
| --- | --- |
| `vendor/bin/phpunit tests/Feature/Accounting/AccountingTenantIsolationTest.php` | 22 / 34 / 0 (matches expectation) |
| `vendor/bin/phpunit tests/Feature/Accounting/OpeningBalanceBatchTest.php` | 29 / 102 / 0 (no regression) |
| `vendor/bin/phpstan analyse app/Modules/Accounting tests/Feature/Accounting/AccountingTenantIsolationTest.php` | OK (level 8, zero errors) |
| `vendor/bin/pint --test app/Modules/Accounting tests/Feature/Accounting/AccountingTenantIsolationTest.php` | pass |
| POS surface diff `dev..HEAD` (`apps/web/src/features/pos`, `apps/web/src/components/pos`, `apps/desktop`) | empty (no UI/Tauri runtime impact) |

POS-related file matches in the broader `dev..HEAD` diff resolve to either
`apps/web/tools/__fixtures__/audit-pos-local-cache/*` (audit scaffolding
fixtures) or `PaymentRepositoryController` (Treasury, not POS). No POS
runtime surface touched.

## Coverage status across the 3 controllers

After d91e6c17, the route-driven exploit class is gated by HTTP attack-shape
tests as follows:

| Controller | Methods (all gated by trait) | HTTP tests gating regression |
| --- | --- | --- |
| `PartnerBalanceController` | 7 | 5 (round-2) |
| `AccountPurposeController` | 4 | 4 (2 read in R3 corrected to `/v1` + 2 new mutation tests in R4) |
| `OpeningBalanceBatchController` | 11 | 3 (round-3: index, status, post) |

Total: 22 controller methods structurally guarded by the
`RequiresCompanyAccess` trait; 12 routes covered by HTTP tests that fail
honestly when the trait or its `assertCompanyAccess` call is removed.

## Findings

1. **Severity: OBSERVATION (carried forward from R3 #2 NICE residual)** — 8 of
   the 11 protected `OpeningBalanceBatchController` methods (show, store,
   destroy, rows, lock, import, validateBatch, preview) still have no
   per-method HTTP attack-shape regression test. The trait is shared, so a
   removal of the trait import would be caught by the existing 3 tests on
   that controller (index, status, post) — but a removal of an individual
   `assertCompanyAccess` call from one of the 8 unguarded methods would not
   be caught. Severity is OBSERVATION (not blocker) for two reasons: (a) the
   R3 review explicitly downgraded this from NICE-FIX to NICE-OBSERVATION
   given the trait is the structural enforcement and per-method test coverage
   is defense-in-depth; (b) the round brief explicitly says "Further
   iterations should be reserved for genuine new blockers, not nice-to-haves"
   and this is exactly the kind of residual the brief tells round-4 to defer.
   No action required this round.

2. **Severity: OBSERVATION (carried forward from R3 #3)** — Pre-existing
   `$batch->company_id !== $companyId` defense-in-depth checks in
   `OpeningBalanceBatchController.show`/`destroy`/etc. are now structurally
   redundant given the trait-tier check, but still serve as a defense
   against "valid companyB URL with valid companyB-membership but a
   tenant-A batch ID smuggled in" — which is a different attack shape from
   the route-driven exploit this cluster targets. Keeping them is sound
   layered defense; no action required.

3. **Severity: OBSERVATION (sibling-cluster, unchanged from R2/R3)** —
   `JournalEntryController`, `LedgerController`, `ReportsController` do not
   take `{companyId}` from the URL segment (use
   `CompanyContext::requireCompanyId()` from `X-Company-Id` header) and are
   not exposed to this exploit shape. `AccountController.show/update` use
   `Account::forTenant($tenantId)->find($id)` and are subject to the
   different "within-tenant cross-company" exposure that does not match
   the route-driven shape this cluster sweeps. Cluster scope is correct.

## Verdict rationale

Per the round-4 brief Treasury thresholds:

- **APPROVE** requires: round-3 BLOCKING fully closed + no new blockers.
  Both criteria met: the four AccountPurpose tests now fail honestly against
  pre-fix code (4/4 failures with the right error shape) and pass against
  fixed code (4/4 OK); two new mutation-route tests added for the
  higher-impact PUT/DELETE routes; gates all green; POS surface clean.
- No new critical exploit identified. The R3-NICE residuals (8 untested
  protected methods on OpeningBalanceBatchController; redundant
  defense-in-depth checks) remain OBSERVATION-grade and were explicitly
  ruled out of scope by the round-4 brief.

This cluster has converged. The structural fix progressed from
PartnerBalanceController-only (R1 auto-flip + R2 callsite-tier) → all 3
sibling controllers via trait extraction (R3) → honest test coverage of the
high-impact routes (R4). Four rounds is the correct depth for a controller
cluster with a route-driven exploit class — and the pattern (controller
trait + HTTP attack-shape tests + honesty check) is now the reference
template for any other cluster with `{companyId}` URL segments.

## Verdict: APPROVE.

Cluster status: api.accounting remains `fixed` (no inventory mutation needed
per brief). No further accounting-cluster review rounds required unless a
new exploit class is discovered.
