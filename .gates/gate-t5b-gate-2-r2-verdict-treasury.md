REJECT

Reviewed `git diff e06d2a831...abccafa6f` (single commit) in full, plus the current state of the service, both controllers, the parser, the row mapper, all four aggregate migrations, the status enum, routes, and both test files. **All three mandated remediations land, and they land well.** The rejection is on a separate, reachable production failure surfaced while reassessing invariant 4 — not a re-litigation of R1.

**Disclosure: I could not execute PHPUnit, PHPStan or Pint here** (permission denied for `php vendor/bin/…`). Every finding below is from static inspection. Your reported figures (59/212 SQLite with 18 PG-skips, 31 tests on fresh PG, Pint, PHPStan, `git diff --check`) are taken as reported. I independently confirmed `git diff --check` is clean.

## Remediation pass/fail

| # | Required change | Verdict | Evidence |
|---|---|---|---|
| 1a | Duplicate lookup ignores `Voided` | **PASS** | `StatementImportService.php:289` `->where('status','!=',BankStatementStatus::Voided)`; reached from both preview `:53` and locked confirm `:176` |
| 1b | File index partial on `status <> 'voided'` | **PASS** | `2026_07_19_110001_create_bank_statements.php:52-56` raw partial index for pgsql+sqlite |
| 1c | Void deletes metadata lines only after proving zero allocations/executions | **PASS** | Guards `:239-249` precede `:251`. Hard delete confirmed: no `deleted_at` in `…110002…` and no `SoftDeletes` on `BankStatementLine.php:36-39` — so `bank_statement_lines_repository_fingerprint_unique` (`110002:41`) is genuinely freed, and re-import is not an empty import |
| 1d | Statement/file audit row remains; no financial row deleted | **PASS** | `:252-253` mutates `status` only; `source_file_sha256`/`source_file_path` retained. Zero GL/movement references in the file |
| 2a | Parse + pre/post integrity before the transaction | **PASS** | Integrity `:131-135` → parse `:137` → integrity `:138-142` → `DB::transaction` opens `:150`. `assertFileIntegrity:322-329` does `clearstatcache` + `hash_equals` |
| 2b | Profile digest defeats TOCTOU | **PASS** | Minted `:76`, checked unlocked `:121`, **rechecked under lock** `:173`. Digest `:311-319` covers every parser-affecting column in `…110000…:24-32` — `column_map`, `date_format`, `decimal_format`, `direction_convention`, `header_rows`, `parser_key`, `payment_repository_id`. Nothing parser-relevant is omitted. `is_active` is excluded but re-verified under lock via `guardOwnership:169 → :279` |
| 2c | Duplicate/fingerprint checks + inserts under the locks | **PASS** | Repo lock `:164`, profile lock `:165`, currency recheck `:170`, duplicate `:176`, fingerprint `:177` with `lock=true` (`:343-345`), inserts `:187-221` — all inside |
| 3 | Eleven named scenarios | **PASS** | non-bank `:404-411`; inactive repo `:413-415`; inactive profile `:418-420`; token expiry `:423-428`; integrity mismatch `:430-436`; allocation-blocked void `:238-271`; reconciled-blocked void `:272-275`; same-file re-import `:204-217`; parser-outside-transaction `:374-400`; referenced-profile deletion `:439-450`; profile mutation after preview `:453-461` |

Two of these deserve credit. The integrity test now overwrites **the stored file** (`:433`) rather than the ciphertext, so it actually reaches the hash compare and pins the exact message — the precise flaw R1 identified. And `test_confirm_reparses_before_opening_the_repository_transaction:374-400` is a real structural assertion: it captures `DB::transactionLevel()` outside the request and swaps in a parser that throws if the level moved, so it fails if anyone ever pulls `parseStored` back inside. That is the right way to lock in a fix.

## Findings

### BLOCKER
None.

### MAJOR

**M1 — `StatementImportService.php:340-342` — the fingerprint `whereIn` has no bound and exceeds PostgreSQL's 65,535 bind-parameter ceiling on a legitimate upload.** `$fingerprints` is every parsed line (`:339`), passed straight to `whereIn`. Laravel does not chunk this. No row cap exists anywhere in the pipeline: `UploadBankStatementRequest.php:33` caps **bytes** only (`max:20480`), and `CsvStatementParser.php:47-67` accumulates every row into `$rows` with no limit.

65,535 rows of a typical bank CSV is roughly **4.6 MB** — comfortably inside the 20 MB cap, and an entirely ordinary multi-year account export. The result is an unhandled `QueryException` → 500, surfacing at **preview** (`:68`, unlocked path), not a clean 422. This is reachable on valid input through the normal UI, and nothing in the suite bounds it. R1 did not flag it; it is not a re-litigation.

**M2 — `:204-221` + `:164` — the per-row insert loop still runs under the `payment_repositories` row lock.** Hoisting `parseStored` fixed the dominant term, but confirm still executes one `BankStatementLine::query()->create()` per accepted line inside the transaction that holds `PaymentRepository::query()->lockForUpdate()` (`:164`). That is the same row lock `TreasuryMovementService.php:61-66` takes for **every** treasury movement, so a large confirm blocks all POS settlements, payments and transfers into that bank account.

Bounded by M1 at ~65k inserts, this is roughly an order of magnitude better than the pre-remediation parse and is sub-second for a realistic 2k-line statement — so I rate it below M1. But it is the same defect class R1 raised, it is triggerable by any `bank-statements.import` holder, and Wave 3 stacks `repository_movements` locking beneath this identical lock. A chunked `insert()` is a few lines and closes both M1 and M2 together.

### MINOR

- **m1 `…110001…:14-16`** — `if (Schema::hasTable('bank_statements')) { return; }` means the new partial index is **never applied to any database where the pre-remediation table already exists**. Those environments keep the unconditional unique index and the void dead-end returns silently, with green tests. `BankStatementAggregateSchemaTest.php:538` cannot catch this — it drops the table first. Low real-world risk (the branch is unmerged, so staging/prod migrate fresh), but every developer DB that ran `ebe85487d` is now stale.
- **m2 `…110001…:57-63`** — the non-pgsql/sqlite branch installs the **unconditional** unique index, reinstating the void dead-end. Dead code on a PostgreSQL-only project, but it encodes the bug it was meant to remove.
- **m3 `ConfirmBankStatementRequest.php:24-25`** — still `regex:/^-?\d+(?:\.\d+)?$/` with no `numeric` and no scale ceiling. CLAUDE.md rule 19 is explicit: *"keep `numeric` and ADD a regex ceiling per column scale — money `/^-?\d+(\.\d{1,3})?$/`"*. `0.00001` still passes validation and trips `canonicalMoney:417` as a `BUSINESS_ERROR` instead of a field-bound `errors` entry. Flagged in R1 as m2, unchanged.
- **m4 `BankStatementAggregateSchemaTest.php:82-96`** — asserts the index still rejects a same-repository duplicate, but never asserts the `WHERE status <> 'voided'` predicate at the DB level. The behaviour is covered end-to-end at `StatementImportFlowTest.php:213-217` (which does exercise the real index), so this is a completeness gap, not a hole.
- **m5 `BankStatementController.php:163` + `StatementImportService.php:255`** — `void()` returns `$locked->fresh()` without `loadCount('lines')`, so the void response emits `lines_count: null` rather than `0`.
- **m6** — R1 carry-overs unfixed and unmandated: staged-preview files are never GC'd (only deleted on parse failure, `:80`); `droppedZeroAmountRows` still conflates balance-only rows with genuine zero-amount rows (`StatementRowMapper.php:83` vs the amount check below it); no mapped-row total surfaced.

## Spec conflicts created by the remediation

Three, none of them documented. The plan diff is a **checkbox flip only** (`- [ ]` → `- [x]`, line 95); the spec was not touched.

1. **Spec §5.1:97** states `source_file_sha256` is *"unique per repository — re-importing an identical file is rejected, not silently deduped to zero lines."* The index is now conditional. The R2 request authorised this and the reasoning is sound, but the normative text still asserts the old, unconditional invariant.
2. **Spec §5.3:118** states *"voiding deletes nothing financial because nothing financial exists yet."* The remediation now deletes **all** `bank_statement_lines`. That is consistent with the letter (lines carry no GL or movement rows), but the spec nowhere authorises line deletion. It matters for the spec-permitted `Reconciling → Voided` transition: an operator's triage work — `match_status = Ignored` plus `ignore_reason`/`ignore_text` (`…110002…:32-34`) — is discarded with no audit trail, and the void guard only proves zero *allocations/executions*, not zero operator effort.
3. **Undocumented cross-statement interaction.** Statement A holds fingerprint `fp`; a later statement B whose file also contains `fp` has it deduped away against A (`:177`). Voiding A now deletes `fp` outright — the repository loses that transaction entirely while B's source file still claims it, and B's line count no longer reconstructs from its own file. Recoverable by re-importing A's file, and harmless in a staging-only wave, but it is a consequence of the new delete that no document records.

All three want a sentence each in §5.1/§5.3 before Wave 3 builds on top.

## Eight-invariant reassessment

| # | Invariant | R1 | Now | Delta |
|---|---|---|---|---|
| 1 | Active bank repo + active bound profile; private disk; hash before store; duplicate → 422 + id | PASS (M3) | **PASS** | Guards now directly tested (`:404-420`). Hash `:49` still precedes storage `:57`; `rejectDuplicateFile:53` precedes both; `assertDirectoryEmpty:317` still proves it |
| 2 | Preview persists nothing; six signals | PASS (m3,m4) | **PASS (m6)** | Unchanged; delta assertions `:102-103`, `:121-122` |
| 3 | Token binds fields; verifies age/ownership/integrity; reparses | PASS (M3) | **PASS** | Now binds **eight** fields (`profile_digest` added `:76`), validated `:400-407`. Expiry `:423-428` and integrity `:430-436` now genuinely tested |
| 4 | Locks, replay-checks, skips fingerprints, `acknowledge_empty`, atomic `Imported` | PASS (M2) | **PASS (M1,M2)** | Correctness intact and improved; the bound is the problem |
| 5 | Currency historical truth; must match; rules 19/20 | PASS (m2,m5) | **PASS (m3)** | R1's m5 is now **closed** — `:170` rechecks the locked repository's currency against the pre-lock value. Grep across the service, both controllers and the row mapper for `(float)`, `floatval`, `number_format`, no-arg `getScale()`: zero hits. Scale always explicit-currency (`:144`, `:371`) |
| 6 | Continuity vs previous **reconciled**; warns not blocks; location from repo | PASS | **PASS** | `:359-381` filters `Reconciled` `:363`, `bccomp` at resolved scale `:372`, never throws; `location_id` `:218` |
| 7 | Void only `Imported\|Reconciling → Voided`, zero allocs/execs, locked | PASS (M1,M3) | **PASS** | R1's M1 **closed**. Both blocked branches now tested (`:232-235` execution, `:268-270` allocation, `:273-275` reconciled) |
| 8 | Pure staging; parser follow-up preserves Gate 1 | PASS | **PASS** | Still zero GL/movement references. `transactionAmountColumnsAreBlank:258-271` branches correctly on both conventions and is inert when no balance columns are mapped |

## Staging purity

Clean, and still **proven rather than asserted** — `:102-103` and `:121-122` are count *deltas*, so an indirect write via a listener would be caught, not just a direct one. Nothing stamps `payment_repositories.last_reconciled_*`. The only non-DB side effect remains the staged file write (`:57`), rolled back on parse failure (`:79-83`) but not on abandonment (m6).

One new consideration: `void()` now performs a **delete**, so this wave is no longer strictly append-only. It is confined to `bank_statement_lines`, which carry no GL or movement linkage in Wave 2, and it is guarded on zero allocations and zero executions. Acceptable — but it is a change of character that the spec should record (conflict 2).

## Replay and concurrency

**Replay sound.** Re-confirming a token hits `rejectDuplicateFile:176` inside the repository lock → 422 with the existing id; the partial index is a hard backstop. Different-file overlap stays a separate concern handled by the locked fingerprint filter `:177`. That split is still right.

**Concurrency — I traced the new void/confirm interleavings and found no defect.** Void takes a `bank_statements` row lock (`:235`) and never takes the repository lock, so it is *not* serialised against confirm's duplicate read. Both orderings are safe: confirm reads the statement as non-voided → fails closed with 422; or reads it as voided → proceeds correctly. Fingerprint safety holds because `withoutExistingFingerprints` takes `lockForUpdate` on the very line rows (`:343-345`) that void deletes (`:251`) — whichever transaction gets there first blocks the other, in both directions.

**No deadlock cycle.** Confirm locks `payment_repositories` → `bank_statement_lines`. Void locks `bank_statements` → `bank_statement_lines`. Neither holds a lock the other acquires first; they contend only on the shared tail. `TreasuryMovementService` takes only the repository row. Lock ordering is consistent.

**The residual is blast radius, not correctness** — M2, now bounded by M1 rather than by file size.

## Test quality

Real behaviour throughout, no tautologies, no mocked subject; `RefreshDatabase:41` + `RolesAndPermissionsSeeder:70`. The new tests are a clear step up from R1: the parser-placement guard (`:374-400`) is structural rather than incidental, the expiry test re-encrypts a mutated payload with the real app key rather than stubbing the clock, and the integrity test now tampers with the correct artifact. `test_repository_profile_expiry_and_integrity_guards_fail_loud:402-437` correctly restores state between sub-cases (`:416`, `:421`) so the assertions stay independent. Rule 12 remains satisfied — all ten routes sit inside the existing group with `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`, each carrying its own `can:` (`routes.php:267-297`).

One standing caveat, unchanged: `Storage::fake('local'):63` means the private-disk storage location is verified by me from `config/filesystems.php`, not by the suite.

---

**VERDICT: spec ❌ + quality CHANGES-REQUESTED**

Fix before merge: chunk the fingerprint lookup at `StatementImportService.php:340-342` and batch the line inserts at `:204-221` (both in blocks well under PostgreSQL's 65,535 bind-parameter limit), or reject oversized statements with an explicit row cap at parse time — then add a test that drives a >65,535-row file through preview and confirm.

I have not written this to `.gates/gate-t5b-gate-2-r2-verdict-treasury.md` (the placeholder is empty) — say the word and I will.
