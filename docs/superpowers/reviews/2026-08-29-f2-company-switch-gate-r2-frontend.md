# Lane F2 — adversarial frontend-conventions gate r2 (after fix round 1)

**VERDICT: CHANGES — 1× P2 (an r1 P2 that is only half-closed), 7× P3. No P1. Behaviour on this branch is correct and strictly better than `dev`; the P2 is a mechanism/test-honesty defect that leaves a green test guarding nothing.**

- Branch `fix/f-bug-2-company-switch` @ `7ab135511` (2 commits), worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/f-bug-2`, base `dev` = `a4ceeb0f5`.
- Scope reviewed: full lane diff `git diff dev...HEAD` = 14 files, +674/−55, **`apps/web` only** (fix round stayed FE-only as briefed).

## Gates re-run by the reviewer (nothing taken on report)

| Gate | Command | Result |
|---|---|---|
| Vitest (all touched dirs + `src/lib`) | `pnpm vitest run src/features/company src/stores src/components/organisms/AddCompanyModal src/components/organisms/CompanySelector src/lib` | **30 files / 213 tests passed** (r1 was 28/205; +`CompanySelector.test.tsx`, +`api.test.ts`) |
| Typecheck | `pnpm --filter @autoerp/web typecheck` | clean |
| ESLint (all touched paths) | `npx eslint <14 changed paths>` | **0 errors**, 15 warnings — all on untouched lines; the two NEW files emit zero |
| `audit:keys` / `audit:design-system` / `audit:quantity` / `audit:i18n:local` | run individually | **all OK** |
| eslint-rules RuleTester | `pnpm test:eslint-rules` | all rules pass |
| `pnpm test:tools` | `pnpm test:tools` | **159/160 — 1 failure, NOT this lane** (see "Environment" below) |
| Full `pnpm lint` | `pnpm --filter @autoerp/web lint` | exit 1 **only** because of that `test:tools` flake; eslint + all four audits + RuleTester legs are green |
| Baseline honesty | `audit-design-system-baseline.json` | **not in the diff** — no `--write-baseline` absorption |
| Mechanism audit | `git diff dev...HEAD \| grep -E '^\+.*(eslint-disable\|@ts-(ignore\|expect-error)\|as any\|: any\|\.skip\|\.only)'` | **no matches** — no suppression, no `any`, no detector evasion |

Convention spot-checks, all clean: no new user-facing strings (i18n mocks return keys); **no TSX styling touched** in the fix round (CompanySelector/CompanyProvider changes are logic-only) so no token/interpolation surface; `tenantScopedKey` used correctly in the new test (`CompanySelector.test.tsx:91`); no raw form controls/tables/pickers; money/quantity untouched. Test-file placement is repo-consistent (co-located `*.test.tsx` matches `TopBar/TopBar.test.tsx`, `purchases/StandaloneReceiptPage.test.tsx`); building an explicit wrapper instead of `renderWithProviders` is what `src/test/renderWithProviders.tsx:60-68` tells tests to do when they need `CompanyProvider`-adjacent composition. `void navigate(...)` (`CompanySelector.tsx:64`, `CompanyOnboardingPage.tsx:71`) is correct for react-router-dom 7 (`navigate` returns a promise) and lint-driven — harmless.

---

## r1 findings — disposition

### P2-1 (live create→switch path untested; tested path is dead code) — **CLOSED (main half)**, residual P3
`apps/web/src/features/company/CompanyOnboardingPage.test.tsx:150-159` now drives the real wizard to submit and asserts `useCompanyStore.getState().currentCompanyId` **and** `localStorage['autoerp-company-selection']`. The page test no longer stubs `@tanstack/react-query` or `./CompanyProvider`, so the real mutation + real `useInvalidateCompanies` run. The `AddCompanyModal` ordering test is retained and additionally pins adopt-before-invalidate (`AddCompanyModal.ordering.test.tsx:89-93,113-114`). Residual: the orphan itself is still an orphan → **P3-6** below.

### P2-2 (create-response mapping executed by zero tests) — **CLOSED**
`apps/web/src/features/company/api.test.ts:16-69` mocks `apiPost` with a literal 31-key `CompanyController::formatCompany` payload (including `legal_name: null`) and asserts the mapped `Company` field by field. This is the one place where mocking a payload is legitimate (pure mapping), and it is the only mock in the file.

### P2-3 (adopt re-keys → full-screen loader unmounts the app → LocationProvider guard destroyed) — **HALF CLOSED → carried forward as P2-A**
- **Unmount harm: CLOSED.** `apps/web/src/features/company/CompanyProvider.tsx:121` now gates the loader on `... && companies.length === 0`, and `CompanyProvider.tenantScope.test.tsx:285-323` proves a populated store re-keying to a pending query keeps children mounted (`childUnmounted` never called). With the subtree alive, `LocationProvider`'s `previousCompanyIdRef` (`features/locations/LocationProvider.tsx:50-58`) survives and `resetForCompanyChange()` fires on the create-driven switch — `App.tsx:29-36` confirms LocationProvider wraps every route including `/company-onboarding`. The stale-`currentLocationId` scenario from r1 is gone.
- **Causality half: NOT CLOSED.** See P2-A.

### P3-1 (Task 3 suppressed a console.error, not the reported "Access denied") — **CLOSED by a better mechanism**
The fix round re-rooted this: the blanket invalidation moved out of the click handler into an effect (`CompanySelector.tsx:42-53`). I verified the mechanism independently rather than taking the brief's word:
- `@tanstack/react-query@5.90.11` applies a new query key in `useBaseQuery`'s **effect** (`build/modern/useBaseQuery.js:68`, `React.useEffect(() => { observer.setOptions(...) })`), and passive effects run child-first — so a deep child like `CompanySelector` legitimately cannot invalidate inside its own effect. `queueMicrotask` is load-bearing and correct: it runs after the whole passive-effect flush, i.e. after every observer has re-keyed.
- The stale `location_ids` half is independently safe: `stores/viewScopeStore.ts:96-105` subscribes to the company store at module scope and `_hydrate('all')` on a real company change **synchronously inside `switchCompany`**, so a post-fix refetch cannot carry the previous company's location ids.
- `CompanySelector.test.tsx:79-118` pins the ordering: the old-company `queryFn` is called exactly once across the switch while `invalidateQueries` is called once and the new-company `queryFn` runs. Under the pre-fix code the synchronous `invalidateQueries()` would have refetched the then-still-active old-key observer, so the assertion is genuinely red on `dev` (verified by construction, not re-executed — I do not mutate lane source).
- Still open as a **note**: nobody asked the tester whether the noise was on-screen or in devtools. `grep -rn "Access denied" apps/web/src` still returns only the log and its own test; no toast/UI copy is wired.

### P3-2 (unguarded `error.config.headers[...]` on every errored status) — **CLOSED**
`apps/web/src/lib/api.ts:319-321` narrows through `isRecord` before indexing, and `api.companyScope.test.ts:273-297` pins that an `AxiosError` whose config has no `headers` propagates untouched. The added `currentCompanyId !== null` clause (`api.ts:322-324`) is also pinned (`api.companyScope.test.ts:259-271`) and does not disturb `handleCompanyScopeRejection`, which already bails on `sentCompanyId !== currentCompanyId` (`api.ts:200-202`) — a stale 403 still cannot mark the OLD company denied.

### P3-3 (two divergent `legalName` mappings) — **NOT CLOSED** (out of brief scope) → P3-5 below

### P3-4 (`adoptCreatedCompany` omits `isPrimary`) — **CLOSED**
`apps/web/src/features/company/api.ts:112` sets `isPrimary: false` explicitly and `api.test.ts:68` asserts it. The backend formatter returns no `is_primary`, so `false` is the only honest value; a first-ever company is corrected by the list refetch, and `resolveCompanySelection` short-circuits on the explicit `currentCompanyId` (`companyStore.ts:106-108`) so the temporarily-wrong flag cannot move the scope. Also closed in the same commit: `default_target_margin` / `default_minimum_margin` typed `string | null` (`api.ts:50-51`), matching `bcformatOrNull`.

### P3-5 (bootstrap test simulates the boot) — **NOT CLOSED** (out of brief scope) → P3-7 below
### P3-6 (cross-tab ignores a created company) / P3-7 (`/admin` no longer clears a leftover selection) — **carried as notes**, as r1 directed. No lane record file exists for them, so they are recorded here.

---

## P2 findings

### P2-A — `invalidateCompanies()` is a guaranteed no-op on BOTH create paths, and the new test only passes because it pre-seeds a cache entry that cannot exist
`apps/web/src/features/company/CompanyOnboardingPage.tsx:69-70` and `apps/web/src/components/organisms/AddCompanyModal/AddCompanyModal.tsx:72-74` now call `adoptCreatedCompany(data)` then `void invalidateCompanies()`. `useInvalidateCompanies` reads the company at call time (`CompanyProvider.tsx:162-165`), so it targets `['user','companies',tenantId,NEW_ID]`. But at that instant **no query exists under that key**:

1. `onSuccess` runs in a promise continuation, not a React event handler.
2. `adoptCreatedCompany` (`companyStore.ts:200-209`) is a zustand `set`; `CompanyProvider` observes it through `useSyncExternalStore`, whose forced re-render is a Sync-lane update flushed in a **later microtask** — not synchronously inside `onSuccess`.
3. So when line 70 executes, `CompanyProvider` is still keyed to `['user','companies',tenantId,OLD_ID]` (`CompanyProvider.tsx:78`); `queryCache.findAll({queryKey:[...NEW_ID]})` returns `[]` and `invalidateQueries` does nothing.
4. The list still refreshes — because the provider re-keys one microtask later and fetches. **Exactly the r1 P2-3 complaint, relocated:** the refresh works for a reason nobody wrote down, and the code documents a mechanism that never fires.

The evidence for the opposite is synthetic: `CompanyOnboardingPage.test.tsx:163-164` calls `queryClient.setQueryData(['user','companies','tenant-A','company-created'], …)` before submitting — a cache entry for a company id that, by definition, has never been fetched. `CompanyProvider.tenantScope.test.tsx:264-283` renders the adopt+invalidate button with **no `CompanyProvider` in the tree** at all. No test combines the provider with a create-driven adopt, which is the only configuration that exists in production.

Concrete failure this lets through: someone later notices that a *list of companies* need not be company-scoped and simplifies `CompanyProvider.tsx:78` to a non-re-keying key (or the provider stops subscribing to `companies`). The re-key disappears, the invalidation is still a no-op, and a freshly created company never appears in the selector — while `CompanyOnboardingPage.test.tsx:161-178` stays green because it pre-seeds its own cache entry.

**Fix directive (small, either arm is acceptable):** (a) add one test that renders `CompanyProvider` around the create flow and asserts the companies endpoint is actually re-fetched after adoption — then delete the two `void invalidateCompanies()` calls and the hook if the re-key alone carries it; or (b) keep the call and make the re-key causal by having `CompanyProvider` subscribe to `currentCompanyId`, with a comment at `CompanyProvider.tsx:162` stating that the invalidation is a defensive no-op on the create path.

---

## P3 findings

### P3-1 — The loader gate newly un-masks a cross-tenant render window on impersonation start
`apps/web/src/features/company/CompanyProvider.tsx:121`. `AdminSupportAccessPage.start` (`src/features/support-access/pages/AdminSupportAccessPage.tsx:42-63`) does `queryClient.clear()` + `setAuth({… tenant_id: <other tenant> …})` + `navigate('/dashboard')` and **never resets `useCompanyStore`**. `isAuthenticated` stays `true`, so `CompanyProvider`'s `wasAuthenticated` guard (`:110-118`) does not reset either. Before this lane the full-screen loader covered that window; now `companies.length > 0` (the previous tenant's in-memory list) skips the loader, so the app renders the previous tenant's company name in the selector and sends its `X-Company-Id` until the new tenant's `/user/companies` resolves. Self-healing (the 403 path resets the selection) and narrow (requires a tenant session in the same tab before visiting `/admin`), and the brief explicitly forbade touching that page this round.
**Fix directive:** in the deferred support-access lane, call `useCompanyStore.getState().reset()` and `useLocationStore.getState().reset()` next to `queryClient.clear()` at `AdminSupportAccessPage.tsx:42`.

### P3-2 — The `invalidationScheduledRef` coalescing guard is unreachable, untested defensive code
`apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:45-51`. The branch is only entered when `previousCompanyIdRef.current !== currentCompanyId`, and that ref is updated synchronously at `:44` before the guard is read — so a repeat entry for the same value is impossible, and a second entry for a *different* value would require two commits inside a single microtask checkpoint, which React's passive-effect scheduling never produces. StrictMode's double-mount is also inert here (refs survive the simulated remount and `previousCompanyIdRef` is initialised to the current id at `:20`, so mount never invalidates). Net: three lines that cannot execute and that no test exercises.
**Fix directive:** delete `invalidationScheduledRef` and schedule the microtask unconditionally, or add a test that fires two switches inside one `act()` and asserts a single `invalidateQueries`.

### P3-3 — App-wide cache policy now lives in a presentational header component
`apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:42-53` broadened from "invalidate on MY click" to "invalidate on ANY `currentCompanyId` transition, if I happen to be mounted". Cross-tab adoption (`companyStore.ts:247-266`) now correctly triggers a refetch — an improvement — but any switch performed while `TopBar` is unmounted invalidates nothing; `/company-onboarding` is deliberately routed outside the layout (`src/routes/index.tsx:542-550`), so the create path relies entirely on the subsequent navigation remounting queries. It works today; the coupling is invisible.
**Fix directive:** move the effect to `CompanyProvider` (the app-level owner of company scope) in a follow-up, or add a comment at `:39` naming the mount dependency.

### P3-4 — `useInvalidateCompanies` mixes a render-time tenant with a call-time company
`apps/web/src/features/company/CompanyProvider.tsx:154` (reactive `tenantId`) vs `:163` (`getState()` company). The pinned `.107` precision test still passes (`CompanyProvider.tenantScope.test.tsx:244-262`, re-run green), so nothing is broken, but the two halves of the same key now resolve at different times.
**Fix directive:** read both from `getState()` at call time, or state the asymmetry in the existing comment block.

### P3-5 — Two divergent `legalName` mappings, now half-pinned (r1 P3-3, still open)
`apps/web/src/features/company/api.ts:106` (`company.legal_name ?? company.name`) vs `CompanyProvider.tsx:39` (raw), with `CompanyProvider.tsx:15` still typing `legal_name: string` while the column is nullable. `api.test.ts:62` now pins the `?? name` side, so the divergence is codified on one side only: create a company with no legal name → the selector's subtitle is correctly hidden after adoption (`CompanySelector.tsx:105`), then an empty subtitle appears after the refetch.
**Fix directive:** apply `?? name` in `mapCompanyResponse` too, or type `legalName: string | null` and render conditionally.

### P3-6 — `AddCompanyModal` is still mounted nowhere (r1 P2-1, second half)
Only non-test references are the barrel re-exports `src/components/organisms/index.ts:6` and `src/features/company/AddCompanyModal.tsx:2`. Retention was a deliberate brief decision ("keep the modal test too"), and the live path is now covered, so this is no longer a coverage risk — but it remains a duplicated surface under the owner rule that anything that works must be reachable and duplicated surfaces resolve to ONE canonical mount.
**Fix directive:** delete the component + both barrel exports in a cleanup lane, or record the retention rationale in the lane record.

### P3-7 — The bootstrap test still simulates the boot (r1 P3-5, out of brief scope)
`CompanyProvider.tenantScope.test.tsx:174-217` still drives `isAuthenticated` false→true directly instead of exercising `AuthProvider` + a persisted `autoerp-auth` payload, so the two-store ordering fact that actually causes the bug is untested.
**Fix directive:** unchanged from r1 — render `AuthProvider > CompanyProvider` with a pre-seeded localStorage session and a deferred `/auth/me`.

---

## Environment note (not a lane finding)

`pnpm --filter @autoerp/web lint` exits 1 on this machine, at its **last** stage only: `tools/__tests__/offset-pagination-meta-consolidation.test.mjs > is reused by every production offset-pagination response type` times out at 5000 ms. Re-run in isolation it passes at **4969 ms** — a 31 ms margin on a repo-wide scan, made fatal by four concurrent ESLint runs and a PHPUnit lane on this box. The lane touches no pagination or type files. The implementer's summary claim "lint passes (0 errors; 160 tool tests)" did not reproduce here (159/160).
**Repo hygiene directive (separate lane):** give that test an explicit `testTimeout` rather than leaving a knife-edge default.

## What is correct and must not be re-litigated

- The Task-1 boot guard (`CompanyProvider.tsx:72,110-118`) is untouched by the fix round, as briefed.
- `adoptCreatedCompany` still deliberately bypasses `resolveCompanySelection`, and `setCurrentCompany`'s membership guard is intact (`companyStore.ts:186-198`).
- The `queueMicrotask` in `CompanySelector.tsx:47` is not cargo cult — it is required by TanStack Query v5's effect-time `setOptions` and React's child-first effect order (verified against the installed `useBaseQuery.js:68`).
- Request count on a create improved: `dev` did two `/user/companies` round trips (invalidate old key + re-key); this branch does one.
