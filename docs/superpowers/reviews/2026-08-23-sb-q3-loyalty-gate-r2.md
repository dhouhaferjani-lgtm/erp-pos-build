# Gate record — Session B lane Q-3 (loyalty redemption), treasury-reviewer r2

Fix commit `8a708ac9d` on `ccc7f7877`. **Verdict: ACCEPT.**

All four r1 blockers verified closed by execution: (1) 501 `LOYALTY_REDEMPTION_NOT_WIRED` arm
ordered validate→tenant-404s→refusal (cross-tenant test still passes, probe learns nothing);
coverage TOTAL — zero other callers of `redeemReward` anywhere (the Shared contract has zero
implementors); not a feature flag, no operator can enable it. (2) Driver-aware classifier
cannot swallow the earn index on either engine (probed real messages); the
`UNIQUE constraint failed` guard also excludes sqlite NOT-NULL 23000s. (3) The race test
exercises the production path (decorator overrides ONLY the pre-check lookup; real INSERT →
real index → real driver error → real recovery; loser's debit proven rolled back; PG 0 skips).
(4) down() verified on real PG both branches; can no longer destroy a column it didn't create.

Residuals carried (wiring-lane brief + LEDGER): down() is deliberately not a true inverse
(rollback leaves the inert nullable column — documented); FE still renders enabled Redeem
buttons that toast failure (same UX as the old 500 — wiring lane hides the affordance); the
wiring lane must ALSO close: reward_value/points_spent response contract + discount
application + RewardRedeemedV2 listener, `quantity_available` never decremented,
`quantity_per_member` unenforced (`isEligible(...,0)` hardcoded), no
`reward.program_id === enrollment.program_id` assertion, rule-19 `<` at
`RewardRedemptionService.php:41`, and sending `idempotency_key` from the client.
