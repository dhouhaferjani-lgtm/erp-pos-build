# Treasury Phase ⑤b Gate 2 R3 — Fable-directed remediation review

You are the **treasury-reviewer**. Review adversarially and code-first. Start with exactly `APPROVE` or `REJECT` on its own line. Cite actual `file:line` evidence for every finding. Do not edit, commit, merge, tag, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Gate base: `e06d2a831` (`t5b-gate-1`)

Current remediation commit: `d87126b38`

Read the complete authority chain, both prior treasury verdicts, the R2 tenancy verdict, and especially `.gates/gate-t5b-gate-2-escalation-verdict.md`. Fable returned `UPHOLD` / `GATE BLOCKED` with a binding eight-step checklist. Review `git diff e06d2a831...d87126b38` and adjacent code. Tenancy re-review is not required because routing and permissions did not change.

Verify every gate-blocking Fable item:

1. Fingerprint reads use `FINGERPRINT_LOOKUP_CHUNK = 500`, accumulate matches across chunks, filter only `dedupe_active`, and retain `lockForUpdate` during confirm.
2. Line persistence uses `LINE_INSERT_CHUNK = 500` bulk inserts under the repository transaction with explicit UUIDs, enum strings, numeric-string amounts, all prior columns, and `dedupe_active = true`.
3. Void never deletes lines; it sets `dedupe_active = false` only after zero-allocation/zero-execution guards, preserves ignore metadata/source evidence, and returns a real line count.
4. The Gate 1 create migrations are restored. New tenant migration `110005` upgrades fresh and stale branch DBs: adds/backfills `dedupe_active` (including existing voided statements), converts both constraints/indexes to partial indexes, supports pgsql+sqlite only, and can run twice safely.
5. Tests prove a 1,201-row non-multiple chunk boundary, structural bind limits, bounded insert-query count, full-row bulk fidelity, overlapping duplicates across chunk boundaries, void provenance, same-file re-import, active-vs-voided index semantics, PostgreSQL predicates, and stale migration upgrade/idempotence.
6. The binding active-row interpretation is recorded verbatim in both `docs/handoff/HANDBACK-treasury-phase5.md` and `docs/handoff/treasury-phase5b-deploy-checklist.md` without editing locked Rev 2.
7. Opportunistic carries fixed here: balance validation now has `numeric` plus 3-decimal regex ceiling; void response line count is populated. Deployment storage/cleanup obligations are explicit in the checklist.
8. Reassess all original Gate 2 invariants, staging purity, replay safety, and repository-lock duration. Do not require a >65,535-row fixture: Fable explicitly bound approval to the 1,201-row functional test plus structural bind assertions.

Evidence from the committed tree:

- TDD RED: void response returned null line count before provenance fix; stale PostgreSQL upgrade left a voided line `dedupe_active = true` before backfill fix.
- SQLite path-only run: 63 tests, 244 assertions, 20 PostgreSQL-only skips.
- Fresh PostgreSQL path-only run: `StatementImportFlowTest.php` + `BankStatementAggregateSchemaTest.php`, all tests passed (process exit 0).
- Targeted PostgreSQL predicate/upgrade/migrate cycle: 4 tests, 25 assertions; focused stale-upgrade backfill: 1 test, 5 assertions.
- Pint on every modified PHP path: pass.
- PHPStan on every modified production PHP path: no errors.
- `git diff --check`: pass.

Required output: after the first-line decision, findings ordered BLOCKER/MAJOR/MINOR with exact evidence; an eight-item Fable-remediation table; original Gate 2 invariant table; explicit staging/replay/concurrency/lock-duration assessment; end with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one exact fix line if rejected.
