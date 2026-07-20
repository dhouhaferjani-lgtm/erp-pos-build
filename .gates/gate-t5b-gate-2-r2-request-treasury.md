# Treasury Phase ⑤b Gate 2 R2 — statement import remediation (treasury review)

You are the **treasury-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `e06d2a831` (`t5b-gate-1`)

Remediation commit: `abccafa6f`

Read in authority order: spec Rev 2 §§5.1–5.3, 6.6, 7–9; all three plan reviews; Wave 2 Task 4 in the ⑤b plan; CLAUDE.md rules 1–21 and the precision contract; `.claude/agents/treasury-reviewer.md`; the original Gate 2 request/verdict; then `git diff e06d2a831...abccafa6f` and adjacent code.

The first review rejected on three required changes. Verify each against committed code and tests:

1. A voided file can be imported again: duplicate lookup ignores `Voided`, the file uniqueness index is partial on `status <> 'voided'`, and void removes the invalid import's metadata lines only after proving zero allocations/executions so repository fingerprint uniqueness does not turn re-import into an empty import. The source statement/file audit row remains; no financial row is deleted.
2. Confirm parses and performs pre/post file-integrity checks before the repository transaction. A parser-profile digest and locked recheck prevent profile TOCTOU; duplicate/fingerprint checks and inserts remain under the repository/profile locks.
3. Direct tests cover non-bank repository, inactive repository, inactive profile, token expiry, integrity mismatch, allocation-blocked void, reconciled-blocked void, same-file re-import, parser placement outside the service transaction, referenced-profile deletion, and profile mutation after preview.

Also reassess all eight invariants in the original request and explicitly call out any spec conflict created by the remediation.

Evidence:

- SQLite targeted paths: 59 tests, 212 assertions, 18 PostgreSQL-only skips.
- Fresh PostgreSQL: `StatementImportFlowTest.php` + `BankStatementAggregateSchemaTest.php` passed (31 tests; process exit 0).
- Pint on modified paths: pass.
- PHPStan on modified production/test paths: no errors.
- `git diff --check`: pass.

Required output: after the first-line decision, findings ordered BLOCKER/MAJOR/MINOR with exact evidence; remediation pass/fail table; staging-purity and replay/concurrency assessment; end with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one exact fix line if rejected.
