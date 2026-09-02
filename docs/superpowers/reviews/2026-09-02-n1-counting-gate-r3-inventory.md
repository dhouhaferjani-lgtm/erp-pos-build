# N-1 (API) — adversarial gate r3 (condition check) — inventory-costing-reviewer

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n-inventory-mobile`
- Branch `test/N-inventory-mobile`, base `dev` `3615cab8f`
- r2: `docs/superpowers/reviews/2026-09-02-n1-counting-gate-r2-inventory.md` (CHANGES — BLOCKER-1, IMPORTANT-1, IMPORTANT-2, MINOR-1..6)
- Fix round reviewed: `a49e68a12` (API). `41580f862` on top is web-only (design-system baseline re-pin, FE coercion test, dead i18n key) — out of scope for this gate.
- No files modified by this review.

## VERDICT: MERGE-WITH-CONDITIONS

All three blocking items are genuinely closed, verified independently of the commit message —
by reading the code, by re-running my r2 PostgreSQL reproduction, and by an independent raw-SQL
savepoint probe against the same PG 16 instance. Every counting test file in the module is green
except one pre-existing failure and four pre-existing PG-only fixture errors, all of which exist
unchanged at base `3615cab8f`. The three new findings below are residuals, none merge-blocking.

## Verification runs (this round, in this worktree)

```
# SQLite (default phpunit.xml) — the list the coordinator asked for
./vendor/bin/phpunit tests/Feature/Inventory/ActivateDraftCountingTest.php \
  tests/Feature/Inventory/InventoryTenantIsolationTest.php \
  tests/Feature/Inventory/ZoneScopedCountingTest.php \
  tests/Feature/Inventory/LiveCountingScenarioTest.php \
  tests/Feature/Inventory/OnboardingFirstCountTest.php \
  tests/Feature/Inventory/CountingOverlapGuardTest.php
=> Tests: 105, Assertions: 368, Failures: 1
   ONLY ZoneScopedCountingTest::test_location_hierarchy_counting_flow_keeps_variant_stock_at_location_grain
   (:601, assertCount(2) got 0) — the identical pre-existing failure r2 measured at :600 (the +1 is
   the new `use InventoryCountingEvent` import). Untouched by this diff.

# PostgreSQL lane (autoerp_test_n / autoerp_test_n_central, 127.0.0.1:5433) — the batch tests
./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/ActivateDraftCountingTest.php
=> OK (25 tests, 88 assertions)      <-- includes all three new batch tests

./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/ZoneScopedCountingTest.php \
  tests/Feature/Inventory/InventoryTenantIsolationTest.php
=> Tests: 61, Assertions: 210, Errors: 4, Failures: 1
   InventoryTenantIsolationTest (36 tests) fully green on PG.
   The 4 errors + 1 failure are all ZoneScopedCountingTest and all PRE-EXISTING (NEW-3 below):
   4x SQLSTATE[22001] value too long for varchar(20) from `'CNT-ZON-'.uniqid()` (8+13 = 21 chars)
   in test_submit_count_assigns_scanned_in_product_to_the_single_zone /
   test_zone_count_rehomes_existing_placement_outside_subtree /
   test_zone_count_treats_similar_path_prefix_as_outside_subtree /
   test_zone_count_does_not_preserve_same_path_from_a_different_location.
   All four exist verbatim at base `3615cab8f` (`git show 3615cab8f:…ZoneScopedCountingTest.php`
   contains all four names and 4 occurrences of `CNT-ZON-'.uniqid()`); `counting_number` is
   `character varying(20)` (information_schema, autoerp_test_n). NOT this lane.
   BOTH new zero-item tests (:710, :758) pass on PG.

# The rest of the counting surface — activate() has other callers than the two guarded tests
./vendor/bin/phpunit tests/Feature/Inventory/{CountingTerminalStateGuard,CountingBlock,BlindCounting,
  CountingFinalizeLock,CountingSubmitCountRace,CountingVarianceApplied,CountCorrectionGlPosting,
  CountCorrectionGlPostingDefault,InventoryCountingDefaultBatch,CountingDiscrepancyReport,
  CountingReason,CountTimestampSkew,LiveCountingSchema,CountingShowUserEagerLoad}Test.php
=> OK (105 tests, 449 assertions, 17 skipped)
   CountingTerminalStateGuardTest --testdox: "The activation edges still work" ✔ — i.e. the new
   zero-item guard in activate() does not refuse any pre-existing legitimate activation.

./vendor/bin/phpstan analyse <6 changed app files + 4 changed test files> --memory-limit=1G
=> 1 error, PRE-EXISTING and outside phpstan.neon `paths` (app/ only):
   tests/Feature/Inventory/InventoryTenantIsolationTest.php:769 method.alreadyNarrowedType.
   All six changed app/ files clean. Identical to r2.
```

---

## Condition-by-condition

### BLOCKER-1 (malformed uuid aborts the batch transaction; 201 with phantom serverIds) — **CLOSED**

Both halves landed, and I verified the mechanism rather than the claim.

**(a) uuid guard before any query.** `InventoryCountingController.php:1119` —
`if ($scopeLocationId !== null && ! Str::isUuid($scopeLocationId))` → per-row `errors[]` +
`continue`. It sits **before** the only statement that touches a uuid column with device text:
the existence check now at `:1137`. Same idiom already in this controller at `:73`
(`onboardingWorklist`) and `:1258` / `:1284` (`batchAddProducts`).

**(b) per-row savepoint.** `:1135` — the row body is its own `\DB::transaction(...)` returning
`?string`, nested inside the batch transaction opened at `:1078`. Verified against the vendor,
not from memory: `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php`
`handleTransactionException()` calls `rollBack()`, and `performRollBack($toLevel)` emits
`ROLLBACK TO SAVEPOINT trans{level+1}` for any level > 0. The exception is then re-thrown into the
loop's `catch (\Throwable)` at `:1184`, so the row is dropped and the loop continues on a **usable**
transaction.

**Independent empirical proof (not the test).** Raw psql against the same PG 16 that produced the
r2 reproduction (`127.0.0.1:5433`, temp tables, same statement shapes, same order):

```
SAVEPOINT trans2; INSERT … 'draft-1-good'; RELEASE
SAVEPOINT trans2; SELECT 1 FROM probe_loc WHERE id = 'undefined';
                  ERROR: invalid input syntax for type uuid: "undefined"
                  ROLLBACK TO SAVEPOINT trans2
SAVEPOINT trans2; INSERT … 'draft-3-good'; RELEASE
rows_before_commit = 2 ; COMMIT ok
```

r2's identical probe without the savepoint gave `rows actually persisted = 0` with a silent COMMIT.
So the outer transaction's commit semantics for the success rows are **preserved**, and the rows
after the bad one survive too.

**Rows persisted == success count, on PG.** `ActivateDraftCountingTest.php:608` sends
`location_id: 'undefined'` plus a healthy sibling and asserts `assertDatabaseHas('inventory_countings',
['id' => $serverId, …])` at `:641` plus `assertSame(1, InventoryCounting::…count())` — the response-only
assertion that hid the bug in r1 is gone. Green on the PG lane above. `:658` is the stronger one:
`count1UserId: 'not-a-uuid'` against a **uuid** column (`information_schema`: `count_1_user_id | uuid`)
cannot be filtered by `Str::isUuid` — it can only be survived by the savepoint. That test passing on
PG with `data.success.0.localId === 'local-healthy'` is direct proof (b) works: without the savepoint
the healthy sibling would have been rolled back with the batch, exactly as r2 measured.

**`Log::warning` replaces raw exception text.** `:1184-1193` — `catch (\Throwable $e)` (widened from
`\Exception`, which also removes the r1 `ValueError` escape as a second line of defence),
`Log::warning('Batch draft counting creation failed', ['company_id', 'local_id', 'exception'])`, and
`errors[].error = 'Draft could not be created'`. Asserted at `:681-690` (every error entry must equal
that literal — no table/column name, no offending value).

### IMPORTANT-1 (zone in the missing-location check) — **CLOSED**
`InventoryCountingController.php:1097-1108` — `in_array($draftData['scopeType'], [ProductLocation->value,
Zone->value], true) && $scopeLocationId === null` → `errors[] = {localId, 'A location must be selected
for this scope'}` + `continue`, before any row is minted. `$scopeLocationId` is normalised to `null` for
`''` at `:1095`, so a whitespace-only value takes the same branch. Test
`ActivateDraftCountingTest.php:697` asserts the per-row error, the healthy sibling in `success[]`, and
**0** rows with `scope_type = zone` for the company. Green on SQLite and on PG. The mobile brief
`docs/handoff/CODEX-mobile-inventory-alignment-2026-09-02.md:24` and the server now agree.

### IMPORTANT-2 (zero-item refusal on the web activation path) — **CLOSED**
`InventoryCountingService.php:724-725` — `if ($counting->items()->count() === 0) throw new
\DomainException('Nothing to count in this scope — no stock rows matched');` inside the transaction
opened at `:706`, after `lockCounting`/`assertNotTerminal` and before `transitionTo`, the assignment
`start()` and the `COUNTING_ACTIVATED` event. Same literal as the `activateDraft` twin at `:661-662`,
so the two surfaces now carry one rule (convention 11). 422 mapping confirmed at
`apps/api/bootstrap/app.php:1022-1031` (generic `DomainException` renderer → `{"error":{"code":
"BUSINESS_ERROR","message":…}}`).

**Rollback proven on the values that matter**, not on the code: `ZoneScopedCountingTest.php:710`
asserts 422 + `error.code = BUSINESS_ERROR` + the message, then re-reads the row and asserts the
status is back to its pre-call value, `items()->count() === 0`, **0** count-1 assignments with a
non-null `started_at`, and `assertDatabaseMissing('inventory_counting_events', [counting_id,
COUNTING_ACTIVATED])`. Control at `:758`: the same shape with real stock still activates (200,
status `Count1InProgress`, items > 0). Both green on SQLite and on PG.

**No legitimate activation is refused — verified by reading the generator and by running every
caller.** `resolveIncludesZeroStock` (`InventoryCountingService.php:197-213`) returns true only for
`FullInventory`/`Location`; `catalogItemSeeds` (`:453-496`) then seeds the cartesian product of every
active product × every resolved location with `theoretical_qty` defaulting to `'0.0000'` (`:491`), and
can only return `[]` when the company has no resolved locations (`:457-459`) or no active products
(`:468-470`) — genuinely nothing to count. `activate()` has exactly **one** application caller,
`InventoryCountingController.php:413` (`grep -F '->activate('` across `app/` returns only that one plus
unrelated Promotion/Billing) — no queue, no console, no scheduler, so the throw cannot kill a worker.
Empirically: `CountingTerminalStateGuardTest` ("The activation edges still work" ✔),
`CountingOverlapGuardTest` (11 `activate()` calls), `LiveCountingScenarioTest` (incl. the
full-inventory activation at `:327`) and `OnboardingFirstCountTest` all green.

### MINOR-1 — **CLOSED** (see BLOCKER-1, `:1184-1193`, asserted at `ActivateDraftCountingTest.php:681`)

### MINOR-4 / MINOR-5 (census committed, reproducible) — **CLOSED**
`docs/superpowers/reviews/2026-09-02-inventory-counting-cross-layer-evidence.md:85-146` — §5
"Promotion census" carries the exact SQL (stranded query + a context query), the correct `jsonb ->>`
justification with the migration line, the zsh word-splitting trap that produces a false all-clear,
and a results table with all three runs (lane 239 DBs / 40 countings / 0 stranded; my r2 run 303 / 79
/ 0; staging 16 / 0 / 0, 2026-09-02), plus the single location-less row identified by database and id
and shown to be already `cancelled`.

### MINOR-2 / MINOR-3 / MINOR-6 (residuals) — **DOCUMENTED as ruled**
`…cross-layer-evidence.md:146-166` records all three: the two error envelopes on the activate route,
the unvalidated `scope_filters.zone_ids` on the draft path (with the `zoneItemSeeds` no-leak argument),
and the non-uniform per-row refusal for an unknown `scopeType`. Each has a why-not-now and a proposed
close. That satisfies the r2 ask.

---

## NEW findings (this round)

### IMPORTANT

**NEW-1 — the savepoint safety net has one hole: a *concurrency* error inside a row decrements the
transaction level WITHOUT rolling back to the savepoint, which re-opens the exact BLOCKER-1 shape.**
`vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php`
`handleTransactionException()` short-circuits: `if ($this->causedByConcurrencyError($e) &&
$this->transactions > 1) { $this->transactions--; … throw new DeadlockException(...); }` — it never
calls `rollBack()`, so **no `ROLLBACK TO SAVEPOINT` is emitted**. The triggers are listed in
`Illuminate/Database/ConcurrencyErrorDetector.php:19-37` (`'deadlock detected'` for PG, SQLSTATE
`40001`, `'database is locked'` for SQLite). In `InventoryCountingController.php:1135`, such an
exception is swallowed by `catch (\Throwable)` at `:1184`, the loop continues on an **aborted** PG
transaction, every later row fails with 25P02 into `errors[]`, and the outer `COMMIT` at `:1078`
silently degrades to ROLLBACK — 201 with `serverId`s the device records as synced for rows that do
not exist. Why it is not a blocker: the trigger is a deadlock/serialization failure on a single-row
INSERT into `inventory_countings` under READ COMMITTED (FK key-share locks on company/user parents),
which is rare, versus BLOCKER-1's trigger which was *any* malformed device string; and the batch is
capped at 50 rows (`:1051`). Suggested fix (2 lines, follow-up): after the `catch`, bail out of the
loop if `\DB::transactionLevel() !== 1`, or catch `DeadlockException` separately and rethrow so the
whole batch 500s honestly instead of answering 201 with phantom ids.

### MINOR

**NEW-2 — the census in §5 does not cover the class the new `activate()` guard creates.**
`…cross-layer-evidence.md:85-124` censuses only location-less `product_location`/`zone` drafts. The
IMPORTANT-2 guard (`InventoryCountingService.php:724`) strands a *different* legacy class: any existing
`draft`/`scheduled` counting with **zero** `inventory_counting_items` becomes permanently
un-activatable (exit = cancel + re-create, same as the location-less class). I measured it myself,
read-only, across every non-template local database carrying the table:
`304 DBs with inventory_countings; draft|scheduled countings with 0 items: 0`. Local exposure is nil,
but the query is not in the doc and so will not be run on staging/production. Add to §5:
`SELECT current_database(), status, count(*) FROM inventory_countings c WHERE c.status IN
('draft','scheduled') AND NOT EXISTS (SELECT 1 FROM inventory_counting_items i WHERE i.counting_id = c.id)
GROUP BY 1,2;`

**NEW-3 — pre-existing, but it means the zone suite has never been green on PG.**
`ZoneScopedCountingTest.php` builds `'counting_number' => 'CNT-ZON-'.uniqid()` in four tests
(`:473` and three siblings) = 21 characters against `counting_number character varying(20)`, so those
four ERROR with SQLSTATE[22001] on PostgreSQL and pass on SQLite (which ignores varchar length). All
four are verbatim at base `3615cab8f`, so this is not the lane's defect — but this lane is precisely
the one whose guards are PG-sensitive (BLOCKER-1 existed only because SQLite could not see it), and it
adds two new tests to this very file. Worth a one-line fix (`substr(uniqid(), -8)`) so the file can be
part of the PG lane at all.

**NEW-4 — the malformed-uuid row is reported as `'Location not found for the current company'`.**
`InventoryCountingController.php:1119-1126` deliberately reuses the foreign-location message (good:
nothing leaks). But the sibling offline-sync endpoint distinguishes them — `batchAddProducts` emits
`'Invalid product ID; expected a UUID'` (`:1284` ff). A device that sent a truncated local id gets the
same text as one that sent a stale-but-valid server id, so the mobile team cannot tell a client bug
from a data-staleness bug from `errors[]`. Cosmetic; align the two endpoints when NEW-1 is fixed.

**NEW-5 — a new English-only server string reaches a FR/AR operator's toast.**
`InventoryCountingService.php:725` `'Nothing to count in this scope — no stock rows matched'` is
rendered verbatim by `apps/web/src/features/inventory-counting/api/queries.ts:135`
(`toast.error(t('counting.messages.activateFailed', { error: getErrorMessage(error) }))`). Same class
as the pre-existing `'A location must be selected before activation'` bare-envelope guards, so it is
consistent rather than a regression — but it is a new user-facing untranslated string on the primary
web surface. A translated `DomainException` subclass (as `CountingUnresolvedItemsException` already
does, `bootstrap/app.php:1010-1018`) is the existing pattern.

## Not a finding (checked, clean)

- No stock movement, WAC arithmetic, batch/FEFO, opening-balance or lock-order code is touched by
  `a49e68a12`. `MovementReason` signs untouched; no new `getScale()` call, so no queue/console no-arg
  scale exposure (rule 19). No float, no `number_format`, no `(float)` in the diff.
- Rules 3/9/13: `declare(strict_types=1)` throughout, enum comparisons (`CountingScopeType::Zone->value`
  at `:1100`, `CountingStatus` in the service), `CompanyContext` constructor-injected, no `app()` added.
- The nested transaction does not change money/quantity handling and adds no `afterCommit` semantics
  change: Laravel's nested "commit" only decrements the level (no `RELEASE SAVEPOINT`, no PDO commit),
  so deferred/`afterCommit` listeners still fire at the single outer commit.
- Convention 09 for the touched surface: second-company (`InventoryTenantIsolationTest.php:328`, `:357`),
  second-location (`ActivateDraftCountingTest.php:312`), partial-success/per-row re-run coverage
  (`:608`, `:658`, `:697`). No migration, no new unique key, no new noun.
- Convention 11: the two activation surfaces (`activateDraft`, `activate`) now carry the identical
  refusal literal — the duplicate-surface gap r2 raised is closed rather than papered over.

## Before merge (conditions)

1. Record **NEW-1** (concurrency-error path bypasses `ROLLBACK TO SAVEPOINT`) as a tracked follow-up
   with a lane id — or land the 2-line `DB::transactionLevel()` bail-out now.
2. Add the **NEW-2** zero-item query to §5 of the evidence doc and run it on staging/production with
   the location-less one before the guards ship there (local: 304 DBs, 0 exposed — measured in this
   gate).
3. NEW-3/NEW-4/NEW-5 are minors: ticket them, do not block.
