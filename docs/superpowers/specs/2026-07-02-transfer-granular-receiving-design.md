# Granular Stock-Transfer Receiving — Design Spec

**Date:** 2026-07-02
**Status:** Revision 2 — addresses Codex adversarial review (`docs/superpowers/audits/2026-07-02-transfer-granular-receiving-codex-review.md`, verdict REVISE). No code written. See the [Revision 2 disposition table](#revision-2--codex-review-disposition) at the end for every finding → change.
**Scope:** Intracompany stock transfers (`StockTransferService`). Intercompany is
still rejected at the seam (`StockTransferService::initiate()` line 93-99) and is
out of scope here.
**Author:** design session (Opus)

---

## 1. Problem statement

Receiving a transfer today is one all-or-nothing button.

- `StockTransferController::complete()` (`app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:205-235`)
  takes **no validated request body** — it looks the transfer up, checks destination
  location access (`:220-223`), and calls the service.
- `StockTransferService::complete()` (`.../Application/Services/StockTransferService.php:186-209`)
  delegates to `completeLocked()` (`:215-290`), which loops every line and calls
  `stockAdjustmentService->receive(...)` for the **full** `line->quantity`
  (`:241-249`) — or, for batch lines, the full `allocation->quantity` per batch
  (`:225-239`). There is no way to say "8 of 10 arrived."
- `stock_transfer_lines` has **no received-quantity column** — the creation migration
  (`database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:86-88`)
  defines only `quantity`, `unit_cost_snapshot`, `allocated_transfer_cost`. `variant_id`
  was added later (`database/migrations/tenant/2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php:26-53`,
  with partial unique indexes) and the model exposes it (`StockTransferLine.php:18-62`).
- The status enum (`app/Modules/Inventory/Domain/Enums/TransferStatus.php:15-51`)
  is `draft → in_transit → completed | cancelled`, and `canBeCompleted()`
  (`:33-35`) only allows `InTransit`.

Real warehouses lose, damage, and miscount units in transit. The owner wants:

1. Line-level confirmation of received quantities.
2. Landed quantity may differ from sent (shrinkage, damage, correction).
3. Partial receiving over multiple sessions (at least considered).
4. Discrepancies must be **explicit, quantified, and resolved** — never silently
   absorbed.

There is already a **partial-receipt precedent** in Procurement
(`GoodsReceiptService`) — this design borrows its vocabulary
(`quantity_received`, `remaining`, `partially_received`, `fully_received`).

---

## 2. What today's flow already guarantees (must preserve)

These are load-bearing invariants the new flow must not break:

1. **Source is decremented at `initiate()`, not at receipt.** `moveSourceToInTransit()`
   issues the full line/batch quantity out of source and flips to `InTransit`
   (`StockTransferService.php:383-473`). The in-transit units live in **no
   `stock_level` row** between initiate and receive.
2. **WAC counts in-transit units as company-owned.** `WeightedAverageCostService::companyOwnedQuantity()`
   sums on-hand `stock_level` rows **plus** `SUM(stock_transfer_lines.quantity)`
   for transfers with `status = InTransit` (`WeightedAverageCostService.php:95-123`,
   in-transit term at `:114-120`). This keeps the WAC denominator honest during
   the in-transit window.
3. **Transfer cost is capitalized once, at completion**, over **sent** quantity.
   `capitalizeTransferCost()` (`:500-582`) weights each line via
   `computeAllocationWeights()` (`:587-605`) using `line->quantity`
   (ProRataValue = qty×cost, ProRataQuantity = qty, EqualPerLine = 1), then calls
   `wacService->recordCostAdjustment()` per line. The residual is absorbed by the
   last cost-bearing line so the persisted shares sum to `transfer_cost` exactly
   at scale 4 (`:538-560`).
4. **Status is flipped to Completed BEFORE cost capitalization** on purpose — if
   the transfer is still `InTransit` when `recordCostAdjustment` re-queries the
   in-transit quantity, the just-received qty is double-counted (see the comment
   at `:255-259`). **This exact hazard reappears for partial receiving** — see §7.
5. **Negative stock is hard-blocked, no override, no back-valuation engine**
   (ADR-0002, `docs/adr/2026-06-30-negative-stock-hard-block.md`). Every issue
   consumes a real, already-costed layer; WAC stays trivially correct. Our
   shortfall handling must not require a back-valuation engine and must never
   drive a `stock_level` negative.
6. **Batch lines enforce FEFO on dispatch** (`assertAllocationsFollowFefo()`
   `:666-743`) — the *sent* split is canonical. Receiving does not re-run FEFO;
   it lands the already-chosen batches.
7. **Deadlock defense**: `complete()`/`cancel()` acquire ALL line product advisory
   locks up-front in one sorted `ProductCostLock::acquire()` call before the
   per-line `receive()` loop (`:195-207`, `:317-319`). Any new receive path must
   keep this pattern.
8. **Money/quantity are strings, scale 4** for quantity (`QTY_SCALE = 4`,
   `StockTransferService.php:60`; casts `decimal:4` on the models). No `parseFloat`
   / float casts (CLAUDE.md rule 19). All payloads carry decimal strings.
9. **Transfer receipt does NOT recompute product WAC.** `completeLocked()` lands
   units via `stockAdjustmentService->receive(...)` **without a `unitCost` argument**
   (`StockTransferService.php:225-249`); `receive()` increments the `stock_level` row
   and records a movement but never calls `recordPurchase()` / never re-derives WAC
   (`StockAdjustmentService.php:88-120`). The **only** WAC touch in the whole transfer
   flow is `capitalizeTransferCost()` → `recordCostAdjustment()` after the status flip
   (`:255-270`, `:565-580`) — i.e. **freight capitalization**, not the receipt itself.
   Purchase GR is different: it *does* blend (`GoodsReceiptService.php:154-191` passes a
   landed unit cost into `recordPurchase()` and emits `GoodsReceived`). Any accounting
   proof in this spec must reason on this actual behavior, not on a phantom WAC blend at
   receipt.
10. **`idempotency_key` is an established transfer contract.** `stock_transfers` carries
    `idempotency_key` with a `UNIQUE (tenant_id, company_id, idempotency_key)` index
    (`create_stock_transfers_table.php:53-67`) and `initiate()` replays the existing
    transfer before doing any work (`StockTransferService.php:101-113`). The receive
    contract must mirror this at the receipt grain (§6.2, D12).

---

## 3. Recommended model (summary of decisions)

| # | Decision | Recommendation | Alternatives considered |
|---|----------|----------------|-------------------------|
| D1 | Receiving granularity | Per-line + per-batch received quantity, **multi-session in scope for Phase 1** (owner ask #3 is a bar, not a "maybe" — the review flagged deferring it as conflicting with the owner bar) | Single session only (rejected — fails owner ask #3) |
| D2 | New statuses | Add `partially_received`; keep `completed` as the terminal "fully reconciled" state | Reuse `in_transit` + a boolean (loses reporting clarity) |
| D3 | Receipt record | Append-only `stock_transfer_receipts` + `_lines` **and** a `received_quantity` running counter on the line | Counter-only (loses per-session audit for tier-2 chain) |
| D4 | Over-receipt | **Hard cap at sent quantity** — reject | Allow with manager permission + positive discrepancy (deferred) |
| D5 | Shortfall while partial | Remainder **stays in-transit** (status `partially_received`); no write-off yet | Auto-write-off each session (premature loss recognition) |
| D6 | Shortfall at final close | Explicit **disposition** required: `write_off` (default) or `return_to_source` | Silent absorption (rejected — owner ask #4) |
| D7 | Write-off mechanics | **Land the FULL remaining in-transit quantity at destination, THEN issue the shortfall back out** as a write-off (land-then-issue, in that order). Landing `remaining = quantity − alreadyLanded` first guarantees the `stock_level` has ≥ `shortfall` available before `issue()` runs, so `StockAdjustmentService::issue()`'s hard over-issue block (`:204-211`) never trips — works for receive-0, receive-8-of-10, and all-lines-short. See §6.3 for the executable sequence + worked examples | (a) Land only `thisSession` then issue `shortfall` — **the original R1 bug**: for receive-0 there is no stock to issue, for receive-8-of-10 net is +6 not +8. (b) A first-class in-transit-loss movement type that books stock+GL without a `stock_level` row — more invasive, new movement type, no anchor row; deferred |
| D12 | Receipt idempotency | **Client `idempotency_key` on `stock_transfer_receipts`**, `UNIQUE (tenant_id, company_id, transfer_id, idempotency_key)`; required on the endpoint; server replays the prior receipt response on retry (mirrors `initiate()` `:101-113`). `sequence` is display numbering only, NOT the idempotency key | Sequence-only uniqueness (rejected — server assigns a new sequence on replay, so a timed-out-but-committed first request double-receives, finding 3) |
| D13 | Concurrency contract | **`lockTransfer()` FIRST** (row lock), then under that lock compute remaining, assign `sequence`, validate over-receipt, bump `received_quantity`; only then take the sorted `ProductCostLock::acquire()` for the stock adjustments. Product locks serialize per-product cost math but do NOT serialize two receipt submissions for the same transfer with disjoint products — the transfer-row lock does | Rely on product locks alone (rejected — finding 11: disjoint-product receipt races + retry races unserialized) |
| D14 | Location scoping | `/receive` + finalize require **destination** location access; `return_to_source` finalize **additionally** requires **source** access (it restocks source). Mirror `StockTransferController` (destination for complete `:220-223`, source for cancel `:253-256`) and `StockTransferLocationScopeTest` | Permission-only gating (rejected — finding 4: a security regression vs the recently-added location scoping) |
| D15 | Expiry-in-transit | A dispatched batch that becomes **expired/recalled before receipt** (`Batch::canBeSold()` false at receive time) CANNOT land as ordinary sellable on-hand. The receive API forces an explicit path per batch: `write_off` (reason `expiry`) or `return_to_source`. Landing such a lot as normal `received_quantity` is rejected `422 TRANSFER_BATCH_NOT_SELLABLE` | Silently land it (rejected — finding 8: expired lot becomes sellable destination stock) |
| D16 | Discrepancy reason taxonomy | `stock_transfer_lines.discrepancy_reason` (rich enum) is the **reporting source of truth**; the stock **movement** collapses to the nearest existing `MovementReason` (Damage→Damage, Expiry→Expiry, CountCorrection→CountCorrection, Loss/Theft/Other→WriteOff). Reports needing loss-vs-theft granularity join the transfer discrepancy rows, not `stock_movements.reason`. `ShortShipment` is **dropped** (finding 9 — full qty is always issued out of source at `initiate()` `:383-473`, so a "never dispatched all" case is a source-side count correction, not a destination discrepancy) | Add first-class `loss`/`theft`/`short_shipment` `MovementReason` cases (rejected — bloats a shared enum every module reads; the discrepancy table already carries the granularity) |
| D17 | In-transit read models | Fix **all** in-transit read models, not just WAC: `WeightedAverageCostService::companyOwnedQuantity()` (§7.1) **and** `LocationStockQueryService` distribution (`:139-165`) + incoming (`:222-244`) queries must sum `quantity − received_quantity` over **both** `InTransit` and `PartiallyReceived` (§7.4, finding 5) | WAC-only fix (rejected — partial receipts overstate incoming stock on the distribution/incoming views) |
| D18 | Write-off GL | Designed end-to-end **now**, not deferred: reuse `GeneralLedgerService::createInventoryWriteOffEntry()` (Dr COGS / Cr Inventory, `:2124-2199`) via a `StockTransferShortfallDispositioned` listener; amount = `shortfall × unit_cost_snapshot` currency-scaled; idempotent on `movementId`; fully-lost freight expensed with the shrinkage. See §7.3 (findings 2, 6, 15) | Emit an event and leave account wiring / freight as an "Accounting follow-up" (rejected — write_off is a first-class disposition; GL cannot be an open item) |
| D19 | Receipt correction/reversal | **Pre-final:** an erroneous partial receipt is corrected by a compensating **reversal receipt** (`is_reversal = true`, negative per-line quantities) that issues the over-received units back out of destination and decrements `received_quantity` — auditable, append-only. **Post-final (finalized) receipt reversal is a non-goal** for Phase 1 (§13) — it needs GL reversal machinery beyond this scope | No correction path at all (rejected — industry reversal gap; an operator must be able to fix a miscount before close) |
| D8 | Transfer-cost allocation basis | Reallocate over **received** quantity, capitalized once at final close | Keep sent-qty basis (freight for lost units mis-capitalized) |
| D9 | WAC in-transit denominator | Count `quantity − received_quantity` (remaining), not full `quantity`, for `InTransit` **and** `partially_received` | Leave as-is (double-counts landed units — a real bug, see §7) |
| D10 | Cancel vs close | Cancel allowed only from `draft`/`in_transit` with **zero** receipts; once any receipt exists you must **close** (finalize) | Allow cancel from partially_received (ambiguous restock semantics) |
| D11 | Permissions | New `inventory.transfers.receive`; finalize-with-write-off needs `inventory.transfers.writeoff` | Reuse `complete` for everything (a P&L loss deserves its own gate) |

---

## 4. Schema changes

All on the tenant DB (`database/migrations/tenant/`). Quantities `decimal(15,4)`,
matching existing columns. Postgres CHECK constraints mirror the existing style
(`create_stock_transfers_table.php:96-100`).

### 4.1 `stock_transfer_lines` — add running counters + line-level discrepancy

```
received_quantity      decimal(15,4)  NOT NULL DEFAULT 0   -- Σ across all receipts
discrepancy_quantity   decimal(15,4)  NOT NULL DEFAULT 0   -- sent − received, set at close (>= 0)
discrepancy_reason     varchar(32)    NULL                 -- TransferDiscrepancyReason (§5.2), set at close
disposition            varchar(24)    NULL                 -- 'write_off' | 'return_to_source', set at close
```

CHECK: `received_quantity >= 0`, `received_quantity <= quantity` (enforces D4 at
the storage layer — the hard over-receipt cap), `discrepancy_quantity >= 0`.

### 4.2 `stock_transfer_line_batch_allocations` — add per-batch received counter

The table today (`2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php`)
has `quantity` (= sent) and a unique `(stock_transfer_line_id, batch_id)`.

```
received_quantity  decimal(15,4)  NOT NULL DEFAULT 0
```

CHECK: `received_quantity >= 0`, `received_quantity <= quantity`.

### 4.3 NEW `stock_transfer_receipts` — append-only receiving-session header

Every "Save receipt" / "Receive & close" click writes one immutable row. This is
the tier-2 audit anchor (see §9).

```
id                 uuid  PK
transfer_id        uuid  FK -> stock_transfers (cascade)
tenant_id          uuid  (plain indexed, no FK — db-per-tenant, matches siblings)
company_id         uuid  FK -> companies (cascade)
sequence           int   -- 1,2,3… per transfer (DISPLAY numbering only, NOT idempotency)
idempotency_key    varchar(128)  NOT NULL   -- client-supplied replay key (D12)
is_final           bool  -- true when this receipt closed the transfer
is_reversal        bool  NOT NULL DEFAULT false  -- true = compensating correction (D19); lines carry negative qty
received_by_user_id uuid FK -> users (restrict)
notes              text  NULL
created_at / updated_at  timestamptz
UNIQUE (transfer_id, sequence)
UNIQUE (tenant_id, company_id, transfer_id, idempotency_key)  -- replay anchor (D12), mirrors stock_transfers_idempotency_unique
INDEX (tenant_id, company_id, transfer_id)
```

The `UNIQUE (tenant_id, company_id, transfer_id, idempotency_key)` index is the
replay anchor: a retried `POST …/receive` with the same key hits the unique
constraint / prior-row lookup and returns the already-committed receipt's response
instead of applying it twice (§6.2). `sequence` remains a human-facing "receipt N of
this transfer" counter, assigned under the transfer-row lock (D13).

### 4.4 NEW `stock_transfer_receipt_lines` — per-session, per-line detail

```
id                    uuid  PK
receipt_id            uuid  FK -> stock_transfer_receipts (cascade)
stock_transfer_line_id uuid FK -> stock_transfer_lines (restrict)
batch_id              bigint NULL  FK -> product_batches (restrict)  -- null for non-batch lines
tenant_id / company_id  (as siblings)
received_quantity     decimal(15,4)  -- this session only; > 0 on normal receipts, < 0 on reversal receipts (is_reversal, D19)
created_at / updated_at
INDEX (receipt_id), INDEX (stock_transfer_line_id)
UNIQUE (receipt_id, stock_transfer_line_id, batch_id)  -- one authoritative row per line/batch per receipt (finding 10)
CHECK (received_quantity <> 0)   -- non-zero (normal > 0, reversal < 0); zero-qty rows are a client bug
```

**Receipt-line uniqueness (finding 10):** duplicate `(line, batch)` scans within one
receipt are **illegal** — the request layer MERGES repeated scan increments for the
same line/batch into a single row before persisting, so the DB carries exactly one
authoritative quantity per line/batch per receipt. `batch_id` is part of the key; for
non-batch lines it is `NULL` and Postgres treats NULLs as distinct, so the request
layer additionally rejects a second `NULL`-batch row for the same line (a non-batch
line has at most one receipt-line per receipt). This keeps API consumers and audit
reports agreeing on the receipt's authoritative quantity.

Rationale for the receipt tables (D3): a bare running counter (the GR precedent,
`GoodsReceiptService` stamps `quantity_received` on the PO line at
`GoodsReceiptService.php:224` and writes `last_goods_receipt_at` into the PO
`payload` at `:240-244`) is enough for state but loses the *per-session* record.
Transfers cross fiscal/audit boundaries (stock movements feed the tier-2 chain,
compliance.md §"Tier 2: Audit Log"), so we keep an explicit, immutable receipt
record. The line-level `received_quantity` counter stays as a denormalized
fast-path for status/validation (mirrors GR).

### 4.5 Backfill (see §11 for the full plan)

- `stock_transfer_lines.received_quantity`:
  - `status = completed` → set `= quantity` (they were fully received under the
    old all-or-nothing path).
  - `status IN (draft, in_transit, cancelled)` → `0` (the default).
- `discrepancy_quantity = 0`, `discrepancy_reason = NULL`, `disposition = NULL`
  everywhere. Historical completed transfers had no discrepancy concept.
- Batch allocations: same rule (`received_quantity = quantity` where the parent
  transfer is `completed`, else `0`).
- No synthetic `stock_transfer_receipts` rows are backfilled for historical
  transfers (they predate the concept). New tables start empty.

---

## 5. Enums

### 5.1 `TransferStatus` evolution (D2)

`TransferStatus` is a plain string-backed enum persisted to a `varchar(20)`
column (`create_stock_transfers_table.php:42`, cast at `StockTransfer.php:89`).
It is **not** a domain Event, so Rule 8 (events immutable) does not apply — adding
a case is additive and safe. Do **not** rename or remove existing cases (reports,
FE filters, and the WAC in-transit query at `WeightedAverageCostService.php:119`
all key off the string values).

Add:

```php
case PartiallyReceived = 'partially_received';
```

`completed` keeps its value and its meaning shifts slightly: "fully reconciled —
every line either received or its shortfall dispositioned." Update helpers:

```php
public function canBeReceived(): bool      // NEW
{
    return $this === self::InTransit || $this === self::PartiallyReceived;
}

public function canBeCancelled(): bool     // NARROWED — see D10
{
    return $this === self::Draft || $this === self::InTransit;
    // NOTE: PartiallyReceived is intentionally NOT cancellable.
}
```

`canBeCompleted()` (`:33-35`) is repurposed / replaced by `canBeReceived()`; the
finalize action (§6.2) is valid from `InTransit` and `PartiallyReceived`.

### 5.2 NEW `TransferDiscrepancyReason` (D6)

```php
enum TransferDiscrepancyReason: string
{
    case Damage          = 'damage';           // -> MovementReason::Damage
    case Loss            = 'loss';             // -> MovementReason::WriteOff
    case Theft           = 'theft';            // -> MovementReason::WriteOff
    case Expiry          = 'expiry';           // -> MovementReason::Expiry
    case CountCorrection = 'count_correction'; // -> MovementReason::CountCorrection
    case Other           = 'other';            // -> MovementReason::WriteOff
}
```

**Mapping and reporting SoT (D16, finding 9).** `MovementReason`
(`app/Modules/Inventory/Domain/Enums/MovementReason.php`) has `CountCorrection`
(line 24), `Damage`/`Expiry`/`WriteOff` (lines 26-28) but **no** `Loss`, `Theft`,
or `ShortShipment` cases. Rather than bloat that shared enum (every module reads it),
we keep `TransferDiscrepancyReason` rich and persist it on
`stock_transfer_lines.discrepancy_reason` + the receipt/event payload — **that column
is the reporting source of truth** for loss-vs-theft-vs-other granularity. The stock
**movement** collapses to the nearest existing `MovementReason` per the `->` mapping
above (Loss/Theft/Other all land on `WriteOff`). Reports that must distinguish them
join back to the transfer discrepancy rows, exactly as GL shrinkage reporting already
joins `journal_entries.source_id` → movement.

**`ShortShipment` removed.** The R1 draft had a `short_shipment` case meaning "never
dispatched all", but `initiate()` issues the **full** line/batch quantity out of
source before receipt (`StockTransferService.php:383-473`) — there is no
partial-dispatch state. A genuine "we shipped less than the doc says" is a **source-side
count correction** on the source location, not a destination-side transfer discrepancy,
so it is out of this flow's scope.

### 5.3 NEW disposition (small enum or string)

```php
enum TransferDisposition: string
{
    case WriteOff       = 'write_off';        // shortfall is a company loss
    case ReturnToSource = 'return_to_source'; // shortfall goes back to source on-hand
}
```

---

## 6. State machine & endpoints

### 6.1 State machine

```
                 initiate()                receive (partial, short)
   draft ───────────────────► in_transit ───────────────────────► partially_received
     │                            │  │                                  │  │
     │ cancel                     │  │ receive (full)                   │  │ receive (more, partial)
     ▼                            │  ▼                                  │  ▼   (self-loop)
  cancelled                       │ completed ◄──────────────────────── │ finalize / receive-and-close
     ▲                            │                                     │
     └──────── cancel ────────────┘                    (with disposition when short)
   (only from draft / in_transit — D10)
```

Transitions:

| From | Action | To | Notes |
|------|--------|----|-------|
| `draft` | `initiate()` | `in_transit` | unchanged (`moveSourceToInTransit` `:471`) |
| `in_transit` | receive (Σ received < sent, not final) | `partially_received` | remainder stays in-transit (D5) |
| `in_transit` | receive (Σ received = sent) | `completed` | full receipt, no discrepancy |
| `in_transit` | receive-and-close (short) | `completed` | shortfall dispositioned (D6) |
| `partially_received` | receive (still < sent, not final) | `partially_received` | self-loop, another session |
| `partially_received` | receive (reaches sent) | `completed` | |
| `partially_received` | finalize / receive-and-close (short) | `completed` | shortfall dispositioned |
| `partially_received` | reverse a non-final receipt (D19) | `partially_received` or `in_transit` | drops to `in_transit` if it reverses the only receipts; issues units back out of destination |
| `draft` / `in_transit` | cancel | `cancelled` | restock in-flight to source (existing `cancel()` `:298-376`) — **only when zero receipts** (D10) |

Over-receipt (Σ received would exceed sent) is **rejected** at validation (D4).

### 6.2 Endpoints

Keep the existing routes (`app/Modules/Inventory/Presentation/routes.php:78-96`)
and add one. All under `['api','auth:sanctum',SetPermissionsTeam::class]` (already
the group middleware).

**a) `POST /stock-transfers/{transfer}/receive`** — record a receiving session
(partial or full), permission `can:inventory.transfers.receive`. **Location scope
(D14):** requires **destination** location access; `return_to_source` dispositions
additionally require **source** access (see below). Both checked in the controller
before the service runs, mirroring `complete` (`:220-223`) and `cancel` (`:253-256`).

Request body:

```jsonc
{
  "idempotency_key": "uuid-or-client-token",    // REQUIRED (D12); replay anchor
  "lines": [
    {
      "line_id": "uuid",
      "received_quantity": "8.0000",           // this session; decimal string
      "batch_allocations": [                    // required iff the line is batch-tracked
        { "batch_id": 42, "received_quantity": "5.0000" },
        { "batch_id": 43, "received_quantity": "3.0000" }
      ]
    }
  ],
  "notes": "Truck 2 of 3",
  "finalize": false,                            // false = save partial; true = close now
  "discrepancy": [                              // REQUIRED only when finalize=true AND short
    {
      "line_id": "uuid",
      "batch_id": 43,                           // REQUIRED for batch lines; disposition is per-batch
      "reason": "damage",                       // TransferDiscrepancyReason
      "disposition": "write_off"                // TransferDisposition
    }
  ]
}
```

Rules:
- **Idempotent replay (D12):** `idempotency_key` is required. If a receipt already
  exists for `(tenant_id, company_id, transfer_id, idempotency_key)`, the endpoint
  **replays** that receipt's response (no second application) — exactly as
  `initiate()` replays on `stock_transfers.idempotency_key` (`:101-113`).
- **No-op rejection:** a `finalize=false` receipt whose every line/batch
  `received_quantity` is 0 (or an empty `lines`) → **422** `TRANSFER_EMPTY_RECEIPT`.
  A receipt must move something (the `CHECK (received_quantity <> 0)` also enforces
  this at storage).
- `received_quantity` per line ≤ `quantity − received_quantity_so_far` (remaining).
  Over-receipt → **422** `TRANSFER_OVER_RECEIPT` (D4).
- For batch lines, `Σ batch_allocations.received_quantity == line.received_quantity`
  and every `batch_id` must be one already allocated on that line
  (`stock_transfer_line_batch_allocations`), each with its own remaining cap.
- **Expiry-in-transit (D15):** if an allocated batch is no longer sellable at receive
  time (`Batch::canBeSold()` false — expired/recalled/inactive), it may NOT be landed
  as ordinary `received_quantity`. Such a batch must be dispositioned (`write_off`
  reason `expiry`, or `return_to_source`) via a `finalize=true` call; attempting to
  land it as normal on-hand → **422** `TRANSFER_BATCH_NOT_SELLABLE`.
- `finalize=false`: apply the receipts, recompute status (`partially_received` or,
  if it happens to reach sent on every line, `completed` with zero discrepancy).
- `finalize=true`: any line with `received_quantity_total < quantity` MUST have a
  matching `discrepancy[]` entry (reason + disposition; per-batch for batch lines) or
  **422** `TRANSFER_DISCREPANCY_UNRESOLVED` (owner ask #4). Then close (§6.3).
- `disposition = write_off` requires `can:inventory.transfers.writeoff` (D11).
- `disposition = return_to_source` requires **source** location access (D14) — the
  units re-appear on source on-hand.

FormRequest regex ceilings per CLAUDE.md rule 19: quantity fields
`/^\d+(\.\d{1,4})?$/` in addition to `numeric|min:0`; `idempotency_key`
`string|max:128`.

**b) `POST /stock-transfers/{transfer}/complete`** — kept for backward compat,
becomes "receive all remaining and close with no discrepancy" (equivalent to the
GR `receiveAll()` shortcut at `GoodsReceiptService.php:259-273`). Internally builds a
receive-all command (every line's remaining as `received_quantity`, `finalize=true`,
no discrepancy) and runs the same finalize path (§6.3). Permission unchanged
(`can:inventory.transfers.complete`); destination location access still enforced
(`:220-223`). **Body handling (finding 14):** `/complete` **ignores** any receipt/
discrepancy fields in the body (it is definitionally "everything arrived") — it does
not accept per-line quantities. It self-generates an `idempotency_key` server-side
(`{transfer_id}-complete`) so a double-click is idempotent. If the transfer is already
`completed` it replays. This keeps the current one-click "everything arrived" workflow
working unchanged.

**c) `POST /stock-transfers/{transfer}/cancel`** — unchanged, but now **rejects**
if any receipt exists (D10): returns `422 TRANSFER_HAS_RECEIPTS` telling the user
to finalize instead. Existing `canBeCancelled()` narrowing (§5.1) enforces this at
the status level (a partially-received transfer is `partially_received`, not
cancellable). Source location access unchanged (`:253-256`).

**d) `POST /stock-transfers/{transfer}/receipts/{receipt}/reverse`** — compensating
reversal of a **non-final** partial receipt (D19), permission
`can:inventory.transfers.receive`, **destination** location access. Writes a new
append-only receipt row with `is_reversal = true` and negative per-line quantities,
issues the over-received units back out of destination (`issue()`, reason
`CountCorrection`), decrements each line's `received_quantity`, and recomputes status
(may drop `partially_received` → `in_transit`). **Only receipts of a transfer that is
still `in_transit`/`partially_received` are reversible** — a finalized receipt (on a
`completed` transfer) is NOT reversible in Phase 1 (§13 non-goal). Also carries its
own `idempotency_key`.

### 6.3 Finalize (close) algorithm — the heart of the design

**Concurrency contract (D13, finding 11).** In order:

1. `DB::transaction(attempts:3)`.
2. **`lockTransfer()` FIRST** (`SELECT … FOR UPDATE` on the `stock_transfers` row —
   the existing `lockTransfer()` used by `complete`/`cancel` `:301`). Under that lock,
   for the whole call: (a) validate `canBeReceived()`, (b) recompute each line's
   `remaining = quantity − received_quantity`, (c) validate over-receipt / no-op /
   expiry (§6.2), (d) assign the receipt `sequence`, (e) bump `received_quantity`
   counters. The transfer-row lock is what serializes two receive submissions for the
   **same transfer with disjoint products** and retry races — product locks alone do
   not (finding 11).
3. **Then** take the up-front sorted `ProductCostLock::acquire()` over all line
   product ids for the stock adjustments (preserve the deadlock defense at
   `StockTransferService.php:203-207`).

**Variant threading (D-, finding 7).** Every `receive()` / `issue()` call below passes
`variantId: $line->variant_id` (exactly as `completeLocked` `:235,248` and `cancel`
`:331,344` already do). `StockAdjustmentService` rejects product-level ops on a
variant product (`:77-88`, `:864-872`), so a missing `variant_id` is a hard failure,
not a silent product-grain write. This applies to the land, the write-off issue, the
return-to-source receive, the batch movements, and the movement events.

**Per-line sequence (D7 corrected — "land the full remaining, THEN issue the
shortfall").** Let `alreadyLanded = received_quantity from prior sessions`,
`thisSession` = counted qty in this final call, `remaining = quantity − alreadyLanded`,
`shortfall = remaining − thisSession`.

1. **Land the FULL `remaining`** at destination via `stockAdjustmentService->receive(…, variantId: $line->variant_id, batchId?)`,
   `MovementType::TransferIn`, reference `{transfer_number}`. This brings **all**
   still-in-transit units to the destination dock — including the ones we are about to
   write off — so the destination `stock_level` now has `≥ shortfall` available.
   `receive()` never blocks and never touches WAC (§2.9).
2. If `shortfall > 0`, apply the line's disposition:
   - **`write_off`**: `stockAdjustmentService->issue(shortfall, …, reason: <mapped MovementReason>, unitCost: $line->unit_cost_snapshot, variantId)` out of destination,
     reference `{transfer_number}-WRITEOFF`. Because step 1 landed `remaining` first,
     `available ≥ shortfall`, so `issue()`'s hard over-issue block (`:204-211`) never
     trips. Net destination change = `remaining − shortfall = thisSession` ✓ (the
     good units stay; the lost units net to zero). Then post GL (§7.3).
   - **`return_to_source`**: `issue(shortfall, …, reason: CountCorrection, variantId)`
     out of destination, then `receive(shortfall, …, variantId, batchId?)` back at
     **source** (reuse the cancel restock shape `:323-347`), reference
     `{transfer_number}-RETURN`. Net destination = `thisSession`, source regains
     `shortfall`. No loss, no GL.
   - **Batch lines**: the same, per allocated batch — land the batch's `remaining`,
     then issue/return the per-batch `shortfall` with the `batchId` (so
     `recordBatchMovement` keeps the batch ledger correct). An expired-in-transit batch
     (D15) may ONLY take `write_off`(expiry) or `return_to_source` here.
   - Persist `discrepancy_quantity = shortfall`, `discrepancy_reason`, `disposition`
     on the line (per-batch reason/disposition captured on the receipt line + event).
3. Set `received_quantity` (final) = `alreadyLanded + thisSession` on the line; write
   the `stock_transfer_receipts` row (`is_final = true`, `idempotency_key`) and its
   `_lines`.
4. Flip status to `Completed` **BEFORE** capitalizing transfer cost — the exact
   ordering the current code documents at `:255-263` (else the just-received qty is
   double-counted in the in-transit denominator).
5. Capitalize `transfer_cost` **once**, over **received** quantity (D8) — see §7.2.
6. `DB::afterCommit` fire events (§9).

**Worked examples (line sent = 10, unit_cost_snapshot = 3.000):**

| Case | alreadyLanded | thisSession | remaining | shortfall | step 1 land | step 2 | net dest | source Δ | GL |
|---|---|---|---|---|---|---|---|---|---|
| Full in one call | 0 | 10 | 10 | 0 | +10 | — | +10 | 0 | none |
| **Receive 0 of 10, write_off** | 0 | 0 | 10 | 10 | **+10** | issue −10 | **0** | 0 | Dr COGS 30 / Cr Inv 30 |
| **Receive 8 of 10, write_off** | 0 | 8 | 10 | 2 | +10 | issue −2 | **+8** | 0 | Dr COGS 6 / Cr Inv 6 |
| Receive 8 of 10, return_to_source | 0 | 8 | 10 | 2 | +10 | issue −2, source +2 | +8 | +2 | none |
| 2nd session: prior 8, now 2 | 8 | 2 | 2 | 0 | +2 | — | +2 (→10 total) | 0 | none |
| **All lines short (0 recv), write_off** | 0 | 0 | 10 | 10 | +10 | issue −10 | 0 | 0 | Dr COGS 30 / Cr Inv 30 |

The R1 draft's bug (land only `thisSession`, then issue `shortfall`) fails the
receive-0 row (nothing to issue) and the receive-8 row (net +6, not +8). Landing the
**full `remaining`** before issuing is what makes every row executable against the
real `issue()` block.

Partial (non-final) receive is the same minus steps 2, 4, 5: land `thisSession` at
destination (NOT `remaining` — there is no shortfall to dispose yet), write the receipt
rows, bump `received_quantity`, set status to `partially_received` (or `completed` if
every line reached sent with no shortfall).

---

## 7. WAC, cost & GL implications (four required changes)

> **Correcting the R1 accounting proof (finding 6).** R1 claimed landing units
> "blends N at `unit_cost_snapshot`" and that write-off is WAC-neutral *because
> removing at the mean does not move the mean*. That reasoning is wrong for this
> codebase: **transfer `receive()` does not blend WAC at all** (§2.9 — no
> `recordPurchase`, no `unitCost` passed today), and `issue()` likewise does not
> recompute WAC. The land-then-issue write-off is WAC-neutral for a **simpler, true**
> reason: **neither the land nor the issue touches product WAC** — they only move
> `stock_level.quantity`. Net `stock_level` change = `thisSession` (the good units);
> product WAC is untouched by the receipt entirely. The only WAC effect in the flow
> is freight capitalization (§7.2), which now runs over received units. No
> back-valuation engine is needed (ADR-0002 satisfied). The inventory-**value**
> reduction for the lost units is recognized in the GL (§7.3), not via a WAC move.

### 7.1 In-transit denominator must net off landed units (D9) — this is a latent bug fix

`companyOwnedQuantity()` (`WeightedAverageCostService.php:95-123`) adds
`SUM(stock_transfer_lines.quantity)` for `status = InTransit`
(`:114-120`). Under partial receiving, a `partially_received` line has some units
**already in a destination `stock_level` row** (counted in the on-hand sum at
`:100-110`) **and** its full `quantity` counted again in the in-transit term →
**double count**. This is the same double-count class the completeLocked comment
warns about (`:255-259`).

Required change: the in-transit term must sum `quantity − received_quantity`
(remaining truly in transit) and include **both** `InTransit` and
`partially_received` statuses:

```php
->whereIn('stock_transfers.status', [TransferStatus::InTransit, TransferStatus::PartiallyReceived])
->selectRaw('COALESCE(SUM(stock_transfer_lines.quantity - stock_transfer_lines.received_quantity),0) ...')
```

For fully `in_transit` lines `received_quantity = 0`, so behavior is unchanged for
today's transfers — safe, non-breaking. Add a regression test that a
partially-received transfer does not inflate the WAC denominator.

### 7.2 Transfer cost reallocates over received quantity (D8)

`capitalizeTransferCost()` (`:500-582`) + `computeAllocationWeights()` (`:587-605`)
currently weight by `line->quantity` (sent). Change the weight source to
`line->received_quantity` (the landed units), for all three distributions:

- `ProRataValue` → `received_quantity × unit_cost_snapshot`
- `ProRataQuantity` → `received_quantity`
- `EqualPerLine` → `1`, but **skip lines with `received_quantity = 0`** (a fully-lost
  line should not draw freight).

Rationale: freight/handling is a sunk cost for the whole shipment; the units that
**landed** should carry it (standard landed-cost treatment, consistent with
`LandedCostService` for PO receipts). The lost units' share of freight is expensed
as part of the shrinkage, not capitalized into phantom stock.

- Capitalize **once, at final close** (step 6), never per partial session — this
  matches the current single capitalization point and avoids re-blending freight
  on every truck. The residual-absorption logic (`:538-560`) is preserved but now
  reconciles over the received-weighted shares.
- Edge case (**decided, not deferred** — finding 2): a transfer fully lost (every line
  `received_quantity = 0`) has zero received weight → skip freight **capitalization**
  and **expense the whole `transfer_cost`** as part of the shrinkage: one extra GL line
  Dr COGS/shrinkage `transfer_cost`, Cr a freight-clearing/inventory account, posted by
  the same shortfall listener (§7.3) keyed on the finalizing movement. It is never
  capitalized into phantom stock.

### 7.3 Write-off GL — designed end-to-end (D18, findings 2, 6, 15)

Mirror the proven batch write-off pattern exactly. `BatchWriteOffService`
(`BatchWriteOffService.php:44-133`) issues stock then calls
`GeneralLedgerService::createInventoryWriteOffEntry()`
(`GeneralLedgerService.php:2124-2199`), which posts **Dr COGS / Cr Inventory** using
`SystemAccountPurpose::CostOfGoodsSold` + `SystemAccountPurpose::Inventory`
(`:2145-2146`), `source_type = 'batch_write_off'`, `source_id = movementId`.

For transfer shortfall write-offs:

- **Trigger:** a `PostTransferWriteOffOnShortfall` listener on
  `StockTransferShortfallDispositioned` (§9), analogous to
  `PostGrIrOnGoodsReceipt` listening to `GoodsReceived`.
- **Accounts:** reuse `createInventoryWriteOffEntry()` (do NOT invent a new GL method) —
  Dr `SystemAccountPurpose::CostOfGoodsSold`, Cr `SystemAccountPurpose::Inventory`.
  These are already the wired shrinkage accounts; no new account configuration is
  introduced. Add `source_type = 'stock_transfer_write_off'` (distinct from
  `batch_write_off`) so transfer shrinkage is separable in reporting.
- **Amount basis:** `shortfall × unit_cost_snapshot` (the cost the in-transit units
  carried — the value at which they were removed from source and counted in the WAC
  in-transit denominator), currency-scaled via the injected
  `CurrencyScaleResolverInterface::getScale($company->currency)` (context-safe, never a
  no-arg `getScale()` — rule 19). This differs deliberately from `BatchWriteOffService`,
  which uses current product WAC: transfer in-transit units carry a captured snapshot,
  and using it keeps the GL credit equal to the inventory value that actually left.
- **Idempotency:** keyed on the write-off **`movementId`** (the `issue()` movement from
  §6.3 step 2), exactly as `createInventoryWriteOffEntry` already keys
  `source_id = movementId`. A replayed shortfall event re-derives the same movement id
  and the GL insert is a no-op (partial index on `(source_type, source_id)` per
  `reference_journal_entries_no_global_source_uniqueness.md`). Because the receive
  endpoint itself is idempotent (D12), the movement is created at most once.
- **Missing accounts:** if COGS/Inventory purposes are unconfigured,
  `createInventoryWriteOffEntry` returns null / the caller logs and continues (matches
  `BatchWriteOffService.php:122-129`) — stock is still correct; GL is retriable.
- **`return_to_source` posts no GL** (no loss; inventory just relocates).

### 7.4 In-transit read models beyond WAC (D17, finding 5)

`LocationStockQueryService` also exposes in-transit incoming stock and has the **same**
`SUM(quantity)` + `status = InTransit`-only bug the WAC query had:

- **Distribution** query (`LocationStockQueryService.php:139-165`): `SUM(stock_transfer_lines.quantity)`
  grouped by destination, `status = in_transit` only.
- **Incoming** query (`:222-244`): same shape, grouped by product/variant.

Both must change identically to §7.1: sum `quantity − received_quantity` and
`whereIn(status, [InTransit, PartiallyReceived])`. Otherwise a partially-received
transfer overstates "incoming" on the product distribution and location incoming views.
Add read-model tests for both (not just WAC) — see §12.

---

## 8. Permissions (D11)

Seed in `RolesAndPermissionsSeeder` alongside the existing
`inventory.transfers.{view,create,complete,cancel}` (`routes.php:79-95`):

| Permission | Action |
|-----------|--------|
| `inventory.transfers.receive` | record a receiving session (partial or full, no write-off) |
| `inventory.transfers.writeoff` | finalize with `disposition = write_off` (books a P&L loss) |
| `inventory.transfers.complete` | one-click "receive all remaining, no discrepancy" (kept) |

- **Over-receipt** is not a permission — it is **impossible** (hard cap, D4). If a
  destination genuinely has more physical stock, that is a separate positive
  count-correction (`StockAdjustmentService::adjust()` `:598`), not a transfer
  receipt.
- `return_to_source` disposition needs only `inventory.transfers.receive` (no loss),
  **plus source location access** (D14 — it restocks the source; permission and
  location scope are orthogonal gates, both enforced).
- **Location scope is enforced in addition to permission (D14):** every
  `receive`/finalize/`complete` path checks **destination** access;
  `return_to_source` additionally checks **source** access — mirroring the controller's
  existing checks for `complete` (`:220-223`) and `cancel` (`:253-256`). A user with the
  permission but no location access is still denied (matches
  `StockTransferLocationScopeTest`).
- Default role wiring: warehouse/stock roles get `receive`; only a
  manager/supervisor role gets `writeoff`.

---

## 9. Fiscal / audit / events

Stock movements already emit `StockMovementRecorded` (+V2) inside `receive()`/
`issue()` (`StockAdjustmentService.php:129-162`), which feeds the tier-2 audit log
(compliance.md §"Tier 2"). So each landing, write-off, and return already leaves an
audited movement.

Events (Rule 8 — never mutate existing event classes; add new ones):

- **Keep** `StockTransferInitiated` / `StockTransferCompleted` /
  `StockTransferCancelled` exactly as-is. `StockTransferCompleted` fires on final
  close (unchanged contract).
- **Add** `StockTransferReceiptRecorded` (fired per receiving session). **Immutable
  scalar payload + replay key (finding 15):** `receiptId` (uuid), `idempotencyKey`,
  `transferId`, `tenantId`, `companyId`, `sequence`, `isFinal`, `isReversal`, and a
  list of `{lineId, variantId, batchId, receivedQuantity}` — all scalar strings/ints,
  no models. **Replay anchor = `receiptId`** (unique per applied receipt); a listener
  processes each `receiptId` at most once.
- **Add** `StockTransferShortfallDispositioned` (fired at close when
  `discrepancy_quantity > 0`, once per short line/batch). **Immutable scalar payload:**
  `movementId` (the write-off `issue()` movement), `transferId`, `lineId`, `variantId`,
  `batchId`, `shortfallQuantity`, `unitCostSnapshot`, `discrepancyReason` (string),
  `disposition` (string), `companyId`, `currencyCode`. **Replay anchor = `movementId`**,
  exactly as `GoodsReceived` uses `movementId` (`GoodsReceived.php:20-36`) and
  `createInventoryWriteOffEntry` keys `source_id = movementId`. The
  `PostTransferWriteOffOnShortfall` listener (§7.3) is idempotent on `movementId`, so a
  re-dispatched event does not double-post the shrinkage. `return_to_source`
  dispositions fire the event too (for audit) but the listener posts no GL for them.

No fiscal-chain (tier-1) document is created — transfers are not fiscal documents.
Write-offs are ordinary stock movements + a GL expense posting, not fiscal receipts.

---

## 10. UI sketch (web ERP — NOT a one-click button)

**Receive dialog** (opened from a transfer in `in_transit`/`partially_received`),
using `<QuantityInput>` (emits strings) and `formatQuantity` (rule 19), design
tokens (rule 18), `t()` keys (rule 11):

```
┌─ Receive transfer TR-2026-00042  (Warehouse A → Boutique Centre) ────────────┐
│                                                                              │
│  Product            Sent    Already recv   Remaining   Receiving now         │
│  ───────────────────────────────────────────────────────────────────────    │
│  Doliprane 500mg     10.0000    2.0000        8.0000    [ 6.0000 ]  ⚠ short   │
│     ▸ batch LOT-A (exp 2027-03)   sent 6  recv 1  rem 5   [ 4.0000 ]          │
│     ▸ batch LOT-B (exp 2027-09)   sent 4  recv 1  rem 3   [ 2.0000 ]          │
│  Bioderma 100ml       5.0000    0.0000        5.0000    [ 5.0000 ]  ✓ full    │
│                                                                              │
│  ☐ Finalize (close transfer)                                                 │
│                                                                              │
│  ── shown only when Finalize + a line is short ──                            │
│  Doliprane 500mg short by 2.0000                                             │
│     Reason:      [ Damage ▾ ]                                                │
│     Disposition: (•) Write off (loss)   ( ) Return to source                 │
│                                                                              │
│  Notes: [ Truck 2 of 3 ................................ ]                     │
│                                                                              │
│                       [ Save receipt ]   [ Receive & close ]                 │
└──────────────────────────────────────────────────────────────────────────┘
```

- **Discrepancy badge** per line: `⚠ short` when receiving < remaining, `✓ full`
  when it clears the line, live-computed.
- **Batch expander** only for batch-tracked lines; per-batch remaining caps.
- **Confirm summary** before `Receive & close`: "Landing 11 units; writing off 2
  (Damage); 0 remaining. This books an inventory loss." — explicit, quantified.
- `Save receipt` (partial, `finalize=false`) vs `Receive & close`
  (`finalize=true`). Two buttons — never a single auto-complete.
- Transfer detail page gains a **Receipts** section (append-only list from
  `stock_transfer_receipts`) and per-line `received / sent` progress.
- The write-off disposition control is disabled (with tooltip) for users lacking
  `inventory.transfers.writeoff`; those users can only `return_to_source` or save
  a partial.

---

## 11. Migration & backfill plan

Ordered tenant migrations (each idempotent, Postgres-guarded like the existing
CHECK block at `create_stock_transfers_table.php:96-100`):

1. Add `received_quantity`, `discrepancy_quantity`, `discrepancy_reason`,
   `disposition` to `stock_transfer_lines` (defaults 0/NULL) + CHECKs (§4.1).
   Add `received_quantity`, `discrepancy_quantity`, `discrepancy_reason`,
   `disposition` to the model `$fillable`/`$casts` (`decimal:4` for quantities,
   enum casts for reason/disposition).
2. Add `received_quantity` to `stock_transfer_line_batch_allocations` (§4.2) +
   model cast.
3. Create `stock_transfer_receipts` (§4.3) incl. `idempotency_key`, `is_reversal`,
   the `UNIQUE (tenant_id, company_id, transfer_id, idempotency_key)` index, and a new
   model with fillable/casts.
4. Create `stock_transfer_receipt_lines` (§4.4) incl. `UNIQUE (receipt_id,
   stock_transfer_line_id, batch_id)` and the `received_quantity <> 0` CHECK + model.
5. **Data backfill** (same migration or a follow-up, per tenant DB):
   - `UPDATE stock_transfer_lines SET received_quantity = quantity WHERE transfer_id IN (SELECT id FROM stock_transfers WHERE status = 'completed')`.
   - Batch allocations: same rule scoped to completed parents.
   - Everything else stays at the `0` default.
   - No synthetic receipt rows for history.
6. Widen `TransferStatus` handling — no DB change (column is `varchar(20)`,
   `partially_received` fits `create_stock_transfers_table.php:41-42`); just the enum +
   helper code. **Status-code fan-out (finding 12) — update ALL of:**
   - `TransferStatus` enum + `label()` (add a `partially_received` label; the enum
     currently has 4 cases + labels `TransferStatus.php:15-51`).
   - The controller status filter, which relies on `TransferStatus::tryFrom()`
     (`StockTransferController.php:56-60`) — confirm it accepts the new value and any
     FE filter option list includes it.
   - **Every `status = InTransit` operational-visibility query** must be reconsidered:
     `WeightedAverageCostService` (§7.1), `LocationStockQueryService` distribution +
     incoming (§7.4). Grep for `TransferStatus::InTransit` and
     `'in_transit'` before merge.
   - Frontend: regenerate the TS enum/types via `php artisan typescript:transform`
     (rule 7) so `PartiallyReceived` and the new line/receipt fields exist FE-side.

In-flight `in_transit` transfers at deploy time: `received_quantity = 0`, fully
receivable by the new flow. No manual intervention. Because 7.1/7.4's denominator
change nets `quantity − received_quantity` and those rows have
`received_quantity = 0`, their WAC/incoming contribution is unchanged across the deploy.

Run generated types after DTO changes: `php artisan typescript:transform` (rule 7).

---

## 12. Test plan (TDD — write first, red → green)

**Backend (PHPUnit, `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`;
run by path, never the full suite — MEMORY rule):**

Service (`StockTransferServiceTest` / new `StockTransferReceivingTest`):
1. Full receive in one session → `completed`, `received_quantity = quantity`,
   destination on-hand +sent, no discrepancy. (Parity with today's `complete()`.)
2. Partial receive (8 of 10) → `partially_received`, destination +8, remainder 2
   still in transit; a second receive of 2 → `completed`.
3. Over-receipt (11 of 10) → `TransferOverReceiptException` / 422, no stock moved.
4. Finalize short with `write_off` (Damage) → `completed`, destination +8,
   `write_off` movement −2 at destination, `discrepancy_quantity=2`,
   `StockTransferShortfallDispositioned` fired; **product WAC unit cost unchanged**
   (because neither `receive` nor `issue` touches WAC — §7 corrected proof).
5. **Receive-zero write-off** (§6.3 example): finalize 0 of 10 with `write_off` →
   `completed`, land +10 then issue −10, net destination 0, `discrepancy_quantity=10`,
   `issue()` never throws `InsufficientStockException` (this is the R1 bug regression).
6. **All-lines-short write-off**: multi-line transfer, every line receives 0 →
   `completed`, each line land-then-issue nets 0, no exception.
7. Finalize short with `return_to_source` → destination +8, source +2, no loss, no GL.
8. Batch line partial + per-batch shortfall write-off → per-batch
   `received_quantity`, batch stock at destination net of write-off, batch movement
   audited (`recordBatchMovement` linked).
9. Finalize short WITHOUT a discrepancy entry → 422
   `TRANSFER_DISCREPANCY_UNRESOLVED`, transaction rolled back.
10. Cancel rejected once a receipt exists → 422 `TRANSFER_HAS_RECEIPTS`.
11. **WAC denominator regression** (§7.1): partially-received transfer does not
    double-count landed units in `companyOwnedQuantity` (assert a concurrent
    `recordPurchase` blends against `on_hand + remaining_in_transit`, not
    `on_hand + full_sent`).
12. **`LocationStockQueryService` read-model regression** (§7.4, finding 5): a
    partially-received transfer shows `remaining` (not full sent) as incoming on BOTH
    the product **distribution** view and the location **incoming** view, and a
    `partially_received` transfer is included (not dropped).
13. **Transfer-cost reallocation** (§7.2): 10 sent, 8 received, freight 100 →
    freight capitalized over 8 (the landed units), residual reconciles to exactly
    100 at scale 4; a fully-lost line draws zero freight; fully-lost **transfer**
    expenses the whole `transfer_cost` (no capitalization).
14. **Write-off GL** (§7.3): shortfall write-off posts a `stock_transfer_write_off`
    journal entry Dr COGS / Cr Inventory at `shortfall × unit_cost_snapshot`
    (currency-scaled); a re-dispatched `StockTransferShortfallDispositioned` (same
    `movementId`) does NOT double-post (idempotency); missing COGS/Inventory accounts
    → logged, stock still correct.
15. **Variant threading** (finding 7): a variant-product transfer receive lands on the
    variant-scoped `stock_level`; partial + shortfall write-off on a variant line
    passes `variant_id` through land, write-off issue, and (for return) source receive;
    a variant line NEVER falls through to a product-grain op (would throw
    `VariantRequiredException`).
16. **Expiry-in-transit** (D15, finding 8): a batch whose `canBeSold()` flips to false
    between initiate and receive → landing it as normal `received_quantity` is rejected
    `422 TRANSFER_BATCH_NOT_SELLABLE`; dispositioning it `write_off`(expiry) or
    `return_to_source` succeeds.
17. **Idempotent receive replay** (D12): the same `idempotency_key` POSTed twice
    applies the receipt once and replays the first response; two different keys create
    two receipts (sequence 1, 2).
18. **Concurrency contract** (D13): two concurrent receives on the same transfer with
    **disjoint** products serialize on the transfer-row lock (no over-receipt, no lost
    counter update); deadlock defense preserved: lines [A,B] vs [B,A] do not deadlock.
19. Over-receipt (11 of 10) → `TransferOverReceiptException` / 422, no stock moved.
20. No-op receipt (`finalize=false`, all zeros / empty lines) → 422
    `TRANSFER_EMPTY_RECEIPT`.
21. Pre-final receipt reversal (D19): reverse a partial receipt → `is_reversal` row,
    destination issued back down, `received_quantity` decremented, status may drop
    `partially_received` → `in_transit`; reversing a finalized receipt → 422.
22. Negative-stock invariant: no path drives a `stock_level` negative (write-off is
    land-then-issue, never a bare decrement).

Controller/HTTP (`StockTransferControllerTest` + new `StockTransferReceiveLocationScopeTest`
mirroring `StockTransferLocationScopeTest`):
23. `receive` happy path (partial), permission `inventory.transfers.receive` enforced.
24. `write_off` disposition without `inventory.transfers.writeoff` → 403.
25. FormRequest quantity regex ceiling rejects `8.00001` (scale > 4); missing
    `idempotency_key` → 422.
26. `complete` (legacy one-click) still fully receives and closes; ignores any
    receipt/discrepancy body fields (finding 14).
27. **Location scope (D14, finding 4):** receive on a transfer whose destination the
    user CAN access → allowed; foreign-destination receive → denied; `return_to_source`
    finalize requires source access (denied without it, allowed with it). Mirror
    `StockTransferLocationScopeTest:29-39,254-307`.

**Frontend (Vitest + component tests):**
28. Receive dialog computes remaining and short/full badges from sent −
    already-received.
29. Finalize toggles the discrepancy sub-form; write-off option hidden without the
    permission.
30. `QuantityInput` emits strings; no `parseFloat` on money/qty (ESLint
    `no-parsefloat-on-money` stays green).

**Full-path verification:** initiate a variant + batch transfer, receive partially,
receive short with write-off, confirm destination stock, source stock, WAC, the
`stock_transfer_write_off` GL entry, incoming read models, and the receipts audit list —
end-to-end (rule 5).

---

## 13. Non-goals (explicit)

- **Intercompany transfers** — still rejected at `initiate()` (`:93-99`).
- **Over-receipt / tolerance policy — deliberate stricter-than-industry policy, not an
  omission (industry over-receipt gap).** We **hard-cap** at sent quantity (D4): a
  destination cannot receive more than was dispatched, because every in-transit unit is
  a real costed layer that left source (ADR-0002, no negative/back-valuation). Configurable
  tolerances + manager approval + variance postings are explicitly out of scope; genuine
  physical surplus at the destination is a separate positive count-correction
  (`StockAdjustmentService::adjust()`), which keeps its own audit trail. If the owner later
  wants tolerances, they layer on as a company/location setting + approval gate without
  changing the receipt contract.
- **Back-valuation / estimated-cost recosting** — not built, not needed (ADR-0002).
  Write-off is WAC-neutral because neither `receive` nor `issue` recomputes WAC (§7
  corrected proof), and the inventory-value reduction is booked in the GL (§7.3).
- **In-transit "shrinkage in flight" as its own document/movement type** — considered as
  the D7 alternative and rejected for Phase 1; a shortfall is a destination write-off or a
  source return via existing primitives, not a new fiscal object or movement type.
- **Serial-number-level receiving** — batch/lot granularity only; the repo has no serial
  model, so serials are out of scope (industry gap acknowledged, deliberate non-goal).
- **Multi-destination / split-destination receiving** — one destination per transfer,
  unchanged.
- **Post-final (finalized) receipt reversal (industry reversal gap, partial non-goal).**
  Pre-final partial receipts ARE correctable via a compensating reversal receipt (D19,
  §6.2d). Reversing a **finalized** receipt (transfer already `completed`, GL posted) is
  out of scope for Phase 1 — it requires reversing the shrinkage journal entry and the
  transfer-cost capitalization, which is a dedicated Accounting workflow. Correct a wrong
  finalized transfer today via a fresh count-correction adjustment.
- **Mobile barcode scan-receive UI (industry mobile gap, deferred non-goal).** Phase 1
  ships the desktop dialog. The receive API is idempotent (D12) and addresses lines by
  `line_id` + allocated `batch_id`, so a future scan client (barcode → product/variant,
  GS1 lot/expiry → `batch_id`, scan-to-increment with the same `idempotency_key` merge
  rules) layers on top **without a contract change** — the reason it is safe to defer.
- **Approval workflow for large write-offs** (thresholds, dual sign-off) — deferred; MVP
  gates on the `writeoff` permission only.

---

## 14. Open questions for the owner

1. **Default disposition** — spec assumes `write_off` is the common case (damage in
   transit). Confirm, or should `return_to_source` be the default (treat
   discrepancies as "recount at source first")? *(Still open — UX default only; both
   paths are fully specified.)*

**Resolved during Revision 2 (were open in R1):**

2. **Write-off GL account** — ~~which expense account?~~ **RESOLVED (§7.3):** reuse the
   existing `SystemAccountPurpose::CostOfGoodsSold` (Dr) / `SystemAccountPurpose::Inventory`
   (Cr) wiring via `createInventoryWriteOffEntry()` — the same accounts the batch
   write-off already posts to. No new account configuration. (The Tunisia/France CoA
   mapping for those purposes is an existing tenant-setup concern, not new to this spec.)
3. **Freight on a fully-lost transfer** — **RESOLVED (§7.2):** expense the whole
   `transfer_cost` with the shrinkage (one extra Dr COGS/shrinkage line via the same
   listener), never capitalize it. Not deferred.
4. **Multi-session receiving** — **RESOLVED (§3 D1):** build multi-session now. The
   review flagged single-session Phase 1 as conflicting with the owner bar (ask #3).
   Schema + WAC/read-model fixes + full `partially_received` self-loop + finalize with
   disposition are all in Phase 1 scope.

---

## Revision 2 — Codex review disposition

Every finding from `docs/superpowers/audits/2026-07-02-transfer-granular-receiving-codex-review.md`,
its disposition, and what changed. All findings were **verified against current code**
before acting (the reviewer's `file:line` citations were spot-checked and confirmed).

| # | Finding (severity) | Disposition | What changed / evidence |
|---|--------------------|-------------|--------------------------|
| 1 | Shortfall write-off algorithm fails receive-0 / all-short (`issue()` hard-blocks over-issue, `StockAdjustmentService.php:204-211`) | **Accepted** | §6.3 rewritten: **land the full `remaining` first, THEN issue the shortfall** (D7). Added worked-example table incl. receive-0, receive-8-of-10, all-short. Verified `issue()` throws `InsufficientStockException` when `qty > available` — landing first guarantees `available ≥ shortfall`. |
| 2 | Write-off GL not designed end-to-end | **Accepted** | New §7.3: reuse `createInventoryWriteOffEntry()` (Dr COGS/Cr Inventory, verified `GeneralLedgerService.php:2124-2199`), `PostTransferWriteOffOnShortfall` listener, amount = `shortfall × unit_cost_snapshot` currency-scaled, idempotent on `movementId`, fully-lost freight expensed (§7.2). D18. |
| 3 | Receipt API lacks idempotency key | **Accepted** | D12; §4.3 adds `idempotency_key` + `UNIQUE (tenant_id, company_id, transfer_id, idempotency_key)`; §6.2 requires it and replays. Mirrors verified `stock_transfers.idempotency_key` (`create_stock_transfers_table.php:53-67`) + `initiate()` replay (`:101-113`). |
| 4 | `/receive` misses location scoping | **Accepted** | D14; §6.2 + §8: destination access for receive/finalize, source access for `return_to_source`. Verified controller checks (`:220-223` complete, `:253-256` cancel) + `StockTransferLocationScopeTest`. Tests 27. |
| 5 | In-transit read models (`LocationStockQueryService`) missed | **Accepted** | New §7.4: fix distribution (`:139-165`) + incoming (`:222-244`) to `SUM(quantity − received_quantity)` over `InTransit`+`PartiallyReceived`. Verified both queries use `SUM(quantity)` + `status=InTransit` today. Tests 12. |
| 6 | Wrong WAC-blend claim | **Accepted** | §2.9 new invariant + §7 corrected-proof callout: `receive()` passes no `unitCost` and never calls `recordPurchase` (verified `StockTransferService.php:225-249`, `StockAdjustmentService.php:88-120`). WAC-neutral for the true reason (no WAC touch), value reduction booked in GL. |
| 7 | Variant threading not explicit | **Accepted** | §6.3 variant-threading paragraph + tests 15. Verified variant-aware lines (`add_variant_id_to_stock_transfer_lines.php:26-53`), current pass-through (`:235,248,331,344`), and `StockAdjustmentService` variant rejection (`:77-88,864-872`). |
| 8 | Expiry-in-transit under-specified | **Accepted** | D15; §6.2 `TRANSFER_BATCH_NOT_SELLABLE`; tests 16. Verified `Batch::canBeSold()` is time-dependent. |
| 9 | Discrepancy reasons lose detail / `short_shipment` contradicts model | **Accepted** | D16; §5.2 drops `ShortShipment` (full qty always dispatched at `initiate()` `:383-473`), collapses Loss/Theft/Other→`WriteOff` at the movement, keeps `discrepancy_reason` as reporting SoT. Verified `MovementReason` has no loss/theft/short_shipment cases. |
| 10 | Receipt-line uniqueness / replay not locked down | **Accepted** | §4.4 `UNIQUE (receipt_id, stock_transfer_line_id, batch_id)` + merge-duplicate-scans rule + `received_quantity <> 0` CHECK; D12 idempotency. |
| 11 | Concurrency only partially addressed | **Accepted** | D13; §6.3 concurrency contract: `lockTransfer()` FIRST (validate/sequence/counters under it), then sorted product locks. Verified product locks don't serialize disjoint-product submissions. Tests 18. |
| 12 | Status/backfill must update all status code paths | **Accepted** | §11 fan-out list: enum `label()`, controller `tryFrom` filter (`:56-60`), all `InTransit` queries (§7.1/§7.4), TS regen. |
| 13 | Stale citations | **Accepted** | §1 `complete()` → `:205-235`; added `variant_id` migration + `StockTransferLine` refs; corrected `MovementReason` line cites (24, 26-28). |
| 14 | `/complete` body behavior unspecified | **Accepted** | §6.2b: `/complete` ignores receipt/discrepancy body, self-generates idempotency key, replays if already completed. Tests 26. |
| 15 | Events need replay/idempotency anchors | **Accepted** | §9: both new events get immutable scalar payloads + explicit replay keys (`receiptId`; `movementId` for shortfall, matching `GoodsReceived.php:20-36`). |
| Industry: over-receipt tolerance | — | **Non-goal w/ justification** | §13: deliberate stricter-than-industry hard cap (ADR-0002); surplus via count-correction. |
| Industry: receipt reversal | — | **Partially accepted / phased** | Pre-final reversal specified (D19, §6.2d, tests 21); post-final reversal explicit non-goal (§13, needs GL-reversal workflow). |
| Industry: concurrent receiving | — | **Accepted** | D13 + D12 (idempotency key + transfer-row lock). |
| Industry: mobile scan-receive | — | **Non-goal w/ justification** | §13: desktop first; idempotent, `batch_id`-addressable API means a scan client layers on with no contract change. |
| Industry: multi-session partial | — | **Accepted (built now)** | D1 / §14 Q4 resolved — no single-session deferral. |
