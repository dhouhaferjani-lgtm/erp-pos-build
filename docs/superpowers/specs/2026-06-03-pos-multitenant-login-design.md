# POS Desktop Multi-Tenant (Email-First) Login — Design Spec

- **Date:** 2026-06-03
- **Branch:** `feat/pos-multitenant-login` (worktree off `origin/dev`)
- **Status:** Design approved by owner; pending adversarial review before implementation plan.

## Problem

The platform moved to email-first, database-per-tenant authentication. The backend
`/auth/login` flow on `dev` now resolves the tenant from the email (via the central
`central_identities` index) and, when an email belongs to **more than one tenant**,
returns an organization picker payload instead of a token. The **web admin** client
(`apps/web`) was updated to handle this. The **desktop POS client** (`apps/pos`,
Tauri / IziPOS) was **not**: it still POSTs `email` + `password` only, ignores the
`requires_org_selection` response, and expects `{ user, token }` unconditionally.

Consequence: a POS user whose email belongs to >1 tenant cannot log in — the backend
returns `{ requires_org_selection: true, organizations: [...] }` (HTTP 200, **no
token**), and the POS store fails because there is no token. This is latent today
(single-tenant emails resolve automatically while `TENANCY_DB_PER_TENANT=false`,
Phase 0a) but breaks for any multi-tenant email and will break broadly at the Phase 0b
DB-per-tenant flip.

## Backend contract (already implemented on `dev` — NOT modified by this work)

`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php::login`:

- `LoginRequest` accepts: `email` (required), `password` (required), optional
  `tenant_id` (nullable uuid), plus the existing device fields
  (`device_name`, `device_id`, `platform`, `platform_version`, `app_version`).
- Resolution:
  - No `tenant_id` → look up `CentralIdentity` by email → tenant id(s).
  - **0 tenants** → `422` validation error `auth.no_organizations`.
  - **Exactly 1 tenant** → bind tenant, `TenancyResolver::initializeIfProvisioned`,
    validate credentials scoped to `tenant_id`, return `{ data: { user, token,
    tokenType, deviceId } }`.
  - **>1 tenants and no explicit `tenant_id`** → **HTTP 200** with
    `{ data: { requires_org_selection: true, organizations: [{ tenant_id, name,
    slug }] } }`. **Credentials are NOT validated on this call** (backend "Balanced
    stance": the org list is derived from a valid email alone).
  - Explicit `tenant_id` supplied → bind that tenant directly and validate
    credentials (this is the device-bound / picked-org path).
  - Unknown/stale `tenant_id`, wrong password, suspended/archived tenant → generic
    `invalid_credentials` (deliberately indistinguishable, enumeration-safe).

Because failures are deliberately indistinguishable, the client **cannot** tell
"wrong password" from "stale tenant_id" by inspecting an error. The recovery design
below works around this without relying on error disambiguation.

## Decisions (locked with owner)

1. **Device-bound, persisted tenant.** A POS terminal is a fixed device for one
   business. Resolve/choose the tenant once, persist `tenant_id` in Tauri storage,
   and reuse it so cashiers do not see a picker on every login.
2. **Email-first discovery + auto-select (self-healing).** Always POST email-first
   (no `tenant_id`). Single-tenant emails return a token in one round-trip.
   Multi-tenant emails return the org list; the client auto-selects the persisted
   tenant from that list and re-POSTs. A stale persisted id is simply absent from the
   list, so the picker shows and self-heals.

## Scope

- **In scope:** `apps/pos` only.
- **Out of scope:** `apps/web` (already handles this); backend (`apps/api`) — no
  changes; the company-selection flow (company is a sub-scope *within* a tenant and is
  unchanged).

## Design

### `authStore.login(email, password, opts?)`

Add an optional `tenantId` to `opts` and change the flow:

1. POST `/auth/login` with `{ email, password, device_id, device_name, platform }`
   and `tenant_id` **only if** `opts.tenantId` is provided.
2. The response (already unwrapped by `apiPost`) is a discriminated union:
   - `{ user, token, tokenType, deviceId }` → **authenticated path**: run the existing
     transactional companies-fetch + persist (UNCHANGED — see "Invariant" below),
     then persist `user.tenantId` to `StorageKeys.LOGIN_TENANT_ID`. Return
     `{ status: 'authenticated' }`.
   - `{ requires_org_selection: true, organizations }` → **multi-tenant path**:
     - Read persisted `LOGIN_TENANT_ID`.
     - If it is present **and** found in `organizations` → transparently re-POST
       `/auth/login` with `tenant_id` (the password is still in this function's
       closure), receive `{ user, token }`, and continue down the authenticated path.
       The UI never sees a picker.
     - Otherwise (nothing stored, or stored id not in the list = stale) → return
       `{ status: 'requires_org_selection', organizations }`. The store does **not**
       retain the password.

`login()` return type becomes:
`Promise<{ status: 'authenticated' } | { status: 'requires_org_selection'; organizations: Organization[] }>`.
(Errors still throw, as today.)

### `LoginPage.tsx`

Mirror the existing `showCompanySelect` pattern with a parallel business picker:

- The page keeps `email` + `password` in its own React state (as it does now, and as
  the web client does).
- When `login()` resolves to `{ status: 'requires_org_selection', organizations }`,
  render the business picker.
- On selecting an organization, call `login(email, password, { tenantId })`. This
  persists `LOGIN_TENANT_ID` (after the authenticated response) and completes login.
- All picker text uses `t()` keys (new POS i18n entries).

### Storage (`apps/pos/src/lib/storage.ts`)

- Add `StorageKeys.LOGIN_TENANT_ID` (NOT in `ENCRYPTED_KEYS` — it is not a secret).
- Written after every successful authentication (value = `user.tenantId`).
- **`logout()` does NOT remove it** — the terminal stays bound to its business across
  logouts. Resetting the terminal to a different business = clear app data (consistent
  with the "device-bound" decision; no in-app "change business" affordance in this
  iteration).

### Types

Define the login union type where the POS login types live (`authStore.ts` /
`apps/pos/src/lib/api.ts`), e.g. `Organization = { tenant_id: string; name: string;
slug: string }` and a `LoginResult` union matching the backend payload.

## Invariant preserved: transactional persist (T1.1)

The existing guard holds all in-memory and on-disk auth writes until **both**
`/auth/login` and `/user/companies` resolve, restoring the prior auth snapshot if
`/user/companies` fails. This work does not weaken it: the `requires_org_selection`
branch happens **before** any token exists, and the auto-retry simply feeds a normal
`{ user, token }` into the existing downstream path. `LOGIN_TENANT_ID` is persisted
only after the full authenticated path succeeds.

## Edge cases

- **Wrong password, multi-tenant email:** first POST returns the org list (no
  credential check); auto-select re-POSTs with `tenant_id`; backend returns generic
  `invalid_credentials`; surfaced as a login error. Persisted tenant is **not**
  cleared (correct — the tenant is valid, the password was wrong).
- **Stale persisted tenant:** not present in `organizations` → picker shown → new
  pick overwrites `LOGIN_TENANT_ID`. Self-heals.
- **0 organizations:** backend `422 auth.no_organizations`; surfaced as a login error.
- **Offline:** unaffected. Tenant resolution is online-only; cached-session boot
  (`authStore.initialize()`) is untouched. `LOGIN_TENANT_ID` is available offline but
  only consumed during an online login.
- **Single-tenant email (the common case):** one round-trip, token returned directly;
  `LOGIN_TENANT_ID` is still recorded so behavior stays correct if the email later
  becomes multi-tenant.

## Testing (TDD, Vitest, following existing `apps/pos` conventions)

`authStore`:
- single-tenant email → `{ status: 'authenticated' }`, normal persist.
- multi-tenant email + persisted id ∈ list → auto re-POST **includes `tenant_id`**, no
  picker, authenticated.
- multi-tenant email + no/stale persisted id → returns `{ status:
  'requires_org_selection', organizations }`, password not retained.
- `login(..., { tenantId })` → persists `LOGIN_TENANT_ID`, authenticates.
- `LOGIN_TENANT_ID` survives `logout()`.
- transactional-persist invariant still holds when `/user/companies` fails after an
  auto-selected re-POST.

`LoginPage`:
- renders the business picker on a `requires_org_selection` result.
- selecting an org re-invokes `login` with `{ tenantId }`.

## Acceptance criteria

1. A single-tenant-email POS user logs in unchanged (one round-trip).
2. A multi-tenant-email POS user with a valid persisted tenant logs in with **no
   picker**, and the authenticating request carries `tenant_id`.
3. A multi-tenant-email POS user with no/stale persisted tenant sees a business picker;
   selecting one logs in and persists the choice; subsequent logins skip the picker.
4. `LOGIN_TENANT_ID` persists across logout.
5. No backend or `apps/web` changes. The T1.1 transactional-persist invariant is
   intact. All new user-facing strings use `t()`.

## Open questions for review

- Is "no in-app change-business affordance" acceptable for the first iteration, or
  should a minimal escape hatch ship now?
- Any concern with persisting `LOGIN_TENANT_ID` unencrypted (it is a tenant UUID, not a
  secret)?
- Does the auto-select re-POST need a guard against an infinite loop if the backend
  ever returned `requires_org_selection` again for an explicit `tenant_id` (it should
  not, per contract)?
