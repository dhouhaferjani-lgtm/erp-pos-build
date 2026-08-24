# Adversarial merge gate — round 2 (final)
## Lane `fix/membership-offboarding-pin-revocation`, tip `2b357bc57`

Reviewer: tenancy-authz-reviewer (adversarial, code-grounded). Read-only on the lane worktree
(`git status --porcelain` = 0 lines before and after every probe). Probes ran in an isolated scratch
copy built by `git archive 2b357bc57` with a CLONED (`cp -Rc`) vendor — never in the lane worktree,
never in the main checkout. Scope per the r2 brief: the two fix commits against F-1..F-10 only. The
r1-verified cascade/reactivation/migration core was NOT re-litigated.

Tip composition confirmed: `2b357bc57` = `cb5d0456f` (r1 tip) + `97a997d72` (dev merge) +
`db2302317` + `2b357bc57` (the two fix commits).

---

## 0. Vendor trap — cleared before anything else

The r1 trap (a symlinked `vendor` makes `autoload_psr4.php` compute `$baseDir` through the symlink, so
every reverted-belt run silently loads the UNREVERTED worktree classes) was re-checked first, in both
the lane worktree and every scratch harness.

- Lane worktree: `apps/api/vendor` is `drwxr-xr-x`, 84 entries, `test -L` → **NO**. A real directory.
- Reflection in the lane worktree:
  - `Illuminate\Support\Str` → `.worktrees/membership-offboarding/apps/api/vendor/laravel/framework/src/Illuminate/Support/Str.php`
  - `PosAuthController`, `UserCompanyMembership`, `PinVerifier` → all under `.worktrees/membership-offboarding/apps/api/app/...`
- Belt-presence by reflection (reading the file the autoloader actually resolved, not the file I
  opened): `whereIn('id', $companyUserIds)` present **2×** in `PosAuthController`; `'revoked_at',` in
  the `$fillable` array **ABSENT**.
- Every scratch harness (`$SP/lane`, `$SP/merged`) got its own `cp -Rc` vendor clone and its
  resolution was re-proved before any number below was trusted — e.g. in `$SP/lane`,
  `UserController` → `$SP/lane/apps/api/app/...` and `Str` → `$SP/lane/apps/api/vendor/...`.

No run in this review used a symlinked vendor.

---

## 1. F-1 [was CRITICAL] — CLOSED, and I re-executed both probes plus the population check myself

### The fix, at source

`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:58-69`:

```php
$company = $this->companyContext->requireCompany();

$companyUserIds = UserCompanyMembership::query()
    ->where('company_id', $company->id)
    ->where('status', MembershipStatus::Active->value)
    ->pluck('user_id');

$users = User::where('tenant_id', $currentUser->tenant_id)
    ->where('status', UserStatus::Active->value)
    ->whereIn('id', $companyUserIds)
    ->whereNotNull('pos_pin')
    ->get();
```

`CompanyContext` was already constructor-injected (`:27-30`, `private readonly` — rule 13 clean);
`MembershipStatus` / `UserCompanyMembership` imports added at `:8-9`.

### Three-surface population diff (the brief's explicit ask)

| Surface | Company scope | Account scope | PIN | Source |
|---|---|---|---|---|
| `verifyPin` | `id IN (memberships WHERE company_id = ctx.company AND status = active)` | `status = Active` | `pos_pin IS NOT NULL` | `PosAuthController.php:60-69` |
| `pinData` | **identical 4-line block** | `status = Active` | `pos_pin IS NOT NULL` | `PosAuthController.php:179-194` |
| `PinVerifier::verifyForApproval` | `EXISTS(membership WHERE user_id, company_id = $companyId, status = active)` | `! $user->isActive()` → `ScopeMismatch` | `pos_pin !== null` | `PinVerifier.php:44-71` |

The first two are now textually the same predicate (`$company = requireCompany()` at `:58` and `:158`;
`$companyUserIds` built identically at `:60-63` and `:179-182`; `whereIn('id', $companyUserIds)` at
`:67` and `:193`). The third expresses the same set per-user rather than as a set. The fourth surface,
`AuthorizedManagersController::index` (`:44-51`), is the same population narrowed by
`->permission('pos.close_shift_with_variance')`. **All admit the same population.**

*Adversarial sub-check I ran and closed against the lane:* `PinVerifier`'s `$companyId` comes from the
REQUEST (`ManagerPinController.php:51`, `$request->string('company_id')`), not from `CompanyContext`
— which would have made its effective population the union over every company in the tenant. It is
pinned: `VerifyManagerPinRequest.php:46` is `'company_id' => ['required','uuid', Rule::in([$companyId])]`
with `$companyId = $this->companyContext->requireCompany()->id`. **Not a hole.** Recorded so the next
gate does not re-raise it.

*Second sub-check, also closed:* `requireCompany()` throws `RuntimeException` when no company is bound
(`CompanyContext.php:100-110`), so the fix could in principle have converted a 422 into a 500 for a
caller with no resolvable company. It cannot: `CompanyContextMiddleware` is appended to the global
`api` group (`bootstrap/app.php:139-148`) and returns **403 `NO_COMPANY_ACCESS`** / **403
`COMPANY_ACCESS_DENIED`** before the controller runs (`CompanyContextMiddleware.php:57-74`). Nothing
reaches `verifyPin` without a validated, access-checked company. **Not a hole.**

### My own probes (independent class, not the lane's tests), lane tip, unmodified

```
[PROBE a cross-company]   status=422 body={"error":{"code":"INVALID_PIN","message":"Invalid PIN"}}
[PROBE b zero-membership] status=422 body={"error":{"code":"INVALID_PIN","message":"Invalid PIN"}}
[PROBE c same-company]    status=200 name="company-a-manager" roles=["manager"]
[PROBE d revoked-member]  status=422
OK (4 tests, 6 assertions)
```

- (a) manager whose ONLY membership is company B, PIN on a company-A terminal → **422**.
- (b) tenant user with ZERO memberships anywhere → **422**.
- (c) **r1 population check**: same-company ACTIVE manager → **200**, correct identity and roles. The
  fix did not over-narrow.
- (d) my own addition: an active member whose membership is then flipped to `revoked` (what the
  cascade does) → **422**. The lane's own reason for existing now holds on this surface too.

### Red-first, proven by me

Reverting ONLY `->whereIn('id', $companyUserIds)` in the scratch copy (cascade and every other belt
left intact):

```
[PROBE a cross-company]   status=200 ... "roles":["manager"],"permissions":["settings.view","partners.view",...
[PROBE b zero-membership] status=200 ... "roles":["manager"],"permissions":["settings.view","partners.view",...
[PROBE c same-company]    status=200   (unchanged — correct)
[PROBE d revoked-member]  status=200
FAILURES! Tests: 11, Assertions: 23, Failures: 5.
```

Five failures = my three discriminating probes (a, b, d) + **both** of the lane's two new tests
(`test_verify_pin_rejects_a_manager_from_another_company`,
`test_verify_pin_rejects_a_user_with_no_membership_anywhere`,
`PosAuthVerifyPinTest.php:152-232`). The lane's new tests are genuinely red-first. Scratch restored
(`/tmp/pac.bak`), probe file deleted.

**F-1 CLOSED.**

---

## 2. F-2 / F-3 — CLOSED, and F-2 is dissolved rather than resolved

### The move is real

`git diff --name-status 97a997d72..2b357bc57` reports
`R081 apps/api/tests/Feature/Identity/UserManagement/UserOffboardingCascadeTest.php → apps/api/tests/Feature/Security/UserOffboardingCascadeTest.php`.

- Gone from Identity: `ls apps/api/tests/Feature/Identity/UserManagement/` → 9 files, none of them the
  cascade test.
- Present in Security: `ls apps/api/tests/Feature/Security/` → 18 files including
  `UserOffboardingCascadeTest.php`, matching the declared `Security.classes = 18`.
- Namespace updated: `tests/Feature/Security/UserOffboardingCascadeTest.php:5` is
  `namespace Tests\Feature\Security;`.
- No stale references: a repo-wide grep for `UserOffboardingCascadeTest` across `*.yml *.json *.php
  *.md *.sh` returns exactly two hits — the manifest note and the class declaration itself. No
  `--filter` allowlist pointed at the old path.

### The Security lane genuinely picks it up

`.github/workflows/ci.yml:503-505` declares `security-regression`; `:506` is a load-bearing comment
`# NO 'if:' GUARD — ON PURPOSE`, and I confirmed by reading the job body through to its steps that no
`if:` key exists at job level. The selector at `:575-576` is:

```yaml
- name: Security regression suite (module gating + kill-switches)
  run: ./vendor/bin/phpunit tests/Feature/Security
```

A **whole-directory** selector, so the class is picked up the moment it lands — no allowlist edit
needed. Contrast the parked lane the class came from (`feature-lane-tenancy/Identity`, gated on
`vars.SELF_HOSTED_RUNNER_READY`). This is a strict, real improvement over r1's F-3.

### Manifest: informational note present, checker green

`apps/api/tests/feature-lane-manifest.json:956-961` — `Security.classes` 17 → 18 with a note recording
the 17→18 raise, the placement rationale, and "This count is informational, as for any live lane."

`php apps/api/tools/feature-lane-manifest-check.php` **on the lane tip: EXIT=0**
(`1374 Feature classes in 74 groups`, gated 1141 — dev's value, untouched).

### F-2 will NOT conflict again — proven against CURRENT dev

Current local `dev` is `1230eb420` (Partner 21, gated_ceiling 1142, new Accounting class), i.e. dev
HAS moved since the lane's merge commit. `git merge-base dev 2b357bc57` = `9d6533c60`.

```
$ git merge-tree --write-tree --messages dev 2b357bc57
EXIT=0
653aafa2521882f954175c9d782c1a9c4b418c18
Auto-merging apps/api/tests/feature-lane-manifest.json
```

**Clean auto-merge, no conflict.** The lane's merge commit took dev's manifest verbatim and then
edited only the `Security` block, while dev's subsequent commits edited `gated_ceiling`, `Partner` and
`Accounting` — disjoint hunks from a common ancestor, so git resolves it without a marker.

I did not stop at "no conflict". I materialized the merged tree
(`git archive 653aafa25` → scratch, `cp -Rc` vendor, reflection re-proved) and ran the checker on the
MERGE RESULT:

```
MERGED EXIT=0
tests/Feature lane manifest OK — 1376 Feature classes in 74 groups; ...
```

with `gated_ceiling = 1142` (dev's), `Security = 18` (the lane's), `Identity = 31` (dev's — the lane's
+1 is gone with the move), `Partner = 21` (dev's). **The merge is clean AND green.** r1's "every naive
resolution turns dev red" blocker no longer exists; the lane no longer raises `gated_ceiling` at all.

**F-2 and F-3 CLOSED.**

---

## 3. F-4 — CLOSED, discriminating probe re-run by me

Scratch copy, baseline green first (`OK (8 tests, 53 assertions)` — r1 measured 8 tests but the
assertion count rose, consistent with added intermediate-state assertions; the full
cascade+PinVerifier pair went 52 → 65 assertions). Then `revokeMembershipsFor()`
(`UserController.php:976-987`) short-circuited to `return 0;`:

```
✔ Cascade leaves other users memberships untouched        <- the negative control
✘ Deactivating a user revokes every active membership
✘ Deactivation stamps revocation provenance
✘ Deactivated user loses company access
✘ Destroy revokes every active membership
✘ Reactivation restores memberships revoked by the cascade      (Revoked expected, Active actual — line 238)
✘ Reactivation does not restore independently revoked memberships (null vs MembershipRevocationReason — line 289)
✘ Cascade leaves non active memberships in place                 (Revoked expected, Active actual — line 331)

FAILURES! Tests: 8, Assertions: 20, Failures: 7.
```

**7/8 red, exactly as the lane claimed (4/8 → 7/8).** The three formerly-vacuous cycle tests now fail
on their INTERMEDIATE assertions (lines 238, 289, 331 — mid-cycle `Revoked` / reason checks), not on
their end state. The single pass is the negative control, which by construction must pass when nothing
happens. Scratch restored from `/tmp/uc.bak`, probe marker grep = 0.

---

## 4. F-6 — CLOSED, mass-assignment proven impossible

`apps/api/app/Modules/Company/Domain/UserCompanyMembership.php:65-72` replaces the three `$fillable`
entries with a comment pinning the invariant (the reason is stated correctly: `revoked_reason =
user_deactivated` is what makes a revocation auto-reversible, and only the cascade may write it). The
model has no `$connection` pin and no global scope (`:41-48`), so it correctly rides the swapped
default connection to the tenant DB — right for a tenant-scoped table.

My probe (create → update → query-builder):

```
[F-6 create]  revoked_at=NULL reason=NULL          <- create() with all three in the payload: ignored
[F-6 update]  reason=NULL                          <- update()/fill() with the stamp: ignored
[F-6 builder] status=revoked reason=user_deactivated  <- the cascade's path: unaffected
OK (1 test, 6 assertions)
```

Both mass-assignment vectors are dead; the cascade's query-builder write path works exactly as before.

---

## 5. F-8 — CLOSED; all three code claims hold and the test is honestly labeled a pinning test

| Claim | Verified at |
|---|---|
| `pending_verification` written only at `store()` for invited users | `UserController.php:227` is the **only** non-`case` occurrence of `PendingVerification` in all of `app/` (repo-wide grep) |
| PIN-only cashiers flipped Active in-transaction | `UserController.php:236-238` — `if ($user->email === null) { $user->update(['status' => UserStatus::Active]); }`, inside the `DB::transaction` opened at `:217` |
| Login refuses non-Active | `AuthController.php:264` `if (! $user->isActive())` → `ValidationException` (`auth.account_not_active`); `User.php:260-263` `isActive()` is `status === UserStatus::Active` |

So the till-operating population is Active by construction, and the reasoning that a
`pending_verification` identity — one nobody has proven control of, and which can never hold a session
— must not authorize discounts/returns/variance closes is sound. Fail-closed is correct.

The pinning test exists: `tests/Feature/POS/PinDataEndpointTest.php:256-296`,
`test_pin_data_excludes_pending_verification_users`. Its docblock states the full derivation and ends
"Fail-closed is the correct reading; **this test pins it**" — honestly labeled as pinning current
behavior rather than proving it optimal. Green in every run below.

**The r1 F-8 ops spot-check still belongs on the promotion checklist** (see §8) — the test pins the
decision, it does not tell you whether a live tenant has a `pending_verification` user holding a PIN.

---

## 6. F-5 / F-7 / F-9 / F-10 — write-up corrections present AND their underlying facts re-verified

All four are carried in the `2b357bc57` commit message under `WRITE-UP CORRECTIONS`. I did not take
the prose on trust:

- **F-5** — corrected reason is accurate. `PosAuthController.php:265-281` carries the `SELF-ONLY`
  block; it `throw`s `ValidationException` for any `$targetId !== $currentUserId` **before** any write.
  The commit says exactly this ("a PRE-EXISTING SELF-ONLY guard … rejects any non-self `user_id`
  before any write happens"), replacing r1's wrong "harmless because deactivated users can't log in"
  reason with the real one. Correct.
- **F-7** — carried as an explicit `TICKET LINE` with the owner-ruling rationale: the exploitable
  consequence is gone (all three PIN surfaces now refuse a non-member), and adding a
  `TARGET_MEMBERSHIP_REQUIRED` guard to `setPosPin` is a UX-visible tightening for multi-company
  admins that deserves an owner ruling. Verified the surface is genuinely untouched by the lane
  (`git diff --name-status` on the fix commits does not list `UserController.php`). Acceptable
  deferral, stated honestly. **Remains an OPEN residual.**
- **F-9** — corrected path verified: `apps/pos/src/lib/sync/syncService.ts` EXISTS,
  `pullOperatorPins` at `:1301`, `pruneOperatorsExcept` at `:1321`; `apps/pos/src/services/syncService.ts`
  confirmed **does not exist**. (The commit says the prune is at `:1320`; the call is at `:1321`,
  inside the block that opens at `:1320` — r1 wrote `:1320-1322`. Immaterial.)
- **F-10** — residual stated and real: `UserController.php:718-723` scans
  `->where('id','!=',$user->id)->whereNotNull('pos_pin')` with **no status filter** before returning
  `PIN_ALREADY_IN_USE` at `:729`, and the cascade (`:976-987`) does not clear `pos_pin`. A fired
  employee's digits stay reserved tenant-wide. The commit correctly flags that clearing `pos_pin`
  would make the reverse edge lossy and must be a deliberate decision. **Remains an OPEN residual.**

---

## 7. Runs (every command executed by me)

| Run | Expected | Result |
|---|---|---|
| `phpunit tests/Feature/Security/UserOffboardingCascadeTest.php tests/Unit/POS/PinVerifierTest.php` | — | **OK (17 tests, 65 assertions)** |
| `phpunit` the 4 POS PIN classes (`PosAuthVerifyPin`, `PinData`, `ManagerPin`, `AuthorizedManagers`) | — | **OK (30 tests, 105 assertions)** |
| POS PIN batch, combined | **47 / 170** | **47 tests / 170 assertions — exact match** |
| `phpunit tests/Feature/Security/` (whole dir) | **101 / 358** | **101 tests, 358 assertions, 0 failures — exact match** (14 PHPUnit deprecations, pre-existing) |
| F-1 independent 3+1 probe, lane tip | a/b 422, c 200 | **OK (4 tests)** — see §1 |
| F-1 red-first (verifyPin scope reverted, scratch) | a/b/d 200 | **5 failures** incl. both lane tests |
| F-4 discriminating probe (`revokeMembershipsFor` disabled, scratch) | **7/8 red** | **Tests: 8, Failures: 7** — sole pass = negative control |
| F-6 mass-assignment probe | stamps ignored | **OK (1 test, 6 assertions)** |
| `feature-lane-manifest-check.php` @ lane tip | EXIT 0 | **EXIT=0** |
| `feature-lane-manifest-check.php` @ **merge result with current dev** | EXIT 0 | **EXIT=0**, 1376 classes / 74 groups |
| `git merge-tree --write-tree dev 2b357bc57` | clean | **EXIT=0**, manifest auto-merged |
| **PG spot** — `PosAuthVerifyPinTest` on PostgreSQL 16.10 (throwaway `gate_r2_probe` @ 5433) | green | **OK (7 tests, 19 assertions)** |
| **PG spot** — `UserOffboardingCascadeTest` + `PinDataEndpointTest` on PG | green | **OK (16 tests, 91 assertions)** |
| **PG spot** — migration column types | uuid / varchar(40) | `revoked_at timestamp(0)`, `revoked_by **uuid**`, `revoked_reason varchar(40)` |
| **PG spot** — migration idempotency at the tip (up×3, down×2, re-up) | clean | `before/up#2/up#3` all three cols; `down`/`down#2` empty, no throw; `re-up` restores. **Clean** |
| `pint --test` on all 5 changed PHP files | pass | `{"result":"pass"}` |
| `phpstan analyse` (level 8, project config) on the 4 touched/adjacent production files | clean | `[OK] No errors` |

PG runs were proven to be real PG, not a silent SQLite fallback: the throwaway DB held **276 tables**
after the run and `\d user_company_memberships` showed the migration's columns with PG-native types.
`gate_r2_probe` was dropped afterwards.

Pint and PHPStan claims: **VERIFIED**.

---

## 8. Scope audit — clean

`git diff --name-status 97a997d72..2b357bc57` = **6 paths, +212 / −21**:

| Path | F-finding |
|---|---|
| `app/Modules/POS/Presentation/Controllers/PosAuthController.php` | F-1 surface |
| `app/Modules/Company/Domain/UserCompanyMembership.php` | F-6 surface |
| `tests/Feature/POS/PosAuthVerifyPinTest.php` | F-1 tests |
| `tests/Feature/POS/PinDataEndpointTest.php` | F-8 pinning test |
| `tests/Feature/Identity/…/UserOffboardingCascadeTest.php` → `tests/Feature/Security/…` (R081) | F-3 move + F-4 hardening |
| `tests/feature-lane-manifest.json` | F-2/F-3 |

`git diff --name-only 97a997d72..2b357bc57 | grep -E '^\.github|apps/pos|apps/web|packages/|apps/api/database/migrations'` → **NONE**. No workflow edits, no device code, no frontend, no generated
types (no DTO touched → rule 7 does not fire), **no new or changed migration in the fix round** (the
lane still carries exactly the one r1-verified tenant migration). No new `can:` guard and no new
permission anywhere in the lane → nothing owed to `RolesAndPermissionsSeeder.php`. No money or
quantity touched → rule 19 does not apply. Route middleware unchanged and rule-12 compliant
(`apps/api/app/Modules/POS/routes.php:42` — `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`).

**Exactly the F-findings' surfaces + the move + the manifest. No scope creep.**

---

## Findings

Every r1 finding F-1..F-10 is now either closed or a deliberately-deferred, honestly-stated residual.
Nothing new blocks the merge. Three items for the promotion checklist:

**P-1 [PROMOTION CHECKLIST] MIGRATION-BEARING.** The lane carries one tenant migration,
`apps/api/database/migrations/tenant/2026_08_23_100000_add_revocation_tracking_to_user_company_memberships.php`.
Pushing to `origin/dev` auto-deploys and runs `tenants:migrate`. Re-verified at THIS tip on real
PostgreSQL 16.10: self-guarding, idempotent across up×3 / down×2 / re-up, correct native types
(`revoked_by` is a genuine `uuid`; the only writer supplies `$actor->id`, so the PG uuid-comparison
500 trap does not apply). No backfill required — legacy rows keep `revoked_reason = NULL` and are
deliberately fail-closed on the reactivation edge.

**P-2 [PROMOTION CHECKLIST] F-8 ops spot-check, carried forward from r1.** The belts are `= Active`,
not `!= Inactive`, and that is now pinned by a test — but a live tenant holding a
`pending_verification` or `suspended` user WITH a `pos_pin` will see that user silently drop off the
POS roster after this lane. Before deploying, per tenant:
`SELECT status, count(pos_pin) FROM users GROUP BY status;`. Anything non-`active` with a PIN needs an
owner decision, not a surprise.

**P-3 [PROMOTION CHECKLIST / transparency] The F-3 fix is real but does not cover an ff-push
promotion.** `security-regression` has no `if:` guard and uses a whole-directory selector, so the class
runs on **PR→dev, PR→main, push→main and workflow_dispatch**. But `ci.yml:3-8` triggers `push` on
`main` only — so the rule-21 promotion path (a clean fast-forward push of local `dev` to `origin/dev`)
fires **no workflow at all**, a repo-wide property ci.yml documents against itself at `:444-449`. Not
caused by this lane, and still a strict improvement over the parked `feature-lane-tenancy/Identity`
(which runs never). State it rather than let "runs in CI" imply gating at the promotion moment.

**Open residuals, deliberately deferred with stated rationale — carry as tickets, not merge blockers:**

- **F-7 [MINOR, OPEN] `UserController.php:687-689, :718-723` — `setPosPin` is tenant-scoped, not
  company-scoped.** An admin in company A can set or clear the PIN of a company-B-only user, and can
  arm a PIN on a deactivated account. The exploitable consequence is gone (all three PIN surfaces now
  refuse a non-member, verified in §1). Deferred for an owner ruling because a
  `TARGET_MEMBERSHIP_REQUIRED` guard is a UX-visible tightening for multi-company admins.
- **F-10 [MINOR, OPEN] `UserController.php:718-723` + `:976-987` — orphaned PIN reservation.** The
  cascade does not clear `pos_pin` and the uniqueness scan has no status filter, so a fired employee's
  digits stay reserved tenant-wide and a new hire given the same PIN gets `PIN_ALREADY_IN_USE` with no
  explanation. Ops papercut, not a hole. Clearing `pos_pin` would make the reverse edge lossy — a
  deliberate decision, not a drive-by.
- **D-1 [MINOR, OPEN, pre-existing] offline device window.** A terminal that never completes a
  non-empty `pullOperatorPins` never runs `pruneOperatorsExcept`
  (`apps/pos/src/lib/sync/syncService.ts:1301, :1321`), so a revoked operator persists in the local
  cache unboundedly while offline; online the window is 1–5 min
  (`apps/pos/src/lib/sync/syncScheduler.ts:11-12`). No device code was touched by this lane.

---

## What to fix before merge

Nothing. All ten r1 findings are closed or deferred with a stated, verified rationale; the merge into
current `dev` (`1230eb420`) is a clean auto-merge whose manifest checker I ran green on the merge
result itself; the F-1 critical is fixed and I re-proved it red-first with my own probes; the vacuous
tests now discriminate 7/8. Merge the lane, then put P-1 (migration-bearing), P-2 (the F-8 per-tenant
`pos_pin`-by-status spot-check) and P-3 (the ff-push CI-coverage caveat) on the promotion checklist,
and open tickets for F-7 and F-10.

VERDICT: ACCEPT
