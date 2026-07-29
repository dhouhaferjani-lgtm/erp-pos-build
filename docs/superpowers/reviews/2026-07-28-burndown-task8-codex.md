# Codex adversarial review — burn-down Task 8 (productized Phase ② backfills)

Reviewer: Codex CLI (`codex exec`, read-only sandbox) · Transcribed by the orchestrator,
controller adjudications inline.

- **Round 1** — diff `be95f49bc..6351fd9a3` · 2026-07-29 · **REJECT**
- **Round 2** — cumulative diff `be95f49bc..e0802b5af` · 2026-07-29 · **REJECT**
- **Round 2 fixes** — commit below; focused suite 18 passed (121 assertions)

---

## Round 1 Verdict: REJECT

### Findings requiring fixes (in scope — the new commands/tests)

- **[Important]** `BackfillBanksCommand:80-114` — per-company rollback preview
  overcounts when companies share a tenant-scoped directory; preview must run once per
  tenant batch in a single rolled-back transaction.
- **[Important]** `BackfillBanksCommand:114-134` — output reports creations only;
  `BanksSeeder` also refreshes bic/position/city → dry-run must report updates too.
- **[Important]** `SeedChartsCommand:95-113` — same honesty gap for `is_system`
  promotions and `parent_id` rewrites.
- **[Important]** `BackfillBanksCommand:25-37` — documented fleet form
  `tenants:run treasury:backfill-banks --dry-run` is invalid; correct syntax is
  `--option=dry-run=1` (checklist style). Fix docblocks/report.
- **[Important]** Tests (both) — no failure-surfacing coverage (delegate throw → exit 1
  + tenant-aware log literal + abort marker), no multi-company same-country preview
  accuracy case, chart assertions don't cover the checklist's seven Phase-② codes.
- **[Minor]** `SeedChartsCommand:58-116` — "across 0 companies" exits 0 silently; keep
  exit 0 but assert the stable marker in tests so grep gates can reject it.

### Adjudicated out of scope (controller) — pre-existing platform/seeder behavior

- **[Critical→platform, mitigated]** `stancl tenants:run` discards child exit codes —
  vendor property true of every existing command incl. the pattern command; the deploy
  checklists already mandate grep gates for exactly this. Reviewer's grep-gate strings
  adopted into the command docblocks (see fix round). An exit-aggregating batch
  contract is a platform change — 🎫 ticketed, not Task 8.
- **[Important→ticket]** `BanksSeeder:35-58` custom-bank identity collision can touch
  custom rows — pre-existing Phase-② seeder semantics; changing it alters shipped
  behavior. 🎫 ticketed.
- **[Important→ticket]** TN/FR/Generic chart seeders unconditionally re-issue
  parent-link updates (apply not a strict write-no-op) — pre-existing seeder
  semantics. 🎫 ticketed.
- **[Important→documented]** `--tenant/--all` scoping: `tenants:run --tenants=` already
  provides fleet scoping (pattern parity); FK-boundary preflight unnecessary now the FK
  migration is fleet-applied — ordering assumption documented instead.
- **[Important→documented]** missing-directory skip keeps exit 0 (only TN directory
  ships today; non-TN skip is legitimate) with the stable `skipped (no directory)`
  marker as the grep-gate signal.

### Reviewer-supplied grep-gate strings — adopted verbatim into the docblocks.

---

## Round 2 Verdict: REJECT

Round 2 re-reviewed the cumulative task (`be95f49bc..e0802b5af`) and was explicitly
instructed NOT to re-raise the five round-1 adjudications above. It did not — no
adjudicated finding reappeared.

### Part A — status of the six round-1 in-scope findings

| # | Finding | Codex round-2 status |
|---|---|---|
| 1 | Batched preview | PARTIALLY FIXED — batching correct; rollback not in `finally` |
| 2 | Bank update honesty | PARTIALLY FIXED — reported, but snapshot lossy on NULL/`''` |
| 3 | Chart promote/reparent honesty | **FIXED** — typed snapshot, no double-count, no `updated_at` churn |
| 4 | Fleet-form docs | PARTIALLY FIXED — docblocks correct; the Task 8 *report* still had the invalid form |
| 5 | Test coverage | PARTIALLY FIXED — 4 residual gaps (below) |
| 6 | Exit semantics | **FIXED** |

### Part B — new findings raised by round 2

- **[Important]** `BackfillBanksCommand:97-143`, `SeedChartsCommand:88-121` — the fix
  moved the dry-run snapshots OUTSIDE the protected delegate `try/catch` without adding a
  `finally`. A query failure in either snapshot bypasses `rollBack()` and returns an open
  (on PostgreSQL, aborted) transaction to `tenants:run`'s shared connection, poisoning
  every later tenant in the fleet loop.
- **[Important]** `BackfillBanksCommand:180-209` — serializing nullable columns with
  `?? ''` makes the update diff non-injective: a real `'' → NULL` rewrite reports zero
  updates, in the very honest-counting path finding 2 demanded.
- **[Minor]** `BackfillBanksCommandTest:145-157` — the failure test writes and then
  unconditionally deletes a real repository path (`ZZ.json`); `finally` does not cover
  SIGKILL, and it would delete a future legitimate `ZZ.json`.
- **[Minor]** both snapshots eagerly `get()` every relevant row twice, holding before and
  after arrays simultaneously.

### Part C — verified non-issues (no action)

- `ChartOfAccountsService`'s nested transaction uses Laravel **savepoints** and does not
  commit the outer preview transaction; the command `break`s after a delegate failure
  rather than continuing inside an aborted PostgreSQL transaction. `BanksSeeder` has no
  inner transaction. → the batched-preview design is sound.
- The container-bound throwing chart service does not leak between tests (Laravel flushes
  the application in teardown).
- Both `@cross-tenant-by-design` annotations remain intact.

---

## Round 2 fix round — controller disposition

Every round-2 finding was independently verified against the code before acting; two
required correcting my own first attempt at a regression test (see finding 2).

1. **[Important] Missing `finally` — FIXED (both commands).** The company loop is now
   wrapped in `try { … } finally { if ($dryRun) rollBack(); }`, so a throw from a snapshot
   read or from per-company reporting can no longer leak an open/aborted transaction into
   the fleet loop. **Honest limitation:** this is defense-in-depth with no dedicated test —
   exercising it requires injecting a query failure into the snapshot read specifically,
   which would test the mock more than the command. The change is a pure control-flow
   guard, reviewed by inspection.

2. **[Important] Non-injective snapshot — FIXED with a genuine RED.** `snapshot()` now
   returns a **typed tuple** (`array{bic: string|null, position: int, city: string|null}`)
   instead of a `sprintf('%s|%s|%s', $bic ?? '', …)` string, matching the chart command's
   already-correct approach; `diff()`'s strict `!==` then distinguishes `NULL` from `''`.

   *Correction to my own work:* my first regression test drifted `city` to `''` on a TN
   row and asserted `1 updated` — but it **passed against the unfixed code**, because every
   row in the shipping `TN.json` has a non-empty `bic` and `city`, so the refresh was
   `'' → 'Tunis'`, which differs under either encoding. The collapse is only reachable when
   the *canonical* value is NULL. The test was rewritten to seed a synthetic `ZY.json`
   whose canonical `bic` is `null`, drift it to `''`, and re-apply:

   ```
   with fix:    1 passed (8 assertions)
   without fix: 1 failed — Bank directory backfill: 0 created, 1 updated  (reported 0 updated)
   ```

   Note this also bounds the finding's real-world severity: with today's shipping directory
   the collapse is **unreachable**; the fix is protection against a future directory file
   (or admin edit) that introduces a NULL `bic`/`city`.

3. **[Important] Stale fleet form in the report — FIXED.** `.superpowers/sdd/task-8-report.md`
   line 63 still read `tenants:run <cmd> [--dry-run]`, contradicting the same file's
   "fixed everywhere" claim. Now `[--option=dry-run=1]` with the reason inline. Repo-wide
   grep confirms the only remaining occurrence of the invalid form is this review record
   quoting the finding itself.

4. **[Important] Test gaps — FIXED (4 of 4).**
   - *Ambiguous markers:* `expectsOutputToContain('0 created')` also matches `'10 created'`.
     Both idempotency tests now assert the FULL summary line.
   - *Loose log assertion:* `Mockery::type('array')` accepted any context. Both failure
     tests now assert the presence of `tenant_id`, `company_id`, `country_code`,
     `exception_class`, `exception_message` — dropping a key now fails.
   - *Non-discriminating Generic test:* it asserted only `413`/`416`, which the **TN chart
     also defines**, so selecting the wrong locale would have passed. Verified against the
     seeders that TN-only = `5312/5313/5314/6275/43666` and Generic-only =
     `5112/5113/5114/44566`; the test now asserts Generic-only present AND TN-only absent.
   - *Uncovered reporting paths:* added dry-run bank update reporting, and chart
     promotion/reparent reporting in both apply and dry-run (drifting `is_system` false and
     clearing a `parent_id`, then asserting `0 created, 1 promoted, 1 reparented`).

5. **[Minor] `ZZ.json` fixture — HARDENED, not eliminated.** Both synthetic-directory tests
   now `assertFileDoesNotExist()` before writing, so a future real `ZZ.json`/`ZY.json` makes
   the test fail loudly instead of being overwritten and deleted. Residue after SIGKILL
   remains possible — accepted: the path is a reserved country code that ships no file, and
   the alternative (making the directory root injectable) is a production-code change to
   suit a test.

6. **[Minor] Eager `get()` snapshots — ACCEPTED, not changed.** Real sizes are 32 bank rows
   per tenant and a chart in the low hundreds of accounts, over 3-4 narrow columns. The
   memory ceiling Codex describes needs an implausibly large imported chart; adding chunked
   diffing would trade real complexity for a hypothetical. Revisit if a tenant ever imports
   a chart large enough to matter.

### Fix-round gates

```
php artisan test tests/Feature/Treasury/BackfillBanksCommandTest.php \
                 tests/Feature/Accounting/SeedChartsCommandTest.php
→ Tests: 18 passed (121 assertions)          [was 14 passed / 83 assertions]

pint --test <2 commands + 2 tests>            → {"result":"pass"}
phpstan analyse <2 commands + 2 tests>        → [OK] No errors
ConsoleCommandTenantContextTest               → still 7 unclassified (pre-existing red;
                                                 includes the pattern command; neither
                                                 BackfillBanks* nor SeedCharts* appears)
```
