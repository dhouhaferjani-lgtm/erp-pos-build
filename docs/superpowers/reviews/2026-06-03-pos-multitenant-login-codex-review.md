# Adversarial Design Review: POS Desktop Multi-Tenant Login

Date: 2026-06-03
Spec reviewed: `docs/superpowers/specs/2026-06-03-pos-multitenant-login-design.md`

## Overall Verdict

REQUEST-CHANGES

Confidence: High. The backend login contract, current POS auth store, POS storage layer, POS login page, web login page, and supporting POS API request helper were read with line-numbered evidence. Findings below are limited to behavior observable from those files plus the proposed spec.

## Findings

### MAJOR 1: The spec misstates the backend failure contract for suspended/archived organizations and inactive users

Problem:
The spec says unknown/stale `tenant_id`, wrong password, and suspended/archived tenant all collapse to generic `invalid_credentials`. That is not the real backend contract. Unknown tenants and wrong passwords are generic, but inactive users return `auth.account_not_active`, and suspended/archived tenants return an explicit `403 ORGANIZATION_UNAVAILABLE` after valid credentials are known.

This matters because the spec uses "failures are deliberately indistinguishable" as a design premise. POS error handling and tests should not assume every explicit-tenant failure is indistinguishable. The auto-select recovery still cannot distinguish stale tenant from wrong password, but suspended/archived organizations are distinguishable and should be handled or at least documented accurately.

Evidence:
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:235` returns generic `auth.invalid_credentials` only when the explicit tenant does not exist.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:255` returns generic `auth.invalid_credentials` when the user is absent or the password hash check fails.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:261` returns `auth.account_not_active` when the user is inactive.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:269` returns a `403` response with code `ORGANIZATION_UNAVAILABLE` for suspended/archived tenants.

Recommended fix:
Revise the backend-contract section and edge cases:
- Keep stale/unknown tenant and wrong password as generic validation failures.
- Document inactive users separately.
- Document suspended/archived organizations as `403 ORGANIZATION_UNAVAILABLE`.
- Add POS tests that assert the actual messages/status categories are surfaced correctly rather than normalized to `invalid_credentials`.

### MAJOR 2: The proposed `opts` change can regress the existing AbortSignal/cancel contract unless the spec explicitly preserves it

Problem:
The current POS login flow has an established cancellation path: `LoginPage` creates an `AbortController`, passes its signal into `authStore.login`, and the store threads that signal into both `/auth/login` and `/user/companies`. The spec says to add optional `tenantId` to `opts`, but it does not state that `opts.signal` must be preserved and reused for the silent auto-select re-POST and the downstream companies fetch.

If implementation changes `opts` to `{ tenantId?: string }`, or if the first POST uses the signal but the auto-select re-POST does not, the Cancel button can abort only part of the login. The user could cancel after the still-trying threshold while the silent tenant-bound POST or `/user/companies` continues and later commits auth state.

Evidence:
- `apps/pos/src/pages/LoginPage.tsx:53` creates a fresh `AbortController` for each submit.
- `apps/pos/src/pages/LoginPage.tsx:57` passes `controller.signal` into `login`.
- `apps/pos/src/pages/LoginPage.tsx:80` aborts that controller from the Cancel button.
- `apps/pos/src/stores/authStore.ts:57` currently defines login options as `{ signal?: AbortSignal }`.
- `apps/pos/src/stores/authStore.ts:167` passes the signal into the `/auth/login` POST.
- `apps/pos/src/stores/authStore.ts:204` passes the same signal into `/user/companies`.
- `apps/pos/src/lib/api.ts:88` documents that `signal` is the user-initiated cancellation path, and `apps/pos/src/lib/api.ts:124` forwards it to `fetchWithTimeout`.

Recommended fix:
Specify the new login signature as:

```ts
opts?: { signal?: AbortSignal; tenantId?: string }
```

Require the same signal to be passed to:
- the email-first `/auth/login` request,
- the auto-selected tenant-bound `/auth/login` re-POST,
- the manual picker tenant-bound `/auth/login` request,
- the downstream `/user/companies` request.

Add tests for cancellation during the first POST, during the auto-select re-POST, and during `/user/companies` after auto-select. Each test should assert no token/user/company/tenant storage is committed after abort.

### MAJOR 3: The business picker can introduce concurrent login races unless selection clicks are guarded

Problem:
The spec says to mirror the existing `showCompanySelect` pattern with a parallel business picker. The existing company picker is safe to leave unguarded because selecting a company is local state plus storage. Selecting a business is different: it launches a network login with credentials and a tenant id.

If the new business picker follows the current company picker shape without disabling buttons during `isLoading` or tracking a selected tenant, a user can double-click or click two organizations quickly. `authStore.login` has no in-flight guard. Concurrent login attempts can interleave around the temporary token write used for `/user/companies`, producing duplicate tokens, last-writer-wins auth state, or a restored `priorAuth` snapshot that was captured while another login's temporary token was in memory.

Evidence:
- `apps/pos/src/pages/LoginPage.tsx:97` renders company-selection buttons.
- `apps/pos/src/pages/LoginPage.tsx:100` handles company selection with no disabled/loading guard, which is currently acceptable because `handleCompanySelect` only calls local `setCompany`.
- `apps/pos/src/pages/LoginPage.tsx:84` shows `handleCompanySelect` only writes the chosen company and hides the selector.
- `apps/pos/src/stores/authStore.ts:150` starts login by setting `isLoading: true`, but there is no early return or request-id guard if another login starts while one is already active.
- `apps/pos/src/stores/authStore.ts:193` snapshots prior auth, then `apps/pos/src/stores/authStore.ts:200` writes a temporary token into memory so `/user/companies` can authenticate.
- `apps/pos/src/stores/authStore.ts:211` restores that snapshot on companies-fetch failure.
- `apps/pos/src/stores/authStore.ts:218` commits token/user/companies after the companies fetch succeeds.

Recommended fix:
Do not mechanically mirror the company picker. Specify that the business picker:
- disables all organization buttons while `isLoading` or while a selected tenant login is pending,
- ignores repeat clicks for the same pending tenant,
- prevents selecting a different tenant until the current tenant-bound login settles or is aborted,
- uses the same AbortController path as the main login.

Add tests for double-clicking the same organization and rapidly selecting two different organizations. The expected result should be one tenant-bound POST and one committed auth state.

### MAJOR 4: The T1.1 "transactional persist" invariant is overstated and the new `LOGIN_TENANT_ID` write can widen the partial-persistence window

Problem:
The spec says the existing guard holds all in-memory and on-disk auth writes until both `/auth/login` and `/user/companies` resolve, and that `LOGIN_TENANT_ID` is persisted only after the full authenticated path succeeds. The network-ordering part is true, but the on-disk persistence is not transactional. The current store performs sequential `setStoredValue` calls with no rollback if one storage write fails after a prior write succeeded.

Adding `LOGIN_TENANT_ID` after the existing auth writes creates another failure point. For example, if token/user/companies are written and then `LOGIN_TENANT_ID` fails, `login()` can throw to the UI while durable auth state has already been committed. If `LOGIN_TENANT_ID` is written before auth data and a later auth write fails, the device binding can change even though the login did not complete.

Evidence:
- `apps/pos/src/stores/authStore.ts:217` starts the "persist auth data" block only after both network calls resolve.
- `apps/pos/src/stores/authStore.ts:218` writes `TOKEN`, then `apps/pos/src/stores/authStore.ts:219` writes `USER`, then `apps/pos/src/stores/authStore.ts:220` writes `COMPANIES`.
- `apps/pos/src/stores/authStore.ts:222` commits in-memory auth after those writes, and `apps/pos/src/stores/authStore.ts:232` later writes `COMPANY_ID` for the single-company case.
- `apps/pos/src/lib/storage.ts:55` implements `setStoredValue` as a single-key write.
- `apps/pos/src/lib/storage.ts:64` writes unencrypted values with a direct `s.set(key, value)` and no transaction boundary.

Recommended fix:
Either narrow the invariant language or implement a real commit/rollback helper for the post-companies storage phase. The design should specify exact ordering and failure behavior for `TOKEN`, `USER`, `COMPANIES`, `COMPANY_ID`, and `LOGIN_TENANT_ID`.

At minimum:
- keep `LOGIN_TENANT_ID` out of any pre-companies branch,
- add a storage-failure test where the `LOGIN_TENANT_ID` write fails,
- assert the final disk and memory state is either the prior complete auth snapshot or the new complete auth snapshot, not a mixture.

### MAJOR 5: Keeping `LOGIN_TENANT_ID` across logout conflicts with existing logout semantics and can trap multi-tenant users on a shared terminal

Problem:
The spec says `logout()` must not clear `LOGIN_TENANT_ID` because the terminal stays bound to its business. Current POS logout does more than end an operator session: it clears auth, company, terminal, pending terminal, resets terminal state, and is invoked from full sign-out flows. Preserving only the tenant id while clearing the company and terminal creates a hidden business binding that the UI cannot change.

In a multi-user/shared-terminal scenario, this can be wrong. If a terminal previously persisted tenant A and a later user enters an email that belongs to tenants A and B, the spec auto-selects A and re-POSTs with `tenant_id`. The backend will validate credentials only inside A on that explicit request. The user may never see the picker for B unless they clear app data, and a valid same-email/same-password account in A would authenticate into the silently selected tenant.

Evidence:
- `apps/pos/src/stores/authStore.ts:312` removes the auth token on logout.
- `apps/pos/src/stores/authStore.ts:314` removes the selected company id on logout.
- `apps/pos/src/stores/authStore.ts:316` removes the persisted terminal on logout.
- `apps/pos/src/stores/authStore.ts:318` resets `terminalStore`.
- `apps/pos/src/components/Header.tsx:382` uses this same logout path for the visible logout action.
- `apps/pos/src/pages/PinEntryPage.tsx:27` uses this same logout path from the sign-out flow on the PIN screen.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:192` uses the explicit tenant id directly when supplied, and `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:250` then looks up the user only within that tenant.

Recommended fix:
Resolve the open question in the spec before implementation. Either:
- add an in-app "Change business" / "Reset business binding" affordance that clears `LOGIN_TENANT_ID` before login, or
- clear `LOGIN_TENANT_ID` from full logout/reset-terminal flows and reserve persistence only for operator switch flows, or
- introduce separate actions with explicit semantics: operator switch, sign out, reset terminal/business.

Acceptance criteria should cover a multi-tenant email with a stale or undesired persisted tenant and prove there is a UI path to choose another valid tenant without clearing all app data externally.

### MINOR 1: The spec should explicitly accept and minimize the unauthenticated organization-list disclosure on POS

Problem:
The backend intentionally returns organization names and slugs for a valid email before checking the password. The spec acknowledges that credentials are not validated, but it does not spell out the client-side disclosure this creates when POS renders the picker: anyone with physical or remote access to the login screen can enter a known multi-tenant email and see business names, and possibly slugs, before authentication.

This is not a new backend vulnerability created by POS; the web client already exposes the picker. But POS is a different surface, often on a shared physical terminal, and the spec should name the accepted risk and reduce unnecessary display.

Evidence:
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:205` returns the organization-picker branch before the password check at `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:255`.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:209` includes `tenant_id`, `name`, and `slug` in each organization.
- `apps/web/src/features/auth/LoginPage.tsx:151` renders the organization picker pre-authentication.
- `apps/web/src/features/auth/LoginPage.tsx:165` displays organization name and `apps/web/src/features/auth/LoginPage.tsx:166` displays slug.

Recommended fix:
Add an explicit security note:
- The organization list is intentionally derived from valid email alone.
- POS should display only the minimum needed to choose the right business, preferably name only unless slug is required for disambiguation.
- Rate limiting and monitoring remain backend concerns and are out of scope for this POS-only task.

Add a UI acceptance criterion that the POS picker does not display slug unless product explicitly requires it.

### MINOR 2: The infinite-loop open question has a clear backend answer, but the spec should still require a one-retry guard

Problem:
The current backend cannot return `requires_org_selection` for a request with an explicit `tenant_id`, because the explicit branch sets `$tenantIds` to a one-element array before the multi-tenant branch checks `count($tenantIds) > 1`. So an infinite auto-select loop is not possible against the current contract.

However, the client should not recursively call `login()` without a one-retry guard or response assertion. A future backend regression or mock mistake should fail closed with a contract error, not loop or repeatedly re-POST credentials.

Evidence:
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:192` turns explicit `tenant_id` into a single-element `$tenantIds` array.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:205` only returns `requires_org_selection` when `explicitTenantId === null` and there is more than one tenant.

Recommended fix:
Answer the open question in the spec:
- No loop is possible with the current backend contract.
- Still implement auto-select as exactly one additional POST, not open recursion.
- If the explicit-tenant response is not `{ user, token }`, throw a typed "unexpected login response" error and do not mutate auth state.

Add a test for "explicit tenant returns org-selection-shaped payload" that asserts no loop and no auth persistence.

### MINOR 3: Missing tests for wrong password after auto-select and for tenant persistence failures

Problem:
The testing section covers the happy paths, stale persisted tenant, manual tenant selection, logout persistence, and companies-fetch failure after auto-select. It does not include two edge cases that follow directly from the real backend and storage behavior:

1. Multi-tenant email + valid persisted tenant + wrong password: the first POST returns organizations without checking the password; the auto-selected explicit-tenant POST fails at the password check. The test should assert the persisted tenant is not cleared, no token/user/company state is committed, and the UI shows the login error.
2. Successful auth + successful companies fetch + failure while writing `LOGIN_TENANT_ID`: the spec needs an expected outcome because storage writes are sequential and can fail independently.

Evidence:
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:205` returns the organization list before validating credentials.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:255` checks the password only on the explicit/single-tenant path.
- `apps/pos/src/stores/authStore.ts:218` begins sequential persistence after `/user/companies`.
- `apps/pos/src/lib/storage.ts:55` exposes single-key `setStoredValue` writes that can fail independently.

Recommended fix:
Add authStore tests for:
- persisted tenant auto-select followed by invalid credentials,
- persisted tenant auto-select followed by `/user/companies` failure,
- persisted tenant write failure after successful companies fetch,
- abort during auto-select re-POST.

The acceptance criteria should state the expected durable storage state after each failure.

## Verified Correct Points

- The spec's core claim that POS currently expects an authenticated login response unconditionally is consistent with `apps/pos/src/stores/authStore.ts:156`, where `apiPost` is typed as `{ user; token; tokenType; deviceId }`, and `apps/pos/src/stores/authStore.ts:171`, where it immediately destructures `{ user, token }`.
- The org-list-without-password-check claim is accurate: the backend returns the organization list at `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:216` before the password check at `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:255`.
- The `0 tenants -> validation error` claim is directionally correct: `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:197` throws a `ValidationException` with `auth.no_organizations`.
- The optional `tenant_id` request contract is accurate: `apps/api/app/Modules/Identity/Presentation/Requests/LoginRequest.php:33` allows nullable UUID strings.
- Persisting `LOGIN_TENANT_ID` unencrypted is not materially worse than current POS storage by itself: `apps/pos/src/stores/authStore.ts:19` shows `User` already includes `tenantId`, `apps/pos/src/stores/authStore.ts:219` persists `USER`, and `apps/pos/src/lib/storage.ts:26` encrypts only `TOKEN`.
- Offline cached-session boot is not directly affected by this spec: `apps/pos/src/stores/authStore.ts:108` reads cached token/user/company state, while `apps/pos/src/pages/LoginPage.tsx:115` blocks interactive login when offline.

## Finding Counts

BLOCKER: 0
MAJOR: 5
MINOR: 3
NIT: 0

## Final Verdict

REQUEST-CHANGES

The design is directionally right on the backend's email-first/multi-tenant shape, and the core POS gap is real. The spec should not proceed unchanged because it under-specifies the parts most likely to regress existing POS behavior: abort propagation, concurrent picker submits, storage failure semantics, and logout/business-binding escape hatches.
