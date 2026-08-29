# Lane F2 — adversarial frontend-conventions gate r3 (after fix round 2)

**VERDICT: MERGEABLE — r2 P2-A is CLOSED, and closed honestly (mutation-proved, not asserted). N-2 and N-4 are closed. 0 P1, 0 P2, 5 P3 (4 carried from r2 and explicitly out of the fix-round brief, 1 new latent-coupling note). No new behaviour risk found in the `currentCompanyId` subscription.**

- Branch `fix/f-bug-2-company-switch` @ `797a565de` (3 commits), worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/f-bug-2`, base `dev` = `a4ceeb0f5`.
- Scope: round 2 = `git diff 7ab135511...HEAD -- apps/web` (8 files, +113/−84); full lane = `git diff dev...HEAD -- apps/web` (14 files, +713/−65, `apps/web` only).
- Reviewer touched **no lane source**. Red-ness claims were verified in an isolated copy of `apps/web/src` under the scratchpad (node_modules/packages symlinked), deleted afterwards; `git status --porcelain -- apps/web` in the worktree is empty.

## Gates re-run by the reviewer (nothing taken on report)

| Gate | Command | Result |
|---|---|---|
| Vitest (touched dirs + `src/lib`) | `pnpm vitest run src/features/company src/stores src/components/organisms/AddCompanyModal src/components/organisms/CompanySelector src/lib` | **30 files / 213 tests passed** (identical count to r2: −1 deleted synthetic test, +1 new `CompanySelector` test) |
| Typecheck | `pnpm typecheck` | clean |
| ESLint (8 touched files only; full `pnpm lint` deliberately NOT run per machine rule) | `npx eslint <8 paths>` | **0 errors**, 9 warnings — all pre-existing rules on untouched lines (`no-unnecessary-template-expression`, `no-unsafe-type-assertion`, and `react-refresh/only-export-components` at `CompanyProvider.tsx:155`, which is the `useInvalidateCompanies` export and pre-dates the lane) |
| `audit:keys` | `node tools/audit-tanstack-keys.mjs` | Gate C: **0** violations, 0 new, 0 stale |
| `audit:design-system` | `node tools/audit-design-system.mjs` | **807 acknowledged, 0 new, 0 stale** |
| Baseline honesty | `git diff dev...HEAD --stat` | `audit-design-system-baseline.json` **not in the diff** — no `--write-baseline` absorption in either round |
| Mechanism audit (round 2) | `git diff 7ab135511...HEAD \| grep -E '^\+.*(eslint-disable\|@ts-(ignore\|expect-error)\|as any\|: any\|\.skip\|\.only\|expect\(true\))'` | **no matches** — no suppression, no `any`, no detector evasion, no tautological assertion |

Convention spot-checks, all clean: round 2 is pure deletion + test work; **no TSX styling touched**, so no token/interpolation surface; no new user-facing strings; no raw form controls/tables/pickers; money/quantity untouched; no route/nav change.

---

## r2 findings — disposition

### P2-A (`invalidateCompanies()` a guaranteed no-op on both create paths + a synthetic test) — **CLOSED**

Both calls are gone: `apps/web/src/features/company/CompanyOnboardingPage.tsx:65-68` (`onSuccess` is now `adoptCreatedCompany(data)` → `void navigate('/dashboard')`) and `apps/web/src/components/organisms/AddCompanyModal/AddCompanyModal.tsx:68-72`. The removal is a genuine no-behaviour-change (conservation): r2 proved the call could never match a cache entry, so deleting it removes documentation of a mechanism that never fired, not a mechanism.

The replacement test is honest on all three counts I asked for:
1. **Real provider, production composition.** `apps/web/src/features/company/CompanyOnboardingPage.test.tsx:110-119` renders `QueryClientProvider > CompanyProvider > CompanyOnboardingPage`. I verified that mirrors production rather than a convenient tree: `apps/web/src/App.tsx:29-36` wraps `AppRoutes` in `CompanyProvider`, and `/company-onboarding` is a plain route inside it (`apps/web/src/routes/index.tsx:543-552`) — "outside the layout" ≠ outside the provider.
2. **Real fetch under the NEW key, zero pre-seeding.** `grep -n setQueryData src/features/company/CompanyOnboardingPage.test.tsx` returns **nothing** — the two r2 pre-seeds are deleted. The test mocks only the network boundary (`api.get`, `CompanyOnboardingPage.test.tsx:31-37,136-139`) and asserts one fetch before submit and a second after (`:216-218,229`), with `getQueryData(['user','companies','tenant-A','company-created'])` deep-equal to the mapped `[oldCompany, createdCompany]` (`:224-227`) and the store on the created id (`:230`).
3. **Mutation-proved red.** In the isolated copy I replaced `queryKey: tenantScopedKey(['user','companies'])` with `['user','companies', tenantId, null]` — literally the r2 P2-A failure scenario ("someone simplifies the key to a non-re-keying one"). Result: **3 failed / 12 passed**, including this test. Under the r2 code that mutation stayed green. The regression class P2-A described is now caught.

### N-2 (tenancy — mounted switch path uncovered) — **CLOSED, mutation-proved**
`apps/web/src/components/organisms/CompanySelector/CompanySelector.test.tsx:132-161` mounts `CompanyProvider > CompanySelector` with the OLD-scope list already cached (`:137-140`, legitimate: that is the state a mounted app is in), switches company, and asserts the NEW key is `fetching`, that `api.get('/user/companies')` was actually issued, that `invalidateQueries` fired once, and that the selector is **still on screen**. Reverting the r1 loader clause (`CompanyProvider.tsx:124`, `… && companies.length === 0` → `… isLoading`) turns this test **RED** along with `CompanyProvider.tenantScope.test.tsx` "keeps children mounted" and the new onboarding test (**3 failed / 12 passed**). The clause is now genuinely guarded.

### N-4 (unreachable `invalidationScheduledRef`) — **CLOSED**
`apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:41-48` is now `previousCompanyIdRef` + unconditional `queueMicrotask`. The r2 dead branch is gone; both `CompanySelector` tests still pass, so the `queueMicrotask` ordering guarantee is intact.

### The dead-ref/synthetic-test cleanup asked for by the brief — **DONE**
`AdoptAndInvalidateCompaniesButton` and its test are removed from `CompanyProvider.tenantScope.test.tsx` (the `.107` precision test and its `InvalidateCompaniesButton` are correctly retained at `:82,:211-234`). The `AddCompanyModal` ordering test keeps the adopt+persist assertions and drops only the invalidate-ordering ones (`AddCompanyModal.ordering.test.tsx:82-104`).

---

## The new `currentCompanyId` subscription — asked-for regression analysis

**It is load-bearing, and it is pinned.** `CompanyProvider.tsx:71-73`. `tenantScopedKey` reads `getState()` without subscribing (`apps/web/src/lib/tenantScopedKey.ts:32-34`), so the key only re-computes if the host component re-renders; a selection-only switch leaves `companies` referentially identical, so before this the provider did not re-render and did not re-key. Mutation test: deleting the selector line + its dep turns **exactly one** test red — the new `CompanySelector` N-2 test (**1 failed / 14 passed**). Causal and covered.

**No regression found from re-running the `setCompanies` effect.** Four things I checked rather than assumed:
- *Foreign-scope data can never be written.* `observer.getOptimisticResult(defaultedOptions)` is computed **during render** in the installed `@tanstack/react-query@5.90.11` (`node_modules/@tanstack/react-query/build/modern/useBaseQuery.js:53`; `setOptions` in the effect at `:68` only commits it). So in the very render where `currentCompanyId` changes, `data` already belongs to the NEW key — for an uncached key that is `undefined` with `isLoading: true`, and the effect takes the `setLoading(true)` branch (`CompanyProvider.tsx:100-108`). It never calls `setCompanies` with the previous scope's list.
- *Re-persist is a no-op rewrite.* When the new key IS cached, `setCompanies` re-runs, `resolveCompanySelection` keeps the current id (`companyStore.ts:109-111`) and `persistCompanyId` rewrites the same string. Characterisation test in the isolated copy (both keys cached with the same list, real switch A→B): selection stays `B`, `companies` unchanged, `localStorage['autoerp-company-selection'] === 'B'`.
- *No loop.* `setCompanies` re-writes the same `companies` array reference and an unchanged `currentCompanyId`, so zustand's selector equality suppresses any re-render; even if `resolveCompanySelection` did move the id, the second pass is a fixed point (bounded at 2 iterations).
- *No storage-listener ping-pong and no new `handleCompanyScopeRejection` cycle.* `persistCompanyId` writing the same value raises no same-tab `storage` event, and other tabs bail on `nextId !== currentCompanyId` (`companyStore.ts:258`). The 403 path's loop-freedom invariant is the denied-set (`companyStore.ts:105`, `lib/api.ts:205-206`), which the extra effect run does not touch — `reset()` empties `companies`, so the re-bootstrap is the same one-shot self-heal as on `dev`.

The one behaviour delta I could construct is P3-1 below.

---

## P3 findings (none blocking)

### P3-1 (new) — the effect **dependency** is decorative; only the subscription is load-bearing, and the dep opens one latent revert path
`apps/web/src/features/company/CompanyProvider.tsx:109`. Mutation test: keep the `currentCompanyId` subscription but drop it from the deps array → **15/15 green**. Nothing in the suite pins the dep, and the comment at `:71-72` justifies the *subscription*, not the dep. The dep's only effect is an extra `setCompanies` run per switch. Characterisation: if the target scope's `['user','companies',tenant,B]` entry ever held a list that OMITS `B`, that extra run makes `resolveCompanySelection` silently revert the pick to the primary (isolated-copy probe: picking `B` ended on `A`); on `dev` the switch would have stuck. **Not reachable today** — `/user/companies` is company-context exempt (`lib/api.ts:262-265`), so every per-company entry holds the same membership list, and a genuinely B-less list means the membership really went away, where reverting is correct. Recorded so the next person does not discover it by accident.
**Fix directive:** drop `currentCompanyId` from the dep array at `:109` (keep the subscription) and move the `:71-72` comment onto the selector line, or add a test that pins the dep as intentional.

### P3-2 (new) — `useInvalidateCompanies` is now dead production code kept against the brief's own instruction
`apps/web/src/features/company/CompanyProvider.tsx:152-169`. `grep -rn useInvalidateCompanies src/` returns the definition plus **only** `__tests__/CompanyProvider.tenantScope.test.tsx:11,82` — zero non-test callers. The fix brief said to delete the hook and keep it only "if any [callers] remain"; none do. Net result: an exported hook nobody calls, a `.107` test guarding a call site that does not exist, and the `react-refresh/only-export-components` warning at `:155`. Owner rule "anything that works must be reachable" applies. It is P3, not P2, because the hook is inert and its comment block (invalidation filters match as positional prefixes — do NOT wrap them in `tenantScopedKey`) is genuinely valuable knowledge.
**Fix directive:** delete the hook and `.107`, relocating the prefix-semantics comment to `lib/tenantScopedKey.ts`; or record the retention rationale in the lane record.

### P3-3 (carried, r2 P3-1) — loader gate un-masks a cross-tenant render window on impersonation start
`CompanyProvider.tsx:124` + `src/features/support-access/pages/AdminSupportAccessPage.tsx:42-63` (`queryClient.clear()` + `setAuth` with a new tenant, no company/location store reset). Unchanged this round; the brief forbade touching that page.
**Fix directive:** in the deferred support-access lane, call `useCompanyStore.getState().reset()` and `useLocationStore.getState().reset()` next to `queryClient.clear()`.

### P3-4 (carried, r2 P3-3) — app-wide cache policy still lives in a presentational header component
`CompanySelector.tsx:38-48`. Now slightly more load-bearing: with `invalidateCompanies()` deleted, the create path performs **no** `invalidateQueries` at all (the selector is unmounted on `/company-onboarding`). That is correct — every other query re-keys and refetches on the post-create navigation, and old-scope entries can never be served to the new scope — but the whole scheme rests on `TopBar` being mounted.
**Fix directive:** move the effect to `CompanyProvider` in a follow-up, or comment the mount dependency at `:38`.

### P3-5 (carried, r2 P3-5/P3-6/P3-7) — unchanged, all out of the fix-round brief
Two divergent `legalName` mappings (`features/company/api.ts:106` `?? name` vs `CompanyProvider.tsx:39` raw, with `:15` typing `legal_name: string` on a nullable column); `AddCompanyModal` still mounted nowhere (barrel re-exports only: `components/organisms/index.ts:6`, `features/company/AddCompanyModal.tsx:2`); the bootstrap test still drives `isAuthenticated` false→true directly (`CompanyProvider.tenantScope.test.tsx:174-217`) instead of exercising `AuthProvider` + a persisted session.

---

## Out-of-lane observation (NOT a finding — pre-existing and untouched)

`CompanyProvider.tsx:136` renders the "no companies" screen instead of children whenever `companies.length === 0 && !isLoading` on any non-`/admin` route, including `/company-onboarding`. A user with zero companies therefore cannot see the onboarding page. The line is byte-identical to `dev` (`git diff dev...HEAD` on that file touches only `:1,68-75,106-121,124,150-169`), and today the page is only reachable from the selector (which requires ≥1 company), so the lane neither causes nor worsens it. Worth a ticket in a first-run lane.

## What is correct and must not be re-litigated

- The Task-1 boot guard (`CompanyProvider.tsx:75,113-121`) and the r1 loader clause (`:124`) are untouched by round 2 and now mutation-verified as guarded.
- `queueMicrotask` in `CompanySelector.tsx:44` remains required by TanStack v5's effect-time `setOptions` + React's child-first passive-effect order.
- Deleting `invalidateCompanies()` from both create paths is a conservation-preserving deletion, not a behaviour change: r2 proved the call matched nothing.
