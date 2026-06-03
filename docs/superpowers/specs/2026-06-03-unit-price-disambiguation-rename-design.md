# `unit_price` Disambiguation Rename — Design / Spec

> **Status:** Approved design (2026-06-03). Spec only — no code.
> **Branch:** `feat/unit-price-rename-spec` (off `dev`).
> **Companion plan:** `docs/superpowers/plans/2026-06-03-unit-price-disambiguation-rename-plan.md` (written after this spec is reviewed).
> **References:** [`docs/architecture/precision-contract.md` — `unit_price` section](../../architecture/precision-contract.md), [CLAUDE.md §19](../../../CLAUDE.md).

---

## 1. Problem

The field name `unit_price` is **context-overloaded** with two incompatible tax semantics that share the same name across many layers:

| Context | `unit_price` means | Canonical evidence |
|---|---|---|
| **B2C / POS** (Tauri `apps/pos` cart, its server-side sale persistence, the signed `SALE_RECEIPT`) | **tax-INCLUSIVE (TTC)** — shelf price includes VAT; net appears separately as `line_subtotal` | `cartStore.computeTaxAmount` extracts VAT from the gross; canonical `line_items[].unit_price` is the inclusive cart price written verbatim |
| **B2B / documents** (quotes, orders, invoices, credit/return notes, delivery notes, workshop, catalog cart) | **net / HT** — pre-tax | `DocumentTotalsCalculator`/`FacturXService` do `bcmul(quantity, unit_price)` = net subtotal; golden vector F-04 shows aggregate `unit_price=20.00` net + `line_vat=4.00` |

This overload has already produced a real false-positive in the fiscal line-arithmetic work (asserting `line_subtotal == unit_price × qty − discount` against a POS line compares net vs gross). The precision contract currently documents the overload as the live mitigation and flags the rename as **deferred**. This spec resolves the deferral.

### Locked classification rule (semantics, not file location)

- **B2C / POS path → `unit_price_incl_tax`.** Tax-inclusive. Includes the Tauri app *and* its server-side sale persistence (`pos_order_lines`, `OrderLineData`) *and* the signed canonical `SALE_RECEIPT` bytes.
- **B2B / everything else → `unit_price_excl_tax`.** Net/HT. Document layer, Workshop (bundle / work-order), catalog Cart, `apps/web` admin.

English names are deliberate (congruent with existing naming; avoid French TTC/HT), per the precision-contract deferred-disambiguation note.

---

## 2. Goals / Non-Goals

**Goals**
- Eliminate the `unit_price` name overload by renaming to `unit_price_excl_tax` (B2B/net) and `unit_price_incl_tax` (B2C/incl).
- Preserve the device↔server fiscal model exactly: **device authors canonical bytes; server re-hashes, never recomputes.**
- Never invalidate an existing fiscal chain: already-signed v1 `SALE_RECEIPT` bytes (carrying the old `unit_price` key) must keep verifying byte-for-byte forever.
- Keep each phase independently shippable as its own PR.

**Non-Goals**
- No change to monetary/quantity precision, scale, storage tiers, or value objects (the precision contract stands).
- No change to tax math, rounding, or aggregate fiscal-integrity invariants — only the *field name* changes.
- No renaming of `line_subtotal` / `line_vat` / `vat_breakdown` (already unambiguous).
- No new product/pricing features.

---

## 3. Sequencing constraint — **AFTER T2 variants**

> **This rename MUST land after T2 product-variants implementation is merged.** T2 (see `project_t2_variants_impl`) reworks pricing and document/POS **line DTOs and services heavily** (`getBulkPrices`, channel pricing, line-level variant resolution). Running this rename concurrently would produce large, conflict-prone diffs on exactly the same DTOs/services this rename touches. Treat T2-merged as a precondition; re-run the surface inventory (Section 7) against post-T2 `dev` before executing, because T2 may have added or moved `unit_price` callsites.

---

## 4. Phase structure (by risk)

Three phases. **Phase 2b is optional / deferred** — it changes signed bytes and is gated on explicit owner sign-off; Phases 1 and 2a deliver the disambiguation everywhere it is safe, and the canonical payload can remain on v1 (documented) until the owner elects to do 2b.

### Phase 1 — B2B / net → `unit_price_excl_tax` (moderate risk)

Every net `unit_price` outside the B2C POS sale path.

- **DTOs (`apps/api`):**
  - `Document/Application/DTOs/DocumentLineData.php`
  - `Workshop/Bundle/Application/DTOs/BundleExpansionLineData.php`
  - `Workshop/WorkOrder/Application/DTOs/WorkOrderLineData.php`
  - `Workshop/Bundle/Application/DTOs/ServiceBundleComponentData.php` (`override_unit_price` → `override_unit_price_excl_tax`)
  - `Cart/Application/DTOs/CatalogCartItemData.php` — **see Cart classification note (§6).**
- **DB — expand/contract (add → backfill → dual-write → switch reads → drop in follow-up migration):**
  - `document_lines.unit_price` → `unit_price_excl_tax` (currently `decimal(15,2)` — preserve scale; do not "fix" precision here).
  - Any other net column surfaced by the §7 re-inventory.
- **Immutable events (Rule 8 — never mutate a used event):** `unit_price` appears in `DraftLineAdded` / `DraftLineAddedV2` / `DraftLineModifiedV2` payloads. Introduce **versioned successors** (`DraftLineAddedV3`, `DraftLineModifiedV3`) carrying `unit_price_excl_tax`; leave existing event classes and their serialized key untouched.
- **Services (`apps/api`):** `DocumentTotalsCalculator`, `Conversion/Concerns/CopiesDocumentData` + the converters (SO→DN, DN→Invoice, SO→Invoice), `CreditNoteService`, `RefundService`, `DraftPersistenceService`, `FacturXService`, and the FormRequests/controllers (`UpdateDocumentRequest`, `RefundController`, `QuoteController`).
- **Frontend (`apps/web`):** regenerate `packages/shared/types/generated.d.ts` via `php artisan typescript:transform`; update the manual mirror `apps/web/src/types/document.ts`; `DocumentLineEditor.tsx`, detail pages (quote/invoice/PO), `CreateCreditNotePage`, `CreateReturnNotePage`, landed-cost breakdown, goods-receipt; fixtures (`__fixtures__/*`) + e2e specs.
- **Fiscal-byte impact:** none — nothing in this phase is signed.

### Phase 2a — B2C non-fiscal POS storage → `unit_price_incl_tax` (moderate risk)

Rename the inclusive `unit_price` everywhere **except** the signed canonical bytes. Lands **before** 2b so the cart already speaks the new name when the byte builder is touched.

- **DB (expand/contract):** `pos_order_lines.unit_price` → `unit_price_incl_tax` (preserve `decimal(15,4)` scale).
- **DTO (`apps/api`):** `POS/Application/DTOs/OrderLineData.php` (+ regenerated TS types).
- **POS device (`apps/pos`):** `src/types/cart.ts` (`CartItem.unit_price`), `src/stores/cartStore.ts` (`computeTaxAmount`, `recalcLineTotal`), POS UI (`features/pos/pages/POSPage`).
- **Fiscal-byte impact:** none — the canonical builder still emits the v1 key `unit_price` until 2b. The cart's internal field name and the canonical payload key are intentionally **decoupled** here (the builder maps the renamed cart field onto the still-v1 payload key). This is the only intentional name-mismatch window; it is documented and removed by 2b.

### Phase 2b — Canonical `SALE_RECEIPT` v2 → `unit_price_incl_tax` (HIGH RISK — signed bytes) — **OPTIONAL / DEFERRED**

> **Gated on explicit owner sign-off.** Phases 1 + 2a already remove the overload from every editable surface. 2b only renames the key *inside the signed canonical payload*, which is the irreversible, chain-affecting part. Defer until the owner decides the cosmetic consistency is worth a versioned fiscal event + fleet cutover.

- **Versioned event, not a mutation:** register `SALE_RECEIPT` **v2** in both registries — `apps/pos/.../FiscalEventPayloadRegistry.ts` and `apps/api/.../FiscalEventPayloadRegistry.php` (`event_version = 2`). v1 DTO (`SaleReceiptPayload`, `LineItemDTO`), parser, and validator remain intact and reachable.
- **Device authors / server re-hashes (preserved):** server continues to verify stored v1 `canonical_bytes` (old `unit_price` key) byte-for-byte and v2 bytes (`unit_price_incl_tax` key) byte-for-byte. **No re-serialization, no recompute, ever.** `canonical_bytes` stays the source of truth for the hash.
- **Per-terminal cutover via `fiscal_schema_version`:** a terminal authors v2 **only** once its `pos_terminals.fiscal_schema_version` is bumped (column already exists, default 2). Mixed-version fleet supported indefinitely; v1 and v2 events coexist in the same chain. **See `fiscal_schema_version` note (§6).**
- **Full fixture regeneration:** generate **new** v2 golden vectors (TND-3dp + EUR-2dp) from the v2 builder; **freeze** v1 golden vectors as permanent regression anchors. Update cross-language canonical-parity tests to assert both (a) v1 fixtures still verify with the v1 path, and (b) v2 device bytes == v2 PHP bytes.
- **v2-aware readers:** `SaleReceiptCanonicalView`, `LineItemDTO`, `CanonicalPayloadReader`, `Nf525DataProvider`, `FiscalPayloadConstraintValidator` must read the version and select the correct key.

---

## 5. Cross-cutting deliverables

- **Docs:** update `precision-contract.md` (unit_price section) and CLAUDE.md §19 from "deferred" to the resolved contract, incrementally per phase (after 2a: the overload is gone from editable surfaces but canonical key is still v1; after 2b: fully resolved).
- **REALIGNMENT-LOG:** add an entry — this changes canonical DB column names and (in 2b) the published canonical payload shape; the ERP/platform-integration side must be notified (`docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`).
- **Guards stay green:** PHPStan (`ForbidFloatCastOnDecimalProperty`, `ForbidHardcodedBcmathScale`), ESLint money/quantity rules, Deptrac, Pint — zero new drift.
- **PR-per-phase:** Phase 1, Phase 2a, (optional) Phase 2b each merge independently. 2b carries the owner-sign-off gate.

---

## 6. Owner-flagged notes (carry into the plan)

**Cart classification note.** `Cart/Application/DTOs/CatalogCartItemData.php` carries a nullable suggested/catalog `unit_price`. Its tax semantics are **not** self-evident from the field alone. Default classification is **B2B / net → `unit_price_excl_tax`** (it feeds document lines, not the POS sale path). **Confirm at implementation time:** trace whether this Cart is the B2B quoting/catalog cart (net, Phase 1) or any B2C online-ordering surface (would be inclusive). If it turns out to be a B2C surface, move it to Phase 2a. This is the single highest misclassification risk in the rename.

**`fiscal_schema_version` note.** `pos_terminals.fiscal_schema_version` already exists (added `2026_05_01_000002`, default 2) and is the designated cutover lever for Phase 2b. It is **device schema version**, distinct from `fiscal_events.event_version` (per-event payload version). The cutover contract: a terminal authors `SALE_RECEIPT` v2 only after its `fiscal_schema_version` is bumped; the server accepts both v1 and v2 indefinitely. No flag-day; the fleet migrates terminal-by-terminal. (Note the default-2 value predates this work and tracks an earlier schema bump — Phase 2b must define the exact threshold value that gates v2 authorship rather than assuming the current default.)

**After-T2 note.** Do not start execution until T2 product-variants is merged to `dev` (see §3). T2 rewrites the same line DTOs/services; re-run the surface inventory (§7) against post-T2 `dev` first.

---

## 7. Surface inventory (snapshot — re-verify post-T2)

> Captured 2026-06-03 against `origin/dev`. **Re-run before execution** — T2 and other work may shift these.

**B2B / net (Phase 1):**
- DTOs: `DocumentLineData`, `BundleExpansionLineData`, `WorkOrderLineData`, `ServiceBundleComponentData` (`override_unit_price`), `CatalogCartItemData` (verify).
- DB: `document_lines.unit_price` `decimal(15,2)` (migration `2025_11_30_080001_create_document_lines_table`).
- Events: `DraftLineAdded`, `DraftLineAddedV2`, `DraftLineModifiedV2`.
- Services: `DocumentTotalsCalculator`, `DraftPersistenceService`, `CopiesDocumentData` + 3 converters, `CreditNoteService`, `RefundService`, `FacturXService`.
- Requests/controllers: `UpdateDocumentRequest`, `RefundController`, `QuoteController`.
- Web: `generated.d.ts` (auto), `apps/web/src/types/document.ts`, `DocumentLineEditor.tsx`, quote/invoice/PO detail pages, `CreateCreditNotePage`, `CreateReturnNotePage`, `PurchaseOrderLandedCostBreakdown`, `GoodsReceiptListPage`, fixtures + e2e.

**B2C / incl, non-fiscal (Phase 2a):**
- DB: `pos_order_lines.unit_price` `decimal(15,4)`.
- DTO: `OrderLineData`.
- POS: `apps/pos/src/types/cart.ts`, `apps/pos/src/stores/cartStore.ts`, `features/pos/pages/POSPage`.

**B2C / incl, canonical signed (Phase 2b — optional):**
- TS builder: `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts`, `FiscalEventEngine.ts` (`LineItemInput.unit_price`), `FiscalEventPayloadRegistry.ts`.
- PHP: `Fiscal/Domain/DTOs/SaleReceiptPayload.php`, `Canonical/LineItemDTO.php`, `Canonical/SaleReceiptCanonicalView.php`, `CanonicalPayloadReader.php`, `FiscalEventPayloadRegistry.php`, `FiscalPayloadConstraintValidator.php`, `POS/Application/Services/Nf525DataProvider.php`.
- Fixtures: `apps/api/tests/Fixtures/Fiscal/canonical-golden-vectors.json` + `GoldenFixtureBuilder`; parity tests in `apps/pos/src/lib/fiscal/__tests__/*CanonicalParity.test.ts` and `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php`.
- Confirmed: canonical `line_items[]` belongs **only** to `SALE_RECEIPT` (no B2B invoice fiscal event shares `LineItemDTO`), so the canonical rename is unambiguously inclusive.

---

## 8. Testing strategy

TDD throughout (red → green → refactor); follow the existing backend PHPUnit + frontend Vitest conventions and real-seeder discipline.

- **Phase 1 / 2a:** existing suites must stay green after the rename; add expand/contract migration tests (add column, backfill correctness, dual-write equivalence, post-drop reads). Web type-check + ESLint + e2e.
- **Phase 2b (if executed):**
  1. **Red:** v1 regression test — a stored v1 fixture (old `unit_price` key) still verifies via the v1 path (must never break).
  2. **Red:** v2 cross-language parity — device-built v2 bytes == PHP-built v2 bytes, byte-for-byte.
  3. **Red:** semantic assertion — v2 payload carries `unit_price_incl_tax`, v1 carries `unit_price`; registry routes by `event_version`.
  4. Then implement the v2 builder/DTO/registry until green.

---

## 9. Risks & mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| Breaking an existing v1 fiscal chain | Critical | Freeze v1 path + v1 golden vectors as permanent regression anchors; server only ever re-hashes stored bytes. |
| Device/server drift on v2 bytes | High | Cross-language canonical-parity test is a merge gate for 2b. |
| Cart misclassified (net vs incl) | Medium | Explicit confirm-at-impl note (§6); default net, move to 2a if proven B2C. |
| Expand/contract dual-write divergence across tenant DBs | Medium | Dual-write equivalence test; drop column only in a separate, later migration after reads are switched and verified. |
| Conflict with in-flight T2 variants work | Medium | Hard sequencing gate: execute only after T2 merges; re-inventory (§7). |
| Stale name-mismatch window (cart renamed, canonical still v1) in 2a | Low | Documented decoupling; builder maps renamed cart field → v1 key; window closes at 2b. |

---

## 10. Open items to resolve in the plan / at execution

1. Confirm Cart (`CatalogCartItemData`) tax semantics (§6).
2. Re-run surface inventory against post-T2 `dev` (§3, §7).
3. Define the exact `fiscal_schema_version` threshold that gates v2 authorship (§6) — only if/when 2b is greenlit.
4. Owner decision on whether/when to execute Phase 2b at all.
