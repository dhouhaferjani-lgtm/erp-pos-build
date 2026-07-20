# Gate t5b-gate-4 round 2 — ignored-statement reconciliation remediation (Fable)

Act again as the adversarial **treasury-reviewer** defined in `.claude/agents/treasury-reviewer.md` and copied into `.gates/gate-t5b-gate-4-request.md`. Verify code directly, cite `file:line`, and do not edit files. This remains a Fable gate because Wave 4 changes the shared money-movement port.

## Authority and scope

Re-read, in order:

1. Spec Rev 2 `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`, especially §§6.3, 6.5–6.7.
2. The three Phase ⑤ plan reviews.
3. Plan ⑤b Wave 4 Tasks 8–9.
4. `apps/erp/CLAUDE.md` rules 1–21.
5. Original request `.gates/gate-t5b-gate-4-request.md` and your rejection `.gates/gate-t5b-gate-4-verdict.md`.

Review the remediation commit in isolation and then re-evaluate the whole Gate 4 range:

```bash
git diff 5fe62aea8...HEAD -- apps/api .gates/gate-t5b-gate-4-verdict.md
git diff cc68744fd...HEAD -- apps/api docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md docs/handoff/treasury-phase5b-deploy-checklist.md
git log --oneline cc68744fd..HEAD
```

Remediation commit: `6f8385600 Phase 5.4.3: Correct ignored statement reconciliation`.

## Required checks

1. Confirm your Important finding is closed in both places: completion and `treasury:reconcile` now use `signed non-ignored == (closing - opening) - signed ignored`, with bcmath at explicit currency scale.
2. Confirm the fixture is now an internally consistent bank statement (opening 0, closing 10, ignored IN 10), accountant-with-ack fails, admin-without-ack fails, admin-with-ack completes, and the durable audit payload records signed ignored `10.000` plus acknowledgment true.
3. Confirm a reconciled statement with that same consistent ignored shape does not false-alert as tampered, while the existing genuine tamper test still alerts and never freezes.
4. Confirm ordinary zero-ignored completion and signed mismatch rejection remain correct.
5. Confirm the day-after boundary test uses a completion-produced Africa/Tunis end-of-day checkpoint and proves a normal interactive movement is durably accepted without the behind-checkpoint flag.
6. Confirm the over-claimed concurrency test was honestly renamed and the load-bearing two-process race assertions were not weakened.
7. Reconfirm no new Critical/Important issue was introduced and the original Gate 4 mandatory checks remain satisfied.

Fresh evidence after remediation:

- RED: consistent ignored HTTP completion returned 422 at the old delta check; nightly reconcile returned failure/false tamper for the same consistent shape.
- SQLite remediation set: 35 passed / 142 assertions / 2 expected PostgreSQL-only skips.
- Fresh isolated PostgreSQL remediation set: 37 passed / 163 assertions / zero skips, including both two-process races.
- PHPStan on all remediation PHP paths: zero errors. Pint pass. `git diff --check` pass.
- Disposable PostgreSQL DB removed and absence confirmed.

Run only explicit test paths if desired; never the full PHPUnit suite.

## Required output

First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT`. Any Critical/Important finding requires REJECT. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and the condition for Wave 5.
