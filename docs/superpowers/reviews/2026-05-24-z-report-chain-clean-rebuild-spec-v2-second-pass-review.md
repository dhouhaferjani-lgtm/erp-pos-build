# POS Z-Report Chain Clean Rebuild Spec v2 Second-Pass Review

**Date:** 2026-05-24  
**Reviewer:** Independent Codex adversarial review  
**Spec reviewed:** `docs/superpowers/specs/2026-05-24-pos-z-report-chain-clean-rebuild-spec-v2.md`  
**Verdict:** REVISE

## Findings

1. **BLOCKER: Canonical hash boundaries are ambiguous.**

   Section 6.1 describes `current_hash` and `canonical_bytes` as part of the shared fiscal-event envelope. In the current POS engine, canonical bytes are the pre-hash canonical object; `current_hash = sha256(canonical_bytes)` is stored outside the hashed bytes. The spec must state that `chain_context` is inside the canonical pre-hash object, while `current_hash` and `canonical_bytes` are storage/transport fields outside it.

2. **BLOCKER: Existing single-chain ingest and verification machinery is not dispositioned.**

   The spec requires sequence continuity by `(tenant_id, company_id, terminal_id, chain_context)`, but current server ingest and database uniqueness are keyed around a single `(tenant_id, terminal_id, sequence_number)` stream. The spec must explicitly require changes to `FiscalEventEnvelope`, `OutboxIngestor`, conflict handling, quarantine slot keys, `VerifyEventChainCommand`, immutable update whitelists, and the related chokepoint gates.

3. **BLOCKER: Training-mode closures need separate chain semantics.**

   Section 10 excludes training-mode closures from production exports, but v2 only defines `operational` and `z_session` contexts. If training events append to production chains, exports would show sequence gaps when they are omitted. The spec needs either separate training contexts or a non-fiscal storage rule, with tests.

4. **HIGH: Phase 4 interim cash movement events need mandatory cutover disposition.**

   Phase 4 currently authors `CASH_OUT` and `SAFE_DROP` through the operational fiscal engine. The spec acknowledges this but does not require concrete deactivation or remapping. Without an explicit disposition for `authorCashDrawerApproval()`, TS/PHP registries, and already-synced operational events, cutover could leave duplicate fiscal authority for one drawer movement.

## Required v3 Changes

- Clarify pre-hash canonical object versus storage envelope.
- Add mandatory chain-context migration work for server ingest, local engine, verification commands, quarantine keys, DB indexes, and immutability gates.
- Add explicit `training_operational` and `training_z_session` chain contexts or an equivalent no-gap design.
- Require Phase 4 operational `CASH_OUT`/`SAFE_DROP` authoring to be deactivated or routed into the Z-session movement path for cutover terminals, while preserving historical pre-cutover events as legacy evidence only.
