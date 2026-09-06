# Handback — PR #211 PG regression (`PaymentRefundRefusalTest`) + 4 flake-shaped reds

**Date:** 2026-09-06
**Lane:** `lane/rh-pr211-pg-refusal` (worktree `.worktrees/rh-pr211-pg`, based on dev `5a241c760`)
**CI evidence:** run 33972668125, tip `189fe7d8a`, job *Treasury Spine — PG-only invariants (PHPUnit + PostgreSQL)*
**Local PG:** `autoerp_test_pg211` on `127.0.0.1:5433` (dropped at the end of the session)

---

## 1. Verdict

| Question | Answer |
|---|---|
| Does the refusal path (`PaymentRefundService::assertRefundableType()`) leak a write? | **No.** Proven by probe — the single `repository_movements` row is byte-identical (same `id`) before and after the refund call, and belongs to a **foreign company**. |
| Who writes the row the assertion tripped on? | Neither the fixture nor the guard: it is **committed cross-class pollution** from `AdvanceReversalConcurrencyTest`, which `DB::commit()`s from a `pcntl_fork()` child and so escapes `RefreshDatabase`'s wrapping transaction. |
| Fix | **Test-only.** `assertDatabaseCount('repository_movements', 0)` (a global, cross-company claim this test does not own) → a **delta** + a company-scoped zero. |
| Production change | **None.** The guard is correct and already refuses before any write. |
| Falsifiability kept? | **Yes** — proven twice in scratch (see §5). |

---

## 2. Root cause, with file:line evidence

### 2.1 The assertion that failed

`apps/api/tests/Feature/Treasury/PaymentRefundRefusalTest.php:334` (pre-fix)

```php
$this->assertDatabaseCount('repository_movements', 0);
```

This is an **absolute, global, cross-company** claim. The test owns one company; the table is process-wide.

### 2.2 The CI step runs the whole directory in ONE process

`.github/workflows/ci.yml:1364-1365`

```yaml
      - name: PG-only invariants — Treasury Feature suite
        run: ./vendor/bin/phpunit tests/Feature/Treasury
```

1355 tests, one PHP process (`Configuration: apps/api/phpunit.xml`, `DB_CONNECTION=pgsql` supplied by the job-level `env:` block at `.github/workflows/ci.yml:1305-1318`, which overrides the non-forced `<env name="DB_CONNECTION" value="sqlite"/>` in `phpunit.xml:44`).

### 2.3 The polluter

`apps/api/tests/Feature/Treasury/AdvanceReversalConcurrencyTest.php` — the only `pcntl_fork()` test in the directory:

- `:249` `DB::disconnect();` in the forked child
- `:252` `DB::beginTransaction();`
- `:269` `DB::commit();`

The child's commit lands **outside** `RefreshDatabase`'s wrapping transaction, so the parent's fixture — created before the fork — is committed for real. Its cash fixture writes one movement through the spine at `:410-430` (`MovementSourceType::OpeningBalance`, `'A10(i) fixture opening balance'`).

### 2.4 Why the leak is permanent for the rest of the process

`repository_movements` is append-only on PostgreSQL — `apps/api/database/migrations/tenant/2026_07_08_100200_create_repository_movements_immutability.php:36-47` raises on `DELETE`, `UPDATE` and `TRUNCATE`. Nothing later in the process can clear the row (the model repeats the guard at `app/Modules/Treasury/Domain/RepositoryMovement.php:72-79`). `RefreshDatabase` only re-migrates once per **process**, so the row survives to the last test.

### 2.5 This is a pre-existing, directory-wide condition — not a PR #211 defect

In the same CI step, the identical ambient `+1` breaks ~20 unrelated classes; the failure text is the tell (`count of 0. Entries found: 1.` / `count of 1. Entries found: 2.`):

`AdvanceReversalReportingAndApiTest`, `DeferredTenderGuardsTest` (5), `DeferredTenderPaymentTest` (5), `InstrumentLifecycleReceiveTest`, `InstrumentRemittanceServiceTest`, `PaymentReversalDocumentTest` (3), `PaymentReversalRefusalTest` (5 data sets), … and then `PaymentRefundRefusalTest` (2).

Step totals confirm the delta is exactly this PR's two tests and nothing else:

| Run | Tests | Errors | Failures |
|---|---|---|---|
| baseline `4d5b8812e` | 1341 | 33 | 97 |
| B `189fe7d8a` | 1355 | 33 | **99** |

PR #211 added a class that (correctly) asserts "no cash moved", so it simply **joined the existing victim list**.

---

## 3. Reproduction (local, CI conditions)

Command shape used throughout (CI's `phpunit.xml` + CI's env, private DB):

```
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 \
DB_DATABASE=autoerp_test_pg211 DB_CENTRAL_DATABASE=autoerp_test_pg211 \
DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret ./vendor/bin/phpunit <target>
```

**(a) The file alone — GREEN on both engines (pre-fix), i.e. isolation cannot see the bug**

```
# SQLite
OK (8 tests, 33 assertions)          Time: 00:17.718
# PostgreSQL
OK (8 tests, 33 assertions)          Time: 01:42.711
```

**(b) The polluter alone, then inspect the database after the process exits**

```
./vendor/bin/phpunit tests/Feature/Treasury/AdvanceReversalConcurrencyTest.php
OK (1 test, 12 assertions)           Time: 00:59.425

psql> select id, direction, amount, source_type, occurred_at from repository_movements;
 id              | 9edb551d-e829-4557-98a2-92c0ae641a1e
 direction       | in
 amount          | 1000.000
 source_type     | opening_balance
 idempotency_key | opening_balance:…:opening-AXzsak
 occurred_at     | 2026-09-06 18:51:45+00

psql> -- whole leaked fixture, committed:
 companies 1 | payments 1 | journal_entries 2 | payment_repositories 1 | repository_movements 1 | documents 1
```

**Control** — a non-fork sibling of the same family (`AdvanceReversalGlShapeTest`, 12 tests, OK) leaves `companies 0 | payments 0 | repository_movements 0`. The fork is the differentiator.

**(c) Two classes, one process — the exact CI failure in 77 s**

```
.FF......                                     9 / 9 (100%)
1) PaymentRefundRefusalTest::test_full_refund_of_a_supplier_payment_refuses_and_writes_nothing
Failed asserting that table [repository_movements] matches expected entries count of 0. Entries found: 1.
   …/PaymentRefundRefusalTest.php:334  /  :154
2) PaymentRefundRefusalTest::test_partial_refund_of_a_supplier_payment_refuses_and_writes_nothing
Failed asserting that table [repository_movements] matches expected entries count of 0. Entries found: 1.
   …/PaymentRefundRefusalTest.php:334  /  :167
FAILURES! Tests: 9, Assertions: 45, Failures: 2.
```

Identical assertion, identical two lines as CI (`:334` helper / `:154` and `:167` call sites).

---

## 4. The probe — who wrote the row?

Temporary instrumentation in `assertRefusedAndNothingWritten()` (dump the table immediately before and after the act, plus this test's own `company_id`), run under the polluted 2-class process, then removed:

```
[PROBE] this test company_id=4b76554e-9bf0-4c33-8778-b96204cde049
[PROBE] movements BEFORE act: [{"id":"a69e2eb4-0272-4826-8a0d-69f4394d7dd3","company_id":"01a07814-fb1f-7059-b91e-23b7bb773310",
                               "source_type":"opening_balance","amount":"1000.000","created_at":"2026-09-06 18:57:22+00"}]
[PROBE] movements AFTER  act: [{"id":"a69e2eb4-0272-4826-8a0d-69f4394d7dd3","company_id":"01a07814-fb1f-7059-b91e-23b7bb773310",
                               "source_type":"opening_balance","amount":"1000.000","created_at":"2026-09-06 18:57:22+00"}]
[PROBE] this test company_id=897f3169-51fc-4571-9c24-9c875f890a3f
… same single row, same id, before and after …
```

Three conclusions, all direct:

1. **The refusal path writes nothing** — same row `id`, same `created_at`, count unchanged across the call.
2. The row's `company_id` (`01a07814-…`) is **not** either test's company (`4b76554e-…`, `897f3169-…`) — it is the leaked concurrency-test company.
3. `source_type = opening_balance`, amount `1000.000` — the `AdvanceReversalConcurrencyTest` fixture, not a refund artefact.

So: **test-only fix**, the guard stays as it is.

---

## 5. The fix

`apps/api/tests/Feature/Treasury/PaymentRefundRefusalTest.php` (only file changed):

- new import `App\Modules\Treasury\Domain\RepositoryMovement`;
- `$movementsBefore = RepositoryMovement::query()->count();` captured alongside the existing `$paymentsBefore` / `$entriesBefore` baselines;
- `assertDatabaseCount('repository_movements', 0)` replaced by **two** assertions plus a comment recording the mechanism (polluter file:line, the append-only trigger, the CI run id):

```php
self::assertSame(
    0,
    RepositoryMovement::query()->where('company_id', $this->company->id)->count(),
    'no cash movement may exist for this payment\'s company',
);
self::assertSame(
    $movementsBefore,
    RepositoryMovement::query()->count(),
    'the refusal wrote no repository movement anywhere',
);
```

This is **stronger** than the old global zero for what the test claims: it pins both "no movement for this payment's company" (absolute, and immune to a foreign company's ambient row) and "no movement anywhere" (delta).

### Falsifiability — proven twice, in scratch, then reverted

1. **Guard neutralised** (`PaymentRefundService.php:160` and `:350` `assertRefundableType()` commented out), file on SQLite:
   `FAILURES! Tests: 8, Assertions: 11, Failures: 4` — both refusal tests red plus both HTTP-envelope tests (`Expected response status code [422] but received 201`).
2. **The movement assertion itself has teeth**: with the guard still neutralised and the `self::fail()` removed so execution reaches the write assertions, hoisting the movement checks first:
   `PROBE company-scoped movement zero — Failed asserting that 1 is identical to 0.` (both tests). The unguarded supplier refund *does* write exactly one movement for this test's own company, and the new assertion catches it.

Both scratch edits were restored from byte copies; `git status` shows one modified file (see §7).

---

## 6. Verification runs (verbatim)

**SQLite — file (post-fix)**

```
Configuration: …/apps/api/phpunit.xml
........                                                            8 / 8 (100%)
Time: 00:09.699, Memory: 165.00 MB
OK (8 tests, 35 assertions)
```

**PostgreSQL — file (post-fix)**

```
........                                                            8 / 8 (100%)
Time: 00:27.933, Memory: 165.00 MB
OK (8 tests, 35 assertions)
```

**PostgreSQL — under the polluter (the condition that was red)**

```
.........                                                           9 / 9 (100%)
Time: 01:38.750, Memory: 165.00 MB
OK (9 tests, 47 assertions)
```

**PostgreSQL — the FULL CI step (`./vendor/bin/phpunit tests/Feature/Treasury`)**

```
Configuration: …/apps/api/phpunit.xml            (CI env: DB_CONNECTION=pgsql, private DB autoerp_test_pg211)

EE..............................F...................FFF.F..F.   61 / 1355 (  4%)
…
Time: 44:17.220, Memory: 503.00 MB
Tests: 1355, Assertions: 5599, Errors: 33, Failures: 97, PHPUnit Deprecations: 39.
```

Read against the two CI runs of the same step:

| Run | Tests | Errors | Failures | `repository_movements` count-assertion failures |
|---|---|---|---|---|
| CI baseline `4d5b8812e` | 1341 | 33 | 97 | 21 |
| CI B `189fe7d8a` (red) | 1355 | 33 | **99** | **23** |
| **local, post-fix** | **1355** | **33** | **97** | **21** |

The two extra failures and the two extra ambient-count failures are gone, and nothing else moved:
`grep -c PaymentRefundRefusalTest full-step-pg.log` → **0**. The step is back at exact baseline parity.

The 33 errors / 97 failures that remain are the **pre-existing** directory-wide reds (same count, same classes as the baseline run) — see §9.

**Static gates (touched file)**

```
./vendor/bin/pint --test tests/Feature/Treasury/PaymentRefundRefusalTest.php   → {"result":"pass"}
./vendor/bin/phpstan analyse tests/Feature/Treasury/PaymentRefundRefusalTest.php → [OK] No errors   (level 8)
```

---

## 7. Flake characterisation (no code changed)

All four were run **by path, 3×, on the engine of the job that reported them**. Every run passed; none reproduced in isolation.

| Test | Engine | Run 1 | Run 2 | Run 3 | First assertion when it failed **in CI** | Mechanism |
|---|---|---|---|---|---|---|
| `ReconcileTreasuryTest::test_one_millime_scale_mismatch_is_tolerated_and_does_not_false_freeze` | SQLite | PASS (4.2 s) | PASS (3.3 s) | PASS (3.5 s) | `ReconcileTreasuryTest.php:808` — *"A 1-millime scale mismatch must be tolerated, not frozen." Failed asserting that 1 is identical to 0* (the command exited 1 = froze) | **Order/ambient-dependent, not time-dependent.** The test does not assert on its own rows: it shells out to the whole `treasury:reconcile` command (`:243`, tenant-scoped) and asserts the process **exit code**, so any repository state another class leaves reachable inside the run changes the verdict. It did not fail in my local PG full-directory run either (`grep ReconcileTreasuryTest full-step-pg.log` → no hits), matching the report that it passes on PG. |
| `TreasuryAccountChargeBridgeTest` (whole class, 11 tests) | PostgreSQL | PASS 11/11 (19.7 s) | PASS 11/11 (24.7 s) | PASS 11/11 (24.2 s) | `:83` `assertSame($this->tenantId, $entry->tenant_id)` → got a **foreign** tenant's uuid; then `:159` `assertSame(0, JournalLine::query()->count())` → **30**; `:202` `assertSame(0, JournalEntry::query()->count())` → **15**; `:530` `assertCount(1, $lines)` → 0 | **Same class of bug as the regression above: absolute, un-scoped global counts in a shared process.** `JournalEntry::query()->firstOrFail()` (`:82`) picks up whatever another class left committed; the "idempotency_conflict:line_count" error is downstream of that. |
| `UnitsInvariantTest` — "visibility census failure logs the exception and tenant context" (and 6 sibling methods) | PostgreSQL | PASS 8/8 (24.5 s) | PASS 8/8 (20.7 s) | PASS 8/8 (21.1 s) | Class-first failure `:29` `assertSame(0, DB::table('units')->count())` → **19**; the spy failure is at `:130`/`:145` — *"Method error(…) from Mockery_2_…_LogManager should be called at least 1 times but called 0 times"* | **Not a Mockery/queued-listener flake — a downstream symptom.** Ambient `units` rows make the migration under test take its already-provisioned branch, so it never logs and the spy is legitimately never called. The root assertion is the absolute `units` count at `:29`. |

**Conclusion:** none of the four is a genuine non-determinism (time, random seed, queue timing). All four are the **same failure mode as the regression this lane fixed** — an absolute, process-global assertion colliding with rows another test class left behind in a shared PHPUnit process. They are silent in isolation by construction, so re-running them by path can never reproduce them; only the whole-directory / whole-filter process does. Per the brief they were characterised, **not fixed**.

---

## 8. CI ticket — the PG-only jobs abort at the first failure (FILED, NOT APPLIED)

**Job `treasury-spine-pgsql`** — three sequential steps, `.github/workflows/ci.yml:1364-1371`:

```yaml
      - name: PG-only invariants — Treasury Feature suite
        run: ./vendor/bin/phpunit tests/Feature/Treasury

      - name: PG-only invariants — Accounting Feature suite
        run: ./vendor/bin/phpunit tests/Feature/Accounting

      - name: PG-only invariants — Treasury Unit suite
        run: ./vendor/bin/phpunit tests/Unit/Treasury
```

A failing step ends the job, so steps 2 and 3 never execute. Confirmed in the logs of **both** runs: the job output contains exactly **one** `PHPUnit 11.5.55 …` banner (run B log line 752, and the same in the baseline log). `tests/Feature/Accounting` and `tests/Unit/Treasury` have therefore **never been observed on PostgreSQL** in either run — the gate certifies a third of what its name claims.

**Job `backend-test-pgsql`** — one `run: |` block (`.github/workflows/ci.yml:1047`) containing three `php artisan test -c phpunit-pgsql.xml` invocations (`:1116`, `:1143-1145`, `:1128-1132` region). GitHub runs `run:` under `bash --noprofile --norc -eo pipefail`, so the first failing invocation aborts the block. The giant `--filter` invocation is red, so `CompanyPaymentRepositoryProvisioningTest`, `BackfillCompanyPaymentRepositoriesMigrationTest` and the four `DeliveryNote*` classes never run on PostgreSQL either — the very classes whose comments say this lane is *"the only live gate that can run them"*.

**Proposed fix (one line per subsequent step — do not apply here):**

```yaml
      - name: PG-only invariants — Accounting Feature suite
        if: ${{ !cancelled() }}          # ← add
        run: ./vendor/bin/phpunit tests/Feature/Accounting
```

…and the same on the Treasury Unit step. `!cancelled()` (not `continue-on-error`) is the right operator: every step still runs and every red still fails the job — only the abort-on-first-red is removed.

For `backend-test-pgsql` the same one-liner cannot be applied inside a single `bash -e` block without hiding failures (dropping `-e` would make the step's exit code that of the *last* invocation, turning red into green). That block must first be **split into one step per invocation**, then each subsequent step gets the same `if: ${{ !cancelled() }}`.

---

## 9. Residual reds / caveats

**Still red (pre-existing, untouched by this lane):** the Treasury Spine PG step remains at **33 errors / 97 failures** — identical to the baseline run, including the 21 ambient `repository_movements` count-assertion failures across ~20 classes and the `ClosedFiscalPeriodException` errors in `AcquirerFeeServiceTest` and friends. This lane did not attempt them: they predate PR #211, and fixing them is a directory-scale lane of its own (see the recommendation below).

**Recommended follow-up lanes (not opened here):**

1. **Contain the polluter.** `AdvanceReversalConcurrencyTest`'s forked child commits into the shared test database. Options, in order of preference: run the class in its own process (`#[RunTestsInSeparateProcesses]` / `#[RunInSeparateProcess]`), or give the fork proof its own throwaway database. Cleaning up afterwards is *not* an option on PostgreSQL — the append-only trigger rejects `DELETE` and `TRUNCATE` on `repository_movements`.
2. **Ban absolute global counts in shared-process suites.** `assertDatabaseCount('<table>', 0)` and `Model::query()->count()` with no company/tenant scope are load-bearing lies in a 1355-test process. A ratchet over `tests/Feature` would surface every instance; the three flake classes above are all in this set.
3. **Un-hide the two dark PG steps** — §8.

**Housekeeping:** the private PostgreSQL database `autoerp_test_pg211` was dropped at the end of the session. No scratch edit survives: `git status` on the lane shows only `apps/api/tests/Feature/Treasury/PaymentRefundRefusalTest.php` plus this document.
