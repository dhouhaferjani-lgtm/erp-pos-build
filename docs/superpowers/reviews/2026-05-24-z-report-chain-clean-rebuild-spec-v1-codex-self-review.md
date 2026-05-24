# Codex Self-Adversarial Review — Z-Report Chain Clean Rebuild Spec v1

**Spec reviewed:** `docs/superpowers/specs/2026-05-24-pos-z-report-chain-clean-rebuild-spec-v1.md`  
**Reviewer:** Codex  
**Verdict:** REVISE

## Findings

1. **BLOCKER — Chain context is named conceptually but not persisted concretely.**  
   v1 says sequence continuity is evaluated against `(tenant_id, company_id, terminal_id, chain_context)`, but it does not explicitly require a `chain_context` column (or equivalent immutable persisted discriminator) on local/server `fiscal_events`, uniqueness indexes, and sync/envelope validation. Without that, the server cannot independently verify which chain a row belongs to, and event-type-derived context would be brittle when future event types move between chains.

2. **BLOCKER — X_REPORT sequence wording is internally inconsistent.**  
   v1 says `X_REPORT` appends to the `z_session` fiscal chain, but §6.4 says it "must not update `z_chain_sequence` as a closure number." A chain append must advance the chain sequence; the thing that should not advance is `z_number`, grand totals, or close/freeze state. This needs exact wording because implementation will wire the engine around these terms.

3. **HIGH — Cash movement reconciliation with the merged Phase 4 branch is too weak.**  
   v1 notes Phase 4 registered `CASH_OUT`/`SAFE_DROP`, but it does not make an implementation requirement to migrate those interim operational-chain emissions into the session-chain ownership model or block duplicate authoring. The plan could accidentally leave two fiscal-authority paths for cash movements.

4. **HIGH — SESSION_OPEN opening-float reference is awkward and race-prone.**  
   v1 has `opening_float_event_id` nullable "until `OPENING_FLOAT` event is authored in the same transaction." That creates circular ordering pressure. Cleaner: `SESSION_OPEN` records the declared opening float amount and can be followed by a distinct `OPENING_FLOAT` movement event referencing `session_id`; `Z_REPORT` summarizes both and validates consistency.

5. **MEDIUM — Month/year GRANDTOTAL semantics are left too open for v1.**  
   v1 includes `period_type DAY|MONTH|YEAR` but only flags later risk. Given device sessions are shift/day closures, the spec should lock initial authoring to `DAY` Z reports and treat month/year NF525 GRANDTOTAL export as deterministic aggregation over canonical day Z reports unless a later spec introduces month/year device closure events.

## Required v2 changes

- Add explicit `chain_context` persistence and uniqueness/index rules.
- Clarify `X_REPORT` advances chain sequence but not `z_number` or closure totals.
- Add Phase 4 cash movement compatibility rule: no duplicate operational-chain and z-session-chain cash movement authority.
- Simplify `SESSION_OPEN`/`OPENING_FLOAT` linkage.
- Lock v1 implementation to `Z_REPORT.period_type = DAY`; month/year exports derive from day canonical bytes.
