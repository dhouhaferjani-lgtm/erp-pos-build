# Phase 3 Task 1 R2 Codex Self-Review

Reviewed commits:

- `2422ea891` (`Phase 3.1.1: Add account charge payload registry`)
- `d36a72198` (`Phase 3.1.2: Validate account charge append payloads`)

Plan: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md` Task 1

Prior Codex review: `docs/superpowers/reviews/2026-05-21-task-1-codex-review.md`

Verdict: APPROVE

## R2 Fix Verification

The R1 BLOCKER is fixed. `FiscalEventEngine.validateRequestPayload()` now has an explicit `ACCOUNT_CHARGE` branch. The branch rejects non-object payloads and enforces the exact 28-key top-level set through `ACCOUNT_CHARGE_PAYLOAD_KEYS`.

Focused tests prove:

- a valid golden `ACCOUNT_CHARGE` appends and seals on the device chain;
- an extra top-level key rejects with `payload_extra_field:<key>`;
- rejection happens before mutation by asserting `fiscal_events` remains empty.

The first R2 attempt introduced a new defect by using a test-only `isRecord` helper name in production. Focused Vitest and typecheck caught it immediately. The final R2 code uses the local object-shape check pattern already used by `ACCOUNT_PAYMENT`.

## Standing Pattern Sweep

- Cross-tenant FK safety: not applicable to Task 1; no FK lookup was added.
- Fail-loud over silent downgrade: APPROVE. Device append now rejects malformed ACCOUNT_CHARGE top-level payloads before chain mutation.
- Dead-path rebuild: APPROVE. Registry implementation is live through `FiscalEventEngine.append()`, and the validator switch now routes the live path.
- Discriminated-union matrix completeness: not applicable yet; no service union was added.
- Contract drift: APPROVE. PHP/TS registry tests, locked TS key list, and golden canonical bytes are present. Full PHP parser/validator drift against `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` is intentionally Task 2.
- Per-method skips: no class-level skips were introduced.
- Constructor injection: no production service locator usage was introduced.
- D16 bounded modules: not applicable; no POS-core projector or outbound bridge was added.

## Verification Evidence

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

```bash
cd apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php --filter account_charge
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventTypeTest.php
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php app/Modules/Fiscal/Domain/Enums/FiscalEventType.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Unit/Fiscal/FiscalEventTypeTest.php
./vendor/bin/pint --test app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php app/Modules/Fiscal/Domain/Enums/FiscalEventType.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Unit/Fiscal/FiscalEventTypeTest.php

cd ../pos
pnpm test -- FiscalEventPayloadRegistry.test.ts AccountChargePayload.test.ts accountChargeCanonicalParity.test.ts
pnpm test -- FiscalEventEngine.test.ts FiscalEventPayloadRegistry.test.ts AccountChargePayload.test.ts accountChargeCanonicalParity.test.ts
pnpm typecheck
pnpm test
pnpm lint

cd /Users/houssamr/Projects/syneriva/apps/erp.phase-3
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
```

Results:

- API focused registry/type tests passed.
- API Fiscal/POS suite passed: 1142 tests, 3896 assertions, 16 deprecations, 107 skipped, 2 incomplete.
- PHPStan passed on touched PHP paths.
- Pint passed on touched PHP paths.
- POS focused tests passed.
- POS full Vitest passed: 164 files, 1458 tests.
- POS typecheck passed.
- POS lint passed with existing warnings and 0 errors.
- Chokepoint sentinels passed.
