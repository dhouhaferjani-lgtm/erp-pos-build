# Ticket: Lane C wave-1 minor carry-overs (reviewer-flagged, non-blocking)

1. Trigger branch (2026_07_31_940000): the sealed_hash_algorithm transition branch does not pin
   NEW.fiscal_status or customer snapshot columns (spec-faithful — §6.2 SQL verbatim — but the
   sibling FK-nulling branch pins them). Tighten in a future trigger revision.
2. ReceiptHashService now constructor-injects an Application service (V3ReceiptHashComputer) from
   Domain — deepens the deptrac baseline (existing precedent in-file). Post-launch hexagonal sweep.
3. §3.6 shipped with exactly ONE refund_policy_alert type (non_zero_original_transaction_discount);
   the return-window alert is N/A — no duration source of truth exists anywhere. Recorded as a spec
   erratum here; if a return-window policy is ever introduced, the alert gains its second type.
