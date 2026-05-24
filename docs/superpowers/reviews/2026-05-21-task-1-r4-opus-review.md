# Phase 3 Task 1 R4 Opus Review

Reviewed commits:

- `2422ea891` - `Phase 3.1.1: Add account charge payload registry`
- `d36a72198` - `Phase 3.1.2: Validate account charge append payloads`
- `692a3a288` - `Phase 3.1.3: Align account charge canonical fixture`
- `82bd2c4da` - `Phase 3.1.4: Harden account charge drift gates`

Authoritative context:

- `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`
- `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md` Task 1
- `docs/superpowers/reviews/2026-05-21-task-1-r3-opus-review.md`
- `docs/superpowers/reviews/2026-05-21-task-1-r4-codex-review.md`

Verdict: APPROVE

## Findings

No blocking or material R4 defects found.

## R3 Finding Verification

1. Fixed: the PHP/TS drift gates were moved out of the SQLite-gated `FiscalEventEngine.append` suite. `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:48` still uses `describe.skip` when `node:sqlite` is absent, but the SALE_RECEIPT, ACCOUNT_PAYMENT, and ACCOUNT_CHARGE drift gates now live in `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts:7`-`30`, which has a normal top-level `describe` and no SQLite gate.

2. Fixed: `AccountChargeCustomer.customer_category` now preserves raw `string | null` values at `apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:55`-`59`. The regression test at `apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts:62`-`73` constructs a runtime `AccountChargePayload` with `customer_category: 'retail'` and asserts the value remains `retail`, so this is not only a type-only assertion that could disappear at runtime.

## R4 Regression Sweep

- Unused imports: no reviewed-file unused import issue surfaced in `pnpm lint`; the full lint run exits 0 with 41 pre-existing warnings in unrelated files.
- Removed coverage: the three drift assertions removed from the SQLite-gated append suite were preserved in `FiscalPayloadKeyDrift.test.ts`, and focused Vitest now reports 79 tests across the four fiscal files, matching the R4 Codex review.
- Drift parsing: the new drift helper fails loud if PHP files or constants are missing and compares exact sorted key lists before checking expected counts. The regex remains simple, but it is scoped to flat PHP key arrays and fails closed on shape changes.
- Wrong test counts: focused POS fiscal run passed `4` files and `79` tests; PHP focused registry run passed `2` tests and `7` assertions.
- Type-only test risk: the retail category test has a runtime object and runtime expectation, while `pnpm typecheck` enforces the `AccountChargePayload` assignment.
- Accidental implementation changes: R4 only changes the exported TS customer category type from `'individual' | 'business'` to `string | null`; no runtime production behavior changed in `FiscalEventEngine` or registry code.

## Standing Pattern Sweep

- Fail-loud append validation: still present. `ACCOUNT_CHARGE` non-object and exact-key validation remains in `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1191`-`1196`, with the extra-key no-mutation test retained at `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1404`-`1412`.
- Dead-path rebuild: still covered. The append happy path seals an `ACCOUNT_CHARGE` through the real engine path at `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1395`-`1402`.
- Contract drift: top-level SALE_RECEIPT, ACCOUNT_PAYMENT, and ACCOUNT_CHARGE key lists now have always-on PHP/TS drift gates.
- No production service locator: no production `app()`, `App::make()`, or `resolve()` usage was introduced in the reviewed implementation files.
- No D16 hard module imports: no Treasury, Accounting, Document/B2B, Partner, or Customer operational import was introduced in the reviewed fiscal production files.
- No class-level skips added: R4 did not add a new class-level skip. The existing SQLite skip remains limited to append tests, and the moved drift gates are outside it.

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3` unless noted:

```bash
git status --short
git show --stat --oneline 2422ea891 d36a72198 692a3a288 82bd2c4da
git show --name-only --format='%h %s' 82bd2c4da
git show --check --oneline 82bd2c4da
git diff --check 2422ea891^..82bd2c4da
git diff 692a3a288..82bd2c4da -- apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts
nl -ba apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts | sed -n '1,240p'
nl -ba apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts | sed -n '1,360p'
nl -ba apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts | sed -n '1,260p'
nl -ba apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts | sed -n '1,100p;1360,1485p;1485,1585p'
rg -n "describe\\.skip|it\\.skip|test\\.skip|markTestSkipped|@doesNotPerformAssertions|phpstan-ignore|@ts-ignore|@ts-expect-error|ACCOUNT_CHARGE|ACCOUNT_CHARGE_PAYLOAD_KEYS|PAYLOAD_KEYS|customer_category|require\\('node:fs'\\)|require\\('node:path'\\)" apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts apps/pos/src/lib/fiscal/FiscalEventEngine.ts apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php
rg -n "from ['\\\"](.*Treasury|.*Accounting|.*Document|.*Partner|.*Customer)|app\\(|App::make|resolve\\(|container\\.resolve|service locator|ACCOUNT_CHARGE" apps/pos/src/lib/fiscal/FiscalEventEngine.ts apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php

cd apps/pos
pnpm test -- FiscalPayloadKeyDrift.test.ts AccountChargePayload.test.ts FiscalEventEngine.test.ts accountChargeCanonicalParity.test.ts
pnpm typecheck
pnpm lint

cd ../api
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php --filter account_charge
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --debug tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php app/Modules/Fiscal/Domain/Enums/FiscalEventType.php

cd /Users/houssamr/Projects/syneriva/apps/erp.phase-3
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
bash apps/pos/scripts/check-pass-2b-pending.sh
git status --short
```

Results:

- `git show --check` and `git diff --check` passed.
- POS focused fiscal tests passed: 4 files, 79 tests.
- POS typecheck passed.
- POS lint passed with 0 errors and 41 existing warnings.
- API focused registry test passed: 2 tests, 7 assertions.
- PHPStan `--debug` passed on reviewed PHP paths.
- `check-saleReceipt-chokepoints.sh` passed.
- `check-pass-2b-pending.sh` exited 0 with no output.
