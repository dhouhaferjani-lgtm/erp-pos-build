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

---

## r2 scoped re-review

Fix range `f60268868..fba317686` (5 commits). READ-ONLY, re-verified against the tree at
`fba317686` in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w4-1-opening-lot-expiry/`.

### VERDICT: CHANGES-REQUESTED — 2 open (1 of them a false verification claim)

### r1 findings → disposition

| r1 finding | disposition | evidence |
|---|---|---|
| **A** — no NULL-expiry coverage of `consumeBatchesAtomically()` **(tests half)** | **ADDRESSED** | `apps/api/tests/Feature/BatchExpiry/AtomicFEFOConsumptionTest.php:289-400` — 4 real cases through the real `$this->service->consumeBatchesAtomically(...)`; helper `:256,270` now mints `expiry_date => null`. Class is PG-only (`:57-58` `markTestSkipped`) and IS in the CI `--filter` allowlist, so these 4 DO run in CI. |
| **A** — CI allowlist **(gating half)** | ❌ **NOT ADDRESSED** — and falsely reported as done | see OPEN-1 |
| **B** — supplied expiry silently dropped on an existing DEFAULT lot | **ADDRESSED (import path); PARTIAL (wizard path)** | `BatchStockService.php:110-131` set-once fill; `:271-289` remainder-zero fill via new `findDefaultBatch()` `:305-322`; `OpeningLotExpiryOutcome` enum; `ProductOpeningStockPhase.php:122-127,152-198` row warnings under their own `opening_lot_expiry` key. Wizard half still silent → OPEN-2. |
| **C** — backfill keys on the product's CURRENT shelf life | **ADDRESSED** | `2026_08_26_100100…:145-183` `reportResidual()` — non-mutating, lists SKU + shelf-life, shares `expiryMatchesFallbackSql()` `:217-224` with the mutating census so the two cannot drift. Pinned `NullInventedDefaultLotExpiryMigrationTest.php:174-194`. |
| **D** — silent skip / silent zero census | **ADDRESSED** | `…100100…:86-93` skip now `emit()`s as well as `Log::warning`; `:100-103` census echoes at 0. Zero-census pinned `…MigrationTest.php:202-214`. (Skip branch itself is not pinned — see MINOR-2.) |
| **E** — client/server FEFO tie-break diverge | **ADDRESSED** | `BatchRepository.php:81` and `:118` `->orderBy('id')`; `StockTransferService.php:860` already had it; `CreateStockTransferPage.tsx:103-118` `compareByFefo` ends `return a.id - b.id`. All three now rank `(expiry nulls-last, then id)`. |
| MINOR — `information_schema` unscoped | **ADDRESSED** | `…100100…:274-279` `table_schema = current_schema()`. |
| MINOR — schema `down()` unguarded | **ADDRESSED** | `2026_08_26_100000…:51-56` `Schema::hasTable` on both tables. |
| MINOR — stale `DeliveryNoteService` comment | **ADDRESSED** | `DeliveryNoteService.php:343-352` now states `expiry_date IS NULL OR expiry_date >= today`. (Comment-only change; the file's deptrac violations are pre-existing, at `:56`.) |
| MINOR — `is_expired` reset | **ADDRESSED (documented)**; ruling below | `…100100…:109-116`. |
| MINOR — editing an undated lot forces a date | **ADDRESSED** | `BatchForm.tsx:11-42,55-58,94-99` blank allowed only when `batch.expiry_date === null`, submitted as an OMITTED key (never `''`); `CreateBatchPage.tsx:16-25` keeps hand-mint required. `common:optional` exists in en/fr/ar. |

### Answers to the four gate questions

1. **Do the 4 PG cases really exercise `consumeBatchesAtomically()` with NULL lots?** **Yes.** All four call the
   real service method (no mock), on the real PG-only `FOR UPDATE … SKIP LOCKED` query, with
   `expiry_date = null` rows. `test_dated_lots_are_drawn_before_the_undated_one` (`:305-341`) creates the undated
   lot FIRST and asserts the exact draw order `[early, late, undated]` plus a `5.0000` remainder in the undated
   lot — falsifiable, not insertion-order-lucky. `test_an_expired_lot_stays_invisible_while_the_undated_one_does_not`
   (`:343-364`) is the one that matters most: it proves admitting NULL did not also admit a passed expiry.

2. **Manifest ceiling bump — precedent + checker?** **Precedent: yes. Checker: passes. Note text: FALSE.**
   `feature-lane-manifest.json` Import 17→18, Inventory 116→118, `gated_ceiling` 1187→1190 — arithmetic consistent
   (+1/+2 = +3), and the `DELIBERATE RAISE X -> Y (date, lane, reason)` shape matches the W2-7/Q-2/W4-6/P-1
   precedents in the same note. `php tools/feature-lane-manifest-check.php` → **OK**, exit 0. But the Inventory
   note asserts the two classes are *"Named in the backend-test-pgsql `--filter` allowlist"* — they are not
   (OPEN-1). The checker cannot catch this: it validates that declared lanes exist in `ci.yml` and that existing
   `--filter` entries are anchored, not that a free-text note is truthful.

3. **`is_expired` reset (concern 2/4) — acceptable, or must the backfill preserve it?** **Reset is correct and
   must NOT be changed to preserve.** Two code facts decide it:
   - `BatchExpiryDailyCheckCommand.php:128,134` only ever flips `false → true` and never back. An `is_expired = true`
     left on a lot whose `expiry_date` is now NULL is **permanently stuck** — no scheduled job, and no UI path,
     can clear it.
   - The flag is read as an independent gate in at least one place — `StockAdjustmentDocumentService.php:636`
     (`$batch->is_expired || is_recalled || ! is_active`) — while every FEFO SQL predicate
     (`FEFOInventoryService.php:270`, `:106-110`) and `Batch::isExpired()` (`Batch.php:80-83`) key on
     `expiry_date`. Preserving the flag therefore creates a split brain: FEFO happily consumes a lot the
     adjustment/write-off path still treats as expired.
   Semantically the quarantine was itself *derived from the fiction this migration deletes* — preserving it would
   keep the fiction's effect after removing its cause. The lot's expiry is UNKNOWN, not passed, and an unknown
   expiry is sellable by design everywhere else in this lane. **No change required** — see MINOR-3 for the one
   cheap improvement.

4. **deptrac 182 vs 183 — what changed?** **Verified 182 at `fba317686`** (`php vendor/bin/deptrac analyse` →
   Violations 182, Skipped 0, Errors 0). The −1 is **not** from the fix round: it is
   `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` losing the line
   `$shelfLifeDays = $product->default_shelf_life_days ?? BatchStockService::DEFAULT_SHELF_LIFE_DAYS;`
   in the r1 range — an `Inventory\Domain → BatchExpiry\Application` (ModuleDomain-on-ModuleApplication)
   occurrence that deptrac counted per line. The class-level dependency survives via
   `ensureDefaultBatchForUntrackedRemainder`, so the drop is exactly one. Cross-checked the JSON report: of the
   28 files this lane touches, only `DeliveryNoteService.php` carries any violation at all, and its two edges are
   at `:56` (`StockReservationService`, `WeightedAverageCostService`) — untouched lines, pre-existing, and this
   round's change to that file is comment-only. **No new violations; the −1 is accounted for.**

### OPEN

#### [IMPORTANT] OPEN-1 — the CI allowlist change does not exist in the tree, and both the report and a committed manifest note assert that it does
`git diff 9d0d08ae5..fba317686 -- .github/` is **empty** across the whole lane; `grep -rn` for
`OpeningLotExpiryW41Test|NullInventedDefaultLotExpiryMigrationTest|SpreadsheetParserDateCellTest` over every
`*.yml`/`*.yaml` in the repo returns **nothing**. `.github/workflows/ci.yml:1048` is byte-identical to `dev`.
So the r1 gating half stands unfixed: the backfill's **PostgreSQL** predicate branch
(`…2026_08_26_100100…:222`, `expiry_date = manufacturing_date + INTERVAL '365 days'`) — the one that executes on
every real tenant `tenants:migrate` — runs in **no CI job at all**, while SQLite runs the different string at
`:223`. `NullInventedDefaultLotExpiryMigrationTest` has no driver skip, but its group is PARKED, so nothing selects it.
What makes this Important rather than Minor is the second half: the report §"inventory [IMPORTANT] A" states the
allowlist "now names" all three classes, and `apps/api/tests/feature-lane-manifest.json:830` ships that claim as a
permanent repo artifact ("Named in the backend-test-pgsql --filter allowlist for the same reason as the W2-7 lot-provenance pins").
A future reader will trust the note and not re-check. **Fix:** either add the three class names to the
`backend-test-pgsql --filter` at `.github/workflows/ci.yml:1048`, or correct the report AND the manifest note to
say plainly that they run nowhere until the parked gate is flipped. Do not merge with the note as written.
(The 4 new atomic-consume cases are unaffected — `AtomicFEFOConsumptionTest` is already in the allowlist.)

#### [IMPORTANT] OPEN-2 — on the opening **wizard** path the conflict outcome is computed and then thrown away, so the preview still asserts something the post will not do
`OpeningBalancePostingService` now returns `expiryOutcomesInInputOrder`
(`OpeningBalancePostingResult.php:19-22`), and `ProductOpeningStockPhase.php:122-127` consumes it. The wizard does
not: `InventoryOpeningService.php:313-330` zips `movementIdsInInputOrder` into `$rowEntityMap` and then
`return $result->entry;` — the outcomes are discarded. Meanwhile `getPostPreview()` (`:398`) still shows the
operator the date they supplied. Set-once closed the common case (row 1 undated + row 2 dated now fills), but the
disagreeing case is exactly the multi-location shape r1 named: two wizard rows for the same SKU at `MAIN` and
`WAREHOUSE` with different dates → the lot keeps row 1's date, row 2's date is dropped, the preview promised it,
and nothing anywhere says otherwise. The report's concern 4 acknowledges only `expiry_in_past` as import-only; it
does not acknowledge that `ConflictExistingLot` / `IgnoredNotBatchTracked` are import-only too. **Fix:** the zip
loop at `:315-319` already walks `$lineRows` by the same index — stash the non-`NotSupplied`/`Applied` outcome on
the row (or return it alongside the entry) so the post response can name it. A full row-warnings channel is a
separate lane; surfacing the two codes that already exist is not.

#### [MINOR] MINOR-1 — the residual line under-reports its own count
`…2026_08_26_100100…:171-179` formats `%d DEFAULT lot(s) …` with `$listed->count()`, which is capped at
`RESIDUAL_LIST_LIMIT` (25). With 300 residual lots the operator reads "25 DEFAULT lot(s)" plus a trailing `…`.
Report the true count (a `count()` query, or `RESIDUAL_LIST_LIMIT + 1` → "25+") and keep the truncated name list.

#### [MINOR] MINOR-2 — the loud-skip branch is not pinned
`…2026_08_26_100100…:86-93` is the branch whose silence r1 flagged, and the report says "Both pinned".
`NullInventedDefaultLotExpiryMigrationTest` pins only the zero census (`:202-214`) and the residual (`:188`); there is no
case asserting `[W4-1] SKIPPED:` reaches stdout. It is awkward under `RefreshDatabase` (the column is already
nullable), but a driver-guarded case that re-tightens the column, runs `up()`, and asserts the string is cheap.
Either add it or soften the report's claim.

#### [MINOR] MINOR-3 — the `is_expired` resurrection is not censused
`…2026_08_26_100100…:116` flips `is_expired => false` for the whole matched set with no separate count. Per Q3
above the reset is right, but on a later-provisioned tenant the resurrected subset is precisely the stock an
operator should physically verify before it goes back on the shelf. Count the matched ids that had
`is_expired = true` before the update and emit `[W4-1] … previously flagged expired: N`. One extra `count()`.

#### [MINOR] MINOR-4 — `IgnoredNotBatchTracked` is emitted for a product that IS batch-tracked
`OpeningBalancePostingService.php:364-366` — inside the `requires_batch_tracking` branch, `$lotAfter === null`
(real lots already covered the whole remainder, so nothing was minted) returns
`OpeningLotExpiryOutcome::IgnoredNotBatchTracked`, and `ProductOpeningStockPhase.php:183-189` renders it as
*"this product is not batch-tracked"*. That statement is false for that row. Reachable when a goods receipt with
real lots precedes the opening post for the same tuple — rare, but the lane's whole thesis is that operator-facing
facts must be true. Add a distinct `IgnoredNoLot` case with its own wording.

### Precision (rule 19) — clean
No float touches money or quantity anywhere in the fix diff. `BatchStockService.php:277` uses
`bccomp($remainder,'0',4)` with the `precision-ok` marker. The XLSX date read
(`SpreadsheetParserService.php:214-262`) is deliberately narrow — `isDateCell()` requires BOTH a numeric stored
value AND `ExcelDate::isDateTime($cell)` — so money/quantity cells never go through Excel's display format; that
negative is the correct call and is pinned in `SpreadsheetParserDateCellTest`. `compareByFefo`'s `a.id - b.id`
(`CreateStockTransferPage.tsx:117`) is an integer lot id, not a decimal.

### What to fix before merge
Land (or truthfully retract) the `ci.yml --filter` entry that the report and `feature-lane-manifest.json:830`
already claim, and surface the wizard-path expiry conflict instead of discarding
`expiryOutcomesInInputOrder` at `InventoryOpeningService.php:330`.
