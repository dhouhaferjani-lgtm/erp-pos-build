## M0 adversarial gate — round 2 register

**Lenses.** **inventory-costing** — applies (D-19 predicate register, GR movement/reference anchor, R-11 stock-integrity probe, T11c lock-order fixture set). **fiscal-pos** — applies (POS projection flush anchor, R-1 refund cost basis, advisory/transaction-depth sweep). **Rule 19 / i18n / migrations / Horizon queues** — N/A: `26b63f0ff..HEAD` touches artifacts plus one standalone PHP script, no production code, no money/quantity arithmetic, no user-facing strings (`git diff --stat` verified: 7 files, all under `docs/` + `scripts/`). ✅ scope-clean.

Round-1 dispositions I independently re-verified: **P1-1 FIXED** (13 extensionless rows present; I replayed the pre-fix regex myself and got exactly `243`, so the red-first claim is substantiated, not just asserted). **P1-3 FIXED** (`M0-evidence.md:141` now labels the sample vacuous and pushes the deploy query to T19). **P2-1 FIXED** (`M0-evidence.md:11` discloses the pin substitution and its independent ratification at `8ce919be2`). **P2-2 RESOLVED BY REBUTTAL** — the rebuttal is correct: `plan-wave3.md:2716` does name pair 4 as `counting listener × goods receipt`, and `ApplyStockAdjustmentsOnCountingCompleted.php:257-288` really is a per-item `DB::transaction`, so the pair is executable in M1 without T21. **P2-3 FIXED** (`goods_receipt_lines.movement_id` / `free_movement_id` exist with partial unique indexes, migration `:48-49,61-69`; written at `GoodsReceiptService.php:701-702`).

---

### P1-1 — VALIDATE still cannot fail on the semantic half; four rows pass `mapped` on non-semantic anchors, one of them at a **wrong address** · CONFIRMED

`scripts/wave3-citation-inventory.php:110` now rejects file-scope symbols and terminal braces (I confirmed: `enclosingSymbol`/`executableAnchor` on `InvoiceController.php:1132` returns anchor `null` → the row *would* be unresolved). But the comment/docblock-fragment class is still a pass. The four citations whose file did not exist at `PLAN_REFERENCE_SHA` get **zero mapping** (`mapLines()` passes the address through, `:306-315`), and all four then pass VALIDATE on prose:

| citation | mapped to | shipped "semantic assertion" |
|---|---|---|
| `UndeliveredGoodsLineScanner:100` | `:100` | ``scan() documents the cited invariant: `this read as though a second arm were coming is gone: it made the` `` |
| `InvoicedBeforeDeliveryScanner:84` | `:84` | ``__construct() documents the cited invariant: `}>` `` |
| `DeliveryConfirmationModal:74` | `:126` | ``… performs the mapped domain/configuration statement `<>` `` |
| `DeliveredQuantityResolver:399-402` | `:520-523` | ``… documents the cited invariant: `net against.` `` |

**Failure scenario (concrete, not hypothetical).** The brief at `:249` states *"`UndeliveredGoodsLineScanner:100` pins `reference_type='Document'`"* — the anchor M3's D-f DN arm is built on. Current line 100 is a mid-sentence comment fragment; the actual pin is `UndeliveredGoodsLineScanner.php:127` (`->where('reference_type', 'Document')`). The inventory records the stale address as `status=mapped`, so M0's single hard number (`unresolved = 0`) is satisfied by a row that is wrong. Same shape at `InvoicedBeforeDeliveryScanner:84`: `:84` is the docblock terminator `* }>`; the set-based predicate the brief cites is at `:92`, and the reported symbol `__construct()` is not the enclosing construct.

Compounding: `scripts/tests/wave3-citation-inventory-test.php:54` guards punctuation-only anchors with `/[{});]+$/`, which matches neither `}>` nor `<>` — the regression test cannot catch either live instance.

**Fix to clear:** reject a code-file anchor that is a comment/docblock fragment or a non-statement token, re-run, and re-map (not hand-wave) the rows that then fail. `:100` → `:127`, `:84` → `:92`.

---

### P2-1 — The three R3-2 addresses M0 was explicitly told to re-derive are neither extracted nor re-derived, and two of them now collide with live-but-different statements · CONFIRMED

The extractor's pattern (`:59`) requires a filename or a `[A-Z]\w{2,}` class before the colon, so the brief's **13** bare `` `:NNN` `` continuation citations never enter the inventory. Most are harmless duplicates of nearby full citations, but three are not: the brief at `:418-424` records `:438` (`writePayments`), `:458`/`:459` (closure end) and immediately says ***"All three of these numbers are now stale (M0's example 1) — carry the semantics, re-derive the addresses."*** `M0-evidence.md:33-38` re-derives only the two examples the brief handed over (`:457/:476/:477`, `:803` — both of which I verified as correct: closure ends `PosCoreReceiptProjection.php:477`, `InvoiceController.php:803` opens C-1's root transaction). The R3-2 trio is absent from both the CSV and the evidence.

**Failure scenario.** On the current tree `PosCoreReceiptProjection.php:457` is `writePayments(...)` and `:458` is `redeemVouchers(...)` — i.e. the brief's stale literals now resolve to *plausible-looking but different* statements. An M2 implementer carrying `:457` as "the flush point" or `:458` as "the closure end" lands inside the statement list, not at the closure tail. That is precisely the "one place a stale address is unrecoverable" the brief names, and T16e's non-waivable reorder proof is keyed to this exact region. The current semantics are cheap to record: `writePayments` `:457` → `redeemVouchers` `:458` → `earnLoyaltyPoints` `:466` → `applyStockMovementForLines` `:467`, closure ends `:477`.

---

### P3 notes

1. **The relocation table is a hand-entered override that runs *before* validation** (`:101`, applied at `:279-294`). Both entries are correct — I checked them against `6626cb373`: `InvoiceController:892` was the physical-line loop, now `DeliveryComplianceGate::hasPhysicalLines():403`; `:910-919` was `checkDeliveryNotesDelivered()`, now the gate call at `InvoiceController.php:669-674`. And both would otherwise be `unresolved` (I verified the terminal-brace path returns a null anchor), so the override is load-bearing on `unresolved = 0`. Disclosed at `M0-evidence.md:31`, so this is a note, not a finding — but emit relocated rows with a distinct status and count them separately in the report, or the pre-M2 re-run has an unbounded escape hatch on its only hard number.
2. **The regression test is a ratchet over the live corpus, not a fixture test.** `M0-evidence.md:31` claims it *"pins the three failure classes"*; it only asserts that the current 256 rows contain no such row (and, per P1-1, its punctuation guard misses two live cases). A regression that cannot fail on a synthetic bad row is not a pin.
3. **T11c's fixture set is the plan's ten rows verbatim**, against brief item 4 (*"DERIVED FROM THAT SWEEP — not from the plan's lanes"*). The sweep's own C-5 gets no pair; `M0-evidence.md:81` dismisses it by assertion. I tried to break that dismissal and could not (below), so the conclusion stands — but state the dismissal as a resource-class argument rather than a bare claim.
4. **The twin-pair containment argument is thin.** Brief `:188` requires recording *why shipping neither is **SAFE**, not merely consistent — the relation-form vs scoped-form asymmetry*. `M0-evidence.md:177-178` records the direction ("resolved non-physical lines are excluded while unresolvable products remain") but never names the asymmetry or completes the safety argument.
5. **R-1's fallback is fixed in substance but the gate is uncited.** `M0-evidence.md:92` now refuses live-WAC fallback, but asserts "the upstream refund gate already requires a resolvable original receipt" without the `file:line` (it is `PosCoreReceiptProjection::assertOriginalReceiptResolvableForRefundOrVoid`). Cite it.
6. **Minor citation slack.** `M0-evidence.md:145` cites `GoodsReceiptService.php:576-578` / `625-627`; the `referenceType`/`referenceId` pair is at `:576-577` / `:625-626` (`578`/`627` is `variantId`). `docs/handoff/progress/wave3-3c-3d.progress.yaml:31` records `commit: d2b5765e0`, one commit behind `HEAD` (`eb60485e3`).

---

### Bypasses I attempted that FAILED (claims I tried to break and could not)

- **`unresolved=0` is unreproducible / hand-edited** → refuted. I re-ran `php scripts/wave3-citation-inventory.php` myself: `N_extracted=256 N_mapped=256 unresolved=0`, exit 0, and the output is **byte-identical** to the committed CSV.
- **The red-first replay is a story** → refuted. I ran the pre-fix regex (`0ad346e0d:scripts/…:59`) over the same two sources and got exactly `243`.
- **The mapping is systematically drifted** → refuted. I cross-checked all 237 mappable PHP rows by comparing the enclosing symbol at the old line under `6626cb373` against the shipped symbol: **2 mismatches**, both explained (one is the deliberate relocation; the other, `Product.php:314-317`, is the T5 rename `resolveWriteOffUnitCost()` → `resolveMovementUnitCost():322-325`, which is the correct semantic successor).
- **The extensionless arm over-captures** (`[A-Z]\w{2,}:\d+` eating prose like "Note: 3") → refuted; all 13 extensionless rows are genuine class citations, zero false positives.
- **The plan §§0–4 boundary is wrong** → refuted; the cut at `"\n## §5 "` lands at `plan-wave3.md:3623` and includes §0–§4.1.
- **C-2's depth-1 finding is wrong** → refuted and it is a genuine catch: `ReturnNoteService::confirmWithin()` opens no transaction (`:508-512` is `confirm()`'s), and `RefundService.php:237` calls it inside the root arrow fn at `:81-86`. The I-1 reason is also real — `appendDecision()` at `:242` writes `documents` after the return-note lane.
- **C-5 needs its own T11c pair (C-3 locks `documents`→stock at `DeliveryNoteController.php:313`; C-5 writes stock→`documents` at `InvoiceController.php:1015` — an AB-BA)** → refuted. C-5 creates its own DN, so the two frames cannot contend on the same `documents` row; no cycle. The evidence's dismissal holds.
- **The GR D-f anchor is invented** → refuted at all four sites: `GoodsReceiptService.php:576-577` and `:625-626` pass `'Document'` / `$purchaseOrder->id`; `WeightedAverageCostService.php:278-279` persists them; the receipt-line grain is real (`movement_id`/`free_movement_id`, partial unique indexes, written at `:701-702`).
- **Both Workshop tickets are missing from the pinned base** → refuted; `git ls-tree 26b63f0ff` lists both.
- **The WAC float-cast claim is false** → refuted for the claim as scoped: zero `(float)` casts in `WeightedAverageCostService.php`. (Pre-existing casts elsewhere in `Inventory/` are untouched by this diff and out of M0 scope.)

**Required to clear:** P1-1 (make the anchor rule reject comment/docblock fragments for code files, re-run, and correct `UndeliveredGoodsLineScanner:100` → `:127` and `InvoicedBeforeDeliveryScanner:84` → `:92`; extend the regression guard past `[{});]`), P2-1 (re-derive and record the R3-2 trio's current semantics — the `writePayments`/`redeemVouchers`/`applyStockMovementForLines`/closure-tail order — and either widen extraction to the bare `` `:NNN` `` form or record why the remaining 10 are covered by neighbouring rows).

VERDICT: CHANGES-REQUIRED
