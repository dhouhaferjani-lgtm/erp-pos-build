## M0 adversarial gate — round 3 register

**Lenses.** **inventory-costing** — applies (D-19 predicate register, GR movement anchor, R-11 probe, T11c fixtures, the `ReturnScrapWriteOffService` inv-I1 ordering site). **fiscal-pos** — applies (POS projection flush anchor, R-1 refund cost basis, transaction-depth sweep). **Rule 19 / i18n / migrations / Horizon** — **N/A**, verified: `26b63f0ff..HEAD` is 8 files, all under `docs/` + `scripts/`; no production code, no money/quantity arithmetic, no migrations, no `onQueue`, no user-facing strings. ✅ scope-clean.

**Round-2 dispositions I re-verified independently:** **P1-1 FIXED for its four named rows** — `UndeliveredGoodsLineScanner.php:127` is `->where('reference_type', 'Document')` ✓; `InvocedBeforeDeliveryScanner.php:92` is the set-based `whereHas` ✓; `DeliveryConfirmationModal.tsx:74` is the `parseFloat` ✓; `DeliveredQuantityResolver.php:521-523` is the executable null-product guard ✓. **P2-1 FIXED and correct** — I counted the lines myself: `writePayments` **457**, `redeemVouchers` **458**, `earnLoyaltyPoints` **466**, `applyStockMovementForLines` **467**, rounding block ends **476**, closure ends **477** (`PosCoreReceiptProjection.php:457-477`). The evidence's re-derivation at `M0-evidence.md:44` is exact.

---

### P1-1 — A citation that **never moved** was hand-relocated onto the wrong construct to satisfy `unresolved = 0`, and it is a 3C task deliverable · **CONFIRMED**

`scripts/wave3-citation-inventory.php:323-325` hard-codes `ReturnScrapWriteOffService.php:218-227` → line **214**.

At the plan reference SHA, `git show 6626cb373:…/ReturnScrapWriteOffService.php` lines 218-227 are the `// ── GL ORDERING DEPENDENCY (gate inv-I1) ──` block. On the pinned tree that block is **still there, byte-identical** (`ReturnScrapWriteOffService.php:217-227`). The citation was never stale. Line **214** is `postSynchronously: true,` — the closing argument of the `recordWriteOff(...)` call, which ends at `:215`, i.e. a different construct belonging to a different concern (the I1 seal), three lines before the block.

All three CSV rows now read:

```
…ReturnScrapWriteOffService.php,214,writeOff(),"writeOff() performs the mapped domain/configuration
statement `postSynchronously: true,`",relocated
```

**Failure scenario.** `plan-wave3.md:1815` makes *"**Update V10's inv-I1 comment block** (`ReturnScrapWriteOffService.php:218-227`)"* an explicit deliverable, and `:2934` lists the same range under **Files:**. The block is the one that says *"POS COGS-at-exit … landing it alone relieves Inventory TWICE"* and *"a GL leg for the RE-ENTRY movement (`MovementReason::POSReturn` already declares `requiresGLEntry() === true`) — landing it alone nets the pair to zero GL while the subledger dropped qty × WAC"* — the exact two lanes **3C lands** (T16 / T16d). An M2/M3 implementer working the register edits `postSynchronously: true,` and leaves the ordering-dependency invariant un-updated, on the site the plan says *"do not land either lane without revisiting."* This is the brief's own "one place a stale address is unrecoverable" — except here the address was correct until M0 broke it.

**Root cause, and why it will recur:** the round-2 fix generalised "a *stale* address landed on an unrelated comment fragment" into "**no code row may be anchored on a comment**" (`executableAnchor()` `:483-511`). A large share of this corpus legitimately cites invariant/rationale blocks (`plan-wave3.md:335` *"The comment at `GeneralLedgerService.php:3248-3255` is verbatim"*; `:296` *"the finding comment at"*; `:1759` *"verbatim, re-read on 7d423d162"*). The rule forces every such citation off its real target. **Fix to clear:** allow a comment/docblock anchor when the cited construct *is* a comment block, require the row to say so, and restore `:218-227` → the current block (`:217-227`). Re-audit the other comment-subject citations under the same rule.

---

### P2-1 — 50 code rows record an address that is blank / a brace / a comment, while the assertion is harvested up to 6 lines away · **CONFIRMED**

`executableAnchor()` (`scripts/wave3-citation-inventory.php:485-508`) scans forward to `max($end, $start + 6)` and backward 2, but `new_line` is never moved to the line it found. I re-derived from the committed CSV: **50 rows** whose recorded `new_line` start is non-executable. Examples: `GeneralLedgerService.php:4444 → :4709` (`]);` — the assertion came from `:4708`); `createCOGSEntry.php:1690-1698 → GLS:1860` (`//` — assertion harvested from `$scale = …`, which is *not* the rationale the plan cites); `PostGrIrOnGoodsReceipt.php:26-56 → :26` (`{`); `ReturnNoteService.php:698-712 → :714` (`{`).

**Failure scenario.** `unresolved = 0` — M0's only hard number — certifies that a *statement exists within six lines*, not that the recorded address is the cited construct. The pre-M2 re-run inherits the same blind spot, so a real drift of ≤6 lines between now and the cutover commit passes silently. Note the punctuation guard the round-2 fix added to `scripts/tests/wave3-citation-inventory-test.php:70` is defeated by this: `:4709` is `]);` and the row still passes, because the *assertion* was taken from a neighbour.

---

### P2-2 — The mandated `symbol` column names the **wrong method** whenever the mapped line lands in a docblock · **CONFIRMED**

`enclosingSymbol()` (`:404-411`) scans **backward** first, so a docblock line resolves to the *preceding* method, never the one it documents. Confirmed instances (I read each site):

| citation | recorded address | `symbol` shipped | method the anchor actually documents |
|---|---|---|---|
| `DeliveredQuantityResolver.php:185-199` and `:186-188` | `:215-229` / `:216-218` | `unresolvedLocationProductIds()` | **`hasGoodsIssued()`** (`:221`) |
| `StockAdjustmentDocumentService.php:336` | `:336` | `cancel()` | **`correct()`** (`:340`) |
| `ReceiptReturnService.php:1250` | `:1250` | `calculateAlreadyReturnedQuantities()` | **`restoreStock()`** (`:1255`) |

**Failure scenario.** The brief's MAP step requires *"the symbol it names"*. `DeliveredQuantityResolver.php:185-199` is load-bearing: `plan-wave3.md:4159` (N-5) pins `hasGoodsIssued()`'s *"remaining > 0"* semantics as the reason **D-29's `hasEverIssuedGoods()` sibling** exists, consumed by **T25b and detector D-c**. The register hands the implementer `unresolvedLocationProductIds()`. `StockAdjustmentDocumentService:336` is worse in kind: the cited invariant is *"`damage` and `write_off` both invert to `adjustment_positive` … G1 must read the ORIGINAL reason through `reverses_movement_id`"* — an inventory-costing rule attributed to `cancel()` instead of `correct()`. The row is also self-contradictory (`ReceiptReturnService:1250` says symbol `calculateAlreadyReturnedQuantities()` and assertion `private function restoreStock(`), and nothing validates symbol/assertion coherence.

---

### P2-3 — `M0-evidence.md:28` presents a second output line that **no committed command produces**, and its headline claim is false under the natural reading · **CONFIRMED**

`M0-evidence.md:20-29` documents exactly one command and labels the block **"Actual output:"**, then prints two lines. `scripts/wave3-citation-inventory.php:159` has a single `printf` and emits only the first. `grep -rn comment_anchors` over the whole repo returns **one hit: the evidence file itself**. There is no script, test, or recorded command behind `csv_rows=256 extensionless=13 relocated=15 file_scope=0 comment_anchors=0`.

**Failure scenario.** M0 is *entirely* evidence; the harness's stated antidote is that a reviewer re-runs the numbers. Line 1 I reproduced byte-identically. Line 2 is unreproducible, and `comment_anchors=0` is **false** under the reading a reviewer applies (50 rows, P2-1) — true only under an undisclosed definition ("no assertion string contains *documents the cited invariant*"). Either delete the line or ship the command that emits it.

---

### P2-4 — The pre-validation hand-override table grew 3 → 11; only 4 entries are pinned · **CONFIRMED**

`applyRelocation()` (`:283-333`) runs at `:101`, **before** the `unresolved` check, so any entry is unconditionally trusted. Round-2 P3-1 flagged the 3-entry version as "an unbounded escape hatch on its only hard number"; the fix widened it to 11 and `scripts/tests/wave3-citation-inventory-test.php:47-52` pins only **4**. The remaining 7 — including P1-1's — are unguarded: a wrong (or newly added) override still prints `unresolved=0` and still passes the regression, at M0 **and** at the pre-M2 re-run. Pin all 11 with their justification, or make the table fail closed (assert the target actually contains the cited construct).

---

### P3 notes

1. **The three new regression guards cannot fire.** `…test.php:67,73,76` check for `file scope`, `documents the cited invariant`, and `` `<>`/`}>` `` in shipped rows — but `enclosingSymbol()` returns `''` (→ `unresolved`) rather than `file scope`, and `executableAnchor()` filters comments and those tokens, so the branch at `semanticAnchor():448-450` is **dead code**. They ratchet the filter against removal; they detect nothing current. Round-2 P3-2 stands.
2. **`GeneralLedgerService.php:3248-3255` → `:3521-3522`** is defensible (the statement immediately under the cited comment at `:3516-3520`), but the plan cites *the comment, verbatim* — same class as P1-1, lower blast radius because the target is adjacent.
3. **Brief M0 item 2's "replace stale literals … everywhere the implementation will reference them"**: neither the dispatch nor `plan-wave3.md` was amended (diff touches neither). The CSV + evidence is the sole carrier, so M2 must be *required* to read the register rather than the brief text. State that explicitly.
4. **`docs/handoff/progress/wave3-3c-3d.progress.yaml:31`** still records `commit: 78933f51e`; `HEAD` is `7609d2ae2`. Round-2 P3-6, unfixed.

---

### Bypasses I attempted that **FAILED** (claims I tried to break and could not)

- **`unresolved=0` is hand-edited / unreproducible** → refuted. Re-ran to `/tmp`: `N_extracted=256 N_mapped=256 relocated=15 unresolved=0`, exit 0, **byte-identical** to the committed CSV. `scripts/tests/…` → `PASS (256 rows)`, exit 0.
- **The R3-2 re-derivation is prose** → refuted; every one of the five line numbers is exact (457/458/466/467/477), and the rounding tail really does end at 476.
- **The round-2 P1-1 relocations are wrong** → refuted at all four sites (see above).
- **The D-28 sweep depths are asserted, not derived** (brief's own antidote: re-run two writers) → refuted for **three** writers. **C-5**: `InvoiceController::createDeliveryAndPost()` opens its transaction at `:909`, calls `deliveryNoteService->confirm()` at `:995`, updates the DN payload at `:1015`, returns at `:1017` → depth 2, and the I-1 tail argument holds. **C-4 EXEMPT**: `StandaloneReceiptService.php:117-146` wraps `goodsReceiptService->post()` at `:134`, and the only subsequent write is `procurement_idempotency_keys` at `:137-143` — not an inventory-class lock ✓. **POS projection depth 1**: `ApplyFiscalEventProjectionJob` opens no surrounding transaction; its own docblock (`:325-329`) states *"T_apply is the projector's own boundary"* ✓.
- **D-19 anchors are invented** → refuted on spot-check: `DeliveryComplianceGate::hasPhysicalLines():400-403` ✓, `RefundService::requiresReturnDecision()` predicate at `:1141` ✓, `PostCOGSOnInvoice::extractPhysicalProductLines():126` ✓. Arithmetic 10+3+4+1 = 18 ✓.
- **R-1's gate citation is fabricated** → refuted; `PosCoreReceiptProjection::assertOriginalReceiptResolvableForRefundOrVoid()` opens at `:617` ✓.
- **The Workshop tickets are absent from the pinned base** → refuted; `git ls-tree 26b63f0ff` lists both.
- **`PosCoreReceiptProjection.php:1913-1922 → :2068-2070` is as wrong as P1-1** → refuted. The quoted docblock ("logged for operator follow-up") survives at `:2018`, and `:2069` is the `DB::transaction` SAVEPOINT the quote describes — a defensible successor, unlike the `:218-227` case where the block itself never moved.

**Required to clear:** P1-1 (restore `ReturnScrapWriteOffService.php:218-227` to its current, unmoved block and stop forcing comment-subject citations onto statements), P2-1 (record the address the anchor was actually taken from), P2-2 (resolve the symbol a docblock documents), P2-3 (produce or withdraw the second output line), P2-4 (pin or fail-close all 11 overrides).

VERDICT: CHANGES-REQUIRED
