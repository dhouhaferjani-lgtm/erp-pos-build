# Codex Self-Adversarial Review — Phase 4 Implementation

**Date:** 2026-05-23
**Branch:** `feat/fiscal-phase-4-account-status-overrides-approval`
**Reviewed range:** `origin/dev..HEAD` after `Phase 4.1.11: Close verification regressions`
**Verdict:** APPROVE after R2 terminal-scope fixes and the R3 tender-tolerance / approval-reference cleanup.

## Scope

Review axes:

- Phase 4 handover §5 and spec v3 coverage.
- D-Q1 supervisor PIN identity model.
- Server-only administrative event authority for `ACCOUNT_STATUS_CHANGED`.
- Tenant/company/terminal/scope safety for approval PINs and offline approval mirrors.
- Fiscal event registry/parser/DB/POS parity for Phase 4 event types.
- Cash drawer boundary: `DEPOSIT`/`PAYOUT` approval evidence plus device-authored `SAFE_DROP`/`CASH_OUT` fiscal movement events, leaving Z-report chain rebuild semantics for the Z-report workstream.
- Verification adequacy after fixes.

## Findings

### F1 — BLOCKER — Online manager-PIN approval accepted arbitrary terminal IDs

`VerifyManagerPinRequest` required `terminal_id` syntactically, but did not prove that the terminal existed, belonged to the active tenant/company, was active, or was non-virtual-admin. A caller could therefore create approval evidence for an arbitrary terminal context after a valid supervisor PIN.

**Resolution:** `VerifyManagerPinRequest` now pins `company_id` to `CompanyContext`, validates `terminal_id` against active non-virtual `pos_terminals` scoped to the same tenant/company, and tests cover unknown and virtual-admin terminals.

### F2 — BLOCKER — Offline approval PIN sync could seed arbitrary terminal eligibility

`GET /pos/auth/pin-data?terminal_id=...` echoed any non-empty query value into `terminal_ids`, allowing an offline mirror to treat a supervisor as eligible for an unowned terminal.

**Resolution:** `PosAuthController::pinData()` now applies the same active, non-virtual, tenant/company terminal validation before emitting `terminal_ids`; tests cover scoped success and unknown-terminal rejection.

### F3 — P2 — Cash drawer threshold policy is evidence-ready but not policy-configured

Phase 4 carries approval evidence through legacy `DEPOSIT`/`PAYOUT` API, offline queue, server row, resource output, and device-authored `SAFE_DROP`/`CASH_OUT` fiscal movement events. It does not introduce a configurable amount threshold that forces approval. The current implementation requires approval for cashier-entered deposit/payout flows; configurable thresholds remain a product-policy follow-up.

**Resolution:** Covered as a residual policy caveat, not a chain-integrity blocker.

### F4 — BLOCKER — SALE_RECEIPT constrained-action evidence collapsed multiple overrides

Receipt authoring originally stored only one discount override in `reference_event_id`, dropping additional line-discount and tender-tolerance approvals.

**Resolution:** `SALE_RECEIPT` is now a 28-key payload with `approval_references`, preserving every referenced approval/override while retaining `reference_event_id` as the primary compatibility link.

### F5 — BLOCKER — Tender tolerance had no approved override path

Quick cash correctly rejected under-tender attempts, but advanced payments had no manager-approved tolerance workflow.

**Resolution:** Advanced payments now accepts a manager PIN for under-tender submissions, `paymentStore` verifies the scoped supervisor PIN, authors `OPERATOR_APPROVAL_GRANTED -> OVERRIDE_TENDER_TOLERANCE`, and passes the resulting evidence into receipt authoring.

## D-Q1 Confirmation

The Phase 4 spec and reviews surfaced D-Q1 and locked the recommended **per-supervisor PIN** model. The implementation follows that model: approval identity is a user id with tenant/company/terminal/scope validation before PIN hash verification, not a shared terminal secret.

## Verification

Post-fix focused verification includes:

- `php artisan test tests/Feature/POS/ManagerPinControllerTest.php tests/Feature/POS/ManagerOverrideAuditTest.php tests/Feature/POS/PinDataEndpointTest.php tests/Feature/Security/RateLimitEnforcementTest.php tests/Feature/Security/FirstTenantProductionSecurityTest.php tests/Feature/Security/PrivilegedAuditLogTest.php`
- `php artisan test tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Unit/Fiscal/FiscalEventTypeTest.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Unit/Fiscal/StrictCanonicalParserTest.php tests/Unit/Fiscal/CanonicalPayloadReaderTest.php`
- `php artisan test tests/Feature/POS/ManagerPinControllerTest.php tests/Feature/POS/PinDataEndpointTest.php tests/Unit/POS/CashDrawerServiceTest.php tests/Feature/POS/ReceiptReturnFlowTest.php tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php tests/Feature/Partner/PartnerAccountStatusTest.php`
- `pnpm --filter @autoerp/pos exec vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts src/lib/fiscal/__tests__/ChainRecoveryService.test.ts src/lib/offline/__tests__/receiptService.test.ts src/stores/__tests__/paymentStore.offlineFirst.test.ts src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx`
- `pnpm --filter @autoerp/pos typecheck`
- `pnpm --filter @autoerp/pos lint` (exits 0 with existing warnings)
- `./vendor/bin/phpstan --memory-limit=1536M`

Earlier full-branch verification before R2 terminal fixes:

- `composer test`
- `pnpm test`
- `pnpm build`
- `pnpm lint`
- `pnpm typecheck`
- `./vendor/bin/phpstan --memory-limit=1536M`
