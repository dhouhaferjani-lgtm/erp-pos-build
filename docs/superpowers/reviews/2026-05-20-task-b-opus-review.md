# Opus Review — Task 27B Pass 2B

**Date:** 2026-05-20
**Reviewer:** Opus-equivalent second-pass adversarial review
**Reviewed commits:**

- `c8cd9fca1` (`Phase 1.27.2: Wire POS receipts through fiscal events`)
- `fdb9e2700` (`Phase 1.27.3: Fix fiscal event sync recovery`)
- `1b8dfa080` (`Phase 1.27.4: Handle null-id fiscal sequence conflicts`)
- `6369d99b3` (`Phase 1.27.5: Harden fiscal sync result discriminator`)

## Review Rounds

### Round 1 — `c8cd9fca1`

Verdict: BLOCKER.

Findings:

- BLOCKER: idempotent fiscal-event re-delivery (`stored=false`, no sequence conflict, no exception, matching `fiscal_event_id`) was treated as failure instead of synced after a lost response retry.
- REQUEST-CHANGES: fiscal events could remain stranded in `sync_status='syncing'` after a crash because pending selection ignored syncing rows and no fiscal-event recovery hook existed.
- MINOR: stale Pass 2B comments remained in `offlineCheckoutService.ts` and `FiscalEventEngine.ts`.

Resolution:

- `fdb9e2700` added `recoverStrandedSyncingFiscalEvents()`, calls it before selecting pending fiscal events, accepts matched idempotent re-delivery as success, and added regression/integration tests.

### Round 2 — `fdb9e2700`

Verdict: BLOCKER.

Finding:

- BLOCKER: real PHP sequence-conflict responses return `fiscal_event_id=null`, so matching only by fiscal event id caused the client to classify a real chain conflict as a missing-result failure and potentially continue later events.

Resolution:

- `1b8dfa080` updated result selection to allow the sole null-id result from the one-envelope POST so the existing sequence-conflict branch can halt the chain. The sequence-conflict test now uses the PHP response shape.

### Round 3 — `1b8dfa080`

Verdict: REQUEST-CHANGES.

Finding:

- P1: the R3 null-id fallback could mark a protocol-drift response with `fiscal_event_id=null`, `sequence_conflict=false`, and `exception_class=null` as synced, violating fail-loud semantics.

Resolution:

- `6369d99b3` hardened the discriminator: exactly one result is required for the one-envelope request; success requires the matching fiscal-event id; null-id results are accepted only for explicit rejection states; empty, multi-result, mismatched non-null id, and null-id/no-rejection responses fail loud. The null-id/no-rejection regression test failed before the production change and passes after it.

### Round 4 — `6369d99b3`

Verdict: APPROVE.

No BLOCKER / REQUEST-CHANGES findings.

The final discriminator fails loud on empty or multi-result responses, requires matched `fiscal_event_id` for success/idempotent success, and only lets null ids proceed into rejection handling when there is an actual rejection signal. The real PHP sequence-conflict shape reaches the chain-break branch, and the prior null-id/no-rejection hole is covered by the new regression test.

Residual risk:

- No explicit test covers multi-result or mismatched non-null-id responses, but implementation handles both before any success path.
- Dead-path/stale-comment grep across task surfaces returned no live production/test hits for retired chain or receipt-sync symbols.

## Verification Reviewed

- `pnpm typecheck` in `apps/pos`: passed.
- `pnpm lint` in `apps/pos`: passed with 0 errors and 42 warnings.
- `pnpm vitest run` in `apps/pos`: 151 files, 1387 tests passed.
- `./vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php tests/Feature/Fiscal/ tests/Unit/Fiscal/` in `apps/api`: 545 tests, 1965 assertions, 53 skipped.
- `./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` in `apps/api`: passed.
- `./vendor/bin/pint --test` in `apps/api`: passed.
- `bash apps/pos/scripts/check-pass-2b-pending.sh && bash apps/api/scripts/check-saleReceipt-chokepoints.sh`: passed; chokepoint reported 6 manifest entries and 8 call sites.
- Dead-path grep for `advanceHashChain`, `ReceiptSyncService`, `SyncReceiptsRequest`, `SyncReceiptPayload`, `SyncReceiptResult`, `computeV3FiscalHash`, and `buildCanonicalPayload` across task surfaces: no live hits.

## Final Verdict

APPROVE.
