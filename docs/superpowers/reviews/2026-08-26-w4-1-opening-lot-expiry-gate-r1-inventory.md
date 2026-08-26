# W4-1 opening-lot expiry — adversarial gate r1 (inventory / FEFO / batch lens)

Branch `fix/campaign-w4-1-opening-lot-expiry` · base `9d0d08ae5` · head `f60268868` · READ-ONLY review.
All paths below are relative to `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w4-1-opening-lot-expiry/`.

## VERDICT: spec ✅ · quality CHANGES-REQUESTED

The defect is correctly diagnosed and the core fix is right: the `?? 365` fallback is gone from all
three mint sites, every FEFO ordering site states nulls-last explicitly, and every "is it expired?"
predicate admits NULL. What blocks merge is coverage and two silent-drop paths, not the ranking rule.

---

## What I verified (not taken from the report)

**Mint sites — all three removed, no fourth survives.**
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:96-98` — precedence is
  supplied → configured shelf life → `null`; the `DEFAULT_SHELF_LIFE_DAYS` constant is gone
  (docblock @36-47 replaces it).
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:823-825` — return restore
  mints `null` when `$defaultShelfLifeDays === null`; the mirror constant is gone (@51 area).
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1103,1110-1117` — no fallback.
- Full-tree sweep for a surviving inventor: `grep DEFAULT_SHELF_LIFE|default_shelf_life_days|addDays`
  over `apps/api/app` + `apps/api/database` returns only the two legitimate derivations above plus
  `ParapharmacySeeder.php:1380,1389` which plucks `default_shelf_life_days` off the product itself.
  GRN (`GoodsReceiptService.php:564`), standalone receipt / supplier invoice
  (`CreateStandaloneReceiptRequest.php:36`, `CreateSupplierInvoiceRequest.php:151`) and manual create
  (`CreateBatchRequest.php:39`) all still REQUIRE an operator-supplied expiry — none invents.
- The 5 indirect callers (`ProductController.php:928`, `StockReservationService.php:399`,
  `OpeningBalancePostingService.php:178`, `StockAdjustmentService.php:1957`, the seeder) all funnel
  through `ensureDefaultBatch()`, so the central fix covers them.

**Every FEFO ordering site.** `grep expiry_date … | orderBy` yields exactly 10 hits;
8 carry `(… expiry_date IS NULL) ASC, expiry_date ASC`:
`FEFOInventoryService.php:95` (suggest), `:271` (raw atomic consume),
`BatchRepository.php:71,107`, `StockTransferService.php:859`,
`StockAdjustmentService.php:1563` (count draw-down),
`PosCoreReceiptProjection.php:2699` (restore provenance),
`RepairPhantomDefaultBatchesCommand.php:668`.
The two bare ones are correct by construction: `FEFOInventoryService.php:859` (`getExpiringProducts`,
`whereBetween` @848 already excludes NULL) and `:909` (`getExpiredBatchesWithStock`, `<` @885 excludes NULL).
No PHP-side `sortBy`/`usort` on expiry exists anywhere in `apps/api/app`. `apps/pos` does no lot FEFO.

**Every expiry predicate.** NULL-admitting: `FEFOInventoryService.php:106-110` (suggest),
`:270` (raw consume), `:948-951` (`getTotalAvailableQuantity`), `RepairPhantomDefaultBatchesCommand.php:662-665`.
Deliberately NULL-excluding and correct: `BatchExpiryDailyCheckCommand.php:127,163`,
`BatchRepository.php:100`, `FEFOInventoryService.php:848,885`.
`Batch::isExpired()` @82 / `daysUntilExpiry()` @86-93 / `expiryStatus()` @101-103 admit NULL, and
`canBeSold()` @121-132 therefore stays true — which is what keeps `computeFefoSplit`
(`StockTransferService.php:866`) and `assertBatchCanIssue` (`:970`) consistent with the SQL predicates.
`CriticalBatchExpiryNotification.php:44` still dereferences `expiry_date` unguarded but is only fed
from `getExpiringProducts()`, which cannot emit a NULL row — safe.

**`pos_receipt_line_batch_allocations.expiry_date` nullable vs sealed rows.** The change
(`2026_08_26_100000…:37`) only relaxes the constraint; no row is rewritten. Nothing in the fiscal chain
reads that column — `BatchTraceabilityController.php:77-88` maps allocations without their expiry, and
the writers (`PosCoreReceiptProjection.php:2109-2116`, `ReceiptCreationService.php:1631-1638`) simply
pass the (now nullable) `ConsumedBatchDTO::$expiryDate` through. The two columns are relaxed in the SAME
migration, so a NULL lot expiry can never reach a still-NOT-NULL allocation column. Concern 2 is benign.

**W4R-2 DEFAULT-lot provenance is not regressed.** `PosCoreReceiptProjection` changes exactly one line
(`:2699`, the ordering inside `lotProvenanceForOriginalLine`). `DEFAULT_BATCH_NUMBER`, `defaultLotIds()`,
`outstandingShippedLots()` and the provenance-vs-heuristic split are untouched in the diff.

**Precision (rule 19).** No new float touches money or quantity; quantities stay 4-dp decimal strings
(`OpeningBalanceLine.php:47-48`, `BatchStockService.php:123-126`). The wizard ingress keeps the quantity
regex ceiling (`OpeningBalanceBatchController.php:32-33`). `Batch::getTotalQuantityAttribute()`
(`Batch.php:143-151`) and `FEFOInventoryService::getTotalAvailableQuantity()` (`:957`) return `float` —
both PRE-EXISTING, not introduced here.

---

## Findings

### [IMPORTANT] The production stock-decrement path has ZERO test coverage with a NULL-expiry lot
`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:262-273` —
`consumeBatchesAtomically()` is the raw, PG-only (`FOR UPDATE OF ibs SKIP LOCKED`) query that performs
the server-authoritative lot draw for BOTH the POS sale
(`PosCoreReceiptProjection.php:2069` → `snapshotLotAllocations`) and the delivery note
(`DeliveryNoteService.php:315-360`). This lane rewrote **both** its WHERE predicate (`:270`) and its
ORDER BY (`:271`). `grep -rl "expiry_date' => null"` over `apps/api/tests` returns **nothing**:
`AtomicFEFOConsumptionTest.php:270` builds every lot with `now()->addDays($expiryDays)`, and the new
`OpeningLotExpiryW41Test` only exercises `suggestBatchesForSale()`. On day one of the launch tenant
EVERY lot is undated, so if that predicate or ordering were wrong the result is not a mis-sort — it is
a strict-fulfilment refusal on every batch-tracked sale and delivery. Fix: add a PG case (undated lot +
dated lot + expired lot) asserting the dated lot draws first, the undated lot draws second and is not
hidden, and add both new classes to the `backend-test-pgsql` `--filter` allowlist
(`.github/workflows/ci.yml:1048` — neither `OpeningLotExpiryW41Test` nor
`NullInventedDefaultLotExpiryMigrationTest` is listed today, so the PG branch of the backfill predicate,
`…INTERVAL '365 days'` at `2026_08_26_100100…:136`, never runs in CI either; SQLite runs a DIFFERENT
SQL string at `:137`).

### [IMPORTANT] A supplied expiry is silently DROPPED when the DEFAULT lot already exists — and the preview lies about it
`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:297-306` —
`findOrCreateBatch()` returns the existing lot untouched, so `ensureDefaultBatch()`'s new `$expiryDate`
argument (`:96-108`) only ever lands on the FIRST opening for a product+variant. The DEFAULT lot is
one row per product+variant across ALL locations (`ensureDefaultBatch` tops up per location at `:116-127`),
so a multi-location opening — two rows, same SKU, `MAIN` and `WAREHOUSE`, which the shipped template at
`apps/web/src/features/opening-balances/components/FileUpload.tsx:97` literally demonstrates — silently
discards the second row's `expiry_date`, and a first-undated/second-dated pair leaves the lot NULL
forever. Worse, `InventoryOpeningService::getPostPreview()` (`:389-392`) shows the operator the supplied
date before posting, so the preview asserts something the post will not do. This is the exact
silently-lost-fact class the lane exists to remove, relocated one layer up. At minimum: fill a NULL
expiry on an existing DEFAULT lot, and emit a labelled warning row (`expiry_conflict`) instead of a
silent drop when the dates disagree. The report acknowledges the mechanism (concern 3) but not the
multi-row/preview consequence.

### [IMPORTANT] The backfill predicate keys on the product's CURRENT shelf life, not the value at mint time
`apps/api/database/migrations/tenant/2026_08_26_100100_null_invented_default_lot_expiries.php:143`
(`whereNull('products.default_shelf_life_days')`). The `?? 365` fallback fired against the value the
product held WHEN the lot was minted. Two drifts follow: (a) FALSE NEGATIVE — a product that had NULL
at import and has since been given a shelf life keeps its invented `mfg + 365` date, FEFO keeps ranking
on the fiction, and the census at `:87`/`:95` reports it as nothing to fix; (b) FALSE POSITIVE — a
product configured at exactly 365 when the lot was minted and since cleared to NULL has a legitimately
rule-derived date nulled. (a) is the one that matters: it is undetectable from the migrate output. Add a
second, non-mutating census of DEFAULT lots where `expiry_date = manufacturing_date + 365` but
`default_shelf_life_days IS NOT NULL AND <> 365`, echoed as a residual so an operator can see what the
predicate declined to touch. (Confirmed the *coincidence* risk the brief asked about is acceptable: a
real supplier lot is excluded by `batch_number = 'DEFAULT'` at `:142`, and that negative IS pinned —
`NullInventedDefaultLotExpiryMigrationTest.php:79,93`.)

### [IMPORTANT] The out-of-order skip is invisible on the exact channel the deploy note tells the operator to watch
`…2026_08_26_100100…:78-82` logs a `Log::warning` and returns; the success path at `:95`/`:109` uses
`echo`. The report's own deploy instruction (concern 1) is "watch the per-tenant migrate log for the
`[W4-1] …` census lines — their absence means the guard skipped". That is not diagnostic: the census is
ALSO silent on the `$census === 0` path (`:89-93`). Answering the gate question directly — a silent skip
is NOT acceptable on a fleet auto-migrate (`origin/dev` → staging `tenants:migrate`), because the failure
mode is "FEFO keeps ranking on fiction on that tenant, forever, with no signal". It does not have to
`throw` — the two files ship in the same commit and filename order guarantees sequence on a normal
`tenants:migrate` — but the skip branch MUST `echo` the same way the write branch does, and the 0-census
path should echo `found: 0` so absence of output means "the migration did not run at all".

### [IMPORTANT] Client/server FEFO tie-break diverge once more than one lot ranks equal
`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:93-101` — `compareByFefo()`
returns `0` for two undated lots (and for two equal dates) and relies on JS sort stability, but its input
comes from `BatchRepository::getByProduct()` (`apps/api/app/…/BatchRepository.php:71`) which has NO
deterministic tie-break, while the server's canonical split does (`StockTransferService.php:859-860`,
`->orderBy('id')`). `assertAllocationsFollowFefo()` (`:934-946`) compares the per-batch QUANTITIES, so on
a partial draw across two equal-rank lots the client can build a split the server refuses with "Batch
allocations must follow FEFO" and the operator has no way to satisfy it. The equal-date case is
pre-existing; this lane makes it newly reachable for undated lots, which on the launch tenant is the
common shape. Fix: add `->orderBy('id')` to both `BatchRepository` orderings and an `id` tie-break to
`compareByFefo()`.

### [MINOR] `expiryDateIsNullable()` reads `information_schema` without a schema filter
`…2026_08_26_100100…:997-1005` region (`SELECT is_nullable FROM information_schema.columns WHERE
table_name = 'product_batches' AND column_name = 'expiry_date'`) — `selectOne` takes an arbitrary row if
the tenant database ever holds a same-named table in a second schema. Add `AND table_schema = current_schema()`.

### [MINOR] `down()` of the schema migration is unguarded
`…2026_08_26_100000…:51-58` queries both tables with no `Schema::hasTable`, unlike the backfill's guard
at `:71`. A rollback on a partially-migrated tenant throws a raw SQLSTATE rather than the intended
counted refusal at `:60`.

### [MINOR] Stale comment now contradicts the shipped predicate
`apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:343` still documents the FEFO
candidate predicate as "`expiry_date >= today`". It is now `expiry_date IS NULL OR expiry_date >= today`
(`FEFOInventoryService.php:270`). That docblock is the load-bearing statement of the DN refusal policy;
leaving it stale is how the next reader re-derives the wrong rule.

### [MINOR] Backfill resets `is_expired` — behaviour note for future tenants
`…2026_08_26_100100…:100` sets `is_expired => false` on every nulled lot. No lot can currently hold a
past invented date (`product_batches` was created 2026-01-05; +365 has not elapsed), so this is inert
today — but on any tenant provisioned later it will resurrect previously-expired DEFAULT lots as
sellable stock. Correct in principle; worth stating in the deploy note rather than discovering.

### [MINOR] Editing an undated lot forces the operator to invent a date
`apps/web/src/features/batches/components/BatchForm.tsx:14` (`z.string().min(1)`) plus
`UpdateBatchRequest.php:22` (`'sometimes','date','after:today'`) — any edit to an undated lot (even
notes) requires supplying a future expiry. Combined with the second finding above, the batch edit screen
is the ONLY route to attach a real expiry to an existing undated DEFAULT lot. The report flags this
(concern 4); recording it here because the two interact.

---

## Test quality

Real `RefreshDatabase` + real models throughout; no mocks of the units under test; no `assertTrue(true)`.
`OpeningLotExpiryW41Test.php:216-244` is genuinely falsifiable — the undated lot is created FIRST so
SQLite's NULLS-FIRST default would fail it, and `:267-285` pins the `created_at` tie-break.
`NullInventedDefaultLotExpiryMigrationTest.php:61-95` carries the weight in the negatives
(configured-365, operator edit, real lot number, missing `manufacturing_date`).
`ProductsImportPipelineTest` additions drive the real HTTP import pipeline end-to-end, including the
422 refusal naming the offending column. `EnsureDefaultBatchTest.php:201-210` now asserts the constant
ABSENT on both classes — a real anti-regression guard.
Gaps: the atomic-consume path (finding 1) and the PG branch of the backfill predicate.
