# Phase 1.5 Mirror-Column Audit — Opus-Equivalent Second-Pass Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/research/2026-05-21-phase1-5-mirror-column-audit.md`  
**Codex self-review:** `docs/superpowers/reviews/2026-05-21-phase1-5-mirror-column-audit-codex-review.md`  
**Verdict:** APPROVE-WITH-MINOR-EDITS

## BLOCKER

None.

## REQUEST-CHANGES

None.

## MINOR

1. The audit slightly overstates one blocker by listing `ExchangeService` among live consumers. `ExchangeService::processExchange()` is explicitly annotated `live: false`, and `rg "processExchange\(" apps/api/app apps/api/routes` found no route/controller caller beyond the method and DTO references. This should not be counted as a live drop blocker.

   This does not change the drop decision: the same columns still have independent live consumers through `ReceiptReturnService::processReturn`, `ReceiptFinalizationService::finalize`, the NF525 export/verify surfaces, the POS projection mirror writes, and the legacy receipt verifier/export arm.

## CLEAN

1. The audit's primary conclusion is correct: no audited column is currently safe to drop.

2. Server receipt mirrors remain consumed:
   - `pos_receipts.fiscal_hash`, `previous_hash`, and `chain_sequence` are still written by `PosCoreReceiptProjection`, used by `ReceiptFinalizationService`, surfaced in NF525 DTOs, and used by the legacy `fiscal_event_id IS NULL` verifier/export path.
   - `pos_receipts.vat_breakdown_hash` and `payment_methods_hash` are still required by legacy hash serialization/verification and by live server-side return drafting before finalization.
   - The registered `pos:verify-chains` command and route-backed NF525 `verify-chains` / `export-jet` paths are not dead paths.

3. Device `terminal_state.last_hash` and `hash_sequence` remain consumed by the local SQLite schema, `terminalStateRepository`, `/pos/terminals/{id}` pull mapping, and server response resources. Dropping them would require a coordinated Tauri terminal-state contract migration, not a drop-only cleanup.

4. I did not find a no-consumer audited column that should be dropped now.

5. D16 is preserved. The audit does not propose new projector dependencies or runtime customer/account traversals; it retains the bounded module distinction between POS receipt mirror columns and unrelated document, GL, Z-report, withholding, and fiscal-event hash chains.

6. Standing-pattern constraints are preserved:
   - No schema deletion under live readers.
   - No dead-path rebuild is introduced.
   - No cross-tenant FK lookup is added.
   - No silent downgrade is introduced.
   - No test skip is added.
   - No new PHP code is added, so CLAUDE.md rule 13 is unaffected.

## Evidence Checked

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.customer-accounts-phase2`:

```bash
rg -n "\b(fiscal_hash|previous_hash|chain_sequence|vat_breakdown_hash|payment_methods_hash)\b" apps/api/app apps/api/database apps/api/routes apps/api/config -g '!apps/api/vendor/**'
rg -n "\b(last_hash|hash_sequence)\b" apps/pos/src apps/api/app apps/api/database -g '!**/__tests__/**' -g '!apps/api/vendor/**'
rg -n "VerifyPosChainCommand|pos:verify|Nf525DataProvider|ReceiptFinalizationService|ReceiptReturnService|ExchangeService|TerminalResource|SyncController|terminalStateRepository|saveTerminalState|loadTerminalState|getTerminalState" apps/api/app apps/api/routes apps/pos/src -g '!**/__tests__/**'
rg -n "processExchange\(" apps/api/app apps/api/routes -g '!apps/api/vendor/**'
bash apps/pos/scripts/check-pass-2b-pending.sh
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
```

Gate results:

- `.PASS_2B_PENDING` sentinel check passed.
- §14.3 chokepoint gate passed: 6 manifest entries validated; 8 call sites reconciled.

## Review Questions

1. Is the audit's conclusion correct that no audited column is currently safe to drop? **Yes.**
2. Did the audit miss any no-consumer column that should be dropped now? **No.**
3. Did it incorrectly treat a dead/stale path as a live blocker? **Only partially:** `ExchangeService` is stale/inert and should not be counted, but the final retain decision does not depend on it.
4. Does it preserve Phase 1's D16 and standing-pattern constraints? **Yes.**
5. Are there any BLOCKER or REQUEST-CHANGES findings before this task can be committed? **No.**

Final verdict: APPROVE-WITH-MINOR-EDITS.
