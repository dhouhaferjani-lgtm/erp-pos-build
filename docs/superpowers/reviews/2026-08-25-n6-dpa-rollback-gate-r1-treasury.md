# N-6 DPA ROLLBACK MICRO-LANE — TREASURY/GL GATE r1 (VERIFY-ONLY, BY EXECUTION)

Lane `fix/n6-advance-clearing-dpa-rollback`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n6-dpa-rollback`,
HEAD `a0a8ef0c2` (fix+test `12867e5fc`, handback `e859ad622`). Reviewed diff = `git diff dev...HEAD`
(merge-base `04ddf0266` = `HEAD^2`, so the diff is against the dev tip the lane merged).

**VERDICT: spec ✅ + quality APPROVED-with-notes — merge-blocking: NO**

Nothing was merged or modified in the lane. All tampering was done in a throwaway worktree
(`scratchpad/n6tamper`, its own cloned `vendor`), all PG work in a throwaway DB `autoerp_test_n6dpa` (dropped).

---

## 1. What the lane actually changes (read, not trusted)

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1808-1926`
`clearCustomerAdvanceToReceivable()` — the `SynchronousInTransaction` post moved from AFTER the creating
`DB::transaction` closure to INSIDE it (`postEntryNow($entry, $user, $currencyCode)` at :1922), and the whole r2/r3
compensation arm (re-read + assert-Draft + per-line model deletes + header delete + `Log::warning`) is deleted.
`$postSynchronously = $mode === PostingMode::SynchronousInTransaction` (:1817); the old `elseif ($user !== null)`
becomes `if (! $postSynchronously && $user !== null)` (:1928) — **same condition, AfterCommit branch unchanged**.
The `DB::transactionLevel() < 1` refusal at :1785 is untouched, so the closure is always a SAVEPOINT in this mode.

No money arithmetic added or altered. `$scale = $this->scaleResolver->getScaleSafe($currencyCode, 3)` at :1798 is
untouched — **no no-arg `getScale()`, no float, no `number_format`** introduced (rule 19 clean).
No new cross-module import (rule 6 clean). No new table, no migration.

Test: `apps/api/tests/Feature/Accounting/ClearCustomerAdvanceOrphanDraftTest.php` — adds a `DB::listen` capture
(:60-65) and a second contract assertion (:161-177): zero `DELETE … journal_entries|journal_lines` statements, on
top of the existing zero-rows assertions. Production shape preserved (the caller CATCHES, the enclosing transaction
COMMITS — a throw that escapes the test's own transaction would pass with or without the fix).

## 2. Item 1 — refused post rolls back with zero rows and zero DELETEs; success path unchanged

| Check | Engine | Result |
|---|---|---|
| `ClearCustomerAdvanceOrphanDraftTest` (2 tests) | sqlite | **OK (2 tests, 10 assertions)** |
| same | **PostgreSQL 16** (`autoerp_test_n6dpa`, 127.0.0.1:5433) | **OK (2 tests, 10 assertions)** |
| N-6 replay + conversion + posting + reversal set — `ClearCustomerAdvanceOrphanDraft`, `GLIntegration`, `ReverseCustomerAdvanceJournalEntry`, `DocumentConversionScenario`, `RepairPaidNeverPostedDocumentsCommand`, `DocumentPostingService`, `N6PaymentOnUnpostedInvoice`, `AdvanceReversalRefusalsAndCeiling`, `AdvanceReversalGlShape`, `AdvanceReversalReportingAndApi` | sqlite | 127 tests, **11 failures — ALL INHERITED** (see §6) |
| same 10 files | **PostgreSQL 16** | 127 tests, **1 error — INHERITED** (see §6) |

The N-6 replay itself is `tests/Feature/Treasury/N6PaymentOnUnpostedInvoiceTest.php:121-146`
(`test_posting_a_prepaid_invoice_clears_419_recognises_revenue_and_settles_the_lifecycle`): 419 → `0.000`, 411 →
`0.000`, invoice `Paid` + sealed, ≥1 `prepayment_application` entry. **Green on both engines.** The
single-clearing regression (`advance_cleared_at` stamped only when the entry is actually `Posted`,
`SalesOrderToInvoiceConverter.php:685-694`) is exercised by `DocumentConversionScenarioTest` — green on both engines.

**Lock ORDER unchanged — verified by reading, not by the comment.** Inside the closure the order is Partner row lock
(`GeneralLedgerService.php:1826-1830`) → tenant numbering advisory + company chain advisory
(`generateEntryNumber`, :5727-5748) → `sealAndPersistEntry` takes the same pair again. Pre-lane the sequence was
byte-identical, only split across the savepoint boundary. No new lock, no reordering. (But see finding [I-1] on the
comment's *retention* claim.)

## 3. Item 2 — savepoint semantics under PG, and the outer-rollback contract

Written a gate probe (throwaway worktree only, `tests/Feature/Accounting/N6GateProbeTest.php`) that drives the REAL
`DocumentPostingService::post()` on a confirmed, fully-prepaid invoice and unbalances the clearing entry between
line creation and the post:

* the refusal propagates **typed and unswallowed** — `assertInstanceOf(UnbalancedJournalEntryPostException::class)`;
* the invoice stays `Confirmed` with `fiscal_hash === null`;
* `journal_entries` and `journal_lines` counts are **identical to before the post** — the invoice's own GL entry is
  rolled back with the clearing;
* zero `DELETE` against the journal tables.

**Green on sqlite AND on PostgreSQL 16 (2 tests, 11 assertions each).**

**STATED CONTRACT: a refused clearing DOES roll back the outer posting, including the invoice seal — and that is the
intended contract, not an accident.** It is the documented design at
`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:160-165` ("Runs INSIDE the posting
transaction: if the clearing entry cannot be written the whole posting is refused rather than leaving a sealed
invoice next to a stranded 419"), and the lane does not change it: pre-lane the compensation arm re-threw
`$postFailure` unconditionally. Proven above by the probe.

The **HTTP mapping is a pre-existing residual, not a lane regression**: `InvoiceController.php:739-741` catches only
`\DomainException` → 422 `POSTING_FAILED`, and `UnbalancedJournalEntryPostException extends \InvalidArgumentException`
(`UnbalancedJournalEntryPostException.php:56`), which the repo itself documents as an "unmapped 500"
(`UnpostableDocumentGlException.php:38`). Identical before and after this lane. Flagged as [M-1], out of lane scope.

**Second-order behaviour the savepoint changes (an improvement, stated for the record).** Only the
`SalesOrderToInvoiceConverter` path CATCHES a non-balance clearing failure and continues
(`SalesOrderToInvoiceConverter.php:614-669`). Pre-lane, a PDO-level post failure there left the outer PG transaction
ABORTED (25P02) and the conversion died at the next statement; now the savepoint contains it and the documented
downgrade-to-payload-note actually works. The advance correctly stays OPEN (`advance_cleared_at` null, :685-694), so
`DocumentPostingService::post()` clears it later. No double-clear window opened.

**afterCommit ordering under the new nesting — probed, not assumed.** `postEntryNow` registers the
`JournalEntryPosted` dispatch via `DB::afterCommit` (`GeneralLedgerService.php:3680-3682`), now at savepoint depth.
Probe 2 asserts the event fires **zero times before the outer commit and exactly once after**. Green on both engines.
Mechanism confirmed in `vendor/laravel/framework/src/Illuminate/Database/DatabaseTransactionsManager.php:67-104`
(nested commit STAGES callbacks up to the parent) and `:128-150` (savepoint rollback REJECTS pending callbacks).
Laravel v12.58.0.

## 4. Item 3 — DPA guard, static gates

| Gate | Command / evidence | Result |
|---|---|---|
| DPA guard (ratchet + write guard), owner-pinned blob | `DPA_BASELINE_PROTECTED_BLOB=1381983d4…` (derived live: `git rev-parse 9cab548f8…:apps/api/tests/Architecture/baselines/document-per-action-baseline.json` == the YAML mirror) | **OK (8 tests, 119 assertions)** |
| Zero new baseline keys | `git diff dev…HEAD -- …/document-per-action-baseline.json` → **empty**; baseline stays 33 keys | ✅ |
| Observer-safety tests untouched | `test_a_chained_entrys_lines_cannot_be_removed_through_a_model_delete` unchanged and green; no test anywhere referenced the removed cleanup strings (`grep` for "Could not remove the unposted" / "refusing to clean up clearing entry" → 0 hits in `app/` and `tests/`) | ✅ |
| PHPStan (whole project, live-DB env) | `[OK] No errors` (3120 files) | ✅ |
| Pint on the two touched files | `{"result":"pass"}` | ✅ |
| deptrac ratchet | `RESULT: PASS — no boundary regression` (183/183 held) | ✅ |
| Feature-lane manifest check | `EXIT=0`, 1438 classes / 74 groups | ✅ |
| The guard test actually runs in CI | manifest lane `treasury-spine-pgsql/feature-accounting`, selector `./vendor/bin/phpunit tests/Feature/Accounting`, `runs_on_pr_dev: true`, **no execution gate** — whole directory, so the new assertion is picked up automatically | ✅ |

## 5. Item 4 — red-proof (both guards bind)

Restored dev's pre-lane `GeneralLedgerService.php` (`git checkout a0a8ef0c2^2 -- …`) into the throwaway worktree,
keeping the lane's test file:

* `ClearCustomerAdvanceOrphanDraftTest` → **FAILS**, exact output:
  `delete from "journal_lines" where "id" = ?` ×4 + `delete from "journal_entries" where "id" = ?`.
* `DocumentPerActionBaselineRatchetTest` → **FAILS**: `NEW document-per-action violations` naming
  `…clearCustomerAdvanceToReceivable::journal_entries::delete#1` (GeneralLedgerService.php:1940) and `#2` (:1943).

Both green again on restore. **This also independently confirms the lane fixes a LIVE red on `dev`** — with dev's
file in place the DPA ratchet is failing today; the baseline never carried those two keys.

## 6. Inherited reds, attributed (NOT lane-caused)

* **sqlite, 11 failures, all in `tests/Feature/Treasury/AdvanceReversalGlShapeTest.php`** — money-scale string
  artifacts (`'1000'` vs `'1000.000'`, `'90'` vs `'90.000'`). **Reproduced with dev's `GeneralLedgerService.php`
  restored (11/11 identical)** and **all green on PostgreSQL**. sqlite-only inherited red.
* **PG, 1 error, `tests/Feature/Document/DocumentPostingServiceTest.php:353`** —
  `'confirmed_by' => 'user-1'` hardcoded non-UUID → `SQLSTATE[22P02] invalid input syntax for type uuid`. The lane
  does not touch that file (rule 17 violation, pre-existing).
* The handback's declared whole-tree pint red (`Company.php`, `CountryInventoryDefaults.php`) has since been fixed on
  local `dev` by `a011a73dc`; it will disappear on re-merge.

## 7. Findings

**[IMPORTANT] `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1912-1919` — the comment's
lock-retention claim is false on the failure path.** It states, unconditionally, that "Row locks are held to the
OUTER transaction's end regardless of this savepoint, so the enclosing caller sees exactly the locking profile it did
before." Probed against the real PG 16 at 127.0.0.1:5433 with two sessions:

| case | other session's attempt while the outer txn is still open | verdict |
|---|---|---|
| lock taken INSIDE a savepoint that is **ROLLED BACK** | `SELECT … FOR UPDATE NOWAIT` **succeeded**; `pg_try_advisory_xact_lock` → **`t`** | **released early** |
| lock taken BEFORE the savepoint (control) | `ERROR: could not obtain lock on row`; try_lock → `f` | retained |
| lock taken INSIDE a savepoint that is **RELEASED** (the success path) | `ERROR: could not obtain lock on row`; try_lock → `f` | retained |

So on the SUCCESS path the claim holds (row 3) and the lock profile really is identical to pre-lane; on the FAILURE
path the Partner row lock **and** the tenant-numbering / company-chain advisory locks taken inside the closure are
released early. *Why it is not merge-blocking:* the only caller that survives a refusal is
`SalesOrderToInvoiceConverter`'s non-balance catch, and at that point nothing was written — the advance is still
un-consumed, so a concurrent reversal racing in is legitimate and the ceiling arithmetic it serializes is unaffected.
No money defect is reachable. *Fix:* narrow the sentence to the success path ("held to the outer transaction's end
once this savepoint is RELEASED; a rolled-back savepoint releases what it took, which is safe here because nothing
was written"), so the A-D8 lock-order record is not left asserting something a future change could rely on.

**[MINOR] `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php:739-741` — a clearing balance
refusal surfaces as 500, not the typed 422.** `catch (\DomainException)` cannot see
`UnbalancedJournalEntryPostException` (an `\InvalidArgumentException`, `UnbalancedJournalEntryPostException.php:56`).
Pre-existing and already self-documented as "unmapped 500" at `UnpostableDocumentGlException.php:38`; **unchanged by
this lane** (pre-lane the compensation arm re-threw the same object). Ticket separately; do not fold into this lane.

**[MINOR] lane is 3 commits behind local `dev`** (`94f29795c`, `adbc00574`, `a011a73dc`). Re-merge `dev` before
promotion; `a011a73dc` also clears the declared pint red.

**[NOTE] `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1913-1915`** — "the Partner row
lock is taken at the top of this closure and `postEntryNow()` takes the company advisory lock second" is imprecise:
`generateEntryNumber()` (:5732-5736) already takes the tenant numbering key and the company chain key before
`postEntryNow` runs. The ORDER assertion is still correct; only the enumeration is incomplete.

## 8. Evidence trail

Test process discipline honoured: never more than one PHPUnit process at a time, never the full suite, always by
path. Throwaway PG DB `autoerp_test_n6dpa` created and dropped. Tamper worktree removed; the lane worktree was never
written to (`git status --short` clean at exit).
