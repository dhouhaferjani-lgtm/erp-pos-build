# Gate record — Session B lane Q-4 (coupon cap), treasury-reviewer r1

Commit `f09a45b80`, branch `fix/sb-q4-coupon-cap-enforcement`.

**Verdict: CHANGES-REQUESTED** (core verified by execution: index, migration abort path, lock,
replay idempotency, red-first reproduced by revert-probe; one advertised behavior provably
false; two load-bearing branches untested).

Findings:
- **F-1 [Important]**: the "seal Exhausted when counter beat status" write
  (`CouponApplicationService.php:152-159`) executes INSIDE the transaction whose refusal throw
  unwinds it — the seal is ALWAYS rolled back (gate probe proved status stays Active). Hoist
  the seal outside the txn or delete it + the comment; add the counter-at-cap-with-Active test.
- **F-2 [Important]**: the savepoint/23505 branch (`:95-115`) has zero test coverage — the gate
  wrote the probe (QueryExecuted-listener race injection under an enclosing txn) and it PASSES;
  port that probe into the lane's test file.
- **F-3 [Important]**: `test_record_usage_refuses_a_second_checkout_once_the_global_cap_is_reached`
  is sequential and refuses via the status gate, not the counter re-check — rename honestly +
  cover the counter branch (folds into F-1's new test).
- **F-4 [Important, contract]**: `recordUsage` throws on a post-seal write — but the discount
  is already sealed on the fiscal receipt; refusing under-counts a granted discount and a
  future sync caller could poison-message. Zero callers today (verified: not on the contract,
  no route, no orchestrator call). Document the caller contract in the docblock (caller MUST
  catch; MUST NOT roll back the sealed receipt; recommended future contract = record-and-flag)
  + register the owner ruling owed before wiring. Replay-returns-success ordering RATIFIED by
  the gate as the right idempotency contract.
- **F-6 [Minor]**: promotion index shipped without the idempotent catch — zero callers
  verified; MUST be a registered follow-up (LEDGER), whoever wires promotion redemption hits a
  500 on first sync retry.
- **F-7 [Minor]**: `isUniqueViolation` matches any unique violation — match the index name.
- **F-8 [Minor]**: migration docblock wording ("same statement batch") inaccurate; scan BOTH
  tables before creating either so a dual-dirty tenant learns both remediations at once.
- **F-10 [Record correction]**: brief/sub-report premise overstated — `Coupon::isValid()`
  already refused a capped coupon at validate time; change (c) is a refusal-MESSAGE fix
  (expired → fully redeemed), not a new enforcement point. Sub-report HIGH must not be closed
  on the false premise; the real new enforcement is the under-lock re-check + DB index.
- **F-5 [Merge caveat]**: Coupon lane parked (SELF_HOSTED_RUNNER_READY) — all green evidence
  local-only (S-17 standard). Manifest union at merge = gated_ceiling **1146** (dev 1145 + 1).
- **F-9 [Pre-existing, NOT this lane]**: `CouponValidationService.php:128-130` no-arg
  `getScale()` — coupon-module backlog.
