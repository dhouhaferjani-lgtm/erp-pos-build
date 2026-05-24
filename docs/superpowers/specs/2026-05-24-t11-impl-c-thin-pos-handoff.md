# T11-impl-C — Thin-POS B2B Hand-off

**Track:** T11-impl-C (next-cycle implementation track derived from the T11 design spec)
**Date:** 2026-05-24
**Recommended workflow:** Codex for the web ERP queue + backend hand-off endpoint; POS-client deltas logged to the POS coordination log → fiscal session (no direct Tauri edits). Adversarial review: Codex headless.
**Estimated effort:** ~4 PD (most POS-client work logged to [2026-05-24-pos-coordination-log.md](../coordination/2026-05-24-pos-coordination-log.md) for fiscal coordination)
**Parent design:** [2026-05-24-t11-b2b-b2c-separation.md](2026-05-24-t11-b2b-b2c-separation.md) §4.4
**Constitutional reference:** [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md)
**Roadmap reference:** [2026-05-24-productization-sprint-roadmap.md](../coordination/2026-05-24-productization-sprint-roadmap.md)
**Depends on:** T11-impl-A (customer model — badge/contact), T11-impl-B (`PricingStrategyResolver` + `DocumentEmissionPolicy`)

---

## 1. Purpose

A B2B account sometimes walks up to the counter. The cashier should be able to
(a) *recognise* the account, (b) get its *negotiated prices*, and (c) either take
**immediate payment** (→ a normal `Receipt`, no change to today) **or** hand the
cart off to the back office as a **draft sales order** the office finalises with
credit terms. The counter must **never** itself extend credit, pick payment
terms, or emit a B2B invoice. The Tauri binary stays thin.

This track delivers the **server + web** side of that hand-off, and *specifies*
(but does not directly build) the **POS-client** deltas, which flow through the
POS coordination log to the in-flight fiscal session that owns the Tauri files.

The architectural keystone: **the POS never emits a `Document`.** "Save as Draft
Order" POSTs the cart to a backend endpoint, and the **server** creates the
`SalesOrder` draft on the Web/API surface — which `DocumentEmissionPolicy`
(impl-B) permits. POS itself only transmits cart data. This is what lets the
counter produce a B2B sales order *without* violating the "POS can't emit
SalesOrder" rule.

---

## 2. Architecture grounding (verified file paths + state)

Read in this order:

1. `apps/erp/apps/pos/src/stores/holdStore.ts` (lines 1–178) — the **park-sale pattern to mirror for the UX**: `holdCurrentCart` captures the cart, persists, clears the cart (line 138), and drops the pending idempotency key (line 143). `recallTransaction` / `discardTransaction` round it out. **Note:** `holdCurrentCart` persists to **local SQLite** (`insertHeldTransaction`, line 126); the new "Save as Draft Order" persists to the **server** instead — see §2.1.
2. `apps/erp/apps/pos/src/lib/db/repositories/heldTransactionRepository.ts` — the local-DB repo holdStore uses (pattern reference only; the draft-order hand-off does not write here).
3. `apps/erp/apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php` (lines 8–14) — `Draft, Confirmed, Posted, Paid, Received, Cancelled`. A draft order = `Document(type = SalesOrder, status = Draft)`. `Draft` is editable + deletable (lines 18–37).
4. `apps/erp/apps/api/app/Modules/Document/Domain/Enums/DocumentType.php` (lines 7–17) — `SalesOrder` is a real type. `Receipt` is **not** a Document type (the POS receipt path is unchanged).
5. `apps/erp/apps/api/app/Modules/Document/Domain/Document.php` — the aggregate. The `documents` table has `source_document_id` (nullable, `2025_11_30_080000_create_documents_table.php:33`) for conversion chains; **no `origin`/`channel` column today** (see §3.3).
6. `apps/erp/apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php` — **the office rep's "convert to invoice with terms" already exists.** This track reuses it; it does not reimplement conversion.
7. `apps/erp/apps/api/app/Modules/Document/Domain/Services/Conversion/DocumentConverterRegistry.php` — registry the converter is resolved from.
8. `apps/erp/apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx` + `apps/erp/apps/web/src/features/documents/DocumentListPage.tsx` — the existing web surfaces the queue extends/filters.
9. **impl-B** `PricingStrategyResolver` (lines = the impl-B spec) — the hand-off prices lines through this, so the draft order carries the same B2B prices the cashier saw.
10. **impl-B** `DocumentEmissionPolicy` — the hand-off endpoint asserts `canEmit(SalesOrder, EmissionContext(surface = Web/Api …))` before creating the draft.
11. `apps/erp/docs/superpowers/coordination/2026-05-24-pos-coordination-log.md` — existing entries **T11-D1** (B2B badge), **T11-D2** ("Save as Draft Order" button + holdStore extension), **T11-D3** (apply PartnerPriceList on B2B select). This track refines those entries; the fiscal session implements them.

**Constraints from `apps/erp/CLAUDE.md`:** hexagonal, constructor injection, strict typing, enums, TDD, PHPStan level 8, route middleware `['api','auth:sanctum',SetPermissionsTeam::class]`, types from DTOs (`typescript:transform`), all strings via `t()`, design tokens for any web colors.

**Migration placement / topology:** **this track aims for zero migrations** (§3.3). If the optional `origin` discriminator is adopted, it is one nullable column in `apps/erp/apps/api/database/migrations/tenant/` — **merge-after T6 Phase 0**. No cross-DB FK. The draft `Document` and its lines are intra-tenant.

### 2.1 The hand-off is online, not a local park (faithful refinement)

The design spec §4.4 says the button "uses the existing `holdStore` pattern
(mirror/extend)". **The UX mirrors** `holdCurrentCart` (capture cart → persist →
clear cart → drop idempotency key). **The persistence target differs:**
`holdCurrentCart` writes local SQLite (offline park); `saveAsDraftOrder` POSTs to
the server to create a `Document`. They are **siblings, not a replacement** — the
existing "Hold" feature is untouched (design §8 adversarial item). Because it
hits the server, "Save as Draft Order" **requires connectivity**; offline it is
disabled with a clear message. Offline draft-order queuing is **out of scope**
(§9) precisely because it would touch the fiscal-sensitive POS sync engine the
fiscal session owns.

---

## 3. Domain model

### 3.1 Server side (built directly in `apps/api`)

**`DraftOrderHandoffService`** (Application service, new, in the Document or POS module — place per `apps/erp/apps/api/app/Modules/Document/.claude` boundaries; cross-module access via the Document module's public service/contract, never direct model import):

```php
final class DraftOrderHandoffService
{
    public function __construct(
        private readonly PricingStrategyResolver $pricingResolver,     // impl-B
        private readonly DocumentEmissionPolicy $emissionPolicy,        // impl-B
        private readonly /* existing Document creation service */ $documentFactory,
        private readonly CompanyContext $companyContext,
    ) {}

    // Creates a SalesOrder/Draft from a POS hand-off. Prices each line via the
    // resolver; asserts emissionPolicy.canEmit(SalesOrder, Web-surface ctx).
    public function createFromHandoff(CreateDraftOrderCommand $command): Document;
}
```

- `CreateDraftOrderCommand` (DTO): `string partnerId` (must be a customer), `string locationId`, `string currency`, `list<DraftOrderLineInput> lines` (`productId`, `?variantId`, `quantity`, optional `note`), `?string reference`, `?string originTerminalId` (informational).
- Line prices are **server-resolved** via `PricingStrategyResolver` (channel_id = null) — the POS-supplied price is treated as advisory and re-verified server-side (never trust client prices for a B2B document).
- The created document is `type = SalesOrder`, `status = Draft`. Office conversion to `Invoice` (with terms) reuses the existing `SalesOrderToInvoiceConverter`.

### 3.2 Enums

No new status enum — `DocumentStatus::Draft` + `DocumentType::SalesOrder` already exist. `EmissionSurface` comes from impl-B.

### 3.3 Origin discriminator — optional, decided explicitly

The "office queue" can be expressed two ways:

- **Default (no migration):** the queue is `Document` where `type = SalesOrder AND status = Draft`. Sufficient for the demo and the core flow. POS-originated drafts are not distinguished from web-drafted sales orders.
- **Optional (one nullable column, merge-after T6 Phase 0):** add `documents.origin` (nullable string, e.g. `pos_handoff | web | online_<channel>`), set by the hand-off endpoint. This both filters the queue and feeds the T5 reporting `channel` dimension (design §4.5).

**This track ships the Default** and treats the `origin` column as a T5-aligned
enhancement (so reporting and the queue filter land together, owned by T5, not
forced here). Flagged so the reviewer doesn't read "queue" as implying a new
column.

---

## 4. Public contracts

### REST endpoints (new / reused)

- `POST /api/v1/draft-orders` — **new.** Body = `CreateDraftOrderCommand`. Creates the `SalesOrder`/`Draft`. Behind `['api','auth:sanctum',SetPermissionsTeam::class]`, RBAC `draft-order.create`. Validates all UUIDs with `Str::isUuid()`. Tenant/company scoped via `CompanyContext`. Returns the created `Document` DTO.
- `GET /api/v1/draft-orders` — **new (thin wrapper)** or a filter on the existing documents list: `type=SalesOrder & status=Draft`. RBAC `draft-order.view`.
- **Reused:** existing sales-order → invoice conversion endpoint/flow (backed by `SalesOrderToInvoiceConverter`) for the office rep. No new conversion code.

### Events

- `DraftOrderHandedOff` (`document_id`, `tenant_id`, `partner_id`, `location_id`, `origin_terminal_id`) — emitted post-commit so the office queue / notifications can react. New immutable event.
- Existing `DocumentConverted` fires on the office conversion (unchanged).

### Permissions (new)

`draft-order.view`, `draft-order.create`. Wired into `RolesAndPermissionsSeeder`. (POS cashiers get `create`; office reps get `view` + existing invoice-conversion permissions.)

### POS-client contract (specified here; implemented via the coordination log)

`useHoldStore`-sibling action (do **not** add to `holdStore` itself — keep the
local-park store pure; add a new store/action, e.g. `draftOrderStore.saveAsDraftOrder`):

```ts
// mirrors holdCurrentCart UX, but POSTs instead of writing SQLite
saveAsDraftOrder(): Promise<void>
//  → builds CreateDraftOrderCommand from cartStore + selected partner
//  → POST /api/v1/draft-orders
//  → on success: clearCart() + discardPendingSubmission() (mirror holdStore.ts:138,143)
//  → requires online; disabled offline
```

---

## 5. User-visible surface

### Web ERP (built directly, `apps/web/src/features/documents/`)

- **DraftOrderQueuePage** — list of `SalesOrder`/`Draft` documents (filtered `DocumentListPage`), newest first, showing partner (B2B badge via impl-A), location, line count, total, origin terminal. Office rep clicks through to the existing `SalesOrderDetailPage`.
- **Convert-to-invoice** — uses the existing sales-order detail action + `SalesOrderToInvoiceConverter`; the rep sets credit terms there (existing B2B flow). No new conversion UI.
- i18n `draftOrders` namespace; design tokens for colors.

### POS (Tauri — **specified, logged, NOT built here**; fiscal session owns the files)

Refines the existing coordination-log entries:

- **T11-D1 — B2B badge** in customer search/selection results when `partner.customer_category === 'business'` (impl-A model). Attaches to whatever customer-selection surface the in-flight POS customer-accounts work produces (no fixed path asserted — the fiscal session owns it).
- **T11-D2 — "Save as Draft Order → Web" button** in the checkout area. New `draftOrderStore.saveAsDraftOrder` sibling action (mirrors `holdStore` UX; POSTs to `/api/v1/draft-orders`; online-only). The existing "Hold" button and `holdStore` are untouched.
- **T11-D3 — apply PartnerPriceList on B2B customer selection** by calling `POST /api/v1/pricing/resolve` (impl-B) for current cart lines and updating cart prices. No pricing logic in the binary.
- **No credit logic, no terms picker, no quote/invoice PDF in the POS binary** (design §4.4).

---

## 6. Generic-ness checklist

- [ ] Zero client names anywhere
- [ ] Hand-off works for any tenant where a B2C-walk-in turns out to be a B2B account
- [ ] Draft order uses generic `SalesOrder`/`Draft` — no vertical-specific document type
- [ ] Server re-prices via the generic `PricingStrategyResolver` (no client-supplied price trusted)
- [ ] Office conversion reuses the generic existing converter
- [ ] No migration in the core track; optional `origin` column (if adopted) is generic + T5-aligned, in `migrations/tenant/`
- [ ] No cross-DB FK; draft document + lines intra-tenant
- [ ] Existing "Hold" / `holdStore` semantics preserved (new feature is a sibling)

---

## 7. Acceptance criteria

- [ ] Cashier selects a B2B partner (`payment_terms_days = 30`) at POS; cart prices update to the partner's `PartnerPriceList` via `/api/v1/pricing/resolve` (impl-B)
- [ ] Cashier pays immediately by cash → a normal `Receipt` is created tied to `partner_id`; **no `Document` is created** (unchanged from today)
- [ ] Cashier instead hits "Save as Draft Order → Web" → `POST /api/v1/draft-orders` creates `Document(type=SalesOrder, status=Draft)` with server-resolved B2B line prices; cart clears; pending idempotency key dropped
- [ ] The draft appears in **DraftOrderQueuePage**; office rep opens it and converts to `Invoice` with 30-day terms via the existing `SalesOrderToInvoiceConverter`
- [ ] The POS **cannot**, through any path, create a `Document` — it only POSTs the hand-off; the server creates the draft on the Web/API surface (policy-allowed). A POS-surface emission attempt is rejected by `DocumentEmissionPolicy` (impl-B)
- [ ] Server **re-prices** lines; a tampered client price in the request body is ignored (server value wins)
- [ ] "Save as Draft Order" is **disabled offline** with a clear message; the existing offline "Hold" still works
- [ ] `DraftOrderHandedOff` event fires post-commit
- [ ] Cross-tenant `partner_id`/`product_id` in the hand-off body → 404/scoped-out, no leak; malformed UUID → 422 not 500
- [ ] Backward compat: existing `holdStore` (Hold/Recall/Discard) behaviour and tests unchanged; existing sales-order + conversion tests pass

### Tests (write first — TDD)

- [ ] Feature: `POST /api/v1/draft-orders` happy path (creates SalesOrder/Draft, lines server-priced, event fired)
- [ ] Feature: server-reprice — client sends wrong price, stored price = resolver price
- [ ] Feature: emission-policy assertion — service refuses to create on a POS surface context; accepts Web/Api
- [ ] Feature: tenant isolation + UUID guard + RBAC on the endpoint
- [ ] Feature: office conversion of the draft → Invoice with terms (reusing the existing converter) works end-to-end
- [ ] Web: DraftOrderQueuePage renders the queue (component test, mocked query) + tenant-scope test mirroring `SalesOrderDetailPage.tenantScope.test.tsx`
- [ ] POS deltas: acceptance owned by the fiscal session when T11-D1/D2/D3 ship (cross-referenced, not run here)

---

## 8. Adversarial review checklist

**Reviewer instruction (mandatory):** *"Verify every finding against actual code at the cited paths — read the files. Pay special attention to: (1) the POS never emits a `Document` — the draft is created server-side via the hand-off endpoint on the Web/API surface, reconciling with impl-B's policy that POS can't emit `SalesOrder`; (2) the hand-off mirrors `holdStore` UX but persists to the server, NOT local SQLite, and is a sibling that leaves the existing 'Hold' feature (holdStore.ts) untouched — confirm by reading holdStore.ts; (3) server re-prices via `PricingStrategyResolver`, never trusting client prices; (4) the office conversion reuses the existing `SalesOrderToInvoiceConverter`, not a new one; (5) all Tauri changes are LOGGED to the POS coordination log, with zero direct Tauri edits in this track; (6) no migration in the core track (or, if `origin` adopted, it's one nullable column in migrations/tenant/, merge-after T6)."*

- [ ] No `Document` is ever created by POS-client code; the draft is server-created on Web/API surface; `DocumentEmissionPolicy` is asserted in `DraftOrderHandoffService`
- [ ] `holdStore.ts` is untouched; the new action is a separate store/sibling; "Hold" still works; idempotency-key drop mirrors `holdStore.ts:143`
- [ ] Server re-prices via `PricingStrategyResolver`; client-supplied line prices are not persisted
- [ ] Office conversion reuses `SalesOrderToInvoiceConverter` (no duplicate conversion logic)
- [ ] All Tauri/POS-client work is logged in the POS coordination log (T11-D1/D2/D3 refined); **zero** Tauri files edited in this track's diff
- [ ] No migration in the core track; if `origin` adopted, single nullable column in `migrations/tenant/`, no cross-DB FK, merge-after T6
- [ ] Endpoint: UUID guard, RBAC (`draft-order.create`/`view`), tenant/company scope; cross-tenant → 404, malformed UUID → 422
- [ ] "Save as Draft Order" online-only; offline path is explicitly out of scope (no sync-engine touch)
- [ ] `DraftOrderHandedOff` is a new immutable event
- [ ] Web UI uses `t()` + design tokens; types from DTOs (`typescript:transform`)
- [ ] Spec drift: every cited path still says what the spec claims

---

## 9. Out of scope

- **Offline draft-order queuing** (would touch the fiscal-sensitive POS sync engine) — deferred; online-only for now
- Direct Tauri edits — all POS-client work via the coordination log → fiscal session
- New invoice-conversion logic — reuse existing `SalesOrderToInvoiceConverter`
- Credit-terms picker / quote PDF in the POS binary (design §4.4)
- Office-queue assignment/routing (who owns which draft) — queue is flat; assignment is a future enhancement
- First-class `origin`/`channel` discriminator + reporting dimension — T5-owned (design §4.5)
- POS customer-search/selection surface itself — owned by the in-flight POS customer-accounts work; this track only specifies the badge + actions that attach to it

---

## 10. Reading order

1. This spec
2. Parent design [2026-05-24-t11-b2b-b2c-separation.md](2026-05-24-t11-b2b-b2c-separation.md) §4.4
3. T11-impl-B spec ([2026-05-24-t11-impl-b-pricing-resolver-doc-policy.md](2026-05-24-t11-impl-b-pricing-resolver-doc-policy.md)) — resolver + policy are hard dependencies
4. T11-impl-A spec ([2026-05-24-t11-impl-a-customer-model.md](2026-05-24-t11-impl-a-customer-model.md)) — badge/contact dependency
5. [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md) (confirm migration-free core)
6. [2026-05-24-pos-coordination-log.md](../coordination/2026-05-24-pos-coordination-log.md) (T11-D1/D2/D3)
7. The file paths in §2 — especially `holdStore.ts`, `DocumentStatus.php`, `SalesOrderToInvoiceConverter.php`
8. `apps/erp/CLAUDE.md` POS notes (thin-POS, fiscal-coordination discipline)

---

## 11. Workflow recommendation

**Phase 1 (Codex, ~2 PD) — server hand-off:** `DraftOrderHandoffService` + `CreateDraftOrderCommand`/`DraftOrderLineInput` DTOs + `DraftOrderHandedOff` event + `POST /api/v1/draft-orders` endpoint + permissions + FormRequest (UUID guards). TDD: endpoint feature test first (incl. server-reprice + policy assertion). Reuse `SalesOrderToInvoiceConverter` for conversion — verify the office flow end-to-end.

**Phase 2 (Codex, ~1 PD) — web queue UI:** `DraftOrderQueuePage` (filter `SalesOrder`/`Draft`), B2B badge (impl-A), link to existing detail/convert. i18n + tenant-scope test.

**Phase 3 (logged, NOT built — fiscal session, sized in the POS log):** refine T11-D1/D2/D3 in the POS coordination log with the §5 acceptance; the fiscal/POS session triages and ships the Tauri deltas (`saveAsDraftOrder` sibling action, B2B badge, resolve-on-select). **Do not edit Tauri code in this track.**

Adversarial review: Codex headless against §8 after Phase 1+2.

---

## 12. Coordination notes

- **Hard dependencies:** T11-impl-B (resolver + policy) and T11-impl-A (customer model) must merge first.
- **No T6 Phase 0 dependency** for the core (migration-free). The optional `origin` column is T5-aligned and would merge-after Phase 0.
- **POS-client deltas** are the existing **T11-D1/D2/D3** entries in [2026-05-24-pos-coordination-log.md](../coordination/2026-05-24-pos-coordination-log.md); this spec refines their acceptance. They ship via the fiscal session per that log's handshake protocol — **never via direct Tauri edits here.**
- **Fiscal Phase 1 collision:** the hand-off does **not** touch the receipt/sync/device files fiscal Phase 1 rewrites (it adds a *new* sibling store + a *server* endpoint). The B2B badge attaches to the POS customer-accounts surface, also fiscal-coordinated. Keeping "Save as Draft Order" online-only deliberately avoids the offline sync engine.
- **Published-API change:** new `POST /api/v1/draft-orders` + `GET /api/v1/draft-orders` — note in [REALIGNMENT-LOG.md](../../03-ERP-INTEGRATION/REALIGNMENT-LOG.md) when shipped.
- **Backward compat is non-negotiable** — `holdStore`, receipts, and existing sales-order/conversion flows are untouched.
