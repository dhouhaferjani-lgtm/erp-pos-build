# Treasury Phase ⑤a exit — mandatory Fable escalation after two consecutive REJECTs

You are the **Fable treasury gate arbitrator**. Two consecutive Opus whole-branch exit reviews rejected Phase ⑤a, so the operating contract requires your independent adjudication. You gate only: do not edit, commit, merge, or push.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5`

Branch: `feat/treasury-phase5`

Design base: `fd10632fb`

Review range: `git diff fd10632fb...HEAD`

Read in authority order:

1. `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md` (Rev 2; Phase ⑤a).
2. The three Phase ⑤ plan-review files in `docs/superpowers/reviews/`.
3. `docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md`.
4. `CLAUDE.md` rules 1–21.
5. `.gates/gate-t5a-exit-verdict-treasury.md` (round 1 REJECT).
6. `.gates/gate-t5a-exit-r2-verdict-treasury.md` (round 2 REJECT).
7. The actual branch diff, tests, routes, UI, and deploy checklist.

## Arbitration questions

Independently adjudicate the round-2 findings; do not defer to Opus:

1. **B1:** Can `InstrumentLifecycleService::cancel()` be invoked on a Phase ⑤a outbound issued instrument, and if so does it mutate status without the required GL reversal, action-key idempotency, and Expense/payment reopen? Is it reachable through the shipped `InstrumentDetailPage` Cancel button? Is this exit-blocking?
2. **M-A:** Does Stancl `tenants:run` discard child command exit codes such that the deploy checklist's fail-loud backfill can return shell success despite an inactive/wrong-type/missing-parent account? What deployment detection is minimally sufficient?
3. **M-B:** Does the Phase ⑤a diff introduce prohibited Expense→Treasury model coupling in `ExpenseMetadata` and `SyncExpenseOnInstrumentLifecycle`, or are those imports pre-existing/authorized by the Phase ⑤ design? If a boundary fix is required, state the minimum contract/event payload shape.
4. Identify any incorrect severity, false statement, or overreach in the round-2 verdict.

## Required output

Start with exactly one of:

- `UPHOLD REJECT`
- `OVERTURN TO APPROVE`

Then provide:

- A decision for B1, M-A, and M-B with exact `file:line` evidence.
- The authoritative minimal fix set required before the next Opus exit rerun, if any.
- Any round-2 claims that should be ignored as non-blocking.
- End with `ARBITRATION: spec ✅/❌ + gate APPROVED/CHANGES-REQUIRED`.
