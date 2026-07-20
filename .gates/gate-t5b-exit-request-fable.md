# Treasury Phase ⑤ whole-branch exit review — Fable

Review `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5` on branch `feat/treasury-phase5` at HEAD. This is the mandatory final whole-branch review after both Gate 5 Opus lanes pass. Gate only: do not edit, commit, merge, tag, or push.

Act as the senior treasury/fiscal/tenancy/frontend arbiter. Be adversarial and code-first. Any Critical or Important defect means REJECT. Cite exact `file:line` evidence. Do not trust prior verdicts or reported test counts without sampling the underlying code/tests.

## Authority

Read in order:

1. `docs/handoff/CODEX-treasury-phase5-2026-07-18.md`
2. `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md` (Rev 2)
3. the three `docs/superpowers/reviews/2026-07-18-treasury-phase5-plans-*-review.md` files
4. both Phase ⑤ plans
5. `CLAUDE.md` rules 1–21 and `docs/architecture/precision-contract.md`
6. every final approved verdict under `.gates/`, including Gate 5's treasury and frontend-conventions verdicts

Review the complete implementation diff:

```bash
git diff fd10632fb..HEAD -- apps/api apps/web packages/shared/types docs/superpowers/plans docs/handoff docs/sessions/treasury-phase5a-e2e docs/sessions/treasury-phase5b-e2e .gates
```

## Mandatory exit invariants

1. Outbound issue/clear/bounce/re-present/cancel is replay-safe, append-only, and GL-correct. Deferred supplier issue creates exactly one JE, no bank line/movement, and suppresses the old immediate-settlement writer.
2. Matching is metadata, never money. Execution action replay precedes handler/transition validation; movement rows are locked in stable ID order before allocation totals; signed sums and rule-19 precision hold.
3. Tier 4 keeps payment method + fiscal business date groups separate and posts exactly one configured fee JE/movement. Statement-created expense/income crosses Treasury→Expense only through synchronous events.
4. Completion and nightly reconcile share the signed ignored-total identity. `matched`, `resolved_by_creation`, and `ignored` resolve a line; created/ignored lines contribute zero remaining. Completion stamps an end-of-period checkpoint.
5. Both movement-port `record()` and `transfer()` reject interactive writes on or behind the checkpoint before partial effects; offline projection uses only `recorded_behind_checkpoint` and alerts.
6. Tenant/company ownership, existing route-group middleware, scoped FK validation, and exact permission splits hold. Legacy reconciliation mutation/summary surfaces are gone; UI routes to `/treasury/statements`.
7. Fiscal payment-method resolution is tenant+company scoped, replay-stable, and does not modify canonical fiscal payloads.
8. Rules 19/20: money is decimal string + bcmath/big.js with explicit currency scale; no float money; queued projections do not rely on CompanyContext.
9. Live evidence is honest: Phase ⑤a issued→cleared→bounced→re-presented; Phase ⑤b uploaded→matched Tier 1/3/4→created→ignored+acknowledged→completed; same-date adjustment and outbound clear returned 422; reconcile reported zero errors.
10. No required test was weakened to pass a gate, no unresolved Critical/Important finding survives, and deploy/rollback instructions match the shipped schema and commands.

## External merge prerequisite

Independently check `origin/dev`. The contract says multi-location §3 must have landed before any Phase ⑤ merge. At request-writing time, `origin/dev` did **not** contain tag `multiloc-gate-3a`, the §3 schema migration, or the full §3 package; local `feat/multi-location` had reached only `multiloc-gate-3a` (Tasks 1–3). Treat this as a required PARK/NO-MERGE condition in the exit report, but distinguish it from a defect in the Phase ⑤ implementation. Do not merge anything.

## Output

First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT` for the Phase ⑤ implementation.

Then list findings by severity, the ten-invariant pass/fail table, prior-gate/deviation assessment, test/evidence assessment, and an explicit `MERGE PREREQUISITE: SATISFIED` or `MERGE PREREQUISITE: NOT SATISFIED` based on current `origin/dev`. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and the exact controller condition.
