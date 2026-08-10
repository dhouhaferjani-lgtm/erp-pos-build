# Workshop work orders have NO goods lane — WO parts move no stock, produce no COGS, and now hold a fiscal exemption

**Severity:** HIGH (fiscal + inventory + GL). **Pre-existing**, made VISIBLE and
load-bearing by DPA Wave 3 sub-wave 3E.
**Raised by:** 3E code gate — fiscal **F-1**, 2026-08-10; **ORCHESTRATOR RULING
S3 (b)** ("recorded, TESTED exemption + ticket").
**Route:** DPA program backlog. **Owner/expert visibility required** — this is a
revenue-recognition and inventory-valuation question, not a refactor.
**Vertical:** Otospex (workshop).

---

## The gap

A work order that consumes physical parts generates an invoice
(`WorkOrderTransitionService` → `DocumentGenerationAdapter::generateInvoice()`)
and **nothing else**. There is no delivery note, no stock issuance, no stock
movement, and therefore no COGS attribution for the parts it consumed.

Verified on `feat/dpa-wave3-3e`:

- `DocumentGenerationAdapter::generateInvoice()` — builds Draft → Confirmed →
  `DocumentPostingService::post()`. It maps `product_id` onto the document lines
  (`mapLine()`), so the parts ARE identified; nothing consumes them.
- No Workshop code path calls `DeliveryNoteService`, writes a `StockMovement`, or
  creates a `DocumentType::DeliveryNote`.

Consequences, today, with no wave-3 change at all:

1. **Revenue without goods movement.** The invoice recognises revenue for parts
   whose units never left inventory in the ledger's view.
2. **No COGS post-cutover.** Every inventory-GL detector in the D-a…D-g family
   keys on a `stock_movements` row. A WO part produces none, so the missing COGS
   is invisible to all of them — including D-f, whose whole purpose is to find
   goods that moved no stock, because D-f scans documents that are *supposed* to
   move stock (confirmed delivery notes) and a WO invoice is not one.
3. **Inventory drifts silently.** On-hand quantity never decrements for parts
   fitted to vehicles.

## Why 3E did not fix it — and what 3E DID do

T25b put the delivery requirement on the fiscal posting chokepoint
(`DocumentPostingService::post()`), which is correct: every caller must pass it.
The sweep missed the second production caller, the WO adapter. Because the WO
module has no issuance lane, `hasEverIssuedGoods()` is false for every
WO-generated invoice **by construction**, and the refusal had **no reachable
compliant path**: the composite `create-delivery-and-post` endpoint needs a
Confirmed invoice over HTTP, while the WO transition creates → confirms → posts
inside one service transaction.

Refusing would have blocked a real repair job on a fact the system cannot record.
Auto-creating a delivery note there would have **fabricated a goods movement that
never happened** — the worse of the two, on a hash-chained document.

The ruling was therefore a **narrow, recorded, tested exemption**, shipped in fix
round 1:

- `PostingContext::WorkOrderGeneratedInvoice`, claimed explicitly at the single WO
  call site (`DocumentGenerationAdapter::generateInvoice()`);
- honoured only when the DOCUMENT also carries `work_order_id`
  (`DocumentPostingService::isExemptFromDeliveryRequirement()`), so the enum alone
  is not a master key;
- exempting exactly ONE verdict, `DeliveryRequiredBeforeInvoice` — draft notes,
  incomplete delivery and "order with no notes" still refuse;
- **recorded on the T25e audit stamp**: `posting_context` and
  `delivery_requirement_exempted`, so the exempted population is findable;
- pinned by `tests/Feature/Document/WorkOrderInvoiceDeliveryExemptionTest.php`,
  including both boundaries (a non-WO standalone invoice still refuses; claiming
  the context for a non-WO invoice still refuses).

**The exemption is a holding action, not a resolution.** It should be DELETED when
the goods lane below lands.

## What the fix has to build

1. **A WO parts issuance lane.** A work order that consumes parts must produce a
   real goods-issue document (per the document-per-action principle: every stock
   mutation needs its own justifying document). Open design question for the
   owner/expert: is that a delivery note against the customer, or an internal
   consumption/issue document against the work order? A repair fits parts to a
   vehicle — that is arguably consumption, not delivery, and the answer changes
   both the GL treatment and the fiscal document type.
2. **COGS attribution** for those movements, joined to the inventory-GL seam 3C
   builds (`InventoryGlSourceTypes`, the `inventory_gl_cutover_at` watermark).
3. **A D-f WO arm** once the lane exists: WO invoice lines carrying a physical
   product with no corresponding movement. Until the lane exists this arm would
   fire on every historical WO invoice in every tenant, which is why it is not
   built now — the same reasoning that defers D-a/D-b/D-e/D-g to 3C.
4. **Delete the exemption**: remove `PostingContext::WorkOrderGeneratedInvoice`,
   its branch in `isExemptFromDeliveryRequirement()`, and the WO call-site claim.
   The stamp's `delivery_requirement_exempted = true` flag identifies the legacy
   population needing accountant disposition.

## 🚩 Blocked-adjacent: the WO invoice path is ALSO red for another reason

While verifying F-1, the three Workshop adapter test classes were run to green.
Removing the delivery refusal was not sufficient: **5 of the 9 tests remain red on
`UnpostableDocumentGlException [negative_residual]`**, and that failure is
PRE-EXISTING at the lane base `6626cb373` (3E modifies no Accounting service, no
`DocumentLine`, no `DocumentTotalsCalculator`, and no Workshop file other than the
one-line exemption claim).

Probe executed: changing `DocumentGenerationAdapter.php` `'line_total' =>
$wol->line_total_incl_tax` to `line_total_excl_tax` — the exact one-line fix
prescribed by `2026-08-07-wo-quote-tax-inclusive-line-total.md` — turns 4 of the 5
green. The change was **reverted**; it belongs to that ticket's own urgent lane,
which also owes a legacy-row assessment.

The 5th (`wo invoice preserves above tolerance line discount`, residual `-6.00`)
survives even that fix: the adapter derives `discount_amount` from the WO line's
`discount_percent` while still writing the WO's own `line_total`, so a surviving
discount leaves the header recompute and the stored column disagreeing. **That is
a second, unticketed WO totals defect** and should be folded into the same lane.

Net effect for the DPA gate: F-1's own remedy is complete and tested, but the
Workshop invoice path cannot reach fully-green tests until the WO totals lane
lands.

## Cross-references

- `2026-08-07-wo-quote-tax-inclusive-line-total.md` — the CRITICAL totals defect
  above; blocks Workshop invoice posting outright.
- 3E gate: `gate-w3e-code-review-fiscal.md` F-1 (blocking), and the S3 ruling.
- Document-per-action principle: every stock/GL/cash/fiscal mutation needs its own
  justifying document.
