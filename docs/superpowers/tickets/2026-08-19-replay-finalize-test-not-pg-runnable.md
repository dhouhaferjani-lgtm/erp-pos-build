# `ReplayFinalizeTest` cannot run under the `[PG]` recipe — and hides three PG-only divergences

**Source:** DPA Wave 3D M5 (T21). Discovered while running the wave's PG regression lane.

**Severity:** P2 — the counting replay lane's primary regression file is exercised on SQLite only,
so PostgreSQL-only behaviour in the replay window is unguarded in production's actual driver.

**Status:** OPEN

**Owner:** Inventory (counting/replay).

## Finding 1 — the file errors out entirely on PostgreSQL

`apps/api/tests/Feature/Inventory/ReplayFinalizeTest.php:157` builds
`'counting_number' => 'CNT-RPL-'.uniqid()`, which is 21 characters.
`inventory_countings.counting_number` is `varchar(20)`. SQLite stores it happily; PostgreSQL
rejects every insert with `SQLSTATE[22001] value too long for type character varying(20)`, so all
14 cases error before asserting anything.

This is the house's documented "green in CI, 500 in production" trap in test-fixture form.

## Finding 2 — with the fixture shortened, three cases FAIL on PostgreSQL

Measured at `d425434cd` (M5's base, production code untouched) with the counting number shortened
to `'RPL-'.uniqid()`:

| case | expected | actual on PG |
|---|---|---|
| `test_case_b_pre_count_sale_excluded` | `17.0000` | `12.0000` |
| `test_case_c_basket_window_flags_and_skips` | `10.0000` | `19.0000` |
| `test_finalize_stamps_final_qty_as_of_from_count_estimate` | `…T13:48:09+00:00` | `…T13:48:09+01:00` |

All three are timestamp-boundary divergences. The third is plainly an offset-rendering difference
(`+00:00` vs `+01:00`); the first two are the replay window `(final_qty_as_of, now]` admitting or
excluding a movement differently — i.e. the *pre-count sale exclusion* and the *basket window* both
behave differently on the production driver. That is a materially different outcome, not cosmetic:
case (b) is the assertion that a sale made BEFORE the count is not double-deducted.

## Why M5 did not fix it

Wave 3D M5's scope is T21/T22. The fixture change is one line, but it converts a silent error into
three genuine PG-only failures whose root cause is timestamp/timezone handling in
`MovementReplayService` — a separate lane. M5 therefore left `ReplayFinalizeTest.php:157` exactly as
inherited and excluded the file from its PG regression run, recording the reason here. M5's own
addition to that file (`test_both_counting_paths_persist_the_row_unit_cost`) is driver-agnostic and
runs green on SQLite; its PostgreSQL counterpart is
`tests/Feature/Inventory/CountCorrectionGlPostingTest.php`, which is `[PG]`-only by construction.

## Acceptance

1. Shorten the counting-number fixture so the file runs on PostgreSQL.
2. Root-cause the (b) and (c) divergences in the replay window before adjusting either assertion —
   determine whether the SQLite expectation or the PostgreSQL result is the correct semantics. Do
   not "fix" the test to match PG if PG is the one that is wrong.
3. Fix the offset assertion to compare instants rather than rendered offsets.
4. Re-run the whole file green on BOTH drivers and add it to the standing PG lane.
