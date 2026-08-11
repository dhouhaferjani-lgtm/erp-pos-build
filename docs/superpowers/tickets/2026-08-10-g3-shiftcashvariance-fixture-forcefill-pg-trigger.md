# Ticket: ShiftCashVarianceAdjustmentTest fixture violates forbid_direct_balance_write on PG (C-7, G3-owned)

**Filed:** 2026-08-10 (DPA session 2 — H-3 lane debt; finding first surfaced by the H-3 fix round, independently reproduced by two reviewers)
**Owner:** G3 shift-variance lane (`feat/dpa-g3-shift-variance-gl`, merged; fixture is theirs)
**Severity:** test-infrastructure only — no production code implicated

## Defect

`apps/api/tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php:413` does
`forceFill(['balance' => '1.000', …])->save()` on a `payment_repositories` row. On real PostgreSQL
this is refused by the `forbid_direct_balance_write()` trigger:

> `payment_repositories.balance may only be changed via TreasuryMovementService (spec §5)`

Result: 1 failed / 21 passed on PG (local 5432). SQLite has no such trigger, so the suite is green
there — the failure only appears on PG runs.

## Evidence

- Reproduced 2026-08-10 on PG 5432 by the H-3 fix-round implementer (revert-replayed against the
  pre-fix resolver: identical failure; the file is absent from `git diff --name-only
  5e817b150..064f4332b`, proving it is pre-existing and not H-3's).
- Independently reproduced by the H-3 round-1 re-reviewer the same night (1 failed / 21 passed).

## Fix shape

Replace the `forceFill` balance write with the legitimate path: drive the balance through
`TreasuryMovementService` (an opening/adjustment movement intent), as every other PG-legal fixture
in the Treasury suite does. Do not weaken or bypass the trigger.

## Related

- Same PG-only-fixture class as CF's seals-then-mutates fixture repair (draft-then-seal, 2026-08-09).
- Candidate for the backend-test-pgsql CI allowlist once green on PG.
