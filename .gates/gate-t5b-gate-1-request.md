# Treasury Phase ⑤b Gate 1 — statement aggregate schema and parsers

You are the **treasury-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `5673518eb`

Review range: `git diff 5673518eb...HEAD`

Read in authority order:

1. `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md` (Rev 2), especially §§5.1, 6.1, 6.3, 6.6, 8, and 9.
2. The three plan reviews in `docs/superpowers/reviews/2026-07-18-treasury-phase5-plans-{codex,treasury,tenancy-authz}-review.md`.
3. Wave 1 / Tasks 2–3 in `docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md`.
4. `CLAUDE.md` rules 1–21 and `docs/architecture/precision-contract.md`.
5. `.claude/agents/treasury-reviewer.md`; apply the persona fully.
6. The entire committed diff and relevant existing Treasury conventions.

## Required invariants to verify

1. All five tenant tables, seven enums, and five models exist with the normative columns, uniqueness, checks, ownership indexes, and FK order.
2. `(bank_statement_id, payment_repository_id)` on lines is constrained to the matching statement repository through the composite FK anchor.
3. Delete behavior is exact: statement → lines → allocations cascades; match executions restrict deletion; parser profiles null on statement deletion; execution provenance is application- and database-immutable.
4. Statement transitions are explicit and legal; line match status is derived only from amount/allocation state using numeric-string arithmetic and explicit currency scale.
5. CSV and XLSX parsing handles profile preambles, configured dates, Excel serial dates, locale decimals including the `7.140` comma-decimal trap, signed and debit/credit conventions, formula evaluation-or-unparseable behavior, encoding/delimiter detection, zero-row dropping, and malformed row reporting.
6. Fingerprints use canonical trim/collapse-whitespace/case-fold normalization. Identical legitimate rows without bank transaction ids receive distinct occurrence indexes/fingerprints; bank transaction ids remain replay-stable identities.
7. Parser money never uses float arithmetic or a hard-coded/no-argument scale. Repository currency is explicit and registry dispatch is constructor-injected and service-provider bound for both parser keys.
8. Migrations are self-guarded, rollback clean in their exact five-file path, and PostgreSQL constraints are genuinely exercised rather than SQLite-only approximations.

## Current committed evidence

- TDD RED was observed before implementation for both Task 2 and Task 3.
- SQLite Wave 1 verification: 22 tests, 101 assertions, 3 PostgreSQL-only skips:
  - `tests/Feature/Treasury/BankStatementAggregateSchemaTest.php`
  - `tests/Unit/Treasury/CsvStatementParserTest.php`
- PostgreSQL aggregate verification on a fresh disposable database: 16 tests, 76 assertions, no skips.
- Exact five-path rollback/reapply and idempotent migration apply were exercised through the aggregate schema test.
- Pint `--test` on all Task 3 touched PHP files: pass.
- PHPStan on all Task 3 production PHP files: no errors.
- `git diff --check`: pass.

## Required output

After the first-line decision:

1. Findings ordered Critical/BLOCKER → Important/MAJOR → Minor, each with exact `file:line` evidence.
2. A table resolving all eight required invariants as pass/fail.
3. Assess migration portability, parser correctness, numeric precision, registry wiring, and test quality.
4. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and, if rejected, one exact required-fix line.
