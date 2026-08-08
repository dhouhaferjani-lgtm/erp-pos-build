# DPA remediation sizing — stock/POS half (V6-V10, G1, cancel-flow UX) — 2026-08-08

Read-only sizing assessment by dispatched agent; citations verified-by-open. Companion:
`2026-08-08-dpa-fix-sizing-gl-treasury.md`. Register: `2026-08-08-document-per-action-violation-sweep.md`.
UX ruling: `docs/superpowers/specs/2026-08-08-cancel-flow-guided-return-ux-ruling.md`.

## Cross-cutting facts (drive every estimate)
1. **S0 — the shared seam:** `stock_movements` HAS morph linkage columns
   (`reference_type`/`reference_id`, `2025_12_24_133827:18-27`) but
   `StockAdjustmentService::recordMovement()` (`:1161-1216`) cannot write them (free-text
   `reference` only) — so even the counting path is document-UNlinked
   (`ApplyStockAdjustmentsOnCountingCompleted.php:194-202` passes a label, not a FK).
   Fix once (~0.5d, threads through all 5 entry points: receive:83, issue:201, transfer:317,
   adjust:618, applyCountResult:729); V7/V8/V10 and the §7 cementing guard all need it.
2. No generic stock-adjustment document table exists (only inventory_countings family).
3. Movement-keyed JE is an accepted idiom (`createInventoryWriteOffEntry` sources
   `source_id=$movementId`, `GeneralLedgerService.php:4380-4382`) — movement-as-document is
   legitimate for write-offs; cheapens V10.
4. **`DocumentStatus` has NO "closed" state** (Draft/Confirmed/Posted/Paid/Received/Cancelled).
   Return notes go Draft → Confirmed only (`ReturnNoteController.php:563-580`). Ruling
   mapping: "open" = Draft; "confirmed+closed behind the scenes" = Confirmed.
5. **VOID is not device-authorable** (`FiscalEventPayloadRegistry.ts:146-152,237-238`
   throws) — no POS SQLite migration, no device release for V9. Device schema at v67.
6. `POST /pos/receipts/{id}/void` has ZERO frontend callers (web + pos grep).

## V6 — stock-level import → opening-balance document — **S (opt c/b) / M (opt a)** · inventory-costing gate
Compliant sibling IN the Import module: `ProductOpeningStockPhase.php:85-101` →
`OpeningBalancePostingService::post()` (Opening movement + WAC + GL + enter-once guard).
DESIGN Q: stock_levels CSV has NO cost column (`ImportType.php:28,60,110-114`). Options:
(a) add required unit_cost (breaking); (b) fall back to product.cost_price, refuse zero-cost
rows; **(c) RECOMMENDED: deprecate the stock_levels import type — products import already
carries quantity+purchase_price+location and uses the compliant phase.** Blast: one caller,
one interface method (`InventoryServiceInterface.php:19`), arch pin
`InventoryCostLockCoverageTest.php:41` (REMOVE the upsert assertion), FE import card.
RISK/product decision: enter-once guard changes re-import from silent-overwrite to
refuse-on-second-run (`OpeningAlreadyExistsException`) — correct but user-visible;
`ResetOpeningBalanceService` is the reset affordance.

## V7 — four raw stock writers — **L** · inventory-costing + frontend-conventions — needs S0 + adjust-document ruling
Per-endpoint disposition: `transfer` = delete-and-redirect (document-backed
`StockTransferController` already shipped + FE-wired); `receive`/`issue` = document-backed
wrappers (goods receipt / delivery-consumption); `adjust` = cannot refuse — needs NEW
lightweight `stock_adjustments` document (reason_code from existing `MovementReason`
vocabulary, note, actor, occurred_at, lines). Store the DELTA + observed-before, NOT the
absolute target (`:643-646` computes difference; async authoring of absolutes = lost-update
race). Sole FE caller for all four: `StockLevelsPage.tsx:89/101/113/125` (one modal —
becomes four flows). Payoff: `EntryExitNoteController.php:41` COALESCE starts resolving
documents automatically. If c1-bis lands COGS-at-exit, `issue` wrapper should emit a domain
event now (listener later).

## V8 — supplier goods-return note — **M units / L with AP modal** · inventory-costing gate — GL half blocked on c1-bis
`issueBonusReturnStock()` (`SupplierCreditNotePostingService.php:591-688`) raw-writes stock
and ASSERTS WAC unchanged (`avg_cost_before=avg_cost_after=$unitCost`, `:670-681`) — wrong:
returning zero-cost bonus goods should RAISE remaining WAC (they diluted it on entry);
silent cost misstatement invisible to drift detectors. Ordinary goods-return lines move NO
stock (`:412`, quantity_invoiced only) — one CN reason, two lane behaviors. Fix: supplier
goods-return/exit note carrying both line kinds, confirm → `StockAdjustmentService::issue()`
(cost-locked, WAC-correct) + S0 linkage; GL hook exists
(`createSupplierCreditNoteEntryWithBonusReturn` `:206-212`, extend to ordinary lines —
c1-bis-gated). DESIGN Q: separate user-confirmed document vs auto-emitted-but-visible on CN
post → the ruling's generalization clause says guided-modal (same pattern as cancel flow,
applied to AP). Pin: `SupplierCreditNoteGlTest.php` (1414 lines) — substantial rework.

## V9 — POS void — **M via fiscal-event rail / L bespoke** · fiscal-pos gate — ⚠️ RULE ON SUNSET FIRST
`LegacyCorrectionGuard.php:35-40` already RETIRES voidReceipt for any terminal with
`v4_refund_authoring_acknowledged_at` — void already throws on modern terminals, zero FE
callers. CHEAPEST OUTCOME: rule void sunset → V9 shrinks to retiring the endpoint.
If kept: (i) original keeps ONLY the `fiscal_status→Voided` flip (PG immutability trigger
requires it, `:93-98`); negative void-receipt mirror row à la returns; (ii) restock via
`StockAdjustmentService::receive()` + `reverses_movement_id` contra
(`ReverseWriteOffService.php:135-150` idiom incl. partial-unique race guard) — today raw
writes, no cost/WAC (`:161-222`); (iii) GL: author a server-side fiscal event
`invoice_type_code='VOID'` → flows through the EXISTING `TreasuryReceiptBridge` refund rail
(`:374`, `:1351-1410`: GL reversal + drawer OUT + idempotent legs) for free. VERIFY FIRST:
device `FiscalEventEngine` chain verification accepts a server-authored VOID in the
terminal chain — the one place a device release could sneak in. Restock semantics must
match the v4 disposition model or legacy-void vs v4-refund of the same receipt diverge.

## V10 — SCRAP write-off — **M (S if projection divergence ticketed separately)** · fiscal-pos + inventory-costing
Swap `writeOffReturnedStock()` raw block (`ReceiptReturnService.php:1340-1391`; explicitly
no cost/WAC/GL) for the `BatchWriteOffService::writeOff()` chain (`:59-131`): issue() with
resolved unit cost + `createInventoryWriteOffEntry` (Dr COGS / Cr Inventory keyed on
movementId). No new table (fact 3). ⚠️ TWO CONTRADICTORY SCRAP SEMANTICS TODAY: server
return API = restore(+) then write-off(−), two legs (`:452-460`); v4 device-refund
projection = SKIP entirely (`PosCoreReceiptProjection.php:1841-1844`). Canonicalize the
two-leg version (auditable, carries GL); make the projection match. c1-bis-INDEPENDENT
(write-off GL is settled repo doctrine). Currently unreachable from device
(`refundCheckoutStore.ts:350` hardcodes 'restock'; no picker UI) — land server fix BEFORE
the picker ships.

## G1 — POS COGS — **L** · inventory-costing + fiscal-pos — TOTAL c1-bis dependency, DO NOT START
`createCOGSEntry` has one caller (`PostCOGSOnInvoice.php:73`, Invoice-gated `:45-48`); POS
exits (`PosCoreReceiptProjection.php:1765`, `ReceiptCreationService.php:959`) relieve no GL
inventory → GL inventory grows monotonically, margin fictitious. Perpetual answer: move
trigger to `StockMovementRecordedV2` on exit reasons — POS covered for free, D1+D2 fixed by
the same relocation, G2+V10-GL collapse into the same seam. Periodic answer: three separate
builds instead. Either way ADD A DETECTOR (docs with physical lines + no COGS entry):
`PostCOGSOnInvoice` is log-never-block (`:105-113`) and silently skips zero-cost products
(`:145-152`) — COGS is already non-deterministically missing; migration would paper over
holes. Backfill of history = separate question. Blast: all GL/margin tests + P&L/margin FE.

## Cancel-flow guided-return UX — **L** · frontend-conventions + fiscal-pos
What exists: cancel BE (`RefundController::cancelInvoice` `:43-80` → chained-entry
cancellation; paid → DOCUMENT_HAS_PAYMENTS refusal); preflight `GET can-cancel` with
reason_code (`:190-214`); RN create Draft w/ source_document_id
(`ReturnNoteController::store` `:246-379`); RN confirm = stock + WAC + fiscal hash seal
(`ReturnNoteService::confirm` `:59`).
**HEADLINES:** (1) NO cancel-invoice UI exists on web AT ALL (`DocumentActions.tsx` has the
button but ZERO consumers; no FE call to any /cancel route) — building the action itself,
not decorating it. (2) `CreateReturnNotePage.tsx:218-239` posts a contract
(`source_invoice_id`, `auto_create_credit_note`, `lines[].line_id`) the backend
(`CreateDocumentRequest.php:66-125`) doesn't accept (needs partner_id, dated, lines min:1
w/ description+qty+unit_price; knows only source_document_id) — full-return submit = 422.
REPAIR PREREQUISITE; 10-min live check owed (either never exercised from web or a hidden
transform). (3) Backdated confirm (option 2 date) = FISCAL CHANGE: `confirm()` consults NO
period lock and seals with now() (`:124`) — needs explicit-date threading + period guard.
Fix shape: `POST /invoices/{id}/cancel` gains `return_decision {mode, returned_on?, lines?}`;
one transaction: cancel → RN store (real path) → (already_returned) real confirm; extract
store/confirm bodies into ReturnNoteService methods both callers share (no side-channel
writers, per ruling); record no_return choice in invoice payload alongside
cancellation_reason (`RefundService.php:44-47`). Deadlock check: RN confirm takes all
ProductCostLocks up front (`:83-93`) inside the invoice-cancel transaction.
GL half of the confirmed RN = c1-bis (explicitly unblocked for UX per
`2026-08-07-cogs-lane-mismatch.md:26`).

## Dispatch plan (this half)
```
Wave 0: S0 recordMovement linkage (~0.5d) — prerequisite for V7/V8/V10 + cementing guard
Wave 1 (parallel, no c1-bis): V6(S) · V10(M) · V9(M, AFTER sunset ruling) · cancel-flow(L, start first)
Wave 2 (after S0): V7(L, after adjust-doc ruling) · V8-units(M)
Wave 3 (after c1-bis): G1 (+G2, V8-GL, RN-GL — perpetual answer collapses them into one listener)
```
DEVICE RELEASES: none needed for any item (verify the V9 server-VOID chain-acceptance).
Totals excl. G1: ~12 dev-days; ~7 calendar days at 3-4 lanes; +40% wall-clock on fiscal
gates (historically 2-4 rounds).
Escalations: V9 sunset ruling (cheapest saving on the list); CreateReturnNotePage live check.
