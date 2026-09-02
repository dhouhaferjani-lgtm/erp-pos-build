# N-1 (API) — adversarial gate r4 (final confirmation, narrow scope) — inventory-costing-reviewer

- Worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n-inventory-mobile`, branch `test/N-inventory-mobile`, base `dev` `3615cab8f`
- Prior round: `docs/superpowers/reviews/2026-09-02-n1-counting-gate-r3-inventory.md` (MERGE-WITH-CONDITIONS)
- Reviewed: `e51ef57ba` (NEW-1 bail-out) + `854c54028` (docs). Nothing else re-reviewed.
- No files left modified by this review (`git status --porcelain` empty after the experiment in §1c).

## VERDICT: MERGE

All three r3 conditions are closed. The deviation on condition 1 is **CORRECT**, and my original
`!== 1` ruling was **wrong on both counts the implementer names** — I verified each against the
vendor source and, for the second, empirically by temporarily applying my own ruling and watching
the r2 test go red. No new finding.

---

## 1. Condition 1 — NEW-1 bail-out vs the ruling

### (a) "the concurrency short-circuit DECREMENTS the level" — CONFIRMED, ruling was a no-op
`vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:93-101`:

```php
if ($this->causedByConcurrencyError($e) && $this->transactions > 1) {
    $this->transactions--;                                   // :95
    $this->transactionsManager?->rollback(...);              // :97
    throw new DeadlockException($e->getMessage(), ...);      // :101
}
```

`rollBack()` is NOT called on this branch (it is reached only at `:107`, below the `throw`), so
`performRollBack()` (`:300`) never emits `ROLLBACK TO SAVEPOINT` — the PG transaction stays aborted,
exactly as r3 described. And because `:95` decrements, `DB::transactionLevel()` after the throw is
back to its pre-row value: the level is *balanced* precisely in the one case NEW-1 is about.
`beginTransaction()` (`:124-132`) had incremented it symmetrically. So a level comparison — mine or
theirs — can never detect this. The `$e instanceof DeadlockException` arm is the only thing that
does. My ruling would have been a **silent no-op for the hazard it was written for**.

The level arm is not dead weight: for a *non*-concurrency row error the fall-through `rollBack()`
at `:107` sets `transactions = toLevel` (`:283`), i.e. back to pre-row → no rethrow (this is why the
r2 savepoint test still returns 201); but `handleRollBackException()` (`:323-331`) zeroes
`transactions` on a lost connection, which the level arm then catches. Belt-and-braces is real.

### (b) "under RefreshDatabase the level is 2, so `!== 1` rethrows every row error" — CONFIRMED EMPIRICALLY
`tests/Feature/Inventory/ActivateDraftCountingTest.php:33` `use RefreshDatabase;`. I did not take
this on argument: I temporarily replaced the committed condition with my literal ruling
(`if (\DB::transactionLevel() !== 1) {`) and re-ran the batch tests on the PG lane:

```
DB_DATABASE=autoerp_test_n … phpunit -c phpunit-pgsql.xml ActivateDraftCountingTest.php \
  --filter test_batch_create_drafts
=> Tests: 7, Assertions: 32, Failures: 1
   test_batch_create_drafts_isolates_a_failing_row_in_its_own_savepoint (:658)
   ActivateDraftCountingTest.php:677 — expected 201, got 500,
   SQLSTATE[22P02] invalid input syntax for type uuid: "not-a-uuid"
```

That is the r2 savepoint-isolation test — the one that proves BLOCKER-1 stays closed — turning red
under my own ruling. File restored from a scratchpad copy immediately after; worktree verified clean.

### (c) Exception type, import, and escape path — ALL CONFIRMED
- Type thrown on the short-circuit is `DeadlockException` (`ManagesTransactions.php:101`), class
  declared `namespace Illuminate\Database;` in `vendor/…/Database/DeadlockException.php:3` →
  the import at `InventoryCountingController.php:27` is the right FQCN.
- Trigger set is `Illuminate\Database\ConcurrencyErrorDetector::causedByConcurrencyError()`
  (`vendor/…/Database/ConcurrencyErrorDetector.php:18-38`): SQLSTATE `40001` (`:20`),
  `'deadlock detected'` (`:29`), `'database is locked'` (`:30`) — PG and SQLite both covered.
- The short-circuit's `$this->transactions > 1` guard (`:94`) always holds for a row body, since the
  batch already opened level 1 at `InventoryCountingController.php:1092` and the row opens level ≥2
  at `:1151`.
- **Escape confirmed by tracing, not by assumption.** The rethrow at `:1228-1230` sits in the per-row
  `catch (\Throwable $e)` at `:1198`, which is inside the closure passed to the outer
  `\DB::transaction(...)` at `:1092`; there is no other `catch` between them and no transaction
  middleware on the route (`app/Modules/Inventory/Presentation/routes.php:239-241` — only
  `can:inventory.adjust`). In production the outer transaction is therefore level 1 when it catches
  (the nested short-circuit already decremented 2→1), so `ManagesTransactions:94` `1 > 1` is FALSE,
  execution falls to `$this->rollBack()` at `:107` (a real `ROLLBACK`, which PG accepts on an aborted
  transaction) and then `throw $e` at `:114` (attempts=1). The `return response()->json(…, 201)` at
  `:1240-1246` is **after** the transaction call and is never reached → 500, no phantom `serverId`s.
  No renderer intercepts it: `grep -n 'PDOException\|QueryException\|DeadlockException' bootstrap/app.php`
  returns nothing, so it falls to the framework 500.

**Ruling: the deviation is CORRECT and strictly better than the instruction it replaces.** The
committed form catches the hazard (which mine did not) and preserves partial success (which mine
broke). It is asserted by construction — docblock `:1041-1050`, inline comment `:1206-1227` — which
is the right call: a real deadlock needs two racing sessions.

## 2. Condition 2 — evidence doc §5
`docs/superpowers/reviews/2026-09-02-inventory-counting-cross-layer-evidence.md`
- NEW-2 query recorded verbatim at `:163-171` (`status IN ('draft','scheduled')` + `NOT EXISTS` on
  `inventory_counting_items`), with **both** runs in the table at `:173-176`: local gate-r3 reviewer
  304 DBs = **0**, staging 16 DBs = **0**, plus "re-run on production before the guards ship".
- NEW-3 at `:193-202`, NEW-4 at `:203-209`, NEW-5 at `:210-218` are each recorded as residuals with
  a proposed close — condition 3 ("ticket them, do not block") satisfied. NEW-1 is additionally
  logged for the record at `:219-226`, correctly marked "handled in r3".

## 3. Condition 3 — re-runs (only the two asked for)
```
./vendor/bin/phpunit tests/Feature/Inventory/ActivateDraftCountingTest.php
=> OK (25 tests, 88 assertions)                                        [SQLite]

DB_DATABASE=autoerp_test_n DB_CENTRAL_DATABASE=autoerp_test_n_central \
  ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/ActivateDraftCountingTest.php
=> OK (25 tests, 88 assertions)                                        [PG autoerp_test_n]
```
No full suite, no other PG database.

## Note (not a finding, not merge-relevant)
Under a caller that wraps `batchCreateDrafts` in a further transaction (only `RefreshDatabase` does),
the rethrown `DeadlockException` re-enters the same short-circuit at `ManagesTransactions:93-101` on
the outer level and leaves *that* transaction aborted rather than rolled back. Test-harness-only:
the production path is level 1 (§1c) and rolls back properly. No action.

## Not re-reviewed
Everything closed in r3 (BLOCKER-1 uuid guard + savepoint, IMPORTANT-1 zone location, IMPORTANT-2
zero-item guard, MINOR-1..6) and the whole web/frontend side. `e51ef57ba` touches one file and adds
no money/quantity arithmetic, no stock movement, no scale resolution, no float.
