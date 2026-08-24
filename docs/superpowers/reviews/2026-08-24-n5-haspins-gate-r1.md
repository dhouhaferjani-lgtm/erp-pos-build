# N-5 gate review r1 — `fix/campaign-n5-haspins`

**Reviewer:** tenancy-authz-reviewer (adversarial, code-grounded) · **Date:** 2026-08-24
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n5-haspins` · branch tip `022a1b543`
**Diff reviewed:** `git diff dev...HEAD` — 3 files: `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php`,
`apps/api/tests/Feature/POS/PosAuthHasPinsTest.php` (new), `docs/superpowers/reviews/2026-08-24-n5-handback.md` (doc).
**No migration, no route change, no permission change, no money/quantity code, no frontend change** — verified from the diffstat.

## VERDICT: **ACCEPT** (spec ✅ / quality APPROVED). All findings below are Minor / follow-up; none blocks merge.

---

## 1. Requirement (1) — the three surfaces admit exactly the same population — **VERIFIED**

Single definition, `PosAuthController.php:46-57`:

```php
private function pinHolders(string $tenantId, string $companyId): Builder
{
    $companyUserIds = UserCompanyMembership::query()
        ->where('company_id', $companyId)
        ->where('status', MembershipStatus::Active->value)
        ->pluck('user_id');

    return User::where('tenant_id', $tenantId)
        ->where('status', UserStatus::Active->value)
        ->whereIn('id', $companyUserIds)
        ->whereNotNull('pos_pin');
}
```

Every caller read:

| Surface | Line | Company source | Call |
|---|---|---|---|
| `verifyPin` | `:85-87` | `companyContext->requireCompany()` | `pinHolders($currentUser->tenant_id, $company->id)->get()` |
| `pinData` | `:176`, `:206` | `companyContext->requireCompany()` | `pinHolders($currentUser->tenant_id, $company->id)->get()` |
| `hasPins` | `:342-344` | `companyContext->requireCompany()` | `pinHolders($currentUser->tenant_id, $company->id)->exists()` |

All three resolve the company the same way, pass the same two arguments, and share one predicate set. The
`:80-84` parity comment in `verifyPin` is now true. The **fourth** PIN surface, `PinVerifier::verifyForApproval`
(`apps/api/app/Modules/POS/Application/Services/PinVerifier.php:44-71`), enforces the identical four predicates
inline (tenant `:44`, `isActive()` `:56`, ACTIVE membership in company `:63-70`, `pos_pin !== null` `:44`) — the
offline-approval lane is consistent with the new helper, so nothing was left behind at that end either.

## 2. Requirement (2) — `pinData()` refactor is behaviour-identical — **VERIFIED**

`git show dev:…/PosAuthController.php` pre-change `pinData` ran:

```php
$companyUserIds = UserCompanyMembership::query()->where('company_id', $company->id)
    ->where('status', MembershipStatus::Active->value)->pluck('user_id');
$operators = User::where('tenant_id', $currentUser->tenant_id)
    ->where('status', UserStatus::Active->value)
    ->whereIn('id', $companyUserIds)->whereNotNull('pos_pin')->get();
```

Token-for-token the same four predicates, same argument sources, same `->get()` terminal, same ordering (none in
either version). `verifyPin`'s pre-change block was likewise identical. The refactor is a pure extraction; the only
behavioural delta in the whole diff is `hasPins` (`dev`: `User::where('tenant_id', …)->whereNotNull('pos_pin')->exists()`).

Guarded empirically as well: with the account-status belt deleted from the shared helper, the **pre-existing**
`PinDataEndpointTest::test_pin_data_excludes_deactivated_users_with_active_memberships` and
`…excludes_pending_verification_users` both go red (tamper B below) — the refactor sits under live pin-data coverage.

## 3. Requirement (3) — tests discriminate — **VERIFIED by three tampers**

Runs were performed on a byte-identical scratch copy of `apps/api` (`cp -a` of the tree + `cp -al` hardlinked
`vendor/`) so the review worktree was never modified. `ReflectionClass(PosAuthController)->getFileName()` resolves
inside the scratch copy (checked), so the tampers were genuinely exercised.

- **Baseline, 4 files by path, `CACHE_STORE=array`:** `OK (28 tests, 87 assertions)` — reproduces the handback's green.
- **Tamper A — delete `->whereIn('id', $companyUserIds)` (company/membership predicate) from `pinHolders`:**
  2 red — `…belongs_to_another_company` (`PosAuthHasPinsTest.php:214`), `…has_no_membership_anywhere` (`:232`).
- **Tamper B — delete `->where('status', UserStatus::Active->value)` (account belt):**
  3 red — `…deactivated_with_a_stale_active_membership` plus the two pre-existing `PinDataEndpointTest` cases.
- **Tamper C — restore `dev`'s tenant-only `hasPins` body:** exactly 4 red, the same four test names and count the
  handback reports as its RED evidence (`Tests: 28, Assertions: 85, Failures: 4`). The three pre-existing POS-auth
  files stay green under C, confirming the new file is the sole discriminator for the N-5 behaviour.

Test quality: real models + `RefreshDatabase` + `RolesAndPermissionsSeeder` (`PosAuthHasPinsTest.php:16,36,68`), no
mocks, no fabricated payloads; the requesting user (`:73-87`) holds **no** PIN, so no test can pass by accident from
the caller's own row; one test cross-asserts the pin-data parity invariant directly (`:144-146`).

## 4. Requirement (4) — no other consumer on the old population — **VERIFIED**

Exhaustive grep of `apps/api`, `apps/pos/src`, `apps/web/src` for `has-pins|hasPins|has_pins`:
- server: only the route `apps/api/app/Modules/POS/routes.php:46` and the new test;
- device: one call site, `apps/pos/src/stores/operatorStore.ts:376-400`, invoked only from
  `apps/pos/src/stores/bootstrapStore.ts:199`, whose `canEnterPhase('checking-pins')` gate
  (`bootstrapStore.ts:257-264`) requires `companyId !== null` — so `X-Company-Id` (`apps/pos/src/lib/api.ts:75-79`)
  is always sent, i.e. `has-pins` and the later `pin-data` pull answer for the *same* company. No new
  company-mismatch class is introduced on the bootstrap path;
- `apps/web`: no consumer at all.

Route middleware unchanged and compliant with rule 12: `routes.php:42` =
`['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`. Authorisation unchanged
(`Gate::authorize('pos.operate_terminal')`, `:338`); that permission is seeded (`RolesAndPermissionsSeeder.php:343`)
and granted to roles (`:597`, `:664`) — **no seeder re-sync is owed by this lane.**

Tenancy: the helper keeps the explicit `tenant_id` predicate (cluster invariant) and adds a company predicate; no
raw cross-tenant join, no row-level-scoping assumption, no central/tenant connection change. Nothing in the diff
runs in a queue/console context.

## 5. Requirement (5) — the `requireCompany()` 500-exposure claim — **SUBSTANTIALLY TRUE, one nuance**

`CompanyContext::requireCompany()` (`apps/api/app/Modules/Company/Services/CompanyContext.php:96-106`) throws when
no company is bound *or* the company row is not found. In practice the "not bound" branch is unreachable on this
route: `CompanyContextMiddleware` runs in the `api` group (`apps/api/bootstrap/app.php:139-143`) and returns
**403 `NO_COMPANY_ACCESS`** before the controller when no company resolves, and 403 `COMPANY_ACCESS_DENIED` when the
requested company has no ACTIVE membership. `pinData` on the same route group already calls `requireCompany()`
(`:176`), so the claim "same exposure as pin-data" holds. See finding 1 for the one residual delta.

## 6. Requirement (6) — PHPStan / Pint — **VERIFIED**

- `./vendor/bin/phpstan analyse app/Modules/POS/Presentation/Controllers/PosAuthController.php --memory-limit=2G` → `[OK] No errors` (level 8, `phpstan.neon:8`).
- Same on `tests/Feature/POS/PosAuthHasPinsTest.php` → `[OK] No errors` (tests are outside `paths:`, analysed explicitly).
- `./vendor/bin/pint --test` on both touched files → `{"result":"pass"}`.
- Review worktree `git status --porcelain` empty before and after — nothing was modified by this review.

## 7. Findings

1. **[Minor]** `PosAuthController.php:342` — `hasPins` acquires a 500 failure mode it did not have on `dev`:
   `Company` is soft-deleting (`apps/api/app/Modules/Company/Domain/Company.php:31,135`), so a soft-deleted company
   with a surviving ACTIVE membership passes `CompanyContextMiddleware` but makes `requireCompany()`
   (`CompanyContext.php:99-104`) throw. Handback §5's "not new exposure" is right at the class level (`pinData:176`
   already throws there, and every other company-scoped POS route would be equally dead in that state) but is not
   literally true of this endpoint. Blast radius is contained: `operatorStore.checkHasPins`
   (`apps/pos/src/stores/operatorStore.ts:387-399`) catches and falls back to the SQLite operator cache.
   *No change required — recorded so the parent does not inherit the claim unqualified.*
2. **[Minor]** `apps/api/tests/Feature/POS/PosAuthHasPinsTest.php:90-113` — the only positive case has a single
   active holder, so an **over-narrowing** regression (e.g. someone adding a stray predicate) is caught by no test.
   Suggested: one case with an offboarded holder *and* an active holder in the same company asserting
   `has_pins === true`. Also absent: the authz deny path (`pos.operate_terminal`-less user → 403) for `has-pins`.
   *Fix: add the mixed-population true case; deny-path is a pre-existing suite gap, optional here.*
3. **[Minor]** `PosAuthController.php:48-56` — `pluck()`-then-`whereIn()` materialises every active member id into
   PHP on each call; `hasPins` used to be a single `EXISTS`. Harmless at current company sizes, but a
   `whereExists()` correlated subquery would keep the helper O(1) in transferred rows for all three callers.
   *Fix (optional): rewrite the helper as a `whereExists` subquery — behaviourally identical, keeps the extraction.*
4. **[Minor / follow-up, correctly deferred by the brief]** `PosAuthController.php:133-144` — `setupPin`'s
   uniqueness scan is tenant-wide and unscoped (includes deactivated users and other companies). This is now
   slightly more reachable, because `has_pins=false` routes the device to `PinSetupPage`
   (`apps/pos/src/App.tsx:323-325`), where a collision with a ghost PIN yields "This PIN is already used by another
   user." The user can pick another PIN, so it is a nuisance, not a lockout. Confirmed no privilege issue:
   `setupPin` writes only `$request->user()`'s own row (`:146`).
   *Fix: schedule with the handback's residual #1 (`pos_pin` is never cleared on offboarding — verified: the only
   clearing site is the explicit `UserController.php:704` endpoint, not the deactivate/revoke cascade).*
5. **[Minor, informational, pre-existing — NOT introduced here]** `apps/api/app/Http/Middleware/CompanyContextMiddleware.php:99-104`
   → `CompanyContext::userHasAccessToCompany()` (`CompanyContext.php:148-151`) binds the raw `X-Company-Id` header
   into a PostgreSQL `uuid` column comparison with no `Str::isUuid()` guard — a malformed header 500s on every
   `api` route, not just this one. Out of this lane's scope; flagged for the platform backlog.
6. **[Minor, cosmetic]** `PosAuthController.php:192-205` — the merged comment block still opens with the F-3
   sentence phrased as if the query were inline ("keeping it in lockstep with…"), then explains the extraction. It
   reads as two stitched paragraphs. *Fix (optional): collapse to one paragraph pointing at `pinHolders()`.*

## 8. Device-side residuals confirmed accurate (out of scope, for the sync lane)

`apps/pos/src/lib/sync/syncService.ts:1320` — `pruneOperatorsExcept` is gated on `operators.length > 0`, so an
**empty** `pin-data` response never prunes. A terminal whose last operator is offboarded keeps the stale cached PIN
and can still authenticate offline, while the server now correctly answers `has_pins=false`. The handback states
this correctly; it is device behaviour the brief excluded.

**What to fix before merge: nothing.** Optionally fold finding 2's mixed-population `true` test into this branch;
findings 1/4/5 are register entries for the parent, not merge blockers.
