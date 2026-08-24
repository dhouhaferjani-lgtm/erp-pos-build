# Gate record — Session B lane Q-3 (loyalty redemption), treasury-reviewer r1

Commit `ccc7f7877`, branch `fix/sb-q3-loyalty-redemption-lock`.

**Verdict: CHANGES-REQUESTED** (spec a/b/c verified incl. PG lock+conditional-debit execution;
d/e shipped-but-inert; narrative + one merge-time behavior change fail).

Findings:
- **F-2 [Important, blocking]**: the lane resurrects `POST /loyalty/pos/redeem` (dead since
  birth — created_at NOT NULL 500 proven by revert-probe). Post-merge a cashier tap debits
  points, writes the ledger row, shows `redeemSuccess` — and delivers NOTHING: redeem response
  (`TransactionData`) has no `reward_value`/`points_spent`, the sole client fabricates that
  type (rule 7) and voids the value; no `RewardRedeemedV2` listener exists. Silent points loss.
  Fix: keep the endpoint explicitly gated until contract + discount application are wired, or
  ship a consumable response AND wiring.
- **F-1 [Important]**: headline self-contradictory — the double-spend was never live (endpoint
  500'd on every call; independent PL-1 record agrees). Promotion risk class = "enable a
  dormant write path", not "stop ongoing loss". created_at in-place fix itself correct.
- **F-3 [Important]**: idempotency protection inert — nothing sends `idempotency_key`; the
  lane's own test shows two keyless redemptions both succeed. Restate as capability-shipped.
- **F-4 [Important]**: false SQLite claim — partial-unique violations on sqlite report COLUMNS,
  not the index name, so the 23505 classifier's sqlite arm is dead; PG arm verified correct.
  23505 recovery branch untested on any engine.
- **F-5**: sqlite runs the debit in float (CAST AS NUMERIC → REAL); PG leg is the only honest
  arithmetic check (gate ran it: 9/9, 0 skips).
- **F-6 [Minor]**: migration down() drops the column unconditionally even on the
  pre-existing-column census path it deliberately didn't create it on.
- **F-7/F-8 [Minor]**: no HTTP-level test for idempotency_key or the 422 mapping; unit mock
  lacks ->with on the debit call.
- Out-of-lane notes (LEDGER candidates): `RewardRedemptionService.php:41` PHP `<` on decimal
  strings (rule-19); `quantity_per_member` never enforced (isEligible hardcoded 0);
  `quantity_available` never decremented; no `reward.program_id === enrollment.program_id`
  assertion (cross-program spend within a tenant).

Verified by the gate: PG feature 9/9 no skips on throwaway 5433; lock + single-statement debit
semantics; tenancy scoping (no cross-company reach); precision (decimal 15,3 matches
POINTS_SCALE 3); phpstan/pint/deptrac clean; scope clean (13 files all Loyalty).
