# HANDOVER — Cutoff B2: Loyalty earn (per-product points on purchase)

> **Goal (owner, 2026-06-27):** a simple loyalty program where the customer **earns points on every
> purchase**, with points **assigned per product** (there is a per-product "Loyalty points" field on
> the new product editor). Treat the demo client as having paid for the Loyalty add-on.
> **In scope for cutoff:** earn + reward-catalog redemption. **Deferred:** pay-with-points tender.

## Current state (verified 2026-06-27)

**The earn ENGINE is already built and solid** — do not rebuild it:
- `EarningProcessingService::earnPoints()` (`apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php:52-181`)
  — bcmath scale-3, **idempotency** via `transactionRepository->findBySourceDocument(sourceType, sourceId)`
  (:66-68), tier multipliers, per-transaction/day caps.
- Rule engine `PointEarningService` supports rule types incl. **Spend** and **Item** (per-product).
- `earning_rules` table (`database/migrations/tenant/2026_01_10_100004_create_earning_rules_table.php`):
  `rule_type`, `reward_value` (NUMERIC 15,4), `reward_type` (fixed/multiplier/percentage),
  `conditions` JSONB, caps, `priority`, `is_active`.
- Admin UI exists: `apps/web/src/features/loyalty/` (programs, members, earning rules, rewards, tiers).
- Reward-catalog redemption already works: `RedemptionProcessingService` + `/loyalty/pos/redeem`.

**Two gaps to close:**

1. **The live POS path never triggers earning.** `EarnPointsOnReceiptCompleted`
   (`.../Listeners/EarnPointsOnReceiptCompleted.php`) listens to `ReceiptCompleted`, which is only
   fired from the **retired** `ReceiptPaymentService`. The live path is the fiscal-event projection
   `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (handles
   `POST /api/v1/pos/sync/fiscal-events`) — it creates `pos_receipts` + decrements stock (ends
   ~:318-321) but dispatches NO loyalty event.

2. **The per-product points field is an unwired placeholder.** Per
   `HANDOFF-loyalty-product-fields.md` + `docs/superpowers/plans/2026-06-25-product-editor-field-model-and-identity.md` §7:
   the editor shows "Loyalty points" (per-product value) + "Eligible for discounts" as
   **module-gated visual placeholders, not persisted**. Owner decision: these are **owned by the
   Loyalty add-on**, not the Product master — persist in a module-owned table, surface as a
   per-product override read/written via the Loyalty module's public contract (no cross-module model
   import — rule 6).

## Design decisions to lock first

1. **Persistence** — recommend a `loyalty_product_overrides` table (Loyalty module, FK `product_id`,
   `points_value` NUMERIC(15,4) nullable, tenant-scoped). Keeps Product clean + gating honest.
2. **Points model** — owner wants **fixed points per product** (the per-product field). Map to an
   **Item-type earning rule** that reads the per-product override at sale time, summed across lines.
   Provide a **fallback default Spend rule (1 TND = 1 point)** for products with no override, seeded
   on program activation, so "every purchase earns" holds even before per-product values are set.
3. **Member resolution** — `pos_receipts` carry `contact_id`/`partner_id`; the listener resolves the
   member via polymorphic `loyaltyable_type`+`loyaltyable_id`. Confirm the POS sale attaches a
   customer (buyer snapshot in `fiscal_events.payload.buyer`); no customer ⇒ no earning (expected).

## Build steps (TDD)

1. **Persist the product override** — `loyalty_product_overrides` migration + model + a Loyalty
   public service method `getPointsForProduct(productId)` / `setPointsForProduct(...)`; wire the
   product editor field to read/write it through the contract (FE gated on `hasModule('Loyalty')`,
   BE `module:Loyalty`).
2. **Trigger earning from the live projection** — after the receipt is persisted in
   `PosCoreReceiptProjection`, call the earning path (prefer firing a domain event the existing
   listener consumes, OR call `EarningProcessingService::earnPoints()` directly). Pass
   `sourceType='pos_receipt'`, `sourceId=receipt.id`, items + amounts + currency.
   - **CRITICAL idempotency:** projections can replay. Rely on `findBySourceDocument('pos_receipt',
     receipt.id)` + the `pos_receipts.fiscal_event_id` UNIQUE anchor. Add a replay test: same fiscal
     event twice → balance unchanged.
   - **No CompanyContext in projections** — pass explicit currency/company (precision rule 19/20).
   - Wrap in try/catch + log (mirror the voucher-redemption block); a loyalty failure must not break
     the sale projection.
3. **Seed default 1:1 Spend rule** on Loyalty program activation (factory/seeder).
4. **Verify end-to-end** in the E2E campaign: ring a POS sale for a member → points credited once,
   correct per-product totals; redeem a catalog reward.

## File-target map

| Concern | File | Note |
|---|---|---|
| Earning engine | `apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php` | ready — no change |
| Trigger earning | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (~after :318) | add earn call, idempotent |
| Product override persistence | NEW `loyalty_product_overrides` table + Loyalty model/service | module-owned |
| Product editor wiring | `apps/web/src/features/products/...` + Loyalty contract | gated on `hasModule('Loyalty')` |
| Default rule seed | `database/factories/Loyalty/` or a seeder | 1 TND = 1 point fallback |

## Scope guard
- **IN:** earn (per-product + fallback) + reward-catalog redemption.
- **OUT (defer to integration branch):** pay-with-points as a tender — needs a `LoyaltyPoints` case
  in `PaymentInstrumentKind`, POS tender UI, fiscal-event payload support, and a GL/fiscal decision
  (discount vs tender vs credit). ~4–6d. Do NOT start it for the demo.

**Estimated effort:** M (~3–5d) — most cost is the product-override persistence + editor wiring; the
earn trigger itself is small.
