# A3 Inventory Costing Review — Round 3

- **Reviewer:** `inventory-costing-reviewer`
- **Model:** Opus
- **Scope:** Revised A3 diff after round-two fixes
- **Mode:** Read-only

## Verified Resolved

The reviewer verified apply-window supersession, posted-vs-unposted flag handling generally, removal of the per-count finalize prefilter, dead reconciliation query removal, verified-only signature persistence, material-risk signature hashing, and all prior authorization/precision/scoping fixes.

## Required Findings

- **Important:** `clock_skew` is a review flag but does not stop the apply listener once a final quantity exists. It must remain residual-eligible; only `basket_window`, `negative_at_apply`, and `pending_opening_cost` block stock application.
- **Important:** physical tills included in health coverage must be allowed to report while inactive or training, subject to the same company, terminal, hardware, and permission binding.

## Verdict

**VERDICT: CHANGES_REQUIRED**
