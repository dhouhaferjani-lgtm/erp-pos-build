# Opus Review - Task 27B Pass 2A.TS

**Date:** 2026-05-20  
**Reviewer:** independent second-pass adversarial review  
**Reviewed commits:** original Pass 2A.TS R1 `8f358d5e6`, Codex R2 fix `1d3526da5`

## Findings

### P2 - Sparse JS arrays bypass TS nested-list row validation, creating a PHP-rejected canonical payload path

`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1073-1098` validates the four SALE_RECEIPT list containers with `Array.prototype.forEach()`. In JavaScript, `forEach()` skips sparse-array holes. `requireList()` only checks `Array.isArray()` and returns the array unchanged at `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1405-1412`; it does not verify dense indices.

Impact: a caller can pass a sparse list such as `line_items: new Array(1)`. The TS validator sees `lineItems.length === 1`, runs zero row validators because the hole is skipped, and proceeds past payload validation. `JSON.stringify` serializes that hole as `null` inside `canonical_bytes`; the PHP validator then rejects the synced payload because its list traversal validates every decoded element (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:382-408`) after confirming list shape with `array_is_list()` (`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:933-943`). This violates the Pass 2A.TS structural mirror requirement for nested-object/list shape and leaves a device-authored event that can fail server parse/validation later.

I reproduced this with a temporary Vitest probe in the review worktree: `line_items: new Array(1)` reached a dummy DB read and threw `Error: DB_READ_REACHED` instead of `FiscalEventPayloadValidationError`. The same hole risk applies to `payments`, `vat_breakdown`, and `vouchers_redeemed` because all four lists use the same `forEach()` traversal.

Expected direction: make the TS list validator reject sparse arrays before per-row validation, or iterate by index (`for (let i = 0; i < items.length; i++)`) and validate missing elements as invalid rows. Add regression coverage for sparse `line_items`, `payments`, `vat_breakdown`, and `vouchers_redeemed`.

## Clean Checks

- `SALE_RECEIPT_PAYLOAD_KEYS` contains the 27 PHP keys in sorted lexicographic order and is covered by a live test that reads `FiscalPayloadConstraintValidator.php` at test time (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1130-1135`, helper at `:1191-1220`).
- R2 fixed the money regex drift: TS now requires fixed fractional digits for non-zero scales, matching PHP `moneyRegex()`.
- TS intentionally leaves VAT partition and total arithmetic to PHP per synthesis v5 section 6.F; I did not treat those BCMath-only checks as TS defects.
- `.PASS_2B_PENDING` content matches synthesis v5 section 8.A (`apps/pos/src/lib/offline/.PASS_2B_PENDING:1`).
- `check-pass-2b-pending.sh` checks both `receiptService.ts` and `paymentStore.ts` for the required four forbidden patterns: `FiscalEventEngine`, `getFiscalEventEngine`, `lockTerminal`, and `\.append\(.*event_type` (`apps/pos/scripts/check-pass-2b-pending.sh:22-63`).
- The Pass 2B sequencing sentinel is wired into the `chokepoint-gate` CI job (`.github/workflows/ci.yml:93-100`).
- No checkout-path wiring is currently present while the marker exists; grep found no forbidden patterns in `receiptService.ts` or `paymentStore.ts`.
- New validator code is live: `append()` calls `validateRequestPayload()` before registry lookup or DB mutation, and the SALE_RECEIPT branch calls the new 27-key validator.
- Standing-pattern scan found no new `app()`, `App::make()`, or Laravel `resolve()` usage in the reviewed fiscal code path.
- No per-method skips were added in the focused FiscalEventEngine tests.

## Verification Notes

- `pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` in `apps/pos`: passed, 54 tests.
- `bash apps/pos/scripts/check-pass-2b-pending.sh`: exited 0 in the clean Pass 2A state.
- Temporary sentinel probe with a `FiscalEventEngine` import in `receiptService.ts`: exited 1 with the expected `.PASS_2B_PENDING` sequencing error.
- Temporary sparse-list probe: failed as expected for this review finding because sparse `line_items` reached `DB_READ_REACHED` instead of throwing `FiscalEventPayloadValidationError`. The probe file was removed after execution.
- Worktree was left with only the pre-existing untracked first-pass Codex review plus this newly written review document.

## Verdict

REQUEST CHANGES. One P2 structural drift remains: TS must reject sparse SALE_RECEIPT list containers before sealing canonical bytes.

## Round-2 / R3 Re-review - commit `edb1e0620`

### Findings

No BLOCKER / P1 / P2 findings remain for the prior sparse-list issue.

R3 adds a dense-index guard to `requireList()` before any caller can proceed to per-row `forEach()` validation. The guard iterates every numeric index from `0` through `items.length - 1` and throws `FiscalEventPayloadValidationError` when `Object.prototype.hasOwnProperty.call(items, index)` is false (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1405-1419`). That closes the exact bypass from the first review: `new Array(1)` no longer reaches canonical sealing.

Regression coverage is present for all four SALE_RECEIPT containers. `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1061-1070` loops over `line_items`, `payments`, `vat_breakdown`, and `vouchers_redeemed`, assigns `new Array(1)`, and expects the dense-list validation error.

No new TS/PHP structural drift or dead path was introduced by R3. The production change is limited to the shared list validator used by all four SALE_RECEIPT list containers; the existing live cross-language key drift gate still executes in the focused test file.

Marker and sentinel constraints remain intact: `.PASS_2B_PENDING` still carries the required synthesis v5 section 8 / plan citation line, `check-pass-2b-pending.sh` still checks both checkout files for the four forbidden patterns, and the CI `chokepoint-gate` still invokes the sentinel.

### Verification

- `pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts -t "sparse list"` in `apps/pos`: passed, 1 test passed / 54 skipped.
- `pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` in `apps/pos`: passed, 55 tests.
- `pnpm typecheck` in `apps/pos`: exited 0.
- `bash apps/pos/scripts/check-pass-2b-pending.sh`: exited 0 in the clean marker-present state.
- Grep for `FiscalEventEngine|getFiscalEventEngine|lockTerminal|\.append\(.*event_type` in `receiptService.ts` and `paymentStore.ts`: no matches.
- Synthetic temp-copy sentinel probe with a `FiscalEventEngine` import in `receiptService.ts`: exited 1 with the expected `.PASS_2B_PENDING` sequencing error.

### Final Verdict

APPROVE. R3 closes the prior P2 and I found no new BLOCKER / P1 / P2 issues in the reviewed scope.
