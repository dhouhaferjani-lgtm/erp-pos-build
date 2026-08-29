# Lane F2 — adversarial frontend-conventions gate r1

**VERDICT: CHANGES — 3× P2, 5× P3. No P1 blocker. The three fixes are behaviourally correct; the objections are about where the evidence sits and about a side effect the brief did not anticipate.**

- Branch `fix/f-bug-2-company-switch` @ `4da24fddb`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/f-bug-2`, base `dev` = `a4ceeb0f5`.
- Scope reviewed: `git diff dev...HEAD -- apps/web` (10 files, +184/−17). Backend read-only for contract verification.

## Gates re-run by the reviewer (not taken on report)

| Gate | Command | Result |
|---|---|---|
| Vitest (all touched dirs + `src/lib`) | `pnpm vitest run src/features/company src/stores src/components/organisms/AddCompanyModal src/components/organisms/CompanySelector src/lib` | **28 files / 205 tests passed** |
| Typecheck | `pnpm --filter @autoerp/web typecheck` | clean |
| Lint (incl. `audit:keys`, `audit:design-system`, `audit:quantity`, `audit:i18n:local`, eslint-rules RuleTester, tools tests) | `pnpm --filter @autoerp/web lint` | **exit 0** — warnings only, all pre-existing |
| Baseline honesty | `apps/web/tools/audit-design-system-baseline.json` | **not in the diff** — no `--write-baseline` absorption |
| Mechanism audit | grep of diff for alias tables / detector-keyword suppressions / renamed literals | **none** — no evasion |

Convention spot-checks, all clean: no `any` (`sentCompanyIdHeader: unknown` narrowed at `api.ts:319-320`); no new user-facing strings (no `t()` debt); **no TSX styling touched at all**, so no token/interpolation surface; `tenantScopedKey` untouched; no raw form controls, tables or pickers introduced; money/quantity untouched.

## Backend contract verification (asked explicitly)

`apps/web/src/features/company/api.ts:25-57` (`CreateCompanyResponse`) vs `CompanyController::formatCompany` (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`, `formatCompany`). Key-set diff computed mechanically:

```
only-in-FE (invented):   (none)
only-in-BE (missing):    (none)
```

31/31 keys match exactly, including the six newly added ones (`code`, `registration_number`, `vat_number`, `website`, `address_street_2`, `address_state`) and the five margin/mode keys. Nullability also matches the formatter (`default_target_margin`/`default_minimum_margin` are non-null after the deliberate `$company->refresh()` at `CompanyController.php:190`; `default_max_discount_percent` nullable). **No invented field. This part is clean.**

---

## P2 findings

### P2-1 — The only user-reachable create→switch path is untested; the path that IS tested is dead production code
`apps/web/src/components/organisms/AddCompanyModal/__tests__/AddCompanyModal.ordering.test.tsx:86-107` is the lane's headline regression test for row 1e. But `AddCompanyModal` is **not mounted anywhere in the app**: the only non-test references are the barrel re-exports `apps/web/src/components/organisms/index.ts:6` and `apps/web/src/features/company/AddCompanyModal.tsx:2`. The reachable "add company" control is `apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:46-49`, which `navigate('/company-onboarding')` → `apps/web/src/routes/index.tsx:544` → `CompanyOnboardingPage`.

`apps/web/src/features/company/CompanyOnboardingPage.tsx:70` — the live `adoptCreatedCompany(data)` — has **no test**. `apps/web/src/features/company/CompanyOnboardingPage.test.tsx:37-70` covers only page primitives (h1, buttons, country order).

Concrete failure: a later refactor of `CompanyOnboardingPage`'s `onSuccess` (or a revert to `setCurrentCompany`) reintroduces bug 1e for every real user while lint, typecheck and 205/205 stay green, because the only regression that guards it drives a component nobody can open.

**Fix directive:** add the adopt+persist assertion to `CompanyOnboardingPage` (drive the wizard to submit, assert `useCompanyStore.getState().currentCompanyId` and `localStorage['autoerp-company-selection']`), and either delete `AddCompanyModal` + its barrel exports or state in the lane record why the orphan is retained (owner rule: anything that works must be reachable; dead surfaces resolve to ONE canonical mount).

### P2-2 — The new response→`Company` mapping is executed by zero tests
`apps/web/src/features/company/api.ts:101-112` is the piece this lane introduced that can silently diverge from the backend. Every test that touches the create flow mocks `createCompany` wholesale (`AddCompanyModal.ordering.test.tsx:18-20` returns an already-camelCased `Company`), so the mapping never runs.

Concrete failure: the backend renames `legal_name`, or a future `formatCompany` edit drops a key. `CreateCompanyResponse` is a hand-maintained mirror, so TS keeps compiling against the stale interface; `legalName`/`countryCode` become `undefined` at runtime while typed `string`; the created company is adopted with blank fields, the selector shows an empty subtitle, and no test fails. (I verified parity today — the point is that nothing keeps it true.)

**Fix directive:** add a unit test for `createCompany` that mocks `apiPost` with a literal copy of a real `formatCompany` payload and asserts the mapped `Company`.

### P2-3 — `adoptCreatedCompany` re-keys the companies query, which blanks the whole app behind CompanyProvider's full-screen loader and destroys LocationProvider's company-change guard
Chain, all in-diff or directly caused by it:
1. `apps/web/src/components/organisms/AddCompanyModal/AddCompanyModal.tsx:70-74` / `apps/web/src/features/company/CompanyOnboardingPage.tsx:69-70` call `invalidateCompanies()` **before** `adoptCreatedCompany(data)`.
2. `useInvalidateCompanies` (`apps/web/src/features/company/CompanyProvider.tsx:152-164`) closes over `currentCompanyId` from its last render — the **OLD** company. So it invalidates `['user','companies',tenantId,OLD]`. At that instant that IS the provider's active key, so the invalidation lands and fires a refetch.
3. `adoptCreatedCompany` (`apps/web/src/stores/companyStore.ts:200-209`) appends to `companies` → new array identity → `CompanyProvider`'s `useCompanyStore((s) => s.companies)` selector (`CompanyProvider.tsx:70`) re-renders it → `tenantScopedKey(['user','companies'])` (`CompanyProvider.tsx:78`) now resolves to the **NEW** company id → a second, cache-less query starts.

Consequences:
- **Two `/user/companies` round trips** per company creation.
- The new key has no cached data, so `CompanyProvider.tsx:121-130` returns the centred full-screen "loading" panel and **unmounts the entire application subtree** for the duration. This is new — a plain switch through `CompanySelector`/`setCurrentCompany` does not change `companies`, so the provider does not re-render and never re-keys.
- That unmount takes `LocationProvider` with it (`apps/web/src/App.tsx:29-38`, LocationProvider nested inside CompanyProvider at line 31). Its `previousCompanyIdRef` guard (`apps/web/src/features/locations/LocationProvider.tsx:51-58`) is destroyed and re-initialises to `null` on remount, so `resetForCompanyChange()` **never fires for this company change**. Recovery is purely incidental: `locationStore.setLocations` (`apps/web/src/stores/locationStore.ts:83-86`) drops a `currentLocationId` that is absent from the new list. **If the new company's locations fetch fails, `currentLocationId` stays pointed at the previous company's location** — the exact stale-scope class F-BUG-1 is about.
- The refresh of the company list therefore works for a reason nobody wrote down (the re-key), not for the reason in the code (the invalidation). `tenantScopedKey`'s own doc comment (`apps/web/src/lib/tenantScopedKey.ts:9-18`) warns against exactly this incidental coupling.

**Fix directive:** make the re-key causal — have `CompanyProvider` subscribe to `currentCompanyId` (and gate the query with it) — and drop the now-redundant `invalidateCompanies()` call at both create sites; add a test asserting `resetForCompanyChange` semantics survive a create-driven switch (or that `locationStore.currentLocationId` is not left on the old company when the locations refetch errors).

---

## P3 findings

### P3-1 — Task 3 suppresses a `console.error`, not the "Access denied" the tester reported
`apps/web/src/lib/api.ts:352-354`. `grep -rn "Access denied" apps/web/src` returns exactly three hits: the log at `api.ts:353`, its assertion at `api.companyScope.test.ts:236`, and an unrelated `customer-history-audit.json:37`. There is **no toast and no UI copy** wired to this path. The transient user-visible artefact of a switch — e.g. `apps/web/src/features/treasury/components/CashPositionWidget.tsx:38` rendering `t('cashWidget.unavailable')` while the stale-scoped query 403s — is untouched. The brief's premise ("logged/toasted", ~line 170) was wrong about the toast; the lane implemented the literal instruction.
**Fix directive:** confirm with the tester whether the noise was on-screen or in devtools; if on-screen, Task 3 is not done and the real fix is at the query/widget layer.

### P3-2 — `error.config?.headers[...]` moved onto the hot path of every errored response, with an unguarded second dereference
`apps/web/src/lib/api.ts:319`. `config?.` guards `config` but `headers` is indexed unguarded, and the expression now runs for **every** status (401/404/422/500), where before it ran only inside the `403 || 400` branch. An `AxiosError` carrying a config without `headers` now throws a `TypeError` inside the response interceptor for all statuses. (No current test constructs that shape — `new AxiosError('locked')` etc. pass `config === undefined` and short-circuit — so this is latent, not live.)
**Fix directive:** `error.config?.headers?.['X-Company-Id']`.

### P3-3 — Two divergent mappings of the same entity's `legalName`
`apps/web/src/features/company/api.ts:106` uses `company.legal_name ?? company.name`; `apps/web/src/features/company/CompanyProvider.tsx:39` uses `company.legal_name` raw, typed `string` at `CompanyProvider.tsx:15` while `UserController::companies` (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:857`) returns the nullable column. Concrete: create a company with no legal name → adopt stores `legalName === name` → `CompanySelector.tsx:89` correctly hides the subtitle; the refetch a moment later sets `legalName = null` → `null !== name` → an empty subtitle `<span>` appears under the company name. Also `Company.legalName: string` (`companyStore.ts:11`) is a lying type on the refetch path (pre-existing, now made visible).
**Fix directive:** pick one convention — apply `?? name` in `mapCompanyResponse` too, or type `legalName: string | null` and render conditionally.

### P3-4 — `adoptCreatedCompany` omits `isPrimary`
`apps/web/src/stores/companyStore.ts:200-209` inserts a `Company` with `isPrimary === undefined`, while `mapCompanyResponse` (`CompanyProvider.tsx:45`) always materialises `is_primary ?? false`. For a first-ever company the backend sets `is_primary = true` (`CompanyController.php:145`). No live impact today because `currentCompanyId` is set explicitly and `resolveCompanySelection` short-circuits on it — but it is a silent divergence between the two constructors of the same store object.
**Fix directive:** thread `is_primary` through `createCompany`'s mapping, or document the omission at the action.

### P3-5 — The bootstrap test simulates the boot instead of exercising it
`apps/web/src/features/company/__tests__/CompanyProvider.tenantScope.test.tsx:149-190` drives `useAuthStore.setState({ isAuthenticated: false })` → `true` directly. It is a genuine red→green test for the `wasAuthenticated` guard (under `dev` the mount-time `reset()` would clear `autoerp-company-selection` and `resolveCompanySelection` would fall through to the `is_primary` company-A, so the `company-B` assertion fails). But the root cause is a two-store ordering fact — `authStore` `partialize` omitting `isAuthenticated` (`apps/web/src/stores/authStore.ts:109-115`) while `AuthProvider` renders children as soon as a persisted `user` exists (`apps/web/src/features/auth/AuthProvider.tsx:103`) — and none of that is under test. If someone later persists `isAuthenticated` or gates `AuthProvider`'s children, this test still passes and proves nothing.
**Fix directive:** add one test that renders `AuthProvider > CompanyProvider` with a pre-seeded `autoerp-auth` localStorage payload and a deferred `/auth/me`.

### P3-6 — Cross-tab: a created company is systematically ignored by the storage listener
`persistCompanyId` inside `adoptCreatedCompany` (`companyStore.ts:208`) fires a `storage` event in other tabs, but the listener (`companyStore.ts:~253-262`) only adopts an id that is already in that tab's `companies` — which is never true for a just-created company when the list is non-empty. Other tabs stay on the old company (self-consistently, since `X-Company-Id` reads in-memory state) but silently jump on their next reload, with no `useScopeChangeNotice`.
**Fix directive:** none required; record the behaviour in the action's doc comment so the next reader doesn't read the listener as a guarantee.

### P3-7 — `/admin` no longer clears a leftover tenant company selection
`apps/web/src/features/company/CompanyProvider.tsx:110-118`. Before this change, mounting `CompanyProvider` on an `/admin` route with the tenant auth store unauthenticated ran `reset()`, removing `autoerp-company-selection`. Now `wasAuthenticated.current` is `false` there, so a previous tenant session's persisted company id survives an admin-console visit and keeps being sent as `X-Company-Id`. Real login/logout still clear it (`clearScopeForNewSession` / `clearAllAppState`, `apps/web/src/lib/clearAppState.ts:21-51`), so this is hygiene, not a leak.
**Fix directive:** none required; note it in the lane record.

---

## What is correct and should not be re-litigated

- **P1 fix (boot):** the `wasAuthenticated` ref at `CompanyProvider.tsx:72,110-118` mirrors the same guard `AuthProvider.tsx:61,93-97` already uses, and nothing is lost: the expired-session path clears through `clearAllAppState` (`AuthProvider.tsx:95`), explicit logout through `useLogout.ts:17`, a non-`/auth/me` 401 through `handleUnauthorized` → `authStore.logout()` (`api.ts:212-222`) which then trips the true→false transition. Login/register clear the previous scope at `LoginPage.tsx:94` / `RegisterPage.tsx:104`.
- **P2 fix (adopt):** `setCurrentCompany`'s membership guard is intentionally preserved (`companyStore.ts:186-198`); `adoptCreatedCompany` deliberately bypasses `resolveCompanySelection`, which is correct — the user just created this company, so the deterministic auto-select rule must not get a vote. Both create sites converted; no `setCurrentCompany` create-path callers remain (`useCompany.ts:30` is the legitimate switch path).
- **P3 fix (403 noise):** `isStaleCompanyResponse` is computed at `api.ts:319-322`, i.e. **before** `handleCompanyScopeRejection` (`api.ts:344-348`) can null `currentCompanyId`. Getting that order wrong would have made every scope rejection look stale and silenced the genuine case; it is right, and both directions are pinned at `api.companyScope.test.ts:236` and `:256`.
