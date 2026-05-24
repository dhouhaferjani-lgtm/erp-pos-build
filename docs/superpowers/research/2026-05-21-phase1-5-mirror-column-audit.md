# Phase 1.5 Mirror-Column Audit

**Date:** 2026-05-21  
**Branch:** `feat/pos-customer-accounts-phase2`  
**Scope:** Roadmap v2 Phase 1.5 task 1: audit consumers of `pos_receipts.{fiscal_hash, previous_hash, chain_sequence, vat_breakdown_hash, payment_methods_hash}` and device `terminal_state.{last_hash, hash_sequence}` after Phase 1 merged to `dev`.

## Verdict

No mirror column is safe to drop in this pass.

The audit found live production consumers for all requested mirror columns. Dropping any of them now would break either:

- the bounded legacy receipt-chain verifier/export arm for rows with `pos_receipts.fiscal_event_id IS NULL`,
- the knowingly retained server-side void/return carve-out from Phase 1 spec v7 §14.2,
- Z-report / terminal-state sync surfaces that still expose legacy receipt-chain state to the Tauri client,
- or the local POS terminal-state repository contract that still persists server-pulled legacy receipt-chain state.

Therefore this task records the audit and makes no schema change. The server-side Phase 1 fiscal-event chain remains authoritative for Phase 1 receipts; these columns are retained only because live code still has bounded legacy or compatibility dependencies.

## Server Columns

Columns audited:

- `pos_receipts.fiscal_hash`
- `pos_receipts.previous_hash`
- `pos_receipts.chain_sequence`
- `pos_receipts.vat_breakdown_hash`
- `pos_receipts.payment_methods_hash`

Live consumers found:

- `apps/api/app/Modules/POS/Domain/Receipt.php` still exposes the fields in `$fillable`, casts, and model docblocks.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` writes mirror values for fiscal-event-backed receipt projections.
- `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` retains the legacy arm for `fiscal_event_id IS NULL` rows, including VAT/payment sub-hash verification.
- `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php` excludes fiscal-event-backed rows but still verifies legacy rows ordered by `chain_sequence`.
- `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` delegates fiscal-event-backed rows to canonical/fiscal-event verification, but the legacy arm still reads these receipt mirrors.
- `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php`, `ReceiptPaymentService.php`, `ReceiptCreationService.php`, and `ReceiptReturnService.php` still use these fields for the retained server-side void/return/legacy flows described in Phase 1 spec v7 §14.2.
- `apps/api/app/Modules/POS/Application/Services/ExchangeService.php` also references the fields, but Phase 1 spec v7 §14.3 records that no live route/caller was found for the exchange path. It is not counted as a live drop blocker.
- `apps/api/app/Modules/Compliance/Commands/BackfillFiscalHashesCommand.php` and `VerifyFiscalChainsCommand.php` still reference fiscal hash-chain fields.

Important distinction: many repository-wide hits for `fiscal_hash`, `previous_hash`, and `chain_sequence` belong to other bounded chains (`documents`, journal entries, Z reports, withholding certificates, grand totals). They are not candidates for this Phase 1.5 POS receipt cleanup and must not be touched by a receipt-column migration.

## Device Columns

Columns audited:

- `terminal_state.last_hash`
- `terminal_state.hash_sequence`

Live consumers found:

- `apps/pos/src/lib/db/repositories/terminalStateRepository.ts` reads and writes both columns as part of the local terminal-state contract.
- `apps/pos/src/lib/sync/syncService.ts` maps `/pos/terminals/{id}` responses into `TerminalHashState`, including server `last_hash` / `hash_sequence`, and preserves local state on regressive writes.
- `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` still exposes `last_hash` and `hash_sequence` to the POS pull surface.
- `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php` still includes terminal `last_hash` in sync payloads.
- `apps/pos/src/lib/db/migrations.ts` creates the columns in the base `terminal_state` table and later adds fiscal-event chain-head columns beside them.

The fiscal-event engine itself reads `terminal_state.fiscal_event_genesis_seed`, `fiscal_event_last_hash`, and `fiscal_event_sequence`; it does not depend on `last_hash` / `hash_sequence`. However, because the broader sync/repository contract still references the legacy fields, dropping them now would require a separate Tauri state-contract refactor and matching server response-contract cleanup. That refactor is larger than a safe drop-only migration and should be planned with the Z-report chain rebuild / terminal-state contract cleanup.

## Drop Decision

Retain all audited columns for now.

Safe-drop prerequisites before revisiting:

1. Legacy `fiscal_event_id IS NULL` POS receipt verification/export paths are removed or isolated behind an archive-only tool that does not require live `pos_receipts` mirror columns.
2. Server-side void/return fiscal-event types (`SALE_VOID`, `REFUND_RECEIPT`, `PARTIAL_REFUND`) are rebuilt on the fiscal event engine, removing the Phase 1 §14.2 carve-out.
3. Tauri terminal-state sync stops mapping server `last_hash` / `hash_sequence` into local SQLite, and the local repository contract is reduced to fiscal-event chain-head columns plus Z-chain columns.
4. Z-report chain rebuild has a dedicated state model so receipt-chain legacy columns are not accidentally serving Z-report compatibility.

## Verification Commands

Audit commands run from `/Users/houssamr/Projects/syneriva/apps/erp.customer-accounts-phase2`:

```bash
rg -n "\b(fiscal_hash|previous_hash|chain_sequence|vat_breakdown_hash|payment_methods_hash)\b" apps/api/app apps/api/database apps/api/routes apps/api/config -g '!apps/api/vendor/**'
rg -n "\b(last_hash|hash_sequence)\b" apps/pos/src apps/api/app apps/api/database -g '!**/__tests__/**' -g '!apps/api/vendor/**'
bash apps/pos/scripts/check-pass-2b-pending.sh
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
```

Gate results:

- `.PASS_2B_PENDING` marker absent; `check-pass-2b-pending.sh` exits 0.
- §14.3 chokepoint gate passes: 6 manifest entries validated; 8 call sites reconciled.
