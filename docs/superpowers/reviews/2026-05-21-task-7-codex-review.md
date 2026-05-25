# Task 7 Codex Self-Adversarial Review

Commit under review: `7b5d0f430 Phase 3.7.1: Add POS account charge AR posting`
Follow-up under review: `57f51d091 Phase 3.7.2: Harden POS charge AR posting tests`
Second-pass fix under review: `da10ec6ec Phase 3.7.3: Move POS charge command into accounting domain`

## Round 1 Verdict: REQUEST-CHANGES

### Finding 1 — REQUEST-CHANGES — Cross-tenant/partner guard is implemented but not pinned by regression coverage

`GeneralLedgerService::createPOSChargeEntry()` scopes the company by `(tenant_id, company_id)` and scopes the partner by `(tenant_id, company_id, partner_id)` before writing the journal. That is the correct shape for the Task 21 / Pass 2A.PHP cross-tenant FK standing pattern, but the Task 7 test suite only covered the happy-path partner id. A future refactor could remove the guard and keep all current tests green.

Required fix: add a regression test that passes a customer from another tenant/company, expects a fail-loud model-not-found exception, asserts no journal rows are written, and asserts `PartnerBalanceService::refreshPartnerBalance()` is not called.

### Finding 2 — REQUEST-CHANGES — Conditional VAT account behavior is not pinned

The implementation only looks up `VatCollected` when `vatTotal > 0`, matching the Task 7 plan. The suite covers positive VAT but not zero VAT. Without a zero-VAT regression, a later change could require a VAT account for exempt/zero-rated account charges and silently break legitimate VAT-free charges.

Required fix: add a regression test that deletes the `VatCollected` account, submits `vatTotal = 0.000`, and verifies the entry is created without a VAT line.

## Standing-Pattern Checks

- Cross-tenant FK safety: implementation shape is correct, but Round 1 test coverage was incomplete.
- Fail-loud vs silent downgrade: missing `SalesDiscount` account fails loud and does not write a journal; partner balance refresh failure bubbles.
- Dead-path rebuild: `createPOSChargeEntry()` has a direct focused test suite and is ready for Task 8 bridge wiring.
- Discriminated-union matrix: not applicable to this PHP command/service slice.
- Contract drift: command fields match the Task 7 plan; canonical VAT arrays are accepted unchanged.
- Per-method skips: no skips added.
- Skip-citation accuracy: no skips added.
- Constructor injection / rule 13: production code does not call `app()`, `App::make()`, or `resolve()`.
- Bounded module seam: this follows the approved Task 7 plan DTO location under Treasury; the accounting domain method does not import POS projector or Customer modules.

## Round 2 Verdict: APPROVE

Round 2 reviewed `57f51d091`, which adds:

- A cross-company/customer rejection regression proving the `(tenant_id, company_id, partner_id)` guard fails loud before any journal write and before partner-balance refresh.
- A zero-VAT regression proving `VatCollected` is only required when `vatTotal > 0`.

No new issues found.

## Round 2 Verification

- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS, 9 tests / 72 assertions.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting app/Modules/Treasury tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS.
- `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Application/DTOs/CreatePOSChargeJournalEntryCommand.php tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS.
- Full backend gate: `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/ tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS, 1190 tests / 4097 assertions / 107 skipped / 2 incomplete / 16 deprecations.
- Full PHPStan: `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — PASS.
- POS Vitest: `pnpm test` — PASS, 168 files / 1500 tests.
- POS typecheck/lint: `pnpm typecheck && pnpm lint` — PASS with the existing 41 warnings.
- Chokepoint: `bash scripts/check-saleReceipt-chokepoints.sh` — PASS.

## Final Standing-Pattern Checks

- Cross-tenant FK safety: APPROVE. Company and partner are tenant/company scoped before write; regression now pins cross-company customer rejection.
- Fail-loud vs silent downgrade: APPROVE. Missing `SalesDiscount` account fails loud; missing partner fails loud; refresh failure bubbles.
- Dead-path rebuild: APPROVE. Method is directly tested and awaits Task 8 bridge caller wiring.
- Discriminated-union matrix: not applicable.
- Contract drift: APPROVE. Command constructor matches Task 7 plan; canonical VAT arrays are preserved.
- Per-method skips: APPROVE. No skips.
- Skip-citation accuracy: APPROVE. No skips.
- Constructor injection / rule 13: APPROVE. Production code uses constructor dependencies; no `app()`, `App::make()`, or `resolve()`.
- D16 bounded-module guard: APPROVE for this task. The Task 7 plan explicitly placed the command DTO in Treasury and the accounting method does not depend on projector/Treasury bridge runtime wiring.

## Round 3 Verdict: APPROVE

Opus R1 correctly found a BLOCKER: `GeneralLedgerService` imported `App\Modules\Treasury\Application\DTOs\CreatePOSChargeJournalEntryCommand`, creating a new `ModuleDomain -> ModuleApplication` deptrac violation. R3 moves the command DTO to `App\Modules\Accounting\Domain\DTOs\CreatePOSChargeJournalEntryCommand` and updates the service/tests to depend on the Accounting domain command shape. This removes the Task 7-owned upward/Treasury dependency without widening the deptrac baseline.

The raw deptrac ratchet still reports pre-existing/current-tree `SharedContracts -> ModuleDomain` growth from `App\Shared\Contracts\Fiscal\FiscalEventProjector` depending on Fiscal domain types. That edge is not introduced by Task 7 and does not involve `CreatePOSChargeJournalEntryCommand`; the Task 7-owned `ModuleDomain -> ModuleApplication` regression is closed.

## Round 3 Verification

- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS, 9 tests / 72 assertions.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting app/Modules/Treasury tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS.
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` — still FAILS on unrelated `SharedContracts -> ModuleDomain` growth; Task 7's prior `ModuleDomain -> ModuleApplication` growth is gone.
- `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Accounting/Domain/DTOs/CreatePOSChargeJournalEntryCommand.php tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS.
- Full backend gate: `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/ tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS, 1190 tests / 4097 assertions / 107 skipped / 2 incomplete / 16 deprecations.
- Full PHPStan: `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — PASS.
- POS Vitest: `pnpm test` — PASS, 168 files / 1500 tests.
- POS typecheck/lint: `pnpm typecheck && pnpm lint` — PASS with the existing 41 warnings.
- Chokepoint: `bash scripts/check-saleReceipt-chokepoints.sh` — PASS.

## Round 3 Standing-Pattern Checks

- Cross-tenant FK safety: APPROVE. Guard and regression unchanged.
- Fail-loud vs silent downgrade: APPROVE. Missing accounts/customer and refresh failures remain fail-loud.
- Dead-path rebuild: APPROVE. Task 7 service method is directly tested; Task 8 bridge will provide the live fiscal-event caller.
- Contract drift: APPROVE. Command constructor remains exactly the Task 7 command shape; only namespace/ownership changed to satisfy architecture.
- Constructor injection / rule 13: APPROVE. Production code still has no service-locator calls.
- D16 bounded-module guard: APPROVE. Accounting domain no longer imports Treasury Application. The command is Accounting-owned, which Task 8's Treasury application bridge may construct because `ModuleApplication -> ModuleDomain` is allowed.
