# Phase 3 Task 1 R4 Codex Self-Review

Reviewed commits:

- `2422ea891` (`Phase 3.1.1: Add account charge payload registry`)
- `d36a72198` (`Phase 3.1.2: Validate account charge append payloads`)
- `692a3a288` (`Phase 3.1.3: Align account charge canonical fixture`)
- `82bd2c4da` (`Phase 3.1.4: Harden account charge drift gates`)

Plan: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md` Task 1

Prior reviews:

- `docs/superpowers/reviews/2026-05-21-task-1-codex-review.md`
- `docs/superpowers/reviews/2026-05-21-task-1-r2-codex-review.md`
- `docs/superpowers/reviews/2026-05-21-task-1-opus-review.md`
- `docs/superpowers/reviews/2026-05-21-task-1-r3-codex-review.md`
- `docs/superpowers/reviews/2026-05-21-task-1-r3-opus-review.md`

Verdict: APPROVE

## R4 Fix Verification

The R3 Opus REQUEST-CHANGES findings are fixed.

Finding 1 is fixed by moving the PHP/TS key-list drift gates out of the SQLite-gated `FiscalEventEngine.append` suite into `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts`. The new suite does not use the `nodeSqliteAvailable ? describe : describe.skip` wrapper and executes three always-on gates:

- `SALE_RECEIPT_PAYLOAD_KEYS` mirrors PHP `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT']`.
- `ACCOUNT_PAYMENT_PAYLOAD_KEYS` mirrors PHP `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['ACCOUNT_PAYMENT']`.
- `ACCOUNT_CHARGE_PAYLOAD_KEYS` mirrors PHP `AccountChargePayload::PAYLOAD_KEYS`.

Finding 2 is fixed by widening `AccountChargeCustomer.customer_category` from `'individual' | 'business'` to `string | null`, preserving raw mirror categories as required by the spec. `AccountChargePayload.test.ts` now includes a typed `AccountChargePayload` sample with `customer_category: 'retail'` so typecheck guards against future narrowing.

## Standing Pattern Sweep

- Cross-tenant FK safety: not applicable; Task 1 still has no DB FK lookup or projection.
- Fail-loud over silent downgrade: APPROVE. ACCOUNT_CHARGE append validation still rejects malformed top-level payloads before mutation.
- Dead-path rebuild: APPROVE. Registry implementation remains live through `FiscalEventEngine.append()`, and drift gates now have their own live non-skipped test file.
- Contract drift: APPROVE. Top-level PHP/TS key drift is always tested; raw customer category typing now matches the locked spec.
- R2/R3/R4 fix regression risk: APPROVE. R4 moved tests and widened one type, then reran focused and full POS verification.
- Per-method skips: no new skip was introduced. The new drift test is outside the existing class-level SQLite skip.
- Skip-citation accuracy: no skip citation was added.
- Constructor injection / service locator rule: no production `app()`, `App::make()`, or `resolve()` usage was introduced.
- D16 bounded modules: no Treasury, Accounting, Document/B2B, Partner, or Customer module import was introduced.

## Verification Evidence

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3` after R4 edits:

```bash
cd apps/pos
pnpm test -- FiscalPayloadKeyDrift.test.ts AccountChargePayload.test.ts FiscalEventEngine.test.ts accountChargeCanonicalParity.test.ts
pnpm typecheck
pnpm lint
pnpm test

cd ../api
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php --filter account_charge
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --debug tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php app/Modules/Fiscal/Domain/Enums/FiscalEventType.php

cd /Users/houssamr/Projects/syneriva/apps/erp.phase-3
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
```

Results:

- POS focused fiscal tests passed: 4 files, 79 tests.
- POS typecheck passed.
- POS lint passed with 0 errors and the existing 41 warnings.
- POS full Vitest passed: 165 files, 1459 tests.
- API focused registry test passed: 2 tests, 7 assertions.
- PHPStan `--debug` passed on reviewed PHP paths.
- Chokepoint sentinels passed.
