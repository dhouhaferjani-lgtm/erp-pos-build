# POS Z-Report Chain Clean Rebuild Spec v3 Codex Self-Review

**Date:** 2026-05-24  
**Reviewer:** Codex self-adversarial review  
**Spec reviewed:** `docs/superpowers/specs/2026-05-24-pos-z-report-chain-clean-rebuild-spec-v3.md`  
**Verdict:** APPROVE

## Checks

1. **Canonical contract.** v3 now separates the pre-hash canonical object from storage/transport fields. `chain_context` is explicitly sealed in canonical bytes, while `canonical_bytes` and `current_hash` are outside the hashed object. This removes the circular hash ambiguity from v2.

2. **Single-chain migration.** v3 explicitly names local append, server ingest, DB uniqueness, `FiscalEventEnvelope`, quarantine keys, `VerifyEventChainCommand`, immutability, and registry drift tests as mandatory migration points. This is sufficient to prevent implementers from only adding a schema column while leaving the single-chain verifier intact.

3. **Training chain semantics.** v3 defines `training_operational` and `training_z_session`, requires `training_flag`/context agreement, and adds tests proving production exports do not show gaps after omitting training events.

4. **Phase 4 cash movement cutover.** v3 requires `authorCashDrawerApproval()` and PHP/TS registries to be dispositioned so cutover terminals cannot author both operational-chain and Z-session movement events for the same drawer operation. Historical Phase 4 rows are preserved as legacy evidence only.

## Residual Risks

- The implementation plan must still audit actual SQLite and PostgreSQL fiscal-event indexes before choosing exact migration mechanics.
- The Z-report projection schema is intentionally not finalized in the spec; the implementation plan must decide between extending `pos_z_reports` and adding dedicated session projection tables after code audit.

No blocking spec issues remain from the v2 second-pass review.
