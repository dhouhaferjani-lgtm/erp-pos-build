## M0 adversarial gate — round 1 register

Lenses: **inventory-costing** (applies: WAC/movement-keying, D-19 predicate register, R-11 stock-integrity probe, GR movement anchor), **fiscal-pos** (applies: POS projection flush anchor, R-1 refund cost basis, advisory/transaction-depth sweep). No migrations, no queues, no user-facing strings, no money arithmetic in the commit — Rule 19, i18n, horizon-coverage and red-first checks are N/A to M0 by the brief's own evidence contract.

Reviewed `26b63f0ff..HEAD` (2 commits, 5 files, artifacts + one script only — no production code, ✅ scope-clean).

---

### P1-1 — The citation extractor silently drops every extensionless `Class:line` citation, and that set is exactly R-4/R-5 · CONFIRMED
`scripts/wave3-citation-inventory.php:59` — the regex requires a `\.(php|md|tsx|ts|js|mjs|neon|yaml|yml)` extension. The brief carries **12** citations in bare `Class:line` form that never enter the inventory:

`SalesOrderToInvoiceConverter:334` · `:273-284` · `:525-540` · `SalesOrderToDeliveryNoteConverter:566` · `PostCOGSOnInvoice:132-141` · `DeliveredQuantityResolver:399-402` · `InvoicedBeforeDeliveryScanner:84` · `UndeliveredGoodsLineScanner:92` · `:100` · `RefundService:481-484` · `DeliveryConfirmationModal:74` · `WorkOrderInvoiceDeliveryExemptionTest:40-44` (plus 1 in plan §§0–4).

**Failure scenario:** these are R-4's four assigned D-19 sites, R-4's non-adoption rationale site, R-5's two scanners, and R-2's fourth-occurrence example. `N_extracted=243` is therefore not "every citation", and the D-19 register at `M0-evidence.md:150-171` re-derives them **by hand in prose** — which is precisely the artifact adversarial F8 ruled insufficient. The brief mandates a re-run immediately before M2; the same blind spot ships into the cutover gate.

### P1-2 — VALIDATE cannot fail on the semantic half; `unresolved = 0` does not mean what the gate reads it to mean · CONFIRMED
`scripts/wave3-citation-inventory.php:93-96` fails a row only when `symbol === ''` or `assertion === ''`. Both are unreachable: `enclosingSymbol()` falls back to `'<file> file scope'` (`:294`) and `semanticAnchor()` falls back to a canned `'blank-line boundary…'` string (`:311`), otherwise returning a verbatim source excerpt — not a semantic assertion. The only live failure modes are `path_not_resolved` and `mapped_line_out_of_range`.

**Failure scenario, demonstrated in the shipped CSV:**
- `InvoiceController.php:892` and `:910-919` both map to **line 1132** — the file's terminal `}` — status `mapped`, assertion `` `}` ``. At the plan reference SHA `:892` is the physical-line loop (`$line->product->is_physical → return true`) and `:910-919` is `checkDeliveryNotesDelivered()`. `:892` **is** D-19 register row 2's site.
- 10 rows carry `… file scope` symbols, i.e. the tool found no symbol yet passed.

The brief: *"A row without a symbol and a semantic assertion is **unresolved**."* Under the shipped implementation such rows are recorded as mapped, so M0's single hard acceptance number is self-satisfying.

### P1-3 — The R-11 pre-deploy probe is vacuous; its self-closure is unsupported · CONFIRMED
`M0-evidence.md:99-134` runs the probe against local dev tenant DBs on port 5432 and concludes *"Integer total: 0 … R-11 self-closes; T19 does not need a non-zero remediation procedure."* I re-ran the denominator across the same 14 databases:

```
stock_movements WHERE reason='delivery' : 0   (total stock_movements across the fleet: 12)
products WHERE is_physical = false      : 0   (total products: 2003)
```

**Failure scenario:** the query returns 0 because the population is empty on a developer laptop, not because pre-T4 non-physical deliveries are absent on the deploy target. R-11 exists because *"those units have no return path"* after the cutover relieves inventory for them. M3's T19 release note is being pre-decided on a null-population sample. (The `reason='delivery'` literal itself is correct — `MovementReason::Delivery = 'delivery'`.)

---

### P2-1 — The dispatch-pinned `BASE_SHA` was rewritten by the implementation, undisclosed · CONFIRMED
`wave3-3c-3d.progress.yaml:9` was changed from `7f84dc91a…` → `26b63f0ff…` in commit `b118075b5`. `7f84dc91a` is a real commit (same subject, 13:18:04 vs 13:18:28) that is **not an ancestor of `26b63f0ff`** and is on no branch. `M0-evidence.md:5-7` then asserts `HEAD == BASE_SHA` against the rewritten value and never states that the pin was changed.

**Failure scenario:** M0's first and only hard entry check becomes self-certifying — the one step whose antidote column exists to prevent exactly this. Outcome is benign (`dev` now independently records `26b63f0ff` at `8ce919be2`), but the correct M0 response to `HEAD != BASE_SHA` is a `blocked_precondition` escalation, not an edit to the pin. Fix = disclose the substitution and its ratification in the evidence file.

### P2-2 — T11c pair 4 substitutes a fixture that cannot go green in M1 · CONFIRMED
`M0-evidence.md:72` sets pair 4 = "counting listener × goods receipt". The brief's M1 acceptance keys on pair numbers: *"pairs 4 & 5 (T5b, landed — re-prove on the merged tree)"* — both are T5b/GR pairs.

**Failure scenario:** `ApplyStockAdjustmentsOnCountingCompleted::handle()` (`:54`) opens no transaction and its `DB::transaction` is per item (`:257`) — the listener has **no root frame until T21**, which is M5 on the 3D branch. A T11c pair driving it is red-before and stays red in M1, tripping the brief's *"if any pair cannot be made green, D-9's architecture is wrong and 3C STOPS"* on a scheduling artefact, or getting silently exempted.

### P2-3 — The GR D-f anchor is established but its grain is under-specified · CONFIRMED (literal) / PLAUSIBLE (consequence)
The literal claim verifies exactly: `GoodsReceiptService.php:576-577` and `:625-626` pass `referenceType: 'Document'`, `referenceId: $purchaseOrder->id`; `WeightedAverageCostService.php:278-279` persists them. ✅ M3's stated fail condition is discharged.

**Failure scenario the evidence does not record:** `M0-evidence.md:138` prescribes *"the GR anti-join must use `reference_type = 'Document'` and the purchase-order document id"*, but D-f's population is **GR lines** while the key is **PO-grained**. Two partial receipts against one PO for the same product: receipt #1's movement satisfies the anti-join for receipt #2's line, so a GR that wrote no movement is silently not reported — a false negative on the arm 3C is adding. M3 needs the grain resolved (or the divergence recorded) before it is built on this anchor.

---

### P3 notes
1. **The brief's own validation example failed and was papered over.** `PosCoreReceiptProjection.php:456-458` → CSV row 198 returns symbol `PosCoreReceiptProjection.php file scope` — `apply()` starts at `:218`, mapped line 475 is 257 lines back, outside `enclosingSymbol()`'s 250-line window (`:285`). `M0-evidence.md:36` supplies a correct *hand-written* anchor instead of recording that the mechanical method failed on the case handed over to validate it. Example 2 lands at `802` (`try {`) vs the brief's `:803` (the `DB::transaction` line) — semantically fine, worth one line of disclosure.
2. **D-19's 18 rows collapse to fewer sites than stated.** Rows 1, 2 and 18 all resolve to `DeliveryComplianceGate::hasPhysicalLines()` line 403 (verified: the `PhysicalLinePredicate::forLine` call is at `:403`). Rows 8/16 duplicate `SalesOrderToDeliveryNoteConverter:572` — disclosed at `:171`; the 1/2/18 triple is not. Substantively the register holds up (I verified rows 11, 12, 14, 15, 16 line-by-line, including the twin-B relocation into `DeliveryNoteFromDocumentFactory:106-118` and row 16 already carrying the predicate), but "the count reconciles" is doing bookkeeping work the reader can't audit.
3. **R-1 step 4 is dead or dangerous.** `M0-evidence.md:91` adds a warn-and-fall-back-to-live-WAC arm. `assertOriginalReceiptResolvableForRefundOrVoid()` already fail-closes upstream (`PosCoreReceiptProjection.php:582-588`), so either the arm is unreachable or it silently reinstates the exact defect R-1 exists to remove. Cite the gate; drop the fallback or make it a typed refusal. Otherwise the option-(a) proposal is sound and feasible (`pos_receipts.original_receipt_id` exists; `COST_SCALE = 6` is an established named constant, not a Rule 19 hardcoded scale).
4. **C-2's savepoint is right for a weaker reason than stated.** `M0-evidence.md:56` justifies the added inner transaction as making "the documented depth true". The real reason is I-1: at depth 1, `confirmWithin`'s own `flushIfOutermost()` posts, then `appendDecision()` (`RefundService.php:243`) writes the `documents` row — an inventory-class lock after the advisory. State that; it's what makes the change non-optional.
5. **The probe command is not recorded verbatim** (`M0-evidence.md:99` describes it in prose), against the house rule "commands + actual output".

---

### Bypasses attempted that FAILED (claims I tried to break and could not)
- `reason='delivery'` wrong enum literal → refuted, `MovementReason::Delivery = 'delivery'`.
- C-2 misidentified (brief says `cancelInvoice`, evidence says `cancelInvoiceWithDecision`) → refuted; the arrow fn is at `RefundService.php:81-86` inside `cancelInvoice()`, `cancelInvoiceWithDecision` is its callee. Both descriptions are consistent.
- `DeliveryNoteService::confirm` call-site list incomplete → refuted; grep finds exactly `InvoiceController:808`, `:995`, `DeliveryNoteController:330`.
- C-2 depth-1 finding invalid because `ProductCostLock::acquire` opens a transaction → refuted, `ProductCostLock.php:40-52` takes advisory locks only. **The depth correction is CONFIRMED and is a genuine catch.**
- D-9.2′ writers missed by the sweep → refuted; all five writers and all five composites (C-1…C-5) are present, and C-5 = `InvoiceController::createDeliveryAndPost` verifies exactly (txn `:909`, confirm `:995`, `documents` payload write `:1010-1015`).
- Twin-B relocation fabricated → refuted; `SalesOrderToInvoiceConverter:520-545` now delegates to `DeliveryNoteFromDocumentFactory`, whose resolved-non-physical skip is at `:106-118`.
- GR reference literal invented → refuted, verified at both call sites and at the persistence site.
- `COST_SCALE = 6` a Rule 19 hardcoded scale → refuted, existing named constant.

---

**Required to clear:** P1-1 (widen extraction to the bare `Class:line` form and re-run), P1-2 (make `symbol`/`assertion` genuinely falsifiable — a row landing on a brace or resolving to file scope must be `unresolved` — then re-run and disclose the new count), P1-3 (re-run R-11 against the deploy target, or record the denominator and downgrade the conclusion from "self-closes" to "unmeasured; T19 must carry the remediation section conditionally"), P2-1 (disclose the pin substitution), P2-2 (restore pair 4 to a T5b pair), P2-3 (resolve or record the GR anti-join grain).

VERDICT: CHANGES-REQUIRED
