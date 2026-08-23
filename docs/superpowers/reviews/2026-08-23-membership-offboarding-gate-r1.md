# Adversarial merge gate — round 1
## Lane `fix/membership-offboarding-pin-revocation` (`f1e680e18` + `cb5d0456f` on base `30001a187`)

Reviewer: tenancy-authz-reviewer (adversarial, code-grounded). Read-only on the lane worktree;
every claim below was re-derived from files I opened and commands I ran. Runs were executed in
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/membership-offboarding` and in an isolated
scratch copy (never in the lane worktree, never in the main checkout).

---

## 0. Class-resolution proof (before anything else)

`vendor/` in the worktree is a REAL directory (86 entries, `drwxr-xr-x`), not a symlink:

- `ReflectionClass(Illuminate\Support\Str)` →
  `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/membership-offboarding/apps/api/vendor/laravel/framework/src/Illuminate/Support/Str.php`
- `ReflectionClass(App\Modules\Company\Domain\UserCompanyMembership)` →
  `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/membership-offboarding/apps/api/app/Modules/Company/Domain/UserCompanyMembership.php:41`

Both vendor and app classes resolve to the WORKTREE. The lane's claim is accurate.

**Trap I hit and corrected (recorded so nobody repeats it):** my first red-first harness rsync'd
`apps/api` to scratch and *symlinked* `vendor` back at the worktree. Every test passed with the
belts reverted — because `vendor/composer/autoload_psr4.php` computes `$baseDir` from `__DIR__`,
which PHP resolves through the symlink, so the run loaded the WORKTREE's `app/` classes, not the
reverted ones. I re-ran with a real cloned `vendor` (`cp -Rc`) and verified resolution
(`PinVerifier` → scratch path, belt text absent) before trusting a single red/green number below.

---

## 1. The cascade

**Atomicity — VERIFIED.** All three account-status writes are inside a `DB::transaction` closure and
the cascade is inside the same closure, after the `save()`:

- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:470` `destroy()` opens the transaction; `:475-476` flips `users.status`; `:483` calls `revokeMembershipsFor()`.
- `:629` `deactivate()` opens the transaction; `:633-634` flips status; `:640` cascades.
- `:547` `activate()` opens the transaction; `:548-549` flips status; `:552` calls `restoreCascadedMembershipsFor()`.

Both tables are TENANT-scoped, so both writes land on the same (swapped-default) connection and one
transaction really does cover them: `users` lives at `apps/api/database/migrations/tenant/2025_11_30_000003_create_users_table.php`
(no root-tier copy exists) and `user_company_memberships` at
`apps/api/database/migrations/tenant/2025_11_30_106000_create_user_company_memberships_table.php`.
A crash between the two writes cannot leave a deactivated user with Active memberships.

Two writes *inside* the same closure are NOT covered by that transaction, both PRE-EXISTING and both
failing in the safe direction — noted for completeness, not as lane defects:
- `$user->tokens()->delete()` (`:472`, `:631`) hits the CENTRAL DB (`apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:31-41`, `use CentralConnection`). A tenant rollback leaves the tokens deleted — fail-safe.
- `identityIndexService->remove()` (`:487`, `:645`) also writes central; the cross-DB ordering contract is documented at `apps/api/app/Modules/Tenant/Application/Services/IdentityIndexService.php:26-29`. Unchanged by this lane.

**`destroy()` covered — VERIFIED** (`:483`), and proven by
`tests/Feature/Identity/UserManagement/UserOffboardingCascadeTest.php:189-203`.

**Active-only sweep is security-complete — VERIFIED by exhaustive re-derivation.** I enumerated every
membership-status read in `app/` and every membership-existence check reachable from an authz path.
All of them require `active`; NOTHING honours `Pending` or `Suspended`, so leaving those rows alone
grants nothing:

- `apps/api/app/Modules/Company/Services/CompanyContext.php:129` (`getDefaultCompanyForUser`) and `:150` (`userHasAccessToCompany`)
- `apps/api/app/Modules/Identity/Domain/User.php:317-320` and `:345-348` (company access + broadcast channel; both also gate on `isActive()` already)
- `apps/api/app/Modules/POS/Application/Services/PinVerifier.php:63-66`
- `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:162-165`
- `apps/api/app/Modules/POS/Presentation/Controllers/AuthorizedManagersController.php:45-48`
- `apps/api/app/Modules/Accounting/Presentation/Concerns/RequiresCompanyAccess.php:44-47`
- `apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerReportScope.php:109-112`
- `apps/api/app/Modules/Treasury/.../TreasuryDepositBridge.php:432-435`, `TreasuryAccountPaymentBridge.php:457-460`, `DepositReferenceResolutionService.php:186-189`, `PaymentAllocationService.php:444-447`
- `apps/api/app/Http/Controllers/Api/CompanyConfigController.php:61-63`
- `apps/api/app/Modules/Identity/Application/Listeners/SendEnrichmentNotificationListener.php:19-21`
- `apps/api/app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:217-219`
- `apps/api/app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php:36`

The lossless-reverse-edge rationale at `UserController.php:966-973` is therefore sound, not a
hand-wave.

**Provenance stamping — VERIFIED.** `revokeMembershipsFor()` (`UserController.php:976-989`) writes
`status=revoked`, `revoked_at=now()`, `revoked_by=$actor->id`, `revoked_reason=user_deactivated`,
filtered to `status = active` and `user_id = $user->id`. Asserted at
`UserOffboardingCascadeTest.php:145-164` and the "other users untouched" negative at `:205-218`.

---

## 2. The reactivation rule

`restoreCascadedMembershipsFor()` (`UserController.php:1007-1020`) restores only
`status = revoked AND revoked_reason = user_deactivated`, clearing all three stamps.

**Attack (a) — independently-revoked row is NOT resurrected: VERIFIED**, covered by
`UserOffboardingCascadeTest.php:253-287` (companyB revoked with `revoked_reason = NULL`, then
deactivate→activate; companyA restores, companyB stays Revoked). Green in my run.

**Attack (b) — idempotency: VERIFIED by my own probe** (scratch copy, lane code unmodified —
I diffed `app/` scratch-vs-worktree byte-identical before running):

```
[PROBE b] destroy-after-deactivate status=200
[PROBE b] after destroy: status=revoked reason=user_deactivated   (x2 — no re-stamp, no loss)
[PROBE b] after activate: status=active revoked_at=NULL           (x2)
```
Second `deactivate` → 422 `USER_ALREADY_INACTIVE` (guard at `UserController.php:619-627`); second
`activate` → 422 `USER_ALREADY_ACTIVE` (`:537-545`). `destroy()` has no already-inactive guard, but
re-running it is a genuine no-op because the sweep is filtered to `status = active`. Chain is clean.

**Attack (c) — can an admin-facing endpoint write `revoked_reason = user_deactivated` and thereby
make an intentional revocation auto-reversible? NO — VERIFIED.** Two independent greps over `app/`:
- The only writes of `MembershipStatus::Revoked` anywhere are `UserController.php:982` (the cascade). No membership-revoke endpoint exists at all.
- The only writes of `revoked_reason` anywhere are `UserController.php:985` (set) and `:1017` (clear).

So the rule is sound today. **Residual (Minor, F-6 below):** the lane added `revoked_at`,
`revoked_by`, `revoked_reason` to `$fillable` (`apps/api/app/Modules/Company/Domain/UserCompanyMembership.php:65-67`).
The day someone adds a membership-management endpoint that mass-assigns validated input, a client
could stamp `revoked_reason = user_deactivated` on a deliberate revocation and make it
auto-reversible. Cheap to pre-empt.

---

## 3. The belts

Four belts, all re-derived at source:

| # | Surface | Belt | Line |
|---|---|---|---|
| 1 | `PinVerifier::verifyForApproval` (offline + online approval) | `if (! $user->isActive())` → `ScopeMismatch` | `apps/api/app/Modules/POS/Application/Services/PinVerifier.php:54` |
| 2 | `POST /pos/auth/verify-pin` (online operator switch) | `->where('status', UserStatus::Active->value)` | `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:50` |
| 3 | `GET /pos/auth/pin-data` (device roster) | same | `PosAuthController.php:175` |
| 4 | `GET /pos/authorized-managers` | same | `apps/api/app/Modules/POS/Presentation/Controllers/AuthorizedManagersController.php:44` |

Route middleware is rule-12 compliant on both groups I touched:
`apps/api/app/Modules/POS/routes.php:42` and `apps/api/app/Modules/Identity/routes.php:57`
(`['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`). No new
`can:` guard and no new permission — nothing to sync into `RolesAndPermissionsSeeder.php`.

**Red-first evidence RE-RUN AND CONFIRMED (5 red → green).** Scratch copy with a real cloned vendor,
belts reverted to `30001a187`, everything else at lane HEAD:

```
Tests: 36, Assertions: 108, Failures: 5, Risky: 1
1) PosAuthVerifyPinTest::test_verify_pin_rejects_a_deactivated_users_pin
2) PinDataEndpointTest::test_pin_data_excludes_deactivated_users_with_active_memberships
3) ManagerPinControllerTest::test_deactivated_manager_cannot_approve_even_with_an_active_membership
   (valid: true → expected false)   ← this is the one that proves the ACTUAL privilege
4) AuthorizedManagersControllerTest::test_deactivated_user_excluded_even_with_active_membership
5) PinVerifierTest::test_scoped_verification_rejects_a_deactivated_user_account
```
Exactly 5, exactly the 5 new belt tests. With the belts restored: `OK (27 tests, 98 assertions)` for
the four POS feature classes and `OK (17 tests, 52 assertions)` for cascade + PinVerifier.

Cascade half, `UserController` reverted (migration/model/enum kept): `Tests: 8, Failures: 4` —
`test_deactivating_a_user_revokes_every_active_membership`, `..._stamps_revocation_provenance`,
`test_deactivated_user_loses_company_access`, `test_destroy_revokes_every_active_membership`.

So of the 13 new tests, **9 are genuinely red-first**, not 13 — see F-4.

**OFFLINE approval path unchanged — VERIFIED, and the D-1 residual write-up is accurate except for a
stale file path.** The device pull + prune is
`apps/pos/src/lib/sync/syncService.ts:1301-1322` (`pullOperatorPins` → `upsertOperators` →
`pruneOperatorsExcept` gated on `operators.length > 0`), NOT `apps/pos/src/services/syncService.ts`
as the write-up cites — that path does not exist. The online window claim is correct:
`apps/pos/src/lib/sync/syncScheduler.ts:11-12` `BASE_INTERVAL_MS = 60_000`,
`MAX_INTERVAL_MS = 5 * 60_000` → 1–5 minutes; a terminal that never completes a non-empty pull never
prunes, so offline is unbounded. No device code was touched by the lane (scope audit §8).

---

## 4. Findings #1 and #2 flagged by the lane

### Finding #1 — CONFIRMED, and MATERIALLY BROADER THAN THE LANE CLAIMED. Prescribed as a fix-round item.

`PosAuthController::verifyPin` filters on `tenant_id` + `status` + `pos_pin IS NOT NULL`
(`PosAuthController.php:49-52`) and never consults `user_company_memberships` — unlike `pinData`
(`:162-165`) and `PinVerifier` (`:63-66`). I proved it with a live probe (lane code, unmodified):

- **Cross-company:** a manager whose ONLY membership is in company B, PIN entered on a company-A terminal → **HTTP 200**, full payload: `{"id":…,"name":"Company B Only","roles":["manager"],"permissions":[… "pos.approve_discount_limit_override","pos.approve_void_or_return_override","pos.approve_cash_drawer_control","pos.close_shift_with_variance" …]}`.
- **Worse — no membership at all:** a tenant user with ZERO `user_company_memberships` rows → also **HTTP 200** with the same manager permission set.

That second case is not in the lane's write-up. The endpoint is the online operator switch, so this
hands a non-member the operator identity, role names and the complete override-permission list for a
company they do not belong to. It is the same hole class the lane exists to close, on a surface the
lane already edited. (For context: the route carries no throttle —
`apps/api/app/Modules/POS/routes.php:44` — unlike `/pos/terminals/claim` at `:57`; PINs are 4–6 digits
and unique tenant-wide, so the endpoint is also an enumeration oracle. Authenticated-only, so this is
context, not the headline.)

**Prescribed fix (fix round):** in `verifyPin`, scope candidates to ACTIVE members of the
`CompanyContext` company exactly as `pinData` does — pluck `user_id` from
`UserCompanyMembership::where('company_id', $company->id)->where('status', 'active')` and add
`->whereIn('id', $companyUserIds)` at `PosAuthController.php:49-52`. Add two red-first tests
mirroring my probes (cross-company member → 422 `INVALID_PIN`; zero-membership tenant user → 422).

**Related, same class, same fix round (Minor):** `UserController::setPosPin`
(`UserController.php:687-689`, `:720-723`) resolves and mutates the target user by `tenant_id` only —
an admin in company A can set or clear the POS PIN of a user who belongs solely to company B. It also
happily arms a PIN on a DEACTIVATED account (harmless now, because all four belts filter status).
Consider the same company-membership scoping.

### Finding #2 — the lane's description is INACCURATE; downgrade, do not carry it as written.

The write-up says `POST /pos/auth/sync-pins` "writes `pos_pin` hashes for any tenant user via
`DB::table` bypassing casts". It cannot. A **pre-existing SELF-ONLY guard**
(`PosAuthController.php:248-265`; present at base — `git show 30001a187:…PosAuthController.php`
line 234 carries the same `SELF-ONLY` block) rejects any `user_id` other than the caller's before a
single write happens. The `DB::table` cast-bypass at `:277-279` is real and deliberate (`:274-276`),
but the only row it can reach is the authenticated caller's own.

Residual is nil in practice: a deactivated account cannot call it — `deactivate()`/`destroy()` delete
all tokens (`UserController.php:472`, `:631`) and login refuses non-Active accounts
(`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:264` → `User::isActive()`
at `User.php:260-263`). "Harmless now" is the right conclusion; the stated reason is wrong.
**Prescription: correct the write-up.** An optional belt (`refuse when the caller is not Active`) is
defensible defense-in-depth but is NOT required — do not spend the fix round on it.

---

## 5. Migration

`apps/api/database/migrations/tenant/2026_08_23_100000_add_revocation_tracking_to_user_company_memberships.php`

- **Tier correct:** `database/migrations/tenant/` — the table is tenant-scoped. **Ordering correct:** it sorts last in that directory (previous latest `2026_08_21_140000_backfill_chart_required_purposes_o27.php`).
- **Additive + self-guarding:** `Schema::hasTable` at `:35`, per-column `Schema::hasColumn` at `:39/:43/:47`; `down()` mirrors at `:56-64`. No FK on `revoked_by`; the SQLite rationale at `:29-33` is accurate (SQLite cannot add an FK to an existing table) and the audit-stamp-over-join-key trade-off is stated.
- **PG idempotency PROVEN on a real PostgreSQL 16 instance** (scratch DB `gate_r1_probe`, created and dropped by me; the table DDL was cloned from the live tenant DB `tenant019fe276-…`, seeded with one row):

```
DB: gate_r1_probe
run1 cols: revoked_at,revoked_by,revoked_reason
run2 (idempotent) cols: revoked_at,revoked_by,revoked_reason
run3 OK
revoked_at     => timestamp without time zone nullable=YES
revoked_by     => uuid                        nullable=YES
revoked_reason => character varying           nullable=YES len=40
rowcount preserved: 1
after down: <none>|end ; down twice OK ; up with table absent = no-op OK
```
Three consecutive `up()` calls, a `down()`, a second `down()`, and an `up()` against a dropped table —
all clean, no data loss, correct PG types. `revoked_by` is a genuine `uuid` column, and the only
writer supplies `$actor->id` (a UUID), so the PG uuid-comparison 500 trap does not apply.

> **MIGRATION-BEARING — for the promotion checklist.** This lane adds ONE tenant migration. Pushing to
> `origin/dev` auto-deploys and runs `tenants:migrate`; the migration is self-guarding so a re-run or a
> tenant that already has the columns is a no-op. No backfill is required: legacy rows keep
> `revoked_reason = NULL` and are deliberately fail-closed on the reactivation edge.

---

## 6. Inherited reds — honesty check

**The lane's inherited-red claim is HONEST and I reproduced it exactly.**
`php apps/api/tools/feature-lane-manifest-check.php`:

- At the lane's base worktree (`.worktrees/ob-hardening`): **EXIT=1** — `Document 76 > 75`, `POS 145 > 143`, `gated 1137 > 1134`.
- In the lane worktree: **EXIT=1** — same `Document 76 > 75`, same `POS 145 > 143`, `gated 1138 > 1135` (the lane raised the gated ceiling by exactly its own +1 and touched nothing else).
- On current `dev` (`3376dd9bf`): **EXIT=0** — green. `c106fb809` did reconcile those two.

`CreateUserTest` red **proven pre-existing**: `tests/Feature/Identity/UserManagement/` in the lane
worktree = `Tests: 117, Failures: 1, Skipped: 1`, the single failure being
`test_store_creates_null_membership_for_new_staff` (expects `Cashier`, gets `Viewer`). The lane
touches neither the test file (`git diff --stat` on it = 0 lines) nor the producing line — `store()`
hardcodes `MembershipRole::Viewer` identically at base
(`git show 30001a187:…UserController.php` line 245). Same failure reproduces at an unrelated commit.

### F-2 — MANIFEST MERGE WILL CONFLICT, AND EVERY NAIVE RESOLUTION TURNS DEV RED

This is the one mechanical blocker.

- Lane: `gated_ceiling` **1134 → 1135**, `groups.Identity.classes` **31 → 32** (`apps/api/tests/feature-lane-manifest.json`).
- Dev since the lane's base: `gated_ceiling` **1134 → 1141**, `groups.POS.classes` **143 → 147**.
- `git merge-base 3376dd9bf cb5d0456f` = `30001a187` → both sides edited the SAME `gated_ceiling` line from the same ancestor. **Textual conflict is guaranteed.**
- And dev has **ZERO headroom**: its own checker prints `PARKED BEHIND AN EXECUTION GATE: 70 group(s) / 1141 class(es)` against a ceiling of exactly `1141`. Taking "ours" (1141) or "theirs" (1135) both fail `gated_classes > gated_ceiling` at `apps/api/tools/feature-lane-manifest-check.php:810-816`.

**Prescribed resolution (must be done at merge, verify with a checker run):** rebase/merge onto current
`dev`, then set `gated_ceiling = 1142` and KEEP `groups.Identity.classes = 32` with the lane's
deliberate-raise note; leave dev's `groups.POS.classes = 147` untouched. Then
`php apps/api/tools/feature-lane-manifest-check.php` must exit 0.

### F-3 — the new security-regression class runs NOWHERE in CI (transparency, must be stated on the promotion checklist)

The lane's own manifest note concedes it: the `Identity` group's lane
(`feature-lane-tenancy/Identity`) is "PARKED behind `vars.SELF_HOSTED_RUNNER_READY` until the owner
registers the self-hosted runner". So `UserOffboardingCascadeTest` — the regression test for a
launch-relevant privilege hole — is proven only by local by-path runs (mine above) and will not run in
CI until the owner flips that flag. Say so out loud in the promotion checklist rather than letting
"13 tests added" imply CI coverage.

---

## 7. Runs (all commands executed by me)

| Run | Result |
|---|---|
| `phpunit tests/Feature/Identity/UserManagement/UserOffboardingCascadeTest.php tests/Unit/POS/PinVerifierTest.php` | **OK (17 tests, 52 assertions)** |
| `phpunit tests/Feature/POS/{PosAuthVerifyPinTest,PinDataEndpointTest,ManagerPinControllerTest,AuthorizedManagersControllerTest}.php` | **OK (27 tests, 98 assertions)** |
| Red-first, belts reverted (scratch, real vendor) | **5 failures — exactly the 5 belt tests** |
| Red-first, `UserController` reverted (scratch) | **4 failures of 8 cascade tests** |
| `tests/Feature/Identity/UserManagement/` (whole dir) | 117 tests, **1 failure = pre-existing `CreateUserTest`** |
| PG migration up×3 / down×2 / up-with-table-absent (PostgreSQL 16, scratch DB) | **clean, idempotent, types correct, no data loss** |
| `pint --test` on all 8 changed PHP files | `{"result":"pass"}` |
| `phpstan analyse` on the 6 changed production files (level 8, project config) | `[OK] No errors` |
| `feature-lane-manifest-check.php` base / lane / dev | EXIT 1 / 1 / 0 (see §6) |

Pint and PHPStan claims: **VERIFIED**.

---

## 8. Scope audit

`git diff --stat 30001a187..cb5d0456f` = **14 files, +696 / −6** — the lane brief says 13. The extra
file is `apps/api/tests/feature-lane-manifest.json` (a required consequence of adding a test class),
so the discrepancy is a counting slip, not scope creep.

All 14 files are under `apps/api/`. `git diff --name-only | grep -E '^\.github|apps/pos|apps/web|packages/'`
→ **NONE**. No workflow files, no device code, no frontend, no generated types (no DTO changed, so
rule 7 does not fire). No `can:` guard added, so no seeder sync is owed. No money or quantity touched,
so rule 19 does not apply.

---

## Findings (ordered by severity)

**F-1 [CRITICAL] `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:49-52` —
`verifyPin` resolves ANY Active tenant user's PIN with no company-membership filter.** Proven live:
a company-B-only manager AND a user with zero memberships both return HTTP 200 with full roles and
the complete `pos.approve_*` permission set on a company-A terminal. Cross-company operator
impersonation inside a tenant; the zero-membership case is broader than the lane reported. *Fix:*
add the `pinData`-style active-membership `whereIn('id', $companyUserIds)` filter plus two red-first
tests (cross-company member → 422; zero-membership user → 422). Pre-authorized fix-round item.

**F-2 [IMPORTANT] `apps/api/tests/feature-lane-manifest.json:9` (and `groups.Identity`) — the
`gated_ceiling` edit will conflict with dev and every naive resolution turns dev's manifest checker
RED.** Lane 1134→1135; dev 1134→1141 with actual gated classes = 1141 (zero headroom). *Fix:* merge
onto current dev, set `gated_ceiling = 1142`, keep `Identity.classes = 32` and dev's `POS.classes = 147`,
then prove `apps/api/tools/feature-lane-manifest-check.php` exits 0.

**F-3 [IMPORTANT] `apps/api/tests/feature-lane-manifest.json` `groups.Identity.note` — the new
security-regression class lands in a lane PARKED behind `vars.SELF_HOSTED_RUNNER_READY`, so it runs
nowhere in CI.** True of the repo, not caused by the lane, but "13 tests added" must not be allowed to
imply CI coverage. *Fix:* state it explicitly on the promotion checklist.

**F-4 [IMPORTANT] `tests/Feature/Identity/UserManagement/UserOffboardingCascadeTest.php:222-245` and
`:295-314` — two tests pass VACUOUSLY against unfixed code.** `test_reactivation_restores_memberships_revoked_by_the_cascade`
asserts the end state is Active with NULL stamps; with the cascade removed the rows were never
revoked, so they are already Active with NULL stamps and the test is green. Same for
`test_cascade_leaves_non_active_memberships_in_place`. They cannot fail if the cascade breaks, which
is why only 9 of 13 tests were actually red-first. *Fix:* assert the INTERMEDIATE state — after
`deactivate`, assert both rows are `Revoked` with `revoked_reason = user_deactivated`, THEN
`activate` and assert the restore.

**F-5 [MINOR] Lane finding #2 is factually wrong as written.** `sync-pins` cannot write another user's
PIN: a pre-existing SELF-ONLY guard at `PosAuthController.php:248-265` (present at base) rejects any
non-self `user_id` before any write. The "harmless now" verdict stands; the reason does not. *Fix:*
correct the write-up; do not spend the fix round on a belt here.

**F-6 [MINOR] `apps/api/app/Modules/Company/Domain/UserCompanyMembership.php:65-67` — `revoked_at` /
`revoked_by` / `revoked_reason` were added to `$fillable`.** Nothing mass-assigns them today (only
`UserController.php:982-986` and `:1014-1018` write them), but the first membership-management
endpoint that mass-assigns validated input would let a client stamp `revoked_reason = user_deactivated`
on a deliberate revocation and make it auto-reversible — defeating the whole fail-closed rule. *Fix:*
drop the three from `$fillable` (both writers use query-builder `update()`, which ignores `$fillable`),
or add a `guarded`-style comment pinning the invariant.

**F-7 [MINOR] `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:687-689,
:720-723` — `setPosPin` is tenant-scoped, not company-scoped**, so an admin in company A can set or
clear the PIN of a user who belongs only to company B, and can arm a PIN on a deactivated account.
Same hole class as F-1, harmless today because all four belts filter status. *Fix:* fold into the F-1
fix round.

**F-8 [MINOR] The belts test `status = Active`, not `status != Inactive`** (`PosAuthController.php:50`,
`:175`; `AuthorizedManagersController.php:44`; `PinVerifier.php:54` via `User::isActive()` at
`User.php:260-263`). `UserStatus` also has `Suspended` and `PendingVerification`
(`apps/api/app/Modules/Identity/Domain/Enums/UserStatus.php:11-14`), and an admin CAN arm a PIN on a
`pending_verification` user (F-7). Such a user silently stops appearing on the roster after this lane.
Fail-closed and almost certainly correct, but it IS a behavior change. The local demo tenant is
unaffected (all 10 users `active`, 7 with PINs). *Fix:* one line on the promotion checklist — spot-check
`SELECT status, count(pos_pin) FROM users GROUP BY status` per tenant before deploying.

**F-9 [MINOR] Lane write-up cites `syncService.ts:1301` under a path that does not exist.** The real
file is `apps/pos/src/lib/sync/syncService.ts` (pull at `:1301-1310`, prune at `:1320-1322`), not
`apps/pos/src/services/syncService.ts`. Everything else in the D-1 residual — non-empty-gated prune,
1–5 min online window (`apps/pos/src/lib/sync/syncScheduler.ts:11-12`), unbounded offline — is accurate.
*Fix:* correct the path.

**F-10 [MINOR] The cascade does not clear `pos_pin`, and `setPosPin`'s uniqueness scan
(`UserController.php:720-723`) includes deactivated users.** A fired employee's PIN stays reserved
tenant-wide forever, so a new hire given the same digits gets `PIN_ALREADY_IN_USE` with no
explanation. Not a security hole (belts hold); an ops papercut. *Fix:* consider clearing `pos_pin` in
the cascade, or excluding non-Active users from the uniqueness scan. Note that clearing the PIN would
make the reverse edge lossy — if you do it, do it deliberately, not as a drive-by.

---

## What to fix before merge

Rebase onto current `dev` and resolve the manifest to `gated_ceiling = 1142` / `Identity.classes = 32`
with a green `feature-lane-manifest-check.php` (F-2); harden the two vacuous tests so all 13 are truly
red-first (F-4); carry F-1 as a pre-authorized fix-round item (company-membership filter on
`verifyPin` + two red-first tests, plus F-7 alongside it); and correct the F-5/F-9 write-up claims and
add the F-3/F-8 lines to the promotion checklist.

The delivered work itself — atomic cascade, fail-closed reverse edge, four belts, self-guarding
PG-idempotent migration, honest inherited-red accounting — is sound and I could not break it.

VERDICT: CHANGES-REQUIRED
