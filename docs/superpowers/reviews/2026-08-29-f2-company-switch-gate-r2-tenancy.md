# Lane F2 — adversarial gate r2 (tenancy/authz reviewer)

**VERDICT: MERGEABLE** — spec ✅ (all six in-scope r1 findings closed, each verified red→green against the pre-fix source) + quality APPROVED. No P1, no P2. Five P3s and one Minor below, all follow-up-able; none block.

Scope reviewed: `git diff dev...HEAD -- apps/web` at `7ab135511` on `fix/f-bug-2-company-switch`,
worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/f-bug-2` — two commits
(`4da24fddb` original, `7ab135511` fix round 1), 14 files, +674/−55. Nothing uncommitted
(`git status --short` = only the two untracked r1 review docs). **No backend files touched**, so no
route-middleware (rule 12), permission-catalog/seeder, `config/verticals.php` module-gating, or
queue-context (rule 20) surface is in play. No money/quantity code in the diff
(`git diff dev...HEAD | grep -E '^\+.*(parseFloat|Number\(|toFixed|\(float\))'` → empty), so rule 19
is not engaged.

## Gates run in the worktree

- `pnpm vitest run src/features/company src/stores src/components/organisms/AddCompanyModal src/components/organisms/CompanySelector src/lib`
  → **30 files / 213 tests passed**. Vitest worker pools killed afterwards (`pkill -f 'node \(vitest'`, `ps aux | grep vitest` clean).
- `pnpm typecheck` → **clean**.
- `npx eslint src/components/organisms/CompanySelector src/components/organisms/AddCompanyModal src/features/company src/lib/api.ts src/stores/companyStore.ts`
  → **0 errors**, 12 warnings, all pre-existing style rules (`no-unnecessary-template-expression`,
  `no-unsafe-type-assertion`, `dot-notation`, `react-refresh/only-export-components`) on lines this
  diff did not author.
- `pnpm audit:keys` (Architecture Gate C, the tenant-scoping guard) → **exit 0**,
  `Gate C baseline: 0 acknowledged, 0 new, 0 stale`. The baseline set is literally empty
  (`tools/audit-tanstack-keys.mjs`, `const BASELINED_VIOLATION_KEYS = new Set([]);`) — i.e. **every**
  `useQuery`/`useQueries`/`invalidateQueries` key in `apps/web/src` carries a tenant/company scope.
  That fact is load-bearing for finding N-2/N-3 below.
- Full `pnpm lint` → **green, all 7 legs.** The script is
  `lint:eslint && audit:keys && audit:design-system && audit:quantity && audit:i18n:local &&
  test:eslint-rules && test:tools`; the run reached and passed the **last** leg
  (`test:tools`, 8 files / 160 tests), which by the `&&` chain proves every preceding leg exited 0.
  Note for anyone reading that log: the alarming `fatal: git cat-file deadbeefdeadbeef… bad file`,
  `RATCHET GROWTH … plantedTamperKey` and `FAIL CLOSED: SCANNED SURFACE SHRANK … de|alpha` lines are
  **planted fixtures** inside `apps/web/tools/__tests__/audit-i18n-completeness.test.mjs:293-320` —
  the i18n gate's own self-tests proving it fails closed. That file reports 46/46 passed. They are
  not real gate failures.

## Red→green verification (I did this, I did not take it on trust)

I temporarily restored the pre-fix sources from `4da24fddb` (`CompanySelector.tsx`,
`CompanyProvider.tsx`, `CompanyOnboardingPage.tsx`, `lib/api.ts`), re-ran the suites, then
`git checkout -- apps/web/src` and confirmed the tree is byte-identical to `7ab135511`
(`git status --short` / `git diff --stat` both clean). Result: **6 of the new tests fail** against the
pre-fix source —

- `api.companyScope.test.ts` → `does not suppress a 403 while company scope is re-bootstrapping at null` ✗
- `api.companyScope.test.ts` → `preserves an API rejection whose Axios config has no headers` ✗
- `CompanySelector.test.tsx` → `re-keys active observers before invalidating and never refetches the old company query` ✗
- `CompanyProvider.tenantScope.test.tsx` → `reads the newly adopted company when invalidating in the same event` ✗
- `CompanyProvider.tenantScope.test.tsx` → `keeps children mounted while a populated store re-keys to a pending companies query` ✗
- `CompanyOnboardingPage.test.tsx` → `invalidates the companies list for the newly adopted selection` ✗

Separately, restoring `dev`'s `CompanyOnboardingPage.tsx` makes **both** live-path tests fail
(`switches to and persists the company created through the reachable onboarding flow` +
`invalidates the companies list for the newly adopted selection`), which is the r1 P2-2 regression
guard actually doing its job.

---

# r1 findings — disposition

## P2-1 — Task 3 does not cover the failure mode its own brief describes → **CLOSED**

The suppression predicate was the wrong lever; the fix replaced it with the right one. The blanket
invalidation moved out of the click handler into an effect:

- `apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:42-53` — effect keyed on
  `currentCompanyId`, guarded by `previousCompanyIdRef` (`:20`, `:43-44`), deferred through
  `queueMicrotask` (`:47-50`).
- `CompanySelector.tsx:55-60` — `handleCompanyChange` is now just `switchCompany(companyId)`; the
  synchronous `queryClient.invalidateQueries()` is gone.

I traced the whole production chain rather than trusting the brief's mechanism claim. On a switch A→B:

1. `switchCompany` → `useCompanyStore.setCurrentCompany` (`stores/companyStore.ts:186-198`), a
   synchronous zustand `set`.
2. `stores/viewScopeStore.ts:87-101` — a module-level `useCompanyStore.subscribe` fires **synchronously**
   and `_hydrate('all')` on a real company change, so company A's persisted location subset can never
   be carried into B.
3. `features/locations/hooks/useScopedLocations.ts:13` re-keys on `tenantScopedKey(['company-locations','scoped'])`
   → cache miss → `query.data` undefined → `useViewScope.ts:8-15` yields `allowedIds = []` and
   `effectiveLocationIds = []`.
4. `features/treasury/hooks/useCashPosition.ts:67-72` re-keys via `locationScopedKey(...)` → cache miss
   → auto-fetch carrying `location_ids: []` under `X-Company-Id: B`. **No stale ids.**

So the only way company A's `location_ids[]` ever reached the wire under header B was the OLD
`invalidateQueries()` refetching the still-mounted observer's *previous render's* `queryFn` closure
before React had re-keyed it. With the invalidation deferred past commit, that observer's old key is
inactive and `invalidateQueries()`' default `refetchType: 'active'` no longer refetches it. That is
exactly what `CompanySelector.test.tsx:105-118` asserts (`oldCompanyQuery` called exactly once,
`invalidateQueries` called once, after `observer company-B` is on screen), and it is red against
`4da24fddb`.

Worth recording for the lane's own honesty: the r1-era Task-3 suppression
(`lib/api.ts:319-324` → `:354-356`) only silences a `console.error`. The promise still rejects
unconditionally (`lib/api.ts:365`), so the query still enters an error state and the widget still
renders its error UI. `grep -rn "Access denied" src` shows the only surface is
`lib/api.ts:355` (`console.error`) — no toast. **The user-visible symptom is fixed by fix 4, not by
fix 3.** That does not change the verdict; it changes what the staging retest should look for.

## P2-2 — the only end-to-end test for Task 2 targeted a component never rendered in production → **CLOSED**

`apps/web/src/features/company/CompanyOnboardingPage.test.tsx` was rewritten to drive the real
reachable flow (`submitCreatedCompany()` clicks through the four wizard steps) and now asserts
`useCompanyStore.getState().currentCompanyId === createdCompany.id` and
`localStorage.getItem('autoerp-company-selection') === createdCompany.id`. The `@tanstack/react-query`
module mock and the `../../stores/companyStore` mock are gone — it renders against a real
`QueryClientProvider` and the real store. Verified red against `dev` (2 failures). The
`AddCompanyModal.ordering.test.tsx` copy is kept as well.

## P2-3 — the `POST /companies` → `Company` mapping was entirely untested → **CLOSED**

New `apps/web/src/features/company/api.test.ts` mocks only `apiPost` and feeds the exact
`CompanyController::formatCompany` snake_case payload (including `legal_name: null` and
`default_target_margin: null`), then asserts every mapped field. It covers the
`company.legal_name ?? company.name` fallback at `features/company/api.ts:105`.

## P3-1 — the suppression predicate diverged from `handleCompanyScopeRejection`'s matching → **CLOSED**

`apps/web/src/lib/api.ts:319-324`:

```ts
const currentCompanyId = useCompanyStore.getState().currentCompanyId
const isStaleCompanyResponse =
  currentCompanyId !== null && sentCompanyId !== null && sentCompanyId !== currentCompanyId
```

Now mirrors the `currentCompanyId === null` bail-out at `lib/api.ts:196-199`. Pinned by a test that is
red against `4da24fddb`. Ordering is still correct: the predicate is computed at `:319-324`, i.e.
*before* `handleCompanyScopeRejection` can null the selection at `:344-350` — pinned by
`lib/__tests__/api.companyScope.test.ts:236`.

## P3-2 — `AdminSupportAccessPage` skips `clearScopeForNewSession` → **NOT CLOSED (out of scope, and now slightly worse — see N-1)**

Explicitly excluded by the fix brief's "Do NOT do" list. Still true at
`apps/web/src/features/support-access/pages/AdminSupportAccessPage.tsx:41-63`.

## P3-3 — `invalidateCompanies()` ran before `adoptCreatedCompany` against an abandoned key → **CLOSED**

- `components/organisms/AddCompanyModal/AddCompanyModal.tsx:70-73` — adopt first, invalidate second.
- `features/company/CompanyOnboardingPage.tsx:68-71` — same order.
- `features/company/CompanyProvider.tsx:162-165` — `useInvalidateCompanies` now reads
  `useCompanyStore.getState().currentCompanyId` **at call time** instead of capturing it at render.
  `tenantId` is still the render-time hook value (`:154`), which is correct — the tenant cannot change
  inside a create-company mutation.
  Correctness when called *before* adoption (the parent's question): it simply targets the old key, i.e.
  exactly the pre-fix behavior — no new failure mode, and both call sites now adopt first.
  Pinned by `CompanyProvider.tenantScope.test.tsx` → `reads the newly adopted company when invalidating
  in the same event` (asserts the NEW key is invalidated and the OLD key is **not**), red against `4da24fddb`.
- Not a hooks-rules violation: `useCompanyStore.getState()` is a member call, not an `use*` identifier
  call, and eslint on the file is clean (0 errors).

## P3-4 — `CreateCompanyResponse` declared nullable server fields as non-nullable → **CLOSED**

`features/company/api.ts:50-51` — `default_target_margin: string | null` / `default_minimum_margin: string | null`,
matching `CompanyController::formatCompany`'s `CurrencyScale::bcformatOrNull(...)`. The new
`api.test.ts` payload sends `null` for both.

## P3-5 — adopted `Company` had no `isPrimary` → **CLOSED**

`features/company/api.ts:112` sets `isPrimary: false` explicitly, and `api.test.ts` asserts it. The
server list refetch corrects it. `resolveCompanySelection` still short-circuits on step 1
(`stores/companyStore.ts:109-111`), so nothing regresses.

## P3-6 — cross-tab `storage` listener adopts an unvalidated company id → **NOT CLOSED (pre-existing, explicitly out of scope)**

`stores/companyStore.ts:254-260` untouched, as instructed. Still the one remaining unvalidated writer
of `currentCompanyId`. Ticket-worthy, not lane-worthy.

---

# NEW FINDINGS (regression hunt on the fix round)

## Cross-tenant leakage — VERIFIED NEGATIVE again (no P1)

The r1 defense chain is intact and none of the four writers of `currentCompanyId` changed semantics:
`stores/companyStore.ts:231` (`partialize: () => ({})` — the store never rehydrates a selection),
`:105-121` (`resolveCompanySelection` filters against server-returned membership),
`:186-198` (`setCurrentCompany` validates against `companies`),
`:200-209` (`adoptCreatedCompany`, id straight from the server's own create response).
`/user/companies` and `/auth/me` remain header-exempt (`lib/api.ts:134`, `:262-265`). Gate C is green
with an empty baseline, so no query can read another tenant's/company's cache entry.

### Boot-storm / double-fire question (asked explicitly) — VERIFIED NEGATIVE

`CompanySelector` is mounted only inside `TopBar` (`components/organisms/TopBar/TopBar.tsx:110`), which
sits under `AppRoutes` → `CompanyProvider` (`App.tsx:28-36`). On a cold boot with a persisted session:
`stores/authStore.ts:64` starts `isLoading: true` → `RequireAuth` renders its loader
(`features/auth/AuthProvider.tsx:120-124`) → `TopBar` is not mounted. When `/auth/me` resolves,
`CompanyProvider.tsx:121` shows its own loader (`companies.length === 0` is still true at that point) →
still not mounted. `TopBar` first mounts only *after* `setCompanies` has resolved a selection, so
`useRef(currentCompanyId)` (`CompanySelector.tsx:20`) initialises to the already-resolved id and the
effect's `previousCompanyIdRef.current !== currentCompanyId` guard is false. **No null→id fire, no boot
refetch storm.** Under `StrictMode` (`main.tsx`) the effect double-invokes with no cleanup and the ref
is not reset, so the second invoke is a no-op.

Same-tick double switch (A→B→C) produces one React commit, one effect run, `previousCompanyIdRef = C`,
one blanket invalidation — correct. A switch driven by the cross-tab `storage` listener
(`stores/companyStore.ts:254-260`) now *does* invalidate, which it previously never did — a small
improvement. A change driven by `handleCompanyScopeRejection` (`lib/api.ts:196-206` → `reset()`) nulls
both `currentCompanyId` and `companies`, which re-arms the `CompanyProvider` loader and unmounts
`TopBar` before the effect body can run — so no invalidation fires there. Harmless: every key is
company-scoped, so the re-bootstrap re-keys everything anyway.

### CompanyProvider loader gate — stale-account list question (asked explicitly)

Login and register both call `clearScopeForNewSession(queryClient)` **before** `setAuth`
(`features/auth/LoginPage.tsx:94`, `features/auth/RegisterPage.tsx:104` → `lib/clearAppState.ts:21-33`,
which calls `useCompanyStore.getState().reset()` first), so `companies` is `[]` and the
`companies.length === 0` clause still holds the loader for the incoming account. Logout and 401 expiry
go through `clearAllAppState` (`features/auth/useLogout.ts:17`, `features/auth/AuthProvider.tsx:95` →
`lib/clearAppState.ts:42-51`). **All ordinary session-establishment paths are safe.** The one exception
is N-1.

---

## [P3] N-1 — `CompanyProvider.tsx:121` narrowing removes the last backstop on the impersonation handover

`apps/web/src/features/support-access/pages/AdminSupportAccessPage.tsx:41-63` installs a session for a
**different tenant** (`useAuthStore.getState().setAuth({ …, tenant_id: grant.tenant_id }, started.plain_text_token)`)
after `queryClient.clear()` (`:41`) and `void navigate('/dashboard')` (`:63`) — and never calls
`clearScopeForNewSession`, so `useCompanyStore` still holds the *previous* tenant's `companies` array
and `currentCompanyId`.

Before this diff `CompanyProvider`'s loader keyed on `isLoading` alone, so it blanked the subtree while
tenant Y's `/user/companies` loaded. Now it also requires `companies.length === 0`, which is false.

**Failure scenario:** an operator is logged in as a tenant-X user in a tab, navigates to `/admin`
(no reload, so the company store keeps X's list), and starts a support-access session for tenant Y.
`/dashboard` renders immediately: `CompanySelector` displays tenant X's company names
(`CompanySelector.tsx:79`, `:103`) under a tenant-Y session, and every non-exempt request stamps
`X-Company-Id: <tenant-X company>` (`lib/api.ts:262-265`).

**Why it is P3 and not P1/P2:** isolation is physical (db-per-tenant) and the server rejects — the
company-scope middleware returns `COMPANY_ACCESS_DENIED`, `handleCompanyScopeRejection`
(`lib/api.ts:186-207`) resets and re-bootstraps, `/user/companies` is header-exempt (`lib/api.ts:134`)
so the recovery cannot deadlock, and tenant-scoped query keys plus the `queryClient.clear()` at
`:41` mean nothing can be read from cache. So the impact is a brief wrong-tenant *label* in the header
plus a burst of `Access denied` console noise, not a data leak. But it is a real reduction of a defense
layer, introduced by this diff.

**Fix:** route `AdminSupportAccessPage.start` through `clearScopeForNewSession(queryClient)` (the r1
P3-2 follow-up) — that restores `companies = []` and with it the loader gate, and clears
`deniedCompanyIds` across the account boundary. Add the "every `setAuth` call site clears scope first"
assertion to `features/auth/__tests__/newSessionCompanyScope.test.tsx:299-305`, which today only
inspects Login/Register by source string.

## [P3] N-2 — the switch-time invalidation now depends on an untested two-file coupling

`CompanySelector.tsx:42-53` can only run if the component stays mounted across the company change, and
that is true **only** because `CompanyProvider.tsx:121` no longer unmounts the subtree on the re-key.
`CompanySelector.test.tsx:97-102` renders the selector bare (no `CompanyProvider`), so if someone
reverts the `&& companies.length === 0` clause, the subtree unmounts on every switch, the effect never
fires, and **no test goes red**. The `CompanyProvider` test that pins the gate
(`CompanyProvider.tenantScope.test.tsx` → `keeps children mounted…`) exercises `adoptCreatedCompany`,
not a plain `setCurrentCompany` switch, so it does not cover this direction either.

Blast radius today is small — `pnpm audit:keys` reports an empty baseline and 0 violations, so every
tenant query re-keys on the company suffix by itself and the blanket invalidation is belt-and-braces —
which is why this is P3.

**Fix:** either wrap the `CompanySelector` switch case in a `CompanyProvider` in its test, or move the
invalidation effect into `CompanyProvider` itself, where its own gate cannot unmount it.

## [P3] N-3 — the two company-change entry points now behave differently, and the blanket invalidation is provably redundant

`/company-onboarding` is registered as a full-page route **without the layout**
(`apps/web/src/routes/index.tsx:542-550`), so `TopBar`/`CompanySelector` is not mounted when
`adoptCreatedCompany` changes the company at `CompanyOnboardingPage.tsx:68`. The live create-company
path therefore gets only the targeted `invalidateCompanies()`, never the blanket one; the header
selector path gets both. Fine today (Gate C green), but the class comment at
`CompanySelector.tsx:11-13` ("Invalidates all queries when company is switched to refetch data") is
now true of only one of the two paths.

Related, same line: `queryClient.invalidateQueries()` defaults to `cancelRefetch: true`, so the
deferred blanket call cancels and re-issues the fetch that the re-key already started — one extra
aborted round trip per active query per switch. Pre-existing (the old click-handler call did the same),
so not a regression; recording it because with an empty Gate-C baseline the call is now demonstrably
unnecessary and could simply be deleted, which would also dissolve N-2.

## [P3] N-4 — `invalidationScheduledRef` is unreachable dead state

`CompanySelector.tsx:21`, `:45-51`. `previousCompanyIdRef` (`:43-44`) already collapses repeats within
a commit, and React flushes the queued microtask before the next passive-effect pass, so
`invalidationScheduledRef.current` can never be observed `true` on entry to the effect. It is an
untested branch in a file whose single test asserts `invalidateQueries` was called exactly once —
i.e. the guard's *only* purpose is covered by the other guard. Remove it, or add the case that
justifies it.

## [P3] N-5 — a `COMPANY_ACCESS_DENIED` burst during re-bootstrap is console-noisy again

Direct consequence of the `currentCompanyId !== null` bail-out I asked for in r1 P3-1
(`lib/api.ts:322-324`). When N parallel queries all carry the company that was just denied, the first
response resets the selection to `null` (`lib/api.ts:196-206`) and the remaining N−1 land with
`currentCompanyId === null` → `isStaleCompanyResponse === false` → `console.error('Access denied:', …)`
at `lib/api.ts:355`. Console-only (there is no toast on that branch — `grep -rn "Access denied" src`
returns only `lib/api.ts:355` and its two tests), and identical to `dev`'s behavior, so **not a
regression** — recorded so the lane is not read as "all stale-scope noise is gone".

**Tighter predicate if it ever matters:** also suppress when the response's own error code is in
`COMPANY_SCOPE_REJECTION_CODES` (`lib/api.ts:157`), which distinguishes "this 403 is the scope reset
we just performed" from "this 403 is a genuine permission denial".

## [Minor] N-6 — `isRecord` is broader than its name

`lib/api.ts:59-61` is `typeof value === 'object' && value !== null`, so it returns `true` for arrays
and class instances. That breadth is *required* here — `error.config.headers` is an `AxiosHeaders`
instance, not a plain object, and the new guard at `lib/api.ts:320` must accept it. Correct as written;
noting only that the name over-promises.

---

## What to fix before merge

Nothing. Merge as is, and file two follow-ups: route `AdminSupportAccessPage.start` through
`clearScopeForNewSession` (N-1, which also finishes r1 P3-2), and either delete the now-redundant
blanket `invalidateQueries()` in `CompanySelector` or cover the `CompanyProvider`-mounted switch case
so the N-2 coupling cannot silently regress. Staging retest should confirm the *widget error states*
(not just the console) are gone when switching company on the dashboard — fix 4 is what closes that,
fix 3 was only ever cosmetic.
