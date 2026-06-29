# POS Return Disposition + Refund Fiscal-Chain Reconciliation — Design Spec

> **Date:** 2026-06-29
> **Status:** Design — model approved by owner; **adversarial reviews folded (Codex + Opus, 2026-06-29)**; pending owner ratification of the refund-model reconciliation (§5) → writing-plans
> **Reviews:** [`2026-06-29-pos-return-disposition-codex-review.md`](../reviews/2026-06-29-pos-return-disposition-codex-review.md), [`2026-06-29-pos-return-disposition-opus-review.md`](../reviews/2026-06-29-pos-return-disposition-opus-review.md). Opus verdict: *design sound, Phase 0 plannable* with the 8 edits below folded in. **Key reversal:** the refund fiscal model is reconciled to the code-committed `SALE_RECEIPT + invoice_type_code='REFUND'/'VOID'` axis (§5), dropping the earlier "separate `REFUND_RECEIPT`/`SALE_VOID` event types" lean.
> **Builds on:** [`docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md`](./2026-04-28-pos-refund-flow-design.md) (the original refund / exchange / voucher design — "one UX, two fiscal documents")
> **Scope:** `apps/api` (Inventory + `ReceiptReturnService`), `apps/pos` (refund flow UI — later phase), `apps/web` (back-office — later phase)
> **Out of scope:** GL postings (tracked in the accounting-gl roadmap); the device-authored fiscal-event migration is specified here as the umbrella but implemented in a later phase.

---

## 0. Why this exists

Two gaps surfaced while looking at POS returns for the parapharmacy launch:

1. **Returns don't work offline.** The "Retours / Échange" lookup (`ReceiptLocatorScreen`) queries only the local `receipt_qr_index` SQLite table, which is **server-pull-only** (`syncService.pullReceiptQrIndex` → `GET /pos/receipts/qr-index`). Offline (or before a sale has round-tripped through the server), the index has no row → "Aucun reçu trouvé". Settlement is also online-only (`refundSettlementService` — "ONLINE-ONLY refund settlement"). So an offline pharmacy cannot return anything.
2. **Returns always restock.** `ReceiptReturnService::restoreStock` adds every returned line back to sellable stock unconditionally. There is no notion of **disposition** — a damaged/opened item that can't be resold, a consumed item (a coffee), or a refund where the customer keeps the goods all get silently restocked, creating phantom sellable inventory. For a parapharmacy this is a real correctness problem (opened cosmetics/health goods are non-resellable).

### Architectural context (the "legacy" clarification)

The refund/exchange/voucher flow was designed and largely **built** on 2026-04-28 (the spec referenced above) on the **legacy inline `pos_receipts` v3 hash-chain** (`ReceiptFinalizationService`, `pending_seal`). The later fiscal-event-engine work migrated **sales** onto **device-authored `fiscal_events`** (offline-first, the device is the fiscal source of truth) — but **left returns/voids on the legacy chain**. `ReceiptReturnService` / `ReceiptVoidService` are therefore **live-but-not-migrated**, not deprecated: they are the only implementation of returns/voids today, authored server-side, online-only, onto the old chain. The reserved fiscal-event types `REFUND_RECEIPT`, `SALE_VOID`, `PARTIAL_REFUND` exist in the enum but are **not implemented** on either side.

This spec covers **(A)** the umbrella reconciliation — migrate the refund flow onto device-authored fiscal events — and **(B)** the **return disposition model**, which is the genuinely new delta (the 2026-04-28 spec always restocks). **Disposition is built first on the live path**; the fiscal-event migration follows in a later phase, reusing the same enum and stock logic.

---

## 1. Findings that anchor the design

Sourced research (full briefs in session history):

- **NF525 (France):** refunds and **annulations/cancellations must be chained, append-only, never hard-deleted**; the signed perimeter is *règlement data* (sales, payment legs, refunds, voids, closures, duplicata). **Cash float / cash-in-out are NOT legally required in the signed chain** — operational (*tenue de caisse*), logged for our own fraud detection/ML and surfaced on the Z (already device-authored as fiscal events today, which is more than required — fine).
- **Void ≠ Return.** A **void** annuls a sale; a **refund/return** reverses a *settled* sale (money moves, per-line disposition). Fiscally, both are carried by `invoice_type_code` on a `SALE_RECEIPT` (`VOID` / `REFUND`) + `original_receipt_reference` — see §5; do **not** model void as "a return with a reason," and do **not** introduce separate event types. **Stock:** a void of a *signed* sale **re-increments** to reverse the sale's decrement (goods never left, money nets zero); a void of an *abandoned/never-finalized* cart has no stock effect (nothing was decremented). See §5.2.
- **Money refund ⟂ physical disposition.** Validated across Square (per-line restock toggle), Shopify ("Restock items" checkbox, default on), and Microsoft Dynamics 365 (return is either *Physical return* or *Credit only*; disposition codes `Credit`/`Scrap`/`Credit only`/`Return to vendor`/`Quarantine`). Never silently restock on a money refund.
- **Two orthogonal facts → three POS outcomes:** physical receipt `{received | not_received}` × resalability `{resellable | not}` (resalability only meaningful when received).
- **Two-movement scrap is the standard** (Dynamics, Odoo): receive back (`+qty`) then write-off to scrap/shrinkage (`−qty`) — traceable; the loss lands in a dedicated returns-shrinkage account (not buried in COGS). *(In Phase 0 these are **quantity-only** movements — see §2.2; WAC/cost integration is deferred to the GL phase, NOT wired via `recordReturn`, which would corrupt WAC on net-zero scrap.)*
- **Hybrid sync/deferred by product risk:** generic retail → cashier decides at the POS; opened cosmetics/hygiene/health → cashier captures *received + condition*, system defaults to quarantine/scrap, **back-office/QA finalizes** (the cashier is never the sole gate for reselling opened health goods). **Pharmacy:** dispensed medicines are **destroy-only, never restock** (FR Code de la santé publique L4211-2 / Cyclamed); opened cosmetics non-resellable (EU CRD Art. 16(e)); sealed generic restockable.

---

## 2. Domain model — return disposition

### 2.1 Two orthogonal facts + a derived/selected disposition

Each **return line** records two raw facts plus the resulting disposition:

- `physical_receipt: bool` — did the goods come back into the store?
- `resalable: bool | null` — only meaningful when received; null when `physical_receipt = false`.
- `ReturnLineDisposition` (enum) — the operational outcome, a function of the two facts + product `restock_policy`:

| Disposition | Phase | Facts | Stock effect |
|---|---|---|---|
| `RESTOCK` | 0 (first slice) | received + resalable | `+qty` to sellable |
| `SCRAP` | 0 (first slice) | received + not resalable | `+qty` receive **then** `−qty` write-off |
| `NOT_RECEIVED` | 0 (first slice) | not received (keep-it) | no movement |
| `QUARANTINE` | 2 (target) | received, decide later | `+qty` to a non-sellable location; back-office reclassifies |
| `DESTROY` | 2 (target) | received, regulated | like `SCRAP` + destruction record (medicines) |
| `RETURN_TO_VENDOR` | 2 (target) | received, supplier defect | non-sellable; RTV credit (back-office) |

Storing the two raw facts alongside the derived disposition keeps it **auditable as a function of facts + policy** and lets back-office perform legal state transitions later (`QUARANTINE → RESTOCK | SCRAP | RETURN_TO_VENDOR`).

**Input contract (Phase 0):**
- Disposition is stored **per return-receipt line** (each return event), NOT per original line — so sequential partial returns of the same original line may carry **mixed dispositions** (e.g. sold qty 3 → 1×`RESTOCK` then 2×`SCRAP`). The existing cumulative "already-returned" cap (`calculateAlreadyReturnedQuantities`) stays **disposition-independent** (it caps quantity only). (Opus P2-6.)
- **Illegal-combination validation** (reject at the request boundary): `physical_receipt = false` ⇒ `resalable` MUST be null and disposition MUST be `NOT_RECEIVED`; `NOT_RECEIVED` ⇒ no batch restitution; `RESTOCK` ⇒ `physical_receipt = true ∧ resalable = true`.
- **Fail-closed default:** when `restock_policy` resolves to `never` (or is genuinely unresolvable for a regulated SKU), the service **rejects `RESTOCK`** — it never silently restocks regulated goods (see §3 + the Phase-0 guard).

### 2.2 Inventory movement model

> **Phase 0 is quantity-only.** These movements update `stock_levels.quantity` (and `inventory_batch_stock` per the batch rules below) but do **not** write cost-ledger fields and do **not** call `WeightedAverageCostService::recordReturn`. This matches the *current* return path, which is already quantity-only (`restoreStock` writes no `unit_cost`/`avg_cost_*`). Wiring `recordReturn` would actively **corrupt** WAC on a net-zero SCRAP (it would blend the returned unit into the average that the write-off then has to un-blend); leaving remaining-inventory WAC unchanged is correct. Valuation / shrinkage loss is recognized downstream in the **GL phase** (accounting-gl roadmap), posting to a dedicated returns-shrinkage account, not COGS. (Codex+Opus 2026-06-29.)

- **`RESTOCK`** → one `MovementType::Receipt` / `MovementReason::POSReturn`, `+qty` to the sellable `stock_levels` row (variant-aware, same grain the sale decremented). Batch allocations restored (existing `restoreBatchAllocations`). *(No cost re-blend; the receive is valued at current `products.cost_price` implicitly — see the quantity-only note. This is the existing behavior, preserved.)*
- **`SCRAP`** → **two movements** (owner-approved, traceability):
  1. `MovementType::Receipt` / `MovementReason::POSReturn`, `+qty` (goods physically received back).
  2. `MovementType::Adjustment` / `MovementReason::WriteOff`, `−qty` (scrapped). Net sellable change = 0 relative to before the return — the sold-then-scrapped unit stays out of sellable (the loss).
  - **Batch rule:** SCRAP **must NOT** run `restoreBatchAllocations` (the goods are scrapped, never re-enter a sellable batch). Restoring batch stock on the receive leg while the aggregate write-off has no batch dimension would inflate `inventory_batch_stock` → phantom FEFO stock. The receive leg restores aggregate `stock_levels` only; the write-off leg nets it back out. (Opus P2-3.)
- **`NOT_RECEIVED`** → **zero** stock movements (nothing came back). Branches off **both** `restoreStock` **and** `restoreBatchAllocations`. The unrecoverable cost is a GL-phase loss; no inventory movement. (Opus P2-5.)
- **`QUARANTINE` / `DESTROY` / `RETURN_TO_VENDOR`** (Phase 2) → `+qty` into a non-sellable location / scrap + record; defined in the Phase-2 section.

Both the aggregate (`restoreStock`) and batch (`restoreBatchAllocations`) paths are **disposition-branched** — disposition gates both, not just the aggregate path.

### 2.3 Default & backward compatibility

`ReturnLineDisposition` defaults to **`RESTOCK`** when a caller does not specify it, so every existing return path and test keeps today's behavior unchanged. New per-line disposition is additive.

---

## 3. `restock_policy` resolution hierarchy

A nullable attribute that drives the cashier's **default** disposition; it does not remove cashier choice.

- **Enum** `RestockPolicy`: `never | if_sealed | default_allow`.
- **Columns** (nullable, `null = inherit`): `products.restock_policy`, `categories.restock_policy`.
- **Resolution order** (first non-null wins): `product.restock_policy` → walk `category.parent` chain (categories are a self-referencing tree via `parent_id`) → **tenant default** in `Company.reservation_settings.default_restock_policy` (seeded, vertical-aware: parapharmacy → `if_sealed`, generic retail → `default_allow`). Mirrors the margin-override-hierarchy resolver (per-field provenance, same shape).
- **Effect** (the resolved policy drives the cashier's pre-filled default and a backend guard — **wired in Phase 1**, when the UI supplies the sealed-vs-opened signal):
  - `never` (medicines) → default `DESTROY` (Phase 2) / `SCRAP` (Phase 0 fallback); cashier cannot select `RESTOCK` (backend guard rejects it).
  - `if_sealed` (cosmetics/hygiene) → received + sealed → `RESTOCK`; received + opened → `SCRAP` (Phase 0) / `QUARANTINE` (Phase 2).
  - `default_allow` (generic) → received → `RESTOCK`.

A `RestockPolicyResolver` domain service returns the effective policy + provenance (which level supplied it), constructor-injected (no `app()`), tenant-scoped.

**Phase-0 enforcement split (revised per Opus P1(e) — the launch is parapharmacy, a regulated tenant):**
- The **hard `never ⇒ no RESTOCK` backend guard ships in Phase 0.** It needs only the *resolved policy* (no sealed/opened UI signal), so it can and must land with disposition acceptance — otherwise a caller could restock a medicine before the UI exists. The service rejects a `RESTOCK` disposition when the resolved policy is `never`.
- The **softer behavior stays deferred to Phase 1** (when the UI supplies sealed-vs-opened): the pre-filled cashier default and the `if_sealed` opened→scrap routing.
- For generic-retail tenants this is fully additive (default `RESTOCK`, no `never` SKUs). For regulated tenants the guard is the safety floor.

---

## 4. Cashier UX (target — Phase 1 UI)

One question per returned line, smart-defaulted from the resolved `restock_policy`:

> **Item returned to store?** → `Yes — resellable` · `Yes — damaged/opened` · `No (kept by customer)`

The money refund proceeds regardless of the answer (money ⟂ disposition). Reason code (`ReturnReason`) is still captured and may override the default (e.g. `Defective` ⇒ never auto-restock). The selector lands in the redesigned refund modal (coordinate with the in-flight `pos-caisse-redesign`). The cashier never has authority to restock an opened health-good when policy is `if_sealed`/`never` — those route to scrap/quarantine.

---

## 5. Umbrella migration — refund flow onto device-authored fiscal events

(Specified here for completeness; implemented in Phase 3, reusing the disposition enum + stock logic. Preserves the 2026-04-28 design intent.)

> **⚠ Refund fiscal model — RECONCILED to the code-committed model (Opus P2-1, 2026-06-29; reverses the earlier "two distinct event types" lean).** The codebase has **already chosen** how a refund/void is represented fiscally, and it is **NOT** separate `REFUND_RECEIPT`/`SALE_VOID` event types. A refund/void is a **`SALE_RECEIPT` fiscal event carrying `invoice_type_code = 'REFUND' | 'VOID'` + `original_receipt_reference`**:
> - `FiscalPayloadConstraintValidator::INVOICE_TYPE_CODES = ['SALE','REFUND','VOID','TRAINING']`; it enforces `invoice_type_code ∈ {REFUND,VOID} ⇒ original_receipt_reference required`, and states verbatim: *"Refunds are modeled via `invoice_type_code='REFUND'` + `original_receipt_reference`, NOT negative amounts."*
> - The device payload (`SaleReceiptPayloadInput.invoice_type_code`) and the server projection (`PosCoreReceiptProjection::resolveReceiptType` → `ReceiptType::Return`, with a fail-closed gate on an unresolvable original) already implement it.
> - The reserved `REFUND_RECEIPT` / `SALE_VOID` / `PARTIAL_REFUND` enum cases are **vestigial** — keep them reserved/unimplemented; do **not** build a second competing model. **OPEN RATIFICATION** for the POS orchestrator: confirm we standardize on the `invoice_type_code` axis (recommended — built, validated, projected) and formally retire the separate-event-type idea.

Therefore the Phase-3 migration is **not** "add new event types"; it is:

- **Extend the canonical `SALE_RECEIPT` refund payload** — add the **refunded-line subset** (original line ids + returned quantities) and **per-line disposition** as new keys (on `original_receipt_reference` or a sibling refund block), since `OriginalReceiptReferenceInput` today carries only `fiscal_event_id / original_business_date / original_receipt_uuid / refund_reason` (no line subset, no disposition). Add validator rules + key-drift gates + golden canonical-byte/hash tests proving disposition changes the hash.
- **Fix the projection stock hazard (see §5.1)** — `PosCoreReceiptProjection` currently decrements stock unconditionally even for REFUND/VOID; it must disposition-gate (restock/scrap/no-op) and a `SALE_VOID` of a *signed* sale must **re-increment** to reverse the sale's decrement.
- **"One UX, two fiscal documents"** exchange decomposition (credit note + new sale, joined by `exchange_group_id`, committed in the hash) preserved via the existing `ExchangeService`.
- **Offline lookup** — on local sale authoring, also write a local lookup-index row so the device's own receipts are findable offline (fixes the root cause). The locator resolves local-authored + synced receipts; unresolvable-and-offline → honest block.
- **Cash reconciliation** — route refund cash-out through the fiscal cash path (Z/session) instead of the parallel non-fiscal `cash_drawer_operations` table.
- **Retire** the legacy server-side `ReceiptReturnService`/`ReceiptVoidService` seal path once the projection path is authoritative. **Coexistence:** existing legacy return receipts (`fiscal_event_id IS NULL`) remain verified by the legacy chain arm; no rewrite of old chain rows — dual-arm verification continues. Define rollback for partially-deployed clients.

### 5.1 Live latent hazard — projection decrements stock for refunds (Opus P2-2)

`PosCoreReceiptProjection::apply()` maps `invoice_type_code ∈ {REFUND,VOID}` to `ReceiptType::Return` but then calls `decrementStockForLines()` **unconditionally** — `decrementStock` has **no receipt-type branch**. The REFUND/VOID-via-`SALE_RECEIPT` path is **already accepted server-side** (validator + projection), so a device-authored refund through it would **decrement** stock (compounding the loss) instead of restoring. The device does not author refunds *today* (only `SALE`/`TRAINING`), so it is **latent** — but it is a live landmine for Phase 3. **Add a guard + regression test asserting the projection does NOT decrement for `invoice_type_code ∈ {REFUND,VOID}`** (candidate Phase-0/fast-follow, since the path is server-live); the disposition-gated restock/scrap/no-op branch (inside the `fiscal_event_id` `INSERT … ON CONFLICT DO NOTHING` idempotency guard) is the Phase-3 deliverable. *(This is exactly the trap the superseded `fix/pos-refund-void-restock` branch addressed; its restock plumbing informs this branch but must be made disposition-aware.)*

### 5.2 `SALE_VOID` stock effect (resolves the §1/§5 contradiction — Opus P2-4)

A void of an **unsealed/abandoned cart** (never finalized, never projected) has **no stock effect** (no decrement happened). A void of an **already-signed sale** must **re-increment** all lines to reverse the sale's projection-time decrement (money nets zero, goods never left). The earlier blanket "no stock effect" wording referred only to the abandoned-cart case.

---

## 6. Implementation phasing

Each phase is its own implementation plan (TDD throughout).

- **Phase 0 — disposition core (FIRST SLICE, this effort):**
  - `ReturnLineDisposition` enum (`RESTOCK | SCRAP | NOT_RECEIVED`) + the `physical_receipt` / `resalable` raw facts, stored **per return-receipt line**.
  - Per-line disposition + the input contract (illegal-combination validation, §2.1) threaded through `ReceiptReturnService::processReturn` (return-line input → validation → stock step), default `RESTOCK`.
  - **Quantity-only** stock model (§2.2): two-movement `SCRAP` (skips batch restitution); `NOT_RECEIVED` skips **both** stock paths; `RESTOCK` unchanged. Both `restoreStock` and `restoreBatchAllocations` disposition-branched. Variant-aware, scale-4 bcmath, no `CompanyContext` regressions, receipt-level idempotency inherited from `refund_request_id`.
  - `RestockPolicy` enum + nullable `products.restock_policy` / `categories.restock_policy` + `Company.reservation_settings.default_restock_policy` (seeded, reversible migration + backfill, casts, shared types) + `RestockPolicyResolver` (hierarchy + provenance).
  - **`never ⇒ no RESTOCK` backend guard SHIPS in Phase 0** (regulated-goods floor; needs only the resolved policy). The *softer* resolver behavior (UI default, `if_sealed` opened→scrap routing) stays Phase 1.
  - **Projection-decrement guard (fast-follow within this effort, §5.1):** `PosCoreReceiptProjection` must not decrement stock for `invoice_type_code ∈ {REFUND,VOID}` — add the guard + regression test now (the path is server-live), even though full disposition-gated projection is Phase 3.
- **Phase 1 — refund-modal disposition selector** (`apps/pos`), coordinated with `pos-caisse-redesign`; wires `RestockPolicyResolver` into the cashier's pre-filled default and the softer `if_sealed` routing.
- **Phase 2 — `QUARANTINE | DESTROY | RETURN_TO_VENDOR`** states + non-sellable location + back-office reclassification (transition table: allowed prev/next, actor, reason, idempotency) + lot-aware destruction records.
- **Phase 3 — fiscal-event migration** — extend the canonical `SALE_RECEIPT` refund payload (`invoice_type_code` model, §5) with refunded-line-subset + per-line disposition; disposition-gated projection stock; offline lookup; golden hash tests. **Not** new event types.
- **Phase 4 — cash reconciliation + retire legacy server return/void services** (with the dual-arm coexistence plan, §5).
- **GL** — returns-shrinkage / write-off postings tracked in the accounting-gl roadmap (out of scope here; movements are GL-ready, not GL-wired).

---

## 7. NF525 / compliance posture

Refunds, voids (annulations), and closures stay in the signed chain, append-only, never hard-deleted. Cash float / cash-in-out unchanged (already events; legally operational). Abandoned carts → operational/JET, not chained.

**Disposition is an operational inventory fact (*tenue de caisse*), NOT *règlement* data, and is intentionally OUTSIDE the signed fiscal perimeter in Phase 0** (Opus P1(d) — corrects the earlier "does not weaken any fiscal invariant" phrasing, which was true only by virtue of disposition being unsigned). Concretely: neither the legacy hash (`ReceiptHashService` binds `receipt_number/posted_at/total/currency/vat_breakdown_hash/payment_methods_hash`) nor the V3 audit block (`authorized_by_user_id/override_reason/out_of_window/policy_trigger/refund_request_id`) binds disposition, `physical_receipt`, `resalable`, or stock-movement ids — so a disposition DB mutation does **not** change the fiscal hash, and is **not claimed** to be tamper-evident in Phase 0. Binding disposition into the signed artifact is a **Phase 3** decision (a new canonical key on the `invoice_type_code` refund payload, with golden hash tests). Phase 0 makes **no compliance claim** for disposition beyond correct inventory accounting.

---

## 8. Testing strategy

TDD throughout. Phase 0 PHP feature tests (run by path, never the full suite):

- Disposition matrix on `ReceiptReturnService`: `RESTOCK` (current behavior preserved, `+qty` sellable), `SCRAP` (two movements `+qty` receipt then `−qty` write-off; sellable nets to before-return; **`inventory_batch_stock` NOT inflated** — batch restitution skipped), `NOT_RECEIVED` (**zero** movements — both `restoreStock` and `restoreBatchAllocations` skipped).
- Default `RESTOCK` when unspecified (backward compat — existing return tests stay green).
- **Input-contract validation:** illegal combos rejected (`physical_receipt=false` with `resalable=true`; `RESTOCK` without received+resalable; batch restitution on `NOT_RECEIVED`).
- **`never ⇒ no RESTOCK` guard:** a return line for a SKU whose resolved `restock_policy = never` is rejected when disposition is `RESTOCK`; allowed for `SCRAP`/`NOT_RECEIVED`.
- **Mixed dispositions across sequential partial returns** of one original line (1×`RESTOCK` then 2×`SCRAP`); cumulative quantity cap stays disposition-independent.
- Variant-aware restock/scrap; precision: fractional qty stays `decimal(4)` bcmath, no float drift; replay (same `refund_request_id`) does not double-write either leg.
- `RestockPolicyResolver`: product wins over category wins over parent-category wins over tenant default; provenance reported; null-inherit chain; fail-closed when unresolved for a regulated SKU.
- **Projection-decrement guard (§5.1):** `PosCoreReceiptProjection` does NOT decrement stock for a `SALE_RECEIPT` with `invoice_type_code ∈ {REFUND,VOID}`.

Phase 1+ add device/Vitest + Playwright E2E (offline return) tests.

---

## 9. Risks / dependencies

- **`pos-caisse-redesign`** owns the refund-modal surface (Phase 1 coordination).
- **Margin-override-hierarchy** resolver pattern is the reference for `RestockPolicyResolver`.
- **Batch-allocation restore** (`restoreBatchAllocations`) is reused for the receive leg.
- **accounting-gl roadmap** owns the shrinkage/write-off GL posting.
- The superseded `fix/pos-refund-void-restock` branch (blanket projection restock) is **replaced** by this design; its restock plumbing informs the Phase-3 projection but is not merged standalone.
