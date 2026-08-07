# Merge gate — `fix/r2c-reports` (R2-C reports residuals, ticket 2026-08-06-l3-cash-scope-residuals.md (a)+(b))

**Date:** 2026-08-07 · **Reviewer:** tenancy-authz-reviewer (adversarial, code-grounded)
**Branch:** `fix/r2c-reports` · **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-r2c-reports`
**Commits:** `397fc6299` (part a), `ba2a95632` (part b backend), `d8356921f` (part b FE) · **Base:** `a1952aa23`

## VERDICT: spec ❌ · quality **CHANGES-REQUESTED**

Part (b) backend + FE are correct as far as they go. Part (a) trades the filed P1 leak for a
**silent money-visibility regression on the company-wide read**, leaves the *same* leak live on three
sibling Expense surfaces, and **fails open** when every location is inactive. Part (b) implements only
half of what the ticket asked (the message-strip is missing).

All claims below were re-executed in this worktree against live PostgreSQL. Probe tests were inserted
temporarily into `apps/api/tests/Feature/Accounting/CashMovementsReportTest.php`, run, and reverted
(`git status` clean; no production or test file was modified by this review).

---

## Findings

### [CRITICAL] F-1 — the clamp silently drops NULL-location (unattributed) cash *and* the deactivated branch's rows from the company-wide read

`apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php:902-909`

```php
if (! $this->locationScopeBoundary->isUnrestricted($companyId, $effective)) {
    return $effective;
}
$activeLocationIds = $this->locationScopeBoundary->activeLocationIds($companyId);
$allLocationIds = $this->locationScopeBoundary->allLocationIds($companyId);

return count($activeLocationIds) === count($allLocationIds) ? [] : $activeLocationIds;
```

Every consumer treats a **non-empty** list as a hard `whereIn`, which excludes `location_id IS NULL`:
`AgedReceivablesService.php:159-160`, `UpcomingPaymentsService.php:169-170,201-202,241-242,274-275`,
`CashMovementsReportService.php:309-311`. So the moment **any** company location is deactivated, an
**unrestricted** principal's company-wide read loses:

1. every NULL-location row — pure advances are NULL by design (ticket (d), `PaymentController.php:596-598`),
   plus company-level safes and any cash the fail-closed guard cannot attribute; and
2. the deactivated branch's own rows — which that principal *is* entitled to.

The branch's own test encodes the regression as expected behaviour
(`apps/api/tests/Feature/Accounting/CashMovementsReportTest.php`, `test_reactivating_a_location_restores_it_to_the_unrestricted_read`):
with Shop B inactive it asserts `count=1 / EUR.in = 10.00`; after reactivation `count=3 / 35.00`. The
missing `25.00` is Shop B's `20.00` **plus a NULL-location `5.00` that no scope decision should ever
have touched**.

Live probes (this worktree, unrestricted admin, Shop B inactive, A=10 / B=20 / NULL=5):

```
[PROBE-1] implicit                        status=200 count=1 totals={"EUR":{"in":"10.00",...}}
[PROBE-1] explicit location_ids[]=B       status=200 count=1 totals={"EUR":{"in":"20.00",...}}
[PROBE-3] explicit location_ids[]=A&[]=B  status=200 count=1 totals={"EUR":{"in":"10.00",...}}
```

Three consequences:

* **Implicit vs explicit still disagree** — mirrored. The ticket filed "implicit shows what explicit
  refuses"; the fix produces "implicit hides what explicit serves".
* **PROBE-3 is a silent partial refusal.** The caller explicitly named A **and** B, got `200`, and B was
  dropped with no `403`, no warning, no `meta` marker. The FE reaches this shape by default: the scope
  picker does **not** filter inactive locations (`LocationController.php:100-119` `pickerPayload()` has
  no `is_active` predicate), and `useViewScope` sends the full id list for scope `all`
  (`apps/web/src/features/locations/hooks/useViewScope.ts:11-14`, `useAgedPayables.ts:14-18`).
* **Cross-surface inconsistency.** `CashPositionController.php:87-93` and
  `MaturingInstrumentsController.php:63-70` keep `orWhereNull` for an unrestricted caller, so on
  `TreasuryOverviewPage` the cash-position card would include unattributed cash while the
  upcoming-payments card (same page, same user, same company) silently would not.
  `tests/Feature/Treasury/LocationReconciliationTest.php:258-264,273-275,282-286` pins
  Σ(location buckets) == grand_total *including* unattributed — that invariant's premise quietly
  changes the day a branch is deactivated.

**Tenant-#1 amplification.** Once the scope is non-empty the journal leg's fail-closed
`whereNotExists` (`CashMovementsReportService.php:391-419`) applies. The ticket's own live measurement
(§(a) PRE-LAUNCH DATA TASK) is that 126 cash repositories share one `gl_account_id` and *all* have
`location_id IS NULL`. So for tenant #1, deactivating one of the four branches removes the **entire
journal leg** from the *company-wide* cash-movements report, not just from branch views.

**Failure scenario:** ops deactivates a closed branch → the next morning the owner's company-wide
cash-movements / aged-AR / aged-payables / upcoming-payments totals drop by every advance, every
company-level safe movement and the whole journal leg, with a `200` and no explanation.

**Fix:** take the ticket's **option 1** — compare the grant against **all** company locations inside
`LocationScopeBoundary::isUnrestricted()` (`LocationScopeBoundary.php:42-45`), matching
`LocationScopeResolver::allCompanyLocationIds()` (`LocationScopeResolver.php:72-80`). That closes the
leak for the *whole* family in one line and leaves `[]` (NULL-preserving) intact for a genuinely
unrestricted principal. If the clamp shape is kept instead, it must carry keep-NULL semantics
(`whereIn(...) orWhereNull(...)`) like the treasury siblings, not a bare id list.

### [IMPORTANT] F-2 — the same bare-`[]` collapse is still live on three Expense surfaces

`app/Modules/Expense/Presentation/Controllers/ExpenseController.php:80`,
`ExpenseAnalyticsController.php:33`, `ExpenseExportController.php:52` are byte-identical to the
pre-fix `reportLocationScope()`; the predicate they feed is skipped when the list is empty
(`app/Modules/Expense/Application/Queries/ExpenseIndexQuery.php:28-29`). A principal granted exactly
today's active set is therefore still classified unrestricted and still reads a deactivated location's
expenses — on the list, the analytics tiles and the CSV export.

Commit `397fc6299`'s "no impact" note clears only `CashPositionController` and
`MaturingInstrumentsController` and does not mention Expense at all. Verified no-impact for the other
call sites (they never collapse to a bare `[]`): `CashPositionController.php:80-93`,
`MaturingInstrumentsController.php:57-70`, `POS/Presentation/Controllers/ReportController.php:269-283`,
`POS/Application/Services/PosAnalyticsService.php:529-538`,
`POS/Presentation/Controllers/AnalyticsController.php:156`.

### [IMPORTANT] F-3 — fail-open when every location is inactive: the original P1 leak survives

`activeLocationIds()` returns `[]` when no location is active, and `[]` means *unrestricted* to every
consumer — so the clamp emits exactly the value it exists to avoid.

```
[PROBE-2] all locations inactive, user granted ONLY Shop A, implicit read
          status=200 count=3 totals={"EUR":{"in":"35.00",...}}   # Shop B's 20.00 leaked
```

Reachable whenever a company's locations are all deactivated (migration, seasonal close, a
single-location company closing its only branch). Any fix must distinguish "no predicate" from "empty
predicate" — e.g. return a sentinel / `false`-predicate rather than `[]`, or adopt F-1's option 1.

### [IMPORTANT] F-4 — part (b) is half-done: the internal exception message is still echoed in the 500 body

The ticket's fix for (b) is "move the other three the same way **and** strip the exception message from
the generic 500". The move landed; the strip did not:
`ReportsController.php:776` (`'Failed to generate aged receivables report: '.$e->getMessage()`),
`:832`, `:867`, `:949`, plus the pre-existing bare `$e->getMessage()` at `:240, :331, :365, :484, :521,
:637, :671, :744, :805, :846, :936`. Any exception raised *inside* the remaining `try` (PDO errors
carrying SQL text, `Carbon::parse` failures on attacker-supplied `as_of_date`) is still returned to the
client verbatim. The fix is also **positional**, not structural: the broad `catch (\Exception $e)`
would swallow any `AuthorizationException` thrown deeper in a service later on. Catch
`\Throwable` → log, return a static message, and let `AuthorizationException` pass through explicitly.

### [MINOR] F-5 — the new authz suite proves DENY only

`tests/Feature/Accounting/Reports/ReportLocationScopeAuthorizationTest.php:71-90` asserts the 403 path
for the three endpoints but never asserts that an **in-scope** request still returns `200` — a
regression that turned all three into blanket 403s would pass this suite green. It also creates the
permission ad hoc (`:65 Permission::findOrCreate('reports.operational')`) instead of seeding
`RolesAndPermissionsSeeder` (the repo convention, used by `CashMovementsReportTest:91`), so it does not
prove the catalog actually carries the permission. (It does — `RolesAndPermissionsSeeder.php:273`, and
the routes are correctly `can:reports.operational`, `routes.php:178-193`, under
`['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` at `routes.php:25`.)

### [MINOR] F-6 — three location queries per unrestricted report request

`isUnrestricted()` runs `activeLocationIds()` internally (`LocationScopeBoundary.php:44`) and
`reportLocationScope()` then runs `activeLocationIds()` **again** plus `allLocationIds()`
(`ReportsController.php:906-907`) — 3 round-trips where 1 (`select id, is_active`) suffices.

### [MINOR] F-7 — `financeSummary` on the same controller applies no location scope at all

`ReportsController.php:927-951` → `FinanceSummaryService` (no `location` occurrence in the file):
the Trésorerie FinanceWidget shows company-wide aged-AR/AP grand totals to a location-restricted user.
Pre-existing and outside this ticket, but it is the fourth surface in the same family and belongs in
the same follow-up as F-2.

### [MINOR] F-8 — no in-branch traceability for the T-5 contract note

The diff touches no docs: the ticket's (a)/(b) sections are not marked resolved and nothing records the
standardized 403 for T-5's consumption. Mitigated by the envelope already being documented at
`docs/conventions/03-AUTHORIZATION.md:54`; a one-line ticket update would close it.

---

## Verified-correct (claims that survived hostile probing)

* **The count heuristic is sound as a set test.** `activeLocationIds()` and `allLocationIds()` differ by
  exactly the `where('is_active', true)` predicate on the same `company_id`
  (`LocationScopeBoundary.php:15-21` vs `:34-38`), so `active ⊆ all` always and
  `count(active) === count(all) ⟺ active == all`. `Location` has **no** `SoftDeletes`
  (`app/Modules/Company/Domain/Location.php:49-56` — `HasFactory`, `HasUuids` only), so there is no
  trashed-row shape that makes the counts agree while the sets differ. `activeLocationIds()` can never
  contain the deactivated id. **Probe 1's "counts match, sets differ" case does not exist** — the
  defect is elsewhere (F-1/F-3).
* **403 envelope is byte-consistent across all four endpoints.** All are unhandled
  `AuthorizationException` → Laravel converts to `AccessDeniedHttpException` → the single render handler
  at `apps/api/bootstrap/app.php:199-216` emits
  `{"error":{"code":"FORBIDDEN","message":<auth.permission_denied_generic>,"ability":null}}`
  (`ability` is null because `LocationScopeResolver.php:42` throws a plain `AuthorizationException`, not
  `PermissionDeniedException`). No resolver text and no `REPORT_GENERATION_ERROR` in the body —
  asserted at `ReportLocationScopeAuthorizationTest.php:96-111`.
* **No sibling swallow.** Scanned every `LocationScopeResolver::resolve()` call site (12 controllers) and
  every `try{…}catch(\Exception|\Throwable)` block in `app/**/Presentation/Controllers/*.php` for an
  enclosed `authorize`/`resolve($user)`/`abort(403)`: **0 hits**. `BatchController:252`,
  `StockLevelController:41`, `StockMovementController:45`, `POS AnalyticsController:183`,
  `POS ReportController:268`, `PaymentInstrumentController:64` all resolve before any try.
* **Tests re-run by this gate.** `CashMovementsReportTest` (27) + `ReportLocationScopeAuthorizationTest`
  (3) = **30 passed / 189 assertions**. Surrounding suites `tests/Feature/Accounting/Reports` +
  `AgedPayablesAutoPoTest` + `Treasury/LocationReconciliationTest` = **63 passed / 270 assertions**.
* **Static gates.** PHPStan (level 8, the 3 changed PHP files): `No errors`. Pint `--test`: `pass`.
  ESLint on the two changed web files: clean.
* **FE.** `AgedPayablesPage.test.tsx` + `AgedReceivablesPage.test.tsx`: **15 passed**. Red-first
  verified — restoring the pre-fix `AgedPayablesPage.tsx` fails the new test at `:175`. i18n key
  `finance:reports.agedPayablesReport.loadError` pre-exists in **both** locales
  (`apps/web/src/locales/en/finance.json:214`, `apps/web/src/locales/fr/finance.json:214`) — no new
  untranslated key. `TreasuryOverviewPage.tsx` already handles both query errors (`:215` cash position,
  `:251-256` upcoming payments) — the "already correct" claim holds.
* **Tenancy.** Nothing in the diff touches the central/tenant connection split, no new `can:` guard, no
  new permission, no module gating change, no money/quantity arithmetic, no `latestOfMany`. Location ids
  reaching a uuid column are `Str::isUuid`-validated upstream by the FormRequests
  (`CashMovementsReportTest::test_a_malformed_location_id_is_rejected_by_validation` is green).

## What to fix before merge

Replace the count-clamp with the ticket's option 1 (`isUnrestricted()` compares against **all** company
locations) so the unrestricted read keeps `[]`/NULL rows and the whole family — including the three
Expense controllers — is fixed at once; then strip `$e->getMessage()` from the report 500 bodies.

---

# Fix-round re-verify — 2026-08-07

**Fix commits:** `2fa18760a` (F-1/F-3 option 1 + F-4), `bc611a45d` (F-2 family proof), `3db20cf15` (F-5), `39b4672fd` (F-8).
**Scope:** narrow re-verification of the eight findings above only. Every probe below was re-executed
against live PostgreSQL in this worktree; probe tests were inserted, run and reverted (`git status`
shows only this untracked review file).

## VERDICT: **CLEAR TO MERGE** — all eight findings closed, no regression found.

One **new, pre-existing** defect surfaced while probing the fix (N-1 below). It is byte-identically
present at the base commit `a1952aa23`, is not introduced or worsened by this branch, and is therefore
**not a merge blocker** — but it is the same `[]`-overload root cause as F-3 and needs its own P1 ticket.

## Finding-by-finding

### F-1 + F-3 — CLOSED. Option 1 taken at the shared source.

`LocationScopeBoundary.php:64-66` now diffs the grant against `allLocationIds()`;
`ReportsController.php:919` is back to the plain `isUnrestricted(...) ? [] : $effective`. The three
original probes, re-run verbatim (unrestricted admin, Shop B inactive, A=10 / B=20 / NULL=5):

```
[PROBE-1] implicit                  200  count=3  in=35.00   (was 10.00)  <- NULL 5.00 restored
[PROBE-1] explicit []=B (inactive)  200  count=1  in=20.00   (unchanged)  <- now agrees with implicit
[PROBE-3] explicit []=A&[]=B        200  count=3  in=35.00   (was 10.00)  <- no silent drop
[PROBE-2] all locations inactive,
          grant = Shop A only       200  count=1  in=10.00   (was 35.00)  <- F-3 fail-open closed
```

`count(active)===count(all)` is gone, so the heuristic question is moot. `allLocationIds()` cannot
collapse to `[]` because a location is inactive, so the all-inactive fail-open is structurally
impossible. Pinning tests re-run: **CashMovementsReportTest 28/28**, including
`test_a_grant_covering_every_location_stays_unrestricted_through_deactivation` (35.00 through
active → inactive → reactivated) and
`test_all_locations_inactive_with_a_partial_grant_stays_restricted_no_leak`.

**The one semantic worth double-checking — explicitly accepted as correct.** Probed directly:

```
[PROBE-4] grant == today's ACTIVE set (Shop B inactive), implicit  200  count=1  in=10.00
[PROBE-4] grant == ALL locations (all active),           implicit  200  count=3  in=35.00
```

A principal whose grant covers exactly the active set is now **restricted**, so their read is an
explicit `location_id IN (granted)` and NULL/unattributed rows are hidden from them. **This is correct
restricted semantics and this gate accepts it**: it is the established convention for a strict
membership scope (`ReportsController.php:896-899`, `CashMovementsReportService.php:97-103`), it matches
what every other restricted principal already got before this branch, and hiding unattributed residue
is the fail-closed direction — the residue's invisibility is ticket (d), already filed, unchanged. The
distinction the fix draws is exactly the right one: *unrestricted* is a property of the grant covering
the company, not of it happening to match today's active set.

**Deployment note (not a defect).** The blast radius includes `CashPosition`, `MaturingInstruments` and
POS analytics: a principal whose membership lists locations explicitly rather than `NULL` and does not
cover an inactive location is now restricted there too, and loses NULL-location (company-level) rows
from those widgets. Fail-closed, so no leak — but before enabling tenant #1, confirm the owner/manager
memberships are `allowed_location_ids IS NULL` (the default written by
`UserController.php:246` and `BackfillMembershipsCommand.php:167`) and not an explicit list, or the
owner's company view will legitimately narrow.

### F-2 — CLOSED, and proven at the shared source with zero Expense-code change.

`bc611a45d` touches only `tests/Feature/Expense/ExpenseAnalyticsTest.php` (`--stat`: 1 file, +34).
Red→green verified by this gate the only way that proves the causal link: reverting **only**
`apps/api/app/Modules/Company/Services/LocationScopeBoundary.php` to its pre-fix (`d8356921f`) content
makes `test_a_deactivated_out_of_grant_location_does_not_leak_into_unscoped_analytics` **FAIL**
("Failed asserting that two strings are identical", 140.00 vs 100.00); restoring it makes it pass. Since
`ExpenseController.php:80`, `ExpenseAnalyticsController.php:33` and `ExpenseExportController.php:52`
route through that one method, the list and CSV-export surfaces are closed by the same change.

Sibling spot-check with the new `isUnrestricted()`: `CashPositionEndpointTest` +
`MaturingInstrumentsTest` + `LocationReconciliationTest` + `LocationScopeResolverTest` = **27 passed /
136 assertions**; `POS/AnalyticsTest` + `POS/ZReportListTest` = **29 passed / 126 assertions**. Their
`whereIn(...) [+ orWhereNull if unrestricted]` shape (`CashPositionController.php:87-93`,
`MaturingInstrumentsController.php:63-70`) is unchanged and still reconciles Σ(buckets) == grand_total.

### F-4 — CLOSED, and live-fire tested rather than merely inspected.

No `$e->getMessage()` interpolation remains at the four sites: `ReportsController.php:786, 849, 891, 966`
now carry a static message, with the detail logged server-side at `:777, :840, :882, :957` (matching the
file's pre-existing `\Log::error` pattern at `:370, :526, :676` — a facade, consistent with the
surrounding code, not an `app()` resolution).

The "can't live-fire because the services are final" objection is beatable: rename the tables the
reports query inside the request (DDL is transactional in PG, so `RefreshDatabase` rolls it back), which
raises a real `PDOException` **inside** each `try`. Result — all four:

```
[PROBE-F4] /api/v1/reports/aged-receivables   500  {"error":{"code":"REPORT_GENERATION_ERROR","message":"Failed to generate aged receivables report. Please try again or contact support."}}
[PROBE-F4] /api/v1/reports/aged-payables      500  {... "Failed to generate aged payables report. ..."}
[PROBE-F4] /api/v1/reports/upcoming-payments  500  {... "Failed to generate upcoming payments report. ..."}
[PROBE-F4] /api/v1/reports/finance-summary    500  {... "Failed to generate finance summary report. ..."}
```

Asserted absent from every body: `SQLSTATE`, `select `, the probe table name, `PDO`. Clean.
(The catch is still `\Exception` rather than `\Throwable`, and 11 pre-existing bare `$e->getMessage()`
sites remain elsewhere in the file — both explicitly out of this round's scope and ticketed.)

### F-5 — CLOSED. `ReportLocationScopeAuthorizationTest` now 6 tests: the three DENY cases plus
`test_{aged_receivables,aged_payables,upcoming_payments}_allows_an_in_scope_location_request`
(`assertOk()` on the principal's own granted location), and `setUp()` seeds
`RolesAndPermissionsSeeder` instead of fabricating the permission. Both halves of the gate are now real.

### F-6 — CLOSED by inspection. `reportLocationScope()` (`:911-920`) makes no boundary query of its own;
`isUnrestricted()` (`:64-66`) makes exactly one (`allLocationIds`). Three location round-trips → one
(two per request including `LocationScopeResolver`'s own pre-existing `allCompanyLocationIds`).

### F-7 — correctly deferred (pre-existing, ticketed).

### F-8 — CLOSED. `docs/conventions/03-AUTHORIZATION.md:66` states the contract T-5 needs: the four
endpoints named, the exception left unhandled *outside* any `try`/`catch`, and the literal envelope
`{"error":{"code":"FORBIDDEN","message":<i18n auth.permission_denied_generic>,"ability":null}}`, plus
the forward-looking instruction for new location-scoped endpoints. Sufficient to discriminate on.

## New (pre-existing, NOT a blocker) — file a P1 ticket

### [N-1] An **empty** location grant (`allowed_location_ids = []`) reads as *unrestricted* on reports and expenses

`reportLocationScope()`'s restricted branch returns `$effective` verbatim, and an empty grant makes
`$effective === []` — which every consumer reads as "no predicate, company-wide".

```
[PROBE-5] membership allowed_location_ids = [], implicit cash-movements read
          200  count=3  in=35.00      # the ENTIRE company, for a principal granted ZERO locations
```

Granting the narrowest possible scope therefore yields the widest read. The intended semantic is the
opposite and is pinned elsewhere: `tests/Feature/Company/LocationScopeResolverTest.php:108-113`
(`test_absent_membership_resolves_to_deny_all` — resolver returns `[]` meaning *deny all*) and
`tests/Feature/POS/ZReportListTest.php:84-92` (`allowed_location_ids = []` → `assertJsonCount(0,'data')`,
fail-closed, because POS always applies `whereIn`). Same membership state, opposite answers on POS vs
reports/expenses. The state is writable: `CreateUserRequest.php:53-54` / `UpdateUserRequest.php:61-62`
validate `['sometimes','nullable','array','list']` with **no `min:1`**, and
`UserController::authorizeLocationGrant` passes `[]` (`array_diff([], $callerAllowed) === []`) straight
into `writeLocationGrant()` (`:923-928`).

**Verified pre-existing:** `git show a1952aa23:…/ReportsController.php:867-876` is byte-identical to the
post-fix version, as is `a1952aa23:…/ExpenseController.php:80`. This branch neither introduces nor
worsens it. **Fix shape:** stop overloading `[]`; return a nullable/sentinel scope (or make the
consumers apply `whereIn([])` = deny-all like POS) and add `min:1` to the grant validation.

## Gates re-run this round

CashMovementsReportTest 28 + ReportLocationScopeAuthorizationTest 6 + ExpenseAnalyticsTest 12 =
**46 passed / 272 assertions**. Treasury/Company sweep **27 passed / 136 assertions**. POS sweep
**29 passed / 126 assertions**. PHPStan level 8 on both changed production files: `No errors`.
Pint `--test` on all five touched files: `pass`. FE untouched this round (15 passed previously,
red-first already verified).

## What to do before merge

Nothing blocking. Merge, then ticket **N-1** (`[]`-as-unrestricted overload, P1, same root cause as
F-3), and carry the deployment note above (confirm tenant #1's owner/manager memberships are
`allowed_location_ids IS NULL`) into the staging runbook.
