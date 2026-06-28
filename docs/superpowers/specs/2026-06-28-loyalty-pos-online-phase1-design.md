# Design — POS Loyalty Phase 1 (online-only balance + tier + earn estimate)

> **Goal (owner, 2026-06-28):** surface loyalty in the POS for the parapharmacy demo. When a customer
> account is attached at checkout and the device is online, show the customer's **balance + tier** and
> a live **"+N pts this sale"** estimate, gated on the Loyalty add-on. Customers **auto-enroll on their
> first sale** so the feature works without manual setup. Points still accrue **server-side** on sale
> sync (already built + idempotent).
>
> **This spec supersedes the earlier handover** `…loyalty-pos-gating-offline-handover.md` **§1**: the
> owner chose **online-only, pure server-side balance** over an offline balance mirror.

## Scope

**IN (Phase 1)**
- **Auto-enroll on first sale (server-side):** when a sale syncs with a customer account attached who
  has no `LoyaltyMember`, the backend creates the member + enrollment in the active program, then
  credits. Idempotent. Walk-ins (no customer account) never enroll.
- **POS balance lookup endpoint:** online, gated, returns a customer's `{enrolled, balance, tier}`.
- **POS loyalty chrome:** online + module-on + customer-account attached → show balance + tier + a
  local earn estimate (`floor(cart TTC total × rate)`), rate cached at login.
- **Module gating** both layers (rule 12).

**OUT (Phase 2 — design must not preclude, but do NOT build now)**
- Redeem / pay-with-points tender.
- Device-side accrual (points accrue only server-side on sync).
- **Offline balance mirror** (no SQLite loyalty columns, no `pullCustomers` loyalty wiring) — offline
  ⇒ loyalty chrome hidden.
- QR-code customer/member resolution (the balance endpoint is the seam a future QR scan reuses).
- Explicit enrollment consent/opt-in UI (auto-enroll is the seam this refines later).

## Locked decisions (owner, 2026-06-28)

1. **Auto-enroll on first sale, server-side.** Easiest path that expands into the correct solution
   (later: gate behind a consent flag). No customer account attached ⇒ no enrollment, no earn.
2. **Balance = online-only, pure server-side.** Offline ⇒ no loyalty chrome at all. Replaces the
   handover's offline-mirror design.
3. **Earn basis = TTC** (receipt total). The shipped server earn uses the TTC total, so the POS
   estimate uses cart **TTC total × rate** to match — never HT, or the estimate would mislead.
4. **Phase-1 UI = balance + tier + live earn estimate.**
5. **Base = local `dev`** (contains the earn foundation). Build chrome with the `Badge` atom already on
   `dev` (not the `KpiCard` on the unmerged redesign branch). Earn + POS promote together.

## Codebase facts (verified 2026-06-28, on this base)

- **Earn engine + server earn (already merged):** `SaleEarningService` (`apps/api/app/Modules/Loyalty/
  Application/Services/SaleEarningService.php`) resolves the member by `contactId`→`partnerId`
  (polymorphic `loyaltyable`), loops active enrollments, credits via `EarningProcessingService::
  earnPoints()` (idempotent via `findBySourceDocument`). It is invoked from
  `PosCoreReceiptProjection::earnLoyaltyPoints()` after `redeemVouchers`, sale-only, with TTC
  `earnBase`. **Currently: no member ⇒ returns (no earn).**
- **Active program:** `LoyaltyProgramRepositoryInterface::findByTenantAndStatus($tenantId,
  ProgramStatus::Active)`. **`MemberEnrollmentService`** exists for enrollment;
  `LoyaltyMember`/`Enrollment` models exist (`Enrollment.current_balance` decimal:3, `current_tier_id`,
  `status`, `currentTier` relation with `name`/`earning_multiplier`).
- **`LoyaltyMember.phone` is unique per tenant** (normalized) — the dedup key. **The sealed buyer
  snapshot has name/tax-number but NO phone.** Auto-enroll must source phone (and name) from the
  customer's `Partner`/`Contact` (cross-module read via a Partner contract, rule 6). The plan MUST
  verify `phone` nullability in the `loyalty_members` migration; design = source phone from the
  Partner, and if absent, **skip enrollment + log** (sale unaffected).
- **Earn-rate API:** `GET /api/v1/loyalty/earn-rate` → `{"data":{"rate": string|null}}`, gated
  `module:Loyalty` + `can:loyalty.view` (built last cutoff).
- **Existing POS loyalty endpoints:** `POST /loyalty/pos/member-lookup` (by phone) on
  `LoyaltyPOSController`, gated `module:Loyalty` + `can:pos.operate_terminal`. We add a **by-customer-id
  balance** variant on the same controller/group.
- **POS module awareness:** `apps/pos/src/types/companyConfig.ts` has `all_enabled_modules: string[]`;
  `productStore.companyConfig` holds it; `hasModule(config, name)` at `productStore.ts:110`
  (defensive array/obj coercion); used in `syncService.ts:702`.
- **POS config caching:** `authStore.refreshCompanyConfig()` (`authStore.ts:418`) fetches
  `/company/config` and persists via `companyConfigCache.ts`; runs during bootstrap
  (`bootstrapStore.ts`). This is where we also fetch+cache the earn rate when Loyalty is on.
- **POS customer attach:** `paymentStore.attachCustomer(AttachedCheckoutCustomer)`
  (`paymentStore.ts:165-184`, no loyalty fields), emits a `pos.customer_attached` audit event. The
  attached customer carries `id` (+ tenant/company).
- **POS UI atoms:** `Badge` (`components/ui/Badge.tsx`, tones neutral/success/warning/danger/action) +
  `CustomerBalanceBadge` (A/R balances) on `dev`. `KpiCard` is only on `feat/pos-caisse-redesign` —
  NOT used here. Design tokens at `apps/pos/src/lib/designTokens.ts`.
- **Cart TTC total:** lives in the POS cart/payment store (the plan locates the exact tax-inclusive
  cart-total selector used by checkout) — the estimate reads it.

## Architecture

```
ONLINE path (Phase 1 chrome)                         SALE-SYNC path (accrual, already built + auto-enroll)
----------------------------                         ------------------------------------------------------
attach customer (online) ─▶ GET /loyalty/pos/balance  fiscal SALE_RECEIPT sync ─▶ PosCoreReceiptProjection
   │  hasModule('Loyalty')      {enrolled,balance,tier}    └▶ SaleEarningService.earnForSale()
   ▼                                                            ├─ resolve member (contact→partner)
loyalty chrome (Badge):                                        ├─ NEW: no member + has buyer + active
   balance + tier                                              │     program ⇒ auto-enroll (find-or-create
   + "+N pts this sale" = floor(cartTTC × rate_cached)         │     member[phone from Partner]+enrollment)
offline / module-off / walk-in ⇒ hidden                        └─ earnPoints() (idempotent)
```

### Backend components (apps/api)

**A. Auto-enroll in `SaleEarningService`.** Replace the "no member ⇒ return" with: if `contactId`/
`partnerId` present AND an active program exists AND no member resolves, **auto-enroll** then earn:
- Find-or-create `LoyaltyMember` keyed on `(tenant_id, loyaltyable_type, loyaltyable_id)`; source
  `phone`/`name` from the customer's `Partner`/`Contact` via a Partner read (rule 6 — use an existing
  Partner contract/service; the plan identifies it). No phone on file ⇒ skip + `Log::info`, no earn.
- Find-or-create `Enrollment` for `(active program, member)`, status Active. Prefer reusing
  `MemberEnrollmentService` rather than hand-rolling.
- Idempotent: find-or-create on both, so replaying the same sale never duplicates the member,
  enrollment, or earn (earn idempotency already proven).
- Seam: a future consent gate wraps this auto-enroll branch; structure it as one private method
  `resolveOrAutoEnrollMember(context)` so the gate is a one-line addition later.

**B. `GET /loyalty/pos/balance`** on `LoyaltyPOSController` (same `module:Loyalty` +
`can:pos.operate_terminal` group): query `partner_id` and/or `contact_id`; resolve the member (reuse
the resolution logic — extract a shared resolver so the service and controller agree); return
`{"data":{"enrolled": bool, "balance": string, "tier": string|null}}`. No member ⇒
`{enrolled:false, balance:"0.000", tier:null}`. Tenant-scoped; validate ids are UUIDs before query.

### POS components (apps/pos)

**C. `useHasModule('Loyalty')`** — a selector over `productStore.companyConfig` using the existing
`hasModule(config, 'Loyalty')`.

**D. Earn-rate cache** — in the bootstrap/config-refresh flow, when `hasModule('Loyalty')`, fetch
`/loyalty/earn-rate` and persist the rate (a small store field + the existing
`companyConfigCache`-style persistence). Read it for the estimate. Missing/no-rate ⇒ no estimate.

**E. Loyalty chrome** (a `Badge`-based component near the attached-customer/checkout area). On customer
attach, when `hasModule('Loyalty')`: call `GET /loyalty/pos/balance` for the attached customer id.
- **Fetch success (online):** show tier + balance (if `enrolled`), and the estimate `floor(cartTTC ×
  rate)` recomputed when the cart total changes. `enrolled:false` ⇒ hide balance, show the estimate +
  a subtle "joins on purchase" note (makes auto-enroll visible).
- **Fetch failure (offline/network) or module off or walk-in:** render nothing. Tying the chrome to a
  successful online fetch IS the "offline = no loyalty" rule — no separate connectivity flag needed.

## Data flow

login/bootstrap → company config cached → (if Loyalty) earn rate cached → cashier attaches customer
(online) → balance fetched → chrome shows balance+tier+estimate → cart changes → estimate recomputed →
sale completes → fiscal event queued → on sync, server projection → `SaleEarningService` (auto-enroll
if needed) credits idempotently → next attach/fetch reflects the new balance.

## Error handling / edges

- **Offline / network error on balance fetch:** chrome hidden (no stale numbers shown).
- **Module off / walk-in (no customer account):** no chrome; no rate fetch.
- **Customer attached, not yet enrolled (`enrolled:false`):** show estimate + "joins on purchase".
- **No active rate (no Spend rule):** no estimate (balance/tier still shown if enrolled).
- **Auto-enroll, customer has no phone:** skip enrollment + log; sale unaffected, no earn that sale.
- **Replay / double sync:** find-or-create member+enrollment + idempotent earn ⇒ exactly once.
- **Worker context (no CompanyContext, rule 20):** the Partner read + program lookup pass explicit
  tenant id; no `now()` (earn timestamp already device-time).

## Testing (TDD; PHPUnit by-path only — NEVER the full suite/`--parallel`; Vitest for POS)

Backend (PHPUnit):
1. `SaleEarningService` auto-enroll: attached customer-account, no member, active program, Partner has
   phone ⇒ member+enrollment created + points credited. Replay same sale ⇒ exactly one member, one
   enrollment, one earn (idempotent). Walk-in (no buyer id) ⇒ no member/earn. Partner without phone ⇒
   skip + log, no member, sale-path returns cleanly. No active program ⇒ no enroll.
2. `GET /loyalty/pos/balance`: enrolled member ⇒ balance+tier; not enrolled ⇒ `enrolled:false`,
   `0.000`; 403 when `module:Loyalty` disabled (isolate module gating, grant `pos.operate_terminal`);
   non-UUID id rejected; cross-tenant id not leaked.

POS (Vitest):
3. `useHasModule('Loyalty')` true/false.
4. Loyalty chrome: renders when online-fetch-succeeds + module-on + customer attached; hidden when
   fetch fails (offline), module off, or walk-in; `enrolled:false` shows estimate + "joins on
   purchase"; estimate = `floor(cartTTC × rate)` and recomputes on cart change; balance/tier from the
   fetch.
5. Earn-rate caching at bootstrap (fetched only when module on; persisted; read by the estimate).

## File-target map

| Concern | File | Action |
|---|---|---|
| Auto-enroll seam | `apps/api/app/Modules/Loyalty/Application/Services/SaleEarningService.php` (+ reuse `MemberEnrollmentService`, a Partner read contract) | edit |
| Balance endpoint | `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php` + `Loyalty/Presentation/routes.php` (+ FormRequest) | edit |
| Shared member resolver | extract from `SaleEarningService` so service + controller agree | refactor |
| POS module selector | `apps/pos/src/...` `useHasModule` (reuse `productStore.hasModule`) | new |
| Earn-rate cache | POS bootstrap/config flow (`authStore.refreshCompanyConfig` + a rate store/cache) | edit |
| Loyalty chrome | new `CustomerLoyaltyBadge`-style component (Badge + tokens) wired into the attached-customer/checkout UI | new/edit |
| i18n | POS locale files (en + fr) for loyalty labels | edit |

## Out-of-scope guard (restate)

Do NOT this phase: build redeem / pay-with-points; device-side accrual; an offline balance mirror
(SQLite loyalty columns / `pullCustomers` loyalty); QR scanning; explicit consent/opt-in UI. Offline ⇒
loyalty chrome hidden.
