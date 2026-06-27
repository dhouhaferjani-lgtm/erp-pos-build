# Design — Loyalty earn on purchase (Spend rule + derived per-product display) + redemption

> **Cutoff B2 for the parapharmacy demo.** Goal (owner, 2026-06-27): a simple loyalty program where
> the customer **earns points on every purchase**. **Simplified for the demo (owner, 2026-06-27):**
> use the existing **points-per-money-unit Spend rule** (e.g. 1 TND = 1 point). The product editor's
> "Loyalty points" field is a **read-only derived display** (`sale_price × rate`), not an editable
> per-product value. Treat the demo client as having paid for the Loyalty add-on.
>
> **Source handover:** `docs/handoff/HANDOVER-cutoff-loyalty-earn.md` +
> `docs/handoff/HANDOFF-loyalty-product-fields.md`. This spec supersedes the handover's per-product
> override design with the owner's simpler Spend-rule choice (see Locked decisions).

## Scope

**IN**
- **Earn points on a live POS sale** by wiring the fiscal-event projection to the existing earning
  engine, using the existing **Spend** rule (points per money unit). This trigger does not exist
  today and is the core of the cutoff.
- **Seed a default Spend rule** (1 TND = 1 point) on Loyalty program activation, so every purchase
  earns out of the box; the rate stays admin-editable via the existing earning-rules UI.
- **Product editor "Loyalty points" field** = read-only derived display (`sale_price × rate`), gated
  on the Loyalty module.
- **Reward-catalog redemption: verify-only** — exercise the existing `/loyalty/pos/redeem` path in
  E2E; add a test only if a real gap surfaces. Do not rebuild.

**OUT (do not build this cutoff)**
- **Per-product editable point values** + the `loyalty_product_overrides` table / model / repo /
  endpoints / a new `ItemOverride` rule type — dropped per the owner's simpler choice.
- **Pay-with-points tender** — deferred to the integration branch.
- **"Eligible for discounts" field** — stays an unwired, module-gated placeholder.
- **Refund/return point reversal** — a refund simply earns nothing this cutoff.
- **Durable earn-retry queue** — best-effort + loud log this cutoff (documented upgrade path).
- Any change to the retired `ReceiptCompleted` / `EarnPointsOnReceiptCompleted` path.

## Locked decisions (owner, 2026-06-27)

1. **Earn model = points-per-money-unit (Spend rule).** Points credited on a sale = `earnBase × rate`,
   where `rate` is the active Spend rule's `reward_value` and `earnBase` is the receipt **total** (the
   amount the customer pays). Default rate seeded at `1` (1 TND = 1 point).
2. **No per-product persistence.** The product master and the Loyalty module gain **no** new table.
   The editor "Loyalty points" field is a **read-only derived display** computed FE-side from the
   product's sale price and the active program's rate.
3. **Member resolution:** resolve by `pos_receipts.contact_id` → `partner_id` (polymorphic
   `loyaltyable_type`/`loyaltyable_id`), looping active enrollments. **No customer ⇒ no earning.**
4. **Redemption: verify-only.**

## Why the live path needs building (verified 2026-06-27)

- **The earning calculation already works.** `PointEarningService::calculateSpendPoints()` =
  `amount × reward_value` (bcmath, scale-3). `EarningProcessingService::earnPoints()` is idempotent
  (`findBySourceDocument`), updates balances, and resolves scale with `getScaleSafe($currency, 3)`
  (no CompanyContext needed). It **throws** `InvalidArgumentException` on a duplicate Earn.
- **The live POS path fires no earning.** `PosCoreReceiptProjection` (handles
  `POST /api/v1/pos/sync/fiscal-events`) creates `pos_receipts` + decrements stock but dispatches
  **zero** loyalty events (grep-confirmed). The only earning listener, `EarnPointsOnReceiptCompleted`,
  is bound to the **retired** `ReceiptCompleted` event. So no real POS sale earns today — regardless
  of rule type. **Wiring this trigger is the irreducible work.**
- **Admin + read surfaces exist.** Earning rules are CRUD-managed under
  `loyalty/programs/{programId}/earning-rules` with a full FE admin
  (`apps/web/src/features/loyalty/EarningRulesTab` + `EarningRuleFormModal`); `GET
  /loyalty/programs/active` returns the active program (the editor reads the rate from here).

## Codebase facts grounding the build

- **Projection trigger site:** `PosCoreReceiptProjection::apply()` persists the receipt then runs
  synchronous side-effect blocks (`writeLines`, `writeVatBreakdown`, `writePayments`,
  `redeemVouchers` — the try/catch block to mirror, `decrementStockForLines`) inside a
  `DB::transaction`. First statement is the idempotency guard
  `if (Receipt::where('fiscal_event_id', $event->id)->exists()) return;`. Buyer comes only from the
  sealed snapshot `$view->buyer` (`customerId`/`contactId`/`name`/`taxNumber`); currency from
  `$view->payload->currencyCode`; net subtotal / tax / TTC total are computed and written as
  `subtotal` / `tax_amount` / `total`; `posted_at` = `$event->event_time_device`;
  `$receiptTypeEnum = resolveReceiptType($payload->invoiceTypeCode, $originalReceiptId)`
  (SALE/TRAINING → `Sale`; REFUND/VOID → `Return`); `$payload->trainingFlag` is the training flag.
- **Cross-module pattern:** `app/Shared/Contracts/` (`Fiscal/`, `Treasury/`, `Compliance/`) holds the
  interfaces, bound in the owning module's provider, constructor-injected into consumers (e.g.
  `Shared/Contracts/Fiscal/PaymentMethodResolver` bound in `TreasuryServiceProvider`, injected into
  `PosCoreReceiptProjection`). **POS has zero Loyalty references today** — the cross-module surface
  must be a new `Shared/Contracts/Loyalty` interface (rule 6).
- **Program activation:** `ProgramManagementService::activateProgram()` dispatches `ProgramActivated`
  (`programId`, `tenantId`, `programName`, `activatedAt`) via `DB::afterCommit`. **No listener yet**;
  listeners register in `app/Providers/EventServiceProvider.php` `$listen`.
- **Earning rule shape:** `earning_rules(program_id, rule_type, reward_value DECIMAL(15,4),
  reward_type, conditions JSONB, priority, is_active, max_earn_per_transaction, max_earn_per_day)`.
  `EarningRuleType::Spend = 'spend'`. `findActiveByProgram()` returns active rules ordered by
  `priority`. Empty `conditions` ⇒ `ruleApplies` matches every transaction (verified).
  **`EarningRuleFactory` does not exist** — create it.
- **Product editor:** `apps/web/src/features/inventory/ProductForm.tsx` has **no** loyalty field yet;
  module-gated sections follow the parapharmacy pattern (`{isParapharmacy && (<div id="section-…">…)}`).
  Module gating via `useCompanyConfig().hasModule('Loyalty')`. `ProductFormData` already carries
  `sale_price` (string). `buildProductPayload` (`productPayload.ts`) assembles the product PATCH/POST.
- **Existing (retired) listener to relocate:** `EarnPointsOnReceiptCompleted` resolves the member by
  `contact_id` then `partner`/legacy `customer_id`, loops active enrollments, calls `earnPoints(...,
  'pos_receipt', receiptId, ...)`. The new path reuses this resolution but reads the buyer from the
  sealed snapshot and passes strings (the old listener casts money to `(float)` — rule-19 violation
  not carried forward). The retired path is left untouched (out of scope).

## Architecture

### Components and boundaries

```
POS module                          Shared/Contracts/Loyalty          Loyalty module
-----------                         ------------------------          --------------
PosCoreReceiptProjection ── calls ─▶ LoyaltyEarningContract ─ bound ─▶ SaleEarningService (Application)
  (after receipt persisted,            ::earnForSale(SaleEarnContext)    ├─ module-enabled guard
   try/catch like redeemVouchers,                                        ├─ resolve member (contact→partner)
   only when receipt is an earning                                      ├─ loop active enrollments
   SALE — see guard)                                                    └─ EarningProcessingService::earnPoints()
                                                                              └─ PointEarningService (existing Spend arm)
```

**Why this split:** POS depends only on a `Shared/Contracts/Loyalty` interface (rule 6) and passes a
DTO of primitives/strings — it imports no Loyalty model. The earning math is the *unchanged* engine;
we add only the trigger + the cross-module surface + member resolution.

### Public contract (cross-module surface)

`app/Shared/Contracts/Loyalty/LoyaltyEarningContract.php`:

```php
interface LoyaltyEarningContract
{
    public function earnForSale(SaleEarnContext $context): void;
}
```

`SaleEarnContext` (immutable DTO of primitives — `app/Shared/Contracts/Loyalty/SaleEarnContext.php`):
`tenantId`, `?contactId`, `?partnerId`, `currency` (string), `sourceType` (`'pos_receipt'`),
`sourceId` (the `pos_receipts.id`), `?receiptNumber`, `postedAt` (sealed device time, used as the
earn timestamp), `earnBase` (numeric-string — the receipt `total`). **Money is a string (rule 19); a
DTO of primitives only, so POS imports no Loyalty model (rule 6).** No line items / overrides — the
Spend rule needs only the aggregate base.

Bound in `LoyaltyServiceProvider::register()` →
`App\Modules\Loyalty\Application\Services\SaleEarningService`.

`SaleEarningService::earnForSale()` (relocates + replaces the retired listener's logic):
0. **Module guard (Codex SF-2):** the projection-side contract call is *not* behind the
   `module:Loyalty` HTTP middleware, so the service gates itself. Worker-safe form (no CompanyContext
   / central Tenant resolution needed): return early unless the tenant has an **active loyalty
   program** (`LoyaltyProgramRepositoryInterface::findByTenantAndStatus($tenantId,
   ProgramStatus::Active)` non-empty). This is a sound proxy for "Loyalty enabled" because program
   creation/activation is itself gated behind `module:Loyalty`; a disabled tenant has no active
   program (and no enrolled members), so nothing earns.
1. If `contactId` and `partnerId` both null ⇒ return (no member).
2. Resolve `LoyaltyMember` by `loyaltyable_type='contact' & loyaltyable_id=contactId` (tenant-scoped),
   else by `partner` / legacy `customer_id = partnerId`. None ⇒ return.
3. Build `transactionData = { amount: earnBase, currency, items: [], timestamp: postedAt }` — use the
   sealed **device time**, not `now()` (Codex SF-3), so time-based rules evaluate at sale time.
4. For each **active** enrollment, call `earningProcessingService->earnPoints(enrollmentId,
   transactionData, 'pos_receipt', sourceId, "POS receipt #{receiptNumber}")` with
   **discriminated failure handling (Codex BLOCKER-3):**
   - Catch the **duplicate-source** `InvalidArgumentException` (already-earned) and swallow it
     silently — the idempotent replay/double-fire case, balance unchanged.
   - Any **other** `\Throwable`: `Log::error()` at high visibility with `tenant_id`, `enrollment_id`,
     `source_id`, `receipt_number` so the credit is **recoverable by hand**, then continue (never
     break the sale). Discriminate by exception type/message, not a blanket `catch (\Throwable)`.
   - **Durability note (scoped):** the projection's `fiscal_event_id` guard makes replay skip the
     whole receipt, so a swallowed *non-duplicate* failure is not auto-retried. Best-effort + loud log
     this cutoff (fine for a controlled demo). Durable fix = an idempotent queued
     `EarnLoyaltyForReceipt` job (Horizon queue registered per rule 20) — deferred.

> **Known limitation (documented, not fixed):** `findBySourceDocument` keys on `(sourceType,
> sourceId)` globally, so a member in *multiple* programs earns on only the first enrollment. The demo
> runs a single program — out of scope, flagged for the integration branch.

### Projection wiring

In `PosCoreReceiptProjection`:
- Constructor-inject `private readonly LoyaltyEarningContract $loyaltyEarning`.
- After the receipt is persisted (after `redeemVouchers(...)`), add
  `earnLoyaltyPoints($receiptId, $event, $view, $receiptTypeEnum, $payload, $totalNorm)`:
  - **Earn-eligibility guard (Codex BLOCKER-2):** earn **only** when
    `$receiptTypeEnum === ReceiptType::Sale` **AND** `$payload->trainingFlag === false`. The
    projection fires on every `SALE_RECEIPT` event — including REFUND/VOID (mapped to `Return`) and
    training receipts. Without this a refund would credit *positive* points. Refund point-reversal is
    **deferred** — a refund earns nothing.
  - Build `SaleEarnContext` from `$view->buyer`, `currency = $payload->currencyCode`,
    `postedAt = $event->event_time_device`, `sourceId = $receiptId`,
    `earnBase = $totalNorm` (the normalized receipt total already computed in `apply()`).
  - Wrap in `try/catch + Log::error` so a loyalty failure never breaks the sale projection (mirror
    `redeemVouchers`).
- **Idempotency / replay:** the `fiscal_event_id` guard prevents re-running for the same event;
  `findBySourceDocument('pos_receipt', receiptId)` is the second line of defense. The read-before-write
  check is projection-scoped, not a DB uniqueness guarantee (Codex SF-1); same-receipt projection is
  serial on the per-device fiscal-event path, so the soft check suffices this cutoff (documented).

> **Earn base = receipt `total` (TTC, what the customer pays).** "Points per money unit" reads most
> naturally as points per dinar paid, and it matches the editor's sale-price-based display. This is a
> clean basket aggregate — not the per-line `unit_price` net-vs-gross trap (that caveat is about
> per-line assertions, not the receipt total).

### Default-rule seed on activation

- New listener `SeedDefaultEarningRuleOnProgramActivated` (Loyalty Application/Listeners), registered
  in `EventServiceProvider` under `ProgramActivated::class`.
- On activation, if the program has **no active earn rule**, persist one Spend rule:
  `rule_type=Spend`, `reward_value='1'` (1 pt / TND), `reward_type='multiplier'`, `priority=1`,
  `is_active=true`, `conditions={}` (matches every transaction).
- **Idempotent / non-clobbering:** if the program already has any active earn rule, do nothing (the
  admin may have configured their own rate) — re-activation safe, never duplicates.
- **Single-rate note (Codex BLOCKER-1):** `earnPoints()` sums *all* active rules. The seed guarantees
  exactly one rule on a fresh program; if an admin later adds more active rules they stack (expected
  engine behavior, the admin's choice). The demo runs the single seeded Spend rule.
- Add `EarningRuleFactory` (does not exist) for tests/seeding.

### Frontend — gated derived display field

- New gated section in `ProductForm.tsx`, rendered only when `hasModule('Loyalty')`, mirroring the
  parapharmacy block. A **read-only** "Loyalty points" display:
  `points = round(sale_price × rate)`, where `rate` is read from `GET /loyalty/programs/active`
  (the active program's active Spend rule `reward_value`; fall back to showing nothing if no active
  program/rule). All labels via `t()` (`catalog:editor.sectionLabels.loyalty` + field keys).
- Computed with the app's money/number helpers (no `parseFloat` on the price string — use the
  existing formatter; the multiplication for display uses a safe decimal helper). Display is
  **indicative** ("≈ N points"): the actual credited amount depends on the final paid total incl.
  discounts. A short helper caption states this.
- **No write path, no product-payload change, no new endpoint** — `buildProductPayload` is untouched;
  the field never persists anything.

## Error handling

- **Non-earning receipts** (REFUND/VOID → `Return`, or `trainingFlag=true`): skipped at the projection
  guard — no points, no error.
- **Loyalty module disabled** for the tenant: `SaleEarningService` returns early.
- **No member / no customer:** silent no-op.
- **Duplicate Earn** (replay / double-fire): `earnPoints` throws the already-earned
  `InvalidArgumentException` → caught specifically → balance unchanged.
- **Transient earn failure** (non-duplicate `\Throwable`): caught, logged loudly with recovery
  context, sale projection still succeeds; auto-retry deferred.
- **Editor rate fetch fails / no active program:** the field renders nothing (or a muted dash) — never
  blocks the editor.

## Testing (TDD, scoped `--filter` only — never the full suite, never `--parallel`)

Backend (PHPUnit, `--filter` by class):
1. `SaleEarningService`: member resolution (contact, partner, none → no-op); module-disabled tenant →
   no-op; `earnBase`/currency passed as strings; `postedAt` (device time) used as the timestamp;
   duplicate `InvalidArgumentException` swallowed while a non-duplicate `\Throwable` is logged and
   surfaced distinctly (not mistaken for a duplicate).
2. **Replay test (rule 20):** apply the same fiscal event / `SaleEarnContext` twice → exactly one Earn
   transaction, enrollment balance unchanged. Clear CompanyContext before apply (worker reality).
3. Projection: a SALE with an enrolled buyer credits `total × rate` once; a sale with no buyer credits
   nothing; **a REFUND/VOID and a training-mode receipt credit nothing** (earn-eligibility guard); a
   loyalty failure does not abort the projection.
4. `SeedDefaultEarningRuleOnProgramActivated`: activation on a fresh program seeds exactly one active
   Spend rule (`reward_value=1`); activation when an active earn rule already exists adds nothing;
   re-activation does not duplicate.

Frontend (Vitest):
5. Editor shows the loyalty section only when `hasModule('Loyalty')`; hidden otherwise.
6. The field renders `≈ sale_price × rate` from the active-program rate, updates when sale price
   changes, and renders nothing when there's no active program/rate. It never submits anything in the
   product payload.

E2E (parapharmacy campaign, manual):
7. Ring a POS sale for an enrolled member → points credited once = `total × rate`.
8. Redeem a catalog reward via `/loyalty/pos/redeem` (verify-only).

## File-target map

| Concern                         | File                                                                                  | Action |
|---------------------------------|---------------------------------------------------------------------------------------|--------|
| Public contract + DTO           | `app/Shared/Contracts/Loyalty/{LoyaltyEarningContract,SaleEarnContext}.php`            | new    |
| Sale earning service            | `Loyalty/Application/Services/SaleEarningService.php`                                  | new    |
| Activation seed listener        | `Loyalty/Application/Listeners/SeedDefaultEarningRuleOnProgramActivated.php` + EventSP  | new/edit |
| Earning-rule factory            | `database/factories/Loyalty/EarningRuleFactory.php`                                    | new    |
| Projection trigger              | `POS/Application/Projections/PosCoreReceiptProjection.php`                             | edit   |
| Provider binding                | `Loyalty/Providers/LoyaltyServiceProvider.php`                                         | edit   |
| Product editor derived field    | `apps/web/src/features/inventory/ProductForm.tsx` + a small loyalty display component + i18n; FE hook to read `GET /loyalty/programs/active` rate | new/edit |

## Out-of-scope guard (restate)

Do **not**, in this cutoff: build per-product editable point values or any `loyalty_product_overrides`
persistence; build pay-with-points tender; wire "Eligible for discounts"; touch the retired
`ReceiptCompleted`/`EarnPointsOnReceiptCompleted` path; fix the multi-enrollment idempotency
limitation; reverse points on refunds/returns; build a durable earn-retry queue; rebuild redemption.
