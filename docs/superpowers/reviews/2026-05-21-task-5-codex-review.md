# Task 5 Codex Self-Adversarial Review — Device ACCOUNT_CHARGE Authoring And Printable

Commit reviewed: `a41e9ddf0 Phase 3.5.1: Author account charges on device`

## Verdict

APPROVE

## Scope Reviewed

- Added device-side `authorAccountCharge()` service and `buildAccountChargePayload()` payload assembler.
- Added `ACCOUNT_CHARGE_RECEIPT` printable mapper.
- Extended `FiscalEventEngine` ACCOUNT_CHARGE validation beyond top-level keyset to nested money/date/customer/credit/terms/staleness structures.
- Added Task 5 POS tests for service authoring, payment-line rejection, credit policy fail-closed behavior, credit-balance offset math, printable mapping, and engine validation.

## Adversarial Checks

### Cross-Tenant FK Safety

PASS. The authoring service never resolves server-side FKs and never imports Customer/Treasury/B2B/Accounting modules. Customer scope is enforced before credit approval through `evaluateAccountChargeCreditDecision()` with `tenant_id/company_id` compared to the expected tenant/company. The append request carries explicit `tenant_id`, `company_id`, `terminal_id`, and `operator_id`; idempotency is scoped by the existing fiscal engine.

### Fail-Loud Versus Silent Downgrade

PASS. Selected-customer absence, forbidden payment lines, credit-policy rejection, missing balance snapshot, stale-policy mismatch, invalid money fields, and invalid payload dates throw typed/local validation errors before a sealed event can be emitted. The implementation does not silently fall back to SALE_RECEIPT, ACCOUNT_PAYMENT, receipt APIs, or payment APIs.

### Dead-Path Rebuild

PASS for Task 5 scope. New production functions are directly exercised by focused tests. No legacy chain or receipt API path is revived. Production UI wiring is intentionally reserved for later Phase 3 tasks; Task 5 owns the authoring/printable service layer and fiscal-engine validation.

### Discriminated-Union / Matrix Completeness

PASS. Task 5 does not introduce a new discriminated-union DTO wrapper. It does cover the ACCOUNT_CHARGE matrix rows owned by this task: approved charge, forbidden payments, rejected credit policy, credit-balance offset, missing selected customer, printable mapping, extra top-level payment key, non-string money, invalid device time, and invalid business date.

### Contract Drift

PASS. The payload assembler uses the locked `AccountChargePayload` interfaces and the engine validator checks the same top-level `ACCOUNT_CHARGE_PAYLOAD_KEYS`. `accountChargeCanonicalParity.test.ts` still passes against the golden canonical bytes, so the Task 1/2 cross-language key-order contract remains intact.

### Constructor Injection / Service Location

PASS. No production `app()`, `App::make()`, `resolve()`, or server-side service location is introduced. Device code receives the SQLite database explicitly and retrieves the already-established fiscal engine singleton through the existing POS pattern.

### D16 Bounded-Modules Guard

PASS. No direct Treasury, Accounting, Customer module, or B2B dependency is imported in the fiscal engine or account-charge authoring service. The service only consumes the local POS customer snapshot type and the local credit-rules engine.

### Skip-Citation Accuracy / markTestSkipped

PASS. No skipped tests or skip citations were added.

### R2-Fix Risk

PASS. This is an R1 implementation commit for Task 5. If Opus requests changes, the follow-up commit must be re-reviewed from scratch because Phase 1/Phase 3 precedent shows R2 fixes can introduce their own defects.

## Verification Evidence

- `pnpm test -- accountChargeService.test.ts accountChargePrintable.test.ts FiscalEventEngine.test.ts accountChargeCanonicalParity.test.ts` — 82 tests passed.
- `pnpm typecheck` — passed.
- Focused `eslint` on touched POS files — passed.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1173 tests, 3999 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — no errors.
- `./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal` — pass.
- `pnpm test` — 168 files, 1489 tests passed.
- `pnpm typecheck && pnpm lint` — typecheck passed; lint passed with 41 pre-existing warnings and 0 errors.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh` — pass.
