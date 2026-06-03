# POS Desktop Multi-Tenant (Email-First) Login — Sub-Spec A of 3

- **Date:** 2026-06-03 (revised 2026-06-04 after Codex adversarial review)
- **Branch:** `feat/pos-multitenant-login` (worktree off `origin/dev`)
- **Status:** Design approved by owner; revised per Codex review
  (`docs/superpowers/reviews/2026-06-03-pos-multitenant-login-codex-review.md`).
- **Decomposition:** This is **A** of three sequenced sub-projects (each its own
  spec → plan → review → implementation):
  - **A (this spec) — Multi-tenant login.** Email-first discovery, device-bound
    persisted tenant, picker, self-heal. Frontend-only (`apps/pos`).
  - **B — Session/device-logout separation + manager-gated device unbind.** Home-screen
    button becomes operator sign-off; a manager-only, confirmed Settings action performs
    the device unbind that clears the tenant binding. (Closes Codex MAJOR 5; see
    "Deferred to Sub-Spec B" below.)
  - **C — Event-sourced auth/session audit pipeline.** Offline audit-event queue +
    `syncService` integration + backend `/pos/audit-events/sync` writing to
    `audit_events`. A's and B's events emit through it.

## Problem

The platform moved to email-first, database-per-tenant authentication. The backend
`/auth/login` flow on `dev` resolves the tenant from the email (via the central
`central_identities` index) and, when an email belongs to **more than one tenant**,
returns an organization picker payload instead of a token. The **web admin**
(`apps/web`) was updated to handle this; the **desktop POS** (`apps/pos`, Tauri /
IziPOS) was **not** — it POSTs `email` + `password` only, ignores
`requires_org_selection`, and unconditionally expects `{ user, token }`.

Consequence: a POS user whose email belongs to >1 tenant cannot log in (the backend
returns `{ requires_org_selection: true, organizations }` — HTTP 200, **no token**).
Latent today (single-tenant emails auto-resolve while `TENANCY_DB_PER_TENANT=false`,
Phase 0a) but breaks for multi-tenant emails and at the Phase 0b flip.

## Backend contract (already implemented on `dev` — NOT modified by Sub-Spec A)

`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php::login`.
`LoginRequest` accepts `email`, `password`, optional `tenant_id` (nullable uuid), plus
device fields (`device_name`, `device_id`, `platform`, `platform_version`,
`app_version`). Outcomes:

| Situation | Response | Notes |
|---|---|---|
| No `tenant_id`, **0** tenants for email | `422` ValidationException `auth.no_organizations` | enumeration-aware |
| No `tenant_id`, **exactly 1** tenant | `200 { data: { user, token, tokenType, deviceId } }` | credentials validated |
| No `tenant_id`, **>1** tenants | `200 { data: { requires_org_selection: true, organizations: [{ tenant_id, name, slug }] } }` | **credentials NOT validated**; list derived from valid email alone ("Balanced stance") |
| Explicit `tenant_id` | binds that tenant, validates credentials | the device-bound / picked path |
| Unknown/stale `tenant_id`, or wrong password | `422` ValidationException `auth.invalid_credentials` | deliberately indistinguishable |
| User exists but inactive | `422` ValidationException `auth.account_not_active` | **distinct** message |
| Tenant Suspended/Archived (after valid creds) | **`403`** `{ error: { code: 'ORGANIZATION_UNAVAILABLE', message } }` | **distinct** status/code |

Evidence: `AuthController.php` — org list `:205–:226`, unknown tenant `:235`, bad
password `:255`, inactive `:261`, suspended/archived `:269` (403). `LoginRequest.php:33`
(nullable uuid `tenant_id`).

**Correction from the first draft:** failures are *not* uniformly
`invalid_credentials`. Only unknown-tenant and wrong-password collapse to the generic
message. Inactive users (`422 account_not_active`) and suspended/archived organizations
(`403 ORGANIZATION_UNAVAILABLE`) are **distinct and must be surfaced as-is**, not
normalized. The auto-select recovery still cannot distinguish *stale tenant* from
*wrong password* (both generic) — the self-heal design below does not rely on that
distinction.

## Decisions (locked with owner)

1. **Device-bound, persisted tenant.** Resolve/choose the tenant once, persist it, and
   reuse it so cashiers do not see a picker on every login.
2. **Email-first discovery + auto-select (self-healing).** Always POST email-first (no
   `tenant_id`); single-tenant emails return a token in one round-trip; multi-tenant
   emails return the org list and the client auto-selects the persisted tenant from it;
   a stale persisted id is simply absent from the list, so the picker shows and
   self-heals.

## Scope

- **In scope (Sub-Spec A):** `apps/pos` login flow only.
- **Out of scope:** `apps/web` (already handles this); backend (`apps/api`) — no
  changes; the company-selection flow (company is a sub-scope *within* a tenant,
  unchanged); logout/device-unbind hardening (**Sub-Spec B**); audit-event emission
  (**Sub-Spec C**).

## Design

### Login outcome and signature

`authStore.login` signature becomes:

```ts
login(
  email: string,
  password: string,
  opts?: { signal?: AbortSignal; tenantId?: string },
): Promise<
  | { status: 'authenticated' }
  | { status: 'requires_org_selection'; organizations: Organization[] }
>
```

where `Organization = { tenant_id: string; name: string; slug: string }`. Errors still
throw (as today). The `/auth/login` response is a discriminated union
`{ user, token, tokenType, deviceId } | { requires_org_selection: true; organizations }`.

### Flow inside `login()`

1. POST `/auth/login` with `{ email, password, device_id, device_name, platform }`, and
   `tenant_id` **only if** `opts.tenantId` is provided. Pass `opts.signal` to the
   request.
2. On `{ user, token, ... }` → **authenticated path**: run the existing
   transactional companies-fetch + persist (UNCHANGED — see "Invariant" below), then
   **best-effort** persist `user.tenantId` to `StorageKeys.LOGIN_TENANT_ID`. Return
   `{ status: 'authenticated' }`.
3. On `{ requires_org_selection: true, organizations }` → **multi-tenant path**:
   - Read persisted `LOGIN_TENANT_ID`.
   - If present **and** found in `organizations`, **and** this call did not already
     supply `opts.tenantId` (one-shot guard) → re-POST `/auth/login` with that
     `tenant_id` (password still in closure; reuse `opts.signal`), receive
     `{ user, token }`, continue the authenticated path. UI never sees a picker.
   - Otherwise (nothing stored, stored id not in list = stale) → return
     `{ status: 'requires_org_selection', organizations }`. The store does **not**
     retain the password.
4. **One-shot / anti-loop guard (MINOR 2):** auto-select performs **exactly one**
   additional POST. If a call that *did* supply an explicit `tenantId` ever receives a
   `requires_org_selection` shape (contract violation — the backend cannot do this:
   explicit `tenant_id` yields a single-element tenant set, `AuthController.php:192`),
   throw a typed `UnexpectedLoginResponseError` and mutate **no** auth state. No
   recursion.

### Abort / cancellation (MAJOR 2)

The same `opts.signal` MUST be threaded to: the email-first POST, the auto-select
re-POST, the manual-pick POST, and the downstream `/user/companies`. Aborting cancels
the whole login; no token/user/company/tenant state is committed after abort. Current
cancel wiring to preserve: `LoginPage.tsx:53/57/80`, `authStore.ts:57/167/204`,
`api.ts:88/124`.

### Business picker UI (`LoginPage.tsx`) + concurrency guard (MAJOR 3)

Mirror the existing `showCompanySelect` structure, but the business picker fires a
**network login**, so it must NOT be copied unguarded (the company picker is safe only
because it writes local state). Requirements:

- The page keeps `email` + `password` in its own React state (as today / as web does).
- On a `requires_org_selection` outcome, render the picker (organization **name only**
  — **not slug**, MINOR 1; minimizes pre-auth disclosure on a shared terminal).
- Selecting an org calls `login(email, password, { signal, tenantId })`.
- **Concurrency guard:** organization buttons are disabled while a selection login is
  pending (`isLoading`); repeat clicks for the same tenant are ignored; a different
  tenant cannot be selected until the current attempt settles or is aborted. The store
  also single-flights: `login()` returns early / rejects if a login is already in
  flight. Result of double-click / rapid two-org selection: **one** tenant-bound POST,
  **one** committed auth state.

### Storage (`apps/pos/src/lib/storage.ts`)

- Add `StorageKeys.LOGIN_TENANT_ID` (NOT in `ENCRYPTED_KEYS` — it is a tenant UUID, not
  a secret; `User.tenantId` is already persisted unencrypted via `StorageKeys.USER`).
- It is a **non-authoritative hint**: its only effect is auto-selecting the picker.
- Written **best-effort** after a fully successful authentication (value =
  `user.tenantId`); the write is wrapped so a storage failure **never throws** out of
  `login()` and never corrupts auth state. Worst case on write failure: the picker
  shows again next time. (This is the MAJOR 4 resolution — no commit/rollback machinery
  needed because the value is non-authoritative.)
- **Sub-Spec A does not change `logout()`.** Today `logout()` clears the device session
  but not `LOGIN_TENANT_ID`; that is acceptable for A. The deliberate reset path is
  defined in **Sub-Spec B**.

### Types

Define `Organization`, the login response union, and the `login()` outcome union where
the POS login types live (`authStore.ts` / `apps/pos/src/lib/api.ts`).

## Invariant preserved: network-ordering guard (T1.1) — corrected wording (MAJOR 4)

The existing T1.1 guard is a **network-ordering** guard, not a transactional storage
commit: it holds all in-memory and on-disk auth writes until **both** `/auth/login` and
`/user/companies` resolve, restoring the prior in-memory auth snapshot if
`/user/companies` fails (`authStore.ts:193/200/211/217–222`). The on-disk
`setStoredValue` calls are sequential with no rollback. Sub-Spec A does not weaken this:
the `requires_org_selection` branch happens **before** any token exists; the auto-retry
feeds a normal `{ user, token }` into the existing downstream path; and
`LOGIN_TENANT_ID` is written **last and best-effort**, so it cannot leave auth state
half-committed.

## Edge cases

- **Wrong password, multi-tenant email:** first POST returns the org list (no credential
  check); auto-select re-POSTs with `tenant_id`; backend returns generic
  `auth.invalid_credentials`; surfaced as a login error; persisted tenant **not**
  cleared (the tenant is valid, the password was wrong).
- **Inactive user:** `422 auth.account_not_active` surfaced as its own message.
- **Suspended/archived org:** `403 ORGANIZATION_UNAVAILABLE` surfaced distinctly (not
  normalized to invalid credentials).
- **Stale persisted tenant:** not in `organizations` → picker shown → pick overwrites
  `LOGIN_TENANT_ID`. Self-heals.
- **0 organizations:** `422 auth.no_organizations` surfaced as a login error.
- **Offline:** unaffected. Tenant resolution is online-only; cached-session boot
  (`authStore.initialize()`, `authStore.ts:108`) is untouched; interactive login is
  already blocked offline (`LoginPage.tsx:115`). `LOGIN_TENANT_ID` is available offline
  but only consumed during an online login.
- **Single-tenant email (common case):** one round-trip; token returned directly;
  `LOGIN_TENANT_ID` still recorded.

## Security note (MINOR 1)

The organization list is intentionally derived from a valid email alone (backend
"Balanced stance"; the web client already exposes it). On a shared physical POS
terminal this means anyone who can type a known multi-tenant email sees the associated
business **names** pre-auth. To minimize disclosure, the POS picker shows **name only**
(no slug). Rate-limiting/monitoring of `/auth/login` remain backend concerns, out of
scope here.

## Deferred to Sub-Spec B (Codex MAJOR 5 resolution)

Codex MAJOR 5: persisting the tenant binding across all logout paths can trap a
multi-tenant user with no in-app escape. **Resolution (owner-approved), implemented in
Sub-Spec B:**

- The **home-screen** logout button becomes an **operator-level sign-off / lock**
  (`operatorStore.clearOperator()` / `lock()`), keeping the device session **and**
  `LOGIN_TENANT_ID`. For all users including managers — tapping it never unbinds the
  device. This also matches retail/PCI/NF525 norms (lock for breaks, operator sign-off
  for shift end; cashiers must not tear down terminal identity).
- A new **Settings → "Device sign-out / unbind"** action performs the full
  `authStore.logout()` **and clears `LOGIN_TENANT_ID`**, gated to **manager and up**
  (reuse `isManager` + `ManagerPinPanel`) and behind a **confirmation modal** (reuse
  `Modal`/`RefundConfirmModal` pattern) so it cannot happen inadvertently. After it
  runs, the next login is a full email-first login → picker if multi-tenant. This is the
  in-app reset path that closes MAJOR 5.

**Interim in Sub-Spec A:** until B ships, the binding reset is "clear app data /
reinstall." Acceptable because the multi-tenant-email trap is rare and A still delivers
working multi-tenant login.

## Testing (TDD, Vitest, existing `apps/pos` conventions)

`authStore`:
- single-tenant email → `{ status: 'authenticated' }`, normal persist.
- multi-tenant email + persisted id ∈ list → auto re-POST **includes `tenant_id`**, no
  picker, authenticated.
- multi-tenant email + no/stale persisted id → returns `{ status:
  'requires_org_selection', organizations }`; password not retained.
- `login(..., { tenantId })` (manual pick) → persists `LOGIN_TENANT_ID`, authenticated.
- **wrong password after auto-select** → generic error surfaced; `LOGIN_TENANT_ID`
  **not** cleared; no auth state committed.
- **`/user/companies` fails after auto-select** → prior auth snapshot restored; nothing
  persisted.
- **`LOGIN_TENANT_ID` write fails after successful companies fetch** → login still
  succeeds; auth state fully committed; no throw.
- **abort during** email-first POST / auto-select re-POST / `/user/companies` → no
  token/user/company/tenant committed.
- **explicit-tenant call returns picker shape** → throws `UnexpectedLoginResponseError`,
  no loop, no auth persistence.
- inactive user `422` and suspended/archived `403` surfaced as their own messages.

`LoginPage`:
- renders the business picker (name only, no slug) on a `requires_org_selection`
  outcome.
- selecting an org re-invokes `login` with `{ tenantId }`.
- **double-click same org** and **rapid two different orgs** → one tenant-bound POST,
  one committed auth state; buttons disabled while pending.

## Acceptance criteria

1. Single-tenant-email POS user logs in unchanged (one round-trip).
2. Multi-tenant-email user with a valid persisted tenant logs in with **no picker**, and
   the authenticating request carries `tenant_id`.
3. Multi-tenant-email user with no/stale persisted tenant sees a name-only business
   picker; selecting one logs in and persists the choice; subsequent logins skip the
   picker.
4. Cancel during any phase commits nothing.
5. Concurrent picker selections produce exactly one authenticated session.
6. Inactive-user and suspended/archived responses surface distinctly (not normalized).
7. No backend or `apps/web` changes. The T1.1 network-ordering guard is intact.
   `LOGIN_TENANT_ID` is best-effort and non-authoritative. All new user-facing strings
   use `t()`.

## Resolved review questions

- *No in-app change-business affordance acceptable for A?* Yes — the proper reset is
  the manager-gated Settings unbind in **Sub-Spec B**; interim is clear-app-data.
- *Unencrypted `LOGIN_TENANT_ID`?* Acceptable — a tenant UUID, not a secret; `User`
  (incl. `tenantId`) is already persisted unencrypted.
- *Infinite-loop risk?* None per the backend contract; still guarded by the one-shot +
  typed-error rule (MINOR 2).
