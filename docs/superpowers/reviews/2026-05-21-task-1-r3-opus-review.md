# Phase 3 Task 1 R3 Opus Review

Reviewed commits:

- `2422ea891` - `Phase 3.1.1: Add account charge payload registry`
- `d36a72198` - `Phase 3.1.2: Validate account charge append payloads`
- `692a3a288` - `Phase 3.1.3: Align account charge canonical fixture`

Authoritative context:

- `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`
- `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md` Task 1
- `docs/superpowers/reviews/2026-05-21-task-1-opus-review.md`
- `docs/superpowers/reviews/2026-05-21-task-1-r3-codex-review.md`

Verdict: REQUEST-CHANGES

## Findings

### 1. The new ACCOUNT_CHARGE PHP/TS drift gate is still behind a class-level skip

The R3 drift gate is a real key-list comparison when it runs, but it was added inside the `d('FiscalEventEngine.append', ...)` suite that aliases to `describe.skip` when `node:sqlite` is unavailable (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:45`-`55`). The gate itself does not use SQLite, but the test at `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1449`-`1455` is skipped with the whole append suite on any Node/runtime where `node:sqlite` is absent. That leaves a pass path where `ACCOUNT_CHARGE_PAYLOAD_KEYS` and `AccountChargePayload::PAYLOAD_KEYS` can drift while the POS suite reports green.

This weakens the closure for prior finding 2 and violates the standing "no class-level skips / tests that pass while contract drifts" pattern. It is also easy to miss because the current local run has `node:sqlite` available, so the gate passed here.

Concrete fix: move the `ACCOUNT_CHARGE` key-list drift gate, and ideally the existing SALE_RECEIPT/ACCOUNT_PAYMENT key-list gates, outside the SQLite-gated append describe. A good target is `apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts` or a dedicated `fiscalPayloadKeyDrift.test.ts` that always runs under Vitest and uses only `fs/path`. Keep only the database append tests under the `nodeSqliteAvailable ? describe : describe.skip` wrapper.

### 2. The ACCOUNT_CHARGE TS customer type rejects spec-valid raw customer categories

The locked spec says `customer.customer_category` is a raw category snapshot: exact `business` routes to the B2B bridge, while `individual`, `retail`, `para-pharmacy`, `null`, and any other non-business value remain B2C/non-B2B (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:91`, `:177`, `:275`). R3 still types the ACCOUNT_CHARGE customer as only `'individual' | 'business'` at `apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:55`-`59`.

That is a contract drift in the exported TS payload surface. Later authoring code that correctly preserves a mirrored `retail`, `para-pharmacy`, custom category, or `null` would be forced to cast around the type or normalize away the raw snapshot, contrary to the spec. The nearby ACCOUNT_PAYMENT type already uses `string | null` for the same raw snapshot field (`apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts:38`-`41`), so this is also inconsistent with the landed account flow pattern.

Concrete fix: change `AccountChargeCustomer.customer_category` to `string | null`, keep the golden fixture as `'individual'`, and add a small type/fixture assertion or `satisfies AccountChargePayload` sample proving a non-business raw category such as `'retail'` or `null` remains valid.

## Prior Finding Verification

Prior finding 1 is fixed for the enumerated nested fields. The TS type and golden fixture now use the locked invoice classifications, include `buyer.contact_id`, include `customer.account_identifier`, include `line_items[].line_uuid`, include `totals.grand_total_before_charge`, use the locked `credit_decision` fields, and keep `credit_limit` out of `local_balance_snapshot` (`apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts:46`-`127`, `:173`-`286`). The PHP fixture mirrors those corrections at `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:493`-`594`. The canonical bytes test also locks the current TS fixture bytes at `apps/pos/src/lib/fiscal/__tests__/accountChargeCanonicalParity.test.ts:8`-`13`.

Prior finding 2 is only partially fixed. The R3 comparison now checks sorted PHP and TS key lists rather than count only (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1449`-`1455`), and the helper fails loud if the PHP DTO path or constant cannot be found (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1518`-`1541`). However, finding 1 above means the gate is not guaranteed to run.

## Standing Pattern Sweep

- Fail-loud append validation: ACCOUNT_CHARGE still rejects non-object payloads and extra top-level keys before mutation (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:489`-`496`, `:757`-`759`, `:1188`-`1197`).
- Dead-path rebuild: live append coverage exists for the ACCOUNT_CHARGE registry branch (`apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:1401`-`1419`), but the drift gate must be moved out of the skipped suite to be a reliable sentinel.
- Contract drift: top-level PHP/TS drift is guarded when the gate runs; nested field drift was manually inspected against the spec. The `customer_category` type remains drifted.
- Production service locator: no production `app()`, `App::make()`, or `resolve()` usage was found in reviewed implementation files.
- D16 hard module imports: no Treasury, Accounting, Document/B2B, Partner, or Customer operational import was introduced in the reviewed production fiscal files.
- Class-level skips: the existing `describe.skip` wrapper remains at `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:54`, and the new drift gate is currently inside it.

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3` unless noted:

```bash
git status --short
git log --oneline -8
git show --stat --oneline 2422ea891 d36a72198 692a3a288
git show --name-only --format='%h %s' 692a3a288
nl -ba apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts | sed -n '1,340p'
nl -ba apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php | sed -n '1,260p'
nl -ba apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php | sed -n '1,260p;490,610p'
nl -ba apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts | sed -n '1,80p;1360,1505p;1504,1588p'
nl -ba apps/pos/src/lib/fiscal/FiscalEventEngine.ts | sed -n '460,780p;1170,1225p;1735,1780p'
nl -ba apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts | sed -n '1,220p'
nl -ba apps/pos/src/lib/fiscal/__tests__/accountChargeCanonicalParity.test.ts | sed -n '1,160p'
nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '131,248p;260,290p'
nl -ba apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts | sed -n '1,280p'
rg -n "markTestSkipped|describe\\.skip|it\\.skip|test\\.skip|@group|@doesNotPerformAssertions|phpstan-ignore|app\\(|App::make|resolve\\(" apps/api/app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts apps/pos/src/lib/fiscal/FiscalEventEngine.ts apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts apps/pos/src/lib/fiscal/__tests__/AccountChargePayload.test.ts apps/pos/src/lib/fiscal/__tests__/accountChargeCanonicalParity.test.ts apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts
git diff --check 2422ea891^..692a3a288
git show --check --oneline 2422ea891 d36a72198 692a3a288
```

Runtime verification:

```bash
cd apps/api
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php --filter account_charge
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php app/Modules/Fiscal/Domain/Enums/FiscalEventType.php
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --debug tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php app/Modules/Fiscal/Domain/DTOs/AccountChargePayload.php app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php app/Modules/Fiscal/Domain/Enums/FiscalEventType.php

cd ../pos
pnpm test -- FiscalEventEngine.test.ts AccountChargePayload.test.ts accountChargeCanonicalParity.test.ts
pnpm typecheck
```

Results:

- PHP focused registry/account-charge tests passed: 2 tests, 7 assertions.
- PHPStan without `--debug` was blocked by sandbox socket permissions: `Failed to listen on "tcp://127.0.0.1:0": Operation not permitted (EPERM)`.
- PHPStan with `--debug` passed on the reviewed PHP paths.
- POS focused Vitest passed: 3 files, 78 tests. This local run did execute `FiscalEventEngine.test.ts` because `node:sqlite` was available.
- POS typecheck passed.
