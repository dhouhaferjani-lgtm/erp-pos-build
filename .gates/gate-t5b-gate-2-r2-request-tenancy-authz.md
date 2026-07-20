# Treasury Phase ⑤b Gate 2 R2 — statement import remediation (tenancy/authz review)

You are the **tenancy-authz-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `e06d2a831` (`t5b-gate-1`)

Remediation commit: `abccafa6f`

Read in authority order: spec Rev 2 §§5.3, 7–8; all three plan reviews; Wave 2 Task 4; CLAUDE.md rules 1–21; `.claude/agents/tenancy-authz-reviewer.md`; the original Gate 2 request/verdict; then `git diff e06d2a831...abccafa6f` and relevant middleware/permission conventions.

The first tenancy/authz review approved with two Major carry-forwards and one Minor. Recheck the full original eight-check matrix plus these changes:

1. Deleting a profile referenced by a statement now returns 422 and directs the operator to deactivate; deactivation preserves historical `parser_profile_id`.
2. Preview tokens now bind a digest of all parser-affecting profile fields; confirm checks ownership/activity/digest before parsing and repeats ownership/activity/digest under lock.
3. The void/re-import and transaction-lock remediation must not weaken company scoping, token isolation, permission enforcement, or error disclosure.
4. Confirm the final deploy checklist still owes tenant migrations, permission reseed plus `permission:cache-reset`, node-stable/shared private staged-file storage, and staged-file retention/cleanup.

Evidence:

- SQLite targeted paths: 59 tests, 212 assertions, 18 PostgreSQL-only skips.
- Fresh PostgreSQL: `StatementImportFlowTest.php` + `BankStatementAggregateSchemaTest.php` passed (31 tests; process exit 0).
- Pint pass; PHPStan no errors; `git diff --check` pass.

Required output: after the first-line decision, findings ordered BLOCKER/MAJOR/MINOR with exact evidence; an eight-check pass/fail table; middleware/permission/scoping assessment; explicit decision on guarded `{bankStatement}`; end with `VERDICT: tenancy ✅/❌ + authz ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one exact fix line if rejected.
