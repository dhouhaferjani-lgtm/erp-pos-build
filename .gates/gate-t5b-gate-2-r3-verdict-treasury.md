APPROVE

I read the full authority chain (`gate-t5b-gate-1-verdict-r2.md`, both treasury Gate 2 verdicts, the R2 tenancy verdict, and `gate-t5b-gate-2-escalation-verdict.md`), then reviewed `git diff e06d2a831...d87126b38` and adjacent code. Every one of Fable's eight binding items is discharged in the tree. No BLOCKER and no MAJOR survives.

**Disclosure on evidence:** I could not execute the test suite in this session — the Bash calls to `vendor/bin/phpunit` were denied by the permission layer (three attempts, all refused before execution). My verdict therefore rests on code reading plus your submitted run evidence, not on an independent re-run. Every structural claim below is cited to source; the pass/fail counts are yours, not mine.

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

**m1 — Structural bind assertion undercounts columns by one.** `StatementImportFlowTest.php:496` asserts `$insertChunk * 15 <= 32000`, but the insert at `StatementImportService.php:211-228` binds **16** columns (Fable's 15 plus `dedupe_active` at `:227`). At the shipped chunk of 500 this is harmless (500 × 16 = 8,000, far under SQLite's 32,766). The gap is narrow but real: a future bump to `LINE_INSERT_CHUNK = 2100` passes the assertion (31,500 ≤ 32,000) while actually binding 33,600 — over the SQLite ceiling. The guard should derive the multiplier from the array or use 16.

**m2 — Bulk-insert fidelity test never asserts the id is a valid UUID.** Fable's answer-5 item 3 named "valid UUID id" explicitly; `StatementImportFlowTest.php:527-544` asserts every other column but omits `id`. On PostgreSQL the `uuid` column type enforces it implicitly (`110002`), so the defect is unreachable on the production driver — but on the SQLite path a regression in `newUniqueId()` (`StatementImportService.php:212`) would pass silently.

**m3 — `StatementRowMapper` change is outside the eight-item checklist.** `StatementRowMapper.php:65-95` reorders balance detection ahead of amount parsing and adds `transactionAmountColumnsAreBlank()` (`:258-271`). This is a behavior change in a commit whose stated scope is the Fable remediation (Rule 4). It is covered by a new unit test (`CsvStatementParserTest.php:171-194`) and it addresses the `droppedZeroAmountRows` conflation Fable named as a carry, so I am not gating on it. One side effect to record: a row with a blank amount **and** a populated balance column now lands in `droppedZeroAmountRows` rather than `unparseableRows`, so the operator loses the per-row reason string for that shape.

**m4 — `110005::down()` can fail on a legitimately voided-then-reimported database.** `2026_07_19_110005:52-70` recreates unconditional unique indexes, which cannot represent a preserved voided line alongside its active replacement. This is correctly and explicitly disclosed at `treasury-phase5b-deploy-checklist.md:62`, so it is documented risk rather than a hidden trap. Noting it only so it is not rediscovered later.

## Fable remediation table

| # | Fable requirement | Status | Evidence |
|---|---|---|---|
| 1 | Fingerprint reads chunked at 500, accumulate across chunks, filter `dedupe_active`, retain `lockForUpdate` on confirm | ✅ | `StatementImportService.php:34` (const), `:353` (`array_chunk`), `:356` (`where('dedupe_active', true)`), `:358-360` (lock only when `$lock`), `:361-363` (accumulate into `$existing` across iterations), `:181` (confirm passes `true`), `:72` (preview passes default `false`). `pluck()` preserves the `for update` clause through `compileLock`. Sole reader of the column — grep confirms no other query touches `fingerprint` |
| 2 | Bulk insert at 500, inside repository transaction, explicit UUIDs, enum strings, numeric-string amounts, all prior columns, `dedupe_active = true` | ✅ | `:36` (const), `:209-231` inside the `DB::transaction` opened at `:154` under the lock taken at `:168`; `:212` `newUniqueId()`; `:218`/`:224` `->value`; `:219` amount passed through as the parser's `numeric-string` (`ParsedStatementLine.php:16`); `:227` `dedupe_active`. Column set matches `110002` and the pre-remediation `create()` payload |
| 3 | Void never deletes; flips `dedupe_active` only after zero-allocation/zero-execution guards; preserves ignore metadata and source evidence; returns real line count | ✅ | `:249-259` guards run first and throw; `:261` `update(['dedupe_active' => false])` — no `delete()` anywhere in `void()`; `ignore_reason`/`ignore_text`/`source_file_path` untouched; `:267` `loadCount('lines')`, surfaced at `BankStatementController.php:163`. Proven end-to-end by `StatementImportFlowTest.php:219-235` |
| 4 | Gate 1 creates restored; `110005` upgrades fresh + stale, backfills voided, converts both to partial, pgsql+sqlite only, twice-safe | ✅ | `110001:44` and `110002:41` carry the unconditional Gate 1 uniques with `hasTable` guards intact, and neither file appears in the diff — restoration confirmed. `110005:20-24` conditional column add; `:25-34` backfills existing voided statements; `:36-48` drops-then-recreates both as partial; `:76-82` `guardDriver()` throws on any other driver; `:84-90` `dropUniqueIndex` handles both the PG constraint form and the SQLite index form. Every step is guarded or `IF EXISTS`, so a second run is a no-op |
| 5 | Seven-part test set; no >65,535-row fixture required | ✅ | 1,201-row non-multiple boundary `StatementImportFlowTest.php:497-526`; structural bounds `:491-496` (see m1); bounded insert count via `DB::listen` `:512-526`; full-row fidelity `:527-544` (see m2); overlapping cross-boundary duplicates `:546-556`; void provenance + same-file re-import `:206-241`; active-vs-voided coexistence `BankStatementAggregateSchemaTest.php:186-217`; PG predicates `:219-237`; stale upgrade + idempotence `:239-270`; the pre-existing parse-outside-transaction guard survives at `StatementImportFlowTest.php:398` |
| 6 | Interpretation verbatim in handback and deploy checklist, locked Rev 2 untouched | ✅ | `treasury-phase5b-deploy-checklist.md:10` and `HANDBACK-treasury-phase5.md:9` both carry Fable's answer-4 paragraph verbatim, byte-for-byte with the escalation verdict. The spec file shows a 1-line diff (`--stat`), not a §5.1/§5.3 edit |
| 7 | Opportunistic carries: balance `numeric` + 3-decimal ceiling; void line count; storage/cleanup obligations explicit | ✅ | `ConfirmBankStatementRequest.php:24-25` — `numeric` plus `/^-?\d+(?:\.\d{1,3})?$/` on both balances (Rule 19 letter); void count per item 3; `treasury-phase5b-deploy-checklist.md:25` shared-storage requirement and `:26` abandoned-preview retention with the explicit carve-out that any path referenced by a `source_file_path` — voided included — is audit evidence |
| 8 | Reassess Gate 2 invariants, staging purity, replay safety, lock duration | ✅ | See tables below |

## Original Gate 2 invariants

| Invariant | Status | Evidence |
|---|---|---|
| Preview persists nothing to the database | ✅ | `preview()` `:44-100` writes only to the `local` disk (`:61`); all DB access is read-only (`:57`, `:72`). Pinned by `StatementImportFlowTest.php:86` |
| File uniqueness per repository, over active rows | ✅ | `rejectDuplicateFile()` `:296-306` excludes `Voided`; enforced at DB level by the partial index (`110005:38-42`), asserted at `BankStatementAggregateSchemaTest.php:219-237` |
| Line fingerprint uniqueness per repository, over active rows | ✅ | `110005:44-48` `WHERE dedupe_active`; application filter at `:356`; index semantics proven at `BankStatementAggregateSchemaTest.php:165-217` |
| Statement + lines are atomic | ✅ | `BankStatement::create()` `:191` and all insert chunks `:209-231` share the one `DB::transaction` at `:154` |
| Void is financially inert | ✅ | Strengthened past §5.3.4 — void now deletes nothing at all (`:261`). Blocked outright when allocations or executions exist (`:257-259`) |
| Tenant/company scoping on every entry point | ✅ | `guardOwnership()` `:271-294` called at preview `:51`, pre-transaction `:124`, and again on the locked rows `:173`; token tenant/company checked at `:112` |
| Currency precision, no float | ✅ | `canonicalMoney()` `:432-440` regex-gates then `CurrencyScale::bcformatStrict`; scale from the injected resolver with the explicit repository currency (`:148`), never a bare no-arg `getScale()` |
| Enums for status/type | ✅ | `BankStatementStatus`, `MovementDirection`, `StatementLineMatchStatus` throughout; `.value` used only at the raw-insert boundary |
| Constructor injection only | ✅ | `:38-41`; no `app()` in the service |
| Immutable status machine | ✅ | `BankStatementStatus::canTransitionTo` makes `Voided` terminal, so no reopen path can resurrect a `dedupe_active = false` line into an index conflict. Worth carrying into Wave 3 when `bank-statements.reopen` (seeder `:238`) gets an implementation |

## Staging purity, replay, concurrency, lock duration

**Staging purity — clean.** The upload writes one file under `bank-statements/{tenant}/{company}/` (`:60-65`) and is deleted on any parse or tokenization failure (`:83-87`). Preview creates zero rows. The confirm token is `Crypt`-sealed and carries tenant, company, repository, profile, path, sha256, and profile digest (`:73-82`), each re-validated on decode (`:408-429`).

**Replay safety — sound.** Confirm re-parses from the staged file (`:141`) rather than trusting preview output, and brackets that parse with sha256 integrity checks on both sides (`:135-139`, `:142-146`) so a mid-parse file swap fails loud. Token lifetime is capped at 4 h (`:115`). The profile digest is re-verified twice — before the transaction (`:125`) and against the locked profile row (`:177`) — so a profile edit between preview and confirm cannot silently change the mapping. The duplicate-file check is re-run *inside* the transaction under the lock (`:180`), so a replayed token cannot double-import.

**Concurrency — no new hazard.** Confirm serializes on the `payment_repositories` row (`:168`), so two confirms into the same repository cannot interleave at all; fingerprint collisions across repositories are impossible because both the index and the query are repository-scoped. The chunked `lockForUpdate` takes no gap locks, but the repository row lock already covers that window. Confirm's line-row locks and void's `UPDATE` of the same rows conflict in both orderings, exactly as Fable's answer 2 required. No deadlock cycle exists: confirm never waits on a `bank_statements` row lock, and void never waits on the `payment_repositories` lock.

**Lock duration — materially improved, and this is the point of the fix.** The repository row lock is held across the same logical span as before, but the per-row `create()` loop (1,201 round trips) is now 3 bulk `INSERT`s — roughly two orders of magnitude less time inside the lock that `TreasuryMovementService.php:61-65` also contends for. Parsing stays outside the transaction (`:141` precedes `:154`), regression-pinned at `StatementImportFlowTest.php:398`. Wave 3 can stack `repository_movements` locking beneath this without inheriting the stall.

## Recommended follow-ups (not gate-blocking)

1. `StatementImportFlowTest.php:496` — change the multiplier to 16 or derive it from the inserted row (m1).
2. Add a UUID-format assertion on the inserted `id` (m2).
3. When `bank-statements.reopen` is implemented in Wave 3, confirm it cannot target a `Voided` statement — the enum forbids it today, and that must stay true or `dedupe_active` reactivation becomes an index-conflict source.

VERDICT: spec ✅ + quality APPROVED
