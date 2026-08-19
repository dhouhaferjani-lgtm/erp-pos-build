## M5 adversarial re-review — round 2 — inventory-costing lens

**Milestone:** M5 (FINAL). **Delta reviewed:** `264cd6bad..7542e8eb9` — `a9ebf091c` (substantive),
`7a9d474db` + `3da145110` + `7542e8eb9` (ledger / evidence citations).
**Scope of this round:** the fix-round response to the round-1 inventory-costing register's two
blocking P2s ONLY (`M5-round1-inventory-costing.md`). The treasury and fiscal-pos lenses ACCEPTed at
round 1 and are not relitigated here.
**Base register:** `M5-round1-inventory-costing.md` (CHANGES-REQUIRED).
**Working tree:** clean before and after. One temporary probe class (`ZzDeProbeR2Test`) was created,
run and DELETED; one throwaway PostgreSQL database (`autoerp_r2_invcost`) was created and DROPPED; a
gitignored `apps/api/.env` was created and REMOVED. I staged and committed nothing but this file.
Tip at commit time: `7542e8eb9`.

**Methodology note.** Every line number below was re-derived from the tree at `7542e8eb9` by reading
the file — none is copied from the executor's commit message, the evidence, or the ticket. That
matters here: the executor disclosed that its first evidence pass carried off-by-one citations, and
I derived `:243` / `:251` / `:258-274` independently BEFORE reading `3da145110`.

---

## Scope containment — VERIFIED

`git diff --stat 264cd6bad..7542e8eb9` is exactly five files and nothing else:

```
 .../Console/CheckCogsCoverageCommand.php           | 17 ++++-
 .../Accounting/CheckCogsCoverageCommandTest.php    | 72 ++++++++++++++++++++-
 docs/handoff/progress/wave3-3c-3d.progress.yaml    | 27 +++++++-
 docs/handoff/reviews/wave3-3c-3d/M5-evidence.md    | 64 ++++++++++++++-----
 ...6-08-19-replay-finalize-test-not-pg-runnable.md | 74 +++++++++++++++-------
```

The only production change is the D-e query block. `apps/api/config/database.php` is **untouched**
(`git diff --name-only 264cd6bad..7542e8eb9 -- apps/api/config/database.php` → 0 files; `grep -n
timezone apps/api/config/database.php` → no match), exactly as the ledger states. No float/`round(`/
`number_format` enters the delta (`git diff … -- 'apps/api/**' | grep '^+' | grep -E
'\(float\)|floatval|number_format|parseFloat|round\('` → empty). Rule 19 is not engaged: the change
is a SQL predicate, not arithmetic.

**Consequence:** the eight P3s from round 1 (findings 3–10) carry forward **unchanged and OPEN** by
construction — the delta touches none of `ApplyStockAdjustmentsOnCountingCompleted.php`,
`InventoryVarianceAccountProvisioner.php`, `CountCorrectionGlPostingTest.php`,
`InventoryGlPostingService.php` or `dpa-inventory-shrinkage-deploy-checklist.md`. The ledger
(`wave3-3c-3d.progress.yaml:185`) correctly declares them out of scope for this round.

---

## P2·1 — the D-e flat/historical predicates — **CLOSED**

### The predicates are where they are claimed to be

`apps/api/app/Modules/Accounting/Presentation/Console/CheckCogsCoverageCommand.php`, re-derived:

- `:243` — `->where('is_historical', false)`
- `:251` — `->whereColumn('quantity_before', '<>', 'quantity_after')`
- `:240-242` and `:244-250` — the two justifying comments
- `:265-267` — the corrected tail of the flag-tied comment: *"reports the count corrections that
  SHOULD have posted and did not — the by-design declines (flat, historical) are filtered above."*
  The round-1 falsehood (*"reports exactly the failed or declined count-correction postings"*) is
  gone. The flag-tied `when()` block itself spans `:269-274`.

### Every citation the fix rests on — verified

| claim | where I verified it | verdict |
|---|---|---|
| "Every posting arm declines a historical movement" at `InventoryGlPostingService.php:38`, `:91`, `:133` | `:38` `postForCountCorrection` → `if ($ctx->isHistorical \|\| ! $ctx->reason->requiresGLEntry())`; `:91` `postForBatchWriteOff` → `if ($ctx->isHistorical)`; `:133` `postMovement` → `if ($ctx->isHistorical \|\| $counterFamily === …Neither)` | **TRUE, and complete.** `grep -n "public function"` on that file returns exactly four posting arms — `postForExit:26`, `postForEntry:31`, `postForCountCorrection:36`, `postForBatchWriteOff:89` — and `:26`/`:31` both delegate to the private `postMovement:130`. `grep -n isHistorical` returns exactly `:38`, `:91`, `:133`. There is no fourth, undeclining arm. |
| flat decline at `InventoryGlPostingService.php:43-46` | `:43` `$direction = $movement->directionForRow();` `:44` `if ($direction === 'flat') {` `:45` `return null;` `:46` `}` | **TRUE** |
| D-a / D-b already carry the historical filter at `:202` / `:221` | read both | **TRUE** — parity argument holds |
| `quantity_before` / `quantity_after` NOT NULL, so `<>` is total | `database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:39-40` — `$table->decimal('quantity_before', 15, 2);` / `…('quantity_after', 15, 2);`, neither `->nullable()`; widened to scale 4 by `2026_05_29_120000_widen_inventory_quantity_columns_to_scale_4.php:39-42` | **TRUE.** The NULL edge the brief asked about is closed by schema: NOT NULL with no DB default means every insert path must supply both, so `whereColumn`'s three-valued-logic hole is unreachable. |
| `is_historical` NOT NULL default false with a `(company_id, is_historical)` index | `2025_12_11_100001_add_is_historical_to_tables.php:22-27` (the `stock_movements` block); no later migration makes it nullable (`grep -rn is_historical database/migrations/` → only `occurred_at`/`reverses_movement_id` positioning refs) | **TRUE** |

### Reproduced — the genuine arm still fires, and ONLY it

Round 1's finding was *drowning*: 870 false D-e findings on a 900-line count. The delivered test
(`CheckCogsCoverageCommandTest.php:404-450`, docblock `:389-403`) asserts exit 0 with flat+historical
present, then exit 1 with a genuine correction added — but its `Log::shouldHaveReceived(...)
->withArgs(...)` matcher only proves *at least one* D-e names the genuine movement. It does not prove
the flat rows are silent **in the same run**. I closed that gap myself with an independent probe (a
copy of the class, since deleted), 5 flat + 2 historical + 1 genuine, all in one scan, on PostgreSQL:

```
PROBE D-e ids: ["01a01b4c-0a63-70b7-9510-017f8742c9a9"]
PROBE genuine id: 01a01b4c-0a63-70b7-9510-017f8742c9a9
OK (2 tests, 3 assertions)
```

`assertSame([$real->id], $ids)` — D-e reports **exactly one** finding, the genuine one. The round-1
defect is closed and the detector's reason for existing survives.

### Adversarial: can a real correction now hide? — NO

1. **A correction where `before == after` but a COST changed.** The only production writer that
   deliberately emits `quantity_before === quantity_after` is the landed-cost / additional-cost
   revaluation at `WeightedAverageCostService.php:775-776` (`'quantity_before' => $totalOwned,
   'quantity_after' => $totalOwned`, `'quantity' => '0'`). That row sets **no `reason`** — `:783` is
   `'notes' => $reason` (free text), and the enum column is `string(50)->nullable()` with no default
   (`2025_12_24_133827_extend_stock_movements_table.php:16`; `StockMovement.php:30` declares
   `MovementReason|null`). A NULL `reason` fails `whereIn('reason', $nonCogsGlReasons)` at `:252`, so
   this movement was **never** in D-e's population, before or after the fix. No coverage was lost.
   I paired every `'quantity_before' =>` / `'quantity_after' =>` assignment in `app/` (18 sites):
   `WeightedAverageCostService.php:775` is the **only** one where the two sides are the same
   expression. Every other writer uses distinct before/after variables, so a flat row there requires a
   genuinely zero delta — the by-design decline.
2. **A NULL-quantity edge.** Closed by schema (table above). The executor's migration citation is
   correct, not a memory claim.
3. **An `is_historical = true` row that SHOULD have posted.** Would require a GL writer that emits one
   of `InventoryGlSourceTypes::ALL` without declining historical. There is none: `inventory_entry` /
   `inventory_exit` are written only at `InventoryGlPostingService.php:192`, `inventory_shrinkage`
   only at `:76`; `batch_write_off` / `batch_write_off_reversal` are written by
   `GeneralLedgerService.php:4840` / `:4960`, both reached only through `postForBatchWriteOff` which
   declines at `:91`. Historical movements are unreachable for all five source types, so filtering
   them out of D-e cannot hide a miss — and D-a `:202` / D-b `:221` set the precedent anyway.
4. **A PHP/SQL scale divergence.** `directionForRow()` (`StockMovement.php:183-192`) compares with
   `bccomp(..., QuantityScale::SCALE)` = scale 4, while the new predicate compares in SQL at full
   column precision. I probed the boundary on PostgreSQL: writing `10.00005` / `10.00000` stores
   `before=10.0001 after=10.0000` (decimal(15,4) at rest), `directionForRow=out`, `sql before<>after =
   true`. **The two agree**, because both read the same scale-4 stored values — a sub-scale delta
   cannot exist at rest. No gap.
5. **A D-e population member whose reasons don't maintain before/after.** `$nonCogsGlReasons`
   (`:178-188`) resolves to exactly `{GoodsReceipt, SupplierReturn, AdjustmentPositive,
   AdjustmentNegative, CountCorrection}` — `requiresGLEntry()` true (`MovementReason.php:96-113`)
   minus the `Cogs` and `Shrinkage` families (`:73-94`). All five are written by paths with real
   before/after pairs (`WeightedAverageCostService.php:271,434,587`,
   `SupplierCreditNotePostingService.php:676`, `StockAdjustmentService.php:1817`). Note the
   `Damage`/`Expiry`/`WriteOff` batch-write-off family — whose posting arm has **no** flat guard — is
   in `costedExitReasons`, i.e. in D-a/D-b, **not** in D-e, so the new flat predicate cannot silence
   it. D-a/D-b are unchanged in this delta.

### Test quality

Red-first is credible and the helper change is non-perturbing: `movement()` (`:707-740`) gains three
optional parameters whose defaults are the previous hardcoded expressions verbatim
(`git show a9ebf091c` — `'quantity_before' => $reason->getMovementType() === 'in' ? '0.0000' :
'2.0000'` becomes `$quantityBefore ?? (…same…)`), so no existing case changes shape. The test asserts
real behaviour on real models under `RefreshDatabase`, mocks nothing under test, and is driver-portable.

---

## P2·2 — the replay-window root cause — **CLOSED**

### The evidence

`M5-evidence.md` §1.7 (`:133`) and §6.1 (`:338`) are both rewritten to the PGTZ truth. §6.1 now
states plainly *"There is **no product defect in `MovementReplayService`**, and the file's exclusion
from M5's PG lane is owed to the 21-character fixture **alone**"*, carries the `PGTZ=UTC TZ=UTC …
OK (11 tests, 36 assertions)` result, and names `config/database.php`'s missing `timezone` key as the
mechanism with the matching arithmetic (b: 20 − 5 − 3 = 12; c: 20 − 1 = 19). §1.7 carries an explicit
**AMENDED** banner that quotes and retracts the original false claim rather than silently editing it.

### The ticket

`docs/superpowers/tickets/2026-08-19-replay-finalize-test-not-pg-runnable.md` — all five corrections
present:

- **Title** — *"…and hides three PG-only divergences"* removed.
- **Banner** — a `> **CORRECTED at M5 round 1**` block naming both registers and both errors.
- **14 → 11** — corrected, with the mechanism kept ("wrong count, correct mechanism").
- **Finding 2 rewritten** — heading now *"(CORRECTED) — the three 'divergences' are an unpinned pgsql
  session timezone, not a replay defect"*, with the disproving experiment inline.
- **Acceptance re-pointed** — old item 2 (*"Root-cause the (b) and (c) divergences … Do not 'fix' the
  test to match PG if PG is the one that is wrong"*) replaced by *"Confirm the measured PGTZ result
  above rather than hunting a replay-window product defect … the SQLite expectations are the correct
  semantics"*; new item 4 splits the `timezone` pin out as a separate lane.

### Its factual claims — re-measured by me, not taken on trust

| ticket / evidence claim | my measurement at `7542e8eb9` |
|---|---|
| `ReplayFinalizeTest.php:157` builds a 21-char `'CNT-RPL-'.uniqid()` | line `:157` is `'counting_number' => 'CNT-RPL-'.uniqid(),`; 8 + 13 = 21 — **confirmed** |
| against `varchar(20)` | `2026_03_04_100000_add_tenant_id_and_counting_number_to_inventory_countings.php:15` — `$table->string('counting_number', 20)` — **confirmed** |
| "**11** cases error before asserting anything … measured `Tests: 11, Assertions: 0, Errors: 11`" | I ran the file on PostgreSQL: `ERRORS! Tests: 11, Assertions: 0, Errors: 11.` — **byte-confirmed**; `grep -c 'public function test_'` → 11 |
| `config/database.php:87-98` pins `charset` and `search_path` but no `timezone` | `:87` is `'pgsql' => [`; `charset` at `:95`, `search_path` at `:98`; `grep -n timezone` → no match — **confirmed** |

### The disclosed off-by-one — independently re-derived

`3da145110` corrects §1.7 from `:242`/`:250`/`:257-274` to `:243`/`:251`/`:258-274`. I derived
`:243`, `:251` and `:269-274` (the `when()` call; `:258-274` including its leading comment) from the
file before reading that commit. **The corrected citations are the right ones.** Correcting a stale
citation in its own commit rather than carrying it silently is the right instinct and I record it as
such.

---

## Verification I ran myself

| command | result |
|---|---|
| `phpunit -c phpunit-pgsql.xml tests/Feature/Accounting/CheckCogsCoverageCommandTest.php` | **OK (27 tests, 59 assertions)** — matches the claim |
| `phpunit tests/Feature/Accounting/CheckCogsCoverageCommandTest.php` (default/SQLite) | **OK (27 tests, 59 assertions)** — matches the claim |
| `phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/CountCorrectionGlPostingTest.php` | **OK (7 tests, 42 assertions)** — unchanged from round 1, as required |
| `phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/ReplayFinalizeTest.php` | `Tests: 11, Assertions: 0, Errors: 11` — the ticket's corrected count, measured |
| **PROBE** — 5 flat + 2 historical + 1 genuine in one scan, PG | D-e reports **exactly** the genuine id (`assertSame`) |
| **PROBE** — sub-scale `10.00005` vs `10.00000`, PG | stored `10.0001`/`10.0000`; `directionForRow=out`; `sql before<>after=true` — PHP and SQL agree |
| `pint --test` on both changed PHP files | `{"result":"pass"}` |
| `phpstan analyse -c phpstan.neon` on the changed command (level 8) | `[OK] No errors` |
| `phpunit` §4.1 four-file SQLite combo | **66 tests, 155 assertions, 7 skipped** (evidence says 65/150 — finding 1) |
| `phpunit -c phpunit-pgsql.xml` §4.2 three-file PG combo | **OK (55 tests, 161 assertions)** (evidence says 54/156 — finding 1) |
| `git status --porcelain` after the review | empty |

**Driver note.** Every green above is real PostgreSQL 16 (`phpunit-pgsql.xml`, throwaway database
`autoerp_r2_invcost`, created for this review and dropped afterwards) except the two explicitly
labelled default/SQLite. My local PostgreSQL session is `CET+0100` — the unfavourable zone that
surfaced round-1 finding 2 — so none of these greens is a timezone artifact. I did **not** run the
full suite.

## Bypasses I tried that FAILED (the code held)

1. **A real correction hiding behind the flat predicate** — closed five ways above; the only
   structurally-flat writer carries a NULL `reason` and was never in the population.
2. **A real correction hiding behind the historical predicate** — all five inventory GL source types
   are unreachable for a historical movement; there is no undeclining fourth arm.
3. **A `whereColumn` NULL hole** — closed by NOT NULL at the migration, verified in the file.
4. **A PHP/SQL rounding-scale gap between `directionForRow()` and the SQL `<>`** — probed at the
   scale-4 boundary; they agree by construction.
5. **The new test passing vacuously** — I re-proved the exclusion with an independent multi-row probe
   the delivered test does not itself assert (see P2·1); it holds.
6. **The delta smuggling scope** — `git diff --stat` is exactly the five expected files;
   `config/database.php` untouched; no float; no seeder; no workflow.

---

## Register

### 1 — P3 · CONFIRMED (measured three ways) · §4 "Verification — exact counts" was not reconciled with the fix round's own new test, so the evidence now understates its verified coverage

`M5-evidence.md:232` (`## 4. Verification — exact counts`), `:242`, `:255`, `:271`

The fix round added one test case and correctly recorded the new counts in `a9ebf091c`'s commit
message and in §1.7 — but §4's three count claims were left at their pre-fix values. Measured at
`7542e8eb9`:

| evidence line | claims | I measured |
|---|---|---|
| `:242` (§4.1, four-file SQLite) | `Tests: 65, Assertions: 150, Skipped: 7` | **66, 155, 7** |
| `:255` (§4.2, three-file PG) | `OK (54 tests, 156 assertions)` | **OK (55 tests, 161 assertions)** |
| `:271` (§4.3 table row, PG) | `OK (26 tests, 54 assertions)` | **OK (27 tests, 59 assertions)** |

The delta is uniform (+1 test, +5 assertions) and entirely attributable to
`test_de_excludes_flat_and_historical_count_corrections_once_the_flag_is_live`. Nothing is wrong at
rest and no code claim is affected — but a promoter reading §4.3, which is the section titled *exact
counts*, would conclude the fix round's test does not exist, and this is the same class of
record-accuracy defect P2·2 was raised about. **Free to close:** re-run the three commands and paste
the three numbers.

### 2 — P3 · CONFIRMED · the corrected root cause's corrective action is assigned to a "repo-wide lane the parent has ledgered separately" that cannot be shown to exist

`wave3-3c-3d.progress.yaml:184-185` (*"`config/database.php` deliberately untouched — the timezone
pin is the parent's repo-wide lane"*), `2026-08-19-replay-finalize-test-not-pg-runnable.md`
acceptance item 4 (*"tracked as its own repo-wide lane, not from this ticket's inventory scope"*),
`M5-evidence.md` §6.1 (*"a repo-wide lane the parent has ledgered separately"*)

Deferring the `'timezone' => 'UTC'` pin out of this wave is the right call — I said so in round 1 and
I do not relitigate it. But the deferral names no artifact. The ledger's `blockers:` list
(`wave3-3c-3d.progress.yaml:205-209`) carries three entries — OQ-12-H-5, S-16, the deptrac ratchet —
and **none** mentions the timezone pin. No ticket file exists for it
(`ls docs/superpowers/tickets/ | grep 2026-08-19` returns five files, none about timezone;
`grep -rln timezone docs/superpowers/tickets/` returns four files, of which the only 2026-08-19 one is
the replay ticket itself and the nearest neighbour is the unrelated
`2026-08-10-deferredtenderguards-tz-coupling.md`). The net effect of the round-1 fix is therefore that
a **false** root cause was replaced by a **true** one whose remedy is homeless: the replay ticket now
instructs its owner *not* to hunt the defect, and points at a lane with no ledger entry, no ticket and
no owner. The cheapest close is one line in `blockers:` or a three-line ticket. Non-blocking because
the record is now *true*, which is what P2·2 demanded, and the pin affects only local/CI session
behaviour, never data at rest.

### Carried from round 1 — all eight P3s remain OPEN, unchanged

Findings 3–10 of `M5-round1-inventory-costing.md` (advisory-lock comment; the truncating
`assertEntryAmountEqualsRowCostTimesAbsoluteDelta` oracle; `entryDate: 'now'` on a queued listener;
the counting-vs-movement `companyId`/`currencyCode` asymmetry; `requirePerpetual` hard-throwing where
POS warns-and-skips; the `fallbackTemplateParent` activity/code-tree residual; the deploy checklist
never naming `INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED`; and T22's DEFAULT-lot inflation carried
by ruling). None of their files is in this delta, so all eight stand verbatim. The ledger declares
them out of scope for this round, which is a legitimate disposition for P3s at a final milestone —
recorded here so they are not lost at promotion.

### Observation (not a finding) — `a9ebf091c`'s commit message is now the only surface carrying the pre-correction citations

It cites the predicates at `:242`/`:250`. `3da145110` fixed the evidence; a commit message is
immutable and the error is disclosed in `3da145110`'s own message, so there is nothing to fix. Noted
only so a future auditor reading `git log` rather than the evidence is not misled.

---

**Gate disposition.** Both blocking P2s are genuinely closed, and I closed them by measurement rather
than by reading the response. **P2·1** is fixed at the right layer with the right predicate: I
re-derived `:243` and `:251` from the file, verified every supporting citation including the claim
that *all four* posting arms decline historical (they do — there is no fourth arm), confirmed the
NOT-NULL schema that makes `whereColumn` total, and then attacked the fix from five directions to see
whether a genuine correction can now hide. It cannot: the only structurally-flat production writer
(`WeightedAverageCostService.php:775-776`, the landed-cost revaluation) carries a NULL `reason` and
was never in D-e's population; the batch-write-off family whose posting arm lacks a flat guard sits in
D-a/D-b, not D-e; and `directionForRow()`'s scale-4 `bccomp` and the SQL `<>` provably agree because
both read the same scale-4 stored values. The delivered test is red-first and non-perturbing, but its
`shouldHaveReceived` matcher alone would not have proven the exclusion, so I proved it myself with a
5-flat + 2-historical + 1-genuine probe: D-e emits exactly one finding, the genuine one. 27/59 on both
drivers, `CountCorrectionGlPostingTest` still 7/42 on PG, pint pass, PHPStan level 8 clean.

**P2·2** is closed at the record layer, which is where it lived. §1.7 and §6.1 both carry explicit
retraction banners that quote the false claim rather than silently overwriting it; the ticket's title,
banner, case count, Finding 2 and acceptance list are all corrected; and I re-measured every factual
claim the corrected text makes — the 21-character fixture, the `varchar(20)` column, the eleven cases
erroring `Tests: 11, Assertions: 0, Errors: 11` on PG, and the absence of a `timezone` key at
`config/database.php:87-98`. `config/database.php` is untouched exactly as declared. The executor's
disclosure of its own off-by-one citations, corrected in a dedicated commit, is the behaviour this
gate wants; I re-derived the corrected line numbers independently and they are right.

Scope is contained to the five expected files, nothing outside the two findings changed, and no rule
19 surface is engaged. The two new findings are both P3 record hygiene: §4's exact-count tables went
stale against the fix round's own test, and the now-true root cause's remedy is deferred to a lane
with no ledger entry. Neither touches cost or quantity at rest, neither is a code defect, and both
are one-line closes. They do not hold the milestone.

**One line to fix before merge (non-blocking, do at promotion):** paste the three re-measured counts
into `M5-evidence.md` §4 and add the `timezone`-pin lane to `wave3-3c-3d.progress.yaml`'s `blockers:`
so the corrected root cause has an owner.

VERDICT: ACCEPT
