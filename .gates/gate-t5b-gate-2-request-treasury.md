# Treasury Phase ⑤b Gate 2 — statement import flow (treasury review)

You are the **treasury-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `e06d2a831` (`t5b-gate-1`)

Review range: `git diff e06d2a831...HEAD`

Read in authority order: spec Rev 2 §§5.1–5.3, 6.6, 7–9; all three plan reviews; Wave 2 Task 4 in the ⑤b plan; CLAUDE.md rules 1–21 and the precision contract; `.claude/agents/treasury-reviewer.md`; then the full committed diff and adjacent parser/schema code.

## Required checks

1. Upload is against an active bank-account repository and an active repository-bound profile; CSV/XLSX file is stored under the private local disk root, hashed before storage, and an identical file is rejected with the existing statement id.
2. Preview persists no statement/line/allocation/execution/GL/movement rows and reports mapped rows, detected balances, dropped zero rows, duplicate fingerprints, unparseable rows, and accepted count.
3. The encrypted preview token binds tenant, company, repository, profile, stored path, hash, and issue time. Confirm verifies token age/ownership/file integrity and reparses rather than trusting client-supplied lines.
4. Confirm locks the repository, replay-checks duplicate file state before insert, skips overlapping fingerprints, requires `acknowledge_empty` for zero accepted lines, and creates statement+lines atomically as `Imported`.
5. Repository currency is persisted as historical truth; request currency must match; balances and line amounts obey rules 19/20 with explicit currency scale and no floats.
6. Continuity compares against the previous reconciled statement, warns without blocking, and location defaults from the repository.
7. Void permits only `Imported|Reconciling → Voided` with zero allocations and zero executions, under lock.
8. Wave 2 is pure staging: no GL entries and no repository movements. Parser follow-up for blank-amount balance rows does not weaken prior Gate 1 guarantees.

## Evidence

- TDD RED: all new endpoints initially returned 404; parser blank-balance regression failed under the pre-fix ordering.
- SQLite: `StatementImportFlowTest.php` + `CsvStatementParserTest.php`: 21 tests, 117 assertions.
- Fresh PostgreSQL: `StatementImportFlowTest.php`: 12 tests, 68 assertions.
- Pint on all Task 4 PHP paths: pass.
- PHPStan on all Task 4 production PHP paths: no errors.
- Route list shows exactly five new `/api/v1/bank-statements` routes within the existing Treasury file/group.
- `git diff --check`: pass.

## Required output

After the first-line decision: findings ordered BLOCKER/MAJOR/MINOR with exact evidence; an eight-invariant pass/fail table; staging-purity and replay/concurrency assessment; end with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one exact fix line if rejected.
