# Adversarial gate r1 — authz/tenancy lens — B-13 POS manager-gate leak

- **Lane:** B-13 (P1) — POS manager-gate leak
- **Branch:** `fix/b13-pos-manager-gate-role-composition`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b13-pos-manager-gate`
- **Range:** `4ae7c68a8..58a14ac25` (16 files, +829/−38 — all `apps/pos`, zero `apps/api`)
- **Reviewer:** tenancy-authz-reviewer (read-only)
- **Date:** 2026-08-26

## VERDICT

**spec ✅ / quality CHANGES-REQUESTED**

The ruled deliverable is met and correctly implemented: the manager gate now composes from the
PIN operator only (`apps/pos/src/lib/auth/roles.ts:51-53`), the two-argument form is *removed*
so no caller can reintroduce the `Math.max` leg, all four consumers were converted, the X-report
surface gained a real execution-time gate, and the deny path is genuinely tested. Five items
below must be resolved (three of them one-liners) before merge; none of them re-opens the
original leak, but two are fail-**open** paths in a lane whose whole stated posture is
fail-closed, and one is a factual error in the report's backend reasoning that will mislead the
next reader into believing a server boundary exists where it does not.

---

## 1. Server-side enumeration — which principal authorizes each manager-only POS action

All POS routes live in one group: `apps/api/app/Modules/POS/routes.php:41`
—`['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`. Rule 12
is satisfied (`api` + `SetPermissionsTeam` both present). `SetPermissionsTeam`
(`apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:23-31`) sets the
Spatie team to `$user->tenant_id`, so every `Gate::authorize` below resolves against the **device
bearer token's user**, tenant-scoped.

**The PIN operator identity is never transmitted on any of these paths.** Confirmed:
`apps/pos/src/lib/api.ts` sends only `X-Client-Type`, `Authorization: Bearer`, `X-Company-Id`;
`operator_id` appears in the `apps/api` POS Presentation layer only on the cash-drawer
deposit/payout requests, the audit-event sync and a discount query.

| Device action | Endpoint | Server gate (file:line) | Principal | Cashier holds it? (seeder) |
|---|---|---|---|---|
| X report | `POST /pos/reports/x` | `ReportController.php:59` `pos.view_reports` | device token user | **No** — cashier grant at `RolesAndPermissionsSeeder.php:685` omits it; manager has it at `:619`, accountant at `:837` |
| Z report (generate) | `POST /pos/reports/z` | `ReportController.php:119` `pos.generate_z_report` | device token user | **Yes** (`:685`) |
| Z list / show / PDF / verify-chain | `GET/POST /pos/reports/z…` | `ReportController.php:218, 261, 327, 366, 424` `pos.view_reports` | device token user | **No** |
| POS analytics (8 actions) | `/pos/analytics/*` | `AnalyticsController.php:29,45,61,79,97,113,129,145` `pos.view_reports` | device token user | **No** |
| Shift open / close | `POST /pos/shifts/open`, `…/{id}/close` | `ShiftController.php:49, 106` `pos.manage_shifts` | device token user | **Yes** (`:685`) |
| Shift current / show / index / receipts | `GET /pos/shifts…` | `ShiftController.php:176, 202, 229, 281` `pos.operate_terminal` | device token user | **Yes** (`:685`) |
| Cash-drawer **deposit** | `POST /pos/cash-drawer/deposit` | `CashDrawerController.php:42` `pos.operate_terminal` | device token user | **Yes** (`:685`) |
| Cash-drawer **payout** | `POST /pos/cash-drawer/payout` | `CashDrawerController.php:111` `pos.operate_terminal` | device token user | **Yes** (`:685`) |
| Cash-drawer operations / balance | `GET /pos/cash-drawer/{shiftId}/…` | `CashDrawerController.php:180, 208` `pos.operate_terminal` | device token user | **Yes** |
| Sync-close shift | `POST /pos/shifts/{id}/sync-close` | `SyncController.php:40` `pos.manage_shifts` | device token user | **Yes** |
| PIN roster pull | `GET /pos/auth/pin-data` | `PosAuthController.php:172` `pos.operate_terminal` | device token user | **Yes** |
| Manager-PIN override verify | `POST /pos/verify-manager-pin` | **no `Gate::authorize`** — controller-internal permission check on the *target* manager (`ManagerPinController.php:40-95`) + rate limit + same-tenant guard | device token user (caller) + named target user | n/a |
| Receipt void | `POST /pos/receipts/{id}/void` | **retired 410** (`routes.php:222-227`) | — | — |

**Answer to "can a cashier holding the owner's device token reach it": yes — all of it.**
Every gate above authorizes `$request->user()`, which on the deployment shape this lane exists
for *is the owner*. The server does not and cannot distinguish "owner at the back office" from
"cashier holding the owner's terminal". So for tenant #1 the server-side permission column above
is **not** a mitigating control for B-13; it only constrains a terminal that was provisioned with
a cashier's own back-office account, which is not the shape in question.

**Confirmation of the two flags the implementer raised:**

1. `ReportController.php:59` — **CONFIRMED**, and materially worse than reported. See finding
   B-13-R1-1: `apps/pos/src/api/reportApi.ts:167-172` wraps the server call in a bare
   `catch { return await generateLocalXReport(...) }`, so a `403` from that gate is
   indistinguishable from "offline" and is silently converted into a locally-authored X report
   (with an immutable `X_REPORT` fiscal event appended at `reportApi.ts:678`). The server gate
   is therefore **not a boundary for the X report at all**, on v2 or v3.
2. `CashDrawerController::deposit/payout` on `pos.operate_terminal` — **CONFIRMED**
   (`CashDrawerController.php:42`, `:111`); cashier holds `pos.operate_terminal` at
   `RolesAndPermissionsSeeder.php:685`. **Severity for tenant #1: Important, not Critical** — it
   is a manager-only-by-UI-convention cash movement, the flow records `operator_id`, and the
   separate `approval_*` scoped-manager-PIN mechanism exists. But see finding B-13-R1-2: this is
   the one manager surface where the device gate is the *only* gate, and it is also the one the
   lane left with a single (menu-filter) layer while giving the X report two.

**Seeder sync:** this diff adds no `can:` guard and no new permission — nothing to re-seed.
No new route, so no rule-12 or module-gating obligation is triggered. (Observation, not a
finding: the POS route group carries no `module:` middleware and no `POS` module name appears in
`apps/api/config/verticals.php` `default_modules` — pre-existing and out of this lane's scope.)

**Consistency wart worth an owner note (not blocking):** a cashier device token is refused
`pos.view_reports` (cannot *view* an X report server-side) yet holds `pos.generate_z_report`
(`RolesAndPermissionsSeeder.php:685`) — it may author the fiscal closing but not read the
mid-shift snapshot. Defensible (the Z is the cashier's own close) but the asymmetry is
undocumented.

## 2. Device role names vs seeded roles — do they match?

`apps/pos/src/lib/auth/roles.ts:1-6`:

```ts
const ROLE_LEVEL = { cashier: 0, manager: 1, admin: 1, owner: 2 } as const;
```

Seeded roles (`RolesAndPermissionsSeeder.php:558-560` → `rolePermissionGrants()`):
`admin`, `manager`, `cashier`, `viewer`, `technician`, `operator`, `accountant`.

| Device `ROLE_LEVEL` key | Seeded? | Effect |
|---|---|---|
| `cashier` | yes (`:667`) | level 0 — correct |
| `manager` | yes (`:562`) | level 1 — correct |
| `admin` | yes (`:559`, all permissions) | level 1 — correct |
| `owner` | **NO** — appears only as a protected *name* in `RoleController.php:228, 277`; nothing creates it | dead entry |
| `viewer` / `technician` / `operator` / `accountant` | seeded, **absent from `ROLE_LEVEL`** | `?? 0` ⇒ level 0 ⇒ gate CLOSED |
| any tenant-created custom role | creatable freely (`RoleController.php:187-198`, no name allowlist) | level 0 ⇒ gate CLOSED |

So the mismatch direction is **silently closed, never silently open** — the gate fails safe, which
is the right default. But it is a real defect class: the device gate is a hardcoded four-name
allowlist while the server authorizes on *permissions*. A tenant that renames `manager`, or grants
`pos.view_reports` to a custom "supervisor" role, gets a server that says yes and a device that
says no, with no error message that explains why. The fix is available at zero cost: the pin-data
payload already carries `permissions` (`PosAuthController.php:226`), it is persisted
(`operatorPinRepository.ts:117-122, 213`) and it is already on the `Operator` type
(`operatorStore.ts:24`). See finding B-13-R1-4.

(Also noted: `operatorStore.ts:329` tests for `'super_admin'`; `RoleController.php:228` protects
`'super-admin'` — two spellings, neither in `ROLE_LEVEL`. Fails closed. Minor.)

## 3. PIN-operator roles: provenance, caching, staleness after demotion/offboarding

**Provenance — server.** `GET /pos/auth/pin-data` (`PosAuthController.php:170-235`) returns
`'roles' => $user->getRoleNames()->values()->all()` (`:222`) for each PIN holder. These are the
seeded Spatie role names, resolved under the tenant team set by `SetPermissionsTeam`.

**Cached on the device — yes, durably.** `pullOperatorPins`
(`apps/pos/src/lib/sync/syncService.ts:1301-1330`) writes them into SQLite `operator_pins.roles`
(`operatorPinRepository.ts:117-122`, JSON column, read back at `:65`). Pulled eagerly at terminal
activation (`terminalStore.ts:517`) and on every full sync (`syncService.ts:2322`).

**Staleness window after a server-side role change:**

- **Online:** bounded by the next `pullOperatorPins`. `upsertOperators` does
  `roles = excluded.roles` (`operatorPinRepository.ts:122`), so a *demotion* is picked up on the
  next successful pull; a *removal* is handled by `pruneOperatorsExcept`
  (`syncService.ts:1321-1323`, gated on a non-empty response). The server-side offboarding belts
  are correct: `pinHolders()` (`PosAuthController.php:46-57`) admits only
  `users.status = Active` **and** an `Active` `UserCompanyMembership` row for the CompanyContext
  company. Good.
- **Offline: UNBOUNDED.** PIN verification is offline-first and reads the cached roles with no
  freshness check whatsoever (`operatorStore.ts:174-201` — bcrypt against `op.pin_hash`, then
  `roles: op.roles` straight into the store at `:189-190`). There is **no TTL on `roles`**. A
  demoted manager on a terminal that never regains connectivity keeps full manager access
  indefinitely.
- Contrast: the offline **approval-scope** cache is explicitly TTL'd —
  `APPROVAL_CACHE_MAX_AGE_MS = 7 days` (`apps/pos/src/lib/operatorApproval/approvalVerifier.ts:19`),
  with a docblock that names exactly this hazard ("authority indefinitely").
- Also note the in-memory snapshot: roles are copied into `useOperatorStore.operator` at PIN
  verify time and never re-read from SQLite afterwards; the store is not persisted
  (`operatorStore.ts:162` — plain `create`, no `persist`), so a demotion mid-shift takes effect
  only at the next lock/unlock or app restart, and only if a pull already landed.

**This is newly load-bearing.** Before this lane, `operator.roles` was one of two inputs to a
`Math.max`; the login user's roles carried the gate on the ordinary terminal. After this lane
`operator.roles` — a device-cached, untimed value — is the **sole** basis for every manager gate
in the POS. The staleness surface did not change, but its importance did. See finding B-13-R1-3.

## 4. Tenant/company scoping of `/pos/auth/pin-data`

**Correct, on both axes, with two belts.** `PosAuthController::pinData`:

- `Gate::authorize('pos.operate_terminal')` (`:172`) under the tenant team set by
  `SetPermissionsTeam`;
- `$company = $this->companyContext->requireCompany()` (`:176`);
- roster query `pinHolders($currentUser->tenant_id, $company->id)` (`:207`) →
  `User::where('tenant_id', …)->where('status', Active)->whereIn('id', <active memberships of
  this company>)->whereNotNull('pos_pin')` (`:46-57`);
- the optional `terminal_id` is validated with `Rule::exists('pos_terminals', 'id')` scoped on
  `tenant_id` + `company_id` + `is_active` + not `VirtualAdmin` (`:178-190`) — a UUID rule guards
  the PG uuid column, so no `22P02` 500;
- emitted `company_ids` is pinned to the request's company (`:230`).

Under db-per-tenant the query runs on the swapped default (tenant) connection, which is the right
DB for `users` / `user_company_memberships` / `pos_terminals`. No central-directory read is
attempted here, and no cross-tenant join exists. **No tenancy finding.** `syncPins`
(`:253-315`) is likewise tenant-scoped and additionally self-only.

---

## FINDINGS

### [IMPORTANT] B-13-R1-1 — `apps/pos/src/api/reportApi.ts:167-172` — a server `403` on the X report is swallowed and converted into a locally-authored X report; the report's §4 claim that "the server's own gate is independent and correct today" is false for this endpoint

```ts
  try {
    return await apiPost<XReportResponse>('/pos/reports/x', { terminal_id: terminalId });
  } catch {
    // Offline fallback: generate X report from local SQLite data
    return await generateLocalXReport(terminalId, opts);
  }
```

A bare `catch` cannot tell `403 Forbidden` from a dropped connection. A cashier-provisioned
terminal — the exact case §4 point 1 offers as the mitigating control — is refused by
`ReportController.php:59` and then **silently succeeds locally**, appending an immutable
`X_REPORT` fiscal event (`reportApi.ts:678` → `zSessionAuthoring.ts:602`). Rule 8: that event is
never correctable, only superseded.

Why it matters: the report's backend "no change needed" reasoning rests on a server gate that
does not gate. The conclusion (device gate is the effective gate) happens to be right, but for
the wrong reason, and the next reader who trusts §4 point 1 will believe a boundary exists that
does not. It also means an authz denial produces a *fiscal write* — the worst shape for a
swallowed error.

Fix: narrow the catch — rethrow on `ApiRequestError` with status 401/403 (and ideally 4xx
generally), fall back only on network/5xx. `ApiRequestError` is already imported in `Header.tsx:51`,
so the type is available. Then correct §4 point 1 of the report.

### [IMPORTANT] B-13-R1-2 — `apps/pos/src/components/Header.tsx:832` — cash-drawer deposit/payout got no execution-time manager re-check, while the X report did; it is the one surface where the device gate is the only gate

`ReportsMenu.tsx:58-63` marks both `xReport` and `cashDrawer` `managerOnly: true` and *filters*
the array (`:63`). The lane's own reasoning — "`ReportsMenu` is a menu, not a boundary" (report
§3 residual (ii)) — was applied to the X report (`Header.tsx:396-399`) but **not** to
`onCashDrawerOps`, which is still `() => { setShowReportsMenu(false); setShowCashDrawerModal(true); }`
with no `isManager` test.

This is backwards relative to risk. The X report has a server gate that at least exists
(`ReportController.php:59`); cash-drawer deposit/payout authorize on `pos.operate_terminal`
(`CashDrawerController.php:42, 111`), which **cashier holds** (`RolesAndPermissionsSeeder.php:685`),
so for deposit/payout the client menu filter is the *entire* manager control — and it is a
one-layer control moving real cash.

Fix: mirror the X-report pattern — `if (!isManager) { toast.error(t('reports.managerOnly')); return; }`
in the `onCashDrawerOps` callback (and/or an `isManager` guard at the top of `CashDrawerModal`).
Cheap, symmetric with what the lane already argued for. The server-side tightening stays out of
scope per rule 4 — but it needs the LEDGER row the report recommends in its §6 point 3.

### [IMPORTANT] B-13-R1-3 — `apps/pos/src/stores/operatorStore.ts:174-201` + `apps/pos/src/lib/db/repositories/operatorPinRepository.ts:65` — cached operator `roles` carry no TTL, so an offline demoted/offboarded manager keeps manager access indefinitely; this lane makes that value the sole gate

Offline PIN verification accepts on a bcrypt match against the SQLite cache and lifts
`roles: op.roles` (`operatorStore.ts:189`) with no freshness check. `pullOperatorPins`
(`syncService.ts:1301`) refreshes it only when the device is online; `pruneOperatorsExcept`
(`:1321`) removes *deleted* operators but a **demoted** one is still present, just with stale
roles until a pull lands.

The same codebase already solved this one layer over: `APPROVAL_CACHE_MAX_AGE_MS = 7 days`
(`approvalVerifier.ts:19`) exists precisely so an offline operator does not "hold authority
indefinitely" — and roles are now a *stronger* authority than approval scopes, because after
this lane they gate `/reports`, `/shift`, `/reports/z`, the X report, cash-drawer ops and device
unbind.

Not Critical: the terminal already holds an owner bearer token (see the structural row below), so
this is an operational control, not a security boundary. But it is a regression in *effective*
freshness relative to the pre-lane state (where the login user's roles came from a live-ish auth
session), and the lane's own fail-closed posture argues for closing it.

Fix (choose one, cheap first): (a) apply the existing `APPROVAL_CACHE_MAX_AGE_MS` pattern to
`operator_pins.synced_at` — beyond the TTL, degrade the operator to level 0 (manager surfaces
close, selling continues); or (b) minimally, re-read roles from SQLite on unlock so an
already-landed pull takes effect without an app restart. If neither is taken now, it must be an
explicit LEDGER row, not silence.

### [IMPORTANT] B-13-R1-4 — `apps/pos/src/lib/auth/roles.ts:1-6` — the gate is a hardcoded four-name role allowlist while the server authorizes on permissions; four seeded roles and every tenant-created role silently score 0

`ROLE_LEVEL` knows `cashier|manager|admin|owner`. The seeder creates `admin|manager|cashier|viewer|technician|operator|accountant`
(`RolesAndPermissionsSeeder.php:559-560, 562, 667, 703, 743, 768, 802`), and `owner` is **not
seeded at all** — it exists only as a protected name in `RoleController.php:228, 277`. Tenants can
create arbitrary role names with no allowlist (`RoleController.php:187-198`).

Direction of failure is *closed* (`?? 0` at `roles.ts:14`), which is correct and is why this is
Important rather than Critical. But: a tenant that grants `pos.view_reports` to a custom role gets
a server that authorizes and a device that redirects to `/` with no explanation, and a tenant that
renames `manager` loses every manager surface on every terminal at once.

Fix: gate on the permission the server already uses. `Operator.permissions` is populated from
pin-data (`PosAuthController.php:226`), persisted (`operatorPinRepository.ts:117-122, 213`) and
typed (`operatorStore.ts:24`) — `hasManagerAccess` can become
`operator.permissions.includes('pos.view_reports')` (or a small set), keeping the name allowlist
only as an offline fallback for pre-existing cache rows. That also makes the FE gate provably
identical to `ReportController.php:59`. If deferred, delete the dead `owner: 2` entry or add
`owner` to the seeder — the current state names a role the seeder does not create, which is exactly
the "gate silently closed" trap.

### [IMPORTANT] B-13-R1-5 — `apps/pos/src/components/Header.tsx:132-139` — the X-report blind-count mask FAILS OPEN when the payment-method store is empty, unlike the `/reports` predicate it claims to mirror

```ts
  useEffect(() => {
    if (!showXReportModal) return;
    const physical = new Set<string>();
    for (const method of usePaymentStore.getState().paymentMethods ?? []) {
      if (method.is_physical) physical.add(method.code);
    }
    setPhysicalTenderCodes(physical);
  }, [showXReportModal]);
```

`paymentMethods` defaults to `[]` (`apps/pos/src/stores/paymentStore.ts:424`) and is filled
asynchronously (`:938` from cache, `:954` from the server; `:975-977` explicitly handles the
"cachedMethodCount === 0" case). If the modal is opened before that lands — cold boot, or offline
with an empty payment cache — `physicalTenderCodes` is empty, `concealedTenderCodes` is empty,
and **every tender amount is disclosed under blind counting**.

`/reports` does not have this hole: `ReportsPage.tsx:179` computes `concealed: concealCash && m.is_physical`
from the preview row's own `is_physical`, with no dependency on the store being loaded. So the two
surfaces do *not* in fact agree in the degraded case, contrary to report §3.

Fix: fail closed — if `paymentMethods` is empty while `cashDisclosure === 'conceal' && shift !== null`,
conceal **all** tender amounts rather than none; or carry `is_physical` on the X-report row the way
the `/reports` preview does.

### [MINOR] B-13-R1-6 — `apps/pos/src/lib/offline/cashDisclosurePolicy.ts:10-14` — docblock now asserts the opposite of the code

> "The `/shift` route is nominally manager-only, but `hasManagerAccess` takes the MAX of the PIN
> operator's roles and the logged-in device user's roles…"

That is precisely what this lane removed (`roles.ts:51-53`). A stale comment describing a
*defeated* authz invariant is worse than none — the next reader may re-derive a "the route gate is
insufficient" conclusion from an obsolete premise. The paragraph's *conclusion* (the policy must be
honoured independently of the route gate) is still right and should be kept; only the reason needs
rewriting. One-line fix, and the lane already touched this file's neighbours.

### [MINOR] B-13-R1-7 — `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:1290-1338` vs `apps/pos/src/api/reportApi.ts:499-503` — the mask keys on `payment_type`; I could not verify the two builders share a code namespace

The device-local builder maps payment-method **id → code** (`reportApi.ts:500-503`), so on the v3
device path `row.payment_type` is a `payment_methods.code` and matches `is_physical` codes. The
server builder uses `$payment->payment_type` from `receipt_payments`
(`ReceiptPayment.php:36` — "Immutable snapshot of payment type"). **Cannot verify** from the code I
read that this snapshot is the same code namespace as `payment_methods.code`. If it is not, the
mask silently no-ops on v2 (server-path) terminals. Low impact for tenant #1 (v3 goes local at
`Header.tsx:410-420` and never calls the server), but worth one grep before merge, or a comment
recording that the mask is v3-only by design.

### [OBSERVATION — not a finding] first-run PIN setup inherits the login user's roles

`operatorStore.setupPin` (`:329-361`) seeds the first operator from the authenticated user
(`roles: user.roles`), reachable whenever `hasPins === false` (`App.tsx:311-330`). On a virgin
owner-logged-in terminal, whoever is holding it can therefore mint an owner-role PIN. Pre-existing,
correctly relied upon by the lane as the "no lockout for the owner" escape valve, and gated
server-side by the `hasPins` roster — but it is the same trust assumption as the structural row
below, and it should be named in the same LEDGER entry rather than left implicit.

---

## Test quality

Adequate and honest. `AppShell.managerGate.test.tsx:107-121` exercises the **deny** path in the
exact deployment shape (`mockOperator = cashier`, `mockUserRoles = ['owner']`) across all three
manager routes plus the nav destination, and `:143-159` proves the owner-as-PIN-operator path did
not regress. `roles.test.ts` covers `undefined`/`[]`/unknown-role fail-closed. Nothing asserts
`true`, nothing mocks the unit under test. The report's caveat that `roles.test.ts` could not go
red on its own (the removed second argument defaulted to `undefined`) is accurate and correctly
disclosed, and RED was properly taken at the consumers.

Gap consistent with the findings above: no test covers the empty-`paymentMethods` X-report case
(B-13-R1-5), no test covers a cash-drawer deny (B-13-R1-2), and no test covers a stale-role cache
(B-13-R1-3). Each fix should land with its own deny-path case.

---

## Recommended LEDGER structural-risk row (owner-scoped device credentials)

Exact wording:

> **B-13-S1 (structural, P2, owner-ruling required) — POS device credentials are owner-scoped: the
> PIN gate is an operational control, not a security boundary.** An IziPOS/Otospex terminal is
> signed in once with a back-office account — in the ordinary single-account deployment the
> owner's — and its Sanctum bearer token is then held by the physical device for as long as it
> stays bound. Every POS endpoint authorizes `$request->user()`, i.e. that token's user: the X
> report and Z history on `pos.view_reports` (`ReportController.php:59, 218, 261, 327, 366, 424`),
> POS analytics (`AnalyticsController.php:29-145`), shift open/close on `pos.manage_shifts`
> (`ShiftController.php:49, 106`), cash-drawer deposit/payout on `pos.operate_terminal`
> (`CashDrawerController.php:42, 111`), and the PIN roster itself (`PosAuthController.php:172`).
> **The PIN operator identity is never transmitted on any of them** (`apps/pos/src/lib/api.ts`
> sends only `X-Client-Type`, `Authorization: Bearer`, `X-Company-Id`). Consequence: anyone with
> physical access to a bound terminal can `curl` every endpoint the owner can reach — including
> non-POS tenant endpoints — regardless of which PIN is active or whether any PIN is active.
> B-13 (merged) closes the *UI* leak by composing the manager gate from the PIN operator only
> (`apps/pos/src/lib/auth/roles.ts:51-53`); it does not and cannot close this, and the first-run
> `setupPin` path (`operatorStore.ts:329-361`) additionally lets a virgin terminal mint a PIN
> carrying the login account's roles. Closing it requires per-operator device credentials — PIN
> verification exchanging the provisioning token for a server-issued, operator-scoped,
> short-TTL token that POS endpoints authorize instead — plus a decision on what the device may
> do while offline with no live token. Program-level; blocks nothing today; must be a conscious
> owner acceptance before tenant #1 hands a terminal to non-owner staff. Related open rows: the
> device-side manager gate is client-side only for cash-drawer deposit/payout (server accepts
> `pos.operate_terminal`, which cashier holds — `RolesAndPermissionsSeeder.php:685`), and cached
> operator roles carry no offline TTL (`operatorStore.ts:174-201`), unlike approval scopes
> (`approvalVerifier.ts:19`, 7 days).

---

## What to fix before merge

Narrow the `catch` in `reportApi.ts:167-172` so a 403 is not laundered into a locally-authored
fiscal X_REPORT (B-13-R1-1), add the missing `isManager` guard on `onCashDrawerOps`
(`Header.tsx:832`, B-13-R1-2), make the X-report tender mask fail closed when `paymentMethods` is
empty (`Header.tsx:132-139`, B-13-R1-5), correct the stale `hasManagerAccess` docblock
(`cashDisclosurePolicy.ts:10-14`, B-13-R1-6), and either TTL the cached operator roles or file
B-13-R1-3 + B-13-R1-4 as explicit LEDGER rows alongside the B-13-S1 structural row above.

---

# r2 scoped re-review

- **Range reviewed:** `58a14ac25..0c84fc7c8` (5 commits, 20 files, +1078/−159 — **all `apps/pos`, zero `apps/api`**)
- **Lens:** authz/tenancy only (fiscal lens reviewed separately)
- **Date:** 2026-08-26 · read-only

## VERDICT

**spec ✅ / quality CHANGES-REQUESTED (one Important)**

All seven r1 findings are ADDRESSED with real code, and the three rulings in force are implemented
as ruled. One new Important lands squarely on the R1-3 fix: the TTL is computed from a column that
two *other* writers stamp, so it dates "the last write to this row", not "the last authority
refresh" — which weakens the TTL in precisely the scenario it exists for.

## R1-1..R1-7 disposition

| # | Status | Evidence |
|---|---|---|
| R1-1 (403 laundered into a local fiscal X) | **ADDRESSED** | `apps/pos/src/api/reportApi.ts:205-216` rethrows via `isAuthorizationRefusal` (`:223-226`, `error instanceof ApiRequestError && (status===401||403)`; class at `apps/pos/src/lib/api.ts:43-53` carries `status`). 404/5xx/transport still fall back. `Header.tsx:447-448` surfaces it as `reportError` — no `generateLocalXReport`, so no `X_REPORT` append. §4 point 1 of the report is corrected at `task-7-report.md:386-388`. |
| R1-2 (no execution-time gate on cash-drawer ops) | **ADDRESSED** | `apps/pos/src/components/Header.tsx:386-393` `handleCashDrawerOps` refuses with `toast.error(t('reports.managerOnly'))` before `setShowCashDrawerModal(true)`. Verified it is the **only** entry point: `setShowCashDrawerModal(true)` occurs exactly once in the tree (`Header.tsx:392`). |
| R1-3 (no TTL on cached operator authority) | **ADDRESSED, with R2-1 open** | `apps/pos/src/lib/auth/operatorAuthorityFreshness.ts:52-68`, 7 days via `OPERATOR_AUTHORITY_MAX_AGE_MS = APPROVAL_CACHE_MAX_AGE_MS` (`:29`); consumed at `apps/pos/src/stores/operatorStore.ts:206` on the offline verify branch; gate closes at `apps/pos/src/lib/auth/roles.ts:119`. Rule-20 read is correct (`sqliteUtcToDate`, `sqliteTime.ts:44-49`). See R2-1. |
| R1-4 (role-name allowlist vs permission gates) | **ADDRESSED** | `apps/pos/src/lib/auth/roles.ts:57-60` `MANAGER_SURFACE_PERMISSION` + `:114-127` permission-first with a name fallback. Deny-path tests at `apps/pos/src/lib/auth/roles.test.ts:66-105` (custom `supervisor` admitted; manager-named-but-stripped refused; per-surface cross-refusal). |
| R1-5 (tender mask failed open on an empty store) | **ADDRESSED** | `is_physical` now travels on the row (`reportApi.ts:52-71` doc, `:723-728` populated from `physicalByCode`); `XReportModal.tsx:73-75` `isConcealed = concealPhysicalTenders && row.is_physical !== false` (UNKNOWN conceals). The store read is gone from `Header.tsx` (the `useEffect` and `physicalTenderCodes` are deleted). Bonus fail-closed: `getAllPaymentMethods` filters `is_active = 1` (`paymentRepository.ts:76`), so a deactivated tender resolves `undefined` ⇒ concealed. |
| R1-6 (falsified docblock) | **ADDRESSED** | `apps/pos/src/lib/offline/cashDisclosurePolicy.ts:10-28` — premise marked falsified, conclusion re-argued from two surviving reasons. |
| R1-7 (`payment_type` namespace — "cannot verify") | **ADDRESSED / resolved against me** | Verified independently: `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:358` writes `'payment_type' => $paymentMethod->name` and `:359` stores the code separately in `payment_method_code`. The device builder emits the **code**. The two namespaces are genuinely different — the old code-join would have no-op'd on every server-built row. Removing the join is the right fix; over-concealing on v2 is the correct direction. |

## Scrutiny items requested

**Which permission gates each surface, and does pin-data ship it.**
`apps/pos/src/lib/auth/roles.ts:57-60`: `reports → pos.view_reports` (routes `/reports`, `/shift`,
`/reports/z`, the nav destination, the X report, the Z-history menu entry, device unbind, and the
`/sales` conceal predicate) and `cash_drawer → pos.approve_cash_drawer_control` (drawer ops only).
`GET /pos/auth/pin-data` ships, per operator (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:218-233`):
`id`, `tenant_id`, `name`, `email`, `pin_hash`, `roles` (`:224`), **`permissions` (`:225` —
`$user->getAllPermissions()->pluck('name')`, i.e. role-derived + direct)**, `can_discount`,
`max_discount_percent`, `company_ids`, `terminal_ids`, `approval_scopes`,
`approval_scope_permissions_fetched_at`, `server_time`. Both gate keys therefore ship whenever
granted. The online paths ship them too: `verifyPin` (`:98`) and `setupPin` (`:153`) both return
`permissions`. Persisted at `operatorPinRepository.ts:130-135` (`permissions = excluded.permissions`)
from `pullOperatorPins` (`syncService.ts:1307-1311`), read back at `:65`/`rowToOperator`.
(Note: r1 cited `PosAuthController.php:226` for `permissions`; the exact line is **:225**.)

**Seeder key match — exact.** `pos.view_reports` defined `RolesAndPermissionsSeeder.php:368`,
granted to `manager` at `:619`, **absent** from the `cashier` block (`:667-699`, POS grants at `:685`).
`pos.approve_cash_drawer_control` defined `:379`, granted to `manager` at `:624`, **absent** from the
cashier block. Both strings match the device constants character-for-character. No new permission,
no new `can:` guard, no new route — **zero `apps/api` files in the range**, so there is nothing to
re-seed and no rule-12 / module-gating obligation from this diff.

**TTL source of truth / absent = stale.** `isOperatorAuthorityStale` (`operatorAuthorityFreshness.ts:57`)
returns `true` on `!syncedAt`; `:60` returns `true` on `NaN`; `:65` returns `true` on gross forward
dating. `CachedOperator.synced_at` is optional (`operatorPinRepository.ts:36`) but `rowToOperator`
always populates it (`:91`, `row.synced_at ?? null`) and the table is read with `SELECT *` (`:96`,
`:103`), so the column is never dropped by a projection list. Grep confirms **exactly one consumer**
(`operatorStore.ts:206`) — no surface treats an absent stamp as fresh.

**No cashier lockout on a failed roster pull.** `hasManagerAccess` has five call sites, all manager
surfaces: `AppShell.tsx:81` (nav + the three routes), `Header.tsx:121` and `:123`, `ReportsMenu.tsx:33`,
`SettingsPage.tsx:99`, `TodaySalesPanel.tsx:97` (conceal only). `authority_stale` is read in exactly
one place (`roles.ts:119`). Nothing on the sell / refund / shift-open / shift-close path consults
either. A stale-authority operator keeps trading; only manager surfaces close, and `/sales` conceals
money while still listing receipts and the count. **Confirmed: manager surfaces only, no cashier lockout.**

## NEW FINDINGS (fix diff only)

### [IMPORTANT] B-13-R2-1 — `apps/pos/src/lib/db/repositories/operatorPinRepository.ts:264` and `:295` — `synced_at` is not an authority-freshness stamp: two non-roster writers bump it, so the R1-3 TTL can be reset without any roster pull

`isOperatorAuthorityStale` treats `operator_pins.synced_at` as "when this operator's roles and
permissions were last confirmed by the server" (`operatorAuthorityFreshness.ts:5-29`,
`operatorPinRepository.ts:27-35`). It is not. Three writers stamp it:

1. `upsertOperators` (`:131`, `:222`) — the roster pull. This is the intended one.
2. `updateOperatorDiscountPermissions` (`:254-265`, `synced_at = datetime('now')` at `:264`) — called
   from `operatorStore.ts:156` inside `resolveOnlineDiscountPermissions`, which fires **on every
   offline-accepted PIN verify** (`operatorStore.ts:259`). It refreshes discount fields from
   `/pos/discount-permissions`; it does **not** touch `roles` or `permissions`.
3. `invalidateTerminalDiscountPermissions` (`:287-298`, stamp at `:295`) — `terminalStore.ts:201`,
   on a terminal-record discount-settings change.

Consequence: a device that is **online but whose roster pull is broken** (pin-data 403 after the
device account loses `pos.operate_terminal`, a persistently failing sync, a pruned/erroring pull —
`pullOperatorPins` swallows its own failure and returns `0`, `syncService.ts:1325-1332`) has its
authority clock reset at every PIN verify by an endpoint that carries no authority. The cached
`roles`/`permissions` of a demoted manager then stay "fresh" indefinitely — the exact hazard R1-3
was ruled to close. The pure-offline case still works (both extra writers need the network), so this
is Important, not Critical.

Fix (either is a small change): (a) stop stamping `synced_at` in the two discount writers — they
already have `discount_permissions_fetched_at` for their own bookkeeping; or (b) date the authority
from a stamp only the roster pull writes — `sync_metadata.operators_last_sync`
(`syncService.ts:1322`) already exists and is written only after a successful `upsertOperators`.
Add a test that a discount-permission refresh does **not** un-stale an operator.

### [MINOR] B-13-R2-2 — `apps/pos/src/components/pos/ReportsMenu.tsx:61` + `:64` vs `apps/pos/src/components/Header.tsx:388` — the cash-drawer surface is filtered on one permission and enforced on another

`ReportsMenu` filters every `managerOnly` entry on `isManager` = `hasManagerAccess(operator)` =
`pos.view_reports` (`ReportsMenu.tsx:33`, `:64`), including the cash-drawer entry (`:61`), while the
handler enforces `pos.approve_cash_drawer_control` (`Header.tsx:123`, `:388`). Harmless on seeded
roles (manager holds both — `RolesAndPermissionsSeeder.php:619`, `:624`; admin holds all), but R1-4's
own rationale was that tenants create custom roles: one holding only
`pos.approve_cash_drawer_control` never sees the entry it is entitled to, and one holding only
`pos.view_reports` sees it and gets a toast. Fix: `hasManagerAccess(operator, 'cash_drawer')` for
that one entry — pass a per-item surface into the filter.

### [MINOR] B-13-R2-3 — `apps/pos/src/lib/auth/roles.ts:122` — an empty `permissions` array falls back to role names, so a manager-*named* role with no permissions is admitted where the server refuses

The fallback condition is `permissions !== undefined && permissions.length > 0`; an operator whose
roster row carries `permissions: []` drops to `isManagerRole(roles)` (`:126`), and
`roles.test.ts:100` pins that as intended (`{ roles: ['manager'], permissions: [] }` ⇒ `true`). That
is the opposite of the R1-4 case the same test file proves at `:83-87` (manager-named, stripped ⇒
refused). The legacy-cache motivation only justifies `permissions === undefined`; an explicitly
empty list is a positive server answer meaning "this principal holds nothing". Fix: fall back on
`permissions === undefined` only.

### [MINOR] B-13-R2-4 — `apps/pos/src/api/reportApi.ts:225` — 401 is treated as a refusal, so an expired/rotated device token now blocks the X report outright instead of degrading to the local builder

`isAuthorizationRefusal` covers 401 as well as 403. 403 is genuinely "you may not"; 401 is "your
credential is not currently valid", which on an offline-first device is closer to an outage than to
a denial, and is the most likely transient credential state on a long-lived terminal. The direction
is fail-closed and the choice is documented (`:206-215`), so this is a flag-for-consciousness, not a
defect: confirm the owner wants a token-rotation event to withhold a legitimate manager's X report
rather than serve the device-built one. If not, narrow the rethrow to 403.

## Test quality (fix round)

Real deny-path coverage, no self-mocking. `roles.test.ts:66-140` exercises permission-based admit
**and** refuse per surface, the cross-surface refusals, and all three stale-authority closures;
`operatorAuthorityFreshness.test.ts` covers the missing/unparseable/future-dated stamps and the
rule-20 space-separator parse; `generateXReport.authz.test.ts` pins that 401/403 do **not** fall
back while 404/5xx/transport do. The report's disclosure that the `/sales` cases were written after
the implementation and proved to bite by forcing `concealTakings = false` (6 failed / 17 passed) is
the honest way to record that. Gap left by this round: no test asserts that a discount-permission
refresh must not un-stale an operator (R2-1).

## What to fix before merge

Date the operator-authority TTL from a stamp only the roster pull writes (R2-1) — otherwise the
R1-3 fix silently no-ops on an online device with a broken roster pull; the three Minors (R2-2 menu
filter surface, R2-3 empty-permissions fallback, R2-4 401 posture) can land in the same commit or be
LEDGER'd explicitly.

## r3 scoped re-review (range 0c84fc7c8..947e3b655) — VERDICT: R2-1..R2-4 ALL ADDRESSED — mergeable
> Provenance: verdict returned by the scoped authz re-reviewer; its file append was lost with the lane worktree — restored by the orchestrator (final review I-2). Key evidence from the verdict:
- R2-1 ADDRESSED: `operator_pins.synced_at` removed from the read/write authority surface (dropped from `CachedOperator`/`OperatorPinRow`; the two non-authority writers at `operatorPinRepository.ts:264/:290` no longer stamp it); TTL dated exclusively from `sync_metadata['operators_last_sync']` (written only after `upsertOperators` succeeds, `syncService.ts:1290-1327`), read via `readOperatorAuthoritySyncedAt` (fails closed); sole consumer `operatorStore.ts:197-200`; repo-wide grep found no remaining production read of `operator_pins.synced_at` for authority.
- `permissions: []` → closed: `roles.ts:126` narrowed to `permissions !== undefined`; pinned by `roles.test.ts` (`{roles:['manager'],permissions:[]}` ⇒ false).
- Unbind on `pos.manage_terminals`: surface added at `roles.ts:60`; all four `SettingsPage.tsx` sites converted; seeder check — manager grants at `RolesAndPermissionsSeeder.php:618`, absent from cashier and accountant blocks.
- 401 → `ReauthenticationRequiredError` before the 403 refusal check in `reportApi.ts`; both stop before `generateLocalXReport`; `Header.tsx` renders `t(err.i18nKey)`; en/fr keys present (`pos.json:430-431`).
- No new Critical/Important in the fix diff.
