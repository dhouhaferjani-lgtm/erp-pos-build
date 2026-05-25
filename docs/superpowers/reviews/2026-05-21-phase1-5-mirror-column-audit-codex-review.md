# Phase 1.5 Mirror-Column Audit — Codex Self-Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/research/2026-05-21-phase1-5-mirror-column-audit.md`  
**Verdict:** APPROVE

## Scope Reviewed

This review checks whether the Phase 1.5 mirror-column audit correctly decides not to drop any audited columns in the current codebase state.

Columns under review:

- `pos_receipts.fiscal_hash`
- `pos_receipts.previous_hash`
- `pos_receipts.chain_sequence`
- `pos_receipts.vat_breakdown_hash`
- `pos_receipts.payment_methods_hash`
- device `terminal_state.last_hash`
- device `terminal_state.hash_sequence`

## Attack Vectors

### Cross-Tenant FK Safety

No new FK lookup, projection, or migration is introduced. The audit does not alter the Task 21 / Pass 2A.PHP.2 cross-tenant FK safety posture.

### Fail-Loud vs Silent Downgrade

The audit explicitly refuses to drop columns still consumed by live code. That is fail-loud at the planning boundary: a future drop requires removing or refactoring the listed consumers first, rather than silently deleting schema under live readers.

### Dead-Path Rebuild

This was the primary attack vector. I verified the audit is not claiming a cleanup that has no live rewiring. The artifact names live consumers and records no migration because the live consumers remain. That avoids the Task 30 anti-pattern of adding a parallel path while leaving the active path unchanged.

### Discriminated-Union Matrix

No discriminated-union DTO or endpoint wrapper is introduced.

### Contract Drift

The audit is consistent with roadmap v2 Phase 1.5 wording: "drop columns with no remaining consumer." The grep found remaining consumers, so no drop is justified in this task. It also preserves Phase 1 spec v7 §14.2's retained void/return carve-out instead of deleting the legacy columns it still relies on.

### Per-Method Skips

No test skip is added or changed.

### Skip-Citation Accuracy

No skip citation is added or changed.

### Constructor Injection / Container Resolution

No PHP code is added. No `app()`, `App::make`, or `resolve()` call is introduced.

### D16 Bounded-Modules Guard

No projector, engine, or ingestion dependency is changed. The audit keeps the distinction between POS receipt mirror columns and unrelated module hash-chain columns, avoiding cross-module cleanup scope creep.

### R2 Fix Risk

No R2 fix exists in this task. If a later review requests dropping columns, the resulting implementation must be reviewed as fresh code because it would touch schema, sync contracts, and export/verification surfaces.

## Evidence Checked

Commands used:

```bash
rg -n "\b(fiscal_hash|previous_hash|chain_sequence|vat_breakdown_hash|payment_methods_hash)\b" apps/api/app apps/api/database apps/api/routes apps/api/config -g '!apps/api/vendor/**'
rg -n "\b(last_hash|hash_sequence)\b" apps/pos/src apps/api/app apps/api/database -g '!**/__tests__/**' -g '!apps/api/vendor/**'
bash apps/pos/scripts/check-pass-2b-pending.sh
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
```

The evidence supports the audit conclusion:

- server POS receipt mirrors still have live legacy/void/return/export/verification consumers;
- device `terminal_state.last_hash/hash_sequence` still have live sync/repository/resource consumers;
- Phase 1's Pass 2B marker is gone and the sentinel exits cleanly;
- the §14.3 chokepoint gate still passes.

## Residual Risk

The retained columns remain technical debt. The audit narrows the next safe cleanup boundary: first rebuild void/return on fiscal events, then remove the legacy receipt-chain verifier/export arm, then refactor Tauri terminal-state sync away from legacy receipt-chain state.

