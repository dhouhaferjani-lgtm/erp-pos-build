UPHOLD

The treasury reviewer's second REJECT is correct on the merits, and I am additionally binding the tenancy reviewer's carried Major (line preservation) into the required remediation because the two reviewers' fixes conflict and only one of them is structurally complete. No reviewer finding is overruled. I verified every load-bearing claim directly against the code at `abccafa6f` before ruling.

## What I verified

- `StatementImportService.php:339-342` — `withoutExistingFingerprints` passes every parsed fingerprint into a single `whereIn`. No row cap exists anywhere: `UploadBankStatementRequest` caps bytes (20 MB), and the CSV parser accumulates unboundedly. PostgreSQL's extended-protocol Bind message carries a uint16 parameter count — 65,535 is a hard ceiling, and ~65k rows of bank CSV is ≈4–5 MB, well inside the byte cap. This is an unhandled `QueryException` → 500 on valid input, reachable at preview (`:68`, unlocked) and confirm (`:177`).
- `StatementImportService.php:164` + `:204-221` — the per-row `create()` loop runs inside the transaction holding `PaymentRepository::query()->lockForUpdate()`, and `TreasuryMovementService.php:61-65` takes that same row lock for every treasury movement. A large confirm stalls all POS settlements, payments, and transfers into that bank account.
- `StatementImportService.php:251` — `$locked->lines()->delete()` on void. The delete is load-bearing: it is the only thing freeing `bank_statement_lines_repository_fingerprint_unique` (`2026_07_19_110002:41`, unconditional) for re-import.
- `2026_07_19_110001:14-16, 50-64` — shipped migration edited in place; the `hasTable` early-return plus the migrations ledger mean no DB that ran the Gate 1 version ever receives the partial file index. The non-pgsql/sqlite branch (`:57-64`) reinstates the unconditional index.
- `BankStatementLine` uses `HasUuids`, `$timestamps = false`, enum casts — this constrains how a bulk-insert fix must be written (ids and enum values must be materialized explicitly; there are no timestamps to fabricate).
- Spec §5.1 ("unique per repository — re-importing an identical file is rejected, not silently deduped to zero lines"), §5.3.4 ("voiding deletes nothing financial because nothing financial exists yet"), §6.6, §9; plan Task 4; the R2 request's mandate items 1–3; both treasury verdicts and the tenancy R2 verdict.

## Findings

### BLOCKER
None (concurring with both reviewers' taxonomy — no spec-integrity or financial-correctness violation exists; staging purity, scoping, replay, and lock ordering are sound).

### MAJOR — gate-blocking

**A1 (upholds treasury R2 M1).** Unbounded fingerprint `whereIn` at `StatementImportService.php:339-342` produces a 500 on a legitimate multi-year export. Reachable through the normal UI by any `bank-statements.import` holder; nothing in the suite bounds it.

**A2 (upholds treasury R2 M2).** Per-line inserts at `:204-221` under the shared `payment_repositories` row lock (`:164`). Same defect class the R1 REJECT raised; Wave 3 stacks `repository_movements` locking beneath this identical lock, so the cost of fixing it only rises from here.

**A3 (elevates tenancy R2 M1; resolves treasury R2 spec conflicts 2 and 3).** Void must stop deleting lines. The delete destroys audit provenance (a voided statement's `show()` returns nothing), discards `Reconciling → Voided` triage metadata (`ignore_reason`/`ignore_text`, `110002:32-34`), and silently erases fingerprints that a later statement's file also claims. The tenancy reviewer's proposed fix (status-aware join in the lookup) is **incomplete on its own**: a join predicate cannot live in an index, so the unconditional `bank_statement_lines_repository_fingerprint_unique` would still 23505 the re-insert. The `dedupe_active` partial-index design in escalation question 2 is the only proposal on the table that frees the key *and* preserves the rows. Binding — see answer 2.

**A4 (elevates tenancy R2 M2 + treasury R2 m1/m2).** The in-place migration edit leaves every DB provisioned between 5.2.3 and 5.2.7 with the unconditional file index and a silent void → re-import dead end, and the A3 fix requires a schema change to `bank_statement_lines` anyway — so the corrective-migration path is now mandatory, not optional. Binding — see answer 3.

### MINOR — carried, not gate-blocking

- `110001:57-64` — non-pgsql/sqlite fallback encodes the removed bug; restrict to pgsql+sqlite and throw on other drivers (folds into A4's rewrite).
- `ConfirmBankStatementRequest.php:24-25` — no `numeric`, no scale ceiling (rule 19 letter). `canonicalMoney` (`:414-422`) is currency-aware and stricter, so this is an error-envelope nit, pre-existing at gate base. Add `numeric` when touched.
- `void()` response returns `fresh()` without `loadCount('lines')` → `lines_count: null`. Trivially fixed alongside A3 (and with A3, the count becomes real, preserved lines).
- Schema test never asserts the partial predicates at DB level — fold into A4's tests.
- Deploy checklist (tenancy M3), staged-file GC, `droppedZeroAmountRows` conflation, mapped-row total — merge obligations and carries as the tenancy verdict recorded them; not Gate 2 material.

## Binding answers

**1. Yes — Gate 2 blocks on M1 and M2, and chunking is the correct minimum fix.** A reachable 500 on valid input within the advertised file cap, plus an operational stall of the treasury hot path triggerable by a routine import, are exactly what "Verification is Law" gates exist to stop before Wave 3 builds on this transaction. Chunked fingerprint lookup + chunked bulk insert, both remaining **inside** the transaction and locks (only the parse stays outside — the existing structural test at `StatementImportFlowTest.php:374` already pins that). Constraints:
- Two named private constants, e.g. `FINGERPRINT_LOOKUP_CHUNK = 500` and `LINE_INSERT_CHUNK = 500`.
- Lookup: 1 bind per fingerprint → 500 stays under even legacy SQLite's 999-variable limit; accumulate matched fingerprints across chunks into one set before filtering. `lockForUpdate` applies per chunk — acceptable, since the repository row lock (`:164`) is what actually serializes confirms; the line-row locks only need to cover the confirm↔void interleaving, which they still do.
- Insert: 15 bound columns per row (id, statement, repository, line_number, value_date, booking_date, direction, amount, reference, bank_transaction_id, label, counterparty_hint, match_status, location_id, fingerprint) → 500 × 15 = 7,500 binds, comfortably under modern SQLite's 32,766 and PostgreSQL's 65,535.
- Because `BankStatementLine::insert()` bypasses Eloquent: generate `id` explicitly (`(new BankStatementLine)->newUniqueId()` or `Str::uuid7()`), serialize `direction`/`match_status` to their enum string values, keep `amount` as the numeric-string it already is. One test must assert full-row content parity with the previous path.

**2. Yes — the `dedupe_active` design is the binding resolution**, superseding both the current delete and the tenancy reviewer's join-only sketch. Specifically: `dedupe_active` boolean NOT NULL DEFAULT true on `bank_statement_lines`; the fingerprint unique index becomes partial `WHERE dedupe_active`; `withoutExistingFingerprints` adds `->where('dedupe_active', true)`; `void()` replaces `:251` with `$locked->lines()->update(['dedupe_active' => false])`, inside the same transaction under the statement lock. The concurrency property R2 traced survives intact: confirm's `lockForUpdate` on matching *active* line rows and void's UPDATE of those same rows conflict in both orderings, so neither can slip past the other. This preserves immutable parsed content and triage metadata, keeps `show()` truthful for auditors, eliminates the cross-statement fingerprint erasure, and honors §5.3.4 *a fortiori* — void now deletes nothing at all.

**3. Yes — restore and correct.** Restore `110001`/`110002` to their Gate 1 definitions (unconditional unique indexes, `hasTable` guards intact). Add one corrective tenant migration (dated after `110004`) that, idempotently and self-guarding per the push=deploy rule: (a) adds `dedupe_active` if the column is missing; (b) drops and recreates `bank_statements_repository_file_unique` as partial `WHERE status <> 'voided'`; (c) drops and recreates `bank_statement_lines_repository_fingerprint_unique` as partial `WHERE dedupe_active`. Driver-guard to pgsql+sqlite and throw a `RuntimeException` on anything else — never silently install the unconditional variant. Fresh DBs get create-then-convert; stale branch DBs (whose ledgers skip the creates) get converted; running it twice is a no-op. The tenancy reviewer verified the branch has never touched dev, so the blast radius is developer/preview DBs — but the standing autodeploy rule makes the self-guarding upgrade path the correct discipline regardless.

**4. Binding interpretation** (record verbatim in the handback and the ⑤b deploy checklist; fold into spec §5.1/§5.3 at the next authorized revision — do not edit locked Rev 2):

> §5.1's file-sha256 uniqueness and line-fingerprint uniqueness range over **active** rows only: statements with `status <> 'voided'`, and lines with `dedupe_active = true`. The invariant's purpose — its own words — is that re-import is "rejected, not silently deduped to zero lines": it prevents duplicate *live* imports; it does not permanently consume a file identity after a §5.3.4 void. Void is a pure status transition: nothing is deleted; a voided statement's lines are retained verbatim for audit but cease participating in dedupe. At most one non-voided statement may exist per (repository, sha256); re-importing the identical file after void is permitted and creates a new active statement.

**5. Tests required for approval** — a >65,535-row parse is **not** required; it would test PhpSpreadsheet throughput, not the defect, whose failure mode (bind count) is pinned exactly by structural assertions. Required set:
1. **Chunk-boundary functional test:** a file whose row count exceeds both chunk constants at a non-multiple (e.g. 1,201 rows at chunk 500) — preview and confirm succeed, all rows persist; then an overlapping re-import whose duplicates straddle chunk boundaries is deduped correctly.
2. **Structural bound assertions:** read both constants (reflection is fine) and assert `FINGERPRINT_LOOKUP_CHUNK ≤ 999` and `LINE_INSERT_CHUNK × 15 ≤ 32000` (the min of both drivers' limits, with headroom); plus a `DB::listen` counter asserting confirm issues ≤ `ceil(n / LINE_INSERT_CHUNK)` insert statements — this is the regression guard against reverting to per-row creates.
3. **Bulk-insert fidelity:** one `assertDatabaseHas` covering a complete row — valid UUID id, enum string values, numeric-string amount, location, fingerprint.
4. **Void provenance:** void preserves lines (`dedupe_active = false`, content intact, `show()` still returns them, `lines_count` real); same-file re-import after void imports the full line count; pre-void ignore metadata survives.
5. **Index semantics at DB level (PG):** the partial predicates asserted from `pg_indexes.indexdef`; a voided duplicate coexists with an active row while a second active duplicate is rejected by the index itself.
6. **Upgrade path (PG):** simulate a stale-branch DB (Gate 1 index shapes in place), run the corrective migration, assert both predicates; run it twice, assert idempotence.
7. Existing structural test `test_confirm_reparses_before_opening_the_repository_transaction` stays green.

## Remediation checklist (gate-blocking items 1–6)

1. Chunk the fingerprint lookup at `StatementImportService.php:339-342` (`FINGERPRINT_LOOKUP_CHUNK = 500`, accumulate across chunks, keep inside the transaction with `lockForUpdate` per chunk).
2. Replace the `:204-221` create-loop with chunked `insert()` (`LINE_INSERT_CHUNK = 500`, explicit UUIDs, enum values serialized), still inside the transaction.
3. Add `dedupe_active` (NOT NULL DEFAULT true); filter `withoutExistingFingerprints` on it; replace `void()`'s line delete with `update(['dedupe_active' => false])`.
4. Restore `110001`/`110002` to Gate 1 definitions; add the idempotent corrective migration performing the column-add and both partial-index conversions; pgsql+sqlite only, throw otherwise.
5. Add the seven-part test set from answer 5.
6. Record the answer-4 interpretation in the handback and the (owed) `treasury-phase5b-deploy-checklist.md`.
7. Carries (fix opportunistically or before merge, not gate-blocking): `loadCount('lines')` on the void response; `numeric` + scale ceiling on `ConfirmBankStatementRequest`; deploy checklist authored per tenancy M3; staged-file GC ticket.
8. Re-submit to **treasury-reviewer R3** with fresh SQLite + PG evidence; tenancy re-review not required unless routing/permissions change (they should not).

ESCALATION VERDICT: GATE BLOCKED
