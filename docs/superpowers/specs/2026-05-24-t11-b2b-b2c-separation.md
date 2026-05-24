# T11 — B2B / B2C Clean Separation (DESIGN ONLY)

**Track:** T11 (P0 sprint — **design-only spec**; implementation deferred to next cycle)
**Date:** 2026-05-24 (v2 after Codex round-1 review)
**Recommended workflow:** Opus produces the design. Implementation tracks (T11-impl-A, B, C) derived for next cycle.
**Estimated effort (design):** ~3 PD
**Roadmap reference:** [2026-05-24-productization-sprint-roadmap.md](../coordination/2026-05-24-productization-sprint-roadmap.md)

---

## 1. Purpose

The web ERP supports a full B2B flow (Quote → SalesOrder → Invoice with credit terms, business_registration_number, payment_terms_days, credit_limit, invoice_consolidation — production via `B2BFieldsSection.tsx` + `Partner.customer_category=Business` + `PartnerPriceList`). The Tauri 2 desktop POS supports pure B2C (Receipt only; partner_id on `pos_receipts` exists but never consumed for B2B logic).

**Today, no leak between flows. Clean by architectural accident.** Near-term needs that bring cohabitation:

- Tenant who runs both (rare per business owner: "edgy case from business perspective" — wholesale + B2C sales directly is uncommon since the wholesaler would compete with their own resellers on price)
- B2C-mode tenant whose B2B *purchasing* flow needs consolidated reporting
- Future Wholesale Sub-Vertical (T9) needs B2B side production-grade
- Future Paradeals Aggregator (T7) is B2B by nature

This design keeps Tauri POS thin and B2C-focused (minimum dev, never extends credit, never creates B2B invoices with terms), web ERP carries B2B, future mobile app extends to either.

---

## 2. Architecture grounding (verified file paths + state)

**Already production-grade (no rework needed):**

- `apps/erp/apps/api/app/Modules/Partner/Domain/Partner.php` (lines 1–378) — Partner with `customer_category` enum (Individual | Business) + `isB2B()` method (lines 188–191); `PartnerType` enum (Customer | Supplier | Both)
- B2B field set on Partner: `company_legal_name`, `business_registration_number`, `payment_terms`, `payment_terms_days`, `credit_limit`, `discount_percentage`, `invoice_consolidation`, `consolidation_frequency` (Partner.php:34-41; `B2BFieldsSection.tsx:6-15`)
- `apps/erp/apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:41-77` (verified) — current pricing resolution: (1) partner-specific price list, (2) default price list for currency, (3) product base price. **No "Business customer group" path exists** (per Codex P1-4 correction; v1 spec was wrong)
- `apps/erp/apps/api/app/Modules/Pricing/Domain/PartnerPriceList.php` (lines 12–65) — per-partner price list with validity dates
- `apps/erp/apps/api/app/Modules/Document/Domain/Document.php` — unified Document with `type` enum (Quote, SalesOrder, Invoice, CreditNote, DeliveryNote, ReturnNote, Expense, PurchaseOrder)
- `apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` — POS creates Receipt only; nullable `partner_id` for analytics
- `apps/erp/apps/api/app/Modules/POS/Domain/Receipt.php` — separate entity from Document
- `apps/erp/apps/pos/src/stores/holdStore.ts` — production park-sale pattern (`holdCurrentCart`, `recallTransaction`, `discardTransaction`) — mirror for "Save as draft order" hand-off

**Payment methods supported at POS** (broader than v1 claimed):

- Tunisia: CASH, CHECK, TRAITE, CARD, WALLET, LOYALTY
- France: CASH, CHECK, TRANSFER, CARD, DIRECT_DEBIT, LCR, PAYPAL, MEAL_VOUCHER, BILL_EXCHANGE
- All support voucher / gift-card discriminators (`instrument_type` / `instrument_serial`)
- **None today represent "B2B credit terms" — design preserves this**

**What's missing for cohabitation:**

- `PricingStrategyResolver` service shared between POS and web (today, POS doesn't apply PartnerPriceList; web does via PricingService::getPrice)
- Document policy enforcer (today, separation is architectural; design makes it explicit policy)
- `customer_contacts` join table extension (existing `party_contacts` per Contact.php:13-27 is the seed; extend with explicit role enum)
- Thin-POS "B2B hand-off" UX (today, walk-in B2B → cashier improvises)
- Consolidated reporting with `channel` × `customer_type` dimensions

---

## 3. Industry pattern grounding

Research validated (Lightspeed, Shopify B2B Edition, Dynamics 365 Commerce, Odoo, ERPNext, Salesforce B2B Commerce, Cegid Retail/Wholesale):

> **Unified Customer aggregate + Type discriminator + Document-policy-per-type + Pricing-strategy resolver + Draft-order hand-off.**

Thin-POS variant: cashier resolves account, applies B2B price list for line items, takes immediate payment (cash/card/voucher/loyalty/etc.) OR saves as draft order pushed to web ERP. **Never commits B2B documents with credit terms at the counter.**

---

## 4. Target architecture

### 4.1 Unified Customer aggregate

Status: **mostly exists today.** Augmentation:

- Keep `Partner` as aggregate root with `customer_category` discriminator
- ADD `customer_contacts` join table — `(partner_id, contact_id, role_enum)` extending existing `PartyContact` pattern with explicit role validation
- Covers: Business partner has multiple Person contacts AND a Person partner who is ALSO a contact at a Business

### 4.2 PricingStrategyResolver (new service, shared)

**Resolution order — first-match algorithm:**

```php
PricingStrategyResolver
  ::resolve(ResolveContext): ResolvedPrice
  // ResolveContext = product_id + variant_id + customer (Partner) + location_id + channel_id (nullable) + quantity
  // ResolvedPrice = unit_price + applied_strategy_id + reasoning
```

1. **Channel-specific override** (if `channel_id` set AND `ChannelProductMapping.price_override` exists; T3 dep, fires only for online channel orders) → use it
2. **Explicit partner-specific price list** matching `(product_id, variant_id)` AND active dates → use it
3. **Default price list for currency** (existing `PricingService::getDefaultPriceListPrice`) → use it
4. **Product / variant base price** (`sale_price`) → fallback

**Ordering rationale (resolves round-2 P1-A finding):** First-match short-circuits, so channel override MUST come first or it would never fire (every active tenant has a default price list). Within typical commerce-system semantics, an explicit channel-level price override is the most specific signal: "this product on this channel sells for X regardless of customer." Partner price lists are next-most-specific (this exact partner). Default price list is generic-by-currency. Base price is fallback. This ordering preserves backward-compatibility with existing `PricingService::getPrice` for the in-store flow (where `channel_id` is null, the resolver naturally falls through step 1 and matches the existing service's order: partner → default → base).

**Removed from v1:** the invented "Business customer group" path (Codex round-1 P1-4 confirmed no such model exists in current code; do not introduce without explicit migration spec).

**Same service called from POS and web.** No divergence. POS calls with `channel_id = null` (step 1 skipped); web sales-order flow for online orders calls with `channel_id` set (step 1 has a chance to fire).

### 4.3 Document policy enforcer

```php
interface DocumentEmissionPolicy
{
    public function canEmit(DocumentType $type, EmissionContext $context): PolicyResult;
}

// EmissionContext = surface (POS | Web | API) + user + partner + payment_methods_used
```

**POS surface allowed Document types** (precise — payment method based, not credit-terms based):
- `Receipt` (always)
- `Invoice` ONLY when payment is **immediate via tendered-at-counter methods** (any of: cash, card, voucher, loyalty, wallet, mobile money, instrument-discriminated payment) AND `payment_terms_days = 0` (no credit terms)

**POS surface DISALLOWED:**
- `Invoice` with `payment_terms_days > 0` (B2B credit-terms invoice)
- `Quote`, `SalesOrder` (B2B-style commit flows)
- Any `Invoice` linked to a Business-category partner where the payment intention is deferred/credit

**Web surface allowed:** all Document types.

**The line is "tendered at the counter" (immediate) vs "settled later" (credit terms).** Tunisia POS supports CASH, CHECK, TRAITE, CARD, WALLET, LOYALTY — all are tendered-at-counter when accepted as POS payment, EXCEPT TRAITE which is a deferred-payment instrument. POS spec already discriminates via `instrument_type` / `instrument_serial`; the policy enforcer uses this discrimination to gate.

### 4.4 Thin-POS B2B hand-off UX

When cashier resolves B2B-category customer at counter:

1. **Customer search UI** returns matching partners; B2B accounts get visible badge (e.g., "B2B" pill)
2. **On selection of B2B partner**, cart automatically applies PartnerPriceList (via PricingStrategyResolver)
3. **Checkout proceeds normally** IF customer pays immediately via allowed method → Receipt tied to `partner_id` (analytics queryable, no B2B invoice generated)
4. **NEW button: "Save as Draft Order → Web"** — uses existing `holdStore` pattern (mirror/extend); creates `Document` of type `SalesOrder` in `draft` status, owned by office team's queue
5. **No credit logic, no terms picker, no quote PDF in POS binary**

Web ERP queue picks up draft order, office rep converts to invoice with terms, pushes back to printer/email.

### 4.5 Consolidated reporting

T5 Owner Reporting dashboards extend with:
- `channel` drill-down dimension: `pos` | `web` | `online_<channel_id>` (online via future T3 adapters)
- `customer_type` drill-down dimension: `individual` | `business`
- B2B-only KPIs (AR aging, quote-to-order rate, reorder rate) live in web ERP only; read same `documents` + `payments` source of truth

---

## 5. Implementation breakdown (DEFERRED to next cycle)

This design produces 3 implementation tracks for the NEXT sprint cycle:

| Implementation track | Scope | Est. effort |
|---|---|---|
| **T11-impl-A** — Customer model augmentation | `customer_contacts` join table with role enum + service + tests | ~3 PD |
| **T11-impl-B** — PricingStrategyResolver + DocumentEmissionPolicy | New services + integration into POS receipt flow + web sales-order flow + comprehensive tests | ~5 PD |
| **T11-impl-C** — Thin-POS B2B hand-off UX | Extend `holdStore` for draft orders + B2B badge in POS search + web queue UI | ~4 PD (most logged to `2026-05-24-pos-coordination-log.md` for fiscal coordination) |

Total next-cycle effort: ~12 PD.

---

## 6. Generic-ness checklist

- [ ] Zero client names anywhere
- [ ] Customer type discriminator works across all verticals
- [ ] PricingStrategyResolver generic (partner / default / channel / base — extensible)
- [ ] DocumentEmissionPolicy generic — surface allowlists configurable per tenant if needed
- [ ] Thin-POS UX works for any tenant where B2C-walk-in turns out to be B2B account
- [ ] Reporting drill-down works for any mix of B2B and B2C activity
- [ ] No cross-DB FK violations (intra-tenant only)
- [ ] All future migrations in `database/migrations/tenant/`

---

## 7. Acceptance criteria (for eventual implementation)

- [ ] Business partner with `payment_terms_days = 30` exists; cashier picks at POS; PartnerPriceList applies; payment via cash succeeds; Receipt created with `partner_id`; no B2B Invoice created
- [ ] Same as above but cashier hits "Save as Draft Order → Web"; SalesOrder draft created; web queue shows it; office rep converts to Invoice with 30-day terms
- [ ] Tauri POS cannot, through any UI path, create Document of type `Invoice` with `payment_terms_days > 0`
- [ ] Web ERP creates B2B Invoice with credit terms — works as today
- [ ] Owner dashboard widget shows revenue split by `customer_type` + `channel`
- [ ] Backward compat: every existing B2C POS test and B2B web test continues to pass
- [ ] PricingStrategyResolver returns identical price for partner X, product Y on both POS and web (verifying same service is shared)

---

## 8. Adversarial review checklist (for the design itself)

**Reviewer instruction:** *"This is a design spec, not implementation. Verify: (1) Section 2 code citations accurate — read the cited files; (2) resolution order in 4.2 matches existing `PricingService::getPrice` exactly (per Codex P1-4) — verify by reading PricingService.php lines 32-78; (3) DocumentEmissionPolicy correctly discriminates by payment method instrument type AND payment_terms_days, not just by customer_category; (4) thin-POS hand-off preserves backward compat with existing holdStore behavior."*

- [ ] Section 2 code citations verified against actual files
- [ ] Resolution order in 4.2 mirrors existing `PricingService::getPrice` order: partner → default-currency → product base (channel override is NEW addition for future T3 integration); the "Business customer group" path is NOT introduced
- [ ] DocumentEmissionPolicy edge case: returns at POS for B2B customer (CreditNote) clarified
- [ ] DocumentEmissionPolicy edge case: walk-in pays partly in voucher + partly in cash for high-value B2B item — clarified (still immediate, still Receipt-allowed)
- [ ] Hand-off UX preserves existing held-transaction semantics ("Hold" feature continues to work; "Save as Draft Order" is new sibling, not replacement)
- [ ] T3 + T5 dependencies surfaced explicitly (channel_id in resolver depends on T3; consolidated reporting dimensions depend on T5)
- [ ] No cross-DB FK violations — all proposed schema is intra-tenant per topology contract

---

## 9. Out of scope

- Actual implementation — deferred (T11-impl-A, B, C)
- B2B-only POS mode (wholesaler with counter-style POS embedding credit-terms UI) — out of scope; separate "wholesale POS variant" if it becomes a real need
- Loyalty programs across B2B + B2C — defer
- B2B portal for self-service quote/order/invoice — defer (future track)
- EDI integration (850/856/810/997/846) — wholesale-vertical concern

---

## 10. Reading order

1. This spec
2. Migration topology contract
3. `apps/erp/CLAUDE.md`
4. File paths in Section 2 (especially PricingService.php and B2BFieldsSection.tsx)
5. `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md` for Tauri-change coordination

---

## 11. Workflow recommendation (for the design)

**Single phase (Opus, ~3 PD):** Refine this design based on adversarial review. Resolve open questions. Produce 3 implementation specs (T11-impl-A, B, C) as separate files for next-cycle scheduling.

For eventual implementation:
- T11-impl-A: Codex (mechanical service + migration + test work)
- T11-impl-B: Opus for policy + resolver architecture, then Codex for integration
- T11-impl-C: Tauri changes via deltas log → fiscal session; web queue UI via Codex

---

## 12. Coordination notes

- **No build in this sprint.** Design produces 3 impl specs for next cycle.
- **In-flight POS fiscal Phase 1** does not conflict — fiscal Phase 1 and B2B/B2C cohabitation are orthogonal
- **Tauri POS changes** (when impl-C ships) logged to `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md` for fiscal session
- **No tenant data migration required** — Partner already has `customer_category`; existing rows are "Individual" by default
- **Backward compat is non-negotiable** — every passing test today must continue to pass after impl ships
