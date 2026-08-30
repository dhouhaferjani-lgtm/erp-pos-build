# Gate r2 — Lane I-1 automated onboarding campaign (fiscal/POS lens)

**Reviewer:** fiscal-pos-reviewer (adversarial, code-grounded)
**Date:** 2026-08-29
**Fix commit:** `43a8993f9` on top of `264474cab` / `008c2a199` — worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/i1-campaign` (branch base dev `7d48b4de1`)
**Diff reviewed:** `git diff 008c2a199..43a8993f9` — 6 files, +101/-21 (`apps/web/e2e/campaign/{README.md,journey.ts,onboarding.campaign.ts}`, `apps/web/eslint.config.js`, `apps/web/playwright.campaign.config.ts`, `docs/qa/ONBOARDING-CAMPAIGN.md`). **No backend, no `apps/pos`, no fiscal-envelope change** (`git diff --name-only 008c2a199..43a8993f9 -- apps/web/e2e/campaign/fiscal apps/api apps/pos` → 0 files).
**Prior record:** `docs/superpowers/reviews/2026-08-29-i1-campaign-gate-r1-fiscal-pos.md`

## VERDICT: spec ✅ (code) / ❌ (deferral docs) + quality **CHANGES-REQUIRED**

**The blocker is genuinely dead and every code fix I re-derived is correct.** B-1, M-1, M-2, M-3, M-7, M-9 and m-3 are RESOLVED, and I verified each one against the server code it depends on rather than accepting the commit message. What holds the gate is small and doc-shaped, plus one client timeout that tonight's own run artifact shows will keep the lane from ever going green: the M-5/M-6/M-8 deferral was conditioned on the doc saying `ONBOARDING_CAMPAIGN_ON_PUSH` stays unset until they close — **no file says that**, two operator-doc lines still assert the claim r1 flagged as false, and the doc actively recommends the reuse path that M-6 leaves broken. Est. fix: 1 code line + ~5 doc lines, **no re-run needed to clear this record**.

---

## Resolution of every r1 finding

| # | Status | Resolving line |
|---|---|---|
| **B-1** false I2-F1 finding | **RESOLVED** | `onboarding.campaign.ts:198` — `filter((repository) => repository['type'] === 'safe')`, location predicate gone |
| **M-1** tenant-wide list consumed as company-scoped | **RESOLVED** (and upgraded to a true finding) | `onboarding.campaign.ts:694-711` scoping + `:697-707` finding |
| **M-2** imprecise P0 diagnosis | **RESOLVED** | `journey.ts:329-331` (non-201 throws with body head) / `:333` (`if (!bodyIsJson)`) |
| **M-3** drawer never pinned after the sale | **RESOLVED** | `onboarding.campaign.ts:553-554` — `assertMoneyEqual(..., '1023.800')` |
| **M-4** no post-POS trial balance / no per-entry balance | **PARTIAL** | `onboarding.campaign.ts:626-627` (L7 only). No L8 re-assert; `assertJournalAccountCode:842` still checks one side of one line, never Σdebit == Σcredit |
| **M-5** country knob is TN-only | **DEFERRED-WITH-DOC (weak)** | `docs/qa/ONBOARDING-CAMPAIGN.md:50`. Code still accepts `--country FR` and runs (`journey.ts:142-165`); no ON_PUSH linkage |
| **M-6** reuse mode broken | **DEFERRED-WITH-DOC (inaccurate — see N-2)** | `docs/qa/ONBOARDING-CAMPAIGN.md:56`, `README.md:22` |
| **M-7** login-per-leg vs 5/min throttle | **RESOLVED** | `journey.ts:374-387` token re-injection; `:605` capture |
| **M-8** teardown / retention claim / artifacts | **PARTIAL** | `playwright.campaign.config.ts:18` `retain-on-failure` ✅; `ONBOARDING-CAMPAIGN.md:51` ✅ — but `:22` and `README.md:11,13` still lie (N-3); password still fixed (`journey.ts:283`); `cancel-in-progress: true` still set (`.github/workflows/onboarding-campaign.yml:24`) |
| **M-9** L1 partner idempotency | **RESOLVED** | `onboarding.campaign.ts:287-288` |
| m-1 poll budget vs test timeout | NOT-ADDRESSED (minor, ok) | `playwright.campaign.config.ts:13` still 90 s; only L0 raised (`onboarding.campaign.ts:143`) |
| m-2 quantities via the money normaliser | NOT-ADDRESSED (minor, ok) | `onboarding.campaign.ts:520, 527` |
| m-3 dead helpers | **RESOLVED** | `journalHasAccountCode` / `deepContains` removed |
| m-4 `tenantId: null` on L0 findings | NOT-ADDRESSED (minor, ok) | `journey.ts:219-234` — no re-stamp |
| m-5/m-6/m-7/m-8 | NOT-ADDRESSED (minor, ok) | — |

---

## Answers to the six r2 questions

### (a) Census scoping — correct, cannot false-positive on a fresh tenant, fires once ✅

`census()` (`onboarding.campaign.ts:683-712`) now derives `ownLocationIds` from the company's own `GET /locations` and partitions the tenant-wide repository list.

- **The payload really has no `company_id`** — confirmed: `PaymentRepositoryController::formatRepository()` (`apps/api/.../PaymentRepositoryController.php:421-441`) emits `location_id` but never `company_id`. Location ownership is the only available discriminator, so the chosen proxy is forced, not lazy.
- **The leak is real, not invented** — `index()` filters on `tenant_id` only (`:36-40`); `show()` filters `tenant_id` **and** `company_id` (`:56-60`). The finding text at `:704` states exactly that.
- **`GET /locations` is company-scoped**, so `ownLocationIds` is not itself contaminated: `LocationController::index()` (`apps/api/.../LocationController.php:87`) `where('company_id', $companyId)`. (This is the load-bearing fact — had `index()` been tenant-wide too, `ownLocationIds` would have contained both companies' locations, `foreign` would be empty, the leak would go unrecorded **and** `ownedSafes` at `:198` would count 2 and re-manufacture B-1.)
- **No false finding on a fresh single-company tenant.** Company 1's census runs at `:159`, before the second company exists, so `allRepositories` is exactly its own CASH-01 + SAFE-01 on its own location → `foreign = []` → no finding. The only way to fabricate one is a repository on a location the *user* cannot see: `LocationController::index():88` intersects with `LocationScopeResolver::resolve()`, which returns all company locations when the user has no membership restriction (`LocationScopeResolver.php:58-62`) — true for the campaign owner. Bounded (see m-9 below).
- **Fires once.** Module-level `reportedRepositoryLeak` (`onboarding.campaign.ts:697-698, 713`) with `workers: 1` + `mode: 'serial'` (`playwright.campaign.config.ts:7`, `onboarding.campaign.ts:56`) — one module instance per run. It is recorded under `leg: 'L0'`, which is where both `census()` calls happen, so `recordProductFinding` (`journey.ts:247-252`) marks the right leg FAIL.
- **Forward-compatible with the fix**: when `index()` gains the `company_id` filter, `foreign` becomes empty, the finding stops firing, and the retained set is unchanged. The gate heals itself instead of needing a second edit.

Note on the target: the worktree base (`7d48b4de1`) does **not** carry G-3c — `CompanyController.php` here has no `paymentRepositoryProvisioner` call (only `:184/:187/:189`). Local `dev` does (`git show dev:...CompanyController.php` line 196), and `9badbe294` is an ancestor of `dev` but not of this branch. The campaign is black-box against a running stack, so what matters is the target tree; the fix is correct for dev-with-G-3c, which is where run 20/21 point. Flagging so nobody re-derives B-1 from this worktree's `apps/api`.

### (b) Second-company criterion now matches company 1's ✅

`:198` `type === 'safe'` is now literally the same predicate as `assertDayOneCensus():724` `type === 'safe'`, and both run over an already-company-scoped set. `:196-197` keeps the `location_id === secondLocationId` predicate for the cash register, mirroring `:723`. Residual asymmetry: company 1 additionally pins `toHaveLength(2)` (`:725`), company 2 does not — a third stray own-location repository would pass on company 2. Minor, not worth a round.

### (c) P0 diagnosis branch ✅

`journey.ts:325-345`, in order: 429 → `throw` with `Retry-After` (`:325-328`); any other non-201 → `throw new Error('register failed: HTTP … — ' + body.slice(0,300))`, **no finding** (`:329-331`); 201 + non-JSON → the migration-echo finding then `loginAs` (`:333-345`). The r1 contradiction is gone — `expect(...).toBe(201)` was deleted, so the `loginAs` continuation at `:344` is now genuinely reachable. A JSON 422/500 can no longer be blamed on `migration-echo-p0`.

### (d) Ordering of the new pins ✅ (and deterministic, not merely late)

`:553-554` sits after all four L6 polls (stock `:517`, DEFAULT lot `:521`, receipt `:530`, journal `:537`) and after `whereDidItLand`. More importantly it is **not** a race: the drawer movement and the GL entry are written inside the *same* `DB::transaction` in `TreasuryReceiptBridge` (`apps/api/.../TreasuryReceiptBridge.php:322` wrapper; `postEntryNow` at `:1526` then `movementService->record(...)` at `:1560+`, with the in-transaction comment at `:1498-1503`). Since GL and drawer are one commit, polling the journal entry implies the balance moved — so the bare `assertMoneyEqual` is sound and will not flake. `1000.000 + 23.800 = 1023.800`, and L7's `1000.000` (`:625`) now proves sale **and** refund rather than "neither" — M-3's discriminating power is restored.
`:626-627` (trial balance `is_balanced`) likewise sits after the refund journal poll (`:607-610`), and the trial balance is derived from the same journal rows, so it is read-consistent.

### (e) Token re-injection — captured in BOTH paths, and it survives boot ✅

- **Capture:** `readBrowserSession()` (`journey.ts:605`) sets `journeyState.persistedAuth`, and it is called on **both** paths — reuse (`:269`, after `loginAs`) and register (`:349`, after the shell/landing assertion or after the P0 `loginAs`).
- **Injection:** `ensureSession()` (`:375-379`) writes `autoerp-auth` via `addInitScript`, i.e. before any app script on every navigation, then `:381-386` navigates and asserts it did not land on `/login`.
- **Does it survive boot?** Yes, and I traced it end to end rather than assuming:
  1. `authStore.ts:107-116` — `persist({ name: 'autoerp-auth', partialize: state => ({ user, token }) })`. The **token is persisted** (impersonation is the only case that nulls it), and login/register do pass it (`LoginPage.tsx:95`, `RegisterPage.tsx:105` → `setAuth(user, data.token)`).
  2. `api.ts:254-256` — the request interceptor reads `useAuthStore.getState().token` and sets `Authorization: Bearer`. Zustand's localStorage persist rehydrates synchronously at store creation, so the first request already carries it.
  3. `AuthProvider.tsx:63-73` fetches `/auth/me` unconditionally and `setUser` flips `isAuthenticated` (`authStore.ts:88-93`). `isAuthenticated` is deliberately **not** persisted, but `RequireAuth` (`AuthProvider.tsx:126-135`) shows the spinner while `isLoading`, so there is no premature `/login` redirect.
  4. The F-BUG-1 trap is closed on this tree: `CompanyProvider.tsx:111-121` only calls `reset()` after a real authenticated→unauthenticated transition (`wasAuthenticated` ref), so the injected `autoerp-company-selection` is **not** wiped during bootstrap (`companyStore.ts:218-225` is the reset that would have removed it).
- **Throttle:** one login per run now, well inside `Limit::perMinute(5)`. M-7 closed.
- Residual: if the token is ever rejected there is no fallback to `loginAs` — the leg dies on a 30 s URL timeout (`:383`) and reads as a product regression. Minor (m-10).

### (f) Money / rule 19 — clean ✅

`grep -rn "parseFloat|parseInt|Number\(|toFixed|Math\." apps/web/e2e/campaign` excluding the vendored encoder → **zero hits**. Every new assertion is a scale-3 string through `assertMoneyEqual`/`normalizeMoney`. The vendored canonicaliser is still byte-identical to the device (`diff apps/pos/src/lib/fiscal/canonicalCore.ts …/campaign/fiscal/canonicalCore.ts` → exit 0; same for `FiscalEventCanonicalEncoder.ts`), and no fiscal envelope/amount literal changed. The new eslint override (`eslint.config.js:417-430`) disables only style/`unknown`-walking rules — **not** `precision/no-parsefloat-on-money` (`:96`) or `precision/no-hardcoded-step` (`:91`), so the money guards still cover the campaign.
Toolchain re-run by me: `pnpm typecheck:e2e` clean; `pnpm exec eslint e2e/campaign playwright.campaign.config.ts` → **0 errors, 0 warnings**.

---

## New findings (r2)

### MAJOR

**N-1 — `journey.ts:317`: the 120 s `waitForResponse` cap sits under the 180 s leg timeout and under the P0-2 hotfix's 300 s server allowance, so a slow registration dies as a bare `TimeoutError` and records NO product finding — while `docs/qa/ONBOARDING-CAMPAIGN.md:65` claims P0-2 *is* a recorded finding.**
`test.setTimeout(180_000)` (`onboarding.campaign.ts:143`) raised the leg, but the inner `page.waitForResponse(..., { timeout: 120_000 })` is now the binding constraint. Evidence on disk in this worktree — `apps/web/test-results/campaign-20260829211324-95146/ledger.json`, written 22:15:36, i.e. a run started **47 s after** commit `43a8993f9` (22:14:49):
```
L0  FAIL | TimeoutError: page.waitForResponse: Timeout 120000ms exceeded while waiting for event "response"
L1..L8 SKIPPED | serial dependency did not pass       (findings: [])
```
I did not wait for run 21 as instructed; I am reporting the artifact that already exists. The target may simply have been slow — but that is exactly the case the doc says produces a P0-2 *finding*, and it produced an empty findings list and eight skipped legs instead. Once the P0-2 hotfix lets the server take up to 300 s, this cap guarantees the lane can never reach L1. **Fix:** raise the wait to ≥ 300 s (or `CAMPAIGN_REGISTER_TIMEOUT_MS`), and wrap it so a timeout records the P0-2 product finding with the elapsed time instead of throwing raw.

**N-2 — `docs/qa/ONBOARDING-CAMPAIGN.md:49` + `:56`: the M-6 deferral doc is not just incomplete, it recommends the broken path.**
`:49` says "iterate with reuse mode (below) rather than burning the window"; `:56` scopes the caveat to "a tenant past L5 (locked) cannot re-run L1–L4 meaningfully". The real breakage is earlier and total: L0 always runs and always calls `assertDayOneCensus(first)` (`onboarding.campaign.ts:159`), which pins `repositories` at exactly 2 (`:725`). L4 creates a bank repository with **no location** (`:405-411` — the body has no `location_id`), and the new census filter deliberately **keeps** null-location rows (`:710`), so it counts. Any tenant that reached L4 therefore fails L0 hard, and `mode: 'serial'` (`:56`) skips L1–L10 — including the "iterate on a single leg" use case the doc advertises. **Fix:** skip `assertDayOneCensus` when `reuseMode` (one line) *or* correct `:49`/`:56` to say reuse mode only works on a tenant that never reached L4.

**N-3 — `docs/qa/ONBOARDING-CAMPAIGN.md:22` and `apps/web/e2e/campaign/README.md:11,13`: the exact M-8 claims r1 flagged are still there, now contradicted by the same files.**
- `ONBOARDING-CAMPAIGN.md:22` — "`CAMPAIGN_KEEP_TENANT=1 …` **retains and prints** the generated credentials" — 29 lines above `:51`'s correction ("only *prints*"). The stale claim is in the "Run it" section an operator actually reads.
- `README.md:11` — unchanged "Set `CAMPAIGN_KEEP_TENANT=1` to **retain and print** the generated login".
- `README.md:13` — "Every run creates a unique tenant and **never reads or mutates an existing one**" is now flatly false, contradicted by `:22` of the same file (reuse mode).
An operator doc for a promotion gate that states the opposite of the code in two places is the same class of defect as B-1. **Fix:** three line edits.

**N-4 — the deferral precondition is not written down anywhere: no file says `ONBOARDING_CAMPAIGN_ON_PUSH` stays unset until M-5/M-6/M-8 close.**
`grep -rn "ONBOARDING_CAMPAIGN_ON_PUSH"` returns only `.github/workflows/onboarding-campaign.yml:1` ("Push runs remain inert until the owner sets …; the B-11/S-14 runner and quota decision is still unresolved") and `:35` (the `if:` guard) — both pre-existing and both attributing the inertness to the *runner/quota* question, not to the open lane gaps. `ONBOARDING-CAMPAIGN.md:51` gets closest with "Do not arm the push→dev trigger on a target you cannot clean", which covers only the M-8 half. Nothing tells the owner that arming it also requires country parameterisation (M-5) and a working reuse path (M-6). Since the doc-only closure of M-5/M-6/M-8 was explicitly conditioned on this sentence existing, the condition is unmet. **Fix:** one bullet naming M-5/M-6/M-8 (and their owning follow-up) as ON_PUSH preconditions, in `ONBOARDING-CAMPAIGN.md` next to `:51`.

**N-5 — `docs/glossary.md:51` still asserts the falsified I2-F1 claim.**
"an additional company created via `POST /api/v1/companies` gets none today (open gap, product finding I2-F1)". That is false on `dev`: `CompanyController::store()` line 196 calls `paymentRepositoryProvisioner->provisionForCompany($company->tenant_id, $company->id, $location->id)` (G-3c `9badbe294`, an ancestor of `dev`). The campaign code and `ONBOARDING-CAMPAIGN.md` were corrected; the glossary — the authoritative one-surface-per-concept artifact under rule 22 — was not, so the false claim now outranks the corrected ones. Outside this diff, but it is the same sentence B-1 was about and the r1 fix list said "correct … handover claims about I2-F1". **Fix:** one line.

### MINOR

- **m-9 — `onboarding.campaign.ts:694`: `ownLocationIds` is the *user-visible* location set, not the company's.** `LocationController::index():88` intersects with `LocationScopeResolver::resolve()`. A campaign user with restricted location membership (`LocationScopeResolver.php:58-65`) would see its own company's repositories classified as `foreign` → a false leak finding. Harmless for a fresh owner tenant; a real trap for staging reuse. Add a comment, or scope by `company_id` once `index()` exposes it.
- **m-10 — `onboarding.campaign.ts:710`: null-location repositories are attributed to *every* company.** The filter keeps `typeof location_id !== 'string'`. Today company 1's L4 bank (`:405-411`) is the only such row and L0 precedes L4, so nothing leaks; the day a census runs after L4, or a legacy null-location safe exists, company 2's `ownedSafes` (`:198`) counts 2 and B-1 comes back in a new costume. Prefer explicit `company_id` scoping as soon as the API offers it.
- **m-11 — `journey.ts:381-386`: no fallback to `loginAs` if the injected token is rejected.** A stale/revoked token becomes a 30 s URL-timeout FAIL that reads as a product regression on a promotion gate. Catch and fall back once.
- **m-12 — `onboarding.campaign.ts:204-209`: the `else` comment still blames G-3c** ("`CompanyController::store()` never calls `PaymentRepositorySeeder`, so a second company cannot take cash"). That is false on `dev` (same evidence as N-5). The branch is now a genuine regression detector; the comment invites a future reader to re-open a shipped lane.
- **m-13 — `docs/qa/ONBOARDING-CAMPAIGN.md:66` says the repositories-index leak needs "owner routing owed"** — `dev` HEAD `2cfeed2e8` already ledgers it as Session J **D-J0-8** ("payment-repositories index tenant-scope leak (P1, micro-lane tonight)"). Name the lane so the finding is not triaged twice.
- **m-14 — `.github/workflows/onboarding-campaign.yml:22-24`** still `cancel-in-progress: true`; a cancelled run can orphan a half-projected fiscal chain mid-L6. Not armed today (`:35`), but it belongs in the ON_PUSH precondition bullet of N-4.
- r1's m-1, m-2, m-4, m-5, m-6, m-7, m-8 remain open as accepted minors.

---

## Fiscal-lens sign-off on what did NOT change

No `apps/api`, `apps/pos` or `e2e/campaign/fiscal/**` file is touched by this commit. The r1 GREEN verifications therefore still stand and were spot-re-checked: vendored canonicaliser byte-identical to the device, `SALE_RECEIPT` v4 REFUND with `invoice_type_code: 'REFUND'` + `original_receipt_reference` (no invented refund event type), TTC `unit_price` vs net `line_subtotal` with **no per-line equality assertion**, aggregate-only fiscal integrity, refund GL asserted as the symmetric reversal (`createPOSRefundReversalEntry`, comment `onboarding.campaign.ts:617-623`) rather than the wrong `sales_return`, restock direction taken from `MovementDirection::Out` on the tender leg rather than assumed. Nothing in the fix round re-authors or mutates a device-signed fact; the campaign still only reads projections.

---

## What to fix before merge

Raise the `waitForResponse` cap and record P0-2 as a finding instead of a raw timeout (**N-1**, one line + a try/catch); fix the four doc lines (**N-2** reuse-mode caveat, **N-3** the two "retains and prints" claims + the false `README.md:13`, **N-4** the missing `ONBOARDING_CAMPAIGN_ON_PUSH` precondition bullet naming M-5/M-6/M-8, **N-5** `docs/glossary.md:51`). No re-run is required to clear this record — re-gate is a diff read.

---

# Round 3 (delta) — fix commit `42fdde50d`

**Reviewed:** `git show 42fdde50d` (6 files, +21/-9 — the only I-1 content in the range; `git diff 43a8993f9..42fdde50d` also carries the dev merge `dc0ba1ad0` of `0cbaa1457`, which is not this lane's work and is not reviewed here).
**Live evidence:** run 22 ledger (`ledger-run-22.json`, run `20260829213028-24693`, 21:30→21:35 UTC on dev `0cbaa1457` with `43a8993f9`'s census).

## VERDICT: spec ✅ + quality **MERGEABLE**

All five r2 findings are resolved, and run 22 independently confirms the r1/r2 census work end to end. One r3 code change (the bank repository's `location_id`) was not exercised by run 22; I verified it statically against the server contract and it is safe — details below, so nobody has to re-run to close it.

## N-1 — RESOLVED

- `journey.ts:317` — `{ timeout: 300_000 }` with the seam named in the comment; `onboarding.campaign.ts:143` — `test.setTimeout(360_000)`. The leg budget is now strictly above the wait, which is strictly at the server seam. The 120 s trap that killed the 22:15 run is gone.
- `journey.ts:326-335` — the P0-2 branch: `status() === 500 && /Maximum execution time/i.test(registerBody)` → `recordProductFinding` with the body head, then falls through to the non-201 `throw` at `:337`. Ordering is right: 429 (`:322-325`) → P0-2 500 → generic non-201 → `!bodyIsJson`. A P0-2 500 now yields a **named finding in the ledger** plus an honest leg failure, and the comment says exactly that ("then the leg fails (nothing to log into)") rather than claiming the journey continues.
- The doc row (`ONBOARDING-CAMPAIGN.md:66`) now states the precondition — "recorded when the server answers the time-limit 500; the client waits up to 300 s to match the hotfix seam" — so the doc/behaviour mismatch that made this a MAJOR is closed.
- Residual (accepted, m-15): a reverse-proxy **504** or a genuine 300 s client-side timeout still produces no finding — the 504 falls into the generic `throw` at `:337`. The doc no longer over-promises, so this is a nicety, not a defect.

## N-2 — RESOLVED (both halves, and they interlock)

- `onboarding.campaign.ts:727` — `if (!reuseMode) expect(value.repositories, …).toHaveLength(2)`. The two criteria that matter (one location-owned cash register `:723`, one safe `:725`) still run in reuse mode; only the exact-count pin is relaxed, which is the minimum correct relaxation.
- `onboarding.campaign.ts:409` — the L4 bank repository is now created with `location_id: requiredState('locationId')`. **Verified against the server, since run 22 predates this line:**
  - the field is accepted and scoped, not silently dropped — `PaymentRepositoryController::store()` validates `'location_id' => ['nullable','uuid', ScopedExists::company('locations', $companyId)]` (`apps/api/.../PaymentRepositoryController.php:94`) and persists it (`:131`); there is no type-conditional rule that would reject a location on a `bank_account`;
  - **it cannot trip the duplicate-drawer refusal** — the partial unique index is `ON payment_repositories (company_id, location_id, type) WHERE location_id IS NOT NULL AND type IN ('cash_register','safe')` (`database/migrations/tenant/2026_08_26_100000_backfill_payment_repository_location_n12.php:432-433`), and `bank_account` is outside that set. Repeat runs against the same tenant cannot collide, so L4 will not start refusing (`:138-140`).
  - Bonus: this also narrows r2's **m-10** — the campaign no longer creates the one null-location row that would have been attributed to every company by the census filter (`:710`).

## N-3 — RESOLVED

`README.md:11` now "print the generated login … (every run's tenant is retained regardless — there is no teardown)"; `README.md:13` now "never mutates **another run's** tenant (reuse mode, below, is the deliberate exception)" — both true and both consistent with `:22`. `ONBOARDING-CAMPAIGN.md:22` no longer carries the "retains and prints" claim at all and now states the Parapharmacy + Tunisia constraint at the top of the operator path, where it is actually read. No file now contradicts another.

## N-4 — RESOLVED

`ONBOARDING-CAMPAIGN.md:51` — "**Push trigger stays INERT.** Do not set the repository variable `ONBOARDING_CAMPAIGN_ON_PUSH=true` until three items close: fixtures parameterised per country, reuse mode hardened for post-L4 tenants, and a tenant teardown for the target." That is M-5, M-6 and M-8 named one-for-one, and it is co-located with the workflow's own inert-by-default guard (`.github/workflows/onboarding-campaign.yml:35`). The documented-deferral precondition is now satisfied.

## N-5 — RESOLVED

`docs/glossary.md:51` now reads "an additional company created via `POST /api/v1/companies` gets its own drawer + safe since G-3c (dev `9badbe294`, 2026-08-29)" and carries the real open gap with its ledger id — "`GET /payment-repositories` is tenant-scoped (company B lists company A's rows — LEDGER D-J0-8)". The falsified I2-F1 claim is gone from the authoritative surface, and the leak is named where a future reader will find it. Cosmetic only: the trailing `` (`LocationController::provisionCashRegisterIfPosEnabled`) `` now dangles off the D-J0-8 sentence instead of the `pos_enabled` clause it documents.

## `e2e/tsconfig.json` scoping — acceptable, and it does NOT weaken the campaign

`include` went from `["**/*.ts"]` to `["campaign/**/*.ts", "../playwright.campaign.config.ts"]`.

- **The error is real, pre-existing, and entirely Session H's.** I reproduced it without touching the repo (scratchpad tsconfig extending the repo one, include `e2e/**/*.ts`): exactly two errors, both `e2e/session-h/m1-data-shape-drift.spec.ts(33,12)` and `(52,42)` — `error TS2304: Cannot find name 'APIRequestContext'` (a missing `@playwright/test` type import). Nothing from the campaign. It arrived with the dev merge `dc0ba1ad0`.
- **The campaign's own coverage is unchanged.** `tsc --listFiles` on the scoped project reports **all 6** campaign sources (`selectors.ts`, `journey.ts`, `onboarding.campaign.ts`, `fiscal/{canonicalCore,FiscalEventCanonicalEncoder,events}.ts`) plus `playwright.campaign.config.ts`. Same `compilerOptions` (strictness untouched — only the root set narrowed). `pnpm typecheck:e2e` clean; `pnpm exec eslint e2e/campaign playwright.campaign.config.ts` → 0 errors / 0 warnings.
- **What it costs:** `e2e/session-h/**` loses its only typecheck path — `apps/web/tsconfig.json` includes `["src"]` only, so the root `pnpm typecheck` never covered e2e. That cost is close to zero in gate terms because `typecheck:e2e` appears **only** in `apps/web/package.json:21` — it is in no workflow under `.github/` and not in `scripts/preflight.sh`, so Session H's files were never CI-gated either; the command was simply red on dev for anyone running it locally. Scoping hides a dev-red rather than creating one.
- **Verdict on it:** acceptable for this lane. Flag to the orchestrator (not to I-1): `e2e/session-h/m1-data-shape-drift.spec.ts` needs `import type { APIRequestContext } from '@playwright/test'` from its owning session, and once fixed the include should widen back so the next e2e author is not silently unchecked.

## Run 22 — what the live ledger independently proves

```
L0a PASS · L0 FAIL(finding) · L1 PASS · L2 FAIL(finding) · L3–L8 PASS · L9 NOT_SCRIPTABLE · L10 FAIL(gate)
findings = exactly 2: [L0 repositories-index tenant scope] [L2 products import never resolves unit → unit_id]
```
- **B-1 is dead by evidence, not just by reading.** Company 2's census line records `cash_register:CASH-01@01a04f6f-44d3-…, safe:SAFE-01@01a04f6f-44d3-…` — both on company 2's **own** location, distinct from company 1's `01a04f6e-a087-…`, followed by the evidence line "company 2 repositories: 1 cash register on … + 1 safe". No I2-F1 finding.
- **The census scoping works live.** Company 2's evidence lists **two** repositories, not four — the tenant-wide leak was filtered out of the retained set while being recorded once as its own finding, on L0, with a non-null `tenantId`. Exactly the r2 (a) design.
- **M-3 / M-4 are live-verified**, not just read: L7's evidence line is `v4 refund …; stock+lot restored=20.000; POS-refund payment; drawer=1000.000; trial balanced after POS`, and L6 passed with the new `1023.800` drawer pin in place — so the sale→refund pair is now genuinely discriminated.
- **M-9** L1 PASS with the partner re-count active.
- L10 correctly red on two **true** findings. A finding still cannot go green.

## Fiscal lens on the delta

Nothing in `42fdde50d` touches an event body, an amount, a hash, or a projection. Re-checked after the dev merge: `apps/web/e2e/campaign/fiscal/{canonicalCore,FiscalEventCanonicalEncoder}.ts` are still **byte-identical** to `apps/pos/src/lib/fiscal/…` (`diff` exit 0 for both), so the merge did not drift the vendored canonicaliser. Float sweep across `e2e/campaign` excluding the vendored SHA-256: **zero hits**. No per-line TTC-vs-HT assertion was introduced. The campaign still only reads projections — it never re-authors a device-signed fact.

## Remaining minors (none blocking; fold into the I-1 follow-up)

- **m-12 (carried)** — `onboarding.campaign.ts:203-205` still comments "`CompanyController::store()` never calls `PaymentRepositorySeeder`, so a second company cannot take cash". That is now contradicted by the glossary row corrected in this very commit. Delete or rewrite to "regression detector: G-3c provisions both rows".
- **m-15 (new)** — a 504 / no-response registration still records no P0-2 finding (`journey.ts:337`).
- **m-16 (new)** — glossary `:51` trailing parenthetical is misplaced (cosmetic).
- r1 m-1, m-2, m-4, m-5, m-6, m-7, m-8 and r2 m-9, m-11, m-13, m-14 remain open as accepted minors.

## FINAL VERDICT: **MERGEABLE** into local dev

B-1 dead (evidence, run 22), M-1/M-2/M-3/M-7/M-9 + N-1..N-5 resolved, M-4 partial and M-5/M-6/M-8 deferred **with the precondition now written down** (`ONBOARDING-CAMPAIGN.md:51`). Merge with `ONBOARDING_CAMPAIGN_ON_PUSH` left unset, and hand `e2e/session-h/m1-data-shape-drift.spec.ts` (TS2304 ×2) back to Session H so the e2e typecheck can be widened again.
