# W2-1 gate r1 — tenancy/authz lens + frontend half

**Lane** `fix/campaign-w21-signup-stale-company` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w21-signup-state` · HEAD `f98ef17e0`
**Reviewed against** local `dev` = `925306c97` (dev moved from `e54da6a05` -> `925306c97` DURING this review; both readings recorded below)
**Reviewer** tenancy-authz-reviewer (adversarial, code-grounded) · 2026-08-25
**Scope** `git diff dev...HEAD` — 2 backend prod files, 4 web prod files, 2 new test files, ci.yml `--filter` append, manifest. No migration.

---

## VERDICT

**spec ✅ — quality CHANGES-REQUESTED**

The lane does exactly what the brief asked and the two central claims are **independently verified by execution**, not by reading:

* the malformed-`X-Company-Id` 500 (LEDGER C-13(iii)) is real and is fixed — reverting only the two backend prod files and re-running the new test on a throwaway PostgreSQL DB reproduces the **exact** `SQLSTATE[22P02] invalid input syntax for type uuid: "01a034af-94ea-713d-8ce0-462216bf6ab5x"` (5 failures + 1 error), and the lane turns it into a typed `400 INVALID_COMPANY_ID`;
* the signup deadlock is fixed — reverting only the four web prod files fails 15 of the 16 new vitest assertions.

**One merge-blocking item, and it is mechanical, not design:** the CI accounting in this lane was computed against a `dev` that has since moved. Merging as-is (a) leaves a `ci.yml` conflict and (b) fails the manifest gate with `GATED-LANE COVERAGE GREW: 1177 ... ceiling is 1176`. **Proven by executing the merge in a throwaway worktree.**

Nothing in the diff leaks across a tenant boundary, weakens an authz check, or creates a silent 403/401 on a prod path. The uuid guard is defense-in-depth at **both** layers (middleware + `CompanyContext`), which is right, and the service-level guard fails **closed**.

**merge-blocking: yes** (F-1 only).

---

## Manifest value

**`gated_ceiling` = 1177** (NOT the 1176 currently in the lane), **`groups.Company.classes` = 32** (dev has 31 — that number is correct as-is).

Derivation, executed: merged `925306c97` into a detached copy of `f98ef17e0`, resolved the `ci.yml` conflict as the union, and ran `apps/api/tools/feature-lane-manifest-check.php`:

```
✗ GATED-LANE COVERAGE GREW: 1177 class(es) now sit in lanes parked behind an
  unflipped execution gate, ceiling is 1176.
```
Setting `gated_ceiling: 1177` then produces `... manifest OK — 1428 Feature classes in 74 groups ... 1177 class(es) are laned but not yet running`.

Root cause: the lane raised 1175 -> 1176 against the `dev` it merged at `ea4eff245`; `dev` has since **independently** reached 1176 (`0b4c5b4a5` "gated_ceiling resolved against live dev 1174 + lane delta 1 = 1175", then the W4-2 merge `73bb1614d`). Git auto-merges `1176` vs `1176` cleanly and the lane's +1 is silently swallowed. **`dev` is moving fast — recompute at the actual merge moment; do not hard-code 1177 blindly.**

---

## Findings

### [Important — MERGE-BLOCKING] F-1 · CI accounting is stale against current `dev`: `ci.yml` conflicts and the manifest gate fails
`apps/api/tests/feature-lane-manifest.json:9` (`gated_ceiling: 1176`) · `.github/workflows/ci.yml:983` (the `backend-pgsql --filter` line)

Merging the lane into `925306c97` produces a **content conflict** on the `--filter` line (both sides appended class names) and, once resolved, a **failing manifest gate**:

* ours only: `CompanyContextMiddlewareTypedErrorsTest`
* theirs only: `ExpensePaidFromRepositoryTest`, `OpeningCashFloatSeedsRepositoryTest`

**Why it matters:** this is a red CI on `dev` at merge, on the two guards (C-3 filter allowlist + O-29 gated ceiling) whose whole purpose is to make "a new test class runs nowhere" impossible.

**Fix (at merge, in this order):**
1. Resolve `ci.yml` as the **union** of the three names — take `dev`'s line and append `|CompanyContextMiddlewareTypedErrorsTest` before `)::`. Resulting allowlist = 131 entries (verified: checker reports "every `--filter` entry is anchored and uniquely matched").
2. Set `gated_ceiling` to `<dev's value at merge> + 1` (1177 against `925306c97`) and re-run `php apps/api/tools/feature-lane-manifest-check.php` until it prints OK. Keep `groups.Company.classes: 32` and its DELIBERATE-RAISE note (both already correct).

---

### [Important] F-2 · The "reset can never loop" invariant is asserted but not enforced — and I broke it with a probe
`apps/web/src/lib/api.ts:158-186` (the `LOOP SAFETY` paragraph at :175-181 and the `currentCompanyId === null` guard at :177-180)

The comment argues the reset cannot ping-pong because after it `currentCompanyId` is null. That is only half the cycle: `CompanyProvider` immediately re-bootstraps on the re-keyed `tenantScopedKey(['user','companies'])` (`apps/web/src/features/company/CompanyProvider.tsx:76-85, 96-105`) and `resolveCompanySelection` (`apps/web/src/stores/companyStore.ts:63-79`) **deterministically re-picks `isPrimary` / `companies[0]`**. If the server denies THAT company, the next 403 resets again — forever.

Verified by execution (reviewer probe, run in a throwaway worktree, then deleted): render the real `CompanyProvider` with a company list containing a denied company →
`handleCompanyScopeRejection('COMPANY_ACCESS_DENIED')` returns `true`, selection nulls, the provider re-selects **the same id**, and the second `handleCompanyScopeRejection(...)` returns `true` again. Test passed, i.e. the loop is real.

**Is it reachable today?** Not quite, and only by accident. The precondition is "`/user/companies` lists a company the middleware denies". `userHasAccessToCompany` requires `MembershipStatus::Active` (`apps/api/app/Modules/Company/Services/CompanyContext.php:154-165`) while `UserController::companies` filters on **`user_id` only, no status** (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:834-835`). The only writer of a non-Active status is the all-or-nothing offboarding cascade (`UserController.php:976-987`) — after it the user has **no** Active membership, so `getDefaultCompanyForUser` returns null (`CompanyContext.php:127-136`) and `/user/companies` itself answers `403 NO_COMPANY_ACCESS`, which is deliberately excluded from the reset codes (`api.ts:158`). Nothing writes `Pending`/`Suspended` today (grep over `app/` + `database/`: 0 hits). So the loop is **latent**, held off by an unrelated accident, not by the stated invariant.

**Fix (cheap, pick either or both):**
* server: add `->where('status', MembershipStatus::Active->value)` to `UserController::companies` (`UserController.php:834`) so the list the client may select from is exactly the set the middleware accepts. Also closes a small info-disclosure (an offboarded token-holder can still enumerate the names of companies they were revoked from);
* client: have `handleCompanyScopeRejection` remember the rejected company id and have `resolveCompanySelection` skip it for the session.

Either way, **soften the comment** — as written it tells the next reader the loop is impossible.

---

### [Important] F-3 · A stale in-flight 403 resets a freshly-VALID selection
`apps/web/src/lib/api.ts:320-323` and `:163-186`

`handleCompanyScopeRejection` keys on the response code alone and never checks **which** company the failing request actually sent. Sequence: user switches A -> B; a request issued moments earlier still carrying A comes back `403 COMPANY_ACCESS_DENIED`; the handler wipes B (a perfectly valid selection) and the persisted key, and the app re-bootstraps to the primary company. The user's deliberate switch is silently undone.

**Fix:** the offending id is on the request config. Read it in the interceptor and pass it down:
```ts
const sent = error.config?.headers?.['X-Company-Id']
handleCompanyScopeRejection(readString(envelope,'code'), typeof sent === 'string' ? sent : null)
```
and reset only when `sent === companyStore.currentCompanyId`. This also narrows F-2.

---

### [Important] F-4 · The fix is client-local — the same deadlock class stays open in `apps/pos` (answers review item 5)
`apps/pos/src/lib/api.ts:60-79` · `apps/pos/src/stores/authStore.ts:376-405`

The POS client attaches `X-Company-Id` to **every** request from `getHeaders()` — including `/user/companies`. Its own stale-company recovery (`fetchCompanies`, `authStore.ts:387-405`, added by an earlier Codex round for exactly this bug) *depends on that call succeeding*: it drops the stale id only after the list comes back. Under a denied company the call 403s and the recovery never runs — the identical W2-1 deadlock, in the app that ships to a till.

**Judgement on handback §7 item 1 (server-side exemption for `user/companies`): DO IT, and do it now — but not as a bare header-ignore.** Ignoring only the header still leaves `403 NO_COMPANY_ACCESS` for a user with no Active membership, which is precisely the POS recovery screen's state. Skip company resolution for that route entirely; it is provably safe — `UserController::companies` reads only `$user->id` (`UserController.php:826-861`) and `getMeta` reads only `X-Request-ID` (`UserController.php:868-874`), so `CompanyContext` is never consulted. Cost is ~10 lines plus one test (`/user/companies` returns 200 with a junk header AND for a zero-Active-membership user). Combined with F-2's status filter it makes this entire bug class structurally impossible instead of conventionally avoided. **Not blocking this lane** (the shipped web client is fixed); recommend a same-day follow-up lane.

---

### [Minor] F-5 · The register/login regression guards are source-text greps
`apps/web/src/features/auth/__tests__/newSessionCompanyScope.test.tsx:186-197`

`expect(source).toContain('clearScopeForNewSession')` plus an `indexOf` ordering comparison. It does catch the exact regression (proven: it fails when the pages are reverted), but it passes on a commented-out call, breaks on a rename, and `indexOf('setAuth(')` would silently mis-anchor if a `setAuth(` string ever appears earlier in the file. Pragmatic given the pages have no seam; worth a `// heuristic guard` comment so the next reader does not trust it as behavioural.

### [Minor] F-6 · The probe route bypasses the real `api` pipeline
`apps/api/tests/Feature/Company/CompanyContextMiddlewareTypedErrorsTest.php:68-69`

The probe mounts `['auth:sanctum', SetPermissionsTeam, CompanyContextMiddleware]` directly, so nothing pins that the middleware still sits in the global `api` group in the order that makes the guard reachable (`bootstrap/app.php:142-146`). Verified by reading that the registration is untouched by this diff, and the admin-route/non-`User` early-returns (`CompanyContextMiddleware.php:51, 64`) still precede the new uuid check at `:75` — but a one-line assertion against a real `api` route would pin it.

### [Minor] F-7 · Login now discards a returning user's persisted company selection
`apps/web/src/features/auth/LoginPage.tsx:91-92`

`clearScopeForNewSession` runs on **every** login, not only when the account changed, so a multi-company user is bounced back to their primary company after each sign-in. Small blast radius (logout already resets the store), and correctness beats convenience here — flagging so it is a decision, not an accident.

### [Minor] F-8 · `Str::isUuid` is stricter than PostgreSQL's `uuid` parser
`apps/api/app/Http/Middleware/CompanyContextMiddleware.php:75` · `CompanyContext.php:154-157`

PG accepts braced (`{...}`) and undashed uuid literals; `Str::isUuid` rejects both, so such a client now gets `400` where it previously worked. No client in this repo emits those forms (all ids come from server JSON). Deliberate tightening — noted, no action.

---

## What I verified, and how

| Review item | Result |
|---|---|
| 400 `INVALID_COMPANY_ID` / 403 `NO_COMPANY_ACCESS` / 403 `COMPANY_ACCESS_DENIED` typed contract | ✅ `CompanyContextMiddleware.php:75-104`; 9 tests green on **sqlite** and on throwaway **PG** `autoerp_test_w21g` (127.0.0.1:5433, dropped) |
| C-13(iii) was a real PG-only 500 | ✅ red-proof on PG with the two backend files reverted to dev: `SQLSTATE[22P02] ... "01a034af-94ea-713d-8ce0-462216bf6ab5x"` on `user_company_memberships.company_id`, 5F+1E |
| `Str::isUuid` in **both** middleware and `userHasAccessToCompany` | ✅ `CompanyContextMiddleware.php:75`, `CompanyContext.php:155-157` (fails closed, never throws) |
| No information leak on 403 | ✅ `userHasAccessToCompany` only queries memberships — a non-existent company and a foreign-tenant company are indistinguishable (both `COMPANY_ACCESS_DENIED`); no 404/existence oracle |
| Super-admin / impersonation unaffected | ✅ admin-route and non-`User` early-returns at `:51` and `:64` still precede the new check at `:75`; `bootstrap/app.php:142-150` middleware order untouched by the diff |
| Rule 12 / every `api` route still gets the middleware | ✅ no route file in the diff; `CompanyContextMiddleware` still appended to the `api` group at `bootstrap/app.php:146` |
| `COMPANY_ACCESS_DENIED` emitted only by the middleware | ✅ grep over `app/`: 0 other emitters (`NO_COMPANY_ACCESS` also at `OnboardingController.php:41`, correctly NOT a reset code) |
| `clearScopeForNewSession` before `setAuth` on Register **and** Login | ✅ `RegisterPage.tsx:100-105`, `LoginPage.tsx:91-92` |
| `/user/companies` + `/auth/me` never carry `X-Company-Id` | ✅ `api.ts:245`, network-level assertions in `api.companyScope.test.ts:81-107`; `/auth/me` is additionally company-context-free server-side (`web` group, and `AuthController::me` never reads company) |
| Reset fires exactly once, no loop | ⚠️ true for the coded case, **false as a general invariant** — see F-2 |
| Re-bootstrap via `tenantScopedKey` | ✅ `CompanyProvider.tsx:77`; `newSessionCompanyScope.test.tsx:160-178` |
| Persisted keys scoped; POS `izipos-*` untouched | ✅ only `autoerp-company-selection` (`companyStore.ts:158-166`) and `autoerp-location` (`locationStore.ts:115-118`); no `localStorage.clear()` |
| Logout still clears | ✅ `clearAllAppState` unchanged (`clearAppState.ts:39-47`) |
| Stale-selection scenario end-to-end | ⚠️ partial — proven at component level with the real `CompanyProvider` + real stores; the browser leg is **not verified**: the vite server on :5173 serves `dev`, not the lane (`GET /src/lib/api.ts` has 0 hits for `isCompanyContextExempt`) |
| Two-company switching | ✅ by code — `setCurrentCompany` validates membership in the list (`companyStore.ts:139-147`); no 403, so no reset. See F-3 for the in-flight race |
| Red-proof, backend | ✅ 5F+1E with prod files reverted |
| Red-proof, vitest | ✅ 15 of 16 fail with prod files reverted; 16/16 green at HEAD |
| PG leg | ✅ (above) |
| Manifest / `--filter` | ❌ see F-1 |
| `pnpm typecheck` | ✅ clean |
| `eslint` (changed files) | ✅ 0 errors (11 pre-existing-style warnings; only `api.ts:321` `["error"]` dot-notation is new, mirroring the existing `:86`) |
| PHPStan (changed files) | ✅ `[OK] No errors` |
| deptrac | ✅ 183 violations = inherited baseline; 0 attributable to the two changed classes (grep of the violation list) |
| `audit-tanstack-keys.mjs` | ✅ Gate C: 0 new, 0 stale |

Throwaway PG databases `autoerp_test_w21g` / `autoerp_test_w21r` and the tamper worktree were dropped/removed. **The lane worktree was not modified.**

---

**What to fix before merge:** resolve `ci.yml` as the union of all three appended test-class names and set `gated_ceiling` to live-`dev` + 1 (1177 against `925306c97`) until `feature-lane-manifest-check.php` prints OK — everything else (F-2..F-4) is a fast follow-up lane, with the server-side `user/companies` exemption + Active-membership filter the one worth doing today.
