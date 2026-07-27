REJECT

I read the full committed diff, both migrations sets, all five models, seven enums, the parser stack, the registry wiring, and both test files. The schema work is genuinely strong — the composite-FK anchor, the dual application+database immutability guards, and the exact-five-path rollback test are all real and correctly built. The defects are concentrated at the parser's **money boundary**, where the at-rest amount string is never canonicalized, and that string is load-bearing for the fingerprint identity.

---

## Findings

### CRITICAL

**C1 — `apps/api/app/Modules/Treasury/Application/Services/StatementRowMapper.php:222-231` — a leading `+` on a signed amount is neither stripped nor rejected.**

`normalizeDecimal`'s pattern at `:288-289` explicitly permits a sign (`/^[+-]?\d+…/`), but `parseDirectionAndAmount` only strips `-` (`:222-223`). An explicitly-signed export (`+1 234,56` / `-42,500` — a common TN/FR convention) therefore returns the literal string `+1234.56` as the amount, which is then passed to `bccomp($amount, '0', $scale)` at `:72`.

*Failure scenario:* bcmath in PHP 8 rejects malformed operands with `ValueError`, not `InvalidArgumentException`. The catch at `:129` catches only `InvalidArgumentException`, so a `ValueError` escapes `map()` entirely and **aborts the whole import** rather than reporting one unparseable row. If bcmath instead tolerates the `+`, the string `+1234.56` is persisted into `decimal(15,3)` and — worse — hashed into the fingerprint at `:103-109`, making that row's identity non-replay-stable against the same transaction re-exported without the sign.

I could not execute PHP in this environment to pin down which of the two branches bcmath takes (permission denied on `php -r`); I am asserting only the code path, which is verifiable from the lines above. Both outcomes are defects, so the finding stands either way. No test covers a `+`-signed amount — `tests/Fixtures/statements/biat_semicolon.csv:4` uses an unsigned positive.

**C2 — `StatementRowMapper.php:101-114` — the fingerprint hashes a non-canonical amount, defeating overlapping-import dedupe.**

Spec §5.1 requires the heuristic identity to be a hash of *canonically-normalized* fields, and the DB backs it with `unique (payment_repository_id, fingerprint)` (`2026_07_19_110002_create_bank_statement_lines.php:41`). `reference` and `label` are canonicalized via `normalizeText` (`:107-108`) — but `$amount` (`:106`) enters raw, carrying whatever decimal shape the bank happened to emit.

The committed tests prove the variance rather than guard against it: within one fixture, `tests/Unit/Treasury/CsvStatementParserTest.php:64` asserts `'1234.56'` while `:67` asserts `'42.500'`, and `:114` asserts `'7140'`. Three different scales, one currency, one file.

*Failure scenario:* BIAT exports a July commission row as `7,140` with no Transaction ID (exactly `tests/Fixtures/statements/locale_decimals.csv:2`). A later overlapping Jul–Aug export writes the same transaction as `7,140,00` → amount `7140.00` → different SHA-256 → the unique index does not fire → **the same bank line imports twice**. Both rows then count toward the §6.5 completion identity `Σ(signed non-ignored lines) == closing − opening`, so the statement can never reconcile, or reconciles against a double-counted total.

This bites precisely the rows the heuristic fingerprint exists for — those *without* `bank_transaction_id` (fees, agios, commissions). Rows with a bank id are safe (`:112-113`), and I confirmed that path is replay-stable.

Note on authority: the plan at `docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md:83` scopes canonical normalization to "trim/collapse-whitespace/case-fold **reference+label**", which the implementation satisfies to the letter. The amount gap is nonetheless a real hole in the spec's stated dedupe purpose, and the Codex plans review already flagged this task band — `docs/superpowers/reviews/2026-07-18-treasury-phase5-plans-codex-review.md:124`: *"⑤b T2–T4 — Partial: occurrence-index and canonical-normalization tests missing."* The occurrence half was implemented and tested (`CsvStatementParserTest.php:116-118`); the normalization half was implemented only partially.

---

### MAJOR

**M1 — the parser never routes the at-rest amount through `CurrencyScale::bcformatStrict()`, in deviation from CLAUDE.md rule 19.**

`StatementRowMapper` correctly constructor-injects the resolver and passes an explicit currency (`:20`, `:37` — `getScale($repository->currency)`), which satisfies the queue/console safety rule. But it uses the resolved scale only as a *regex ceiling* (`:287-289`) and a `bccomp` scale — never to *format* the output. `grep` confirms `CurrencyScale` is not imported into the mapper. Rule 19's at-rest contract is `CurrencyScale::bcformatStrict($value, $scaleResolver->getScale($currency))`, and that helper exists at `apps/api/app/Shared/Domain/CurrencyScale.php:130-145` with an `is_numeric` guard on the way in. Applying it is the single change that resolves C1 and C2 together.

**M2 — PostgreSQL CHECK constraints are written but almost entirely unexercised, against invariant 8.**

Fourteen CHECK constraints ship across the five migrations. Only two are covered by a PG-gated test: `bank_statement_lines_amount_positive` (`BankStatementAggregateSchemaTest.php:158-178`) and `bank_statement_allocations_amount_positive` (`:209-227`). Untested: the statement `status` and `period_end >= period_start` checks (`110001:52-53`), the line `direction` / `match_status` / `line_number > 0` checks (`110002:64-66`), the profile `parser_key` / `direction_convention` checks (`110000:40-41`), and the executions `action_type` / `semantic_digest ~ '^[0-9a-f]{64}$'` / `target_pair` / `jsonb_typeof = 'array'` checks (`110004:39-42`).

Most consequential is `bank_statement_lines_ignore_shape_check` (`110002:67-81`) — the sole enforcement of spec §6.3's "mandatory reason enum + text" on ignored lines, and the one constraint whose four-way boolean shape is most likely to be subtly wrong. `test_execution_semantic_digest_is_required` (`:270-284`) is not PG-gated and passes on SQLite via NOT NULL alone, so it never reaches the hex-format regex.

**M3 — `XlsxStatementParser.php:99,102` reads money through `getFormattedValue()`, Excel's display formatter.**

Both the formula branch and the plain-cell branch return the *formatted* string. A cell holding `1234.5678` under number format `#,##0.00` yields `"1,234.57"` — a silent money rounding performed by Excel's display layer, not by the precision contract's boundary helper. It also emits locale group separators that then have to survive `normalizeDecimal`, and can carry a currency symbol under a currency format code (which `normalizeDecimal` would reject as unparseable).

I recognize the tradeoff: `getValue()` returns `int|float` on numeric cells, and stringifying that float is the worse rule-19 violation. But the current choice is untested for anything but an integral result — `CsvStatementParserTest.php:183` asserts `'120'` from `=100+20`. There is no fixture with a fractional amount under a `#,##0.00` format code.

---

### MINOR

**m1 — `BankStatementMatchExecution.php:47-49` DELETE immutability is untested.** The model-level `deleting` guard and the PG delete trigger (`110004:56-59`) have no direct coverage. `test_statement_delete_cascades…restricted_by_execution` (`:329-344`) exercises the FK `restrictOnDelete` (`110004:20-22`), which fires *before* the trigger is ever reached — so the trigger itself is never proven.

**m2 — `StatementLineMatchStatus::derive()` (`:30-37`) reads over-allocation as `Partial`.** If `$allocationTotal > $lineAmount`, `bccomp !== 0` and `!== 0` against zero, so the line silently reports `Partial` instead of raising. The §6.1 sum rule makes this unreachable *if* the Task-5 caller guards it, but the enum is the last line of defence and does not.

**m3 — `BankStatementAggregateSchemaTest.php:357-361` — `--step 5` is positionally fragile.** It reverts the last five tenant migrations by sort order. That is correct today (I confirmed `2026_07_19_110004` sorts last in `database/migrations/tenant/`), but any migration filed after it silently redirects the rollback at the wrong five tables.

**m4 — `StatementRowMapper.php:83-99` — opening/closing balance detection sits after the zero-amount `continue` at `:75`.** Banks commonly carry the opening balance on a zero-amount `SOLDE INITIAL` row; that row is dropped before `$detectedOpening` is read, so the balance is lost.

**m5 — `StatementRowMapper.php:253` always tries `'Y-m-d'` before the profile's configured `date_format`.** The round-trip check at `:258` makes this harmless for the common formats, but it does mean a profile format is not authoritative. Relatedly, the strict round-trip rejects non-zero-padded dates (`1/2/2026` under `d/m/Y`), which some exports emit.

**m6 — `tests/Unit/Treasury/CsvStatementParserTest.php` is misnamed** — it contains the XLSX parser test (`:144-186`) and the registry dispatch test (`:188-194`).

---

## Invariant resolution

| # | Invariant | Verdict | Evidence |
|---|---|---|---|
| 1 | 5 tables / 7 enums / 5 models, normative columns, uniqueness, checks, ownership indexes, FK order | **PASS** | All five migrations present, `110000`→`110004` ordering puts profiles before statements; `(tenant_id, company_id)` indexes at `110000:35`, `110001:46`; all 7 enums + 5 models confirmed |
| 2 | `(bank_statement_id, payment_repository_id)` anchored to statement repository | **PASS** | Composite FK `110002:53-59` against unique anchor `110001:44`; negative test `BankStatementAggregateSchemaTest.php:140-156` |
| 3 | Delete behavior exact | **PASS** | Cascade `110002:48` / `110003:22` proven at `:309-327`; execution restrict `110004:20-22` proven at `:329-344`; profile null-on-delete `110001:33-36` proven at `:346-353`; immutability app-level `BankStatementMatchExecution.php:43-49` + DB trigger `110004:43-60`, both proven at `:229-250` and `:286-307`. Gap m1 |
| 4 | Explicit legal transitions; derived line status via numeric-string arithmetic + explicit scale | **PASS** | `BankStatementStatus::canTransitionTo` `:14-26` matches §6.6 exactly (incl. blocking `Imported→Reconciled`); `derive()` `:30-37` uses `bccomp` with injected `$scale`, zero floats. Gap m2 |
| 5 | CSV/XLSX behavior matrix | **FAIL** | Preamble, delimiter, encoding, debit/credit, `7.140` trap, zero-drop, malformed-row reporting, formula evaluate-or-unparseable all implemented **and tested**. Signed handling is incomplete — **C1**. Also m3/m4 |
| 6 | Canonical fingerprint normalization; distinct occurrences; bank-id replay stability | **FAIL** | Occurrence indexing `:110-114` ✅ (tested `:116-118`); bank-id identity `:112-113` ✅ replay-stable; reference/label canonicalized `:107-108` ✅ — but amount uncanonicalized, **C2** |
| 7 | No float, no hard-coded/no-arg scale; explicit currency; registry constructor-injected + provider-bound for both keys | **PASS** | `grep` for `(float)`/`floatval`/`number_format`/`getScale()` across all three parser files returns nothing; explicit `getScale($repository->currency)` `:37`; registry `StatementParserRegistry.php:16` + `TreasuryServiceProvider.php:68-74` binds both keys, proven at `CsvStatementParserTest.php:188-194`. See M1 for the separate at-rest formatting gap |
| 8 | Self-guarded, clean five-path rollback, PG constraints genuinely exercised | **FAIL** | Self-guarding `Schema::hasTable` early-return in all five ✅; rollback/reapply/idempotent-reapply proven `:355-380` ✅ — but only 2 of 14 CHECKs exercised, **M2**. Also m3 |

---

## Assessment

**Migration portability.** Correct and disciplined. Every non-portable construct sits behind `DB::connection()->getDriverName() === 'pgsql'`, the function drop in `110004:68-70` is paired with its creation, and FK creation order matches file order. The `Schema::hasTable` guards satisfy the push=deploy self-guarding rule. The SQLite/PG split is handled honestly — tests `markTestSkipped` rather than silently asserting a weaker approximation, which is the right call.

**Parser correctness.** The hard parts are right: preamble skipping reconciles physical-line indexing (`CsvStatementParser.php:29`) with CSV-record counting (`:55`), delimiter detection scores candidates against the header line (`:103-117`), Windows-1252 detection is strict-mode (`:82-92`), and the `7.140` thousands trap is correctly resolved *by profile convention* rather than guessed. The formula guard (`XlsxStatementParser.php:86-100`) checks both calculated and formatted values for `#`/`=` prefixes, which is more careful than typical. The gap is the sign convention (C1) and the display-formatter money path (M3).

**Numeric precision.** No floats anywhere — I verified this by grep, not assumption. The scale resolver is constructor-injected with an explicit currency, which correctly anticipates the queued/console reality of rule 19 and rule 20. The single miss is that the resolved scale is used to *validate* but never to *normalize* (M1), which is what lets C1 and C2 through.

**Registry wiring.** Clean. Array-injected map, enum-keyed, throws on unknown key, bound as a singleton with both launch keys, and the binding itself is asserted rather than assumed. This directly closes the Codex plans review's finding 17 ("no XLSX parser dispatch/binding").

**Test quality.** Above the bar for this codebase. Real models, `RefreshDatabase`, real FK violations caught as `QueryException`, no `assertTrue(true)`, no mocking of the unit under test, and the rollback test actually asserts `Artisan::call` return codes with output on failure. The weakness is coverage breadth on PG constraints (M2), not test integrity.

---

**VERDICT: spec ❌ + quality CHANGES-REQUESTED**

**Required fix before merge:** in `StatementRowMapper::normalizeDecimal()` (`:267-295`), drop `+` from the sign class (or strip it after matching) and return `CurrencyScale::bcformatStrict($value, $scale)` so every parsed amount is a single canonical scale-N string before it reaches `bccomp` (`:72`), the fingerprint (`:106`), and the `decimal(15,3)` column — then add fixture tests for a `+`-signed amount and for the same logical transaction re-exported at a different decimal shape producing an identical fingerprint.
