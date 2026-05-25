# Task 7 Opus Second-Pass Adversarial Review

Commits reviewed:

- `7b5d0f430` — `Phase 3.7.1: Add POS account charge AR posting`
- `57f51d091` — `Phase 3.7.2: Harden POS charge AR posting tests`
- `da10ec6ec` — `Phase 3.7.3: Move POS charge command into accounting domain`
- First-pass review: `docs/superpowers/reviews/2026-05-21-task-7-codex-review.md`

Latest R3 verdict: **APPROVE**

R1 verdict: **BLOCKER**

## Findings

### BLOCKER — Task 7 introduces a new Domain -> Application dependency that fails the deptrac hard gate

`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:20` imports `App\Modules\Treasury\Application\DTOs\CreatePOSChargeJournalEntryCommand`, and `createPOSChargeEntry()` type-hints it at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1263`.

That places an Application-layer DTO from Treasury into an Accounting Domain service signature. The repository's deptrac ratchet explicitly treats `ModuleDomain on ModuleApplication` growth as a BLOCKER. Running the ratchet on the reviewed tree reports:

```text
ModuleDomain on ModuleApplication  baseline 22  current 23  BLOCKER (+1)
TOTAL                              baseline 59  current 62
RESULT: FAIL - architecture boundary regression.
```

The raw deptrac report identifies the Task 7-introduced edge directly:

```text
GeneralLedgerService must not depend on CreatePOSChargeJournalEntryCommand
(ModuleDomain on ModuleApplication)
```

This is not just aesthetic layering. It makes the Accounting domain API depend on a Treasury application concern before the Task 8 bridge even exists, and it defeats the D16 bounded-module posture the phase has been defending. The Task 7 plan did ask for a Treasury DTO, but the enforced architecture gate is stricter than that plan shape. The implementation needs a boundary-preserving command shape before this can be approved, such as an Accounting-owned DTO or shared contract DTO that the Treasury bridge can translate into without making Accounting Domain import Treasury Application.

## Checks That Passed

- Journal shape matches Task 7 behavior: debit CustomerReceivable with partner, debit SalesDiscount only when discount is positive, credit ProductRevenue, credit VatCollected only when VAT is positive.
- Entry metadata is correct: `source_type = pos_account_charge`, `source_id = fiscalEventId`, Draft status, and `POS Account Charge {accountChargeUuid}` description.
- The implementation does not create `payments` or `pos_receipt_payments`, and it does not call `createPOSPaymentEntry()`.
- Partner scoping is fail-loud for cross-company/cross-tenant customers, and Round 2 added the missing regression.
- Missing discount account and missing VAT account for positive VAT fail loud before journal writes.
- `PartnerBalanceService::refreshPartnerBalance()` is called through constructor injection, and refresh failures bubble.
- No production `app()`, `App::make()`, or `resolve()` calls were introduced.
- The Round 2 hardening commit did not introduce a production-path defect.

## Verification

- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS, 9 tests / 72 assertions.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting app/Modules/Treasury tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS.
- `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Application/DTOs/CreatePOSChargeJournalEntryCommand.php tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS.
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` — FAIL, including the Task 7 `ModuleDomain on ModuleApplication` regression above. The same run also reports unrelated current-tree `SharedContracts on ModuleDomain` growth, but the blocking Task 7-owned issue is the new `GeneralLedgerService -> CreatePOSChargeJournalEntryCommand` dependency.

## Notes For R2

Do not paper over this by updating the deptrac baseline. The fix should remove the new Accounting Domain -> Treasury Application edge while preserving the Task 7 behavior and tests.

## R3 Re-Review

Verdict: **APPROVE**

The R1 blocker is closed. Commit `da10ec6ec` moves `CreatePOSChargeJournalEntryCommand` from `App\Modules\Treasury\Application\DTOs` to `App\Modules\Accounting\Domain\DTOs`, then updates `GeneralLedgerService` and the Task 7 test to import the Accounting-owned DTO. The current service import is now `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:9`, and `createPOSChargeEntry()` still type-hints the same command shape at `:1263`. This removes the Task 7-owned Accounting Domain -> Treasury Application edge without changing the journal write logic.

No R3-introduced defect found.

### Boundary And Deptrac

`php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` now reports:

```text
ModuleDomain on ModuleApplication  baseline 22  current 22  held (domain)
SharedContracts on ModuleDomain    baseline 2   current 4   RATCHET (+2)
TOTAL                              baseline 59  current 61
RESULT: FAIL - architecture boundary regression.
```

The Task 7-owned blocker is resolved: `ModuleDomain on ModuleApplication` no longer increases. The remaining deptrac failure is the unrelated current-tree `SharedContracts on ModuleDomain` growth from `FiscalEventProjector`/Fiscal domain types; I do not attribute that to Task 7.

### R3 Standing Checks

- Boundary direction / D16: PASS. Accounting Domain no longer imports Treasury Application. A future Treasury Application bridge may depend on the Accounting Domain command because Application -> Domain is allowed.
- Cross-tenant FK safety: PASS. `createPOSChargeEntry()` still scopes Company by `(tenant_id, company_id)` and Partner by `(tenant_id, company_id, partner_id)` before writes.
- Fail-loud behavior: PASS. Missing partner/company/account failures still occur before journal writes where covered; partner balance refresh exceptions still bubble.
- No Payment / ReceiptPayment: PASS. Task 7 still writes only journal entries/lines and the test asserts `payments` and `pos_receipt_payments` stay empty.
- No `createPOSPaymentEntry()` call: PASS. The charge path does not call the POS payment posting path.
- Command contract drift: PASS. Constructor fields and canonical VAT arrays are unchanged; only namespace/ownership changed.
- R3 regression surface: PASS. The diff is limited to a file rename plus imports in the service and test.

### R3 Verification

- `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS, 9 tests / 72 assertions.
- `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting app/Modules/Treasury tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS.
- `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Accounting/Domain/DTOs/CreatePOSChargeJournalEntryCommand.php tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — PASS.
- `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` — FAIL only on unrelated `SharedContracts on ModuleDomain`; Task 7's prior `ModuleDomain on ModuleApplication` blocker is closed.
