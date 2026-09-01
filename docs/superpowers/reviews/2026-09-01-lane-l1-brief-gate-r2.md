# Lane L-1 "receipt hardening" — adversarial brief gate, round 2

**Reviewer:** inventory-costing-reviewer (adversarial, code-grounded)
**Artefact under gate:** `docs/sessions/session-L-wave2-po-2026-09-01/LANE-L1-RECEIPT-HARDENING-BRIEF.md` (revision r2)
**Round 1:** `docs/superpowers/reviews/2026-09-01-lane-l1-brief-gate-r1.md` (CHANGES-REQUIRED — 4 BLOCKER, 8 MAJOR, 7 MINOR)
**Worktree read:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` (HEAD `c09479f27`, docs-only ahead of dev `62964e5cc`)
**Method:** every r1 disposition re-verified against the code it cites; then a fresh adversarial pass on r2-new content only (D1 pre-pass, `$extra` shape, standalone envelope, the clock, rule 19 on new fields, PHPStan level 8). No suites, no servers.

---

## VERDICT: **ACCEPT-WITH-CONDITIONS**

**All four r1 BLOCKERs are CONFIRMED-FIXED against the code.** The placement of D5 off the shared DEFAULT-lot writer, the persisted-`allow_expired`/re-authorised-`post()` design, the standalone surface being taken in scope, and the SKU-only-where-a-Product-is-held rule are each correct and correctly cited.

What remains is 6 MAJOR + 8 MINOR, **every one of which is a one-line edit** to the brief (a substitution, a sentence, a label change, or one extra file in a list). No condition requires re-deriving a decision, so the orchestrator can apply them and dispatch without a round 3.

The headline residue: **the r2 acceptance oracle still contains strings today's data cannot produce** — a SKU (`P-LOT-5`) that exists nowhere but this brief, and an over-receipt sentence that is impossible under the unit-formatting rule r2 itself adopted. That is the same defect class as r1's L1-G1-02, at MAJOR rather than BLOCKER because r2 got the *mechanism* right and only the *values* wrong.

---

## Disposition audit — every r1 finding re-verified

| r1 # | r2 claim | Verdict | Evidence I re-opened |
|---|---|---|---|
| **L1-G1-01** | Refusal moved to the GR call site; `findOrCreateBatch` untouched; two blast-radius arms | **CONFIRMED-FIXED** | `StockAdjustmentService.php:1102-1118` (machine-derived `now()+shelf_life`, drifts daily) ✔; `OpeningBalancePostingService.php:190-210` (`findDefaultBatch` snapshot → `classifyExpiryOutcome`) ✔; W4-1 set-once block `BatchStockService.php:110-130` ✔; brief §D5 line 199 places the check at `GoodsReceiptService:559` ✔; forbidden-actions line 331 fences the method ✔. Blast-radius arms present (task 5 bullets 4-6) — **but mislabelled `[red]`, see L1-G2-06**, and the lookup they need has no public API, see **L1-G2-04** |
| **L1-G1-02** | SKU from the loaded `Product` only; over-receipt uses line + description; oracle re-derived | **CONFIRMED-FIXED (mechanism) / NOT-FIXED (values)** | No `product_code` in `PurchaseOrderController.php:455-473` (store) or `:597-612` (update) ✔; `line_number` written `:459`/`:601` ✔; `assertQuantitiesWithinRemaining` called `GoodsReceiptService.php:157` and `:496`, product loaded only `:515-518` ✔. **The AFTER strings are still underivable** — see **L1-G2-01** (`P-LOT-5` does not exist) and **L1-G2-02** (unit formatting) |
| **L1-G1-03** | Guard in `createDraft`; `allow_expired` persisted; actor re-authorised at `post()` | **CONFIRMED-FIXED** | payload write `GoodsReceiptService.php:136-140` ✔; re-read `draftInput` `:377-378` ✔; precedent `assertCanApplyDraftPriceOverrides` invoked `:219`, defined `:334-348` ✔; no draft-update route exists (`Inventory/Presentation/routes.php:156-177`) so a persisted flag cannot go stale ✔. Residue: the return-shape docblock (**L1-G2-09**) and the third controller (**L1-G2-03**) |
| **L1-G1-04** | Standalone in scope with the same flag + a `reason`-carrying envelope | **CONFIRMED-FIXED** | route `Procurement/Presentation/routes.php:40-42` ✔; `StandaloneReceiptService.php:121` `createDraft`, `:134` `post(...,true)` ✔; envelope `StandaloneReceiptController.php:49-56` ✔. Residue: the DTO hop and the compensation semantics (**L1-G2-08**, **L1-G2-12**) |
| **L1-G1-05** | Named aggregating pre-pass, own scoped query, before any write; "three lines" corrected | **CONFIRMED-FIXED** | in-loop throw `:523-525` inside the loop opened at `:485` ✔; lock-order comment `:507-514` ✔; brief §D1 lines 134-136 name the method, the scoping and the cost ✔. Cost note understates by one query (**L1-G2-11**) |
| **L1-G1-06** | B53 → DIVERGE (stricter), owner-scoped; B52 reworded as structural | **CONFIRMED-FIXED** | brief lines 68-69 ✔ |
| **L1-G1-07** | Convention-11 sentence replaced with the true caller list + glossary citation | **CONFIRMED-FIXED** | `docs/glossary.md:40` (*Lot (batch)* — "receipts, opening stock import, counting") ✔; the 8-row table (brief 75-84) matches the code ✔. One direct minter still missing (**L1-G2-10**) |
| **L1-G1-08** | Per-tenant seeder re-run in browser re-verification + deliverables + promotion note | **CONFIRMED-FIXED** | brief 301-308, deliverable 4 ✔; anchors `RolesAndPermissionsSeeder.php:148-149`, grant `:571` ✔ |
| **L1-G1-09** | B23's `createDraft` half taken in scope (task 1.5) | **CONFIRMED-FIXED** | `createDraft:107-201` never reads `requires_batch_tracking` ✔ (re-read in full); task 1.5 bullets 6-7 present ✔ |
| **L1-G1-10** | `:163`/`:538` added; `:213` explicitly OUT + ticket; every enum case has a site | **CONFIRMED-FIXED** | `:163`, `:213`, `:538`, `:193`, `:720` all re-read and match ✔; OUT list line 118 ✔; change list line 238 ✔ |
| **L1-G1-11** | Sentence unit-formatted, canonical values in `details` | **PARTIALLY FIXED** | `QuantityScale::formatForUnit` is at `Shared/Domain/QuantityScale.php:72` ✔ and takes `?int $decimalPlaces`. **But the ruling is unimplementable in the over-receipt guard** and contradicts the oracle string — **L1-G2-02** |
| **L1-G1-12** | Second-company expectation must be derived from code and cited | **CONFIRMED-FIXED** | brief line 293 requires the derivation and forbids forcing a 422 ✔ |
| **L1-G1-13** | Narrower catch mandatory, inserted before the `\DomainException` arm | **CONFIRMED-FIXED for 2 of 3 controllers** | `PurchaseOrderController.php:857-858` ✔, `StandaloneReceiptController.php:49` ✔ — **`GoodsReceiptController.php:125-131` missed**, see **L1-G2-03** |
| **L1-G1-14** | `$extra` gets a declared array shape | **CONFIRMED-FIXED** | `HandlesDocuments::validationErrorResponse` decl at `:315`, body `:316-323` ✔ (brief says `:316-323` — off by one on the decl, nit in **L1-G2-13**); shape given at brief 154-155 ✔ |
| **L1-G1-15** | One clock: `now()->startOfDay()`, app timezone | **CONFIRMED-FIXED** | nightly `BatchExpiryDailyCheckCommand.php:123-130` (`expiry_date < $today`) ✔; FEFO `FEFOInventoryService.php:107-112` (`now()->startOfDay()`) ✔ |
| **L1-G1-16** | Four citation drifts corrected | **CONFIRMED-FIXED** | `ReceiveGoodsRequest.php:35` = `received_unit_prices ⇒ prohibited` ✔ (`:33` = `expiry_date`); `normalizePurchaseLine` opens `PurchaseOrderController.php:91` ✔; `BatchStockServiceVariantTest.php:173-200` is the two-row shape ✔; nightly `:123-130` ✔ |
| **L1-G1-17** | Lane-map supersession of `L-8` | **PARTIALLY FIXED** | `01-research.md:181` (B23 → `L-8`) ✔ superseded. **B24 at `:182` assigns the expired-lot decision and `W2-LOT-6` to lane `L-9`** and is *not* superseded — **L1-G2-05** |
| **L1-G1-18** | `product_batches` not in `CATALOGUE_TABLES`; uniques already carry `company_id`; arms voluntary | **CONFIRMED-FIXED** | partial indexes `2026_06_02_100008_add_variant_id_to_product_batches.php:27-31` ✔ both carry `company_id`. **New consequence found: those indexes are PG-only** — **L1-G2-07** |
| **L1-G1-19** | Every bullet labelled `[red]`/`[pin]` | **MOSTLY FIXED — 3 mislabels** | Spot-checked 8 labels against today's code; 5 correct, 3 wrong — **L1-G2-06** |

---

## Findings (r2)

### L1-G2-01 [MAJOR] — the W2-LOT-5 oracle names a SKU that exists nowhere; the fixture product is `P-LOT-1`

**Brief:** D2 example lines 147-148 (`"line 1 (P-LOT-5 — Paracétamol 500mg)"`, `"sku": "P-LOT-5"`), oracle line 279, browser row line 314.

**Code:** the wave-2 fixture table has no `P-LOT-5` — `apps/web/e2e-local/wave2-shared.ts:99-117` lists `P-LOT-1, P-LOT-4, P-LOT-6, P-LOT-7, P-LOT-8, P-LOT-11…`. The `W2-LOT-5` leg builds its PO from **`P-LOT-1`** (`apps/web/e2e-local/wave2-po.part1.spec.ts:1533`) and asserts `Batch data is required for batch-tracked product ${product('P-LOT-1')}` (`:1538`). `grep -rn "P-LOT-5" docs/ apps/web/e2e-local/` returns **only this brief**.

Worse for derivability: PO line `description` **defaults to the product SKU** in this harness (`wave2-po.part1.spec.ts:373-375` — *"API-created lines default to the product SKU"*), and `Paracétamol 500mg` is never used. So the real AFTER sentence is `… line 1 (P-LOT-1 — P-LOT-1).` — SKU and description identical.

**Why it matters:** identical to r1's L1-G1-02 — an executor told to make an oracle green with a non-existent SKU either invents a fixture (scope creep into the orchestrator-owned, git-excluded harness, which line 332 forbids) or fakes the assertion.

**Fix (one line each):** replace `P-LOT-5` with `P-LOT-1` at brief lines 147, 148, 279, 314; add to D2's `describeLine()` bullet: *"on the run-24 fixtures `document_lines.description` equals the product SKU (`wave2-po.part1.spec.ts:373-375`), so the label renders `line 1 (P-LOT-1 — P-LOT-1)`; omit the description when it equals the SKU."*

---

### L1-G2-02 [MAJOR] — the unit-formatted over-receipt sentence is unimplementable in the guard that must produce it, and the oracle string is internally contradictory

**Brief:** D2 line 164 (*"the human sentence renders quantities with `QuantityScale::formatForUnit($value, $unit?->decimal_places)`"*), line 160 (over-receipt guard must NOT load the product — *"Rejected alternative … loading the product inside the guard would add a per-line query"*), oracle line 283 (`Ordered: 10, already received: 0, requested: 10.0001.`).

**Code:**
- `decimal_places` lives on `units`, reachable only via `Product::unit()` (`app/Modules/Product/Domain/Product.php:367`, `unit_id` at `:106`). **`DocumentLine` has no unit column** (fillable `DocumentLine.php:79-120`). So `assertQuantitiesWithinRemaining(DocumentLine $line, …)` (`GoodsReceiptService.php:309`) physically cannot resolve a unit without the product load the brief forbids two paragraphs earlier.
- The in-repo precedent for exactly this situation passes `null` (scale 4): `Document/Application/DTOs/DocumentLineData.php:48-52` (`formatForUnit($line->quantity, null)`).
- Every wave-2 fixture product is created with the **`pc`** unit (`wave2-shared.ts:331` `unit_id: this.req('pieceUnitId')`, resolved at `:516-517`), and `pc` has **`decimal_places => 0`** (`database/seeders/UomSeeder.php:190-196`). Under the r2 ruling the sentence for `W2-OVER-1` can only read `Ordered: 10, already received: 0, requested: 10` — three identical-looking numbers, which the brief itself forbids. The printed oracle mixes a 0-decimal `10` with a 4-decimal `10.0001` and is therefore unreachable on any single unit.

**Why it matters:** the executor is handed a rule, a prohibition that blocks the rule, and an oracle that satisfies neither. Left as is, they will pass `null` to `formatForUnit` (mechanically "compliant", semantically scale-4) and hand-write the oracle string to match — the fabricated-evidence path.

**Fix (one line):** rule it in D2: *"the over-receipt sentence carries NO figures — it states the comparison (`requested more than the remaining quantity`) — because the guard holds only a `DocumentLine` and the unit lives on the product (`Product.php:367`); `details.ordered / already_received / requested` carry the canonical scale-4 values. Unit-formatted figures appear only in messages raised where a `Product` is already loaded."* Then rewrite the `W2-OVER-1` AFTER cell to that single derived sentence.

---

### L1-G2-03 [MAJOR] — a third controller catches the receipt `\DomainException`, and it is the one task 4 bullet 6 exercises

**Brief:** D2 line 153 names two controllers; checklist line 354 says *"the narrower catch precedes the `\DomainException` arm on **both** controllers"*; deliverable 2 omits the file. Yet entry point 4 (line 109) is `POST /goods-receipts/{id}/post`, and task 4 bullet 6 asserts *"posting it as an actor **without** the permission ⇒ 422 `EXPIRED_LOT_REFUSED`"*.

**Code:** `app/Modules/Inventory/Presentation/Controllers/GoodsReceiptController.php:123-132`:
```php
} catch (\DomainException $e) {
    return response()->json(['error' => ['code' => 'GOODS_RECEIPT_POST_FAILED', 'message' => $e->getMessage()]], 422);
}
```
Route `Inventory/Presentation/routes.php:169-172` gates it on `can:purchase-orders.receive` — so the "posting actor without `goods-receipt.receive-expired`" arm is reachable exactly there, and without a narrower catch the new `reason` is swallowed by the broad arm (the r1 L1-G1-13 mechanism), under a **different** code (`GOODS_RECEIPT_POST_FAILED`, not `GOODS_RECEIPT_FAILED`).

**Fix (one line):** add to D2 and deliverable 2: *"third surface — insert `catch (GoodsReceiptException $e)` before `GoodsReceiptController.php:125`; its `code` stays `GOODS_RECEIPT_POST_FAILED`. Task 4 bullet 6 asserts that code plus `reason=EXPIRED_LOT_REFUSED`."* Change "both controllers" to "all three controllers" at line 354.

---

### L1-G2-04 [MAJOR] — D5's lot lookup has no public surface, and the deliverables forbid creating one

**Brief:** D5 line 201 — *"reuse the repository finder `findByBatchNumberAndVariant` (`BatchStockService.php:358-364`) via a read-only lookup"*; deliverable 2 caps the file at *"`BatchStockService.php` (one array key)"*; forbidden action line 331 fences the DEFAULT-lot methods.

**Code:** `BatchStockService`'s public API is `ensureDefaultBatch:81`, `trackedLotQuantityAt:167`, `untrackedRemainderAt:222`, `ensureDefaultBatchForUntrackedRemainder:255`, `findDefaultBatch:314`, `findOrCreateBatch:340`, `receiveBatchStock:397`, `issueBatchStock:436`, `transferBatchStock:478`. `:359-364` is an **internal call** on `$this->batchRepository`; the method itself is declared on `App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface:26`. There is **no** public by-batch-number finder — `findDefaultBatch:314` hard-codes `DEFAULT_BATCH_NUMBER`. So D5 as written cannot be implemented without either a new public method (deliverables say one array key) or injecting the repository interface into `GoodsReceiptService` (unlisted). Both are legal by deptrac (`deptrac.yaml:24-95` enforces hexagonal direction only, not cross-module) and precedented (`GoodsReceiptService.php:8` already imports `BatchStockService`; `Inventory/Application/Services/OpeningBalancePostingService.php:13` imports `BatchExpiry\Domain\Entities\Batch`), so this is a specification gap, not an architecture problem.

**Fix (one line):** *"D5's lookup uses a new read-only `BatchStockService::findByBatchNumber(companyId, productId, batchNumber, variantId): ?Batch` that delegates to `BatchRepositoryInterface::findByBatchNumberAndVariant` and touches nothing else; deliverable 2 becomes `BatchStockService.php` (one array key + one read-only finder)."*

---

### L1-G2-05 [MAJOR] — the supersession covers B23 but not B24, whose expired-lot half the research assigns to lane L-9

**Brief:** header line 8 supersedes `L-8` for B23 only. OUT list line 116 concedes a collision: *"F-W2-32 nullable/unknown expiry (lane `L-9`) — task 4 edits the same rule line (`ReceiveGoodsRequest.php:33`) … **Collision noted.**"*

**Code/doc:** `docs/superpowers/audits/2026-09-01-wave2-po-flow/01-research.md:182` — B24's decision is *"MATCH — lane `L-9` (default the expiry from shelf life; make `expiry_date` nullable on the receive contract; **decide on expired-lot acceptance**). Scenarios W2-LOT-3, **W2-LOT-6**, W2-LOT-11."* W2-LOT-6 **is** L-1's task 4. Two lanes are therefore chartered to edit the same three lines of `ReceiveGoodsRequest.php:33-35` with different intents, and "collision noted" is not an assignment.

**Fix (one line):** extend the header: *"L-1 also supersedes `L-9` for B24's **expired-lot acceptance** half (`W2-LOT-6`); `L-9` keeps only the nullable/unknown-expiry half (`W2-LOT-3`, `W2-LOT-11`) and must rebase onto L-1's version of `ReceiveGoodsRequest`."*

---

### L1-G2-06 [MAJOR] — three `[red]` labels are pins; r1's L1-G1-19 re-introduced in r2-new content

Spot-check of 8 labels against today's code (per the gate instruction to check five):

| Bullet | Label | Truth today | Verdict |
|---|---|---|---|
| task 1 b3 — `batches`-only body ⇒ 200 | `[red]` | `receiveAll:795` passes `[]` ⇒ 422 at `:523-525` | ✔ genuinely red |
| task 1.5 b6 — draft with no batches ⇒ 422, zero rows | `[red]` | `createDraft:107-201` persists it | ✔ red |
| task 2 b6 — `received_unit_price <= 0` ⇒ `RECEIVED_PRICE_INVALID`, no UUID | `[red]` | `:163`/`:538` leak the line UUID | ✔ red |
| task 3 b1 — variant line ⇒ 200 | `[red]` | `:559` omits `variantId` ⇒ `MissingVariantException` (`BatchStockService.php:349-355`) | ✔ red (but see L1-G2-07) |
| task 4 b2 — `allow_expired` without permission ⇒ 422 | `[red]` | no such rule today ⇒ ignored, 200 | ✔ red |
| **task 4 b4 — `expiry_date === today` ⇒ 200** | `[red]` | **no date constraint exists at all (`ReceiveGoodsRequest.php:33`) ⇒ already 200** | ✘ **pin** (r1 flagged this exact bullet at r1:260) |
| **task 5 b4 — next-day adjustment still succeeds** | `[red — blast radius]` | **`findOrCreateBatch:366-368` returns the existing lot ⇒ already succeeds** | ✘ **pin** |
| **task 5 b5 — opening conflict still `ConflictExistingLot`** | `[red — blast radius]` | **`OpeningBalancePostingService.php:190-210` already returns it** | ✘ **pin** |

A blast-radius arm is a **pin by definition** — it proves the change did *not* alter a path. The brief's own rule (line 211) demands pasted red output for `[red]` bullets, so these three force the executor to either fabricate red output or distort the test until it fails.

**Fix (one line):** relabel task 4 bullet 4 and task 5 bullets 4-5 to `[pin]`, and add: *"blast-radius arms are pins by construction — they are new coverage, not red-first evidence (`EnsureDefaultBatchTest.php:91-112` re-runs with an identical `asOfDate`, which is why the day-drift case has no test today)."*

---

### L1-G2-07 [MAJOR] — task 3's "second lot row under a second variant" cannot pass on SQLite, and the brief mandates the SQLite leg first

**Brief:** task 3 bullet 1 (line 242) — *"the same `batch_number` under a second variant creates a **second** lot row (contract shape: `BatchStockServiceVariantTest.php:173-200`)"*; task header line 211 — *"SQLite leg first, then the PG leg"*.

**Code:** the variant-aware partial indexes are created **only on PostgreSQL** — `database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:20-33` returns early for non-pgsql drivers, so SQLite keeps the original tenant-wide `unique(['company_id','product_id','batch_number'], 'unique_batch_per_product')` from `2026_01_05_150000_create_product_batches_table.php:46`. The cited contract test says so and skips: `tests/Feature/BatchExpiry/BatchStockServiceVariantTest.php:165-171` — *"Partial unique indexes are PostgreSQL-only"* → `markTestSkipped`.

**Why it matters:** this is the classic SQLite/PG divergence the review charter calls out, inverted — SQLite is *stricter* here. Run on SQLite the arm dies on a unique violation (a `QueryException`, i.e. a 500), and an executor debugging a "500 on receipt" will start pulling on the D5/variant code that is actually correct.

**Fix (one line):** append to task 3 bullet 1: *"the two-variant-rows assertion is **PG-only** — guard it with the same `markTestSkipped` as `BatchStockServiceVariantTest.php:165-171`; on SQLite `unique_batch_per_product` is still in force (`2026_06_02_100008…:20-33`)."*

---

### L1-G2-08 [MAJOR] — D4's `is_expired` truthfulness change flips a downstream refusal predicate; "keep the diff to that single array key" understates it

**Brief:** D4 bullet 1 (line 178) — *"It is not a new policy and it does not change lot matching behaviour. Keep the diff to that single array key."*

**Code:** `is_expired` is not inert. `app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:630-636`:
```php
private function lotRefusesInboundStock(Batch $batch, bool $isNegative, bool $isContra): bool
{
    if ($isNegative || $isContra) { return false; }
    return $batch->is_expired || $batch->is_recalled || ! $batch->is_active;
}
```
and the docblock at `:612-628` explicitly reasons about the flag *"which flips on a nightly timer"*. After D4, a lot admitted through the `allow_expired` override (task 4 bullet 3 requires `is_expired = true` at creation) **immediately refuses positive stock adjustments** — where today it accepts them until the nightly run. Convergent, not divergent (the nightly reaches the same state within a day), but it is a same-request behaviour change on a different module's write path, invisible from the one-key diff.

**Fix (one line):** add to D4 bullet 1: *"consequence: `StockAdjustmentDocumentService::lotRefusesInboundStock` (`:630-636`) already refuses inbound stock into an `is_expired` lot, so an override-admitted expired lot is un-toppable-up from the moment of receipt instead of from the next nightly run (`BatchExpiryDailyCheckCommand.php:123-130`). Ship a `[pin]` asserting exactly that, so it is a recorded consequence and not a later regression report."*

---

### L1-G2-09 [MINOR] — a standalone refusal is not a zero-row event; it cancels the auto-PO

**Brief:** task 4 bullet 1 says *"zero rows anywhere"*; bullet 7 adds the standalone arm; second-of-everything item 3 lists seven tables.

**Code:** `StandaloneReceiptService.php:147-151` catches every `\Throwable` and calls `compensateFailedReceiptCreation` (`:391-427`), which sets the auto-PO to `DocumentStatus::Cancelled` (`:411-415`) and deletes the `procurement_idempotency_keys` row (`:418-421`). So each refused standalone call leaves a **Cancelled `documents` row + its `document_lines`** behind, and a re-run with the same idempotency key legitimately mints a second one. The seven-table assertion still holds (documents is not in the list) — but "zero rows anywhere" does not.

**Fix (one line):** *"on the standalone surface a refusal compensates rather than vanishing: the auto-PO is Cancelled and the idempotency key deleted (`StandaloneReceiptService.php:391-427`), so 'no-op' there means no receipt/lot/movement/GL row — assert the seven tables, not `documents`."*

---

### L1-G2-10 [MINOR] — `draftInput()`'s declared return shape must gain the flag (PHPStan level 8)

`GoodsReceiptService.php:351-359` declares `@return array{receivedQuantities: …, batchData: …, priceOverrideReason: ?string}`. D4 adds `allow_expired` to the same payload and re-reads it at `:377-378`; the key must be added to that array shape (and narrowed from the `mixed` that `$receipt->payload` yields, exactly as `:378` does with `is_array(...)`). Not stated; level 8 will fail the lane at the last gate.

**Fix:** one clause in D4: *"extend `draftInput()`'s `@return` shape with `allowExpired: bool` and narrow the payload read with an `is_bool` check mirroring `:378`."*

---

### L1-G2-11 [MINOR] — the lot-writer inventory misses a fourth direct minter

The caller table (brief 75-84) covers `findOrCreateBatch` and its DEFAULT-lot descendants, but a **raw insert** mints DEFAULT lots outside that method entirely: `FEFOInventoryService.php:810-831` (returns path), which re-states the variant guard by hand at `:801-807` and writes `'is_expired' => false` at `:827`. It is harmless for D4/D5 (its date is always `now()+shelf_life`, never past; the receipt path never reaches it), but a table presented as the complete lot-write inventory should say so.

**Fix:** one row: *"`FEFOInventoryService.php:810-831` — raw insert, returns path, machine-derived; not reached by this lane, `is_expired` stays hard-coded there (ticket `L-1-FU-fefo-default-lot-is-expired`)."*

---

### L1-G2-12 [MINOR] — D1's extra-query cost is two, not one, on the `receiveGoods` path

Brief line 136 says *"One extra scoped query per receive is accepted deliberately"*. The pre-pass runs at the top of **both** `createDraft` and `processReceiptLines` (line 134), and `receiveGoods:76,89` calls both in sequence — so a straight-through receive pays it twice, plus the existing per-line `Product::find` at `:515-518`.

**Fix:** *"two extra scoped queries on the straight-through `receiveGoods` path (pre-pass in `createDraft:76` and again in `processReceiptLines`), one on the draft and post paths individually."*

---

### L1-G2-13 [MINOR] — the standalone flag needs a DTO hop the deliverables omit

`allow_expired` must travel `CreateStandaloneReceiptRequest` → `StandaloneReceiptController.php:37-48` → **`StandaloneReceiptInput`** → `StandaloneReceiptService.php:121`. `StandaloneReceiptInput` is a DTO (rule 3, constructor-arg list at `:37-48`) and is not in deliverable 2. Also note `StandaloneReceiptController` does **not** use `HandlesDocuments` — its envelope at `:50-55` is hand-rolled, so the typed `$extra` helper does not serve it and `reason`/`details` must be written out there explicitly.

**Fix:** add `StandaloneReceiptInput.php` to deliverable 2 and one clause: *"the standalone envelope is hand-rolled (`StandaloneReceiptController.php:50-55`) — emit `reason`/`details` inline; `validationErrorResponse` is not on that path."*

---

### L1-G2-14 [MINOR] — citation nits and one naming collision to declare

- `validationErrorResponse` is declared at `HandlesDocuments.php:315` (body `:316-323`); the brief's `:316-323` points at the body only.
- `normalizePurchaseLine` spans `PurchaseOrderController.php:91-130` (closing brace at `:130`); the fence says `:91-129`.
- The brief never gives the controller's module: it is `app/Modules/**Document**/Presentation/Controllers/PurchaseOrderController.php` — worth stating once, since deliverable 2 lists a bare filename and the lane also touches a `Procurement` controller and an `Inventory` one.
- Convention 11: `App\Modules\Inventory\Domain\Exceptions\BatchRequiredForLineException` already exists (thrown at `StockAdjustmentDocumentService.php:599`, `:709`) and is a *different* concept (a negative adjustment must name the lot it draws down). Declare the distinction so the new `GoodsReceiptFailureReason::BATCH_DATA_REQUIRED` is not later merged with it. (It also leaks a product UUID in its message — same F-W2-27 class, out of scope: ticket it.)

---

### L1-G2-15 [MINOR] — record the pre-existing lot-creation race D5 reads against

D5's lookup is an unlocked read followed by `findOrCreateBatch`'s own unlocked read + insert (`BatchStockService.php:359-368` → `:386`). Two concurrent receipts of the same new lot number already race today into a unique violation (a `QueryException` → 500, not a 422). D5 neither causes nor fixes it, but it now sits directly behind a guard the brief promises is deterministic.

**Fix:** one OUT line + ticket `L-1-FU-lot-create-race`, so the next reviewer does not attribute it to this lane.

---

## Conditions for ACCEPT (all one-line edits, no re-gate)

1. `P-LOT-5` → `P-LOT-1` at brief lines 147, 148, 279, 314, plus the description==SKU note (**L1-G2-01**).
2. Rule the over-receipt sentence figure-free and rewrite the `W2-OVER-1` AFTER cell (**L1-G2-02**).
3. Add `GoodsReceiptController.php:125` as the third catch site; "both controllers" → "all three" (**L1-G2-03**).
4. Name the read-only `BatchStockService::findByBatchNumber(...)` D5 needs; widen deliverable 2 accordingly (**L1-G2-04**).
5. Extend the header supersession to B24's expired-lot half vs lane `L-9` (**L1-G2-05**).
6. Relabel task 4 b4 and task 5 b4/b5 as `[pin]` (**L1-G2-06**).
7. Mark task 3 bullet 1's two-variant-rows assertion PG-only (**L1-G2-07**).
8. State the `lotRefusesInboundStock` consequence of the `is_expired` fix + a pin (**L1-G2-08**).
9. Scope "zero rows" on the standalone surface to the seven tables (**L1-G2-09**).
10. Extend `draftInput()`'s `@return` shape with the flag (**L1-G2-10**).
11. Add the FEFO raw-insert row to the lot-writer table (**L1-G2-11**).
12. Correct the query-cost note to two (**L1-G2-12**).
13. Add `StandaloneReceiptInput.php` + the hand-rolled-envelope note (**L1-G2-13**).
14. Fix the four citation nits and declare the `BatchRequiredForLineException` distinction (**L1-G2-14**).
15. Ticket the lot-creation race (**L1-G2-15**).

## What to fix before merge

Apply the fifteen one-line conditions — above all the three that decide whether an executor can produce the acceptance oracle at all (`P-LOT-5` → `P-LOT-1`, the figure-free over-receipt sentence, the third controller's catch) — then dispatch; no round 3 is required.
