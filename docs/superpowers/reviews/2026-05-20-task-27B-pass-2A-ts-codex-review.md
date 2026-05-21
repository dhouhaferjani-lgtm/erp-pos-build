# Codex Review — Task 27B Pass 2A.TS

**Date:** 2026-05-20  
**Reviewer:** Codex first-pass adversarial review  
**Reviewed commits:** `8f358d5e6` plus R2 closure `1d3526da5` and R3 closure `edb1e0620`

## Executive Summary

Initial review of `8f358d5e6` found one BLOCKER in the TS/PHP validator mirror: TypeScript accepted missing fractional digits for non-zero `currency_scale` money strings, while PHP requires fixed-scale `bcformat` strings. R2 commit `1d3526da5` closes that drift with a red/green regression and a one-line regex correction.

Independent second-pass review then found one P2: sparse JS arrays could bypass `forEach()` row validation and seal canonical bytes PHP would reject after JSON serialization. R3 commit `edb1e0620` closes that with a dense-list guard in `requireList()` plus coverage across all four `SALE_RECEIPT` list containers. Clean Codex re-review after R3 found no remaining BLOCKER / P1 / P2 issues.

## R1 Finding

### BLOCKER-1 — TS money regex was wider than PHP

`apps/pos/src/lib/fiscal/FiscalEventEngine.ts` used:

```ts
new RegExp(`^(0|[1-9]\\d*)(\\.\\d{${scale}})?$`)
```

For `currency_scale=2`, this accepted `"10"`. PHP authority `FiscalPayloadConstraintValidator::moneyRegex()` requires `^(0|[1-9]\d*)\.\d{2}$` for non-zero scales. Impact: the device could author and hash a `SALE_RECEIPT` payload that passed TS validation but later failed server parse/validation, creating a sync-time fiscal event rejection.

**Closure:** `1d3526da5` changes TS to require the fractional part for non-zero scales and adds `Pass 2A.TS R2 — rejects missing fractional digits when currency_scale is non-zero`.

## Second-Pass Finding

### P2-1 — Sparse JS arrays bypassed nested-list row validation

`Array.prototype.forEach()` skips sparse-array holes. `requireList()` previously only checked `Array.isArray()`, so a caller could pass `line_items: new Array(1)`: length was non-zero, no row validator ran, and `JSON.stringify()` later serialized the hole as `null` in canonical bytes. PHP would reject the decoded list row.

**Closure:** `edb1e0620` makes `requireList()` reject any missing numeric index before per-row validation. Regression `Pass 2A.TS R3 — rejects sparse list containers before canonical sealing` covers `line_items`, `payments`, `vat_breakdown`, and `vouchers_redeemed`.

## Re-Review Checklist

| Axis | Result | Evidence |
|---|---|---|
| TS validator mirrors PHP structural constraints | PASS after R3 | Key set, dense list containers, nested object/list row shapes, UUID/date/country/tax-number regexes, enums, training flag, discount reason, foreign-currency pairing, and fixed-scale money now match PHP's structural surface. PHP-only BCMath partition/total arithmetic remains intentionally server-side per synthesis v5 §6.F. |
| `SALE_RECEIPT_PAYLOAD_KEYS` byte-mirrors PHP | PASS | Cross-language test reads `FiscalPayloadConstraintValidator.php` at runtime and asserts sorted equality with TS const; 27 keys. |
| Drift gate is live | PASS | `FiscalEventEngine.test.ts` includes the non-skipped `Pass 2A.TS — cross-language drift gate` test; full POS suite ran 1468/1468 passing after R2. |
| `.PASS_2B_PENDING` content | PASS | Marker contains the synthesis v5 §8 + plan §2144-2348 A1-A6 citation line required by the handoff. |
| `check-pass-2b-pending.sh` clean state | PASS | Script exits 0 while marker exists and checkout path is not wired. |
| `check-pass-2b-pending.sh` forbidden patterns | PASS | Script checks `FiscalEventEngine`, `getFiscalEventEngine`, `lockTerminal`, and `\.append\(.*event_type` across `receiptService.ts` and `paymentStore.ts`. Synthetic temp-copy probe with a `FiscalEventEngine` import exited 1. |
| CI sentinel wiring | PASS | `.github/workflows/ci.yml` runs `bash apps/pos/scripts/check-pass-2b-pending.sh` inside the `chokepoint-gate` job. |
| Dead-path probe | PASS | New validator branches are called from `FiscalEventEngine.append()` via `validateRequestPayload()` for `SALE_RECEIPT`; tests exercise the branches. `SALE_RECEIPT_PAYLOAD_KEYS` is consumed by validator and drift gate. |
| Discriminated-union / failure matrix | PASS | Pass 2A.TS tests cover extras, missing keys, UUID/date/datetime, scale allowlist, enum invalidity, training-flag both directions, negative money, wrong scale, missing fractional digits, discount reason both directions, buyer present/missing, foreign-currency pair/half-pair, REFUND/SALE original-reference rules, sparse and empty lists, tax category, jurisdiction code, and tax-number control/length failures. |
| Cross-tenant FK safety | N/A for this TS validator task | No FK lookups or database FK resolution were added. |
| Fail-loud vs silent downgrade | PASS | Validation errors throw `FiscalEventPayloadValidationError` before mutation; no fallback authoring path added. |
| Dead legacy path rebuild | PASS | Pass 2A intentionally does not wire checkout; marker + sentinel enforce no early `receiptService.ts` / `paymentStore.ts` engine path before Pass 2B. |
| Contract drift docs vs code | PASS | Comments now say TS mirrors PHP fixed-scale `bcformat`; no remaining optional-fraction doc contradiction in the TS regex block. |
| CLAUDE.md rule 13 | PASS | No `app()`, `App::make`, or `resolve()` usage introduced. |
| Per-method skip / skip citations | N/A | No skipped tests added. |

## Verification

- `pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts -t "missing fractional digits"`: RED before R2, GREEN after R2.
- `pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts -t "sparse list"`: RED before R3, GREEN after R3.
- `pnpm test` in `apps/pos`: 154 files, 1469 tests passed after R3.
- `pnpm typecheck` in `apps/pos`: passed.
- `pnpm lint` in `apps/pos`: passed with existing warnings only.
- `./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/` in `apps/api`: 496 tests, 1805 assertions, 45 skipped.
- `./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` in `apps/api`: passed. The first run without explicit memory hit the configured 512M PHPStan worker limit.
- `./vendor/bin/pint --test` in `apps/api`: passed.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh`: passed, 9 call sites reconciled.
- `bash apps/pos/scripts/check-pass-2b-pending.sh`: passed clean-state check.
- Temp-copy sentinel failure probe with `receiptService.ts` importing `FiscalEventEngine`: exited 1 with the expected sequencing error.

## Verdict

APPROVE.
