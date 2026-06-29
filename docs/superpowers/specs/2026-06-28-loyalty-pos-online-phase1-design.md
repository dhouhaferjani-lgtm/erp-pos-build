# Design — POS Loyalty Phase 1 (online-only balance + tier + earn estimate)

> **Goal (owner, 2026-06-28):** surface loyalty in the POS for the parapharmacy demo. When a customer
> account is attached at checkout and the device is online, show the customer's **balance + tier** and
> a live **"+N pts this sale"** estimate, gated on the Loyalty add-on. Customers **auto-enroll when
> attached** (online) so the feature works without manual setup. Points accrue **server-side** on sale
> sync (already built + idempotent).
>
> Supersedes the earlier handover `…loyalty-pos-gating-offline-handover.md`: **online-only** (not the
> offline mirror), and **auto-enroll at attach** (not inside the fiscal projection).

## Scope

**IN (Phase 1)**
- **Auto-enroll at customer-attach (online):** the POS's online balance call doubles as
  "ensure-enrolled" — it passes the attached customer's already-known phone, and the backend
  find-or-creates the `LoyaltyMember` + enrollment in the active program, then returns the balance.
- **POS balance/ensure-enroll endpoint:** online, gated, returns `{enrolled, balance, tier}`.
- **POS loyalty chrome:** online + module-on + customer-account attached → balance + tier + a local
  earn estimate (`floor(cart TTC total × rate)`), rate cached at login.
- **Module gating** both layers (rule 12).

**OUT (Phase 2 — design must not preclude, do NOT build now)**
- Redeem / pay-with-points tender; device-side accrual.
- **Offline balance mirror** (no SQLite loyalty columns, no `pullCustomers` loyalty) — offline ⇒
  loyalty chrome hidden.
- **Auto-enroll inside the fiscal projection** / catching offline sales for un-enrolled customers
  (a customer never attached while online won't earn — acceptable; offline = no loyalty).
- QR-code resolution (the balance/ensure-enroll endpoint is the seam a future QR scan reuses).
- Explicit enrollment consent/opt-in UI (the attach-time auto-enroll is the seam this refines later).

## Locked decisions (owner, 2026-06-28)

1. **Auto-enroll at customer-attach, online, folded into the balance call.** The POS passes the
   attached customer's phone; the backend find-or-creates member+enrollment in a normal HTTP request
   (CompanyContext present). Chosen after the adversarial review showed sale-sync auto-enroll needed a
   new Partner contract + member find-or-create + fiscal-transaction savepoint safety; attach-time
   dissolves all three because the POS already holds the phone and there is no fiscal-transaction
   write. The fiscal projection / `SaleEarningService` is **unchanged** — by sale time the member
   already exists and earns normally.
2. **Balance = online-only, pure server-side.** Offline ⇒ no loyalty chrome.
3. **Earn basis = TTC** (receipt total). The POS estimate uses cart **TTC total × rate** to match the
   server's TTC accrual.
4. **Phase-1 UI = balance + tier + live earn estimate.**
5. **Base = local `dev`** (carries the earn foundation). Build chrome with the `Badge` atom on `dev`
   (not the `KpiCard` on the unmerged redesign branch). Earn + POS promote together.

## Codebase facts (verified 2026-06-28, incl. adversarial review)

- **Earn foundation (already merged on this base):** `SaleEarningService` resolves member by
  `contactId`→`partnerId`, credits via idempotent `EarningProcessingService::earnPoints()` (uses
  `getScaleSafe($currency, FALLBACK_SCALE)` — **CompanyContext-safe; the "no-arg getScale() throws"
  review claim was false, dismissed**). Invoked from `PosCoreReceiptProjection::earnLoyaltyPoints()`,
  sale-only, TTC `earnBase`. **This path is NOT modified by this phase.**
- **`loyalty_members.phone` is NOT NULL + UNIQUE `(tenant_id, phone)`** (`2026_01_10_100001_create_
  loyalty_members_table.php:26,47`). So member creation requires a phone — supplied by the POS at
  attach (the attached customer / synced mirror carries `phone`). Members dedupe by `(tenant, phone)`.
- **`MemberEnrollmentService::enroll(memberId, programId, ?welcomeBonus)` THROWS if the member is
  already enrolled** (`:56-58`) and does **not** create the member. So the endpoint must:
  find-or-create the member, then enroll only if `findByMemberAndProgram` is null (replay/re-attach
  safe). The member-**create** path is NOT in `MemberEnrollmentService` — the plan locates it (the
  member-registration service/repo behind the existing `EnrollMemberModal`).
- **`PartnerServiceInterface` exposes only `findByVatOrName` + `upsertWithTypeMerge`** — no phone/
  contact-by-id read. We deliberately **avoid needing it**: the POS supplies the phone (rule 6 stays
  clean; no new cross-module contract).
- **Active program:** `LoyaltyProgramRepositoryInterface::findByTenantAndStatus($tenantId,
  ProgramStatus::Active)`. `Enrollment.current_balance` (decimal:3), `currentTier.name`.
- **Earn-rate API:** `GET /api/v1/loyalty/earn-rate` → `{"data":{"rate": string|null}}`, gated
  `module:Loyalty` + `can:loyalty.view`.
- **Existing POS loyalty routes:** `LoyaltyPOSController` under `module:Loyalty` +
  `can:pos.operate_terminal` (e.g. `member-lookup` by phone). `SaleEarningService::resolveMember` is
  **private** — extract a shared resolver so the new endpoint and the service agree.
- **POS:** `companyConfig.all_enabled_modules`; `hasModule(config,name)` (`productStore.ts:110`);
  `authStore.refreshCompanyConfig()` (`:418`) + `companyConfigCache.ts` (the rate-cache seam);
  `paymentStore.attachCustomer(AttachedCheckoutCustomer)` — the attached customer **carries `phone`**
  and `id`; emits `pos.customer_attached` audit. `Badge` at `components/ui/Badge.tsx`; tokens at
  `lib/designTokens.ts`. **Cart total:** `cartStore.total()` returns a **`number`** (and
  `subtotalString()` a decimal string) — the estimate is display-only so a `number` is acceptable
  (mirror the editor's display-only `Number()`); the plan confirms `total()` is **TTC**.

## Architecture

```
ATTACH (online) ─▶ POST /loyalty/pos/balance {partner_id?, contact_id?, phone, name?}
   hasModule('Loyalty')        backend (normal request, CompanyContext present):
   │                             ├─ resolve member (contact→partner; shared resolver)
   ▼                             ├─ none + phone + active program ⇒ find-or-create member(phone) + enroll (guarded)
loyalty chrome (Badge):         └─ return {enrolled, balance, tier}
   balance + tier
   + "+N pts this sale" = floor(cartTTC × rate_cached)
offline / module-off / walk-in / no-phone ⇒ hidden or "joins on purchase"

SALE-SYNC (unchanged): fiscal SALE_RECEIPT ─▶ PosCoreReceiptProjection ─▶ SaleEarningService
   member already exists (enrolled at attach) ⇒ earnPoints() credits idempotently
```

### Backend components (apps/api)

**A. `POST /loyalty/pos/balance` (ensure-enroll + balance)** on `LoyaltyPOSController`, same group
(`module:Loyalty` + `can:pos.operate_terminal`). FormRequest: `partner_id?`/`contact_id?` (≥1, UUID),
`phone?` (string), `name?`. Behavior, in a normal request:
1. Resolve member via a **shared resolver** extracted from `SaleEarningService` (contact→partner,
   tenant-scoped).
2. If no member AND `phone` present AND an active program exists: **find-or-create** the member —
   first by `(tenant, loyaltyable)`, else by `(tenant, phone)` (the unique key), else create with
   `phone`/`name`/loyaltyable — then **enroll** only if `findByMemberAndProgram` is null (guards the
   `enroll()`-throws-on-duplicate behavior; replay/re-attach safe).
3. Return `{"data":{"enrolled": bool, "balance": string, "tier": string|null}}`. No phone / no active
   program ⇒ `{enrolled:false, balance:"0.000", tier:null}` (no throw).
- Tenant-scoped; validate ids are UUIDs before query; never leak cross-tenant data.
- **Member-create path:** reuse the existing member-registration service/repo (the plan locates it).
- Seam: the find-or-create-member branch is where a future consent gate slots in.

### POS components (apps/pos), online-only

**B. `useHasModule('Loyalty')`** — selector over `productStore.companyConfig` via `hasModule`.

**C. Earn rate** — returned by `POST /loyalty/pos/balance` itself (it already loads the active
program and is `pos.operate_terminal`-gated), so the POS needs no separate `can:loyalty.view` call and
no login-time rate cache. The chrome only renders on attach, which is exactly when the rate is needed.
Missing rate ⇒ no estimate. *(Revised from a login-time fetch of `/loyalty/earn-rate` after the
adversarial review showed POS terminal users lack `loyalty.view`.)*

**D. Loyalty chrome** (`Badge`-based component near the attached-customer/checkout area). On attach,
when `hasModule('Loyalty')` AND the customer is **server-synced** (has a server id) AND has a phone:
`POST /loyalty/pos/balance`.
- **Success (online):** show tier + balance (if `enrolled`) + estimate `floor(cartTTC × rate)`
  recomputed on cart-total change. `enrolled:false` (e.g. no phone) ⇒ hide balance, show estimate + a
  subtle "joins on purchase" note.
- **Fetch failure (offline/network) / module off / walk-in / unsynced-local customer:** render
  nothing. Tying the chrome to a successful online call IS the "offline = no loyalty" rule.

## Data flow

login/bootstrap → config cached → (if Loyalty) rate cached → cashier attaches a synced customer
(online) → `POST /loyalty/pos/balance` ensures member+enrollment and returns balance → chrome shows
balance+tier+estimate → cart changes → estimate recomputed → sale completes → fiscal event syncs →
server projection → `SaleEarningService` credits the (already-enrolled) member idempotently → next
attach reflects the new balance.

## Error handling / edges

- **Offline / network error / module off / walk-in / unsynced-local customer (no server id yet):**
  chrome hidden (no stale numbers; no enroll attempt against a non-existent server record — Codex S5).
- **Customer with no phone on file:** endpoint returns `enrolled:false`; chrome shows estimate +
  "joins on purchase" (no member created — phone is the required unique key).
- **Re-attach / repeated calls:** find-first on member and enrollment ⇒ no duplicate member,
  no duplicate enrollment, no throw (replay-safe).
- **No active rate (no Spend rule):** no estimate; balance/tier still shown if enrolled.
- **Estimate precision:** display-only integer (`floor`), so reading `cartStore.total()` as a number
  is acceptable (rule 19 governs persisted/payload money, not an on-screen estimate).

## Testing (TDD; PHPUnit by-path only — NEVER the full suite/`--parallel`; Vitest for POS)

Backend (PHPUnit):
1. `POST /loyalty/pos/balance`: (a) existing enrolled member → balance+tier; (b) no member + phone +
   active program → creates member+enrollment, returns `enrolled:true, balance:"0.000"`; (c) repeat
   call (same customer) → no duplicate member/enrollment, same result (replay-safe); (d) no phone →
   `enrolled:false`, nothing created; (e) no active program → `enrolled:false`; (f) 403 when
   `module:Loyalty` disabled (grant `pos.operate_terminal` to isolate module gating); (g) non-UUID id
   rejected; cross-tenant id not leaked.
2. Shared member resolver: extracted and used identically by the endpoint and `SaleEarningService`
   (behavior unchanged for the service — a characterization test).

POS (Vitest):
3. `useHasModule('Loyalty')` true/false.
4. Loyalty chrome: renders when online-call-succeeds + module-on + synced customer attached; hidden
   when the call fails (offline), module off, walk-in, or unsynced-local customer; `enrolled:false`
   shows estimate + "joins on purchase"; estimate = `floor(cartTTC × rate)` and recomputes on
   cart-total change; balance/tier from the response.
5. Earn-rate caching at bootstrap (fetched only when module on; persisted; read by the estimate).

## File-target map

| Concern | File | Action |
|---|---|---|
| Ensure-enroll + balance endpoint | `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php` + `Loyalty/Presentation/routes.php` + a FormRequest | edit/new |
| Shared member resolver | extract from `SaleEarningService` (e.g. a small resolver class/method both use) | refactor |
| Member create + enroll | reuse member-registration service/repo + `MemberEnrollmentService::enroll` (guarded) | reuse |
| POS module selector | `apps/pos/src/...` `useHasModule` (reuse `productStore.hasModule`) | new |
| Earn-rate cache | POS bootstrap/config flow (`authStore.refreshCompanyConfig` + a rate store/cache) | edit |
| Loyalty chrome | new `CustomerLoyaltyBadge`-style component (Badge + tokens) in the attached-customer/checkout UI | new/edit |
| i18n | POS locale files (en + fr) for loyalty labels | edit |

## Out-of-scope guard (restate)

Do NOT this phase: redeem / pay-with-points; device-side accrual; an offline balance mirror (SQLite
loyalty columns / `pullCustomers` loyalty); auto-enroll inside the fiscal projection / a new Partner
phone-read contract; QR scanning; explicit consent/opt-in UI. Offline ⇒ loyalty chrome hidden. The
fiscal projection and `SaleEarningService` earn path are unchanged by this phase.
