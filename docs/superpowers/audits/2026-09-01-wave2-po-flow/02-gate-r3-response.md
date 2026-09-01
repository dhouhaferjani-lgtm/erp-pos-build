# Wave-2 PO spec — gate r3 response (rev 3 → rev 4)

**Session:** L · **Date:** 2026-09-01 · **Base commit:** `62964e5cc`
**Reviews answered:** `…-gate-r3-stock-gl.md` (**ACCEPT-WITH-CONDITIONS**, G3-S1-01..06) and `…-gate-r3-costing.md` (**CHANGES-REQUIRED, narrow**, G3-C1-01 BLOCKER + 02-05 MAJOR + 06-10 MINOR).
**Documents revised:** [`01-research.md`](./01-research.md) and [`02-scenario-matrix.md`](./02-scenario-matrix.md), both now rev 4. Prior rounds: [`02-gate-r1-response.md`](./02-gate-r1-response.md), [`02-gate-r2-response.md`](./02-gate-r2-response.md).

## Summary

| | r1 | r2 | r3 | r4 |
|---|---|---|---|---|
| Scenarios | 130 | 151 | 154 | **154** (no new rows; 9 rows re-fixtured, 2 de-duplicated) |
| Findings | 28 | 36 | 36 | **36** (F-W2-03 widened again — the repair is a no-op) |
| Owner questions | 12 | 10 | 10 | **10** |
| Settings ledger | — | — | 6 | **6** (S-3 residue + principal-scope clause added) |
| Fixture table | — | 30 SKUs | 37 rows | **39 rows** — `P-OVER-5a/b` added; `P-IFIRST-1`/`-2` split so `-2` can carry its own price and its orchestrator-minted-PO note. **[r4c] corrected from the 38 claimed here at r4 — the spec table itself was always right; the miscount was in this response file only (gate r4).** |

**Every finding from both reviews is FIXED. No rebuttal in r4.** The r3 costing reviewer **withdrew its own r1 G1-C1-16** and ruled the r2 `allocated_costs` resolution correct — that resolution is retained unchanged.

**The BLOCKER in one sentence:** r3 fixed "partial policy body" by sending a *complete* body — and added `preset: null` to it, which 422s on two independent rules, so the r2 BLOCKER was still live. All four bodies now **omit the `preset` key entirely**.

---

## Inventory / WAC / batch lens — G3-C1-01..10

| Finding | Verdict | Where in r4 |
|---|---|---|
| **G3-C1-01** [BLOCKER] every `PUT /procurement-policies` sends `preset: null`, which 422s twice over; the r2 BLOCKER is not cleared | **FIXED** | The key is **deleted** from all four bodies (`W2-PRICE-6`, `W2-PRICE-7`, the `W2-IFIRST` class header, `W2-IFIRST-6`) and from run-plan step 7; the two restore rows now say "the same eight raw fields (**no `preset` key**)". THE SETTINGS RULE's "second trap" paragraph becomes **two traps**, spelling out both mechanisms: `sometimes` is satisfied by key presence so a null fails `required` (`UpdateProcurementPolicyRequest.php:26`), and `after()`'s `has('preset')` is true for null because `has()` resolves to `array_key_exists` (`:59-76`). Verified in this worktree, including that the controller writes `'preset' => null` itself in the raw branch (`ProcurementPolicyController.php:42`), so the client never needs the key. Recorded as `01` §Corrections item 17. |
| **G3-C1-02** [MAJOR] the reuse list licenses three rows that *mutate* state on an unnamed fixture | **FIXED** | The list header is re-worded to **"assert no absolute figure AND write no stock, WAC or AP state"**, and the three writers are fixtured: **`W2-OVER-5(b)`** → its own **`PO-OV5`** (new SKUs `P-OVER-5a`/`P-OVER-5b`), because the only unnamed 2-line POs available were `PO-D` and `PO-U2` and a `4.0000` tranche before `W2-LAND-1`'s freight would have destroyed the whole LAND derivation through the company-wide denominator; **`W2-SEC-5`** → **`PO-X6`'s residual `balance_due 4.950`** with the payment amount stated, since the locked-row guard refuses `allocationAmount > currentBalance`; **`W2-IDEM-7`** → **`PO-M`**. `W2-MATCH-5` also writes and is removed from the list (it already names `PO-M`). The list now carries the reason the hazard runs writer→everyone-else, not the other way. |
| **G3-C1-03** [MAJOR] `W2-EDGE-11` names no PO and returns 200 on an untracked product | **FIXED** | The row now runs on **`PO-T11` / `P-LOT-11`** (batch-tracked, still `Confirmed` with a full remainder because both `W2-LOT-11` arms are 422 at validation), states the requirement inline — the `isset($batchData[$line->id]) && $product->requires_batch_tracking` conjunction at `GoodsReceiptService.php:557` drops the payload on an untracked line, so `product_batches.batch_number` is never touched and the 500 arm is unreachable — and adds **"assert no `goods_receipts` row and no `stock_movements` row survive the 500"**. |
| **G3-C1-04** [MAJOR] `W2-IDEM-6`'s first payment amount is unstated; the concurrent arm needs it ≤ `24.950` | **FIXED** | Arm 1 is stated as **`20.000`**, leaving `104.950`, with the arithmetic in the row: exactly one concurrent `100.000` wins only while `124.950 − X ≥ 100.000`. The row also says what r3's silence would have cost — a runner reusing `100.000` leaves `24.950`, both concurrent payments 422, and the row reports a **false idempotency defect**. |
| **G3-C1-05** [MAJOR] `W2-IFIRST-4`'s order-independence comparison cannot hold at the fixture price | **FIXED** | The row states its own line — **`P-IFIRST-2 × 10.0000 @ 10.500`, 19 %** — and its own derived legs **Dr 408 `105.000` / Dr 4456 `19.950` / Cr 401 `124.950`**, compared leg-for-leg with W2-PART-6. `P-IFIRST-2`'s fixture price is changed from `10.000` to **`10.500`**. At the old price the invoice-first net was `100.000 / 19.000 / 119.000` and the assertion would have failed on arithmetic and been recorded as an order-dependence defect. |
| **G3-C1-06** [MINOR] `W2-LOT-10`(a) duplicates `W2-LOT-6`'s r3 probe, and LOT-6 does not pin the header | **FIXED (merged, per the reviewer's first option)** | **`W2-LOT-6` stops at the receipt** — 200, lot created, `is_expired = false` — and cross-references `W2-LOT-10` for the exclusion proof. **`W2-LOT-10` is the only consuming call on that tuple** and carries both evidence sets. Same fix as G3-S1-05. |
| **G3-C1-07** [MINOR] `W2-LOT-10b` does not pin its header | **FIXED** | Appended: **"same pinned header as `W2-LOT-9` (`location_id = MAIN`, `partner_id = SUP-A`, no `batch_id`)"**, with the reason — without a header location the confirm refuses on a different guard entirely and the asserted `INSUFFICIENT_STOCK` never fires. |
| **G3-C1-08** [MINOR] the rollback citation is a comment, not the mechanism | **FIXED** | The **mechanism** is now cited as the `DB::transaction` wrapping `confirm()` at **`DeliveryNoteService.php:95`**, with `:376-379` kept as the stated contract. Also in `01` §Corrections item 20. |
| **G3-C1-09** [MINOR] the ledger's completeness sentence misses principal-scoped state; S-3 leaves a residue | **FIXED** | (a) The completeness sentence gains: **no row narrows a membership's `allowed_location_ids`**, and `W2-LOC-4`'s contrasting 403 `LOCATION_FORBIDDEN` is **code truth read off `GoodsReceiptDestinationTest.php:183`/`:206-212`, not executed** — narrowing it on the shared admin would 403 every later receiving class. (b) **S-3** now states the residue: the raw branch writes `'preset' => null` where `firstOrCreateForCompany` seeded `standard`, it is **behaviourally inert** (`ProcurementPolicyResolver::forCompany` reads only the raw columns, `:24-50`), and the eight restored values are byte-identical to `defaultForVertical:99-117`. |
| **G3-C1-10** [MINOR] two residual fixture-name drifts | **FIXED** | (a) The company-2 LAND-6 PO is **`PO-G2`** in both documents (`01` §6.2's enumeration said `PO-c2L6`); recorded as `01` §Corrections item 21. (b) **`PO-IF2` is annotated as orchestrator-minted**, not seeded — the fixture row for `P-IFIRST-2` now says its PO is created by `InvoiceFirstOrchestrator` (`:22-87`) and that nothing in SETUP seeds one. |

---

## Stock↔GL lens — G3-S1-01..06

| Finding | Verdict | Where in r4 |
|---|---|---|
| **G3-S1-01** [MAJOR] `01-research.md:354` re-opens the hole `:352` closes | **FIXED** | The scope note is rewritten: LOT-9/LOT-10 assert the lot ledger, the aggregate **and the existence of the exit journal entry**; only the COGS **amount** and the document lifecycle stay recorded-not-asserted. The paragraph now says explicitly that r3 left two categorical statements with opposite instructions two lines apart, and that a runner reading §6.7 to the end would have taken the later, absolute one. |
| **G3-S1-02** [MAJOR] the exit-GL assertion has no key, no oracle and no failure semantics | **FIXED** | `W2-LOT-9`'s Expected now carries the executable query — `SELECT 1 FROM journal_entries WHERE source_type='inventory_exit' AND source_id = <issue movement id> AND status='posted'`, **empty result FAILS the row** — plus the full chain: enqueue `DeliveryNoteService.php:399-401`, flush inside the root transaction `DeliveryNoteController.php:577`, `postSynchronously: true` `InventoryGlPostingService.php:206` → `GeneralLedgerService.php:5291` `postEntryNow`, DB-unique `('inventory_exit', source_id)` (`2026_08_11_000100_…:38-40`), and **a 200 confirm with no entry = unmapped COGS/Inventory accounts** (`InventoryGlPostingService.php:161-169`), which is the F-W2-03 shape on the exit side. Also `01` §Corrections item 18, including that this path — unlike GR-IR — cannot leave a committed Draft. |
| **G3-S1-03** [MAJOR] F-W2-03's advertised remedy does not work | **FIXED** | Appended to F-W2-03 with the code read verbatim: the listener promises *"Ops can replay the GoodsReceived event"* (`PostGrIrOnGoodsReceipt.php:44`), but `createGoodsReceiptGrIrEntry`'s idempotency guard is **status-blind** (`GeneralLedgerService.php:2052-2054`) and a committed Draft satisfies `exists()`, so **every retry returns null silently and the movement stays unbooked forever**; the ledger-shaped fix is the status-aware guard `createInventoryMovementEntry` already uses at `:5224-5229`. Severity stays **P0**, with the reviewer's framing recorded: "detectable" and "repairable by replay" are different claims, and the fix lane sizes its remediation from the second. Also `01` §Corrections item 19. |
| **G3-S1-04** [MINOR] the "silent `continue`" rationale for pinning `location_id` is wrong | **FIXED** | Both places corrected (`01` §6.7 item 1 and `W2-LOT-9`'s oracle): `confirm()` **refuses a header-less delivery note outright** — `DeliveryNoteService.php:89-93` → **422 `INVALID_STATUS_TRANSITION`** (`DeliveryNoteController.php:595-596`) — and because the header location is non-null past that guard, the `continue` at `:309-313` is **unreachable from this endpoint**. Verified independently. The pin is unchanged; only the justification was wrong, and both documents now say so, including the reviewer's point that the wrong version costs a triage hour when a tester meets a 422 the spec called impossible. |
| **G3-S1-05** [MINOR] W2-LOT-6 and W2-LOT-10 are the same gesture on the same tuple | **FIXED (both halves of the reviewer's option set)** | LOT-6 is reduced to the receipt facts and cross-references LOT-10; **and** LOT-10 is given a distinct shape — it consumes **half** the received quantity, so the `Shortfall:` figure in the message is non-trivial and distinguishable from a whole-quantity refusal. The row states it is the only consuming call on that tuple and carries both evidence sets. |
| **G3-S1-06** [MINOR] two residual citation nits | **FIXED** | The zero-amount suppression is **`:2070-2072`** (amount computed at `:2068`; `:2069` is blank) — corrected in both places it appeared, which also removes the intra-document disagreement with W2-EDGE-7. W2-HP-5's oracle is **`:2102`** (the `'source_id' => $movementId` write inside the `JournalEntry::create([…])` spanning `:2093-2103`), not `:2044-2050`, the method signature. Also `01` §Corrections item 20. |

---

## Reviewer conflict — closed

The r2 disagreement on `document_lines.allocated_costs` is **settled by the r3 costing reviewer against itself**: it opened the migration (`2026_05_30_000000_…:60-63`, applied at `:75-82`), the cast (`DocumentLine.php:156`) and the pin (`LandedCostBcmathTest.php:330-331`, `assertSame('20.000000', …)`), confirmed its r1 citation `:298-300` was that test's docblock, and ruled: *"`20.000000` / `10.000000` / `30.000000` / `0.000000` (6 dp) is correct. My r1 G1-C1-16 is WITHDRAWN and my r2 'CONFIRMED-FIXED' of the 3-dp figure was an error."* The stock-GL reviewer independently confirmed the same. **The r3 text stands unchanged in r4** — both facts stated together (computed at currency scale, stored and read at 6 dp) and every comparison routed through `assertMoneyEqual`.

## Rebuttals

**None in r4.** Every numbered finding from both r3 reviews is fixed. For the record, the two rebuttals raised in r2 remain upheld and untouched (the `CreateDocumentRequest.php` renumbering, re-verified a third time by the r3 costing reviewer; and the W2-WDIL-3 netting arithmetic).

Worth noting in the other direction: **one of my own r3 statements was wrong and a reviewer caught it** — the "silent `continue`" rationale for the location pin (G3-S1-04), which I had adopted from the r2 review without re-deriving it. It is corrected in both documents with the real mechanism, and flagged as a correction rather than quietly rewritten.

## Findings register — r4 delta

**No new defects.** One widened:

- **F-W2-03 (P0)** — now records that the repair is **not** a replay: `GeneralLedgerService.php:2052-2054` early-returns on any entry for that movement regardless of status, so a committed Draft permanently absorbs every retry and the listener's `:44` comment is wrong; the status-aware shape exists at `:5224-5229`.

Register unchanged at **36 findings — 2 P0 · 8 P1 · 16 P2 · 10 P3**. Owner questions unchanged at **10** (Q-1..Q-6, Q-8, Q-9, Q-11, Q-12).
