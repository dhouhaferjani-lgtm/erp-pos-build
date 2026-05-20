# Codex Review — Task 27B Pass 2B

**Date:** 2026-05-20
**Reviewer:** Codex first-pass adversarial review
**Reviewed commits:**

- `c8cd9fca1` (`Phase 1.27.2: Wire POS receipts through fiscal events`)
- `fdb9e2700` (`Phase 1.27.3: Fix fiscal event sync recovery`)
- `1b8dfa080` (`Phase 1.27.4: Handle null-id fiscal sequence conflicts`)
- `6369d99b3` (`Phase 1.27.5: Harden fiscal sync result discriminator`)

## Executive Summary

Task 27B Pass 2B wires the Tauri POS receipt path through `FiscalEventEngine.append()` and retires the legacy receipt-sync route/service/DTO/request surface in the same atomic implementation commit. The `.PASS_2B_PENDING` marker was removed in that same commit, and the Pass 2B sentinel now exits cleanly because the engine path is live.

Initial self-review found one dead-path risk before the implementation commit: `advanceHashChain()` remained exported from `terminalStateRepository.ts` as a direct writer to the legacy `terminal_state.last_hash/hash_sequence` columns even though no production caller remained. I deleted the helper and its tests before committing.

Opus second-pass review of `c8cd9fca1` then found two real sync-lifecycle defects: idempotent server re-delivery (`stored=false`, no conflict, no exception, same `fiscal_event_id`) was treated as failure, and rows could remain stranded at `sync_status='syncing'` after a crash between local status update and HTTP response handling. R2 commit `fdb9e2700` fixes both by recovering stranded `syncing` rows to `pending` before pending selection and accepting no-conflict/no-exception matching responses as successful re-delivery. It also removes the remaining stale legacy-chain mocks/comments identified in review.

Opus re-review of `fdb9e2700` found a follow-up sequence-conflict shape bug: the real server returns `fiscal_event_id: null` for sequence conflicts because the conflicting envelope cannot enter `fiscal_events`. R3 commit `1b8dfa080` changes the client to accept the sole null-id result item for the one-envelope POST shape, then classify `sequence_conflict=true` as a chain break. The regression test now uses the actual PHP response shape (`stored=false`, `fiscal_event_id=null`, `sequence_conflict=true`, `exception_class='sequence_conflict'`) and failed before the R3 production change.

Opus re-review of `1b8dfa080` then found a fail-loud gap in the R3 repair itself: a protocol-drift response with `fiscal_event_id=null`, `sequence_conflict=false`, and `exception_class=null` would still be marked synced. R4 commit `6369d99b3` closes that by requiring exactly one result for the one-envelope POST, requiring successful/idempotent success to carry the matching fiscal-event id, and allowing null-id results only when they are explicit rejections (`sequence_conflict=true` or `exception_class` non-null). The new null-id/no-rejection test failed before the production change and passes after it.

## Scope Reviewed

- POS device receipt authoring now builds the synthesis-v5 27-key `SALE_RECEIPT` payload via `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts`.
- `receiptService.ts` appends through `getFiscalEventEngine(companyId, db).append()` and mirrors the resulting fiscal event into `offline_receipts`.
- `paymentStore.ts` requires active terminal, open shift, tenant/company/seller context, and serializes checkout through `lockTerminal()`.
- Local `fiscal_events` sync uses `/pos/sync/fiscal-events` via `fiscalEventRepository.ts`; `/pos/receipts/sync` is deleted.
- Legacy TS v3 receipt canonicalization and golden parity script are deleted.
- Backend receipt-sync service, DTOs, request, route, and route tests are deleted or trimmed from mixed-purpose suites.

## Adversarial Checklist

| Axis | Result | Evidence |
|---|---|---|
| 27-key canonical payload | PASS | `SaleReceiptPayload.ts` emits the locked key set and calls `engine.append()`; POS tests cover the fiscal-event mirror row and sync envelope. |
| TS ↔ PHP byte/contract drift | PASS | Pass 2A drift gate remains live; Pass 2B no longer uses TS v3 receipt canonicalization. Fiscal event sync posts immutable engine envelopes, not rebuilt receipt payloads. |
| Cross-tenant FK safety | PASS | Device payload requires `tenantId` + `companyId`; backend receipt projection continues to resolve domain rows under the fiscal event's tenant/company. The broader `PosStabilizationTenantIsolationTest` was preserved with only retired receipt-sync cases removed. |
| Fail-loud vs silent downgrade | PASS | Missing active terminal/open shift throws `ActiveTerminalRequiredError`; missing receipt fiscal context throws before authoring; `buildSaleReceiptPayload()` throws `SaleReceiptPayloadInputError`; sync missing-result, multiple-result, null-id/no-rejection, sequence-conflict, and exception statuses fail loudly. Idempotent no-conflict/no-exception re-delivery is accepted only for the matching fiscal event id returned by the server; null-id results are accepted only for explicit rejection states, so real sequence conflicts halt the chain. No fallback to legacy hash authoring remains. |
| Dead-path rebuild | PASS | Deleted `/pos/receipts/sync`, `ReceiptSyncService`, `SyncReceiptPayload`, `SyncReceiptResult`, `SyncReceiptsRequest`, TS `lib/fiscal/v3`, the v3 fixture parity script, and the dead `advanceHashChain()` writer. Post-R4 grep for `ReceiptSyncService`, `SyncReceiptPayload`, `SyncReceiptsRequest`, `SyncReceiptResult`, `computeV3FiscalHash`, `buildCanonicalPayload`, and `advanceHashChain` across production/task surfaces returns no hits. |
| `.PASS_2B_PENDING` atomicity | PASS | Marker deletion is in `c8cd9fca1` with receipt engine wiring. `check-pass-2b-pending.sh` exits 0 from the post-Pass-2B tree. |
| §14.3 chokepoint gate | PASS | Manifest now has 6 receiver entries; gate reconciles 8 call sites. The retired receipt-sync manifest entry is gone. |
| Discriminated-union / matrix coverage | PASS | Pass 2A validator matrix remains intact. Pass 2B adds/updates receipt authoring, fiscal-event sync, idempotent re-delivery, stranded-syncing recovery, missing-context, concurrency, voucher, and offline-first integration coverage. |
| Contract drift docs vs code | PASS | Stale comments naming `/pos/receipts/sync`, `ReceiptSyncService`, TS v3 fixtures, `.PASS_2B_PENDING`, and `buildCanonicalPayload` were removed, updated, or narrowed to explicit retired-route assertions. |
| Per-method skips only | PASS | No class-level skips added. Existing skipped tests are unchanged and reported by PHPUnit. |
| Skip-citation accuracy | PASS | No new skips added. |
| CLAUDE.md rule 13 | PASS for Task B changes | No new `app()`, `App::make()`, or service-locator `resolve()` calls were introduced in Task B production code. Existing repo-level service-locator hits predate this task. |
| Constructor injection only | PASS | New production paths use direct imports/constructors on TS side and existing PHP constructor-injected services; deleted backend sync surface reduces service entry points. |

## Verification

- Targeted R2 RED first failed on the missing idempotent replay success path and missing `recoverStrandedSyncingFiscalEvents()` hook.
- Targeted R2 GREEN: `pnpm vitest run src/lib/offline/__tests__/offlineCheckoutService.test.ts src/__tests__/integration/offlineFirstFlow.test.ts src/lib/sync/__tests__/syncService.test.ts src/lib/db/repositories/__tests__/fiscalEventRepository.recovery.test.ts`: 4 files, 61 tests passed.
- Targeted R3 RED first failed on the real null-id sequence-conflict response: `pnpm vitest run src/lib/sync/__tests__/syncService.test.ts` reported 1 failed / 46 passed.
- Targeted R3 GREEN: `pnpm vitest run src/lib/sync/__tests__/syncService.test.ts`: 47 tests passed.
- Targeted R4 RED first failed on null-id/no-rejection protocol drift: `pnpm vitest run src/lib/sync/__tests__/syncService.test.ts` reported 1 failed / 47 passed.
- Targeted R4 GREEN plus typecheck: `pnpm vitest run src/lib/sync/__tests__/syncService.test.ts && pnpm typecheck`: 48 sync tests passed and TypeScript passed.
- `pnpm typecheck` in `apps/pos`: passed.
- `pnpm lint` in `apps/pos`: passed with 0 errors and 42 warnings.
- `pnpm vitest run` in `apps/pos`: 151 files, 1387 tests passed.
- `./vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php tests/Feature/Fiscal/ tests/Unit/Fiscal/` in `apps/api`: 545 tests, 1965 assertions, 53 skipped.
- `./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` in `apps/api`: passed.
- `./vendor/bin/pint --test` in `apps/api`: passed.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh`: passed, 6 manifest entries and 8 call sites reconciled.
- `bash apps/pos/scripts/check-pass-2b-pending.sh`: passed.

## Verdict

APPROVE.
