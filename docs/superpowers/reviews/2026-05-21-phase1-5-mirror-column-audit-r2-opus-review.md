# Phase 1.5 Mirror-Column Audit R2 — Opus-Equivalent Review

**Date:** 2026-05-21  
**Reviewed artifact:** `docs/superpowers/research/2026-05-21-phase1-5-mirror-column-audit.md`  
**R2 self-review:** `docs/superpowers/reviews/2026-05-21-phase1-5-mirror-column-audit-r2-codex-review.md`  
**Trigger:** R1 MINOR that `ExchangeService` was grouped with live drop blockers despite Phase 1 spec v7 §14.3 marking it as a stale/no-live-entrypoint path.

## BLOCKER

None.

## REQUEST-CHANGES

None.

## MINOR

None.

## CLEAN

1. The R1 minor is resolved. The corrected audit now names `ExchangeService` as a stale/non-live reference, cites Phase 1 spec v7 §14.3, and explicitly says it is not counted as a live drop blocker.

2. The no-drop conclusion still holds without relying on `ExchangeService`. Independent live blockers remain for the audited receipt mirror columns: `PosCoreReceiptProjection` writes the mirror values from fiscal events; legacy `fiscal_event_id IS NULL` verification/export paths still read `fiscal_hash`, `previous_hash`, `chain_sequence`, `vat_breakdown_hash`, and `payment_methods_hash`; and the retained void/return carve-out still depends on the legacy finalization/hash path until `SALE_VOID`, `REFUND_RECEIPT`, and `PARTIAL_REFUND` move onto fiscal events.

3. The dead-path rebuild boundary is accurate. R2 does not resurrect exchange as live work, and the §14.3 chokepoint gate still reconciles the inert exchange call sites separately from the live/retained paths.

4. The device-column conclusion is accurate. `terminal_state.last_hash` and `terminal_state.hash_sequence` remain part of the local POS repository and server pull contract, while the fiscal event engine itself uses `fiscal_event_last_hash` / `fiscal_event_sequence`. Dropping the legacy fields would require a separate terminal-state contract cleanup, coordinated with the Z-report chain rebuild.

5. The D16 bounded-module seam is preserved. The audit distinguishes POS receipt mirrors from unrelated document, GL, Z-report, withholding, grand-total, and fiscal-event chains; it does not introduce a customer/account lookup, Treasury operational dependency, or projector dependency.

6. Phase 1 standing patterns are preserved: no schema deletion under live readers, no dead-path rebuild, no silent downgrade, no cross-tenant FK lookup, no test skip, and no new PHP code or service-resolution pattern.

## Evidence Checked

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.customer-accounts-phase2`:

```bash
rg -n "\b(fiscal_hash|previous_hash|chain_sequence|vat_breakdown_hash|payment_methods_hash)\b" apps/api/app/Modules/POS apps/api/app/Modules/Compliance apps/api/database -g '!apps/api/vendor/**'
rg -n "\b(last_hash|hash_sequence|fiscal_event_last_hash|fiscal_event_sequence)\b" apps/pos/src apps/api/app/Modules/POS apps/api/database -g '!**/__tests__/**' -g '!apps/api/vendor/**'
rg -n "createReceipt\(|finalize\(|processExchange\(|ExchangeService" apps/api/app apps/api/routes -g '!apps/api/vendor/**'
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
git diff --check
```

Gate results:

- `check-saleReceipt-chokepoints.sh` passed: 6 manifest entries validated; 8 call sites reconciled.
- `check-pass-2b-pending.sh` passed.
- `git diff --check` passed.

## Final Verdict

APPROVE. R2 fixes the R1 minor and introduces no new BLOCKER, REQUEST-CHANGES, or MINOR issue.
