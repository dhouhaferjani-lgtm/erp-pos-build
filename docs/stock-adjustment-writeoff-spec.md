# Stock Adjustment & Write-Off Flow — Audit + Implementation Spec

**Module:** Inventory
**Products:** IziPOS (all verticals), Otospex
**Priority vertical for this work:** Para-pharmacy / pharmacy (lot + expiry write-offs are routine, recurring, and reportable)
**Handoff target:** Claude Code (audit existing → implement gaps)

---

## 0. Context & non-negotiable principles

Read this section before touching code. These rules are already partially implemented elsewhere in the system; this spec extends them to stock adjustments. Do not violate them to make a flow simpler.

1. **Quantity on hand is derived, never entered.** On-hand stock is the running total of the Stock Movements ledger. The cached on-hand column is a performance optimization maintained from movements (compute-then-cache); it is never the source of truth and is never written directly by a UI field.

2. **Two families of stock movement.** Every movement belongs to exactly one:
   - **Document-driven** (the happy path, ~all volume): purchase receipt, sales issue, customer return, supplier return, transfer in/out. Cause = a business document. **Out of scope for this spec** except where adjustments must not duplicate them.
   - **Adjustment** (the controlled exception): movements with no transactional document behind them — opening balance, count correction, damage, expiry, theft/loss, found stock. **This is what this spec covers.**

3. **Adjustments are gated, not free.** An adjustment is never a bare "type a new number." It is a posted movement carrying: a **mandatory restricted reason code**, optional justification document(s), and (where configured) an approval step before posting. The reason code is to an adjustment what the source document is to a document-driven movement.

4. **Posted movements are immutable; corrections are new movements.** Same pattern as the fiscal `CreditNote` model. You never edit or delete a posted adjustment. A mistake is fixed by posting a reversing/correcting movement on top. The opening-balance movement, once confirmed, is locked the same way.

5. **Reconciliation happens through counts, not ad-hoc adjustments.** The Inventory Counting flow already exists and is considered good — **do not modify it**. It is the sanctioned way to make the system match the shelf; it posts count-correction adjustment movements for discrepancies. This spec must integrate with it (shared reason code, shared movement type) but not change it.

6. **Posting is event-first.** Adjustment posting must dispatch the movement event and update the hash chain / ledger synchronously inside the DB transaction, before the cached on-hand is updated. Async side effects (notifications, reports) come after. Use pessimistic locking on the stock row(s) being adjusted to prevent races.

7. **Catalog vs Inventory boundary stays intact.** Nothing in this work should add an editable quantity to the product page. The product page shows on-hand read-only and links out to operations. Adjustments live in the Inventory section.

---

## 1. Phase 1 — AUDIT (do this first, report findings, do not implement yet)

Goal: establish exactly what already exists for adjustments before building. Produce a short written findings report mapped to each checklist item (Exists / Partial / Missing) before starting Phase 2.

### 1.1 Locate the inventory movement domain

```bash
# Find the Inventory module root and its domain layer
find app/Modules/Inventory -type d | sort

# Find the stock movement model / entity and its movement-type enum
grep -rni "StockMovement\|MovementType\|InventoryMovement" app/Modules/Inventory --include="*.php" -l

# Find where on-hand is cached and how it's recomputed from movements
grep -rni "on_hand\|onHand\|quantity_on_hand\|available" app/Modules/Inventory --include="*.php" -l
```

### 1.2 Reason codes — does a restricted, enumerated set exist?

```bash
# Look for an existing reason enum/table for adjustments
grep -rni "AdjustmentReason\|ReasonCode\|reason_code\|adjustment_reason" app --include="*.php"

# Check migrations for any reason column or reason lookup table
grep -rni "reason" database/migrations --include="*.php" -l
```

**Audit questions to answer in the report:**
- Is there a closed enum/lookup of adjustment reasons, or is `reason` a free-text column? (Free text = gap.)
- Is reason currently nullable on adjustment movements? (Nullable = gap; must be mandatory for the adjustment family.)
- Are document-driven movements and adjustments distinguishable by type, or are they mixed?

### 1.3 Justification document upload — does the media link exist?

```bash
# Existing media/attachment system (MinIO → R2, polymorphic collections per memory)
grep -rni "Media\|Attachment\|morphMany\|mediable\|collection" app/Modules --include="*.php" -l | head -40

# Can a stock movement already own attachments?
grep -rni "morphMany\|morphTo" app/Modules/Inventory --include="*.php"
```

**Audit questions:** Can an adjustment movement carry one or more attachments today via the existing polymorphic media system? If the media system exists but isn't wired to movements, that's a wiring gap, not a new subsystem.

### 1.4 Lot / batch / expiry write-off path

```bash
# Batch / lot domain
find app/Modules -type d -iname "*batch*" -o -type d -iname "*lot*" 2>/dev/null
grep -rni "Batch\|Lot\|expiry\|expiration\|FEFO\|FEFO" app/Modules/Inventory --include="*.php" -l

# Are adjustments lot-aware? (i.e. can an adjustment target a specific lot, not just a product)
grep -rni "lot_id\|batch_id" app/Modules/Inventory --include="*.php"
```

**Audit questions:**
- Can a stock movement reference a specific lot/batch? Adjustments for expiry/damage **must** be lot-scoped for lot-tracked products.
- Is there an existing query for "lots expiring before date X" / "expired lots on hand"? (Needed to drive the expiry write-off UI.)

### 1.5 Opening balance

```bash
grep -rni "opening\|OpeningBalance\|initial_stock\|opening_balance" app --include="*.php"
```

**Audit question:** Does opening balance already post as a movement, or is initial stock set some other (direct) way? It must be a movement of the adjustment family, locked after confirmation.

### 1.6 Approval / posting state

```bash
grep -rni "approve\|approval\|posted\|status" app/Modules/Inventory --include="*.php" -l
```

**Audit question:** Do adjustments have a draft → posted lifecycle, and is there any approval hook? (Approval can be config-gated; see §3.4.)

### 1.7 Routes & controllers (confirm conventions)

```bash
# Confirm the Presentation/Controllers + Presentation/routes.php convention is followed in Inventory
find app/Modules/Inventory/Presentation -type f
```

**Deliverable for Phase 1:** a findings table — one row per item above — marked Exists / Partial / Missing, with the file path where it lives (or "n/a"). Stop and surface this before Phase 2.

---

## 2. Phase 2 — Target model (what "correct" looks like)

### 2.1 Movement type taxonomy (adjustment family only)

The adjustment family must contain these types. Document-driven types are listed only so they are NOT re-created here.

| Type | Family | Direction | Justified by | Lot-scoped? | Approval? |
|---|---|---|---|---|---|
| `opening_balance` | adjustment | in | reason = Opening Balance, optional doc | yes if product lot-tracked | one-time, locks after confirm |
| `count_correction` | adjustment | in/out | the Inventory Count (existing flow) | yes if lot-tracked | per count flow (unchanged) |
| `write_off_damage` | adjustment | out | reason = Damage, optional doc | yes if lot-tracked | config-gated |
| `write_off_expiry` | adjustment | out | reason = Expiry, **lot required** | **yes (mandatory)** | config-gated |
| `write_off_loss` | adjustment | out | reason = Theft/Loss, optional doc | yes if lot-tracked | config-gated |
| `found_stock` | adjustment | in | reason = Found Stock, optional doc | yes if lot-tracked | config-gated |
| *(document-driven — DO NOT build here)* | — | — | purchase/sale/return/transfer docs | — | — |

> Implementation note: "write-off" types share one flow and differ only by reason code + direction. Implement one adjustment posting service that takes a reason code; don't fork the code per reason.

### 2.2 Reason codes — restricted set

Reason must be a **closed enum / lookup table**, not free text. Seed set (extendable per tenant later, but seeded centrally):

```
OPENING_BALANCE   (in)   — onboarding only
COUNT_CORRECTION  (in/out)— written by the count flow only, not user-selectable in the generic adjustment screen
DAMAGE            (out)
EXPIRY            (out)   — forces lot selection
THEFT_LOSS        (out)
FOUND_STOCK       (in)
```

Rules:
- Reason is **mandatory** on every adjustment movement (DB: `NOT NULL` + CHECK that type ∈ adjustment family ⇒ reason set).
- `EXPIRY` and any lot-tracked product ⇒ a lot/batch reference is **required** (DB CHECK or domain invariant + validation).
- `COUNT_CORRECTION` is reserved for the counting flow; it must not appear as a selectable reason in the manual adjustment UI.

### 2.3 Justification documents

- Reuse the existing polymorphic media/attachment system (MinIO → R2). Do **not** build a new uploader.
- An adjustment movement may own 0..n attachments via the existing polymorphic relation.
- Upload is **optional by default**, but make it **configurable to required per reason code** (e.g. a tenant can force a document on `EXPIRY` for destruction records). Add a per-reason `requires_document` flag in the reason lookup.
- Accept the formats the existing media system already supports (PDF/image at minimum). No new format handling.

### 2.4 Immutability & correction

- An adjustment has a `draft` → `posted` lifecycle. On post: lock the stock row (pessimistic), write the movement event + hash chain entry synchronously in the transaction, then update cached on-hand.
- A `posted` adjustment is immutable — no edit, no delete of the row or its quantity.
- Corrections = a new reversing movement referencing the original (`reverses_movement_id`), following the existing CreditNote-style correction pattern. Surface this in the UI as "Reverse / correct," never as "edit."
- `opening_balance`: after confirm, it is posted and locked like any other; later changes go through normal operations or a correcting adjustment.

---

## 3. Phase 3 — Implementation tasks (only after audit)

Implement in this order. Each task should be a self-contained commit.

### 3.1 Data model & constraints
- [ ] Ensure adjustment family movement types exist in the movement-type enum (§2.1).
- [ ] Add/confirm `reason_code` as a non-nullable FK/enum on adjustment movements; add the reason lookup table seeded with §2.2, including `direction` and `requires_document` columns.
- [ ] Add DB CHECK constraints: adjustment ⇒ reason set; `EXPIRY` + lot-tracked product ⇒ lot reference present.
- [ ] Add `reverses_movement_id` (nullable, self-FK) for corrections if not already present.
- [ ] Confirm the cached on-hand recompute path covers adjustment movements (and lot-level on-hand for lot-scoped ones).

### 3.2 Domain / application layer
- [ ] One `PostStockAdjustmentService` (or extend existing posting service): takes product, location, lot (optional/required per rules), quantity delta, reason code, attachments, posts as event-first inside a transaction with pessimistic lock.
- [ ] Validation: reason in allowed set for the manual screen (exclude `COUNT_CORRECTION`); lot required where rules demand; document required where `requires_document` is set.
- [ ] Reversal/correction method that posts an inverse movement linked via `reverses_movement_id`.

### 3.3 Lot/expiry write-off flow (priority — pharmacy)
- [ ] Query: "lots on hand expiring before date / already expired" for a location, lot-level quantities.
- [ ] Expiry write-off UI: pick location → see expiring/expired lots with quantities → select lots + quantities to write off → reason auto-set to `EXPIRY` → optional/required justification doc → confirm. Posts one `write_off_expiry` movement per lot (or a grouped adjustment with per-lot lines — match existing movement granularity found in audit).
- [ ] Make this reachable as its own action (not buried in a generic adjustment screen), since it's routine for this vertical.

### 3.4 Approval gating (config)
- [ ] A tenant/location setting: do write-off adjustments above a threshold (qty or value) require approval before posting? If enabled, adjustment posts as `draft` pending approval, then posts on approve. Keep this simple and behind a flag; do not block the happy path when disabled.

### 3.5 Opening balance
- [ ] At first product+location creation, allow entering a starting quantity that posts as an `opening_balance` movement (optional justification doc). After confirm, lock — no in-place quantity edit anywhere.

### 3.6 Presentation
- [ ] Controllers under `Presentation/Controllers/`, routes in the Inventory module's `Presentation/routes.php` (follow audited convention).
- [ ] Product page: on-hand stays **read-only**, with a link to the adjustment/movement flows. Do not add an editable quantity field.

---

## 4. Explicit out-of-scope (do not touch)
- The **Inventory Counting** flow — considered good, integrate only via shared movement type/reason.
- Document-driven movements (purchases, sales, returns, transfers) — not part of this spec.
- The media/upload subsystem internals — reuse, don't rebuild.
- Catalog (product master data) editing surfaces.

---

## 5. Acceptance checks
- [ ] On-hand cannot be changed by any field edit; only by a posted movement.
- [ ] Every manual adjustment requires a reason from the restricted set; `COUNT_CORRECTION` is not user-selectable there.
- [ ] `EXPIRY` write-off on a lot-tracked product cannot post without a lot reference.
- [ ] A reason flagged `requires_document` blocks posting until a document is attached.
- [ ] A posted adjustment cannot be edited or deleted; correcting it creates a linked reversing movement.
- [ ] Opening balance posts as a movement and locks after confirmation.
- [ ] Posting locks the stock row, writes movement + hash chain synchronously, then updates cached on-hand; side effects are async.
- [ ] Expiry write-off is reachable as a dedicated action and lists expiring/expired lots with quantities.
