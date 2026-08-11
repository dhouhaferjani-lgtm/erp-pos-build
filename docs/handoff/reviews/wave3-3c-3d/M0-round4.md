## M0 adversarial gate — round 4 register

**Lenses.** **inventory-costing** — applies (D‑19 predicate register, D‑28 sweep, GR movement anchor, R‑11 probe, T11c fixtures, V10 inv‑I1 site). **fiscal-pos** — applies (POS projection flush anchor, R‑1 refund cost basis, transaction‑depth sweep). **Rule 19 / i18n / migrations / Horizon queues / tenancy‑authz / constructor injection** — **N/A, verified**: `26b63f0ff..HEAD` is 9 files under `docs/` + `scripts/` only; no production code, no money/quantity arithmetic, no migration, no `onQueue`, no user‑facing string, no container use. ✅ scope‑clean.

**Round‑3 dispositions I re-verified independently (all genuinely fixed for their named rows):** **P1‑1** — `ReturnScrapWriteOffService.php:218-227` now maps to the unmoved block at `217-226`, `anchor_kind=comment`, symbol `writeOff()`; I diffed `6626cb373` against the pinned tree and the block is byte‑identical, spanning **217‑226** (227 is blank) ✓. **P2‑1** — I re-derived every one of the 256 rows mechanically: the recorded `new_line` now actually contains the text quoted in `semantic_assertion` (0 real mismatches; my 2 hits were my own regex's backtick greed) ✓. **P2‑2** — the three docblock symbols resolve forward correctly: `DeliveredQuantityResolver.php:215-220 → hasGoodsIssued()` (`:221`), `StockAdjustmentDocumentService.php:336 → correct()` (`:340`), `ReceiptReturnService.php:1250 → restoreStock()` (`:1255`) ✓. **P2‑3** — the second metrics line is now emitted by `scripts/wave3-citation-inventory.php:179`; I reproduced both lines byte‑identically and `comment_anchors=34` matches the CSV's actual count ✓. **P2‑4** — the override table is 5 keys (`:311-331`), all 5 pinned by `scripts/tests/wave3-citation-inventory-test.php:66-81` ✓. **P3‑2** — `GeneralLedgerService.php:3248-3255` now anchors on the comment itself at `3513-3520` ✓.

---

### P1‑1 — `unresolved = 0` certifies **wrong anchors** for every citation whose construct moved to a different file/class; three load‑bearing clusters, and `M0-evidence.md` contradicts its own CSV · **CONFIRMED**

The validator only asks "does *some* construct exist near the mapped line, and can I name its enclosing symbol". It has no way to express *"the cited construct no longer lives in this file"*. The only mechanism for that is the 5‑entry hand table at `scripts/wave3-citation-inventory.php:311-331` — and it was applied to two citations while their twins, cited in the **same plan sentence**, were left to the mechanical mapper.

**(a) The `DocumentPostingService` delivery‑compliance cluster — 5 citations, all wrong.** T25f **deleted** the physical‑line loop and the by‑hand `sourceOrder.payload['delivery_note_ids']` traversal from this file; the file's own docblock says so at `DocumentPostingService.php:610-616` (*"the traversal that used to live here … has moved to `DeliveryComplianceGate`"*). The register maps them onto unrelated post‑T25f control flow:

| citation | cited construct at `6626cb373` | CSV anchor | what is actually there now |
|---|---|---|---|
| `:605` | `if ($product !== null && $product->is_physical) {` | `630` | `return;` (the *compliant* early return) |
| `:616-620` | `$sourceOrder = $invoice->sourceDocument;` | `637-640` | the F‑1 **exemption** guard |
| `:621-624`, `:623-624` | `$orderPayload = $sourceOrder->payload ?? [];` | `647` | the **T25b typed refusal** |
| `:626-630` | `if (empty($deliveryNoteIds)) {` | `647-656` | same typed refusal |

Real successors: `DeliveryComplianceGate::hasPhysicalLines()` (`DeliveryComplianceGate.php:400-403`) and `DeliveredQuantityResolver::confirmedDeliveryNotesFor()` (`DeliveryComplianceGate.php:266`). The sibling citation in the identical plan sentence, `InvoiceController.php:910-919`, **was** hand‑relocated (`:317-319`); this twin was not.

**(b) D‑19 twin B — the V‑11 SHIP‑NEITHER ruling.** `SalesOrderToInvoiceConverter:525-540` (dispatch `:187`) is the scoped‑lookup twin. CSV row 8 maps it to `SalesOrderToInvoiceConverter.php:505`, assertion *"invokes find() … at `->find($order->location_id);`"* — a **location** lookup inside `createDeliveryNoteForOrder()`. The true successor is `DeliveryNoteFromDocumentFactory.php:103-119` (the scoped `Product` lookup at `:111-114` that deliberately keeps an unresolvable product id) — **which `M0-evidence.md:188` already names in D‑19 row 12.** The two M0 artifacts disagree, and the CSV is the one the brief mandates M2 re-run.

**Failure scenario.** The brief makes this register the sole carrier of addresses (`M0-evidence.md:33`: *"stale literals remaining in the authority prose are not implementation addresses"*), and M2 is required to re-run it because *"the cutover commit is the one place a stale address is unrecoverable."* Plan `:1601-1607` labels the `:621-624` citation **"Load-bearing detail the implementer must not miss (inv R-2)"** — the enforcement point must read *both* payload shapes or it 422s every DN→invoice-converted invoice. An M2/M3 implementer resolving that citation through the register is handed the T25b typed‑refusal `if`, in a method that no longer contains the logic, and the register asserts `unresolved = 0` over it. Identically for the V‑11 twin: the ruling is *ship neither, together or not at all*, and the register points at the wrong file for one half.

---

### P2‑1 — Round‑3 P1‑1 survives under a second citation form: the same inv‑I1 comment block, cited as `:216-227`, still anchors on `postSynchronously: true,` · **CONFIRMED**

CSV row 86: `ReturnScrapWriteOffService.php:216-227 → 214`, `anchor_kind=statement`, assertion *"writeOff() performs the mapped domain/configuration statement `postSynchronously: true,`"*. The citation is `plan-wave3.md:528` — *"That is verbatim the hazard **V10's own comment** parks"* — and `:1223` — *"V10's own comment (`:216-227`) asked to be revisited"*. The block is at `ReturnScrapWriteOffService.php:217-226`, unmoved. Line `214` is the closing argument of `recordWriteOff(...)` — the **I1 seal** concern, separately cited at `:209-213` and `plan-wave3.md:1153`.

**Root cause.** `locateSemanticAnchor()` prefers a comment anchor only when the *first* line of the range is a comment (`scripts/wave3-citation-inventory.php:536-538`). `:216` is blank, so `commentPreferred` is false; the forward statement scan (`:564-570`) finds nothing in a comment‑only range, and the backward fallback (`:572-577`) walks onto the preceding statement. The round‑3 fix pinned only the literal string `ReturnScrapWriteOffService.php:218-227` in the test (`…test.php:54`), so the identical block cited three lines wider passed unnoticed.

**Failure scenario.** Exactly round‑3's: `plan-wave3.md:1815` makes *"Update V10's inv-I1 comment block"* a 3C deliverable, and the register now carries **one correct and one incorrect address for the same block**, with the wrong one classified `statement`. An implementer working row 86 edits the I1 seal argument and leaves the ordering invariant — *"do not land either lane without revisiting this site"* — untouched, on the site 3C's T16/T16d land.

---

### P2‑2 — `GeneralLedgerService.php:4444` anchors on the **next** create call, not the cited `source_id` idiom · **CONFIRMED**

`plan-wave3.md:937` cites it as the V10 precedent — *"`createInventoryWriteOffEntry`, `source_id = $movementId`, `GeneralLedgerService.php:4444`"* — and `:963` as *"the V10 idiom at `:4444`"*, the shape D‑a/T12's idempotency keying is modelled on. At the reference SHA `:4443` is `'source_id' => $movementId,` and `:4444` is the `]);` closing that `JournalEntry::create([`. On the pinned tree `source_id` is at `:4708`; the register records **`4712`** = `JournalLine::create([` (the COGS **debit line**). The forward‑first scan skipped the construct's end onto the next statement; only a 2‑line backward fallback exists, and it never fires when a forward hit is available within 6 lines. Same asymmetry as P2‑1, opposite direction.

**Failure scenario.** T12/D‑a's idempotency design is keyed on `source_type`/`source_id`; the register hands the implementer a `JournalLine` create as *"the V10 idiom"*. Sibling rows in the same cluster (`:4438 → 4703`, `:4442 → 4707`) are correct, so the error is silent and localised to the one row the plan actually leans on.

---

### P3 notes

1. **`docs/handoff/progress/wave3-3c-3d.progress.yaml:30`** records `commit: 850b62b46`; `HEAD` is `62e0bc17d`. Round‑3 P3‑4 was fixed for its own commit and immediately recurred at the next one.
2. **Dead code.** `semanticAnchor()` (`scripts/wave3-citation-inventory.php:441-479`) and `hasExecutableAnchor()` (`:482-485`) are declared and never called after the round‑3 rewrite superseded them with `semanticAssertion()`/`locateSemanticAnchor()`. Confirmed by grep across the script and its test.
3. **Residual same‑class, low blast radius:** `ReturnNoteService.php:698-712 → 722-734` and `createCOGSEntry.php:1690-1698 → GLS:1859-1867` convert a comment‑introduced citation into an adjacent statement/comment that is near, but not identical to, the cited text.
4. **Structural.** A register whose only hard number cannot express *"the cited construct is gone from this file"* will keep producing P1‑1's class at every re-run, including M2's. The fix is a fail‑closed check — compare the reference‑SHA text at `old_line` with the mapped text and force `unresolved` (or a mandatory hand relocation) below a similarity floor — not more entries in the hand table.

---

### Bypasses I attempted that **FAILED** (claims I tried to break and could not)

- **`unresolved=0` / the CSV is hand‑edited** → refuted. Re-ran to `/tmp`: `N_extracted=256 N_mapped=256 relocated=6 unresolved=0`, exit 0, **byte‑identical** to the committed CSV; `scripts/tests/wave3-citation-inventory-test.php` → `PASS (256 rows)`, exit 0; both metrics lines reproduce, `comment_anchors=34` matches the CSV's real count.
- **The round‑3 P2‑1 fix is cosmetic (assertions still harvested away from the address)** → refuted across all 256 rows by independent re-derivation.
- **The D‑28 sweep is not exhaustive over D‑9.2′'s writers** → refuted. I re-grepped production call sites for all five writers: `deliveryNoteService->confirm()` = `DeliveryNoteController:330`, `InvoiceController:808`, `InvoiceController:995`; `confirmWithin()` = `ReturnNoteService:510`, `RefundService:237`; `returnNoteService->confirm()` = `ReturnNoteController:427`; `goodsReceiptService->post()` = `GoodsReceiptController:124`, `StandaloneReceiptService:134`. Exactly the 10 rows in the evidence table, no misses.
- **The C‑2 depth correction is invented** → refuted, and it is a real correction of the plan: `RefundService.php:81` opens the root `DB::transaction(fn (): Document => …)` (the arrow fn the brief flags), `:237` calls `confirmWithin()` inside `costLock->acquire`, and `ReturnNoteService::confirmWithin()` (`:531`) opens **no** nested transaction — depth **1**, not 2.
- **C‑1 / C‑5 frames are asserted** → refuted line by line: `InvoiceController:803` root, DN confirm `:808`, post `:818`, closure ends `:829`; `:909` root, DN confirm `:995`, `invoiced_at` payload update `:1015`, response `:1017`, closure ends `:1031` — the I‑1 tail argument holds at both.
- **The GR D‑f literals are inferred** → refuted: `referenceType: 'Document'` / `referenceId: $purchaseOrder->id` at `GoodsReceiptService.php:576-577` and `625-626`; receipt‑line links written at `:701-702`; `StockMovementReferenceType::Document = 'Document'` confirms the vocabulary.
- **R‑1's `'pos_receipt'` literal is fabricated** → refuted: `PosCoreReceiptProjection.php:1881` and `:2220` both write `'reference_type' => 'pos_receipt'`.
- **The R‑11 probe is keyed on a non-existent enum value/column** → refuted: `MovementReason::Delivery = 'delivery'`, DN movements write `referenceType: StockMovementReferenceType::Document, referenceId: $deliveryNote->id` (`DeliveryNoteService.php:274-275`), `products.is_physical` exists (`2025_12_12_100000_add_is_physical_to_products_table.php`). The probe's own vacuity is honestly declared, not concealed.
- **The base pin was silently swapped** → refuted: `git show 8ce919be2:…progress.yaml:9` independently names `26b63f0ff…`; `6974231d0` is an ancestor; both Workshop tickets are in `git ls-tree 26b63f0ff`; the substitution from the abandoned `7f84dc91a` is disclosed at `M0-evidence.md:11`.
- **The tree is not the integrated 3E tree** → refuted: `DeliveryComplianceGate.php:403` drives `PhysicalLinePredicate::forLine`, and `RECEIPT_ROW_LOCK_TABLES` with its batch subset is live at `GoodsReceiptGlPostingOrderTest.php:106,121`.
- **The plan slice misses §§0–4** → refuted: the cut at `## §5` lands at plan line 3623, so §§0–4 (through §4.1 at `:3521`) are fully consumed; no en‑dash or other unmatched citation form exists in the corpus.
- **`GoodsReceiptService.php:543-552 → 582-591` is as wrong as P1‑1's cluster** → refuted; that is the T5b‑buffered form of the same `GoodsReceived` construct, a correct successor.

**Required to clear:** P1‑1 (re-anchor the five `DocumentPostingService` rows and the D‑19 twin‑B row onto their real successors, and add a fail‑closed drift check so this class cannot pass as `mapped`), P2‑1 (`:216-227` back onto the `217-226` comment block; prefer a comment anchor whenever the cited *range* is comment‑dominated, not only when its first line is), P2‑2 (`:4444` onto the `source_id` line).

VERDICT: CHANGES-REQUIRED
