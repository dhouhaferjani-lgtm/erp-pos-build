# T11-impl-B — PricingStrategyResolver + DocumentEmissionPolicy

**Track:** T11-impl-B (next-cycle implementation track derived from the T11 design spec)
**Date:** 2026-05-24
**Recommended workflow:** Opus for the resolver + policy architecture; Codex for the POS / web integration + tests. Adversarial review: Codex headless.
**Estimated effort:** ~5 PD
**Parent design:** [2026-05-24-t11-b2b-b2c-separation.md](2026-05-24-t11-b2b-b2c-separation.md) §4.2, §4.3
**Constitutional reference:** [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md)
**Roadmap reference:** [2026-05-24-productization-sprint-roadmap.md](../coordination/2026-05-24-productization-sprint-roadmap.md)

---

## 1. Purpose

Two pieces of B2B/B2C cohabitation are *behavioural*, not schema:

1. **One pricing brain, two callers.** Today web sales documents price through
   `PricingService::getPrice` (partner price list → default price list → base
   price). POS does **not** apply partner price lists at all. When a B2B account
   is recognised at the counter, the cart must get the *same* price the web
   would give. We introduce a `PricingStrategyResolver` that is the single
   resolution path for both surfaces, **delegating to the existing
   `PricingService` so the in-store result is byte-for-byte identical to today**,
   and adding one new, more-specific step at the front (a channel-level override)
   for future online-channel orders.

2. **The B2C/B2B document line, made explicit policy.** Today separation is an
   architectural accident: POS only ever writes a fiscal `Receipt`, never a
   `Document`. We make that a *policy* — `DocumentEmissionPolicy` — so the
   counter can never silently start emitting B2B credit-terms invoices, quotes,
   or sales orders, while the web keeps emitting all document types. The gate is
   payment-driven and code-grounded: **no credit terms + every tender settles
   immediately**.

**This track has zero migrations.** Resolver and policy are runtime services.
The only schema-dependent step (the channel override) is guarded and inert until
T3 lands its `channel_product_mappings` table.

---

## 2. Architecture grounding (verified file paths + state)

Read in this order:

1. `apps/erp/apps/api/app/Modules/Pricing/Domain/Services/PricingService.php` (lines 32–78: `getPrice()` — the **exact resolution order to preserve**: (1) partner-specific price list, (2) default price list for currency, (3) product base `sale_price`). Returns `array{price: string, source: string, price_list_id: string|null}` with `source` ∈ `partner_price_list | default_price_list | base_price`. Note the tenant/company scoping already baked into every branch (lines 68–71, 108–109, 164–165).
2. `apps/erp/apps/api/app/Modules/Pricing/Domain/PartnerPriceList.php` (lines 12–65) — per-partner list with `priority` + `isValidForDate()`.
3. `apps/erp/apps/api/app/Modules/Partner/Domain/Partner.php` — `customer_category` + `isB2B()` (lines 188–191), `payment_terms_days` (property line 37, fillable line 95, cast `integer` line 139), `PaymentTerms` cast (line 132). **`payment_terms_days` is the credit-terms signal.**
4. `apps/erp/apps/api/app/Modules/Partner/Domain/Enums/PaymentTerms.php` (lines 7–42) — `days()` maps `Immediate => 0`, `Net30 => 30`, etc.; `Custom => null`.
5. `apps/erp/apps/api/app/Modules/Treasury/Domain/PaymentMethod.php` — **the verified immediate-vs-deferred discriminator is `has_maturity`** (property line 26, fillable line 67, cast `boolean` line 89, `scopeWithMaturity()` line 200). `has_maturity = true` ⇒ the instrument matures later (TRAITE / LCR / bill-of-exchange = settled-later). `has_maturity = false` ⇒ tendered-at-counter (cash, card, wallet, voucher, loyalty). **This replaces the design spec's hand-named "TRAITE is deferred" prose with a code-grounded flag.**
6. `apps/erp/apps/api/app/Modules/Document/Domain/Enums/DocumentType.php` (lines 7–17) — the **actual** document types: `Quote, SalesOrder, PurchaseOrder, Invoice, CreditNote, DeliveryNote, ReturnNote, Expense`. **`Receipt` is NOT a `DocumentType`** — see §2.1.
7. `apps/erp/apps/api/app/Modules/Document/Domain/Document.php` — unified Document aggregate (the thing the policy governs).
8. `apps/erp/apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (lines 65–95: constructor + `create()` signature; it writes a `Receipt`, never a `Document`). The policy is a guardrail wired here and at any future "emit fiscal invoice from POS" path.
9. `apps/erp/apps/api/app/Modules/POS/Domain/Receipt.php` — the POS fiscal entity, **separate** from `Document`.
10. `apps/erp/apps/api/app/Modules/Company/Services/CompanyContext.php` — `requireCompany()` for tenant/company scoping (used by `PricingService`).

**Constraints from `apps/erp/CLAUDE.md`:** hexagonal, constructor injection only (`private readonly`, never `app()`), strict typing (DTOs, no `mixed`), enums for type columns, TDD, PHPStan level 8, Pint. `bcmath` via `CurrencyScale` for any money (never `number_format((float)…)`).

**Migration placement / topology:** none in this track. The channel-override step references the T3-owned `channel_product_mappings` table (tenant DB, NEW); until T3 ships it, the resolver's step 1 is a guarded no-op (it checks for a bound mapping repository / table existence and falls straight through). No cross-DB FK is introduced.

### 2.1 Refinement of the design spec (faithful correction)

The design spec §4.3 lists "`Receipt` (always)" among POS-allowed *document types*.
The verified `DocumentType` enum (file 6 above) has **no `Receipt` case** — the POS
`Receipt` is a distinct fiscal aggregate (`App\Modules\POS\Domain\Receipt`), not a
`Document`. **This impl spec scopes `DocumentEmissionPolicy` to `DocumentType`
emission only.** The POS `Receipt` is outside the policy and is always created by
the POS flow as it is today. The policy's real job is: *prevent the POS surface
from emitting `Document` rows it must not* (credit-terms `Invoice`, `Quote`,
`SalesOrder`) and *permit the narrow immediate-`Invoice` case* if/when a
"print a fiscal invoice for this cash sale" feature is built.

---

## 3. Domain model

No tables. New value objects, DTOs, enums, and services only.

### 3.1 PricingStrategyResolver

```php
final class PricingStrategyResolver
{
    public function __construct(
        private readonly PricingService $pricingService,            // delegated to for steps 2-4 (parity)
        private readonly ChannelPriceOverrideResolver $channelResolver, // step 1; no-op until T3
    ) {}

    public function resolve(ResolveContext $context): ResolvedPrice;
}
```

**Resolution order — first match wins (per design §4.2):**

1. **Channel override** — if `context.channelId !== null` AND a `channel_product_mappings` price override exists for `(channelId, productId, variantId)`, use it. (T3 dependency. `ChannelPriceOverrideResolver` returns `null` whenever channels are not yet installed → step is inert today.)
2. **Partner price list** — delegate to `PricingService` (its step 1).
3. **Default price list for currency** — delegate to `PricingService` (its step 2).
4. **Base price** — delegate to `PricingService` (its step 3).

**Parity guarantee (resolves design acceptance "identical price on POS and web"):**
for `channelId === null`, `resolve()` performs **a single call to
`PricingService::getPrice($productId, $partnerId, $quantity, $currency, $date)`**
and maps the result into `ResolvedPrice`. It does **not** re-implement steps 2–4.
This makes the in-store result provably identical to today's web result. Step 1
only ever *prepends* a more-specific match; it can never change the
`channelId === null` outcome.

**`ResolveContext`** (DTO): `string productId`, `?string variantId` *(T2 dep; pass-through nullable; `PricingService` is variant-agnostic today so a non-null `variantId` resolves at the product level until T2 wires variant pricing — documented limitation, not silent)*, `?string partnerId`, `?string locationId`, `?string channelId`, `string quantity = '1.00'`, `string currency`, `?DateTimeInterface date`.

**`ResolvedPrice`** (DTO): `string unitPrice`, `PricingStrategy appliedStrategy` (enum), `?string priceListId`, `string reasoning` (human-readable, e.g. "partner price list #abc, qty break ≥ 10").

**`PricingStrategy`** (enum, string-backed): `ChannelOverride = 'channel_override'`, `PartnerPriceList = 'partner_price_list'`, `DefaultPriceList = 'default_price_list'`, `BasePrice = 'base_price'`. (The last three values **match `PricingService`'s existing `source` strings** so logs/analytics stay consistent; `channel_override` is the new one.)

### 3.2 DocumentEmissionPolicy

```php
interface DocumentEmissionPolicy
{
    public function canEmit(DocumentType $type, EmissionContext $context): PolicyResult;
}

final class ConfigurableDocumentEmissionPolicy implements DocumentEmissionPolicy { /* default impl */ }
```

**`EmissionSurface`** (enum): `Pos = 'pos'`, `Web = 'web'`, `Api = 'api'`.

**`EmissionContext`** (DTO): `EmissionSurface $surface`, `?Partner $partner`, `int $paymentTermsDays` *(derived: `$partner?->payment_terms_days ?? 0`)*, `list<PaymentMethod> $tenderMethods` *(the payment methods used / intended; empty for not-yet-paid docs)*, `?User $user`.

**`PolicyResult`** (DTO): `bool $allowed`, `?DocumentEmissionDenialReason $reason` (enum: `CreditTermsAtCounter`, `DeferredTenderAtCounter`, `DocumentTypeNotAllowedOnSurface`), `string $message` (i18n key-able).

**Default policy rules:**

| Surface | DocumentType | Allowed? |
|---|---|---|
| `Web` | any | ✅ (subject to existing module RBAC) |
| `Api` | any | ✅ (subject to RBAC) |
| `Pos` | `Invoice` | ✅ **iff** `paymentTermsDays === 0` **AND** every `tenderMethod->has_maturity === false` |
| `Pos` | `CreditNote` | ✅ (immediate at-counter refund — resolves design §8 edge case) |
| `Pos` | `Quote`, `SalesOrder`, `PurchaseOrder` | ❌ `DocumentTypeNotAllowedOnSurface` |
| `Pos` | `Invoice` with `paymentTermsDays > 0` | ❌ `CreditTermsAtCounter` |
| `Pos` | `Invoice` where any tender `has_maturity === true` | ❌ `DeferredTenderAtCounter` |
| `Pos` | `DeliveryNote`, `ReturnNote`, `Expense` | ❌ `DocumentTypeNotAllowedOnSurface` |

**Edge cases (resolve design §8):**
- *Split tender, voucher + cash, high-value B2B item:* both methods have `has_maturity = false` → still immediate → POS `Invoice` allowed (terms permitting). The amount is irrelevant; only credit-terms + maturity matter.
- *`CreditNote` at POS for a B2B customer:* allowed — an at-counter refund is immediate, not a credit extension.
- *Partner is `null` (walk-in):* `paymentTermsDays` defaults to `0`; gate reduces to the maturity check.

**Surface allowlists are config-driven** (`config/documents.php` → `emission_surfaces`), so a tenant could in principle be granted a different allowlist later **without a migration** (per design §6 "configurable per tenant if needed"). Per-tenant override is **out of scope** for this track (§9); the default config is shipped and read.

---

## 4. Public contracts

### Services / interfaces

```php
PricingStrategyResolver::resolve(ResolveContext): ResolvedPrice          // shared POS + web
DocumentEmissionPolicy::canEmit(DocumentType, EmissionContext): PolicyResult
ChannelPriceOverrideResolver::override(ResolveContext): ?ResolvedPrice    // null until T3
```

### Integration points (the only behavioural changes to existing code)

1. **Web sales-document line pricing** — wherever a sales `Document` line currently calls `PricingService::getPrice` directly, route it through `PricingStrategyResolver::resolve` with `channelId` from the document's channel (null for in-store/manual web docs). Behaviour is unchanged for `channelId === null` (parity).
2. **POS pricing on B2B customer selection** — exposed via a read endpoint (below) consumed by impl-C; `PricingStrategyResolver` is the source. POS itself does not change in this track (Tauri deltas are impl-C, logged to the POS coordination log).
3. **Document-emission guardrail** — `DocumentEmissionPolicy::canEmit` is asserted at every `Document` creation entry point that can run on the POS surface. Today that set is **empty** (POS writes only `Receipt`), so wiring is a guard placed at the `Document` creation service used by POS-originating flows (the impl-C draft-order endpoint creates a `SalesOrder` on the **Web** surface — allowed — never on the POS surface). The guard throws `DocumentEmissionNotAllowedException` (→ 422 with `reason` + message) when `allowed === false`.

### REST endpoint (new, read-only — consumed by impl-C POS)

- `POST /api/v1/pricing/resolve` — body `{ product_id, variant_id?, partner_id?, location_id?, channel_id?, quantity?, currency }` → `ResolvedPrice` JSON. Behind `['api', 'auth:sanctum', SetPermissionsTeam::class]`, RBAC `pricing.resolve`. Validates UUIDs with `Str::isUuid()`. Tenant/company scoping inherited from `PricingService`'s `CompanyContext`.

### Events

None. Pricing and policy are synchronous query/decision services with no state change.

---

## 5. User-visible surface

- **Pricing is invisible** — same numbers, one code path. The only user-observable change is that a B2B account selected at POS (impl-C) now gets its negotiated price; this track supplies the engine, impl-C the UI.
- **Policy denials are user-visible only as guard errors** — if any code path attempts a disallowed POS document emission, the user sees a clear `t()` message ("Credit-terms invoices can't be issued at the counter — save as a draft order for the office to finalise"). The message points the cashier at the impl-C hand-off.
- **No new admin screen** in this track. (A future "document emission rules" settings page is out of scope.)

---

## 6. Generic-ness checklist

- [ ] Zero client names anywhere
- [ ] Resolution order is generic and extensible (channel → partner → default → base); adding a future strategy = new enum case + ordered step
- [ ] `DocumentEmissionPolicy` decisions derive from `payment_terms_days` + `PaymentMethod.has_maturity` only — no vertical-specific logic, no hard-coded payment-method codes
- [ ] Surface allowlists are config, not code
- [ ] Works for any tenant/vertical; walk-in (`partner === null`) handled
- [ ] No migration; no cross-DB FK; channel step inert (not erroring) until T3
- [ ] Parity: `channelId === null` path produces identical output to today's `PricingService::getPrice`

---

## 7. Acceptance criteria

- [ ] **Parity:** for a fixed `(productId, partnerId, quantity, currency, date)` and `channelId === null`, `PricingStrategyResolver::resolve()` returns the same `unitPrice` and `priceListId` as `PricingService::getPrice()` — proven by a test that calls both and asserts equality across partner-price-list, default-price-list, and base-price cases
- [ ] Partner with a `PartnerPriceList` priced at X; resolver returns X with `appliedStrategy = PartnerPriceList` for both a POS-style call (`channelId = null`) and a web-style call
- [ ] No partner price list → resolver returns default-price-list price (`appliedStrategy = DefaultPriceList`); none → base price (`appliedStrategy = BasePrice`)
- [ ] `channelId` set + a (stubbed/fixture) channel override present → resolver returns the override first (`appliedStrategy = ChannelOverride`); with no channels installed, a non-null `channelId` falls through to partner/default/base without error
- [ ] **Policy — POS Invoice allowed:** `partner.payment_terms_days = 0`, tenders all `has_maturity = false` (cash) → `canEmit(Invoice, posContext).allowed === true`
- [ ] **Policy — credit terms blocked:** `partner.payment_terms_days = 30` → `canEmit(Invoice, posContext).allowed === false`, `reason = CreditTermsAtCounter`
- [ ] **Policy — deferred tender blocked:** terms = 0 but a tender method has `has_maturity = true` (TRAITE) → blocked, `reason = DeferredTenderAtCounter`
- [ ] **Policy — Quote/SalesOrder blocked at POS:** `canEmit(Quote, posContext).allowed === false`, `reason = DocumentTypeNotAllowedOnSurface`
- [ ] **Policy — web unrestricted:** `canEmit(<any type>, webContext).allowed === true`
- [ ] **Policy — CreditNote at POS allowed** (immediate refund)
- [ ] **Policy — split tender (voucher + cash), high amount, terms = 0:** POS `Invoice` allowed
- [ ] `POST /api/v1/pricing/resolve` returns `ResolvedPrice`; rejects malformed UUIDs with 422; cross-tenant `product_id` resolves nothing / is scoped out (no leak)
- [ ] Backward compat: every existing Pricing test passes; web sales-document pricing output unchanged after the resolver is wired in
- [ ] Any disallowed POS `Document` emission attempt raises `DocumentEmissionNotAllowedException` → 422 (not 500), with `reason` + message

### Tests (write first — TDD)

- [ ] Resolver parity test (3 source cases) — calls both `resolve` and `getPrice`, asserts equality
- [ ] Resolver channel-override test (with a fake `ChannelPriceOverrideResolver` returning a price, and one returning null)
- [ ] Resolver: variant_id pass-through documented behaviour (resolves at product level pre-T2)
- [ ] Policy truth-table tests — one per row of the §3.2 table, plus the three edge cases
- [ ] Endpoint feature tests (happy path, UUID guard, RBAC, tenant scope)

---

## 8. Adversarial review checklist

**Reviewer instruction (mandatory):** *"Verify every finding against actual code at the cited paths — read the files. Pay special attention to: (1) the resolver's `channelId === null` path delegates to `PricingService::getPrice` and does NOT re-implement steps 2–4, so output is identical to today — confirm by reading PricingService.php lines 32–78; (2) the policy discriminates on `Partner.payment_terms_days` AND `PaymentMethod.has_maturity` (verify both exist: Partner.php:37/139 and PaymentMethod.php:26/89) — NOT on `customer_category` alone; (3) `Receipt` is correctly treated as outside the policy (it is not a `DocumentType` — verify DocumentType.php has no Receipt case); (4) no migration and no cross-DB FK are introduced; (5) the channel step is inert (returns null, does not error) when T3's `channel_product_mappings` table is absent."*

- [ ] `resolve()` for `channelId === null` is a single delegation to `PricingService::getPrice`; no duplicated resolution logic; parity test exists and passes
- [ ] `PricingStrategy` enum's three legacy values exactly equal `PricingService`'s `source` strings (`partner_price_list`, `default_price_list`, `base_price`)
- [ ] Policy gate uses `payment_terms_days === 0` AND all-tenders-`has_maturity===false`; it does NOT gate on `customer_category` (a B2B customer paying cash with terms=0 can still get an immediate POS invoice)
- [ ] `has_maturity` is the real column (PaymentMethod.php:26/67/89); no invented `is_deferred`/`settlement_timing` column
- [ ] `Receipt` is out of policy scope; the spec did not invent a `DocumentType::Receipt`
- [ ] No migration in this track; channel step degrades to null (not exception) without T3
- [ ] `variant_id` pass-through is documented (not silently dropped); flagged as T2-completed later
- [ ] `DocumentEmissionNotAllowedException` maps to 422 with reason + message, not 500
- [ ] Endpoint: UUID guard, RBAC, tenant/company scope inherited from `PricingService`
- [ ] Backward compat: existing Pricing tests untouched and passing; web pricing output identical
- [ ] No money via float; `CurrencyScale`/bcmath only
- [ ] Spec drift: every cited path still says what the spec claims

---

## 9. Out of scope

- Concrete channel override data (T3 owns `channels` / `channel_product_mappings`); this track only wires the *guarded hook*
- Variant-level pricing (T2 owns `product_variants`); resolver passes `variant_id` through and resolves at product level until T2 completes the wiring
- Per-tenant document-emission allowlist overrides (config default only)
- Quantity-break UI / price-list management UI (already exists in Pricing module)
- Any POS Tauri change (impl-C, via POS coordination log)
- Consolidated reporting dimensions (T5 + design §4.5)
- Loyalty/wallet earn-burn pricing interactions

---

## 10. Reading order

1. This spec
2. Parent design [2026-05-24-t11-b2b-b2c-separation.md](2026-05-24-t11-b2b-b2c-separation.md) §4.2, §4.3, §8
3. [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md) (confirm: this track adds no migration)
4. `apps/erp/CLAUDE.md`
5. The 10 file paths in §2 — especially `PricingService.php` (32–78), `PaymentMethod.php` (26/89/200), `DocumentType.php`
6. Memory: `project_monetary_precision.md` (bcmath discipline)
7. Existing tests at `apps/erp/apps/api/tests/Feature/Pricing/` to mirror style

---

## 11. Workflow recommendation

**Phase 1 (Opus, ~2 PD) — architecture:** `ResolveContext` / `ResolvedPrice` / `PricingStrategy` enum; `PricingStrategyResolver` delegating to `PricingService` with the parity guarantee; `ChannelPriceOverrideResolver` no-op stub + interface. TDD: parity test first (red against a not-yet-existing resolver), then implement.

**Phase 2 (Opus, ~1.5 PD) — policy:** `EmissionSurface`, `DocumentEmissionDenialReason` enums; `EmissionContext` / `PolicyResult` DTOs; `ConfigurableDocumentEmissionPolicy` + `config/documents.php` allowlist; `DocumentEmissionNotAllowedException`. TDD: the §3.2 truth table as tests first.

**Phase 3 (Codex, ~1.5 PD) — integration + endpoint + tests:** route web sales-document pricing through the resolver (verify output unchanged); place the policy guard at POS-originating `Document` creation; `POST /api/v1/pricing/resolve` endpoint + permission + FormRequest; backward-compat sweep (grep all `PricingService::getPrice` callsites, list each, confirm parity).

Adversarial review: Codex headless against §8 after each Opus phase ideally, minimally once at the end.

---

## 12. Coordination notes

- **No migration → no T6 Phase 0 dependency.** This track can develop and merge independently to `dev`, *except* the channel-override step's eventual data source (T3). The step ships inert and becomes live when T3's `channel_product_mappings` exists — no rework, just data.
- **Depends conceptually on nothing else in T11**, but **enables T11-impl-C**: the POS B2B price application (`T11-D3`) and the draft-order hand-off (which must create its `SalesOrder` on the **Web** surface, allowed by the policy). Logged in [2026-05-24-pos-coordination-log.md](../coordination/2026-05-24-pos-coordination-log.md) (T11-D3 dependency "T11-impl-B merged").
- **T3 dependency** (channel step) and **T5 dependency** (reporting dimensions) are surfaced explicitly per design §8.
- **Published-API change:** the new `POST /api/v1/pricing/resolve` endpoint and the resolver becoming the canonical pricing entry point are worth a [REALIGNMENT-LOG.md](../../03-ERP-INTEGRATION/REALIGNMENT-LOG.md) note when shipped, so the platform/ERP-integration side knows the canonical pricing path moved from `PricingService::getPrice` to `PricingStrategyResolver::resolve`.
- **No Tauri / POS-client code in this track.**
- **Backward compat is non-negotiable** — the parity guarantee is the headline acceptance criterion.
