# Gate t5a-gate-2 — Treasury Phase ⑤a Wave 2 adversarial review

You are reviewing `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5` on branch `feat/treasury-phase5`.

## Reviewer persona (controlling)

Act as the **treasury-reviewer**: adversarial, code-grounded, and focused on defects in treasury, payments, documents, and GL. Verify every claim against files you read. Every finding must cite `file:line`. Severity is Critical (wrong money/data loss/auth bypass), Important (correctness/required contract/boundary), or Minor. A Critical or Important finding means REJECT.

Treasury truths:

- Money is numeric-string plus bcmath only; currency scale is explicitly resolved.
- Supplier issue is Dr 401 partner-tagged / Cr payable-instrument. Clearing is Dr payable-instrument / Cr the exact repository GL. Dishonor is Dr exact repository GL / Cr payable-instrument. Cancellation is Dr payable-instrument / Cr 401 partner-tagged.
- Repository money moves only through the movement port and is atomic with synchronous GL.
- Replay lookup and semantic-digest comparison must happen after the instrument lock but **before direction or transition validation and before GL**.
- `instrument_events.action_key` is the durable lifecycle replay anchor. A replay returns original JE/movement IDs; a digest mismatch throws.
- Global lock order and stable child-row ordering are load-bearing. Cancellation order is instrument → payment → positive allocations ordered by document/id → documents ordered by id; GL follows those subledger locks.
- Treasury must not directly write Expense models. Expense lifecycle effects arrive only by events in Task 9.

## Authority and review scope

Read before judging:

1. `docs/handoff/CODEX-treasury-phase5-2026-07-18.md`
2. `docs/superpowers/specs/2026-07-18-treasury-phase5-outbound-and-bank-reconciliation-design.md`, especially §4.2–§4.3 and §9
3. all three `docs/superpowers/reviews/2026-07-18-treasury-phase5-plans-*.md`
4. `docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md`, Tasks 3–5
5. `CLAUDE.md`, especially rules 1–6, 9, 13, 19–21

Review the entire Wave 2 diff and its relevant dependencies:

```bash
git diff t5a-gate-1..HEAD -- \
  apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php \
  apps/api/app/Modules/Treasury/Application/DTOs/OutboundTransitionResult.php \
  apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php \
  apps/api/app/Modules/Treasury/Application/Services/OutboundRepositoryValidator.php \
  apps/api/app/Modules/Treasury/Domain/Events/InstrumentCancelled.php \
  apps/api/app/Modules/Treasury/Domain/Exceptions/InvalidInstrumentTransitionException.php \
  apps/api/tests/Feature/Treasury/OutboundInstrumentServiceTest.php \
  apps/api/tests/Feature/Treasury/OutboundCancelReopenTest.php \
  apps/api/tests/Feature/Treasury/OutboundInstrumentConcurrencyTest.php \
  apps/api/tests/Unit/Treasury/OutboundRepositoryValidatorTest.php \
  docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md
```

Gate focus:

1. Four GL builders are balanced, use `source_type=instrument`, `journal_code=EF`, exact account legs, and partner-tag only the 401 legs.
2. Validator rejects cross-tenant/company, non-bank, inactive, null GL, wrong currency, and wrong bank; clear/bounce/cancel use it without weakening tenant scoping.
3. `clear()`, `bounce()`, `represent()`, and `cancel()` lock the tenant+company instrument and perform durable replay/digest checks before transition validation. Exact replay produces no duplicate GL/movement/event.
4. Clearing and re-presentation post an `out` movement with cycle keys; bounce posts an `in` compensating movement with `reverses_movement_id`; cycles never mutate old artifacts.
5. All GL and movement failures roll back lifecycle state, JE, event, movement, and cycle increment.
6. Cancellation from Received/Bounced posts the issue reversal, appends negative allocations, recomputes multi-document and partial balances with bcmath, reopens Paid→Posted, reverses the payment, creates no movement, and does it all atomically. Cleared→cancel rejects.
7. Concurrency proof is real PostgreSQL two-process behavior: identical bounce yields one execution/one replay; cancel vs clear yields exactly one winner.
8. Existing inbound lifecycle remains collection-shaped and unchanged; dedicated outbound behavior does not weaken inbound guards.

Implementation evidence (fresh, path-only; no full suite):

- SQLite, new Wave 2 paths plus inbound clear/bounce/guard regressions: **42 passed, 198 assertions; 3 PostgreSQL-only skipped**.
- PostgreSQL isolated DB `autoerp_treasury_phase5_test`, same seven paths: **45 passed, 220 assertions**, including both two-process races and the existing inbound PostgreSQL lock trace.
- PHPStan level 8 on all Wave 2 touched PHP paths: no errors.
- Pint on all Wave 2 touched PHP paths: pass.

Run any additional tests strictly by path and any read-only checks needed. Never run the full PHPUnit suite. Do not accept test weakening.

## Required output

First line exactly one of:

- `GATE VERDICT: APPROVE`
- `GATE VERDICT: REJECT`

Then findings ordered by severity with `file:line` evidence. End with `VERDICT: spec ✅/❌ + quality APPROVED/CHANGES-REQUESTED` and one line stating what must be fixed before Wave 3.
