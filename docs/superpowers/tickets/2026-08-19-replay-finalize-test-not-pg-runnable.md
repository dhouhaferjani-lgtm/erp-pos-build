# `ReplayFinalizeTest` cannot run under the `[PG]` recipe

**Source:** DPA Wave 3D M5 (T21). Discovered while running the wave's PG regression lane.

**Severity:** P2 — the counting replay lane's primary regression file is exercised on SQLite only,
so PostgreSQL-only behaviour in the replay window is unguarded in production's actual driver.

**Status:** OPEN

**Owner:** Inventory (counting/replay).

> **CORRECTED at M5 round 1** (`M5-round1-inventory-costing.md` finding 2, `M5-round1-fiscal-pos.md`
> F-2). This ticket originally asserted that the file also "hides three PG-only divergences" whose
> root cause was timestamp handling in `MovementReplayService`, and gave the case count as 14. Both
> were wrong. The divergences are an **unpinned pgsql session timezone**, not a product defect, and
> the file has **11** cases. Finding 1 stands unchanged. See Finding 2 below.

## Finding 1 — the file errors out entirely on PostgreSQL

`apps/api/tests/Feature/Inventory/ReplayFinalizeTest.php:157` builds
`'counting_number' => 'CNT-RPL-'.uniqid()`, which is 21 characters.
`inventory_countings.counting_number` is `varchar(20)`. SQLite stores it happily; PostgreSQL
rejects every insert with `SQLSTATE[22001] value too long for type character varying(20)`, so all
**11** cases error before asserting anything (`grep -c 'public function test_'` → 11; measured
`Tests: 11, Assertions: 0, Errors: 11`). An earlier revision of this ticket said 14 — wrong count,
correct mechanism.

This is the house's documented "green in CI, 500 in production" trap in test-fixture form.

## Finding 2 (CORRECTED) — the three "divergences" are an unpinned pgsql session timezone, not a replay defect

With the counting number shortened to `'RPL-'.uniqid()` at `d425434cd` (M5's base, production code
untouched), three cases fail on PostgreSQL:

| case | expected | actual on PG |
|---|---|---|
| `test_case_b_pre_count_sale_excluded` | `17.0000` | `12.0000` |
| `test_case_c_basket_window_flags_and_skips` | `10.0000` | `19.0000` |
| `test_finalize_stamps_final_qty_as_of_from_count_estimate` | `…T13:48:09+00:00` | `…T13:48:09+01:00` |

**This ticket originally recorded those as "three genuine PG-only divergences" in the replay window
and sent this lane after a `MovementReplayService` timestamp defect. That root cause is wrong and was
disproved by experiment** at M5 round 1 (`M5-round1-inventory-costing.md`, finding 2). The identical
file was re-run against the identical database with only the PostgreSQL **session timezone** forced:

```
PGTZ=UTC TZ=UTC … phpunit -c phpunit-pgsql.xml <shortened ReplayFinalizeTest copy>
->  OK (11 tests, 36 assertions)
```

All three pass. `config/database.php:87-98` pins `charset` and `search_path` on the `pgsql`
connection but carries **no `timezone` key**, so on a `CET+0100` machine (`SHOW timezone` → `now()`
renders `+01`) a datetime bound without an offset is interpreted in the session zone. That shifts
`final_qty_as_of` one hour earlier, which (b) admits the pre-count sale into the replay window
(20 − 5 − 3 = 12) and (c) pushes the t+5min movement outside the 15-minute basket window (20 − 1 =
19). Both observed deltas match the shift arithmetically, and the third case is the same shift
rendered rather than computed.

**Consequences of the correction:**

- There is **no defect in `MovementReplayService`** to root-cause. The pre-count-sale exclusion and
  the basket window behave identically on both drivers once the session zone is pinned.
- The file's exclusion from M5's PG lane is owed to the **21-character fixture alone** (Finding 1).
  Once that is fixed the file is green on PG.
- The real, cheap fix is to pin the connection timezone. That is a **repo-wide lane the parent has
  ledgered separately**; wave 3D does **not** touch `config/database.php`.

## Why M5 did not fix it

Wave 3D M5's scope is T21/T22. M5 left `ReplayFinalizeTest.php:157` exactly as inherited and excluded
the file from its PG regression run, recording the reason here. M5's own addition to that file
(`test_both_counting_paths_persist_the_row_unit_cost`) is driver-agnostic and runs green on SQLite;
its PostgreSQL counterpart is `tests/Feature/Inventory/CountCorrectionGlPostingTest.php`, which is
`[PG]`-only by construction.

## Acceptance

1. Shorten the counting-number fixture so the file runs on PostgreSQL.
2. Confirm the measured PGTZ result above rather than hunting a replay-window product defect: with
   the pgsql session timezone pinned to UTC, all 11 cases pass unchanged. Do **not** adjust the (b)
   or (c) assertions — the SQLite expectations are the correct semantics.
3. Fix the offset assertion to compare instants rather than rendered offsets, so the file is
   independent of the session zone.
4. Pin `'timezone' => 'UTC'` on the `pgsql` / `tenant` connections in `config/database.php` — tracked
   as its own repo-wide lane, not from this ticket's inventory scope.
5. Re-run the whole file green on BOTH drivers and add it to the standing PG lane.
