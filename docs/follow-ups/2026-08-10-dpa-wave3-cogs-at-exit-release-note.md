# Wave 3C — COGS-at-inventory-exit deploy and operations note

**Scope:** DPA Wave 3C movement-keyed inventory GL cutover.
**Deployment posture:** forward-only; branch artifacts are not themselves a
promotion authorization.

## Blocking pre-promotion script

Run `scripts/preflight-wave3-inventory-gl-cutover.sql` against **every
deploy-target tenant database**, separately, and retain the tenant identifier
with all three result sets. Promotion requires:

1. `legacy_invoice_cogs_count = 0`. A non-zero count means legacy invoice-time
   COGS exists; stop and escalate for reversal and re-derivation.
2. zero duplicate rows for every source type in `InventoryGlSourceTypes::ALL`:
   `inventory_exit`, `inventory_entry`, `inventory_shrinkage`,
   `batch_write_off`, and `batch_write_off_reversal`. This is the hard gate in
   `docs/superpowers/tickets/2026-08-18-inventory-gl-cutover-duplicate-precondition.md`.
3. zero pre-T4 delivery movements on non-physical product lines. A non-zero
   R-11 result requires accounting remediation before cutover because those
   units have no legitimate return path.

The M0 local R-11 run had a zero denominator and is **vacuous**. It is not
deployment evidence for either the R-11 or duplicate-count gate. Do not promote
until the real per-tenant outputs are recorded.

## 1. Forward-only rollback and no backfill

The detector can stop the next deployment; it never authorizes `git revert`
after new Posted, hash-chained entries exist. The only sanctioned inventory-GL
rollback mechanism is the operator-invoked
`accounting:reverse-inventory-movement-entries --from=<timestamp> --confirm`
command (T19b). It posts exact compensating entries and is never called by the
normal application path.

There is no historical backfill. The retired invoice listener had one caller,
`InvoicePosted`, and provisioning/seeders do not post invoices through that
runtime path. The blocking per-tenant `source_type = 'cogs'` count verifies the
premise before deployment. Already-applied POS receipts are deliberately not
replayed into new COGS entries.

## 2. Cutover watermark and detector scope

Each company carries `inventory_gl_cutover_at`, seeded idempotently by the
deploy step; newly provisioned companies receive their creation timestamp.
Checks D-a, D-b, D-e, and D-f report only sources at or above that watermark.
Pre-cutover movements are out of scope by design, so historical rows do not
turn the detector into a permanent alarm. D-a alone has a two-hour grace window.
The POS D-f missing-movement arm has no grace window.

POS lines also carry the immutable projection decision
`stock_movement_expected`. D-f ignores `not_received` and regulated
never-restock refunds, product-level sale lines with no stock grain at the
terminal location, and archived-product scrap refunds whose writers
deliberately produced no movement. A missing variant-scoped grain remains an
anomaly: the writer warns and D-f reports it. Later catalogue policy edits do
not reclassify those historical outcomes.

Until 3D T21 wires count-correction GL at the real counting root, D-e excludes
`reference_type = inventory_counting`; otherwise every completed count would
be a permanent false alarm during the 3C-to-3D interval. T21 must remove that
temporary exclusion in the same change that makes the count writer live; this
is pinned by
`docs/superpowers/tickets/2026-08-18-remove-counting-detector-exclusion-with-t21.md`.

## 3. POS cutover behavior

An already-applied pre-cutover receipt never regains COGS on replay. Pending or
dead-lettered receipts first applied after deployment use the movement-keyed
seam and do book COGS. Refunds use the original sale movement's exact
`unit_cost` at receipt + product + variant grain; the receipt-line snapshot is
only the missing-movement fallback.

## 4. Failure behavior on shipped paths

Before Wave 3, a scrap pair whose GL leg failed was contained to a log. Wave 3
deliberately preserves that contained behavior for both interactive and
projected receipt paths so the two do not diverge.

### 4a. Closed periods have two operational signals

A closed-period synchronization is ledger-consistent but not
operations-consistent: expect **one contained COGS log line** and **one
dead-lettered `TreasuryReceiptBridge` revenue row after retries are exhausted**.
The treasury dead letter is not, by itself, a new Wave 3 inventory defect.

### 4b. Receipt containment is all-or-nothing

One bad inventory line discards every buffered inventory entry for that receipt.
D-a therefore reports the whole receipt's movement population, not just the
line that first failed.

### 4c. Return scrap posts at the flush

T16d changes when the shipped V10 `batch_write_off` entry is posted, not what it
writes. It now posts at the owning receipt's terminal flush instead of
mid-loop. Its source type, amount, accounts, and source id are unchanged.

## 5. Named follow-ups and owners

- **D-20, known document-per-action violation:** non-batch stock-adjustment
  document lines still have no GL leg while batch-tracked lines do. Owner:
  Inventory + Accounting architecture. Ticket:
  `docs/superpowers/tickets/2026-08-18-stock-adjustment-document-gl-leg.md`.
- **D-21, source-type consistency:** return scrap deliberately remains
  `batch_write_off`. Owner: Inventory + Accounting architecture. Ticket:
  `docs/superpowers/tickets/2026-08-18-scrap-inventory-gl-source-unification.md`.
- **Delivery-gate / §0.14 residual:** 3E unified both linkage shapes, but the
  ruled Workshop exemption remains until a real parts goods lane exists. Owner:
  Workshop + Inventory. Ticket:
  `docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md`.
- **`reverseDocumentGl` chain allocation:** its pre-existing reversal path has
  no company advisory. Owner: Accounting/fiscal ledger. Ticket:
  `docs/superpowers/tickets/2026-08-18-reverse-document-gl-advisory.md`.
- **Quantity precision trigger:** close
  `docs/superpowers/tickets/2026-08-10-float-on-wac-exit-path.md` before any
  product unit above four decimal places ships.
- **D-f immutable classification:** all three missing-movement arms currently
  use the live product physical flag because there is no movement snapshot to
  read. Owner: Inventory + Product architecture. Ticket:
  `docs/superpowers/tickets/2026-08-18-df-immutable-physical-snapshot.md`.
- **POS location stock-tracking classification:** product-level POS lines use
  exact location-grain presence as the temporary stock-tracked signal; missing
  variant grains remain reportable. Owner: Inventory + Product architecture.
  Ticket:
  `docs/superpowers/tickets/2026-08-18-pos-location-stock-tracking-classification.md`.
- **Goods-receipt reason coverage:** real inbound WAC movements carry no
  movement reason, while GR-IR remains document-keyed. Owner: Procurement +
  Inventory + Accounting architecture. Ticket:
  `docs/superpowers/tickets/2026-08-18-goods-receipt-movement-reason-detector-gap.md`.

## 6. Known fiscal-chain verifier false tamper

Do not attribute the existing `fiscal:verify-chains` false-tamper P1 to this
cutover. It is recorded in
`docs/handoff/HANDOVER-dpa-session2-2026-08-09.md` under the routed main-session
items (the original line-100 citation). Investigate that verifier lane on its
own authority; do not rewrite Posted inventory entries to satisfy it.

## 7. 3E delivery-first behavior

Under `require_delivery_first`, a definitive goods invoice must be backed by a
confirmed delivery note before posting. The unified resolver reads both the
sales-order `delivery_note_ids` shape and the converted/standalone invoice
`source_delivery_note_ids` shape. The guided standalone endpoint creates and
confirms the delivery note, records that linkage, then posts the invoice in one
root frame; its C-5 tail flushes all movement entries before commit. Recorded
Workshop exemptions remain visible in the detector rather than being treated as
unexplained holes.
