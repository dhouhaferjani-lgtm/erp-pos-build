APPROVE

I re-read the full gate range, the remediation commit in isolation (`git diff 344276c1e..91d9814b1`), both migrations sets, all sixteen PostgreSQL CHECK constraints against their PHP enums, the rewritten XLSX raw-value path including the ZIP/relationship/XML handling, and both test files. Every Critical and Major finding is genuinely closed — not papered over. The amount boundary is now a single canonical path, and the CHECK coverage went from 2/16 to 16/16 with real `QueryException` expectations rather than SQLite approximations.

One disclosure up front: **I could not execute PHP or PHPUnit in this environment** (`php -r` and `./vendor/bin/phpunit` both denied). Every finding below is from code inspection. Where runtime behaviour is load-bearing I say so explicitly and identify the discriminating test.

---

## Findings

### CRITICAL

None.

### MAJOR

None.

### MINOR

**m3 (carried, unresolved) — `tests/Feature/Treasury/BankStatementAggregateSchemaTest.php:542` — `'--step' => 5` is still positionally fragile.** Unchanged by the remediation. Correct today because `2026_07_19_110004` sorts last in `database/migrations/tenant/`, but any migration filed after it silently redirects the rollback at the wrong five tables, and `test_migration_down_order_is_fk_safe_and_reapply_is_clean` (`:538`) would then pass while proving nothing.

**m5 (carried, unresolved) — `app/Modules/Treasury/Application/Services/StatementRowMapper.php:254` — `'Y-m-d'` is still tried before the profile's configured `date_format`.** The round-trip guard at `:259` keeps this harmless for the fixture formats, but the profile's format is still not authoritative, and the strict round-trip still rejects non-zero-padded dates (`1/2/2026` under `d/m/Y`).

**m6 (carried, unresolved) — `tests/Unit/Treasury/CsvStatementParserTest.php` is still misnamed.** It now holds the XLSX parser test (`:193`), the XLSX raw-XML fixture helper (`:285`), and the registry dispatch test (`:245`).

**m7 (new) — `XlsxStatementParser.php:140` — the raw-value map is bound to the worksheet by *positional index*, not by name.** `$sheets->item($activeSheetIndex)` assumes PhpSpreadsheet's loaded-worksheet ordinal equals the ordinal of `<sheet>` in `xl/workbook.xml`. That holds for the current call site — the parser never calls `setLoadSheetsOnly()`, and the reader admits both worksheet and chartsheet relationship types into its sheet map (`vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Reader/Xlsx.php:243-245`), so no sheet is skipped. But if a later task adds sheet filtering, the indexes desync *silently* and money strings are read from the wrong sheet at colliding coordinates. Matching on `$sheet->getAttribute('name')` against `getActiveSheet()->getTitle()` would make the coupling explicit. Not a defect today; a latent one.

**m8 (new) — `StatementRowMapper.php:65-89` — m4 is fixed for the *zero-amount* balance row but not the *empty-amount* one.** Balance detection (`:73-89`) is now correctly ahead of the zero-drop `continue` (`:91-95`), and `test_zero_amount_balance_row_is_dropped_after_balance_detection` (`:148`) proves it. But detection still sits *after* `parseDirectionAndAmount` (`:65`) inside the same `try`. A `SOLDE INITIAL` row with a **blank** amount cell throws `'Amount is required.'` at `:279`, unwinds to the catch at `:130`, and the opening balance is lost *and* the row is reported unparseable. Blank-amount balance rows are as common in TN/FR exports as zero-amount ones. The added fixture uses `0`, which covers exactly the case the original finding named and no more.

**m9 (new, hardening only) — `StatementRowMapper.php:130` still catches only `InvalidArgumentException`, which is narrower than `bcformatStrict`'s failure surface.** `CurrencyScale::bcformatStrict` guards with `is_numeric`, which accepts scientific notation that `bcadd` rejects with a `ValueError` — the helper's own docblock warns about this (`app/Shared/Domain/CurrencyScale.php`, bcformatStrict docblock). A `ValueError` would escape `map()` and abort the whole import instead of reporting one row. **I confirmed this is currently unreachable:** every call site routes through `normalizeDecimal`, whose regex (`:288-290`) rejects any `e`/`E`, and the XLSX raw path feeds that same regex, so an exponent-serialized `<v>` fails loud as a per-row unparseable rather than as a `ValueError`. Worth widening to `Throwable` defensively when Task 5 adds callers.

**m10 (process) — TDD RED is asserted, not observable.** The new tests and the implementation land in the same commit (`91d9814b1`), so git history cannot corroborate the RED claim. I verified RED **by inspection** against the pre-remediation code for each new regression test, and all four discriminate correctly — see the resolution table.

---

## Prior-finding resolution

| # | Prior finding | Status | Evidence |
|---|---|---|---|
| **C1** | Leading `+` neither stripped nor rejected | **RESOLVED** | `normalizeDecimal` now returns `CurrencyScale::bcformatStrict($value, $scale)` (`StatementRowMapper.php:295`), so `+1234.5` is canonicalized to `1234.500` before `str_starts_with($signed, '-')` (`:223`), before `bccomp` (`:91`) and before the fingerprint (`:107`). `test_signed_amounts_are_canonicalized_before_fingerprinting` (`CsvStatementParserTest.php:123-145`) drives `+1234.5` end-to-end. **RED by inspection:** pre-remediation `normalizeDecimal` returned `$value` raw, so `:143` would have got `'+1234.5'`. This test is also the discriminator for the unresolved bcmath question in the original C1 — it would *error* with a `ValueError`, not fail, if `bcadd` rejected the `+`; the reported 41-test pass is therefore the runtime evidence I could not re-derive myself |
| **C2** | Fingerprint hashes a non-canonical amount | **RESOLVED** | `$amount` at `:107` is now the `bcformatStrict` output, so one scale per currency. Proven convergent, not merely formatted: `:143-145` asserts two files (`+1234.5 / " REF-001 " / "Monthly   fee"` vs `1234.500 / ref-001 / monthly fee`) produce the **same fingerprint**. Scale drift within one file is gone — `'1234.560'` (`:65`) and `'42.500'` (`:68`) and `'7140.000'` (`:115-116`) are now all scale-3 |
| **M1** | At-rest amount never crosses `bcformatStrict` | **RESOLVED** | `use App\Shared\Domain\CurrencyScale` (`:15`); applied at `:276` (empty-is-zero) and `:295` (all parsed values), both with the explicitly-resolved `$scale` threaded from `getScale($repository->currency)` (`:38`). `optionalBalance` (`:314`) routes through the same helper, so detected opening/closing are canonical too. Rule 19's at-rest contract is now satisfied verbatim |
| **M2** | 2 of 14 CHECKs exercised | **RESOLVED** | Now **16/16** (the count rose to 16 with the new `ignore_reason` CHECK). Profiles `110000:40,41` → tests `:106,:115`; statements `110001:52,53` → `:124,:133`; lines `110002:62-81` → `:202` (amount), `:224`, `:233`, `:242`, `:264` (reason), `:251` (shape); allocations `110003:41,42` → `:306`, `:326`; executions `110004:39-42` → `:401`, `:413`, `:425`, `:438`. All gated via `requirePostgres()` (`:704`) so SQLite skips honestly rather than asserting a weaker approximation |
| **M3** | XLSX money read through `getFormattedValue()` | **RESOLVED** | Replaced by `readRawNumericValues` (`XlsxStatementParser.php:124-197`), reading exact `<v>` text from worksheet XML. `getFormattedValue()` is gone from the file entirely (grep: zero hits). The regression is genuinely discriminating: `1234.567` under format code `#,##0.00` (`CsvStatementParserTest.php:209-210`) asserts `'1234.567'` (`:240`) — the old path would have returned `"1,234.57"` → `1234.570`. **RED by inspection.** Formula behaviour is preserved correctly: serialized evaluated value when `<v>` is present (`:99`), `getCalculatedValueString()` fallback when absent, `#`/`=` prefix → unparseable (`:103-105`), still proven by the `=1/0` row now at row 6 (`:241`) |
| **m1** | Execution DELETE immutability untested | **RESOLVED** | DB trigger (`110004:57-59`) now covered by `test_execution_rows_reject_direct_database_deletes_on_postgres` (`:473`), model guard (`BankStatementMatchExecution.php:47-49`) by `test_execution_model_rejects_deletes` (`:483`). Both paths independently proven, no longer masked by the FK `restrictOnDelete` |
| **m2** | `derive()` reads over-allocation as `Partial` | **RESOLVED** | `StatementLineMatchStatus.php:31-33` now throws on `$comparison > 0` **and** on a negative total, before any `Partial` derivation. Covered by `test_derived_line_status_rejects_overallocation` (`:98-103`). **RED by inspection:** pre-remediation `derive` returned `Partial` for `('100.001','100.000')` |
| **m3** | `--step 5` positionally fragile | **OPEN** | Unchanged at `:542`. See m3 above |
| **m4** | Balance detection after zero-drop | **PARTIALLY RESOLVED** | Ordering fixed (`StatementRowMapper.php:73-89` now precedes the `continue` at `:94`), tested at `CsvStatementParserTest.php:148-169`. **RED by inspection.** Empty-amount balance rows remain lost — see m8 |
| **m5** | `'Y-m-d'` tried before profile format | **OPEN** | Unchanged at `:254`. See m5 above |
| **m6** | Test file misnamed | **OPEN** | Unchanged. See m6 above |

---

## Gate invariants — round 2

| # | Invariant | R1 | R2 | Evidence |
|---|---|---|---|---|
| 1 | 5 tables / 7 enums / 5 models, normative columns, uniqueness, checks, ownership indexes, FK order | PASS | **PASS** | Unchanged and still correct; CHECK set grew to 16 with `110002:67` |
| 2 | `(bank_statement_id, payment_repository_id)` anchored to statement repository | PASS | **PASS** | Composite FK `110002:53-59`; negative test `:184` |
| 3 | Delete behaviour exact; provenance app- and DB-immutable | PASS | **PASS** | m1 closed — all four paths (app update, DB update, app delete, DB delete) now proven at `:344`, `:450`, `:483`, `:473`. Trigger paired with its function drop (`110004:66-68`) |
| 4 | Explicit legal transitions; derived status via numeric-string arithmetic + explicit scale | PASS | **PASS** | m2 closed — `derive()` now raises on over/negative allocation (`StatementLineMatchStatus.php:31-33`) |
| 5 | CSV/XLSX behaviour matrix | **FAIL** | **PASS** | C1 closed (signed handling canonical, `:123`); M3 closed (raw-XML money, `:240`); m4 ordering closed (`:148`). Residual m8 is a narrower gap than the original finding and non-blocking |
| 6 | Canonical fingerprint normalization; distinct occurrences; bank-id replay stability | **FAIL** | **PASS** | C2 closed — amount canonicalized, cross-file fingerprint convergence asserted at `:145`; occurrence indexing (`:111-115`) and bank-id replay stability (`:113-114`) unchanged and still correct |
| 7 | No float / hard-coded / no-arg scale; explicit currency; registry DI-bound for both keys | PASS | **PASS** | grep for `(float)`/`floatval`/`number_format`/`getFormattedValue`/`getScale()` across all three parser files returns nothing but two legitimate `is_float` type guards on Excel date serials (`XlsxStatementParser.php:90`). M1's at-rest gap now closed. Registry dispatch still proven at `:245` |
| 8 | Self-guarded, clean five-path rollback, PG constraints genuinely exercised | **FAIL** | **PASS** | M2 closed — 16/16 CHECKs directly exercised. `Schema::hasTable` guards intact in all five; rollback/reapply proven at `:538`. Residual m3 is a maintenance hazard, not a coverage gap |

---

## Assessment

**Enum ↔ CHECK agreement.** I cross-checked all six constrained columns against their PHP enums, since this is exactly where a hand-written CHECK drifts. All agree exactly: `StatementLineIgnoreReason` (5 cases) ↔ `110002:67`; `MatchActionType` (6) ↔ `110004:39`; `StatementMatchType` (3) ↔ `110003:42`; `BankStatementStatus` (4) ↔ `110001:52`; `StatementParserKey` (2) ↔ `110000:40`; `StatementDirectionConvention` (2) ↔ `110000:41`. Round-2 check 6 passes. Note that spec §5.1 (`…design.md:99`) mandates `ignore_reason` as "enum + text, required when `Ignored`" without enumerating values, so the enum is implementation-chosen and the CHECK correctly tracks it; the four-way shape constraint (`110002:69-81`) matches the spec's "required when Ignored" in both directions and is now directly tested (`:251`).

**XLSX raw-value path.** I inspected this beyond the happy test, as asked. Relationship resolution walks `workbook.xml` → `xl/_rels/workbook.xml.rels` by `r:id` (`:144-165`) rather than guessing `sheet1.xml`, and fails loud if the target is missing. Path traversal is not exploitable: `worksheetEntryName` (`:214-232`) normalizes `..` segments, and even a crafted `../../../etc/passwd` target resolves to `etc/passwd` passed to `$zip->getFromName()` — a lookup *inside the archive*, never the filesystem. XML loads with `LIBXML_NONET` and without `LIBXML_NOENT`, so external entities are not fetched and internal entities are not substituted. Cell-type filtering (`:188`) admits only untyped/`n` cells plus any formula cell, which correctly excludes shared strings (`t="s"`), inline strings, and booleans (`t="b"`) from the raw map while letting a formula's `t="e"` error value through to the `#`-prefix guard at `:103`. Dates are intercepted at `:88` before either raw or formula handling. The design is sound; my only reservation is the positional sheet binding (m7).

**Numeric precision.** Now fully compliant with rule 19. The resolved scale is used to validate (`:288-290`), to compare (`:91`), *and* to format (`:276`, `:295`) — the third of those was the whole defect. Truncation is not a hazard because the regex ceiling caps decimals at exactly `$scale` before `bcadd` ever truncates. `-0` inputs normalize through bcmath to `0.000` and drop cleanly as zero-amount rows.

**Test quality.** No test was weakened. The three changed assertions (`'1234.56'→'1234.560'`, `'7140'→'7140.000'`, `'120'→'120.000'`) are canonicalization consequences and are strictly more specific. The XLSX test *gained* a row rather than trading one away — the `=1/0` unparseable case moved from row 5 to row 6 and its assertion is preserved verbatim (`:241`). The `replaceRawWorksheetValue` helper (`:285-298`) is not a tautology: it pins the serialized `<v>` deterministically against float-precision ini drift, while the discriminating factor for M3 remains the `#,##0.00` format code, which the old display-formatter path would have honoured regardless of the raw XML. The helper asserts `$count === 1`, so a fixture that silently failed to patch would fail the test rather than pass vacuously.

**What I could not verify.** No PHP execution. The reported figures — 41 tests / 118 assertions / 18 PG skips on SQLite, 33 / 110 / 0 on fresh PostgreSQL, Pint, PHPStan, `git diff --check` — are taken as reported. The skip count is consistent with my read: I count 18 `requirePostgres()`-or-inline-gated tests in the aggregate file, and 33 non-skipped on PG against 41 total minus the 8 unit tests in the parser file is arithmetically coherent.

---

**VERDICT: spec ✅ + quality APPROVED**

Gate 1 passes. m3, m5, m6, m7, m8, m9 are non-blocking; I recommend folding m8 (blank-amount balance row) and m3 (`--step 5` anchored to a named migration rather than a count) into Wave 1 Task 5 rather than opening a separate remediation round.
