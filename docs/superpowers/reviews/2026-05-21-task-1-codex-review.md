# Phase 3 Task 1 Codex Self-Review

Reviewed commit: `2422ea891` (`Phase 3.1.1: Add account charge payload registry`)

Plan: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md` Task 1

Verdict: BLOCKER

## Findings

### 1. BLOCKER: device append can now seal ACCOUNT_CHARGE without runtime payload validation

The commit adds `ACCOUNT_CHARGE` to the device registry's implemented set, so `FiscalEventEngine.append()` now accepts the type and assigns `event_version = 1`. However `FiscalEventEngine.validateRequestPayload()` only switches on `SALE_RECEIPT`, `ACCOUNT_PAYMENT`, `CHAIN_BREAK_DETECTED`, and `CHAIN_RESTART`; the default branch returns without validation.

That means a direct caller can append `ACCOUNT_CHARGE` with extra top-level keys, missing required keys, or non-object payloads, and the device will seal it into the fiscal chain. This violates the fail-loud standing pattern and the Task 1 "canonical drift gate" intent.

Required fix: add a Task 1 R2 commit that imports `ACCOUNT_CHARGE_PAYLOAD_KEYS`, adds an `ACCOUNT_CHARGE` branch in `validateRequestPayload()`, and at minimum enforces object shape plus exact top-level key set. Add focused tests proving a valid golden ACCOUNT_CHARGE appends and an extra key rejects before state mutation.

## Standing Pattern Sweep

- Cross-tenant FK safety: not applicable to Task 1; no FK lookups were added.
- Fail-loud over silent downgrade: failed by the blocker above.
- Dead-path rebuild: registry is now live through `FiscalEventEngine.append()`, but the validation path was not rebuilt with it.
- Discriminated-union matrix completeness: not applicable yet; no discriminated-union service was added.
- Contract drift: PHP/TS top-level key lists and canonical bytes are locked for the payload surface, but device runtime validation does not yet use the key list.
- Per-method skips: no class-level skips were introduced.
- Constructor injection: no production service locator usage was introduced.
- D16 bounded modules: not applicable to Task 1; no module bridge or POS-core projector was added.

## Verification Evidence

Commands run before this review:

```bash
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php --filter account_charge
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventTypeTest.php
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php app/Modules/Fiscal/Domain/Enums/FiscalEventType.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Unit/Fiscal/FiscalEventTypeTest.php
./vendor/bin/pint --test app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php app/Modules/Fiscal/Domain/Enums/FiscalEventType.php tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php tests/Unit/Fiscal/FiscalEventTypeTest.php
pnpm test
pnpm typecheck
pnpm lint
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
```

The verification commands passed, which is why this review focuses on the untested fail-loud gap.
