# Lane L-1 "receipt hardening" — adversarial brief gate, round 1

**Reviewer:** inventory-costing-reviewer (adversarial, code-grounded)
**Artefact under gate:** `docs/sessions/session-L-wave2-po-2026-09-01/LANE-L1-RECEIPT-HARDENING-BRIEF.md` (pre-implementation dispatch brief)
**Worktree read:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` (HEAD `be58e2339`, docs-only ahead of dev `62964e5cc`)
**Method:** every `path:line` in the brief re-opened in this worktree; every touched method's caller set enumerated by grep; no suites run, no servers.

---

## VERDICT: **CHANGES-REQUIRED**

4 BLOCKERs, 8 MAJORs, 7 MINORs. None of the blockers is a one-line edit, so ACCEPT-WITH-CONDITIONS is not available.

The brief is unusually good on the parts it verified: the citations that matter (`GoodsReceiptService.php:795`, `:523-525`, `:554`, `:559-566`, `:316-319`, `PurchaseOrderController.php:829/:841-843/:857-858/:859-865`, `BatchStockService.php:349-355/:359-368/:378`, `ReceiveGoodsRequest.php:33`, `PurchaseOrderToGoodsReceiptConverter.php:140,143`, `RolesAndPermissionsSeeder.php:148-149/:571`) are all accurate, and the run-24 BEFORE column reproduces the evidence ledger verbatim. The failures are all of one kind: **the brief under-counted the blast radius of the two shared-surface changes (D5, D4) and over-promised on one message oracle that today's data cannot produce.**

---

## Findings

### L1-G1-01 [BLOCKER] — D5 puts the expiry-conflict refusal in a method with **three** production callers, two of which machine-generate a *different* expiry on every later day

**Brief section:** "One surface per concept" (line 39) and **D5** (lines 116-120): *"`findOrCreateBatch` … is the single write path for a lot — two production callers, `GoodsReceiptService.php:559` and `StockAdjustmentService.php:1105`. The expiry-conflict rule (task 5) belongs THERE, not in the receipt service."*

**Code:** there is a third direct caller — `BatchStockService::ensureDefaultBatch` at `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:100`, which is itself reached from five production sites via `ensureDefaultBatchForUntrackedRemainder` (`:292`):

- `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:399` (sales-order reservation)
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:928` (flip-to-batch-tracked backfill)
- `apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:198` (opening stock)
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1957`
- `database/seeders/ParapharmacySeeder.php:1389`

Two concrete production breaks D5 as written would ship:

1. **Positive stock adjustment on any later day.** `StockAdjustmentService::receiveIntoDefaultBatchByDelta` (`:1088-1119`) calls `findOrCreateBatch` with
   `expiryDate: $shelfLifeDays === null ? null : Carbon::parse($asOfDate)->addDays($shelfLifeDays)->toDateString()` where `$asOfDate = now()->toDateString()` (`:1102`).
   For a batch-tracked product with `default_shelf_life_days` set, the DEFAULT lot created on day *D* carries `D+n`; the next positive adjustment on day *D+1* supplies `D+1+n` ≠ stored ⇒ **D5 throws**. Same shape in `ensureDefaultBatch` (`BatchStockService.php:96-98`), so the reservation path (`StockReservationService.php:399`) and the product backfill (`ProductController.php:928`) break identically.
2. **Opening balances.** `OpeningBalancePostingService.php:185-208` deliberately *reports* this exact conflict: it snapshots the lot with `findDefaultBatch` before the call and classifies the outcome as `OpeningLotExpiryOutcome::ConflictExistingLot` (`:208 classifyExpiryOutcome`). The W4-1 comment block at `BatchStockService.php:110-127` states the design in full ("OVERWRITING a date that is already there is NOT done here … the caller compares what it asked for against what the lot now carries and reports the disagreement"). D5 converts a designed, reported, non-fatal outcome into an exception that aborts the opening posting.

**Why it matters:** wrong-severity by construction — a lane scoped to "goods receipt" would make every second-day stock adjustment and every conflicting opening row throw, on the parapharmacy vertical where *every* physical product is batch-tracked (`config/verticals.php:341-343`, `Product.php:186-212`). The existing suite cannot catch it: `tests/Feature/BatchExpiry/EnsureDefaultBatchTest.php:91-130` re-runs `ensureDefaultBatch` with an **identical** `asOfDate` (`'2026-06-27'` twice), so the day-drift case has no coverage today, and the brief's own task-5 adjustment arm (line 169) *asserts the broken behaviour as desired*.

**Fix wording:** replace D5's placement. Either (a) scope the refusal to the receipt boundary — perform the stored-vs-supplied comparison in `GoodsReceiptService` before `:559` — or (b) give `findOrCreateBatch` an explicit policy parameter (`ExpiryConflictPolicy::Reuse` default, `::Refuse` passed only from the receipt call site) and state that `DEFAULT_BATCH_NUMBER` lots keep the set-once W4-1 rule. Delete the "single write path / two callers" sentence and replace it with the six-site caller list above. Add a mandatory regression test: a second positive stock adjustment on `now()->addDay()` for a product with `default_shelf_life_days` still succeeds, and an opening row with a conflicting expiry still returns `OpeningLotExpiryOutcome::ConflictExistingLot` rather than throwing.

---

### L1-G1-02 [BLOCKER] — the acceptance oracle demands the SKU in every refusal message, but PO lines created through the PO API never carry `product_code`

**Brief section:** D2 line 94 (`describeLine()` → `line {line_number} ({product_code} — {description})`, *"falling back … when `product_code` is null (service lines)"*), task 2 line 138 (*"contains the SKU"*), the oracle rows at lines 180/183/184, and the browser row at line 216 (`label="line 1 (P-OVER-1 — …)"`).

**Code:** `document_lines.product_code` exists and is fillable (`DocumentLine.php:83`, migration `2025_12_22_200000_add_product_code_to_document_lines_table.php:22`) but **is not written by either PO write path**:
- `PurchaseOrderController.php:455-473` (`store`) — the `DocumentLine::create([...])` array has `description`, `designation_default_snapshot`, `line_number` … and **no `product_code`**; same at `:597-610` (update).
- `DraftPersistenceService.php:429-440` (the generic draft line writer) — same omission.

The only writers of `product_code` are POS/standalone/conversion paths (`grep "'product_code' =>"`: `POSAccountChargeDraftService.php:82,210`, `StandaloneReceiptService.php:275`, `CopiesDocumentData.php:134`, the converters, `CreateSupplierInvoiceService.php:173`).

**Why it matters:** on every PO built the way run 24 built them (POST `/api/v1/purchase-orders`), `product_code IS NULL`, so `describeLine()` silently takes the *fallback* branch and emits `line 1 (Paracétamol 500mg)`. The B51 guarantee ("names the item **code/SKU** + line number") is not met, and three of the five acceptance-oracle AFTER strings are unreachable. An executor told to make the oracle green will either fake it or wander outside scope to backfill `product_code` (a Document-module change this lane forbids).

Compounding: the over-receipt guard `assertQuantitiesWithinRemaining` is invoked at `:496` — **before** the `Product` row is loaded at `:515-518` — and also from `createDraft` at `:157`, where no product is loaded at all. So "just read `$product->sku`" is not a drop-in either.

**Fix wording:** decide and write it into the brief: (a) resolve the SKU from the product for the two guards that already hold it (`:523-525` batch, `:559` variant) and re-derive the over-receipt oracle string from `description` only, **or** (b) load the product (scoped, unlocked, as at `:515-518`) before the over-receipt assertion and state explicitly that the guard's evaluation order for service lines is unchanged. Either way, restate the five oracle AFTER strings so they are derivable from the code, and add a one-line note that `product_code` is null on API-created PO lines.

---

### L1-G1-03 [BLOCKER] — D4's post-time guard creates a *new* stuck-draft class, the exact thing D4 says it avoids

**Brief section:** D4 bullet 3 (line 112): *"Defense in depth in the service: the same guard runs in `processReceiptLines` before `findOrCreateBatch`, because `createDraft` accepts `batches` … the draft path must not be able to persist something `post()` will refuse (that is the F-W2-19 stuck-draft class — do not create a new instance of it)."*

**Code:** `processReceiptLines` runs at **post** (`post()` at `:203`, transaction at `:205`). The draft is created earlier by `createDraft` (`:107-201`), whose payload stores **only** `batch_data` (`:136-140`), and `draftInput()` re-reads **only** `batch_data` at post (`:377-378`). There is no request context inside `post()` and **`allow_expired` is never persisted**.

Consequences:
- A user WITH `goods-receipt.receive-expired` saves a draft with a past expiry (validation passes because the flag is present) → at post the service guard has no flag and no actor intent → the draft is **permanently unpostable**. That is a brand-new F-W2-19 instance, authored by the mitigation.
- The brief's own task-4 bullet 5 ("`save_as_draft: true` with a past expiry and no flag ⇒ 422 and **zero** `goods_receipts` rows") is satisfied only by the *FormRequest*, not by the service guard — i.e. the guard the brief calls "defense in depth" is at the wrong layer to deliver the property the test asserts.

The in-repo precedent the brief should mirror is right there and behaves the same way (a draft with an unauthorised price override can be created and is refused only at post): `assertCanApplyDraftPriceOverrides` invoked at `:219`, defined at `:334-348`. It is a *known* stuck-draft shape, not a model to copy.

**Fix wording:** move the expired-lot guard into `createDraft` (nothing persists, matching the "refusal is a no-op" oracle) **and** state the post-time behaviour explicitly: either persist `allow_expired` into `goods_receipts.payload` and re-authorise the posting actor at `post()` (mirroring `:219`), or declare that an admitted expired lot needs no re-check at post and run the guard only at draft creation. Name which.

---

### L1-G1-04 [BLOCKER] — the second receipt entry point is unlisted; D4 changes its behaviour with no override surface and no test

**Brief section:** Scope (lines 45-63) names one API surface; "One surface per concept" (line 39) does not mention it; D4 (lines 104-114) assumes `ReceiveGoodsRequest` is the only validation layer.

**Code:** a second HTTP write path reaches the same engine:
`apps/api/app/Modules/Procurement/Presentation/routes.php:40-42` → `StandaloneReceiptController::store` (`:37`, line payload includes `batch: {batch_number, expiry_date, manufacturing_date}` per the docblock `:66-72`) → `StandaloneReceiptService.php:121` `createDraft(..., $maps['batchData'], ...)` → `:134` `post($draft, …, true)`. Its FormRequest is `CreateStandaloneReceiptRequest` — **no `allow_expired`**, and its error envelope is `STANDALONE_RECEIPT_FAILED` at 422 (`StandaloneReceiptController.php:49-56`), so the new `reason` discriminator is dropped on that surface.
Third path: `PurchaseOrderToGoodsReceiptConverter.php:140,143` (the brief does record this one, line 78).

**Why it matters:** after D4 the standalone/document-ingestion receipt (`SupplierDeliveryNoteCommitter.php:48` gates on `goods-receipt.create-standalone`) hard-refuses any legitimately-expired-at-arrival lot with **no override at all**, and the operator sees a message with no `reason`. That is an undeclared second surface (convention 11) and an unversioned API behaviour change.

**Fix wording:** add `StandaloneReceiptService.php:121` / `POST /api/v1/goods-receipts/standalone` to the caller inventory in the brief, and rule one of: (a) the guard lives at the PO-receive boundary only (standalone unaffected — then say so and pin it with a test), or (b) `CreateStandaloneReceiptRequest` gains the same `allow_expired` + permission pair and a test. Also state what the standalone envelope does with `reason`.

---

### L1-G1-05 [MAJOR] — task 1 requires an **aggregated** batch-data refusal; the guard throws on the first offender inside the write loop and the change list forbids restructuring

**Brief section:** task 1 bullet 4 (line 133): *"Mixed PO, batches given for **neither** ⇒ 422 listing **all** offending lines (the guard aggregates; it must not stop at the first)"*, vs task 1's Change line 134 (*"`receiveAll(...)` signature + the `:795` call; `PurchaseOrderController.php:841-843` … Nothing else"*) and D1 line 76 (*"three lines plus a signature"*).

**Code:** `GoodsReceiptService.php:523-525` throws inside the per-line loop that begins at `:485` and has already created `GoodsReceiptLine` rows / stock movements for earlier lines (rolled back by the transaction, but the *listing* is impossible after the throw). Aggregating requires a new pre-pass over `$purchaseOrder->lines` (product load + `requires_batch_tracking` + `isset($batchData[...])`) before any write — which also duplicates the scoped product query at `:515-518`.

**Fix wording:** name the pre-pass explicitly (method name, where it runs, that it reuses the same tenant/company-scoped product query and does not hold row locks), and correct D1's "three lines" claim.

---

### L1-G1-06 [MAJOR] — B53's vendor cells overstate the baseline; the honest decision is DIVERGE, not MATCH

**Brief section:** baseline row **B53** (line 35): Odoo `✅ (m) refuses/keeps + warns`, ERPNext `✅ (m) batch expiry is fixed on the Batch record`, Dolibarr `⚠ (m)`, verdict `WRONG → MATCH`.

**Challenge:** the (m) labelling is convention-10 compliant (`docs/conventions/10-BENCHMARK-FIRST-SPECS.md:43`), so this is a content challenge, as instructed. In all three references a lot/batch record *owns* its expiry and a re-declaration **silently keeps** the stored date — ERPNext's own cell says so ("fixed on the Batch record"), which is *keeping*, not *reporting*. None of the three is known to raise a blocking error on the second receipt. That makes a hard 422 **stricter than every reference** — the same shape as B24, which the brief correctly labels DIVERGE and marks owner-scoped. B53 gets MATCH instead, and that mislabel is what licenses the cross-service placement in L1-G1-01.

Same weakness, lower stakes, on **B52** (line 34): in Odoo, ERPNext and Dolibarr a product variant is a distinct product/item record, so "the lot is per product+variant" is structurally free rather than a guarantee those systems chose. The decision (MATCH) is still right; the reasoning is not.

**Fix wording:** re-label B53 `WRONG → **DIVERGE (stricter)**`, add the one-sentence why and an owner-ruling marker like B24's, and reword B52's vendor cells as "variants are distinct item records, so the guarantee is structural".

---

### L1-G1-07 [MAJOR] — the convention-11 statement contradicts the glossary and the code

**Brief section:** line 39 (single write path, two production callers).

**Code/doc:** `docs/glossary.md:40` — **Lot (batch)** — declares the operator surfaces as *"receipts, opening stock import, counting"*, i.e. three write surfaces, and the tables as `product_batches`, `inventory_batch_stock`. Direct callers of `findOrCreateBatch` in production: `BatchStockService.php:100`, `GoodsReceiptService.php:559`, `StockAdjustmentService.php:1105`.

**Fix wording:** replace the sentence with the true caller list and cite `docs/glossary.md:40`; then the D5 placement argument has to be re-made on its merits (see L1-G1-01).

---

### L1-G1-08 [MAJOR] — the new permission has no rollout step, and the browser re-verification silently depends on it

**Brief section:** D4 bullet 2 (line 111) and the browser table row `W2-LOT-6` (line 213: *"re-receive with `allow_expired:true` as an admin to rebuild the expired-lot fixture"*), plus the cross-row warning at line 219 that `W2-LOT-10` depends on that fixture.

**Code:** the seeder anchors are correct (`RolesAndPermissionsSeeder.php:148-149` beside `goods-receipt.edit-price` / `goods-receipt.create-standalone`; grant list `:571`). But a seeder edit does **not** reach an already-provisioned tenant DB: the session-L stack tenant `01a05ce9-…` and staging both need `RolesAndPermissionsSeeder` re-run per tenant before the override arm can return 200. Nothing in the brief, the deliverables, or the checklist says so. If it is missed, `W2-LOT-6`'s override arm 422s, `W2-LOT-10`'s expired-lot tuple is never built, and two rows of the running suite go red for an operational reason that looks like a code defect.

**Fix wording:** add to "Browser re-verification" and to the deliverables: *"the orchestrator re-runs `php artisan tenants:run db:seed --class=RolesAndPermissionsSeeder` (or the project's equivalent) on the session-L tenant before re-running the spec; the same step is a promotion precondition for staging."*

---

### L1-G1-09 [MAJOR] — B23's other half is silently dropped: `createDraft` has no batch-data check

**Brief section:** B23 row (line 31) reproduces the research gap but omits its middle clause; task 1 has no `save_as_draft` arm.

**Code/doc:** `docs/superpowers/audits/2026-09-01-wave2-po-flow/01-research.md:181` states the gap as *"enforced at post (`GoodsReceiptService.php:523-525`) ✔ and client-side …; **but `createDraft` has no check** (`:107-201`) and `receiveAll` passes `[]` (`:795`)"*. Verified: `createDraft` (`:107-201`) never reads `requires_batch_tracking`; it writes `GoodsReceiptLine` rows and stores `batch_data` verbatim at `:136-140`. So `save_as_draft: true` with no `batches` on a batch-tracked PO persists a draft that `post()` can never accept — the F-W2-19 class, on the very finding this lane claims to close.

**Fix wording:** either extend task 1 to run the (now aggregating) batch-data guard in `createDraft` as well — one guard, both entry points — or add an explicit OUT line: *"`createDraft` has no batch-data check (`:107-201`); recorded as `L-1-FU-draft-batch-guard`, not fixed here"*, and say why the draft surface may keep producing unpostable drafts.

---

### L1-G1-10 [MAJOR] — the "no UUID from this lane" checklist item is unsatisfiable as scoped, and the enum declares a case with no throw site

**Brief section:** D2 line 91 (enum cases incl. `RECEIVED_PRICE_INVALID`, `NOTHING_TO_RECEIVE`), task 2 Change line 144 (four throw sites), checklist line 251.

**Code:** UUID-leaking `DomainException`s on the receipt path are at `:163`, `:213`, `:316-319`, `:327-330`, `:524`, `:538`. The brief rewrites four; `:163` and `:538` (`"received_unit_price must be greater than zero for line {$line->id}."` — reachable from the same 422 envelope, both the draft and the post path) and `:213` (`"Goods receipt {$lockedReceipt->id} must be Draft before posting."`) keep leaking. `RECEIVED_PRICE_INVALID` therefore has no producer, and `NOTHING_TO_RECEIVE` maps to `:193`/`:720`, also outside the change list.

**Fix wording:** add `:163` and `:538` to the throw-site list (they are `describeLine()` one-liners), rule `:213` explicitly IN or OUT (it names a receipt id, not a product), and drop any enum case with no throw site — or narrow the checklist to "the messages listed in task 2".

---

### L1-G1-11 [MAJOR] — the oracle bakes scale-4 quantities into operator-facing strings, against rule 19's display clause

**Brief section:** oracle row `W2-OVER-1` (line 184): *"`Ordered: 10.0000, already received: 0.0000, requested: 10.0001.`"* and task 2 line 138 (*"contains the ordered / already-received / requested quantities"*).

**Contract:** rule 19 display/emission — human-facing quantities render at `units.decimal_places` via `QuantityScale::formatForUnit`; the mechanical guards (`ForbidFixedScaleQuantityLiteralRule`, `apps/api/app/PHPStan/Rules/ForbidFixedScaleQuantityLiteralRule.php:41-48`) only fire in the Presentation layer, so `GoodsReceiptService` (Application) will not fail PHPStan — the contract is violated silently. The tension is real, not cosmetic: for a `piece` unit (0 decimals) `10.0001` renders `10`, and the over-receipt message loses the very distinction it exists to explain. This lane's whole premise (B51) is that these strings are operator-facing.

**Fix wording:** rule it explicitly in the brief — either "quantities in refusal messages are emitted with `QuantityScale::formatForUnit($qty, $unit)`; the oracle strings are re-derived accordingly and the sub-unit over-receipt case is reported as a comparison, not a rounded figure", or "canonical scale-4 is retained in refusal messages as a deliberate exception because the value must be byte-comparable to the payload — recorded against rule 19".

---

### L1-G1-12 [MAJOR] — the second-company arm mandates an outcome the brief never derived from code

**Brief section:** "Second-of-everything" item 1 (line 194): *"the new `goods-receipt.receive-expired` grant in company A does **not** let the same user bypass the guard in company B (permissions are team-scoped — assert the 422)."*

**Problem:** no citation. Whether the same user ends up holding the role in company B depends on what the real company-creation path does with roles/teams — which the brief neither cites nor was verified here. Mandating a specific expected status in a test the executor must make green is how a wrong oracle becomes a wrong implementation.

**Fix wording:** *"derive the expected cross-company result from the permission/team code (cite `path:line` for how company creation assigns roles) and assert the derived behaviour; if the user does hold the grant in company B, assert that and record it as a finding rather than forcing a 422."*

---

### L1-G1-13 [MINOR] — the controller catch edit is mandatory and must be ordered before the `\DomainException` arm

**Brief section:** Forbidden actions line 228 (*"and, if D2 needs it, the `\DomainException` arm at `:857-858` (read-only preferred)"*).

**Code:** `PurchaseOrderController.php:857-858` is `catch (\DomainException $e) { return $this->validationErrorResponse('GOODS_RECEIPT_FAILED', $e->getMessage()); }`. A `GoodsReceiptException extends \DomainException` is matched by that arm first, so `reason`/`details` are dropped unless a narrower `catch (GoodsReceiptException …)` is inserted **above** it. D2 definitively needs the edit; "read-only preferred" is wrong.

**Fix wording:** *"insert `catch (GoodsReceiptException $e)` immediately BEFORE the `\DomainException` arm at `:857`; the `\DomainException` and `\RuntimeException` arms are otherwise unchanged."*

---

### L1-G1-14 [MINOR] — `array $extra = []` will fail PHPStan level 8 and contradicts the brief's own rule-3 sentence

**Brief section:** D2 line 93 (optional `array $extra = []` on `validationErrorResponse`) vs D2 line 92 (*"no `mixed`, no bare arrays crossing a boundary — rule 3"*).

**Code:** `HandlesDocuments.php:316-324`; `phpstan.neon` level 8 → missing iterable value types are reported. `array<string, mixed>` satisfies PHPStan but violates rule 3.

**Fix wording:** specify the shape, e.g. `@param array{reason: string, details?: array{lines: list<array{line_number: int, product_code: string|null, description: string}>}} $extra`, or pass the typed details DTO and let the helper serialise it.

---

### L1-G1-15 [MINOR] — "today" must come from one clock in the validator, the service and the nightly command

**Brief section:** D4 (line 108-113): `after_or_equal:today` in validation, `expiry_date < today` in `findOrCreateBatch`, boundary test `expiry_date === today ⇒ 200` (task 4 line 158).

**Code:** the nightly rule is `BatchExpiryDailyCheckCommand.php:123-130` (`where('expiry_date','<',$today)` with an injected `Carbon $today`); FEFO excludes with `now()->startOfDay()` (`FEFOInventoryService.php:104-112`). Laravel's `after_or_equal:today` resolves against the app timezone. A mismatch makes the boundary arm flaky and can admit a lot the same request just stamped `is_expired = true`.

**Fix wording:** name the single source ("all three compare against `now()->startOfDay()` in the app timezone") and assert it in the boundary test.

---

### L1-G1-16 [MINOR] — citation drift on four anchors the brief tells the executor to re-open

- `received_unit_prices ⇒ prohibited` is **`ReceiveGoodsRequest.php:35`**, not `:34` (`:34` is `manufacturing_date`). Brief D4 line 109.
- The K-1 hunk is **`PurchaseOrderController.php:91-129`** (`normalizePurchaseLine` opens at `:91`), not `:100-127`. Brief line 228 — as written it leaves `:91-99` unprotected.
- `tests/Feature/BatchExpiry/BatchStockServiceVariantTest.php:87-100` is the *same-variant idempotency* shape; the "same batch_number under a second variant ⇒ two rows" shape the brief points at is at `:173-200`. Brief task 3 line 148.
- The nightly rule is `BatchExpiryDailyCheckCommand.php:123-130` (brief says `:119-134`, which spans the docblock). Brief line 39 / D4 line 107.

**Fix wording:** correct the four line refs.

---

### L1-G1-17 [MINOR] — lane-map conflict with the research the brief cites as authoritative

`docs/superpowers/audits/2026-09-01-wave2-po-flow/01-research.md:181` assigns B23 / F-W2-02 to **lane `L-8`**; this brief claims it for L-1 (Scope line 49). One line of supersession prevents two lanes shipping the same fix.

---

### L1-G1-18 [MINOR] — `product_batches` is claimed as a catalogue entity but is not in the ratchet's canonical list

Brief line 37 declares `product_batches` a catalogue entity; convention 09 (`docs/conventions/09-SECOND-OF-EVERYTHING.md`, "The canonical list is the `CATALOGUE_TABLES` constant … keep the two in sync") — and `tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:30-54` does **not** list it (nor is it in `EXCLUDED_TABLES`). No ratchet failure results, because its uniques already carry `company_id` (`database/migrations/tenant/2026_06_02_100008_add_variant_id_to_product_batches.php:27-31`, partial indexes `product_batches_non_variant` / `product_batches_with_variant`) — which is also what makes the brief's second-company arm genuinely provable. Either add the table to `CATALOGUE_TABLES` (safe, no violating key) or drop the "catalogue entity" wording.

---

### L1-G1-19 [MINOR] — several mandated test bullets are green before the change; the "red first, paste the red output" instruction invites fabricated evidence

See the vacuous-test list below. **Fix wording:** mark each such bullet `[pin]` and require red output only for the `[red]` ones.

---

## Callers I traced

| Method / surface | Production callers (`file:line`) | Brief lists them? | Consequence |
|---|---|---|---|
| `BatchStockService::findOrCreateBatch` (`BatchStockService.php:340`) | `BatchStockService::ensureDefaultBatch:100`; `GoodsReceiptService.php:559`; `StockAdjustmentService.php:1105` | **No** — claims two | L1-G1-01 (BLOCKER) |
| `BatchStockService::ensureDefaultBatch` (`:81`) | `ensureDefaultBatchForUntrackedRemainder:292` | No | transitive blast radius |
| `…::ensureDefaultBatchForUntrackedRemainder` (`:255`) | `StockReservationService.php:399`; `ProductController.php:928`; `OpeningBalancePostingService.php:198`; `StockAdjustmentService.php:1957`; `ParapharmacySeeder.php:1389` | No | reservation / backfill / opening / seeder all reach D5 |
| `GoodsReceiptService::receiveAll` (`:771`) | `PurchaseOrderController.php:843`; `PurchaseOrderToGoodsReceiptConverter.php:143`; tests `GoodsReceiptTest.php:255,669,692,868`, `GoodsReceiptLedgerWriteTest.php:337` | Yes (both prod) | appending `array $batchData = []` 4th is positionally safe ✔ |
| `GoodsReceiptService::receiveGoods` (`:65`) | `receiveAll:795`; `PurchaseOrderController.php:831`; `PurchaseOrderToGoodsReceiptConverter.php:140` | Yes | ✔ |
| `GoodsReceiptService::createDraft` (`:107`) | `receiveGoods:76`; `PurchaseOrderController.php:813`; **`StandaloneReceiptService.php:121`** | **No** (standalone missing) | L1-G1-04 (BLOCKER), L1-G1-09 |
| `GoodsReceiptService::post` (`:203`) | `receiveGoods:89`; `StandaloneReceiptService.php:134` (positional `true` fail-closed) | Partially (via §D2 note) | expired guard reaches the standalone path |
| `HandlesDocuments::validationErrorResponse` (`:316`) | 39 call sites across Document controllers | Yes ("nullable-default addition only") | ✔ non-breaking; typing caveat L1-G1-14 |
| `MissingVariantException::forProduct` (`MissingVariantException.php:24-27`) | `BatchStockService.php:353` only; `::withId` used elsewhere | Yes | catch-at-call-site at `:559` is safe ✔ |
| `assertQuantitiesWithinRemaining` (`:309`) | `createDraft:157`; `processReceiptLines:496` | **No** — brief treats it as one site | affects L1-G1-02 (no `$product` at `:157`) |
| `RolesAndPermissionsSeeder` permission list | `:148-149` definition, `:571` grant | Yes ✔ | but no rollout step — L1-G1-08 |

---

## Tests that would be vacuous (green before the change) — must be labelled pins, not red-first

| Brief line | Bullet | Why it passes today |
|---|---|---|
| 130 | task 1: empty body ⇒ 422 + zero receipts/movements/batches | already 422 `GOODS_RECEIPT_FAILED` via `:523-525` inside the `post()` transaction (`:205`); only the `reason` + no-UUID assertions are red |
| 142 | task 2: "422 and never 500" for all four | all four already throw `\DomainException` (`:316`, `:327`, `:524`, `MissingVariantException extends \DomainException`) → `:857-858`; pure pin |
| 143 | task 2: `error.code` still `GOODS_RECEIPT_FAILED` | regression pin by construction |
| 150 | task 3: batch-tracked product with **no** variants still receives product-level | unchanged path (`variantId` null ⇒ same lookup) |
| 158 | task 4: `expiry_date === today ⇒ 200` | today's code has no date constraint at all |
| 161 | task 4: `W2-LOT-11` shapes (missing / null expiry) still 422 | `required_with` + `date` already do this (`ReceiveGoodsRequest.php:33`) |
| 167 | task 5: same batch, **same** expiry ⇒ 200, quantities summed | today's `:359-368` behaviour |
| 168 | task 5: supplied `null` vs stored date reuses the lot | today's `:359-368` behaviour |
| 169 (2nd clause) | task 5: adjustment, same-expiry re-declaration still succeeds | today's behaviour — and its sibling clause asserts the regression in L1-G1-01 as *desired* |
| 195 | second-location: one lot row, two `inventory_batch_stock` rows | already true (`BatchStock::firstOrCreate(['batch_id','location_id'])`, `BatchStockService.php:138-141`) |

Genuinely red-first: task 1 bullets 2/3/4, task 2's `reason`/`details`/no-UUID/label assertions, task 3 bullets 1-2, task 4 bullets 1/2/3/5/6, task 5 bullet 1, and the re-run arms for the new refusals.

---

## What to fix before merge

Re-scope D5 off the shared DEFAULT-lot path (L1-G1-01), make the message oracle derivable from data that actually exists (L1-G1-02), move the expired-lot guard to draft creation with a stated post-time rule (L1-G1-03), and declare + rule on the standalone receipt surface (L1-G1-04); then the eight MAJORs are brief edits and this can go back out on round 2.
