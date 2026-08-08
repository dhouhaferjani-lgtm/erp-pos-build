# Document-per-action principle — violation sweep (2026-08-08)

**Trigger:** owner directive 2026-08-08, following ruling R-c c1 ("stock and money are
separate lanes; every economic mutation carries its own justifying document") and the
confirmed COGS-at-invoice mismatch (`2026-08-07-cogs-lane-mismatch.md`). Owner asked: is
the principle violated anywhere else, and what cements it for the future?

**Method:** very-thorough read-only sweep of `apps/api` (listeners/providers wiring
document lifecycles to stock/GL/treasury writers; raw writers callable without a document;
console commands/jobs). Orchestrator spot-verified the Tier-1 headline findings against
the cited lines before committing this register (verification notes inline).

**Status: findings register — NOT yet fix lanes.** Each lane cut from this register gets
its own adversarial gate per standing review rules.

---

## Tier 1 — fiscal / GL integrity

### V1 — `test:e2e-gl-posting` hard-deletes sealed GL on the live connection ⚠️ TOP
`app/Console/Commands/TestE2EGLPosting.php:28` (signature), cleanup `:201-208`.
Auto-registered artisan command; fabricates a real Invoice + CreditNote, posts them, then
`$invoiceGLEntry->lines()->delete(); $invoiceGLEntry->delete(); …` — the ONLY path in the
codebase that physically deletes `journal_entries`, punching holes in the GL chain.
✅ ORCHESTRATOR-VERIFIED verbatim. **Disposition: delete the command (or hard-gate to
local env) — smallest lane on the list.**

### V2 — opening-balance IMPORT posts unsourced, unchained, single-legged JEs
Writer `app/Modules/Accounting/Application/Services/AccountingService.php:382-395`
(`createOpeningBalanceEntry`), caller `app/Modules/Import/Services/ImportService.php:595/614`.
Posted entry per CSV row: no `source_type`/`source_id`, no chain fields, no
`is_historical`, ONE line (unbalanced by construction). Compliant sibling exists:
`AccountingOpeningService.php:193-232` (batch-sourced, historical, OBE plug).
✅ writer shape ORCHESTRATOR-VERIFIED. **Disposition: route the import through the
batch-document path; kill the raw writer.**

### V3 — treasury repository adjustment: GL + cash movement sourced to a UUID that references NOTHING
`app/Modules/Treasury/Presentation/Controllers/RepositoryAdjustmentController.php:102`
mints `$adjustmentId = Str::uuid()` inline; JE (`source_type='repository_adjustment'`) and
`MovementSourceType::Adjustment` both point at it; **no `repository_adjustments` table
exists anywhere** (migrations grep empty). Linkage shape right, referent nonexistent.
✅ ORCHESTRATOR-VERIFIED (incl. table absence). **Disposition: introduce the adjustment
document row (id = the sourced UUID), lifecycle + reason codes.**

### V4 — `reversePayment` DELETEs the lineage's allocations; no reversal document
`app/Modules/Treasury/Domain/Services/PaymentRefundService.php:629` (entry), `:686`
(`PaymentAllocation::whereIn(...)->delete()`), balance/status rewrites in place; no
reversing Payment row; cash payments (no instrument) restore AR with ZERO journal entries.
Compliant sibling IN THE SAME FILE: `refundPayment()` `:155` (negative child Payment,
`original_payment_id`, mirrored negative allocations, refund JE).
✅ allocation-delete ORCHESTRATOR-VERIFIED. **Disposition: reversal emits a linked
reversing Payment + JE; originals never mutated. Treasury gate mandatory.**

### V5 — manual JEs are SELF-sourced (`source_id = self`) — the exact c4 hole
`app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:96-107`:
`source_type='manual'`, then `source_id = $entry->id`. Free-floating corrections with the
linkage shape satisfied and zero justification. The codebase already flags it
(`UnreversibleDocumentGlException.php:27`).
✅ ORCHESTRATOR-VERIFIED verbatim. **Disposition: this IS the F4 correcting-document
lane's target — mandatory link to a corrected original / justifying document.**

## Tier 2 — stock lane: units move without a justifying document

### V6 — `upsertStockLevel` absolute overwrite: no movement row, no document, reserved zeroed
`app/Modules/Inventory/Application/Services/InventoryService.php:30-65`
(`StockLevel::updateOrCreate` absolute set), caller `ImportService.php:564/580`.
Leaves NO `stock_movements` trace — invisible to every ledger-based detector.

### V7 — raw HTTP stock writers: receive / issue / transfer / adjust
`app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:91/132/188/248`;
routes `Inventory/Presentation/routes.php:84-118`. Justification = free-text `reference`;
no document, no lifecycle. The entry/exit-note register itself labels them
`'manual'` (`EntryExitNoteController.php:41`). `adjust` = absolute quantity set behind
`inventory.adjust`.

### V8 — supplier credit note (money doc) ISSUES stock for bonus goods-return lines
`app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:664-668` (raw
`StockLevel` decrement + `Issue` movement referencing the CN). Ordinary goods-return lines
move NO stock (only `quantity_invoiced`) — one CN reason, two lane behaviors. Also
bypasses `WeightedAverageCostService` (asserts WAC instead of recomputing). Supplier-side
mirror of the COGS-at-invoice defect; needs its own exit/goods-return note.

### V9 — POS void: in-place receipt mutation drives restock + cash refund; no void document; NO GL
`app/Modules/POS/Application/Services/ReceiptVoidService.php:99/113-125/135/138/203`.
Original receipt edited in place; restock + batch reversal + drawer refund fire off it; the
only separate artifact is the downstream NF525 `ANNULATION` fiscal event (gray-compliant
half). `createPOSRefundReversalEntry`'s only caller is the fiscal-event-driven
`TreasuryReceiptBridge.php:1352` — NOT this path → no GL reversal on void.

### V10 — POS SCRAP return: inventory destroyed on the return receipt's lifecycle
`app/Modules/POS/Application/Services/ReceiptReturnService.php:1341/1371`
(`WriteOff` movement, `pos_receipt_return_scrap`). A return note justifies RE-ENTRY, not
destruction; no write-off document, no WAC touch, no GL. Compliant sibling:
`BatchWriteOffService.php:82/:106` (adjustment via service + Dr COGS / Cr Inventory).

## Tier 3 — GRAY: document exists, GL leg missing (c1-bis family — route to expert decision)

- **G1 — POS sales never book COGS at all:** `createCOGSEntry` (`GeneralLedgerService.php:1679`)
  has exactly one caller, `PostCOGSOnInvoice` (Invoice-gated). POS exits
  (`PosCoreReceiptProjection.php:1765`, `ReceiptCreationService.php:959`) relieve no GL
  inventory. BIGGER than D1/D2 for a retail tenant. Feed into c1-bis: if COGS moves to
  stock-exit, POS projections become the COGS trigger for free.
- **G2 — counting adjustments write no GL** (`StockAdjustmentService` has zero
  GL references): shrinkage/gain never reaches 603x, even on the document-compliant
  counting path (`ApplyStockAdjustmentsOnCountingCompleted.php:188/246`).
- **G3 — shift-close cash variance has no GL leg** (`ShiftManagementService.php:129-213`);
  tolerance accounts already exist (`SystemAccountPurpose::PaymentTolerance*`) — unwired,
  not unmodeled.

## Compliant house idioms (cite these in fix lanes)

- Best-in-repo: `RefundCompensationService.php:271-340` (own row + fiscal event + JE +
  movement, all cross-linked, operator attestation).
- Contra-record reversal, original untouched: `ResetOpeningBalanceService.php:107`,
  `ReverseWriteOffService.php:150` (+ partial-unique `reverses_movement_id` race guard).
- Cancellation as new chained entry keyed to source: `DocumentPostingService.php:214` →
  `AccountingService.php:933-944` (`DOCUMENT_CANCELLATION`).
- Batch-documented opening balances: `AccountingOpeningService.php:219-232`,
  `OpeningBalancePostingService.php:232-244`.
- Movement port carrying source + JE link: `TreasuryDepositBridge.php:249-269`.
- Lane-correct by construction: customer CN confirm touches money only
  (`CreditNoteController.php:233-300`, zero stock references).
- Refund as linked child document: `PaymentRefundService.php:155`.

## Proposed triage → lanes (owner-visible)

1. **V1** delete/gate the GL-deleting command (trivial lane, fiscal-pos gate).
2. **V3 + V2 + V5** "GL source integrity" lane(s) — fake/absent/self-referential sources;
   V5 folds into F4 (correcting-document type, already ruled c4).
3. **V4** treasury reversal-document lane (treasury gate).
4. **V6 + V7** stock-writer containment (document-or-refuse; imports routed through
   opening-balance/counting documents).
5. **V8, V9, V10** own-document lanes (supplier goods-return note; POS void receipt + GL;
   write-off document) — V9/V10 touch POS/fiscal surface → fiscal-pos gate.
6. **G1-G3** attach to c1-bis expert answer (COGS placement) — decides the whole family.
7. **Cementing guard (after fixes):** architecture test forbidding
   `JournalEntry::create` / `StockMovement::create` / `StockLevel` writes outside
   document-keyed services with non-self `source_*` linkage (PHPStan rule or deptrac
   layer + pinned test), so this class can't regrow.

**Not dispatched yet — awaiting owner priority call vs launch program (several Tier-1/2
items predate launch and touch launch-critical surfaces; V1/V3/V5 are cheap and high-value).**
