# Gate record — Session B lane Q-2 (counting finalize), inventory-costing-reviewer r2

Commits `1ec42fc43` (r1 target) + `600ccbc28` (fix round), branch `fix/sb-q2-counting-finalize-lock`.
Base `ce59503a9`; local `dev` tip at gate time `d5443c1c7`. Worktree
`.worktrees/sb-q2-counting-finalize` (own real `apps/api/vendor` + `.env`, verified).

**Verdict: ACCEPT-with-conditions** — the three r1 code defects are genuinely closed and
red-provable from the pre-fix source; the typed renderer is correct and HTTP-proven. Two
conditions, both non-code-behaviour: (1) the manifest arithmetic is STALE and would turn dev's
checker RED on merge, (2) one docblock added by this fix round asserts an invariant that is
false in the same file.

## Disposition of the r1 findings

- **BLOCKER-1 — CLOSED.** `submitCount()` now locks at
  `apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:753`
  (`$lockedCounting = $this->lockCounting($counting->id)`) and re-asserts at `:771`
  (`$this->assertNotTerminal($lockedCounting, $expectedStatus)`) — i.e. AFTER the
  `lockForUpdate()` re-read, before the first write (`$item->submitCount(...)` at `:775`).
  `assertNotTerminal()` (`:835-840`) covers BOTH terminal states
  (`Finalized || Cancelled`). The false rationale comment r1 flagged at the old `:759-762`
  is gone; `:757-770` now carries the executed counter-example. Red-proof re-derived from the
  pre-fix source in the fix-round diff: at `1ec42fc43` the lock existed with NO status test, and
  the pre-transaction guard at `:733-739` compares the CALLER's snapshot
  (`$counting->status`, loaded via `$item->counting` at `:708`) — a stale
  `count_1_in_progress` snapshot passes it, so probe A had nothing left to stop it.
- **Tolerant forward-phase no-op — PRESERVED.** `checkPhaseCompletion()` still returns silently
  when the edge is gone (`:932-936`: `if (! $counting->canTransitionTo($completedStatus)) { return; }`),
  and `:914`'s docblock still states the no-throw contract. Pinned by
  `CountingTerminalStateGuardTest::test_a_forward_phase_race_is_still_a_no_op_and_keeps_the_count`
  (`tests/Feature/Inventory/CountingTerminalStateGuardTest.php:203-221`), which asserts the
  9.0000 quantity survives and the status does not move.
- **BLOCKER-2 — CLOSED.** `triggerThirdCount()` locks at `:1039` and asserts at `:1040`, as the
  FIRST statements inside its transaction (opened `:1026`), i.e. before the item reset at
  `:1042-1049`. The decision now reads the locked instance (`:1063`
  `if ($lockedCounting->status === CountingStatus::PendingReview)`), replacing the stale-caller
  test the fix-round diff shows being removed. Probe B at
  `CountingTerminalStateGuardTest.php:231-276`.
- **IMPORTANT-3 — CLOSED.** `cancel()` locks at `:1271` and asserts at `:1272`, BEFORE the
  `cancellation_reason` write at `:1273-1274`. The pre-transaction guard at `:1257-1263` is now
  typed (`CountingTransitionException`) so the common already-terminal case 422s rather than
  500s. Probe C at `CountingTerminalStateGuardTest.php:283-326`; the live-cancel path is kept
  green by `:332-341`. Caller-instance staleness does not leak: the cancel endpoint returns only
  a message (`Presentation/Controllers/InventoryCountingController.php:440-442`).
- **MINOR-5 — CLOSED and better than asked.** `CountingTransitionException::CODE =
  'COUNTING_TRANSITION_REFUSED'`
  (`app/Modules/Inventory/Domain/Exceptions/CountingTransitionException.php:39`); render handler
  at `bootstrap/app.php:930-942` returning 422 with the house `{error:{code,message,...}}`
  envelope plus `counting_id` / `current_status` / `attempted_status`. Ordering VERIFIED, not
  taken on the comment's word: the callback list is append-only
  (`vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:242`) and matched
  in registration order, first non-null wins (`Handler.php:712-725`); the typed handler is at
  bootstrap `:930`, the generic `DomainException` one at `:945`, the `Throwable` catch-all at
  `:983`, and no earlier-registered handler (`:246`-`:908`) types a supertype of
  `DomainException`. Proven end-to-end over real HTTP in
  `CountingFinalizeLockTest.php:199-233` and `CountingSubmitCountRaceTest.php:228-252`,
  including the "message must not lead with a bare UUID" assertion.
- **MINOR-6 — STILL OPEN, parent action.** The migration still builds both indexes without
  `CONCURRENTLY` (`database/migrations/tenant/2026_08_23_120000_unique_stock_movements_counting_apply.php:99-113`),
  which takes ACCESS EXCLUSIVE on `stock_movements` per tenant. Unchanged by the fix round and
  correctly so (a Laravel migration runs inside a transaction on PG, where `CONCURRENTLY` is
  illegal). Promotion-checklist item: per-tenant census + a maintenance window.
- **MINOR-7 — CLOSED.** The fix-round commit message explicitly retracts the H-1 overstatement
  ("a SEQUENTIAL double-finalize was already caught by the listener's applied markers … a genuine
  double-apply needs two CONCURRENT workers"). Accurate.
- **MINOR-8 — WIDER THAN THE FIX ROUND CLAIMS (see NEW-2).**

## New findings

- **[Important] NEW-1 — manifest arithmetic is stale; the merge as-committed turns dev RED.**
  `apps/api/tests/feature-lane-manifest.json` sets `gated_ceiling: 1150` and `POS.classes: 149`,
  and the Inventory note asserts "1150 is therefore the post-merge count". That was true against
  base `ce59503a9`; dev has since moved (`e70dcaad4` / `d1dfa30cd`, Session A N-5) to
  `POS 150` / `gated_ceiling 1148`. Measured (census method validated: it reproduces the
  checker's own numbers exactly on this branch — 70 gated groups / 1147 classes / 1381 total):
  - dev `d5443c1c7`: 70 gated groups, **1148** gated classes, ceiling 1148 (**zero headroom**),
    Inventory 108, POS 150.
  - branch HEAD: 70 gated groups, 1147 gated classes, ceiling 1150, Inventory 111, POS 149.
  - post-merge union (dev tree + this lane's 3 new `tests/Feature/Inventory/*Test.php`, no
    deletions): **1151** gated classes, Inventory **111**, POS **150**.
  1151 > 1150 ⇒ `feature-lane-manifest-check.php` emits "GATED-LANE COVERAGE GREW: 1151 …
  ceiling is 1150" (`tools/feature-lane-manifest-check.php:807-818`) and EXIT=1 on dev.
  **The merge MUST resolve to: `Inventory.classes` = 111, `POS.classes` = 150 (take dev's POS
  entry and note verbatim — dev's note already subsumes this branch's 147→149 text), and
  `gated_ceiling` = 1151**, and the Inventory note's "1150 is therefore the post-merge count"
  sentence must be corrected to 1151. In-branch the checker is EXIT=0 (run from the worktree
  root, output quoted under "Gate verified") — the defect is purely at the union.
- **[Important] NEW-2 — the fix round's own docblock claims an invariant the file does not hold;
  `manualOverride()` is the one mutating counting path with neither lock nor terminal guard.**
  `lockCounting()`'s docblock states "every mutating counting path takes this before it writes"
  (`InventoryCountingService.php:806-808`). `manualOverride()` (`:1086-1110`) takes no lock and
  makes no status test, and its only caller —
  `Presentation/Controllers/CountingItemController.php:288-310` — has no status check either
  (contrast `setOpeningCost` at `:222-236`, which DOES refuse Finalized/Cancelled with exactly
  the r1 rationale). So a manual override against a FINALIZED counting still writes `final_qty`,
  `final_qty_as_of`, `resolution_method` and `resolved_at` — the very fields the finalize
  listener consumes — onto a document whose variance has already posted and which has no
  outgoing edge. This is pre-existing (NOT lane-caused) and the brief puts "any other Inventory
  lifecycle" out of scope, so it is not a blocker; but the lane may not ship a comment that says
  it is covered. Minimum before merge: correct `:806-808` to name the exception, and file the
  gap on the LEDGER. Preferred (2 lines, same helpers already in the file): add
  `lockCounting()` + `assertNotTerminal()` to `manualOverride()`'s transaction.
- **[Minor] NEW-3 — MINOR-8 is 5 files / 34 PG errors on the counting regression set, not the
  "4 files / 13" the fix-round commit message records.** Executed on PostgreSQL against a
  throwaway DB, 23 counting-touching files: 183 tests, **34 errors, all one cause** —
  `SQLSTATE[22001] value too long for type character varying(20)` at the fixture INSERT of
  `counting_number`. Per file: `ReplayFinalizeTest` 11, `OnboardingFirstCountTest` 7,
  `OnboardingLifecycleTest` 6, `PreFinalizeReplayPreviewTest` 6, `ZoneScopedCountingTest` 4.
  `OnboardingLifecycleTest` is a FIFTH file the commit message does not name. Confirmed
  pre-existing and lane-independent: the fixtures build `'CNT-ONB-'.uniqid()` /
  `'CNT-ZON-'.uniqid()` / `'CNT-RPL-'.uniqid()` / `'CNT-OLC-'.uniqid()` = 21 chars
  (`OnboardingFirstCountTest.php:141`, `ZoneScopedCountingTest.php:309`,
  `ReplayFinalizeTest.php:157`, `OnboardingLifecycleTest.php:131`,
  `PreFinalizeReplayPreviewTest.php:264`) against
  `$table->string('counting_number', 20)`
  (`database/migrations/tenant/2026_03_04_100000_add_tenant_id_and_counting_number_to_inventory_countings.php:15`).
  Nothing in this lane touches `counting_number` or that schema. LEDGER ticket — update the
  numbers to 5 files / 34.
- **[Minor] NEW-4 — `finalize()` still 500s for its OTHER refusal.** The lane typed the
  transition refusal but left the unresolved-items refusal as a bare
  `\InvalidArgumentException` (`InventoryCountingService.php:1131-1134`), and
  `tests/Feature/Inventory/ReconciliationTest.php:494` still pins `assertStatus(500)` for it.
  Two business refusals of the same endpoint now render as 422 and 500. Out of the brief's
  scope; note for the state-machine program, not for this merge.
- **[Minor] NEW-5 — the 422 message is untranslated English prose**
  (`CountingTransitionException.php:46-48`). Acceptable because the FE has `code` +
  `current_status` + `attempted_status` to localise from, but the raw `message` will surface if
  the FE falls back to it.

## Gate verified

Lane tests, BY PATH, both drivers (never the full suite):

| file | sqlite (`phpunit.xml`) | PostgreSQL (`phpunit-pgsql.xml`, 127.0.0.1:5433, throwaway `autoerp_gate_q2r2`) |
|---|---|---|
| `tests/Feature/Inventory/CountingFinalizeLockTest.php` | 5 tests, 11 assertions, **3 skipped** (the PG-only partial-index probes) | **5 tests, 14 assertions, 0 skipped, OK** |
| `tests/Feature/Inventory/CountingSubmitCountRaceTest.php` | 2 tests, 11 assertions, 0 skipped, OK | **2 tests, 11 assertions, 0 skipped, OK** |
| `tests/Feature/Inventory/CountingTerminalStateGuardTest.php` | 6 tests, 24 assertions, **1 skipped** (the FOR-UPDATE sentinel) | **6 tests, 28 assertions, 0 skipped, OK** |
| combined lane run | 13 tests, 46 assertions, 4 skipped, OK | (as above) 13 tests, 0 skipped |

Counting regression set (23 files: the 20 counting-touching `tests/Feature/Inventory/*` plus
`tests/Unit/Inventory/InventoryCountingServiceTest.php` and
`CountingReconciliationServiceTest.php`):
- **sqlite: 183 tests, 710 assertions, 7 skipped, 0 failures — GREEN.**
- **PostgreSQL: 183 tests, 637 assertions, 34 errors — every one the pre-existing
  `counting_number` varchar(20) fixture overflow (NEW-3). Zero lane-attributable failures.**

Callers of the retyped `transitionTo()` audited for the `\InvalidArgumentException` →
`CountingTransitionException` change: `tests/Unit/Inventory/InventoryCountingServiceTest.php:446`
(`activateDraft`, still bare `\InvalidArgumentException` — unaffected),
`tests/Feature/Inventory/ZoneScopedCountingTest.php:495` (foreign-node assignment message —
unaffected), `tests/Feature/Inventory/ReconciliationTest.php:494` (unresolved-items 500 —
unaffected, see NEW-4), `StockMovementDocumentLinkageTest.php:384,405` and
`LateSyncResidualTest.php:309` (unrelated). All green above.

Sentinel quality (fix-round claim (c) verified in code): `test_every_mutating_counting_path_emits_the_header_row_lock`
(`CountingTerminalStateGuardTest.php:365-480`) is PG-gated at `:367-371` for a real reason —
`SQLiteGrammar::compileLock()` returns `''`, so `FOR UPDATE` is never emitted on the sqlite leg —
and the trace is armed AFTER `$arrange()` and before `$act()` (`:456-465`), so a fixture's own
`submitCount()` lock cannot vouch for the path under test. It genuinely asserts (`assertTrue`
with a message on a real SQL-text scan), not `assertTrue(true)`.

Static/style: `./vendor/bin/phpstan analyse` on the 8 changed backend files (service, exception,
domain model, `bootstrap/app.php`, migration, 3 tests) — **[OK] No errors** (level 8).
`./vendor/bin/pint --test` on the same 8 — `{"result":"pass"}`.

Manifest checker in-branch, run from the worktree root
(`php apps/api/tools/feature-lane-manifest-check.php`): **EXIT=0** —
"1381 Feature classes in 74 groups … 70 group(s) / 1147 class(es) are laned but not yet running".

Migration re-verified (unchanged by the fix round): pgsql-guarded at `:65-67`, pre-flight scan at
`:74-97` runs BEFORE `CREATE UNIQUE INDEX` and aborts with a named first-offender rather than an
opaque 23505, two partial indexes for the nullable `variant_id` (`:99-113`), and the docblock
census (`:36-46`) is byte-equivalent to the executed query at `:75-80` modulo the alias and
`ORDER BY`. Grain re-confirmed: both listener write paths stamp
`referenceType: StockMovementReferenceType::InventoryCounting`
(`app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:240`
legacy delta, `:310` replay) and that enum's value is `'inventory_counting'`
(`app/Shared/Domain/Enums/StockMovementReferenceType.php:55`), which is exactly the index
predicate.

### Census executed (local PostgreSQL, 127.0.0.1:5433, all 11 tenant databases + `autoerp`)

Query reproduced verbatim from the migration docblock. Result: **0 duplicate grains in every
database — and 0 rows with `reference_type = 'inventory_counting'` at all.** Databases scanned:
`autoerp`, `tenant019fbe86-…`, `tenant019fcf48-…`, `tenant019fe276-…`, `tenant01a01b77-…`,
`tenant01a03028-…`, `tenant01a033c6-…`, `tenant3f16ac36-…`, `tenant4c3a1260-…`,
`tenantbe3cd47a-…`, `tenantf6c592ac-…`.
**This census is CLEAN but VACUOUS** — the busiest local tenant holds only `Document` (274),
`StockTransfer` (38), `DocumentAdditionalCost` (16) and `pos_receipt` (3) movements, so no local
database has ever executed a counting apply. It is evidence that the migration will not abort
locally; it is NOT evidence about staging or the first tenant. The per-tenant census on staging
stays a promotion-checklist obligation (MINOR-6).

### Scope

Clean. The lane touches 9 files (service, new exception, `InventoryCounting` domain model,
`bootstrap/app.php`, 1 tenant migration, 3 new tests, the manifest). `git diff --stat ce59503a9 dev`
restricted to those paths shows dev changed **only** `feature-lane-manifest.json` — so the manifest
is the sole merge conflict, and there is zero code overlap with Session A's collision matrix (VAT
resolution, StockLevel read paths, opening-balance services, PIN/has_pins, web document pages).
No float touches money or quantity anywhere in the diff; test quantities are canonical strings
(`'12.0000'`, `'9.0000'`); tests use `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`
(`CountingTerminalStateGuardTest.php:57,95`).

### On faith

Serialization itself. As r1, no two-connection proof exists — `RefreshDatabase` hides fixture rows
from a second connection. The sentinel pins that the `FOR UPDATE` is EMITTED by all four paths;
that PostgreSQL then blocks the second session rests on `FOR UPDATE` semantics, not on a test.
The fix round's stated red-proofs of probes A/B/C against `1ec42fc43` were not re-executed here;
they were re-derived from the pre-fix source shown in the fix-round diff (submitCount locked with
no status test; `triggerThirdCount` unlocked and testing `$counting->status`; `cancel()` guarding
on the caller snapshot) — each probe's stale handle passes the pre-fix guard by construction.

## Before merge

1. Resolve the manifest to `Inventory.classes` = 111, `POS.classes` = 150 (dev's entry verbatim),
   `gated_ceiling` = **1151**, and correct the Inventory note's post-merge-count sentence.
2. Correct `InventoryCountingService.php:806-808` (or guard `manualOverride()`), + LEDGER ticket.
3. LEDGER: MINOR-8 restated as 5 files / 34 PG errors; MINOR-6 staging census + window on the
   promotion checklist.
