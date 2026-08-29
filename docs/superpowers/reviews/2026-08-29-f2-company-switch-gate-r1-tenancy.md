# Lane F2 — adversarial gate r1 (tenancy/authz reviewer)

**VERDICT: CHANGES** — spec ❌ (Task 3 does not fix the failure mode its own brief describes) + quality CHANGES-REQUESTED.

Scope reviewed: `git diff dev...HEAD -- apps/web` at `4da24fddb` on `fix/f-bug-2-company-switch`,
worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/f-bug-2`. 10 files, +184/−17.
No backend files touched (confirmed by the diff stat), so no route-middleware / permission-catalog /
`config/verticals.php` / queue-context surface is in play. Everything below is grounded in files I read.

Gates I ran in the worktree: `pnpm typecheck` → clean; `pnpm lint` → exit 0 (warnings only, all
pre-existing; this is the run that executes `tools/audit-tanstack-keys.mjs`); targeted
`pnpm vitest run src/features/company src/stores/__tests__/companyStore.test.ts
src/components/organisms/AddCompanyModal src/lib/__tests__/api.companyScope.test.ts`
→ **5 files / 59 tests passed**.

---

## 1. Cross-tenant / cross-account leakage — VERIFIED NEGATIVE (no P1)

I hunted the "skipping the boot reset lets a previous ACCOUNT's company selection survive" thesis and
could **not** construct a leak. The defense chain, each link read:

- `currentCompanyId` is never rehydrated from storage by the persist middleware —
  `apps/web/src/stores/companyStore.ts:231` `partialize: () => ({})`. On any page load it starts `null`
  (`:152-156`), so the request interceptor (`apps/web/src/lib/api.ts:262-265`) cannot stamp a stale
  `X-Company-Id` before the membership list is known.
- There are exactly four writers of `currentCompanyId`, and three validate against server truth:
  - `setCompanies` → `resolveCompanySelection` filters the persisted id against the server-returned
    membership list (`companyStore.ts:105-120`, persisted branch at `:112-115`). A previous account's
    company id is simply not in the new account's list, so it is discarded.
  - `setCurrentCompany` guards on `companies.find(...)` (`companyStore.ts:189`).
  - `adoptCreatedCompany` (new, `companyStore.ts:200-209`) takes the id straight out of the
    `POST /companies` response, and `CompanyController::store` creates an **owner membership for the
    calling user** inside the same transaction
    (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:142-149`) and scopes
    the company to `$user->tenant_id` (`:73`, `:84`). It cannot select a company the caller is not a
    member of, and it cannot cross a tenant.
  - The cross-tab `storage` listener (`companyStore.ts:254-259`) adopts an unvalidated id when
    `companies.length === 0` — **pre-existing, untouched by this diff** (see P3-1).
- The two session-teardown paths are independent of the effect that changed: `useLogout.ts:17` and the
  401-expiry branch `AuthProvider.tsx:93-96` both call `clearAllAppState`
  (`lib/clearAppState.ts:42-51`), which resets the company store *and* `clearDeniedCompanyIds()`.
- The session-establishment paths call `clearScopeForNewSession` **before** `setAuth`:
  `LoginPage.tsx:94-95` and `RegisterPage.tsx:104-105` (`lib/clearAppState.ts:21-34`). The multi-tenant
  org-picker re-runs the *same* mutation (`LoginPage.tsx:141-143` → `loginMutation.mutate`), so it is
  covered too. Ordering is already pinned by
  `features/auth/__tests__/newSessionCompanyScope.test.tsx:299-305`.

`adoptCreatedCompany`'s `deniedCompanyIds.delete(company.id)` (`companyStore.ts:201`) does not bypass
denial semantics in any harmful way: the id is a freshly minted server-side UUID that cannot have been
legitimately denied, and the same "explicit user intent outranks a remembered denial" rule already
exists for `setCurrentCompany` (`companyStore.ts:193`, rationale at `:190-192`).

## 2. StrictMode / ref lifetime — VERIFIED SAFE

- `main.tsx:20` wraps the app in `StrictMode`. `CompanyProvider` has **no cleanup function**
  (`CompanyProvider.tsx:110-118`), so the StrictMode double-invoke can only set
  `wasAuthenticated.current = true` twice or take the no-op branch twice. It cannot manufacture a
  false→true→false transition. `useRef` survives the simulated remount.
- `CompanyProvider` is mounted once at the App root (`App.tsx:29`) inside `AuthProvider` (`:28`) and is
  never unmounted for the life of the page, so the ref cannot be silently reset mid-session.
- This is the **same pattern already accepted in `AuthProvider.tsx:61 / :79 / :93-96`** and in
  `LocationProvider.tsx:51-58`. Consistent with the codebase, not a novel construct.

## 3. Stale-403 suppression ordering — VERIFIED CORRECT

`isStaleCompanyResponse` is computed at `lib/api.ts:319-322`, i.e. **before**
`handleCompanyScopeRejection` can null the selection at `:345-348`. Had it been computed after, every
genuine `COMPANY_ACCESS_DENIED` would have looked "stale" (current becomes `null`) and been silenced.
The new assertion at `lib/__tests__/api.companyScope.test.ts:236` pins exactly that ordering. Good.

I also verified the header actually round-trips: the interceptor assigns
`config.headers['X-Company-Id']` by direct property set (`lib/api.ts:264`), axios stores the literal
key, and `AxiosHeaders.normalize`/`formatHeader`
(`node_modules/.pnpm/axios@1.16.0/.../lib/core/AxiosHeaders.js:256-280`, `:87-94`) re-emits
`X-Company-Id` with the same casing — so `error.config?.headers['X-Company-Id']` (`api.ts:319`) is a
real read, not silently `undefined`.

## 4. `tenantScopedKey` — NO REGRESSION

No query key is touched by the diff. `useInvalidateCompanies` (`CompanyProvider.tsx:152-165`) and its
explicit-full-key comment are unchanged, the `.107` test still passes, and `pnpm lint` (which runs
`tools/audit-tanstack-keys.mjs`) exits 0.

## 5. Task 1 red/green — the new test genuinely pins the bug

`__tests__/CompanyProvider.tenantScope.test.tsx:149-192` sets `user` via `beforeEach`'s
`setTenant('tenant-A')` (`:117`, `:24-36`) then forces `isAuthenticated: false` (`:178`) — exactly the
`authStore` shape produced by `partialize` persisting `user` but not `isAuthenticated`
(`stores/authStore.ts:111-115`, `:60-65`). Under the pre-diff effect the first render would call
`reset()` → `localStorage.removeItem` (`companyStore.ts:219-226`), and `resolveCompanySelection` would
then land on the `is_primary` company `company-A` (`companyStore.ts:116-119`), failing the
`company-B` assertion. Under the new effect the key survives and `:112-115` restores `company-B`.
Real red→green. The `.106` counterpart now drives an actual true→false transition (`:207-209`) and
asserts the key is removed (`:216`), so removing the effect entirely would also fail. Both good.

---

# FINDINGS

## P2-1 — Task 3 does not cover the failure mode its own brief describes (`lib/api.ts:321-322`)

The suppression keys on "the request carried an `X-Company-Id` **different from** the current
selection". But in the switch flow the brief cites, the offending requests are issued **after** the
selection has already changed, so they carry the *new* company id and are not suppressed:

- `CompanySelector.tsx:37-44` — `switchCompany(companyId)` runs on line 39, `queryClient.invalidateQueries()`
  on line 41. `switchCompany` is `setCurrentCompany` (`hooks/useCompany.ts:30`), a synchronous zustand
  `set` (`companyStore.ts:194`).
- React batches the re-render, so at the moment `invalidateQueries()` runs the observers are still
  mounted on the OLD keys and refetch immediately — but the request interceptor reads
  `useCompanyStore.getState().currentCompanyId` (`lib/api.ts:262`), which is **already the new company**.
- The stale bit is the params, not the header: `LocationProvider.tsx:52-58` only resets the location
  selection in an *effect*, i.e. after the refetches have already gone out, and neither
  `setCurrentCompany` nor `adoptCreatedCompany` touches the location store.

**Failure scenario:** user on company A switches to company B in the header selector. The dashboard's
`treasury/cash-position` (or any `location_ids[]`-carrying query) refetches with
`X-Company-Id: B` + company A's `location_ids[]`, 403s, `sentCompanyId === currentCompanyId` ⇒
`isStaleCompanyResponse === false` ⇒ `console.error('Access denied: …')` still fires. The reported
symptom persists on staging.

The change is still correct for the *in-flight* subset (a request issued before the switch), so it is
not a regression — but the lane cannot be closed as "Task 3 done" without either re-scoping it or
resetting the location scope synchronously in the switch handler.

## P2-2 — The only end-to-end test for Task 2 targets a component that is never rendered in production

`components/organisms/AddCompanyModal/AddCompanyModal.tsx` has **zero JSX usages outside tests**
(exhaustive grep over `apps/web/src`: only `__tests__/AddCompanyModal.ordering.test.tsx:52` and
`features/settings/__tests__/TunisiaLocalization.test.tsx:81`;
`features/company/AddCompanyModal.tsx` is a 2-line re-export). The live "add a company" action is
`CompanySelector.tsx:46-49` → `navigate('/company-onboarding')` → `routes/index.tsx:544` →
`CompanyOnboardingPage`.

**Failure scenario:** someone later reverts `CompanyOnboardingPage.tsx:70` to `setCurrentCompany(data.id)`.
Every test still passes (the new `AddCompanyModal.ordering.test.tsx:86-107` covers the dead component),
and F-BUG-1 row 1e silently regresses on the only path a user can reach.
`features/company/CompanyOnboardingPage.test.tsx` asserts nothing about the store (its four `it(...)`
blocks are all title/ordering/markup) and mocks `createCompany` at `:24`.

**Fix:** add the `currentCompanyId` + `autoerp-company-selection` assertion to
`CompanyOnboardingPage.test.tsx`, on the live path.

## P2-3 — The new `POST /companies` → `Company` mapping is entirely untested (`features/company/api.ts:101-111`)

This mapping is the load-bearing new code for Task 2, and **both** consumers mock `createCompany`
away (`AddCompanyModal.ordering.test.tsx:18-20` returns an already-camelCase object;
`CompanyOnboardingPage.test.tsx:24`). Nothing exercises `api.ts:101-111`.

I checked the field names against the server and they are correct **today**:
`CompanyController::formatCompany` (`CompanyController.php:627-674`) emits
`legal_name` / `tax_id` / `country_code` / `currency` / `locale` / `timezone`, wrapped in
`{data, meta}` (`:196-202`) and unwrapped once by `apiPost` (`lib/api.ts:387-390`).

**Failure scenario:** a rename on either side (or someone "fixing" the response to camelCase) makes
`company.currency` `undefined`; `adoptCreatedCompany` writes a `Company` with `currency: undefined`
into the store, `getCurrentCompany()` hands it to every `formatCurrency` consumer, and nothing fails
until `/user/companies` refetches. No test turns red.

**Fix:** one direct unit test on `createCompany` with a real snake_case `formatCompany`-shaped payload.

## P3-1 — The suppression predicate is *not* the matching `handleCompanyScopeRejection` uses (`lib/api.ts:322` vs `:196-198`)

The brief says to reuse "the same 'sent vs current' matching". `handleCompanyScopeRejection` bails out
first when `currentCompanyId === null` (`api.ts:196-198`); the new predicate does not, so
`sentCompanyId !== null && null !== sentCompanyId` ⇒ `true`.

**Failure scenario:** a company-scope 403 has just reset the selection to `null`; a second, *genuine*
permission 403 (e.g. `FORBIDDEN` on `treasury.view`) from a request that carried the former company id
lands a tick later and is silently swallowed while the app re-bootstraps. Console-only impact, but it
diverges from the documented invariant. Suggest `currentCompanyId !== null && sentCompanyId !== currentCompanyId`.

## P3-2 — Removing the boot reset makes `clearScopeForNewSession` a single-layer defense, and one session-establishment path skips it

`features/support-access/pages/AdminSupportAccessPage.tsx:35-63` establishes a **tenant session for a
different tenant's user** (`setAuth(...)` at `:44` with `tenant_id: grant.tenant_id`, bearer token at
`:62`) via `queryClient.clear()` + `navigate('/dashboard')`. It never calls `clearScopeForNewSession`
and never calls `clearDeniedCompanyIds()`.

Before this diff, `CompanyProvider`'s unconditional boot reset incidentally scrubbed
`autoerp-company-selection` on any unauthenticated boot (which is how you reach `/admin`). That backstop
is now gone. I traced the impersonation flow and it still **self-heals** — `/user/companies` is header-exempt
(`lib/api.ts:134`, `:263`) and `resolveCompanySelection` discards the foreign id (`companyStore.ts:112-115`) —
so this is defense-in-depth, not a live leak. But the module-level `deniedCompanyIds` set
(`companyStore.ts:62`) is also carried across the account boundary. Recommend routing
`AdminSupportAccessPage.start` through `clearScopeForNewSession` in a follow-up, and adding the
"every `setAuth` call site clears scope first" assertion to `newSessionCompanyScope.test.tsx:299-305`
(today it only checks Login/Register by source-string inspection).

## P3-3 — `invalidateCompanies()` runs before `adoptCreatedCompany` and targets a key that is immediately abandoned

`AddCompanyModal.tsx:71-74` and `CompanyOnboardingPage.tsx:69-70` call `invalidateCompanies()` first.
`useInvalidateCompanies` captures `companyId` at render time and builds the explicit full key
`['user','companies', tenantId, companyId]` (`CompanyProvider.tsx:163-164`) — the **old** company. The
very next statement re-keys the live query to the new company id, so the invalidation is a no-op for
the observed query; the refetch happens only because the new key is a cache miss. Harmless today, but
the comment `// Invalidate companies query to refetch the list` (`AddCompanyModal.tsx:71`) is wrong,
and the sequence breaks silently if the key ever stops carrying `companyId`. Swap the two lines.

## P3-4 — `CreateCompanyResponse` declares two nullable server fields as non-nullable (`features/company/api.ts:49-50`)

`default_target_margin: string` / `default_minimum_margin: string`, but the server produces them via
`CurrencyScale::bcformatOrNull(...)` (`CompanyController.php:658-665`), which can return `null`.
Unused at runtime (the mapper at `api.ts:101-111` reads neither), so it is a type lie only.

## P3-5 — `adoptCreatedCompany` writes a `Company` with no `isPrimary`

`formatCompany` does not emit `is_primary` (`CompanyController.php:627-674`) and the mapper does not
set it (`api.ts:101-111`), so the adopted entry carries `isPrimary: undefined` until `/user/companies`
resolves. Benign today because `resolveCompanySelection` short-circuits on step 1
(`companyStore.ts:109-111`), but any future consumer reading `isPrimary` during that window gets
`undefined` rather than server truth.

## P3-6 — Pre-existing, flagged only because this diff makes the persisted key live longer

The cross-tab `storage` listener adopts an **unvalidated** company id whenever `companies.length === 0`
(`companyStore.ts:254-259`). Two tabs on different accounts (CLAUDE.md explicitly warns localStorage is
shared on localhost) can push tenant Y's company id into a still-bootstrapping tenant X tab. The server
rejects it (`COMPANY_ACCESS_DENIED`) and the client self-heals, so it is 403 noise rather than a leak —
but it is the one remaining unvalidated writer. Out of scope for this lane; worth a ticket.

## Incidental improvement worth recording

The old boot reset called `localStorage.removeItem(COMPANY_SELECTION_KEY)` on **every** page load, which
fires a `storage` event in *other* tabs and drove them through `companyStore.ts:261-263`
(`currentCompanyId = null`). Opening a second tab therefore collapsed the first tab's scope. The fix
removes that, which is a real cross-tab bug fixed for free — but it is untested.

---

## What to fix before merge

Re-scope or complete Task 3 (P2-1 — the post-switch refetch path is the one that actually 403s), move
the Task-2 adopt assertion onto the live `CompanyOnboardingPage` path (P2-2), and add one direct test
for the `createCompany` snake→camel mapping (P2-3). Task 1 is correct, well-pinned, and can ship as is.
