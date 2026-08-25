# N-6 DPA ROLLBACK MICRO-LANE — HANDBACK (2026-08-25)

Worktree `.worktrees/n6-dpa-rollback`, branch `fix/n6-advance-clearing-dpa-rollback`, based on `dev` (`eaf80a112`).
Raised by consolidation r1 on merged `dev`: the **`backend-dpa-guard`** ratchet grew by two keys because the N-6 r2
`R2-F5` fix compensated a failed post with row DELETEs.

**Commit `12867e5fc`.** Two files touched, nothing else:
* `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
* `apps/api/tests/Feature/Accounting/ClearCustomerAdvanceOrphanDraftTest.php`

## The growth, reproduced before touching anything

```
NEW document-per-action violations (growth — the guard is a ratchet; give the write a
justifying document reference instead of baselining it):
  …GeneralLedgerService::clearCustomerAdvanceToReceivable::journal_entries::delete#1
    (GeneralLedgerService.php:1940 — journal_entries rows are append-only; a correction
     is a reversing document, never a row delete)
  …GeneralLedgerService::clearCustomerAdvanceToReceivable::journal_entries::delete#2
    (GeneralLedgerService.php:1943 — …)
```

The r2 fix was **right about the symptom and wrong about the mechanism**. The symptom is real: an unposted DRAFT
clearing entry that survives a failed post discharges nothing, no reconcile consumes it, and nothing links to it
(the converter returns before recording `advance_journal_entry_id`). But `journal_entries` / `journal_lines` are
APPEND-ONLY under the document-per-action contract — a correction is a reversing document, never a row delete — and
the ratchet said so.

## The fix: containment, not compensation

`clearCustomerAdvanceToReceivable()` created the entry inside its own `DB::transaction` and posted **after** that
closure returned. Under an enclosing transaction the inner one is a SAVEPOINT that has already been RELEASED by then,
which is exactly why the draft was durable and needed deleting.

The synchronous post now runs **inside that same closure**. `SynchronousInTransaction` is only reachable with an
enclosing transaction (the guard at the top of the method refuses otherwise), so the closure is always a savepoint:
a refused post unwinds it with `ROLLBACK TO SAVEPOINT` and the entry and its two lines **never existed**. Same
outcome, no DELETE, and the re-read + assert-`Draft` belt that the delete needed in order to be safe is gone with it
— along with the `JournalEntry`/`JournalEntryStatus` re-read, the two `RuntimeException` refusals and the
`Log::warning` cleanup-failure arm (**52 insertions, 83 deletions** — the fix is net-smaller than what it replaces).

**Lock order is unchanged** and still matches the A-D8 record (`… -> Partner -> GL advisory -> …`): the Partner row
lock is taken at the top of the closure and `postEntryNow()` takes the company advisory lock second. Row locks are
held to the OUTER transaction's end regardless of the savepoint, so the enclosing caller's locking profile is
byte-identical to before.

The AfterCommit branch is untouched in behaviour (`if (! $postSynchronously && $user !== null)` replaces the old
`elseif`, same condition).

## The observer-safety question, answered explicitly

The r3 round made the cleanup delete through the MODELS so `JournalLineObserver::deleting()` would fire. That guard
is now **unreachable from this path** — there is no delete here at all. It is not lost: `JournalLineObserver` and
`JournalEntryObserver` are untouched, and
`ClearCustomerAdvanceOrphanDraftTest::test_a_chained_entrys_lines_cannot_be_removed_through_a_model_delete` still
asserts them directly against a real posted, hash-chained entry (line delete AND header delete both raise
`ImmutableJournalEntryException`, nothing removed). That test never depended on the production delete existing.

## Test — the contract is now BOTH facts, and the second is measured on the database

`test_a_failed_synchronous_post_leaves_no_draft_entry_behind_and_rethrows` keeps its production shape (the caller
CATCHES and the enclosing transaction COMMITS — a test that lets the throw escape its own transaction rolls the entry
back for free and passes either way) and now asserts:

1. zero `prepayment_application` entries **and zero lines** survive;
2. **zero DELETE statements** against `journal_entries` / `journal_lines` in the query log.

(2) is asserted from a `DB::listen` capture rather than from the source, so a future re-introduction of a delete on
this path fails here even if it is written differently.

## Red-proof (both guards bind)

Restoring the r2 shape — post after the closure, compensate with model deletes:

| Guard | Result with the delete restored |
|---|---|
| `ClearCustomerAdvanceOrphanDraftTest` | FAILS — query log carries `delete from "journal_lines" where "id" = ?` ×4 |
| `DocumentPerActionBaselineRatchetTest` | FAILS — `NEW document-per-action violations` re-appears |

File restored; both green again.

## Gates

| Gate | Result |
|---|---|
| **DPA guard** (`DocumentPerActionWriteGuardTest` + `DocumentPerActionBaselineRatchetTest`), with the owner-pinned blob | **`OK (8 tests, 119 assertions)`** — **zero new keys** vs the pre-N-6 baseline |
| `phpstan analyse` (whole project, live-DB env) | `[OK] No errors` |
| `pint --test` on the two touched files | `{"result":"pass"}` |
| `deptrac-ratchet` | **PASS**, no boundary regression |
| `feature-lane-manifest-check.php` | **EXIT=0** (no new test class — the assertions were added to an existing one) |
| Affected paths, sqlite (`ClearCustomerAdvanceOrphanDraft`, `DocumentConversionScenario`, `N6PaymentOnUnpostedInvoice`, `RepairPaidNeverPosted`) | `OK (47 tests, 181 assertions)` |
| Same on **PostgreSQL 16** (throwaway `autoerp_test_n6dpa`, dropped) | `OK (47 tests, 181 assertions)` |

**DPA baseline pin used:** `dpa_baseline_seed_commit 9cab548f8…` → blob `1381983d463e6c546535be907d4aa1c7ca94c597`
(pin tag `ci-pin/enforcement-p1-r2`), read from `docs/handoff/progress/enforcement-p1.progress.yaml` exactly as the
CI job does. Without it the ratchet fails CLOSED locally, which is correct and is not a lane red.

## Declared inherited red (not this lane)

`pint --test` over the WHOLE tree fails on two files this lane never touches —
`app/Modules/Inventory/Domain/CountryInventoryDefaults.php` (`fully_qualified_strict_types`, `ordered_imports`) and
`app/Modules/Company/Domain/Company.php` (`unary_operator_spaces`, `not_operator_with_successor_space`,
`phpdoc_align`). `git diff --name-only dev...HEAD` for this lane is exactly the two files listed at the top, and
`git diff HEAD` on those two Inventory/Company files is empty. Inherited from `dev`; left untouched rather than fixed
as scope creep.

## Residuals

None new. The N-6 residual register (R-9 … R-16, R2-M2) is unchanged — this lane changes only HOW a failed clearing
post is undone, not what any caller sees.
