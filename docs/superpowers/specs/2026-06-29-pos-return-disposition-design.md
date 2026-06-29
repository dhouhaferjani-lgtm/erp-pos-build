# POS Return Disposition + Refund Fiscal-Chain Reconciliation — Design Spec

> **Date:** 2026-06-29
> **Status:** Design — model approved by owner; pending spec review → writing-plans
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
- **Void ≠ Return.** `SALE_VOID` = same-session annulation of an already-*signed* sale (no money moved, goods never left → no stock effect). `REFUND_RECEIPT` = reversal of a *settled* sale (money moves, per-line disposition). An abandoned cart that was never finalized is not a fiscal event (operational/JET log at most, for fraud signal). Do **not** model void as "a return with a reason".
- **Money refund ⟂ physical disposition.** Validated across Square (per-line restock toggle), Shopify ("Restock items" checkbox, default on), and Microsoft Dynamics 365 (return is either *Physical return* or *Credit only*; disposition codes `Credit`/`Scrap`/`Credit only`/`Return to vendor`/`Quarantine`). Never silently restock on a money refund.
- **Two orthogonal facts → three POS outcomes:** physical receipt `{received | not_received}` × resalability `{resellable | not}` (resalability only meaningful when received).
- **Two-movement scrap is the standard** (Dynamics, Odoo): receive back at original cost (`+qty`) then write-off to scrap/shrinkage (`−qty`). Traceable, WAC-correct; the loss lands in a dedicated returns-shrinkage account (not buried in COGS).
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

### 2.2 Inventory movement model

- **`RESTOCK`** → one `MovementType::Receipt` / `MovementReason::POSReturn`, `+qty` to the sellable `stock_levels` row (variant-aware, same grain the sale decremented), restoring at original cost. Batch allocations restored (existing `restoreBatchAllocations`).
- **`SCRAP`** → **two movements** (owner-approved, traceability):
  1. `MovementType::Receipt` / `MovementReason::POSReturn`, `+qty` (goods physically received back).
  2. `MovementType::Adjustment` / `MovementReason::WriteOff`, `−qty` (scrapped). Net sellable change = 0 relative to before the return, i.e. the sold-then-scrapped unit stays out of sellable (the loss).
- **`NOT_RECEIVED`** → **zero** stock movements (nothing came back). The cost is an unrecoverable loss booked downstream (GL phase); no inventory movement.
- **`QUARANTINE` / `DESTROY` / `RETURN_TO_VENDOR`** (Phase 2) → `+qty` into a non-sellable location / scrap + record; defined in the Phase-2 section.

All movements carry `lot/batch` via the existing batch-allocation restore. Scrap/destroy write-off legs post (Phase: accounting-gl) to a **dedicated returns-shrinkage account**, not COGS.

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

A `RestockPolicyResolver` domain service returns the effective policy + provenance (which level supplied it), constructor-injected (no `app()`), tenant-scoped. **Phase 0 builds and tests this resolver but does not yet enforce it** — the per-line disposition is honored as supplied by the caller (default `RESTOCK`), keeping Phase 0 fully additive. Phase 1 wires the resolver into UI defaults and the `never ⇒ no RESTOCK` guard.

---

## 4. Cashier UX (target — Phase 1 UI)

One question per returned line, smart-defaulted from the resolved `restock_policy`:

> **Item returned to store?** → `Yes — resellable` · `Yes — damaged/opened` · `No (kept by customer)`

The money refund proceeds regardless of the answer (money ⟂ disposition). Reason code (`ReturnReason`) is still captured and may override the default (e.g. `Defective` ⇒ never auto-restock). The selector lands in the redesigned refund modal (coordinate with the in-flight `pos-caisse-redesign`). The cashier never has authority to restock an opened health-good when policy is `if_sealed`/`never` — those route to scrap/quarantine.

---

## 5. Umbrella migration — refund flow onto device-authored fiscal events

(Specified here for completeness; implemented in Phase 3, reusing the disposition enum + stock logic. Preserves the 2026-04-28 design intent.)

- **`REFUND_RECEIPT` / `SALE_VOID` device payload builders** in `FiscalEventEngine` (offline-first), added to `IMPLEMENTED_EVENT_TYPES` + registry with key-drift gates; `FiscalPayloadConstraintValidator` rules; `isImplemented() = true`. `REFUND_RECEIPT` references the original, carries refunded line subset (full or partial — `PARTIAL_REFUND` stays reserved), refund payments, reason, **and per-line disposition**. `SALE_VOID` references the original, reason, no payments, no disposition.
- **"One UX, two fiscal documents"** exchange decomposition (credit note + new sale receipt joined by `exchange_group_id`, committed in the hash) is preserved via the existing `ExchangeService`, re-platformed onto the two device-authored events.
- **Offline lookup** — on local sale authoring, also write a local lookup-index row so the device's own receipts are findable offline (fixes the root cause). The locator resolves local-authored + synced receipts; unresolvable-and-offline → honest block.
- **Server projection** for the reversal events (sibling of `PosCoreReceiptProjection`) — writes the reversal `pos_receipts` row (append-only, never hard-delete), money via the existing refund machinery, and **disposition-gated stock** (the §2.2 model). `SALE_VOID` → full restock of all lines (goods never left), money nets zero.
- **Cash reconciliation** — route refund cash-out through the fiscal cash path (Z/session) instead of the parallel non-fiscal `cash_drawer_operations` table.
- **Retire** the legacy server-side return/void seal path once the projection path is authoritative.

---

## 6. Implementation phasing

Each phase is its own implementation plan (TDD throughout).

- **Phase 0 — disposition core (FIRST SLICE, this effort):**
  - `ReturnLineDisposition` enum (`RESTOCK | SCRAP | NOT_RECEIVED`).
  - Per-line disposition threaded through `ReceiptReturnService::processReturn` (return-line input → validation → stock step), default `RESTOCK`.
  - Two-movement `SCRAP`; `NOT_RECEIVED` no-op; `RESTOCK` unchanged. Variant- and batch-aware, scale-4 bcmath, no `CompanyContext` regressions.
  - `RestockPolicy` enum + nullable `products.restock_policy` / `categories.restock_policy` + `Company.reservation_settings.default_restock_policy` (seeded) + `RestockPolicyResolver` (hierarchy + provenance), **built and tested but not yet enforcing** (additive).
- **Phase 1 — refund-modal disposition selector** (`apps/pos`), coordinated with `pos-caisse-redesign`; wires `RestockPolicyResolver` into the cashier's pre-filled default and the `never ⇒ no RESTOCK` backend guard.
- **Phase 2 — `QUARANTINE | DESTROY | RETURN_TO_VENDOR`** states + non-sellable location + back-office reclassification + lot-aware destruction records.
- **Phase 3 — fiscal-event migration** (`REFUND_RECEIPT` / `SALE_VOID` device authoring + offline lookup + projection with disposition) — the umbrella.
- **Phase 4 — cash reconciliation + retire legacy server return/void services.**
- **GL** — returns-shrinkage / write-off postings tracked in the accounting-gl roadmap (out of scope here; movements are GL-ready).

---

## 7. NF525 / compliance posture

Refunds, voids (annulations), and closures stay in the signed chain, append-only, never hard-deleted. Cash float / cash-in-out unchanged (already events; legally operational). Abandoned carts → operational/JET, not chained. The disposition model adds inventory semantics only; it does not weaken any fiscal invariant.

---

## 8. Testing strategy

TDD throughout. Phase 0 PHP feature tests (run by path, never the full suite):

- Disposition matrix on `ReceiptReturnService`: `RESTOCK` (current behavior preserved), `SCRAP` (two movements: `+qty` receipt then `−qty` write-off; sellable nets down), `NOT_RECEIVED` (zero movements).
- Default `RESTOCK` when unspecified (backward compat — existing return tests stay green).
- Variant- and batch-aware restock/scrap (batch allocations restored only on the receive leg).
- Precision: fractional qty stays `decimal(4)` bcmath, no float drift.
- `RestockPolicyResolver`: product wins over category wins over parent-category wins over tenant default; provenance reported; null-inherit chain.

Phase 1+ add device/Vitest + Playwright E2E (offline return) tests.

---

## 9. Risks / dependencies

- **`pos-caisse-redesign`** owns the refund-modal surface (Phase 1 coordination).
- **Margin-override-hierarchy** resolver pattern is the reference for `RestockPolicyResolver`.
- **Batch-allocation restore** (`restoreBatchAllocations`) is reused for the receive leg.
- **accounting-gl roadmap** owns the shrinkage/write-off GL posting.
- The superseded `fix/pos-refund-void-restock` branch (blanket projection restock) is **replaced** by this design; its restock plumbing informs the Phase-3 projection but is not merged standalone.
